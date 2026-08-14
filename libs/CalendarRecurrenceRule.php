<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Validates provider-neutral recurrence settings and serializes RFC 5545 rules.
 */
final class CalendarRecurrenceRule
{
    private const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
    private const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /**
     * Builds the Google Calendar recurrence lines for a newly created event.
     *
     * @param array<string, mixed> $recurrence Provider-neutral recurrence settings.
     * @return list<string> Google Calendar recurrence lines.
     */
    public static function toGoogleLines(
        array $recurrence,
        DateTimeImmutable $start,
        bool $allDay,
        string $timezone
    ): array {
        if ($recurrence === [] || array_is_list($recurrence)) {
            throw new InvalidArgumentException('The recurrence settings are invalid.');
        }

        $frequency = strtoupper(trim((string) ($recurrence['frequency'] ?? '')));
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException('The recurrence frequency is invalid.');
        }

        $interval = filter_var(
            $recurrence['interval'] ?? 1,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 999]]
        );
        if ($interval === false) {
            throw new InvalidArgumentException('The recurrence interval is invalid.');
        }

        $parts = ['FREQ=' . $frequency];
        if ($interval !== 1) {
            $parts[] = 'INTERVAL=' . $interval;
        }

        if ($frequency === 'WEEKLY') {
            $weekdays = self::weekdays($recurrence['byDay'] ?? []);
            if ($weekdays !== []) {
                $parts[] = 'BYDAY=' . implode(',', $weekdays);
            }
        }

        $endMode = strtolower(trim((string) ($recurrence['endMode'] ?? 'never')));
        if (!in_array($endMode, ['never', 'count', 'until'], true)) {
            throw new InvalidArgumentException('The recurrence end mode is invalid.');
        }

        if ($endMode === 'count') {
            $count = filter_var(
                $recurrence['count'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 9999]]
            );
            if ($count === false) {
                throw new InvalidArgumentException('The recurrence count is invalid.');
            }
            $parts[] = 'COUNT=' . $count;
        } elseif ($endMode === 'until') {
            $parts[] = 'UNTIL=' . self::untilValue(
                trim((string) ($recurrence['until'] ?? '')),
                $start,
                $allDay,
                $timezone
            );
        }

        return ['RRULE:' . implode(';', $parts)];
    }

    /**
     * @return list<string>
     */
    private static function weekdays(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('The recurrence weekdays are invalid.');
        }

        $selected = [];
        foreach ($value as $weekday) {
            $weekday = strtoupper(trim((string) $weekday));
            if (!in_array($weekday, self::WEEKDAYS, true)) {
                throw new InvalidArgumentException('The recurrence weekdays are invalid.');
            }
            $selected[$weekday] = true;
        }

        return array_values(array_filter(
            self::WEEKDAYS,
            static fn (string $weekday): bool => isset($selected[$weekday])
        ));
    }

    private static function untilValue(
        string $value,
        DateTimeImmutable $start,
        bool $allDay,
        string $timezone
    ): string {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('The recurrence end date is invalid.');
        }

        $zone = $allDay ? new DateTimeZone('UTC') : self::timezone($timezone);
        $untilDate = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if ($untilDate === false || $untilDate->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('The recurrence end date is invalid.');
        }

        if ($allDay) {
            $seriesStart = DateTimeImmutable::createFromFormat('!Y-m-d', $start->format('Y-m-d'), $zone);
            if ($seriesStart === false || $untilDate < $seriesStart) {
                throw new InvalidArgumentException('The recurrence end date must not be before the event start.');
            }

            return $untilDate->format('Ymd');
        }

        $localStart = $start->setTimezone($zone);
        $until = $untilDate->setTime(
            (int) $localStart->format('H'),
            (int) $localStart->format('i'),
            (int) $localStart->format('s')
        );
        if ($until < $localStart) {
            throw new InvalidArgumentException('The recurrence end date must not be before the event start.');
        }

        return $until->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private static function timezone(string $name): DateTimeZone
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('The recurring event timezone is missing.');
        }

        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            throw new InvalidArgumentException('The recurring event timezone is invalid.');
        }
    }
}
