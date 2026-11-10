<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender/module.php';

date_default_timezone_set('Europe/Berlin');

final class MicrosoftTodoCalendarWriteHarness extends Calendar
{
    /** @var array<string, string|bool|int> */
    public array $attributeValues;

    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $responses = [];

    /** @param array<string, mixed> $task */
    public function __construct(array $task)
    {
        parent::__construct(9022);
        $this->attributeValues = [
            'RuntimeReady'            => true,
            'CachedMicrosoftTasks'    => json_encode([$task], JSON_THROW_ON_ERROR),
            'MicrosoftTaskLastError'  => '',
            'LastError'               => '',
            'LastSynchronization'     => 123,
            'MicrosoftTaskDeltaLink'  => 'https://graph.microsoft.com/delta',
            'MicrosoftTaskSyncListID' => 'list-1'
        ];
    }

    protected function ReadPropertyBoolean(string $Name): bool
    {
        return $Name === 'LocalCalendar' ? false : true;
    }

    protected function ReadPropertyString(string $Name): string
    {
        return match ($Name) {
            'MicrosoftTaskListID' => 'list-1',
            'CalendarID'          => 'calendar-1',
            default               => ''
        };
    }

    protected function ReadAttributeBoolean(string $Name): bool
    {
        return (bool) ($this->attributeValues[$Name] ?? false);
    }

    protected function ReadAttributeInteger(string $Name): int
    {
        return (int) ($this->attributeValues[$Name] ?? 0);
    }

    protected function ReadAttributeString(string $Name): string
    {
        return (string) ($this->attributeValues[$Name] ?? '');
    }

    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        $this->attributeValues[$Name] = $Value;
        return true;
    }

    protected function HasActiveParent(): bool
    {
        return true;
    }

    protected function SendDataToParent(string $Data): string
    {
        $request = json_decode($Data, true, 512, JSON_THROW_ON_ERROR);
        $this->requests[] = $request;
        $response = array_shift($this->responses)
            ?? throw new RuntimeException('Unexpected Microsoft task parent request.');
        return json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    protected function SetStatus(int $Status): bool
    {
        return true;
    }

    protected function SetValue(string $Ident, mixed $Value): bool
    {
        return true;
    }
}

function assertTodoWrite(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function todoWriteTask(string $status = 'notStarted', string $title = 'Native task', string $due = '2026-09-20T17:30:00.0000000'): array
{
    return [
        'id'                => 'task-1',
        'listId'            => 'list-1',
        'title'             => $title,
        'description'       => 'Description',
        'status'            => $status,
        'importance'        => 'normal',
        'categories'        => [],
        'startDateTime'     => null,
        'dueDateTime'       => ['dateTime' => $due, 'timeZone' => 'W. Europe Standard Time'],
        'completedDateTime' => $status === 'completed'
            ? ['dateTime' => '2026-09-16T12:00:00', 'timeZone' => 'UTC']
            : null,
        'reminderDateTime'  => ['dateTime' => '2026-09-20T08:00:00', 'timeZone' => 'W. Europe Standard Time'],
        'reminder'          => true,
        'recurrence'        => ['pattern' => ['type' => 'weekly'], 'range' => ['type' => 'noEnd']],
        'created'           => '2026-09-01T08:00:00Z',
        'lastModified'      => '2026-09-16T08:00:00Z',
        'etag'              => 'etag-1',
        'deleted'           => false
    ];
}

/** @return array<string, mixed> */
function todoWriteIdentity(string $listId = 'list-1'): array
{
    return [
        'sourceType'     => 'microsoft-todo',
        'taskProvider'   => 'microsoft-todo',
        'taskId'         => 'task-1',
        'taskListId'     => $listId,
        'uid'            => 'microsoft-todo:task-1',
        'startTimestamp' => (new DateTimeImmutable('2026-09-20T00:00:00Z'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-09-21T00:00:00Z'))->getTimestamp()
    ];
}

function todoWriteSuccess(array $payload): array
{
    return ['Success' => true, 'Payload' => $payload];
}

$createdNativeTask = todoWriteTask('notStarted', 'Native created task', '2026-09-25T00:00:00');
$createdNativeTask['id'] = 'task-created';
$createdNativeTask['description'] = 'Created in OpenCalendar';
$createdNativeTask['dueDateTime']['timeZone'] = 'Europe/Berlin';
$createdNativeTask['recurrence'] = [
    'pattern' => [
        'type'           => 'weekly',
        'interval'       => 2,
        'daysOfWeek'     => ['friday'],
        'firstDayOfWeek' => 'monday'
    ],
    'range' => [
        'type'                => 'numbered',
        'startDate'           => '2026-09-25',
        'recurrenceTimeZone'  => 'Europe/Berlin',
        'numberOfOccurrences' => 4
    ]
];
$creationCalendar = new MicrosoftTodoCalendarWriteHarness(todoWriteTask());
$creationCalendar->responses[] = todoWriteSuccess($createdNativeTask);
$creationResult = json_decode($creationCalendar->CreateEvent(json_encode([
    'summary'       => 'Native created task',
    'description'   => 'Created in OpenCalendar',
    'task'          => true,
    'taskCompleted' => false,
    'allDay'        => true,
    'start'         => '2026-09-25',
    'end'           => '2026-09-26',
    'timezone'      => 'Europe/Berlin',
    'recurrence'    => [
        'frequency'  => 'WEEKLY',
        'interval'   => 2,
        'byDay'      => ['FR'],
        'weekStart'  => 'MO',
        'endMode'    => 'count',
        'count'      => 4
    ]
], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
$creationRequest = $creationCalendar->requests[0] ?? [];
assertTodoWrite(
    $creationResult['success'] === true
        && ($creationResult['event']['sourceType'] ?? '') === 'microsoft-todo'
        && ($creationResult['event']['taskId'] ?? '') === 'task-created'
        && ($creationRequest['Operation'] ?? '') === 'CreateTask'
        && ($creationRequest['TaskListID'] ?? '') === 'list-1'
        && ($creationRequest['Task']['title'] ?? '') === 'Native created task'
        && ($creationRequest['Task']['description'] ?? '') === 'Created in OpenCalendar'
        && ($creationRequest['Task']['status'] ?? '') === 'notStarted'
        && ($creationRequest['Task']['dueDateTime'] ?? null) === [
            'dateTime' => '2026-09-25T00:00:00',
            'timeZone' => 'Europe/Berlin'
        ]
        && ($creationRequest['Task']['recurrence'] ?? null) === $createdNativeTask['recurrence']
        && count(json_decode($creationCalendar->GetMicrosoftTasks(), true, 512, JSON_THROW_ON_ERROR)) === 2,
    'Creating a task in a calendar with a selected Microsoft To Do list must create and cache a native task.'
);

$ordinaryCalendar = new MicrosoftTodoCalendarWriteHarness(todoWriteTask());
$ordinaryCalendar->responses[] = todoWriteSuccess([
    'uid'            => 'calendar-event',
    'eventReference' => 'calendar-event',
    'summary'        => 'Ordinary appointment',
    'allDay'         => true,
    'start'          => '2026-09-25',
    'end'            => '2026-09-26'
]);
$ordinaryResult = json_decode($ordinaryCalendar->CreateEvent(json_encode([
    'summary'       => 'Ordinary appointment',
    'task'          => false,
    'taskCompleted' => false,
    'allDay'        => true,
    'start'         => '2026-09-25',
    'end'           => '2026-09-26'
], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
assertTodoWrite(
    $ordinaryResult['success'] === true
        && ($ordinaryCalendar->requests[0]['Operation'] ?? '') === 'CreateEvent',
    'A selected Microsoft To Do list must not redirect ordinary calendar appointments.'
);

$calendar = new MicrosoftTodoCalendarWriteHarness(todoWriteTask());
$editable = json_decode(
    $calendar->GetEventForEdit(json_encode(todoWriteIdentity(), JSON_THROW_ON_ERROR)),
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertTodoWrite(
    $editable['taskId'] === 'task-1'
        && $editable['canWrite'] === true
        && $editable['recurrenceEditable'] === false
        && $calendar->requests === [],
    'Preparing a Microsoft task edit must use the current native cache without requesting a calendar event.'
);

$calendar->responses[] = todoWriteSuccess(todoWriteTask('completed'));
$completedResult = json_decode($calendar->UpdateEvent(json_encode(array_merge(todoWriteIdentity(), [
    'changes' => ['task' => true, 'taskCompleted' => true]
]), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
assertTodoWrite(
    $completedResult['success'] === true
        && $calendar->requests[0]['Operation'] === 'UpdateTask'
        && $calendar->requests[0]['TaskListID'] === 'list-1'
        && $calendar->requests[0]['TaskID'] === 'task-1'
        && $calendar->requests[0]['Changes'] === ['status' => 'completed'],
    'Completing a virtual task must update the native Microsoft To Do status.'
);

$calendar->responses[] = todoWriteSuccess(todoWriteTask('completed', 'Renamed', '2026-09-22T17:30:00.0000000'));
$editedResult = json_decode($calendar->UpdateEvent(json_encode(array_merge(todoWriteIdentity(), [
    'changes' => [
        'summary'       => 'Renamed',
        'description'   => 'Changed description',
        'task'          => true,
        'taskCompleted' => true,
        'start'         => '2026-09-22',
        'end'           => '2026-09-23'
    ]
]), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
assertTodoWrite(
    $editedResult['success'] === true
        && $calendar->requests[1]['Changes']['title'] === 'Renamed'
        && $calendar->requests[1]['Changes']['description'] === 'Changed description'
        && $calendar->requests[1]['Changes']['dueDateTime'] === [
            'dateTime' => '2026-09-22T17:30:00',
            'timeZone' => 'Europe/Berlin'
        ]
        && !isset($calendar->requests[1]['Changes']['status']),
    'Editing a native task must preserve its due time and timezone without rewriting an unchanged completion state.'
);

$calendar->responses[] = todoWriteSuccess(todoWriteTask('notStarted', 'Renamed', '2026-09-22T17:30:00.0000000'));
$reopenedResult = json_decode($calendar->UpdateEvent(json_encode(array_merge(todoWriteIdentity(), [
    'changes' => ['task' => true, 'taskCompleted' => false]
]), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
assertTodoWrite(
    $reopenedResult['success'] === true
        && $calendar->requests[2]['Changes'] === ['status' => 'notStarted'],
    'Reopening a completed task must restore the native Microsoft To Do status.'
);

$forged = json_decode($calendar->UpdateEvent(json_encode(array_merge(todoWriteIdentity('other-list'), [
    'changes' => ['task' => true, 'taskCompleted' => true]
]), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
assertTodoWrite(
    $forged['success'] === false && count($calendar->requests) === 3,
    'A task identity outside the configured list must be rejected before reaching the account gateway.'
);

$calendar->responses[] = todoWriteSuccess(['success' => true]);
assertTodoWrite(
    $calendar->DeleteEvent(json_encode(todoWriteIdentity(), JSON_THROW_ON_ERROR)) === true
        && $calendar->requests[3]['Operation'] === 'DeleteTask'
        && json_decode($calendar->GetMicrosoftTasks(), true, 512, JSON_THROW_ON_ERROR) === [],
    'Deleting a virtual task must delete the native Microsoft task and remove it from the local cache.'
);

$utcTask = todoWriteTask();
$utcTask['dueDateTime'] = ['dateTime' => '2026-09-21T22:00:00.0000000', 'timeZone' => 'UTC'];
$unchangedCalendar = new MicrosoftTodoCalendarWriteHarness($utcTask);
$unchangedCalendar->responses[] = todoWriteSuccess($utcTask);
$unchangedResult = json_decode($unchangedCalendar->UpdateEvent(json_encode(array_merge(todoWriteIdentity(), [
    'changes' => ['summary' => 'Title only', 'start' => '2026-09-22', 'taskCompleted' => false]
]), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
assertTodoWrite(
    $unchangedResult['success'] === true
    && $unchangedCalendar->requests[0]['Changes'] === ['title' => 'Title only'],
    'An unchanged displayed date must never be forwarded, even if the server may have advanced the series.'
);

$dstCalendar = new MicrosoftTodoCalendarWriteHarness($utcTask);
$dstCalendar->responses[] = todoWriteSuccess($utcTask);
$dstResult = json_decode($dstCalendar->UpdateEvent(json_encode(array_merge(todoWriteIdentity(), [
    'changes' => ['start' => '2026-10-26']
]), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
assertTodoWrite($dstResult['success'] === true
    && $dstCalendar->requests[0]['Changes']['dueDateTime'] === [
        'dateTime' => '2026-10-26T00:00:00', 'timeZone' => 'Europe/Berlin'
    ], 'Moving a date across DST must preserve local midnight, not UTC 22:00 on the requested day.');

fwrite(STDOUT, "Microsoft To Do calendar write tests passed.\n");
