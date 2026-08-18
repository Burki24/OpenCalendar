<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\ChunkedJsonTransferHelper;
use Burki24\SymconModuleHelper\ConfigurationFormHelper;
use Burki24\SymconModuleHelper\DataFlowHelper;
use Burki24\SymconModuleHelper\DebugHelper;
use Burki24\SymconModuleHelper\HttpResponseHelper;
use Burki24\SymconModuleHelper\SymconOAuthException;
use IPSKalender\CalDAVOriginPolicy;
use IPSKalender\CalDAVProvider;
use IPSKalender\CalDAVProviderException;
use IPSKalender\CalendarEventRecurrence;
use IPSKalender\CalendarEventTranslation;
use IPSKalender\CalendarHttpClient;
use IPSKalender\CalendarHttpOriginPolicyInterface;
use IPSKalender\CalendarProviderInterface;
use IPSKalender\GoogleCalendarOriginPolicy;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\GoogleCalendarProviderException;
use IPSKalender\ICalendarAuthentication;
use IPSKalender\ICalendarFeedProvider;
use IPSKalender\ICalendarFeedProviderException;
use IPSKalender\ICalendarFileProvider;
use IPSKalender\ICalendarFileProviderException;
use IPSKalender\ICalendarSubscriptionProvider;
use IPSKalender\MicrosoftCalendarProvider;
use IPSKalender\MicrosoftCalendarProviderException;
use IPSKalender\MicrosoftGraphOriginPolicy;
use IPSKalender\SynchronizationSchedule;

require_once __DIR__ . '/../libs/helper/ChunkedJsonTransferHelper.php';
require_once __DIR__ . '/../libs/helper/ConfigurationFormHelper.php';
require_once __DIR__ . '/../libs/helper/DataFlowHelper.php';
require_once __DIR__ . '/../libs/helper/DebugHelper.php';
require_once __DIR__ . '/../libs/helper/HttpResponseHelper.php';
require_once __DIR__ . '/../libs/helper/SymconOAuthHelper.php';
require_once __DIR__ . '/../libs/CalendarProviderInterface.php';
require_once __DIR__ . '/../libs/RecurringCalendarProviderInterface.php';
require_once __DIR__ . '/../libs/CalendarHttpClient.php';
require_once __DIR__ . '/../libs/CalendarHttpOriginPolicyInterface.php';
require_once __DIR__ . '/../libs/CalendarEventTranslation.php';
require_once __DIR__ . '/../libs/CalendarEventRecurrence.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';
require_once __DIR__ . '/../libs/CalDAVOriginPolicy.php';
require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/GoogleCalendarOriginPolicy.php';
require_once __DIR__ . '/../libs/GoogleOAuthOriginPolicy.php';
require_once __DIR__ . '/../libs/ICalendarAuthentication.php';
require_once __DIR__ . '/../libs/ICalendarFeedProvider.php';
require_once __DIR__ . '/../libs/ICalendarFileProvider.php';
require_once __DIR__ . '/../libs/ICalendarSubscriptionProvider.php';
require_once __DIR__ . '/../libs/MicrosoftCalendarProvider.php';
require_once __DIR__ . '/../libs/MicrosoftGraphOriginPolicy.php';
require_once __DIR__ . '/../libs/SymconOAuthOriginPolicy.php';
require_once __DIR__ . '/../libs/SynchronizationSchedule.php';
require_once __DIR__ . '/traits/SymconOAuthTrait.php';
require_once __DIR__ . '/traits/GoogleOAuthTrait.php';
require_once __DIR__ . '/traits/MicrosoftOAuthTrait.php';
require_once __DIR__ . '/traits/ICalendarAccountTrait.php';
require_once __DIR__ . '/traits/ChildGatewayTrait.php';

class CalendarAccount extends IPSModuleStrict
{
    use ChunkedJsonTransferHelper;
    use ConfigurationFormHelper;
    use DataFlowHelper;
    use DebugHelper;
    use HttpResponseHelper;
    use KalenderKontoSymconOAuthTrait;
    use KalenderKontoGoogleOAuthTrait;
    use KalenderKontoMicrosoftOAuthTrait;
    use KalenderKontoICalendarAccountTrait;
    use KalenderKontoChildGatewayTrait;

    private const DATA_ID_FROM_CHILD = '{4E535B1D-69C7-AC77-1372-0282B21BAEC9}';
    private const DATA_ID_TO_CHILD = '{8ED646DD-88E9-ACE2-95D5-9766EED4B5B0}';
    private const MODULE_ID = '{966D6119-7FF3-5CA5-06C3-536FBF8100C4}';
    private const EVENT_TRANSFER_SCOPE = 'AccountCalendarEvents';
    private const APPLE_CALDAV_URL = 'https://caldav.icloud.com';
    private const CONNECT_CONTROL_MODULE_ID = '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}';
    private const GOOGLE_OAUTH_IDENTIFIER = 'opencalendar_google';
    private const MICROSOFT_OAUTH_IDENTIFIER = 'opencalendar_microsoft';
    private const OAUTH_REGISTRATION_DELAY_MS = 5_000;
    private const OAUTH_DISPATCHER_RECHECK_MS = 60_000;
    private const OAUTH_PENDING_TIMEOUT_SECONDS = 900;

    private const PROVIDER_APPLE = 0;
    private const PROVIDER_CALDAV = 1;
    private const PROVIDER_GOOGLE = 2;
    private const PROVIDER_MICROSOFT = 3;
    private const PROVIDER_ICS = 4;

    private const STATUS_CONFIGURATION_MISSING = 201;
    private const STATUS_AUTHENTICATION_FAILED = 202;
    private const STATUS_CONNECTION_FAILED = 203;
    private const STATUS_INVALID_RESPONSE = 205;

    /**
     * Registers account properties, provider state, cache attributes, timers, and kernel messages.
     */
    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyInteger('Provider', self::PROVIDER_APPLE);
        $this->RegisterPropertyString('ServerURL', self::APPLE_CALDAV_URL);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('CalendarName', '');
        $this->RegisterPropertyInteger('ICalendarAuthenticationMode', ICalendarAuthentication::AUTOMATIC);
        $this->RegisterPropertyInteger('ICalendarTranslationProfile', CalendarEventTranslation::NONE);
        $this->RegisterPropertyString('ICalendarFeeds', '[]');
        $this->RegisterPropertyString('ICalendarFiles', '[]');
        $this->RegisterPropertyInteger('UpdateSchedule', SynchronizationSchedule::CUSTOM);
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyBoolean('VerifyTLS', true);
        $this->RegisterPropertyInteger('RequestTimeout', 30);

        $this->RegisterAttributeString('CachedCalendars', '[]');
        $this->RegisterAttributeString('ICalendarFeedCache', '{}');
        $this->RegisterAttributeInteger('LastSynchronization', 0);
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeString('GoogleRefreshToken', '');
        $this->RegisterAttributeString('GoogleAccount', '');
        // Legacy marker used to require one reconnect after migrating from personal Google OAuth.
        $this->RegisterAttributeString('GoogleTokenClientID', '');
        $this->RegisterAttributeString('MicrosoftRefreshToken', '');
        $this->RegisterAttributeString('MicrosoftAccount', '');
        $this->RegisterAttributeInteger('PendingOAuthProvider', -1);
        $this->RegisterAttributeInteger('PendingOAuthInstanceID', 0);
        $this->RegisterAttributeInteger('PendingOAuthStartedAt', 0);

        $this->RegisterTimer('SynchronizationTimer', 0, 'IPSKALACC_ScheduledSynchronize($_IPS[\'TARGET\']);');
        $this->RegisterTimer('OAuthRegistrationTimer', 0, 'IPSKALACC_InitializeOAuth($_IPS[\'TARGET\']);');
    }

    /**
     * Builds the provider-specific account configuration form.
     *
     * @return string JSON-encoded configuration form.
     */
    public function GetConfigurationForm(): string
    {
        $form = $this->LoadConfigurationForm();
        $provider = $this->ReadPropertyInteger('Provider');
        $isPasswordProvider = in_array($provider, [self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_ICS], true);
        $iCalendarAuthenticationMode = $this->ReadPropertyInteger('ICalendarAuthenticationMode');
        $showIcsCredentials = $iCalendarAuthenticationMode === ICalendarAuthentication::USERNAME_PASSWORD
            || ($iCalendarAuthenticationMode === ICalendarAuthentication::AUTOMATIC
                && (trim($this->ReadPropertyString('Username')) !== '' || $this->ReadPropertyString('Password') !== ''));
        $isGoogle = $provider === self::PROVIDER_GOOGLE;
        $isMicrosoft = $provider === self::PROVIDER_MICROSOFT;
        $isIcs = $provider === self::PROVIDER_ICS;
        $canConfigureTls = in_array($provider, [self::PROVIDER_CALDAV, self::PROVIDER_ICS], true);

        foreach ($form['elements'] as &$element) {
            $name = (string) ($element['name'] ?? '');
            if ($name === 'ServerURL') {
                $element['visible'] = $isPasswordProvider;
                $element['enabled'] = in_array($provider, [self::PROVIDER_CALDAV, self::PROVIDER_ICS], true);
                $element['caption'] = $isIcs ? $this->Translate('iCalendar URL') : $this->Translate('Server URL');
                if ($provider === self::PROVIDER_APPLE) {
                    $element['value'] = self::APPLE_CALDAV_URL;
                }
            } elseif ($name === 'ICalendarAuthenticationMode') {
                $element['visible'] = $isIcs;
            } elseif (in_array($name, ['Username', 'Password'], true)) {
                $element['visible'] = in_array($provider, [self::PROVIDER_APPLE, self::PROVIDER_CALDAV], true)
                    || ($isIcs && $showIcsCredentials);
            } elseif (in_array($name, ['CalendarName', 'ICalendarTranslationProfile'], true)) {
                $element['visible'] = $isIcs;
            } elseif (in_array($name, ['ICalendarSubscriptionsPanel', 'ICalendarFilesPanel'], true)) {
                $element['visible'] = $isIcs;
            } elseif ($name === 'UpdateSchedule') {
                $element['caption'] = $isIcs
                    ? $this->Translate('Account discovery schedule')
                    : $this->Translate('Synchronization schedule');
            } elseif ($name === 'UpdateInterval') {
                $element['visible'] = $this->ReadPropertyInteger('UpdateSchedule') === SynchronizationSchedule::CUSTOM;
                $element['caption'] = $isIcs
                    ? $this->Translate('Account custom interval')
                    : $this->Translate('Custom interval');
            } elseif ($name === 'VerifyTLS') {
                $element['visible'] = $canConfigureTls;
            } elseif (in_array($name, [
                'GoogleOAuthHint',
                'GoogleConnectHint',
                'GoogleStatus',
                'GoogleConnect',
                'GoogleDisconnect'
            ], true)) {
                $element['visible'] = $isGoogle;
                if ($name === 'GoogleStatus') {
                    $element['caption'] = $this->googleStatusText();
                } elseif ($name === 'GoogleConnect') {
                    $element['visible'] = $isGoogle && !$this->isGoogleConnected();
                } elseif ($name === 'GoogleDisconnect') {
                    $element['visible'] = $isGoogle && $this->isGoogleConnected();
                }
            } elseif (in_array($name, [
                'MicrosoftOAuthHint',
                'MicrosoftConnectHint',
                'MicrosoftStatus',
                'MicrosoftConnect',
                'MicrosoftDisconnect'
            ], true)) {
                $element['visible'] = $isMicrosoft;
                if ($name === 'MicrosoftStatus') {
                    $element['caption'] = $this->microsoftStatusText();
                } elseif ($name === 'MicrosoftConnect') {
                    $element['visible'] = $isMicrosoft && !$this->isMicrosoftConnected();
                } elseif ($name === 'MicrosoftDisconnect') {
                    $element['visible'] = $isMicrosoft && $this->isMicrosoftConnected();
                }
            }
        }
        unset($element);

        return $this->EncodeConfigurationForm($form);
    }

    /**
     * Updates provider-specific form fields when the provider selection changes.
     */
    public function UpdateProviderForm(int $provider): void
    {
        $isPasswordProvider = in_array($provider, [self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_ICS], true);
        $isGoogle = $provider === self::PROVIDER_GOOGLE;
        $isMicrosoft = $provider === self::PROVIDER_MICROSOFT;
        $isIcs = $provider === self::PROVIDER_ICS;
        $iCalendarAuthenticationMode = $this->ReadPropertyInteger('ICalendarAuthenticationMode');
        $showIcsCredentials = $iCalendarAuthenticationMode === ICalendarAuthentication::USERNAME_PASSWORD
            || ($iCalendarAuthenticationMode === ICalendarAuthentication::AUTOMATIC
                && (trim($this->ReadPropertyString('Username')) !== '' || $this->ReadPropertyString('Password') !== ''));
        $canConfigureTls = in_array($provider, [self::PROVIDER_CALDAV, self::PROVIDER_ICS], true);
        $this->UpdateFormField('ServerURL', 'visible', $isPasswordProvider);
        $this->UpdateFormField('ServerURL', 'caption', $isIcs ? $this->Translate('iCalendar URL') : $this->Translate('Server URL'));
        $this->UpdateFormField('ICalendarAuthenticationMode', 'visible', $isIcs);
        $showCredentials = in_array($provider, [self::PROVIDER_APPLE, self::PROVIDER_CALDAV], true)
            || ($isIcs && $showIcsCredentials);
        $this->UpdateFormField('Username', 'visible', $showCredentials);
        $this->UpdateFormField('Password', 'visible', $showCredentials);
        $this->UpdateFormField('CalendarName', 'visible', $isIcs);
        $this->UpdateFormField('ICalendarTranslationProfile', 'visible', $isIcs);
        $this->UpdateFormField('ICalendarSubscriptionsPanel', 'visible', $isIcs);
        $this->UpdateFormField('ICalendarFilesPanel', 'visible', $isIcs);
        $this->UpdateFormField('VerifyTLS', 'visible', $canConfigureTls);
        $this->UpdateFormField(
            'UpdateSchedule',
            'caption',
            $isIcs ? $this->Translate('Account discovery schedule') : $this->Translate('Synchronization schedule')
        );
        $this->UpdateFormField(
            'UpdateInterval',
            'caption',
            $isIcs ? $this->Translate('Account custom interval') : $this->Translate('Custom interval')
        );
        $this->UpdateFormField('GoogleStatus', 'visible', $isGoogle);
        $this->UpdateFormField('GoogleOAuthHint', 'visible', $isGoogle);
        $this->UpdateFormField('GoogleConnectHint', 'visible', $isGoogle);
        $this->UpdateFormField('GoogleConnect', 'visible', $isGoogle && !$this->isGoogleConnected());
        $this->UpdateFormField('GoogleDisconnect', 'visible', $isGoogle && $this->isGoogleConnected());
        $this->UpdateFormField('MicrosoftOAuthHint', 'visible', $isMicrosoft);
        $this->UpdateFormField('MicrosoftConnectHint', 'visible', $isMicrosoft);
        $this->UpdateFormField('MicrosoftStatus', 'visible', $isMicrosoft);
        $this->UpdateFormField('MicrosoftStatus', 'caption', $this->microsoftStatusText());
        $this->UpdateFormField('MicrosoftConnect', 'visible', $isMicrosoft && !$this->isMicrosoftConnected());
        $this->UpdateFormField('MicrosoftDisconnect', 'visible', $isMicrosoft && $this->isMicrosoftConnected());

        if ($provider === self::PROVIDER_APPLE) {
            $this->UpdateFormField('ServerURL', 'value', self::APPLE_CALDAV_URL);
            $this->UpdateFormField('ServerURL', 'enabled', false);
            return;
        }

        if ($provider === self::PROVIDER_CALDAV) {
            $storedProvider = $this->ReadPropertyInteger('Provider');
            $storedServerUrl = trim($this->ReadPropertyString('ServerURL'));
            if ($storedProvider === self::PROVIDER_APPLE || $storedServerUrl === self::APPLE_CALDAV_URL) {
                $this->UpdateFormField('ServerURL', 'value', '');
            }
            $this->UpdateFormField('ServerURL', 'enabled', true);
            return;
        }

        if ($provider === self::PROVIDER_ICS) {
            $storedProvider = $this->ReadPropertyInteger('Provider');
            $storedServerUrl = trim($this->ReadPropertyString('ServerURL'));
            if ($storedProvider === self::PROVIDER_APPLE || $storedServerUrl === self::APPLE_CALDAV_URL) {
                $this->UpdateFormField('ServerURL', 'value', '');
            }
            $this->UpdateFormField('ServerURL', 'enabled', true);
            return;
        }

        $this->UpdateFormField('ServerURL', 'enabled', false);
    }

    /**
     * Updates the legacy ICS credential fields for the selected authentication mode.
     */
    public function UpdateICalendarAuthenticationForm(int $authenticationMode): void
    {
        $showCredentials = $authenticationMode === ICalendarAuthentication::USERNAME_PASSWORD
            || ($authenticationMode === ICalendarAuthentication::AUTOMATIC
                && (trim($this->ReadPropertyString('Username')) !== '' || $this->ReadPropertyString('Password') !== ''));
        $this->UpdateFormField('Username', 'visible', $showCredentials);
        $this->UpdateFormField('Password', 'visible', $showCredentials);
    }

    /**
     * Updates the custom interval field for the selected synchronization schedule.
     */
    public function UpdateScheduleForm(int $schedule): void
    {
        $this->UpdateFormField(
            'UpdateInterval',
            'visible',
            $schedule === SynchronizationSchedule::CUSTOM
        );
    }

    /**
     * Registers the native OAuth callbacks once all Symcon instance interfaces are available.
     *
     * @param array<int, mixed> $Data Message payload supplied by Symcon.
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($SenderID === 0 && $Message === IPS_KERNELSTARTED) {
            $this->scheduleOAuthRegistration();
        }
    }

    /**
     * Handles actions triggered from the account configuration form.
     *
     * @param string $Ident Action identifier supplied by Symcon.
     * @param mixed  $Value Action value supplied by Symcon.
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'FormTestConnection':
                $result = json_decode($this->TestConnection(), true);
                $this->UpdateFormField(
                    is_array($result) && ($result['success'] ?? false)
                        ? 'ConnectionSuccessPopup'
                        : 'ConnectionFailurePopup',
                    'visible',
                    true
                );
                break;

            case 'FormSynchronize':
                $this->UpdateFormField(
                    $this->Synchronize() ? 'SynchronizationSuccessPopup' : 'SynchronizationFailurePopup',
                    'visible',
                    true
                );
                break;

            case 'FormClearCache':
                $this->ClearCache();
                $this->UpdateFormField('CacheClearedPopup', 'visible', true);
                break;

            case 'FormGoogleAuthorizationFailed':
                $this->UpdateFormField('GoogleAuthorizationFailedPopup', 'visible', true);
                break;

            case 'FormMicrosoftAuthorizationFailed':
                $this->UpdateFormField('MicrosoftAuthorizationFailedPopup', 'visible', true);
                break;

            case 'InternalOAuthBegin':
                $this->beginOAuthDispatch($Value);
                break;

            case 'InternalOAuthCancel':
                $this->cancelOAuthDispatch($Value);
                break;

            case 'InternalOAuthComplete':
                $this->completeOAuthDispatch($Value);
                break;

            default:
                throw new InvalidArgumentException('Unsupported form action: ' . $Ident);
        }
    }

    /**
     * Applies account configuration, validates the provider, and configures synchronization.
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $providerName = $this->getProviderName($this->ReadPropertyInteger('Provider'));
        $username = match ($this->ReadPropertyInteger('Provider')) {
            self::PROVIDER_GOOGLE    => trim($this->ReadAttributeString('GoogleAccount')),
            self::PROVIDER_MICROSOFT => trim($this->ReadAttributeString('MicrosoftAccount')),
            self::PROVIDER_ICS       => $this->iCalendarSummary(),
            default                  => trim($this->ReadPropertyString('Username'))
        };
        $this->SetSummary($username !== '' ? $providerName . ' – ' . $username : $providerName);

        $this->SetTimerInterval('OAuthRegistrationTimer', 0);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->scheduleOAuthRegistration();
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('SynchronizationTimer', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        $validationError = $this->validateConfiguration();
        if ($validationError !== '') {
            $this->SetTimerInterval('SynchronizationTimer', 0);
            $this->WriteAttributeString('LastError', $validationError);
            $this->SetStatus(self::STATUS_CONFIGURATION_MISSING);
            return;
        }

        $this->SetTimerInterval(
            'SynchronizationTimer',
            SynchronizationSchedule::timerInterval(
                $this->ReadPropertyInteger('UpdateSchedule'),
                $this->ReadPropertyInteger('UpdateInterval')
            )
        );
        $this->SetStatus(IS_ACTIVE);
    }

    /**
     * Registers OAuth handlers after a short delay so module reloads can finish first.
     *
     * @return bool True when deferred registration was processed.
     */
    public function InitializeOAuth(): bool
    {
        $this->SetTimerInterval('OAuthRegistrationTimer', 0);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return false;
        }

        if (!$this->isOAuthDispatcher()) {
            $this->SetTimerInterval('OAuthRegistrationTimer', self::OAUTH_DISPATCHER_RECHECK_MS);
            return false;
        }

        $this->registerOAuthHandlers();
        return true;
    }

    /**
     * Runs account synchronization when the configured schedule is due.
     *
     * @return bool True when no synchronization was due or synchronization succeeded.
     */
    public function ScheduledSynchronize(): bool
    {
        if (!SynchronizationSchedule::isDue(
            $this->ReadPropertyInteger('UpdateSchedule'),
            $this->ReadPropertyInteger('UpdateInterval'),
            $this->ReadAttributeInteger('LastSynchronization')
        )) {
            return true;
        }

        return $this->Synchronize();
    }

    /**
     * Tests the configured provider connection without modifying calendar data.
     *
     * @return string JSON-encoded connection test result.
     */
    public function TestConnection(): string
    {
        $validationError = $this->validateConfiguration();
        if ($validationError !== '') {
            $this->WriteAttributeString('LastError', $validationError);
            $this->SetStatus(self::STATUS_CONFIGURATION_MISSING);

            return json_encode(
                ['success' => false, 'message' => $validationError],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }

        $providerName = $this->getProviderName($this->ReadPropertyInteger('Provider'));
        $startedAt = microtime(true);
        $this->SendSafeDebug('ConnectionTestStart', [
            'provider'       => $providerName,
            'timeoutSeconds' => max(5, min(120, $this->ReadPropertyInteger('RequestTimeout')))
        ]);

        try {
            $provider = $this->createProvider();
            $result = $provider->testConnection();
            if (isset($result['message']) && is_string($result['message'])) {
                $result['message'] = $this->Translate($result['message']);
            }
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus($this->ReadPropertyBoolean('Active') ? IS_ACTIVE : IS_INACTIVE);
            $this->SendSafeDebug('ConnectionTestCompleted', [
                'provider'   => $providerName,
                'success'    => (bool) ($result['success'] ?? true),
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000)
            ]);

            return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $message = $this->handleProviderError($exception);

            return json_encode(
                ['success' => false, 'message' => $message],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }
    }

    /**
     * Refreshes the account-level calendar discovery cache.
     *
     * @return bool True when synchronization succeeded.
     */
    public function Synchronize(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }

        $validationError = $this->validateConfiguration();
        if ($validationError !== '') {
            $this->WriteAttributeString('LastError', $validationError);
            $this->SetStatus(self::STATUS_CONFIGURATION_MISSING);
            return false;
        }

        $providerName = $this->getProviderName($this->ReadPropertyInteger('Provider'));
        $startedAt = microtime(true);
        $this->SendSafeDebug('AccountSynchronizationStart', ['provider' => $providerName]);

        try {
            $calendars = $this->discoverCalendars();
            $this->SetStatus(IS_ACTIVE);

            $this->SendDataToChildren($this->EncodeDataFlowMessage(
                self::DATA_ID_TO_CHILD,
                [
                    'Operation' => 'CalendarsUpdated',
                    'Payload'   => $calendars
                ]
            ));

            $this->SendSafeDebug('AccountSynchronizationCompleted', [
                'provider'      => $providerName,
                'calendarCount' => count($calendars),
                'durationMs'    => (int) round((microtime(true) - $startedAt) * 1000)
            ]);

            return true;
        } catch (Throwable $exception) {
            $this->handleProviderError($exception);
            return false;
        }
    }

    /**
     * Returns the calendars currently cached for this account.
     *
     * @return string JSON-encoded calendar list.
     */
    public function GetCalendars(): string
    {
        $cachedCalendars = $this->ReadAttributeString('CachedCalendars');
        $provider = $this->ReadPropertyInteger('Provider');
        if (!in_array(
            $provider,
            [self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_GOOGLE, self::PROVIDER_MICROSOFT],
            true
        )) {
            return $cachedCalendars;
        }

        try {
            $calendars = json_decode($cachedCalendars, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($calendars) || !array_is_list($calendars)) {
                return $cachedCalendars;
            }

            return json_encode(
                self::normalizeCachedCalendarCapabilities($calendars, $provider),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return $cachedCalendars;
        }
    }

    /**
     * Returns provider, synchronization, and cache status for this account.
     *
     * @return string JSON-encoded account status.
     */
    public function GetAccountStatus(): string
    {
        return json_encode(
            [
                'provider'            => $this->getProviderName($this->ReadPropertyInteger('Provider')),
                'connected'           => match ($this->ReadPropertyInteger('Provider')) {
                    self::PROVIDER_GOOGLE    => $this->isGoogleConnected(),
                    self::PROVIDER_MICROSOFT => $this->isMicrosoftConnected(),
                    default                  => true
                },
                'account'             => match ($this->ReadPropertyInteger('Provider')) {
                    self::PROVIDER_GOOGLE    => $this->ReadAttributeString('GoogleAccount'),
                    self::PROVIDER_MICROSOFT => $this->ReadAttributeString('MicrosoftAccount'),
                    self::PROVIDER_ICS       => $this->iCalendarSummary(),
                    default                  => trim($this->ReadPropertyString('Username'))
                },
                'lastSynchronization' => $this->ReadAttributeInteger('LastSynchronization'),
                'lastError'           => $this->ReadAttributeString('LastError'),
                'subscriptionCache'   => $this->ReadPropertyInteger('Provider') === self::PROVIDER_ICS
                    ? $this->iCalendarCacheStatus()
                    : []
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Clears account-level calendar and iCalendar feed caches.
     */
    public function ClearCache(): void
    {
        $this->WriteAttributeString('CachedCalendars', '[]');
        $this->WriteAttributeString('ICalendarFeedCache', '{}');
        $this->WriteAttributeInteger('LastSynchronization', 0);
        $this->WriteAttributeString('LastError', '');
    }

    /**
     * Routes a native Symcon OAuth callback to the provider configured for this account.
     */
    protected function ProcessOAuthData(): void
    {
        if (!$this->isOAuthDispatcher()) {
            $this->SendHtmlTextResponse(409, $this->Translate('The OAuth callback was received by the wrong account instance.'));
            return;
        }

        $targetInstanceId = $this->ReadAttributeInteger('PendingOAuthInstanceID');
        $provider = $this->ReadAttributeInteger('PendingOAuthProvider');
        $startedAt = $this->ReadAttributeInteger('PendingOAuthStartedAt');
        $this->clearOAuthDispatch();

        if (!$this->isValidOAuthProvider($provider)
            || $targetInstanceId <= 0
            || time() - $startedAt > self::OAUTH_PENDING_TIMEOUT_SECONDS
            || !$this->isCalendarAccountInstance($targetInstanceId)) {
            $this->SendHtmlTextResponse(400, $this->Translate('The pending calendar authorization is missing or expired.'));
            return;
        }

        try {
            IPS_RequestAction(
                $targetInstanceId,
                'InternalOAuthComplete',
                json_encode(
                    ['provider' => $provider, 'data' => $this->readSymconOAuthData()],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (Throwable $exception) {
            $this->SendHtmlTextResponse(
                400,
                $this->Translate('Calendar authorization could not be completed') . ': '
                    . $this->sanitizeError($exception->getMessage())
            );
        }
    }

    /**
     * Defers OAuth registration until the current module reload sequence has settled.
     */
    /**
     * Adds capabilities introduced after calendar discovery to compatible cached entries.
     *
     * Existing account caches survive module updates. Capabilities introduced later are
     * therefore derived from the provider and the already cached write permission so child
     * calendar instances can use them without a manual account resynchronization. Google
     * calendar-default reminder details remain protected until the next discovery refresh.
     *
     * @param list<array<string, mixed>> $calendars Cached account calendars.
     * @return list<array<string, mixed>> Normalized calendars.
     */
    private static function normalizeCachedCalendarCapabilities(array $calendars, int $provider): array
    {
        if (!in_array(
            $provider,
            [self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_GOOGLE, self::PROVIDER_MICROSOFT],
            true
        )) {
            return $calendars;
        }

        foreach ($calendars as &$calendar) {
            $capabilities = is_array($calendar['capabilities'] ?? null)
                ? $calendar['capabilities']
                : [];
            $accessRole = strtolower(trim((string) ($calendar['accessRole'] ?? '')));
            $canWrite = (bool) ($capabilities['create'] ?? false)
                || in_array($accessRole, ['writer', 'owner'], true);
            if (!array_key_exists('maxReminders', $capabilities)) {
                $capabilities['maxReminders'] = in_array(
                    $provider,
                    [self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_GOOGLE],
                    true
                ) ? 5 : 1;
            }
            if (!array_key_exists('createRecurrence', $capabilities)) {
                $capabilities['createRecurrence'] = $canWrite;
            }
            if (!array_key_exists('updateRecurrence', $capabilities)) {
                $capabilities['updateRecurrence'] = $canWrite;
            }
            if (in_array($provider, [self::PROVIDER_APPLE, self::PROVIDER_CALDAV], true)) {
                if (!array_key_exists('updateOccurrence', $capabilities)) {
                    $capabilities['updateOccurrence'] = $canWrite;
                }
                if (!array_key_exists('deleteOccurrence', $capabilities)) {
                    $capabilities['deleteOccurrence'] = $canWrite;
                }
                if (!array_key_exists('updateFollowing', $capabilities)) {
                    $capabilities['updateFollowing'] = $canWrite;
                }
                if (!array_key_exists('updateSeries', $capabilities)) {
                    $capabilities['updateSeries'] = $canWrite;
                }
                if (!array_key_exists('deleteSeries', $capabilities)) {
                    $capabilities['deleteSeries'] = $canWrite;
                }
            }
            if ($provider === self::PROVIDER_MICROSOFT) {
                if (!array_key_exists('createWithDefaultReminder', $capabilities)) {
                    $capabilities['createWithDefaultReminder'] = $canWrite;
                }
                if (!array_key_exists('updateOccurrence', $capabilities)) {
                    $capabilities['updateOccurrence'] = $canWrite;
                }
                if (!array_key_exists('deleteOccurrence', $capabilities)) {
                    $capabilities['deleteOccurrence'] = $canWrite;
                }
                if (!array_key_exists('updateFollowing', $capabilities)) {
                    $capabilities['updateFollowing'] = $canWrite;
                }
                if (!array_key_exists('updateSeries', $capabilities)) {
                    $capabilities['updateSeries'] = $canWrite;
                }
                if (!array_key_exists('deleteSeries', $capabilities)) {
                    $capabilities['deleteSeries'] = $canWrite;
                }
            }
            if ($provider === self::PROVIDER_GOOGLE) {
                if (!array_key_exists('useDefaultReminder', $capabilities)) {
                    $capabilities['useDefaultReminder'] = true;
                }
                if (!array_key_exists('createWithDefaultReminder', $capabilities)) {
                    $capabilities['createWithDefaultReminder'] = $canWrite;
                }
                if (!array_key_exists('defaultReminder', $calendar)) {
                    $calendar['defaultReminder'] = [
                        'mode'               => 'complex',
                        'minutesBeforeStart' => null,
                        'editable'           => false
                    ];
                }
                if (!array_key_exists('updateFollowing', $capabilities)) {
                    $capabilities['updateFollowing'] = $canWrite;
                }
                if (!array_key_exists('updateSeries', $capabilities)) {
                    $capabilities['updateSeries'] = $canWrite;
                }
                if (!array_key_exists('deleteSeries', $capabilities)) {
                    $capabilities['deleteSeries'] = $canWrite;
                }
            }
            $calendar['capabilities'] = $capabilities;
        }
        unset($calendar);

        return $calendars;
    }

    private function scheduleOAuthRegistration(): void
    {
        $this->SetTimerInterval('OAuthRegistrationTimer', 0);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->SetTimerInterval('OAuthRegistrationTimer', self::OAUTH_REGISTRATION_DELAY_MS);
        }
    }

    /**
     * Registers shared Google and Microsoft OAuth endpoints after the kernel is ready.
     */
    private function registerOAuthHandlers(): void
    {
        if (!$this->isOAuthDispatcher()) {
            return;
        }

        foreach ([self::GOOGLE_OAUTH_IDENTIFIER, self::MICROSOFT_OAUTH_IDENTIFIER] as $identifier) {
            try {
                if (!$this->RegisterOAuth($identifier)) {
                    $this->SendSafeDebug(
                        'OAuthRegistration',
                        sprintf('OAuth handler "%s" could not be registered.', $identifier)
                    );
                }
            } catch (Throwable $exception) {
                $this->SendSafeDebug('OAuthRegistrationError', [
                    'type'    => $exception::class,
                    'message' => $this->sanitizeError($exception->getMessage()),
                    'code'    => $exception->getCode()
                ]);
            }
        }
    }

    private function beginOAuthDispatch(mixed $value): void
    {
        if (!$this->isOAuthDispatcher()) {
            throw new SymconOAuthException('The OAuth dispatcher is unavailable.');
        }

        $payload = $this->decodeOAuthActionPayload($value);
        $targetInstanceId = (int) ($payload['instanceId'] ?? 0);
        $provider = (int) ($payload['provider'] ?? -1);
        if (!$this->isValidOAuthProvider($provider) || !$this->isCalendarAccountInstance($targetInstanceId)) {
            throw new SymconOAuthException('The calendar OAuth request is invalid.');
        }

        $pendingInstanceId = $this->ReadAttributeInteger('PendingOAuthInstanceID');
        $pendingStartedAt = $this->ReadAttributeInteger('PendingOAuthStartedAt');
        $pendingIsCurrent = $pendingInstanceId > 0
            && time() - $pendingStartedAt <= self::OAUTH_PENDING_TIMEOUT_SECONDS;
        if ($pendingIsCurrent && $pendingInstanceId !== $targetInstanceId) {
            throw new SymconOAuthException('Another calendar account authorization is already in progress.');
        }

        $this->WriteAttributeInteger('PendingOAuthInstanceID', $targetInstanceId);
        $this->WriteAttributeInteger('PendingOAuthProvider', $provider);
        $this->WriteAttributeInteger('PendingOAuthStartedAt', time());
        $this->registerOAuthHandlers();
    }

    private function cancelOAuthDispatch(mixed $value): void
    {
        if (!$this->isOAuthDispatcher()) {
            return;
        }

        $payload = $this->decodeOAuthActionPayload($value);
        $targetInstanceId = (int) ($payload['instanceId'] ?? 0);
        if ($targetInstanceId === $this->ReadAttributeInteger('PendingOAuthInstanceID')) {
            $this->clearOAuthDispatch();
        }
    }

    private function completeOAuthDispatch(mixed $value): void
    {
        $payload = $this->decodeOAuthActionPayload($value);
        $provider = (int) ($payload['provider'] ?? -1);
        $oauthData = $payload['data'] ?? null;
        if (!$this->isValidOAuthProvider($provider) || !is_array($oauthData)) {
            throw new SymconOAuthException('The calendar OAuth callback is invalid.');
        }

        $normalizedData = [];
        foreach ($oauthData as $key => $item) {
            if (is_scalar($item)) {
                $normalizedData[(string) $key] = (string) $item;
            }
        }

        if ($provider === self::PROVIDER_GOOGLE) {
            $this->processGoogleOAuthData($normalizedData);
            return;
        }

        $this->processMicrosoftOAuthData($normalizedData);
    }

    private function requestOAuthDispatch(int $provider): void
    {
        $dispatcherId = $this->oauthDispatcherId();
        if ($dispatcherId <= 0) {
            throw new SymconOAuthException('The OAuth dispatcher is unavailable.');
        }

        IPS_RequestAction(
            $dispatcherId,
            'InternalOAuthBegin',
            json_encode(
                ['instanceId' => $this->InstanceID, 'provider' => $provider],
                JSON_THROW_ON_ERROR
            )
        );
    }

    private function releaseOAuthDispatch(): void
    {
        $dispatcherId = $this->oauthDispatcherId();
        if ($dispatcherId <= 0) {
            return;
        }

        try {
            IPS_RequestAction(
                $dispatcherId,
                'InternalOAuthCancel',
                json_encode(['instanceId' => $this->InstanceID], JSON_THROW_ON_ERROR)
            );
        } catch (Throwable) {
            // Cleanup is best effort; an abandoned request expires automatically.
        }
    }

    /** @return array<string, mixed> */
    private function decodeOAuthActionPayload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function clearOAuthDispatch(): void
    {
        $this->WriteAttributeInteger('PendingOAuthProvider', -1);
        $this->WriteAttributeInteger('PendingOAuthInstanceID', 0);
        $this->WriteAttributeInteger('PendingOAuthStartedAt', 0);
    }

    private function oauthDispatcherId(): int
    {
        $instanceIds = array_values(array_filter(
            IPS_GetInstanceListByModuleID(self::MODULE_ID),
            static fn (int $instanceId): bool => $instanceId > 0 && IPS_InstanceExists($instanceId)
        ));
        sort($instanceIds, SORT_NUMERIC);

        return $instanceIds[0] ?? 0;
    }

    private function isOAuthDispatcher(): bool
    {
        return $this->InstanceID === $this->oauthDispatcherId();
    }

    private function isCalendarAccountInstance(int $instanceId): bool
    {
        if ($instanceId <= 0 || !IPS_InstanceExists($instanceId)) {
            return false;
        }

        $instance = IPS_GetInstance($instanceId);
        return ($instance['ModuleInfo']['ModuleID'] ?? '') === self::MODULE_ID;
    }

    private function isValidOAuthProvider(int $provider): bool
    {
        return in_array($provider, [self::PROVIDER_GOOGLE, self::PROVIDER_MICROSOFT], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverCalendars(): array
    {
        $validationError = $this->validateConfiguration();
        if ($validationError !== '') {
            throw new InvalidArgumentException($validationError);
        }

        $providerName = $this->getProviderName($this->ReadPropertyInteger('Provider'));
        $startedAt = microtime(true);
        $this->SendSafeDebug('CalendarDiscoveryStart', ['provider' => $providerName]);
        $calendars = $this->createProvider()->getCalendars();
        if ($this->ReadPropertyInteger('Provider') === self::PROVIDER_ICS) {
            $this->pruneICalendarFeedCache(array_map(
                static fn (array $calendar): string => (string) ($calendar['id'] ?? ''),
                $calendars
            ));
        }
        if ($this->ReadPropertyInteger('Provider') === self::PROVIDER_GOOGLE) {
            foreach ($calendars as $calendar) {
                if ((bool) ($calendar['primary'] ?? false)) {
                    $account = trim((string) ($calendar['providerId'] ?? ''));
                    $this->WriteAttributeString('GoogleAccount', $account);
                    if ($account !== '') {
                        $this->SetSummary($this->getProviderName(self::PROVIDER_GOOGLE) . ' – ' . $account);
                    }
                    break;
                }
            }
        }
        if ($this->ReadPropertyInteger('Provider') === self::PROVIDER_MICROSOFT) {
            $account = '';
            foreach ($calendars as $calendar) {
                $owner = trim((string) ($calendar['owner'] ?? ''));
                if ($owner !== '' && ((bool) ($calendar['primary'] ?? false) || $account === '')) {
                    $account = $owner;
                }
                if ($owner !== '' && (bool) ($calendar['primary'] ?? false)) {
                    break;
                }
            }
            $this->WriteAttributeString('MicrosoftAccount', $account);
            if ($account !== '') {
                $this->SetSummary($this->getProviderName(self::PROVIDER_MICROSOFT) . ' – ' . $account);
            }
        }
        $this->WriteAttributeString(
            'CachedCalendars',
            json_encode(
                $calendars,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
        $this->WriteAttributeInteger('LastSynchronization', time());
        $this->WriteAttributeString(
            'LastError',
            $this->ReadPropertyInteger('Provider') === self::PROVIDER_ICS
                ? $this->iCalendarCacheWarning()
                : ''
        );
        $this->SendSafeDebug('CalendarDiscoveryCompleted', [
            'provider'      => $providerName,
            'calendarCount' => count($calendars),
            'durationMs'    => (int) round((microtime(true) - $startedAt) * 1000)
        ]);

        return $calendars;
    }

    private function createProvider(): CalendarProviderInterface
    {
        $provider = $this->ReadPropertyInteger('Provider');
        $verifyTls = match ($provider) {
            self::PROVIDER_APPLE, self::PROVIDER_GOOGLE, self::PROVIDER_MICROSOFT => true,
            default                                                               => $this->ReadPropertyBoolean('VerifyTLS')
        };
        $this->SendSafeDebug('ProviderCreate', [
            'provider'       => $this->getProviderName($provider),
            'timeoutSeconds' => max(5, min(120, $this->ReadPropertyInteger('RequestTimeout'))),
            'verifyTLS'      => $verifyTls
        ]);

        if ($provider === self::PROVIDER_GOOGLE) {
            return new GoogleCalendarProvider(
                $this->createTrustedCloudHttpClient(new GoogleCalendarOriginPolicy()),
                $this->getGoogleAccessToken()
            );
        }

        if ($provider === self::PROVIDER_MICROSOFT) {
            return new MicrosoftCalendarProvider(
                $this->createTrustedCloudHttpClient(new MicrosoftGraphOriginPolicy()),
                $this->getMicrosoftAccessToken()
            );
        }

        if ($provider === self::PROVIDER_ICS) {
            return new ICalendarSubscriptionProvider(
                $this->iCalendarSources(),
                function (array $subscription): CalendarProviderInterface
                {
                    $subscriptionId = (string) ($subscription['id'] ?? '');
                    if (($subscription['sourceType'] ?? 'url') === 'file') {
                        return new ICalendarFileProvider(
                            (string) ($subscription['fileData'] ?? ''),
                            (string) ($subscription['name'] ?? ''),
                            $subscriptionId
                        );
                    }

                    $credentials = ICalendarAuthentication::credentials(
                        (int) ($subscription['authenticationMode'] ?? ICalendarAuthentication::AUTOMATIC),
                        (string) ($subscription['username'] ?? ''),
                        (string) ($subscription['password'] ?? '')
                    );

                    return new ICalendarFeedProvider(
                        new CalendarHttpClient(
                            max(5, min(120, $this->ReadPropertyInteger('RequestTimeout'))),
                            $this->ReadPropertyBoolean('VerifyTLS'),
                            $credentials['username'],
                            $credentials['password']
                        ),
                        (string) ($subscription['url'] ?? ''),
                        (string) ($subscription['name'] ?? ''),
                        $this->readICalendarFeedCache($subscriptionId),
                        function (array $cacheState) use ($subscriptionId): void
                        {
                            $this->writeICalendarFeedCache($subscriptionId, $cacheState);
                        }
                    );
                }
            );
        }

        if (!in_array($provider, [self::PROVIDER_APPLE, self::PROVIDER_CALDAV], true)) {
            throw new InvalidArgumentException('Unknown calendar provider.');
        }

        $serverUrl = $provider === self::PROVIDER_APPLE
            ? self::APPLE_CALDAV_URL
            : trim($this->ReadPropertyString('ServerURL'));

        $originPolicy = new CalDAVOriginPolicy($serverUrl);
        $verifyTls = $provider === self::PROVIDER_APPLE
            ? true
            : $this->ReadPropertyBoolean('VerifyTLS');

        return new CalDAVProvider(
            new CalendarHttpClient(
                max(5, min(120, $this->ReadPropertyInteger('RequestTimeout'))),
                $verifyTls,
                trim($this->ReadPropertyString('Username')),
                $this->ReadPropertyString('Password'),
                $originPolicy
            ),
            $serverUrl,
            $originPolicy
        );
    }

    private function validateConfiguration(): string
    {
        $provider = $this->ReadPropertyInteger('Provider');
        if (!SynchronizationSchedule::isValid($this->ReadPropertyInteger('UpdateSchedule'))) {
            return $this->Translate('The synchronization schedule is invalid.');
        }
        if (!in_array($provider, [
            self::PROVIDER_APPLE,
            self::PROVIDER_CALDAV,
            self::PROVIDER_GOOGLE,
            self::PROVIDER_MICROSOFT,
            self::PROVIDER_ICS
        ], true)) {
            return $this->Translate('Unknown calendar provider.');
        }

        if ($provider === self::PROVIDER_APPLE) {
            if (trim($this->ReadPropertyString('Username')) === '') {
                return $this->Translate('The Apple Account email address is missing.');
            }
            if ($this->ReadPropertyString('Password') === '') {
                return $this->Translate('The app-specific password is missing.');
            }
        }

        if ($provider === self::PROVIDER_CALDAV && trim($this->ReadPropertyString('ServerURL')) === '') {
            return $this->Translate('The CalDAV server URL is missing.');
        }

        if ($provider === self::PROVIDER_ICS) {
            $subscriptions = $this->iCalendarSubscriptions();
            $localFiles = $this->iCalendarLocalFiles();
            if ($subscriptions === [] && $localFiles === []) {
                return $this->Translate('At least one active iCalendar subscription or local file is required.');
            }
            $subscriptionUrls = [];
            foreach ($subscriptions as $subscription) {
                $url = trim((string) ($subscription['url'] ?? ''));
                if (filter_var($url, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https', 'webcal'], true)) {
                    return sprintf(
                        $this->Translate('The iCalendar URL for subscription "%s" is invalid.'),
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                $urlKey = $this->iCalendarUrlKey($url);
                if (isset($subscriptionUrls[$urlKey])) {
                    return sprintf(
                        $this->Translate('The iCalendar URL for subscription "%s" is configured more than once.'),
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                $subscriptionUrls[$urlKey] = true;
                $authenticationMode = (int) (
                    $subscription['authenticationMode']
                    ?? ICalendarAuthentication::AUTOMATIC
                );
                if (!ICalendarAuthentication::isValidMode($authenticationMode)) {
                    return sprintf(
                        $this->Translate('The authentication mode for iCalendar subscription "%s" is invalid.'),
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                if ($authenticationMode === ICalendarAuthentication::USERNAME_PASSWORD) {
                    if (trim((string) ($subscription['username'] ?? '')) === '') {
                        return sprintf(
                            $this->Translate('The username for iCalendar subscription "%s" is missing.'),
                            trim((string) ($subscription['name'] ?? ''))
                        );
                    }
                    if ((string) ($subscription['password'] ?? '') === '') {
                        return sprintf(
                            $this->Translate('The password for iCalendar subscription "%s" is missing.'),
                            trim((string) ($subscription['name'] ?? ''))
                        );
                    }
                }
                $color = strtoupper(trim((string) ($subscription['color'] ?? '')));
                if ($color !== '' && preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
                    return sprintf(
                        $this->Translate('The color for iCalendar subscription "%s" is invalid.'),
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                if (!SynchronizationSchedule::isValid((int) ($subscription['updateSchedule'] ?? -1))) {
                    return sprintf(
                        $this->Translate('The synchronization schedule for iCalendar subscription "%s" is invalid.'),
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                if (!CalendarEventTranslation::isValidProfile(
                    (int) ($subscription['translationProfile'] ?? -1)
                )) {
                    return sprintf(
                        $this->Translate('The title translation profile for iCalendar subscription "%s" is invalid.'),
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
            }

            $localFileNames = [];
            foreach ($localFiles as $localFile) {
                $name = trim((string) ($localFile['name'] ?? ''));
                if ($name === '') {
                    return $this->Translate('A calendar name is required for each local iCalendar file.');
                }
                if (isset($localFileNames[$name])) {
                    return sprintf(
                        $this->Translate('The local iCalendar file name "%s" is configured more than once.'),
                        $name
                    );
                }
                $localFileNames[$name] = true;

                $color = strtoupper(trim((string) ($localFile['color'] ?? '')));
                if ($color !== '' && preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
                    return sprintf(
                        $this->Translate('The color for local iCalendar file "%s" is invalid.'),
                        $name
                    );
                }
                if (!CalendarEventTranslation::isValidProfile(
                    (int) ($localFile['translationProfile'] ?? -1)
                )) {
                    return sprintf(
                        $this->Translate('The title translation profile for local iCalendar file "%s" is invalid.'),
                        $name
                    );
                }

                try {
                    new ICalendarFileProvider(
                        (string) ($localFile['fileData'] ?? ''),
                        $name,
                        hash('sha256', 'ics-file|' . $name)
                    );
                } catch (ICalendarFileProviderException $exception) {
                    return sprintf(
                        $this->Translate('The local iCalendar file "%s" is invalid: %s'),
                        $name,
                        $this->translateErrorMessage($exception->getMessage())
                    );
                }
            }
        }

        if ($provider === self::PROVIDER_GOOGLE) {
            if (!$this->isGoogleConnected()) {
                return $this->Translate('Google Calendar is not connected yet.');
            }
        }

        if ($provider === self::PROVIDER_MICROSOFT && !$this->isMicrosoftConnected()) {
            return $this->Translate('Microsoft 365 is not connected yet.');
        }

        return '';
    }

    private function getProviderName(int $provider): string
    {
        return $this->Translate(match ($provider) {
            self::PROVIDER_APPLE     => 'Apple iCloud',
            self::PROVIDER_CALDAV    => 'CalDAV',
            self::PROVIDER_GOOGLE    => 'Google Calendar',
            self::PROVIDER_MICROSOFT => 'Microsoft 365',
            self::PROVIDER_ICS       => 'ICS/Webcal',
            default                  => 'Unknown'
        });
    }

    private function handleProviderError(Throwable $exception): string
    {
        $rawMessage = $this->sanitizeError($exception->getMessage());

        if ($exception instanceof CalDAVProviderException) {
            if (in_array($exception->httpStatus, [401, 403], true)) {
                $this->SetStatus(self::STATUS_AUTHENTICATION_FAILED);
            } elseif (str_contains(strtolower($rawMessage), 'xml')) {
                $this->SetStatus(self::STATUS_INVALID_RESPONSE);
            } else {
                $this->SetStatus(self::STATUS_CONNECTION_FAILED);
            }
        } elseif ($exception instanceof GoogleCalendarProviderException) {
            $this->SetStatus($exception->httpStatus === 401
                ? self::STATUS_AUTHENTICATION_FAILED
                : self::STATUS_CONNECTION_FAILED);
        } elseif ($exception instanceof MicrosoftCalendarProviderException) {
            $this->SetStatus(in_array($exception->httpStatus, [401, 403], true)
                ? self::STATUS_AUTHENTICATION_FAILED
                : self::STATUS_CONNECTION_FAILED);
        } elseif ($exception instanceof ICalendarFeedProviderException
            || $exception instanceof ICalendarFileProviderException) {
            $this->SetStatus(in_array($exception->httpStatus, [401, 403], true)
                ? self::STATUS_AUTHENTICATION_FAILED
                : self::STATUS_CONNECTION_FAILED);
        } elseif ($exception instanceof SymconOAuthException) {
            $this->SetStatus(self::STATUS_AUTHENTICATION_FAILED);
        } elseif ($exception instanceof JsonException) {
            $this->SetStatus(self::STATUS_INVALID_RESPONSE);
        } else {
            $this->SetStatus(self::STATUS_CONNECTION_FAILED);
        }

        $message = $exception instanceof JsonException
            ? $this->Translate('Invalid JSON data.')
            : $this->translateErrorMessage($rawMessage);
        $this->WriteAttributeString('LastError', $message);
        $httpStatus = match (true) {
            $exception instanceof CalDAVProviderException,
            $exception instanceof GoogleCalendarProviderException,
            $exception instanceof MicrosoftCalendarProviderException,
            $exception instanceof ICalendarFeedProviderException,
            $exception instanceof ICalendarFileProviderException => $exception->httpStatus,
            default                                              => 0
        };
        $this->SendSafeDebug('ProviderError', [
            'provider'   => $this->getProviderName($this->ReadPropertyInteger('Provider')),
            'type'       => $exception::class,
            'httpStatus' => $httpStatus,
            'message'    => $rawMessage
        ]);

        return $message;
    }

    private function createTrustedCloudHttpClient(
        CalendarHttpOriginPolicyInterface $originPolicy
    ): CalendarHttpClient {
        return new CalendarHttpClient(
            max(5, min(120, $this->ReadPropertyInteger('RequestTimeout'))),
            true,
            '',
            '',
            $originPolicy
        );
    }

    private function translateErrorMessage(string $message): string
    {
        $message = $this->sanitizeError($message);
        if ($message === '') {
            return '';
        }

        if (preg_match('/^Unsupported operation: (.+)$/', $message, $matches) === 1) {
            return sprintf($this->Translate('Unsupported operation: %s'), $matches[1]);
        }
        if (preg_match('/^Unexpected CalDAV response during (.+): HTTP (\d+)\.$/', $message, $matches) === 1) {
            return sprintf(
                $this->Translate('Unexpected CalDAV response during %s: HTTP %d.'),
                $this->Translate($matches[1]),
                (int) $matches[2]
            );
        }

        $patterns = [
            '/^HTTP request failed \((\d+)\): (.+)$/'                                          => ['HTTP request failed (%d): %s', [1, 2]],
            '/^HTTP response exceeds the maximum size of (\d+) bytes\.$/'                      => ['HTTP response exceeds the maximum size of %d bytes.', [1]],
            '/^Unexpected CalDAV response: HTTP (\d+)\.$/'                                     => ['Unexpected CalDAV response: HTTP %d.', [1]],
            '/^Google Calendar request failed with HTTP (\d+)\.$/'                             => ['Google Calendar request failed with HTTP %d.', [1]],
            '/^Microsoft Calendar request failed with HTTP (\d+)\.$/'                          => ['Microsoft Calendar request failed with HTTP %d.', [1]],
            '/^The calendar feed returned HTTP status (\d+)\.$/'                               => ['The calendar feed returned HTTP status %d.', [1]],
            '/^The calendar contains an invalid date value: (.+)$/'                            => ['The calendar contains an invalid date value: %s', [1]],
            '/^The iCalendar subscription URL for "(.+)" is configured more than once\.$/'     => ['The iCalendar subscription URL for "%s" is configured more than once.', [1]],
            '/^The configured color for iCalendar subscription "(.+)" is invalid\.$/'          => ['The configured color for iCalendar subscription "%s" is invalid.', [1]],
            '/^The synchronization schedule for iCalendar subscription "(.+)" is invalid\.$/'  => ['The synchronization schedule for iCalendar subscription "%s" is invalid.', [1]],
            '/^The title translation profile for iCalendar subscription "(.+)" is invalid\.$/' => ['The title translation profile for iCalendar subscription "%s" is invalid.', [1]],
            '/^The iCalendar URL for subscription "(.+)" is invalid\.$/'                       => ['The iCalendar URL for subscription "%s" is invalid.', [1]],
            '/^The iCalendar URL for subscription "(.+)" is configured more than once\.$/'     => ['The iCalendar URL for subscription "%s" is configured more than once.', [1]],
            '/^The color for iCalendar subscription "(.+)" is invalid\.$/'                     => ['The color for iCalendar subscription "%s" is invalid.', [1]],
            '/^The local iCalendar file name "(.+)" is configured more than once\.$/'          => ['The local iCalendar file name "%s" is configured more than once.', [1]],
            '/^The color for local iCalendar file "(.+)" is invalid\.$/'                       => ['The color for local iCalendar file "%s" is invalid.', [1]],
            '/^The title translation profile for local iCalendar file "(.+)" is invalid\.$/'   => ['The title translation profile for local iCalendar file "%s" is invalid.', [1]],
        ];

        foreach ($patterns as $pattern => [$template, $groups]) {
            if (preg_match($pattern, $message, $matches) !== 1) {
                continue;
            }
            $values = array_map(static fn (int $group): string => $matches[$group], $groups);
            return sprintf($this->Translate($template), ...$values);
        }

        if (str_starts_with($message, 'The calendar feed could not be refreshed: ')) {
            $detail = substr($message, strlen('The calendar feed could not be refreshed: '));
            return sprintf(
                $this->Translate('The calendar feed could not be refreshed: %s'),
                $this->translateErrorMessage($detail)
            );
        }

        return $this->Translate($message);
    }

    private function sanitizeError(string $message): string
    {
        $password = $this->ReadPropertyString('Password');
        if ($password !== '') {
            $message = str_replace($password, '***', $message);
        }
        foreach ([
            $this->ReadAttributeString('GoogleRefreshToken'),
            $this->ReadAttributeString('MicrosoftRefreshToken')
        ] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }
        if ($this->ReadPropertyInteger('Provider') === self::PROVIDER_ICS) {
            foreach ($this->iCalendarSubscriptions() as $subscription) {
                $feedUrl = trim((string) ($subscription['url'] ?? ''));
                $feedPassword = (string) ($subscription['password'] ?? '');
                if ($feedUrl !== '') {
                    $message = str_replace($feedUrl, '[iCalendar URL]', $message);
                }
                if ($feedPassword !== '') {
                    $message = str_replace($feedPassword, '***', $message);
                }
            }
        }

        return $message;
    }
}
