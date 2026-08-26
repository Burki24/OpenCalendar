<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\GoogleCalendarIncrementalSync;
use IPSKalender\GoogleCalendarProvider;

require_once __DIR__ . '/../libs/GoogleCalendarIncrementalSync.php';

final class GoogleIncrementalSyncTestHttpClient implements CalendarHttpClientInterface
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
            throw new RuntimeException('The Google incremental-sync test only expects GET requests.');
        }
        $this->urls[] = $url;
        if ($this->responses === []) {
            throw new RuntimeException('The Google incremental-sync test has no queued response.');
        }

        return array_shift($this->responses);
    }
}

/** @param array<string, mixed> $payload */
function googleSyncResponse(int $statusCode, array $payload): CalendarHttpResponse
{
    return new CalendarHttpResponse(
        $statusCode,
        [],
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ''
    );
}

function googleSyncExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function googleSyncEvent(string $id, string $summary, string $date): array
{
    return [
        'id'      => $id,
        'iCalUID' => $id . '@example.test',
        'status'  => 'confirmed',
        'summary' => $summary,
        'start'   => ['date' => $date],
        'end'     => ['date' => (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')]
    ];
}

$start = new DateTimeImmutable('2026-08-01T00:00:00+00:00');
$end = new DateTimeImmutable('2027-08-01T00:00:00+00:00');

$initialHttp = new GoogleIncrementalSyncTestHttpClient([
    googleSyncResponse(200, ['items' => [], 'nextSyncToken' => 'token-1']),
    googleSyncResponse(200, ['items' => [googleSyncEvent('initial-1', 'Initial', '2026-08-20')]])
]);
$initialProvider = new GoogleCalendarProvider($initialHttp, 'access-token');
$initialSynchronizer = new GoogleCalendarIncrementalSync(
    $initialProvider,
    $initialHttp,
    'access-token'
);
$initial = $initialSynchronizer->synchronize('primary', $start, $end);
googleSyncExpect($initial['incremental'] === false, 'The first Google synchronization must be a full synchronization.');
googleSyncExpect($initial['syncToken'] === 'token-1', 'The first Google synchronization did not return its sync token.');
googleSyncExpect(count($initial['items']) === 1, 'The first Google synchronization did not return the full event set.');
googleSyncExpect(
    str_contains($initialHttp->urls[0], 'timeMin=') && str_contains($initialHttp->urls[0], 'timeMax='),
    'The Google token bootstrap must use the configured event window.'
);
googleSyncExpect(
    !str_contains($initialHttp->urls[0], 'orderBy='),
    'The Google token bootstrap must not use a query option forbidden for incremental synchronization.'
);

$incrementalHttp = new GoogleIncrementalSyncTestHttpClient([
    googleSyncResponse(200, [
        'items' => [
            ['id' => 'changed-1', 'status' => 'confirmed'],
            [
                'id'               => 'deleted-1',
                'status'           => 'cancelled',
                'recurringEventId' => 'series-1'
            ]
        ],
        'nextSyncToken' => 'token-2'
    ]),
    googleSyncResponse(200, googleSyncEvent('changed-1', 'Changed', '2026-08-21'))
]);
$incrementalProvider = new GoogleCalendarProvider($incrementalHttp, 'access-token');
$incrementalSynchronizer = new GoogleCalendarIncrementalSync(
    $incrementalProvider,
    $incrementalHttp,
    'access-token'
);
$incremental = $incrementalSynchronizer->synchronize('primary', $start, $end, 'token-1');
googleSyncExpect($incremental['incremental'] === true, 'A valid Google sync token must trigger incremental synchronization.');
googleSyncExpect($incremental['syncToken'] === 'token-2', 'The incremental Google synchronization did not return the next sync token.');
googleSyncExpect(count($incremental['items']) === 2, 'The incremental Google synchronization returned an unexpected change count.');
googleSyncExpect(
    ($incremental['items'][0]['eventReference'] ?? '') === 'changed-1',
    'A changed Google event was not normalized through the existing provider.'
);
googleSyncExpect(
    ($incremental['items'][1]['_syncDeleted'] ?? false) === true
        && ($incremental['items'][1]['eventReference'] ?? '') === 'deleted-1'
        && ($incremental['items'][1]['seriesId'] ?? '') === 'series-1',
    'A deleted Google event was not returned as a deletion marker.'
);
$incrementalUrl = $incrementalHttp->urls[0];
googleSyncExpect(str_contains($incrementalUrl, 'syncToken=token-1'), 'The incremental Google request did not contain the previous sync token.');
googleSyncExpect(str_contains($incrementalUrl, 'singleEvents=true'), 'The incremental Google request did not retain singleEvents.');
googleSyncExpect(
    isset($incrementalHttp->urls[1])
        && str_contains($incrementalHttp->urls[1], '/calendars/primary/events/changed-1'),
    'A changed Google event must be resolved through the provider-neutral direct event lookup.'
);
foreach (['timeMin=', 'timeMax=', 'orderBy=', 'showDeleted=false'] as $forbiddenQuery) {
    googleSyncExpect(
        !str_contains($incrementalUrl, $forbiddenQuery),
        'The incremental Google request contains a forbidden query option: ' . $forbiddenQuery
    );
}

$fallbackHttp = new GoogleIncrementalSyncTestHttpClient([
    googleSyncResponse(410, ['error' => ['message' => 'Sync token is no longer valid']]),
    googleSyncResponse(200, ['items' => [], 'nextSyncToken' => 'token-3']),
    googleSyncResponse(200, ['items' => [googleSyncEvent('fallback-1', 'Fallback', '2026-08-22')]])
]);
$fallbackProvider = new GoogleCalendarProvider($fallbackHttp, 'access-token');
$fallbackSynchronizer = new GoogleCalendarIncrementalSync(
    $fallbackProvider,
    $fallbackHttp,
    'access-token'
);
$fallback = $fallbackSynchronizer->synchronize('primary', $start, $end, 'expired-token');
googleSyncExpect($fallback['incremental'] === false, 'HTTP 410 must automatically fall back to a full Google synchronization.');
googleSyncExpect($fallback['syncToken'] === 'token-3', 'The HTTP 410 fallback did not return a fresh Google sync token.');
googleSyncExpect(
    count($fallback['items']) === 1 && ($fallback['items'][0]['eventReference'] ?? '') === 'fallback-1',
    'The HTTP 410 fallback did not return the new full event set.'
);

echo "Google incremental synchronization tests passed.\n";
