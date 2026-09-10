<?php

declare(strict_types=1);

use IPSKalender\CalendarTaskEvent;

require_once dirname(__DIR__) . '/libs/CalendarTaskEvent.php';

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

foreach ([
    ['allDay' => false, 'recurrence' => null],
    ['allDay' => true, 'recurrence' => ['frequency' => 'DAILY']],
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

fwrite(STDOUT, "Task appointment tests passed.\n");
