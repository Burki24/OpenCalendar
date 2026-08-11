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
            'allDay'        => false,
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

fwrite(STDOUT, 'Calendar View PHP API checks passed.' . PHP_EOL);
