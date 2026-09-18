<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/CalendarEventRecurrence.php';

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
     * Whether an occurrence has an isolated provider identity suitable for direct cache readback.
     * Currently only Graph identities have this verified contract; shared iCalendar UIDs do not.
     *
     * @param array<string, mixed> $event
     */
    public static function supportsOccurrenceReadback(array $event): bool
    {
        return CalendarEventRecurrence::isOccurrence($event)
            && trim((string) ($event['seriesId'] ?? '')) !== ''
            && preg_match(
                '~^https://graph\.microsoft\.com/v1\.0/me/calendars/[^/]+/events/[^/?#]+$~D',
                (string) ($event['resourceUrl'] ?? '')
            ) === 1;
    }

    /**
     * Matches a logical Microsoft occurrence when its provider ID changes.
     * Other providers can share one UID across a whole series and must not use this fallback.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    public static function sameOccurrence(array $left, array $right): bool
    {
        if (!self::supportsOccurrenceReadback($left)
            || !self::supportsOccurrenceReadback($right)
            || (string) $left['seriesId'] !== (string) $right['seriesId']
            || dirname((string) $left['resourceUrl']) !== dirname((string) $right['resourceUrl'])) {
            return false;
        }
        $uid = trim((string) ($left['uid'] ?? ''));
        if ($uid !== '' && $uid === trim((string) ($right['uid'] ?? ''))) {
            return true;
        }
        $leftStart = trim((string) ($left['originalStart'] ?? ''));
        $rightStart = trim((string) ($right['originalStart'] ?? ''));
        if ($leftStart === '' || $rightStart === '') {
            return false;
        }
        if ($leftStart === $rightStart) {
            return true;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $leftStart) === 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $rightStart) === 1) {
            // A date-only fallback cannot safely be compared with Graph's UTC
            // originalStart without a verified timezone. Prefer the UID above.
            return false;
        }
        try {
            return (new DateTimeImmutable($leftStart))->getTimestamp()
                === (new DateTimeImmutable($rightStart))->getTimestamp();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Accepts a confirmed occurrence readback without mistaking a series master or sibling for the write.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $current
     * @return array<string, mixed>|null
     */
    public static function resolveOccurrenceReadback(array $source, array $current, string $writtenReference): ?array
    {
        if (!self::supportsOccurrenceReadback($source)
            || !self::supportsOccurrenceReadback($current)
            || (string) ($source['writeScope'] ?? '') !== CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE
            || (string) $source['seriesId'] !== (string) $current['seriesId']
            || dirname((string) $source['resourceUrl']) !== dirname((string) $current['resourceUrl'])
            || $writtenReference === ''
            || $writtenReference !== (string) ($current['eventReference'] ?? '')) {
            return null;
        }
        if (trim((string) ($current['originalStart'] ?? '')) === '') {
            // The confirmed write links the new provider ID to the previous immutable occurrence anchor.
            $current['originalStart'] = trim((string) ($source['originalStart'] ?? ''));
            $current['canUpdateFollowing'] = $current['originalStart'] !== '';
        }
        return $current;
    }

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
