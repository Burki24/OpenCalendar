<?php

declare(strict_types=1);

use IPSKalender\CalendarEventCounter;

require_once __DIR__ . '/../libs/CalendarEventCounter.php';

function assertEventCounterSame(int $expected, int $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s Expected %d, got %d.', $message, $expected, $actual));
    }
}

$timezone = new DateTimeZone('Europe/Berlin');
$day = new DateTimeImmutable('2026-08-10 12:00:00', $timezone);
$timestamp = static fn (string $value): int => (new DateTimeImmutable($value, $timezone))->getTimestamp();

$events = [
    [
        'startTimestamp' => $timestamp('2026-08-10 09:00:00'),
        'endTimestamp'   => $timestamp('2026-08-10 10:00:00')
    ],
    [
        'startTimestamp' => $timestamp('2026-08-09 00:00:00'),
        'endTimestamp'   => $timestamp('2026-08-11 00:00:00')
    ],
    [
        'startTimestamp' => $timestamp('2026-08-10 12:00:00'),
        'endTimestamp'   => $timestamp('2026-08-10 12:00:00')
    ],
    [
        'startTimestamp' => $timestamp('2026-08-09 23:00:00'),
        'endTimestamp'   => $timestamp('2026-08-10 00:00:00')
    ],
    [
        'startTimestamp' => $timestamp('2026-08-11 00:00:00'),
        'endTimestamp'   => $timestamp('2026-08-11 01:00:00')
    ],
    ['summary' => 'Invalid event without timestamps']
];

assertEventCounterSame(
    3,
    CalendarEventCounter::countForDay($events, $day),
    'Timed, multi-day and zero-duration events must be counted once when they overlap the local day.'
);
assertEventCounterSame(
    0,
    CalendarEventCounter::countForDay(
        [[
            'startTimestamp' => $timestamp('2026-08-10 00:00:00'),
            'endTimestamp'   => $timestamp('2026-08-11 00:00:00')
        ]],
        $day->modify('+1 day')
    ),
    'An all-day event with an exclusive midnight end must not count on the following day.'
);

assertEventCounterSame(
    0,
    CalendarEventCounter::countForDay(
        [[
            'allDay'         => true,
            'start'          => '2026-08-10',
            'end'            => '2026-08-11',
            'startTimestamp' => (new DateTimeImmutable('2026-08-10T00:00:00Z'))->getTimestamp(),
            'endTimestamp'   => (new DateTimeImmutable('2026-08-11T00:00:00Z'))->getTimestamp()
        ]],
        $day->modify('+1 day')
    ),
    'A UTC-backed all-day event must use its exclusive date-only end and not spill into the next local day.'
);

fwrite(STDOUT, "Calendar event counter tests passed.\n");
