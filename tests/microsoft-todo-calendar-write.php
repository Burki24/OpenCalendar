<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender/module.php';

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
            'dateTime' => '2026-09-22T17:30:00.0000000',
            'timeZone' => 'W. Europe Standard Time'
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

fwrite(STDOUT, "Microsoft To Do calendar write tests passed.\n");
