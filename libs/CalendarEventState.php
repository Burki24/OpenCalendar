<?php

declare(strict_types=1);

namespace IPSKalender;

final class CalendarEventState
{
    public const STATUS_TENTATIVE = 'TENTATIVE';
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const TRANSP_OPAQUE = 'OPAQUE';
    public const TRANSP_TRANSPARENT = 'TRANSPARENT';

    /**
     * Normalizes a provider event status to the RFC 5545 VEVENT values.
     */
    public static function normalizeStatus(mixed $value, string $fallback = ''): string
    {
        $status = strtoupper(trim((string) $value));
        if (in_array($status, [
            self::STATUS_TENTATIVE,
            self::STATUS_CONFIRMED,
            self::STATUS_CANCELLED
        ], true)) {
            return $status;
        }

        $fallback = strtoupper(trim($fallback));
        return in_array($fallback, [
            self::STATUS_TENTATIVE,
            self::STATUS_CONFIRMED,
            self::STATUS_CANCELLED
        ], true) ? $fallback : '';
    }

    /**
     * Returns whether a provider-neutral event status represents a cancelled event.
     */
    public static function isCancelled(mixed $value): bool
    {
        return self::normalizeStatus($value) === self::STATUS_CANCELLED;
    }

    /**
     * Removes cancelled events from a provider-neutral event list.
     *
     * @param array<mixed> $events
     * @return list<array<string, mixed>>
     */
    public static function filterVisibleEvents(array $events): array
    {
        return array_values(array_filter(
            $events,
            static fn (mixed $event): bool => is_array($event)
                && !self::isCancelled($event['status'] ?? '')
        ));
    }

    /**
     * Normalizes provider free/busy transparency to the RFC 5545 VEVENT values.
     */
    public static function normalizeTransparency(
        mixed $value,
        string $fallback = self::TRANSP_OPAQUE
    ): string {
        $transparency = strtoupper(trim((string) $value));
        if (in_array($transparency, [self::TRANSP_OPAQUE, self::TRANSP_TRANSPARENT], true)) {
            return $transparency;
        }

        $fallback = strtoupper(trim($fallback));
        return in_array($fallback, [self::TRANSP_OPAQUE, self::TRANSP_TRANSPARENT], true)
            ? $fallback
            : self::TRANSP_OPAQUE;
    }
}
