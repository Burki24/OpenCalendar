<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use RuntimeException;

/**
 * Raised when a provider-neutral event lookup range cannot be built safely.
 */
final class CalendarEventLookupException extends RuntimeException
{
}

final class CalendarEventLookup
{
    private const RANGE_PADDING_SECONDS = 86400;
    private const MAX_RANGE_SECONDS = 6 * 366 * 86400;

    /**
     * Builds the bounded provider-neutral lookup range around an event identity.
     *
     * @param array<string, mixed> $identity
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null
     */
    public static function range(array $identity): ?array
    {
        $startTimestamp = (int) ($identity['startTimestamp'] ?? 0);
        if ($startTimestamp <= 0) {
            return null;
        }
        $endTimestamp = (int) ($identity['endTimestamp'] ?? 0);
        if ($endTimestamp <= $startTimestamp) {
            $endTimestamp = $startTimestamp + 1;
        }

        $rangeStart = max(1, $startTimestamp - self::RANGE_PADDING_SECONDS);
        $rangeEnd = $endTimestamp + self::RANGE_PADDING_SECONDS;
        if (($rangeEnd - $rangeStart) > self::MAX_RANGE_SECONDS) {
            throw new CalendarEventLookupException('The selected event time range is too large.');
        }

        return [
            new DateTimeImmutable('@' . $rangeStart),
            new DateTimeImmutable('@' . $rangeEnd)
        ];
    }
}
