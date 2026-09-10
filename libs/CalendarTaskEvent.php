<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Encodes lightweight task semantics in ordinary calendar events.
 */
final class CalendarTaskEvent
{
    public const OPEN_MARKER = '☐';
    public const COMPLETED_MARKER = '☑';

    /** @param array<string, mixed> $event */
    public static function enrich(array $event): array
    {
        $marker = self::marker((string) ($event['summary'] ?? ''));
        if ($marker === '') {
            if ((bool) ($event['task'] ?? false)) {
                unset($event['displaySummary']);
            }
            unset($event['task'], $event['taskCompleted'], $event['taskStatus']);
            return $event;
        }

        $completed = $marker === self::COMPLETED_MARKER;
        $event['task'] = true;
        $event['taskCompleted'] = $completed;
        $event['taskStatus'] = $completed ? 'completed' : 'open';
        $event['displaySummary'] = self::plainSummary((string) ($event['summary'] ?? ''));

        return $event;
    }

    /**
     * Converts visualization task fields to the persistent title marker.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $sourceEvent Existing event metadata for partial updates.
     * @return array<string, mixed>
     */
    public static function prepareWrite(array $event, array $sourceEvent = []): array
    {
        $taskWasSupplied = array_key_exists('task', $event)
            || array_key_exists('taskCompleted', $event)
            || array_key_exists('taskStatus', $event);
        $source = self::enrich($sourceEvent);
        $isTask = $taskWasSupplied
            ? (bool) ($event['task'] ?? true)
            : (bool) ($source['task'] ?? false);
        $completed = self::requestedCompletion($event, $source);

        if ($isTask) {
            self::assertTaskShape($event, $sourceEvent);
            $summary = array_key_exists('summary', $event)
                ? (string) $event['summary']
                : (string) ($sourceEvent['summary'] ?? '');
            $summary = self::plainSummary($summary);
            if ($summary === '') {
                throw new InvalidArgumentException('The task title is missing.');
            }
            $event['summary'] = ($completed ? self::COMPLETED_MARKER : self::OPEN_MARKER) . ' ' . $summary;
        } elseif ($taskWasSupplied && array_key_exists('summary', $event)) {
            $event['summary'] = self::plainSummary((string) $event['summary']);
        }

        unset($event['task'], $event['taskCompleted'], $event['taskStatus'], $event['displaySummary']);
        return $event;
    }

    /**
     * Returns the provider changes needed to move one overdue open task to today.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    public static function rollForwardChanges(array $event, DateTimeImmutable $today): ?array
    {
        $event = self::enrich($event);
        if (!(bool) ($event['task'] ?? false)
            || (bool) ($event['taskCompleted'] ?? false)
            || !(bool) ($event['allDay'] ?? false)
            || (bool) ($event['recurring'] ?? false)) {
            return null;
        }

        $startValue = trim((string) ($event['start'] ?? ''));
        $endValue = trim((string) ($event['end'] ?? ''));
        $start = self::date($startValue);
        if ($start === null || $start >= $today) {
            return null;
        }

        $end = self::date($endValue);
        $duration = $end !== null && $end > $start
            ? max(1, (int) $start->diff($end)->format('%a'))
            : 1;

        return [
            'allDay' => true,
            'start'  => $today->format('Y-m-d'),
            'end'    => $today->modify('+' . $duration . ' days')->format('Y-m-d')
        ];
    }

    /**
     * Removes an OpenCalendar task marker from an event title.
     */
    public static function plainSummary(string $summary): string
    {
        return trim((string) preg_replace('/^[☐☑]\s*/u', '', trim($summary)));
    }

    private static function marker(string $summary): string
    {
        $summary = ltrim($summary);
        foreach ([self::OPEN_MARKER, self::COMPLETED_MARKER] as $marker) {
            if (str_starts_with($summary, $marker)) {
                return $marker;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $source */
    private static function requestedCompletion(array $event, array $source): bool
    {
        if (array_key_exists('taskStatus', $event)) {
            return strtolower(trim((string) $event['taskStatus'])) === 'completed';
        }
        if (array_key_exists('taskCompleted', $event)) {
            return (bool) $event['taskCompleted'];
        }

        return (bool) ($source['taskCompleted'] ?? false);
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $source */
    private static function assertTaskShape(array $event, array $source): void
    {
        $allDay = array_key_exists('allDay', $event)
            ? (bool) $event['allDay']
            : (bool) ($source['allDay'] ?? false);
        $recurrence = array_key_exists('recurrence', $event)
            ? $event['recurrence']
            : ($source['recurrence'] ?? null);
        $recurring = (bool) ($source['recurring'] ?? false)
            || (is_array($recurrence) && $recurrence !== []);
        $start = self::date((string) ($event['start'] ?? $source['start'] ?? ''));
        $end = self::date((string) ($event['end'] ?? $source['end'] ?? ''));
        $oneDay = $start === null || $end === null || $end == $start->modify('+1 day');
        if (!$allDay || $recurring || !$oneDay) {
            throw new InvalidArgumentException('Task appointments must be non-recurring one-day all-day events.');
        }
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
