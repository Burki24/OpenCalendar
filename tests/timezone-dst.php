<?php

declare(strict_types=1);

use IPSKalender\ICalendarCodec;

require_once __DIR__ . '/../libs/ICalendarCodec.php';

function assertTimezoneDst(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL
                . 'Expected: ' . var_export($expected, true) . PHP_EOL
                . 'Actual:   ' . var_export($actual, true)
        );
    }
}

function timezoneDstCalendar(string $event): string
{
    return "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . $event
        . "END:VCALENDAR\r\n";
}

/**
 * @param list<array<string, mixed>> $events
 * @return list<array<string, mixed>>
 */
function timezoneDstEventsForUid(array $events, string $uid): array
{
    return array_values(array_filter(
        $events,
        static fn (array $event): bool => ($event['uid'] ?? '') === $uid
    ));
}

$springDurationFeed = timezoneDstCalendar(
    "BEGIN:VEVENT\r\n"
        . "UID:spring-duration@example.com\r\n"
        . "DTSTART;TZID=Europe/Berlin:20260328T090000\r\n"
        . "DURATION:P1D\r\n"
        . "RRULE:FREQ=DAILY;COUNT=3\r\n"
        . "SUMMARY:Spring duration\r\n"
        . "END:VEVENT\r\n"
);
$springDurationEvents = timezoneDstEventsForUid(ICalendarCodec::parseEventsInRange(
    $springDurationFeed,
    'https://calendar.example/spring-duration.ics',
    '',
    new DateTimeImmutable('2026-03-27T00:00:00Z'),
    new DateTimeImmutable('2026-04-01T00:00:00Z')
), 'spring-duration@example.com');
assertTimezoneDst(
    [
        '2026-03-28T09:00:00+01:00',
        '2026-03-29T09:00:00+02:00',
        '2026-03-30T09:00:00+02:00'
    ],
    array_column($springDurationEvents, 'start'),
    'A daily series must retain its local start time across the spring DST transition.'
);
assertTimezoneDst(
    [
        '2026-03-29T09:00:00+02:00',
        '2026-03-30T09:00:00+02:00',
        '2026-03-31T09:00:00+02:00'
    ],
    array_column($springDurationEvents, 'end'),
    'A recurring P1D duration must remain nominal across the spring DST transition.'
);
assertTimezoneDst(
    [23 * 3600, 24 * 3600, 24 * 3600],
    array_map(
        static fn (array $event): int => (int) $event['endTimestamp'] - (int) $event['startTimestamp'],
        $springDurationEvents
    ),
    'Nominal P1D duration must reflect the shorter DST transition day.'
);

$fallDurationFeed = timezoneDstCalendar(
    "BEGIN:VEVENT\r\n"
        . "UID:fall-duration@example.com\r\n"
        . "DTSTART;TZID=Europe/Berlin:20261024T090000\r\n"
        . "DURATION:P1D\r\n"
        . "RRULE:FREQ=DAILY;COUNT=3\r\n"
        . "SUMMARY:Fall duration\r\n"
        . "END:VEVENT\r\n"
);
$fallDurationEvents = timezoneDstEventsForUid(ICalendarCodec::parseEventsInRange(
    $fallDurationFeed,
    'https://calendar.example/fall-duration.ics',
    '',
    new DateTimeImmutable('2026-10-23T00:00:00Z'),
    new DateTimeImmutable('2026-10-28T00:00:00Z')
), 'fall-duration@example.com');
assertTimezoneDst(
    [
        '2026-10-25T09:00:00+01:00',
        '2026-10-26T09:00:00+01:00',
        '2026-10-27T09:00:00+01:00'
    ],
    array_column($fallDurationEvents, 'end'),
    'A recurring P1D duration must remain nominal across the autumn DST transition.'
);
assertTimezoneDst(
    [25 * 3600, 24 * 3600, 24 * 3600],
    array_map(
        static fn (array $event): int => (int) $event['endTimestamp'] - (int) $event['startTimestamp'],
        $fallDurationEvents
    ),
    'Nominal P1D duration must reflect the longer DST transition day.'
);

$exactDtendFeed = timezoneDstCalendar(
    "BEGIN:VEVENT\r\n"
        . "UID:exact-dtend@example.com\r\n"
        . "DTSTART;TZID=Europe/Berlin:20260328T090000\r\n"
        . "DTEND;TZID=Europe/Berlin:20260329T090000\r\n"
        . "RRULE:FREQ=DAILY;COUNT=3\r\n"
        . "SUMMARY:Exact DTEND\r\n"
        . "END:VEVENT\r\n"
);
$exactDtendEvents = timezoneDstEventsForUid(ICalendarCodec::parseEventsInRange(
    $exactDtendFeed,
    'https://calendar.example/exact-dtend.ics',
    '',
    new DateTimeImmutable('2026-03-27T00:00:00Z'),
    new DateTimeImmutable('2026-04-01T00:00:00Z')
), 'exact-dtend@example.com');
assertTimezoneDst(
    [
        '2026-03-29T09:00:00+02:00',
        '2026-03-30T08:00:00+02:00',
        '2026-03-31T08:00:00+02:00'
    ],
    array_column($exactDtendEvents, 'end'),
    'DTEND-based recurring events must preserve the exact master duration across DST.'
);
assertTimezoneDst(
    [23 * 3600, 23 * 3600, 23 * 3600],
    array_map(
        static fn (array $event): int => (int) $event['endTimestamp'] - (int) $event['startTimestamp'],
        $exactDtendEvents
    ),
    'DTEND must retain one exact duration for every generated recurrence instance.'
);

$midnightFeed = timezoneDstCalendar(
    "BEGIN:VEVENT\r\n"
        . "UID:midnight-duration@example.com\r\n"
        . "DTSTART;TZID=Europe/Berlin:20260329T000000\r\n"
        . "DURATION:PT4H\r\n"
        . "RRULE:FREQ=DAILY;COUNT=2\r\n"
        . "SUMMARY:Midnight duration\r\n"
        . "END:VEVENT\r\n"
);
$midnightEvents = timezoneDstEventsForUid(ICalendarCodec::parseEventsInRange(
    $midnightFeed,
    'https://calendar.example/midnight-duration.ics',
    '',
    new DateTimeImmutable('2026-03-28T00:00:00Z'),
    new DateTimeImmutable('2026-04-01T00:00:00Z')
), 'midnight-duration@example.com');
assertTimezoneDst(
    ['2026-03-29T05:00:00+02:00', '2026-03-30T04:00:00+02:00'],
    array_column($midnightEvents, 'end'),
    'Accurate hour durations must keep their exact elapsed time when a DST gap is crossed.'
);

$allDayFeed = timezoneDstCalendar(
    "BEGIN:VEVENT\r\n"
        . "UID:all-day-duration@example.com\r\n"
        . "DTSTART;VALUE=DATE:20260328\r\n"
        . "DURATION:P2D\r\n"
        . "RRULE:FREQ=DAILY;COUNT=3\r\n"
        . "SUMMARY:All-day duration\r\n"
        . "END:VEVENT\r\n"
);
$allDayEvents = timezoneDstEventsForUid(ICalendarCodec::parseEventsInRange(
    $allDayFeed,
    'https://calendar.example/all-day-duration.ics',
    '',
    new DateTimeImmutable('2026-03-27T00:00:00Z'),
    new DateTimeImmutable('2026-04-03T00:00:00Z')
), 'all-day-duration@example.com');
assertTimezoneDst(
    ['2026-03-30', '2026-03-31', '2026-04-01'],
    array_column($allDayEvents, 'end'),
    'Multi-day all-day duration must remain date-based across DST.'
);

$singleCrossingFeed = timezoneDstCalendar(
    "BEGIN:VEVENT\r\n"
        . "UID:single-crossing@example.com\r\n"
        . "DTSTART;TZID=Europe/Berlin:20260328T233000\r\n"
        . "DTEND;TZID=Europe/Berlin:20260329T033000\r\n"
        . "SUMMARY:Single crossing\r\n"
        . "END:VEVENT\r\n"
);
$singleCrossing = ICalendarCodec::parseEvents($singleCrossingFeed, 'https://calendar.example/single.ics', '')[0];
assertTimezoneDst(
    3 * 3600,
    (int) $singleCrossing['endTimestamp'] - (int) $singleCrossing['startTimestamp'],
    'A non-recurring event crossing the DST gap must retain the correct absolute duration.'
);

$overrideMasterBlock = [
    'BEGIN:VEVENT',
    'UID:override-duration@example.com',
    'DTSTART;TZID=Europe/Berlin:20260328T090000',
    'DURATION:P1D',
    'RRULE:FREQ=DAILY;COUNT=3',
    'SUMMARY:Override duration',
    'END:VEVENT'
];
$overrideMethod = new ReflectionMethod(ICalendarCodec::class, 'createOccurrenceOverrideBlock');
$overrideBlock = $overrideMethod->invoke(
    null,
    $overrideMasterBlock,
    new DateTimeImmutable('2026-03-29T09:00:00+02:00')
);
assertTimezoneDst(
    true,
    in_array('DTEND;TZID=Europe/Berlin:20260330T090000', $overrideBlock, true),
    'Creating an occurrence override from DURATION must keep the nominal local end time after DST.'
);

fwrite(STDOUT, "Timezone and DST hardening tests passed.\n");
