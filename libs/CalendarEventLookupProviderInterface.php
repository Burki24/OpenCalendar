<?php

declare(strict_types=1);

namespace IPSKalender;

interface CalendarEventLookupProviderInterface
{
    /**
     * Returns one current normalized event directly from the provider.
     *
     * @param string $calendarReference Provider-specific calendar identifier or URL.
     * @param string $eventReference Provider-specific event identifier or resource URL.
     * @return array<string, mixed> Current normalized event data.
     */
    public function getEventForEdit(string $calendarReference, string $eventReference): array;
}
