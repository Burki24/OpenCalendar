<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\SymconOAuthClient;
use IPSKalender\CalendarEventTranslation;
use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\CalendarProviderInterface;
use IPSKalender\GoogleCalendarOriginPolicy;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\GoogleOAuthOriginPolicy;
use IPSKalender\ICalendarAuthentication;
use IPSKalender\ICalendarCodec;
use IPSKalender\ICalendarFeedProvider;
use IPSKalender\ICalendarFeedProviderException;
use IPSKalender\ICalendarFileProvider;
use IPSKalender\ICalendarFileProviderException;
use IPSKalender\ICalendarSubscriptionProvider;
use IPSKalender\MicrosoftCalendarProvider;
use IPSKalender\MicrosoftCalendarProviderException;
use IPSKalender\MicrosoftGraphOriginPolicy;
use IPSKalender\SymconOAuthOriginPolicy;
use IPSKalender\SynchronizationSchedule;

require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/GoogleCalendarOriginPolicy.php';
require_once __DIR__ . '/../libs/GoogleOAuthOriginPolicy.php';
require_once __DIR__ . '/../libs/MicrosoftCalendarProvider.php';
require_once __DIR__ . '/../libs/MicrosoftGraphOriginPolicy.php';
require_once __DIR__ . '/../libs/helper/SymconOAuthHelper.php';
require_once __DIR__ . '/../libs/SymconOAuthOriginPolicy.php';
require_once __DIR__ . '/../libs/CalendarEventTranslation.php';
require_once __DIR__ . '/../libs/ICalendarAuthentication.php';
require_once __DIR__ . '/../libs/ICalendarFeedProvider.php';
require_once __DIR__ . '/../libs/ICalendarFileProvider.php';
require_once __DIR__ . '/../libs/ICalendarSubscriptionProvider.php';
require_once __DIR__ . '/../libs/SynchronizationSchedule.php';

final class FakeHttpClient implements CalendarHttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** @var list<CalendarHttpResponse|Throwable> */
    private array $responses;

    /** @param list<CalendarHttpResponse|Throwable> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(string $method, string $url, array $headers = [], string $body = ''): CalendarHttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->responses === []) {
            throw new RuntimeException('No fake response was queued.');
        }
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

function response(int $status, array|string $body = ''): CalendarHttpResponse
{
    return new CalendarHttpResponse(
        $status,
        [],
        is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body,
        'https://example.test'
    );
}

/**
 * Adapts the calendar test transport to the generic shared OAuth transport contract.
 *
 * @return Closure(string,string,array<string,string>,string):array{statusCode:int,body:string}
 */
function oauthTransport(FakeHttpClient $httpClient): Closure
{
    return static function (string $method, string $url, array $headers, string $body) use ($httpClient): array
    {
        $response = $httpClient->request($method, $url, $headers, $body);

        return [
            'statusCode' => $response->statusCode,
            'body'       => $response->body
        ];
    };
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . PHP_EOL
            . 'Expected: ' . var_export($expected, true) . PHP_EOL
            . 'Actual:   ' . var_export($actual, true));
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$calendarClient = new FakeHttpClient([
    response(200, [
        'items'         => [
            [
                'id'              => 'owner@example.com',
                'summary'         => 'Primary',
                'backgroundColor' => '#1a73e8',
                'accessRole'      => 'owner',
                'primary'         => true
            ],
            [
                'id'         => 'availability@example.com',
                'summary'    => 'Availability',
                'accessRole' => 'freeBusyReader'
            ]
        ],
        'nextPageToken' => 'page-2'
    ]),
    response(200, [
        'items' => [[
            'id'              => 'shared@example.com',
            'summaryOverride' => 'Shared calendar',
            'backgroundColor' => '#34a853',
            'accessRole'      => 'reader'
        ]]
    ])
]);
$provider = new GoogleCalendarProvider($calendarClient, 'access-token');
$calendars = $provider->getCalendars();
assertSameValue(2, count($calendars), 'Calendar discovery must paginate and exclude free/busy-only entries.');
assertSameValue('owner@example.com', $calendars[0]['providerId'], 'The primary calendar must be listed first.');
assertSameValue(true, $calendars[0]['writeAccessKnown'], 'Google access roles must provide authoritative write metadata.');
assertSameValue(true, $calendars[0]['capabilities']['create'], 'Owners must have write access.');
assertSameValue(false, $calendars[1]['capabilities']['create'], 'Readers must not have write access.');
assertTrueValue(str_contains($calendarClient->requests[1]['url'], 'pageToken=page-2'), 'The second calendar page must be requested.');

$eventClient = new FakeHttpClient([
    response(200, [
        'timeZone' => 'Europe/Berlin',
        'items'    => [
            [
                'id'       => 'all-day-id',
                'iCalUID'  => 'all-day@example.com',
                'etag'     => '"etag-1"',
                'summary'  => 'Holiday',
                'status'   => 'confirmed',
                'start'    => ['date' => '2026-07-20'],
                'end'      => ['date' => '2026-07-21'],
                'htmlLink' => 'https://calendar.google.com/event?eid=1'
            ],
            [
                'id'               => 'instance-id',
                'iCalUID'          => 'series@example.com',
                'summary'          => 'Meeting',
                'status'           => 'confirmed',
                'recurringEventId' => 'series-id',
                'start'            => ['dateTime' => '2026-07-20T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
                'end'              => ['dateTime' => '2026-07-20T11:00:00+02:00', 'timeZone' => 'Europe/Berlin']
            ],
            [
                'id'     => 'deleted-id',
                'status' => 'cancelled',
                'start'  => ['date' => '2026-07-20']
            ]
        ]
    ])
]);
$provider = new GoogleCalendarProvider($eventClient, 'access-token');
$events = $provider->getEvents(
    'owner@example.com',
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(2, count($events), 'Cancelled events must be excluded.');
assertSameValue(true, $events[0]['allDay'], 'Google date values must map to all-day events.');
assertSameValue('2026-07-21', $events[0]['end'], 'The exclusive Google all-day end date must be retained.');
assertSameValue(true, $events[1]['recurring'], 'Expanded recurring instances must remain marked as recurring.');
assertSameValue('series-id', $events[1]['recurrenceId'], 'The recurring series ID must be retained.');
assertTrueValue(str_contains($eventClient->requests[0]['url'], 'owner%40example.com'), 'Calendar IDs must be URL encoded.');
assertSameValue('Bearer access-token', $eventClient->requests[0]['headers']['Authorization'], 'API requests must use Bearer authorization.');

$writeClient = new FakeHttpClient([
    response(200, ['id' => 'created-id', 'iCalUID' => 'created@example.com', 'etag' => '"new"']),
    response(200, ['id' => 'created-id', 'iCalUID' => 'created@example.com', 'etag' => '"updated"']),
    response(204)
]);
$provider = new GoogleCalendarProvider($writeClient, 'access-token');
$created = $provider->createEvent('owner@example.com', [
    'summary'  => 'Test',
    'allDay'   => false,
    'start'    => '2026-07-20T10:00:00+02:00',
    'end'      => '2026-07-20T11:00:00+02:00',
    'location' => 'Berlin'
]);
assertSameValue('created-id', $created['eventReference'], 'The created Google event ID must be returned.');
assertSameValue('POST', $writeClient->requests[0]['method'], 'Events must be created via POST.');
$createBody = json_decode($writeClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
assertSameValue('Test', $createBody['summary'], 'The event summary must be sent.');
$provider->updateEvent(
    'owner@example.com',
    $created['resourceUrl'],
    '"new"',
    'created@example.com',
    ['summary' => 'Updated']
);
assertSameValue('PATCH', $writeClient->requests[1]['method'], 'Events must be updated without replacing unrelated Google fields.');
assertSameValue('"new"', $writeClient->requests[1]['headers']['If-Match'], 'Updates must use the ETag for conflict detection.');
assertTrueValue(
    $provider->deleteEvent('owner@example.com', $created['resourceUrl'], '"updated"'),
    'Event deletion must return true after HTTP 204.'
);
assertSameValue('DELETE', $writeClient->requests[2]['method'], 'Events must be deleted via DELETE.');

$googleOAuthHttpClient = new FakeHttpClient([
    response(200, [
        'access_token'  => 'google-access-token',
        'refresh_token' => 'google-refresh-token',
        'expires_in'    => 3600,
        'token_type'    => 'Bearer'
    ]),
    response(200, [
        'access_token'  => 'google-refreshed-access-token',
        'refresh_token' => 'google-rotated-refresh-token',
        'expires_in'    => 1800,
        'token_type'    => 'Bearer'
    ])
]);
$googleOAuth = new SymconOAuthClient(
    oauthTransport($googleOAuthHttpClient),
    'opencalendar_google',
    'Google Calendar'
);
$googleAuthorizationUrl = $googleOAuth->getAuthorizationUrl('license@example.com');
$googleAuthorizationQuery = [];
parse_str((string) parse_url($googleAuthorizationUrl, PHP_URL_QUERY), $googleAuthorizationQuery);
assertSameValue(
    'oauth.ipmagic.de',
    parse_url($googleAuthorizationUrl, PHP_URL_HOST),
    'Google authorization must use the Symcon OAuth service.'
);
assertSameValue(
    '/authorize/opencalendar_google',
    parse_url($googleAuthorizationUrl, PHP_URL_PATH),
    'Google authorization must use the registered shared OAuth identifier.'
);
assertSameValue('license@example.com', $googleAuthorizationQuery['username'], 'Symcon OAuth must route Google authorization using the license account.');
assertTrueValue(
    !str_contains($googleAuthorizationUrl, 'client_secret') && !str_contains($googleAuthorizationUrl, 'client_id='),
    'Google client credentials must never be exposed to OpenCalendar users.'
);

$googleTokens = $googleOAuth->exchangeAuthorizationCode('google-code');
assertSameValue('google-refresh-token', $googleTokens['refreshToken'], 'Google authorization must retain the delegated refresh token.');
$googleTokenBody = [];
parse_str($googleOAuthHttpClient->requests[0]['body'], $googleTokenBody);
assertSameValue(['code' => 'google-code'], $googleTokenBody, 'The Google code exchange must delegate client credentials to the Symcon OAuth backend.');

$googleTokens = $googleOAuth->refreshAccessToken('google-refresh-token');
assertSameValue(
    'google-rotated-refresh-token',
    $googleTokens['refreshToken'],
    'Rotating Google refresh tokens must replace the stored token.'
);
$googleRefreshBody = [];
parse_str($googleOAuthHttpClient->requests[1]['body'], $googleRefreshBody);
assertSameValue(['refresh_token' => 'google-refresh-token'], $googleRefreshBody, 'Google token renewal must use only the delegated refresh token.');

$msCalendarClient = new FakeHttpClient([
    response(200, [
        'value'           => [
            [
                'id'                => 'AQMk-primary',
                'name'              => 'Calendar',
                'hexColor'          => '#0078D4',
                'canEdit'           => true,
                'isDefaultCalendar' => true,
                'changeKey'         => 'ck-primary',
                'owner'             => ['name' => 'Max', 'address' => 'max@example.com']
            ],
            [
                'id'      => 'AQMk-readonly',
                'name'    => 'Shared',
                'canEdit' => false,
                'owner'   => ['address' => 'other@example.com']
            ]
        ],
        '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendars?$skiptoken=abc'
    ]),
    response(200, [
        'value' => [[
            'id'      => 'AQMk-secondary',
            'name'    => 'Projects',
            'canEdit' => true,
            'owner'   => ['address' => 'max@example.com']
        ]]
    ])
]);
$msProvider = new MicrosoftCalendarProvider($msCalendarClient, 'ms-access-token');
$msCalendars = $msProvider->getCalendars();
assertSameValue(3, count($msCalendars), 'Microsoft calendar discovery must follow trusted Graph pagination.');
assertSameValue('AQMk-primary', $msCalendars[0]['providerId'], 'The default Microsoft calendar must be listed first.');
assertSameValue('max@example.com', $msCalendars[0]['owner'], 'Microsoft calendar ownership must be retained for account display.');
assertSameValue(true, $msCalendars[0]['writeAccessKnown'], 'Microsoft canEdit must provide authoritative write metadata.');
assertSameValue(true, $msCalendars[0]['capabilities']['create'], 'Editable Microsoft calendars must expose write capabilities.');
assertSameValue(false, $msCalendars[2]['capabilities']['create'], 'Read-only Microsoft calendars must remain read-only.');
assertSameValue(
    'Bearer ms-access-token',
    $msCalendarClient->requests[0]['headers']['Authorization'],
    'Microsoft Graph requests must use Bearer authorization.'
);
assertTrueValue(
    str_contains($msCalendarClient->requests[0]['headers']['Prefer'] ?? '', 'IdType="ImmutableId"'),
    'Microsoft Graph requests must opt in to immutable Outlook IDs.'
);
assertSameValue(
    'https://graph.microsoft.com/v1.0/me/calendars?$skiptoken=abc',
    $msCalendarClient->requests[1]['url'],
    'Microsoft Graph pagination must retain the trusted nextLink exactly.'
);

$msUntrustedPageClient = new FakeHttpClient([
    response(200, [
        'value'           => [],
        '@odata.nextLink' => 'https://evil.example/steal-token'
    ])
]);
try {
    (new MicrosoftCalendarProvider($msUntrustedPageClient, 'ms-access-token'))->getCalendars();
    throw new RuntimeException('An untrusted Microsoft Graph nextLink was accepted.');
} catch (MicrosoftCalendarProviderException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'untrusted URL'),
        'Untrusted Microsoft Graph pagination URLs must be rejected before the next request.'
    );
}

$msEventClient = new FakeHttpClient([
    response(200, [
        'value' => [
            [
                'id'          => 'all-day-id',
                'iCalUId'     => 'all-day@example.com',
                '@odata.etag' => 'W/"etag-1"',
                'subject'     => 'Holiday',
                'isAllDay'    => true,
                'start'       => ['dateTime' => '2026-07-20T00:00:00.0000000', 'timeZone' => 'UTC'],
                'end'         => ['dateTime' => '2026-07-21T00:00:00.0000000', 'timeZone' => 'UTC'],
                'type'        => 'singleInstance'
            ],
            [
                'id'              => 'instance/id+1',
                'iCalUId'         => 'series@example.com',
                '@odata.etag'     => 'W/"etag-2"',
                'subject'         => 'Teams meeting',
                'body'            => ['contentType' => 'text', 'content' => 'Agenda'],
                'location'        => ['displayName' => 'Berlin'],
                'start'           => ['dateTime' => '2026-07-20T10:00:00.1234567', 'timeZone' => 'UTC'],
                'end'             => ['dateTime' => '2026-07-20T11:00:00.1234567', 'timeZone' => 'UTC'],
                'type'            => 'occurrence',
                'seriesMasterId'  => 'series-master',
                'isOnlineMeeting' => true,
                'webLink'         => 'https://outlook.office.com/calendar/item/1'
            ],
            [
                'id'          => 'cancelled-id',
                'subject'     => 'Cancelled',
                'isCancelled' => true,
                'start'       => ['dateTime' => '2026-07-20T12:00:00', 'timeZone' => 'UTC']
            ]
        ]
    ])
]);
$msProvider = new MicrosoftCalendarProvider($msEventClient, 'ms-access-token');
$msEvents = $msProvider->getEvents(
    'AQMk-primary',
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(2, count($msEvents), 'Cancelled Microsoft events must be excluded.');
assertSameValue(true, $msEvents[0]['allDay'], 'Microsoft all-day events must retain their exclusive end date.');
assertSameValue('2026-07-21', $msEvents[0]['end'], 'Microsoft all-day end dates must remain exclusive.');
assertSameValue(true, $msEvents[1]['recurring'], 'Microsoft occurrences must remain marked as recurring.');
assertSameValue('series-master', $msEvents[1]['recurrenceId'], 'Microsoft series master IDs must be retained.');
assertSameValue(true, $msEvents[1]['onlineMeeting'], 'Microsoft online-meeting state must be exposed to the calendar view.');
assertTrueValue(
    str_contains($msEventClient->requests[0]['url'], 'AQMk-primary/calendarView?'),
    'Microsoft events must be read through calendarView for expanded occurrences.'
);
assertTrueValue(
    str_contains($msEventClient->requests[0]['headers']['Prefer'] ?? '', 'outlook.body-content-type="text"')
        && str_contains($msEventClient->requests[0]['headers']['Prefer'] ?? '', 'outlook.timezone="UTC"')
        && str_contains($msEventClient->requests[0]['headers']['Prefer'] ?? '', 'IdType="ImmutableId"'),
    'Microsoft event reads must request text bodies, UTC event times and immutable IDs.'
);

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('Europe/Berlin');
try {
    $msWindowsTimezoneClient = new FakeHttpClient([
        response(200, [
            'value' => [[
                'id'       => 'berlin-timezone-id',
                'subject'  => 'Berlin meeting',
                'isAllDay' => false,
                'start'    => [
                    'dateTime' => '2026-07-20T18:00:00.0000000',
                    'timeZone' => 'W. Europe Standard Time'
                ],
                'end'      => [
                    'dateTime' => '2026-07-20T19:00:00.0000000',
                    'timeZone' => 'W. Europe Standard Time'
                ],
                'type'     => 'singleInstance'
            ]]
        ])
    ]);
    $msWindowsTimezoneEvents = (new MicrosoftCalendarProvider(
        $msWindowsTimezoneClient,
        'ms-access-token'
    ))->getEvents(
        'AQMk-primary',
        new DateTimeImmutable('2026-07-20T00:00:00+02:00'),
        new DateTimeImmutable('2026-07-21T00:00:00+02:00')
    );
    assertSameValue(
        '2026-07-20T18:00:00+02:00',
        $msWindowsTimezoneEvents[0]['start'],
        'Microsoft Windows time zones must be normalized without applying the local UTC offset twice.'
    );
    assertSameValue(
        (new DateTimeImmutable('2026-07-20T18:00:00+02:00'))->getTimestamp(),
        $msWindowsTimezoneEvents[0]['startTimestamp'],
        'Microsoft Windows time-zone normalization must preserve the actual event instant.'
    );
} finally {
    date_default_timezone_set($previousTimezone);
}

$msWriteClient = new FakeHttpClient([
    response(201, [
        'id'          => 'created-id',
        'iCalUId'     => 'created@example.com',
        '@odata.etag' => 'W/"created"'
    ]),
    response(200, [
        'id'          => 'created-id',
        'iCalUId'     => 'created@example.com',
        '@odata.etag' => 'W/"updated"'
    ]),
    response(200, ['isOnlineMeeting' => false]),
    response(200, [
        'id'          => 'created-id',
        'iCalUId'     => 'created@example.com',
        '@odata.etag' => 'W/"description-updated"'
    ]),
    response(204)
]);
$msProvider = new MicrosoftCalendarProvider($msWriteClient, 'ms-access-token');
$msCreated = $msProvider->createEvent('AQMk-primary', [
    'summary'     => 'Test',
    'description' => 'Description',
    'location'    => 'Berlin',
    'allDay'      => false,
    'start'       => '2026-07-20T10:00:00+02:00',
    'end'         => '2026-07-20T11:00:00+02:00'
]);
assertSameValue('created-id', $msCreated['eventReference'], 'The created Microsoft event ID must be returned.');
assertSameValue('POST', $msWriteClient->requests[0]['method'], 'Microsoft events must be created via POST.');
$msCreateBody = json_decode($msWriteClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
assertSameValue('Test', $msCreateBody['subject'], 'Microsoft event subjects must be sent.');
assertSameValue('text', $msCreateBody['body']['contentType'], 'Microsoft event descriptions must be sent as text.');
assertSameValue('UTC', $msCreateBody['start']['timeZone'], 'Microsoft event writes must use unambiguous UTC times.');

$msProvider->updateEvent(
    'AQMk-primary',
    $msCreated['resourceUrl'],
    'W/"created"',
    'created@example.com',
    ['summary' => 'Updated']
);
assertSameValue('PATCH', $msWriteClient->requests[1]['method'], 'Microsoft events must be updated via PATCH.');
assertSameValue('W/"created"', $msWriteClient->requests[1]['headers']['If-Match'], 'Microsoft updates must use ETags.');

$msProvider->updateEvent(
    'AQMk-primary',
    $msCreated['resourceUrl'],
    'W/"updated"',
    'created@example.com',
    ['description' => 'Updated description']
);
assertSameValue('GET', $msWriteClient->requests[2]['method'], 'Description changes must first check for protected online-meeting content.');
assertTrueValue(
    str_contains($msWriteClient->requests[2]['url'], '$select=isOnlineMeeting'),
    'The online-meeting safety check should fetch only the required metadata.'
);
assertSameValue('PATCH', $msWriteClient->requests[3]['method'], 'Normal Microsoft event descriptions may be updated after the safety check.');
assertTrueValue(
    $msProvider->deleteEvent('AQMk-primary', $msCreated['resourceUrl'], 'W/"description-updated"'),
    'Microsoft event deletion must return true after HTTP 204.'
);
assertSameValue('DELETE', $msWriteClient->requests[4]['method'], 'Microsoft events must be deleted via DELETE.');

$msOnlineMeetingClient = new FakeHttpClient([
    response(200, ['isOnlineMeeting' => true])
]);
try {
    (new MicrosoftCalendarProvider($msOnlineMeetingClient, 'ms-access-token'))->updateEvent(
        'AQMk-primary',
        'online-event-id',
        'W/"online"',
        'online@example.com',
        ['description' => 'Do not overwrite Teams meeting data']
    );
    throw new RuntimeException('A Microsoft online-meeting description was overwritten.');
} catch (MicrosoftCalendarProviderException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'cannot be changed safely'),
        'Microsoft online-meeting descriptions must be protected from destructive updates.'
    );
    assertSameValue(1, count($msOnlineMeetingClient->requests), 'Protected online-meeting descriptions must not trigger PATCH.');
}

$msOAuthHttpClient = new FakeHttpClient([
    response(200, [
        'access_token'  => 'ms-access-token',
        'refresh_token' => 'ms-refresh-token',
        'expires_in'    => 3600,
        'token_type'    => 'Bearer'
    ]),
    response(200, [
        'access_token'  => 'ms-refreshed-access-token',
        'refresh_token' => 'ms-rotated-refresh-token',
        'expires_in'    => 1800,
        'token_type'    => 'Bearer'
    ])
]);
$msOAuth = new SymconOAuthClient(
    oauthTransport($msOAuthHttpClient),
    'opencalendar_microsoft',
    'Microsoft 365'
);
$msAuthorizationUrl = $msOAuth->getAuthorizationUrl('license@example.com');
$msAuthorizationQuery = [];
parse_str((string) parse_url($msAuthorizationUrl, PHP_URL_QUERY), $msAuthorizationQuery);
assertSameValue('oauth.ipmagic.de', parse_url($msAuthorizationUrl, PHP_URL_HOST), 'Microsoft authorization must use the Symcon OAuth service.');
assertSameValue('/authorize/opencalendar_microsoft', parse_url($msAuthorizationUrl, PHP_URL_PATH), 'Microsoft authorization must use the registered shared OAuth identifier.');
assertSameValue('license@example.com', $msAuthorizationQuery['username'], 'Symcon OAuth must route authorization using the license account.');
assertTrueValue(
    !str_contains($msAuthorizationUrl, 'client_secret') && !str_contains($msAuthorizationUrl, 'client_id='),
    'Microsoft client credentials must never be exposed to OpenCalendar users.'
);
$msTokens = $msOAuth->exchangeAuthorizationCode('ms-code');
assertSameValue('ms-refresh-token', $msTokens['refreshToken'], 'Microsoft authorization must store the delegated refresh token.');
$msTokenBody = [];
parse_str($msOAuthHttpClient->requests[0]['body'], $msTokenBody);
assertSameValue(['code' => 'ms-code'], $msTokenBody, 'The Microsoft code exchange must delegate client credentials to the Symcon OAuth backend.');
$msTokens = $msOAuth->refreshAccessToken('ms-refresh-token');
assertSameValue('ms-rotated-refresh-token', $msTokens['refreshToken'], 'Rotating Microsoft refresh tokens must replace the stored token.');
$msRefreshBody = [];
parse_str($msOAuthHttpClient->requests[1]['body'], $msRefreshBody);
assertSameValue(['refresh_token' => 'ms-refresh-token'], $msRefreshBody, 'Microsoft token renewal must use only the delegated refresh token.');

$googleCalendarOriginPolicy = new GoogleCalendarOriginPolicy();
assertTrueValue($googleCalendarOriginPolicy->isAllowedUrl('https://www.googleapis.com/calendar/v3/users/me/calendarList'), 'The Google Calendar API origin must be trusted.');
assertTrueValue(!$googleCalendarOriginPolicy->isAllowedUrl('https://www.googleapis.com.evil.example/calendar/v3'), 'Lookalike Google Calendar API hosts must be rejected.');
assertTrueValue(!$googleCalendarOriginPolicy->isAllowedUrl('http://www.googleapis.com/calendar/v3'), 'Google Calendar API requests must never downgrade to HTTP.');
assertTrueValue(!$googleCalendarOriginPolicy->isAllowedUrl('https://www.googleapis.com:444/calendar/v3'), 'Unexpected Google Calendar API ports must be rejected.');

$googleOAuthOriginPolicy = new GoogleOAuthOriginPolicy();
assertTrueValue($googleOAuthOriginPolicy->isAllowedUrl('https://oauth2.googleapis.com/token'), 'The Google OAuth origin must be trusted.');
assertTrueValue($googleOAuthOriginPolicy->isAllowedUrl('https://oauth2.googleapis.com/revoke'), 'The Google OAuth revocation endpoint must be trusted.');
assertTrueValue(!$googleOAuthOriginPolicy->isAllowedUrl('https://oauth2.googleapis.com.evil.example/token'), 'Lookalike Google OAuth hosts must be rejected.');
assertTrueValue(!$googleOAuthOriginPolicy->isAllowedUrl('http://oauth2.googleapis.com/token'), 'Google OAuth requests must never downgrade to HTTP.');

$msOriginPolicy = new MicrosoftGraphOriginPolicy();
assertTrueValue($msOriginPolicy->isAllowedUrl('https://graph.microsoft.com/v1.0/me/calendars'), 'The Microsoft Graph origin must be trusted.');
assertTrueValue(!$msOriginPolicy->isAllowedUrl('https://graph.microsoft.com.evil.example/v1.0/me'), 'Lookalike Microsoft Graph hosts must be rejected.');
assertTrueValue(!$msOriginPolicy->isAllowedUrl('http://graph.microsoft.com/v1.0/me'), 'Microsoft Graph must never downgrade to HTTP.');
assertTrueValue(!$msOriginPolicy->isAllowedUrl('https://graph.microsoft.com:444/v1.0/me'), 'Unexpected Microsoft Graph ports must be rejected.');

$symconOAuthOriginPolicy = new SymconOAuthOriginPolicy();
assertTrueValue($symconOAuthOriginPolicy->isAllowedUrl('https://oauth.ipmagic.de/access_token/opencalendar_microsoft'), 'The Symcon OAuth origin must be trusted.');
assertTrueValue(!$symconOAuthOriginPolicy->isAllowedUrl('https://oauth.ipmagic.de.evil.example/access_token'), 'Lookalike Symcon OAuth hosts must be rejected.');

$icalFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "X-WR-CALNAME:Google Privat\r\n"
    . "X-APPLE-CALENDAR-COLOR:#34AADCFF\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:inside@example.com\r\n"
    . "DTSTART:20260720T080000Z\r\n"
    . "DTEND:20260720T090000Z\r\n"
    . "SUMMARY:Included event\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:outside@example.com\r\n"
    . "DTSTART:20260820T080000Z\r\n"
    . "DTEND:20260820T090000Z\r\n"
    . "SUMMARY:Excluded event\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$feedClient = new FakeHttpClient([
    new CalendarHttpResponse(200, ['etag' => '"feed-1"'], $icalFeed, 'https://calendar.example/private.ics'),
    new CalendarHttpResponse(200, ['etag' => '"feed-1"'], $icalFeed, 'https://calendar.example/private.ics')
]);
$urlKeyCredentials = ICalendarAuthentication::credentials(
    ICalendarAuthentication::URL_ACCESS_KEY,
    'must-not-be-sent',
    'must-not-be-sent'
);
assertSameValue('', $urlKeyCredentials['username'], 'URL/access-key ICS feeds must never send an HTTP username.');
assertSameValue('', $urlKeyCredentials['password'], 'URL/access-key ICS feeds must never send an HTTP password.');

$automaticIncompleteCredentials = ICalendarAuthentication::credentials(
    ICalendarAuthentication::AUTOMATIC,
    'left-over-user',
    ''
);
assertSameValue('', $automaticIncompleteCredentials['username'], 'Automatic ICS authentication must not send incomplete credentials.');
assertSameValue('', $automaticIncompleteCredentials['password'], 'Automatic ICS authentication must not send incomplete credentials.');

$automaticCredentials = ICalendarAuthentication::credentials(
    ICalendarAuthentication::AUTOMATIC,
    'calendar-user',
    'calendar-password'
);
assertSameValue('calendar-user', $automaticCredentials['username'], 'Automatic ICS authentication must retain complete credentials.');
assertSameValue('calendar-password', $automaticCredentials['password'], 'Automatic ICS authentication must retain complete credentials.');

$explicitCredentials = ICalendarAuthentication::credentials(
    ICalendarAuthentication::USERNAME_PASSWORD,
    'calendar-user',
    'calendar-password'
);
assertSameValue('calendar-user', $explicitCredentials['username'], 'Explicit username/password mode must retain the username.');
assertSameValue('calendar-password', $explicitCredentials['password'], 'Explicit username/password mode must retain the password.');

$diveraIcs = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:DIVERA247//DIVERA GmbH//Terminkalender//DE
X-WR-CALNAME:DIVERA247
X-WR-TIMEZONE:Europe/Berlin
BEGIN:VEVENT
UID:2225701
DTSTART;TZID=Europe/Berlin:20260809T170000
DTEND;TZID=Europe/Berlin:20260809T220000
SUMMARY:DIVERA Test
END:VEVENT
END:VCALENDAR
ICS;
$diveraEvents = ICalendarCodec::parseEvents($diveraIcs, 'urn:test:divera', '');
assertSameValue(1, count($diveraEvents), 'DIVERA247-style ICS data must be parsed as a normal iCalendar feed.');
assertSameValue('2026-08-09T17:00:00+02:00', $diveraEvents[0]['start'], 'DIVERA247 TZID values must retain Europe/Berlin local time.');
assertSameValue('2026-08-09T22:00:00+02:00', $diveraEvents[0]['end'], 'DIVERA247 TZID end values must retain Europe/Berlin local time.');

$provider = new ICalendarFeedProvider($feedClient, 'webcal://calendar.example/private.ics');
$feedCalendars = $provider->getCalendars();
assertSameValue('Google Privat', $feedCalendars[0]['name'], 'The feed calendar name must be read from X-WR-CALNAME.');
assertSameValue('#34AADC', $feedCalendars[0]['color'], 'Eight-digit feed colors must be normalized.');
assertSameValue(true, $feedCalendars[0]['writeAccessKnown'], 'iCalendar subscriptions must expose authoritative read-only metadata.');
assertSameValue(false, $feedCalendars[0]['capabilities']['create'], 'iCalendar subscriptions must be read-only.');
assertSameValue('', $feedCalendars[0]['url'], 'Secret feed URLs must not be copied into child instance properties.');
$feedEvents = $provider->getEvents(
    $feedCalendars[0]['reference'],
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(1, count($feedEvents), 'Feed events outside the requested range must be excluded.');
assertSameValue('Included event', $feedEvents[0]['summary'], 'The event inside the range must be returned.');
assertTrueValue(
    !str_contains($feedEvents[0]['resourceUrl'], 'private.ics'),
    'Secret feed URLs must not be copied into event data.'
);
assertSameValue('https://calendar.example/private.ics', $feedClient->requests[0]['url'], 'Webcal URLs must be fetched over HTTPS.');
try {
    $provider->createEvent($feedCalendars[0]['reference'], ['summary' => 'Not allowed']);
    throw new RuntimeException('The read-only feed unexpectedly accepted an event.');
} catch (ICalendarFeedProviderException $exception) {
    assertTrueValue(str_contains($exception->getMessage(), 'read-only'), 'Write attempts must explain the read-only limitation.');
}

$persistentFeedCache = [];
$conditionalClient = new FakeHttpClient([
    new CalendarHttpResponse(
        200,
        [
            'etag'          => '"feed-cache-1"',
            'last-modified' => 'Fri, 24 Jul 2026 07:00:00 GMT'
        ],
        $icalFeed,
        'https://calendar.example/cached.ics'
    )
]);
$conditionalProvider = new ICalendarFeedProvider(
    $conditionalClient,
    'https://calendar.example/cached.ics',
    '',
    [],
    static function (array $cacheState) use (&$persistentFeedCache): void
    {
        $persistentFeedCache = $cacheState;
    }
);
$conditionalProvider->getCalendars();
assertSameValue('"feed-cache-1"', $persistentFeedCache['etag'], 'The feed ETag must be cached.');
assertSameValue(
    'Fri, 24 Jul 2026 07:00:00 GMT',
    $persistentFeedCache['lastModified'],
    'The Last-Modified validator must be cached.'
);
assertTrueValue($persistentFeedCache['lastDownload'] > 0, 'The successful download time must be cached.');
$initialChangeTimestamp = $persistentFeedCache['lastChange'];

$notModifiedClient = new FakeHttpClient([
    new CalendarHttpResponse(304, [], '', 'https://calendar.example/cached.ics')
]);
$notModifiedProvider = new ICalendarFeedProvider(
    $notModifiedClient,
    'https://calendar.example/cached.ics',
    '',
    $persistentFeedCache,
    static function (array $cacheState) use (&$persistentFeedCache): void
    {
        $persistentFeedCache = $cacheState;
    }
);
$notModifiedEvents = $notModifiedProvider->getEvents(
    'https://calendar.example/cached.ics',
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(1, count($notModifiedEvents), 'HTTP 304 must reuse the cached feed body.');
assertSameValue(
    '"feed-cache-1"',
    $notModifiedClient->requests[0]['headers']['If-None-Match'] ?? '',
    'A cached ETag must be sent with the next request.'
);
assertSameValue(
    'Fri, 24 Jul 2026 07:00:00 GMT',
    $notModifiedClient->requests[0]['headers']['If-Modified-Since'] ?? '',
    'A cached Last-Modified value must be sent with the next request.'
);
assertSameValue(
    $initialChangeTimestamp,
    $persistentFeedCache['lastChange'],
    'HTTP 304 must not change the last content change timestamp.'
);
assertSameValue(false, $persistentFeedCache['stale'], 'HTTP 304 is a successful cache validation.');

$invalidRefreshClient = new FakeHttpClient([
    new CalendarHttpResponse(200, [], '<html>Temporary error</html>', 'https://calendar.example/cached.ics')
]);
$invalidRefreshProvider = new ICalendarFeedProvider(
    $invalidRefreshClient,
    'https://calendar.example/cached.ics',
    '',
    $persistentFeedCache,
    static function (array $cacheState) use (&$persistentFeedCache): void
    {
        $persistentFeedCache = $cacheState;
    }
);
$fallbackEvents = $invalidRefreshProvider->getEvents(
    'https://calendar.example/cached.ics',
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(1, count($fallbackEvents), 'An invalid replacement must not overwrite the last valid feed.');
assertSameValue(true, $persistentFeedCache['stale'], 'Fallback data must be marked as stale.');
assertTrueValue(
    str_contains($persistentFeedCache['lastError'], 'not a valid iCalendar feed'),
    'The cache must retain the reason for using stale data.'
);

$temporaryFailureClient = new FakeHttpClient([
    new RuntimeException('Temporary network outage'),
    new RuntimeException('Temporary network outage')
]);
$temporaryFailureProvider = new ICalendarFeedProvider(
    $temporaryFailureClient,
    'https://calendar.example/cached.ics',
    '',
    $persistentFeedCache
);
assertSameValue(
    1,
    count($temporaryFailureProvider->getEvents(
        'https://calendar.example/cached.ics',
        new DateTimeImmutable('2026-07-19T00:00:00Z'),
        new DateTimeImmutable('2026-07-22T00:00:00Z')
    )),
    'A temporary transport failure must use the last valid feed.'
);
try {
    $temporaryFailureProvider->testConnection();
    throw new RuntimeException('The connection test unexpectedly hid a transport failure behind cached data.');
} catch (ICalendarFeedProviderException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'Temporary network outage'),
        'Connection tests must report current transport failures.'
    );
}

$translationInput = [
    ['summary' => 'New Moon'],
    ['summary' => 'First quarter 11:06am'],
    ['summary' => 'Full Moon 1:30pm'],
    ['summary' => 'Third Quarter 12:05am'],
    ['summary' => 'Day 205 of 2026'],
    ['summary' => 'Team meeting']
];
assertSameValue(
    $translationInput,
    CalendarEventTranslation::translateEvents($translationInput, CalendarEventTranslation::NONE),
    'The disabled translation profile must leave all event data unchanged.'
);
$translatedEvents = CalendarEventTranslation::translateEvents(
    $translationInput,
    CalendarEventTranslation::GOOGLE_PUBLIC_CALENDARS_GERMAN
);
assertSameValue('Neumond', $translatedEvents[0]['summary'], 'New Moon must be translated.');
assertSameValue('Erstes Viertel 11:06 Uhr', $translatedEvents[1]['summary'], 'AM times must use German notation.');
assertSameValue('Vollmond 13:30 Uhr', $translatedEvents[2]['summary'], 'PM times must use 24-hour notation.');
assertSameValue('Letztes Viertel 00:05 Uhr', $translatedEvents[3]['summary'], 'Third-quarter midnight times must be converted correctly.');
assertSameValue('Tag 205 von 2026', $translatedEvents[4]['summary'], 'Day-of-year titles must be translated.');
assertSameValue('Full Moon 1:30pm', $translatedEvents[2]['originalSummary'], 'Translated events must retain their original title.');
assertSameValue('Team meeting', $translatedEvents[5]['summary'], 'Unrecognized titles must remain unchanged.');
assertTrueValue(
    !isset($translatedEvents[5]['originalSummary']),
    'Unchanged events must not receive an original title field.'
);

$localFileProvider = new ICalendarFileProvider(
    base64_encode($icalFeed),
    'Imported calendar',
    'local-calendar-test'
);
$localFileCalendars = $localFileProvider->getCalendars();
assertSameValue(1, count($localFileCalendars), 'A local ICS file must expose exactly one calendar.');
assertSameValue('Imported calendar', $localFileCalendars[0]['name'], 'The configured local file name must override X-WR-CALNAME.');
assertSameValue(false, $localFileCalendars[0]['capabilities']['create'], 'Local ICS files must be read-only.');
assertTrueValue(
    str_starts_with($localFileCalendars[0]['reference'], 'urn:ips-kalender:ics-file:'),
    'Local ICS files must use an internal reference instead of a server path.'
);
$localFileEvents = $localFileProvider->getEvents(
    $localFileCalendars[0]['reference'],
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(1, count($localFileEvents), 'A local ICS file must return events from the requested range.');
try {
    new ICalendarFileProvider('not-base64', 'Broken file', 'broken-file');
    throw new RuntimeException('Invalid Base64 file content was unexpectedly accepted.');
} catch (ICalendarFileProviderException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'decoded'),
        'Invalid Base64 file content must produce an actionable validation error.'
    );
}
try {
    new ICalendarFileProvider(base64_encode('not an iCalendar file'), 'Broken file', 'broken-calendar');
    throw new RuntimeException('Invalid local iCalendar content was unexpectedly accepted.');
} catch (ICalendarFileProviderException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'valid iCalendar'),
        'Invalid local iCalendar content must produce an actionable validation error.'
    );
}

$secondIcalFeed = str_replace(
    ['Google Privat', '#34AADCFF', 'inside@example.com', 'Included event'],
    ['Moon phases', '#6D3A38FF', 'moon@example.com', 'First quarter 11:06am'],
    $icalFeed
);
$subscriptionFactoryCalls = [];
$subscriptionProvider = new ICalendarSubscriptionProvider(
    [
        [
            'url'            => 'https://calendar.example/private.ics',
            'name'           => 'Private',
            'username'       => '',
            'password'       => '',
            'color'          => '#112233',
            'updateSchedule' => SynchronizationSchedule::HOURLY,
            'updateInterval' => 15
        ],
        [
            'url'                => 'https://calendar.example/waste.ics',
            'name'               => 'Waste',
            'username'           => 'feed-user',
            'password'           => 'feed-password',
            'color'              => '',
            'translationProfile' => CalendarEventTranslation::GOOGLE_PUBLIC_CALENDARS_GERMAN,
            'updateSchedule'     => SynchronizationSchedule::WEEKLY,
            'updateInterval'     => 15
        ]
    ],
    static function (array $subscription) use (
        &$subscriptionFactoryCalls,
        $icalFeed,
        $secondIcalFeed
    ): ICalendarFeedProvider {
        $subscriptionFactoryCalls[] = $subscription;
        $body = str_contains((string) $subscription['url'], 'waste.ics') ? $secondIcalFeed : $icalFeed;

        return new ICalendarFeedProvider(
            new FakeHttpClient([
                new CalendarHttpResponse(200, [], $body, (string) $subscription['url'])
            ]),
            (string) $subscription['url'],
            (string) $subscription['name']
        );
    }
);
$subscriptionCalendars = $subscriptionProvider->getCalendars();
assertSameValue(2, count($subscriptionCalendars), 'All active iCalendar subscriptions must be exposed as calendars.');
assertSameValue('Private', $subscriptionCalendars[0]['name'], 'A configured subscription name must override the feed name.');
assertSameValue('#112233', $subscriptionCalendars[0]['color'], 'A configured subscription color must override the feed color.');
assertSameValue(
    SynchronizationSchedule::WEEKLY,
    $subscriptionCalendars[1]['updateSchedule'],
    'The subscription schedule must be passed to the calendar configurator.'
);
assertTrueValue(
    !str_contains($subscriptionCalendars[1]['reference'], 'waste.ics'),
    'Subscription references must not expose secret feed URLs.'
);
$subscriptionEvents = $subscriptionProvider->getEvents(
    $subscriptionCalendars[1]['reference'],
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(1, count($subscriptionEvents), 'The selected subscription must return its own events.');
assertSameValue(
    'Erstes Viertel 11:06 Uhr',
    $subscriptionEvents[0]['summary'],
    'Calendar references must be routed through the selected title translation profile.'
);
assertSameValue(
    'First quarter 11:06am',
    $subscriptionEvents[0]['originalSummary'],
    'Translated subscription events must preserve their original title.'
);
assertSameValue(
    'feed-user',
    $subscriptionFactoryCalls[2]['username'],
    'Per-subscription credentials must be passed only to the selected feed provider.'
);
$subscriptionConnection = $subscriptionProvider->testConnection();
assertSameValue(2, $subscriptionConnection['calendarCount'], 'A connection test must include every subscription.');
assertSameValue(4, $subscriptionConnection['eventCount'], 'A connection test must total all feed events.');
try {
    new ICalendarSubscriptionProvider(
        [
            ['url' => 'https://calendar.example/duplicate.ics'],
            ['url' => 'https://calendar.example/duplicate.ics']
        ],
        static fn (array $subscription): ICalendarFeedProvider => new ICalendarFeedProvider(
            new FakeHttpClient([]),
            (string) $subscription['url']
        )
    );
    throw new RuntimeException('Duplicate iCalendar subscriptions were unexpectedly accepted.');
} catch (InvalidArgumentException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'more than once'),
        'Duplicate subscription URLs must produce an actionable validation error.'
    );
}
try {
    new ICalendarSubscriptionProvider(
        [[
            'url'                => 'https://calendar.example/invalid-translation.ics',
            'translationProfile' => 999
        ]],
        static fn (array $subscription): ICalendarFeedProvider => new ICalendarFeedProvider(
            new FakeHttpClient([]),
            (string) $subscription['url']
        )
    );
    throw new RuntimeException('An invalid title translation profile was unexpectedly accepted.');
} catch (InvalidArgumentException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'translation profile'),
        'Invalid title translation profiles must produce an actionable validation error.'
    );
}

$mixedSourceProvider = new ICalendarSubscriptionProvider(
    [
        [
            'url'            => 'https://calendar.example/mixed.ics',
            'name'           => 'Online',
            'updateSchedule' => SynchronizationSchedule::DAILY,
            'updateInterval' => 15
        ],
        [
            'sourceType'     => 'file',
            'fileData'       => base64_encode($secondIcalFeed),
            'name'           => 'Local file',
            'updateSchedule' => SynchronizationSchedule::DAILY,
            'updateInterval' => 15
        ]
    ],
    static function (array $source) use ($icalFeed): CalendarProviderInterface
    {
        if (($source['sourceType'] ?? 'url') === 'file') {
            return new ICalendarFileProvider(
                (string) $source['fileData'],
                (string) $source['name'],
                (string) $source['id']
            );
        }

        return new ICalendarFeedProvider(
            new FakeHttpClient([
                new CalendarHttpResponse(200, [], $icalFeed, (string) $source['url'])
            ]),
            (string) $source['url'],
            (string) $source['name']
        );
    }
);
$mixedCalendars = $mixedSourceProvider->getCalendars();
assertSameValue(2, count($mixedCalendars), 'Online subscriptions and local ICS files must coexist in one account.');
assertSameValue('Local file', $mixedCalendars[1]['name'], 'The local source must be exposed with its configured name.');
$mixedLocalEvents = $mixedSourceProvider->getEvents(
    $mixedCalendars[1]['reference'],
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(1, count($mixedLocalEvents), 'The composite provider must route local file calendar references correctly.');

$replacementSourceProvider = new ICalendarSubscriptionProvider(
    [[
        'sourceType'     => 'file',
        'fileData'       => base64_encode(str_replace('Included event', 'Updated event', $icalFeed)),
        'name'           => 'Local file',
        'updateSchedule' => SynchronizationSchedule::DAILY,
        'updateInterval' => 15
    ]],
    static fn (array $source): CalendarProviderInterface => new ICalendarFileProvider(
        (string) $source['fileData'],
        (string) $source['name'],
        (string) $source['id']
    )
);
assertSameValue(
    $mixedCalendars[1]['id'],
    $replacementSourceProvider->getCalendars()[0]['id'],
    'Replacing a local ICS file must keep the calendar identity stable while its configured name is unchanged.'
);

$recurringFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:weekly-series@example.com\r\n"
    . "DTSTART;TZID=Europe/Berlin:20260323T100000\r\n"
    . "DTEND;TZID=Europe/Berlin:20260323T110000\r\n"
    . "RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=4\r\n"
    . "EXDATE;TZID=Europe/Berlin:20260406T100000\r\n"
    . "RDATE;TZID=Europe/Berlin:20260408T100000\r\n"
    . "SUMMARY:Weekly meeting\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:weekly-series@example.com\r\n"
    . "RECURRENCE-ID;TZID=Europe/Berlin:20260330T100000\r\n"
    . "DTSTART;TZID=Europe/Berlin:20260331T140000\r\n"
    . "DTEND;TZID=Europe/Berlin:20260331T150000\r\n"
    . "SEQUENCE:2\r\n"
    . "SUMMARY:Moved meeting\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$recurringEvents = ICalendarCodec::parseEventsInRange(
    $recurringFeed,
    'https://calendar.example/recurring.ics',
    '"series"',
    new DateTimeImmutable('2026-03-20T00:00:00Z'),
    new DateTimeImmutable('2026-04-20T00:00:00Z')
);
assertSameValue(4, count($recurringEvents), 'RRULE, EXDATE, RDATE and moved overrides must form one recurrence set.');
assertSameValue('2026-03-23T10:00:00+01:00', $recurringEvents[0]['start'], 'The first occurrence must use winter time.');
assertSameValue('Moved meeting', $recurringEvents[1]['summary'], 'A RECURRENCE-ID override must replace its generated occurrence.');
assertSameValue('2026-03-31T14:00:00+02:00', $recurringEvents[1]['start'], 'Moved occurrences must retain their actual local time.');
assertSameValue('2026-04-08T10:00:00+02:00', $recurringEvents[2]['start'], 'RDATE must add an occurrence.');
assertSameValue('2026-04-13T10:00:00+02:00', $recurringEvents[3]['start'], 'Weekly recurrences must preserve wall time after DST.');
assertSameValue(true, $recurringEvents[3]['recurring'], 'Generated recurrence instances must be marked as recurring.');

$monthlyFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:first-monday@example.com\r\n"
    . "DTSTART;TZID=Europe/Berlin:20260105T090000\r\n"
    . "DTEND;TZID=Europe/Berlin:20260105T100000\r\n"
    . "RRULE:FREQ=MONTHLY;BYDAY=1MO;COUNT=3\r\n"
    . "SUMMARY:First Monday\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:last-workday@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260130\r\n"
    . "DTEND;VALUE=DATE:20260131\r\n"
    . "RRULE:FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;COUNT=3\r\n"
    . "SUMMARY:Last workday\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$monthlyEvents = ICalendarCodec::parseEventsInRange(
    $monthlyFeed,
    'https://calendar.example/monthly.ics',
    '',
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
    new DateTimeImmutable('2026-04-02T00:00:00Z')
);
$firstMondayDates = array_values(array_map(
    static fn (array $event): string => substr((string) $event['start'], 0, 10),
    array_filter($monthlyEvents, static fn (array $event): bool => $event['uid'] === 'first-monday@example.com')
));
$lastWorkdayDates = array_values(array_map(
    static fn (array $event): string => (string) $event['start'],
    array_filter($monthlyEvents, static fn (array $event): bool => $event['uid'] === 'last-workday@example.com')
));
assertSameValue(
    ['2026-01-05', '2026-02-02', '2026-03-02'],
    $firstMondayDates,
    'Ordinal BYDAY rules must generate the first Monday of each month.'
);
assertSameValue(
    ['2026-01-30', '2026-02-27', '2026-03-31'],
    $lastWorkdayDates,
    'BYSETPOS=-1 must select the final matching weekday of each month.'
);

$advancedFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:daily-until@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260701\r\n"
    . "DTEND;VALUE=DATE:20260702\r\n"
    . "RRULE:FREQ=DAILY;UNTIL=20260703\r\n"
    . "SUMMARY:Daily until\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:daily-until@example.com\r\n"
    . "RECURRENCE-ID;VALUE=DATE:20260702\r\n"
    . "DTSTART;VALUE=DATE:20260702\r\n"
    . "DTEND;VALUE=DATE:20260703\r\n"
    . "STATUS:CANCELLED\r\n"
    . "SUMMARY:Cancelled day\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:last-month-day@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260131\r\n"
    . "DTEND;VALUE=DATE:20260201\r\n"
    . "RRULE:FREQ=MONTHLY;BYMONTHDAY=-1;COUNT=3\r\n"
    . "SUMMARY:Month end\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:yearly-sunday@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260329\r\n"
    . "DTEND;VALUE=DATE:20260330\r\n"
    . "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU;COUNT=2\r\n"
    . "SUMMARY:Last Sunday in March\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:duration@example.com\r\n"
    . "DTSTART;TZID=Europe/Berlin:20260705T100000\r\n"
    . "DURATION:PT1H30M\r\n"
    . "SUMMARY:Duration event\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$advancedEvents = ICalendarCodec::parseEventsInRange(
    $advancedFeed,
    'https://calendar.example/advanced.ics',
    '',
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
    new DateTimeImmutable('2028-01-01T00:00:00Z')
);
$dailyDates = array_values(array_map(
    static fn (array $event): string => (string) $event['start'],
    array_filter($advancedEvents, static fn (array $event): bool => $event['uid'] === 'daily-until@example.com')
));
$monthEndDates = array_values(array_map(
    static fn (array $event): string => (string) $event['start'],
    array_filter($advancedEvents, static fn (array $event): bool => $event['uid'] === 'last-month-day@example.com')
));
$yearlyDates = array_values(array_map(
    static fn (array $event): string => (string) $event['start'],
    array_filter($advancedEvents, static fn (array $event): bool => $event['uid'] === 'yearly-sunday@example.com')
));
assertSameValue(['2026-07-01', '2026-07-03'], $dailyDates, 'UNTIL must be inclusive and cancelled overrides must remove occurrences.');
assertSameValue(['2026-01-31', '2026-02-28', '2026-03-31'], $monthEndDates, 'Negative BYMONTHDAY values must count from month end.');
assertSameValue(['2026-03-29', '2027-03-28'], $yearlyDates, 'Yearly ordinal BYDAY rules must be expanded.');
$durationEvents = array_values(array_filter(
    $advancedEvents,
    static fn (array $event): bool => $event['uid'] === 'duration@example.com'
));
assertSameValue('2026-07-05T11:30:00+02:00', $durationEvents[0]['end'], 'DURATION must define the event end when DTEND is absent.');

assertSameValue(
    604800000,
    SynchronizationSchedule::timerInterval(SynchronizationSchedule::WEEKLY, 15),
    'Weekly synchronization must use a safe direct timer interval.'
);
assertSameValue(
    86400000,
    SynchronizationSchedule::timerInterval(SynchronizationSchedule::MONTHLY, 15),
    'Monthly synchronization must use a daily due-date timer.'
);
assertSameValue(
    0,
    SynchronizationSchedule::timerInterval(SynchronizationSchedule::MANUAL, 15),
    'Manual synchronization must disable the timer.'
);
$lastSynchronization = (new DateTimeImmutable('2026-01-15T12:00:00Z'))->getTimestamp();
assertSameValue(
    false,
    SynchronizationSchedule::isDue(
        SynchronizationSchedule::MONTHLY,
        15,
        $lastSynchronization,
        (new DateTimeImmutable('2026-02-15T11:59:59Z'))->getTimestamp()
    ),
    'Monthly synchronization must not run before the next month is reached.'
);
assertSameValue(
    true,
    SynchronizationSchedule::isDue(
        SynchronizationSchedule::MONTHLY,
        15,
        $lastSynchronization,
        (new DateTimeImmutable('2026-02-15T12:00:00Z'))->getTimestamp()
    ),
    'Monthly synchronization must become due after one calendar month.'
);
assertSameValue(
    false,
    SynchronizationSchedule::isDue(SynchronizationSchedule::MANUAL, 15, 0),
    'Manual synchronization must never be triggered by the scheduler.'
);

$libraryMetadata = json_decode(
    file_get_contents(__DIR__ . '/../library.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue(
    'OpenCalendar',
    $libraryMetadata['name'] ?? null,
    'The visible library name must remain independent of protected Symcon product names.'
);
foreach ([
    'Kalender Konto',
    'Kalender Konfigurator',
    'Kalender',
    'Kalender Ansicht'
] as $moduleDirectory) {
    $moduleMetadata = json_decode(
        file_get_contents(__DIR__ . '/../' . $moduleDirectory . '/module.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    assertTrueValue(
        filter_var($moduleMetadata['url'] ?? '', FILTER_VALIDATE_URL) !== false,
        sprintf('The module "%s" must link to its documentation.', $moduleDirectory)
    );
}

$calendarModuleSource = file_get_contents(__DIR__ . '/../Kalender/module.php');
$accountModuleSource = file_get_contents(__DIR__ . '/../Kalender Konto/module.php');
$accountGoogleOAuthSource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/GoogleOAuthTrait.php');
$viewModuleSource = file_get_contents(__DIR__ . '/../Kalender Ansicht/module.php');
$viewTemplateSource = file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/index.html');
$viewStyleSource = file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/style.css');
$viewScriptSource = file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/app.js');
$viewFormSource = file_get_contents(__DIR__ . '/../Kalender Ansicht/form.json');
$viewLocaleSource = file_get_contents(__DIR__ . '/../Kalender Ansicht/locale.json');
assertTrueValue(
    is_string($accountModuleSource)
        && str_contains($accountModuleSource, 'self::GOOGLE_OAUTH_IDENTIFIER, self::MICROSOFT_OAUTH_IDENTIFIER')
        && str_contains($accountModuleSource, '$this->RegisterOAuth($identifier)')
        && str_contains($accountModuleSource, 'RegisterMessage(0, IPS_KERNELSTARTED)')
        && str_contains($accountModuleSource, "RegisterTimer('OAuthRegistrationTimer'")
        && str_contains($accountModuleSource, 'IPSKALACC_InitializeOAuth')
        && str_contains($accountModuleSource, 'OAUTH_REGISTRATION_DELAY_MS = 5_000')
        && str_contains($accountModuleSource, 'private function scheduleOAuthRegistration(): void')
        && preg_match(
            '/public function Create\(\): void[\s\S]*?public function GetConfigurationForm/',
            $accountModuleSource,
            $accountCreateMethod
        ) === 1
        && !str_contains($accountCreateMethod[0], 'RegisterOAuth(')
        && preg_match(
            '/public function ApplyChanges\(\): void[\s\S]*?public function InitializeOAuth/',
            $accountModuleSource,
            $accountApplyChangesMethod
        ) === 1
        && !str_contains($accountApplyChangesMethod[0], 'registerOAuthHandlers()')
        && str_contains($accountApplyChangesMethod[0], 'scheduleOAuthRegistration()')
        && preg_match(
            '/public function MessageSink\([\s\S]*?public function RequestAction/',
            $accountModuleSource,
            $accountMessageSinkMethod
        ) === 1
        && !str_contains($accountMessageSinkMethod[0], 'registerOAuthHandlers()')
        && str_contains($accountMessageSinkMethod[0], 'scheduleOAuthRegistration()')
        && !str_contains($accountModuleSource, "RegisterPropertyString('GoogleClientID'")
        && !str_contains($accountModuleSource, "RegisterPropertyString('GoogleClientSecret'")
        && !str_contains($accountModuleSource, 'RegisterHook(')
        && is_string($accountGoogleOAuthSource)
        && str_contains($accountGoogleOAuthSource, 'private function processGoogleOAuthData(): void'),
    'OAuth registration must be deferred beyond ApplyChanges so module reloads can settle first.'
);
assertTrueValue(
    is_string($calendarModuleSource)
        && str_contains($calendarModuleSource, 'RegisterMessage(0, IPS_KERNELSTARTED)')
        && str_contains($calendarModuleSource, "RegisterTimer('InitializationTimer'")
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('RuntimeReady', false)")
        && str_contains($calendarModuleSource, 'IPS_GetKernelRunlevel() !== KR_READY'),
    'The calendar module must defer parent communication until the kernel is ready.'
);
assertTrueValue(
    is_string($calendarModuleSource)
        && str_contains($calendarModuleSource, "RegisterAttributeString('ResolvedCalendarID', '')")
        && str_contains($calendarModuleSource, 'private function effectiveCalendarId(): string')
        && str_contains($calendarModuleSource, 'Recovered the calendar identity from the unique instance name.'),
    'Existing calendar instances with a missing ID must recover an unambiguous identity without recreation.'
);
assertTrueValue(
    is_string($calendarModuleSource)
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('DetectedWriteAccessKnown', false)")
        && str_contains($calendarModuleSource, "array_key_exists('writeAccessKnown', \$calendar)")
        && !str_contains($calendarModuleSource, ": array_key_exists('create', \$capabilities)")
        && str_contains($calendarModuleSource, "\$this->ReadAttributeBoolean('DetectedCanWrite')
                            || \$this->ReadPropertyBoolean('CanWrite')"),
    'Calendar instances must preserve writable operation for legacy caches and incomplete DAV privilege metadata.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, 'RegisterMessage(0, IPS_KERNELSTARTED)')
        && str_contains($viewModuleSource, "RegisterTimer('InitializationTimer'")
        && str_contains($viewModuleSource, "RegisterAttributeBoolean('RuntimeReady', false)")
        && str_contains($viewModuleSource, 'IPS_GetKernelRunlevel() !== KR_READY'),
    'The calendar view must defer cross-instance access until the kernel is ready.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, "RegisterAttributeString('CalendarSelectionBackup', '[]')")
        && str_contains($viewModuleSource, 'private function recoverCalendarSelectionFromMessages(): void')
        && str_contains($viewModuleSource, 'public function SelectAllCalendars(): bool'),
    'Calendar view selections must survive module reloads and remain recoverable after an update.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, 'use Burki24\\SymconModuleHelper\\VisualizationAssetHelper;')
        && str_contains($viewModuleSource, "require_once __DIR__ . '/../libs/helper/VisualizationAssetHelper.php';")
        && str_contains($viewModuleSource, 'use VisualizationAssetHelper;')
        && str_contains($viewModuleSource, 'use Burki24\\SymconModuleHelper\\IPSViewHTMLPageHelper;')
        && str_contains($viewModuleSource, "require_once __DIR__ . '/../libs/helper/IPSViewHTMLPageHelper.php';")
        && str_contains($viewModuleSource, 'use IPSViewHTMLPageHelper;')
        && str_contains($viewModuleSource, '$this->RegisterIPSViewHTMLPageProperties();')
        && str_contains($viewModuleSource, '$this->InsertIPSViewHTMLPageFormItems($form[\'elements\']);')
        && str_contains($viewModuleSource, '$this->MaintainIPSViewHTMLVariable(')
        && str_contains($viewModuleSource, '$this->UpdateIPSViewHTMLVariable(')
        && str_contains($viewModuleSource, '$this->RenderVisualizationHTMLPage($ipsView, [')
        && !str_contains($viewModuleSource, "RegisterPropertyBoolean('EnableIPSView'")
        && !str_contains($viewModuleSource, '$this->MaintainVariable('),
    'The calendar view must manage and render its optional IPSView output through IPSViewHTMLPageHelper.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, "case 'FormRegenerateIPSViewHTML':")
        && str_contains($viewModuleSource, 'public function RegenerateIPSViewHTML(): bool')
        && str_contains($viewModuleSource, "return \$this->UpdateIPSViewHTMLVariable('IPSViewCalendar', \$html);")
        && str_contains($viewModuleSource, 'private function renderNonEmptyIPSViewHTML(array $state, string $debugContext): ?string')
        && str_contains($viewModuleSource, 'private function existingIPSViewHTML(): string')
        && str_contains($viewModuleSource, "'Rendering returned an empty document; preserving the existing IPSView HTML.'")
        && !str_contains($viewModuleSource, "UnregisterVariable('IPSViewCalendar')"),
    'IPSView regeneration must preserve the object ID and must never replace valid WebContent with an empty render result.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, '$this->RegisterHook($this->ipsViewHookAddress());')
        && str_contains($viewModuleSource, 'protected function ProcessHookData(): void')
        && str_contains($viewModuleSource, "strtoupper((string) (\$_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'")
        && str_contains($viewModuleSource, 'hash_equals($this->ipsViewToken(), $token)')
        && str_contains($viewModuleSource, "case 'CreateEvent':")
        && str_contains($viewModuleSource, "case 'UpdateEvent':")
        && str_contains($viewModuleSource, "case 'MoveEvent':")
        && str_contains($viewModuleSource, "case 'DeleteEvent':")
        && str_contains($viewModuleSource, "return 'opencalendar/view/' . \$this->InstanceID;")
        && str_contains($viewModuleSource, "'endpoint' => '/hook/' . \$this->ipsViewHookAddress()")
        && str_contains($viewModuleSource, "'token'    => \$this->ipsViewToken()"),
    'The calendar IPSView page must use a unique, token-protected POST WebHook with an explicit action whitelist.'
);
assertTrueValue(
    is_string($viewScriptSource)
        && str_contains($viewScriptSource, "const calendarIPSViewConfig = calendarVisualization.mode === 'ipsview'")
        && str_contains($viewScriptSource, 'async function calendarIPSViewRequest(action, value)')
        && str_contains($viewScriptSource, "body.set('token', String(calendarIPSViewConfig.token));")
        && str_contains($viewScriptSource, "'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'")
        && !str_contains($viewScriptSource, 'Authorization')
        && !str_contains($viewScriptSource, "'/api/'")
        && str_contains($viewScriptSource, 'return isNativeVisualization() || hasIPSViewActionBridge();')
        && str_contains($viewScriptSource, "return calendarVisualization.mode === 'symcon';")
        && str_contains($viewScriptSource, 'async function waitForNativeActionBridge(timeoutMilliseconds = 1500)')
        && str_contains($viewScriptSource, 'if (await sendAction(action, value))')
        && str_contains($viewScriptSource, "const action = moving ? 'MoveEvent' : (selectedEvent ? 'UpdateEvent' : 'CreateEvent');")
        && str_contains($viewScriptSource, "await sendAction('DeleteEvent',"),
    'The shared calendar interface must create, update, move, delete and refresh through either requestAction or the IPSView WebHook.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, "case 'MoveEvent':")
        && str_contains($viewModuleSource, 'IPSKAL_CreateEvent(')
        && str_contains($viewModuleSource, 'IPSKAL_DeleteEvent(')
        && str_contains($viewModuleSource, "'Event moved.'")
        && str_contains($viewModuleSource, 'The event was created in the target calendar, but could not be deleted from the source calendar.'),
    'Moving an event must create the target copy before deleting the source and report partial failures without risking event loss.'
);

assertTrueValue(
    is_string($viewModuleSource)
        && is_string($viewScriptSource)
        && str_contains($viewModuleSource, "'instanceId'           => \$this->InstanceID")
        && str_contains($viewScriptSource, 'const calendarViewStateStorageKey = Number(calendarOptions.instanceId) > 0')
        && str_contains($viewScriptSource, 'restoreClientViewState(calendarState.settings.defaultView')
        && str_contains($viewScriptSource, 'window.localStorage.getItem(calendarViewStateStorageKey)')
        && str_contains($viewScriptSource, 'window.localStorage.setItem(calendarViewStateStorageKey, value)')
        && str_contains($viewScriptSource, 'persistClientViewState();'),
    'The calendar visualization must preserve the selected view and cursor date client-side per instance.'
);

assertTrueValue(
    is_string($viewTemplateSource)
        && is_string($viewStyleSource)
        && is_string($viewScriptSource)
        && str_contains($viewTemplateSource, '{{HTML_LANGUAGE}}')
        && str_contains($viewTemplateSource, '{{HTML_CLASSES}}')
        && str_contains($viewTemplateSource, '{{VIEWPORT_CONTENT}}')
        && str_contains($viewTemplateSource, '{{ROOT_FONT_SIZE}}')
        && str_contains($viewTemplateSource, '{{VISUALIZATION_THEME}}')
        && str_contains($viewTemplateSource, '{{MODULE_STYLE}}')
        && str_contains($viewTemplateSource, '{{IPSVIEW_STYLE}}')
        && str_contains($viewTemplateSource, '{{BOOTSTRAP_JSON}}')
        && str_contains($viewTemplateSource, '{{MODULE_SCRIPT}}')
        && str_contains($viewScriptSource, 'window.SYMC_VISUALIZATION')
        && str_contains($viewScriptSource, "handleMessage({ type: 'state', payload: calendarVisualization.state });")
        && !str_contains($viewModuleSource, "VisualizationAsset('module.html')"),
    'The calendar view must use the shared asset and bootstrap contract.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, 'use Burki24\\SymconModuleHelper\\VisualizationThemeHelper;')
        && str_contains($viewModuleSource, "require_once __DIR__ . '/../libs/helper/VisualizationThemeHelper.php';")
        && str_contains($viewModuleSource, 'use VisualizationThemeHelper;')
        && str_contains($viewModuleSource, '$this->VisualizationThemeCSS()')
        && is_string($viewTemplateSource)
        && is_string($viewStyleSource)
        && str_contains($viewTemplateSource, '{{VISUALIZATION_THEME}}')
        && str_contains($viewStyleSource, '--cal-accent: var(--symc-accent);')
        && str_contains($viewStyleSource, '--cal-card: var(--symc-background);'),
    'The calendar view must consume the shared Symcon visualization theme.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, 'use Burki24\\SymconModuleHelper\\IPSViewStyleHelper;')
        && str_contains($viewModuleSource, "require_once __DIR__ . '/../libs/helper/IPSViewStyleHelper.php';")
        && str_contains($viewModuleSource, 'use IPSViewStyleHelper;')
        && str_contains($viewModuleSource, '$this->RegisterIPSViewStyleProperties();')
        && str_contains($viewModuleSource, '$this->InsertIPSViewStyleFormItems(')
        && str_contains($viewModuleSource, '$this->IPSViewStyleRootFontSize()')
        && str_contains($viewModuleSource, '$this->IPSViewStyleCSSVariables(')
        && str_contains($viewModuleSource, '$this->RegisterIPSViewStyleMediaMessages();')
        && str_contains($viewModuleSource, '$this->IsIPSViewStyleMediaUpdate(')
        && !str_contains($viewModuleSource, 'private function injectIPSViewStyleFormItems(')
        && !str_contains($viewModuleSource, 'private function IPSViewRootFontSize(')
        && !str_contains($viewModuleSource, 'private function renderIPSViewStyleCSS(')
        && is_string($viewTemplateSource)
        && is_string($viewStyleSource)
        && str_contains($viewTemplateSource, '{{IPSVIEW_STYLE}}')
        && str_contains($viewStyleSource, '--cal-text: var(--ipsview-role-text-primary);')
        && str_contains($viewStyleSource, '--cal-text-active: var(--ipsview-role-text-active);')
        && str_contains($viewStyleSource, '--cal-label-text: var(--ipsview-role-text-label);')
        && str_contains($viewStyleSource, '--cal-surface: var(--ipsview-role-control-background);')
        && str_contains($viewStyleSource, '--cal-accent: var(--ipsview-role-accent);')
        && str_contains($viewStyleSource, '--cal-danger: var(--ipsview-role-critical);')
        && str_contains($viewStyleSource, '--cal-popup-shadow: var(--ipsview-role-popup-shadow);'),
    'The calendar view must consume IPSViewStyleHelper directly without replacing calendar event colors.'
);
assertTrueValue(
    is_string($viewFormSource)
        && str_contains($viewFormSource, 'Configure optional IPSView HTML output.')
        && str_contains(
            $viewFormSource,
            'Configure the shared IPSView style used by the standalone HTML page.'
        )
        && !str_contains($viewFormSource, '"name": "EnableIPSView"')
        && !str_contains($viewFormSource, '"name": "IPSViewTheme"')
        && !str_contains($viewFormSource, '"name": "IPSViewTransparent"')
        && !str_contains($viewFormSource, '"name": "IPSViewFontScale"'),
    'The calendar view form must delegate optional output and the complete shared style to the helpers.'
);
assertTrueValue(
    is_string($viewLocaleSource)
        && !str_contains($viewLocaleSource, 'Provide IPSView HTMLBox')
        && !str_contains($viewLocaleSource, 'Creates a WebContent variable with the calendar for an IPSView HTML-Box.')
        && !str_contains($viewLocaleSource, 'Choose a shared IPSView style source.'),
    'Helper-owned IPSView captions and hints must not be duplicated in the calendar locale.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, 'public function Migrate(string $JSONData): string')
        && str_contains($viewModuleSource, "'IPSViewTheme'")
        && str_contains($viewModuleSource, "'IPSViewTransparent' => 'IPSViewStyleTransparentBackground'")
        && str_contains($viewModuleSource, "'IPSViewFontScale'   => 'IPSViewStyleFontScale'"),
    'The calendar view must migrate its former IPSView palette and layout properties.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowAgendaCalendarWeek', false)")
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowListCalendarWeek', false)")
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowThreeDaysCalendarWeek', false)")
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowWeekCalendarWeek', true)")
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowMonthCalendarWeek', false)")
        && is_string($viewScriptSource)
        && str_contains($viewScriptSource, 'formatCalendarWeekLabel(days)')
        && str_contains($viewScriptSource, "calendarWeeks.join('/')")
        && str_contains($viewScriptSource, 'day.getDay() === 1')
        && str_contains($viewScriptSource, 'Date.UTC'),
    'Agenda, list, three-day, week and month views must optionally show ISO calendar weeks.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowAgendaDayOfYear', true)")
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowListDayOfYear', false)")
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowThreeDaysDayOfYear', true)")
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowWeekDayOfYear', true)")
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowMonthDayOfYear', true)")
        && !str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowDayOfYear', true)")
        && is_string($viewScriptSource)
        && str_contains($viewScriptSource, 'formatDayHeading(')
        && str_contains($viewScriptSource, 'dayOfYear(date)')
        && str_contains($viewScriptSource, 'daysInYear(date)')
        && str_contains($viewScriptSource, 'calendarState.settings.showListDayOfYear === true')
        && str_contains($viewScriptSource, 'calendarState.settings.showMonthDayOfYear !== false'),
    'Agenda, list, three-day, week and month views must optionally show the day of year.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowAgendaEventCount', true)")
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowThreeDaysEventCount', true)")
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowWeekEventCount', true)")
        && is_string($viewScriptSource)
        && str_contains($viewScriptSource, 'group.events.length')
        && str_contains($viewScriptSource, 'formatDayHeading(date, options, showDayOfYear, eventCount, showEventCount)')
        && str_contains($viewScriptSource, 'calendarState.settings.showAgendaEventCount !== false')
        && str_contains($viewScriptSource, 'calendarState.settings.showThreeDaysEventCount !== false')
        && str_contains($viewScriptSource, 'calendarState.settings.showWeekEventCount !== false')
        && str_contains($viewScriptSource, "eventCount === 1 ? 'Event' : 'Events'")
        && preg_match('/function renderMonth\(\)[\s\S]*?function renderEmpty/', $viewScriptSource, $monthRenderer) === 1
        && !str_contains($monthRenderer[0], 'formatDayHeading('),
    'Agenda, three-day and weekly event totals must be independently configurable without changing the month view.'
);

assertTrueValue(
    is_string($viewModuleSource)
        && is_string($viewFormSource)
        && is_string($viewScriptSource)
        && str_contains($viewModuleSource, "RegisterPropertyInteger('AgendaPeriodDays', 14)")
        && str_contains($viewModuleSource, "RegisterPropertyInteger('ListPeriodDays', 14)")
        && str_contains($viewModuleSource, "RegisterPropertyInteger('ThreeDaysPeriodDays', 3)")
        && str_contains($viewModuleSource, "RegisterPropertyInteger('WeekPeriodWeeks', 1)")
        && str_contains($viewModuleSource, "RegisterPropertyInteger('MonthPeriodMonths', 1)")
        && preg_match('/"type": "ExpansionPanel",\s*"caption": "View periods"/', $viewFormSource) === 1
        && preg_match('/"type": "PopupButton",\s*"caption": "View periods"/', $viewFormSource) === 0
        && str_contains($viewScriptSource, 'function viewPeriod(view)')
        && str_contains($viewScriptSource, "viewPeriod('list')")
        && str_contains($viewScriptSource, "viewPeriod('month')"),
    'All calendar views must expose an independently configurable visible period.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && is_string($viewFormSource)
        && is_string($viewTemplateSource)
        && is_string($viewScriptSource)
        && is_string($viewStyleSource)
        && str_contains($viewModuleSource, "4       => 'list'")
        && str_contains($viewFormSource, '"caption": "List"')
        && str_contains($viewTemplateSource, 'data-view="list"')
        && str_contains($viewScriptSource, 'function renderList()')
        && str_contains($viewScriptSource, 'function listColumns()')
        && str_contains($viewStyleSource, '.list-table {')
        && str_contains($viewStyleSource, '.list-color-column {'),
    'Calendar View must provide the shared minimal list view for Tile and IPSView.'
);
foreach ([
    'ShowListDate'         => true,
    'ShowListStart'        => true,
    'ShowListEnd'          => true,
    'ShowListTitle'        => true,
    'ShowListCalendarName' => true,
    'ShowListLocation'     => false,
    'ShowListDescription'  => false
] as $property => $default) {
    assertTrueValue(
        str_contains($viewFormSource, '"name": "' . $property . '"')
            && str_contains(
                $viewModuleSource,
                "RegisterPropertyBoolean('" . $property . "', " . ($default ? 'true' : 'false') . ')'
            )
            && str_contains($viewModuleSource, "ReadPropertyBoolean('" . $property . "')"),
        sprintf('List column %s must be configurable, persisted and exposed.', $property)
    );
}

assertTrueValue(
    is_string($viewModuleSource)
        && is_string($viewFormSource)
        && is_string($viewScriptSource)
        && str_contains($viewFormSource, '"name": "ShowListControls"')
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowListControls', true)")
        && str_contains($viewModuleSource, "ReadPropertyBoolean('ShowListControls')")
        && str_contains($viewScriptSource, 'function listControlsVisible()')
        && str_contains($viewScriptSource, "activeView !== 'list' || calendarState.settings.showListControls !== false")
        && str_contains($viewScriptSource, "document.getElementById('previous-button').parentElement.classList.toggle('hidden', !showControls)")
        && str_contains($viewScriptSource, "document.getElementById('refresh-button').classList.toggle('hidden', !showControls)")
        && str_contains($viewScriptSource, 'actionBridgeAvailable && listControlsVisible()'),
    'List controls must be independently configurable without hiding the period title or view selector.'
);

assertTrueValue(
    is_string($calendarModuleSource)
        && str_contains($calendarModuleSource, "RegisterVariableInteger('TodayEventCount'")
        && str_contains($calendarModuleSource, "RegisterTimer('DayChangeTimer'")
        && str_contains($calendarModuleSource, 'CalendarEventCounter::countForDay('),
    'Each calendar instance must expose and refresh a current-day event count.'
);

assertTrueValue(
    is_string($calendarModuleSource)
        && str_contains($calendarModuleSource, 'use Burki24\\SymconModuleHelper\\VariableHelper;')
        && str_contains($calendarModuleSource, "require_once __DIR__ . '/../libs/helper/VariableHelper.php';")
        && str_contains($calendarModuleSource, 'use VariableHelper;')
        && str_contains($calendarModuleSource, '$this->VariableExists(\'Events\')')
        && !str_contains($calendarModuleSource, "IPS_GetObjectIDByIdent('Events'"),
    'The calendar module must use VariableHelper for legacy Events variable detection.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, 'use Burki24\\SymconModuleHelper\\VariableHelper;')
        && str_contains($viewModuleSource, "require_once __DIR__ . '/../libs/helper/VariableHelper.php';")
        && str_contains($viewModuleSource, 'use VariableHelper;')
        && str_contains($viewModuleSource, 'GetVariableIDByIdent(\'LastSynchronization\', $instanceId)')
        && !str_contains($viewModuleSource, 'findChildByIdent('),
    'The calendar view must use parent-aware VariableHelper lookups instead of its local child scan.'
);

$configuratorModuleSource = file_get_contents(__DIR__ . '/../Kalender Konfigurator/module.php');

assertTrueValue(
    is_string($configuratorModuleSource)
        && str_contains($configuratorModuleSource, 'use Burki24\\SymconModuleHelper\\ParentConnectionHelper;')
        && str_contains($configuratorModuleSource, "require_once __DIR__ . '/../libs/helper/ParentConnectionHelper.php';")
        && str_contains($configuratorModuleSource, 'use ParentConnectionHelper;')
        && str_contains($configuratorModuleSource, '$parentId = $this->GetParentID();'),
    'The calendar configurator must use the shared ParentConnectionHelper for its connected account.'
);
assertTrueValue(
    is_string($configuratorModuleSource)
        && str_contains($configuratorModuleSource, 'private function parentConnectionError(): string')
        && str_contains($configuratorModuleSource, 'if (!$this->HasParent())')
        && str_contains($configuratorModuleSource, 'if (!is_string($responseJson) || $responseJson === \'\')'),
    'The calendar configurator must not send or decode data without a valid active parent account.'
);

$accountModuleSource = file_get_contents(__DIR__ . '/../Kalender Konto/module.php');
$googleOAuthTraitSource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/GoogleOAuthTrait.php');
$microsoftOAuthTraitSource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/MicrosoftOAuthTrait.php');

assertTrueValue(
    is_string($accountModuleSource)
        && str_contains($accountModuleSource, 'use Burki24\\SymconModuleHelper\\HttpResponseHelper;')
        && str_contains($accountModuleSource, "require_once __DIR__ . '/../libs/helper/HttpResponseHelper.php';")
        && str_contains($accountModuleSource, 'use HttpResponseHelper;'),
    'The calendar account must use the shared HttpResponseHelper.'
);
assertTrueValue(
    is_string($googleOAuthTraitSource)
        && str_contains($googleOAuthTraitSource, 'SendHtmlTextResponse(')
        && str_contains($googleOAuthTraitSource, '400,')
        && !str_contains($googleOAuthTraitSource, "header('Content-Type: text/html; charset=utf-8')")
        && !str_contains($googleOAuthTraitSource, 'http_response_code(400)'),
    'Google OAuth callback responses must be emitted through HttpResponseHelper.'
);
assertTrueValue(
    is_string($microsoftOAuthTraitSource)
        && str_contains($microsoftOAuthTraitSource, 'SendHtmlTextResponse(')
        && str_contains($microsoftOAuthTraitSource, '400,')
        && !str_contains($microsoftOAuthTraitSource, "header('Content-Type: text/html; charset=utf-8')"),
    'Microsoft OAuth callback responses must be emitted through HttpResponseHelper with HTTP 400 on errors.'
);

echo "All OpenCalendar tests passed.\n";
