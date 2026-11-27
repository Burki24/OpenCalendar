<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClient;
use IPSKalender\CalendarProviderType;
use IPSKalender\ICalendarAuthentication;
use IPSKalender\ICalendarFeedProvider;
use IPSKalender\SynchronizationSchedule;

require_once __DIR__ . '/../libs/CalendarProviderType.php';
require_once __DIR__ . '/../libs/CalendarHttpClient.php';
require_once __DIR__ . '/../libs/ICalendarAuthentication.php';
require_once __DIR__ . '/../libs/ICalendarFeedProvider.php';
require_once __DIR__ . '/../libs/SynchronizationSchedule.php';

class OpenCalendarDiscovery extends IPSModuleStrict
{
    private const CALENDAR_ACCOUNT_MODULE_ID = '{966D6119-7FF3-5CA5-06C3-536FBF8100C4}';
    private const CALENDAR_CONFIGURATOR_MODULE_ID = '{4A013D9D-3611-9900-5815-A8EC8A91287D}';
    private const CALENDAR_MODULE_ID = '{227B63E4-4223-316B-76E9-FD3849689562}';
    private const CALENDAR_VIEW_MODULE_ID = '{1B19AB6B-9052-EA85-F158-86A13FE6F5BA}';
    private const APPLE_CALDAV_URL = 'https://caldav.icloud.com';

    /** @var array<int, string> */
    private const PROVIDER_LABELS = [
        CalendarProviderType::APPLE     => 'Apple iCloud',
        CalendarProviderType::CALDAV    => 'CalDAV',
        CalendarProviderType::GOOGLE    => 'Google Calendar',
        CalendarProviderType::MICROSOFT => 'Microsoft 365 / Outlook.com',
        CalendarProviderType::ICS       => 'ICS / Webcal',
        CalendarProviderType::LOCAL     => 'Symcon - Calendar'
    ];

    /** @var array<string, string> */
    private const PROVIDER_INSTANCE_PREFIXES = [
        'apple'     => 'Apple',
        'caldav'    => 'CalDAV',
        'google'    => 'Google',
        'microsoft' => 'O365',
        'ics'       => 'ICS',
        'local'     => 'Symcon'
    ];

    private const ACCOUNT_MODE_NEW = 'new';
    private const ACCOUNT_MODE_EXISTING = 'existing';
    private const VIEW_MODE_NEW = 'new';
    private const VIEW_MODE_EXISTING = 'existing';

    private const ICALENDAR_AUTH_URL_ACCESS_KEY = 1;
    private const ICALENDAR_AUTH_USERNAME_PASSWORD = 2;

    /**
     * Registers the persistent setup state used by the OpenCalendar discovery wizard.
     */
    public function Create(): void
    {
        parent::Create();

        $this->RegisterAttributeInteger('SelectedCalendarAccountID', 0);
        $this->RegisterAttributeInteger('SelectedCalendarConfiguratorID', 0);
        $this->RegisterAttributeString('SelectedCalendarIDs', '[]');
        $this->RegisterAttributeString('SelectedCalendarInstanceIDs', '[]');
        $this->RegisterAttributeInteger('SelectedCalendarViewID', 0);
        $this->RegisterAttributeString('LastSetupResult', '{}');
    }

    /**
     * Builds the discovery wizard form and injects current account, calendar, view, and result state.
     *
     * @return string JSON-encoded configuration form.
     */
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
                if (!in_array($pageName, ['account', 'local', 'calendars', 'view', 'result'], true)) {
                    continue;
                }

                $localAccountID = (int) $this->GetBuffer('WizardAccountID');
                $localNew = $localAccountID <= 0
                    || (int) $this->GetBuffer('WizardCreatedAccountID') === $localAccountID;

                foreach ($page['items'] as &$item) {
                    if ($pageName === 'account' && ($item['name'] ?? '') === 'ExistingAccountID') {
                        $item['options'] = $this->calendarAccountSelectOptions();
                    } elseif ($pageName === 'calendars' && ($item['name'] ?? '') === 'WizardCalendars') {
                        $item['values'] = $this->wizardCalendarListValues($this->readWizardCalendars());
                    } elseif ($pageName === 'view' && ($item['name'] ?? '') === 'ExistingViewID') {
                        $item['options'] = $this->calendarViewSelectOptions();
                    } elseif ($pageName === 'local' && in_array(
                        (string) ($item['name'] ?? ''),
                        ['WizardLocalCalendarName', 'WizardLocalCalendarColor'],
                        true
                    )) {
                        $item['visible'] = $localNew;
                    } elseif ($pageName === 'local' && ($item['name'] ?? '') === 'WizardLocalExistingHint') {
                        $item['visible'] = !$localNew;
                    } elseif ($pageName === 'result') {
                        $result = $this->readLastSetupResult();
                        $captions = $this->wizardResultCaptions($result);
                        $itemName = (string) ($item['name'] ?? '');
                        if (isset($captions[$itemName])) {
                            $item['caption'] = $captions[$itemName];
                        }
                        if (in_array($itemName, [
                            'ResultSynchronizationDetails',
                            'ResultRetryVerification',
                            'ResultSynchronizationHintRetry',
                            'ResultSynchronizationHintManual',
                            'ResultSynchronizationHintDebug'
                        ], true)) {
                            $item['visible'] = $this->wizardResultNeedsVerificationHelp($result);
                        }
                    }
                }
                unset($item);
            }
            unset($page);
        }
        unset($action);

        foreach ($form['actions'] as $action) {
            if (($action['name'] ?? '') === 'SetupWizard') {
                $extensionWizard = $this->extensionWizardAction($action);
                $form['actions'][] = $extensionWizard;
                $form['actions'][] = $this->iCalendarSubscriptionWizardAction($extensionWizard, $action);
                break;
            }
        }

        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Handles discovery-wizard actions and forwards unsupported actions to the Symcon base module.
     *
     * @param string $Ident Action identifier supplied by the configuration form.
     * @param mixed  $Value Action payload supplied by the configuration form.
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'WizardProvider':
                $this->resetWizardCompletionState();
                $this->SetBuffer('WizardExtensionMode', '0');
                $providerSelection = $this->decodeWizardProviderSelection($Value);
                $provider = $providerSelection['provider'];
                $this->SetBuffer('WizardProvider', $provider);
                $this->SetBuffer(
                    'WizardCreateConfigurator',
                    $providerSelection['createConfigurator'] ? '1' : '0'
                );
                $this->SetBuffer('WizardAccountSelection', '');
                $this->SetBuffer('WizardConnectionVerified', '0');
                $this->clearWizardCalendarSelection(true);
                $this->clearWizardCalendarViewSelection();
                break;

            case 'WizardProviderUndo':
                $this->resetWizardCompletionState();
                $this->SetBuffer('WizardProvider', '');
                $this->SetBuffer('WizardCreateConfigurator', '0');
                $this->SetBuffer('WizardAccountSelection', '');
                $this->SetBuffer('WizardConnectionVerified', '0');
                $this->clearWizardCalendarSelection(true);
                $this->clearWizardCalendarViewSelection();
                break;

            case 'WizardPrepareAccount':
                $this->resetWizardCompletionState();
                $this->prepareWizardAccount($Value);
                break;

            case 'WizardAccountSelectionUndo':
                $this->resetWizardCompletionState();
                $this->cleanupPreparedWizardAccount();
                $this->SetBuffer('WizardAccountSelection', '');
                $this->SetBuffer('WizardConnectionVerified', '0');
                $this->clearWizardCalendarSelection(true);
                $this->clearWizardCalendarViewSelection();
                break;

            case 'WizardCalendarSelectionChanged':
                $this->resetWizardCompletionState();
                $this->updateWizardCalendarSelection($Value);
                break;

            case 'WizardSelectCalendars':
                $this->resetWizardCompletionState();
                $this->confirmWizardCalendarSelection();
                break;

            case 'WizardCalendarSelectionUndo':
                $this->resetWizardCompletionState();
                $this->SetBuffer('WizardSelectedCalendarIDs', '[]');
                $this->clearWizardCalendarViewSelection();
                break;

            case 'WizardSelectCalendarView':
                $this->resetWizardCompletionState();
                $this->storeWizardCalendarViewSelection($Value);
                break;

            case 'WizardCalendarViewSelectionUndo':
                $this->resetWizardCompletionState();
                $this->clearWizardCalendarViewSelection();
                break;

            case 'WizardFinishAccount':
                $this->finishWizardAccount();
                break;

            case 'WizardFinishICSSubscription':
                $this->finishWizardICSSubscription($Value);
                break;

            case 'WizardRetryVerification':
                $this->retryWizardVerification();
                break;

            case 'WizardResultNext':
                $this->continueWizardFromResult((string) $Value);
                break;

            case 'WizardComplete':
                $this->completeWizardSession();
                break;

            default:
                parent::RequestAction($Ident, $Value);
        }
    }

    /**
     * Validates whether the wizard can use the selected existing or new calendar account.
     *
     * @param string $AccountMode Existing-account or new-account mode.
     * @param int    $ExistingAccountID Existing account instance ID when applicable.
     * @param string $AccountName Name for a newly created account.
     * @return string Empty string when valid, otherwise a localized validation message.
     */
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

    /**
     * Validates and prepares one existing account for the shorter calendar-addition flow.
     *
     * @param int $AccountID Existing active Calendar Account instance ID.
     * @return string Empty on success or a localized validation error.
     */
    public function ValidateWizardExtensionAccount(int $AccountID): string
    {
        try {
            if ($AccountID <= 0 || !IPS_InstanceExists($AccountID)) {
                throw new InvalidArgumentException($this->Translate('Please select an existing calendar account.'));
            }
            if (!in_array($AccountID, IPS_GetInstanceListByModuleID(self::CALENDAR_ACCOUNT_MODULE_ID), true)) {
                throw new InvalidArgumentException($this->Translate('The selected instance is not a calendar account.'));
            }
            $provider = $this->providerKeyForAccount($AccountID);
            if (!(bool) IPS_GetProperty($AccountID, 'Active')) {
                throw new InvalidArgumentException($this->Translate('Choose an active calendar account to add calendars.'));
            }

            $this->completeWizardSession();
            $this->SetBuffer('WizardExtensionMode', '1');
            $this->SetBuffer('WizardProvider', $provider);
            $this->prepareWizardAccount(json_encode([
                'mode'              => self::ACCOUNT_MODE_EXISTING,
                'existingAccountID' => $AccountID,
                'accountName'       => ''
            ], JSON_THROW_ON_ERROR));

            $connectionError = $this->testWizardConnection($AccountID);
            if ($connectionError !== '') {
                return $connectionError;
            }

            $existingCalendarIDs = array_keys($this->existingCalendarInstancesForAccount($AccountID));
            $newCalendarIDs = array_values(array_filter(array_map(
                static fn (array $calendar): string => trim((string) ($calendar['id'] ?? '')),
                $this->readWizardCalendars()
            ), static fn (string $calendarID): bool => $calendarID !== ''
                && !in_array($calendarID, $existingCalendarIDs, true)));
            $selectedCalendarIDs = count($newCalendarIDs) === 1 ? $newCalendarIDs : [];
            $this->SetBuffer('WizardSelectedCalendarIDs', json_encode($selectedCalendarIDs, JSON_THROW_ON_ERROR));
            $this->UpdateFormField(
                'ExtensionCalendars',
                'values',
                json_encode(
                    $this->wizardCalendarListValues($this->readWizardCalendars()),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );

            return '';
        } catch (InvalidArgumentException | RuntimeException | JsonException $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * Selects an existing active ICS account without changing its stored feeds.
     */
    public function ValidateWizardICSSubscriptionAccount(int $AccountID): string
    {
        if ($AccountID <= 0 || !IPS_InstanceExists($AccountID)
            || !in_array($AccountID, IPS_GetInstanceListByModuleID(self::CALENDAR_ACCOUNT_MODULE_ID), true)) {
            return $this->Translate('Please select an existing calendar account.');
        }
        if ((int) IPS_GetProperty($AccountID, 'Provider') !== CalendarProviderType::ICS) {
            return $this->Translate('Choose an ICS/Webcal calendar account.');
        }
        if (!(bool) IPS_GetProperty($AccountID, 'Active')) {
            return $this->Translate('Choose an active calendar account to add calendars.');
        }

        $this->completeWizardSession();
        $this->SetBuffer('WizardExtensionMode', 'ics');
        $this->SetBuffer('WizardProvider', 'ics');
        $this->SetBuffer('WizardAccountID', (string) $AccountID);
        return '';
    }

    /**
     * Checks a new ICS/Webcal feed before the account configuration is changed.
     */
    public function ValidateWizardICSSubscriptionFeed(
        string $URL,
        string $Name,
        int $AuthenticationMode,
        string $Username,
        string $Password
    ): string {
        $this->SetBuffer('WizardConnectionVerified', '0');
        $this->SetBuffer('WizardICSPreviewHash', '');
        try {
            if ($this->GetBuffer('WizardExtensionMode') !== 'ics') {
                throw new InvalidArgumentException($this->Translate('Choose an ICS/Webcal calendar account.'));
            }
            $accountID = $this->wizardAccountID();
            $this->assertWizardProviderMatchesAccount($accountID, 'ics');
            if (!(bool) IPS_GetProperty($accountID, 'Active')) {
                throw new InvalidArgumentException($this->Translate('Choose an active calendar account to add calendars.'));
            }
            $feed = $this->normalizeWizardICSSubscription($URL, $Name, $AuthenticationMode, $Username, $Password);
            $this->assertWizardICSSubscriptionNotConfigured($accountID, $feed['url']);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $exception->getMessage();
        }

        try {
            $discovered = $this->inspectWizardICSFeed($accountID, $feed);
        } catch (Throwable) {
            return $this->Translate('The ICS/Webcal feed could not be checked. Verify its URL and credentials.');
        }

        $calendarID = (string) $discovered['id'];
        $calendar = [
            'id'             => $calendarID,
            'providerId'     => $calendarID,
            'name'           => $feed['name'],
            'url'            => '',
            'color'          => (string) ($discovered['color'] ?? ''),
            'capabilities'   => ['read' => true, 'create' => false, 'update' => false, 'delete' => false],
            'updateSchedule' => (int) IPS_GetProperty($accountID, 'UpdateSchedule'),
            'updateInterval' => (int) IPS_GetProperty($accountID, 'UpdateInterval')
        ];
        $this->SetBuffer('WizardCalendars', json_encode([$calendar], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->SetBuffer('WizardSelectedCalendarIDs', json_encode([$calendarID], JSON_THROW_ON_ERROR));
        $this->SetBuffer('WizardICSPreviewHash', hash('sha256', json_encode($feed, JSON_THROW_ON_ERROR)));
        $this->SetBuffer('WizardConnectionVerified', '1');
        return '';
    }

    /**
     * Returns the provider-specific wizard page for the currently selected provider.
     *
     * @return string Provider page identifier.
     */
    public function GetWizardProviderPage(): string
    {
        return match ($this->wizardProvider()) {
            'apple'     => 'apple',
            'caldav'    => 'caldav',
            'google'    => 'google',
            'microsoft' => 'microsoft',
            'ics'       => 'ics',
            'local'     => 'local'
        };
    }

    /**
     * Configures a new Symcon-local calendar or checks an existing local account.
     *
     * @param string $CalendarName Name of a new local calendar.
     * @param int $CalendarColor RGB color of a new local calendar.
     * @return string Empty string when valid, otherwise a localized error.
     */
    public function ValidateWizardLocalConfiguration(string $CalendarName, int $CalendarColor): string
    {
        try {
            $accountID = $this->wizardAccountID();
            $this->assertWizardProviderMatchesAccount($accountID, 'local');

            if ((int) $this->GetBuffer('WizardCreatedAccountID') === $accountID) {
                $name = trim($CalendarName);
                if ($name === '') {
                    throw new InvalidArgumentException($this->Translate('Please enter a local calendar name.'));
                }
                if ($CalendarColor < 0 || $CalendarColor > 0xFFFFFF) {
                    throw new InvalidArgumentException($this->Translate('Please select a valid calendar color.'));
                }

                IPS_SetProperty($accountID, 'LocalCalendarName', $name);
                IPS_SetProperty($accountID, 'LocalCalendarColor', $CalendarColor);
                IPS_ApplyChanges($accountID);
            }

            return $this->testWizardConnection($accountID);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $this->SetBuffer('WizardConnectionVerified', '0');
            return $exception->getMessage();
        }
    }

    /**
     * Applies and verifies Apple iCloud credentials entered in the wizard.
     *
     * @param string $Username Apple Account email address.
     * @param string $Password App-specific Apple password.
     * @return string Empty string on success, otherwise a localized validation or connection error.
     */
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

    /**
     * Applies and verifies generic CalDAV connection settings entered in the wizard.
     *
     * @param string $ServerURL CalDAV server URL.
     * @param string $Username Optional CalDAV username.
     * @param string $Password Optional CalDAV password.
     * @return string Empty string on success, otherwise a localized validation or connection error.
     */
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

    /**
     * Applies and verifies a single ICS/Webcal subscription entered in the wizard.
     *
     * @param string $ServerURL iCalendar feed URL.
     * @param string $CalendarName Display name for the subscription.
     * @param int    $AuthenticationMode Selected iCalendar authentication mode.
     * @param string $Username Optional HTTP authentication username.
     * @param string $Password Optional HTTP authentication password.
     * @return string Empty string on success, otherwise a localized validation or connection error.
     */
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

    /**
     * Starts the native OAuth flow for the currently selected Google or Microsoft account.
     *
     * @return string OAuth authorization URL.
     */
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

    /**
     * Verifies OAuth state and the provider connection for the prepared Google or Microsoft account.
     *
     * @return string Empty string on success, otherwise a localized validation or connection error.
     */
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

    /**
     * Validates that at least one currently discovered calendar is selected.
     *
     * @return string Empty string when valid, otherwise a localized validation message.
     */
    public function ValidateWizardCalendarSelection(): string
    {
        try {
            $this->assertWizardCalendarSelectionValid();
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    /**
     * Validates the existing or new Calendar View selected by the discovery wizard.
     *
     * @param string $ViewMode Existing-view or new-view mode.
     * @param int    $ExistingViewID Existing Calendar View instance ID when applicable.
     * @param string $ViewName Name for a newly created Calendar View.
     * @return string Empty string when valid, otherwise a localized validation message.
     */
    public function ValidateWizardCalendarViewSelection(
        string $ViewMode,
        int $ExistingViewID,
        string $ViewName
    ): string {
        try {
            $this->assertWizardCalendarViewSelectionValid($ViewMode, $ExistingViewID, $ViewName);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    /**
     * Validates the final wizard state before instances are created, reused, and verified.
     *
     * @return string Empty string when valid, otherwise a localized validation message.
     */
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
            $viewSelection = $this->readWizardCalendarViewSelection();
            $this->assertWizardCalendarViewSelectionValid(
                $viewSelection['mode'],
                $viewSelection['existingViewID'],
                $viewSelection['viewName']
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    /**
     * @param array{url: string, name: string, authenticationMode: int, username: string, password: string} $feed
     * @return array<string, mixed>
     */
    protected function inspectWizardICSFeed(int $accountID, array $feed): array
    {
        $credentials = ICalendarAuthentication::credentials(
            $feed['authenticationMode'],
            $feed['username'],
            $feed['password']
        );
        $provider = new ICalendarFeedProvider(
            new CalendarHttpClient(
                max(5, min(120, (int) IPS_GetProperty($accountID, 'RequestTimeout'))),
                (bool) IPS_GetProperty($accountID, 'VerifyTLS'),
                $credentials['username'],
                $credentials['password']
            ),
            $feed['url'],
            $feed['name']
        );
        $discovered = $provider->getCalendars()[0] ?? null;
        if (!is_array($discovered) || trim((string) ($discovered['id'] ?? '')) === '') {
            throw new RuntimeException('No calendar returned.');
        }
        return $discovered;
    }

    /**
     * Builds a read-only feed subscription flow that commits the new source on the final page.
     *
     * @param array<string, mixed> $extensionWizard Existing-account wizard with prepared view/result pages.
     * @param array<string, mixed> $setupWizard Main wizard for the first ICS/Webcal account.
     * @return array<string, mixed>
     */
    private function iCalendarSubscriptionWizardAction(array $extensionWizard, array $setupWizard): array
    {
        $accounts = $this->calendarAccountSelectOptions(true, CalendarProviderType::ICS);
        if (count($accounts) === 1) {
            $setupWizard['name'] = 'AddICSSubscriptionWizard';
            $setupWizard['caption'] = 'Add ICS/Webcal';
            $setupWizard['width'] = '240px';
            $setupWizard['popup']['caption'] = 'Add an ICS/Webcal subscription';
            foreach ($setupWizard['popup']['pages'] as &$page) {
                if (($page['name'] ?? '') !== 'provider') {
                    continue;
                }
                foreach ($page['items'] as &$item) {
                    if (($item['name'] ?? '') === 'Provider') {
                        $item['value'] = 'ics';
                        $item['options'] = array_values(array_filter(
                            $item['options'],
                            static fn (array $option): bool => ($option['value'] ?? null) === 'ics'
                        ));
                        break;
                    }
                }
                unset($item);
                break;
            }
            unset($page);
            return $setupWizard;
        }

        $pages = [[
            'name'     => 'icsAccount',
            'caption'  => 'Choose an ICS/Webcal account',
            'items'    => [
                [
                    'type'    => 'Label',
                    'caption' => 'Choose an existing ICS/Webcal account. To create an account, use the main setup assistant.'
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'ICSSubscriptionAccountID',
                    'caption' => 'Calendar account',
                    'value'   => 0,
                    'options' => $accounts
                ]
            ],
            'validate' => 'return IPSKALDISCOVERY_ValidateWizardICSSubscriptionAccount($id, $ICSSubscriptionAccountID);',
            'nextPage' => 'icsFeed'
        ], [
            'name'     => 'icsFeed',
            'caption'  => 'Check ICS/Webcal subscription',
            'items'    => [
                [
                    'type'     => 'ValidationTextBox',
                    'name'     => 'ICSSubscriptionURL',
                    'caption'  => 'iCalendar URL',
                    'validate' => '^(https?|webcal)://.+'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'ICSSubscriptionName',
                    'caption' => 'Calendar name'
                ],
                [
                    'type'    => 'RadioButtonGroup',
                    'name'    => 'ICSSubscriptionAuthenticationMode',
                    'caption' => 'Authentication',
                    'value'   => self::ICALENDAR_AUTH_URL_ACCESS_KEY,
                    'options' => [
                        ['caption' => 'URL / access key', 'value' => self::ICALENDAR_AUTH_URL_ACCESS_KEY],
                        ['caption' => 'Username / password', 'value' => self::ICALENDAR_AUTH_USERNAME_PASSWORD]
                    ]
                ],
                ['type' => 'ValidationTextBox', 'name' => 'ICSSubscriptionUsername', 'caption' => 'Username'],
                ['type' => 'PasswordTextBox', 'name' => 'ICSSubscriptionPassword', 'caption' => 'Password'],
                [
                    'type'    => 'Label',
                    'caption' => 'The feed is checked now and saved only after you confirm the summary. Existing subscriptions remain unchanged.'
                ]
            ],
            'validate' => 'return IPSKALDISCOVERY_ValidateWizardICSSubscriptionFeed($id, $ICSSubscriptionURL, $ICSSubscriptionName, $ICSSubscriptionAuthenticationMode, $ICSSubscriptionUsername, $ICSSubscriptionPassword);',
            'nextPage' => 'view'
        ]];

        foreach ($extensionWizard['popup']['pages'] as $page) {
            $pageName = (string) ($page['name'] ?? '');
            if (!in_array($pageName, ['view', 'summary', 'result'], true)) {
                continue;
            }
            if ($pageName === 'view') {
                foreach ($page['items'] as &$item) {
                    if (str_starts_with((string) ($item['name'] ?? ''), 'Extension')) {
                        $item['name'] = 'ICS' . substr($item['name'], strlen('Extension'));
                    }
                }
                unset($item);
                $page['validate'] = str_replace('$Extension', '$ICS', (string) $page['validate']);
                $page['onConfirm'] = str_replace('$Extension', '$ICS', (string) $page['onConfirm']);
            } elseif ($pageName === 'summary') {
                $page['caption'] = 'Review ICS/Webcal subscription';
                $page['items'] = [
                    ['type' => 'Label', 'caption' => 'The new feed was checked successfully. Its URL and credentials will be saved to the selected account only when you continue.'],
                    ['type' => 'Label', 'caption' => 'OpenCalendar then creates or reuses its calendar instance, adds it to the chosen view and checks synchronization. Existing subscriptions and view assignments remain unchanged.']
                ];
                $page['onConfirm'] = 'IPS_RequestAction($id, \'WizardFinishICSSubscription\', json_encode([\'url\' => $ICSSubscriptionURL, \'name\' => $ICSSubscriptionName, \'authenticationMode\' => $ICSSubscriptionAuthenticationMode, \'username\' => $ICSSubscriptionUsername, \'password\' => $ICSSubscriptionPassword], JSON_THROW_ON_ERROR));';
            } else {
                $page['caption'] = 'ICS/Webcal subscription completed';
                foreach ($page['items'] as &$item) {
                    if (str_starts_with((string) ($item['name'] ?? ''), 'ExtensionResult')) {
                        $item['name'] = 'ICS' . substr($item['name'], strlen('Extension'));
                    }
                }
                unset($item);
            }
            $pages[] = $page;
        }

        return [
            'type'    => 'PopupButton',
            'name'    => 'AddICSSubscriptionWizard',
            'caption' => 'Add ICS/Webcal',
            'enabled' => true,
            'width'   => '240px',
            'popup'   => [
                'caption'      => 'Add an ICS/Webcal subscription',
                'closeCaption' => 'Cancel',
                'pages'        => $pages
            ]
        ];
    }

    /**
     * @return array{url: string, name: string, authenticationMode: int, username: string, password: string}
     */
    private function normalizeWizardICSSubscription(
        string $url,
        string $name,
        int $authenticationMode,
        string $username,
        string $password
    ): array {
        $url = trim($url);
        $normalizedURL = $this->wizardICSUrlKey($url);
        if (filter_var($normalizedURL, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($normalizedURL, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException($this->Translate('Please enter a valid iCalendar URL.'));
        }
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException($this->Translate('Please enter a calendar name.'));
        }
        if (!in_array($authenticationMode, [self::ICALENDAR_AUTH_URL_ACCESS_KEY, self::ICALENDAR_AUTH_USERNAME_PASSWORD], true)) {
            throw new InvalidArgumentException($this->Translate('Please select an iCalendar authentication mode.'));
        }
        $username = trim($username);
        if ($authenticationMode === self::ICALENDAR_AUTH_USERNAME_PASSWORD && ($username === '' || $password === '')) {
            throw new InvalidArgumentException($this->Translate('Please enter the iCalendar username and password.'));
        }

        return [
            'url'                => $url,
            'name'               => $name,
            'authenticationMode' => $authenticationMode,
            'username'           => $authenticationMode === self::ICALENDAR_AUTH_USERNAME_PASSWORD ? $username : '',
            'password'           => $authenticationMode === self::ICALENDAR_AUTH_USERNAME_PASSWORD ? $password : ''
        ];
    }

    private function wizardICSUrlKey(string $url): string
    {
        $url = trim($url);
        return str_starts_with(strtolower($url), 'webcal://') ? 'https://' . substr($url, 9) : $url;
    }

    private function assertWizardICSSubscriptionNotConfigured(int $accountID, string $url): void
    {
        $key = $this->wizardICSUrlKey($url);
        $legacyURL = trim((string) IPS_GetProperty($accountID, 'ServerURL'));
        if ($legacyURL !== '' && $legacyURL !== self::APPLE_CALDAV_URL
            && $this->wizardICSUrlKey($legacyURL) === $key) {
            throw new InvalidArgumentException($this->Translate('This ICS/Webcal URL is already configured.'));
        }
        foreach ($this->readWizardICSFeedRows($accountID) as $row) {
            if ($this->wizardICSUrlKey((string) ($row['URL'] ?? $row['url'] ?? '')) === $key) {
                throw new InvalidArgumentException($this->Translate('This ICS/Webcal URL is already configured.'));
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function readWizardICSFeedRows(int $accountID): array
    {
        try {
            $rows = json_decode((string) IPS_GetProperty($accountID, 'ICalendarFeeds'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException($this->Translate('The existing iCalendar subscription list is invalid.'));
        }
        if (!is_array($rows) || !array_is_list($rows) || count(array_filter($rows, 'is_array')) !== count($rows)) {
            throw new RuntimeException($this->Translate('The existing iCalendar subscription list is invalid.'));
        }
        return $rows;
    }

    /**
     * Reuses the existing calendar, view and result pages for a shorter existing-account flow.
     *
     * @param array<string, mixed> $setupWizard Existing setup popup after dynamic fields were filled.
     * @return array<string, mixed>
     */
    private function extensionWizardAction(array $setupWizard): array
    {
        $activeAccounts = $this->calendarAccountSelectOptions(true);
        $pages = [[
            'name'     => 'existingAccount',
            'caption'  => 'Choose an existing calendar account',
            'items'    => [
                [
                    'type'    => 'Label',
                    'caption' => 'This assistant adds calendars from an existing, active account. Its connection and calendar list are checked before you continue.'
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'ExtensionAccountID',
                    'caption' => 'Calendar account',
                    'value'   => 0,
                    'options' => $activeAccounts
                ]
            ],
            'validate' => 'return IPSKALDISCOVERY_ValidateWizardExtensionAccount($id, $ExtensionAccountID);',
            'nextPage' => 'calendars'
        ]];

        $viewOptions = $this->calendarViewSelectOptions();
        foreach ($setupWizard['popup']['pages'] as $page) {
            $pageName = (string) ($page['name'] ?? '');
            if (!in_array($pageName, ['calendars', 'view', 'summary', 'result'], true)) {
                continue;
            }

            if ($pageName === 'calendars') {
                $page['closeCaption'] = 'Cancel';
                $page['items'] = array_values(array_filter(
                    $page['items'],
                    static fn (array $item): bool => !in_array(
                        (string) ($item['caption'] ?? ''),
                        [
                            'If you choose Set up later, a newly created calendar account remains disabled until setup is completed.',
                            'Select at least one calendar. A primary calendar, or the only available calendar, is preselected automatically.'
                        ],
                        true
                    )
                ));
                foreach ($page['items'] as &$item) {
                    if (($item['name'] ?? '') === 'WizardCalendars') {
                        $item['name'] = 'ExtensionCalendars';
                        $item['onEdit'] = str_replace(
                            '$WizardCalendars',
                            '$ExtensionCalendars',
                            (string) ($item['onEdit'] ?? '')
                        );
                        $item['columns'][] = [
                            'caption' => 'State',
                            'name'    => 'setupState',
                            'width'   => '130px'
                        ];
                    }
                }
                unset($item);
                $page['items'][] = [
                    'type'    => 'Label',
                    'caption' => 'Already configured calendars are reused. Select them only if they should also be added to the chosen calendar view.'
                ];
            } elseif ($pageName === 'view') {
                foreach ($page['items'] as &$item) {
                    if (($item['name'] ?? '') === 'ViewMode') {
                        $item['name'] = 'ExtensionViewMode';
                        $item['value'] = count($viewOptions) > 1 ? self::VIEW_MODE_EXISTING : self::VIEW_MODE_NEW;
                    } elseif (($item['name'] ?? '') === 'ExistingViewID') {
                        $item['name'] = 'ExtensionExistingViewID';
                    } elseif (($item['name'] ?? '') === 'ViewName') {
                        $item['name'] = 'ExtensionViewName';
                    }
                }
                unset($item);
                $page['validate'] = str_replace(
                    ['$ViewMode', '$ExistingViewID', '$ViewName'],
                    ['$ExtensionViewMode', '$ExtensionExistingViewID', '$ExtensionViewName'],
                    (string) $page['validate']
                );
                $page['onConfirm'] = str_replace(
                    ['$ViewMode', '$ExistingViewID', '$ViewName'],
                    ['$ExtensionViewMode', '$ExtensionExistingViewID', '$ExtensionViewName'],
                    (string) $page['onConfirm']
                );
                $page['closeCaption'] = 'Cancel';
            } elseif ($pageName === 'summary') {
                $page['caption'] = 'Review calendar addition';
                $page['items'] = array_values(array_filter(
                    $page['items'],
                    static fn (array $item): bool => !in_array(
                        (string) ($item['caption'] ?? ''),
                        [
                            'If selected, OpenCalendar also creates a Calendar Configurator for this account. An existing matching configurator is reused.',
                            'A newly created calendar account is activated. Existing accounts keep their current activation state.'
                        ],
                        true
                    )
                ));
            } elseif ($pageName === 'result') {
                $page['caption'] = 'Calendar addition completed';
                $page['items'] = array_values(array_filter(
                    $page['items'],
                    static fn (array $item): bool => !in_array(
                        (string) ($item['name'] ?? ''),
                        ['ResultNextAction', 'ResultConfigurator'],
                        true
                    ) && ($item['caption'] ?? '')
                        !== 'Choose “Set up another calendar account” and click Next to return directly to provider selection. The completed configuration remains unchanged.'
                ));
                foreach ($page['items'] as &$item) {
                    if (str_starts_with((string) ($item['name'] ?? ''), 'Result')) {
                        $item['name'] = 'Extension' . $item['name'];
                    }
                }
                unset($item);
                $page['onConfirm'] = "IPS_RequestAction(\$id, 'WizardComplete', '');";
                $page['nextPage'] = -1;
            }

            $pages[] = $page;
        }

        return [
            'type'    => 'PopupButton',
            'name'    => 'AddCalendarWizard',
            'caption' => 'Add calendars',
            'enabled' => count($activeAccounts) > 1,
            'width'   => '240px',
            'popup'   => [
                'caption'      => 'Add calendars',
                'closeCaption' => 'Cancel',
                'items'        => [[
                    'type'    => 'Label',
                    'caption' => 'This fallback content is not shown while wizard pages are available.'
                ]],
                'pages' => $pages
            ]
        ];
    }

    private function providerKeyForAccount(int $accountID): string
    {
        return match ((int) IPS_GetProperty($accountID, 'Provider')) {
            CalendarProviderType::APPLE     => 'apple',
            CalendarProviderType::CALDAV    => 'caldav',
            CalendarProviderType::GOOGLE    => 'google',
            CalendarProviderType::MICROSOFT => 'microsoft',
            CalendarProviderType::ICS       => 'ics',
            CalendarProviderType::LOCAL     => 'local',
            default                         => throw new InvalidArgumentException(
                $this->Translate('The selected calendar account uses an unsupported provider.')
            )
        };
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
        $this->clearWizardCalendarViewSelection();
        if ($provider === 'local') {
            $isNew = $selection['mode'] === self::ACCOUNT_MODE_NEW;
            $this->UpdateFormField('WizardLocalCalendarName', 'visible', $isNew);
            $this->UpdateFormField('WizardLocalCalendarColor', 'visible', $isNew);
            $this->UpdateFormField('WizardLocalExistingHint', 'visible', !$isNew);
        }
    }

    private function finishWizardAccount(): void
    {
        if ($this->GetBuffer('WizardSetupCompleted') === '1') {
            $this->updateWizardResultForm($this->readLastSetupResult());
            return;
        }

        $validationError = $this->ValidateWizardConfirmation();
        if ($validationError !== '') {
            throw new RuntimeException($validationError);
        }

        $accountID = $this->wizardAccountID();
        $provider = $this->wizardProvider();
        $selectedCalendarIDs = $this->readWizardSelectedCalendarIDs();
        $viewSelection = $this->readWizardCalendarViewSelection();
        $existingCalendarInstanceIDs = array_values($this->existingCalendarInstancesForAccount($accountID));
        $createdAccountID = (int) $this->GetBuffer('WizardCreatedAccountID');
        $activateNewAccount = $createdAccountID === $accountID;
        $wasActive = (bool) IPS_GetProperty($accountID, 'Active');

        $configuratorInstanceID = 0;
        $createdConfiguratorInstanceID = 0;
        $configuratorCreated = false;

        try {
            if ($activateNewAccount && !$wasActive) {
                IPS_SetProperty($accountID, 'Active', true);
                IPS_ApplyChanges($accountID);
            }

            if ($this->GetBuffer('WizardCreateConfigurator') === '1') {
                $configurator = $this->prepareCalendarConfigurator($accountID, $provider);
                $configuratorInstanceID = $configurator['instanceID'];
                $configuratorCreated = $configurator['created'];
                if ($configuratorCreated) {
                    $createdConfiguratorInstanceID = $configuratorInstanceID;
                }
            }
        } catch (Throwable $exception) {
            if ($activateNewAccount && !$wasActive && IPS_InstanceExists($accountID)) {
                IPS_SetProperty($accountID, 'Active', false);
                IPS_ApplyChanges($accountID);
            }

            throw new RuntimeException(
                $this->Translate('The calendar configurator could not be created.') . ' '
                    . $exception->getMessage(),
                0,
                $exception
            );
        }

        try {
            $calendarInstanceIDs = $this->prepareSelectedCalendarInstances(
                $accountID,
                $provider,
                $selectedCalendarIDs
            );
        } catch (Throwable $exception) {
            if ($createdConfiguratorInstanceID > 0 && IPS_InstanceExists($createdConfiguratorInstanceID)) {
                IPS_DeleteInstance($createdConfiguratorInstanceID);
            }
            if ($activateNewAccount && !$wasActive && IPS_InstanceExists($accountID)) {
                IPS_SetProperty($accountID, 'Active', false);
                IPS_ApplyChanges($accountID);
            }

            throw new RuntimeException(
                $this->Translate('The selected calendar instances could not be created.') . ' '
                    . $exception->getMessage(),
                0,
                $exception
            );
        }

        $createdCalendarInstanceIDs = array_values(array_diff(
            $calendarInstanceIDs,
            $existingCalendarInstanceIDs
        ));
        try {
            $calendarView = $this->prepareCalendarView(
                $accountID,
                $viewSelection,
                $calendarInstanceIDs
            );
        } catch (Throwable $exception) {
            foreach (array_reverse($createdCalendarInstanceIDs) as $calendarInstanceID) {
                if (IPS_InstanceExists($calendarInstanceID)) {
                    IPS_DeleteInstance($calendarInstanceID);
                }
            }
            if ($createdConfiguratorInstanceID > 0 && IPS_InstanceExists($createdConfiguratorInstanceID)) {
                IPS_DeleteInstance($createdConfiguratorInstanceID);
            }
            if ($activateNewAccount && !$wasActive && IPS_InstanceExists($accountID)) {
                IPS_SetProperty($accountID, 'Active', false);
                IPS_ApplyChanges($accountID);
            }

            throw new RuntimeException(
                $this->Translate('The calendar view could not be configured.') . ' ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $verification = $this->verifyWizardSetup(
            $accountID,
            $calendarInstanceIDs,
            $calendarView['instanceID']
        );
        $setupResult = [
            'success'                     => $verification['accountSynchronized']
                && $verification['failedCalendarNames'] === []
                && $verification['viewInitialized'],
            'accountID'                   => $accountID,
            'accountName'                 => trim(IPS_GetName($accountID)),
            'accountActive'               => (bool) IPS_GetProperty($accountID, 'Active'),
            'accountSynchronized'         => $verification['accountSynchronized'],
            'accountSynchronizationError' => $verification['accountSynchronizationError'],
            'configuratorID'              => $configuratorInstanceID,
            'configuratorCreated'         => $configuratorCreated,
            'calendarCount'               => count($calendarInstanceIDs),
            'calendarCreatedCount'        => count($createdCalendarInstanceIDs),
            'calendarReusedCount'         => count($calendarInstanceIDs) - count($createdCalendarInstanceIDs),
            'calendarSynchronizedCount'   => $verification['calendarSynchronizedCount'],
            'failedCalendarNames'         => $verification['failedCalendarNames'],
            'failedCalendars'             => $verification['failedCalendars'],
            'viewID'                      => $calendarView['instanceID'],
            'viewName'                    => trim(IPS_GetName($calendarView['instanceID'])),
            'viewCreated'                 => $calendarView['created'],
            'viewInitialized'             => $verification['viewInitialized'],
            'viewInitializationError'     => $verification['viewInitializationError']
        ];

        $this->WriteAttributeInteger('SelectedCalendarAccountID', $accountID);
        $this->WriteAttributeInteger('SelectedCalendarConfiguratorID', $configuratorInstanceID);
        $this->WriteAttributeInteger('SelectedCalendarViewID', $calendarView['instanceID']);
        $this->WriteAttributeString('SelectedCalendarIDs', $this->GetBuffer('WizardSelectedCalendarIDs'));
        $this->WriteAttributeString(
            'SelectedCalendarInstanceIDs',
            json_encode(
                $calendarInstanceIDs,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
        $this->WriteAttributeString(
            'LastSetupResult',
            json_encode(
                $setupResult,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
        $this->SetBuffer(
            'WizardCalendarViewSelection',
            json_encode(
                [
                    'mode'           => self::VIEW_MODE_EXISTING,
                    'existingViewID' => $calendarView['instanceID'],
                    'viewName'       => ''
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            )
        );
        $this->SetBuffer('WizardCreatedAccountID', '');
        $this->SetBuffer('WizardSetupCompleted', '1');
        $this->updateWizardResultForm($setupResult);
    }

    /**
     * Commits one verified feed and delegates instance/view setup to the existing finalization path.
     */
    private function finishWizardICSSubscription(mixed $value): void
    {
        if ($this->GetBuffer('WizardExtensionMode') !== 'ics' || !is_string($value)) {
            throw new InvalidArgumentException($this->Translate('The iCalendar subscription selection is invalid.'));
        }
        try {
            $selection = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException($this->Translate('The iCalendar subscription selection is invalid.'));
        }
        if (!is_array($selection)) {
            throw new InvalidArgumentException($this->Translate('The iCalendar subscription selection is invalid.'));
        }
        $feed = $this->normalizeWizardICSSubscription(
            (string) ($selection['url'] ?? ''),
            (string) ($selection['name'] ?? ''),
            (int) ($selection['authenticationMode'] ?? -1),
            (string) ($selection['username'] ?? ''),
            (string) ($selection['password'] ?? '')
        );
        $accountID = $this->wizardAccountID();
        $this->assertWizardProviderMatchesAccount($accountID, 'ics');
        if (!(bool) IPS_GetProperty($accountID, 'Active')) {
            throw new InvalidArgumentException($this->Translate('Choose an active calendar account to add calendars.'));
        }
        $calendarID = hash('sha256', 'ics|' . $this->wizardICSUrlKey($feed['url']));
        $previewHash = $this->GetBuffer('WizardICSPreviewHash');
        if ($this->readWizardSelectedCalendarIDs() !== [$calendarID]
            || $previewHash === ''
            || !hash_equals($previewHash, hash('sha256', json_encode($feed, JSON_THROW_ON_ERROR)))) {
            throw new InvalidArgumentException($this->Translate('The verified iCalendar feed has changed. Check it again.'));
        }
        $confirmationError = $this->ValidateWizardConfirmation();
        if ($confirmationError !== '') {
            throw new RuntimeException($confirmationError);
        }

        if ($this->GetBuffer('WizardICSCommittedID') !== $calendarID) {
            $this->assertWizardICSSubscriptionNotConfigured($accountID, $feed['url']);
            $oldValue = (string) IPS_GetProperty($accountID, 'ICalendarFeeds');
            $rows = $this->readWizardICSFeedRows($accountID);
            $rows[] = [
                'Active'             => true,
                'Name'               => $feed['name'],
                'URL'                => $feed['url'],
                'AuthenticationMode' => $feed['authenticationMode'],
                'Username'           => $feed['username'],
                'Password'           => $feed['password'],
                'Color'              => '',
                'TranslationProfile' => 0,
                'UpdateSchedule'     => (int) IPS_GetProperty($accountID, 'UpdateSchedule'),
                'UpdateInterval'     => (int) IPS_GetProperty($accountID, 'UpdateInterval')
            ];
            $newValue = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            IPS_SetProperty($accountID, 'ICalendarFeeds', $newValue);
            try {
                IPS_ApplyChanges($accountID);
            } catch (Throwable $exception) {
                if (IPS_InstanceExists($accountID)
                    && (string) IPS_GetProperty($accountID, 'ICalendarFeeds') === $newValue) {
                    IPS_SetProperty($accountID, 'ICalendarFeeds', $oldValue);
                    IPS_ApplyChanges($accountID);
                }
                throw $exception;
            }
            $this->SetBuffer('WizardICSCommittedID', $calendarID);
        }

        $this->finishWizardAccount();
    }

    /**
     * @param list<int> $calendarInstanceIDs
     * @return array{
     *     accountSynchronized: bool,
     *     accountSynchronizationError: string,
     *     calendarSynchronizedCount: int,
     *     failedCalendarNames: list<string>,
     *     failedCalendars: list<array{instanceID: int, name: string, error: string}>,
     *     viewInitialized: bool,
     *     viewInitializationError: string
     * }
     */
    private function verifyWizardSetup(int $accountID, array $calendarInstanceIDs, int $viewID): array
    {
        $accountWasActive = (bool) IPS_GetProperty($accountID, 'Active');
        $temporarilyActivated = false;
        $accountSynchronized = false;
        $accountSynchronizationError = '';
        $calendarSynchronizedCount = 0;
        $failedCalendars = [];
        $viewInitialized = false;
        $viewInitializationError = '';

        try {
            if (!$accountWasActive) {
                IPS_SetProperty($accountID, 'Active', true);
                IPS_ApplyChanges($accountID);
                $temporarilyActivated = true;
            }

            try {
                $accountSynchronized = IPSKALACC_Synchronize($accountID);
                if (!$accountSynchronized) {
                    $accountSynchronizationError = $this->wizardAccountSynchronizationError($accountID);
                }
            } catch (Throwable $exception) {
                $accountSynchronizationError = $this->normalizeWizardErrorMessage($exception->getMessage());
            }

            foreach ($calendarInstanceIDs as $calendarInstanceID) {
                $name = IPS_InstanceExists($calendarInstanceID)
                    ? trim(IPS_GetName($calendarInstanceID))
                    : '';
                if ($name === '') {
                    $name = sprintf('#%d', $calendarInstanceID);
                }

                $synchronized = false;
                $error = '';
                try {
                    if (!IPS_InstanceExists($calendarInstanceID)) {
                        $error = $this->Translate('The calendar instance no longer exists.');
                    } else {
                        $synchronized = IPSKAL_Synchronize($calendarInstanceID);
                        if (!$synchronized) {
                            $error = $this->wizardCalendarSynchronizationError($calendarInstanceID);
                        }
                    }
                } catch (Throwable $exception) {
                    $error = $this->normalizeWizardErrorMessage($exception->getMessage());
                }

                if ($synchronized) {
                    ++$calendarSynchronizedCount;
                    continue;
                }

                $failedCalendars[] = [
                    'instanceID' => $calendarInstanceID,
                    'name'       => $name,
                    'error'      => $error !== ''
                        ? $error
                        : $this->Translate('The calendar reported a synchronization failure.')
                ];
            }

            try {
                $viewInitialized = IPS_InstanceExists($viewID) && IPSKALVIEW_Initialize($viewID);
                if (!$viewInitialized) {
                    $viewInitializationError = $this->wizardViewInitializationError($viewID);
                }
            } catch (Throwable $exception) {
                $viewInitializationError = $this->normalizeWizardErrorMessage($exception->getMessage());
            }
        } catch (Throwable $exception) {
            $fatalError = $this->normalizeWizardErrorMessage($exception->getMessage());
            if ($accountSynchronizationError === '') {
                $accountSynchronizationError = sprintf(
                    $this->Translate('Final verification could not be completed: %s'),
                    $fatalError
                );
            }
            foreach ($calendarInstanceIDs as $calendarInstanceID) {
                $name = IPS_InstanceExists($calendarInstanceID)
                    ? trim(IPS_GetName($calendarInstanceID))
                    : '';
                if ($name === '') {
                    $name = sprintf('#%d', $calendarInstanceID);
                }
                $failedCalendars[] = [
                    'instanceID' => $calendarInstanceID,
                    'name'       => $name,
                    'error'      => sprintf(
                        $this->Translate('Final verification could not be completed: %s'),
                        $fatalError
                    )
                ];
            }
            if ($viewInitializationError === '') {
                $viewInitializationError = sprintf(
                    $this->Translate('Final verification could not be completed: %s'),
                    $fatalError
                );
            }
        } finally {
            if ($temporarilyActivated && IPS_InstanceExists($accountID)) {
                IPS_SetProperty($accountID, 'Active', false);
                IPS_ApplyChanges($accountID);
            }
        }

        $failedCalendars = $this->uniqueFailedWizardCalendars($failedCalendars);

        return [
            'accountSynchronized'         => $accountSynchronized,
            'accountSynchronizationError' => $accountSynchronizationError,
            'calendarSynchronizedCount'   => $calendarSynchronizedCount,
            'failedCalendarNames'         => array_values(array_map(
                static fn (array $calendar): string => $calendar['name'],
                $failedCalendars
            )),
            'failedCalendars'             => $failedCalendars,
            'viewInitialized'             => $viewInitialized,
            'viewInitializationError'     => $viewInitializationError
        ];
    }

    private function wizardAccountSynchronizationError(int $accountID): string
    {
        try {
            $status = json_decode(
                IPSKALACC_GetAccountStatus($accountID),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            if (is_array($status)) {
                $lastError = $this->normalizeWizardErrorMessage((string) ($status['lastError'] ?? ''));
                if ($lastError !== '') {
                    return $lastError;
                }
            }
        } catch (Throwable $exception) {
            $message = $this->normalizeWizardErrorMessage($exception->getMessage());
            if ($message !== '') {
                return $message;
            }
        }

        return $this->Translate('Calendar account synchronization returned no success.');
    }

    private function wizardCalendarSynchronizationError(int $calendarInstanceID): string
    {
        if (!IPS_InstanceExists($calendarInstanceID)) {
            return $this->Translate('The calendar instance no longer exists.');
        }

        $instance = IPS_GetInstance($calendarInstanceID);
        $status = (int) ($instance['InstanceStatus'] ?? 0);

        return match ($status) {
            IS_INACTIVE => $this->Translate('The calendar instance is inactive.'),
            201         => $this->Translate('The calendar configuration is incomplete.'),
            202         => $this->Translate('The calendar reported a synchronization failure.'),
            203         => $this->Translate('The provider returned an invalid calendar response.'),
            204         => $this->Translate('An event write conflict was reported.'),
            default     => sprintf(
                $this->Translate('Calendar synchronization returned no success (instance status %d).'),
                $status
            )
        };
    }

    private function wizardViewInitializationError(int $viewID): string
    {
        if (!IPS_InstanceExists($viewID)) {
            return $this->Translate('The calendar view no longer exists.');
        }

        $instance = IPS_GetInstance($viewID);
        $status = (int) ($instance['InstanceStatus'] ?? 0);

        return sprintf(
            $this->Translate('Calendar View initialization returned no success (instance status %d).'),
            $status
        );
    }

    private function normalizeWizardErrorMessage(string $message): string
    {
        $message = strip_tags($message);
        $message = preg_replace('/\\s+/', ' ', $message) ?? $message;
        $message = trim($message);

        return strlen($message) > 600 ? substr($message, 0, 597) . '...' : $message;
    }

    /**
     * @param list<array{instanceID: int, name: string, error: string}> $failedCalendars
     * @return list<array{instanceID: int, name: string, error: string}>
     */
    private function uniqueFailedWizardCalendars(array $failedCalendars): array
    {
        $unique = [];
        foreach ($failedCalendars as $calendar) {
            $instanceID = (int) ($calendar['instanceID'] ?? 0);
            $key = $instanceID > 0 ? (string) $instanceID : (string) ($calendar['name'] ?? '');
            if ($key === '' || isset($unique[$key])) {
                continue;
            }
            $unique[$key] = [
                'instanceID' => $instanceID,
                'name'       => trim((string) ($calendar['name'] ?? '')),
                'error'      => trim((string) ($calendar['error'] ?? ''))
            ];
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function updateWizardResultForm(array $result): void
    {
        $fieldPrefix = match ($this->GetBuffer('WizardExtensionMode')) {
            '1'     => 'Extension',
            'ics'   => 'ICS',
            default => ''
        };
        foreach ($this->wizardResultCaptions($result) as $field => $caption) {
            $this->UpdateFormField($fieldPrefix . $field, 'caption', $caption);
        }

        $showVerificationHelp = $this->wizardResultNeedsVerificationHelp($result);
        foreach ([
            'ResultSynchronizationDetails',
            'ResultRetryVerification',
            'ResultSynchronizationHintRetry',
            'ResultSynchronizationHintManual',
            'ResultSynchronizationHintDebug'
        ] as $field) {
            $this->UpdateFormField($fieldPrefix . $field, 'visible', $showVerificationHelp);
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, string>
     */
    private function wizardResultCaptions(array $result): array
    {
        if ($result === []) {
            return [];
        }

        $accountID = (int) ($result['accountID'] ?? 0);
        $accountName = trim((string) ($result['accountName'] ?? ''));
        if ($accountName === '') {
            $accountName = $this->Translate('Calendar account');
        }
        $configuratorID = (int) ($result['configuratorID'] ?? 0);
        $calendarCount = max(0, (int) ($result['calendarCount'] ?? 0));
        $calendarCreatedCount = max(0, (int) ($result['calendarCreatedCount'] ?? 0));
        $calendarReusedCount = max(0, (int) ($result['calendarReusedCount'] ?? 0));
        $calendarSynchronizedCount = max(0, (int) ($result['calendarSynchronizedCount'] ?? 0));
        $failedCalendars = is_array($result['failedCalendars'] ?? null)
            ? array_values(array_filter($result['failedCalendars'], 'is_array'))
            : [];
        $failedCalendarNames = is_array($result['failedCalendarNames'] ?? null)
            ? array_values(array_filter(array_map('strval', $result['failedCalendarNames'])))
            : [];
        $viewID = (int) ($result['viewID'] ?? 0);
        $viewName = trim((string) ($result['viewName'] ?? ''));
        if ($viewName === '') {
            $viewName = $this->Translate('Calendar view');
        }

        $viewCaption = sprintf(
            $this->Translate(
                (bool) ($result['viewCreated'] ?? false)
                    ? 'Calendar View: %s (#%d) was created and the selected calendars were assigned.'
                    : 'Calendar View: %s (#%d) was reused and the selected calendars were assigned.'
            ),
            $viewName,
            $viewID
        ) . ' ' . $this->Translate(
            (bool) ($result['viewInitialized'] ?? false)
                ? 'Calendar View initialization completed successfully.'
                : 'Calendar View initialization could not be completed automatically.'
        );
        $viewInitializationError = trim((string) ($result['viewInitializationError'] ?? ''));
        if (!(bool) ($result['viewInitialized'] ?? false) && $viewInitializationError !== '') {
            $viewCaption .= ' ' . sprintf(
                $this->Translate('Reason: %s'),
                $viewInitializationError
            );
        }

        $captions = [
            'ResultStatus' => $this->Translate(
                (bool) ($result['success'] ?? false)
                    ? 'OpenCalendar setup completed successfully.'
                    : 'OpenCalendar setup completed with warnings. The created configuration was kept; review the details below.'
            ),
            'ResultAccount' => sprintf(
                $this->Translate(
                    (bool) ($result['accountActive'] ?? false)
                        ? 'Calendar account: %s (#%d) - connection verified and active.'
                        : 'Calendar account: %s (#%d) - connection verified; the existing account remains disabled as before.'
                ),
                $accountName,
                $accountID
            ),
            'ResultCalendars' => sprintf(
                $this->Translate('Calendars: %d selected, %d newly created, %d reused.'),
                $calendarCount,
                $calendarCreatedCount,
                $calendarReusedCount
            ),
            'ResultView' => $viewCaption
        ];

        if ($configuratorID <= 0) {
            $captions['ResultConfigurator'] = $this->Translate('Calendar Configurator: not requested.');
        } else {
            $configuratorName = IPS_InstanceExists($configuratorID)
                ? trim(IPS_GetName($configuratorID))
                : '';
            if ($configuratorName === '') {
                $configuratorName = $this->Translate('Configurator');
            }
            $captions['ResultConfigurator'] = sprintf(
                $this->Translate(
                    (bool) ($result['configuratorCreated'] ?? false)
                        ? 'Calendar Configurator: %s (#%d) was created.'
                        : 'Calendar Configurator: %s (#%d) was reused.'
                ),
                $configuratorName,
                $configuratorID
            );
        }

        if ((bool) ($result['accountSynchronized'] ?? false) && $failedCalendarNames === []) {
            $captions['ResultSynchronization'] = sprintf(
                $this->Translate('Synchronization: account and all %d selected calendars synchronized successfully.'),
                $calendarCount
            );
        } else {
            $parts = [];
            if (!(bool) ($result['accountSynchronized'] ?? false)) {
                $parts[] = $this->Translate('Account synchronization failed during the final check.');
            }
            if ($failedCalendarNames !== []) {
                $parts[] = sprintf(
                    $this->Translate(
                        'Synchronization: %d of %d selected calendars synchronized successfully. Failed: %s'
                    ),
                    $calendarSynchronizedCount,
                    $calendarCount,
                    implode(', ', $failedCalendarNames)
                );
            }
            $captions['ResultSynchronization'] = implode(' ', $parts);
        }

        $detailParts = [];
        $accountError = trim((string) ($result['accountSynchronizationError'] ?? ''));
        if (!(bool) ($result['accountSynchronized'] ?? false) && $accountError !== '') {
            $detailParts[] = sprintf(
                $this->Translate('Calendar account: %s'),
                $accountError
            );
        }
        foreach ($failedCalendars as $failedCalendar) {
            $instanceID = (int) ($failedCalendar['instanceID'] ?? 0);
            $name = trim((string) ($failedCalendar['name'] ?? ''));
            if ($name === '') {
                $name = $this->Translate('Calendar');
            }
            $error = trim((string) ($failedCalendar['error'] ?? ''));
            if ($error === '') {
                $error = $this->Translate('The calendar reported a synchronization failure.');
            }
            $detailParts[] = sprintf(
                $this->Translate('Calendar %s (#%d): %s'),
                $name,
                $instanceID,
                $error
            );
        }
        if (!(bool) ($result['viewInitialized'] ?? false) && $viewInitializationError !== '') {
            $detailParts[] = sprintf(
                $this->Translate('Calendar View: %s'),
                $viewInitializationError
            );
        }
        if ($detailParts === [] && $this->wizardResultNeedsVerificationHelp($result)) {
            $detailParts[] = $this->Translate('No more detailed error information was returned.');
        }
        $captions['ResultSynchronizationDetails'] = sprintf(
            $this->Translate('Synchronization details: %s'),
            implode(' | ', $detailParts)
        );

        return $captions;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function wizardResultNeedsVerificationHelp(array $result): bool
    {
        if ($result === []) {
            return false;
        }

        return !(bool) ($result['accountSynchronized'] ?? false)
            || (is_array($result['failedCalendarNames'] ?? null) && $result['failedCalendarNames'] !== [])
            || !(bool) ($result['viewInitialized'] ?? false);
    }

    private function retryWizardVerification(): void
    {
        $result = $this->readLastSetupResult();
        if ($result === []) {
            throw new RuntimeException($this->Translate('No completed OpenCalendar setup is available for verification.'));
        }

        $accountID = (int) ($result['accountID'] ?? 0);
        $viewID = (int) ($result['viewID'] ?? 0);
        $calendarInstanceIDs = $this->readSelectedCalendarInstanceIDs();
        if ($accountID <= 0 || !IPS_InstanceExists($accountID) || $viewID <= 0 || $calendarInstanceIDs === []) {
            throw new RuntimeException($this->Translate('The completed OpenCalendar setup is no longer available.'));
        }

        $verification = $this->verifyWizardSetup($accountID, $calendarInstanceIDs, $viewID);
        $result['success'] = $verification['accountSynchronized']
            && $verification['failedCalendarNames'] === []
            && $verification['viewInitialized'];
        $result['accountActive'] = (bool) IPS_GetProperty($accountID, 'Active');
        $result['accountSynchronized'] = $verification['accountSynchronized'];
        $result['accountSynchronizationError'] = $verification['accountSynchronizationError'];
        $result['calendarSynchronizedCount'] = $verification['calendarSynchronizedCount'];
        $result['failedCalendarNames'] = $verification['failedCalendarNames'];
        $result['failedCalendars'] = $verification['failedCalendars'];
        $result['viewInitialized'] = $verification['viewInitialized'];
        $result['viewInitializationError'] = $verification['viewInitializationError'];

        $this->WriteAttributeString(
            'LastSetupResult',
            json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
        $this->updateWizardResultForm($result);
    }

    /**
     * @return list<int>
     */
    private function readSelectedCalendarInstanceIDs(): array
    {
        try {
            $instanceIDs = json_decode(
                $this->ReadAttributeString('SelectedCalendarInstanceIDs'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return [];
        }
        if (!is_array($instanceIDs)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $instanceIDs),
            static fn (int $instanceID): bool => $instanceID > 0
        )));
    }

    private function continueWizardFromResult(string $nextPage): void
    {
        if (!in_array($nextPage, ['close', 'provider'], true)) {
            throw new InvalidArgumentException($this->Translate('The selected setup continuation is invalid.'));
        }

        $this->completeWizardSession();
    }

    /**
     * @return array<string, mixed>
     */
    private function readLastSetupResult(): array
    {
        try {
            $result = json_decode($this->ReadAttributeString('LastSetupResult'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($result) ? $result : [];
    }

    private function resetWizardCompletionState(): void
    {
        $this->SetBuffer('WizardSetupCompleted', '0');
    }

    private function completeWizardSession(): void
    {
        $this->SetBuffer('WizardExtensionMode', '0');
        $this->SetBuffer('WizardICSCommittedID', '');
        $this->SetBuffer('WizardICSPreviewHash', '');
        $this->SetBuffer('WizardProvider', '');
        $this->SetBuffer('WizardCreateConfigurator', '0');
        $this->SetBuffer('WizardAccountSelection', '');
        $this->SetBuffer('WizardAccountID', '');
        $this->SetBuffer('WizardCreatedAccountID', '');
        $this->SetBuffer('WizardConnectionVerified', '0');
        $this->SetBuffer('WizardSetupCompleted', '0');
        $this->clearWizardCalendarSelection(true);
        $this->clearWizardCalendarViewSelection();
    }

    /**
     * @param array{mode: string, existingViewID: int, viewName: string} $selection
     * @param list<int> $calendarInstanceIDs
     * @return array{instanceID: int, created: bool}
     */
    private function prepareCalendarView(int $accountID, array $selection, array $calendarInstanceIDs): array
    {
        $this->assertWizardCalendarViewSelectionValid(
            $selection['mode'],
            $selection['existingViewID'],
            $selection['viewName']
        );

        if ($selection['mode'] === self::VIEW_MODE_NEW) {
            return [
                'instanceID' => $this->createCalendarView($accountID, $selection['viewName'], $calendarInstanceIDs),
                'created'    => true
            ];
        }

        $viewID = $selection['existingViewID'];
        $previousCalendars = (string) IPS_GetProperty($viewID, 'Calendars');
        $updatedCalendars = $this->mergeCalendarViewConfiguration($previousCalendars, $calendarInstanceIDs);

        try {
            if ($updatedCalendars !== $previousCalendars) {
                IPS_SetProperty($viewID, 'Calendars', $updatedCalendars);
                IPS_ApplyChanges($viewID);
            }
        } catch (Throwable $exception) {
            if (IPS_InstanceExists($viewID)) {
                IPS_SetProperty($viewID, 'Calendars', $previousCalendars);
                IPS_ApplyChanges($viewID);
            }
            throw $exception;
        }

        return [
            'instanceID' => $viewID,
            'created'    => false
        ];
    }

    /**
     * @param list<int> $calendarInstanceIDs
     */
    private function createCalendarView(int $accountID, string $viewName, array $calendarInstanceIDs): int
    {
        $viewID = 0;

        try {
            $viewID = IPS_CreateInstance(self::CALENDAR_VIEW_MODULE_ID);
            IPS_SetName($viewID, trim($viewName));

            $parentID = IPS_GetParent($accountID);
            if ($parentID > 0) {
                IPS_SetParent($viewID, $parentID);
            }

            IPS_SetProperty(
                $viewID,
                'Calendars',
                $this->encodeCalendarViewConfiguration($calendarInstanceIDs)
            );
            IPS_ApplyChanges($viewID);
        } catch (Throwable $exception) {
            if ($viewID > 0 && IPS_InstanceExists($viewID)) {
                IPS_DeleteInstance($viewID);
            }
            throw $exception;
        }

        return $viewID;
    }

    /**
     * @param list<int> $calendarInstanceIDs
     */
    private function encodeCalendarViewConfiguration(array $calendarInstanceIDs): string
    {
        return json_encode(
            array_map(
                static fn (int $calendarInstanceID): array => [
                    'InstanceID' => $calendarInstanceID,
                    'Enabled'    => true
                ],
                array_values(array_unique($calendarInstanceIDs))
            ),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * @param list<int> $calendarInstanceIDs
     */
    private function mergeCalendarViewConfiguration(string $configuration, array $calendarInstanceIDs): string
    {
        try {
            $rows = json_decode($configuration !== '' ? $configuration : '[]', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                $this->Translate('The selected calendar view contains invalid calendar configuration.'),
                0,
                $exception
            );
        }
        if (!is_array($rows)) {
            throw new RuntimeException(
                $this->Translate('The selected calendar view contains invalid calendar configuration.')
            );
        }

        $selectedIDs = array_values(array_unique($calendarInstanceIDs));
        $configuredIDs = [];
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $instanceID = (int) ($row['InstanceID'] ?? 0);
            if ($instanceID <= 0) {
                continue;
            }
            $configuredIDs[$instanceID] = true;
            if (in_array($instanceID, $selectedIDs, true)) {
                $row['Enabled'] = true;
            }
        }
        unset($row);

        foreach ($selectedIDs as $instanceID) {
            if (isset($configuredIDs[$instanceID])) {
                continue;
            }
            $rows[] = [
                'InstanceID' => $instanceID,
                'Enabled'    => true
            ];
        }

        return json_encode(
            $rows,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Returns the existing Calendar Configurator for the account or creates one.
     *
     * @return array{instanceID: int, created: bool}
     */
    private function prepareCalendarConfigurator(int $accountID, string $provider): array
    {
        $this->assertSupportedProvider($provider);

        $configuratorIDs = IPS_GetInstanceListByModuleID(self::CALENDAR_CONFIGURATOR_MODULE_ID);
        sort($configuratorIDs, SORT_NUMERIC);
        foreach ($configuratorIDs as $configuratorID) {
            $instance = IPS_GetInstance($configuratorID);
            if ((int) ($instance['ConnectionID'] ?? 0) === $accountID) {
                return [
                    'instanceID' => $configuratorID,
                    'created'    => false
                ];
            }
        }

        return [
            'instanceID' => $this->createCalendarConfigurator($accountID, $provider),
            'created'    => true
        ];
    }

    private function createCalendarConfigurator(int $accountID, string $provider): int
    {
        $configuratorID = 0;

        try {
            $configuratorID = IPS_CreateInstance(self::CALENDAR_CONFIGURATOR_MODULE_ID);
            $accountName = trim(IPS_GetName($accountID));
            if ($accountName === '') {
                $accountName = $this->Translate('Calendar account');
            }
            IPS_SetName(
                $configuratorID,
                self::PROVIDER_INSTANCE_PREFIXES[$provider] . ' - ' . $accountName . ' - '
                    . $this->Translate('Configurator')
            );

            $parentID = IPS_GetParent($accountID);
            if ($parentID > 0) {
                IPS_SetParent($configuratorID, $parentID);
            }

            if (!IPS_ConnectInstance($configuratorID, $accountID)) {
                throw new RuntimeException(
                    $this->Translate('The calendar configurator could not be connected to the calendar account.')
                );
            }

            IPS_ApplyChanges($configuratorID);
        } catch (Throwable $exception) {
            if ($configuratorID > 0 && IPS_InstanceExists($configuratorID)) {
                IPS_DeleteInstance($configuratorID);
            }

            throw $exception;
        }
        return $configuratorID;
    }

    /**
     * @param list<string> $selectedCalendarIDs
     * @return list<int>
     */
    private function prepareSelectedCalendarInstances(
        int $accountID,
        string $provider,
        array $selectedCalendarIDs
    ): array {
        $this->assertSupportedProvider($provider);

        $calendars = [];
        foreach ($this->readWizardCalendars() as $calendar) {
            $calendarID = trim((string) ($calendar['id'] ?? ''));
            if ($calendarID !== '') {
                $calendars[$calendarID] = $calendar;
            }
        }

        $existingInstances = $this->existingCalendarInstancesForAccount($accountID);
        $calendarInstanceIDs = [];
        $createdCalendarInstanceIDs = [];

        try {
            foreach ($selectedCalendarIDs as $calendarID) {
                if (!isset($calendars[$calendarID])) {
                    throw new InvalidArgumentException($this->Translate('The calendar selection is invalid.'));
                }

                if (isset($existingInstances[$calendarID])) {
                    $calendarInstanceIDs[] = $existingInstances[$calendarID];
                    continue;
                }

                $calendarInstanceID = $this->createCalendarInstance(
                    $accountID,
                    $provider,
                    $calendars[$calendarID]
                );
                $createdCalendarInstanceIDs[] = $calendarInstanceID;
                $calendarInstanceIDs[] = $calendarInstanceID;
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($createdCalendarInstanceIDs) as $calendarInstanceID) {
                if (IPS_InstanceExists($calendarInstanceID)) {
                    IPS_DeleteInstance($calendarInstanceID);
                }
            }

            throw $exception;
        }

        return $calendarInstanceIDs;
    }

    /**
     * @return array<string, int>
     */
    private function existingCalendarInstancesForAccount(int $accountID): array
    {
        $instances = [];

        foreach (IPS_GetInstanceListByModuleID(self::CALENDAR_MODULE_ID) as $calendarInstanceID) {
            $instance = IPS_GetInstance($calendarInstanceID);
            if ((int) ($instance['ConnectionID'] ?? 0) !== $accountID) {
                continue;
            }

            $calendarID = trim((string) IPS_GetProperty($calendarInstanceID, 'CalendarID'));
            if ($calendarID !== '' && !isset($instances[$calendarID])) {
                $instances[$calendarID] = $calendarInstanceID;
            }
        }

        return $instances;
    }

    /**
     * @param array<string, mixed> $calendar
     */
    private function createCalendarInstance(int $accountID, string $provider, array $calendar): int
    {
        $calendarID = trim((string) ($calendar['id'] ?? ''));
        if ($calendarID === '') {
            throw new InvalidArgumentException($this->Translate('The calendar selection is invalid.'));
        }

        $calendarName = trim((string) ($calendar['name'] ?? ''));
        if ($calendarName === '') {
            $calendarName = $calendarID;
        }

        $capabilities = is_array($calendar['capabilities'] ?? null)
            ? $calendar['capabilities']
            : [];
        $canWrite = (bool) ($capabilities['create'] ?? false)
            || (bool) ($capabilities['update'] ?? false)
            || (bool) ($capabilities['delete'] ?? false);
        $isLocal = $provider === 'local';
        if ($isLocal && (!(bool) ($calendar['localCalendar'] ?? false)
            || $calendarID !== 'local:' . $accountID)) {
            throw new UnexpectedValueException($this->Translate('The local calendar identity is invalid.'));
        }

        $calendarInstanceID = 0;

        try {
            $calendarInstanceID = IPS_CreateInstance(self::CALENDAR_MODULE_ID);
            IPS_SetName(
                $calendarInstanceID,
                self::PROVIDER_INSTANCE_PREFIXES[$provider] . ' - ' . $calendarName
            );

            $parentID = IPS_GetParent($accountID);
            if ($parentID > 0) {
                IPS_SetParent($calendarInstanceID, $parentID);
            }

            IPS_SetProperty($calendarInstanceID, 'CalendarID', $calendarID);
            if ($isLocal) {
                IPS_SetProperty($calendarInstanceID, 'LocalCalendar', true);
            }
            IPS_SetProperty(
                $calendarInstanceID,
                'ProviderCalendarID',
                $isLocal ? '' : (string) ($calendar['providerId'] ?? $calendarID)
            );
            IPS_SetProperty($calendarInstanceID, 'CalendarURL', (string) ($calendar['url'] ?? ''));
            IPS_SetProperty($calendarInstanceID, 'CalendarColor', (string) ($calendar['color'] ?? ''));
            IPS_SetProperty($calendarInstanceID, 'CanWrite', $canWrite);
            IPS_SetProperty(
                $calendarInstanceID,
                'UpdateSchedule',
                $isLocal ? SynchronizationSchedule::MANUAL : (int) ($calendar['updateSchedule'] ?? 0)
            );
            IPS_SetProperty(
                $calendarInstanceID,
                'UpdateInterval',
                max(1, min(525600, (int) ($calendar['updateInterval'] ?? 15)))
            );

            if (!IPS_ConnectInstance($calendarInstanceID, $accountID)) {
                throw new RuntimeException(
                    $this->Translate('The calendar instance could not be connected to the calendar account.')
                );
            }

            IPS_ApplyChanges($calendarInstanceID);
        } catch (Throwable $exception) {
            if ($calendarInstanceID > 0 && IPS_InstanceExists($calendarInstanceID)) {
                IPS_DeleteInstance($calendarInstanceID);
            }

            throw new RuntimeException(
                sprintf(
                    $this->Translate('The calendar instance "%s" could not be created.'),
                    $calendarName
                ) . ' ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        return $calendarInstanceID;
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

            IPS_SetProperty($accountID, 'Provider', CalendarProviderType::fromKey($provider));
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
        $this->clearWizardCalendarViewSelection();
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
            $availableCalendarIDs = array_values(array_filter(array_map(
                static fn (array $calendar): string => trim((string) ($calendar['id'] ?? '')),
                $calendars
            )));
            $selectedCalendarIDs = array_values(array_intersect(
                $this->readWizardSelectedCalendarIDs(),
                $availableCalendarIDs
            ));
            if ($selectedCalendarIDs === [] && $this->GetBuffer('WizardExtensionMode') !== '1') {
                $selectedCalendarIDs = $this->defaultWizardSelectedCalendarIDs($calendars);
            }
            $this->SetBuffer(
                'WizardSelectedCalendarIDs',
                json_encode(
                    $selectedCalendarIDs,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
            $this->UpdateFormField(
                $this->GetBuffer('WizardExtensionMode') === '1' ? 'ExtensionCalendars' : 'WizardCalendars',
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
        $wasActive = (bool) IPS_GetProperty($accountID, 'Active');

        try {
            if (!$wasActive) {
                IPS_SetProperty($accountID, 'Active', true);
                IPS_ApplyChanges($accountID);
            }

            if (!IPSKALACC_Synchronize($accountID)) {
                $status = json_decode(
                    IPSKALACC_GetAccountStatus($accountID),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $error = is_array($status) ? trim((string) ($status['lastError'] ?? '')) : '';
                throw new RuntimeException(
                    $error !== '' ? $error : $this->Translate('Calendar discovery failed.')
                );
            }

            $payload = json_decode(
                IPSKALACC_GetCalendars($accountID),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                $this->Translate('The calendar account returned invalid calendar data.'),
                0,
                $exception
            );
        } finally {
            if (!$wasActive && IPS_InstanceExists($accountID)) {
                IPS_SetProperty($accountID, 'Active', false);
                IPS_ApplyChanges($accountID);
            }
        }

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
        $extensionMode = $this->GetBuffer('WizardExtensionMode') === '1'
            && IPS_InstanceExists((int) $this->GetBuffer('WizardAccountID'));
        $hasStoredSelection = $selectedCalendarIDs !== [] || $extensionMode;
        $existingCalendarIDs = $extensionMode
            ? array_keys($this->existingCalendarInstancesForAccount($this->wizardAccountID()))
            : [];
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
                'calendarId' => $calendarID,
                'setupState' => $extensionMode
                    ? $this->Translate(in_array($calendarID, $existingCalendarIDs, true)
                        ? 'Already configured'
                        : 'New calendar')
                    : ''
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
     * @param list<array<string, mixed>> $calendars
     * @return list<string>
     */
    private function defaultWizardSelectedCalendarIDs(array $calendars): array
    {
        $primaryCalendarIDs = array_values(array_filter(array_map(
            static fn (array $calendar): string => (bool) ($calendar['primary'] ?? false)
                ? trim((string) ($calendar['id'] ?? ''))
                : '',
            $calendars
        )));
        if ($primaryCalendarIDs !== []) {
            return $primaryCalendarIDs;
        }

        if (count($calendars) !== 1) {
            return [];
        }

        $calendarID = trim((string) ($calendars[0]['id'] ?? ''));
        return $calendarID !== '' ? [$calendarID] : [];
    }

    /**
     * @return list<string>
     */
    private function availableWizardCalendarIDs(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $calendar): string => trim((string) ($calendar['id'] ?? '')),
            $this->readWizardCalendars()
        )));
    }

    private function assertWizardCalendarSelectionValid(): void
    {
        $availableCalendarIDs = $this->availableWizardCalendarIDs();
        if ($availableCalendarIDs === []) {
            throw new RuntimeException($this->Translate('No discovered calendars are available.'));
        }

        $selectedCalendarIDs = $this->readWizardSelectedCalendarIDs();
        if ($selectedCalendarIDs === []) {
            throw new InvalidArgumentException($this->Translate('Please select at least one calendar.'));
        }

        foreach ($selectedCalendarIDs as $calendarID) {
            if (!in_array($calendarID, $availableCalendarIDs, true)) {
                throw new InvalidArgumentException($this->Translate('The calendar selection is invalid.'));
            }
        }
    }

    private function updateWizardCalendarSelection(mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($this->Translate('The calendar selection is invalid.'));
        }

        try {
            $change = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException($this->Translate('The calendar selection is invalid.'));
        }
        if (!is_array($change) || !array_key_exists('selected', $change)) {
            throw new InvalidArgumentException($this->Translate('The calendar selection is invalid.'));
        }

        $calendarID = trim((string) ($change['calendarId'] ?? ''));
        if ($calendarID === '' || !in_array($calendarID, $this->availableWizardCalendarIDs(), true)) {
            throw new InvalidArgumentException($this->Translate('The calendar selection is invalid.'));
        }

        $selectedCalendarIDs = $this->readWizardSelectedCalendarIDs();
        if ((bool) $change['selected']) {
            $selectedCalendarIDs[] = $calendarID;
        } else {
            $selectedCalendarIDs = array_values(array_filter(
                $selectedCalendarIDs,
                static fn (string $selectedCalendarID): bool => $selectedCalendarID !== $calendarID
            ));
        }

        $this->SetBuffer(
            'WizardSelectedCalendarIDs',
            json_encode(
                array_values(array_unique($selectedCalendarIDs)),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
    }

    private function confirmWizardCalendarSelection(): void
    {
        $this->assertWizardCalendarSelectionValid();
    }

    private function clearWizardCalendarSelection(bool $clearDiscovery): void
    {
        if ($clearDiscovery) {
            $this->SetBuffer('WizardCalendars', '[]');
        }
        $this->SetBuffer('WizardSelectedCalendarIDs', '[]');
    }

    /**
     * @return list<array{caption: string, value: int}>
     */
    private function calendarViewSelectOptions(): array
    {
        $options = [[
            'caption' => $this->Translate('Please select an existing calendar view.'),
            'value'   => 0
        ]];
        $views = [];

        foreach (IPS_GetInstanceListByModuleID(self::CALENDAR_VIEW_MODULE_ID) as $viewID) {
            $name = trim(IPS_GetName($viewID));
            if ($name === '') {
                $name = $this->Translate('Calendar view');
            }
            $views[] = [
                'caption' => sprintf('%s (#%d)', $name, $viewID),
                'value'   => $viewID
            ];
        }

        usort(
            $views,
            static fn (array $left, array $right): int => strnatcasecmp($left['caption'], $right['caption'])
                ?: ($left['value'] <=> $right['value'])
        );

        return array_merge($options, $views);
    }

    private function storeWizardCalendarViewSelection(mixed $value): void
    {
        $selection = $this->decodeWizardCalendarViewSelection($value);
        $this->assertWizardCalendarViewSelectionValid(
            $selection['mode'],
            $selection['existingViewID'],
            $selection['viewName']
        );
        $this->SetBuffer(
            'WizardCalendarViewSelection',
            json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * @return array{mode: string, existingViewID: int, viewName: string}
     */
    private function readWizardCalendarViewSelection(): array
    {
        return $this->decodeWizardCalendarViewSelection($this->GetBuffer('WizardCalendarViewSelection'));
    }

    /**
     * @return array{mode: string, existingViewID: int, viewName: string}
     */
    private function decodeWizardCalendarViewSelection(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($this->Translate('The calendar view selection is invalid.'));
        }

        try {
            $selection = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException($this->Translate('The calendar view selection is invalid.'));
        }
        if (!is_array($selection)) {
            throw new InvalidArgumentException($this->Translate('The calendar view selection is invalid.'));
        }

        return [
            'mode'           => (string) ($selection['mode'] ?? ''),
            'existingViewID' => (int) ($selection['existingViewID'] ?? 0),
            'viewName'       => trim((string) ($selection['viewName'] ?? ''))
        ];
    }

    private function assertWizardCalendarViewSelectionValid(
        string $viewMode,
        int $existingViewID,
        string $viewName
    ): void {
        if ($viewMode === self::VIEW_MODE_NEW) {
            if (trim($viewName) === '') {
                throw new InvalidArgumentException($this->Translate('Please enter a name for the calendar view.'));
            }

            return;
        }

        if ($viewMode !== self::VIEW_MODE_EXISTING) {
            throw new InvalidArgumentException(
                $this->Translate('Please choose how the calendar view should be provided.')
            );
        }
        if ($existingViewID <= 0 || !IPS_InstanceExists($existingViewID)) {
            throw new InvalidArgumentException($this->Translate('Please select an existing calendar view.'));
        }
        if (!in_array($existingViewID, IPS_GetInstanceListByModuleID(self::CALENDAR_VIEW_MODULE_ID), true)) {
            throw new InvalidArgumentException($this->Translate('The selected instance is not a calendar view.'));
        }
    }

    private function clearWizardCalendarViewSelection(): void
    {
        $this->SetBuffer('WizardCalendarViewSelection', '');
    }

    /**
     * @return array{provider: string, createConfigurator: bool}
     */
    private function decodeWizardProviderSelection(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($this->Translate('Please select a calendar provider.'));
        }

        $provider = trim($value);
        $createConfigurator = false;
        if (str_starts_with($provider, '{')) {
            try {
                $selection = json_decode($provider, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidArgumentException($this->Translate('Please select a calendar provider.'));
            }
            if (!is_array($selection)) {
                throw new InvalidArgumentException($this->Translate('Please select a calendar provider.'));
            }

            $provider = trim((string) ($selection['provider'] ?? ''));
            $createConfigurator = (bool) ($selection['createConfigurator'] ?? false);
        }

        $this->assertSupportedProvider($provider);

        return [
            'provider'           => $provider,
            'createConfigurator' => $createConfigurator
        ];
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
        if ((int) IPS_GetProperty($accountID, 'Provider') !== CalendarProviderType::fromKey($provider)) {
            throw new RuntimeException(
                $this->Translate('The selected calendar account uses a different calendar provider.')
            );
        }
    }

    /**
     * @return list<array{caption: string, value: int}>
     */
    private function calendarAccountSelectOptions(bool $activeOnly = false, ?int $providerFilter = null): array
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
            if ($providerFilter !== null && $provider !== $providerFilter) {
                continue;
            }
            if ($activeOnly && (
                !(bool) IPS_GetProperty($accountID, 'Active')
                || !isset(self::PROVIDER_LABELS[$provider])
            )) {
                continue;
            }
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

        if ((int) IPS_GetProperty($existingAccountID, 'Provider') !== CalendarProviderType::fromKey($provider)) {
            throw new InvalidArgumentException(
                $this->Translate('The selected calendar account uses a different calendar provider.')
            );
        }
    }

    private function assertSupportedProvider(string $provider): void
    {
        if (!CalendarProviderType::isSupportedKey($provider)) {
            throw new InvalidArgumentException($this->Translate('Please select a calendar provider.'));
        }
    }

}
