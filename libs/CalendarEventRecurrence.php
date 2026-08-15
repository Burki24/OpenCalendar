<?php

declare(strict_types=1);

namespace IPSKalender;

/**
 * Normalizes provider-specific recurrence metadata into a common event identity.
 */
final class CalendarEventRecurrence
{
    public const SINGLE = 'single';
    public const MASTER = 'master';
    public const OCCURRENCE = 'occurrence';
    public const EXCEPTION = 'exception';
    public const UNKNOWN = 'unknown';
    public const WRITE_SCOPE_OCCURRENCE = 'occurrence';
    public const WRITE_SCOPE_SERIES = 'series';

    /**
     * @return array<string, mixed>
     */
    public static function single(): array
    {
        return self::metadata(self::SINGLE);
    }

    /**
     * @return array<string, mixed>
     */
    public static function master(
        string $seriesId,
        bool $canUpdateSeries = false,
        bool $canDeleteSeries = false
    ): array {
        return self::metadata(
            self::MASTER,
            $seriesId,
            '',
            '',
            '',
            false,
            $canUpdateSeries,
            $canDeleteSeries
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function occurrence(
        string $seriesId,
        string $occurrenceId,
        string $originalStart,
        string $recurrenceId = '',
        bool $writeSupported = false,
        bool $exception = false,
        bool $canUpdateSeries = false,
        bool $canDeleteSeries = false
    ): array {
        return self::metadata(
            $exception ? self::EXCEPTION : self::OCCURRENCE,
            $seriesId,
            $occurrenceId,
            $originalStart,
            $recurrenceId,
            $writeSupported,
            $canUpdateSeries,
            $canDeleteSeries
        );
    }

    /**
     * Returns recurrence metadata for current and legacy normalized events.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function fromEvent(array $event): array
    {
        $type = strtolower(trim((string) ($event['recurrenceType'] ?? '')));
        if (!in_array($type, [self::SINGLE, self::MASTER, self::OCCURRENCE, self::EXCEPTION, self::UNKNOWN], true)) {
            $type = ((bool) ($event['recurring'] ?? false) || trim((string) ($event['recurrenceId'] ?? '')) !== '')
                ? self::UNKNOWN
                : self::SINGLE;
        }
        if ($type === self::SINGLE
            && ((bool) ($event['recurring'] ?? false) || trim((string) ($event['recurrenceId'] ?? '')) !== '')) {
            $type = self::UNKNOWN;
        }

        $writeSupported = in_array($type, [self::OCCURRENCE, self::EXCEPTION], true)
            && (bool) ($event['canUpdateOccurrence'] ?? false)
            && (bool) ($event['canDeleteOccurrence'] ?? false);
        $canUpdateSeries = $type !== self::SINGLE
            && (bool) ($event['canUpdateSeries'] ?? false);
        $canDeleteSeries = $type !== self::SINGLE
            && (bool) ($event['canDeleteSeries'] ?? false);
        $writeScope = strtolower(trim((string) ($event['writeScope'] ?? '')));
        if (!in_array($writeScope, [self::WRITE_SCOPE_OCCURRENCE, self::WRITE_SCOPE_SERIES], true)) {
            $writeScope = self::WRITE_SCOPE_OCCURRENCE;
        }

        return self::metadata(
            $type,
            trim((string) ($event['seriesId'] ?? '')),
            trim((string) ($event['occurrenceId'] ?? '')),
            trim((string) ($event['originalStart'] ?? '')),
            trim((string) ($event['recurrenceId'] ?? '')),
            $writeSupported,
            $canUpdateSeries,
            $canDeleteSeries,
            $writeScope
        );
    }

    /** @param array<string, mixed> $event */
    public static function isOccurrence(array $event): bool
    {
        return in_array(
            (string) (self::fromEvent($event)['recurrenceType'] ?? ''),
            [self::OCCURRENCE, self::EXCEPTION],
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function metadata(
        string $type,
        string $seriesId = '',
        string $occurrenceId = '',
        string $originalStart = '',
        string $recurrenceId = '',
        bool $writeSupported = false,
        bool $canUpdateSeries = false,
        bool $canDeleteSeries = false,
        string $writeScope = self::WRITE_SCOPE_OCCURRENCE
    ): array {
        $recurring = $type !== self::SINGLE;
        $occurrence = in_array($type, [self::OCCURRENCE, self::EXCEPTION], true);

        return [
            'recurrenceType'      => $type,
            'seriesId'            => $seriesId,
            'occurrenceId'        => $occurrenceId,
            'originalStart'       => $originalStart,
            'recurrenceId'        => $recurrenceId,
            'recurring'           => $recurring,
            'canUpdateOccurrence' => $occurrence && $writeSupported,
            'canDeleteOccurrence' => $occurrence && $writeSupported,
            'canUpdateSeries'     => $recurring && $canUpdateSeries,
            'canDeleteSeries'     => $recurring && $canDeleteSeries,
            'writeScope'          => $recurring ? $writeScope : ''
        ];
    }
}
