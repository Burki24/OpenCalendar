<?php

declare(strict_types=1);

use IPSKalender\ICalendarCodec;
use IPSKalender\ICalendarRecurrence;

require_once __DIR__ . '/../libs/ICalendarCodec.php';

function assertLoadStress(mixed $expected, mixed $actual, string $message): void
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
 * @param list<string> $eventLines
 */
function loadStressCalendar(array $eventLines): string
{
    return "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . implode("\r\n", $eventLines)
        . "\r\nEND:VCALENDAR\r\n";
}

$startedAt = hrtime(true);
$memoryBefore = memory_get_usage(true);

// Large flat calendar: exercise parsing and normalized event construction.
$singleEventCount = 5_000;
$singleEventLines = [];
$singleBase = new DateTimeImmutable('2026-01-01T00:00:00Z');
for ($index = 0; $index < $singleEventCount; ++$index) {
    $start = $singleBase->modify('+' . $index . ' minutes');
    $end = $start->modify('+30 minutes');
    array_push(
        $singleEventLines,
        'BEGIN:VEVENT',
        sprintf('UID:load-single-%05d@example.com', $index),
        'DTSTART:' . $start->format('Ymd\THis\Z'),
        'DTEND:' . $end->format('Ymd\THis\Z'),
        sprintf('SUMMARY:Load single %05d', $index),
        'END:VEVENT'
    );
}
$singleEvents = ICalendarCodec::parseEvents(
    loadStressCalendar($singleEventLines),
    'https://calendar.example/load-flat.ics',
    '"load-flat"'
);
assertLoadStress(
    $singleEventCount,
    count($singleEvents),
    'A large flat iCalendar resource must not lose events while parsing.'
);
assertLoadStress(
    'load-single-00000@example.com',
    $singleEvents[0]['uid'] ?? '',
    'The first bulk event must retain its UID.'
);
assertLoadStress(
    'load-single-04999@example.com',
    $singleEvents[$singleEventCount - 1]['uid'] ?? '',
    'The last bulk event must retain its UID.'
);
unset($singleEventLines, $singleEvents);

// Many concurrent recurring series: exercise grouping, expansion, sorting and diagnostics.
$seriesCount = 120;
$occurrencesPerSeries = 180;
$seriesEventLines = [];
$berlin = new DateTimeZone('Europe/Berlin');
$seriesDay = new DateTimeImmutable('2026-01-01T00:00:00', $berlin);
for ($seriesIndex = 0; $seriesIndex < $seriesCount; ++$seriesIndex) {
    $hour = 6 + intdiv($seriesIndex, 12);
    $minute = ($seriesIndex % 12) * 5;
    $start = $seriesDay->setTime($hour, $minute);
    $end = $start->modify('+30 minutes');
    array_push(
        $seriesEventLines,
        'BEGIN:VEVENT',
        sprintf('UID:load-series-%03d@example.com', $seriesIndex),
        'DTSTART;TZID=Europe/Berlin:' . $start->format('Ymd\THis'),
        'DTEND;TZID=Europe/Berlin:' . $end->format('Ymd\THis'),
        'RRULE:FREQ=DAILY;COUNT=' . $occurrencesPerSeries,
        sprintf('SUMMARY:Load series %03d', $seriesIndex),
        'END:VEVENT'
    );
}
$seriesEvents = ICalendarCodec::parseEventsInRange(
    loadStressCalendar($seriesEventLines),
    'https://calendar.example/load-series.ics',
    '"load-series"',
    new DateTimeImmutable('2025-12-31T00:00:00Z'),
    new DateTimeImmutable('2026-07-01T00:00:00Z')
);
$expectedSeriesOccurrences = $seriesCount * $occurrencesPerSeries;
assertLoadStress(
    $expectedSeriesOccurrences,
    count($seriesEvents),
    'Concurrent recurring series must expand completely under load.'
);
$seriesIds = array_values(array_unique(array_column($seriesEvents, 'seriesId')));
assertLoadStress(
    $seriesCount,
    count($seriesIds),
    'Recurring expansion must retain every series identity under load.'
);
$diagnostics = ICalendarRecurrence::diagnostics($seriesEvents);
assertLoadStress(
    $seriesCount,
    $diagnostics['seriesCount'] ?? 0,
    'Recurrence diagnostics must account for every expanded series under load.'
);
assertLoadStress(
    $seriesCount,
    $diagnostics['supportedSeriesCount'] ?? 0,
    'Supported recurring series must remain supported under load.'
);
$diagnosticOccurrences = array_sum(array_map(
    static fn (array $rule): int => (int) ($rule['occurrencesInRange'] ?? 0),
    is_array($diagnostics['rules'] ?? null) ? $diagnostics['rules'] : []
));
assertLoadStress(
    $expectedSeriesOccurrences,
    $diagnosticOccurrences,
    'Recurrence diagnostics must not lose expanded occurrences under load.'
);
unset($seriesEventLines, $seriesEvents, $seriesIds, $diagnostics);

// One long-running series: guard against accidental truncation well below the safety ceiling.
$longSeriesCount = 10_000;
$longSeriesFeed = loadStressCalendar([
    'BEGIN:VEVENT',
    'UID:load-long-series@example.com',
    'DTSTART;TZID=Europe/Berlin:20260101T090000',
    'DTEND;TZID=Europe/Berlin:20260101T093000',
    'RRULE:FREQ=DAILY;COUNT=' . $longSeriesCount,
    'SUMMARY:Long load series',
    'END:VEVENT'
]);
$longSeriesEvents = ICalendarCodec::parseEventsInRange(
    $longSeriesFeed,
    'https://calendar.example/load-long-series.ics',
    '"load-long-series"',
    new DateTimeImmutable('2025-12-31T00:00:00Z'),
    new DateTimeImmutable('2054-01-01T00:00:00Z')
);
assertLoadStress(
    $longSeriesCount,
    count($longSeriesEvents),
    'A long but supported recurring series must expand without silent truncation.'
);
assertLoadStress(
    true,
    !in_array(false, array_column($longSeriesEvents, 'recurrenceExpansionSupported'), true),
    'A long supported recurring series must not hit the recurrence safety fallback.'
);

$elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
$peakMemoryBytes = max(0, memory_get_peak_usage(true) - $memoryBefore);
fwrite(
    STDOUT,
    sprintf(
        "OpenCalendar load/stress tests passed: %d flat events, %d parallel-series occurrences, %d long-series occurrences; %.1f ms; +%.1f MiB peak memory.\n",
        $singleEventCount,
        $expectedSeriesOccurrences,
        $longSeriesCount,
        $elapsedMilliseconds,
        $peakMemoryBytes / 1_048_576
    )
);
