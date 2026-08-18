<?php

declare(strict_types=1);

use IPSKalender\ICalendarRecurrence;

require_once __DIR__ . '/../libs/ICalendarRecurrence.php';

function assertTimedSetPosition(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL
                . 'Expected: ' . var_export($expected, true) . PHP_EOL
                . 'Actual:   ' . var_export($actual, true)
        );
    }
}

/** @return array<string, mixed> */
function timedSetPositionMaster(
    string $uid,
    string $start,
    string $end,
    string $rule,
    string $timezone = 'UTC'
): array {
    $zone = new DateTimeZone($timezone);
    $startDate = new DateTimeImmutable($start, $zone);
    $endDate = new DateTimeImmutable($end, $zone);

    return [
        'uid'             => $uid,
        'summary'         => $uid,
        'start'           => $startDate->format(DATE_ATOM),
        'end'             => $endDate->format(DATE_ATOM),
        'startTimestamp'  => $startDate->getTimestamp(),
        'endTimestamp'    => $endDate->getTimestamp(),
        'allDay'          => false,
        'timezone'        => $timezone,
        'recurrenceRule'  => $rule,
        'recurrenceDates' => [],
        'exceptionDates'  => []
    ];
}

/** @return list<string> */
function timedSetPositionStarts(array $events): array
{
    return array_values(array_map(
        static fn (array $event): string => (string) $event['start'],
        $events
    ));
}

$daily = timedSetPositionMaster(
    'daily-setpos@example.com',
    '2026-07-01 17:30:00',
    '2026-07-01 18:00:00',
    'FREQ=DAILY;BYHOUR=9,12,17;BYMINUTE=0,30;BYSETPOS=-1;COUNT=3'
);
assertTimedSetPosition(
    [
        '2026-07-01T17:30:00+00:00',
        '2026-07-02T17:30:00+00:00',
        '2026-07-03T17:30:00+00:00'
    ],
    timedSetPositionStarts(ICalendarRecurrence::expand(
        [$daily],
        new DateTimeImmutable('2026-07-01T00:00:00Z'),
        new DateTimeImmutable('2026-07-05T00:00:00Z')
    )),
    'BYSETPOS=-1 must select the final expanded time candidate of each DAILY interval.'
);

$hourly = timedSetPositionMaster(
    'hourly-setpos@example.com',
    '2026-07-01 09:50:00',
    '2026-07-01 10:00:00',
    'FREQ=HOURLY;BYMINUTE=10,30,50;BYSETPOS=-1;COUNT=4'
);
assertTimedSetPosition(
    [
        '2026-07-01T09:50:00+00:00',
        '2026-07-01T10:50:00+00:00',
        '2026-07-01T11:50:00+00:00',
        '2026-07-01T12:50:00+00:00'
    ],
    timedSetPositionStarts(ICalendarRecurrence::expand(
        [$hourly],
        new DateTimeImmutable('2026-07-01T00:00:00Z'),
        new DateTimeImmutable('2026-07-02T00:00:00Z')
    )),
    'HOURLY BYSETPOS must be applied after BYMINUTE expansion inside each hour.'
);

$yearly = timedSetPositionMaster(
    'yearly-setpos@example.com',
    '2026-01-26 17:00:00',
    '2026-01-26 18:00:00',
    'FREQ=YEARLY;BYMONTH=1;BYDAY=MO;BYHOUR=9,17;BYSETPOS=-1;COUNT=2'
);
assertTimedSetPosition(
    [
        '2026-01-26T17:00:00+00:00',
        '2027-01-25T17:00:00+00:00'
    ],
    timedSetPositionStarts(ICalendarRecurrence::expand(
        [$yearly],
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        new DateTimeImmutable('2028-01-01T00:00:00Z')
    )),
    'YEARLY BYSETPOS must select across all expanded date/time candidates in the yearly interval.'
);

$midWeek = timedSetPositionMaster(
    'midweek-scan@example.com',
    '2026-06-29 09:00:00',
    '2026-06-29 10:00:00',
    'FREQ=WEEKLY;BYDAY=MO,WE;BYHOUR=9,17;BYSETPOS=1'
);
assertTimedSetPosition(
    [],
    timedSetPositionStarts(ICalendarRecurrence::expand(
        [$midWeek],
        new DateTimeImmutable('2026-07-01T00:00:00Z'),
        new DateTimeImmutable('2026-07-02T00:00:00Z')
    )),
    'A range that starts mid-week must still evaluate BYSETPOS from the beginning of the weekly interval.'
);

$midMonth = timedSetPositionMaster(
    'midmonth-scan@example.com',
    '2026-06-30 17:00:00',
    '2026-06-30 18:00:00',
    'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYHOUR=9,17;BYSETPOS=-1'
);
assertTimedSetPosition(
    [],
    timedSetPositionStarts(ICalendarRecurrence::expand(
        [$midMonth],
        new DateTimeImmutable('2026-07-01T00:00:00Z'),
        new DateTimeImmutable('2026-07-15T00:00:00Z')
    )),
    'A range that ends mid-month must not mistake the last in-range candidate for the monthly BYSETPOS=-1 occurrence.'
);

$dst = timedSetPositionMaster(
    'dst-setpos@example.com',
    '2026-03-28 02:30:00',
    '2026-03-28 03:00:00',
    'FREQ=DAILY;BYHOUR=1,2,3;BYMINUTE=30;BYSETPOS=2;COUNT=2',
    'Europe/Berlin'
);
assertTimedSetPosition(
    [
        '2026-03-28T02:30:00+01:00',
        '2026-03-29T03:30:00+02:00'
    ],
    timedSetPositionStarts(ICalendarRecurrence::expand(
        [$dst],
        new DateTimeImmutable('2026-03-27T00:00:00Z'),
        new DateTimeImmutable('2026-03-31T00:00:00Z')
    )),
    'Nonexistent DST candidates must be discarded before BYSETPOS selects a position.'
);

$diagnostics = ICalendarRecurrence::diagnostics(ICalendarRecurrence::expand(
    [$daily, $hourly],
    new DateTimeImmutable('2026-07-01T00:00:00Z'),
    new DateTimeImmutable('2026-07-05T00:00:00Z')
));
assertTimedSetPosition(2, $diagnostics['supportedSeriesCount'], 'Timed BYSETPOS rules must be reported as supported.');
assertTimedSetPosition(0, $diagnostics['unsupportedSeriesCount'], 'Timed BYSETPOS rules must not remain in the safety fallback.');

fwrite(STDOUT, "RFC time-aware BYSETPOS recurrence checks passed.\n");
