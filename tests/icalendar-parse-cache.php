<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\ICalendarFeedProvider;

require_once __DIR__ . '/../libs/ICalendarFeedProvider.php';

final class ParseCacheHttpClient implements CalendarHttpClientInterface
{
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
        if ($this->responses === []) {
            throw new RuntimeException('No parse-cache response was queued.');
        }

        return array_shift($this->responses);
    }
}

function assertParseCacheSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL
            . 'Expected: ' . var_export($expected, true) . PHP_EOL
            . 'Actual:   ' . var_export($actual, true)
        );
    }
}

function assertParseCacheTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$feedUrl = 'https://calendar.example/parse-cache.ics';
$feed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:parse-cache@example.com\r\n"
    . "DTSTART:20260904T080000Z\r\n"
    . "DTEND:20260904T090000Z\r\n"
    . "SUMMARY:Cached event\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$rangeStart = new DateTimeImmutable('2026-09-04T00:00:00Z');
$rangeEnd = new DateTimeImmutable('2026-09-05T00:00:00Z');

$cacheState = [];
$firstProvider = new ICalendarFeedProvider(
    new ParseCacheHttpClient([
        new CalendarHttpResponse(
            200,
            ['etag' => '"parse-cache-1"'],
            $feed,
            $feedUrl
        )
    ]),
    $feedUrl,
    '',
    [],
    static function (array $state) use (&$cacheState): void
    {
        $cacheState = $state;
    }
);
$firstEvents = $firstProvider->getEvents($feedUrl, $rangeStart, $rangeEnd);

assertParseCacheSame(1, count($firstEvents), 'The initial iCalendar feed must return its event.');
assertParseCacheSame('Cached event', $firstEvents[0]['summary'], 'The initial event summary must be parsed.');
assertParseCacheSame(1, $cacheState['parsedCacheVersion'] ?? 0, 'The parsed feed cache must use the current cache schema.');
assertParseCacheSame(
    hash('sha256', $feed),
    $cacheState['parsedContentHash'] ?? '',
    'The parsed feed cache must be bound to the exact feed content.'
);
assertParseCacheTrue(
    in_array($cacheState['parsedEncoding'] ?? '', ['base64', 'gzip-base64'], true),
    'The parsed feed cache must declare a supported compact encoding.'
);
assertParseCacheTrue(
    is_string($cacheState['parsedData'] ?? null) && $cacheState['parsedData'] !== '',
    'The parsed feed cache must persist a serialized payload.'
);
assertParseCacheTrue(
    !array_key_exists('parsedEvents', $cacheState),
    'Parsed event arrays must not be persisted directly in the feed cache.'
);

$initialParsedData = $cacheState['parsedData'];
$initialParsedEncoding = $cacheState['parsedEncoding'];

$unchangedProvider = new ICalendarFeedProvider(
    new ParseCacheHttpClient([
        new CalendarHttpResponse(
            200,
            ['etag' => '"parse-cache-2"'],
            $feed,
            $feedUrl
        )
    ]),
    $feedUrl,
    '',
    $cacheState,
    static function (array $state) use (&$cacheState): void
    {
        $cacheState = $state;
    }
);
$unchangedEvents = $unchangedProvider->getEvents($feedUrl, $rangeStart, $rangeEnd);

assertParseCacheSame(
    $initialParsedData,
    $cacheState['parsedData'] ?? '',
    'A byte-identical HTTP 200 response must preserve the existing parsed payload.'
);
assertParseCacheSame(
    $initialParsedEncoding,
    $cacheState['parsedEncoding'] ?? '',
    'A byte-identical HTTP 200 response must preserve the parsed payload encoding.'
);
assertParseCacheSame(
    '"parse-cache-2"',
    $unchangedEvents[0]['etag'] ?? '',
    'Events restored from the parsed cache must expose the current feed ETag.'
);

$notModifiedProvider = new ICalendarFeedProvider(
    new ParseCacheHttpClient([
        new CalendarHttpResponse(
            304,
            ['etag' => '"parse-cache-3"'],
            '',
            $feedUrl
        )
    ]),
    $feedUrl,
    '',
    $cacheState,
    static function (array $state) use (&$cacheState): void
    {
        $cacheState = $state;
    }
);
$notModifiedEvents = $notModifiedProvider->getEvents($feedUrl, $rangeStart, $rangeEnd);

assertParseCacheSame(1, count($notModifiedEvents), 'HTTP 304 must reuse the cached parsed feed.');
assertParseCacheSame(
    '"parse-cache-3"',
    $notModifiedEvents[0]['etag'] ?? '',
    'HTTP 304 ETag updates must also be reflected in cached event metadata.'
);

$connectionCache = $cacheState;
$connectionProvider = new ICalendarFeedProvider(
    new ParseCacheHttpClient([
        new CalendarHttpResponse(304, [], '', $feedUrl)
    ]),
    $feedUrl,
    '',
    $cacheState,
    static function (array $state) use (&$connectionCache): void
    {
        $connectionCache = $state;
    }
);
$connectionResult = $connectionProvider->testConnection();
assertParseCacheSame(
    1,
    $connectionResult['eventCount'] ?? 0,
    'Connection tests must reuse the parsed cache when the feed is unchanged.'
);

$corruptCache = $cacheState;
$corruptCache['parsedData'] = 'invalid-cache-data';
$recoveredCache = $corruptCache;
$recoveryProvider = new ICalendarFeedProvider(
    new ParseCacheHttpClient([
        new CalendarHttpResponse(304, [], '', $feedUrl)
    ]),
    $feedUrl,
    '',
    $corruptCache,
    static function (array $state) use (&$recoveredCache): void
    {
        $recoveredCache = $state;
    }
);
$recoveredEvents = $recoveryProvider->getEvents($feedUrl, $rangeStart, $rangeEnd);

assertParseCacheSame(1, count($recoveredEvents), 'A damaged parsed cache must fall back to parsing the valid cached feed body.');
assertParseCacheTrue(
    ($recoveredCache['parsedData'] ?? '') !== 'invalid-cache-data',
    'A damaged parsed cache must be replaced after a successful fallback parse.'
);

$changedFeed = str_replace('Cached event', 'Changed event', $feed);
$changedCache = $cacheState;
$changedProvider = new ICalendarFeedProvider(
    new ParseCacheHttpClient([
        new CalendarHttpResponse(
            200,
            ['etag' => '"parse-cache-4"'],
            $changedFeed,
            $feedUrl
        )
    ]),
    $feedUrl,
    '',
    $cacheState,
    static function (array $state) use (&$changedCache): void
    {
        $changedCache = $state;
    }
);
$changedEvents = $changedProvider->getEvents($feedUrl, $rangeStart, $rangeEnd);

assertParseCacheSame('Changed event', $changedEvents[0]['summary'], 'Changed feed content must be parsed again.');
assertParseCacheSame(
    hash('sha256', $changedFeed),
    $changedCache['parsedContentHash'] ?? '',
    'Changed feed content must replace the parsed cache hash.'
);
assertParseCacheTrue(
    ($changedCache['parsedData'] ?? '') !== $initialParsedData,
    'Changed feed content must replace the serialized parsed payload.'
);

$durationFeedUrl = 'https://calendar.example/parse-cache-duration.ics';
$durationFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:parse-cache-duration@example.com\r\n"
    . "DTSTART:20260904T080000Z\r\n"
    . "DURATION:PT2H\r\n"
    . "RRULE:FREQ=DAILY;COUNT=2\r\n"
    . "SUMMARY:Duration event\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$durationProvider = new ICalendarFeedProvider(
    new ParseCacheHttpClient([
        new CalendarHttpResponse(
            200,
            ['etag' => '"parse-cache-duration"'],
            $durationFeed,
            $durationFeedUrl
        )
    ]),
    $durationFeedUrl
);
$durationEvents = $durationProvider->getEvents(
    $durationFeedUrl,
    new DateTimeImmutable('2026-09-04T00:00:00Z'),
    new DateTimeImmutable('2026-09-06T00:00:00Z')
);

assertParseCacheSame(2, count($durationEvents), 'Recurring DURATION events must retain canonical range expansion.');
foreach ($durationEvents as $durationEvent) {
    assertParseCacheSame(
        7200,
        (int) ($durationEvent['endTimestamp'] ?? 0) - (int) ($durationEvent['startTimestamp'] ?? 0),
        'The parsed feed cache must not bypass canonical recurring DURATION handling.'
    );
}

fwrite(STDOUT, "iCalendar parsed feed cache checks passed.\n");
