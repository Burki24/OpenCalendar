<?php

declare(strict_types=1);

use IPSKalender\CalendarAppointmentRange;

require_once __DIR__ . '/../libs/CalendarAppointmentRange.php';

function assertCalendarViewApi(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertCalendarViewApiThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('Europe/Berlin');

try {
    [$dayStart, $dayEnd] = CalendarAppointmentRange::fromInclusiveDates('2026-08-11', '2026-08-11');
    assertCalendarViewApi(
        $dayStart->format('Y-m-d H:i:s P') === '2026-08-11 00:00:00 +02:00'
            && $dayEnd->format('Y-m-d H:i:s P') === '2026-08-12 00:00:00 +02:00',
        'A one-day appointment query must use local midnight boundaries.'
    );

    [$dstStart, $dstEnd] = CalendarAppointmentRange::fromInclusiveDates('2026-10-25', '2026-10-25');
    assertCalendarViewApi(
        ($dstEnd->getTimestamp() - $dstStart->getTimestamp()) === 25 * 3600,
        'Local date ranges must retain daylight-saving transitions instead of assuming 24-hour days.'
    );

    assertCalendarViewApi(
        !CalendarAppointmentRange::eventOverlaps([
            'allDay' => true,
            'start'  => '2026-08-10',
            'end'    => '2026-08-11'
        ], $dayStart, $dayEnd),
        'An all-day event ending exclusively on the queried date must not spill into that day.'
    );
    assertCalendarViewApi(
        CalendarAppointmentRange::eventOverlaps([
            'allDay' => true,
            'start'  => '2026-08-11',
            'end'    => '2026-08-12'
        ], $dayStart, $dayEnd),
        'An all-day event covering the queried date must be returned.'
    );
    assertCalendarViewApi(
        CalendarAppointmentRange::eventOverlaps([
            'allDay'         => false,
            'startTimestamp' => (new DateTimeImmutable('2026-08-10T23:30:00+02:00'))->getTimestamp(),
            'endTimestamp'   => (new DateTimeImmutable('2026-08-11T00:30:00+02:00'))->getTimestamp()
        ], $dayStart, $dayEnd),
        'Timed appointments spanning midnight must be returned on every overlapping day.'
    );

    assertCalendarViewApiThrows(
        static fn (): array => CalendarAppointmentRange::fromInclusiveDates('2026-02-30', '2026-03-01'),
        'Invalid local dates must be rejected.'
    );
    assertCalendarViewApiThrows(
        static fn (): array => CalendarAppointmentRange::fromInclusiveDates('2026-08-12', '2026-08-11'),
        'An appointment range ending before it starts must be rejected.'
    );
} finally {
    date_default_timezone_set($previousTimezone);
}

$moduleSource = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/module.php');
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetDayAppointments(string $Date): string')
        && str_contains($moduleSource, 'return $this->GetAppointments($Date, $Date);'),
    'Calendar View must expose a provider-independent GetDayAppointments PHP function.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetAppointments(string $From, string $To): string')
        && str_contains($moduleSource, 'CalendarAppointmentRange::fromInclusiveDates($From, $To)')
        && str_contains($moduleSource, '$this->collectAppointmentsForRange($rangeStart, $rangeEnd)'),
    'Calendar View must expose an inclusive provider-independent appointment range function.'
);
assertCalendarViewApi(
    str_contains($moduleSource, '$event[\'calendarInstanceId\'] = $calendar[\'instanceId\'];')
        && str_contains($moduleSource, '$event[\'calendarName\'] = $calendar[\'name\'];')
        && str_contains($moduleSource, '$event[\'calendarColor\'] = $calendar[\'color\'];')
        && str_contains($moduleSource, '$event[\'canWrite\'] = $calendar[\'canWrite\'];'),
    'Calendar View appointment results must identify their source calendar.'
);

assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetDayAppointmentsCompact(string $Date, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'return $this->GetAppointmentsCompact($Date, $Date, $CalendarInstanceID);'),
    'Calendar View must expose a compact provider-independent day appointment function.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetAppointmentsCompact(string $From, string $To, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, '$this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID)'),
    'Calendar View must expose a compact provider-independent appointment range function.'
);
assertCalendarViewApi(
    str_contains($moduleSource, "'summary'   =>")
        && str_contains($moduleSource, "'start'     =>")
        && str_contains($moduleSource, "'end'       =>")
        && str_contains($moduleSource, "'startTime' =>")
        && str_contains($moduleSource, "'endTime'   =>")
        && str_contains($moduleSource, "Translate('All day')")
        && str_contains($moduleSource, "->format('Y-m-d');")
        && str_contains($moduleSource, "->modify('-1 day')->format('Y-m-d');")
        && str_contains($moduleSource, "->format('H:i');"),
    'Compact appointment results must contain only script-friendly dates and readable local clock values.'
);

assertCalendarViewApi(
    str_contains($moduleSource, 'private function filterAppointmentsByCalendarInstanceId(array $appointments, int $CalendarInstanceID): array')
        && str_contains($moduleSource, 'if ($CalendarInstanceID === 0)')
        && str_contains($moduleSource, '(int) ($appointment[\'calendarInstanceId\'] ?? 0) === $CalendarInstanceID'),
    'Compact appointment APIs must optionally filter by selected calendar instance ID while zero keeps all calendars.'
);

require_once __DIR__ . '/stubs/ModuleStrictStubs.php';
require_once __DIR__ . '/../Kalender Ansicht/module.php';

$compactMethod = new ReflectionMethod(KalenderAnsicht::class, 'compactAppointments');
$compactMethod->setAccessible(true);
$filterMethod = new ReflectionMethod(KalenderAnsicht::class, 'filterAppointmentsByCalendarInstanceId');
$filterMethod->setAccessible(true);
$calendarView = new KalenderAnsicht(1);
$previousTimezone = date_default_timezone_get();
date_default_timezone_set('Europe/Berlin');

try {
    $compact = $compactMethod->invoke($calendarView, [[
        'summary'        => 'Timed event',
        'start'          => '2026-08-12T09:00:00+02:00',
        'end'            => '2026-08-12T10:30:00+02:00',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T09:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-12T10:30:00+02:00'))->getTimestamp(),
        'allDay'         => false
    ], [
        'summary'        => 'All-day event',
        'start'          => '2026-08-12',
        'end'            => '2026-08-13',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T00:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-13T00:00:00+02:00'))->getTimestamp(),
        'allDay'         => true
    ], [
        'summary'        => 'Overnight event',
        'start'          => '2026-08-12T23:30:00+02:00',
        'end'            => '2026-08-13T00:30:00+02:00',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T23:30:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-13T00:30:00+02:00'))->getTimestamp(),
        'allDay'         => false
    ], [
        'summary'        => 'Multi-day all-day event',
        'start'          => '2026-08-12',
        'end'            => '2026-08-15',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T00:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-15T00:00:00+02:00'))->getTimestamp(),
        'allDay'         => true
    ]]);
} finally {
    date_default_timezone_set($previousTimezone);
}

$filterSource = [
    ['summary' => 'Calendar A', 'calendarInstanceId' => 111],
    ['summary' => 'Calendar B', 'calendarInstanceId' => 222],
    ['summary' => 'Calendar A second', 'calendarInstanceId' => 111]
];
assertCalendarViewApi(
    $filterMethod->invoke($calendarView, $filterSource, 0) === $filterSource,
    'Calendar instance ID zero must keep all compact appointments.'
);
assertCalendarViewApi(
    $filterMethod->invoke($calendarView, $filterSource, 111) === [$filterSource[0], $filterSource[2]],
    'A compact calendar filter must keep only appointments from the requested calendar instance.'
);
assertCalendarViewApi(
    $filterMethod->invoke($calendarView, $filterSource, 999) === [],
    'An unknown compact calendar filter must return an empty appointment list.'
);

assertCalendarViewApi(
    $compact[0] === [
        'summary'   => 'Timed event',
        'start'     => '2026-08-12',
        'end'       => '2026-08-12',
        'startTime' => '09:00',
        'endTime'   => '10:30'
    ],
    'Compact timed appointments must expose local dates and readable start and end times.'
);
assertCalendarViewApi(
    $compact[1] === [
        'summary'   => 'All-day event',
        'start'     => '2026-08-12',
        'end'       => '2026-08-12',
        'startTime' => 'All day',
        'endTime'   => ''
    ],
    'Compact all-day appointments must expose the visible inclusive end date and not invent clock times.'
);
assertCalendarViewApi(
    $compact[2] === [
        'summary'   => 'Overnight event',
        'start'     => '2026-08-12',
        'end'       => '2026-08-13',
        'startTime' => '23:30',
        'endTime'   => '00:30'
    ],
    'Compact overnight appointments must keep separate local start and end dates.'
);
assertCalendarViewApi(
    $compact[3] === [
        'summary'   => 'Multi-day all-day event',
        'start'     => '2026-08-12',
        'end'       => '2026-08-14',
        'startTime' => 'All day',
        'endTime'   => ''
    ],
    'Compact multi-day all-day appointments must convert the exclusive provider end to the visible inclusive end date.'
);

fwrite(STDOUT, 'Calendar View PHP API checks passed.' . PHP_EOL);
