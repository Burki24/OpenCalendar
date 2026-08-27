<?php

declare(strict_types=1);

namespace IPSKalender;

/**
 * Exposes provider operations for recurring series and verified scoped writes.
 */
interface RecurringCalendarProviderInterface
{
    /**
     * Returns the normalized parent event for a recurring series.
     *
     * @param string $calendarReference Provider-specific calendar identifier or URL.
     * @param string $seriesId Provider-specific recurring parent event identifier.
     * @param string $resourceReference Optional provider-specific resource reference already known for the series.
     * @return array<string, mixed> Normalized recurring parent event.
     */
    public function getRecurringSeries(
        string $calendarReference,
        string $seriesId,
        string $resourceReference = ''
    ): array;

    /**
     * Returns a verified recurring target event for operations on this and following occurrences.
     *
     * The returned event contains the provider-neutral recurrence data required to
     * safely split, shorten, update or delete the series from the selected occurrence.
     *
     * @param string $calendarReference Provider-specific calendar identifier or URL.
     * @param string $seriesId Provider-specific recurring parent event identifier.
     * @param string $occurrenceId Provider-specific target occurrence identifier.
     * @param string $originalStart Immutable original start of the target occurrence.
     * @param string $resourceReference Optional provider-specific resource reference already known for the series.
     * @return array<string, mixed> Normalized recurring target event and recurrence settings.
     */
    public function getRecurringFollowing(
        string $calendarReference,
        string $seriesId,
        string $occurrenceId,
        string $originalStart,
        string $resourceReference = ''
    ): array;
}
