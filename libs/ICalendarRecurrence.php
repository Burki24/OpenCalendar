<?php

declare(strict_types=1);

namespace IPSKalender;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

require_once __DIR__ . '/CalendarEventRecurrence.php';

final class ICalendarRecurrence
{
    private const MAX_GENERATED_DAYS = 200_000;
    private const SUPPORTED_FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
    private const SUPPORTED_RULE_PARTS = [
        'FREQ',
        'UNTIL',
        'COUNT',
        'INTERVAL',
        'BYDAY',
        'BYMONTHDAY',
        'BYYEARDAY',
        'BYWEEKNO',
        'BYMONTH',
        'BYSETPOS',
        'WKST'
    ];
    private const SINGLE_VALUE_RULE_PARTS = ['FREQ', 'UNTIL', 'COUNT', 'INTERVAL', 'WKST'];

    /**
     * Expands recurring event masters, exceptions, and overrides within a time range.
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public static function expand(array $events, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): array
    {
        if ($rangeEnd <= $rangeStart) {
            return [];
        }

        $groups = [];
        foreach ($events as $event) {
            $uid = trim((string) ($event['uid'] ?? ''));
            if ($uid !== '') {
                $groups[$uid][] = $event;
            }
        }

        $result = [];
        foreach ($groups as $group) {
            array_push($result, ...self::expandGroup($group, $rangeStart, $rangeEnd));
        }

        usort(
            $result,
            static fn (array $left, array $right): int => ((int) $left['startTimestamp'] <=> (int) $right['startTimestamp'])
                ?: strcasecmp((string) ($left['summary'] ?? ''), (string) ($right['summary'] ?? ''))
        );

        return $result;
    }

    /**
     * Builds privacy-conscious recurrence diagnostics from already expanded events.
     *
     * The summary intentionally omits event identity, titles, descriptions, locations,
     * and resource URLs. Equivalent rules are grouped so account-level debug output
     * stays compact even when several calendars contain the same recurrence pattern.
     *
     * @param list<array<string, mixed>> $events
     * @return array{
     *     seriesCount: int,
     *     supportedSeriesCount: int,
     *     unsupportedSeriesCount: int,
     *     rules: list<array{
     *         rule: string,
     *         timezone: string,
     *         supported: bool,
     *         unsupportedParts: list<string>,
     *         seriesCount: int,
     *         occurrencesInRange: int,
     *         overrideOccurrencesInRange: int,
     *         rDateCount: int,
     *         exDateCount: int
     *     }>
     * }
     */
    public static function diagnostics(array $events): array
    {
        $series = [];
        foreach ($events as $event) {
            $rule = strtoupper(trim((string) ($event['recurrenceRule'] ?? '')));
            $recurrenceDates = is_array($event['recurrenceDates'] ?? null)
                ? $event['recurrenceDates']
                : [];
            $exceptionDates = is_array($event['exceptionDates'] ?? null)
                ? $event['exceptionDates']
                : [];
            $recurrenceType = strtolower(trim((string) ($event['recurrenceType'] ?? '')));
            if ($rule === ''
                && $recurrenceDates === []
                && $exceptionDates === []
                && !(bool) ($event['recurring'] ?? false)
                && !in_array($recurrenceType, ['master', 'occurrence', 'exception'], true)) {
                continue;
            }

            $seriesId = trim((string) ($event['seriesId'] ?? ''));
            if ($seriesId === '') {
                $seriesId = trim((string) ($event['uid'] ?? ''));
            }
            $timezone = trim((string) ($event['timezone'] ?? ''));
            $seriesKey = $seriesId !== ''
                ? 'series:' . $seriesId
                : 'fallback:' . hash(
                    'sha256',
                    $rule . '|' . $timezone . '|' . (string) ($event['startTimestamp'] ?? '')
                );

            if (!isset($series[$seriesKey])) {
                $series[$seriesKey] = [
                    'rule'                       => $rule,
                    'timezone'                   => $timezone,
                    'supported'                  => (bool) ($event['recurrenceExpansionSupported'] ?? true),
                    'unsupportedParts'           => [],
                    'occurrencesInRange'         => 0,
                    'overrideOccurrencesInRange' => 0,
                    'rDateCount'                 => count($recurrenceDates),
                    'exDateCount'                => count($exceptionDates)
                ];
            }

            if ($series[$seriesKey]['rule'] === '' && $rule !== '') {
                $series[$seriesKey]['rule'] = $rule;
            }
            if ($series[$seriesKey]['timezone'] === '' && $timezone !== '') {
                $series[$seriesKey]['timezone'] = $timezone;
            }
            if (array_key_exists('recurrenceExpansionSupported', $event)
                && !(bool) $event['recurrenceExpansionSupported']) {
                $series[$seriesKey]['supported'] = false;
            }

            $unsupportedParts = is_array($event['recurrenceUnsupportedRuleParts'] ?? null)
                ? array_values(array_filter(
                    array_map(
                        static fn (mixed $part): string => strtoupper(trim((string) $part)),
                        $event['recurrenceUnsupportedRuleParts']
                    ),
                    static fn (string $part): bool => $part !== ''
                ))
                : [];
            $series[$seriesKey]['unsupportedParts'] = array_values(array_unique(array_merge(
                $series[$seriesKey]['unsupportedParts'],
                $unsupportedParts
            )));
            sort($series[$seriesKey]['unsupportedParts'], SORT_STRING);
            $series[$seriesKey]['rDateCount'] = max(
                $series[$seriesKey]['rDateCount'],
                count($recurrenceDates)
            );
            $series[$seriesKey]['exDateCount'] = max(
                $series[$seriesKey]['exDateCount'],
                count($exceptionDates)
            );
            ++$series[$seriesKey]['occurrencesInRange'];
            if ($recurrenceType === CalendarEventRecurrence::EXCEPTION) {
                ++$series[$seriesKey]['overrideOccurrencesInRange'];
            }
        }

        $rules = [];
        $supportedSeriesCount = 0;
        foreach ($series as $entry) {
            if ($entry['supported']) {
                ++$supportedSeriesCount;
            }
            $signature = hash(
                'sha256',
                $entry['rule'] . "\0"
                    . $entry['timezone'] . "\0"
                    . ($entry['supported'] ? '1' : '0') . "\0"
                    . implode(',', $entry['unsupportedParts'])
            );
            if (!isset($rules[$signature])) {
                $rules[$signature] = [
                    'rule'                       => $entry['rule'] !== '' ? $entry['rule'] : 'RDATE',
                    'timezone'                   => $entry['timezone'],
                    'supported'                  => $entry['supported'],
                    'unsupportedParts'           => $entry['unsupportedParts'],
                    'seriesCount'                => 0,
                    'occurrencesInRange'         => 0,
                    'overrideOccurrencesInRange' => 0,
                    'rDateCount'                 => 0,
                    'exDateCount'                => 0
                ];
            }
            ++$rules[$signature]['seriesCount'];
            $rules[$signature]['occurrencesInRange'] += $entry['occurrencesInRange'];
            $rules[$signature]['overrideOccurrencesInRange'] += $entry['overrideOccurrencesInRange'];
            $rules[$signature]['rDateCount'] += $entry['rDateCount'];
            $rules[$signature]['exDateCount'] += $entry['exDateCount'];
        }

        $rules = array_values($rules);
        usort(
            $rules,
            static fn (array $left, array $right): int => strcmp($left['rule'], $right['rule'])
                ?: strcmp($left['timezone'], $right['timezone'])
                ?: ((int) $right['supported'] <=> (int) $left['supported'])
        );

        return [
            'seriesCount'            => count($series),
            'supportedSeriesCount'   => $supportedSeriesCount,
            'unsupportedSeriesCount' => count($series) - $supportedSeriesCount,
            'rules'                  => $rules
        ];
    }

    /**
     * @param list<array<string, mixed>> $group
     * @return list<array<string, mixed>>
     */
    private static function expandGroup(
        array $group,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd
    ): array {
        $masters = [];
        $overrides = [];
        foreach ($group as $event) {
            $recurrenceTimestamp = $event['recurrenceIdTimestamp'] ?? null;
            if (is_int($recurrenceTimestamp)) {
                if (!isset($overrides[$recurrenceTimestamp])
                    || self::isNewerEvent($event, $overrides[$recurrenceTimestamp])) {
                    $overrides[$recurrenceTimestamp] = $event;
                }
            } else {
                $masters[] = $event;
            }
        }

        if ($masters === []) {
            return array_values(array_filter(
                $overrides,
                static fn (array $event): bool => !self::isCancelled($event)
                    && self::overlapsRange($event, $rangeStart, $rangeEnd)
            ));
        }

        $master = array_shift($masters);
        foreach ($masters as $candidate) {
            if (self::isNewerEvent($candidate, $master)) {
                $master = $candidate;
            }
        }
        if (self::isCancelled($master)) {
            return [];
        }

        $result = [];
        $usedOverrides = [];
        $recurrenceExpansionState = ['recurrenceExpansionSupported' => true];
        foreach ([$master] as $master) {
            $rule = trim((string) ($master['recurrenceRule'] ?? ''));
            $recurrenceDates = is_array($master['recurrenceDates'] ?? null) ? $master['recurrenceDates'] : [];
            if ($rule === '' && $recurrenceDates === []) {
                if (!self::isCancelled($master) && self::overlapsRange($master, $rangeStart, $rangeEnd)) {
                    $result[] = $master;
                }
                continue;
            }

            $expansion = self::generateRuleStarts($master, $rule, $rangeEnd);
            $starts = $expansion['starts'];
            $recurrenceExpansionState = ['recurrenceExpansionSupported' => $expansion['supported']];
            if ($expansion['unsupportedParts'] !== []) {
                $recurrenceExpansionState['recurrenceUnsupportedRuleParts'] = $expansion['unsupportedParts'];
            }
            $master = array_merge($master, $recurrenceExpansionState);
            foreach ($recurrenceDates as $recurrenceDate) {
                if (is_array($recurrenceDate) && isset($recurrenceDate['timestamp'])) {
                    $starts[(int) $recurrenceDate['timestamp']] = self::dateAtTimestamp(
                        (int) $recurrenceDate['timestamp'],
                        (string) ($master['timezone'] ?? 'UTC')
                    );
                }
            }
            ksort($starts);

            $exceptions = [];
            foreach ((array) ($master['exceptionDates'] ?? []) as $exception) {
                if (is_array($exception) && isset($exception['timestamp'])) {
                    $exceptions[(int) $exception['timestamp']] = true;
                }
            }

            foreach ($starts as $originalTimestamp => $occurrenceStart) {
                if (isset($overrides[$originalTimestamp])) {
                    $usedOverrides[$originalTimestamp] = true;
                    $override = $overrides[$originalTimestamp];
                    if (!self::isCancelled($override)
                        && self::overlapsRange($override, $rangeStart, $rangeEnd)) {
                        $override = array_merge(
                            $override,
                            $recurrenceExpansionState,
                            CalendarEventRecurrence::occurrence(
                                (string) ($override['uid'] ?? ''),
                                (string) ($override['uid'] ?? '') . '|' . (string) ($override['recurrenceId'] ?? ''),
                                (string) ($override['originalStart'] ?? ''),
                                (string) ($override['recurrenceId'] ?? ''),
                                false,
                                true
                            )
                        );
                        $result[] = $override;
                    }
                    continue;
                }
                if (isset($exceptions[$originalTimestamp])) {
                    continue;
                }

                $occurrence = self::createOccurrence($master, $occurrenceStart);
                if (self::overlapsRange($occurrence, $rangeStart, $rangeEnd)) {
                    $result[] = $occurrence;
                }
            }
        }

        foreach ($overrides as $recurrenceTimestamp => $override) {
            if (!isset($usedOverrides[$recurrenceTimestamp])
                && !self::isCancelled($override)
                && self::overlapsRange($override, $rangeStart, $rangeEnd)) {
                $override = array_merge(
                    $override,
                    $recurrenceExpansionState,
                    CalendarEventRecurrence::occurrence(
                        (string) ($override['uid'] ?? ''),
                        (string) ($override['uid'] ?? '') . '|' . (string) ($override['recurrenceId'] ?? ''),
                        (string) ($override['originalStart'] ?? ''),
                        (string) ($override['recurrenceId'] ?? ''),
                        false,
                        true
                    )
                );
                $result[] = $override;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $master
     * @return array{
     *     starts: array<int, DateTimeImmutable>,
     *     supported: bool,
     *     unsupportedParts: list<string>
     * }
     */
    private static function generateRuleStarts(
        array $master,
        string $ruleText,
        DateTimeImmutable $rangeEnd
    ): array {
        $timezone = self::timezone((string) ($master['timezone'] ?? 'UTC'));
        $seriesStart = (new DateTimeImmutable('@' . (int) $master['startTimestamp']))->setTimezone($timezone);
        $starts = [$seriesStart->getTimestamp() => $seriesStart];
        if ($ruleText === '') {
            return [
                'starts'           => $starts,
                'supported'        => true,
                'unsupportedParts' => []
            ];
        }

        $analysis = self::analyzeRule($ruleText, $timezone);
        if (!$analysis['supported']) {
            return [
                'starts'           => $starts,
                'supported'        => false,
                'unsupportedParts' => $analysis['unsupportedParts']
            ];
        }

        $rule = $analysis['rule'];
        $frequency = $rule['FREQ'][0];
        $countLimit = isset($rule['COUNT'][0]) ? (int) $rule['COUNT'][0] : null;
        $until = self::parseUntil($rule['UNTIL'][0] ?? '', $timezone);
        $day = $seriesStart->setTime(0, 0);
        $lastDay = $rangeEnd->setTimezone($timezone)->setTime(0, 0)->add(new DateInterval('P1D'));
        if ($until !== null && $until < $lastDay) {
            $lastDay = $until->setTimezone($timezone)->setTime(0, 0)->add(new DateInterval('P1D'));
        }

        $occurrenceCount = 0;
        $iterations = 0;
        while ($day <= $lastDay && $iterations++ < self::MAX_GENERATED_DAYS) {
            if (self::matchesRuleDate($day, $seriesStart, $rule, $frequency)) {
                $candidate = $day->setTime(
                    (int) $seriesStart->format('H'),
                    (int) $seriesStart->format('i'),
                    (int) $seriesStart->format('s')
                );
                if ($candidate >= $seriesStart && ($until === null || $candidate <= $until)) {
                    $occurrenceCount++;
                    if ($countLimit === null || $occurrenceCount <= $countLimit) {
                        $starts[$candidate->getTimestamp()] = $candidate;
                    }
                    if ($countLimit !== null && $occurrenceCount >= $countLimit) {
                        break;
                    }
                }
            }
            $day = $day->add(new DateInterval('P1D'));
        }

        return [
            'starts'           => $starts,
            'supported'        => true,
            'unsupportedParts' => []
        ];
    }

    /**
     * @param array<string, list<string>> $rule
     */
    private static function matchesRuleDate(
        DateTimeImmutable $date,
        DateTimeImmutable $seriesStart,
        array $rule,
        string $frequency,
        bool $applySetPosition = true
    ): bool {
        $interval = isset($rule['INTERVAL'][0]) ? max(1, (int) $rule['INTERVAL'][0]) : 1;
        $months = self::integerValues($rule['BYMONTH'] ?? []);
        if ($months !== [] && !in_array((int) $date->format('n'), $months, true)) {
            return false;
        }

        $matchesFrequency = match ($frequency) {
            'DAILY'   => self::calendarDayDifference($seriesStart, $date) % $interval === 0,
            'WEEKLY'  => self::matchesWeeklyInterval($date, $seriesStart, $rule, $interval),
            'MONTHLY' => self::calendarMonthDifference($seriesStart, $date) % $interval === 0,
            'YEARLY'  => self::matchesYearlyInterval($date, $seriesStart, $rule, $interval),
            default   => false
        };
        if (!$matchesFrequency) {
            return false;
        }

        $yearDays = self::integerValues($rule['BYYEARDAY'] ?? []);
        if ($yearDays !== [] && !self::matchesYearDay($date, $yearDays)) {
            return false;
        }

        $weekNumbers = self::integerValues($rule['BYWEEKNO'] ?? []);
        if ($weekNumbers !== [] && !self::matchesWeekNumber(
            $date,
            $weekNumbers,
            self::weekdayNumber($rule['WKST'][0] ?? 'MO')
        )) {
            return false;
        }

        $monthDays = self::integerValues($rule['BYMONTHDAY'] ?? []);
        if ($monthDays !== [] && !self::matchesMonthDay($date, $monthDays)) {
            return false;
        }

        $byDays = self::stringValues($rule['BYDAY'] ?? []);
        if ($byDays !== [] && !self::matchesByDay($date, $byDays, $frequency, $months !== [])) {
            return false;
        }

        if ($frequency === 'WEEKLY' && $byDays === []
            && (int) $date->format('N') !== (int) $seriesStart->format('N')) {
            return false;
        }
        if ($frequency === 'MONTHLY' && $monthDays === [] && $byDays === []
            && (int) $date->format('j') !== (int) $seriesStart->format('j')) {
            return false;
        }
        if ($frequency === 'YEARLY') {
            $hasExpandedDaySelector = $weekNumbers !== []
                || $yearDays !== []
                || $monthDays !== []
                || $byDays !== [];
            if ($months === [] && !$hasExpandedDaySelector
                && (int) $date->format('n') !== (int) $seriesStart->format('n')) {
                return false;
            }
            if (!$hasExpandedDaySelector
                && (int) $date->format('j') !== (int) $seriesStart->format('j')) {
                return false;
            }
        }

        $setPositions = self::integerValues($rule['BYSETPOS'] ?? []);
        return !$applySetPosition
            || $setPositions === []
            || self::matchesSetPosition($date, $seriesStart, $rule, $frequency, $setPositions);
    }

    /**
     * @param array<string, list<string>> $rule
     */
    private static function matchesYearlyInterval(
        DateTimeImmutable $date,
        DateTimeImmutable $seriesStart,
        array $rule,
        int $interval
    ): bool {
        $year = (int) $date->format('Y');
        $seriesYear = (int) $seriesStart->format('Y');
        if (isset($rule['BYWEEKNO'])) {
            $weekStart = self::weekdayNumber($rule['WKST'][0] ?? 'MO');
            [$year] = self::weekYearAndNumber($date, $weekStart);
            [$seriesYear] = self::weekYearAndNumber($seriesStart, $weekStart);
        }
        $years = $year - $seriesYear;

        return $years >= 0 && $years % $interval === 0;
    }

    /**
     * @param array<string, list<string>> $rule
     */
    private static function matchesWeeklyInterval(
        DateTimeImmutable $date,
        DateTimeImmutable $seriesStart,
        array $rule,
        int $interval
    ): bool {
        $weekStart = self::weekdayNumber($rule['WKST'][0] ?? 'MO');
        $dateWeek = self::startOfWeek($date, $weekStart);
        $seriesWeek = self::startOfWeek($seriesStart, $weekStart);
        $weeks = intdiv(self::calendarDayDifference($seriesWeek, $dateWeek), 7);

        return $weeks >= 0 && $weeks % $interval === 0;
    }

    /**
     * @param list<string> $byDays
     */
    private static function matchesByDay(
        DateTimeImmutable $date,
        array $byDays,
        string $frequency,
        bool $hasByMonth
    ): bool {
        foreach ($byDays as $value) {
            if (preg_match('/^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/', strtoupper($value), $matches) !== 1) {
                continue;
            }
            $ordinal = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
            $weekday = self::weekdayNumber($matches[2]);
            if ((int) $date->format('N') !== $weekday) {
                continue;
            }
            if ($ordinal === 0 || $frequency === 'DAILY' || $frequency === 'WEEKLY') {
                return true;
            }
            if ($frequency === 'YEARLY' && !$hasByMonth) {
                if (self::matchesWeekdayOrdinalInYear($date, $ordinal)) {
                    return true;
                }
                continue;
            }
            if (self::matchesWeekdayOrdinalInMonth($date, $ordinal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $monthDays
     */
    private static function matchesMonthDay(DateTimeImmutable $date, array $monthDays): bool
    {
        $day = (int) $date->format('j');
        $daysInMonth = (int) $date->format('t');
        foreach ($monthDays as $monthDay) {
            $normalized = $monthDay < 0 ? $daysInMonth + $monthDay + 1 : $monthDay;
            if ($normalized === $day) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $yearDays
     */
    private static function matchesYearDay(DateTimeImmutable $date, array $yearDays): bool
    {
        $day = (int) $date->format('z') + 1;
        $daysInYear = $date->format('L') === '1' ? 366 : 365;
        foreach ($yearDays as $yearDay) {
            $normalized = $yearDay < 0 ? $daysInYear + $yearDay + 1 : $yearDay;
            if ($normalized === $day) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $weekNumbers
     */
    private static function matchesWeekNumber(
        DateTimeImmutable $date,
        array $weekNumbers,
        int $weekStart
    ): bool {
        [$weekYear, $weekNumber] = self::weekYearAndNumber($date, $weekStart);
        $weeksInYear = self::weeksInWeekYear($date, $weekYear, $weekStart);
        foreach ($weekNumbers as $requestedWeek) {
            $normalized = $requestedWeek < 0
                ? $weeksInYear + $requestedWeek + 1
                : $requestedWeek;
            if ($normalized === $weekNumber) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function weekYearAndNumber(DateTimeImmutable $date, int $weekStart): array
    {
        $calendarYear = (int) $date->format('Y');
        $weekYear = $calendarYear;
        $firstWeek = self::firstWeekStart($date, $calendarYear, $weekStart);
        if ($date->setTime(0, 0) < $firstWeek) {
            --$weekYear;
            $firstWeek = self::firstWeekStart($date, $weekYear, $weekStart);
        } else {
            $nextFirstWeek = self::firstWeekStart($date, $calendarYear + 1, $weekStart);
            if ($date->setTime(0, 0) >= $nextFirstWeek) {
                ++$weekYear;
                $firstWeek = $nextFirstWeek;
            }
        }

        $week = intdiv(
            self::calendarDayDifference($firstWeek, self::startOfWeek($date, $weekStart)),
            7
        ) + 1;

        return [$weekYear, $week];
    }

    private static function weeksInWeekYear(
        DateTimeImmutable $date,
        int $year,
        int $weekStart
    ): int {
        return intdiv(
            self::calendarDayDifference(
                self::firstWeekStart($date, $year, $weekStart),
                self::firstWeekStart($date, $year + 1, $weekStart)
            ),
            7
        );
    }

    private static function firstWeekStart(
        DateTimeImmutable $date,
        int $year,
        int $weekStart
    ): DateTimeImmutable {
        return self::startOfWeek($date->setDate($year, 1, 4)->setTime(0, 0), $weekStart);
    }

    /**
     * @param array<string, list<string>> $rule
     * @param list<int> $setPositions
     */
    private static function matchesSetPosition(
        DateTimeImmutable $date,
        DateTimeImmutable $seriesStart,
        array $rule,
        string $frequency,
        array $setPositions
    ): bool {
        [$periodStart, $periodEnd] = match ($frequency) {
            'WEEKLY'  => [
                self::startOfWeek($date, self::weekdayNumber($rule['WKST'][0] ?? 'MO')),
                self::startOfWeek($date, self::weekdayNumber($rule['WKST'][0] ?? 'MO'))->add(new DateInterval('P7D'))
            ],
            'MONTHLY' => [
                $date->modify('first day of this month')->setTime(0, 0),
                $date->modify('first day of next month')->setTime(0, 0)
            ],
            'YEARLY'  => self::yearlySetPositionPeriod($date, $rule),
            default   => [$date->setTime(0, 0), $date->setTime(0, 0)->add(new DateInterval('P1D'))]
        };

        $matches = [];
        $candidate = $periodStart;
        while ($candidate < $periodEnd) {
            if ($candidate >= $seriesStart->setTime(0, 0)
                && self::matchesRuleDate($candidate, $seriesStart, $rule, $frequency, false)) {
                $matches[] = $candidate->format('Y-m-d');
            }
            $candidate = $candidate->add(new DateInterval('P1D'));
        }
        $position = array_search($date->format('Y-m-d'), $matches, true);
        if ($position === false) {
            return false;
        }

        $positivePosition = $position + 1;
        $negativePosition = $position - count($matches);
        return in_array($positivePosition, $setPositions, true)
            || in_array($negativePosition, $setPositions, true);
    }

    /**
     * @param array<string, list<string>> $rule
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    private static function yearlySetPositionPeriod(DateTimeImmutable $date, array $rule): array
    {
        if (isset($rule['BYWEEKNO'])) {
            $weekStart = self::weekdayNumber($rule['WKST'][0] ?? 'MO');
            [$weekYear] = self::weekYearAndNumber($date, $weekStart);

            return [
                self::firstWeekStart($date, $weekYear, $weekStart),
                self::firstWeekStart($date, $weekYear + 1, $weekStart)
            ];
        }

        $year = (int) $date->format('Y');

        return [
            $date->setDate($year, 1, 1)->setTime(0, 0),
            $date->setDate($year + 1, 1, 1)->setTime(0, 0)
        ];
    }

    /**
     * @param array<string, mixed> $master
     * @return array<string, mixed>
     */
    private static function createOccurrence(array $master, DateTimeImmutable $occurrenceStart): array
    {
        $occurrence = $master;
        $allDay = (bool) ($master['allDay'] ?? false);
        $timezone = self::timezone((string) ($master['timezone'] ?? 'UTC'));
        $masterStart = (new DateTimeImmutable('@' . (int) $master['startTimestamp']))->setTimezone($timezone);
        $masterEnd = (new DateTimeImmutable('@' . (int) $master['endTimestamp']))->setTimezone($timezone);

        if ($allDay) {
            $durationDays = max(1, (int) $masterStart->diff($masterEnd)->format('%a'));
            $occurrenceEnd = $occurrenceStart->add(new DateInterval('P' . $durationDays . 'D'));
            $occurrence['start'] = $occurrenceStart->format('Y-m-d');
            $occurrence['end'] = $occurrenceEnd->format('Y-m-d');
            $recurrenceId = $occurrenceStart->format('Ymd');
        } else {
            $durationSeconds = max(0, (int) $master['endTimestamp'] - (int) $master['startTimestamp']);
            $occurrenceEnd = (new DateTimeImmutable('@' . ($occurrenceStart->getTimestamp() + $durationSeconds)))
                ->setTimezone($timezone);
            $occurrence['start'] = $occurrenceStart->format(DATE_ATOM);
            $occurrence['end'] = $occurrenceEnd->format(DATE_ATOM);
            $recurrenceId = $occurrenceStart->format('Ymd\THis');
        }

        $occurrence['startTimestamp'] = $occurrenceStart->getTimestamp();
        $occurrence['endTimestamp'] = $occurrenceEnd->getTimestamp();
        $occurrence['recurrenceIdTimestamp'] = $occurrenceStart->getTimestamp();
        $occurrence = array_merge(
            $occurrence,
            CalendarEventRecurrence::occurrence(
                (string) ($master['uid'] ?? ''),
                (string) ($master['uid'] ?? '') . '|' . $recurrenceId,
                $allDay ? $occurrenceStart->format('Y-m-d') : $occurrenceStart->format(DATE_ATOM),
                $recurrenceId
            )
        );
        $occurrence['id'] = hash(
            'sha256',
            (string) ($master['resourceUrl'] ?? '') . '|'
                . (string) ($master['uid'] ?? '') . '|'
                . $occurrenceStart->getTimestamp()
        );

        return $occurrence;
    }

    /**
     * Parses an RFC 5545 recurrence rule and verifies that every rule part can be
     * expanded losslessly by the local recurrence engine.
     *
     * Unsupported or malformed rules deliberately keep only DTSTART and explicit
     * RDATE values. This prevents a partially understood rule from generating
     * incorrect occurrences.
     *
     * @return array{
     *     rule: array<string, list<string>>,
     *     supported: bool,
     *     unsupportedParts: list<string>
     * }
     */
    private static function analyzeRule(string $ruleText, DateTimeZone $timezone): array
    {
        $rule = [];
        $unsupportedParts = [];
        $seen = [];

        foreach (explode(';', strtoupper(trim($ruleText))) as $part) {
            $part = trim($part);
            $separator = strpos($part, '=');
            if ($part === '' || $separator === false || $separator === 0) {
                $unsupportedParts[] = $part !== '' ? $part : 'INVALID';
                continue;
            }

            $name = trim(substr($part, 0, $separator));
            $values = array_values(array_filter(
                array_map('trim', explode(',', substr($part, $separator + 1))),
                static fn (string $value): bool => $value !== ''
            ));
            if ($name === '' || $values === []) {
                $unsupportedParts[] = $name !== '' ? $name : 'INVALID';
                continue;
            }
            if (isset($seen[$name])) {
                $unsupportedParts[] = $name;
                continue;
            }
            $seen[$name] = true;
            $rule[$name] = $values;

            if (!in_array($name, self::SUPPORTED_RULE_PARTS, true)) {
                $unsupportedParts[] = $name;
            }
        }

        foreach (self::SINGLE_VALUE_RULE_PARTS as $name) {
            if (isset($rule[$name]) && count($rule[$name]) !== 1) {
                $unsupportedParts[] = $name;
            }
        }

        $frequency = $rule['FREQ'][0] ?? '';
        if ($frequency === '' || !in_array($frequency, self::SUPPORTED_FREQUENCIES, true)) {
            $unsupportedParts[] = $frequency !== '' ? 'FREQ=' . $frequency : 'FREQ';
        }
        if (isset($rule['COUNT'], $rule['UNTIL'])) {
            $unsupportedParts[] = 'COUNT+UNTIL';
        }
        if (isset($rule['COUNT']) && !self::singlePositiveInteger($rule['COUNT'])) {
            $unsupportedParts[] = 'COUNT';
        }
        if (isset($rule['INTERVAL']) && !self::singlePositiveInteger($rule['INTERVAL'])) {
            $unsupportedParts[] = 'INTERVAL';
        }
        if (isset($rule['UNTIL'])
            && (count($rule['UNTIL']) !== 1 || self::parseUntil($rule['UNTIL'][0], $timezone) === null)) {
            $unsupportedParts[] = 'UNTIL';
        }
        if (isset($rule['WKST'])
            && (count($rule['WKST']) !== 1
                || preg_match('/^(MO|TU|WE|TH|FR|SA|SU)$/D', $rule['WKST'][0]) !== 1)) {
            $unsupportedParts[] = 'WKST';
        }
        if (isset($rule['BYMONTH']) && !self::integerListInRange($rule['BYMONTH'], 1, 12, false)) {
            $unsupportedParts[] = 'BYMONTH';
        }
        if (isset($rule['BYMONTHDAY']) && !self::integerListInRange($rule['BYMONTHDAY'], 1, 31, true)) {
            $unsupportedParts[] = 'BYMONTHDAY';
        }
        if (isset($rule['BYYEARDAY']) && !self::integerListInRange($rule['BYYEARDAY'], 1, 366, true)) {
            $unsupportedParts[] = 'BYYEARDAY';
        }
        if (isset($rule['BYWEEKNO']) && !self::integerListInRange($rule['BYWEEKNO'], 1, 53, true)) {
            $unsupportedParts[] = 'BYWEEKNO';
        }
        if (isset($rule['BYSETPOS']) && !self::integerListInRange($rule['BYSETPOS'], 1, 366, true)) {
            $unsupportedParts[] = 'BYSETPOS';
        }
        if (isset($rule['BYSETPOS'])
            && !array_intersect(
                ['BYMONTH', 'BYWEEKNO', 'BYYEARDAY', 'BYMONTHDAY', 'BYDAY'],
                array_keys($rule)
            )) {
            $unsupportedParts[] = 'BYSETPOS';
        }
        if (isset($rule['BYDAY']) && !self::supportedByDayValues($rule['BYDAY'], $frequency)) {
            $unsupportedParts[] = 'BYDAY';
        }
        if ($frequency === 'WEEKLY' && isset($rule['BYMONTHDAY'])) {
            $unsupportedParts[] = 'BYMONTHDAY';
        }
        if (isset($rule['BYYEARDAY']) && $frequency !== 'YEARLY') {
            $unsupportedParts[] = 'BYYEARDAY';
        }
        if (isset($rule['BYWEEKNO']) && $frequency !== 'YEARLY') {
            $unsupportedParts[] = 'BYWEEKNO';
        }
        if ($frequency === 'YEARLY' && isset($rule['BYWEEKNO'])) {
            foreach ($rule['BYDAY'] ?? [] as $byDay) {
                if (preg_match('/^[+-]?\d+(?:MO|TU|WE|TH|FR|SA|SU)$/D', $byDay) === 1) {
                    $unsupportedParts[] = 'BYDAY';
                    break;
                }
            }
        }

        $unsupportedParts = array_values(array_unique($unsupportedParts));

        return [
            'rule'             => $rule,
            'supported'        => $unsupportedParts === [],
            'unsupportedParts' => $unsupportedParts
        ];
    }

    /** @param list<string> $values */
    private static function singlePositiveInteger(array $values): bool
    {
        return count($values) === 1
            && preg_match('/^[1-9]\d*$/D', $values[0]) === 1;
    }

    /**
     * @param list<string> $values
     */
    private static function integerListInRange(array $values, int $minimum, int $maximum, bool $allowNegative): bool
    {
        foreach ($values as $value) {
            if (preg_match($allowNegative ? '/^[+-]?\d+$/D' : '/^\d+$/D', $value) !== 1) {
                return false;
            }
            $number = (int) $value;
            $absolute = abs($number);
            if ($number === 0 || $absolute < $minimum || $absolute > $maximum) {
                return false;
            }
            if (!$allowNegative && $number < 0) {
                return false;
            }
        }

        return $values !== [];
    }

    /**
     * @param list<string> $values
     */
    private static function supportedByDayValues(array $values, string $frequency): bool
    {
        foreach ($values as $value) {
            if (preg_match('/^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/D', $value, $matches) !== 1) {
                return false;
            }
            if (($matches[1] ?? '') === '') {
                continue;
            }

            $ordinal = (int) $matches[1];
            if ($ordinal === 0 || abs($ordinal) > 53 || !in_array($frequency, ['MONTHLY', 'YEARLY'], true)) {
                return false;
            }
        }

        return $values !== [];
    }

    private static function parseUntil(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{8}$/', $value) === 1) {
                $date = DateTimeImmutable::createFromFormat('!Ymd', $value, $timezone);
                return $date === false ? null : $date->setTime(23, 59, 59);
            }
            if (str_ends_with($value, 'Z')) {
                $format = strlen($value) === 14 ? '!Ymd\THi\Z' : '!Ymd\THis\Z';
                $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
                return $date === false ? null : $date;
            }
            $format = strlen($value) === 13 ? '!Ymd\THi' : '!Ymd\THis';
            $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            return $date === false ? null : $date;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $values
     * @return list<int>
     */
    private static function integerValues(array $values): array
    {
        return array_values(array_map('intval', self::stringValues($values)));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function stringValues(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $part) {
                $part = strtoupper(trim($part));
                if ($part !== '') {
                    $result[] = $part;
                }
            }
        }

        return $result;
    }

    private static function matchesWeekdayOrdinalInMonth(DateTimeImmutable $date, int $ordinal): bool
    {
        $positive = intdiv((int) $date->format('j') - 1, 7) + 1;
        $negative = -(intdiv((int) $date->format('t') - (int) $date->format('j'), 7) + 1);

        return $ordinal === $positive || $ordinal === $negative;
    }

    private static function matchesWeekdayOrdinalInYear(DateTimeImmutable $date, int $ordinal): bool
    {
        $positive = intdiv((int) $date->format('z'), 7) + 1;
        $daysInYear = $date->format('L') === '1' ? 366 : 365;
        $negative = -(intdiv($daysInYear - 1 - (int) $date->format('z'), 7) + 1);

        return $ordinal === $positive || $ordinal === $negative;
    }

    private static function weekdayNumber(string $weekday): int
    {
        return match (strtoupper($weekday)) {
            'TU'    => 2,
            'WE'    => 3,
            'TH'    => 4,
            'FR'    => 5,
            'SA'    => 6,
            'SU'    => 7,
            default => 1
        };
    }

    private static function startOfWeek(DateTimeImmutable $date, int $weekStart): DateTimeImmutable
    {
        $offset = ((int) $date->format('N') - $weekStart + 7) % 7;
        return $date->setTime(0, 0)->sub(new DateInterval('P' . $offset . 'D'));
    }

    private static function calendarDayDifference(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) $start->setTime(12, 0)->diff($end->setTime(12, 0))->format('%r%a');
    }

    private static function calendarMonthDifference(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return ((int) $end->format('Y') - (int) $start->format('Y')) * 12
            + (int) $end->format('n')
            - (int) $start->format('n');
    }

    private static function isCancelled(array $event): bool
    {
        return strtoupper((string) ($event['status'] ?? '')) === 'CANCELLED';
    }

    private static function overlapsRange(
        array $event,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd
    ): bool {
        return (int) ($event['endTimestamp'] ?? 0) > $rangeStart->getTimestamp()
            && (int) ($event['startTimestamp'] ?? 0) < $rangeEnd->getTimestamp();
    }

    private static function isNewerEvent(array $candidate, array $current): bool
    {
        $candidateSequence = (int) ($candidate['sequence'] ?? 0);
        $currentSequence = (int) ($current['sequence'] ?? 0);
        if ($candidateSequence !== $currentSequence) {
            return $candidateSequence > $currentSequence;
        }

        return strcmp((string) ($candidate['lastModified'] ?? ''), (string) ($current['lastModified'] ?? '')) > 0;
    }

    private static function dateAtTimestamp(int $timestamp, string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(self::timezone($timezone));
    }

    private static function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
