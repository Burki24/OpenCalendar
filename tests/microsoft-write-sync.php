<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\MicrosoftCalendarIncrementalSync;

require_once __DIR__ . '/../libs/MicrosoftCalendarIncrementalSync.php';

final class MicrosoftWriteSyncTestHttpClient implements CalendarHttpClientInterface
{
    /** @var list<array{method:string,url:string,headers:array<string, string>}> */
    public array $requests = [];

    /** @var list<CalendarHttpResponse> */
    private array $responses;

    /** @param list<CalendarHttpResponse> $responses */
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
        $this->requests[] = [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers
        ];
        if ($this->responses === []) {
            throw new RuntimeException('The Microsoft write-sync test has no queued response.');
        }

        return array_shift($this->responses);
    }
}

/** @param array<string, mixed> $payload */
function microsoftWriteSyncResponse(array $payload): CalendarHttpResponse
{
    return new CalendarHttpResponse(
        200,
        [],
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ''
    );
}

function microsoftWriteSyncExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function microsoftWriteSyncEvent(string $id, string $summary, string $date): array
{
    return [
        'id'                    => $id,
        'iCalUId'               => $id . '@example.test',
        'type'                  => 'singleInstance',
        'subject'               => $summary,
        'body'                  => ['contentType' => 'text', 'content' => ''],
        'location'              => ['displayName' => ''],
        'start'                 => ['dateTime' => $date . 'T09:00:00.0000000', 'timeZone' => 'UTC'],
        'end'                   => ['dateTime' => $date . 'T10:00:00.0000000', 'timeZone' => 'UTC'],
        'isAllDay'              => false,
        'isCancelled'           => false,
        'isReminderOn'          => false,
        'originalStartTimeZone' => 'UTC',
        '@odata.etag'           => '"' . $id . '-etag"'
    ];
}

/** @return array<string, mixed> */
function microsoftWriteSyncOccurrence(string $id, string $seriesId, string $summary, string $date): array
{
    $event = microsoftWriteSyncEvent($id, $summary, $date);
    $event['type'] = 'occurrence';
    $event['seriesMasterId'] = $seriesId;

    return $event;
}

/** @param list<array{method:string,url:string,headers:array<string, string>}> $requests */
function microsoftWriteSyncAssertImmutableIds(array $requests): void
{
    microsoftWriteSyncExpect($requests !== [], 'The Microsoft write-sync test did not issue any Graph requests.');
    foreach ($requests as $request) {
        $prefer = (string) ($request['headers']['Prefer'] ?? '');
        microsoftWriteSyncExpect(
            str_contains($prefer, 'IdType="ImmutableId"'),
            'Every Microsoft delta and event refresh request must request immutable Graph IDs.'
        );
    }
}

$start = new DateTimeImmutable('2026-08-01T00:00:00+00:00');
$end = new DateTimeImmutable('2027-08-01T00:00:00+00:00');

$directHttp = new MicrosoftWriteSyncTestHttpClient([
    microsoftWriteSyncResponse(microsoftWriteSyncEvent('created-immutable-id', 'Created directly', '2026-08-20'))
]);
$directSynchronizer = new MicrosoftCalendarIncrementalSync($directHttp, 'access-token');
$directEvent = $directSynchronizer->getEventByReference('primary', 'created-immutable-id');
microsoftWriteSyncExpect(
    ($directEvent['eventReference'] ?? '') === 'created-immutable-id'
        && str_ends_with($directHttp->requests[0]['url'] ?? '', '/events/created-immutable-id'),
    'A freshly created Microsoft single event must be reloadable directly by its returned immutable ID.'
);
microsoftWriteSyncAssertImmutableIds($directHttp->requests);

$previousDeltaLink = 'https://graph.microsoft.com/v1.0/me/calendars/primary/calendarView/delta?$deltatoken=before-write';
$singleDeltaLink = 'https://graph.microsoft.com/v1.0/me/calendars/primary/calendarView/delta?$deltatoken=after-single-write';

$singleHttp = new MicrosoftWriteSyncTestHttpClient([
    microsoftWriteSyncResponse([
        'value'            => [[
            'id'       => 'new-single',
            'type'     => 'singleInstance',
            'isAllDay' => false,
            'start'    => ['dateTime' => '2026-08-20T09:00:00.0000000', 'timeZone' => 'UTC'],
            'end'      => ['dateTime' => '2026-08-20T10:00:00.0000000', 'timeZone' => 'UTC']
        ]],
        '@odata.deltaLink' => $singleDeltaLink
    ]),
    microsoftWriteSyncResponse(microsoftWriteSyncEvent('new-single', 'Created in OpenCalendar', '2026-08-20'))
]);
$singleSynchronizer = new MicrosoftCalendarIncrementalSync($singleHttp, 'access-token');
$single = $singleSynchronizer->synchronize('primary', $start, $end, $previousDeltaLink);
microsoftWriteSyncExpect(
    $single['incremental'] === true
        && count($single['items']) === 1
        && ($single['items'][0]['eventReference'] ?? '') === 'new-single',
    'A Microsoft event created after the previous delta token must be returned by incremental synchronization.'
);
microsoftWriteSyncAssertImmutableIds($singleHttp->requests);

$seriesDeltaLink = 'https://graph.microsoft.com/v1.0/me/calendars/primary/calendarView/delta?$deltatoken=after-series-write';
$seriesHttp = new MicrosoftWriteSyncTestHttpClient([
    microsoftWriteSyncResponse([
        'value'            => [[
            'id'       => 'new-series-master',
            'type'     => 'seriesMaster',
            'isAllDay' => false,
            'start'    => ['dateTime' => '2026-08-20T11:00:00.0000000', 'timeZone' => 'UTC'],
            'end'      => ['dateTime' => '2026-08-20T12:00:00.0000000', 'timeZone' => 'UTC']
        ]],
        '@odata.deltaLink' => $seriesDeltaLink
    ]),
    microsoftWriteSyncResponse([
        'value'            => [[
            'id'       => 'new-series-master',
            'type'     => 'seriesMaster',
            'isAllDay' => false,
            'start'    => ['dateTime' => '2026-08-20T11:00:00.0000000', 'timeZone' => 'UTC'],
            'end'      => ['dateTime' => '2026-08-20T12:00:00.0000000', 'timeZone' => 'UTC']
        ]],
        '@odata.deltaLink' => $seriesDeltaLink
    ]),
    microsoftWriteSyncResponse([
        'value' => [
            microsoftWriteSyncOccurrence(
                'new-series-occurrence-1',
                'new-series-master',
                'Created series',
                '2026-08-20'
            ),
            microsoftWriteSyncOccurrence(
                'new-series-occurrence-2',
                'new-series-master',
                'Created series',
                '2026-08-27'
            )
        ]
    ])
]);
$seriesSynchronizer = new MicrosoftCalendarIncrementalSync($seriesHttp, 'access-token');
$series = $seriesSynchronizer->synchronize('primary', $start, $end, $previousDeltaLink);
microsoftWriteSyncExpect(
    $series['incremental'] === false
        && count($series['items']) === 2
        && ($series['items'][0]['seriesId'] ?? '') === 'new-series-master'
        && ($series['items'][1]['seriesId'] ?? '') === 'new-series-master',
    'A newly created Microsoft series must trigger a bounded refresh that returns its concrete occurrences.'
);
microsoftWriteSyncAssertImmutableIds($seriesHttp->requests);

$calendarSource = (string) file_get_contents(__DIR__ . '/../Kalender/module.php');
$refreshStart = strpos($calendarSource, 'private function refreshAfterWrite(): void');
$refreshEnd = $refreshStart === false
    ? false
    : strpos($calendarSource, "\n    /**", $refreshStart);
microsoftWriteSyncExpect(
    $refreshStart !== false && $refreshEnd !== false,
    'The Calendar post-write refresh implementation could not be inspected.'
);
$refreshBody = substr($calendarSource, (int) $refreshStart, (int) $refreshEnd - (int) $refreshStart);
microsoftWriteSyncExpect(
    !str_contains($refreshBody, 'clearIncrementalSyncState()')
        && str_contains($refreshBody, '$events = $this->requestEvents();'),
    'Post-write synchronization must preserve the existing delta token instead of creating a new baseline.'
);
microsoftWriteSyncExpect(
    str_contains($calendarSource, "'GetEventAfterWrite'")
        && str_contains($calendarSource, "['EventReference' => \$eventReference]")
        && str_contains($calendarSource, "'GetEventForEdit'"),
    'Single writes must try the provider-returned event reference directly before the range-based fallback.'
);
$gatewaySource = (string) file_get_contents(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');
microsoftWriteSyncExpect(
    str_contains($gatewaySource, "'GetEventAfterWrite'")
        && str_contains($gatewaySource, 'getEventByReference('),
    'The calendar account gateway must route Microsoft post-write lookups through immutable event references.'
);

fwrite(STDOUT, "Microsoft post-write synchronization tests passed.\n");
