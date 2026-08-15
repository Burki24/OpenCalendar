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
}
