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
    str_contains($moduleSource, 'public function GetDayAppointments(string $Date, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'return $this->GetAppointments($Date, $Date, $CalendarInstanceID);'),
    'Calendar View must expose a provider-independent GetDayAppointments PHP function with optional calendar filtering.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetAppointments(string $From, string $To, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'CalendarAppointmentRange::fromInclusiveDates($From, $To)')
        && str_contains($moduleSource, '$this->collectAppointmentsForRange($rangeStart, $rangeEnd)')
        && str_contains($moduleSource, '$this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID)'),
    'Calendar View must expose an inclusive provider-independent appointment range function with optional calendar filtering.'
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
    'Appointment APIs must optionally filter by selected calendar instance ID while zero keeps all calendars.'
);

assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetDayAppointmentCount(string $Date, int $CalendarInstanceID = 0): int')
        && str_contains($moduleSource, 'return $this->GetAppointmentCount($Date, $Date, $CalendarInstanceID);')
        && str_contains($moduleSource, 'public function GetAppointmentCount(string $From, string $To, int $CalendarInstanceID = 0): int'),
    'Calendar View must expose provider-independent appointment count functions with optional calendar filtering.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetRemainingDayAppointments(int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'public function GetRemainingDayAppointmentCount(int $CalendarInstanceID = 0): int')
        && str_contains($moduleSource, '$this->filterRemainingAppointments($appointments, $now->getTimestamp())'),
    'Calendar View must expose remaining-today appointment list and count functions.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetNextAppointment(int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'self::APPOINTMENT_LOOKAHEAD_DAYS')
        && str_contains($moduleSource, '$this->findNextAppointment($appointments, $now->getTimestamp())'),
    'Calendar View must expose the next cached appointment with optional calendar filtering.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetCurrentAppointments(int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, '$this->filterCurrentAppointments($appointments, $now->getTimestamp())')
        && str_contains($moduleSource, 'public function GetCurrentAppointmentCount(int $CalendarInstanceID = 0): int'),
    'Calendar View must expose appointments that are currently in progress and their count.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetUpcomingAppointments(int $Hours, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'public function GetUpcomingAppointmentCount(int $Hours, int $CalendarInstanceID = 0): int')
        && str_contains($moduleSource, '$this->filterUpcomingAppointments(')
        && str_contains($moduleSource, '$this->validateUpcomingHours($Hours);'),
    'Calendar View must expose upcoming appointment list and count functions with a bounded hour window.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetNextAppointments(int $Count, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'array_slice($this->filterFutureAppointments($appointments, $now->getTimestamp()), 0, $Count)'),
    'Calendar View must expose a configurable list of the next future appointments.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetDayReminders(string $Date, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'return $this->GetReminders($Date, $Date, $CalendarInstanceID);')
        && str_contains($moduleSource, 'public function GetReminders(string $From, string $To, int $CalendarInstanceID = 0): string'),
    'Calendar View must expose provider-neutral day and date-range reminder functions.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetUpcomingReminders(int $Minutes, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'public function GetNextReminder(int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'public function GetDueReminders(int $ToleranceMinutes = 1, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, '$this->collectRemindersForTimestampRange(')
        && str_contains($moduleSource, 'CalendarEventReminder::MAX_MINUTES_BEFORE_START'),
    'Calendar View must expose upcoming, next and due reminder functions using the provider-neutral reminder model.'
);
assertCalendarViewApi(
    str_contains($moduleSource, "'reminderId'")
        && str_contains($moduleSource, "'reminderMode'")
        && str_contains($moduleSource, "'minutesBeforeStart'")
        && str_contains($moduleSource, "'reminderTimestamp'")
        && str_contains($moduleSource, "'reminderDateTime'")
        && str_contains($moduleSource, "'reminderIndex'")
        && str_contains($moduleSource, "'reminderCount'"),
    'Reminder API results must expose stable IDs, effective lead time, trigger timestamps and per-event reminder positions.'
);
$normalizedModuleSource = preg_replace('/\s+/', ' ', $moduleSource) ?? $moduleSource;
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetSelectedCalendars(): string')
        && str_contains($moduleSource, '$this->loadSelectedCalendars()')
        && str_contains($moduleSource, 'private function loadSelectedCalendars(): array')
        && str_contains($normalizedModuleSource, "'timezone' => trim((string) (\$calendarStatus['timezone'] ?? ''))")
        && str_contains($normalizedModuleSource, "'canCreateRecurrence' => (bool) (\$calendarStatus['canCreateRecurrence'] ?? false)")
        && str_contains($normalizedModuleSource, "'canDeleteSeries' => (bool) (\$calendarStatus['canDeleteSeries'] ?? false)")
        && str_contains($normalizedModuleSource, "'maxReminders' => max(1, min(CalendarEventReminder::MAX_REMINDERS, (int) (\$calendarStatus['maxReminders'] ?? 1)))"),
    'Calendar View must expose selected calendar metadata including recurrence capabilities, reminder limits and timezone without colliding with its internal loader.'
);

require_once __DIR__ . '/stubs/ModuleStrictStubs.php';
require_once __DIR__ . '/../Kalender Ansicht/module.php';

$compactMethod = new ReflectionMethod(KalenderAnsicht::class, 'compactAppointments');
$compactMethod->setAccessible(true);
$filterMethod = new ReflectionMethod(KalenderAnsicht::class, 'filterAppointmentsByCalendarInstanceId');
$filterMethod->setAccessible(true);
$remainingMethod = new ReflectionMethod(KalenderAnsicht::class, 'filterRemainingAppointments');
$remainingMethod->setAccessible(true);
$currentMethod = new ReflectionMethod(KalenderAnsicht::class, 'filterCurrentAppointments');
$currentMethod->setAccessible(true);
$nextMethod = new ReflectionMethod(KalenderAnsicht::class, 'findNextAppointment');
$nextMethod->setAccessible(true);
$futureMethod = new ReflectionMethod(KalenderAnsicht::class, 'filterFutureAppointments');
$futureMethod->setAccessible(true);
$upcomingMethod = new ReflectionMethod(KalenderAnsicht::class, 'filterUpcomingAppointments');
$upcomingMethod->setAccessible(true);
$validateUpcomingHoursMethod = new ReflectionMethod(KalenderAnsicht::class, 'validateUpcomingHours');
$validateUpcomingHoursMethod->setAccessible(true);
$buildReminderRecordMethod = new ReflectionMethod(KalenderAnsicht::class, 'buildReminderRecord');
$buildReminderRecordMethod->setAccessible(true);
$buildReminderRecordsMethod = new ReflectionMethod(KalenderAnsicht::class, 'buildReminderRecords');
$buildReminderRecordsMethod->setAccessible(true);
$validateReminderMinutesWindowMethod = new ReflectionMethod(KalenderAnsicht::class, 'validateReminderMinutesWindow');
$validateReminderMinutesWindowMethod->setAccessible(true);
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

$now = (new DateTimeImmutable('2026-08-12T12:00:00+02:00'))->getTimestamp();
$stateAppointments = [
    [
        'summary'        => 'Ended',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T09:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-12T10:00:00+02:00'))->getTimestamp()
    ],
    [
        'summary'        => 'Current',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T11:30:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-12T12:30:00+02:00'))->getTimestamp()
    ],
    [
        'summary'        => 'All day',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T00:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-13T00:00:00+02:00'))->getTimestamp(),
        'allDay'         => true
    ],
    [
        'summary'        => 'Next',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T14:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-12T15:00:00+02:00'))->getTimestamp()
    ],
    [
        'summary'        => 'Later',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T16:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-12T17:00:00+02:00'))->getTimestamp()
    ]
];

$remaining = $remainingMethod->invoke($calendarView, $stateAppointments, $now);
assertCalendarViewApi(
    array_column($remaining, 'summary') === ['Current', 'All day', 'Next', 'Later'],
    'Remaining-day filtering must keep current, all-day and future appointments while excluding ended appointments.'
);

$current = $currentMethod->invoke($calendarView, $stateAppointments, $now);
assertCalendarViewApi(
    array_column($current, 'summary') === ['Current', 'All day'],
    'Current appointment filtering must include timed and all-day appointments covering the supplied timestamp.'
);

$chronologicalAppointments = [
    $stateAppointments[2],
    $stateAppointments[0],
    $stateAppointments[1],
    $stateAppointments[3],
    $stateAppointments[4]
];
$next = $nextMethod->invoke($calendarView, $chronologicalAppointments, $now);
assertCalendarViewApi(
    is_array($next) && ($next['summary'] ?? '') === 'Next',
    'Next appointment lookup must skip ended and currently running appointments and return the next future start.'
);
assertCalendarViewApi(
    $nextMethod->invoke($calendarView, [$stateAppointments[0], $stateAppointments[1]], $now) === null,
    'Next appointment lookup must return null when no future appointment is available.'
);

$future = $futureMethod->invoke($calendarView, $chronologicalAppointments, $now);
assertCalendarViewApi(
    array_column($future, 'summary') === ['Next', 'Later'],
    'Future appointment filtering must exclude ended and currently running appointments.'
);

$upcomingUntil = (new DateTimeImmutable('2026-08-12T15:00:00+02:00'))->getTimestamp();
$upcoming = $upcomingMethod->invoke($calendarView, $chronologicalAppointments, $now, $upcomingUntil);
assertCalendarViewApi(
    array_column($upcoming, 'summary') === ['Next'],
    'Upcoming appointment filtering must keep only future starts inside the requested hour window.'
);

$validateUpcomingHoursMethod->invoke($calendarView, 1);
$validateUpcomingHoursMethod->invoke($calendarView, 1095 * 24);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateUpcomingHoursMethod->invoke($calendarView, 0),
    'Upcoming appointment queries must reject a zero-hour window.'
);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateUpcomingHoursMethod->invoke($calendarView, (1095 * 24) + 1),
    'Upcoming appointment queries must reject windows beyond the maximum synchronized future range.'
);

$previousReminderTimezone = date_default_timezone_get();
date_default_timezone_set('Europe/Berlin');

$reminderCalendar = [
    'instanceId'            => 111,
    'name'                  => 'Calendar A',
    'color'                 => '#123456',
    'canUseDefaultReminder' => true,
    'maxReminders'          => 5,
    'defaultReminder'       => [
        'mode'               => 'custom',
        'minutesBeforeStart' => 15,
        'editable'           => true
    ]
];
$reminderStart = (new DateTimeImmutable('2026-08-12T10:00:00+02:00'))->getTimestamp();
$customReminderAppointment = [
    'uid'                => 'custom-reminder@example.com',
    'summary'            => 'Custom reminder',
    'calendarInstanceId' => 111,
    'calendarName'       => 'Calendar A',
    'calendarColor'      => '#123456',
    'start'              => '2026-08-12T10:00:00+02:00',
    'startTimestamp'     => $reminderStart,
    'allDay'             => false,
    'location'           => 'Office',
    'reminder'           => [
        'mode'               => 'custom',
        'minutesBeforeStart' => 30,
        'editable'           => true
    ]
];
$customReminderRecord = $buildReminderRecordMethod->invoke(
    $calendarView,
    $customReminderAppointment,
    $reminderCalendar
);
assertCalendarViewApi(
    is_array($customReminderRecord)
        && ($customReminderRecord['summary'] ?? '') === 'Custom reminder'
        && ($customReminderRecord['reminderMode'] ?? '') === 'custom'
        && ($customReminderRecord['minutesBeforeStart'] ?? -1) === 30
        && ($customReminderRecord['reminderTimestamp'] ?? 0) === $reminderStart - (30 * 60)
        && ($customReminderRecord['reminderDateTime'] ?? '') === '2026-08-12T09:30:00+02:00'
        && strlen((string) ($customReminderRecord['reminderId'] ?? '')) === 64,
    'Custom reminder records must expose their exact provider-neutral trigger.'
);
assertCalendarViewApi(
    $buildReminderRecordMethod->invoke($calendarView, $customReminderAppointment, $reminderCalendar)
        === $customReminderRecord,
    'Reminder IDs and normalized output must remain stable for unchanged appointment data.'
);

$multipleReminderAppointment = $customReminderAppointment;
$multipleReminderAppointment['uid'] = 'multiple-reminders@example.com';
$multipleReminderAppointment['summary'] = 'Multiple reminders';
$multipleReminderAppointment['reminder'] = [
    'mode'               => 'multiple',
    'minutesBeforeStart' => null,
    'reminders'          => [
        ['minutesBeforeStart' => 10],
        ['minutesBeforeStart' => 60]
    ],
    'editable'           => true
];
$multipleReminderRecords = $buildReminderRecordsMethod->invoke(
    $calendarView,
    $multipleReminderAppointment,
    $reminderCalendar
);
assertCalendarViewApi(
    is_array($multipleReminderRecords)
        && count($multipleReminderRecords) === 2
        && ($multipleReminderRecords[0]['reminderMode'] ?? '') === 'multiple'
        && ($multipleReminderRecords[0]['minutesBeforeStart'] ?? -1) === 10
        && ($multipleReminderRecords[0]['reminderIndex'] ?? -1) === 0
        && ($multipleReminderRecords[0]['reminderCount'] ?? 0) === 2
        && ($multipleReminderRecords[1]['minutesBeforeStart'] ?? -1) === 60
        && ($multipleReminderRecords[1]['reminderIndex'] ?? -1) === 1
        && ($multipleReminderRecords[1]['reminderCount'] ?? 0) === 2
        && ($multipleReminderRecords[0]['reminderTimestamp'] ?? 0) === $reminderStart - (10 * 60)
        && ($multipleReminderRecords[1]['reminderTimestamp'] ?? 0) === $reminderStart - (60 * 60)
        && ($multipleReminderRecords[0]['reminderId'] ?? '') !== ($multipleReminderRecords[1]['reminderId'] ?? ''),
    'One event with multiple reminders must produce one stable PHP API record per exact trigger.'
);
assertCalendarViewApi(
    $buildReminderRecordsMethod->invoke($calendarView, $multipleReminderAppointment, $reminderCalendar)
        === $multipleReminderRecords,
    'Multiple-reminder API IDs and ordering must remain stable for unchanged appointment data.'
);

$defaultReminderAppointment = $customReminderAppointment;
$defaultReminderAppointment['uid'] = 'default-reminder@example.com';
$defaultReminderAppointment['summary'] = 'Default reminder';
$defaultReminderAppointment['reminder'] = [
    'mode'               => 'default',
    'minutesBeforeStart' => null,
    'editable'           => true
];
$defaultReminderRecord = $buildReminderRecordMethod->invoke(
    $calendarView,
    $defaultReminderAppointment,
    $reminderCalendar
);
assertCalendarViewApi(
    is_array($defaultReminderRecord)
        && ($defaultReminderRecord['reminderMode'] ?? '') === 'default'
        && ($defaultReminderRecord['minutesBeforeStart'] ?? -1) === 15
        && ($defaultReminderRecord['reminderTimestamp'] ?? 0) === $reminderStart - (15 * 60),
    'Calendar-default reminders must resolve to the selected calendar default when it has one exact trigger.'
);

$multipleDefaultReminderCalendar = $reminderCalendar;
$multipleDefaultReminderCalendar['defaultReminder'] = [
    'mode'               => 'multiple',
    'minutesBeforeStart' => null,
    'reminders'          => [
        ['minutesBeforeStart' => 5],
        ['minutesBeforeStart' => 45]
    ],
    'editable'           => true
];
$multipleDefaultReminderRecords = $buildReminderRecordsMethod->invoke(
    $calendarView,
    $defaultReminderAppointment,
    $multipleDefaultReminderCalendar
);
assertCalendarViewApi(
    count($multipleDefaultReminderRecords) === 2
        && array_column($multipleDefaultReminderRecords, 'minutesBeforeStart') === [5, 45]
        && array_column($multipleDefaultReminderRecords, 'reminderMode') === ['default', 'default'],
    'Calendar-default reminder APIs must emit every exact trigger when the Google calendar default contains multiple reminders.'
);

$noneReminderAppointment = $customReminderAppointment;
$noneReminderAppointment['reminder'] = [
    'mode'               => 'none',
    'minutesBeforeStart' => null,
    'editable'           => true
];
assertCalendarViewApi(
    $buildReminderRecordMethod->invoke($calendarView, $noneReminderAppointment, $reminderCalendar) === null,
    'Disabled reminders must not produce due-reminder records.'
);

$complexReminderAppointment = $customReminderAppointment;
$complexReminderAppointment['reminder'] = [
    'mode'               => 'complex',
    'minutesBeforeStart' => null,
    'editable'           => false
];
assertCalendarViewApi(
    $buildReminderRecordMethod->invoke($calendarView, $complexReminderAppointment, $reminderCalendar) === null,
    'Complex reminder settings without one exact provider-neutral trigger must not be guessed.'
);

$noDefaultReminderCalendar = $reminderCalendar;
$noDefaultReminderCalendar['defaultReminder'] = [
    'mode'               => 'none',
    'minutesBeforeStart' => null,
    'editable'           => true
];
assertCalendarViewApi(
    $buildReminderRecordMethod->invoke(
        $calendarView,
        $defaultReminderAppointment,
        $noDefaultReminderCalendar
    ) === null,
    'A calendar default without an active reminder must not create an artificial reminder trigger.'
);

$validateReminderMinutesWindowMethod->invoke($calendarView, 1);
$validateReminderMinutesWindowMethod->invoke($calendarView, 1095 * 24 * 60);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateReminderMinutesWindowMethod->invoke($calendarView, 0),
    'Reminder windows must reject zero minutes.'
);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateReminderMinutesWindowMethod->invoke(
        $calendarView,
        (1095 * 24 * 60) + 1
    ),
    'Reminder windows must reject values beyond the synchronized future range.'
);

date_default_timezone_set($previousReminderTimezone);

fwrite(STDOUT, 'Calendar View PHP API checks passed.' . PHP_EOL);
