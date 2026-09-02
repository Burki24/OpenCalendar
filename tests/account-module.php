<?php

declare(strict_types=1);

if (!class_exists('IPSModuleStrict')) {
    class IPSModuleStrict
    {
    }
}

require_once __DIR__ . '/../Kalender Konto/module.php';

final class CalendarAccountGatewayRecurrenceProbe
{
    use KalenderKontoChildGatewayTrait;

    /** @var list<array<string, mixed>> */
    private array $deleteCalls = [];

    public function ReadAttributeString(string $name): string
    {
        if ($name !== 'CachedCalendars') {
            return '';
        }

        return json_encode([
            [
                'id'        => 'calendar-id',
                'reference' => 'owner@example.com'
            ]
        ], JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    public function deleteCalls(): array
    {
        return $this->deleteCalls;
    }

    /** @return object{deleteEvent: callable} */
    private function createProvider(): object
    {
        return new class($this->deleteCalls) implements
            \IPSKalender\CalendarEventLookupProviderInterface,
            \IPSKalender\RecurringCalendarProviderInterface {
            /** @param list<array<string, mixed>> $deleteCalls */
            public function __construct(private array &$deleteCalls)
            {
            }

            /** @return array<string, mixed> */
            public function getEventForEdit(string $calendarReference, array $identity): array
            {
                $eventReference = trim((string) ($identity['eventReference'] ?? ''));

                return [
                    'uid'            => 'event@example.com',
                    'eventReference' => $eventReference,
                    'resourceUrl'    => $calendarReference . '/events/' . $eventReference,
                    'etag'           => '"fresh-etag"',
                    'summary'        => 'Fresh event',
                    'start'          => '2026-08-12T09:00:00+02:00',
                    'end'            => '2026-08-12T10:00:00+02:00',
                    'startTimestamp' => 1786518000,
                    'endTimestamp'   => 1786521600,
                    'allDay'         => false,
                    'recurring'      => false
                ];
            }

            /** @return array<string, mixed> */
            public function getRecurringSeries(
                string $calendarReference,
                string $seriesId,
                string $resourceReference = ''
            ): array {
                return [
                    'recurrenceType'  => 'master',
                    'seriesId'        => $seriesId,
                    'resourceUrl'     => $resourceReference !== ''
                        ? $resourceReference
                        : $calendarReference . '/events/' . $seriesId,
                    'canUpdateSeries' => true
                ];
            }

            /** @return array<string, mixed> */
            public function getRecurringFollowing(
                string $calendarReference,
                string $seriesId,
                string $occurrenceId,
                string $originalStart,
                string $resourceReference = ''
            ): array {
                return [
                    'recurrenceType'     => 'occurrence',
                    'seriesId'           => $seriesId,
                    'occurrenceId'       => $occurrenceId,
                    'originalStart'      => $originalStart,
                    'resourceUrl'        => $resourceReference !== ''
                        ? $resourceReference
                        : $calendarReference . '/events/' . $occurrenceId,
                    'canUpdateFollowing' => true
                ];
            }

            /** @param array<string, mixed> $recurrence */
            public function deleteEvent(
                string $calendarReference,
                string $eventReference,
                string $etag,
                string $recurrenceId = '',
                array $recurrence = []
            ): bool {
                $this->deleteCalls[] = [
                    'calendarReference' => $calendarReference,
                    'eventReference'    => $eventReference,
                    'etag'              => $etag,
                    'recurrenceId'      => $recurrenceId,
                    'recurrence'        => $recurrence
                ];

                return true;
            }
        };
    }
}

function assertAccountStructure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$gatewayProbe = new CalendarAccountGatewayRecurrenceProbe();
$getEventForEditForChild = new ReflectionMethod(CalendarAccountGatewayRecurrenceProbe::class, 'getEventForEditForChild');
$gatewayEventForEdit = $getEventForEditForChild->invoke($gatewayProbe, [
    'CalendarID'     => 'calendar-id',
    'EventReference' => 'event-id',
    'Start'          => 1786518000,
    'End'            => 1786521600
]);
assertAccountStructure(
    is_array($gatewayEventForEdit)
        && ($gatewayEventForEdit['eventReference'] ?? '') === 'event-id'
        && ($gatewayEventForEdit['etag'] ?? '') === '"fresh-etag"',
    'The account child gateway must read the current provider event before editing.'
);
$getRecurringSeriesForChild = new ReflectionMethod(CalendarAccountGatewayRecurrenceProbe::class, 'getRecurringSeriesForChild');
$gatewaySeriesResourceUrl = 'https://calendar.example/calendars/user/work/series-id.ics';
$gatewaySeries = $getRecurringSeriesForChild->invoke($gatewayProbe, [
    'CalendarID'  => 'calendar-id',
    'SeriesID'    => 'series-id',
    'ResourceURL' => $gatewaySeriesResourceUrl
]);
assertAccountStructure(
    is_array($gatewaySeries)
        && ($gatewaySeries['recurrenceType'] ?? '') === 'master'
        && ($gatewaySeries['seriesId'] ?? '') === 'series-id'
        && ($gatewaySeries['resourceUrl'] ?? '') === $gatewaySeriesResourceUrl
        && ($gatewaySeries['canUpdateSeries'] ?? false) === true,
    'A recurring parent event and its known resource URL must pass through the account child gateway for complete-series editing.'
);
$getRecurringFollowingForChild = new ReflectionMethod(
    CalendarAccountGatewayRecurrenceProbe::class,
    'getRecurringFollowingForChild'
);
$gatewayFollowingResourceUrl = 'https://calendar.example/calendars/user/work/series-id.ics';
$gatewayFollowing = $getRecurringFollowingForChild->invoke($gatewayProbe, [
    'CalendarID'    => 'calendar-id',
    'SeriesID'      => 'series-id',
    'OccurrenceID'  => 'instance-id',
    'OriginalStart' => '2026-08-12T09:00:00+02:00',
    'ResourceURL'   => $gatewayFollowingResourceUrl
]);
assertAccountStructure(
    is_array($gatewayFollowing)
        && ($gatewayFollowing['recurrenceType'] ?? '') === 'occurrence'
        && ($gatewayFollowing['seriesId'] ?? '') === 'series-id'
        && ($gatewayFollowing['occurrenceId'] ?? '') === 'instance-id'
        && ($gatewayFollowing['resourceUrl'] ?? '') === $gatewayFollowingResourceUrl
        && ($gatewayFollowing['canUpdateFollowing'] ?? false) === true,
    'A recurring target occurrence and its known resource URL must pass through the account child gateway for this-and-following editing.'
);
$deleteEventForChild = new ReflectionMethod(CalendarAccountGatewayRecurrenceProbe::class, 'deleteEventForChild');
$recurringEvent = [
    'CalendarID'  => 'calendar-id',
    'ResourceURL' => 'https://www.googleapis.com/calendar/v3/calendars/owner%40example.com/events/instance-id',
    'ETag'        => '"occurrence-etag"',
    'Recurrence'  => [
        'recurrenceType'      => 'occurrence',
        'seriesId'            => 'series-id',
        'occurrenceId'        => 'instance-id',
        'originalStart'       => '2026-08-12T09:00:00+02:00',
        'recurring'           => true,
        'canUpdateOccurrence' => true,
        'canDeleteOccurrence' => true,
        'canUpdateSeries'     => false,
        'canDeleteSeries'     => true,
        'writeScope'          => 'occurrence'
    ]
];
assertAccountStructure(
    $deleteEventForChild->invoke($gatewayProbe, $recurringEvent) === true,
    'A recurring Google occurrence must pass through the account child gateway for deletion.'
);
$recurringEvent['Recurrence']['writeScope'] = 'series';
assertAccountStructure(
    $deleteEventForChild->invoke($gatewayProbe, $recurringEvent) === true,
    'A complete Google recurring series must pass through the account child gateway for deletion.'
);
$deleteCalls = $gatewayProbe->deleteCalls();
assertAccountStructure(
    count($deleteCalls) === 2
        && ($deleteCalls[0]['recurrence']['recurrenceType'] ?? '') === 'occurrence'
        && ($deleteCalls[0]['recurrence']['seriesId'] ?? '') === 'series-id'
        && ($deleteCalls[0]['recurrence']['writeScope'] ?? '') === 'occurrence'
        && ($deleteCalls[1]['recurrence']['writeScope'] ?? '') === 'series',
    'The account child gateway must normalize recurring occurrence and series deletion metadata.'
);

$reflection = new ReflectionClass(CalendarAccount::class);
$traits = class_uses(CalendarAccount::class);
foreach ([
    KalenderKontoSymconOAuthTrait::class,
    KalenderKontoGoogleOAuthTrait::class,
    KalenderKontoMicrosoftOAuthTrait::class,
    KalenderKontoICalendarAccountTrait::class,
    KalenderKontoChildGatewayTrait::class
] as $trait) {
    assertAccountStructure(
        isset($traits[$trait]),
        sprintf('CalendarAccount must use %s.', $trait)
    );
}

foreach ([
    'Create',
    'GetConfigurationForm',
    'UpdateProviderForm',
    'UpdateICalendarAuthenticationForm',
    'UpdateScheduleForm',
    'MessageSink',
    'RequestAction',
    'ConnectGoogle',
    'DisconnectGoogle',
    'ConnectMicrosoft',
    'DisconnectMicrosoft',
    'ApplyChanges',
    'InitializeOAuth',
    'ScheduledSynchronize',
    'ForwardData',
    'TestConnection',
    'Synchronize',
    'GetCalendars',
    'GetAccountStatus',
    'ClearCache'
] as $method) {
    assertAccountStructure(
        $reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(),
        sprintf('Public account API method %s is missing.', $method)
    );
}

$providerFormState = $reflection->getMethod('providerFormState');
$providerFormState->setAccessible(true);
$formStateProbe = new class extends CalendarAccount {
    public function Translate(string $text): string
    {
        return $text;
    }
};
$appleFormState = $providerFormState->invoke($formStateProbe, 0, false, false, false);
$calDavFormState = $providerFormState->invoke($formStateProbe, 1, false, false, false);
$googleFormState = $providerFormState->invoke($formStateProbe, 2, false, false, false);
$microsoftFormState = $providerFormState->invoke($formStateProbe, 3, false, false, true);
$icsFormState = $providerFormState->invoke($formStateProbe, 4, false, false, false);
$icsCredentialsFormState = $providerFormState->invoke($formStateProbe, 4, true, false, false);
assertAccountStructure(
    ($appleFormState['ServerURL']['visible'] ?? false) === true
        && ($appleFormState['ServerURL']['enabled'] ?? true) === false
        && ($appleFormState['ServerURL']['value'] ?? '') === 'https://caldav.icloud.com'
        && ($appleFormState['Username']['visible'] ?? false) === true
        && ($appleFormState['VerifyTLS']['visible'] ?? true) === false,
    'Apple provider form state must keep the fixed iCloud URL, credentials, and TLS policy.'
);
assertAccountStructure(
    ($calDavFormState['ServerURL']['visible'] ?? false) === true
        && ($calDavFormState['ServerURL']['enabled'] ?? false) === true
        && !array_key_exists('value', $calDavFormState['ServerURL'])
        && ($calDavFormState['Username']['visible'] ?? false) === true
        && ($calDavFormState['VerifyTLS']['visible'] ?? false) === true,
    'CalDAV provider form state must expose editable server, credential, and TLS fields.'
);
assertAccountStructure(
    ($googleFormState['ServerURL']['visible'] ?? true) === false
        && ($googleFormState['GoogleStatus']['visible'] ?? false) === true
        && ($googleFormState['GoogleConnect']['visible'] ?? false) === true
        && ($googleFormState['GoogleDisconnect']['visible'] ?? true) === false
        && ($googleFormState['MicrosoftStatus']['visible'] ?? true) === false,
    'Google provider form state must expose only Google OAuth controls while disconnected.'
);
assertAccountStructure(
    ($microsoftFormState['MicrosoftStatus']['visible'] ?? false) === true
        && ($microsoftFormState['MicrosoftConnect']['visible'] ?? true) === false
        && ($microsoftFormState['MicrosoftDisconnect']['visible'] ?? false) === true
        && ($microsoftFormState['GoogleStatus']['visible'] ?? true) === false,
    'Microsoft provider form state must expose the connected Microsoft OAuth controls.'
);
assertAccountStructure(
    ($icsFormState['ServerURL']['caption'] ?? '') === 'iCalendar URL'
        && ($icsFormState['ICalendarAuthenticationMode']['visible'] ?? false) === true
        && ($icsFormState['Username']['visible'] ?? true) === false
        && ($icsFormState['CalendarName']['visible'] ?? false) === true
        && ($icsFormState['ICalendarSubscriptionsPanel']['visible'] ?? false) === true
        && ($icsFormState['ICalendarFilesPanel']['visible'] ?? false) === true
        && ($icsFormState['UpdateSchedule']['caption'] ?? '') === 'Account discovery schedule'
        && ($icsFormState['UpdateInterval']['caption'] ?? '') === 'Account custom interval'
        && ($icsFormState['VerifyTLS']['visible'] ?? false) === true,
    'ICS provider form state must expose the iCalendar-specific fields and schedule labels.'
);
assertAccountStructure(
    ($icsCredentialsFormState['Username']['visible'] ?? false) === true
        && ($icsCredentialsFormState['Password']['visible'] ?? false) === true,
    'ICS provider form state must expose credentials only when requested.'
);
$accountSource = (string) file_get_contents(__DIR__ . '/../Kalender Konto/module.php');
assertAccountStructure(
    substr_count($accountSource, '$this->currentProviderFormState(') === 2,
    'Initial and dynamic provider form rendering must use the same resolved provider state.'
);
assertAccountStructure(
    substr_count($accountSource, '$this->showICalendarCredentials(') === 2,
    'Initial provider rendering and authentication-mode updates must share ICS credential visibility logic.'
);

assertAccountStructure(
    $reflection->hasMethod('ProcessOAuthData') && $reflection->getMethod('ProcessOAuthData')->isProtected(),
    'The native Google/Microsoft OAuth handler must remain protected.'
);
assertAccountStructure(
    is_string(file_get_contents(__DIR__ . '/../Kalender Konto/module.php'))
        && str_contains(
            (string) file_get_contents(__DIR__ . '/../Kalender Konto/module.php'),
            "RegisterAttributeInteger('PendingOAuthProvider', -1)"
        ),
    'OAuth callbacks must retain their pending provider across form changes and restarts.'
);

$normalizeCapabilities = $reflection->getMethod('normalizeCachedCalendarCapabilities');
$normalizeCapabilities->setAccessible(true);
$legacyCalDavCalendars = [[
    'id'           => 'legacy-caldav-calendar',
    'capabilities' => [
        'read'   => true,
        'create' => true,
        'update' => true,
        'delete' => true
    ]
], [
    'id'           => 'legacy-caldav-read-only',
    'capabilities' => [
        'read'   => true,
        'create' => false,
        'update' => false,
        'delete' => false
    ]
]];
foreach ([0, 1] as $calDavProvider) {
    $normalizedCalDavCalendars = $normalizeCapabilities->invoke(null, $legacyCalDavCalendars, $calDavProvider);
    assertAccountStructure(
        ($normalizedCalDavCalendars[0]['capabilities']['createRecurrence'] ?? false) === true
            && ($normalizedCalDavCalendars[0]['capabilities']['updateOccurrence'] ?? false) === true
            && ($normalizedCalDavCalendars[0]['capabilities']['deleteOccurrence'] ?? false) === true
            && ($normalizedCalDavCalendars[0]['capabilities']['updateFollowing'] ?? false) === true
            && ($normalizedCalDavCalendars[0]['capabilities']['updateSeries'] ?? false) === true
            && ($normalizedCalDavCalendars[0]['capabilities']['deleteSeries'] ?? false) === true
            && ($normalizedCalDavCalendars[0]['capabilities']['maxReminders'] ?? 0) === 5
            && ($normalizedCalDavCalendars[1]['capabilities']['maxReminders'] ?? 0) === 5
            && ($normalizedCalDavCalendars[1]['capabilities']['createRecurrence'] ?? true) === false
            && ($normalizedCalDavCalendars[1]['capabilities']['updateOccurrence'] ?? true) === false
            && ($normalizedCalDavCalendars[1]['capabilities']['deleteOccurrence'] ?? true) === false
            && ($normalizedCalDavCalendars[1]['capabilities']['updateFollowing'] ?? true) === false
            && ($normalizedCalDavCalendars[1]['capabilities']['updateSeries'] ?? true) === false
            && ($normalizedCalDavCalendars[1]['capabilities']['deleteSeries'] ?? true) === false,
        'Legacy Apple and CalDAV caches must derive supported recurring occurrence, following and series writes from cached write access.'
    );
}

$legacyGoogleCalendars = [[
    'id'           => 'legacy-google-calendar',
    'accessRole'   => 'writer',
    'capabilities' => [
        'read'   => true,
        'create' => true,
        'update' => true,
        'delete' => true
    ]
], [
    'id'           => 'legacy-google-read-only',
    'accessRole'   => 'reader',
    'capabilities' => [
        'read'   => true,
        'create' => false,
        'update' => false,
        'delete' => false
    ]
]];
$normalizedGoogleCalendars = $normalizeCapabilities->invoke(null, $legacyGoogleCalendars, 2);
assertAccountStructure(
    ($normalizedGoogleCalendars[0]['capabilities']['createRecurrence'] ?? false) === true
        && ($normalizedGoogleCalendars[0]['capabilities']['updateFollowing'] ?? false) === true
        && ($normalizedGoogleCalendars[0]['capabilities']['updateSeries'] ?? false) === true
        && ($normalizedGoogleCalendars[0]['capabilities']['deleteSeries'] ?? false) === true
        && ($normalizedGoogleCalendars[0]['capabilities']['useDefaultReminder'] ?? false) === true
        && ($normalizedGoogleCalendars[0]['capabilities']['createWithDefaultReminder'] ?? false) === true
        && ($normalizedGoogleCalendars[0]['capabilities']['maxReminders'] ?? 0) === 5
        && ($normalizedGoogleCalendars[0]['defaultReminder']['mode'] ?? '') === 'complex'
        && ($normalizedGoogleCalendars[1]['capabilities']['createRecurrence'] ?? true) === false
        && ($normalizedGoogleCalendars[1]['capabilities']['updateFollowing'] ?? true) === false
        && ($normalizedGoogleCalendars[1]['capabilities']['updateSeries'] ?? true) === false
        && ($normalizedGoogleCalendars[1]['capabilities']['deleteSeries'] ?? true) === false
        && ($normalizedGoogleCalendars[1]['capabilities']['createWithDefaultReminder'] ?? true) === false,
    'Legacy Google calendar caches must derive recurring and default-reminder capabilities while protecting unknown default details.'
);

$legacyMicrosoftCalendars = [[
    'id'           => 'legacy-microsoft-calendar',
    'accessRole'   => 'writer',
    'capabilities' => [
        'read'   => true,
        'create' => true,
        'update' => true,
        'delete' => true
    ]
], [
    'id'           => 'legacy-microsoft-read-only',
    'accessRole'   => 'reader',
    'capabilities' => [
        'read'   => true,
        'create' => false,
        'update' => false,
        'delete' => false
    ]
]];
$normalizedMicrosoftCalendars = $normalizeCapabilities->invoke(null, $legacyMicrosoftCalendars, 3);
assertAccountStructure(
    ($normalizedMicrosoftCalendars[0]['capabilities']['createRecurrence'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['updateRecurrence'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['updateOccurrence'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['deleteOccurrence'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['updateFollowing'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['updateSeries'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['deleteSeries'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['createWithDefaultReminder'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['maxReminders'] ?? 0) === 1
        && !isset($normalizedMicrosoftCalendars[0]['capabilities']['useDefaultReminder'])
        && ($normalizedMicrosoftCalendars[1]['capabilities']['createRecurrence'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['updateRecurrence'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['updateOccurrence'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['deleteOccurrence'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['updateFollowing'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['updateSeries'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['deleteSeries'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['createWithDefaultReminder'] ?? true) === false,
    'Legacy Microsoft calendar caches must derive recurrence and creation-only default-reminder support from cached write access.'
);

$gatewaySource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');
assertAccountStructure(
    is_string($gatewaySource)
        && str_contains($gatewaySource, 'use IPSKalender\CalendarEventLookupProviderInterface;')
        && str_contains($gatewaySource, 'use IPSKalender\RecurringCalendarProviderInterface;')
        && str_contains($gatewaySource, "'GetEventForEdit'")
        && str_contains($gatewaySource, 'getEventForEditForChild($request)')
        && str_contains($gatewaySource, 'instanceof CalendarEventLookupProviderInterface')
        && str_contains($gatewaySource, '->getEventForEdit(')
        && str_contains($gatewaySource, 'eventLookupIdentityForChild(')
        && !str_contains($gatewaySource, 'directEventForEditForChild(')
        && !str_contains($gatewaySource, '->getEventByReference(')
        && !str_contains($gatewaySource, '->getEventsForEditByResource(')
        && str_contains($gatewaySource, 'eventEditLookupRange(')
        && str_contains($gatewaySource, "'GetRecurringSeries'")
        && str_contains($gatewaySource, 'getRecurringSeriesForChild($request)')
        && str_contains($gatewaySource, "'GetRecurringFollowing'")
        && str_contains($gatewaySource, 'getRecurringFollowingForChild($request)')
        && str_contains($gatewaySource, 'instanceof RecurringCalendarProviderInterface')
        && str_contains($gatewaySource, '->getRecurringSeries(')
        && str_contains($gatewaySource, '->getRecurringFollowing('),
    'The account child gateway must route direct event lookup through the provider capability while retaining the bounded fallback only for providers without that capability.'
);

$accountSource = file_get_contents(__DIR__ . '/../Kalender Konto/module.php');
$accountFormSource = file_get_contents(__DIR__ . '/../Kalender Konto/form.json');
$accountForm = json_decode((string) $accountFormSource, true, 512, JSON_THROW_ON_ERROR);

assertAccountStructure(
    is_array($accountForm)
        && str_contains((string) $accountFormSource, '/blob/main/PRIVACY.md')
        && !str_contains((string) $accountFormSource, '/blob/legal/PRIVACY.md'),
    'The privacy button must link to the published privacy policy on the main branch.'
);

assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, "RegisterPropertyString('ICalendarFiles', '[]')")
        && str_contains($accountSource, 'new ICalendarFileProvider(')
        && str_contains($accountSource, '$this->iCalendarSources()')
        && is_string($accountFormSource)
        && str_contains($accountFormSource, '"name": "ICalendarFilesPanel"')
        && str_contains($accountFormSource, '"name": "ICalendarFiles"')
        && str_contains($accountFormSource, '"type": "SelectFile"')
        && str_contains($accountFormSource, '"extensions": ".ics"'),
    'ICS/Webcal must support local ICS uploads from the client through a SelectFile-backed list.'
);
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, "RegisterPropertyInteger('ICalendarAuthenticationMode'")
        && str_contains($accountSource, 'ICalendarAuthentication::credentials(')
        && is_string($accountFormSource)
        && str_contains($accountFormSource, '"name": "ICalendarAuthenticationMode"')
        && str_contains($accountFormSource, '"name": "AuthenticationMode"')
        && str_contains($accountFormSource, '"caption": "URL / access key"')
        && str_contains($accountFormSource, '"caption": "Username / password"'),
    'ICS/Webcal must distinguish URL/access-key feeds from username/password authentication.'
);

assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, 'self::GOOGLE_OAUTH_IDENTIFIER, self::MICROSOFT_OAUTH_IDENTIFIER')
        && str_contains($accountSource, '$this->RegisterOAuth($identifier)')
        && str_contains($accountSource, "RegisterAttributeInteger('PendingOAuthInstanceID', 0)")
        && str_contains($accountSource, "RegisterAttributeInteger('PendingOAuthStartedAt', 0)")
        && str_contains($accountSource, 'RegisterMessage(0, IPS_KERNELSTARTED)')
        && str_contains($accountSource, "RegisterTimer('OAuthRegistrationTimer'")
        && str_contains($accountSource, 'IPSKALACC_InitializeOAuth')
        && str_contains($accountSource, 'OAUTH_REGISTRATION_DELAY_MS = 5_000')
        && str_contains($accountSource, 'OAUTH_DISPATCHER_RECHECK_MS = 60_000')
        && str_contains($accountSource, 'IPS_GetKernelRunlevel() === KR_READY')
        && str_contains($accountSource, "SetTimerInterval('OAuthRegistrationTimer', self::OAUTH_DISPATCHER_RECHECK_MS)")
        && str_contains($accountSource, 'private function oauthDispatcherId(): int')
        && str_contains($accountSource, 'IPS_GetInstanceListByModuleID(self::MODULE_ID)')
        && str_contains($accountSource, 'OAUTH_PENDING_TIMEOUT_SECONDS = 900')
        && str_contains($accountSource, "case 'InternalOAuthBegin':")
        && str_contains($accountSource, "case 'InternalOAuthComplete':")
        && str_contains($accountSource, "'InternalOAuthComplete',")
        && str_contains($accountSource, 'private function scheduleOAuthRegistration(): void')
        && preg_match(
            '/public function Create\(\): void[\s\S]*?public function GetConfigurationForm/',
            $accountSource,
            $createMethod
        ) === 1
        && !str_contains($createMethod[0], 'RegisterOAuth(')
        && preg_match(
            '/public function ApplyChanges\(\): void[\s\S]*?public function InitializeOAuth/',
            $accountSource,
            $applyChangesMethod
        ) === 1
        && !str_contains($applyChangesMethod[0], 'registerOAuthHandlers()')
        && str_contains($applyChangesMethod[0], 'scheduleOAuthRegistration()')
        && preg_match(
            '/public function MessageSink\([\s\S]*?public function RequestAction/',
            $accountSource,
            $messageSinkMethod
        ) === 1
        && !str_contains($messageSinkMethod[0], 'registerOAuthHandlers()')
        && str_contains($messageSinkMethod[0], 'scheduleOAuthRegistration()')
        && !str_contains($accountSource, "RegisterPropertyString('GoogleClientID'")
        && !str_contains($accountSource, "RegisterPropertyString('GoogleClientSecret'")
        && !str_contains($accountSource, "RegisterPropertyString('MicrosoftClientID'")
        && !str_contains($accountSource, "RegisterPropertyString('MicrosoftClientSecret'"),
    'Google and Microsoft OAuth registration must be deferred and routed through one deterministic dispatcher.'
);
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, 'foreach ([self::GOOGLE_OAUTH_IDENTIFIER, self::MICROSOFT_OAUTH_IDENTIFIER] as $identifier)')
        && str_contains($accountSource, 'if (!$this->RegisterOAuth($identifier))')
        && str_contains($accountSource, 'catch (Throwable $exception)')
        && str_contains($accountSource, "'OAuthRegistration'"),
    'A temporarily unavailable OAuth control must not abort account or library initialization.'
);

$googleOAuthSource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/GoogleOAuthTrait.php');
$microsoftOAuthSource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/MicrosoftOAuthTrait.php');
$sharedOAuthSource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/SymconOAuthTrait.php');
assertAccountStructure(
    is_string($googleOAuthSource)
        && is_string($microsoftOAuthSource)
        && !str_contains($googleOAuthSource, 'RegisterOAuth(')
        && !str_contains($microsoftOAuthSource, 'RegisterOAuth(')
        && str_contains($googleOAuthSource, 'requestOAuthDispatch(self::PROVIDER_GOOGLE)')
        && str_contains($microsoftOAuthSource, 'requestOAuthDispatch(self::PROVIDER_MICROSOFT)')
        && str_contains($googleOAuthSource, 'processGoogleOAuthData(array $oauthData): void')
        && str_contains($microsoftOAuthSource, 'processMicrosoftOAuthData(array $oauthData): void'),
    'Provider traits must delegate OAuth registration and callback routing to the account dispatcher.'
);
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, "require_once __DIR__ . '/../libs/helper/SymconOAuthHelper.php';")
        && is_string($sharedOAuthSource)
        && str_contains($sharedOAuthSource, 'use Burki24\\SymconModuleHelper\\SymconOAuthClient;')
        && !is_file(__DIR__ . '/../libs/SymconOAuthClient.php'),
    'The account module must use the shared vendored SymconOAuthHelper without retaining a duplicate client.'
);
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, 'new GoogleCalendarOriginPolicy()')
        && str_contains($accountSource, 'new MicrosoftGraphOriginPolicy()')
        && str_contains($accountSource, 'private function createTrustedCloudHttpClient')
        && preg_match('/createTrustedCloudHttpClient\([\s\S]*?new CalendarHttpClient\([\s\S]*?true,/', $accountSource) === 1,
    'Trusted Google/Microsoft cloud clients must always verify TLS and enforce a fixed origin policy.'
);
assertAccountStructure(
    is_string($googleOAuthSource)
        && str_contains($googleOAuthSource, 'new GoogleOAuthOriginPolicy()')
        && is_string($microsoftOAuthSource)
        && is_string($sharedOAuthSource)
        && str_contains($sharedOAuthSource, 'new SymconOAuthOriginPolicy()'),
    'OAuth token and revocation requests must use fixed trusted-origin policies.'
);
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, '$verifyTls = $provider === self::PROVIDER_APPLE')
        && str_contains($accountSource, '? true')
        && str_contains($accountSource, ': $this->ReadPropertyBoolean(\'VerifyTLS\')')
        && str_contains($accountSource, '$this->UpdateFormField(\'VerifyTLS\', \'visible\', $canConfigureTls)'),
    'VerifyTLS must only be user-configurable for custom CalDAV and ICS/Webcal endpoints; iCloud remains verified.'
);
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, '$rawMessage = $this->sanitizeError($exception->getMessage());')
        && str_contains($accountSource, '$this->SendSafeDebug(\'ProviderError\', [')
        && str_contains($accountSource, '\'message\'    => $rawMessage')
        && !str_contains($accountSource, '\'message\'    => $exception->getMessage()'),
    'Provider debug output must only receive sanitized exception messages.'
);

fwrite(STDOUT, "CalendarAccount structure tests passed.\n");
