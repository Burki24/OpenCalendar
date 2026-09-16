<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use DateTimeZone;

require_once __DIR__ . '/CalendarEventRecurrence.php';
require_once __DIR__ . '/CalendarTaskEvent.php';

/**
 * Projects native Microsoft To Do tasks into read-only calendar-view entries.
 */
final class MicrosoftTodoTaskProjection
{
    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    public static function project(array $tasks): array
    {
        $events = [];
        foreach ($tasks as $task) {
            $event = self::projectTask($task);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>|null
     */
    private static function projectTask(array $task): ?array
    {
        $id = trim((string) ($task['id'] ?? ''));
        $due = is_array($task['dueDateTime'] ?? null) ? $task['dueDateTime'] : [];
        $dueDate = self::date(trim((string) ($due['dateTime'] ?? '')));
        if ($id === '' || $dueDate === null || (bool) ($task['deleted'] ?? false)) {
            return null;
        }

        $start = $dueDate->format('Y-m-d');
        $end = $dueDate->modify('+1 day')->format('Y-m-d');
        $status = trim((string) ($task['status'] ?? 'notStarted'));
        $completed = strcasecmp($status, 'completed') === 0;
        $title = trim((string) ($task['title'] ?? ''));
        $listId = trim((string) ($task['listId'] ?? ''));

        return [
            'uid'                    => 'microsoft-todo:' . $id,
            'eventReference'         => 'microsoft-todo:' . $id,
            'summary'                => $title,
            'displaySummary'         => $title,
            'description'            => (string) ($task['description'] ?? ''),
            'allDay'                 => true,
            'start'                  => $start,
            'end'                    => $end,
            'startTimestamp'         => $dueDate->getTimestamp(),
            'endTimestamp'           => $dueDate->modify('+1 day')->getTimestamp(),
            'timezone'               => trim((string) ($due['timeZone'] ?? 'UTC')) ?: 'UTC',
            'recurring'              => false,
            'recurrenceType'         => CalendarEventRecurrence::SINGLE,
            'task'                   => true,
            'taskCompleted'          => $completed,
            'taskStatus'             => $completed ? 'completed' : 'open',
            'taskRollForwardScope'   => CalendarTaskEvent::ROLL_FORWARD_SCOPE_DISABLED,
            'taskFollowPlanned'      => false,
            'taskRolledForward'      => false,
            'sourceType'             => 'microsoft-todo',
            'taskProvider'           => 'microsoft-todo',
            'taskId'                 => $id,
            'taskListId'             => $listId,
            'microsoftTaskStatus'    => $status,
            'taskImportance'         => trim((string) ($task['importance'] ?? 'normal')),
            'categories'             => array_values(array_map(
                'strval',
                is_array($task['categories'] ?? null) ? $task['categories'] : []
            )),
            'taskDueDateTime'        => $due,
            'taskStartDateTime'      => is_array($task['startDateTime'] ?? null) ? $task['startDateTime'] : null,
            'taskCompletedDateTime'  => is_array($task['completedDateTime'] ?? null) ? $task['completedDateTime'] : null,
            'taskReminderDateTime'   => is_array($task['reminderDateTime'] ?? null) ? $task['reminderDateTime'] : null,
            'taskReminder'           => (bool) ($task['reminder'] ?? false),
            'taskNativeRecurrence'   => is_array($task['recurrence'] ?? null) ? $task['recurrence'] : null,
            'etag'                   => trim((string) ($task['etag'] ?? '')),
            'lastModified'           => trim((string) ($task['lastModified'] ?? '')),
            'canWrite'               => false,
            'canUpdateOccurrence'    => false,
            'canDeleteOccurrence'    => false,
            'canUpdateFollowing'     => false,
            'canUpdateSeries'        => false,
            'canDeleteSeries'        => false,
            'readOnlyReason'         => 'Microsoft To Do task editing is not available yet.'
        ];
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $dateValue = substr($value, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateValue) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $dateValue ? $date : null;
    }
}
