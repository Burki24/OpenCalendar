<?php

declare(strict_types=1);

use IPSKalender\CalendarTaskEvent;

require_once dirname(__DIR__) . '/libs/CalendarTaskEvent.php';
require_once __DIR__ . '/stubs/autoload.php';
require_once dirname(__DIR__) . '/Kalender/module.php';

function assertTaskAppointment(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$created = CalendarTaskEvent::prepareWrite([
    'summary'       => 'Versicherung prüfen',
    'task'          => true,
    'taskCompleted' => false,
    'allDay'        => true,
    'start'         => '2026-09-08',
    'end'           => '2026-09-09'
]);
assertTaskAppointment(
    $created['summary'] === '☐ Versicherung prüfen'
        && !array_key_exists('task', $created)
        && !array_key_exists('taskCompleted', $created),
    'Creating a task appointment must persist only the open title marker.'
);

$enriched = CalendarTaskEvent::enrich($created);
assertTaskAppointment(
    ($enriched['task'] ?? false) === true
        && ($enriched['taskCompleted'] ?? true) === false
        && ($enriched['taskStatus'] ?? '') === 'open'
        && ($enriched['displaySummary'] ?? '') === 'Versicherung prüfen',
    'An open task marker must be exposed as normalized task metadata.'
);

$completed = CalendarTaskEvent::prepareWrite(
    ['task' => true, 'taskCompleted' => true],
    $enriched + ['allDay' => true, 'recurring' => false]
);
assertTaskAppointment(
    $completed['summary'] === '☑ Versicherung prüfen',
    'Completing a task must replace its open marker without changing the title.'
);

$followPlanned = CalendarTaskEvent::prepareWrite(
    ['task' => true, 'taskFollowPlanned' => true],
    $enriched + ['allDay' => true, 'recurring' => true, 'start' => '2026-09-08', 'end' => '2026-09-09']
);
assertTaskAppointment(
    $followPlanned['summary'] === '☐↻ Versicherung prüfen'
        && (CalendarTaskEvent::enrich($followPlanned)['taskFollowPlanned'] ?? false) === true,
    'A task series must persist the choice to move planned follow-up appointments.'
);

$renamed = CalendarTaskEvent::prepareWrite(
    ['summary' => 'Versicherung wechseln'],
    $enriched + ['allDay' => true, 'recurring' => false]
);
assertTaskAppointment(
    $renamed['summary'] === '☐ Versicherung wechseln',
    'Renaming a task without explicit task fields must preserve its current task status.'
);

$reopened = CalendarTaskEvent::prepareWrite(
    ['task' => true, 'taskCompleted' => false],
    CalendarTaskEvent::enrich($completed + ['allDay' => true, 'recurring' => false])
);
assertTaskAppointment(
    $reopened['summary'] === '☐ Versicherung prüfen',
    'Reopening a task must restore its open marker.'
);

$normal = CalendarTaskEvent::prepareWrite(
    ['summary' => 'Versicherung prüfen', 'task' => false],
    $enriched
);
assertTaskAppointment(
    $normal['summary'] === 'Versicherung prüfen',
    'Converting a task back to a normal event must remove its marker.'
);
$normalEnriched = CalendarTaskEvent::enrich(array_merge($enriched, $normal));
assertTaskAppointment(
    !array_key_exists('task', $normalEnriched) && !array_key_exists('displaySummary', $normalEnriched),
    'Converting a task back to a normal event must remove stale task display metadata.'
);

$calendar = new Calendar(9012);
$registerString = new ReflectionMethod(IPSModuleStrict::class, 'RegisterAttributeString');
$registerString->setAccessible(true);
$registerString->invoke($calendar, 'CachedEvents', '[]');
$registerString->invoke($calendar, 'AnniversaryMetadata', '[]');
$registerString->invoke($calendar, 'BirthdayMetadata', '[]');
$writeString = new ReflectionMethod(IPSModuleStrict::class, 'WriteAttributeString');
$writeString->setAccessible(true);
$writeString->invoke($calendar, 'CachedEvents', json_encode([[
    'uid'     => 'task-event',
    'summary' => '☐ Versicherung prüfen'
]], JSON_THROW_ON_ERROR));
$cachedTaskForEdit = new ReflectionMethod(Calendar::class, 'cachedTaskEventForEdit');
$cachedTaskForEdit->setAccessible(true);
$fallbackTask = $cachedTaskForEdit->invoke(
    $calendar,
    ['uid' => 'task-event'],
    new RuntimeException('Provider lookup temporarily unavailable.')
);
assertTaskAppointment(
    is_array($fallbackTask)
        && ($fallbackTask['task'] ?? false) === true
        && ($fallbackTask['displaySummary'] ?? '') === 'Versicherung prüfen',
    'A cached task must remain editable when its fresh provider lookup is temporarily unavailable.'
);
$writeString->invoke($calendar, 'CachedEvents', json_encode([[
    'uid'     => 'normal-event',
    'summary' => 'Versicherung prüfen'
]], JSON_THROW_ON_ERROR));
assertTaskAppointment(
    $cachedTaskForEdit->invoke(
        $calendar,
        ['uid' => 'normal-event'],
        new RuntimeException('Provider lookup temporarily unavailable.')
    ) === null,
    'Normal appointments must keep the strict fresh-provider edit lookup.'
);

$rollForward = CalendarTaskEvent::rollForwardChanges(
    $enriched + ['allDay' => true, 'recurring' => false, 'start' => '2026-09-08', 'end' => '2026-09-09'],
    new DateTimeImmutable('2026-09-10')
);
assertTaskAppointment(
    $rollForward === ['allDay' => true, 'start' => '2026-09-10', 'end' => '2026-09-11'],
    'An overdue open task must move to the current day and retain its duration.'
);
assertTaskAppointment(
    CalendarTaskEvent::rollForwardChanges(
        CalendarTaskEvent::enrich($completed) + [
            'allDay'    => true,
            'recurring' => false,
            'start'     => '2026-09-08',
            'end'       => '2026-09-09'
        ],
        new DateTimeImmutable('2026-09-10')
    ) === null,
    'Completed task appointments must never roll forward.'
);

$shiftedRecurrence = CalendarTaskEvent::shiftPlannedRecurrence(
    [
        'frequency' => 'WEEKLY',
        'interval'  => 1,
        'byDay'     => ['SA'],
        'endMode'   => 'until',
        'until'     => '2026-10-31'
    ],
    '2026-09-12',
    '2026-09-11'
);
assertTaskAppointment(
    ($shiftedRecurrence['byDay'] ?? []) === ['FR']
        && ($shiftedRecurrence['until'] ?? '') === '2026-10-30',
    'Moving a planned task-series tail must rebase its weekday and end date to the new occurrence date.'
);

$seriesEvents = [
    [
        'uid'            => 'task-series@example.com',
        'seriesId'       => 'task-series',
        'occurrenceId'   => 'task-first',
        'recurrenceType' => 'exception',
        'recurring'      => true,
        'summary'        => '☐↻ Serienaufgabe',
        'allDay'         => true,
        'originalStart'  => '2026-09-12',
        'start'          => '2026-09-10',
        'end'            => '2026-09-11',
        'startTimestamp' => 1788998400,
        'endTimestamp'   => 1789084800
    ],
    [
        'uid'            => 'task-series@example.com',
        'seriesId'       => 'task-series',
        'occurrenceId'   => 'task-second',
        'recurrenceType' => 'occurrence',
        'recurring'      => true,
        'summary'        => '☐↻ Serienaufgabe',
        'allDay'         => true,
        'originalStart'  => '2026-09-19',
        'start'          => '2026-09-19',
        'end'            => '2026-09-20',
        'startTimestamp' => 1789776000,
        'endTimestamp'   => 1789862400
    ],
    [
        'uid'            => 'other-series@example.com',
        'seriesId'       => 'other-series',
        'occurrenceId'   => 'other-first',
        'recurrenceType' => 'occurrence',
        'recurring'      => true,
        'originalStart'  => '2026-09-19',
        'start'          => '2026-09-19',
        'end'            => '2026-09-20'
    ]
];
$shiftedEvents = CalendarTaskEvent::shiftFollowingEvents(
    $seriesEvents,
    $seriesEvents[0],
    '2026-09-11'
);
assertTaskAppointment(
    $shiftedEvents[0]['start'] === '2026-09-10'
        && $shiftedEvents[1]['start'] === '2026-09-18'
        && $shiftedEvents[1]['end'] === '2026-09-19'
        && $shiftedEvents[1]['startTimestamp'] === 1789689600
        && $shiftedEvents[2]['start'] === '2026-09-19',
    'The synchronization cache must optimistically move only later occurrences of the affected task series.'
);

$cachedShiftedEvents = $shiftedEvents;
$cachedShiftedEvents[0]['start'] = '2026-09-11';
$cachedShiftedEvents[0]['end'] = '2026-09-12';
$preservedEvents = CalendarTaskEvent::preservePendingSeries(
    $seriesEvents,
    $cachedShiftedEvents,
    new DateTimeImmutable('2026-09-11')
);
assertTaskAppointment(
    $preservedEvents[0]['start'] === '2026-09-11'
        && $preservedEvents[1]['start'] === '2026-09-18'
        && $preservedEvents[2]['start'] === '2026-09-19',
    'A delayed provider response must not overwrite or repeat a task-series shift already completed today.'
);

foreach ([
    ['allDay' => false, 'recurrence' => null],
    ['allDay' => true, 'recurrence' => null, 'start' => '2026-09-08', 'end' => '2026-09-10']
] as $invalidShape) {
    try {
        CalendarTaskEvent::prepareWrite([
            'summary' => 'Ungültige Aufgabe',
            'task'    => true,
            ...$invalidShape
        ]);
        throw new RuntimeException('Invalid task appointment shapes must be rejected.');
    } catch (InvalidArgumentException) {
    }
}

$recurringTask = CalendarTaskEvent::prepareWrite([
    'summary'       => 'Kühlschrank reinigen',
    'task'          => true,
    'allDay'        => true,
    'start'         => '2026-09-08',
    'end'           => '2026-09-09',
    'recurrence'    => ['frequency' => 'DAILY']
]);
assertTaskAppointment(
    $recurringTask['summary'] === '☐ Kühlschrank reinigen',
    'One-day all-day task series must be accepted.'
);

fwrite(STDOUT, "Task appointment tests passed.\n");
