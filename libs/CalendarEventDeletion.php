<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use Throwable;

final class CalendarEventDeletion
{
    private const SINGLE = 'single';
    private const WRITE_SCOPE_FOLLOWING = 'following';
    private const WRITE_SCOPE_SERIES = 'series';

    /**
     * Removes cached events covered by a confirmed provider deletion.
     *
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed> $event
     * @param array<string, mixed> $recurrence Normalized recurrence metadata.
     * @return list<array<string, mixed>>
     */
    public static function filter(array $events, array $event, array $recurrence): array
    {
        $recurrenceType = strtolower(trim((string) ($recurrence['recurrenceType'] ?? self::SINGLE)));
        $writeScope = strtolower(trim((string) ($recurrence['writeScope'] ?? '')));

        return array_values(array_filter(
            $events,
            static fn (array $candidate): bool => !self::matchesDeletedEvent(
                $candidate,
                $event,
                $recurrence,
                $recurrenceType,
                $writeScope
            )
        ));
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $event
     * @param array<string, mixed> $recurrence
     */
    private static function matchesDeletedEvent(
        array $candidate,
        array $event,
        array $recurrence,
        string $recurrenceType,
        string $writeScope
    ): bool {
        if ($recurrenceType === self::SINGLE) {
            return self::matchesDirectIdentity($candidate, $event, true);
        }

        if ($writeScope === self::WRITE_SCOPE_SERIES) {
            return self::matchesSeries($candidate, $event, $recurrence);
        }

        if ($writeScope === self::WRITE_SCOPE_FOLLOWING) {
            if (!self::matchesSeries($candidate, $event, $recurrence)) {
                return false;
            }

            $threshold = self::eventBoundaryTimestamp($recurrence, $event);
            $candidateBoundary = self::eventBoundaryTimestamp($candidate, $candidate);
            if ($threshold > 0 && $candidateBoundary > 0) {
                return $candidateBoundary >= $threshold;
            }

            return self::matchesOccurrence($candidate, $event, $recurrence);
        }

        return self::matchesOccurrence($candidate, $event, $recurrence);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $event
     * @param array<string, mixed> $recurrence
     */
    private static function matchesOccurrence(array $candidate, array $event, array $recurrence): bool
    {
        $targetSeriesId = trim((string) ($recurrence['seriesId'] ?? $event['seriesId'] ?? ''));
        $candidateSeriesId = trim((string) ($candidate['seriesId'] ?? ''));
        if ($targetSeriesId !== ''
            && $candidateSeriesId !== ''
            && !hash_equals($targetSeriesId, $candidateSeriesId)) {
            return false;
        }

        foreach (['occurrenceId', 'recurrenceId'] as $key) {
            $expected = trim((string) ($recurrence[$key] ?? $event[$key] ?? ''));
            $actual = trim((string) ($candidate[$key] ?? ''));
            if ($expected !== '' && $actual !== '' && hash_equals($expected, $actual)) {
                return true;
            }
        }

        $expectedOriginalStart = trim((string) ($recurrence['originalStart'] ?? $event['originalStart'] ?? ''));
        $actualOriginalStart = trim((string) ($candidate['originalStart'] ?? ''));
        if ($expectedOriginalStart !== '' && $actualOriginalStart !== '') {
            if (hash_equals($expectedOriginalStart, $actualOriginalStart)) {
                return true;
            }

            $expectedTimestamp = self::timestamp($expectedOriginalStart);
            $actualTimestamp = self::timestamp($actualOriginalStart);
            if ($expectedTimestamp > 0 && $expectedTimestamp === $actualTimestamp) {
                return true;
            }
        }

        return self::matchesDirectIdentity($candidate, $event, false);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $event
     * @param array<string, mixed> $recurrence
     */
    private static function matchesSeries(array $candidate, array $event, array $recurrence): bool
    {
        $targetSeriesId = trim((string) ($recurrence['seriesId'] ?? $event['seriesId'] ?? ''));
        $candidateSeriesId = trim((string) ($candidate['seriesId'] ?? ''));
        if ($targetSeriesId !== '') {
            if ($candidateSeriesId !== '' && hash_equals($targetSeriesId, $candidateSeriesId)) {
                return true;
            }

            $candidateReference = trim((string) ($candidate['eventReference'] ?? ''));
            if ($candidateReference !== '' && hash_equals($targetSeriesId, $candidateReference)) {
                return true;
            }
        }

        $targetUid = trim((string) ($event['uid'] ?? ''));
        $candidateUid = trim((string) ($candidate['uid'] ?? ''));
        return $targetUid !== '' && $candidateUid !== '' && hash_equals($targetUid, $candidateUid);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $identity
     */
    private static function matchesDirectIdentity(array $candidate, array $identity, bool $includeUid): bool
    {
        $keys = ['resourceUrl', 'eventReference'];
        if ($includeUid) {
            $keys[] = 'uid';
        }

        foreach ($keys as $key) {
            $expected = trim((string) ($identity[$key] ?? ''));
            $actual = trim((string) ($candidate[$key] ?? ''));
            if ($expected !== '' && $actual !== '' && hash_equals($expected, $actual)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $event
     */
    private static function eventBoundaryTimestamp(array $identity, array $event): int
    {
        $originalStart = trim((string) ($identity['originalStart'] ?? $event['originalStart'] ?? ''));
        if ($originalStart !== '') {
            $timestamp = self::timestamp($originalStart);
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        $start = trim((string) ($event['start'] ?? ''));
        if ($start !== '') {
            $timestamp = self::timestamp($start);
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        return max(0, (int) ($event['startTimestamp'] ?? 0));
    }

    private static function timestamp(string $value): int
    {
        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }
}
