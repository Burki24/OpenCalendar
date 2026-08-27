<?php

declare(strict_types=1);

use IPSKalender\CalDAVProvider;
use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\ICalendarCodec;
use IPSKalender\MicrosoftCalendarProvider;

require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/MicrosoftCalendarProvider.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';

final class ProviderTimezoneDstHttpClient implements CalendarHttpClientInterface
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
            throw new RuntimeException('No provider timezone/DST response was queued.');
        }

        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

function providerTimezoneDstResponse(
    int $statusCode,
    array|string $body = '',
    array $headers = [],
    string $effectiveUrl = 'https://example.test'
): CalendarHttpResponse {
    return new CalendarHttpResponse(
        $statusCode,
        $headers,
        is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body,
        $effectiveUrl
    );
}

function assertProviderTimezoneDst(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL
                . 'Expected: ' . var_export($expected, true) . PHP_EOL
                . 'Actual:   ' . var_export($actual, true)
        );
    }
}

/**
 * @param list<array<string, mixed>> $events
 * @return list<string>
 */
function providerTimezoneDstBerlinStarts(array $events): array
{
    $timezone = new DateTimeZone('Europe/Berlin');

    return array_map(
        static fn (array $event): string => (new DateTimeImmutable('@' . (int) $event['startTimestamp']))
            ->setTimezone($timezone)
            ->format(DATE_ATOM),
        $events
    );
}

$recurrence = [
    'frequency' => 'daily',
    'interval'  => 1,
    'endMode'   => 'count',
    'count'     => 3
];
$event = [
    'summary'    => 'DST provider series',
    'allDay'     => false,
    'start'      => '2026-03-28T09:00:00+01:00',
    'end'        => '2026-03-28T10:00:00+01:00',
    'timezone'   => 'Europe/Berlin',
    'recurrence' => $recurrence
];
$expectedStarts = [
    '2026-03-28T09:00:00+01:00',
    '2026-03-29T09:00:00+02:00',
    '2026-03-30T09:00:00+02:00'
];
$rangeStart = new DateTimeImmutable('2026-03-27T00:00:00Z');
$rangeEnd = new DateTimeImmutable('2026-04-01T00:00:00Z');

$googleClient = new ProviderTimezoneDstHttpClient([
    providerTimezoneDstResponse(200, [
        'id'      => 'google-dst-series',
        'iCalUID' => 'google-dst-series@example.com',
        'etag'    => '"google-dst"'
    ]),
    providerTimezoneDstResponse(200, [
        'timeZone' => 'Europe/Berlin',
        'items'    => [
            [
                'id'                => 'google-dst-1',
                'iCalUID'           => 'google-dst-series@example.com',
                'summary'           => 'DST provider series',
                'recurringEventId'  => 'google-dst-series',
                'originalStartTime' => [
                    'dateTime' => '2026-03-28T09:00:00+01:00',
                    'timeZone' => 'Europe/Berlin'
                ],
                'start' => ['dateTime' => '2026-03-28T09:00:00+01:00', 'timeZone' => 'Europe/Berlin'],
                'end'   => ['dateTime' => '2026-03-28T10:00:00+01:00', 'timeZone' => 'Europe/Berlin']
            ],
            [
                'id'                => 'google-dst-2',
                'iCalUID'           => 'google-dst-series@example.com',
                'summary'           => 'DST provider series',
                'recurringEventId'  => 'google-dst-series',
                'originalStartTime' => [
                    'dateTime' => '2026-03-29T09:00:00+02:00',
                    'timeZone' => 'Europe/Berlin'
                ],
                'start' => ['dateTime' => '2026-03-29T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
                'end'   => ['dateTime' => '2026-03-29T10:00:00+02:00', 'timeZone' => 'Europe/Berlin']
            ],
            [
                'id'                => 'google-dst-3',
                'iCalUID'           => 'google-dst-series@example.com',
                'summary'           => 'DST provider series',
                'recurringEventId'  => 'google-dst-series',
                'originalStartTime' => [
                    'dateTime' => '2026-03-30T09:00:00+02:00',
                    'timeZone' => 'Europe/Berlin'
                ],
                'start' => ['dateTime' => '2026-03-30T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
                'end'   => ['dateTime' => '2026-03-30T10:00:00+02:00', 'timeZone' => 'Europe/Berlin']
            ]
        ]
    ])
]);
$googleProvider = new GoogleCalendarProvider($googleClient, 'access-token');
$googleProvider->createEvent('owner@example.com', $event);
$googleBody = json_decode($googleClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
assertProviderTimezoneDst(
    ['dateTime' => '2026-03-28T09:00:00+01:00', 'timeZone' => 'Europe/Berlin'],
    $googleBody['start'],
    'Google recurring writes must preserve the local start time and IANA timezone before DST.'
);
assertProviderTimezoneDst(
    ['RRULE:FREQ=DAILY;COUNT=3'],
    $googleBody['recurrence'],
    'Google recurring writes must keep the provider-neutral daily recurrence count.'
);
$googleEvents = $googleProvider->getEvents('owner@example.com', $rangeStart, $rangeEnd);
assertProviderTimezoneDst(
    $expectedStarts,
    providerTimezoneDstBerlinStarts($googleEvents),
    'Google readback must retain one local wall-clock start across the DST transition.'
);
assertProviderTimezoneDst(
    ['Europe/Berlin', 'Europe/Berlin', 'Europe/Berlin'],
    array_column($googleEvents, 'timezone'),
    'Google readback must retain the recurring IANA timezone across DST.'
);

$microsoftClient = new ProviderTimezoneDstHttpClient([
    providerTimezoneDstResponse(201, [
        'id'          => 'microsoft-dst-series',
        'iCalUId'     => 'microsoft-dst-series@example.com',
        '@odata.etag' => 'W/"microsoft-dst"'
    ]),
    providerTimezoneDstResponse(200, [
        'value' => [
            [
                'id'                    => 'microsoft-dst-1',
                'iCalUId'               => 'microsoft-dst-series@example.com',
                'subject'               => 'DST provider series',
                'type'                  => 'occurrence',
                'seriesMasterId'        => 'microsoft-dst-series',
                'originalStartTimeZone' => 'Europe/Berlin',
                'start'                 => ['dateTime' => '2026-03-28T08:00:00.0000000', 'timeZone' => 'UTC'],
                'end'                   => ['dateTime' => '2026-03-28T09:00:00.0000000', 'timeZone' => 'UTC']
            ],
            [
                'id'                    => 'microsoft-dst-2',
                'iCalUId'               => 'microsoft-dst-series@example.com',
                'subject'               => 'DST provider series',
                'type'                  => 'occurrence',
                'seriesMasterId'        => 'microsoft-dst-series',
                'originalStartTimeZone' => 'Europe/Berlin',
                'start'                 => ['dateTime' => '2026-03-29T07:00:00.0000000', 'timeZone' => 'UTC'],
                'end'                   => ['dateTime' => '2026-03-29T08:00:00.0000000', 'timeZone' => 'UTC']
            ],
            [
                'id'                    => 'microsoft-dst-3',
                'iCalUId'               => 'microsoft-dst-series@example.com',
                'subject'               => 'DST provider series',
                'type'                  => 'occurrence',
                'seriesMasterId'        => 'microsoft-dst-series',
                'originalStartTimeZone' => 'Europe/Berlin',
                'start'                 => ['dateTime' => '2026-03-30T07:00:00.0000000', 'timeZone' => 'UTC'],
                'end'                   => ['dateTime' => '2026-03-30T08:00:00.0000000', 'timeZone' => 'UTC']
            ]
        ]
    ])
]);
$microsoftProvider = new MicrosoftCalendarProvider($microsoftClient, 'access-token');
$microsoftProvider->createEvent('AQMk-primary', $event);
$microsoftBody = json_decode($microsoftClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
assertProviderTimezoneDst(
    ['dateTime' => '2026-03-28T09:00:00', 'timeZone' => 'Europe/Berlin'],
    $microsoftBody['start'],
    'Microsoft recurring writes must preserve the local start time and IANA timezone before DST.'
);
assertProviderTimezoneDst(
    [
        'type'                => 'numbered',
        'startDate'           => '2026-03-28',
        'numberOfOccurrences' => 3
    ],
    $microsoftBody['recurrence']['range'],
    'Microsoft recurring writes must keep the provider-neutral occurrence count.'
);
$microsoftEvents = $microsoftProvider->getEvents('AQMk-primary', $rangeStart, $rangeEnd);
assertProviderTimezoneDst(
    $expectedStarts,
    providerTimezoneDstBerlinStarts($microsoftEvents),
    'Microsoft UTC calendar-view readback must map back to one local wall-clock start across DST.'
);
assertProviderTimezoneDst(
    ['Europe/Berlin', 'Europe/Berlin', 'Europe/Berlin'],
    array_column($microsoftEvents, 'timezone'),
    'Microsoft readback must retain originalStartTimeZone across DST.'
);

$calDavClient = new ProviderTimezoneDstHttpClient([
    providerTimezoneDstResponse(201, '', ['etag' => '"caldav-dst"'], '')
]);
$calDavProvider = new CalDAVProvider($calDavClient, 'https://calendar.example/dav/');
$calDavProvider->createEvent('https://calendar.example/calendars/user/work/', $event);
$calDavBody = $calDavClient->requests[0]['body'];
assertProviderTimezoneDst(
    true,
    str_contains($calDavBody, 'DTSTART;TZID=Europe/Berlin:20260328T090000')
        && str_contains($calDavBody, 'DTEND;TZID=Europe/Berlin:20260328T100000')
        && str_contains($calDavBody, 'RRULE:FREQ=DAILY;COUNT=3')
        && str_contains($calDavBody, 'BEGIN:VTIMEZONE')
        && str_contains($calDavBody, 'TZID:Europe/Berlin'),
    'CalDAV recurring writes must serialize local wall time with a self-contained VTIMEZONE.'
);
$calDavEvents = ICalendarCodec::parseEventsInRange(
    $calDavBody,
    'https://calendar.example/calendars/user/work/dst.ics',
    '"caldav-dst"',
    $rangeStart,
    $rangeEnd
);
assertProviderTimezoneDst(
    $expectedStarts,
    providerTimezoneDstBerlinStarts($calDavEvents),
    'CalDAV create-and-parse roundtrip must retain one local wall-clock start across DST.'
);
assertProviderTimezoneDst(
    ['Europe/Berlin', 'Europe/Berlin', 'Europe/Berlin'],
    array_column($calDavEvents, 'timezone'),
    'CalDAV create-and-parse roundtrip must retain the IANA timezone across DST.'
);

fwrite(STDOUT, "Provider timezone/DST parity tests passed.\n");
