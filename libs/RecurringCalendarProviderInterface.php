<?php

declare(strict_types=1);

namespace IPSKalender;

/**
 * Exposes provider operations that address the parent resource of a recurring series.
 */
interface RecurringCalendarProviderInterface
{
    /**
     * Returns the normalized parent event for a recurring series.
     *
     * @param string $calendarReference Provider-specific calendar identifier or URL.
     * @param string $seriesId Provider-specific recurring parent event identifier.
     * @return array<string, mixed> Normalized recurring parent event.
     */
    public function getRecurringSeries(string $calendarReference, string $seriesId): array;

    /**
     * Returns an editable recurring event starting at one verified occurrence.
     *
     * The returned event represents the new series that would begin with the target
     * occurrence when a provider implements "this and following" updates.
     *
     * @param string $calendarReference Provider-specific calendar identifier or URL.
     * @param string $seriesId Provider-specific recurring parent event identifier.
     * @param string $occurrenceId Provider-specific target occurrence identifier.
     * @param string $originalStart Immutable original start of the target occurrence.
     * @return array<string, mixed> Normalized recurring target event.
     */
    public function getRecurringFollowing(
        string $calendarReference,
        string $seriesId,
        string $occurrenceId,
        string $originalStart
    ): array;
}

