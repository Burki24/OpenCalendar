<?php

declare(strict_types=1);

namespace IPSKalender;

use InvalidArgumentException;

final class CalendarEventReminder
{
    public const MODE_DEFAULT = 'default';
    public const MODE_NONE = 'none';
    public const MODE_CUSTOM = 'custom';
    public const MODE_COMPLEX = 'complex';

    public const MAX_MINUTES_BEFORE_START = 40_320;

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
     * @return array{mode: string, minutesBeforeStart: int|null}
     */
    public static function normalizeInput(mixed $value, bool $allowProviderDefault = false): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('The reminder settings are invalid.');
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

        if ($mode !== self::MODE_CUSTOM) {
            throw new InvalidArgumentException('The reminder settings are invalid.');
        }

        $minutes = filter_var(
            $value['minutesBeforeStart'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => self::MAX_MINUTES_BEFORE_START]]
        );
        if ($minutes === false) {
            throw new InvalidArgumentException('The reminder time is invalid.');
        }

        return [
            'mode'               => self::MODE_CUSTOM,
            'minutesBeforeStart' => (int) $minutes
        ];
    }

    private static function assertMinutes(int $minutesBeforeStart): void
    {
        if ($minutesBeforeStart < 0 || $minutesBeforeStart > self::MAX_MINUTES_BEFORE_START) {
            throw new InvalidArgumentException('The reminder time is invalid.');
        }
    }
}
