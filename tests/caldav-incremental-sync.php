<?php

declare(strict_types=1);

use IPSKalender\CalDAVIncrementalSync;
use IPSKalender\CalDAVOriginPolicy;
use IPSKalender\CalDAVProvider;
use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;

require_once __DIR__ . '/../libs/CalDAVIncrementalSync.php';

if (!class_exists(DOMDocument::class)) {
    throw new RuntimeException('The CalDAV incremental-sync test requires the PHP DOM extension.');
}

final class CalDAVIncrementalSyncTestHttpClient implements CalendarHttpClientInterface
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:string}> */
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
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->responses === []) {
            throw new RuntimeException('The CalDAV incremental-sync test has no queued response.');
        }

        return array_shift($this->responses);
    }
}

function caldavIncrementalResponse(
    int $statusCode,
    string $body,
    string $effectiveUrl = 'https://calendar.example/calendars/user/work/',
    array $headers = []
): CalendarHttpResponse {
    return new CalendarHttpResponse($statusCode, $headers, $body, $effectiveUrl);
}

function caldavIncrementalExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function caldavSyncCapabilityXml(string $syncToken, bool $supported = true): string
{
    $report = $supported
        ? '<d:supported-report><d:report><d:sync-collection/></d:report></d:supported-report>'
        : '<d:supported-report><d:report><d:expand-property/></d:report></d:supported-report>';

    return '<?xml version="1.0" encoding="utf-8" ?>'
        . '<d:multistatus xmlns:d="DAV:"><d:response>'
        . '<d:href>/calendars/user/work/</d:href><d:propstat><d:prop>'
        . '<d:sync-token>' . htmlspecialchars($syncToken, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</d:sync-token>'
        . '<d:supported-report-set>' . $report . '</d:supported-report-set>'
        . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
        . '</d:response></d:multistatus>';
}

function caldavSyncEventIcal(string $uid, string $summary, string $start): string
{
    $startDate = new DateTimeImmutable($start);
    $endDate = $startDate->modify('+1 hour');

    return "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "BEGIN:VEVENT\r\n"
        . 'UID:' . $uid . "\r\n"
        . 'DTSTART:' . $startDate->setTimezone(new DateTimeZone('UTC'))->format('Ymd\\THis\\Z') . "\r\n"
        . 'DTEND:' . $endDate->setTimezone(new DateTimeZone('UTC'))->format('Ymd\\THis\\Z') . "\r\n"
        . 'SUMMARY:' . $summary . "\r\n"
        . "END:VEVENT\r\n"
        . "END:VCALENDAR\r\n";
}

function caldavFullQueryXml(string $href, string $ical): string
{
    return '<?xml version="1.0" encoding="utf-8" ?>'
        . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        . '<d:response><d:href>' . htmlspecialchars($href, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</d:href>'
        . '<d:propstat><d:prop><d:getetag>"full-etag"</d:getetag>'
        . '<c:calendar-data><![CDATA[' . $ical . ']]></c:calendar-data>'
        . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
        . '</d:response></d:multistatus>';
}

function caldavSyncCollectionXml(string $syncToken): string
{
    return '<?xml version="1.0" encoding="utf-8" ?>'
        . '<d:multistatus xmlns:d="DAV:">'
        . '<d:response><d:href>/calendars/user/work/changed.ics</d:href>'
        . '<d:propstat><d:prop><d:getetag>"changed-etag"</d:getetag></d:prop>'
        . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
        . '<d:response><d:href>/calendars/user/work/deleted.ics</d:href>'
        . '<d:status>HTTP/1.1 404 Not Found</d:status></d:response>'
        . '<d:sync-token>' . htmlspecialchars($syncToken, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</d:sync-token>'
        . '</d:multistatus>';
}

$calendarUrl = 'https://calendar.example/calendars/user/work/';
$start = new DateTimeImmutable('2026-08-01T00:00:00Z');
$end = new DateTimeImmutable('2027-08-01T00:00:00Z');
$originPolicy = new CalDAVOriginPolicy('https://calendar.example/');

$initialHttp = new CalDAVIncrementalSyncTestHttpClient([
    caldavIncrementalResponse(207, caldavSyncCapabilityXml('sync-1')),
    caldavIncrementalResponse(
        207,
        caldavFullQueryXml(
            '/calendars/user/work/initial.ics',
            caldavSyncEventIcal('initial@example.test', 'Initial', '2026-08-20T10:00:00Z')
        )
    )
]);
$initialProvider = new CalDAVProvider($initialHttp, 'https://calendar.example/', $originPolicy);
$initialSynchronizer = new CalDAVIncrementalSync($initialProvider, $initialHttp, $originPolicy);
$initial = $initialSynchronizer->synchronize($calendarUrl, $start, $end);
caldavIncrementalExpect($initial['incremental'] === false, 'The first CalDAV synchronization must be a full synchronization.');
caldavIncrementalExpect($initial['syncToken'] === 'sync-1', 'The initial CalDAV synchronization did not store the advertised sync token.');
caldavIncrementalExpect(count($initial['items']) === 1, 'The initial CalDAV synchronization did not return the full event set.');
caldavIncrementalExpect(
    ($initialHttp->requests[0]['method'] ?? '') === 'PROPFIND'
        && str_contains($initialHttp->requests[0]['body'] ?? '', '<d:supported-report-set/>')
        && str_contains($initialHttp->requests[0]['body'] ?? '', '<d:sync-token/>'),
    'The initial CalDAV synchronization must detect sync-collection support before storing a token.'
);

$incrementalHttp = new CalDAVIncrementalSyncTestHttpClient([
    caldavIncrementalResponse(207, caldavSyncCollectionXml('sync-2')),
    caldavIncrementalResponse(
        200,
        caldavSyncEventIcal('changed@example.test', 'Changed', '2026-08-21T10:00:00Z'),
        'https://calendar.example/calendars/user/work/changed.ics',
        ['etag' => '"changed-etag"']
    )
]);
$incrementalProvider = new CalDAVProvider($incrementalHttp, 'https://calendar.example/', $originPolicy);
$incrementalSynchronizer = new CalDAVIncrementalSync(
    $incrementalProvider,
    $incrementalHttp,
    $originPolicy
);
$incremental = $incrementalSynchronizer->synchronize($calendarUrl, $start, $end, 'sync-1');
caldavIncrementalExpect($incremental['incremental'] === true, 'A valid CalDAV sync token must trigger sync-collection.');
caldavIncrementalExpect($incremental['syncToken'] === 'sync-2', 'The CalDAV sync-collection response did not advance the token.');
caldavIncrementalExpect(count($incremental['items']) === 2, 'The CalDAV incremental synchronization returned an unexpected change count.');
caldavIncrementalExpect(
    ($incremental['items'][0]['summary'] ?? '') === 'Changed'
        && ($incremental['items'][0]['_syncReplaceResource'] ?? false) === true
        && ($incremental['items'][0]['resourceUrl'] ?? '') === $calendarUrl . 'changed.ics',
    'A changed CalDAV resource was not returned as a resource replacement.'
);
caldavIncrementalExpect(
    ($incremental['items'][1]['_syncDeleted'] ?? false) === true
        && ($incremental['items'][1]['resourceUrl'] ?? '') === $calendarUrl . 'deleted.ics',
    'A deleted CalDAV resource was not returned as a deletion marker.'
);
caldavIncrementalExpect(
    ($incrementalHttp->requests[0]['method'] ?? '') === 'REPORT'
        && ($incrementalHttp->requests[0]['headers']['Depth'] ?? '') === '0'
        && str_contains($incrementalHttp->requests[0]['body'] ?? '', '<d:sync-collection')
        && str_contains($incrementalHttp->requests[0]['body'] ?? '', '<d:sync-token>sync-1</d:sync-token>'),
    'The incremental CalDAV request did not use the previous sync token with WebDAV Depth 0.'
);

$unsupportedHttp = new CalDAVIncrementalSyncTestHttpClient([
    caldavIncrementalResponse(207, caldavSyncCapabilityXml('ignored-token', false)),
    caldavIncrementalResponse(
        207,
        caldavFullQueryXml(
            '/calendars/user/work/full-only.ics',
            caldavSyncEventIcal('full-only@example.test', 'Full only', '2026-08-22T10:00:00Z')
        )
    )
]);
$unsupportedProvider = new CalDAVProvider($unsupportedHttp, 'https://calendar.example/', $originPolicy);
$unsupportedSynchronizer = new CalDAVIncrementalSync($unsupportedProvider, $unsupportedHttp, $originPolicy);
$unsupported = $unsupportedSynchronizer->synchronize($calendarUrl, $start, $end);
caldavIncrementalExpect($unsupported['syncToken'] === '', 'A CalDAV server without sync-collection support must not persist a sync token.');
caldavIncrementalExpect($unsupported['incremental'] === false, 'A CalDAV server without sync-collection support must keep the full synchronization path.');

$fallbackHttp = new CalDAVIncrementalSyncTestHttpClient([
    caldavIncrementalResponse(
        403,
        '<?xml version="1.0" encoding="utf-8" ?><d:error xmlns:d="DAV:"><d:valid-sync-token/></d:error>'
    ),
    caldavIncrementalResponse(207, caldavSyncCapabilityXml('sync-3')),
    caldavIncrementalResponse(
        207,
        caldavFullQueryXml(
            '/calendars/user/work/fallback.ics',
            caldavSyncEventIcal('fallback@example.test', 'Fallback', '2026-08-23T10:00:00Z')
        )
    )
]);
$fallbackProvider = new CalDAVProvider($fallbackHttp, 'https://calendar.example/', $originPolicy);
$fallbackSynchronizer = new CalDAVIncrementalSync($fallbackProvider, $fallbackHttp, $originPolicy);
$fallback = $fallbackSynchronizer->synchronize($calendarUrl, $start, $end, 'expired-sync-token');
caldavIncrementalExpect($fallback['incremental'] === false, 'An invalid CalDAV sync token must trigger a full synchronization.');
caldavIncrementalExpect($fallback['syncToken'] === 'sync-3', 'The invalid-token fallback did not acquire a fresh CalDAV sync token.');
caldavIncrementalExpect(
    count($fallback['items']) === 1 && ($fallback['items'][0]['summary'] ?? '') === 'Fallback',
    'The invalid-token fallback did not return the refreshed event set.'
);

echo "CalDAV incremental synchronization tests passed.\n";
