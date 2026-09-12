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

final class TaskSeriesWriteCalendar extends Calendar
{
    public array $requests = [];
    public array $source = [];
    public bool $cacheAvailable = true;
    public bool $followingAvailable = true;

    protected function HasActiveParent(): bool
    {
        return true;
    }

    protected function ReadAttributeBoolean(string $Name): bool
    {
        return true;
    }

    protected function ReadAttributeString(string $Name): string
    {
        return $Name === 'CachedEvents' && $this->cacheAvailable ? json_encode([$this->source], JSON_THROW_ON_ERROR) : '[]';
    }

    protected function ReadPropertyString(string $Name): string
    {
        return 'calendar';
    }

    protected function ReadPropertyInteger(string $Name): int
    {
        return 30;
    }

    protected function SetStatus(int $Status): bool
    {
        return true;
    }

    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        return true;
    }

    protected function SendDataToParent(string $Data): string
    {
        $request = json_decode($Data, true, 512, JSON_THROW_ON_ERROR);
        $this->requests[] = $request;
        if ($request['Operation'] === 'GetRecurringFollowing' && !$this->followingAvailable) {
            return json_encode(['Success' => false, 'Error' => 'Series lookup unavailable.'], JSON_THROW_ON_ERROR);
        }
        $payload = match ($request['Operation']) {
            'GetRecurringFollowing' => array_merge($this->source, [
                'etag'               => 'fresh-etag',
                'writeScope'         => 'following',
                'recurrenceSettings' => [
                    'frequency' => 'DAILY', 'interval' => 10,
                    'endMode'   => 'until', 'until' => '2026-10-12'
                ]
            ]),
            'UpdateEvent' => ['uid' => 'updated'],
            default       => []
        };
        // Stop after the actual write: provider refresh is outside this regression.
        return json_encode(['Success' => true, 'Payload' => $payload], JSON_THROW_ON_ERROR);
    }
}

$manualCalendar = new TaskSeriesWriteCalendar(9013);
$manualCalendar->source = array_merge(
    IPSKalender\CalendarEventRecurrence::occurrence('series', 'first', '2026-09-12', '', true, false, true, true, true),
    ['uid'       => 'first', 'resourceUrl' => 'first', 'summary' => '[OC:TODO] Task',
        'allDay' => true, 'start' => '2026-09-12', 'end' => '2026-09-13']
);
foreach ([true, false] as $follow) {
    $manualCalendar->requests = [];
    $manualCalendar->UpdateEvent(json_encode(array_merge(
        array_intersect_key($manualCalendar->source, array_flip(['uid', 'resourceUrl', 'occurrenceId', 'seriesId', 'recurrenceType', 'writeScope'])),
        ['changes' => [
            'task'  => true, 'taskFollowPlanned' => $follow,
            'start' => '2026-09-13', 'end' => '2026-09-14'
        ]]
    ), JSON_THROW_ON_ERROR));
    $writes = array_values(array_filter($manualCalendar->requests, static fn (array $r): bool => $r['Operation'] === 'UpdateEvent'));
    assertTaskAppointment(count($writes) === 1, 'A manual task date change must issue one provider write.');
    assertTaskAppointment(
        $writes[0]['Recurrence']['writeScope'] === ($follow ? 'following' : 'occurrence'),
        'The checked follow-up option must route manual date changes to the series tail.'
    );
    if ($follow) {
        assertTaskAppointment(
            $writes[0]['Event']['recurrence']['interval'] === 10
                && $writes[0]['Event']['recurrence']['until'] === '2026-10-13'
                && $writes[0]['ETag'] === 'fresh-etag',
            'Manual series moves must retain the interval, shift the end date and use the verified ETag.'
        );
    }
}
$manualCalendar->requests = [];
$manualCalendar->source['summary'] = '[OC:TODO:FOLLOW] Task';
$manualCalendar->UpdateEvent(json_encode(array_merge($manualCalendar->source, ['changes' => [
    'task' => true, 'taskCompleted' => true
]]), JSON_THROW_ON_ERROR));
$writes = array_values(array_filter($manualCalendar->requests, static fn (array $r): bool => $r['Operation'] === 'UpdateEvent'));
assertTaskAppointment(
    count($writes) === 1 && $writes[0]['Recurrence']['writeScope'] === 'occurrence',
    'Completing a task with follow-up enabled must still affect only that occurrence.'
);

$manualCalendar->cacheAvailable = false;
foreach (['2026-09-10' => '2026-10-10', '2026-09-17' => '2026-10-17', '2026-09-12' => null] as $newDate => $expectedUntil) {
    $manualCalendar->requests = [];
    $identity = IPSKalender\CalendarEventRecurrence::fromEvent($manualCalendar->source);
    $manualCalendar->UpdateEvent(json_encode(array_merge($identity, [
        'uid'     => 'first', 'resourceUrl' => 'first',
        'changes' => [
            'summary' => 'Task', 'task' => true, 'taskFollowPlanned' => true,
            'allDay'  => true, 'start' => $newDate,
            'end'     => (new DateTimeImmutable($newDate))->modify('+1 day')->format('Y-m-d')
        ]
    ]), JSON_THROW_ON_ERROR));
    $writes = array_values(array_filter($manualCalendar->requests, static fn (array $r): bool => $r['Operation'] === 'UpdateEvent'));
    assertTaskAppointment(
        count($writes) === 1
            && $writes[0]['Recurrence']['writeScope'] === ($expectedUntil === null ? 'occurrence' : 'following')
            && ($writes[0]['Event']['recurrence']['until'] ?? null) === $expectedUntil,
        'Moving a task to the past or future must retain its series even without a cached source date.'
    );
}
$manualCalendar->requests = [];
$manualCalendar->followingAvailable = false;
$manualCalendar->UpdateEvent(json_encode(array_merge($manualCalendar->source, ['changes' => [
    'task' => true, 'taskFollowPlanned' => true, 'start' => '2026-09-17', 'end' => '2026-09-18'
]]), JSON_THROW_ON_ERROR));
assertTaskAppointment(
    array_filter($manualCalendar->requests, static fn (array $r): bool => $r['Operation'] === 'UpdateEvent') === [],
    'A failed series lookup must never silently fall back to writing a single occurrence.'
);

$created = CalendarTaskEvent::prepareWrite([
    'summary'       => 'Versicherung prüfen',
    'task'          => true,
    'taskCompleted' => false,
    'allDay'        => true,
    'start'         => '2026-09-08',
    'end'           => '2026-09-09'
]);
assertTaskAppointment(
    $created['summary'] === '[OC:TODO] Versicherung prüfen'
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
    $completed['summary'] === '[OC:DONE] Versicherung prüfen',
    'Completing a task must replace its open marker without changing the title.'
);

$followPlanned = CalendarTaskEvent::prepareWrite(
    ['task' => true, 'taskFollowPlanned' => true],
    $enriched + ['allDay' => true, 'recurring' => true, 'start' => '2026-09-08', 'end' => '2026-09-09']
);
assertTaskAppointment(
    $followPlanned['summary'] === '[OC:TODO:FOLLOW] Versicherung prüfen'
        && (CalendarTaskEvent::enrich($followPlanned)['taskFollowPlanned'] ?? false) === true,
    'A task series must persist the choice to move planned follow-up appointments.'
);

$renamed = CalendarTaskEvent::prepareWrite(
    ['summary' => 'Versicherung wechseln'],
    $enriched + ['allDay' => true, 'recurring' => false]
);
assertTaskAppointment(
    $renamed['summary'] === '[OC:TODO] Versicherung wechseln',
    'Renaming a task without explicit task fields must preserve its current task status.'
);

$reopened = CalendarTaskEvent::prepareWrite(
    ['task' => true, 'taskCompleted' => false],
    CalendarTaskEvent::enrich($completed + ['allDay' => true, 'recurring' => false])
);
assertTaskAppointment(
    $reopened['summary'] === '[OC:TODO] Versicherung prüfen',
    'Reopening a task must restore its open marker.'
);

$legacyTask = CalendarTaskEvent::enrich(['summary' => '☐ Bestehende Aufgabe']);
assertTaskAppointment(
    ($legacyTask['task'] ?? false) === true
        && ($legacyTask['displaySummary'] ?? '') === 'Bestehende Aufgabe',
    'Legacy symbol task markers must remain readable after the ASCII marker migration.'
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
assertTaskAppointment(
    CalendarTaskEvent::requiresOccurrenceDetachment($seriesEvents, $seriesEvents[0], '2026-09-20'),
    'A task occurrence must be detached when moving it would cross another planned occurrence.'
);
assertTaskAppointment(
    !CalendarTaskEvent::requiresOccurrenceDetachment($seriesEvents, $seriesEvents[0], '2026-09-18'),
    'A task occurrence must remain part of its series when its new date precedes the next planned occurrence.'
);
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
    $recurringTask['summary'] === '[OC:TODO] Kühlschrank reinigen',
    'One-day all-day task series must be accepted.'
);

fwrite(STDOUT, "Task appointment tests passed.\n");
