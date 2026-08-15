<?php

declare(strict_types=1);

namespace IPSKalender;

/**
 * Normalizes provider-specific recurrence metadata and supported write scopes.
 */
final class CalendarEventRecurrence
{
    public const SINGLE = 'single';
    public const MASTER = 'master';
    public const OCCURRENCE = 'occurrence';
    public const EXCEPTION = 'exception';
    public const UNKNOWN = 'unknown';
    public const WRITE_SCOPE_OCCURRENCE = 'occurrence';
    public const WRITE_SCOPE_FOLLOWING = 'following';
    public const WRITE_SCOPE_SERIES = 'series';

    /**
     * Returns recurrence metadata for a non-recurring event.
     *
     * @return array<string, mixed> Normalized single-event recurrence metadata.
     */
    public static function single(): array
    {
        return self::metadata(self::SINGLE);
    }

    /**
     * Returns recurrence metadata for a recurring parent event.
     *
     * @param string $seriesId Provider-specific recurring parent event identifier.
     * @param bool $canUpdateSeries Whether the provider allows updating the complete series.
     * @param bool $canDeleteSeries Whether the provider allows deleting the complete series.
     * @return array<string, mixed> Normalized recurring parent metadata.
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
     * Returns recurrence metadata for one occurrence or exception of a recurring series.
     *
     * @param string $seriesId Provider-specific recurring parent event identifier.
     * @param string $occurrenceId Provider-specific occurrence identifier.
     * @param string $originalStart Immutable original start of the occurrence.
     * @param string $recurrenceId Optional provider-neutral recurrence identifier.
     * @param bool $writeSupported Whether the individual occurrence can be updated and deleted.
     * @param bool $exception Whether the occurrence is an explicitly modified series exception.
     * @param bool $canUpdateSeries Whether the complete series can be updated.
     * @param bool $canDeleteSeries Whether the complete series can be deleted.
     * @param bool $canUpdateFollowing Whether operations may start here and affect all following occurrences.
     * @return array<string, mixed> Normalized recurring occurrence metadata.
     */
    public static function occurrence(
        string $seriesId,
        string $occurrenceId,
        string $originalStart,
        string $recurrenceId = '',
        bool $writeSupported = false,
        bool $exception = false,
        bool $canUpdateSeries = false,
        bool $canDeleteSeries = false,
        bool $canUpdateFollowing = false
    ): array {
        return self::metadata(
            $exception ? self::EXCEPTION : self::OCCURRENCE,
            $seriesId,
            $occurrenceId,
            $originalStart,
            $recurrenceId,
            $writeSupported,
            $canUpdateSeries,
            $canDeleteSeries,
            self::WRITE_SCOPE_OCCURRENCE,
            $canUpdateFollowing
        );
    }

    /**
     * Returns recurrence metadata for current and legacy normalized events.
     *
     * The write scope is restricted to occurrence, following or complete series and
     * provider capabilities are normalized into one common event identity.
     *
     * @param array<string, mixed> $event Normalized event data from a provider or legacy cache.
     * @return array<string, mixed> Normalized recurrence identity and write capabilities.
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

        $occurrence = in_array($type, [self::OCCURRENCE, self::EXCEPTION], true);
        $writeSupported = $occurrence
            && (bool) ($event['canUpdateOccurrence'] ?? false)
            && (bool) ($event['canDeleteOccurrence'] ?? false);
        $canUpdateSeries = $type !== self::SINGLE
            && (bool) ($event['canUpdateSeries'] ?? false);
        $canDeleteSeries = $type !== self::SINGLE
            && (bool) ($event['canDeleteSeries'] ?? false);
        $canUpdateFollowing = $occurrence
            && (bool) ($event['canUpdateFollowing'] ?? false);
        $writeScope = strtolower(trim((string) ($event['writeScope'] ?? '')));
        if (!in_array(
            $writeScope,
            [self::WRITE_SCOPE_OCCURRENCE, self::WRITE_SCOPE_FOLLOWING, self::WRITE_SCOPE_SERIES],
            true
        )) {
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
            $writeScope,
            $canUpdateFollowing
        );
    }

    /**
     * Checks whether normalized event data represents a recurring occurrence or exception.
     *
     * @param array<string, mixed> $event Normalized event data.
     * @return bool True for recurring occurrences and explicit exceptions.
     */
    public static function isOccurrence(array $event): bool
    {
        return in_array(
            (string) (self::fromEvent($event)['recurrenceType'] ?? ''),
            [self::OCCURRENCE, self::EXCEPTION],
            true
        );
    }

    /**
     * Builds the normalized recurrence metadata array.
     *
     * @return array<string, mixed> Normalized recurrence metadata.
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
        string $writeScope = self::WRITE_SCOPE_OCCURRENCE,
        bool $canUpdateFollowing = false
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
            'canUpdateFollowing'  => $occurrence && $canUpdateFollowing,
            'canUpdateSeries'     => $recurring && $canUpdateSeries,
            'canDeleteSeries'     => $recurring && $canDeleteSeries,
            'writeScope'          => $recurring ? $writeScope : ''
        ];
    }
}
