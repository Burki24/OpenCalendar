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
    public const OPEN_FOLLOW_MARKER = '☐↻';
    public const COMPLETED_FOLLOW_MARKER = '☑↻';

    private const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /** @param array<string, mixed> $event */
    public static function enrich(array $event): array
    {
        $marker = self::marker((string) ($event['summary'] ?? ''));
        if ($marker === '') {
            if ((bool) ($event['task'] ?? false)) {
                unset($event['displaySummary']);
            }
            unset($event['task'], $event['taskCompleted'], $event['taskStatus'], $event['taskFollowPlanned']);
            return $event;
        }

        $completed = in_array($marker, [self::COMPLETED_MARKER, self::COMPLETED_FOLLOW_MARKER], true);
        $event['task'] = true;
        $event['taskCompleted'] = $completed;
        $event['taskStatus'] = $completed ? 'completed' : 'open';
        $event['taskFollowPlanned'] = in_array($marker, [self::OPEN_FOLLOW_MARKER, self::COMPLETED_FOLLOW_MARKER], true);
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
            || array_key_exists('taskStatus', $event)
            || array_key_exists('taskFollowPlanned', $event);
        $source = self::enrich($sourceEvent);
        $isTask = $taskWasSupplied
            ? (bool) ($event['task'] ?? true)
            : (bool) ($source['task'] ?? false);
        $completed = self::requestedCompletion($event, $source);
        $followPlanned = self::requestedFollowPlanned($event, $source);

        if ($isTask) {
            self::assertTaskShape($event, $sourceEvent);
            $summary = array_key_exists('summary', $event)
                ? (string) $event['summary']
                : (string) ($sourceEvent['summary'] ?? '');
            $summary = self::plainSummary($summary);
            if ($summary === '') {
                throw new InvalidArgumentException('The task title is missing.');
            }
            $event['summary'] = self::markerFor($completed, $followPlanned) . ' ' . $summary;
        } elseif ($taskWasSupplied && array_key_exists('summary', $event)) {
            $event['summary'] = self::plainSummary((string) $event['summary']);
        }

        unset($event['task'], $event['taskCompleted'], $event['taskStatus'], $event['taskFollowPlanned'], $event['displaySummary']);
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
            || !(bool) ($event['allDay'] ?? false)) {
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
     * Reanchors recurrence settings when a planned task-series tail is moved.
     *
     * The offset is measured from the occurrence's immutable planned start. This
     * matters for provider exceptions which may already have been moved independently.
     *
     * @param array<string, mixed> $recurrence
     * @return array<string, mixed>
     */
    public static function shiftPlannedRecurrence(
        array $recurrence,
        string $originalStart,
        string $newStart
    ): array {
        $original = self::eventDate($originalStart);
        $target = self::eventDate($newStart);
        if ($original === null || $target === null) {
            throw new InvalidArgumentException('The task series contains an invalid occurrence date.');
        }

        $shift = (int) $original->diff($target)->format('%r%a');
        if ($shift === 0) {
            return $recurrence;
        }

        $frequency = strtoupper(trim((string) ($recurrence['frequency'] ?? '')));
        $patternMode = strtolower(trim((string) ($recurrence['patternMode'] ?? 'absolute')));
        if ($frequency === 'WEEKLY') {
            $recurrence['byDay'] = self::shiftWeekdays(
                $recurrence['byDay'] ?? [],
                $original,
                $shift
            );
        } elseif (in_array($frequency, ['MONTHLY', 'YEARLY'], true) && $patternMode === 'relative') {
            $weekdays = self::shiftWeekdays($recurrence['byDay'] ?? [], $original, $shift);
            $recurrence['byDay'] = $weekdays;
            if (count($weekdays) === 1) {
                $recurrence['relativeIndex'] = self::relativeIndex($target);
            }
            if ($frequency === 'YEARLY') {
                $recurrence['month'] = (int) $target->format('n');
            }
        } elseif ($frequency === 'MONTHLY') {
            $recurrence['dayOfMonth'] = (int) $target->format('j');
        } elseif ($frequency === 'YEARLY') {
            $recurrence['dayOfMonth'] = (int) $target->format('j');
            $recurrence['month'] = (int) $target->format('n');
        }

        if (array_key_exists('rangeStart', $recurrence)) {
            $recurrence['rangeStart'] = $target->format('Y-m-d');
        }
        if (strtolower(trim((string) ($recurrence['endMode'] ?? ''))) === 'until') {
            $until = self::date(trim((string) ($recurrence['until'] ?? '')));
            if ($until === null) {
                throw new InvalidArgumentException('The task series contains an invalid end date.');
            }
            $recurrence['until'] = self::shiftDate($until, $shift)->format('Y-m-d');
        }

        return $recurrence;
    }

    /**
     * Optimistically updates already fetched following occurrences after a series write.
     *
     * Provider synchronization remains authoritative. This prevents the stale snapshot
     * fetched before the write from immediately overwriting the visible shifted dates.
     *
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed> $targetEvent
     * @return list<array<string, mixed>>
     */
    public static function shiftFollowingEvents(
        array $events,
        array $targetEvent,
        string $newStart
    ): array {
        $original = self::eventDate((string) ($targetEvent['originalStart'] ?? ''));
        $target = self::eventDate($newStart);
        $seriesKey = self::seriesKey($targetEvent);
        if ($original === null || $target === null || $seriesKey === '') {
            return $events;
        }

        $shift = (int) $original->diff($target)->format('%r%a');
        if ($shift === 0) {
            return $events;
        }

        foreach ($events as &$event) {
            $eventOriginal = self::eventDate((string) ($event['originalStart'] ?? ''));
            if (self::seriesKey($event) !== $seriesKey
                || $eventOriginal === null
                || $eventOriginal <= $original) {
                continue;
            }

            foreach (['start', 'end'] as $key) {
                $date = self::date(trim((string) ($event[$key] ?? '')));
                if ($date !== null) {
                    $event[$key] = self::shiftDate($date, $shift)->format('Y-m-d');
                }
            }
            foreach (['startTimestamp', 'endTimestamp'] as $key) {
                if (isset($event[$key]) && is_numeric($event[$key])) {
                    $event[$key] = (int) $event[$key] + ($shift * 86400);
                }
            }
        }
        unset($event);

        return $events;
    }

    /**
     * Preserves a task-series shift while a provider still returns its pre-write snapshot.
     *
     * @param list<array<string, mixed>> $events Fresh provider events.
     * @param list<array<string, mixed>> $cachedEvents Last successfully stored events.
     * @return list<array<string, mixed>>
     */
    public static function preservePendingSeries(
        array $events,
        array $cachedEvents,
        DateTimeImmutable $today
    ): array {
        $cachedByOccurrence = [];
        foreach ($cachedEvents as $cachedEvent) {
            $key = self::occurrenceKey($cachedEvent);
            if ($key !== '') {
                $cachedByOccurrence[$key] = $cachedEvent;
            }
        }

        $pendingSeries = [];
        foreach ($events as $event) {
            if (self::rollForwardChanges($event, $today) === null) {
                continue;
            }
            $key = self::occurrenceKey($event);
            $cached = $cachedByOccurrence[$key] ?? null;
            if (!is_array($cached)) {
                continue;
            }
            $cached = self::enrich($cached);
            $cachedStart = self::eventDate((string) ($cached['start'] ?? ''));
            if ((bool) ($cached['task'] ?? false)
                && !(bool) ($cached['taskCompleted'] ?? false)
                && $cachedStart !== null
                && $cachedStart == $today) {
                $seriesKey = self::seriesKey($event);
                if ($seriesKey !== '') {
                    $pendingSeries[$seriesKey] = true;
                }
            }
        }

        if ($pendingSeries === []) {
            return $events;
        }
        foreach ($events as &$event) {
            if (!isset($pendingSeries[self::seriesKey($event)])) {
                continue;
            }
            $cached = $cachedByOccurrence[self::occurrenceKey($event)] ?? null;
            if (is_array($cached)) {
                $event = $cached;
            }
        }
        unset($event);

        return $events;
    }

    /**
     * Removes an OpenCalendar task marker from an event title.
     */
    public static function plainSummary(string $summary): string
    {
        return trim((string) preg_replace('/^(?:☐↻|☑↻|☐|☑)\s*/u', '', trim($summary)));
    }

    private static function marker(string $summary): string
    {
        $summary = ltrim($summary);
        foreach ([self::OPEN_FOLLOW_MARKER, self::COMPLETED_FOLLOW_MARKER, self::OPEN_MARKER, self::COMPLETED_MARKER] as $marker) {
            if (str_starts_with($summary, $marker)) {
                return $marker;
            }
        }

        return '';
    }

    /** @param mixed $weekdays @return list<string> */
    private static function shiftWeekdays(mixed $weekdays, DateTimeImmutable $fallback, int $shift): array
    {
        $values = is_array($weekdays) ? $weekdays : [];
        $values = array_values(array_filter(
            array_map(static fn (mixed $day): string => strtoupper(trim((string) $day)), $values),
            static fn (string $day): bool => in_array($day, self::WEEKDAYS, true)
        ));
        if ($values === []) {
            $values = [self::WEEKDAYS[(int) $fallback->format('N') - 1]];
        }

        $shifted = [];
        foreach ($values as $weekday) {
            $index = array_search($weekday, self::WEEKDAYS, true);
            if ($index === false) {
                continue;
            }
            $shifted[] = self::WEEKDAYS[(($index + $shift) % 7 + 7) % 7];
        }

        return array_values(array_unique($shifted));
    }

    private static function relativeIndex(DateTimeImmutable $date): string
    {
        $position = (int) ceil(((int) $date->format('j')) / 7);
        if ($date->modify('+7 days')->format('n') !== $date->format('n')) {
            return 'last';
        }

        return match ($position) {
            1       => 'first',
            2       => 'second',
            3       => 'third',
            default => 'fourth'
        };
    }

    /** @param array<string, mixed> $event */
    private static function seriesKey(array $event): string
    {
        if (!(bool) ($event['recurring'] ?? false)) {
            return '';
        }
        $seriesId = trim((string) ($event['seriesId'] ?? ''));
        if ($seriesId !== '') {
            return 'series:' . $seriesId;
        }
        $uid = trim((string) ($event['uid'] ?? ''));
        return $uid !== '' ? 'uid:' . $uid : '';
    }

    /** @param array<string, mixed> $event */
    private static function occurrenceKey(array $event): string
    {
        $seriesKey = self::seriesKey($event);
        if ($seriesKey === '') {
            return '';
        }
        $originalStart = trim((string) ($event['originalStart'] ?? ''));
        if ($originalStart !== '') {
            return $seriesKey . '|original:' . $originalStart;
        }
        $occurrenceId = trim((string) ($event['occurrenceId'] ?? ''));
        return $occurrenceId !== '' ? $seriesKey . '|occurrence:' . $occurrenceId : '';
    }

    private static function eventDate(string $value): ?DateTimeImmutable
    {
        return self::date(substr(trim($value), 0, 10));
    }

    private static function shiftDate(DateTimeImmutable $date, int $days): DateTimeImmutable
    {
        return $date->modify(($days >= 0 ? '+' : '') . $days . ' days');
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
    private static function requestedFollowPlanned(array $event, array $source): bool
    {
        if (array_key_exists('taskFollowPlanned', $event)) {
            return (bool) $event['taskFollowPlanned'];
        }

        return (bool) ($source['taskFollowPlanned'] ?? false);
    }

    private static function markerFor(bool $completed, bool $followPlanned): string
    {
        if ($followPlanned) {
            return $completed ? self::COMPLETED_FOLLOW_MARKER : self::OPEN_FOLLOW_MARKER;
        }

        return $completed ? self::COMPLETED_MARKER : self::OPEN_MARKER;
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
        $start = self::date((string) ($event['start'] ?? $source['start'] ?? ''));
        $end = self::date((string) ($event['end'] ?? $source['end'] ?? ''));
        $oneDay = $start === null || $end === null || $end == $start->modify('+1 day');
        if (!$allDay || !$oneDay) {
            throw new InvalidArgumentException('Task appointments must be one-day all-day events.');
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
