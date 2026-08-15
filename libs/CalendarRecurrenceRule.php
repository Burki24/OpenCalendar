<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Validates provider-neutral recurrence settings and serializes provider recurrence formats.
 */
final class CalendarRecurrenceRule
{
    private const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
    private const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
    private const MICROSOFT_WEEKDAYS = [
        'MO' => 'monday',
        'TU' => 'tuesday',
        'WE' => 'wednesday',
        'TH' => 'thursday',
        'FR' => 'friday',
        'SA' => 'saturday',
        'SU' => 'sunday'
    ];
    private const MICROSOFT_WEEKDAY_CODES = [
        'monday'    => 'MO',
        'tuesday'   => 'TU',
        'wednesday' => 'WE',
        'thursday'  => 'TH',
        'friday'    => 'FR',
        'saturday'  => 'SA',
        'sunday'    => 'SU'
    ];

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
     * Builds one RFC 5545 RRULE line for an iCalendar VEVENT.
     *
     * @param array<string, mixed> $recurrence Provider-neutral recurrence settings.
     */
    public static function toICalendarRule(
        array $recurrence,
        DateTimeImmutable $start,
        bool $allDay,
        string $timezone
    ): string {
        return self::toGoogleLines($recurrence, $start, $allDay, $timezone)[0];
    }

    /**
     * Builds a Microsoft Graph patternedRecurrence object for a recurring event.
     *
     * @param array<string, mixed> $recurrence Provider-neutral recurrence settings.
     * @param DateTimeImmutable $start Event start in the recurrence timezone.
     * @return array<string, mixed> Microsoft Graph patternedRecurrence data.
     */
    public static function toMicrosoftRecurrence(array $recurrence, DateTimeImmutable $start): array
    {
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

        $pattern = [
            'type'     => match ($frequency) {
                'DAILY'   => 'daily',
                'WEEKLY'  => 'weekly',
                'MONTHLY' => 'absoluteMonthly',
                'YEARLY'  => 'absoluteYearly'
            },
            'interval' => $interval
        ];
        if ($frequency === 'WEEKLY') {
            $weekdays = self::weekdays($recurrence['byDay'] ?? []);
            if ($weekdays === []) {
                $weekdays = [self::WEEKDAYS[(int) $start->format('N') - 1]];
            }
            $pattern['daysOfWeek'] = array_map(
                static fn (string $weekday): string => self::MICROSOFT_WEEKDAYS[$weekday],
                $weekdays
            );
            $pattern['firstDayOfWeek'] = 'monday';
        } elseif ($frequency === 'MONTHLY') {
            $pattern['dayOfMonth'] = (int) $start->format('j');
        } elseif ($frequency === 'YEARLY') {
            $pattern['dayOfMonth'] = (int) $start->format('j');
            $pattern['month'] = (int) $start->format('n');
        }

        $endMode = strtolower(trim((string) ($recurrence['endMode'] ?? 'never')));
        if (!in_array($endMode, ['never', 'count', 'until'], true)) {
            throw new InvalidArgumentException('The recurrence end mode is invalid.');
        }

        $range = [
            'type'      => 'noEnd',
            'startDate' => $start->format('Y-m-d')
        ];
        if ($endMode === 'count') {
            $count = filter_var(
                $recurrence['count'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 9999]]
            );
            if ($count === false) {
                throw new InvalidArgumentException('The recurrence count is invalid.');
            }
            $range['type'] = 'numbered';
            $range['numberOfOccurrences'] = $count;
        } elseif ($endMode === 'until') {
            $until = trim((string) ($recurrence['until'] ?? ''));
            $untilDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until) === 1
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $until, new DateTimeZone('UTC'))
                : false;
            $startDate = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $start->format('Y-m-d'),
                new DateTimeZone('UTC')
            );
            if ($untilDate === false
                || $untilDate->format('Y-m-d') !== $until
                || $startDate === false
                || $untilDate < $startDate) {
                throw new InvalidArgumentException('The recurrence end date must not be before the event start.');
            }
            $range['type'] = 'endDate';
            $range['endDate'] = $until;
        }

        return [
            'pattern' => $pattern,
            'range'   => $range
        ];
    }

    /**
     * Parses the supported subset of a Microsoft Graph patternedRecurrence object.
     *
     * Relative monthly/yearly rules and weekly rules with a non-Monday week boundary
     * are kept provider-side and return null rather than being represented lossily.
     *
     * @param array<string, mixed> $recurrence Microsoft Graph patternedRecurrence data.
     * @return array<string, mixed>|null Provider-neutral recurrence settings.
     */
    public static function fromMicrosoftRecurrence(array $recurrence, DateTimeImmutable $start): ?array
    {
        if ($recurrence === [] || array_is_list($recurrence)) {
            return null;
        }

        $pattern = is_array($recurrence['pattern'] ?? null) ? $recurrence['pattern'] : [];
        $range = is_array($recurrence['range'] ?? null) ? $recurrence['range'] : [];
        if ($pattern === [] || array_is_list($pattern) || $range === [] || array_is_list($range)) {
            return null;
        }

        $type = strtolower(trim((string) ($pattern['type'] ?? '')));
        $frequency = match ($type) {
            'daily'           => 'DAILY',
            'weekly'          => 'WEEKLY',
            'absolutemonthly' => 'MONTHLY',
            'absoluteyearly'  => 'YEARLY',
            default           => ''
        };
        if ($frequency === '') {
            return null;
        }

        $interval = filter_var(
            $pattern['interval'] ?? 1,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 999]]
        );
        if ($interval === false) {
            return null;
        }

        $result = [
            'frequency' => $frequency,
            'interval'  => $interval,
            'endMode'   => 'never'
        ];

        if ($frequency === 'WEEKLY') {
            $firstDayOfWeek = strtolower(trim((string) ($pattern['firstDayOfWeek'] ?? 'monday')));
            $daysOfWeek = $pattern['daysOfWeek'] ?? null;
            if ($firstDayOfWeek !== 'monday' || !is_array($daysOfWeek) || !array_is_list($daysOfWeek)) {
                return null;
            }

            $weekdays = [];
            foreach ($daysOfWeek as $dayOfWeek) {
                $weekday = self::MICROSOFT_WEEKDAY_CODES[strtolower(trim((string) $dayOfWeek))] ?? '';
                if ($weekday === '') {
                    return null;
                }
                $weekdays[] = $weekday;
            }
            if ($weekdays === []) {
                return null;
            }
            $result['byDay'] = self::weekdays($weekdays);
        } elseif ($frequency === 'MONTHLY') {
            if ((int) ($pattern['dayOfMonth'] ?? 0) !== (int) $start->format('j')) {
                return null;
            }
        } elseif ($frequency === 'YEARLY') {
            if ((int) ($pattern['dayOfMonth'] ?? 0) !== (int) $start->format('j')
                || (int) ($pattern['month'] ?? 0) !== (int) $start->format('n')) {
                return null;
            }
        }

        $rangeStart = trim((string) ($range['startDate'] ?? ''));
        if ($rangeStart !== '' && $rangeStart !== $start->format('Y-m-d')) {
            return null;
        }

        $rangeType = strtolower(trim((string) ($range['type'] ?? '')));
        if ($rangeType === 'numbered') {
            $count = filter_var(
                $range['numberOfOccurrences'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 9999]]
            );
            if ($count === false) {
                return null;
            }
            $result['endMode'] = 'count';
            $result['count'] = $count;
        } elseif ($rangeType === 'enddate') {
            $until = trim((string) ($range['endDate'] ?? ''));
            $untilDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until) === 1
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $until, new DateTimeZone('UTC'))
                : false;
            if ($untilDate === false || $untilDate->format('Y-m-d') !== $until) {
                return null;
            }
            $result['endMode'] = 'until';
            $result['until'] = $until;
        } elseif ($rangeType !== 'noend') {
            return null;
        }

        return $result;
    }

    /**
     * Returns the one-based position of a Microsoft occurrence in a supported recurrence pattern.
     *
     * @param array<string, mixed> $recurrence Microsoft Graph patternedRecurrence data.
     */
    public static function microsoftOccurrencePosition(array $recurrence, string $targetDate): int
    {
        [$pattern, $range, $startDate, $target] = self::microsoftRecurrenceDates($recurrence, $targetDate);
        $type = strtolower(trim((string) ($pattern['type'] ?? '')));
        $interval = filter_var(
            $pattern['interval'] ?? 1,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 999]]
        );
        if ($interval === false) {
            throw new InvalidArgumentException('The Microsoft recurrence interval is invalid.');
        }

        $position = match ($type) {
            'daily'           => self::microsoftDailyPosition($startDate, $target, $interval),
            'weekly'          => self::microsoftWeeklyPosition($pattern, $startDate, $target, $interval),
            'absolutemonthly' => self::microsoftMonthlyPosition($pattern, $startDate, $target, $interval),
            'absoluteyearly'  => self::microsoftYearlyPosition($pattern, $startDate, $target, $interval),
            default           => 0
        };
        if ($position < 1) {
            throw new InvalidArgumentException('The Microsoft recurring target occurrence is not part of the pattern.');
        }

        $rangeType = strtolower(trim((string) ($range['type'] ?? '')));
        if ($rangeType === 'numbered') {
            $count = filter_var(
                $range['numberOfOccurrences'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 9999]]
            );
            if ($count === false || $position > $count) {
                throw new InvalidArgumentException('The Microsoft recurring target occurrence is outside the series range.');
            }
        } elseif ($rangeType === 'enddate') {
            $endDate = self::strictDate(trim((string) ($range['endDate'] ?? '')));
            if ($endDate === null || $target > $endDate) {
                throw new InvalidArgumentException('The Microsoft recurring target occurrence is outside the series range.');
            }
        } elseif ($rangeType !== 'noend') {
            throw new InvalidArgumentException('The Microsoft recurrence range is invalid.');
        }

        return $position;
    }

    /**
     * Returns the number of occurrences remaining from a target in a numbered Microsoft series.
     *
     * @param array<string, mixed> $recurrence Microsoft Graph patternedRecurrence data.
     */
    public static function remainingMicrosoftOccurrenceCount(array $recurrence, string $targetDate): int
    {
        $range = is_array($recurrence['range'] ?? null) ? $recurrence['range'] : [];
        if (strtolower(trim((string) ($range['type'] ?? ''))) !== 'numbered') {
            throw new InvalidArgumentException('The Microsoft recurrence range is not numbered.');
        }
        $count = filter_var(
            $range['numberOfOccurrences'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 9999]]
        );
        if ($count === false) {
            throw new InvalidArgumentException('The Microsoft recurrence count is invalid.');
        }

        return $count - self::microsoftOccurrencePosition($recurrence, $targetDate) + 1;
    }

    /**
     * Shortens a Microsoft recurrence so it ends before the selected occurrence.
     *
     * @param array<string, mixed> $recurrence Microsoft Graph patternedRecurrence data.
     * @return array<string, mixed> Microsoft Graph recurrence ending before the target.
     */
    public static function trimMicrosoftRecurrenceBefore(array $recurrence, string $targetDate): array
    {
        if (self::microsoftOccurrencePosition($recurrence, $targetDate) <= 1) {
            throw new InvalidArgumentException('The Microsoft recurrence cannot be shortened before its first occurrence.');
        }
        $pattern = is_array($recurrence['pattern'] ?? null) ? $recurrence['pattern'] : [];
        $range = is_array($recurrence['range'] ?? null) ? $recurrence['range'] : [];
        $startDate = trim((string) ($range['startDate'] ?? ''));
        $target = self::strictDate($targetDate);
        if ($pattern === [] || array_is_list($pattern) || $target === null) {
            throw new InvalidArgumentException('The Microsoft recurrence settings are invalid.');
        }

        $trimmedRange = [
            'type'      => 'endDate',
            'startDate' => $startDate,
            'endDate'   => $target->modify('-1 day')->format('Y-m-d')
        ];
        $recurrenceTimezone = trim((string) ($range['recurrenceTimeZone'] ?? ''));
        if ($recurrenceTimezone !== '') {
            $trimmedRange['recurrenceTimeZone'] = $recurrenceTimezone;
        }

        return [
            'pattern' => $pattern,
            'range'   => $trimmedRange
        ];
    }

    /**
     * Parses the supported subset of a Google Calendar RRULE for the recurrence editor.
     *
     * Unsupported or more complex rules return null so callers can preserve the original
     * provider rule without exposing a lossy editor representation.
     *
     * @return array<string, mixed>|null Provider-neutral recurrence settings.
     */
    public static function fromGoogleRule(
        string $rule,
        bool $allDay,
        string $timezone
    ): ?array {
        $rule = strtoupper(trim($rule));
        if (str_starts_with($rule, 'RRULE:')) {
            $rule = substr($rule, 6);
        }
        if ($rule === '') {
            return null;
        }

        $values = [];
        foreach (explode(';', $rule) as $part) {
            $separator = strpos($part, '=');
            if ($separator === false) {
                return null;
            }
            $key = trim(substr($part, 0, $separator));
            $value = trim(substr($part, $separator + 1));
            if ($key === '' || $value === '' || isset($values[$key])) {
                return null;
            }
            if (!in_array($key, ['FREQ', 'INTERVAL', 'BYDAY', 'COUNT', 'UNTIL'], true)) {
                return null;
            }
            $values[$key] = $value;
        }

        $frequency = $values['FREQ'] ?? '';
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            return null;
        }

        $interval = filter_var(
            $values['INTERVAL'] ?? 1,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 999]]
        );
        if ($interval === false) {
            return null;
        }

        $result = [
            'frequency' => $frequency,
            'interval'  => $interval,
            'endMode'   => 'never'
        ];

        if (isset($values['BYDAY'])) {
            if ($frequency !== 'WEEKLY') {
                return null;
            }
            $byDay = explode(',', $values['BYDAY']);
            if ($byDay === [] || array_filter(
                $byDay,
                static fn (string $weekday): bool => !in_array($weekday, self::WEEKDAYS, true)
            ) !== []) {
                return null;
            }
            $result['byDay'] = self::weekdays($byDay);
        } elseif ($frequency === 'WEEKLY') {
            $result['byDay'] = [];
        }

        if (isset($values['COUNT']) && isset($values['UNTIL'])) {
            return null;
        }
        if (isset($values['COUNT'])) {
            $count = filter_var(
                $values['COUNT'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 9999]]
            );
            if ($count === false) {
                return null;
            }
            $result['endMode'] = 'count';
            $result['count'] = $count;
        } elseif (isset($values['UNTIL'])) {
            $until = self::untilDate($values['UNTIL'], $allDay, $timezone);
            if ($until === null) {
                return null;
            }
            $result['endMode'] = 'until';
            $result['until'] = $until;
        }

        return $result;
    }

    /**
     * Trims one supported Google RRULE so it ends before the target occurrence.
     *
     * COUNT is replaced by an UNTIL value because the original series is being
     * shortened at a concrete occurrence boundary.
     */
    public static function trimGoogleRuleBefore(
        string $rule,
        string $targetOriginalStart,
        bool $allDay,
        string $timezone
    ): string {
        if (self::fromGoogleRule($rule, $allDay, $timezone) === null) {
            throw new InvalidArgumentException('The recurrence pattern cannot be split safely.');
        }

        $rule = strtoupper(trim($rule));
        if (str_starts_with($rule, 'RRULE:')) {
            $rule = substr($rule, 6);
        }

        $parts = array_values(array_filter(
            explode(';', $rule),
            static fn (string $part): bool => !str_starts_with($part, 'COUNT=')
                && !str_starts_with($part, 'UNTIL=')
        ));
        $parts[] = 'UNTIL=' . self::cutoffValue($targetOriginalStart, $allDay, $timezone);

        return 'RRULE:' . implode(';', $parts);
    }

    /**
     * @param array<string, mixed> $recurrence
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: DateTimeImmutable, 3: DateTimeImmutable}
     */
    private static function microsoftRecurrenceDates(array $recurrence, string $targetDate): array
    {
        if ($recurrence === [] || array_is_list($recurrence)) {
            throw new InvalidArgumentException('The Microsoft recurrence settings are invalid.');
        }
        $pattern = is_array($recurrence['pattern'] ?? null) ? $recurrence['pattern'] : [];
        $range = is_array($recurrence['range'] ?? null) ? $recurrence['range'] : [];
        if ($pattern === [] || array_is_list($pattern) || $range === [] || array_is_list($range)) {
            throw new InvalidArgumentException('The Microsoft recurrence settings are invalid.');
        }

        $startDate = self::strictDate(trim((string) ($range['startDate'] ?? '')));
        $target = self::strictDate($targetDate);
        if ($startDate === null || $target === null || $target < $startDate) {
            throw new InvalidArgumentException('The Microsoft recurring target date is invalid.');
        }

        return [$pattern, $range, $startDate, $target];
    }

    private static function microsoftDailyPosition(
        DateTimeImmutable $startDate,
        DateTimeImmutable $target,
        int $interval
    ): int {
        $days = (int) $startDate->diff($target)->format('%a');
        return $days % $interval === 0 ? intdiv($days, $interval) + 1 : 0;
    }

    /** @param array<string, mixed> $pattern */
    private static function microsoftWeeklyPosition(
        array $pattern,
        DateTimeImmutable $startDate,
        DateTimeImmutable $target,
        int $interval
    ): int {
        if (strtolower(trim((string) ($pattern['firstDayOfWeek'] ?? 'monday'))) !== 'monday') {
            return 0;
        }
        $daysOfWeek = $pattern['daysOfWeek'] ?? null;
        if (!is_array($daysOfWeek) || !array_is_list($daysOfWeek)) {
            return 0;
        }
        $weekdays = [];
        foreach ($daysOfWeek as $dayOfWeek) {
            $code = self::MICROSOFT_WEEKDAY_CODES[strtolower(trim((string) $dayOfWeek))] ?? '';
            $index = array_search($code, self::WEEKDAYS, true);
            if ($index === false) {
                return 0;
            }
            $weekdays[] = $index + 1;
        }
        $weekdays = array_values(array_unique($weekdays));
        sort($weekdays, SORT_NUMERIC);
        if ($weekdays === []) {
            return 0;
        }

        $startMonday = $startDate->modify('-' . ((int) $startDate->format('N') - 1) . ' days');
        $targetMonday = $target->modify('-' . ((int) $target->format('N') - 1) . ' days');
        $weekIndex = intdiv((int) $startMonday->diff($targetMonday)->format('%a'), 7);
        if ($weekIndex % $interval !== 0 || !in_array((int) $target->format('N'), $weekdays, true)) {
            return 0;
        }

        $activeWeeks = intdiv($weekIndex, $interval);
        $position = 0;
        for ($activeWeek = 0; $activeWeek <= $activeWeeks; ++$activeWeek) {
            $weekStart = $startMonday->modify('+' . ($activeWeek * $interval) . ' weeks');
            foreach ($weekdays as $weekday) {
                $candidate = $weekStart->modify('+' . ($weekday - 1) . ' days');
                if ($candidate < $startDate) {
                    continue;
                }
                if ($candidate > $target) {
                    return $position;
                }
                ++$position;
                if ($candidate == $target) {
                    return $position;
                }
                if ($position > 9999) {
                    return 0;
                }
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $pattern */
    private static function microsoftMonthlyPosition(
        array $pattern,
        DateTimeImmutable $startDate,
        DateTimeImmutable $target,
        int $interval
    ): int {
        $dayOfMonth = (int) ($pattern['dayOfMonth'] ?? 0);
        if ($dayOfMonth < 1 || $dayOfMonth > 31) {
            return 0;
        }

        $position = 0;
        for ($offset = 0; $position <= 9999; $offset += $interval) {
            $month = $startDate->modify('first day of this month')->modify('+' . $offset . ' months');
            $candidate = self::dateWithDay($month, $dayOfMonth);
            if ($candidate === null || $candidate < $startDate) {
                if ($month > $target) {
                    return 0;
                }
                continue;
            }
            if ($candidate > $target) {
                return 0;
            }
            ++$position;
            if ($candidate == $target) {
                return $position;
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $pattern */
    private static function microsoftYearlyPosition(
        array $pattern,
        DateTimeImmutable $startDate,
        DateTimeImmutable $target,
        int $interval
    ): int {
        $dayOfMonth = (int) ($pattern['dayOfMonth'] ?? 0);
        $monthNumber = (int) ($pattern['month'] ?? 0);
        if ($dayOfMonth < 1 || $dayOfMonth > 31 || $monthNumber < 1 || $monthNumber > 12) {
            return 0;
        }

        $position = 0;
        for ($offset = 0; $position <= 9999; $offset += $interval) {
            $year = (int) $startDate->format('Y') + $offset;
            $month = DateTimeImmutable::createFromFormat(
                '!Y-n-j',
                $year . '-' . $monthNumber . '-1',
                new DateTimeZone('UTC')
            );
            if ($month === false) {
                return 0;
            }
            $candidate = self::dateWithDay($month, $dayOfMonth);
            if ($candidate === null || $candidate < $startDate) {
                if ($month > $target) {
                    return 0;
                }
                continue;
            }
            if ($candidate > $target) {
                return 0;
            }
            ++$position;
            if ($candidate == $target) {
                return $position;
            }
        }

        return 0;
    }

    private static function dateWithDay(DateTimeImmutable $month, int $dayOfMonth): ?DateTimeImmutable
    {
        $value = sprintf('%s-%02d', $month->format('Y-m'), $dayOfMonth);
        return self::strictDate($value);
    }

    private static function strictDate(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
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

    private static function cutoffValue(string $targetOriginalStart, bool $allDay, string $timezone): string
    {
        $targetOriginalStart = trim($targetOriginalStart);
        if ($allDay) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $targetOriginalStart) !== 1) {
                throw new InvalidArgumentException('The recurring target start is invalid.');
            }
            $target = DateTimeImmutable::createFromFormat('!Y-m-d', $targetOriginalStart, new DateTimeZone('UTC'));
            if ($target === false || $target->format('Y-m-d') !== $targetOriginalStart) {
                throw new InvalidArgumentException('The recurring target start is invalid.');
            }

            return $target->modify('-1 day')->format('Ymd');
        }

        try {
            $target = new DateTimeImmutable($targetOriginalStart, self::timezone($timezone));
        } catch (Throwable) {
            throw new InvalidArgumentException('The recurring target start is invalid.');
        }

        return $target->setTimezone(new DateTimeZone('UTC'))->modify('-1 second')->format('Ymd\THis\Z');
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

    private static function untilDate(string $value, bool $allDay, string $timezone): ?string
    {
        if ($allDay) {
            $date = DateTimeImmutable::createFromFormat('!Ymd', $value, new DateTimeZone('UTC'));
            return $date !== false && $date->format('Ymd') === $value
                ? $date->format('Y-m-d')
                : null;
        }

        if (preg_match('/^\d{8}T\d{6}Z$/D', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Ymd\THis\Z') !== $value) {
            return null;
        }

        try {
            return $date->setTimezone(self::timezone($timezone))->format('Y-m-d');
        } catch (InvalidArgumentException) {
            return null;
        }
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
