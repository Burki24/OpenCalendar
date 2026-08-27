<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\MicrosoftCalendarIncrementalSync;

require_once __DIR__ . '/../libs/MicrosoftCalendarIncrementalSync.php';

final class MicrosoftIncrementalSyncTestHttpClient implements CalendarHttpClientInterface
{
    /** @var list<string> */
    public array $urls = [];

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
        if ($method !== 'GET') {
            throw new RuntimeException('The Microsoft incremental-sync test only expects GET requests.');
        }
        $this->urls[] = $url;
        if ($this->responses === []) {
            throw new RuntimeException('The Microsoft incremental-sync test has no queued response.');
        }

        return array_shift($this->responses);
    }
}

/** @param array<string, mixed> $payload */
function microsoftSyncResponse(int $statusCode, array $payload): CalendarHttpResponse
{
    return new CalendarHttpResponse(
        $statusCode,
        [],
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ''
    );
}

function microsoftSyncExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function microsoftSyncEvent(string $id, string $summary, string $date): array
{
    return [
        'id'                    => $id,
        'iCalUId'               => $id . '@example.test',
        'type'                  => 'singleInstance',
        'subject'               => $summary,
        'body'                  => ['contentType' => 'text', 'content' => $summary . ' description'],
        'location'              => ['displayName' => 'Office'],
        'start'                 => ['dateTime' => $date . 'T09:00:00.0000000', 'timeZone' => 'UTC'],
        'end'                   => ['dateTime' => $date . 'T10:00:00.0000000', 'timeZone' => 'UTC'],
        'isAllDay'              => false,
        'isCancelled'           => false,
        'isReminderOn'          => false,
        'originalStartTimeZone' => 'UTC',
        'createdDateTime'       => $date . 'T07:00:00Z',
        'lastModifiedDateTime'  => $date . 'T08:00:00Z',
        'webLink'               => 'https://outlook.office.com/calendar/item/' . $id,
        'isOnlineMeeting'       => false,
        '@odata.etag'           => '"' . $id . '-etag"'
    ];
}

/** @return array<string, mixed> */
function microsoftSyncOccurrence(string $id, string $seriesId, string $summary, string $date): array
{
    $event = microsoftSyncEvent($id, $summary, $date);
    $event['type'] = 'occurrence';
    $event['seriesMasterId'] = $seriesId;

    return $event;
}

$start = new DateTimeImmutable('2026-08-01T00:00:00+00:00');
$end = new DateTimeImmutable('2027-08-01T00:00:00+00:00');

$directLookupHttp = new MicrosoftIncrementalSyncTestHttpClient([
    microsoftSyncResponse(200, microsoftSyncEvent('direct-edit-1', 'Direct edit', '2026-08-19'))
]);
$directLookupSynchronizer = new MicrosoftCalendarIncrementalSync($directLookupHttp, 'access-token');
$directLookupEvent = $directLookupSynchronizer->getEventByReference('primary', 'direct-edit-1');
microsoftSyncExpect(
    ($directLookupEvent['eventReference'] ?? '') === 'direct-edit-1'
        && ($directLookupEvent['summary'] ?? '') === 'Direct edit',
    'Microsoft edit preparation must support a direct event lookup by provider reference.'
);
microsoftSyncExpect(
    count($directLookupHttp->urls) === 1
        && str_contains($directLookupHttp->urls[0], '/me/calendars/primary/events/direct-edit-1'),
    'Direct Microsoft edit lookup must request exactly the selected event instead of a calendar view.'
);

$initialNextLink = 'https://graph.microsoft.com/v1.0/me/calendars/primary/calendarView/delta?$skiptoken=page-2';
$initialDeltaLink = 'https://graph.microsoft.com/v1.0/me/calendars/primary/calendarView/delta?$deltatoken=token-1';

$initialHttp = new MicrosoftIncrementalSyncTestHttpClient([
    microsoftSyncResponse(200, [
        'value'           => [microsoftSyncEvent('initial-1', 'Initial', '2026-08-20')],
        '@odata.nextLink' => $initialNextLink
    ]),
    microsoftSyncResponse(200, ['value' => [], '@odata.deltaLink' => $initialDeltaLink]),
    microsoftSyncResponse(200, ['value' => [microsoftSyncEvent('initial-1', 'Initial', '2026-08-20')]])
]);
$initialSynchronizer = new MicrosoftCalendarIncrementalSync($initialHttp, 'access-token');
$initial = $initialSynchronizer->synchronize('primary', $start, $end);
microsoftSyncExpect($initial['incremental'] === false, 'The first Microsoft synchronization must be a full synchronization.');
microsoftSyncExpect($initial['syncToken'] === $initialDeltaLink, 'The initial Microsoft synchronization did not preserve its delta link.');
microsoftSyncExpect(count($initial['items']) === 1, 'The initial Microsoft synchronization did not return the full event set.');
microsoftSyncExpect(
    str_contains($initialHttp->urls[0], '/calendarView/delta?')
        && str_contains($initialHttp->urls[0], 'startDateTime=')
        && str_contains($initialHttp->urls[0], 'endDateTime='),
    'The initial Microsoft delta request must use the configured event window.'
);
microsoftSyncExpect(
    $initialHttp->urls[1] === $initialNextLink,
    'The initial Microsoft delta request did not follow the opaque nextLink unchanged.'
);
microsoftSyncExpect(
    isset($initialHttp->urls[2])
        && str_contains($initialHttp->urls[2], '/calendarView?')
        && !str_contains($initialHttp->urls[2], '/calendarView/delta?'),
    'The authoritative initial Microsoft snapshot must use the regular calendarView endpoint.'
);

$incrementalDeltaLink = 'https://graph.microsoft.com/v1.0/me/calendars/primary/calendarView/delta?$deltatoken=token-2';
$incrementalHttp = new MicrosoftIncrementalSyncTestHttpClient([
    microsoftSyncResponse(200, [
        'value'            => [
            [
                'id'       => 'changed-1',
                'type'     => 'singleInstance',
                'isAllDay' => false,
                'start'    => ['dateTime' => '2026-08-21T09:00:00.0000000', 'timeZone' => 'UTC'],
                'end'      => ['dateTime' => '2026-08-21T10:00:00.0000000', 'timeZone' => 'UTC']
            ],
            ['id' => 'deleted-1', '@removed' => ['reason' => 'deleted']]
        ],
        '@odata.deltaLink' => $incrementalDeltaLink
    ]),
    microsoftSyncResponse(200, microsoftSyncEvent('changed-1', 'Changed', '2026-08-21'))
]);
$incrementalSynchronizer = new MicrosoftCalendarIncrementalSync($incrementalHttp, 'access-token');
$incremental = $incrementalSynchronizer->synchronize('primary', $start, $end, $initialDeltaLink);
microsoftSyncExpect($incremental['incremental'] === true, 'A valid Microsoft delta link must trigger incremental synchronization.');
microsoftSyncExpect($incremental['syncToken'] === $incrementalDeltaLink, 'The incremental Microsoft synchronization did not return the next delta link.');
microsoftSyncExpect(count($incremental['items']) === 2, 'The incremental Microsoft synchronization returned an unexpected change count.');
microsoftSyncExpect(
    ($incremental['items'][0]['eventReference'] ?? '') === 'changed-1'
        && ($incremental['items'][0]['summary'] ?? '') === 'Changed',
    'A changed Microsoft event was not normalized from its current provider representation.'
);
microsoftSyncExpect(
    ($incremental['items'][1]['_syncDeleted'] ?? false) === true
        && ($incremental['items'][1]['eventReference'] ?? '') === 'deleted-1',
    'A removed Microsoft event was not returned as a deletion marker.'
);
microsoftSyncExpect(
    $incrementalHttp->urls[0] === $initialDeltaLink,
    'The Microsoft delta link must be reused exactly as returned by Graph.'
);

$seriesDeltaLink = 'https://graph.microsoft.com/v1.0/me/calendars/primary/calendarView/delta?$deltatoken=series-change';
$seriesRefreshDeltaLink = 'https://graph.microsoft.com/v1.0/me/calendars/primary/calendarView/delta?$deltatoken=series-refresh';
$seriesHttp = new MicrosoftIncrementalSyncTestHttpClient([
    microsoftSyncResponse(200, [
        'value'            => [[
            'id'       => 'series-master',
            'type'     => 'seriesMaster',
            'isAllDay' => false,
            'start'    => ['dateTime' => '2026-08-20T09:00:00.0000000', 'timeZone' => 'UTC'],
            'end'      => ['dateTime' => '2026-08-20T10:00:00.0000000', 'timeZone' => 'UTC']
        ]],
        '@odata.deltaLink' => $seriesDeltaLink
    ]),
    microsoftSyncResponse(200, [
        'value'            => [[
            'id'       => 'series-master',
            'type'     => 'seriesMaster',
            'isAllDay' => false,
            'start'    => ['dateTime' => '2026-08-20T09:00:00.0000000', 'timeZone' => 'UTC'],
            'end'      => ['dateTime' => '2026-08-20T10:00:00.0000000', 'timeZone' => 'UTC']
        ]],
        '@odata.deltaLink' => $seriesRefreshDeltaLink
    ]),
    microsoftSyncResponse(200, [
        'value' => [
            microsoftSyncOccurrence('series-occurrence-1', 'series-master', 'Sabine arbeiten', '2026-08-20'),
            microsoftSyncOccurrence('series-occurrence-2', 'series-master', 'Sabine arbeiten', '2026-08-27')
        ]
    ])
]);
$seriesSynchronizer = new MicrosoftCalendarIncrementalSync($seriesHttp, 'access-token');
$seriesRefresh = $seriesSynchronizer->synchronize('primary', $start, $end, $initialDeltaLink);
microsoftSyncExpect(
    $seriesRefresh['incremental'] === false,
    'A changed Microsoft series master must trigger a complete bounded calendar-view refresh.'
);
microsoftSyncExpect(
    $seriesRefresh['syncToken'] === $seriesRefreshDeltaLink,
    'The Microsoft series refresh must replace the previous delta state.'
);
microsoftSyncExpect(
    count($seriesRefresh['items']) === 2
        && ($seriesRefresh['items'][0]['summary'] ?? '') === 'Sabine arbeiten'
        && ($seriesRefresh['items'][1]['summary'] ?? '') === 'Sabine arbeiten',
    'A numbered Microsoft series must keep all concrete occurrences after a series-master change.'
);
microsoftSyncExpect(
    ($seriesRefresh['items'][0]['recurrenceType'] ?? '') === 'occurrence'
        && ($seriesRefresh['items'][1]['recurrenceType'] ?? '') === 'occurrence',
    'Microsoft series masters must not be stored as visible calendar-view events.'
);
microsoftSyncExpect(
    isset($seriesHttp->urls[1])
        && str_contains($seriesHttp->urls[1], '/calendarView/delta?')
        && str_contains($seriesHttp->urls[1], 'startDateTime='),
    'A Microsoft series-master delta change must restart the bounded calendar-view synchronization.'
);
microsoftSyncExpect(
    isset($seriesHttp->urls[2])
        && str_contains($seriesHttp->urls[2], '/calendarView?')
        && !str_contains($seriesHttp->urls[2], '/calendarView/delta?'),
    'A Microsoft series refresh must rebuild the authoritative snapshot through regular calendarView.'
);

$fallbackDeltaLink = 'https://graph.microsoft.com/v1.0/me/calendars/primary/calendarView/delta?$deltatoken=token-3';
$fallbackHttp = new MicrosoftIncrementalSyncTestHttpClient([
    microsoftSyncResponse(410, ['error' => ['code' => 'resyncRequired', 'message' => 'Resynchronization required.']]),
    microsoftSyncResponse(200, [
        'value'            => [microsoftSyncEvent('fallback-1', 'Fallback', '2026-08-22')],
        '@odata.deltaLink' => $fallbackDeltaLink
    ]),
    microsoftSyncResponse(200, ['value' => [microsoftSyncEvent('fallback-1', 'Fallback', '2026-08-22')]])
]);
$fallbackSynchronizer = new MicrosoftCalendarIncrementalSync($fallbackHttp, 'access-token');
$fallback = $fallbackSynchronizer->synchronize('primary', $start, $end, $initialDeltaLink);
microsoftSyncExpect($fallback['incremental'] === false, 'HTTP 410 must automatically fall back to a full Microsoft synchronization.');
microsoftSyncExpect($fallback['syncToken'] === $fallbackDeltaLink, 'The HTTP 410 fallback did not return a fresh Microsoft delta link.');
microsoftSyncExpect(
    count($fallback['items']) === 1 && ($fallback['items'][0]['eventReference'] ?? '') === 'fallback-1',
    'The HTTP 410 fallback did not return the new full Microsoft event set.'
);

require_once __DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php';

final class MicrosoftEditIdentityTestHarness
{
    use KalenderKontoChildGatewayTrait;

    /**
     * Exposes the provider identity matcher for the Microsoft recurrence regression test.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $request
     */
    public function matches(array $event, array $request, bool $stableMicrosoftIdentity): bool
    {
        return $this->eventMatchesEditRequest($event, $request, $stableMicrosoftIdentity);
    }
}

$editIdentityHarness = new MicrosoftEditIdentityTestHarness();
$editEvent = [
    'occurrenceId'   => 'series-occurrence-1',
    'eventReference' => 'series-occurrence-1',
    'resourceUrl'    => 'https://graph.microsoft.com/v1.0/me/calendars/primary/events/series-occurrence-1',
    'uid'            => 'series@example.test',
    'originalStart'  => '2026-08-20T09:00:00+00:00',
    'recurrenceId'   => ''
];
$staleEditRequest = [
    'OccurrenceID'  => 'series-occurrence-1',
    'OriginalStart' => '2026-08-20T08:00:00+00:00'
];
microsoftSyncExpect(
    $editIdentityHarness->matches($editEvent, $staleEditRequest, true),
    'A stable Microsoft occurrence ID must remain editable when cached original-start metadata is stale.'
);
microsoftSyncExpect(
    !$editIdentityHarness->matches($editEvent, $staleEditRequest, false),
    'Non-Microsoft edit matching must retain the stricter original-start identity check.'
);
microsoftSyncExpect(
    !$editIdentityHarness->matches(
        $editEvent,
        ['OccurrenceID' => 'different-occurrence'],
        true
    ),
    'Microsoft edit matching must still reject a different occurrence ID.'
);

echo "Microsoft incremental synchronization and recurring-edit tests passed.\n";
