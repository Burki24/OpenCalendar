<?php

declare(strict_types=1);

namespace IPSKalender;

interface CalendarEventLookupProviderInterface
{
    /**
     * Returns one current normalized event directly from the provider.
     *
     * @param string $calendarReference Provider-specific calendar identifier or URL.
     * @param array{
     *     eventReference?: string,
     *     resourceUrl?: string,
     *     uid?: string,
     *     seriesId?: string,
     *     occurrenceId?: string,
     *     originalStart?: string,
     *     recurrenceId?: string,
     *     startTimestamp?: int,
     *     endTimestamp?: int
     * } $identity Provider-neutral event identity and optional bounded lookup range.
     * @return array<string, mixed> Current normalized event data.
     */
    public function getEventForEdit(string $calendarReference, array $identity): array;
}
