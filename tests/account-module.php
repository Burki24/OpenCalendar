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
        return new class($this->deleteCalls) implements \IPSKalender\RecurringCalendarProviderInterface {
            /** @param list<array<string, mixed>> $deleteCalls */
            public function __construct(private array &$deleteCalls)
            {
            }

            /** @return array<string, mixed> */
            public function getRecurringSeries(string $calendarReference, string $seriesId): array
            {
                return [
                    'recurrenceType'  => 'master',
                    'seriesId'        => $seriesId,
                    'resourceUrl'     => $calendarReference . '/events/' . $seriesId,
                    'canUpdateSeries' => true
                ];
            }

            /** @return array<string, mixed> */
            public function getRecurringFollowing(
                string $calendarReference,
                string $seriesId,
                string $occurrenceId,
                string $originalStart
            ): array {
                return [
                    'recurrenceType'     => 'occurrence',
                    'seriesId'           => $seriesId,
                    'occurrenceId'       => $occurrenceId,
                    'originalStart'      => $originalStart,
                    'resourceUrl'        => $calendarReference . '/events/' . $occurrenceId,
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
$getRecurringSeriesForChild = new ReflectionMethod(CalendarAccountGatewayRecurrenceProbe::class, 'getRecurringSeriesForChild');
$gatewaySeries = $getRecurringSeriesForChild->invoke($gatewayProbe, [
    'CalendarID' => 'calendar-id',
    'SeriesID'   => 'series-id'
]);
assertAccountStructure(
    is_array($gatewaySeries)
        && ($gatewaySeries['recurrenceType'] ?? '') === 'master'
        && ($gatewaySeries['seriesId'] ?? '') === 'series-id'
        && ($gatewaySeries['canUpdateSeries'] ?? false) === true,
    'A recurring parent event must pass through the account child gateway for complete-series editing.'
);
$getRecurringFollowingForChild = new ReflectionMethod(
    CalendarAccountGatewayRecurrenceProbe::class,
    'getRecurringFollowingForChild'
);
$gatewayFollowing = $getRecurringFollowingForChild->invoke($gatewayProbe, [
    'CalendarID'    => 'calendar-id',
    'SeriesID'      => 'series-id',
    'OccurrenceID'  => 'instance-id',
    'OriginalStart' => '2026-08-12T09:00:00+02:00'
]);
assertAccountStructure(
    is_array($gatewayFollowing)
        && ($gatewayFollowing['recurrenceType'] ?? '') === 'occurrence'
        && ($gatewayFollowing['seriesId'] ?? '') === 'series-id'
        && ($gatewayFollowing['occurrenceId'] ?? '') === 'instance-id'
        && ($gatewayFollowing['canUpdateFollowing'] ?? false) === true,
    'A recurring target occurrence must pass through the account child gateway for this-and-following editing.'
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

$reflection = new ReflectionClass(KalenderKonto::class);
$traits = class_uses(KalenderKonto::class);
foreach ([
    KalenderKontoSymconOAuthTrait::class,
    KalenderKontoGoogleOAuthTrait::class,
    KalenderKontoMicrosoftOAuthTrait::class,
    KalenderKontoICalendarAccountTrait::class,
    KalenderKontoChildGatewayTrait::class
] as $trait) {
    assertAccountStructure(
        isset($traits[$trait]),
        sprintf('KalenderKonto must use %s.', $trait)
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
            && ($normalizedCalDavCalendars[1]['capabilities']['createRecurrence'] ?? true) === false
            && ($normalizedCalDavCalendars[1]['capabilities']['updateOccurrence'] ?? true) === false
            && ($normalizedCalDavCalendars[1]['capabilities']['deleteOccurrence'] ?? true) === false
            && !array_key_exists('updateFollowing', $normalizedCalDavCalendars[0]['capabilities'])
            && !array_key_exists('updateSeries', $normalizedCalDavCalendars[0]['capabilities'])
            && !array_key_exists('deleteSeries', $normalizedCalDavCalendars[0]['capabilities']),
        'Legacy Apple and CalDAV caches must derive supported recurring occurrence writes from cached write access.'
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
        && ($normalizedGoogleCalendars[1]['capabilities']['createRecurrence'] ?? true) === false
        && ($normalizedGoogleCalendars[1]['capabilities']['updateFollowing'] ?? true) === false
        && ($normalizedGoogleCalendars[1]['capabilities']['updateSeries'] ?? true) === false
        && ($normalizedGoogleCalendars[1]['capabilities']['deleteSeries'] ?? true) === false,
    'Legacy Google calendar caches must derive recurring create/update/delete support from cached write access.'
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
        && ($normalizedMicrosoftCalendars[0]['capabilities']['updateOccurrence'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['deleteOccurrence'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['updateFollowing'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['updateSeries'] ?? false) === true
        && ($normalizedMicrosoftCalendars[0]['capabilities']['deleteSeries'] ?? false) === true
        && ($normalizedMicrosoftCalendars[1]['capabilities']['createRecurrence'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['updateOccurrence'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['deleteOccurrence'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['updateFollowing'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['updateSeries'] ?? true) === false
        && ($normalizedMicrosoftCalendars[1]['capabilities']['deleteSeries'] ?? true) === false,
    'Legacy Microsoft calendar caches must derive supported recurrence writes from cached write access.'
);

$gatewaySource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');
assertAccountStructure(
    is_string($gatewaySource)
        && str_contains($gatewaySource, 'use IPSKalender\RecurringCalendarProviderInterface;')
        && str_contains($gatewaySource, "'GetRecurringSeries'")
        && str_contains($gatewaySource, 'getRecurringSeriesForChild($request)')
        && str_contains($gatewaySource, "'GetRecurringFollowing'")
        && str_contains($gatewaySource, 'getRecurringFollowingForChild($request)')
        && str_contains($gatewaySource, 'instanceof RecurringCalendarProviderInterface')
        && str_contains($gatewaySource, '->getRecurringSeries(')
        && str_contains($gatewaySource, '->getRecurringFollowing('),
    'The account child gateway must route recurring parent and following reads only through recurrence-capable providers.'
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
        && str_contains($accountSource, '$this->SendDebug(\'ProviderError\', $rawMessage, 0);')
        && !str_contains($accountSource, '$this->SendDebug(\'ProviderError\', $exception->getMessage(), 0);'),
    'Provider debug output must only receive sanitized exception messages.'
);

fwrite(STDOUT, "KalenderKonto structure tests passed.\n");
