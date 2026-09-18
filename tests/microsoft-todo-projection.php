<?php

declare(strict_types=1);

use IPSKalender\MicrosoftTodoTaskProjection;

require_once __DIR__ . '/../libs/MicrosoftTodoTaskProjection.php';
require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender/module.php';

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('Europe/Berlin');

function assertTodoProjection(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tasks = [
    [
        'id'            => 'open-overdue',
        'listId'        => 'list-1',
        'title'         => 'Submit report',
        'description'   => 'Keep the native description.',
        'status'        => 'inProgress',
        'importance'    => 'high',
        'categories'    => ['Finance'],
        'dueDateTime'   => ['dateTime' => '2026-09-10T00:00:00.0000000', 'timeZone' => 'W. Europe Standard Time'],
        'startDateTime' => ['dateTime' => '2026-09-08T00:00:00.0000000', 'timeZone' => 'W. Europe Standard Time'],
        'recurrence'    => ['pattern' => ['type' => 'weekly'], 'range' => ['type' => 'noEnd']],
        'deleted'       => false
    ],
    [
        'id'            => 'completed',
        'listId'        => 'list-1',
        'title'         => 'Completed task',
        'status'        => 'completed',
        'dueDateTime'   => ['dateTime' => '2026-09-16T22:00:00.0000000', 'timeZone' => 'UTC'],
        'deleted'       => false
    ],
    ['id' => 'without-due', 'title' => 'Backlog', 'status' => 'notStarted', 'dueDateTime' => null, 'deleted' => false],
    ['id' => 'deleted', 'dueDateTime' => ['dateTime' => '2026-09-16T00:00:00', 'timeZone' => 'UTC'], 'deleted' => true],
    ['id' => 'invalid-date', 'dueDateTime' => ['dateTime' => '2026-02-30T00:00:00', 'timeZone' => 'UTC'], 'deleted' => false]
];

$events = MicrosoftTodoTaskProjection::project($tasks);
assertTodoProjection(count($events) === 2, 'Only active Microsoft tasks with a valid due date may be projected.');
assertTodoProjection(
    $events[0]['start'] === '2026-09-10'
        && $events[0]['end'] === '2026-09-11'
        && $events[0]['taskCompleted'] === false
        && $events[0]['microsoftTaskStatus'] === 'inProgress'
        && $events[0]['taskRollForwardScope'] === 'disabled'
        && $events[0]['sourceType'] === 'microsoft-todo'
        && $events[0]['canWrite'] === true
        && $events[0]['canUpdateOccurrence'] === true
        && $events[0]['canDeleteOccurrence'] === true
        && $events[0]['recurrenceEditable'] === false,
    'Open and overdue Microsoft tasks must stay on their original due date and expose only supported write capabilities.'
);
assertTodoProjection(
    $events[0]['taskNativeRecurrence']['pattern']['type'] === 'weekly'
        && $events[0]['timezone'] === 'W. Europe Standard Time'
        && $events[1]['start'] === '2026-09-17'
        && $events[1]['end'] === '2026-09-18'
        && $events[1]['taskCompleted'] === true,
    'The projection must retain native metadata and convert UTC instants to the local due date.'
);

final class MicrosoftTodoProjectionCalendarHarness extends Calendar
{
    /** @var array<string, string> */
    public array $attributeValues;

    /** @var array<string, string> */
    private array $buffers = [];

    /** @param list<array<string, mixed>> $events @param list<array<string, mixed>> $tasks */
    public function __construct(array $events, array $tasks)
    {
        parent::__construct(9021);
        $this->attributeValues = [
            'CachedEvents'         => json_encode($events, JSON_THROW_ON_ERROR),
            'CachedMicrosoftTasks' => json_encode($tasks, JSON_THROW_ON_ERROR)
        ];
    }

    protected function ReadAttributeString(string $Name): string
    {
        return $this->attributeValues[$Name] ?? '';
    }

    protected function GetBuffer(string $Name): string
    {
        return $this->buffers[$Name] ?? '';
    }

    protected function SetBuffer(string $Name, string $Value): bool
    {
        $this->buffers[$Name] = $Value;
        return true;
    }
}

$calendarEvent = [
    'uid'            => 'calendar-event',
    'summary'        => 'Calendar event',
    'startTimestamp' => (new DateTimeImmutable('2026-09-15T10:00:00Z'))->getTimestamp(),
    'endTimestamp'   => (new DateTimeImmutable('2026-09-15T11:00:00Z'))->getTimestamp(),
    'allDay'         => false
];
$calendar = new MicrosoftTodoProjectionCalendarHarness([$calendarEvent], $tasks);
$rangeStart = (new DateTimeImmutable('2026-09-09T00:00:00Z'))->getTimestamp();
$rangeEnd = (new DateTimeImmutable('2026-09-18T00:00:00Z'))->getTimestamp();
$metadata = json_decode($calendar->BeginEventsTransfer($rangeStart, $rangeEnd), true, 512, JSON_THROW_ON_ERROR);
$page = json_decode($calendar->ReadEventsTransferPage($metadata['Token'], 0), true, 512, JSON_THROW_ON_ERROR);
assertTodoProjection(
    $metadata['ItemCount'] === 3
        && array_column($page['Items'], 'uid') === ['calendar-event', 'microsoft-todo:open-overdue', 'microsoft-todo:completed'],
    'Calendar-view transfers must combine cached calendar events with due Microsoft task projections.'
);
assertTodoProjection(
    array_column(json_decode($calendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR), 'uid') === ['calendar-event'],
    'The provider event API must remain separate from virtual Microsoft task projections.'
);
$calendar->FinishEventsTransfer($metadata['Token']);
date_default_timezone_set($previousTimezone);

fwrite(STDOUT, "Microsoft To Do task projection tests passed.\n");
