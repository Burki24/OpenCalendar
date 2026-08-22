<?php

declare(strict_types=1);

class OpenCalendarDiscovery extends IPSModuleStrict
{
    private const CALENDAR_ACCOUNT_MODULE_ID = '{966D6119-7FF3-5CA5-06C3-536FBF8100C4}';
    private const APPLE_CALDAV_URL = 'https://caldav.icloud.com';

    /** @var array<string, int> */
    private const PROVIDERS = [
        'apple'     => 0,
        'caldav'    => 1,
        'google'    => 2,
        'microsoft' => 3,
        'ics'       => 4
    ];

    /** @var array<int, string> */
    private const PROVIDER_LABELS = [
        0 => 'Apple iCloud',
        1 => 'CalDAV',
        2 => 'Google Calendar',
        3 => 'Microsoft 365 / Outlook.com',
        4 => 'ICS / Webcal'
    ];

    private const ACCOUNT_MODE_NEW = 'new';
    private const ACCOUNT_MODE_EXISTING = 'existing';

    private const ICALENDAR_AUTH_URL_ACCESS_KEY = 1;
    private const ICALENDAR_AUTH_USERNAME_PASSWORD = 2;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterAttributeInteger('SelectedCalendarAccountID', 0);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(
            (string) file_get_contents(__DIR__ . '/form.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') !== 'SetupWizard') {
                continue;
            }

            foreach ($action['popup']['pages'] as &$page) {
                if (($page['name'] ?? '') !== 'account') {
                    continue;
                }

                foreach ($page['items'] as &$item) {
                    if (($item['name'] ?? '') === 'ExistingAccountID') {
                        $item['options'] = $this->calendarAccountSelectOptions();
                        break;
                    }
                }
                unset($item);
                break;
            }
            unset($page);
            break;
        }
        unset($action);

        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'WizardProvider':
                $provider = trim((string) $Value);
                $this->assertSupportedProvider($provider);
                $this->SetBuffer('WizardProvider', $provider);
                $this->SetBuffer('WizardAccountSelection', '');
                $this->SetBuffer('WizardConnectionVerified', '0');
                break;

            case 'WizardProviderUndo':
                $this->SetBuffer('WizardProvider', '');
                $this->SetBuffer('WizardAccountSelection', '');
                $this->SetBuffer('WizardConnectionVerified', '0');
                break;

            case 'WizardPrepareAccount':
                $this->prepareWizardAccount($Value);
                break;

            case 'WizardAccountSelectionUndo':
                $this->cleanupPreparedWizardAccount();
                $this->SetBuffer('WizardAccountSelection', '');
                $this->SetBuffer('WizardConnectionVerified', '0');
                break;

            case 'WizardFinishAccount':
                $this->finishWizardAccount();
                break;

            default:
                parent::RequestAction($Ident, $Value);
        }
    }

    public function ValidateWizardAccountSelection(
        string $AccountMode,
        int $ExistingAccountID,
        string $AccountName
    ): string {
        try {
            $this->assertWizardAccountSelectionValid(
                $this->GetBuffer('WizardProvider'),
                $AccountMode,
                $ExistingAccountID,
                $AccountName
            );
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    public function GetWizardProviderPage(): string
    {
        return match ($this->wizardProvider()) {
            'apple'     => 'apple',
            'caldav'    => 'caldav',
            'google'    => 'google',
            'microsoft' => 'microsoft',
            'ics'       => 'ics'
        };
    }

    public function ValidateWizardAppleConfiguration(string $Username, string $Password): string
    {
        try {
            $accountID = $this->wizardAccountID();
            $this->assertWizardProviderMatchesAccount($accountID, 'apple');

            $username = trim($Username);
            if ($username === '') {
                $username = trim((string) IPS_GetProperty($accountID, 'Username'));
            }
            $password = $Password;
            if ($password === '') {
                $password = (string) IPS_GetProperty($accountID, 'Password');
            }

            if ($username === '') {
                throw new InvalidArgumentException($this->Translate('Please enter the Apple Account email address.'));
            }
            if ($password === '') {
                throw new InvalidArgumentException($this->Translate('Please enter the app-specific password.'));
            }

            IPS_SetProperty($accountID, 'ServerURL', self::APPLE_CALDAV_URL);
            IPS_SetProperty($accountID, 'Username', $username);
            IPS_SetProperty($accountID, 'Password', $password);
            IPS_SetProperty($accountID, 'Active', false);
            IPS_ApplyChanges($accountID);

            return $this->testWizardConnection($accountID);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $this->SetBuffer('WizardConnectionVerified', '0');
            return $exception->getMessage();
        }
    }

    public function ValidateWizardCalDAVConfiguration(
        string $ServerURL,
        string $Username,
        string $Password
    ): string {
        try {
            $accountID = $this->wizardAccountID();
            $this->assertWizardProviderMatchesAccount($accountID, 'caldav');

            $serverURL = trim($ServerURL);
            if ($serverURL === '') {
                $serverURL = trim((string) IPS_GetProperty($accountID, 'ServerURL'));
            }
            if ($serverURL === '' || filter_var($serverURL, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException($this->Translate('Please enter a valid CalDAV server URL.'));
            }

            IPS_SetProperty($accountID, 'ServerURL', $serverURL);
            if (trim($Username) !== '') {
                IPS_SetProperty($accountID, 'Username', trim($Username));
            }
            if ($Password !== '') {
                IPS_SetProperty($accountID, 'Password', $Password);
            }
            IPS_SetProperty($accountID, 'Active', false);
            IPS_ApplyChanges($accountID);

            return $this->testWizardConnection($accountID);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $this->SetBuffer('WizardConnectionVerified', '0');
            return $exception->getMessage();
        }
    }

    public function ValidateWizardICalendarConfiguration(
        string $ServerURL,
        string $CalendarName,
        int $AuthenticationMode,
        string $Username,
        string $Password
    ): string {
        try {
            $accountID = $this->wizardAccountID();
            $this->assertWizardProviderMatchesAccount($accountID, 'ics');

            $serverURL = trim($ServerURL);
            if ($serverURL === '') {
                $storedServerURL = trim((string) IPS_GetProperty($accountID, 'ServerURL'));
                if ($storedServerURL !== '' && $storedServerURL !== self::APPLE_CALDAV_URL) {
                    return $this->testWizardConnection($accountID);
                }

                throw new InvalidArgumentException($this->Translate('Please enter an iCalendar URL.'));
            }

            $scheme = strtolower((string) parse_url($serverURL, PHP_URL_SCHEME));
            if (filter_var(
                str_starts_with(strtolower($serverURL), 'webcal://')
                    ? 'https://' . substr($serverURL, 9)
                    : $serverURL,
                FILTER_VALIDATE_URL
            ) === false || !in_array($scheme, ['http', 'https', 'webcal'], true)) {
                throw new InvalidArgumentException($this->Translate('Please enter a valid iCalendar URL.'));
            }

            $calendarName = trim($CalendarName);
            if ($calendarName === '') {
                $calendarName = trim((string) IPS_GetProperty($accountID, 'CalendarName'));
            }
            if ($calendarName === '') {
                throw new InvalidArgumentException($this->Translate('Please enter a calendar name.'));
            }

            if (!in_array(
                $AuthenticationMode,
                [self::ICALENDAR_AUTH_URL_ACCESS_KEY, self::ICALENDAR_AUTH_USERNAME_PASSWORD],
                true
            )) {
                throw new InvalidArgumentException($this->Translate('Please select an iCalendar authentication mode.'));
            }

            $username = trim($Username);
            $password = $Password;
            if ($AuthenticationMode === self::ICALENDAR_AUTH_USERNAME_PASSWORD) {
                if ($username === '') {
                    $username = trim((string) IPS_GetProperty($accountID, 'Username'));
                }
                if ($password === '') {
                    $password = (string) IPS_GetProperty($accountID, 'Password');
                }
                if ($username === '') {
                    throw new InvalidArgumentException($this->Translate('Please enter the iCalendar username.'));
                }
                if ($password === '') {
                    throw new InvalidArgumentException($this->Translate('Please enter the iCalendar password.'));
                }
            } else {
                $username = '';
                $password = '';
            }

            IPS_SetProperty($accountID, 'ServerURL', $serverURL);
            IPS_SetProperty($accountID, 'CalendarName', $calendarName);
            IPS_SetProperty($accountID, 'ICalendarAuthenticationMode', $AuthenticationMode);
            IPS_SetProperty($accountID, 'Username', $username);
            IPS_SetProperty($accountID, 'Password', $password);
            IPS_SetProperty($accountID, 'Active', false);
            IPS_ApplyChanges($accountID);

            return $this->testWizardConnection($accountID);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $this->SetBuffer('WizardConnectionVerified', '0');
            return $exception->getMessage();
        }
    }

    public function BeginWizardOAuth(): string
    {
        $accountID = $this->wizardAccountID();
        $provider = $this->wizardProvider();
        $this->assertWizardProviderMatchesAccount($accountID, $provider);

        $result = match ($provider) {
            'google'    => IPSKALACC_ConnectGoogle($accountID),
            'microsoft' => IPSKALACC_ConnectMicrosoft($accountID),
            default     => throw new RuntimeException($this->Translate('OAuth is not used for the selected calendar provider.'))
        };

        if (!str_starts_with($result, 'http://') && !str_starts_with($result, 'https://')) {
            throw new RuntimeException($result);
        }

        return $result;
    }

    public function ValidateWizardOAuthConnection(): string
    {
        try {
            $accountID = $this->wizardAccountID();
            $provider = $this->wizardProvider();
            if (!in_array($provider, ['google', 'microsoft'], true)) {
                throw new RuntimeException($this->Translate('OAuth is not used for the selected calendar provider.'));
            }

            $this->assertWizardProviderMatchesAccount($accountID, $provider);
            $status = json_decode(
                IPSKALACC_GetAccountStatus($accountID),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($status) || !($status['connected'] ?? false)) {
                return $provider === 'google'
                    ? $this->Translate('Please connect the Google account before continuing.')
                    : $this->Translate('Please connect the Microsoft account before continuing.');
            }

            return $this->testWizardConnection($accountID);
        } catch (JsonException | RuntimeException $exception) {
            $this->SetBuffer('WizardConnectionVerified', '0');
            return $exception->getMessage();
        }
    }

    public function ValidateWizardConfirmation(): string
    {
        try {
            $this->wizardAccountID();
            if ($this->GetBuffer('WizardConnectionVerified') !== '1') {
                throw new InvalidArgumentException(
                    $this->Translate('The calendar account connection has not been verified yet.')
                );
            }
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    private function prepareWizardAccount(mixed $value): void
    {
        $selection = $this->decodeWizardAccountSelection($value);
        $provider = $this->wizardProvider();

        $this->assertWizardAccountSelectionValid(
            $provider,
            $selection['mode'],
            $selection['existingAccountID'],
            $selection['accountName']
        );

        $this->cleanupPreparedWizardAccount();

        if ($selection['mode'] === self::ACCOUNT_MODE_EXISTING) {
            $accountID = $selection['existingAccountID'];
            $this->SetBuffer('WizardCreatedAccountID', '0');
        } else {
            $accountID = $this->createCalendarAccount($provider, $selection['accountName']);
            $this->SetBuffer('WizardCreatedAccountID', (string) $accountID);
        }

        $this->SetBuffer(
            'WizardAccountSelection',
            json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );
        $this->SetBuffer('WizardAccountID', (string) $accountID);
        $this->SetBuffer('WizardConnectionVerified', '0');
    }

    private function finishWizardAccount(): void
    {
        $validationError = $this->ValidateWizardConfirmation();
        if ($validationError !== '') {
            throw new RuntimeException($validationError);
        }

        $accountID = $this->wizardAccountID();
        IPS_SetProperty($accountID, 'Active', true);
        IPS_ApplyChanges($accountID);

        $this->WriteAttributeInteger('SelectedCalendarAccountID', $accountID);
        $this->SetBuffer('WizardProvider', '');
        $this->SetBuffer('WizardAccountSelection', '');
        $this->SetBuffer('WizardAccountID', '');
        $this->SetBuffer('WizardCreatedAccountID', '');
        $this->SetBuffer('WizardConnectionVerified', '0');
    }

    private function createCalendarAccount(string $provider, string $accountName): int
    {
        $accountID = 0;

        try {
            $accountID = IPS_CreateInstance(self::CALENDAR_ACCOUNT_MODULE_ID);
            IPS_SetName($accountID, trim($accountName));

            $parentID = IPS_GetParent($this->InstanceID);
            if ($parentID > 0) {
                IPS_SetParent($accountID, $parentID);
            }

            IPS_SetProperty($accountID, 'Provider', self::PROVIDERS[$provider]);
            IPS_SetProperty($accountID, 'Active', false);
            IPS_ApplyChanges($accountID);
        } catch (Throwable $exception) {
            if ($accountID > 0 && IPS_InstanceExists($accountID)) {
                IPS_DeleteInstance($accountID);
            }

            throw new RuntimeException(
                $this->Translate('The calendar account could not be created.') . ' ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        return $accountID;
    }

    private function cleanupPreparedWizardAccount(): void
    {
        $createdAccountID = (int) $this->GetBuffer('WizardCreatedAccountID');
        if ($createdAccountID > 0 && IPS_InstanceExists($createdAccountID)) {
            IPS_DeleteInstance($createdAccountID);
        }

        $this->SetBuffer('WizardAccountID', '');
        $this->SetBuffer('WizardCreatedAccountID', '');
        $this->SetBuffer('WizardConnectionVerified', '0');
    }

    private function testWizardConnection(int $accountID): string
    {
        try {
            $result = json_decode(
                IPSKALACC_TestConnection($accountID),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            $this->SetBuffer('WizardConnectionVerified', '0');
            return $this->Translate('The calendar account returned an invalid connection test result.');
        }

        if (!is_array($result) || !($result['success'] ?? false)) {
            $this->SetBuffer('WizardConnectionVerified', '0');
            $message = trim((string) ($result['message'] ?? ''));

            return $message !== ''
                ? $message
                : $this->Translate('The calendar account connection test failed.');
        }

        $this->SetBuffer('WizardConnectionVerified', '1');

        return '';
    }

    private function wizardAccountID(): int
    {
        $accountID = (int) $this->GetBuffer('WizardAccountID');
        if ($accountID <= 0 || !IPS_InstanceExists($accountID)) {
            throw new RuntimeException($this->Translate('The prepared calendar account no longer exists.'));
        }

        $accountIDs = IPS_GetInstanceListByModuleID(self::CALENDAR_ACCOUNT_MODULE_ID);
        if (!in_array($accountID, $accountIDs, true)) {
            throw new RuntimeException($this->Translate('The prepared instance is not a calendar account.'));
        }

        return $accountID;
    }

    private function wizardProvider(): string
    {
        $provider = $this->GetBuffer('WizardProvider');
        $this->assertSupportedProvider($provider);

        return $provider;
    }

    private function assertWizardProviderMatchesAccount(int $accountID, string $provider): void
    {
        $this->assertSupportedProvider($provider);
        if ((int) IPS_GetProperty($accountID, 'Provider') !== self::PROVIDERS[$provider]) {
            throw new RuntimeException(
                $this->Translate('The selected calendar account uses a different calendar provider.')
            );
        }
    }

    /**
     * @return list<array{caption: string, value: int}>
     */
    private function calendarAccountSelectOptions(): array
    {
        $options = [
            [
                'caption' => $this->Translate('Please select an existing calendar account.'),
                'value'   => 0
            ]
        ];

        $accounts = [];
        foreach (IPS_GetInstanceListByModuleID(self::CALENDAR_ACCOUNT_MODULE_ID) as $accountID) {
            $provider = (int) IPS_GetProperty($accountID, 'Provider');
            $providerLabel = self::PROVIDER_LABELS[$provider] ?? $this->Translate('Calendar account');
            $name = trim(IPS_GetName($accountID));
            if ($name === '') {
                $name = $this->Translate('Calendar account');
            }

            $accounts[] = [
                'caption' => sprintf(
                    '%s — %s (#%d)',
                    $this->Translate($providerLabel),
                    $name,
                    $accountID
                ),
                'value' => $accountID
            ];
        }

        usort(
            $accounts,
            static fn (array $left, array $right): int => strnatcasecmp($left['caption'], $right['caption'])
                ?: ($left['value'] <=> $right['value'])
        );

        return array_merge($options, $accounts);
    }

    /**
     * @return array{mode: string, existingAccountID: int, accountName: string}
     */
    private function decodeWizardAccountSelection(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($this->Translate('The calendar account selection is invalid.'));
        }

        try {
            $selection = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException($this->Translate('The calendar account selection is invalid.'));
        }

        if (!is_array($selection)) {
            throw new InvalidArgumentException($this->Translate('The calendar account selection is invalid.'));
        }

        return [
            'mode'              => (string) ($selection['mode'] ?? ''),
            'existingAccountID' => (int) ($selection['existingAccountID'] ?? 0),
            'accountName'       => trim((string) ($selection['accountName'] ?? ''))
        ];
    }

    private function assertWizardAccountSelectionValid(
        string $provider,
        string $accountMode,
        int $existingAccountID,
        string $accountName
    ): void {
        $this->assertSupportedProvider($provider);

        if ($accountMode === self::ACCOUNT_MODE_NEW) {
            if (trim($accountName) === '') {
                throw new InvalidArgumentException($this->Translate('Please enter a name for the calendar account.'));
            }

            return;
        }

        if ($accountMode !== self::ACCOUNT_MODE_EXISTING) {
            throw new InvalidArgumentException($this->Translate('Please choose how the calendar account should be provided.'));
        }

        if ($existingAccountID <= 0 || !IPS_InstanceExists($existingAccountID)) {
            throw new InvalidArgumentException($this->Translate('Please select an existing calendar account.'));
        }

        $accountIDs = IPS_GetInstanceListByModuleID(self::CALENDAR_ACCOUNT_MODULE_ID);
        if (!in_array($existingAccountID, $accountIDs, true)) {
            throw new InvalidArgumentException($this->Translate('The selected instance is not a calendar account.'));
        }

        if ((int) IPS_GetProperty($existingAccountID, 'Provider') !== self::PROVIDERS[$provider]) {
            throw new InvalidArgumentException(
                $this->Translate('The selected calendar account uses a different calendar provider.')
            );
        }
    }

    private function assertSupportedProvider(string $provider): void
    {
        if (!array_key_exists($provider, self::PROVIDERS)) {
            throw new InvalidArgumentException($this->Translate('Please select a calendar provider.'));
        }
    }
}
