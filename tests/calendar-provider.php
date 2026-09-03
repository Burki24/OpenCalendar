<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\SymconOAuthClient;
use IPSKalender\CalendarEventReminder;
use IPSKalender\CalendarEventState;
use IPSKalender\CalendarEventTranslation;
use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\CalendarProviderInterface;
use IPSKalender\CalendarRecurrenceRule;
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

require_once __DIR__ . '/../libs/CalendarEventState.php';
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
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string, maxResponseBytes: int}> */
    public array $requests = [];

    /** @var list<CalendarHttpResponse|Throwable> */
    private array $responses;

    /** @param list<CalendarHttpResponse|Throwable> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $maxResponseBytes = 67_108_864
    ): CalendarHttpResponse {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'maxResponseBytes');
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

assertSameValue(
    CalendarEventState::STATUS_TENTATIVE,
    CalendarEventState::normalizeStatus(' tentative '),
    'Provider event status values must normalize to RFC 5545 tokens.'
);
assertSameValue(
    '',
    CalendarEventState::normalizeStatus('unknown'),
    'Unsupported provider event status values must not leak into the shared event model.'
);
assertSameValue(
    CalendarEventState::TRANSP_TRANSPARENT,
    CalendarEventState::normalizeTransparency(' transparent '),
    'Provider transparency values must normalize to RFC 5545 tokens.'
);
assertSameValue(
    CalendarEventState::TRANSP_OPAQUE,
    CalendarEventState::normalizeTransparency(''),
    'Missing provider transparency must use the RFC 5545 opaque default.'
);
assertSameValue(
    true,
    CalendarEventState::isCancelled(' cancelled '),
    'Cancelled provider events must be recognized independent of token casing.'
);
assertSameValue(
    false,
    CalendarEventState::isCancelled(CalendarEventState::STATUS_TENTATIVE),
    'Tentative events must remain visible.'
);
$visibleStateEvents = CalendarEventState::filterVisibleEvents([
    ['id' => 'google', 'status' => CalendarEventState::STATUS_CONFIRMED],
    ['id' => 'microsoft', 'status' => CalendarEventState::STATUS_CONFIRMED],
    ['id' => 'caldav', 'status' => CalendarEventState::STATUS_CANCELLED],
    ['id' => 'ics', 'status' => 'cancelled'],
    ['id' => 'tentative', 'status' => CalendarEventState::STATUS_TENTATIVE]
]);
assertSameValue(
    ['google', 'microsoft', 'tentative'],
    array_column($visibleStateEvents, 'id'),
    'Provider-neutral event lists must exclude cancelled events while retaining active and tentative events.'
);

$multipleReminder = CalendarEventReminder::fromMinutes([5, 30, 120]);
assertSameValue('multiple', $multipleReminder['mode'], 'Multiple exact reminder offsets must use the shared multiple reminder mode.');
assertSameValue(
    [
        ['minutesBeforeStart' => 5],
        ['minutesBeforeStart' => 30],
        ['minutesBeforeStart' => 120]
    ],
    $multipleReminder['reminders'],
    'Multiple reminder offsets must remain individually addressable in the provider-neutral model.'
);
try {
    CalendarEventReminder::normalizeInput([
        'mode'      => 'multiple',
        'reminders' => [
            ['minutesBeforeStart' => 15],
            ['minutesBeforeStart' => 15]
        ]
    ]);
    throw new RuntimeException('Duplicate reminder offsets were accepted.');
} catch (InvalidArgumentException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'unique'),
        'Provider-neutral multiple reminders must reject duplicate trigger times.'
    );
}

$calendarClient = new FakeHttpClient([
    response(200, [
        'items'         => [
            [
                'id'               => 'owner@example.com',
                'summary'          => 'Primary',
                'backgroundColor'  => '#1a73e8',
                'accessRole'       => 'owner',
                'primary'          => true,
                'timeZone'         => 'Europe/Berlin',
                'defaultReminders' => [['method' => 'popup', 'minutes' => 30]]
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
            'id'               => 'shared@example.com',
            'summaryOverride'  => 'Shared calendar',
            'backgroundColor'  => '#34a853',
            'accessRole'       => 'reader',
            'defaultReminders' => [['method' => 'email', 'minutes' => 60]]
        ]]
    ])
]);
$provider = new GoogleCalendarProvider($calendarClient, 'access-token');
$calendars = $provider->getCalendars();
assertSameValue(2, count($calendars), 'Calendar discovery must paginate and exclude free/busy-only entries.');
assertSameValue('owner@example.com', $calendars[0]['providerId'], 'The primary calendar must be listed first.');
assertSameValue(true, $calendars[0]['writeAccessKnown'], 'Google access roles must provide authoritative write metadata.');
assertSameValue(true, $calendars[0]['capabilities']['create'], 'Owners must have write access.');
assertSameValue(true, $calendars[0]['capabilities']['createRecurrence'], 'Writable Google calendars must advertise recurrence creation support.');
assertSameValue(true, $calendars[0]['capabilities']['updateRecurrence'], 'Writable Google calendars must advertise recurrence conversion support.');
assertSameValue(true, $calendars[0]['capabilities']['updateOccurrence'], 'Writable Google calendars must advertise single-occurrence update support.');
assertSameValue(true, $calendars[0]['capabilities']['deleteOccurrence'], 'Writable Google calendars must advertise single-occurrence deletion support.');
assertSameValue(true, $calendars[0]['capabilities']['updateFollowing'], 'Writable Google calendars must advertise this-and-following update support.');
assertSameValue(true, $calendars[0]['capabilities']['updateSeries'], 'Writable Google calendars must advertise recurring-series update support.');
assertSameValue(true, $calendars[0]['capabilities']['deleteSeries'], 'Writable Google calendars must advertise recurring-series deletion support.');
assertSameValue(true, $calendars[0]['capabilities']['useDefaultReminder'], 'Google calendars must advertise persistent calendar-default reminder support.');
assertSameValue(true, $calendars[0]['capabilities']['createWithDefaultReminder'], 'Google calendars must allow new events to use the calendar default.');
assertSameValue(5, $calendars[0]['capabilities']['maxReminders'], 'Google calendars must advertise support for up to five provider-neutral reminders.');
assertSameValue(true, $calendars[0]['capabilities']['writeStatus'], 'Writable Google calendars must advertise provider-neutral status writes.');
assertSameValue(true, $calendars[0]['capabilities']['writeTransparency'], 'Writable Google calendars must advertise provider-neutral transparency writes.');
assertSameValue(CalendarEventState::STATUS_CONFIRMED, $calendars[0]['defaultStatus'], 'Google calendars must advertise their default event status.');
assertSameValue(CalendarEventState::TRANSP_OPAQUE, $calendars[0]['defaultTransparency'], 'Google timed events must default to busy.');
assertSameValue(CalendarEventState::TRANSP_OPAQUE, $calendars[0]['defaultAllDayTransparency'], 'Google all-day events must default to busy.');
assertSameValue('custom', $calendars[0]['defaultReminder']['mode'], 'One Google popup calendar default must use the shared reminder model.');
assertSameValue(30, $calendars[0]['defaultReminder']['minutesBeforeStart'], 'Google calendar-default reminder offsets must be retained.');
assertSameValue('Europe/Berlin', $calendars[0]['timezone'], 'Google calendar timezones must be retained for recurring events.');
assertSameValue(false, $calendars[1]['capabilities']['create'], 'Readers must not have write access.');
assertSameValue(false, $calendars[1]['capabilities']['createWithDefaultReminder'], 'Read-only Google calendars must not allow default-reminder event creation.');
assertSameValue('complex', $calendars[1]['defaultReminder']['mode'], 'Non-popup Google calendar defaults must be protected as complex settings.');
assertSameValue(false, $calendars[1]['capabilities']['updateRecurrence'], 'Read-only Google calendars must not advertise recurrence conversion support.');
assertSameValue(false, $calendars[1]['capabilities']['updateOccurrence'], 'Read-only Google calendars must not advertise single-occurrence update support.');
assertSameValue(false, $calendars[1]['capabilities']['deleteOccurrence'], 'Read-only Google calendars must not advertise single-occurrence deletion support.');
assertTrueValue(str_contains($calendarClient->requests[1]['url'], 'pageToken=page-2'), 'The second calendar page must be requested.');

$googleRepeatedPageClient = new FakeHttpClient([
    response(200, ['items' => [], 'nextPageToken' => 'repeated-token']),
    response(200, ['items' => [], 'nextPageToken' => 'repeated-token'])
]);
try {
    (new GoogleCalendarProvider($googleRepeatedPageClient, 'access-token'))->getCalendars();
    throw new RuntimeException('A repeated Google page token was accepted.');
} catch (RuntimeException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'repeated page token'),
        'Repeated Google page tokens must stop pagination.'
    );
}

$eventClient = new FakeHttpClient([
    response(200, [
        'timeZone' => 'Europe/Berlin',
        'items'    => [
            [
                'id'        => 'all-day-id',
                'iCalUID'   => 'all-day@example.com',
                'etag'      => '"etag-1"',
                'summary'   => 'Holiday',
                'status'    => 'confirmed',
                'reminders' => ['useDefault' => true],
                'start'     => ['date' => '2026-07-20'],
                'end'       => ['date' => '2026-07-21'],
                'htmlLink'  => 'https://calendar.google.com/event?eid=1'
            ],
            [
                'id'                => 'instance-id',
                'iCalUID'           => 'series@example.com',
                'summary'           => 'Meeting',
                'status'            => 'tentative',
                'transparency'      => 'transparent',
                'reminders'         => [
                    'useDefault' => false,
                    'overrides'  => [['method' => 'popup', 'minutes' => 15]]
                ],
                'recurringEventId'  => 'series-id',
                'originalStartTime' => ['dateTime' => '2026-07-20T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
                'start'             => ['dateTime' => '2026-07-20T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
                'end'               => ['dateTime' => '2026-07-20T11:00:00+02:00', 'timeZone' => 'Europe/Berlin']
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
assertSameValue('CONFIRMED', $events[0]['status'], 'Google confirmed status must use the provider-neutral RFC value.');
assertSameValue('OPAQUE', $events[0]['transparency'], 'Google events without explicit transparency must default to opaque.');
assertSameValue('TENTATIVE', $events[1]['status'], 'Google tentative status must remain distinguishable from confirmed events.');
assertSameValue('TRANSPARENT', $events[1]['transparency'], 'Google transparent events must retain their free/busy behavior.');
assertSameValue('default', $events[0]['reminder']['mode'], 'Google calendar-default reminders must remain distinguishable.');
assertSameValue('custom', $events[1]['reminder']['mode'], 'One Google popup reminder must use the shared reminder model.');
assertSameValue(15, $events[1]['reminder']['minutesBeforeStart'], 'Google popup reminder offsets must be retained.');
assertSameValue(true, $events[1]['recurring'], 'Expanded recurring instances must remain marked as recurring.');
assertSameValue('occurrence', $events[1]['recurrenceType'], 'Expanded Google events must be identified as occurrences.');
assertSameValue('series-id', $events[1]['seriesId'], 'The Google series ID must be retained separately.');
assertSameValue('instance-id', $events[1]['occurrenceId'], 'The concrete Google occurrence ID must be retained.');
assertSameValue('2026-07-20T09:00:00+02:00', $events[1]['originalStart'], 'The original occurrence start must be retained.');
assertSameValue('', $events[1]['recurrenceId'], 'Google series IDs must not be exposed as RFC recurrence IDs.');
assertSameValue(true, $events[1]['canUpdateOccurrence'], 'Google occurrences must advertise update support.');
assertSameValue(true, $events[1]['canDeleteOccurrence'], 'Google occurrences must advertise delete support.');
assertSameValue(true, $events[1]['canUpdateFollowing'], 'Google occurrences must advertise this-and-following update support.');
assertSameValue(true, $events[1]['canUpdateSeries'], 'Google occurrences must advertise parent-series update support.');
assertSameValue(true, $events[1]['canDeleteSeries'], 'Google occurrences must advertise parent-series delete support.');
assertTrueValue(str_contains($eventClient->requests[0]['url'], 'owner%40example.com'), 'Calendar IDs must be URL encoded.');
assertSameValue('Bearer access-token', $eventClient->requests[0]['headers']['Authorization'], 'API requests must use Bearer authorization.');

$googleMultipleReminderClient = new FakeHttpClient([
    response(200, [
        'timeZone' => 'Europe/Berlin',
        'items'    => [[
            'id'        => 'multi-reminder-id',
            'iCalUID'   => 'multi-reminder@example.com',
            'summary'   => 'Multiple reminders',
            'status'    => 'confirmed',
            'reminders' => [
                'useDefault' => false,
                'overrides'  => [
                    ['method' => 'popup', 'minutes' => 10],
                    ['method' => 'popup', 'minutes' => 60]
                ]
            ],
            'start' => ['dateTime' => '2026-07-20T12:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
            'end'   => ['dateTime' => '2026-07-20T13:00:00+02:00', 'timeZone' => 'Europe/Berlin']
        ]]
    ])
]);
$googleMultipleReminderEvents = (new GoogleCalendarProvider($googleMultipleReminderClient, 'access-token'))->getEvents(
    'owner@example.com',
    new DateTimeImmutable('2026-07-20T00:00:00Z'),
    new DateTimeImmutable('2026-07-21T00:00:00Z')
);
assertSameValue('multiple', $googleMultipleReminderEvents[0]['reminder']['mode'], 'Multiple Google popup reminders must stay editable in the shared reminder model.');
assertSameValue(
    [['minutesBeforeStart' => 10], ['minutesBeforeStart' => 60]],
    $googleMultipleReminderEvents[0]['reminder']['reminders'],
    'Google popup reminder offsets must survive provider mapping without loss.'
);

$freshEditClient = new FakeHttpClient([
    response(200, [
        'id'       => 'event-id',
        'iCalUID'  => 'event@example.com',
        'etag'     => '"fresh-etag"',
        'summary'  => 'Fresh event',
        'status'   => 'confirmed',
        'start'    => ['dateTime' => '2026-07-20T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'end'      => ['dateTime' => '2026-07-20T11:00:00+02:00', 'timeZone' => 'Europe/Berlin']
    ])
]);
$freshEditEvent = (new GoogleCalendarProvider($freshEditClient, 'access-token'))->getEventForEdit(
    'owner@example.com',
    [
        'eventReference' => 'event-id',
        'uid'            => 'event@example.com',
        'startTimestamp' => (new DateTimeImmutable('2026-07-20T10:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-07-20T11:00:00+02:00'))->getTimestamp()
    ]
);
assertSameValue('"fresh-etag"', $freshEditEvent['etag'], 'Editing must use the current Google event ETag.');
assertSameValue('event-id', $freshEditEvent['eventReference'], 'The directly loaded Google event identity must be retained.');
assertSameValue('GET', $freshEditClient->requests[0]['method'], 'Preparing an edit must read the event directly from Google.');
assertTrueValue(
    str_ends_with($freshEditClient->requests[0]['url'], '/events/event-id'),
    'Preparing an edit must address the selected Google event directly.'
);

$writeClient = new FakeHttpClient([
    response(200, ['id' => 'created-id', 'iCalUID' => 'created@example.com', 'etag' => '"new"']),
    response(200, ['id' => 'created-id', 'iCalUID' => 'created@example.com', 'etag' => '"updated"']),
    response(204)
]);
$provider = new GoogleCalendarProvider($writeClient, 'access-token');
$created = $provider->createEvent('owner@example.com', [
    'summary'      => 'Test',
    'allDay'       => false,
    'start'        => '2026-07-20T10:00:00+02:00',
    'end'          => '2026-07-20T11:00:00+02:00',
    'location'     => 'Berlin',
    'status'       => 'TENTATIVE',
    'transparency' => 'TRANSPARENT',
    'reminder'     => [
        'mode'               => 'custom',
        'minutesBeforeStart' => 30
    ]
]);
assertSameValue('created-id', $created['eventReference'], 'The created Google event ID must be returned.');
assertSameValue('POST', $writeClient->requests[0]['method'], 'Events must be created via POST.');
$createBody = json_decode($writeClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
assertSameValue('Test', $createBody['summary'], 'The event summary must be sent.');
assertSameValue('tentative', $createBody['status'], 'Google event status must be written using provider values.');
assertSameValue(
    'transparent',
    $createBody['transparency'],
    'Google event availability must be written using the Calendar API transparency field.'
);
assertSameValue(false, $createBody['reminders']['useDefault'], 'Custom Google reminders must override calendar defaults.');
assertSameValue(
    [['method' => 'popup', 'minutes' => 30]],
    $createBody['reminders']['overrides'],
    'Custom Google reminders must be written as one popup override.'
);

$googleMultipleWriteClient = new FakeHttpClient([
    response(200, ['id' => 'multiple-reminder-id', 'iCalUID' => 'multiple-reminder@example.com'])
]);
(new GoogleCalendarProvider($googleMultipleWriteClient, 'access-token'))->createEvent('owner@example.com', [
    'summary'  => 'Multiple reminders',
    'allDay'   => false,
    'start'    => '2026-07-20T10:00:00+02:00',
    'end'      => '2026-07-20T11:00:00+02:00',
    'reminder' => [
        'mode'      => 'multiple',
        'reminders' => [
            ['minutesBeforeStart' => 10],
            ['minutesBeforeStart' => 60]
        ]
    ]
]);
$googleMultipleWriteBody = json_decode(
    $googleMultipleWriteClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue(
    [
        ['method' => 'popup', 'minutes' => 10],
        ['method' => 'popup', 'minutes' => 60]
    ],
    $googleMultipleWriteBody['reminders']['overrides'],
    'Multiple Google reminders must be written as separate popup overrides.'
);

$googleDefaultReminderClient = new FakeHttpClient([
    response(200, ['id' => 'default-reminder-id', 'iCalUID' => 'default-reminder@example.com'])
]);
(new GoogleCalendarProvider($googleDefaultReminderClient, 'access-token'))->createEvent('owner@example.com', [
    'summary'  => 'Calendar default reminder',
    'allDay'   => false,
    'start'    => '2026-07-20T12:00:00+02:00',
    'end'      => '2026-07-20T13:00:00+02:00',
    'reminder' => ['mode' => 'default']
]);
$googleDefaultReminderBody = json_decode(
    $googleDefaultReminderClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue(
    ['useDefault' => true],
    $googleDefaultReminderBody['reminders'],
    'Google calendar-default reminders must be written explicitly when selected.'
);

$recurringCreateClient = new FakeHttpClient([
    response(200, ['id' => 'series-id', 'iCalUID' => 'series@example.com', 'etag' => '"series"'])
]);
$recurringProvider = new GoogleCalendarProvider($recurringCreateClient, 'access-token');
$recurringProvider->createEvent('owner@example.com', [
    'summary'    => 'Weekly meeting',
    'allDay'     => false,
    'start'      => '2026-10-19T08:00:00Z',
    'end'        => '2026-10-19T09:00:00Z',
    'timezone'   => 'Europe/Berlin',
    'reminder'   => [
        'mode'               => 'custom',
        'minutesBeforeStart' => 30
    ],
    'recurrence' => [
        'frequency' => 'weekly',
        'interval'  => 2,
        'byDay'     => ['TH', 'MO'],
        'endMode'   => 'until',
        'until'     => '2026-11-30'
    ]
]);
$recurringCreateBody = json_decode(
    $recurringCreateClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue(
    '2026-10-19T10:00:00+02:00',
    $recurringCreateBody['start']['dateTime'],
    'Recurring Google events must preserve the local wall-clock start time.'
);
assertSameValue(
    'Europe/Berlin',
    $recurringCreateBody['start']['timeZone'],
    'Recurring Google events must send an explicit IANA timezone.'
);
assertSameValue(
    ['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;UNTIL=20261130T090000Z'],
    $recurringCreateBody['recurrence'],
    'Recurring Google events must serialize normalized recurrence settings as RFC 5545 RRULE lines.'
);

$allDayRecurringClient = new FakeHttpClient([
    response(200, ['id' => 'all-day-series', 'iCalUID' => 'all-day-series@example.com', 'etag' => '"series"'])
]);
(new GoogleCalendarProvider($allDayRecurringClient, 'access-token'))->createEvent('owner@example.com', [
    'summary'    => 'Yearly day',
    'allDay'     => true,
    'start'      => '2026-08-14',
    'end'        => '2026-08-15',
    'recurrence' => [
        'frequency' => 'yearly',
        'interval'  => 1,
        'endMode'   => 'count',
        'count'     => 3
    ]
]);
$allDayRecurringBody = json_decode($allDayRecurringClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
assertSameValue(
    ['RRULE:FREQ=YEARLY;COUNT=3'],
    $allDayRecurringBody['recurrence'],
    'All-day recurring Google events must support count-based series without a timezone.'
);

$googleSingleToSeriesClient = new FakeHttpClient([
    response(200, [
        'id'      => 'single-to-series-id',
        'iCalUID' => 'single-to-series@example.com',
        'etag'    => '"single-to-series"'
    ])
]);
(new GoogleCalendarProvider($googleSingleToSeriesClient, 'access-token'))->updateEvent(
    'owner@example.com',
    'single-to-series-id',
    '"single-before"',
    'single-to-series@example.com',
    [
        'summary'    => 'Converted recurring event',
        'allDay'     => false,
        'start'      => '2026-08-17T08:00:00Z',
        'end'        => '2026-08-17T09:00:00Z',
        'timezone'   => 'Europe/Berlin',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 1,
            'byDay'     => ['MO'],
            'endMode'   => 'count',
            'count'     => 4
        ]
    ]
);
assertSameValue(
    'PATCH',
    $googleSingleToSeriesClient->requests[0]['method'],
    'Google single events must be convertible to recurring series via PATCH.'
);
assertSameValue(
    '"single-before"',
    $googleSingleToSeriesClient->requests[0]['headers']['If-Match'] ?? '',
    'Google recurrence conversion must retain optimistic locking.'
);
$googleSingleToSeriesBody = json_decode(
    $googleSingleToSeriesClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue(
    '2026-08-17T10:00:00+02:00',
    $googleSingleToSeriesBody['start']['dateTime'],
    'Converting a Google single event to a series must preserve its local wall-clock time.'
);
assertSameValue(
    'Europe/Berlin',
    $googleSingleToSeriesBody['start']['timeZone'],
    'Converting a Google single event to a series must retain its timezone.'
);
assertSameValue(
    ['RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=4'],
    $googleSingleToSeriesBody['recurrence'],
    'Converting a Google single event must submit the requested recurrence rule.'
);

$googleSeriesToSingleClient = new FakeHttpClient([
    response(200, [
        'id'      => 'series-to-single-id',
        'iCalUID' => 'series-to-single@example.com',
        'etag'    => '"series-to-single"'
    ])
]);
(new GoogleCalendarProvider($googleSeriesToSingleClient, 'access-token'))->updateEvent(
    'owner@example.com',
    'series-to-single-id',
    '"series-before-single"',
    'series-to-single@example.com',
    [
        'summary'    => 'Converted single event',
        'allDay'     => false,
        'start'      => '2026-08-17T08:00:00Z',
        'end'        => '2026-08-17T09:00:00Z',
        'timezone'   => 'Europe/Berlin',
        'recurrence' => null
    ],
    [
        'recurrenceType'  => 'master',
        'seriesId'        => 'series-to-single-id',
        'recurring'       => true,
        'canUpdateSeries' => true,
        'writeScope'      => 'series'
    ]
);
assertSameValue(
    'PATCH',
    $googleSeriesToSingleClient->requests[0]['method'],
    'Google recurring series must be convertible to a single event via PATCH.'
);
assertSameValue(
    '"series-before-single"',
    $googleSeriesToSingleClient->requests[0]['headers']['If-Match'] ?? '',
    'Google series-to-single conversion must retain optimistic locking.'
);
$googleSeriesToSingleBody = json_decode(
    $googleSeriesToSingleClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertTrueValue(
    array_key_exists('recurrence', $googleSeriesToSingleBody),
    'Google series-to-single conversion must explicitly submit the recurrence property.'
);
assertSameValue(
    [],
    $googleSeriesToSingleBody['recurrence'],
    'Google series-to-single conversion must clear the recurrence array.'
);
assertSameValue(
    '2026-08-17T10:00:00+02:00',
    $googleSeriesToSingleBody['start']['dateTime'],
    'Converting a Google series to a single event must preserve its local wall-clock time.'
);
$provider->updateEvent(
    'owner@example.com',
    $created['resourceUrl'],
    '"new"',
    'created@example.com',
    [
        'summary'      => 'Updated',
        'status'       => 'CONFIRMED',
        'transparency' => 'OPAQUE'
    ]
);
assertSameValue('PATCH', $writeClient->requests[1]['method'], 'Events must be updated without replacing unrelated Google fields.');
assertSameValue('"new"', $writeClient->requests[1]['headers']['If-Match'], 'Updates must use the ETag for conflict detection.');
$updateBody = json_decode($writeClient->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
assertSameValue('confirmed', $updateBody['status'], 'Google status updates must preserve the provider-neutral selection.');
assertSameValue('opaque', $updateBody['transparency'], 'Google availability updates must preserve the provider-neutral selection.');
assertTrueValue(
    $provider->deleteEvent('owner@example.com', $created['resourceUrl'], '"updated"'),
    'Event deletion must return true after HTTP 204.'
);
assertSameValue('DELETE', $writeClient->requests[2]['method'], 'Events must be deleted via DELETE.');

$occurrenceWriteClient = new FakeHttpClient([
    response(200, ['id' => 'instance-id', 'iCalUID' => 'series@example.com', 'etag' => '"occurrence-updated"']),
    response(200, ['id' => 'instance-id', 'status' => 'cancelled'])
]);
$occurrenceProvider = new GoogleCalendarProvider($occurrenceWriteClient, 'access-token');
$googleOccurrence = [
    'recurrenceType'      => 'occurrence',
    'seriesId'            => 'series-id',
    'occurrenceId'        => 'instance-id',
    'originalStart'       => '2026-07-20T09:00:00+02:00',
    'recurring'           => true,
    'canUpdateOccurrence' => true,
    'canDeleteOccurrence' => true,
    'canUpdateFollowing'  => true,
    'canUpdateSeries'     => true,
    'canDeleteSeries'     => true
];
$occurrenceProvider->updateEvent(
    'owner@example.com',
    'https://www.googleapis.com/calendar/v3/calendars/owner%40example.com/events/instance-id',
    '',
    'series@example.com',
    ['summary' => 'Changed occurrence'],
    $googleOccurrence
);
assertTrueValue(
    str_ends_with($occurrenceWriteClient->requests[0]['url'], '/events/instance-id'),
    'Google occurrence updates must target the concrete occurrence ID.'
);
assertTrueValue(
    $occurrenceProvider->deleteEvent(
        'owner@example.com',
        'https://www.googleapis.com/calendar/v3/calendars/owner%40example.com/events/instance-id',
        '',
        '',
        $googleOccurrence
    ),
    'Google occurrences must be deletable by their concrete occurrence ID.'
);
assertSameValue(
    'PATCH',
    $occurrenceWriteClient->requests[1]['method'],
    'Deleting one Google occurrence must cancel the concrete instance instead of deleting the parent series.'
);
assertSameValue(
    ['status' => 'cancelled'],
    json_decode($occurrenceWriteClient->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR),
    'Deleting one Google occurrence must create a cancelled recurring-event exception.'
);
assertTrueValue(
    str_ends_with($occurrenceWriteClient->requests[1]['url'], '/events/instance-id'),
    'Cancelling one Google occurrence must target the concrete occurrence ID.'
);

$seriesDeleteClient = new FakeHttpClient([response(204)]);
$seriesDeleteProvider = new GoogleCalendarProvider($seriesDeleteClient, 'access-token');
$seriesDeleteIdentity = $googleOccurrence;
$seriesDeleteIdentity['writeScope'] = 'series';
assertTrueValue(
    $seriesDeleteProvider->deleteEvent(
        'owner@example.com',
        'https://www.googleapis.com/calendar/v3/calendars/owner%40example.com/events/instance-id',
        '"occurrence-etag"',
        '',
        $seriesDeleteIdentity
    ),
    'A verified Google occurrence must allow deleting its complete recurring series.'
);
assertTrueValue(
    str_ends_with($seriesDeleteClient->requests[0]['url'], '/events/series-id'),
    'Deleting a complete Google series must target the parent recurring event ID.'
);
assertTrueValue(
    !array_key_exists('If-Match', $seriesDeleteClient->requests[0]['headers']),
    'A concrete occurrence ETag must not be sent when deleting the parent recurring series.'
);

$seriesReadClient = new FakeHttpClient([
    response(200, [
        'id'         => 'series-id',
        'iCalUID'    => 'series@example.com',
        'etag'       => '"series-etag"',
        'summary'    => 'Series meeting',
        'status'     => 'confirmed',
        'start'      => ['dateTime' => '2026-07-20T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'end'        => ['dateTime' => '2026-07-20T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'recurrence' => ['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;COUNT=6']
    ])
]);
$seriesParent = (new GoogleCalendarProvider($seriesReadClient, 'access-token'))->getRecurringSeries(
    'owner@example.com',
    'series-id'
);
assertSameValue('master', $seriesParent['recurrenceType'], 'The Google recurring parent must be normalized as a master event.');
assertSameValue(true, $seriesParent['canUpdateSeries'], 'The Google recurring parent must advertise series update support.');
assertSameValue(true, $seriesParent['recurrenceEditable'], 'Supported Google RRULEs must be editable in OpenCalendar.');
assertSameValue(
    [
        'frequency' => 'WEEKLY',
        'interval'  => 2,
        'endMode'   => 'count',
        'byDay'     => ['MO', 'TH'],
        'count'     => 6
    ],
    $seriesParent['recurrenceSettings'],
    'The recurring parent RRULE must be parsed into provider-neutral editor settings.'
);
assertTrueValue(
    str_ends_with($seriesReadClient->requests[0]['url'], '/events/series-id'),
    'Loading a recurring series must address the Google parent event directly.'
);

$followingReadClient = new FakeHttpClient([
    response(200, [
        'id'         => 'series-id',
        'iCalUID'    => 'series@example.com',
        'etag'       => '"series-etag"',
        'summary'    => 'Series meeting',
        'status'     => 'confirmed',
        'start'      => ['dateTime' => '2026-07-20T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'end'        => ['dateTime' => '2026-07-20T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'recurrence' => ['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;COUNT=6']
    ]),
    response(200, [
        'id'                => 'target-id',
        'iCalUID'           => 'series@example.com',
        'etag'              => '"target-etag"',
        'summary'           => 'Series meeting',
        'status'            => 'confirmed',
        'recurringEventId'  => 'series-id',
        'originalStartTime' => ['dateTime' => '2026-08-03T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'start'             => ['dateTime' => '2026-08-03T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'end'               => ['dateTime' => '2026-08-03T10:00:00+02:00', 'timeZone' => 'Europe/Berlin']
    ]),
    response(200, [
        'items' => array_map(
            static fn (string $start, int $index): array => [
                'id'                => 'instance-' . $index,
                'recurringEventId'  => 'series-id',
                'originalStartTime' => ['dateTime' => $start, 'timeZone' => 'Europe/Berlin']
            ],
            [
                '2026-07-20T09:00:00+02:00',
                '2026-07-23T09:00:00+02:00',
                '2026-08-03T09:00:00+02:00',
                '2026-08-06T09:00:00+02:00',
                '2026-08-17T09:00:00+02:00',
                '2026-08-20T09:00:00+02:00'
            ],
            [1, 2, 3, 4, 5, 6]
        )
    ])
]);
$followingEvent = (new GoogleCalendarProvider($followingReadClient, 'access-token'))->getRecurringFollowing(
    'owner@example.com',
    'series-id',
    'target-id',
    '2026-08-03T09:00:00+02:00'
);
assertSameValue('following', $followingEvent['writeScope'], 'Following edits must carry their dedicated recurrence write scope.');
assertSameValue(true, $followingEvent['canUpdateFollowing'], 'A verified Google target must advertise following-update support.');
assertSameValue('target-id', $followingEvent['eventReference'], 'Following edits must retain the concrete target occurrence ID.');
assertSameValue('2026-08-03T09:00:00+02:00', $followingEvent['start'], 'Following edits must start at the selected occurrence.');
assertSameValue(4, $followingEvent['recurrenceSettings']['count'], 'Count-based following edits must retain only the target and later instances.');
assertTrueValue(
    str_contains($followingReadClient->requests[2]['url'], '/events/series-id/instances?')
        && str_contains($followingReadClient->requests[2]['url'], 'showDeleted=true'),
    'Count-based following edits must verify all Google instances including cancelled exceptions.'
);

$followingWriteClient = new FakeHttpClient([
    response(200, [
        'id'                => 'target-id',
        'iCalUID'           => 'series@example.com',
        'etag'              => '"target-etag"',
        'summary'           => 'Series meeting',
        'status'            => 'confirmed',
        'recurringEventId'  => 'series-id',
        'originalStartTime' => ['dateTime' => '2026-08-03T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'start'             => ['dateTime' => '2026-08-03T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'end'               => ['dateTime' => '2026-08-03T10:00:00+02:00', 'timeZone' => 'Europe/Berlin']
    ]),
    response(200, [
        'id'         => 'series-id',
        'iCalUID'    => 'series@example.com',
        'etag'       => '"series-etag"',
        'summary'    => 'Series meeting',
        'location'   => 'Old room',
        'status'     => 'confirmed',
        'attendees'  => [['email' => 'guest@example.com']],
        'start'      => ['dateTime' => '2026-07-20T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'end'        => ['dateTime' => '2026-07-20T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'recurrence' => ['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;COUNT=6']
    ]),
    response(200, ['id' => 'series-id', 'etag' => '"trimmed-etag"']),
    response(200, ['id' => 'new-series-id', 'iCalUID' => 'new-series@example.com', 'etag' => '"new-series-etag"'])
]);
$followingWriteProvider = new GoogleCalendarProvider($followingWriteClient, 'access-token');
$followingIdentity = $googleOccurrence;
$followingIdentity['occurrenceId'] = 'target-id';
$followingIdentity['originalStart'] = '2026-08-03T09:00:00+02:00';
$followingIdentity['writeScope'] = 'following';
$followingWriteProvider->updateEvent(
    'owner@example.com',
    'https://www.googleapis.com/calendar/v3/calendars/owner%40example.com/events/target-id',
    '"target-etag"',
    'series@example.com',
    [
        'summary'    => 'Changed from here',
        'location'   => 'New room',
        'allDay'     => false,
        'start'      => '2026-08-03T10:00:00+02:00',
        'end'        => '2026-08-03T11:00:00+02:00',
        'timezone'   => 'Europe/Berlin',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 2,
            'byDay'     => ['MO', 'TH'],
            'endMode'   => 'count',
            'count'     => 4
        ]
    ],
    $followingIdentity
);
assertSameValue('PATCH', $followingWriteClient->requests[2]['method'], 'Following updates must first trim the original Google parent series.');
assertTrueValue(
    str_ends_with($followingWriteClient->requests[2]['url'], '/events/series-id'),
    'Following updates must trim the original parent event rather than the target occurrence.'
);
$trimmedFollowingBody = json_decode($followingWriteClient->requests[2]['body'], true, 512, JSON_THROW_ON_ERROR);
assertSameValue(
    ['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;UNTIL=20260803T065959Z'],
    $trimmedFollowingBody['recurrence'],
    'The original series must end immediately before the selected target occurrence.'
);
assertSameValue('POST', $followingWriteClient->requests[3]['method'], 'Following updates must create a new recurring Google event for the changed tail.');
$newFollowingBody = json_decode($followingWriteClient->requests[3]['body'], true, 512, JSON_THROW_ON_ERROR);
assertSameValue('Changed from here', $newFollowingBody['summary'], 'The new tail series must contain the requested changes.');
assertSameValue([['email' => 'guest@example.com']], $newFollowingBody['attendees'], 'The split series must preserve supported parent event metadata.');
assertSameValue(['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;COUNT=4'], $newFollowingBody['recurrence'], 'The new tail series must use the remaining count from the selected target onward.');

$followingDeleteClient = new FakeHttpClient([
    response(200, [
        'id'                => 'target-id',
        'iCalUID'           => 'series@example.com',
        'etag'              => '"target-etag"',
        'summary'           => 'Series meeting',
        'recurringEventId'  => 'series-id',
        'originalStartTime' => ['dateTime' => '2026-08-03T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'start'             => ['dateTime' => '2026-08-03T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'end'               => ['dateTime' => '2026-08-03T10:00:00+02:00', 'timeZone' => 'Europe/Berlin']
    ]),
    response(200, [
        'id'         => 'series-id',
        'iCalUID'    => 'series@example.com',
        'etag'       => '"series-etag"',
        'summary'    => 'Series meeting',
        'start'      => ['dateTime' => '2026-07-20T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'end'        => ['dateTime' => '2026-07-20T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'recurrence' => ['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;COUNT=6']
    ]),
    response(200, ['id' => 'series-id', 'etag' => '"trimmed-etag"'])
]);
$followingDeleteProvider = new GoogleCalendarProvider($followingDeleteClient, 'access-token');
$followingDeleteIdentity = $followingIdentity;
assertTrueValue(
    $followingDeleteProvider->deleteEvent(
        'owner@example.com',
        'https://www.googleapis.com/calendar/v3/calendars/owner%40example.com/events/target-id',
        '"target-etag"',
        '',
        $followingDeleteIdentity
    ),
    'Google recurring events must support deleting the selected occurrence and all following occurrences.'
);
assertSameValue(
    'PATCH',
    $followingDeleteClient->requests[2]['method'],
    'Deleting this and following Google occurrences must trim the original recurring parent.'
);
assertTrueValue(
    str_ends_with($followingDeleteClient->requests[2]['url'], '/events/series-id'),
    'This-and-following deletion must modify the recurring parent instead of cancelling only the target occurrence.'
);
assertSameValue(
    ['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;UNTIL=20260803T065959Z'],
    json_decode($followingDeleteClient->requests[2]['body'], true, 512, JSON_THROW_ON_ERROR)['recurrence'],
    'This-and-following deletion must end the parent immediately before the selected occurrence.'
);

$followingDeleteFirstClient = new FakeHttpClient([
    response(200, [
        'id'                => 'first-id',
        'iCalUID'           => 'series@example.com',
        'etag'              => '"first-etag"',
        'summary'           => 'Series meeting',
        'recurringEventId'  => 'series-id',
        'originalStartTime' => ['dateTime' => '2026-07-20T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'start'             => ['dateTime' => '2026-07-20T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'end'               => ['dateTime' => '2026-07-20T10:00:00+02:00', 'timeZone' => 'Europe/Berlin']
    ]),
    response(200, [
        'id'         => 'series-id',
        'iCalUID'    => 'series@example.com',
        'etag'       => '"series-etag"',
        'summary'    => 'Series meeting',
        'start'      => ['dateTime' => '2026-07-20T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'end'        => ['dateTime' => '2026-07-20T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=6']
    ]),
    response(204)
]);
$followingDeleteFirstProvider = new GoogleCalendarProvider($followingDeleteFirstClient, 'access-token');
$followingDeleteFirstIdentity = $googleOccurrence;
$followingDeleteFirstIdentity['occurrenceId'] = 'first-id';
$followingDeleteFirstIdentity['writeScope'] = 'following';
assertTrueValue(
    $followingDeleteFirstProvider->deleteEvent(
        'owner@example.com',
        'https://www.googleapis.com/calendar/v3/calendars/owner%40example.com/events/first-id',
        '"first-etag"',
        '',
        $followingDeleteFirstIdentity
    ),
    'Deleting this and following from the first occurrence must delete the complete Google series.'
);
assertSameValue(
    'DELETE',
    $followingDeleteFirstClient->requests[2]['method'],
    'Deleting from the first occurrence onward must remove the recurring parent.'
);
assertTrueValue(
    str_ends_with($followingDeleteFirstClient->requests[2]['url'], '/events/series-id'),
    'Deleting from the first occurrence onward must target the recurring parent ID.'
);

$seriesWriteClient = new FakeHttpClient([
    response(200, ['id' => 'series-id', 'iCalUID' => 'series@example.com', 'etag' => '"series-updated"'])
]);
$seriesWriteProvider = new GoogleCalendarProvider($seriesWriteClient, 'access-token');
$seriesWriteIdentity = [
    'recurrenceType'  => 'master',
    'seriesId'        => 'series-id',
    'recurring'       => true,
    'canUpdateSeries' => true,
    'canDeleteSeries' => true,
    'writeScope'      => 'series'
];
$seriesWriteProvider->updateEvent(
    'owner@example.com',
    'https://www.googleapis.com/calendar/v3/calendars/owner%40example.com/events/series-id',
    '"series-etag"',
    'series@example.com',
    [
        'summary'    => 'Changed series',
        'allDay'     => false,
        'start'      => '2026-07-20T11:00:00+02:00',
        'end'        => '2026-07-20T12:00:00+02:00',
        'timezone'   => 'Europe/Berlin',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 1,
            'byDay'     => ['MO', 'WE'],
            'endMode'   => 'count',
            'count'     => 4
        ]
    ],
    $seriesWriteIdentity
);
assertSameValue('PATCH', $seriesWriteClient->requests[0]['method'], 'Complete Google series updates must use PATCH semantics.');
assertTrueValue(
    str_ends_with($seriesWriteClient->requests[0]['url'], '/events/series-id'),
    'Updating a complete Google series must target the parent recurring event ID.'
);
assertSameValue(
    '"series-etag"',
    $seriesWriteClient->requests[0]['headers']['If-Match'],
    'Complete series updates must use the parent event ETag for conflict detection.'
);
$seriesWriteBody = json_decode($seriesWriteClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
assertSameValue(
    ['RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4'],
    $seriesWriteBody['recurrence'],
    'Complete series updates must serialize the edited recurrence rule on the parent event.'
);

$mismatchedOccurrenceClient = new FakeHttpClient([]);
try {
    (new GoogleCalendarProvider($mismatchedOccurrenceClient, 'access-token'))->deleteEvent(
        'owner@example.com',
        'series-id',
        '',
        '',
        $googleOccurrence
    );
    throw new RuntimeException('A mismatched Google occurrence identity was accepted.');
} catch (RuntimeException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'identity does not match'),
        'Google occurrence writes must verify the concrete event ID.'
    );
}
assertSameValue(0, count($mismatchedOccurrenceClient->requests), 'Mismatched occurrence identities must not issue a request.');

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
assertSameValue(true, $msCalendars[0]['capabilities']['createRecurrence'], 'Editable Microsoft calendars must advertise recurrence creation support.');
assertSameValue(true, $msCalendars[0]['capabilities']['updateRecurrence'], 'Editable Microsoft calendars must advertise recurrence conversion support.');
assertSameValue(true, $msCalendars[0]['capabilities']['updateOccurrence'], 'Editable Microsoft calendars must advertise recurring occurrence update support.');
assertSameValue(true, $msCalendars[0]['capabilities']['deleteOccurrence'], 'Editable Microsoft calendars must advertise recurring occurrence delete support.');
assertSameValue(true, $msCalendars[0]['capabilities']['updateFollowing'], 'Editable Microsoft calendars must advertise this-and-following update support.');
assertSameValue(true, $msCalendars[0]['capabilities']['updateSeries'], 'Editable Microsoft calendars must advertise recurring series update support.');
assertSameValue(true, $msCalendars[0]['capabilities']['deleteSeries'], 'Editable Microsoft calendars must advertise recurring series delete support.');
assertSameValue(true, $msCalendars[0]['capabilities']['createWithDefaultReminder'], 'Microsoft calendars must allow the server default when creating events.');
assertSameValue(1, $msCalendars[0]['capabilities']['maxReminders'], 'Microsoft calendars must advertise the single reminder supported by the shared model.');
assertSameValue(false, $msCalendars[0]['capabilities']['writeStatus'], 'Microsoft calendars must not advertise unsupported provider-neutral status writes.');
assertSameValue(true, $msCalendars[0]['capabilities']['writeTransparency'], 'Writable Microsoft calendars must advertise provider-neutral transparency writes.');
assertSameValue(CalendarEventState::STATUS_CONFIRMED, $msCalendars[0]['defaultStatus'], 'Microsoft calendars must advertise their provider-neutral default status.');
assertSameValue(CalendarEventState::TRANSP_OPAQUE, $msCalendars[0]['defaultTransparency'], 'Microsoft timed events must default to busy.');
assertSameValue(CalendarEventState::TRANSP_TRANSPARENT, $msCalendars[0]['defaultAllDayTransparency'], 'Microsoft all-day events must retain the provider free default.');
assertTrueValue(!isset($msCalendars[0]['capabilities']['useDefaultReminder']), 'Microsoft events must not advertise a persistent calendar-default reminder mode.');
assertSameValue(false, $msCalendars[2]['capabilities']['create'], 'Read-only Microsoft calendars must remain read-only.');
assertSameValue(false, $msCalendars[2]['capabilities']['createRecurrence'], 'Read-only Microsoft calendars must not advertise recurrence creation support.');
assertSameValue(false, $msCalendars[2]['capabilities']['updateRecurrence'], 'Read-only Microsoft calendars must not advertise recurrence conversion support.');
assertSameValue(false, $msCalendars[2]['capabilities']['updateOccurrence'], 'Read-only Microsoft calendars must not advertise recurring occurrence update support.');
assertSameValue(false, $msCalendars[2]['capabilities']['deleteOccurrence'], 'Read-only Microsoft calendars must not advertise recurring occurrence delete support.');
assertSameValue(false, $msCalendars[2]['capabilities']['updateFollowing'], 'Read-only Microsoft calendars must not advertise this-and-following update support.');
assertSameValue(false, $msCalendars[2]['capabilities']['updateSeries'], 'Read-only Microsoft calendars must not advertise recurring series update support.');
assertSameValue(false, $msCalendars[2]['capabilities']['deleteSeries'], 'Read-only Microsoft calendars must not advertise recurring series delete support.');
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

$msRepeatedPageUrl = 'https://graph.microsoft.com/v1.0/me/calendars?$skiptoken=repeated';
$msRepeatedPageClient = new FakeHttpClient([
    response(200, ['value' => [], '@odata.nextLink' => $msRepeatedPageUrl]),
    response(200, ['value' => [], '@odata.nextLink' => $msRepeatedPageUrl])
]);
try {
    (new MicrosoftCalendarProvider($msRepeatedPageClient, 'ms-access-token'))->getCalendars();
    throw new RuntimeException('A repeated Microsoft pagination link was accepted.');
} catch (MicrosoftCalendarProviderException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'repeated pagination link'),
        'Repeated Microsoft pagination links must stop pagination.'
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
                'showAs'      => 'free',
                'start'       => ['dateTime' => '2026-07-20T00:00:00.0000000', 'timeZone' => 'UTC'],
                'end'         => ['dateTime' => '2026-07-21T00:00:00.0000000', 'timeZone' => 'UTC'],
                'type'        => 'singleInstance'
            ],
            [
                'id'                         => 'instance/id+1',
                'iCalUId'                    => 'series@example.com',
                '@odata.etag'                => 'W/"etag-2"',
                'subject'                    => 'Teams meeting',
                'body'                       => ['contentType' => 'text', 'content' => 'Agenda'],
                'location'                   => ['displayName' => 'Berlin'],
                'start'                      => ['dateTime' => '2026-07-20T10:00:00.1234567', 'timeZone' => 'UTC'],
                'end'                        => ['dateTime' => '2026-07-20T11:00:00.1234567', 'timeZone' => 'UTC'],
                'type'                       => 'occurrence',
                'seriesMasterId'             => 'series-master',
                'showAs'                     => 'tentative',
                'isReminderOn'               => true,
                'reminderMinutesBeforeStart' => 45,
                'isOnlineMeeting'            => true,
                'webLink'                    => 'https://outlook.office.com/calendar/item/1'
            ],
            [
                'id'             => 'exception-id',
                'iCalUId'        => 'series-exception@example.com',
                '@odata.etag'    => 'W/"etag-3"',
                'subject'        => 'Moved series occurrence',
                'start'          => ['dateTime' => '2026-07-20T14:00:00', 'timeZone' => 'UTC'],
                'end'            => ['dateTime' => '2026-07-20T15:00:00', 'timeZone' => 'UTC'],
                'type'           => 'exception',
                'seriesMasterId' => 'series-master',
                'originalStart'  => '2026-07-20T13:00:00Z'
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
assertSameValue(3, count($msEvents), 'Cancelled Microsoft events must be excluded.');
assertSameValue(true, $msEvents[0]['allDay'], 'Microsoft all-day events must retain their exclusive end date.');
assertSameValue('2026-07-21', $msEvents[0]['end'], 'Microsoft all-day end dates must remain exclusive.');
assertSameValue('CONFIRMED', $msEvents[0]['status'], 'Microsoft non-cancelled events must use the provider-neutral confirmed status.');
assertSameValue('TRANSPARENT', $msEvents[0]['transparency'], 'Microsoft free availability must normalize to RFC transparent.');
assertSameValue('CONFIRMED', $msEvents[1]['status'], 'Microsoft tentative availability must not be confused with RFC event status.');
assertSameValue('OPAQUE', $msEvents[1]['transparency'], 'Microsoft tentative availability must continue to block time.');
assertSameValue(true, $msEvents[1]['recurring'], 'Microsoft occurrences must remain marked as recurring.');
assertSameValue('occurrence', $msEvents[1]['recurrenceType'], 'Microsoft occurrences must use the shared recurrence type.');
assertSameValue('series-master', $msEvents[1]['seriesId'], 'Microsoft series master IDs must be retained separately.');
assertSameValue('instance/id+1', $msEvents[1]['occurrenceId'], 'Microsoft occurrence IDs must be retained separately.');
assertSameValue('', $msEvents[1]['recurrenceId'], 'Microsoft series IDs must not be exposed as RFC recurrence IDs.');
assertSameValue(
    '2026-07-20T10:00:00+00:00',
    $msEvents[1]['originalStart'],
    'Microsoft calendarView occurrences must derive their unchanged original start when Graph omits it.'
);
assertSameValue(true, $msEvents[1]['canUpdateOccurrence'], 'Microsoft occurrences must advertise individual update support.');
assertSameValue(true, $msEvents[1]['canDeleteOccurrence'], 'Microsoft occurrences must advertise individual delete support.');
assertSameValue(true, $msEvents[1]['canUpdateFollowing'], 'Microsoft occurrences with a verified series start must advertise following-update support.');
assertSameValue('custom', $msEvents[1]['reminder']['mode'], 'Microsoft reminders must use the shared reminder model.');
assertSameValue(45, $msEvents[1]['reminder']['minutesBeforeStart'], 'Microsoft reminder offsets must be retained.');
assertSameValue(true, $msEvents[1]['onlineMeeting'], 'Microsoft online-meeting state must be exposed to the calendar view.');
assertSameValue('exception', $msEvents[2]['recurrenceType'], 'Modified Microsoft occurrences must be normalized as exceptions.');
assertSameValue('2026-07-20T13:00:00Z', $msEvents[2]['originalStart'], 'Microsoft exception original starts must be retained when Graph supplies them.');
assertSameValue(true, $msEvents[2]['canUpdateOccurrence'], 'Microsoft exceptions must remain individually editable.');
assertSameValue(true, $msEvents[2]['canDeleteOccurrence'], 'Microsoft exceptions must remain individually deletable.');
assertSameValue(true, $msEvents[2]['canUpdateFollowing'], 'Microsoft exceptions with an original start must support following updates.');
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

$msDirectEditClient = new FakeHttpClient([
    response(200, [
        'id'                    => 'direct-edit-id',
        'iCalUId'               => 'direct-edit@example.com',
        '@odata.etag'           => 'W/"direct-edit-etag"',
        'subject'               => 'Direct edit',
        'start'                 => ['dateTime' => '2026-07-20T10:00:00', 'timeZone' => 'UTC'],
        'end'                   => ['dateTime' => '2026-07-20T11:00:00', 'timeZone' => 'UTC'],
        'type'                  => 'singleInstance',
        'isAllDay'              => false,
        'isCancelled'           => false,
        'originalStartTimeZone' => 'UTC'
    ])
]);
$msDirectEdit = (new MicrosoftCalendarProvider($msDirectEditClient, 'ms-access-token'))->getEventForEdit(
    'AQMk-primary',
    [
        'eventReference' => 'direct-edit-id',
        'uid'            => 'direct-edit@example.com',
        'startTimestamp' => (new DateTimeImmutable('2026-07-20T10:00:00Z'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-07-20T11:00:00Z'))->getTimestamp()
    ]
);
assertSameValue('direct-edit-id', $msDirectEdit['eventReference'], 'Microsoft direct edit lookup must retain the immutable provider event ID.');
assertSameValue('W/"direct-edit-etag"', $msDirectEdit['etag'], 'Microsoft direct edit lookup must use the current Graph ETag.');
assertSameValue(1, count($msDirectEditClient->requests), 'Microsoft direct edit lookup must request only the selected event when its immutable ID is current.');
assertTrueValue(
    str_contains($msDirectEditClient->requests[0]['url'], '/me/calendars/AQMk-primary/events/direct-edit-id'),
    'Microsoft direct edit lookup must use the provider event endpoint.'
);
assertTrueValue(
    str_contains($msDirectEditClient->requests[0]['headers']['Prefer'] ?? '', 'IdType="ImmutableId"'),
    'Microsoft direct edit lookup must request immutable Graph IDs.'
);

$msFallbackEditClient = new FakeHttpClient([
    response(404, [
        'error' => [
            'code'    => 'ErrorItemNotFound',
            'message' => 'The specified object was not found.'
        ]
    ]),
    response(200, [
        'value' => [[
            'id'                    => 'refreshed-occurrence-id',
            'iCalUId'               => 'stable-occurrence@example.com',
            '@odata.etag'           => 'W/"refreshed-etag"',
            'subject'               => 'Refreshed occurrence',
            'start'                 => ['dateTime' => '2026-07-20T10:00:00', 'timeZone' => 'UTC'],
            'end'                   => ['dateTime' => '2026-07-20T11:00:00', 'timeZone' => 'UTC'],
            'type'                  => 'occurrence',
            'seriesMasterId'        => 'series-master-id',
            'isAllDay'              => false,
            'isCancelled'           => false,
            'originalStartTimeZone' => 'UTC'
        ]]
    ])
]);
$msFallbackEdit = (new MicrosoftCalendarProvider($msFallbackEditClient, 'ms-access-token'))->getEventForEdit(
    'AQMk-primary',
    [
        'eventReference' => 'stale-occurrence-id',
        'uid'            => 'stable-occurrence@example.com',
        'seriesId'       => 'series-master-id',
        'originalStart'  => '2026-07-20T10:00:00+00:00',
        'startTimestamp' => (new DateTimeImmutable('2026-07-20T10:00:00Z'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-07-20T11:00:00Z'))->getTimestamp()
    ]
);
assertSameValue('refreshed-occurrence-id', $msFallbackEdit['eventReference'], 'Microsoft lookup must recover a stale occurrence ID through the bounded provider fallback.');
assertSameValue(2, count($msFallbackEditClient->requests), 'Microsoft stale-ID recovery must perform one direct request followed by one bounded calendarView request.');
assertTrueValue(
    str_contains($msFallbackEditClient->requests[1]['url'], '/calendarView?'),
    'Microsoft stale-ID recovery must keep its bounded fallback inside the provider.'
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
    'end'         => '2026-07-20T11:00:00+02:00',
    'reminder'    => [
        'mode'               => 'custom',
        'minutesBeforeStart' => 20
    ]
]);
assertSameValue('created-id', $msCreated['eventReference'], 'The created Microsoft event ID must be returned.');
assertSameValue('POST', $msWriteClient->requests[0]['method'], 'Microsoft events must be created via POST.');
$msCreateBody = json_decode($msWriteClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
assertSameValue('Test', $msCreateBody['subject'], 'Microsoft event subjects must be sent.');
assertSameValue('text', $msCreateBody['body']['contentType'], 'Microsoft event descriptions must be sent as text.');
assertSameValue(true, $msCreateBody['isReminderOn'], 'Custom Microsoft reminders must enable the Graph reminder.');
assertSameValue(20, $msCreateBody['reminderMinutesBeforeStart'], 'Microsoft reminder offsets must be written to Graph.');
assertSameValue('UTC', $msCreateBody['start']['timeZone'], 'Microsoft event writes without a timezone must use unambiguous UTC times.');
assertTrueValue(
    !array_key_exists('showAs', $msCreateBody),
    'Microsoft timed event creation must leave the Graph availability default unchanged.'
);

$msAllDayCreateClient = new FakeHttpClient([
    response(201, [
        'id'          => 'all-day-created-id',
        'iCalUId'     => 'all-day-created@example.com',
        '@odata.etag' => 'W/"all-day-created"'
    ])
]);
(new MicrosoftCalendarProvider($msAllDayCreateClient, 'ms-access-token'))->createEvent('AQMk-primary', [
    'summary' => 'All-day test',
    'allDay'  => true,
    'start'   => '2026-07-20',
    'end'     => '2026-07-21'
]);
$msAllDayCreateBody = json_decode(
    $msAllDayCreateClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue(true, $msAllDayCreateBody['isAllDay'], 'Microsoft all-day event creation must retain all-day mode.');
assertSameValue(
    'free',
    $msAllDayCreateBody['showAs'] ?? '',
    'New Microsoft all-day events must use Outlook\'s free availability default.'
);

$msAllDayBusyClient = new FakeHttpClient([
    response(201, [
        'id'          => 'all-day-busy-id',
        'iCalUId'     => 'all-day-busy@example.com',
        '@odata.etag' => 'W/"all-day-busy"'
    ])
]);
(new MicrosoftCalendarProvider($msAllDayBusyClient, 'ms-access-token'))->createEvent('AQMk-primary', [
    'summary'      => 'Busy all-day test',
    'allDay'       => true,
    'start'        => '2026-07-20',
    'end'          => '2026-07-21',
    'transparency' => 'OPAQUE'
]);
$msAllDayBusyBody = json_decode(
    $msAllDayBusyClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue(
    'busy',
    $msAllDayBusyBody['showAs'] ?? '',
    'Explicit busy availability must override Microsoft\'s free all-day creation default.'
);

$msTransparentClient = new FakeHttpClient([
    response(201, [
        'id'          => 'transparent-id',
        'iCalUId'     => 'transparent@example.com',
        '@odata.etag' => 'W/"transparent"'
    ])
]);
(new MicrosoftCalendarProvider($msTransparentClient, 'ms-access-token'))->createEvent('AQMk-primary', [
    'summary'      => 'Free timed test',
    'allDay'       => false,
    'start'        => '2026-07-20T10:00:00+02:00',
    'end'          => '2026-07-20T11:00:00+02:00',
    'transparency' => 'TRANSPARENT'
]);
$msTransparentBody = json_decode(
    $msTransparentClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue(
    'free',
    $msTransparentBody['showAs'] ?? '',
    'Provider-neutral transparent availability must map to Microsoft Graph free.'
);

try {
    (new MicrosoftCalendarProvider(new FakeHttpClient([]), 'ms-access-token'))->createEvent('AQMk-primary', [
        'summary' => 'Tentative status test',
        'allDay'  => false,
        'start'   => '2026-07-20T10:00:00+02:00',
        'end'     => '2026-07-20T11:00:00+02:00',
        'status'  => 'TENTATIVE'
    ]);
    throw new RuntimeException('Microsoft accepted a provider-neutral tentative event status.');
} catch (InvalidArgumentException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'does not support changing'),
        'Microsoft writes must reject RFC event status values that have no Graph equivalent.'
    );
}

$msAllDayUpdateClient = new FakeHttpClient([
    response(200, [
        'id'          => 'all-day-existing-id',
        'iCalUId'     => 'all-day-existing@example.com',
        '@odata.etag' => 'W/"all-day-existing"'
    ])
]);
(new MicrosoftCalendarProvider($msAllDayUpdateClient, 'ms-access-token'))->updateEvent(
    'AQMk-primary',
    'all-day-existing-id',
    'W/"all-day-before"',
    'all-day-existing@example.com',
    [
        'summary' => 'Existing all-day test',
        'allDay'  => true,
        'start'   => '2026-07-20',
        'end'     => '2026-07-21'
    ]
);
$msAllDayUpdateBody = json_decode(
    $msAllDayUpdateClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertTrueValue(
    !array_key_exists('showAs', $msAllDayUpdateBody),
    'Updating an existing Microsoft all-day event must preserve its current availability.'
);

try {
    (new MicrosoftCalendarProvider(new FakeHttpClient([]), 'ms-access-token'))->createEvent('AQMk-primary', [
        'summary'  => 'Too many reminders',
        'allDay'   => false,
        'start'    => '2026-07-20T10:00:00+02:00',
        'end'      => '2026-07-20T11:00:00+02:00',
        'reminder' => [
            'mode'      => 'multiple',
            'reminders' => [
                ['minutesBeforeStart' => 10],
                ['minutesBeforeStart' => 30]
            ]
        ]
    ]);
    throw new RuntimeException('Microsoft accepted multiple event reminders.');
} catch (InvalidArgumentException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'does not support this many reminders'),
        'Microsoft event writes must reject multiple reminders instead of dropping one silently.'
    );
}

$msLocalTimeClient = new FakeHttpClient([
    response(201, [
        'id'          => 'local-time-id',
        'iCalUId'     => 'local-time@example.com',
        '@odata.etag' => 'W/"local-time"'
    ])
]);
(new MicrosoftCalendarProvider($msLocalTimeClient, 'ms-access-token'))->createEvent('AQMk-primary', [
    'summary'  => 'Local time test',
    'allDay'   => false,
    'start'    => '2026-07-20T08:00:00Z',
    'end'      => '2026-07-20T09:00:00Z',
    'timezone' => 'Europe/Berlin'
]);
$msLocalTimeBody = json_decode($msLocalTimeClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
assertTrueValue(
    !array_key_exists('isReminderOn', $msLocalTimeBody)
        && !array_key_exists('reminderMinutesBeforeStart', $msLocalTimeBody),
    'Microsoft calendar-default reminder creation must be represented by omitting explicit reminder fields.'
);
assertSameValue(
    '2026-07-20T10:00:00',
    $msLocalTimeBody['start']['dateTime'],
    'Microsoft timed events must preserve the local wall-clock time supplied by the calendar view.'
);
assertSameValue(
    'Europe/Berlin',
    $msLocalTimeBody['start']['timeZone'],
    'Microsoft timed events must retain the submitted local timezone instead of being stored as UTC.'
);
assertSameValue(
    '2026-07-20T11:00:00',
    $msLocalTimeBody['end']['dateTime'],
    'Microsoft timed event end values must use the same local timezone conversion.'
);

$msSingleToSeriesClient = new FakeHttpClient([
    response(200, [
        'id'          => 'single-to-series-id',
        'iCalUId'     => 'single-to-series@example.com',
        '@odata.etag' => 'W/"single-to-series"'
    ])
]);
(new MicrosoftCalendarProvider($msSingleToSeriesClient, 'ms-access-token'))->updateEvent(
    'AQMk-primary',
    'single-to-series-id',
    'W/"single-before"',
    'single-to-series@example.com',
    [
        'summary'    => 'Converted recurring event',
        'allDay'     => false,
        'start'      => '2026-08-17T08:00:00Z',
        'end'        => '2026-08-17T09:00:00Z',
        'timezone'   => 'Europe/Berlin',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 1,
            'byDay'     => ['MO'],
            'endMode'   => 'count',
            'count'     => 4
        ]
    ]
);
assertSameValue(
    'PATCH',
    $msSingleToSeriesClient->requests[0]['method'],
    'Microsoft single events must be convertible to recurring series via PATCH.'
);
assertSameValue(
    'W/"single-before"',
    $msSingleToSeriesClient->requests[0]['headers']['If-Match'] ?? '',
    'Microsoft recurrence conversion must retain optimistic locking.'
);
$msSingleToSeriesBody = json_decode(
    $msSingleToSeriesClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue(
    ['dateTime' => '2026-08-17T10:00:00', 'timeZone' => 'Europe/Berlin'],
    $msSingleToSeriesBody['start'],
    'Converting a Microsoft single event to a series must preserve its local wall-clock time.'
);
assertSameValue(
    'weekly',
    $msSingleToSeriesBody['recurrence']['pattern']['type'] ?? '',
    'Converting a Microsoft single event must submit a Graph recurrence pattern.'
);
assertSameValue(
    4,
    $msSingleToSeriesBody['recurrence']['range']['numberOfOccurrences'] ?? 0,
    'Converting a Microsoft single event must submit the requested recurrence range.'
);

$msSeriesToSingleClient = new FakeHttpClient([
    response(200, [
        'id'          => 'series-to-single-id',
        'iCalUId'     => 'series-to-single@example.com',
        '@odata.etag' => 'W/"series-to-single"'
    ])
]);
(new MicrosoftCalendarProvider($msSeriesToSingleClient, 'ms-access-token'))->updateEvent(
    'AQMk-primary',
    'series-to-single-id',
    'W/"series-before-single"',
    'series-to-single@example.com',
    [
        'summary'    => 'Converted single event',
        'allDay'     => false,
        'start'      => '2026-08-17T08:00:00Z',
        'end'        => '2026-08-17T09:00:00Z',
        'timezone'   => 'Europe/Berlin',
        'recurrence' => null
    ],
    [
        'recurrenceType'  => 'master',
        'seriesId'        => 'series-to-single-id',
        'recurring'       => true,
        'canUpdateSeries' => true,
        'writeScope'      => 'series'
    ]
);
assertSameValue(
    'PATCH',
    $msSeriesToSingleClient->requests[0]['method'],
    'Microsoft recurring series must be convertible to a single event via PATCH.'
);
assertSameValue(
    'W/"series-before-single"',
    $msSeriesToSingleClient->requests[0]['headers']['If-Match'] ?? '',
    'Microsoft series-to-single conversion must retain optimistic locking.'
);
$msSeriesToSingleBody = json_decode(
    $msSeriesToSingleClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertTrueValue(
    array_key_exists('recurrence', $msSeriesToSingleBody),
    'Microsoft series-to-single conversion must explicitly submit the recurrence property.'
);
assertSameValue(
    null,
    $msSeriesToSingleBody['recurrence'],
    'Microsoft series-to-single conversion must clear the Graph recurrence pattern with null.'
);
assertSameValue(
    ['dateTime' => '2026-08-17T10:00:00', 'timeZone' => 'Europe/Berlin'],
    $msSeriesToSingleBody['start'],
    'Converting a Microsoft series to a single event must preserve its local wall-clock time.'
);

$msRecurringCreateClient = new FakeHttpClient([
    response(201, [
        'id'          => 'microsoft-series-id',
        'iCalUId'     => 'microsoft-series@example.com',
        '@odata.etag' => 'W/"microsoft-series"'
    ])
]);
(new MicrosoftCalendarProvider($msRecurringCreateClient, 'ms-access-token'))->createEvent('AQMk-primary', [
    'summary'    => 'Microsoft weekly meeting',
    'allDay'     => false,
    'start'      => '2026-10-19T08:00:00Z',
    'end'        => '2026-10-19T09:00:00Z',
    'timezone'   => 'Europe/Berlin',
    'recurrence' => [
        'frequency' => 'weekly',
        'interval'  => 2,
        'byDay'     => ['TH', 'MO'],
        'endMode'   => 'until',
        'until'     => '2026-11-30'
    ]
]);
$msRecurringCreateBody = json_decode(
    $msRecurringCreateClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue(
    ['dateTime' => '2026-10-19T10:00:00', 'timeZone' => 'Europe/Berlin'],
    $msRecurringCreateBody['start'],
    'Recurring Microsoft events must preserve the local wall-clock start time and timezone.'
);
assertSameValue(
    [
        'type'           => 'weekly',
        'interval'       => 2,
        'daysOfWeek'     => ['monday', 'thursday'],
        'firstDayOfWeek' => 'monday'
    ],
    $msRecurringCreateBody['recurrence']['pattern'],
    'Recurring Microsoft events must serialize weekly patterns in Microsoft Graph format.'
);
assertSameValue(
    [
        'type'      => 'endDate',
        'startDate' => '2026-10-19',
        'endDate'   => '2026-11-30'
    ],
    $msRecurringCreateBody['recurrence']['range'],
    'Recurring Microsoft events must serialize end-date ranges in Microsoft Graph format.'
);

$msDailyRecurrence = CalendarRecurrenceRule::toMicrosoftRecurrence(
    [
        'frequency' => 'daily',
        'interval'  => 3,
        'endMode'   => 'count',
        'count'     => 5
    ],
    new DateTimeImmutable('2026-08-17T09:00:00+02:00')
);
assertSameValue('daily', $msDailyRecurrence['pattern']['type'], 'Microsoft daily recurrence patterns must be supported.');
assertSameValue(5, $msDailyRecurrence['range']['numberOfOccurrences'], 'Microsoft numbered recurrence ranges must retain the occurrence count.');

$msMonthlyRecurrence = CalendarRecurrenceRule::toMicrosoftRecurrence(
    ['frequency' => 'monthly', 'interval' => 2, 'endMode' => 'never'],
    new DateTimeImmutable('2026-08-31T09:00:00+02:00')
);
assertSameValue('absoluteMonthly', $msMonthlyRecurrence['pattern']['type'], 'Microsoft monthly recurrence patterns must be absolute monthly patterns.');
assertSameValue(31, $msMonthlyRecurrence['pattern']['dayOfMonth'], 'Microsoft monthly recurrence patterns must use the start day of month.');

$msYearlyRecurrence = CalendarRecurrenceRule::toMicrosoftRecurrence(
    ['frequency' => 'yearly', 'interval' => 1, 'endMode' => 'never'],
    new DateTimeImmutable('2026-12-24T09:00:00+01:00')
);
assertSameValue('absoluteYearly', $msYearlyRecurrence['pattern']['type'], 'Microsoft yearly recurrence patterns must be absolute yearly patterns.');
assertSameValue(12, $msYearlyRecurrence['pattern']['month'], 'Microsoft yearly recurrence patterns must retain the start month.');
assertSameValue(24, $msYearlyRecurrence['pattern']['dayOfMonth'], 'Microsoft yearly recurrence patterns must retain the start day.');

$msParsedWeeklyRecurrence = CalendarRecurrenceRule::fromMicrosoftRecurrence(
    [
        'pattern' => [
            'type'           => 'weekly',
            'interval'       => 2,
            'daysOfWeek'     => ['monday', 'thursday'],
            'firstDayOfWeek' => 'monday'
        ],
        'range'   => [
            'type'                => 'numbered',
            'startDate'           => '2026-10-19',
            'numberOfOccurrences' => 6
        ]
    ],
    new DateTimeImmutable('2026-10-19T00:00:00Z')
);
assertSameValue(
    [
        'frequency' => 'WEEKLY',
        'interval'  => 2,
        'endMode'   => 'count',
        'byDay'     => ['MO', 'TH'],
        'count'     => 6
    ],
    $msParsedWeeklyRecurrence,
    'Supported Microsoft recurrence patterns must round-trip into the shared recurrence editor.'
);
assertSameValue(
    [
        'frequency' => 'WEEKLY',
        'interval'  => 1,
        'endMode'   => 'never',
        'byDay'     => ['MO'],
        'weekStart' => 'SU'
    ],
    CalendarRecurrenceRule::fromMicrosoftRecurrence(
        [
            'pattern' => [
                'type'           => 'weekly',
                'interval'       => 1,
                'daysOfWeek'     => ['monday'],
                'firstDayOfWeek' => 'sunday'
            ],
            'range'   => ['type' => 'noEnd', 'startDate' => '2026-10-19']
        ],
        new DateTimeImmutable('2026-10-19T00:00:00Z')
    ),
    'Microsoft weekly rules must retain Outlook week boundaries in the shared recurrence editor.'
);

$msRelativeMonthlyRecurrence = [
    'pattern' => [
        'type'       => 'relativeMonthly',
        'interval'   => 1,
        'daysOfWeek' => ['wednesday'],
        'index'      => 'second'
    ],
    'range'   => [
        'type'                => 'numbered',
        'startDate'           => '2026-08-12',
        'recurrenceTimeZone'  => 'W. Europe Standard Time',
        'numberOfOccurrences' => 6
    ]
];
$msParsedRelativeMonthly = CalendarRecurrenceRule::fromMicrosoftRecurrence(
    $msRelativeMonthlyRecurrence,
    new DateTimeImmutable('2026-08-12T00:00:00Z')
);
assertSameValue(
    [
        'frequency'          => 'MONTHLY',
        'interval'           => 1,
        'endMode'            => 'count',
        'patternMode'        => 'relative',
        'byDay'              => ['WE'],
        'relativeIndex'      => 'second',
        'recurrenceTimeZone' => 'W. Europe Standard Time',
        'count'              => 6
    ],
    $msParsedRelativeMonthly,
    'Relative monthly Outlook patterns must be editable without losing their weekday position.'
);
assertSameValue(
    $msRelativeMonthlyRecurrence,
    CalendarRecurrenceRule::toMicrosoftRecurrence(
        $msParsedRelativeMonthly,
        new DateTimeImmutable('2026-08-12T09:00:00+02:00')
    ),
    'Relative monthly Outlook patterns must round-trip without changing their Graph semantics.'
);
assertSameValue(
    2,
    CalendarRecurrenceRule::microsoftOccurrencePosition($msRelativeMonthlyRecurrence, '2026-09-09'),
    'Relative monthly Microsoft series must support this-and-following occurrence positioning.'
);

$msRelativeYearlyRecurrence = [
    'pattern' => [
        'type'       => 'relativeYearly',
        'interval'   => 1,
        'daysOfWeek' => ['wednesday'],
        'index'      => 'last',
        'month'      => 11
    ],
    'range'   => [
        'type'      => 'noEnd',
        'startDate' => '2026-11-25'
    ]
];
$msParsedRelativeYearly = CalendarRecurrenceRule::fromMicrosoftRecurrence(
    $msRelativeYearlyRecurrence,
    new DateTimeImmutable('2026-11-25T00:00:00Z')
);
assertSameValue('YEARLY', $msParsedRelativeYearly['frequency'], 'Relative yearly Outlook patterns must be supported.');
assertSameValue('relative', $msParsedRelativeYearly['patternMode'], 'Relative yearly patterns must retain their pattern mode.');
assertSameValue('last', $msParsedRelativeYearly['relativeIndex'], 'Relative yearly patterns must retain their position.');
assertSameValue(
    $msRelativeYearlyRecurrence,
    CalendarRecurrenceRule::toMicrosoftRecurrence(
        $msParsedRelativeYearly,
        new DateTimeImmutable('2026-11-25T09:00:00+01:00')
    ),
    'Relative yearly Outlook patterns must round-trip without changing their Graph semantics.'
);
assertSameValue(
    2,
    CalendarRecurrenceRule::microsoftOccurrencePosition($msRelativeYearlyRecurrence, '2027-11-24'),
    'Relative yearly Microsoft series must support this-and-following occurrence positioning.'
);

assertSameValue(
    ['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH;WKST=SU'],
    CalendarRecurrenceRule::toGoogleLines(
        [
            'frequency' => 'weekly',
            'interval'  => 2,
            'byDay'     => ['TH', 'TU'],
            'weekStart' => 'SU',
            'endMode'   => 'never'
        ],
        new DateTimeImmutable('2026-09-01T09:00:00+02:00'),
        false,
        'Europe/Berlin'
    ),
    'Provider-neutral weekly recurrence must preserve non-Monday week boundaries when serialized as RFC 5545.'
);
assertSameValue(
    [
        'frequency' => 'WEEKLY',
        'interval'  => 2,
        'endMode'   => 'never',
        'byDay'     => ['TU', 'TH'],
        'weekStart' => 'SU'
    ],
    CalendarRecurrenceRule::fromGoogleRule(
        'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH;WKST=SU',
        false,
        'Europe/Berlin'
    ),
    'RFC 5545 weekly recurrence must round-trip Outlook week boundaries for cross-provider moves.'
);
assertSameValue(
    ['RRULE:FREQ=MONTHLY;BYDAY=WE,FR;BYSETPOS=2;COUNT=6'],
    CalendarRecurrenceRule::toGoogleLines(
        [
            'frequency'     => 'monthly',
            'interval'      => 1,
            'patternMode'   => 'relative',
            'byDay'         => ['FR', 'WE'],
            'relativeIndex' => 'second',
            'endMode'       => 'count',
            'count'         => 6
        ],
        new DateTimeImmutable('2026-08-12T09:00:00+02:00'),
        false,
        'Europe/Berlin'
    ),
    'Relative monthly recurrence must serialize losslessly for Google and CalDAV targets.'
);
assertSameValue(
    [
        'frequency'     => 'MONTHLY',
        'interval'      => 1,
        'endMode'       => 'count',
        'patternMode'   => 'relative',
        'byDay'         => ['WE', 'FR'],
        'relativeIndex' => 'second',
        'count'         => 6
    ],
    CalendarRecurrenceRule::fromGoogleRule(
        'RRULE:FREQ=MONTHLY;BYDAY=WE,FR;BYSETPOS=2;COUNT=6',
        false,
        'Europe/Berlin'
    ),
    'Relative monthly RFC 5545 recurrence must return to the shared recurrence model.'
);
assertSameValue(
    ['RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=WE;BYSETPOS=-1'],
    CalendarRecurrenceRule::toGoogleLines(
        [
            'frequency'     => 'yearly',
            'interval'      => 1,
            'patternMode'   => 'relative',
            'byDay'         => ['WE'],
            'relativeIndex' => 'last',
            'month'         => 11,
            'endMode'       => 'never'
        ],
        new DateTimeImmutable('2026-11-25T09:00:00+01:00'),
        false,
        'Europe/Berlin'
    ),
    'Relative yearly recurrence must retain its month when moved to an RFC 5545 provider.'
);
assertSameValue(
    [
        'frequency'     => 'YEARLY',
        'interval'      => 1,
        'endMode'       => 'never',
        'patternMode'   => 'relative',
        'byDay'         => ['WE'],
        'relativeIndex' => 'last',
        'month'         => 11
    ],
    CalendarRecurrenceRule::fromGoogleRule(
        'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=WE;BYSETPOS=-1',
        false,
        'Europe/Berlin'
    ),
    'Relative yearly RFC 5545 recurrence must remain editable after a cross-provider move.'
);
assertSameValue(
    ['RRULE:FREQ=YEARLY;BYMONTH=3;BYMONTHDAY=15'],
    CalendarRecurrenceRule::toGoogleLines(
        [
            'frequency'  => 'yearly',
            'interval'   => 1,
            'month'      => 3,
            'dayOfMonth' => 15,
            'endMode'    => 'never'
        ],
        new DateTimeImmutable('2026-02-01T09:00:00+01:00'),
        false,
        'Europe/Berlin'
    ),
    'Absolute yearly recurrence must preserve an Outlook month and day that differ from DTSTART.'
);

$msSplitRecurrence = [
    'pattern' => [
        'type'           => 'weekly',
        'interval'       => 1,
        'daysOfWeek'     => ['monday', 'thursday'],
        'firstDayOfWeek' => 'monday'
    ],
    'range'   => [
        'type'                => 'numbered',
        'startDate'           => '2026-10-19',
        'numberOfOccurrences' => 8
    ]
];
assertSameValue(
    4,
    CalendarRecurrenceRule::microsoftOccurrencePosition($msSplitRecurrence, '2026-10-29'),
    'Microsoft recurrence splitting must locate the selected occurrence in the original pattern.'
);
assertSameValue(
    5,
    CalendarRecurrenceRule::remainingMicrosoftOccurrenceCount($msSplitRecurrence, '2026-10-29'),
    'Numbered Microsoft series must retain only the remaining occurrences after a split.'
);
$msSundaySplitRecurrence = $msSplitRecurrence;
$msSundaySplitRecurrence['pattern']['firstDayOfWeek'] = 'sunday';
assertSameValue(
    4,
    CalendarRecurrenceRule::microsoftOccurrencePosition($msSundaySplitRecurrence, '2026-10-29'),
    'Microsoft recurrence splitting must support Outlook weekly patterns whose week starts on Sunday.'
);
$msTrimmedRecurrence = CalendarRecurrenceRule::trimMicrosoftRecurrenceBefore(
    $msSplitRecurrence,
    '2026-10-29'
);
assertSameValue(
    [
        'type'      => 'endDate',
        'startDate' => '2026-10-19',
        'endDate'   => '2026-10-28'
    ],
    $msTrimmedRecurrence['range'],
    'The original Microsoft series must end on the day before the selected occurrence.'
);

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

$msOccurrenceWriteClient = new FakeHttpClient([
    response(200, [
        'id'          => 'instance/id+1',
        'iCalUId'     => 'series-occurrence@example.com',
        '@odata.etag' => 'W/"occurrence-updated"'
    ]),
    response(204)
]);
$msOccurrenceProvider = new MicrosoftCalendarProvider($msOccurrenceWriteClient, 'ms-access-token');
$msOccurrenceRecurrence = [
    'recurrenceType'      => 'occurrence',
    'seriesId'            => 'series-master',
    'occurrenceId'        => 'instance/id+1',
    'originalStart'       => '',
    'recurring'           => true,
    'canUpdateOccurrence' => true,
    'canDeleteOccurrence' => true,
    'canUpdateFollowing'  => false,
    'canUpdateSeries'     => false,
    'canDeleteSeries'     => false,
    'writeScope'          => 'occurrence'
];
$msOccurrenceProvider->updateEvent(
    'AQMk-primary',
    'instance/id+1',
    'W/"occurrence"',
    'series-occurrence@example.com',
    ['summary' => 'Updated occurrence'],
    $msOccurrenceRecurrence
);
assertSameValue('PATCH', $msOccurrenceWriteClient->requests[0]['method'], 'Microsoft occurrences must be updated via PATCH.');
assertTrueValue(
    str_ends_with($msOccurrenceWriteClient->requests[0]['url'], '/events/instance%2Fid%2B1'),
    'Microsoft occurrence updates must target the concrete immutable occurrence ID.'
);
$msOccurrenceUpdateBody = json_decode(
    $msOccurrenceWriteClient->requests[0]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue('Updated occurrence', $msOccurrenceUpdateBody['subject'], 'Microsoft occurrence changes must be sent without changing the series pattern.');
$msExceptionRecurrence = $msOccurrenceRecurrence;
$msExceptionRecurrence['recurrenceType'] = 'exception';
assertTrueValue(
    $msOccurrenceProvider->deleteEvent(
        'AQMk-primary',
        'instance/id+1',
        'W/"occurrence-updated"',
        '',
        $msExceptionRecurrence
    ),
    'Microsoft occurrence deletion must return true after HTTP 204.'
);
assertSameValue('DELETE', $msOccurrenceWriteClient->requests[1]['method'], 'Microsoft occurrences must be deleted via DELETE.');
assertTrueValue(
    str_ends_with($msOccurrenceWriteClient->requests[1]['url'], '/events/instance%2Fid%2B1'),
    'Microsoft occurrence deletion must target only the concrete occurrence ID.'
);

$msSeriesWriteClient = new FakeHttpClient([
    response(200, [
        'id'                    => 'series-master',
        'iCalUId'               => 'series@example.com',
        '@odata.etag'           => 'W/"series-master"',
        'subject'               => 'Microsoft series',
        'body'                  => ['contentType' => 'text', 'content' => 'Series description'],
        'location'              => ['displayName' => 'Berlin'],
        'start'                 => ['dateTime' => '2026-10-19T08:00:00', 'timeZone' => 'UTC'],
        'end'                   => ['dateTime' => '2026-10-19T09:00:00', 'timeZone' => 'UTC'],
        'originalStartTimeZone' => 'Europe/Berlin',
        'type'                  => 'seriesMaster',
        'recurrence'            => [
            'pattern' => [
                'type'           => 'weekly',
                'interval'       => 1,
                'daysOfWeek'     => ['monday', 'thursday'],
                'firstDayOfWeek' => 'monday'
            ],
            'range'   => [
                'type'                => 'numbered',
                'startDate'           => '2026-10-19',
                'numberOfOccurrences' => 8
            ]
        ]
    ]),
    response(200, [
        'id'          => 'series-master',
        'iCalUId'     => 'series@example.com',
        '@odata.etag' => 'W/"series-updated"'
    ]),
    response(204)
]);
$msSeriesProvider = new MicrosoftCalendarProvider($msSeriesWriteClient, 'ms-access-token');
$msSeries = $msSeriesProvider->getRecurringSeries('AQMk-primary', 'series-master');
assertSameValue('master', $msSeries['recurrenceType'], 'Microsoft recurring parent events must be normalized as series masters.');
assertSameValue(true, $msSeries['canUpdateSeries'], 'Microsoft recurring parent events must allow full-series updates.');
assertSameValue(true, $msSeries['canDeleteSeries'], 'Microsoft recurring parent events must allow full-series deletion.');
assertSameValue(true, $msSeries['recurrenceEditable'], 'Supported Microsoft recurrence patterns must be editable.');
assertSameValue(
    [
        'frequency' => 'WEEKLY',
        'interval'  => 1,
        'endMode'   => 'count',
        'byDay'     => ['MO', 'TH'],
        'count'     => 8
    ],
    $msSeries['recurrenceSettings'],
    'Microsoft series masters must expose provider-neutral recurrence settings.'
);
assertTrueValue(
    str_ends_with($msSeriesWriteClient->requests[0]['url'], '/events/series-master'),
    'Microsoft recurring parent reads must target the concrete series master ID.'
);

$msSeriesRecurrence = $msSeries;
$msSeriesRecurrence['writeScope'] = 'series';
$msSeriesProvider->updateEvent(
    'AQMk-primary',
    $msSeries['resourceUrl'],
    $msSeries['etag'],
    $msSeries['uid'],
    [
        'summary'    => 'Updated Microsoft series',
        'allDay'     => false,
        'start'      => '2026-10-19T08:30:00Z',
        'end'        => '2026-10-19T09:30:00Z',
        'timezone'   => 'Europe/Berlin',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 2,
            'byDay'     => ['MO', 'TH'],
            'endMode'   => 'count',
            'count'     => 6
        ]
    ],
    $msSeriesRecurrence
);
assertSameValue('PATCH', $msSeriesWriteClient->requests[1]['method'], 'Microsoft recurring series must be updated via PATCH.');
assertTrueValue(
    str_ends_with($msSeriesWriteClient->requests[1]['url'], '/events/series-master'),
    'Microsoft full-series updates must target the series master ID.'
);
assertSameValue(
    'W/"series-master"',
    $msSeriesWriteClient->requests[1]['headers']['If-Match'],
    'Microsoft full-series updates must retain the verified parent ETag.'
);
$msSeriesUpdateBody = json_decode(
    $msSeriesWriteClient->requests[1]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue('Updated Microsoft series', $msSeriesUpdateBody['subject'], 'Microsoft series updates must change the parent subject.');
assertSameValue('weekly', $msSeriesUpdateBody['recurrence']['pattern']['type'], 'Microsoft series updates must send the edited recurrence pattern.');
assertSameValue(2, $msSeriesUpdateBody['recurrence']['pattern']['interval'], 'Microsoft series updates must send the edited recurrence interval.');
assertSameValue(6, $msSeriesUpdateBody['recurrence']['range']['numberOfOccurrences'], 'Microsoft series updates must send the edited recurrence range.');

$msSeriesDeleteRecurrence = [
    'recurrenceType'  => 'occurrence',
    'seriesId'        => 'series-master',
    'occurrenceId'    => 'instance/id+1',
    'recurring'       => true,
    'canDeleteSeries' => true,
    'writeScope'      => 'series'
];
assertTrueValue(
    $msSeriesProvider->deleteEvent(
        'AQMk-primary',
        'instance/id+1',
        'W/"occurrence"',
        '',
        $msSeriesDeleteRecurrence
    ),
    'Microsoft full-series deletion must return true after HTTP 204.'
);
assertSameValue('DELETE', $msSeriesWriteClient->requests[2]['method'], 'Microsoft recurring series must be deleted via DELETE.');
assertTrueValue(
    str_ends_with($msSeriesWriteClient->requests[2]['url'], '/events/series-master'),
    'Microsoft full-series deletion must target the series master ID rather than the selected occurrence.'
);
assertTrueValue(
    !array_key_exists('If-Match', $msSeriesWriteClient->requests[2]['headers']),
    'Microsoft full-series deletion must not reuse an occurrence ETag for the parent event.'
);

$msFollowingParent = [
    'id'                    => 'series-master',
    'iCalUId'               => 'series@example.com',
    '@odata.etag'           => 'W/"series-following"',
    'subject'               => 'Microsoft series',
    'body'                  => ['contentType' => 'text', 'content' => 'Series description'],
    'location'              => ['displayName' => 'Berlin'],
    'attendees'             => [[
        'emailAddress' => ['address' => 'guest@example.com', 'name' => 'Guest'],
        'type'         => 'required',
        'status'       => ['response' => 'accepted']
    ]],
    'start'                 => ['dateTime' => '2026-10-19T08:00:00', 'timeZone' => 'UTC'],
    'end'                   => ['dateTime' => '2026-10-19T09:00:00', 'timeZone' => 'UTC'],
    'originalStartTimeZone' => 'Europe/Berlin',
    'type'                  => 'seriesMaster',
    'recurrence'            => $msSplitRecurrence
];
$msFollowingTarget = [
    'id'                    => 'instance-following',
    'iCalUId'               => 'series@example.com',
    '@odata.etag'           => 'W/"following-target"',
    'subject'               => 'Microsoft series',
    'start'                 => ['dateTime' => '2026-10-29T08:00:00', 'timeZone' => 'UTC'],
    'end'                   => ['dateTime' => '2026-10-29T09:00:00', 'timeZone' => 'UTC'],
    'originalStartTimeZone' => 'Europe/Berlin',
    'type'                  => 'occurrence',
    'seriesMasterId'        => 'series-master'
];
$msFollowingPrepareClient = new FakeHttpClient([
    response(200, $msFollowingParent),
    response(200, $msFollowingTarget)
]);
$msFollowingProvider = new MicrosoftCalendarProvider($msFollowingPrepareClient, 'ms-access-token');
$msFollowing = $msFollowingProvider->getRecurringFollowing(
    'AQMk-primary',
    'series-master',
    'instance-following',
    '2026-10-29T08:00:00+00:00'
);
assertSameValue('following', $msFollowing['writeScope'], 'Microsoft following preparation must return the following write scope.');
assertSameValue(true, $msFollowing['canUpdateFollowing'], 'Microsoft following preparation must advertise following-update support.');
assertSameValue(5, $msFollowing['recurrenceSettings']['count'], 'Microsoft following preparation must reduce numbered ranges to the remaining occurrence count.');
assertSameValue(
    '2026-10-29T08:00:00+00:00',
    $msFollowing['originalStart'],
    'Microsoft following preparation must retain a stable original occurrence start.'
);

$msFollowingUpdateClient = new FakeHttpClient([
    response(200, $msFollowingParent),
    response(200, $msFollowingTarget),
    response(200, [
        'id'          => 'series-master',
        '@odata.etag' => 'W/"series-trimmed"'
    ]),
    response(201, [
        'id'          => 'new-series-master',
        'iCalUId'     => 'new-series@example.com',
        '@odata.etag' => 'W/"new-series"'
    ])
]);
$msFollowingUpdateProvider = new MicrosoftCalendarProvider($msFollowingUpdateClient, 'ms-access-token');
$msFollowingIdentity = $msFollowing;
$msFollowingIdentity['writeScope'] = 'following';
$msFollowingResult = $msFollowingUpdateProvider->updateEvent(
    'AQMk-primary',
    'instance-following',
    'W/"following-target"',
    'series@example.com',
    [
        'summary'     => 'Updated from this occurrence',
        'description' => 'Series description',
        'location'    => 'Berlin',
        'allDay'      => false,
        'start'       => '2026-10-29T08:00:00Z',
        'end'         => '2026-10-29T09:00:00Z',
        'timezone'    => 'Europe/Berlin',
        'recurrence'  => [
            'frequency' => 'weekly',
            'interval'  => 1,
            'byDay'     => ['MO', 'TH'],
            'endMode'   => 'count',
            'count'     => 5
        ]
    ],
    $msFollowingIdentity
);
assertSameValue('new-series-master', $msFollowingResult['eventReference'], 'Microsoft following updates must return the newly created series ID.');
assertSameValue('PATCH', $msFollowingUpdateClient->requests[2]['method'], 'Microsoft following updates must first shorten the original series.');
$msFollowingTrimBody = json_decode(
    $msFollowingUpdateClient->requests[2]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue(
    '2026-10-28',
    $msFollowingTrimBody['recurrence']['range']['endDate'],
    'Microsoft following updates must end the original series before the selected occurrence.'
);
assertSameValue('POST', $msFollowingUpdateClient->requests[3]['method'], 'Microsoft following updates must create a new future series.');
$msFollowingCreateBody = json_decode(
    $msFollowingUpdateClient->requests[3]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue('Updated from this occurrence', $msFollowingCreateBody['subject'], 'The new Microsoft future series must contain the edited subject.');
assertSameValue('2026-10-29', $msFollowingCreateBody['recurrence']['range']['startDate'], 'The new Microsoft future series must begin at the selected occurrence.');
assertSameValue(5, $msFollowingCreateBody['recurrence']['range']['numberOfOccurrences'], 'The new Microsoft future series must retain the remaining count.');
assertSameValue(
    [
        'emailAddress' => ['address' => 'guest@example.com', 'name' => 'Guest'],
        'type'         => 'required'
    ],
    $msFollowingCreateBody['attendees'][0],
    'Microsoft series splitting must preserve writable attendee identity without copying response state.'
);

$msFollowingDeleteClient = new FakeHttpClient([
    response(200, $msFollowingParent),
    response(200, $msFollowingTarget),
    response(200, [
        'id'          => 'series-master',
        '@odata.etag' => 'W/"series-trimmed-delete"'
    ])
]);
$msFollowingDeleteProvider = new MicrosoftCalendarProvider($msFollowingDeleteClient, 'ms-access-token');
assertTrueValue(
    $msFollowingDeleteProvider->deleteEvent(
        'AQMk-primary',
        'instance-following',
        'W/"following-target"',
        '',
        $msFollowingIdentity
    ),
    'Microsoft following deletion must return true after shortening the original series.'
);
assertSameValue('PATCH', $msFollowingDeleteClient->requests[2]['method'], 'Microsoft following deletion must shorten the parent series rather than delete one occurrence.');
$msFollowingDeleteBody = json_decode(
    $msFollowingDeleteClient->requests[2]['body'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSameValue('2026-10-28', $msFollowingDeleteBody['recurrence']['range']['endDate'], 'Microsoft following deletion must end before the selected occurrence.');

$msFirstFollowingTarget = $msFollowingTarget;
$msFirstFollowingTarget['id'] = 'instance-first';
$msFirstFollowingTarget['start']['dateTime'] = '2026-10-19T08:00:00';
$msFirstFollowingTarget['end']['dateTime'] = '2026-10-19T09:00:00';
$msFirstFollowingDeleteClient = new FakeHttpClient([
    response(200, $msFollowingParent),
    response(200, $msFirstFollowingTarget),
    response(204)
]);
$msFirstFollowingDeleteProvider = new MicrosoftCalendarProvider($msFirstFollowingDeleteClient, 'ms-access-token');
$msFirstFollowingIdentity = [
    'recurrenceType'     => 'occurrence',
    'seriesId'           => 'series-master',
    'occurrenceId'       => 'instance-first',
    'originalStart'      => '2026-10-19T08:00:00+00:00',
    'recurring'          => true,
    'canUpdateFollowing' => true,
    'canDeleteSeries'    => true,
    'writeScope'         => 'following'
];
assertTrueValue(
    $msFirstFollowingDeleteProvider->deleteEvent(
        'AQMk-primary',
        'instance-first',
        'W/"first"',
        '',
        $msFirstFollowingIdentity
    ),
    'Deleting from the first Microsoft occurrence must delete the complete series.'
);
assertSameValue('DELETE', $msFirstFollowingDeleteClient->requests[2]['method'], 'A first-occurrence following delete must delete the Microsoft series master.');
assertTrueValue(
    str_ends_with($msFirstFollowingDeleteClient->requests[2]['url'], '/events/series-master'),
    'A first-occurrence following delete must target the series master ID.'
);

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

$eventStateFixture = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-state@example.com
DTSTART:20260817T100000Z
DTEND:20260817T110000Z
SUMMARY:State test
STATUS:TENTATIVE
TRANSP:TRANSPARENT
END:VEVENT
END:VCALENDAR
ICS;
$eventStateFixture = str_replace("\n", "\r\n", $eventStateFixture) . "\r\n";
$eventState = ICalendarCodec::parseEvents(
    $eventStateFixture,
    'https://calendar.example/work/state.ics',
    '"state"'
)[0];
assertSameValue('TENTATIVE', $eventState['status'], 'RFC 5545 STATUS must survive iCalendar parsing.');
assertSameValue('TRANSPARENT', $eventState['transparency'], 'RFC 5545 TRANSP must survive iCalendar parsing.');

$cancelledStateFixture = str_replace('STATUS:TENTATIVE', 'STATUS:CANCELLED', $eventStateFixture);
$cancelledState = ICalendarCodec::parseEvents(
    $cancelledStateFixture,
    'https://calendar.example/work/cancelled-state.ics',
    '"cancelled-state"'
)[0];
assertSameValue('CANCELLED', $cancelledState['status'], 'RFC 5545 cancelled status must normalize canonically.');
assertSameValue(
    [],
    CalendarEventState::filterVisibleEvents([$cancelledState]),
    'Cancelled CalDAV and ICS events must be removed by the provider-neutral visibility policy.'
);

$opaqueStateFixture = str_replace(
    "STATUS:TENTATIVE\r\nTRANSP:TRANSPARENT\r\n",
    "STATUS:CONFIRMED\r\n",
    $eventStateFixture
);
$opaqueState = ICalendarCodec::parseEvents(
    $opaqueStateFixture,
    'https://calendar.example/work/opaque-state.ics',
    '"opaque-state"'
)[0];
assertSameValue('CONFIRMED', $opaqueState['status'], 'RFC 5545 confirmed status must normalize canonically.');
assertSameValue('OPAQUE', $opaqueState['transparency'], 'Missing RFC 5545 TRANSP must use the opaque default.');

$eventStateCreated = ICalendarCodec::createEvent([
    'summary'      => 'Writable state',
    'allDay'       => false,
    'start'        => '2026-08-17T10:00:00Z',
    'end'          => '2026-08-17T11:00:00Z',
    'status'       => 'TENTATIVE',
    'transparency' => 'TRANSPARENT'
]);
assertTrueValue(
    str_contains($eventStateCreated['ical'], 'STATUS:TENTATIVE')
        && str_contains($eventStateCreated['ical'], 'TRANSP:TRANSPARENT'),
    'iCalendar creation must serialize provider-neutral status and transparency.'
);
$eventStateUpdated = ICalendarCodec::updateEvent(
    $eventStateCreated['ical'],
    $eventStateCreated['uid'],
    [
        'status'       => 'CONFIRMED',
        'transparency' => 'OPAQUE'
    ]
);
assertTrueValue(
    str_contains($eventStateUpdated, 'STATUS:CONFIRMED')
        && str_contains($eventStateUpdated, 'TRANSP:OPAQUE')
        && !str_contains($eventStateUpdated, 'STATUS:TENTATIVE')
        && !str_contains($eventStateUpdated, 'TRANSP:TRANSPARENT'),
    'iCalendar updates must replace status and transparency without leaving stale properties.'
);
$eventStateRoundTrip = ICalendarCodec::parseEvents(
    $eventStateUpdated,
    'https://calendar.example/work/writable-state.ics',
    '"writable-state"'
)[0];
assertSameValue('CONFIRMED', $eventStateRoundTrip['status'], 'Updated iCalendar status must round-trip.');
assertSameValue('OPAQUE', $eventStateRoundTrip['transparency'], 'Updated iCalendar transparency must round-trip.');

$calDavRecurringCreated = ICalendarCodec::createEvent([
    'summary'    => 'CalDAV weekly meeting',
    'allDay'     => false,
    'start'      => '2026-10-19T08:00:00Z',
    'end'        => '2026-10-19T09:00:00Z',
    'timezone'   => 'Europe/Berlin',
    'reminder'   => [
        'mode'               => 'custom',
        'minutesBeforeStart' => 30
    ],
    'recurrence' => [
        'frequency' => 'weekly',
        'interval'  => 2,
        'byDay'     => ['TH', 'MO'],
        'endMode'   => 'until',
        'until'     => '2026-11-30'
    ]
]);
assertTrueValue(
    str_contains($calDavRecurringCreated['ical'], 'BEGIN:VTIMEZONE')
        && str_contains($calDavRecurringCreated['ical'], 'TZID:Europe/Berlin')
        && str_contains($calDavRecurringCreated['ical'], 'DTSTART;TZID=Europe/Berlin:20261019T100000')
        && str_contains(
            $calDavRecurringCreated['ical'],
            'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;UNTIL=20261130T090000Z'
        )
        && str_contains($calDavRecurringCreated['ical'], 'BEGIN:VALARM')
        && str_contains($calDavRecurringCreated['ical'], 'TRIGGER:-PT30M')
        && str_contains($calDavRecurringCreated['ical'], 'ACTION:DISPLAY'),
    'Recurring iCalendar creation must preserve local time, recurrence and one standard display reminder.'
);
$calDavRecurringParsed = ICalendarCodec::parseEvents(
    $calDavRecurringCreated['ical'],
    'https://calendar.example/work/series.ics',
    '"series"'
);
assertSameValue(1, count($calDavRecurringParsed), 'A newly created recurring iCalendar resource must parse as one master event.');
assertSameValue('master', $calDavRecurringParsed[0]['recurrenceType'], 'A created RRULE event must parse as a recurring master.');
assertSameValue('Europe/Berlin', $calDavRecurringParsed[0]['timezone'], 'The recurring iCalendar TZID must survive parsing.');
assertSameValue('custom', $calDavRecurringParsed[0]['reminder']['mode'], 'One CalDAV display alarm must use the shared reminder model.');
assertSameValue(30, $calDavRecurringParsed[0]['reminder']['minutesBeforeStart'], 'CalDAV reminder offsets must survive parsing.');
assertSameValue(
    'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;UNTIL=20261130T090000Z',
    $calDavRecurringParsed[0]['recurrenceRule'],
    'The created RFC 5545 recurrence rule must survive parsing.'
);

$calDavMultipleReminderCreated = ICalendarCodec::createEvent([
    'summary'  => 'CalDAV multiple reminders',
    'allDay'   => false,
    'start'    => '2026-10-19T08:00:00Z',
    'end'      => '2026-10-19T09:00:00Z',
    'timezone' => 'Europe/Berlin',
    'reminder' => [
        'mode'      => 'multiple',
        'reminders' => [
            ['minutesBeforeStart' => 10],
            ['minutesBeforeStart' => 60]
        ]
    ]
]);
assertTrueValue(
    substr_count($calDavMultipleReminderCreated['ical'], 'BEGIN:VALARM') === 2
        && str_contains($calDavMultipleReminderCreated['ical'], 'TRIGGER:-PT10M')
        && str_contains($calDavMultipleReminderCreated['ical'], 'TRIGGER:-PT60M'),
    'iCalendar creation must emit one DISPLAY VALARM for every supported reminder trigger.'
);
$calDavMultipleReminderParsed = ICalendarCodec::parseEvents(
    $calDavMultipleReminderCreated['ical'],
    'https://calendar.example/work/multiple-reminders.ics',
    '"multiple-reminders"'
)[0];
assertSameValue('multiple', $calDavMultipleReminderParsed['reminder']['mode'], 'Created multiple CalDAV alarms must parse back as editable multiple reminders.');
assertSameValue(
    [['minutesBeforeStart' => 10], ['minutesBeforeStart' => 60]],
    $calDavMultipleReminderParsed['reminder']['reminders'],
    'Created multiple CalDAV reminder offsets must round-trip through iCalendar.'
);

$calDavReminderUpdated = ICalendarCodec::updateRecurringSeries(
    $calDavRecurringCreated['ical'],
    $calDavRecurringCreated['uid'],
    [
        'reminder' => [
            'mode'               => 'custom',
            'minutesBeforeStart' => 90
        ]
    ]
);
assertTrueValue(
    str_contains($calDavReminderUpdated, 'TRIGGER:-PT90M')
        && substr_count($calDavReminderUpdated, 'BEGIN:VALARM') === 1,
    'Updating a CalDAV reminder must replace the supported VALARM without duplicating it.'
);
$calDavReminderRemoved = ICalendarCodec::updateRecurringSeries(
    $calDavReminderUpdated,
    $calDavRecurringCreated['uid'],
    ['reminder' => ['mode' => 'none']]
);
assertTrueValue(
    !str_contains($calDavReminderRemoved, 'BEGIN:VALARM'),
    'Disabling a CalDAV reminder must remove the supported VALARM.'
);

$complexAlarmFixture = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:complex-alarm@example.com
DTSTART:20260817T100000Z
DTEND:20260817T110000Z
SUMMARY:Complex alarm
BEGIN:VALARM
TRIGGER:-PT15M
ACTION:DISPLAY
DESCRIPTION:First
END:VALARM
BEGIN:VALARM
TRIGGER:-PT30M
ACTION:DISPLAY
DESCRIPTION:Second
END:VALARM
END:VEVENT
END:VCALENDAR
ICS;
$complexAlarmFixture = str_replace("\n", "\r\n", $complexAlarmFixture) . "\r\n";
$complexAlarmEvent = ICalendarCodec::parseEvents(
    $complexAlarmFixture,
    'https://calendar.example/work/complex.ics',
    '"complex"'
)[0];
assertSameValue('multiple', $complexAlarmEvent['reminder']['mode'], 'Multiple simple CalDAV display alarms must stay editable in the shared reminder model.');
assertSameValue(
    [['minutesBeforeStart' => 15], ['minutesBeforeStart' => 30]],
    $complexAlarmEvent['reminder']['reminders'],
    'Multiple CalDAV display alarm offsets must survive parsing without loss.'
);
$multipleAlarmUpdated = ICalendarCodec::updateEvent(
    $complexAlarmFixture,
    'complex-alarm@example.com',
    [
        'reminder' => [
            'mode'      => 'multiple',
            'reminders' => [
                ['minutesBeforeStart' => 20],
                ['minutesBeforeStart' => 120]
            ]
        ]
    ]
);
assertTrueValue(
    substr_count($multipleAlarmUpdated, 'BEGIN:VALARM') === 2
        && str_contains($multipleAlarmUpdated, 'TRIGGER:-PT20M')
        && str_contains($multipleAlarmUpdated, 'TRIGGER:-PT120M'),
    'Editing multiple CalDAV reminders must replace all supported alarms with the requested triggers.'
);

$complexAlarmFixture = str_replace(
    "ACTION:DISPLAY\r\nDESCRIPTION:Second",
    "ACTION:EMAIL\r\nDESCRIPTION:Second",
    $complexAlarmFixture
);
$complexAlarmEvent = ICalendarCodec::parseEvents(
    $complexAlarmFixture,
    'https://calendar.example/work/complex-email.ics',
    '"complex-email"'
)[0];
assertSameValue('complex', $complexAlarmEvent['reminder']['mode'], 'Unsupported CalDAV alarm types must remain protected as complex reminder settings.');
assertSameValue(false, $complexAlarmEvent['reminder']['editable'], 'Complex CalDAV alarms must not be exposed as editable.');
try {
    ICalendarCodec::updateEvent(
        $complexAlarmFixture,
        'complex-alarm@example.com',
        ['reminder' => ['mode' => 'none']]
    );
    throw new RuntimeException('Complex CalDAV alarms were replaced destructively.');
} catch (RuntimeException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'cannot be edited safely'),
        'Complex CalDAV alarms must be protected from lossy reminder edits.'
    );
}

$calDavAllDayRecurringCreated = ICalendarCodec::createEvent([
    'summary'    => 'CalDAV yearly day',
    'allDay'     => true,
    'start'      => '2026-08-15',
    'end'        => '2026-08-16',
    'recurrence' => [
        'frequency' => 'yearly',
        'interval'  => 1,
        'endMode'   => 'count',
        'count'     => 3
    ]
]);
assertTrueValue(
    str_contains($calDavAllDayRecurringCreated['ical'], 'DTSTART;VALUE=DATE:20260815')
        && str_contains($calDavAllDayRecurringCreated['ical'], 'RRULE:FREQ=YEARLY;COUNT=3')
        && !str_contains($calDavAllDayRecurringCreated['ical'], 'BEGIN:VTIMEZONE'),
    'All-day recurring iCalendar events must use DATE values without a timezone component.'
);

$calDavOccurrenceFixture = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:caldav-series@example.com
DTSTAMP:20260801T000000Z
SEQUENCE:1
DTSTART;TZID=Europe/Berlin:20260817T100000
DTEND;TZID=Europe/Berlin:20260817T110000
RRULE:FREQ=WEEKLY;COUNT=4
SUMMARY:Weekly master
LOCATION:Room A
END:VEVENT
END:VCALENDAR
ICS;
$calDavOccurrenceFixture = str_replace("\n", "\r\n", $calDavOccurrenceFixture) . "\r\n";
assertSameValue(
    true,
    ICalendarCodec::hasRecurringEvent($calDavOccurrenceFixture, 'caldav-series@example.com'),
    'Recurring iCalendar resources must be detectable before occurrence writes.'
);
$calDavOccurrenceUpdated = ICalendarCodec::updateRecurringOccurrence(
    $calDavOccurrenceFixture,
    'caldav-series@example.com',
    '2026-08-24T10:00:00+02:00',
    [
        'summary' => 'Changed occurrence',
        'allDay'  => false,
        'start'   => '2026-08-24T12:00:00+02:00',
        'end'     => '2026-08-24T13:00:00+02:00'
    ]
);
assertTrueValue(
    str_contains($calDavOccurrenceUpdated, 'RRULE:FREQ=WEEKLY;COUNT=4')
        && str_contains(
            $calDavOccurrenceUpdated,
            'RECURRENCE-ID;TZID=Europe/Berlin:20260824T100000'
        )
        && str_contains($calDavOccurrenceUpdated, 'DTSTART;TZID=Europe/Berlin:20260824T120000')
        && str_contains($calDavOccurrenceUpdated, 'SUMMARY:Changed occurrence'),
    'Updating one CalDAV occurrence must add a detached override while preserving the recurring master.'
);
$calDavOccurrenceUpdatedAgain = ICalendarCodec::updateRecurringOccurrence(
    $calDavOccurrenceUpdated,
    'caldav-series@example.com',
    '2026-08-24T10:00:00+02:00',
    ['summary' => 'Changed occurrence again']
);
assertSameValue(
    1,
    substr_count($calDavOccurrenceUpdatedAgain, 'RECURRENCE-ID;TZID=Europe/Berlin:20260824T100000'),
    'Updating an existing CalDAV override must not create duplicate detached instances.'
);
assertTrueValue(
    str_contains($calDavOccurrenceUpdatedAgain, 'SUMMARY:Changed occurrence again'),
    'An existing detached CalDAV occurrence must remain editable.'
);
$calDavSeriesUpdated = ICalendarCodec::updateRecurringSeries(
    $calDavOccurrenceUpdatedAgain,
    'caldav-series@example.com',
    [
        'summary'    => 'Changed complete series',
        'allDay'     => false,
        'start'      => '2026-08-17T11:00:00+02:00',
        'end'        => '2026-08-17T12:00:00+02:00',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 2,
            'byDay'     => ['MO'],
            'endMode'   => 'count',
            'count'     => 3
        ]
    ]
);
assertTrueValue(
    str_contains($calDavSeriesUpdated, 'SUMMARY:Changed complete series')
        && str_contains($calDavSeriesUpdated, 'DTSTART;TZID=Europe/Berlin:20260817T110000')
        && str_contains($calDavSeriesUpdated, 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO;COUNT=3')
        && str_contains($calDavSeriesUpdated, 'RECURRENCE-ID;TZID=Europe/Berlin:20260824T100000')
        && str_contains($calDavSeriesUpdated, 'SUMMARY:Changed occurrence again'),
    'Updating the complete CalDAV series must change the master while retaining detached occurrence overrides.'
);
$calDavOccurrenceDeleted = ICalendarCodec::deleteRecurringOccurrence(
    $calDavOccurrenceUpdatedAgain,
    'caldav-series@example.com',
    '2026-08-24T10:00:00+02:00'
);
assertTrueValue(
    str_contains($calDavOccurrenceDeleted, 'EXDATE;TZID=Europe/Berlin:20260824T100000')
        && !str_contains($calDavOccurrenceDeleted, 'RECURRENCE-ID;TZID=Europe/Berlin:20260824T100000')
        && str_contains($calDavOccurrenceDeleted, 'RRULE:FREQ=WEEKLY;COUNT=4'),
    'Deleting one CalDAV occurrence must exclude it from the master and remove a matching detached override.'
);
$calDavFirstOccurrenceDeleted = ICalendarCodec::deleteRecurringOccurrence(
    $calDavOccurrenceFixture,
    'caldav-series@example.com',
    ''
);
assertTrueValue(
    str_contains($calDavFirstOccurrenceDeleted, 'EXDATE;TZID=Europe/Berlin:20260817T100000')
        && str_contains($calDavFirstOccurrenceDeleted, 'DTSTART;TZID=Europe/Berlin:20260817T100000'),
    'Deleting the first recurrence instance must retain the master DTSTART and exclude it with EXDATE.'
);
$calDavAllDayOccurrenceFixture = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:caldav-allday@example.com
DTSTART;VALUE=DATE:20260815
DTEND;VALUE=DATE:20260816
RRULE:FREQ=DAILY;COUNT=3
SUMMARY:All-day series
END:VEVENT
END:VCALENDAR
ICS;
$calDavAllDayOccurrenceFixture = str_replace("\n", "\r\n", $calDavAllDayOccurrenceFixture) . "\r\n";
$calDavAllDayOccurrenceUpdated = ICalendarCodec::updateRecurringOccurrence(
    $calDavAllDayOccurrenceFixture,
    'caldav-allday@example.com',
    '2026-08-16',
    ['summary' => 'All-day exception']
);
assertTrueValue(
    str_contains($calDavAllDayOccurrenceUpdated, 'RECURRENCE-ID;VALUE=DATE:20260816')
        && str_contains($calDavAllDayOccurrenceUpdated, 'DTSTART;VALUE=DATE:20260816'),
    'All-day CalDAV occurrence overrides must keep DATE-valued recurrence identities.'
);

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
assertSameValue(
    16 * 1024 * 1024,
    $feedClient->requests[0]['maxResponseBytes'],
    'Remote iCalendar feeds must enforce their size limit while downloading.'
);
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
assertSameValue('occurrence', $recurringEvents[3]['recurrenceType'], 'Generated RFC occurrences must use the shared recurrence type.');
assertSameValue('weekly-series@example.com', $recurringEvents[3]['seriesId'], 'RFC occurrences must retain their series UID.');
assertSameValue('20260413T100000', $recurringEvents[3]['recurrenceId'], 'RFC recurrence IDs must remain provider-native.');
assertSameValue(
    true,
    $recurringEvents[3]['recurrenceExpansionSupported'],
    'Supported RFC recurrence rules must remain marked as safely expandable.'
);

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

$recurrenceSafetyFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:supported-byhour@example.com\r\n"
    . "DTSTART:20260701T090000Z\r\n"
    . "DTEND:20260701T100000Z\r\n"
    . "RRULE:FREQ=DAILY;BYHOUR=9,17;COUNT=4\r\n"
    . "RDATE:20260705T090000Z\r\n"
    . "SUMMARY:Supported BYHOUR\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:supported-year-day@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260410\r\n"
    . "DTEND;VALUE=DATE:20260411\r\n"
    . "RRULE:FREQ=YEARLY;BYYEARDAY=100;COUNT=2\r\n"
    . "SUMMARY:Supported BYYEARDAY\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:supported-yearly-monthday@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260115\r\n"
    . "DTEND;VALUE=DATE:20260116\r\n"
    . "RRULE:FREQ=YEARLY;BYMONTHDAY=15;COUNT=2\r\n"
    . "SUMMARY:Supported yearly BYMONTHDAY expansion\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:supported-hourly@example.com\r\n"
    . "DTSTART:20260701T120000Z\r\n"
    . "DTEND:20260701T123000Z\r\n"
    . "RRULE:FREQ=HOURLY;COUNT=4\r\n"
    . "SUMMARY:Supported hourly frequency\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:unsupported-extension@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260701\r\n"
    . "DTEND;VALUE=DATE:20260702\r\n"
    . "RRULE:FREQ=DAILY;X-OPEN-CALENDAR-TEST=1;COUNT=3\r\n"
    . "SUMMARY:Unsupported extension\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$recurrenceSafetyEvents = ICalendarCodec::parseEventsInRange(
    $recurrenceSafetyFeed,
    'https://calendar.example/unsupported-recurrence.ics',
    '',
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
    new DateTimeImmutable('2028-01-02T00:00:00Z')
);
$supportedByHourEvents = array_values(array_filter(
    $recurrenceSafetyEvents,
    static fn (array $event): bool => $event['uid'] === 'supported-byhour@example.com'
));
$supportedYearDayEvents = array_values(array_filter(
    $recurrenceSafetyEvents,
    static fn (array $event): bool => $event['uid'] === 'supported-year-day@example.com'
));
$supportedYearlyMonthDayEvents = array_values(array_filter(
    $recurrenceSafetyEvents,
    static fn (array $event): bool => $event['uid'] === 'supported-yearly-monthday@example.com'
));
$supportedHourlyEvents = array_values(array_filter(
    $recurrenceSafetyEvents,
    static fn (array $event): bool => $event['uid'] === 'supported-hourly@example.com'
));
$unsupportedExtensionEvents = array_values(array_filter(
    $recurrenceSafetyEvents,
    static fn (array $event): bool => $event['uid'] === 'unsupported-extension@example.com'
));
assertSameValue(
    [
        '2026-07-01T09:00:00+00:00',
        '2026-07-01T17:00:00+00:00',
        '2026-07-02T09:00:00+00:00',
        '2026-07-02T17:00:00+00:00',
        '2026-07-05T09:00:00+00:00'
    ],
    array_column($supportedByHourEvents, 'start'),
    'BYHOUR rules must expand each requested hour and retain explicit RDATE values.'
);
assertSameValue(
    true,
    $supportedByHourEvents[0]['recurrenceExpansionSupported'],
    'BYHOUR recurrence rules must be marked as safely expandable.'
);
assertSameValue(
    [],
    $supportedByHourEvents[0]['recurrenceUnsupportedRuleParts'] ?? [],
    'Supported BYHOUR rules must not report unsupported rule parts.'
);
assertSameValue(
    ['2026-04-10', '2027-04-10'],
    array_column($supportedYearDayEvents, 'start'),
    'BYYEARDAY rules must expand the requested ordinal day of each year.'
);
assertSameValue(
    true,
    $supportedYearDayEvents[0]['recurrenceExpansionSupported'],
    'BYYEARDAY rules must be marked as safely expandable.'
);
assertSameValue(
    [],
    $supportedYearDayEvents[0]['recurrenceUnsupportedRuleParts'] ?? [],
    'Supported BYYEARDAY rules must not report unsupported rule parts.'
);
assertSameValue(
    ['2026-01-15', '2026-02-15'],
    array_column($supportedYearlyMonthDayEvents, 'start'),
    'YEARLY BYMONTHDAY rules must expand the requested month day across the recurrence year.'
);
assertSameValue(
    true,
    $supportedYearlyMonthDayEvents[0]['recurrenceExpansionSupported'],
    'YEARLY BYMONTHDAY rules must be marked as safely expandable.'
);
assertSameValue(
    [
        '2026-07-01T12:00:00+00:00',
        '2026-07-01T13:00:00+00:00',
        '2026-07-01T14:00:00+00:00',
        '2026-07-01T15:00:00+00:00'
    ],
    array_column($supportedHourlyEvents, 'start'),
    'HOURLY recurrence rules must expand from DTSTART at hourly frequency.'
);
assertSameValue(
    true,
    $supportedHourlyEvents[0]['recurrenceExpansionSupported'],
    'HOURLY recurrence rules must be marked as safely expandable.'
);
assertSameValue(
    [],
    $supportedHourlyEvents[0]['recurrenceUnsupportedRuleParts'] ?? [],
    'Supported HOURLY rules must not report unsupported rule parts.'
);
assertSameValue(
    ['2026-07-01'],
    array_column($unsupportedExtensionEvents, 'start'),
    'Unknown recurrence extensions must not be ignored while generating occurrences.'
);
assertSameValue(
    ['X-OPEN-CALENDAR-TEST'],
    $unsupportedExtensionEvents[0]['recurrenceUnsupportedRuleParts'],
    'Unknown recurrence extensions must remain explicitly identifiable.'
);

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
$calDavProviderSource = file_get_contents(__DIR__ . '/../libs/CalDAVProvider.php');
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
        && str_contains($accountModuleSource, 'OAUTH_DISPATCHER_RECHECK_MS = 60_000')
        && str_contains($accountModuleSource, 'OAUTH_PENDING_TIMEOUT_SECONDS = 900')
        && str_contains($accountModuleSource, 'private function oauthDispatcherId(): int')
        && str_contains($accountModuleSource, "case 'InternalOAuthComplete':")
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
        && !str_contains($accountGoogleOAuthSource, 'RegisterOAuth(')
        && str_contains($accountGoogleOAuthSource, 'requestOAuthDispatch(self::PROVIDER_GOOGLE)')
        && str_contains($accountGoogleOAuthSource, 'private function processGoogleOAuthData(array $oauthData): void'),
    'OAuth registration must be deferred and callbacks must be routed through one account dispatcher.'
);
assertTrueValue(
    is_string($calendarModuleSource)
        && str_contains($calendarModuleSource, 'RegisterMessage(0, IPS_KERNELSTARTED)')
        && str_contains($calendarModuleSource, "RegisterTimer('InitializationTimer'")
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('RuntimeReady', false)")
        && str_contains($calendarModuleSource, 'IPS_GetKernelRunlevel() !== KR_READY'),
    'The calendar module must defer parent communication until the kernel is ready.'
);
$calDavProviderDeclaration = '';
if (
    is_string($calDavProviderSource)
    && preg_match('/final class CalDAVProvider implements[^{]+/', $calDavProviderSource, $calDavProviderDeclarationMatch) === 1
) {
    $calDavProviderDeclaration = $calDavProviderDeclarationMatch[0];
}
assertTrueValue(
    is_string($calDavProviderSource)
        && str_contains($calDavProviderDeclaration, 'CalendarEventLookupProviderInterface')
        && str_contains($calDavProviderDeclaration, 'CalendarProviderInterface')
        && str_contains($calDavProviderDeclaration, 'RecurringCalendarProviderInterface')
        && str_contains($calDavProviderSource, 'public function getRecurringSeries(')
        && str_contains($calDavProviderSource, '<c:prop-filter name="UID">')
        && str_contains($calDavProviderSource, '<c:text-match collation="i;octet">')
        && str_contains($calDavProviderSource, "'updateSeries'      => \$canWrite")
        && str_contains($calDavProviderSource, "'deleteSeries'      => \$canWrite")
        && str_contains($calDavProviderSource, "'writeStatus'       => \$canWrite")
        && str_contains($calDavProviderSource, "'writeTransparency' => \$canWrite")
        && str_contains($calDavProviderSource, "'defaultStatus'                 => CalendarEventState::STATUS_CONFIRMED")
        && str_contains($calDavProviderSource, "'defaultTransparency'           => CalendarEventState::TRANSP_OPAQUE")
        && str_contains($calDavProviderSource, "'defaultAllDayTransparency'     => CalendarEventState::TRANSP_OPAQUE")
        && str_contains($calDavProviderSource, 'ICalendarCodec::updateRecurringSeries(')
        && str_contains($calDavProviderSource, 'CalendarEventRecurrence::WRITE_SCOPE_SERIES'),
    'CalDAV must expose verified full-series lookup, editing and deletion while keeping following writes separate.'
);
assertTrueValue(
    is_string($accountModuleSource)
        && str_contains($accountModuleSource, "array_key_exists('writeStatus', \$capabilities)")
        && str_contains($accountModuleSource, "array_key_exists('writeTransparency', \$capabilities)")
        && str_contains($accountModuleSource, "'defaultStatus'] = CalendarEventState::STATUS_CONFIRMED")
        && str_contains($accountModuleSource, "'defaultTransparency'] = CalendarEventState::TRANSP_OPAQUE")
        && str_contains($accountModuleSource, '$provider === self::PROVIDER_MICROSOFT')
        && str_contains($accountModuleSource, 'CalendarEventState::TRANSP_TRANSPARENT'),
    'Cached account calendars must gain provider event-state capabilities and defaults without manual rediscovery.'
);

assertTrueValue(
    is_string($calendarModuleSource)
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('DetectedCanWriteStatus', false)")
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('DetectedCanWriteTransparency', false)")
        && str_contains($calendarModuleSource, "RegisterAttributeString('DetectedDefaultStatus', CalendarEventState::STATUS_CONFIRMED)")
        && str_contains($calendarModuleSource, "\$capabilities['writeStatus'] ?? false")
        && str_contains($calendarModuleSource, "\$capabilities['writeTransparency'] ?? false")
        && preg_match('/\'canWriteStatus\'\s*=>\s*\$metadataAvailable/', $calendarModuleSource) === 1
        && preg_match('/\'canWriteTransparency\'\s*=>\s*\$metadataAvailable/', $calendarModuleSource) === 1
        && preg_match('/\'defaultAllDayTransparency\'\s*=>\s*\$metadataAvailable/', $calendarModuleSource) === 1,
    'Calendar instances must persist and expose provider-neutral event-state capabilities and defaults.'
);

assertTrueValue(
    is_string($calendarModuleSource)
        && substr_count($calendarModuleSource, 'CalendarEventState::filterVisibleEvents($events)') >= 3
        && str_contains($calendarModuleSource, 'return CalendarEventState::filterVisibleEvents($events);')
        && str_contains($calendarModuleSource, 'private function assertEventAvailable(array $event): void')
        && str_contains($calendarModuleSource, 'CalendarEventState::isCancelled($event[\'status\'] ?? \'\')')
        && substr_count($calendarModuleSource, '$this->assertEventAvailable(') >= 3,
    'Calendar instances must hide cancelled events from synchronization, cached reads, writes, and direct edit preparation.'
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
    is_string($calendarModuleSource)
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('DetectedCanCreateRecurrence', false)")
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('DetectedCanUpdateRecurrence', false)")
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('DetectedCanUpdateOccurrence', false)")
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('DetectedCanDeleteOccurrence', false)")
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('DetectedCanUpdateFollowing', false)")
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('DetectedCanUpdateSeries', false)")
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('DetectedCanDeleteSeries', false)")
        && str_contains($calendarModuleSource, "RegisterAttributeString('DetectedCalendarTimezone', '')")
        && str_contains($calendarModuleSource, "\$capabilities['createRecurrence'] ?? false")
        && str_contains($calendarModuleSource, "\$capabilities['updateRecurrence'] ?? false")
        && str_contains($calendarModuleSource, "\$capabilities['updateOccurrence'] ?? false")
        && str_contains($calendarModuleSource, "\$capabilities['deleteOccurrence'] ?? false")
        && str_contains($calendarModuleSource, "\$capabilities['updateFollowing'] ?? false")
        && str_contains($calendarModuleSource, "\$capabilities['updateSeries'] ?? false")
        && str_contains($calendarModuleSource, "\$capabilities['deleteSeries'] ?? false")
        && preg_match('/\'canCreateRecurrence\'\s+=> \$metadataAvailable/', $calendarModuleSource) === 1
        && preg_match('/\'canUpdateRecurrence\'\s+=> \$metadataAvailable/', $calendarModuleSource) === 1
        && preg_match('/\'canUpdateOccurrence\'\s+=> \$metadataAvailable/', $calendarModuleSource) === 1
        && preg_match('/\'canDeleteOccurrence\'\s+=> \$metadataAvailable/', $calendarModuleSource) === 1
        && str_contains($calendarModuleSource, "'canUpdateFollowing'")
        && str_contains($calendarModuleSource, "ReadAttributeBoolean('DetectedCanUpdateFollowing')")
        && preg_match('/\'canUpdateSeries\'\s+=> \$metadataAvailable/', $calendarModuleSource) === 1
        && preg_match('/\'canDeleteSeries\'\s+=> \$metadataAvailable/', $calendarModuleSource) === 1
        && preg_match('/\'timezone\'\s+=> \$metadataAvailable/', $calendarModuleSource) === 1
        && str_contains($calendarModuleSource, "(!\$updating && !\$this->ReadAttributeBoolean('DetectedCanDeleteSeries'))")
        && str_contains($calendarModuleSource, "\$identity['canDeleteSeries'] = true;")
        && str_contains($calendarModuleSource, "trim((string) (\$cachedEvent['originalStart'] ?? '')) === ''")
        && str_contains($calendarModuleSource, "\$cachedEvent['originalStart'] = trim((string) \$event['originalStart']);")
        && str_contains($calendarModuleSource, "\$matchesOccurrence = \$occurrenceId !== ''")
        && str_contains($calendarModuleSource, "\$matchesResource = \$occurrenceId === ''")
        && str_contains($calendarModuleSource, 'if ($matchesOccurrence || $matchesResource)')
        && str_contains($calendarModuleSource, "\$cachedEvent['canUpdateOccurrence'] = true;")
        && str_contains($calendarModuleSource, "\$cachedEvent['canDeleteOccurrence'] = true;")
        && str_contains($calendarModuleSource, 'Recurring event creation is not supported by this calendar.')
        && str_contains($calendarModuleSource, 'Converting this event into a recurring series is not supported by this calendar.')
        && str_contains($calendarModuleSource, "ReadAttributeBoolean('DetectedCanUpdateRecurrence')")
        && str_contains($calendarModuleSource, 'This and following updates are not supported by this calendar.'),
    'Calendar instances must expose recurring create/following/series capabilities and calendar timezone while blocking unsupported recurring writes.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && preg_match('/\'canCreateRecurrence\'\s+=> \(bool\) \(\$calendarStatus\[\'canCreateRecurrence\'\] \?\? false\)/', $viewModuleSource) === 1
        && preg_match('/\'canUpdateRecurrence\'\s+=> \(bool\) \(\$calendarStatus\[\'canUpdateRecurrence\'\] \?\? false\)/', $viewModuleSource) === 1
        && preg_match('/\'canUpdateOccurrence\'\s+=> \(bool\) \(\$calendarStatus\[\'canUpdateOccurrence\'\] \?\? false\)/', $viewModuleSource) === 1
        && preg_match('/\'canDeleteOccurrence\'\s+=> \(bool\) \(\$calendarStatus\[\'canDeleteOccurrence\'\] \?\? false\)/', $viewModuleSource) === 1
        && preg_match('/\'canUpdateFollowing\'\s+=> \(bool\) \(\$calendarStatus\[\'canUpdateFollowing\'\] \?\? false\)/', $viewModuleSource) === 1
        && preg_match('/\'canUpdateSeries\'\s+=> \(bool\) \(\$calendarStatus\[\'canUpdateSeries\'\] \?\? false\)/', $viewModuleSource) === 1
        && preg_match('/\'canDeleteSeries\'\s+=> \(bool\) \(\$calendarStatus\[\'canDeleteSeries\'\] \?\? false\)/', $viewModuleSource) === 1
        && preg_match('/\'timezone\'\s+=> trim\(\(string\) \(\$calendarStatus\[\'timezone\'\] \?\? \'\'\)\)/', $viewModuleSource) === 1
        && str_contains($viewModuleSource, "\$event['canUpdateOccurrence'] = (bool) (\$event['canUpdateOccurrence'] ?? false)")
        && str_contains($viewModuleSource, "\$event['canDeleteOccurrence'] = (bool) (\$event['canDeleteOccurrence'] ?? false)")
        && str_contains($viewModuleSource, "\$event['canUpdateFollowing'] = (bool) (\$event['canUpdateFollowing'] ?? false)")
        && str_contains($viewModuleSource, "\$event['canDeleteSeries'] = (bool) (\$event['canDeleteSeries'] ?? false)"),
    'Calendar views must pass recurrence capability and timezone metadata to Tile and IPSView clients.'
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
        && str_contains($viewModuleSource, "\$sourceRecurring = (bool) (\$sourceEvent['recurring'] ?? false);")
        && str_contains($viewModuleSource, "in_array(\$writeScope, ['occurrence', 'following', 'series'], true)")
        && str_contains($viewModuleSource, '$this->requireRecurrenceCreationCalendar($targetInstanceId);')
        && str_contains($viewModuleSource, 'IPSKAL_CreateEvent(')
        && str_contains($viewModuleSource, "json_encode(\n                        \$sourceEvent,")
        && str_contains($viewModuleSource, '$this->rollbackMovedTargetEvent($targetInstanceId, $creationResult, $targetRecurring)')
        && str_contains($viewModuleSource, "'Event moved.'")
        && !str_contains($viewModuleSource, 'Recurring events cannot be moved yet.')
        && str_contains($viewModuleSource, 'The event was created in the target calendar, but could not be deleted from the source calendar.'),
    'Moving an event must support recurring write scopes, create the target copy before deleting the source, and roll the target back when source deletion fails.'
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
        && str_contains($viewModuleSource, 'use Burki24\\SymconModuleHelper\\IPSViewStyleConfigurationHelper;')
        && str_contains($viewModuleSource, "require_once __DIR__ . '/../libs/helper/IPSViewStyleConfigurationHelper.php';")
        && str_contains($viewModuleSource, 'use IPSViewStyleConfigurationHelper;')
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
    'The calendar view must consume IPSViewStyleConfigurationHelper without replacing calendar event colors.'
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
        && str_contains($viewScriptSource, 'const showAddButton = actionBridgeAvailable')
        && str_contains($viewScriptSource, '&& listControlsVisible()')
        && str_contains($viewScriptSource, '&& [\'agenda\', \'list\'].includes(activeView);'),
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
