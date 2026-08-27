<?php

declare(strict_types=1);

use IPSKalender\ICalendarCodec;

require_once __DIR__ . '/../libs/ICalendarCodec.php';

function assertYearWeekRecurrence(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL
                . 'Expected: ' . var_export($expected, true) . PHP_EOL
                . 'Actual:   ' . var_export($actual, true)
        );
    }
}

function recurrenceStarts(array $events, string $uid): array
{
    return array_values(array_map(
        static fn (array $event): string => (string) $event['start'],
        array_filter($events, static fn (array $event): bool => ($event['uid'] ?? '') === $uid)
    ));
}

$feed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:year-day-60@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260301\r\n"
    . "DTEND;VALUE=DATE:20260302\r\n"
    . "RRULE:FREQ=YEARLY;BYYEARDAY=60;COUNT=3\r\n"
    . "SUMMARY:Year day 60\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:last-year-day@example.com\r\n"
    . "DTSTART;VALUE=DATE:20261231\r\n"
    . "DTEND;VALUE=DATE:20270101\r\n"
    . "RRULE:FREQ=YEARLY;BYYEARDAY=-1;COUNT=3\r\n"
    . "SUMMARY:Last day of year\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:week-20-monday@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260511\r\n"
    . "DTEND;VALUE=DATE:20260512\r\n"
    . "RRULE:FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO;COUNT=3\r\n"
    . "SUMMARY:Monday of week 20\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:last-week-monday@example.com\r\n"
    . "DTSTART;VALUE=DATE:20251222\r\n"
    . "DTEND;VALUE=DATE:20251223\r\n"
    . "RRULE:FREQ=YEARLY;BYWEEKNO=-1;BYDAY=MO;COUNT=3\r\n"
    . "SUMMARY:Monday of last week\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:sunday-weekstart@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260105\r\n"
    . "DTEND;VALUE=DATE:20260106\r\n"
    . "RRULE:FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO;WKST=SU;COUNT=3\r\n"
    . "SUMMARY:Monday of week 1 with Sunday week start\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:whole-week@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260511\r\n"
    . "DTEND;VALUE=DATE:20260512\r\n"
    . "RRULE:FREQ=YEARLY;BYWEEKNO=20;COUNT=7\r\n"
    . "SUMMARY:Whole week 20\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:cross-year-week-interval@example.com\r\n"
    . "DTSTART;VALUE=DATE:20251229\r\n"
    . "DTEND;VALUE=DATE:20251230\r\n"
    . "RRULE:FREQ=YEARLY;INTERVAL=2;BYWEEKNO=1;BYDAY=MO;COUNT=3\r\n"
    . "SUMMARY:Cross-year week interval\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:invalid-monthly-year-day@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260410\r\n"
    . "DTEND;VALUE=DATE:20260411\r\n"
    . "RRULE:FREQ=MONTHLY;BYYEARDAY=100;COUNT=2\r\n"
    . "SUMMARY:Invalid monthly year day\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:invalid-weekly-weekno@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260511\r\n"
    . "DTEND;VALUE=DATE:20260512\r\n"
    . "RRULE:FREQ=WEEKLY;BYWEEKNO=20;COUNT=2\r\n"
    . "SUMMARY:Invalid weekly week number\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:invalid-ordinal-weekno@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260511\r\n"
    . "DTEND;VALUE=DATE:20260512\r\n"
    . "RRULE:FREQ=YEARLY;BYWEEKNO=20;BYDAY=1MO;COUNT=2\r\n"
    . "SUMMARY:Invalid ordinal weekday with week number\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";

$events = ICalendarCodec::parseEventsInRange(
    $feed,
    'https://calendar.example/year-week.ics',
    '',
    new DateTimeImmutable('2025-01-01T00:00:00Z'),
    new DateTimeImmutable('2031-01-10T00:00:00Z')
);

assertYearWeekRecurrence(
    ['2026-03-01', '2027-03-01', '2028-02-29'],
    recurrenceStarts($events, 'year-day-60@example.com'),
    'BYYEARDAY must follow leap-year day numbering.'
);
assertYearWeekRecurrence(
    ['2026-12-31', '2027-12-31', '2028-12-31'],
    recurrenceStarts($events, 'last-year-day@example.com'),
    'Negative BYYEARDAY values must count backwards from year end.'
);
assertYearWeekRecurrence(
    ['2026-05-11', '2027-05-17', '2028-05-15'],
    recurrenceStarts($events, 'week-20-monday@example.com'),
    'BYWEEKNO with BYDAY must match RFC week-number semantics.'
);
assertYearWeekRecurrence(
    ['2025-12-22', '2026-12-28', '2027-12-27'],
    recurrenceStarts($events, 'last-week-monday@example.com'),
    'Negative BYWEEKNO values must count backwards from the final week of the week-year.'
);
assertYearWeekRecurrence(
    ['2026-01-05', '2027-01-04', '2028-01-03'],
    recurrenceStarts($events, 'sunday-weekstart@example.com'),
    'WKST must affect YEARLY BYWEEKNO calculations.'
);
assertYearWeekRecurrence(
    [
        '2026-05-11',
        '2026-05-12',
        '2026-05-13',
        '2026-05-14',
        '2026-05-15',
        '2026-05-16',
        '2026-05-17'
    ],
    recurrenceStarts($events, 'whole-week@example.com'),
    'BYWEEKNO without BYDAY must expand the complete selected week.'
);
assertYearWeekRecurrence(
    ['2025-12-29', '2028-01-03', '2029-12-31'],
    recurrenceStarts($events, 'cross-year-week-interval@example.com'),
    'YEARLY intervals with BYWEEKNO must be anchored to the RFC week-year across calendar-year boundaries.'
);

foreach ([
    'year-day-60@example.com',
    'last-year-day@example.com',
    'week-20-monday@example.com',
    'last-week-monday@example.com',
    'sunday-weekstart@example.com',
    'whole-week@example.com',
    'cross-year-week-interval@example.com'
] as $uid) {
    $matching = array_values(array_filter(
        $events,
        static fn (array $event): bool => ($event['uid'] ?? '') === $uid
    ));
    assertYearWeekRecurrence(true, $matching[0]['recurrenceExpansionSupported'], $uid . ' must be safely expandable.');
    assertYearWeekRecurrence(
        [],
        $matching[0]['recurrenceUnsupportedRuleParts'] ?? [],
        $uid . ' must not report unsupported rule parts.'
    );
}

foreach ([
    'invalid-monthly-year-day@example.com' => ['BYYEARDAY'],
    'invalid-weekly-weekno@example.com'    => ['BYWEEKNO'],
    'invalid-ordinal-weekno@example.com'   => ['BYDAY']
] as $uid => $unsupportedParts) {
    $matching = array_values(array_filter(
        $events,
        static fn (array $event): bool => ($event['uid'] ?? '') === $uid
    ));
    assertYearWeekRecurrence(1, count($matching), $uid . ' must keep only the explicit DTSTART occurrence.');
    assertYearWeekRecurrence(false, $matching[0]['recurrenceExpansionSupported'], $uid . ' must remain unsupported.');
    assertYearWeekRecurrence(
        $unsupportedParts,
        $matching[0]['recurrenceUnsupportedRuleParts'],
        $uid . ' must report the exact unsupported RFC rule part.'
    );
}

fwrite(STDOUT, "RFC BYYEARDAY/BYWEEKNO recurrence checks passed.\n");
