<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\MicrosoftCalendarIncrementalSync;

require_once __DIR__ . '/../libs/MicrosoftCalendarIncrementalSync.php';

final class MicrosoftSeriesMasterSyncTestHttpClient implements CalendarHttpClientInterface
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
        if ($method !== 'GET') {
            throw new RuntimeException('The Microsoft series-master sync test only expects GET requests.');
        }
        if ($this->responses === []) {
            throw new RuntimeException('The Microsoft series-master sync test has no queued response.');
        }

        return array_shift($this->responses);
    }
}

/** @param array<string, mixed> $payload */
function microsoftSeriesMasterResponse(array $payload): CalendarHttpResponse
{
    return new CalendarHttpResponse(
        200,
        [],
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ''
    );
}

function microsoftSeriesMasterExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function microsoftSeriesMasterOccurrence(string $id, string $date): array
{
    return [
        'id'                    => $id,
        'iCalUId'               => $id . '@example.test',
        'type'                  => 'occurrence',
        'seriesMasterId'        => 'series-master',
        'subject'               => 'test einr',
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

$oldDeltaLink = 'https://graph.microsoft.com/v1.0/me/calendars/primary/calendarView/delta?$deltatoken=before-create';
$newDeltaLink = 'https://graph.microsoft.com/v1.0/me/calendars/primary/calendarView/delta?$deltatoken=after-create';
$seriesMaster = [
    'id'       => 'series-master',
    'type'     => 'seriesMaster',
    'subject'  => 'test einr',
    'isAllDay' => false,
    'start'    => ['dateTime' => '2026-09-07T09:00:00.0000000', 'timeZone' => 'UTC'],
    'end'      => ['dateTime' => '2026-09-07T10:00:00.0000000', 'timeZone' => 'UTC']
];
$http = new MicrosoftSeriesMasterSyncTestHttpClient([
    // Graph can first report only the changed series master through the existing delta token.
    microsoftSeriesMasterResponse([
        'value'            => [$seriesMaster],
        '@odata.deltaLink' => $newDeltaLink
    ]),
    // The bounded full delta refresh can still expose the master before all occurrences are expanded.
    microsoftSeriesMasterResponse([
        'value'            => [$seriesMaster],
        '@odata.deltaLink' => $newDeltaLink
    ]),
    microsoftSeriesMasterResponse([
        'value' => [
            microsoftSeriesMasterOccurrence('occurrence-1', '2026-09-07'),
            microsoftSeriesMasterOccurrence('occurrence-2', '2026-09-08'),
            microsoftSeriesMasterOccurrence('occurrence-3', '2026-09-09'),
            microsoftSeriesMasterOccurrence('occurrence-4', '2026-09-10'),
            microsoftSeriesMasterOccurrence('occurrence-5', '2026-09-11')
        ]
    ])
]);
$synchronizer = new MicrosoftCalendarIncrementalSync($http, 'access-token');
$result = $synchronizer->synchronize(
    'primary',
    new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
    new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
    $oldDeltaLink
);

microsoftSeriesMasterExpect(
    $result['incremental'] === false && $result['syncToken'] === $newDeltaLink,
    'A Microsoft series-master change must complete as a bounded full refresh with a new delta baseline.'
);
microsoftSeriesMasterExpect(
    count($result['items']) === 5,
    'A Microsoft series master returned by calendarView/delta must be expanded into all concrete instances.'
);
foreach ($result['items'] as $item) {
    microsoftSeriesMasterExpect(
        ($item['recurrenceType'] ?? '') === 'occurrence'
            && ($item['seriesId'] ?? '') === 'series-master',
        'Expanded Microsoft series instances must retain their recurring identity.'
    );
}
microsoftSeriesMasterExpect(
    isset($http->requests[0]) && $http->requests[0]['url'] === $oldDeltaLink,
    'The existing Microsoft delta token must be checked before the bounded refresh.'
);
microsoftSeriesMasterExpect(
    isset($http->requests[1])
        && str_contains($http->requests[1]['url'], '/calendarView/delta?')
        && str_contains($http->requests[1]['url'], 'startDateTime='),
    'A Microsoft series-master change must restart the bounded calendar-view delta refresh.'
);
microsoftSeriesMasterExpect(
    isset($http->requests[2])
        && str_contains($http->requests[2]['url'], '/events/series-master/instances?')
        && str_contains($http->requests[2]['url'], 'startDateTime=')
        && str_contains($http->requests[2]['url'], 'endDateTime='),
    'A series master returned during the full refresh must be expanded through the Graph instances endpoint.'
);
foreach ($http->requests as $request) {
    microsoftSeriesMasterExpect(
        str_contains((string) ($request['headers']['Prefer'] ?? ''), 'IdType="ImmutableId"'),
        'All Microsoft synchronization requests must use immutable Graph IDs.'
    );
}

echo "Microsoft series-master synchronization tests passed.\n";
