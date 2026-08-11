<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Normalizes local appointment date ranges and checks event overlap semantics.
 */
final class CalendarAppointmentRange
{
    private const MAX_RANGE_SECONDS = 6 * 366 * 86400;

    /**
     * Converts an inclusive local date range into start-inclusive/end-exclusive boundaries.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function fromInclusiveDates(string $from, string $to): array
    {
        $rangeStart = self::parseLocalDate($from, 'from');
        $rangeLastDay = self::parseLocalDate($to, 'to');
        if ($rangeLastDay < $rangeStart) {
            throw new InvalidArgumentException('The end date must not be before the start date.');
        }

        $rangeEnd = $rangeLastDay->modify('+1 day');
        if (($rangeEnd->getTimestamp() - $rangeStart->getTimestamp()) > self::MAX_RANGE_SECONDS) {
            throw new InvalidArgumentException('The requested appointment range is too large.');
        }

        return [$rangeStart, $rangeEnd];
    }

    /**
     * Returns whether a normalized event overlaps the supplied local range.
     *
     * @param array<string, mixed> $event Normalized OpenCalendar event.
     */
    public static function eventOverlaps(
        array $event,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd
    ): bool {
        if ((bool) ($event['allDay'] ?? false)) {
            $eventStartDate = self::dateOnly((string) ($event['start'] ?? ''));
            $eventEndDate = self::dateOnly((string) ($event['end'] ?? ''));
            if ($eventStartDate !== '' && $eventEndDate !== '') {
                return $eventStartDate < $rangeEnd->format('Y-m-d')
                    && $eventEndDate > $rangeStart->format('Y-m-d');
            }
        }

        $startTimestamp = (int) ($event['startTimestamp'] ?? 0);
        if ($startTimestamp <= 0) {
            return false;
        }

        $endTimestamp = (int) ($event['endTimestamp'] ?? $startTimestamp);
        if ($endTimestamp <= $startTimestamp) {
            $endTimestamp = $startTimestamp + 1;
        }

        return $startTimestamp < $rangeEnd->getTimestamp()
            && $endTimestamp > $rangeStart->getTimestamp();
    }

    private static function parseLocalDate(string $value, string $field): DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(sprintf(
                'The %s date must use the format YYYY-MM-DD.',
                $field
            ));
        }

        return $date;
    }

    private static function dateOnly(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
