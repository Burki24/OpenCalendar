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
            if ((bool) ($event['allDay'] ?? false)) {
                $eventStartDate = self::dateOnly((string) ($event['start'] ?? ''));
                $eventEndDate = self::dateOnly((string) ($event['end'] ?? ''));
                if ($eventStartDate !== '' && $eventEndDate !== '') {
                    $dayDate = $dayStart->format('Y-m-d');
                    if ($eventStartDate <= $dayDate && $eventEndDate > $dayDate) {
                        $count++;
                    }
                    continue;
                }
            }

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

    private static function dateOnly(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
