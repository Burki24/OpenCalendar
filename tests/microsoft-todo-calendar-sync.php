<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender/module.php';

final class MicrosoftTodoCalendarSyncHarness extends Calendar
{
    /** @var array<string, string|bool> */
    public array $attributeValues = [
        'RuntimeReady'            => true,
        'CachedMicrosoftTasks'    => '[]',
        'MicrosoftTaskDeltaLink'  => '',
        'MicrosoftTaskSyncListID' => '',
        'MicrosoftTaskLastError'  => ''
    ];

    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $responses = [];

    public string $taskListId = 'list-1';

    protected function ReadPropertyBoolean(string $Name): bool
    {
        return $Name === 'LocalCalendar' ? false : true;
    }

    protected function ReadPropertyString(string $Name): string
    {
        return match ($Name) {
            'MicrosoftTaskListID' => $this->taskListId,
            'CalendarID'          => 'calendar-1',
            default               => ''
        };
    }

    protected function ReadAttributeBoolean(string $Name): bool
    {
        return (bool) ($this->attributeValues[$Name] ?? false);
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
            ?? throw new RuntimeException('Unexpected parent request.');
        return json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    protected function SendSafeDebugException(
        string $message,
        Throwable $exception,
        int $maxLength = 16_384,
        array $additionalSensitiveKeys = []
    ): void {
    }
}

function assertTodoCalendarSync(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function todoCalendarSuccess(array $payload): array
{
    return ['Success' => true, 'Payload' => $payload];
}

$calendar = new MicrosoftTodoCalendarSyncHarness(9020);
$synchronize = new ReflectionMethod(Calendar::class, 'synchronizeMicrosoftTasksSafely');

$calendar->responses[] = todoCalendarSuccess([
    'tasks' => [
        ['id' => 'task-1', 'title' => 'First', 'status' => 'notStarted', 'deleted' => false],
        ['id' => 'task-2', 'title' => 'Second', 'status' => 'notStarted', 'deleted' => false]
    ],
    'deltaLink' => 'https://graph.microsoft.com/v1.0/me/todo/lists/list-1/tasks/delta?$deltatoken=one'
]);
assertTodoCalendarSync($synchronize->invoke($calendar) === 2, 'Initial task synchronization must cache every active task.');
$tasks = json_decode($calendar->GetMicrosoftTasks(), true, 512, JSON_THROW_ON_ERROR);
assertTodoCalendarSync(
    array_column($tasks, 'id') === ['task-1', 'task-2']
        && $calendar->attributeValues['MicrosoftTaskSyncListID'] === 'list-1',
    'Initial task synchronization must persist tasks and their list identity.'
);

$calendar->responses[] = todoCalendarSuccess([
    'tasks' => [
        ['id' => 'task-1', 'title' => 'First', 'status' => 'completed', 'deleted' => false],
        ['id' => 'task-2', 'listId' => 'list-1', 'deleted' => true],
        ['id' => 'task-3', 'title' => 'Third', 'status' => 'notStarted', 'deleted' => false]
    ],
    'deltaLink' => 'https://graph.microsoft.com/v1.0/me/todo/lists/list-1/tasks/delta?$deltatoken=two'
]);
assertTodoCalendarSync($synchronize->invoke($calendar) === 2, 'Delta synchronization must update, remove, and add tasks.');
$tasks = json_decode($calendar->GetMicrosoftTasks(), true, 512, JSON_THROW_ON_ERROR);
assertTodoCalendarSync(
    array_column($tasks, 'id') === ['task-1', 'task-3']
        && $tasks[0]['status'] === 'completed'
        && ($calendar->requests[1]['DeltaLink'] ?? '') === 'https://graph.microsoft.com/v1.0/me/todo/lists/list-1/tasks/delta?$deltatoken=one',
    'Incremental task synchronization must use the saved cursor and apply Graph tombstones.'
);

$cachedBeforeFailure = $calendar->attributeValues['CachedMicrosoftTasks'];
$deltaBeforeFailure = $calendar->attributeValues['MicrosoftTaskDeltaLink'];
$calendar->responses[] = ['Success' => false, 'Error' => 'Tasks.ReadWrite consent is missing.'];
assertTodoCalendarSync(
    $synchronize->invoke($calendar) === 2
        && $calendar->attributeValues['CachedMicrosoftTasks'] === $cachedBeforeFailure
        && $calendar->attributeValues['MicrosoftTaskDeltaLink'] === $deltaBeforeFailure
        && $calendar->attributeValues['MicrosoftTaskLastError'] === 'Tasks.ReadWrite consent is missing.',
    'A task authorization failure must preserve cached tasks and the last valid delta cursor.'
);

$calendar->taskListId = 'list-2';
$calendar->responses[] = todoCalendarSuccess([
    'tasks'     => [['id' => 'other-1', 'title' => 'Other list', 'deleted' => false]],
    'deltaLink' => 'https://graph.microsoft.com/v1.0/me/todo/lists/list-2/tasks/delta?$deltatoken=first'
]);
assertTodoCalendarSync($synchronize->invoke($calendar) === 1, 'Changing the task list must perform a new full synchronization.');
$tasks = json_decode($calendar->GetMicrosoftTasks(), true, 512, JSON_THROW_ON_ERROR);
assertTodoCalendarSync(
    array_column($tasks, 'id') === ['other-1']
        && ($calendar->requests[3]['DeltaLink'] ?? null) === '',
    'A task-list change must not reuse the previous list delta cursor or cache.'
);

$calendar->taskListId = '';
assertTodoCalendarSync(
    $synchronize->invoke($calendar) === 0
        && json_decode($calendar->GetMicrosoftTasks(), true, 512, JSON_THROW_ON_ERROR) === []
        && $calendar->attributeValues['MicrosoftTaskDeltaLink'] === '',
    'Disabling Microsoft To Do must clear its disposable cache and synchronization state.'
);

$calendar->taskListId = 'configured-list';
$calendar->responses[] = todoCalendarSuccess([
    ['id' => 'default-list', 'name' => 'Tasks'],
    ['id' => 'work-list', 'name' => 'Work']
]);
$optionsMethod = new ReflectionMethod(Calendar::class, 'microsoftTaskListOptions');
$options = $optionsMethod->invoke($calendar);
assertTodoCalendarSync(
    array_column($options, 'value') === ['', 'default-list', 'work-list', 'configured-list'],
    'Task-list selection must expose discovered lists and preserve a configured list that is temporarily unavailable.'
);

fwrite(STDOUT, "Microsoft To Do calendar synchronization tests passed.\n");
