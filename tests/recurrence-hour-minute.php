<?php

declare(strict_types=1);

use IPSKalender\ICalendarCodec;

require_once __DIR__ . '/../libs/ICalendarCodec.php';

function assertHourMinuteRecurrence(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL
                . 'Expected: ' . var_export($expected, true) . PHP_EOL
                . 'Actual:   ' . var_export($actual, true)
        );
    }
}

function hourMinuteStarts(array $events, string $uid): array
{
    return array_values(array_map(
        static fn (array $event): string => (string) $event['start'],
        array_filter($events, static fn (array $event): bool => ($event['uid'] ?? '') === $uid)
    ));
}

function hourMinuteEvents(array $events, string $uid): array
{
    return array_values(array_filter(
        $events,
        static fn (array $event): bool => ($event['uid'] ?? '') === $uid
    ));
}

$feed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:daily-grid@example.com\r\n"
    . "DTSTART:20260701T090000Z\r\n"
    . "DTEND:20260701T100000Z\r\n"
    . "RRULE:FREQ=DAILY;BYHOUR=9,17;BYMINUTE=0,30;COUNT=6\r\n"
    . "SUMMARY:Daily time grid\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:hourly-interval@example.com\r\n"
    . "DTSTART:20260701T090000Z\r\n"
    . "DTEND:20260701T093000Z\r\n"
    . "RRULE:FREQ=HOURLY;INTERVAL=3;COUNT=4\r\n"
    . "SUMMARY:Three-hour interval\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:hourly-minute@example.com\r\n"
    . "DTSTART:20260701T091000Z\r\n"
    . "DTEND:20260701T092000Z\r\n"
    . "RRULE:FREQ=HOURLY;BYMINUTE=10,40;COUNT=5\r\n"
    . "SUMMARY:Minutes within each hour\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:hourly-hour-filter@example.com\r\n"
    . "DTSTART:20260701T091500Z\r\n"
    . "DTEND:20260701T094500Z\r\n"
    . "RRULE:FREQ=HOURLY;BYHOUR=9,17;BYMINUTE=15;COUNT=4\r\n"
    . "SUMMARY:Selected hours only\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:dst-gap@example.com\r\n"
    . "DTSTART;TZID=Europe/Berlin:20260328T023000\r\n"
    . "DTEND;TZID=Europe/Berlin:20260328T033000\r\n"
    . "RRULE:FREQ=DAILY;BYHOUR=2;BYMINUTE=30;COUNT=3\r\n"
    . "SUMMARY:DST gap\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:all-day-ignore@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260701\r\n"
    . "DTEND;VALUE=DATE:20260702\r\n"
    . "RRULE:FREQ=DAILY;BYHOUR=9;BYMINUTE=30;COUNT=2\r\n"
    . "SUMMARY:All-day ignored time selectors\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:invalid-hour@example.com\r\n"
    . "DTSTART:20260701T090000Z\r\n"
    . "DTEND:20260701T100000Z\r\n"
    . "RRULE:FREQ=DAILY;BYHOUR=24;COUNT=2\r\n"
    . "SUMMARY:Invalid hour\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:invalid-minute@example.com\r\n"
    . "DTSTART:20260701T090000Z\r\n"
    . "DTEND:20260701T100000Z\r\n"
    . "RRULE:FREQ=DAILY;BYMINUTE=60;COUNT=2\r\n"
    . "SUMMARY:Invalid minute\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:unsupported-bysecond@example.com\r\n"
    . "DTSTART:20260701T090000Z\r\n"
    . "DTEND:20260701T100000Z\r\n"
    . "RRULE:FREQ=DAILY;BYSECOND=30;COUNT=2\r\n"
    . "SUMMARY:Unsupported seconds\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:daily-setpos-last@example.com\r\n"
    . "DTSTART:20260701T173000Z\r\n"
    . "DTEND:20260701T180000Z\r\n"
    . "RRULE:FREQ=DAILY;BYHOUR=9,12,17;BYMINUTE=0,30;BYSETPOS=-1;COUNT=3\r\n"
    . "SUMMARY:Last daily time candidate\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:daily-setpos-multiple@example.com\r\n"
    . "DTSTART:20260701T093000Z\r\n"
    . "DTEND:20260701T100000Z\r\n"
    . "RRULE:FREQ=DAILY;BYHOUR=9,12,17;BYMINUTE=0,30;BYSETPOS=2,5;COUNT=4\r\n"
    . "SUMMARY:Multiple daily set positions\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:hourly-setpos-last@example.com\r\n"
    . "DTSTART:20260701T095000Z\r\n"
    . "DTEND:20260701T100000Z\r\n"
    . "RRULE:FREQ=HOURLY;BYMINUTE=10,30,50;BYSETPOS=-1;COUNT=4\r\n"
    . "SUMMARY:Last minute candidate per hour\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:weekly-setpos-time@example.com\r\n"
    . "DTSTART:20260701T170000Z\r\n"
    . "DTEND:20260701T173000Z\r\n"
    . "RRULE:FREQ=WEEKLY;BYDAY=MO,WE;BYHOUR=9,17;BYSETPOS=-1;COUNT=3\r\n"
    . "SUMMARY:Last weekly time candidate\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:all-day-hourly@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260701\r\n"
    . "DTEND;VALUE=DATE:20260702\r\n"
    . "RRULE:FREQ=HOURLY;COUNT=2\r\n"
    . "SUMMARY:Invalid all-day hourly recurrence\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:unsupported-minutely@example.com\r\n"
    . "DTSTART:20260701T090000Z\r\n"
    . "DTEND:20260701T091000Z\r\n"
    . "RRULE:FREQ=MINUTELY;INTERVAL=15;COUNT=3\r\n"
    . "SUMMARY:Unsupported minutely frequency\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";

$events = ICalendarCodec::parseEventsInRange(
    $feed,
    'https://calendar.example/hour-minute.ics',
    '',
    new DateTimeImmutable('2026-03-27T00:00:00Z'),
    new DateTimeImmutable('2026-07-20T00:00:00Z')
);

assertHourMinuteRecurrence(
    [
        '2026-07-01T09:00:00+00:00',
        '2026-07-01T09:30:00+00:00',
        '2026-07-01T17:00:00+00:00',
        '2026-07-01T17:30:00+00:00',
        '2026-07-02T09:00:00+00:00',
        '2026-07-02T09:30:00+00:00'
    ],
    hourMinuteStarts($events, 'daily-grid@example.com'),
    'DAILY BYHOUR/BYMINUTE must expand all requested time combinations in chronological order.'
);
assertHourMinuteRecurrence(
    [
        '2026-07-01T09:00:00+00:00',
        '2026-07-01T12:00:00+00:00',
        '2026-07-01T15:00:00+00:00',
        '2026-07-01T18:00:00+00:00'
    ],
    hourMinuteStarts($events, 'hourly-interval@example.com'),
    'HOURLY INTERVAL must advance from DTSTART by the requested number of hours.'
);
assertHourMinuteRecurrence(
    [
        '2026-07-01T09:10:00+00:00',
        '2026-07-01T09:40:00+00:00',
        '2026-07-01T10:10:00+00:00',
        '2026-07-01T10:40:00+00:00',
        '2026-07-01T11:10:00+00:00'
    ],
    hourMinuteStarts($events, 'hourly-minute@example.com'),
    'HOURLY BYMINUTE must expand multiple minutes within each selected hour.'
);
assertHourMinuteRecurrence(
    [
        '2026-07-01T09:15:00+00:00',
        '2026-07-01T17:15:00+00:00',
        '2026-07-02T09:15:00+00:00',
        '2026-07-02T17:15:00+00:00'
    ],
    hourMinuteStarts($events, 'hourly-hour-filter@example.com'),
    'HOURLY BYHOUR must limit hourly periods while BYMINUTE expands inside matching hours.'
);
assertHourMinuteRecurrence(
    [
        '2026-03-28T02:30:00+01:00',
        '2026-03-30T02:30:00+02:00',
        '2026-03-31T02:30:00+02:00'
    ],
    hourMinuteStarts($events, 'dst-gap@example.com'),
    'Nonexistent local recurrence times during a DST gap must be ignored and must not consume COUNT.'
);
assertHourMinuteRecurrence(
    ['2026-07-01', '2026-07-02'],
    hourMinuteStarts($events, 'all-day-ignore@example.com'),
    'BYHOUR and BYMINUTE must be ignored for DATE-valued DTSTART as required by RFC 5545.'
);
assertHourMinuteRecurrence(
    [
        '2026-07-01T17:30:00+00:00',
        '2026-07-02T17:30:00+00:00',
        '2026-07-03T17:30:00+00:00'
    ],
    hourMinuteStarts($events, 'daily-setpos-last@example.com'),
    'DAILY BYSETPOS must select from the fully expanded BYHOUR/BYMINUTE candidate set.'
);
assertHourMinuteRecurrence(
    [
        '2026-07-01T09:30:00+00:00',
        '2026-07-01T17:00:00+00:00',
        '2026-07-02T09:30:00+00:00',
        '2026-07-02T17:00:00+00:00'
    ],
    hourMinuteStarts($events, 'daily-setpos-multiple@example.com'),
    'Multiple DAILY BYSETPOS values must preserve chronological candidate order and COUNT.'
);
assertHourMinuteRecurrence(
    [
        '2026-07-01T09:50:00+00:00',
        '2026-07-01T10:50:00+00:00',
        '2026-07-01T11:50:00+00:00',
        '2026-07-01T12:50:00+00:00'
    ],
    hourMinuteStarts($events, 'hourly-setpos-last@example.com'),
    'HOURLY BYSETPOS must select from BYMINUTE candidates inside each hour.'
);
assertHourMinuteRecurrence(
    [
        '2026-07-01T17:00:00+00:00',
        '2026-07-08T17:00:00+00:00',
        '2026-07-15T17:00:00+00:00'
    ],
    hourMinuteStarts($events, 'weekly-setpos-time@example.com'),
    'WEEKLY BYSETPOS must operate across all expanded date/time candidates in the week.'
);

foreach ([
    'daily-grid@example.com',
    'hourly-interval@example.com',
    'hourly-minute@example.com',
    'hourly-hour-filter@example.com',
    'dst-gap@example.com',
    'all-day-ignore@example.com',
    'daily-setpos-last@example.com',
    'daily-setpos-multiple@example.com',
    'hourly-setpos-last@example.com',
    'weekly-setpos-time@example.com'
] as $uid) {
    $matching = hourMinuteEvents($events, $uid);
    assertHourMinuteRecurrence(true, $matching[0]['recurrenceExpansionSupported'], $uid . ' must be safely expandable.');
    assertHourMinuteRecurrence(
        [],
        $matching[0]['recurrenceUnsupportedRuleParts'] ?? [],
        $uid . ' must not report unsupported recurrence rule parts.'
    );
}

foreach ([
    'invalid-hour@example.com'             => ['BYHOUR'],
    'invalid-minute@example.com'           => ['BYMINUTE'],
    'unsupported-bysecond@example.com'     => ['BYSECOND'],
    'all-day-hourly@example.com'           => ['FREQ=HOURLY'],
    'unsupported-minutely@example.com'     => ['FREQ=MINUTELY']
] as $uid => $unsupportedParts) {
    $matching = hourMinuteEvents($events, $uid);
    assertHourMinuteRecurrence(1, count($matching), $uid . ' must keep only the explicit DTSTART occurrence.');
    assertHourMinuteRecurrence(false, $matching[0]['recurrenceExpansionSupported'], $uid . ' must remain unsupported.');
    assertHourMinuteRecurrence(
        $unsupportedParts,
        $matching[0]['recurrenceUnsupportedRuleParts'],
        $uid . ' must report the exact unsupported recurrence rule part.'
    );
}

$oldHourlyFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:old-hourly@example.com\r\n"
    . "DTSTART:19900101T000000Z\r\n"
    . "DTEND:19900101T003000Z\r\n"
    . "RRULE:FREQ=HOURLY\r\n"
    . "SUMMARY:Old hourly series\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$oldHourlyEvents = ICalendarCodec::parseEventsInRange(
    $oldHourlyFeed,
    'https://calendar.example/old-hourly.ics',
    '',
    new DateTimeImmutable('2026-07-01T00:00:00Z'),
    new DateTimeImmutable('2026-07-01T04:00:00Z')
);
assertHourMinuteRecurrence(
    [
        '2026-07-01T00:00:00+00:00',
        '2026-07-01T01:00:00+00:00',
        '2026-07-01T02:00:00+00:00',
        '2026-07-01T03:00:00+00:00'
    ],
    hourMinuteStarts($oldHourlyEvents, 'old-hourly@example.com'),
    'Unbounded old HOURLY series must scan from the requested range instead of exhausting the safety budget from DTSTART.'
);
assertHourMinuteRecurrence(
    true,
    $oldHourlyEvents[0]['recurrenceExpansionSupported'],
    'Old unbounded HOURLY series must remain safely expandable in a narrow requested range.'
);

$source = file_get_contents(__DIR__ . '/../libs/ICalendarRecurrence.php');
assertHourMinuteRecurrence(true, is_string($source), 'Recurrence source must be readable for safety-contract checks.');
assertHourMinuteRecurrence(
    true,
    str_contains($source, 'MAX_GENERATED_OCCURRENCES')
        && str_contains($source, "'EXPANSION_LIMIT'"),
    'High-frequency recurrence expansion must retain an explicit occurrence safety limit.'
);

fwrite(STDOUT, "RFC BYHOUR/BYMINUTE/HOURLY recurrence checks passed.\n");
