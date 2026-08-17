<?php

declare(strict_types=1);

namespace IPSKalender;

use InvalidArgumentException;

final class CalendarEventReminder
{
    public const MODE_DEFAULT = 'default';
    public const MODE_NONE = 'none';
    public const MODE_CUSTOM = 'custom';
    public const MODE_MULTIPLE = 'multiple';
    public const MODE_COMPLEX = 'complex';

    public const MAX_MINUTES_BEFORE_START = 40_320;
    public const MAX_REMINDERS = 5;

    /**
     * Returns a normalized provider-default reminder state.
     *
     * @return array{mode: string, minutesBeforeStart: null, editable: bool}
     */
    public static function providerDefault(bool $editable = true): array
    {
        return [
            'mode'               => self::MODE_DEFAULT,
            'minutesBeforeStart' => null,
            'editable'           => $editable
        ];
    }

    /**
     * Returns a normalized disabled reminder state.
     *
     * @return array{mode: string, minutesBeforeStart: null, editable: bool}
     */
    public static function none(bool $editable = true): array
    {
        return [
            'mode'               => self::MODE_NONE,
            'minutesBeforeStart' => null,
            'editable'           => $editable
        ];
    }

    /**
     * Returns one normalized reminder relative to the event start.
     *
     * @return array{mode: string, minutesBeforeStart: int, editable: bool}
     */
    public static function custom(int $minutesBeforeStart, bool $editable = true): array
    {
        self::assertMinutes($minutesBeforeStart);

        return [
            'mode'               => self::MODE_CUSTOM,
            'minutesBeforeStart' => $minutesBeforeStart,
            'editable'           => $editable
        ];
    }

    /**
     * Returns multiple provider-neutral reminders relative to the event start.
     *
     * @param list<int> $minutesBeforeStart
     * @return array{mode: string, minutesBeforeStart: null, reminders: list<array{minutesBeforeStart: int}>, editable: bool}
     */
    public static function multiple(array $minutesBeforeStart, bool $editable = true): array
    {
        $minutes = self::normalizeMinutesList($minutesBeforeStart, self::MAX_REMINDERS);
        if (count($minutes) < 2) {
            throw new InvalidArgumentException('Multiple reminder settings require at least two reminders.');
        }

        return [
            'mode'               => self::MODE_MULTIPLE,
            'minutesBeforeStart' => null,
            'reminders'          => array_map(
                static fn (int $minutesBeforeStart): array => ['minutesBeforeStart' => $minutesBeforeStart],
                $minutes
            ),
            'editable'           => $editable
        ];
    }

    /**
     * Builds the canonical provider-neutral state for zero, one, or multiple reminder offsets.
     *
     * @param list<int> $minutesBeforeStart
     * @return array<string, mixed>
     */
    public static function fromMinutes(array $minutesBeforeStart, bool $editable = true): array
    {
        $minutes = self::normalizeMinutesList($minutesBeforeStart, self::MAX_REMINDERS);

        return match (count($minutes)) {
            0       => self::none($editable),
            1       => self::custom($minutes[0], $editable),
            default => self::multiple($minutes, $editable)
        };
    }

    /**
     * Marks provider reminder data that cannot be represented losslessly.
     *
     * @return array{mode: string, minutesBeforeStart: null, editable: false}
     */
    public static function complex(): array
    {
        return [
            'mode'               => self::MODE_COMPLEX,
            'minutesBeforeStart' => null,
            'editable'           => false
        ];
    }

    /**
     * Validates reminder settings supplied by OpenCalendar callers.
     *
     * @param mixed $value Provider-neutral reminder settings.
     * @return array<string, mixed>
     */
    public static function normalizeInput(
        mixed $value,
        bool $allowProviderDefault = false,
        int $maxReminders = self::MAX_REMINDERS
    ): array {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('The reminder settings are invalid.');
        }
        if ($maxReminders < 1 || $maxReminders > self::MAX_REMINDERS) {
            throw new InvalidArgumentException('The reminder count limit is invalid.');
        }

        $mode = strtolower(trim((string) ($value['mode'] ?? '')));
        if ($mode === self::MODE_DEFAULT) {
            if (!$allowProviderDefault) {
                throw new InvalidArgumentException('Provider-default reminders are not supported by this calendar.');
            }

            return [
                'mode'               => self::MODE_DEFAULT,
                'minutesBeforeStart' => null
            ];
        }

        if ($mode === self::MODE_NONE) {
            return [
                'mode'               => self::MODE_NONE,
                'minutesBeforeStart' => null
            ];
        }

        if ($mode === self::MODE_CUSTOM) {
            return [
                'mode'               => self::MODE_CUSTOM,
                'minutesBeforeStart' => self::normalizedMinutes($value['minutesBeforeStart'] ?? null)
            ];
        }

        if ($mode !== self::MODE_MULTIPLE) {
            throw new InvalidArgumentException('The reminder settings are invalid.');
        }

        $reminders = $value['reminders'] ?? null;
        if (!is_array($reminders) || !array_is_list($reminders) || count($reminders) < 2) {
            throw new InvalidArgumentException('Multiple reminder settings require at least two reminders.');
        }
        if (count($reminders) > $maxReminders) {
            throw new InvalidArgumentException('The calendar does not support this many reminders.');
        }

        $minutes = [];
        foreach ($reminders as $reminder) {
            if (!is_array($reminder) || array_is_list($reminder)) {
                throw new InvalidArgumentException('The reminder settings are invalid.');
            }
            $minutes[] = self::normalizedMinutes($reminder['minutesBeforeStart'] ?? null);
        }
        $minutes = self::normalizeMinutesList($minutes, $maxReminders);

        return [
            'mode'               => self::MODE_MULTIPLE,
            'minutesBeforeStart' => null,
            'reminders'          => array_map(
                static fn (int $minutesBeforeStart): array => ['minutesBeforeStart' => $minutesBeforeStart],
                $minutes
            )
        ];
    }

    /**
     * Returns all exact reminder offsets contained in a normalized custom reminder state.
     *
     * Provider-default, disabled, and complex states intentionally return an empty list.
     *
     * @param array<string, mixed> $reminder Normalized reminder state.
     * @return list<int>
     */
    public static function minutesBeforeStartValues(array $reminder): array
    {
        $mode = (string) ($reminder['mode'] ?? '');
        if ($mode === self::MODE_CUSTOM) {
            return [self::normalizedMinutes($reminder['minutesBeforeStart'] ?? null)];
        }
        if ($mode !== self::MODE_MULTIPLE) {
            return [];
        }

        $normalized = self::normalizeInput($reminder);

        return array_map(
            static fn (array $item): int => (int) $item['minutesBeforeStart'],
            $normalized['reminders']
        );
    }

    private static function normalizedMinutes(mixed $value): int
    {
        $minutes = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => self::MAX_MINUTES_BEFORE_START]]
        );
        if ($minutes === false) {
            throw new InvalidArgumentException('The reminder time is invalid.');
        }

        return (int) $minutes;
    }

    /**
     * @param list<int> $minutesBeforeStart
     * @return list<int>
     */
    private static function normalizeMinutesList(array $minutesBeforeStart, int $maxReminders): array
    {
        if (count($minutesBeforeStart) > $maxReminders) {
            throw new InvalidArgumentException('The calendar does not support this many reminders.');
        }

        $normalized = [];
        foreach ($minutesBeforeStart as $minutes) {
            $normalized[] = self::normalizedMinutes($minutes);
        }
        if (count(array_unique($normalized, SORT_NUMERIC)) !== count($normalized)) {
            throw new InvalidArgumentException('Reminder times must be unique.');
        }

        return array_values($normalized);
    }

    private static function assertMinutes(int $minutesBeforeStart): void
    {
        if ($minutesBeforeStart < 0 || $minutesBeforeStart > self::MAX_MINUTES_BEFORE_START) {
            throw new InvalidArgumentException('The reminder time is invalid.');
        }
    }
}
