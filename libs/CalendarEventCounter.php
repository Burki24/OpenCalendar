<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;

/**
 * Counts normalized calendar events within local calendar-day boundaries.
 */
final class CalendarEventCounter
{
    /**
     * Counts events that overlap the supplied local day.
     *
     * @param list<array<string, mixed>> $events Normalized calendar events.
     */
    public static function countForDay(array $events, DateTimeImmutable $day): int
    {
        $dayStart = $day->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $dayStartTimestamp = $dayStart->getTimestamp();
        $dayEndTimestamp = $dayEnd->getTimestamp();
        $count = 0;

        foreach ($events as $event) {
            $startTimestamp = (int) ($event['startTimestamp'] ?? 0);
            if ($startTimestamp <= 0) {
                continue;
            }

            $endTimestamp = (int) ($event['endTimestamp'] ?? $startTimestamp);
            if ($endTimestamp <= $startTimestamp) {
                $endTimestamp = $startTimestamp + 1;
            }

            if ($startTimestamp < $dayEndTimestamp && $endTimestamp > $dayStartTimestamp) {
                $count++;
            }
        }

        return $count;
    }
}
