<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

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
        $dueDate = self::date($due);
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
            'reminder'               => [
                'mode'     => (bool) ($task['reminder'] ?? false) ? 'complex' : 'none',
                'editable' => false
            ],
            'taskNativeRecurrence'   => is_array($task['recurrence'] ?? null) ? $task['recurrence'] : null,
            'etag'                   => trim((string) ($task['etag'] ?? '')),
            'lastModified'           => trim((string) ($task['lastModified'] ?? '')),
            'canWrite'               => true,
            'canUpdateOccurrence'    => true,
            'canDeleteOccurrence'    => true,
            'canUpdateFollowing'     => false,
            'canUpdateSeries'        => false,
            'canDeleteSeries'        => false,
            'recurrenceEditable'     => false
        ];
    }

    /** @param array<string, mixed> $value */
    private static function date(array $value): ?DateTimeImmutable
    {
        $rawDateTime = trim((string) ($value['dateTime'] ?? ''));
        if ($rawDateTime === '') {
            return null;
        }

        try {
            $rawDateTime = preg_replace(
                '/(\.\d{6})\d+(?=(?:Z|[+-]\d{2}:\d{2})?$)/',
                '$1',
                $rawDateTime
            ) ?? $rawDateTime;
            $sourceTimezone = self::timezone(trim((string) ($value['timeZone'] ?? 'UTC')));
            $displayTimezone = new DateTimeZone(date_default_timezone_get());
            $sourceDate = new DateTimeImmutable($rawDateTime, $sourceTimezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }
            $dateValue = $sourceDate
                ->setTimezone($displayTimezone)
                ->format('Y-m-d');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, $displayTimezone);
            return $date !== false && $date->format('Y-m-d') === $dateValue ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (Throwable) {
            $ianaName = match ($name) {
                'GMT Standard Time'              => 'Europe/London',
                'W. Europe Standard Time'        => 'Europe/Berlin',
                'Central Europe Standard Time'   => 'Europe/Budapest',
                'Romance Standard Time'          => 'Europe/Paris',
                'Central European Standard Time' => 'Europe/Warsaw',
                'GTB Standard Time'              => 'Europe/Bucharest',
                'FLE Standard Time'              => 'Europe/Kyiv',
                'Turkey Standard Time'           => 'Europe/Istanbul',
                'Russian Standard Time'          => 'Europe/Moscow',
                'Eastern Standard Time'          => 'America/New_York',
                'Central Standard Time'          => 'America/Chicago',
                'Mountain Standard Time'         => 'America/Denver',
                'Pacific Standard Time'          => 'America/Los_Angeles',
                'Tokyo Standard Time'            => 'Asia/Tokyo',
                'China Standard Time'            => 'Asia/Shanghai',
                'India Standard Time'            => 'Asia/Kolkata',
                'AUS Eastern Standard Time'      => 'Australia/Sydney',
                'New Zealand Standard Time'      => 'Pacific/Auckland',
                default                          => 'UTC'
            };
            return new DateTimeZone($ianaName);
        }
    }
}
