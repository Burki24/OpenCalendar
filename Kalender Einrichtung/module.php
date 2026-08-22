<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\DataFlowHelper;

require_once __DIR__ . '/../libs/helper/DataFlowHelper.php';

class OpenCalendarDiscovery extends IPSModuleStrict
{
    use DataFlowHelper;

    private const CALENDAR_ACCOUNT_MODULE_ID = '{966D6119-7FF3-5CA5-06C3-536FBF8100C4}';
    private const DATA_ID_TO_CALENDAR_ACCOUNT = '{4E535B1D-69C7-AC77-1372-0282B21BAEC9}';
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
        $this->RegisterAttributeString('SelectedCalendarIDs', '[]');
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
                $pageName = (string) ($page['name'] ?? '');
                if (!in_array($pageName, ['account', 'calendars'], true)) {
                    continue;
                }

                foreach ($page['items'] as &$item) {
                    if ($pageName === 'account' && ($item['name'] ?? '') === 'ExistingAccountID') {
                        $item['options'] = $this->calendarAccountSelectOptions();
                    } elseif ($pageName === 'calendars' && ($item['name'] ?? '') === 'WizardCalendars') {
                        $item['values'] = $this->wizardCalendarListValues($this->readWizardCalendars());
                    }
                }
                unset($item);
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
                $this->clearWizardCalendarSelection(true);
                break;

            case 'WizardProviderUndo':
                $this->SetBuffer('WizardProvider', '');
                $this->SetBuffer('WizardAccountSelection', '');
                $this->SetBuffer('WizardConnectionVerified', '0');
                $this->clearWizardCalendarSelection(true);
                break;

            case 'WizardPrepareAccount':
                $this->prepareWizardAccount($Value);
                break;

            case 'WizardAccountSelectionUndo':
                $this->cleanupPreparedWizardAccount();
                $this->SetBuffer('WizardAccountSelection', '');
                $this->SetBuffer('WizardConnectionVerified', '0');
                $this->clearWizardCalendarSelection(true);
                break;

            case 'WizardSelectCalendars':
                $this->storeWizardCalendarSelection($Value);
                break;

            case 'WizardCalendarSelectionUndo':
                $this->SetBuffer('WizardSelectedCalendarIDs', '[]');
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

    public function ValidateWizardCalendarSelection(string $CalendarSelection): string
    {
        try {
            $selectedCalendarIDs = $this->selectedCalendarIDsFromWizardValue($CalendarSelection);
            if ($selectedCalendarIDs === []) {
                throw new InvalidArgumentException($this->Translate('Please select at least one calendar.'));
            }
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $exception->getMessage();
        }

        return '';
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
            if ($this->readWizardSelectedCalendarIDs() === []) {
                throw new InvalidArgumentException($this->Translate('Please select at least one calendar.'));
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
        $this->clearWizardCalendarSelection(true);
    }

    private function finishWizardAccount(): void
    {
        $validationError = $this->ValidateWizardConfirmation();
        if ($validationError !== '') {
            throw new RuntimeException($validationError);
        }

        $accountID = $this->wizardAccountID();
        $createdAccountID = (int) $this->GetBuffer('WizardCreatedAccountID');
        if ($createdAccountID === $accountID) {
            IPS_SetProperty($accountID, 'Active', true);
            IPS_ApplyChanges($accountID);
        }

        $this->WriteAttributeInteger('SelectedCalendarAccountID', $accountID);
        $this->WriteAttributeString('SelectedCalendarIDs', $this->GetBuffer('WizardSelectedCalendarIDs'));
        $this->SetBuffer('WizardProvider', '');
        $this->SetBuffer('WizardAccountSelection', '');
        $this->SetBuffer('WizardAccountID', '');
        $this->SetBuffer('WizardCreatedAccountID', '');
        $this->SetBuffer('WizardConnectionVerified', '0');
        $this->clearWizardCalendarSelection(true);
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
        $this->clearWizardCalendarSelection(true);
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

        try {
            $calendars = $this->discoverWizardCalendars($accountID);
            $this->SetBuffer(
                'WizardCalendars',
                json_encode($calendars, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $this->SetBuffer('WizardSelectedCalendarIDs', '[]');
            $this->UpdateFormField(
                'WizardCalendars',
                'values',
                json_encode(
                    $this->wizardCalendarListValues($calendars),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
        } catch (Throwable $exception) {
            $this->SetBuffer('WizardConnectionVerified', '0');
            return $exception->getMessage();
        }

        $this->SetBuffer('WizardConnectionVerified', '1');

        return '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverWizardCalendars(int $accountID): array
    {
        $responseJson = IPSKALACC_ForwardData(
            $accountID,
            $this->EncodeDataFlowMessage(
                self::DATA_ID_TO_CALENDAR_ACCOUNT,
                [
                    'Operation' => 'DiscoverCalendars',
                    'RequestID' => bin2hex(random_bytes(8))
                ]
            )
        );
        if ($responseJson === '') {
            throw new RuntimeException($this->Translate('The calendar account did not return calendar data.'));
        }

        try {
            $response = json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                $this->Translate('The calendar account returned invalid calendar data.'),
                0,
                $exception
            );
        }
        if (!is_array($response) || !($response['Success'] ?? false)) {
            $error = is_array($response) ? trim((string) ($response['Error'] ?? '')) : '';
            throw new RuntimeException(
                $error !== '' ? $error : $this->Translate('Calendar discovery failed.')
            );
        }

        $payload = $response['Payload'] ?? null;
        if (!is_array($payload)) {
            throw new RuntimeException($this->Translate('The calendar account returned invalid calendar data.'));
        }

        $calendars = array_values(array_filter(
            $payload,
            static fn (mixed $calendar): bool => is_array($calendar)
                && trim((string) ($calendar['id'] ?? '')) !== ''
        ));
        if ($calendars === []) {
            throw new RuntimeException($this->Translate('No calendars were found for this account.'));
        }

        return $calendars;
    }

    /**
     * @param list<array<string, mixed>> $calendars
     * @return list<array<string, mixed>>
     */
    private function wizardCalendarListValues(array $calendars): array
    {
        $selectedCalendarIDs = $this->readWizardSelectedCalendarIDs();
        $hasStoredSelection = $selectedCalendarIDs !== [];
        $hasPrimaryCalendar = count(array_filter(
            $calendars,
            static fn (array $calendar): bool => (bool) ($calendar['primary'] ?? false)
        )) > 0;
        $singleCalendar = count($calendars) === 1;
        $values = [];

        foreach ($calendars as $calendar) {
            $calendarID = trim((string) ($calendar['id'] ?? ''));
            if ($calendarID === '') {
                continue;
            }

            $name = trim((string) ($calendar['name'] ?? ''));
            if ($name === '') {
                $name = $calendarID;
            }
            $capabilities = is_array($calendar['capabilities'] ?? null)
                ? $calendar['capabilities']
                : [];
            $canWrite = (bool) ($capabilities['create'] ?? false)
                || (bool) ($capabilities['update'] ?? false)
                || (bool) ($capabilities['delete'] ?? false);
            $selected = $hasStoredSelection
                ? in_array($calendarID, $selectedCalendarIDs, true)
                : (($hasPrimaryCalendar && (bool) ($calendar['primary'] ?? false)) || $singleCalendar);

            $values[] = [
                'selected'   => $selected,
                'name'       => $name,
                'access'     => $this->Translate($canWrite ? 'Read and write' : 'Read only'),
                'primary'    => (bool) ($calendar['primary'] ?? false) ? $this->Translate('Yes') : '',
                'calendarId' => $calendarID
            ];
        }

        return $values;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readWizardCalendars(): array
    {
        try {
            $calendars = json_decode($this->GetBuffer('WizardCalendars') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($calendars) ? array_values(array_filter($calendars, 'is_array')) : [];
    }

    /**
     * @return list<string>
     */
    private function readWizardSelectedCalendarIDs(): array
    {
        try {
            $calendarIDs = json_decode(
                $this->GetBuffer('WizardSelectedCalendarIDs') ?: '[]',
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return [];
        }

        if (!is_array($calendarIDs)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $calendarID): string => trim((string) $calendarID), $calendarIDs),
            static fn (string $calendarID): bool => $calendarID !== ''
        )));
    }

    /**
     * @return list<string>
     */
    private function selectedCalendarIDsFromWizardValue(string $value): array
    {
        try {
            $rows = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException($this->Translate('The calendar selection is invalid.'));
        }
        if (!is_array($rows)) {
            throw new InvalidArgumentException($this->Translate('The calendar selection is invalid.'));
        }

        $availableCalendarIDs = array_values(array_filter(array_map(
            static fn (array $calendar): string => trim((string) ($calendar['id'] ?? '')),
            $this->readWizardCalendars()
        )));
        if ($availableCalendarIDs === []) {
            throw new RuntimeException($this->Translate('No discovered calendars are available.'));
        }

        $selectedCalendarIDs = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !($row['selected'] ?? false)) {
                continue;
            }
            $calendarID = trim((string) ($row['calendarId'] ?? ''));
            if ($calendarID === '' || !in_array($calendarID, $availableCalendarIDs, true)) {
                throw new InvalidArgumentException($this->Translate('The calendar selection is invalid.'));
            }
            $selectedCalendarIDs[] = $calendarID;
        }

        return array_values(array_unique($selectedCalendarIDs));
    }

    private function storeWizardCalendarSelection(mixed $value): void
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($this->Translate('The calendar selection is invalid.'));
        }

        $selectedCalendarIDs = $this->selectedCalendarIDsFromWizardValue($value);
        if ($selectedCalendarIDs === []) {
            throw new InvalidArgumentException($this->Translate('Please select at least one calendar.'));
        }

        $this->SetBuffer(
            'WizardSelectedCalendarIDs',
            json_encode($selectedCalendarIDs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function clearWizardCalendarSelection(bool $clearDiscovery): void
    {
        if ($clearDiscovery) {
            $this->SetBuffer('WizardCalendars', '[]');
        }
        $this->SetBuffer('WizardSelectedCalendarIDs', '[]');
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
