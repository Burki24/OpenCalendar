<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

require_once __DIR__ . '/ICalendarRecurrence.php';

final class ICalendarTimezoneResolver
{
    private const WINDOWS_TIMEZONES = [
        'GMT Standard Time'              => 'Europe/London',
        'W. Europe Standard Time'        => 'Europe/Berlin',
        'Central Europe Standard Time'   => 'Europe/Budapest',
        'Romance Standard Time'          => 'Europe/Paris',
        'Central European Standard Time' => 'Europe/Warsaw',
        'GTB Standard Time'              => 'Europe/Bucharest',
        'FLE Standard Time'              => 'Europe/Kyiv',
        'Turkey Standard Time'           => 'Europe/Istanbul',
        'Russian Standard Time'          => 'Europe/Moscow',
        'Eastern Standard Time'          => 'America/New_York',
        'Central Standard Time'          => 'America/Chicago',
        'Mountain Standard Time'         => 'America/Denver',
        'Pacific Standard Time'          => 'America/Los_Angeles',
        'Tokyo Standard Time'            => 'Asia/Tokyo',
        'China Standard Time'            => 'Asia/Shanghai',
        'India Standard Time'            => 'Asia/Kolkata',
        'AUS Eastern Standard Time'      => 'Australia/Sydney',
        'New Zealand Standard Time'      => 'Pacific/Auckland'
    ];

    /** @var array<string, array{resolvedName: string, observances: list<array<string, mixed>>}> */
    private array $definitions;

    /**
     * @param array<string, array{resolvedName: string, observances: list<array<string, mixed>>}> $definitions
     */
    private function __construct(array $definitions)
    {
        $this->definitions = $definitions;
    }

    /**
     * Creates a resolver from VTIMEZONE components embedded in an iCalendar resource.
     */
    public static function fromCalendar(string $ical): self
    {
        $lines = self::unfoldLines($ical);
        $definitions = [];
        foreach (self::extractComponents($lines, 'VTIMEZONE') as $block) {
            $properties = self::readTopLevelProperties($block);
            $timezoneId = trim(self::propertyValue($properties, 'TZID'));
            if ($timezoneId === '') {
                continue;
            }

            $resolvedName = self::resolveSystemTimezoneName($timezoneId);
            foreach (['X-LIC-LOCATION', 'TZURL'] as $hintName) {
                if ($resolvedName !== '') {
                    break;
                }
                $resolvedName = self::resolveSystemTimezoneName(self::propertyValue($properties, $hintName));
            }

            $observances = [];
            foreach (['STANDARD', 'DAYLIGHT'] as $type) {
                foreach (self::extractComponents($block, $type) as $observanceBlock) {
                    $observance = self::parseObservance($observanceBlock);
                    if ($observance !== null) {
                        $observances[] = $observance;
                    }
                }
            }

            $definitions[$timezoneId] = [
                'resolvedName' => $resolvedName,
                'observances'  => $observances
            ];
        }

        return new self($definitions);
    }

    /**
     * Resolves an RFC local DATE-TIME with TZID into an absolute instant.
     *
     * When an embedded VTIMEZONE is present, its observances take precedence
     * over the host tzdb for the referenced calendar object. Unknown custom
     * definitions can therefore still be interpreted for individual dates.
     *
     * @return array{date:DateTimeImmutable,timezone:string,reference:string,resolved:bool}|null
     */
    public function resolveDateTime(string $timezoneId, string $raw): ?array
    {
        $timezoneId = trim($timezoneId, " \t\n\r\0\x0B\"");
        if ($timezoneId === '') {
            return null;
        }

        $definition = $this->definitions[$timezoneId] ?? null;
        $resolvedName = $definition['resolvedName'] ?? self::resolveSystemTimezoneName($timezoneId);
        if ($definition === null) {
            if ($resolvedName === '') {
                return null;
            }

            $date = self::parseWithTimezone($raw, new DateTimeZone($resolvedName));
            return $date === null
                ? null
                : [
                    'date'      => $date,
                    'timezone'  => $resolvedName,
                    'reference' => $timezoneId,
                    'resolved'  => true
                ];
        }

        $localDate = self::parseLocalWallTime($raw);
        if ($localDate === null) {
            return null;
        }
        $offsetState = self::offsetStateForLocal($definition['observances'], $localDate);
        if ($offsetState === null) {
            if ($resolvedName === '') {
                return null;
            }
            $date = self::parseWithTimezone($raw, new DateTimeZone($resolvedName));
            return $date === null
                ? null
                : [
                    'date'      => $date,
                    'timezone'  => $resolvedName,
                    'reference' => $timezoneId,
                    'resolved'  => true
                ];
        }

        $timestamp = $localDate->getTimestamp() - $offsetState['calculationOffset'];
        $definitionMatchesSystem = $resolvedName !== ''
            && self::definitionMatchesSystemTimezone(
                $definition['observances'],
                $resolvedName,
                (int) $localDate->format('Y')
            );
        $displayTimezone = $definitionMatchesSystem
            ? new DateTimeZone($resolvedName)
            : new DateTimeZone(self::offsetName($offsetState['displayOffset']));
        $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($displayTimezone);

        return [
            'date'      => $date,
            'timezone'  => $definitionMatchesSystem ? $resolvedName : $displayTimezone->getName(),
            'reference' => $timezoneId,
            'resolved'  => $definitionMatchesSystem
        ];
    }

    /**
     * Returns whether the calendar contains an embedded definition for the given TZID.
     */
    public function hasDefinition(string $timezoneId): bool
    {
        $timezoneId = trim($timezoneId, " \t\n\r\0\x0B\"");

        return $timezoneId !== '' && isset($this->definitions[$timezoneId]);
    }

    private static function resolveSystemTimezoneName(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B\"");
        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeZone($value))->getName();
        } catch (Throwable) {
            // Continue with aliases used by common calendar producers.
        }

        if (isset(self::WINDOWS_TIMEZONES[$value])) {
            return self::WINDOWS_TIMEZONES[$value];
        }

        $decoded = rawurldecode($value);
        $identifiers = timezone_identifiers_list();
        usort(
            $identifiers,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left)
        );
        foreach ($identifiers as $identifier) {
            if ($decoded === $identifier
                || str_ends_with($decoded, '/' . $identifier)
                || str_contains($decoded, '/' . $identifier . '?')
                || str_contains($decoded, '/' . $identifier . '#')) {
                return $identifier;
            }
        }

        return '';
    }

    /**
     * @param list<string> $block
     * @return array<string, mixed>|null
     */
    private static function parseObservance(array $block): ?array
    {
        $properties = self::readTopLevelProperties($block);
        $start = trim(self::propertyValue($properties, 'DTSTART'));
        $offsetFrom = self::parseOffset(self::propertyValue($properties, 'TZOFFSETFROM'));
        $offsetTo = self::parseOffset(self::propertyValue($properties, 'TZOFFSETTO'));
        if ($start === '' || $offsetFrom === null || $offsetTo === null || self::parseLocalWallTime($start) === null) {
            return null;
        }

        $recurrenceDates = [];
        foreach ($properties['RDATE'] ?? [] as $property) {
            foreach (explode(',', $property['value']) as $value) {
                $value = trim($value);
                if ($value !== '' && self::parseLocalWallTime($value) !== null) {
                    $recurrenceDates[] = $value;
                }
            }
        }

        return [
            'start'           => $start,
            'offsetFrom'      => $offsetFrom,
            'offsetTo'        => $offsetTo,
            'rule'            => trim(self::propertyValue($properties, 'RRULE')),
            'recurrenceDates' => array_values(array_unique($recurrenceDates))
        ];
    }

    /**
     * @param list<array<string, mixed>> $observances
     * @return array{calculationOffset: int, displayOffset: int}|null
     */
    private static function offsetStateForLocal(array $observances, DateTimeImmutable $localDate): ?array
    {
        $transitions = [];
        foreach ($observances as $observance) {
            $generated = self::observanceTransitions($observance, (int) $localDate->format('Y'));
            if ($generated === null) {
                continue;
            }
            array_push($transitions, ...$generated);
        }
        if ($transitions === []) {
            return null;
        }

        usort(
            $transitions,
            static fn (array $left, array $right): int => $left['localTimestamp'] <=> $right['localTimestamp']
        );
        $calculationOffset = (int) $transitions[0]['offsetFrom'];
        $displayOffset = $calculationOffset;
        $target = $localDate->getTimestamp();

        foreach ($transitions as $transition) {
            $transitionLocal = (int) $transition['localTimestamp'];
            $offsetFrom = (int) $transition['offsetFrom'];
            $offsetTo = (int) $transition['offsetTo'];
            if ($target < $transitionLocal) {
                break;
            }

            if ($offsetTo > $offsetFrom && $target < $transitionLocal + ($offsetTo - $offsetFrom)) {
                return [
                    'calculationOffset' => $offsetFrom,
                    'displayOffset'     => $offsetTo
                ];
            }

            $calculationOffset = $offsetTo;
            $displayOffset = $offsetTo;
        }

        return [
            'calculationOffset' => $calculationOffset,
            'displayOffset'     => $displayOffset
        ];
    }

    /**
     * @param array<string, mixed> $observance
     * @return list<array{localTimestamp: int, offsetFrom: int, offsetTo: int}>|null
     */
    private static function observanceTransitions(array $observance, int $targetYear): ?array
    {
        $start = self::parseLocalWallTime((string) $observance['start']);
        if ($start === null) {
            return null;
        }

        $dates = [$start->getTimestamp() => $start];
        foreach ($observance['recurrenceDates'] as $rawDate) {
            $date = self::parseLocalWallTime((string) $rawDate);
            if ($date !== null) {
                $dates[$date->getTimestamp()] = $date;
            }
        }

        $rule = trim((string) $observance['rule']);
        if ($rule !== '') {
            $ruleWithoutUntil = implode(';', array_values(array_filter(
                explode(';', $rule),
                static fn (string $part): bool => !str_starts_with(strtoupper(trim($part)), 'UNTIL=')
            )));
            $synthetic = [[
                'uid'             => 'vtimezone-' . hash('sha256', $rule . '|' . (string) $observance['start']),
                'start'           => $start->format(DATE_ATOM),
                'end'             => $start->modify('+1 second')->format(DATE_ATOM),
                'startTimestamp'  => $start->getTimestamp(),
                'endTimestamp'    => $start->getTimestamp() + 1,
                'allDay'          => false,
                'timezone'        => 'UTC',
                'status'          => 'CONFIRMED',
                'recurrenceRule'  => $ruleWithoutUntil,
                'recurrenceDates' => [],
                'exceptionDates'  => []
            ]];
            $rangeStart = new DateTimeImmutable(($targetYear - 2) . '-01-01T00:00:00Z');
            $rangeEnd = new DateTimeImmutable(($targetYear + 2) . '-12-31T23:59:59Z');
            foreach (ICalendarRecurrence::expand($synthetic, $rangeStart, $rangeEnd) as $event) {
                if (!(bool) ($event['recurrenceExpansionSupported'] ?? true)) {
                    return null;
                }
                $date = (new DateTimeImmutable('@' . (int) $event['startTimestamp']))->setTimezone(new DateTimeZone('UTC'));
                $dates[$date->getTimestamp()] = $date;
            }
        }

        $until = self::ruleUntil($rule);
        $transitions = [];
        foreach ($dates as $date) {
            if ((int) $date->format('Y') < $targetYear - 2 || (int) $date->format('Y') > $targetYear + 2) {
                continue;
            }
            $absoluteTimestamp = $date->getTimestamp() - (int) $observance['offsetFrom'];
            if ($until !== null && $absoluteTimestamp > $until) {
                continue;
            }
            $transitions[] = [
                'localTimestamp' => $date->getTimestamp(),
                'offsetFrom'     => (int) $observance['offsetFrom'],
                'offsetTo'       => (int) $observance['offsetTo']
            ];
        }

        return $transitions;
    }

    /**
     * Verifies that an alias hint and the embedded VTIMEZONE observances describe
     * the same transitions before recurrence expansion is delegated to PHP tzdb.
     *
     * @param list<array<string, mixed>> $observances
     */
    private static function definitionMatchesSystemTimezone(
        array $observances,
        string $timezoneName,
        int $targetYear
    ): bool {
        if ($observances === []) {
            return false;
        }

        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            return false;
        }

        $customTransitions = [];
        foreach ($observances as $observance) {
            $generated = self::observanceTransitions($observance, $targetYear);
            if ($generated === null) {
                return false;
            }
            foreach ($generated as $transition) {
                $timestamp = (int) $transition['localTimestamp'] - (int) $transition['offsetFrom'];
                $customTransitions[$timestamp] = (int) $transition['offsetTo'];
            }
        }
        ksort($customTransitions);
        if (count($customTransitions) < 2) {
            return false;
        }

        $comparisonStart = (int) array_key_first($customTransitions);
        $comparisonEnd = (int) array_key_last($customTransitions);
        $systemTransitions = $timezone->getTransitions($comparisonStart - 1, $comparisonEnd + 1);
        if ($systemTransitions === false) {
            return false;
        }
        $expectedTransitions = [];
        foreach ($systemTransitions as $index => $transition) {
            $timestamp = (int) ($transition['ts'] ?? 0);
            if ($index === 0 || $timestamp < $comparisonStart || $timestamp > $comparisonEnd) {
                continue;
            }
            $expectedTransitions[$timestamp] = (int) ($transition['offset'] ?? 0);
        }

        return $customTransitions === $expectedTransitions;
    }

    private static function ruleUntil(string $rule): ?int
    {
        if (preg_match('/(?:^|;)UNTIL=([^;]+)/i', $rule, $matches) !== 1) {
            return null;
        }
        $value = strtoupper(trim($matches[1]));
        if (!str_ends_with($value, 'Z')) {
            return null;
        }
        $format = strlen($value) === 14 ? '!Ymd\THi\Z' : '!Ymd\THis\Z';
        $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));

        return $date === false ? null : $date->getTimestamp();
    }

    private static function parseOffset(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^([+-])(\d{2})(\d{2})(\d{2})?$/D', $value, $matches) !== 1) {
            return null;
        }
        $seconds = ((int) $matches[2] * 3600) + ((int) $matches[3] * 60) + (int) ($matches[4] ?? 0);

        return $matches[1] === '-' ? -$seconds : $seconds;
    }

    private static function offsetName(int $offset): string
    {
        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);
        $hours = intdiv($offset, 3600);
        $minutes = intdiv($offset % 3600, 60);
        $seconds = $offset % 60;

        return $seconds === 0
            ? sprintf('%s%02d:%02d', $sign, $hours, $minutes)
            : sprintf('%s%02d:%02d:%02d', $sign, $hours, $minutes, $seconds);
    }

    private static function parseWithTimezone(string $raw, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $format = strlen($raw) === 13 ? '!Ymd\THi' : '!Ymd\THis';
        $date = DateTimeImmutable::createFromFormat($format, $raw, $timezone);

        return $date === false ? null : $date;
    }

    private static function parseLocalWallTime(string $raw): ?DateTimeImmutable
    {
        $raw = strtoupper(trim($raw));
        if (str_ends_with($raw, 'Z')) {
            $raw = substr($raw, 0, -1);
        }
        if (preg_match('/^\d{8}T(?:\d{4}|\d{6})$/D', $raw) !== 1) {
            return null;
        }
        $format = strlen($raw) === 13 ? '!Ymd\THi' : '!Ymd\THis';
        $date = DateTimeImmutable::createFromFormat($format, $raw, new DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }

    /** @return list<string> */
    private static function unfoldLines(string $ical): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $ical);
        $physicalLines = explode("\n", $normalized);
        $lines = [];
        foreach ($physicalLines as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $lines !== []) {
                $lines[array_key_last($lines)] .= substr($line, 1);
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @return list<list<string>>
     */
    private static function extractComponents(array $lines, string $component): array
    {
        $component = strtoupper($component);
        $blocks = [];
        $start = null;
        $depth = 0;
        foreach ($lines as $index => $line) {
            $upper = strtoupper(trim($line));
            if ($upper === 'BEGIN:' . $component && $start === null) {
                $start = $index;
                $depth = 1;
                continue;
            }
            if ($start === null) {
                continue;
            }
            if (str_starts_with($upper, 'BEGIN:')) {
                ++$depth;
            } elseif (str_starts_with($upper, 'END:')) {
                --$depth;
                if ($depth === 0) {
                    $blocks[] = array_slice($lines, $start, $index - $start + 1);
                    $start = null;
                }
            }
        }

        return $blocks;
    }

    /**
     * @param list<string> $block
     * @return array<string, list<array{value: string}>>
     */
    private static function readTopLevelProperties(array $block): array
    {
        $properties = [];
        $depth = 0;
        foreach ($block as $line) {
            $upper = strtoupper(trim($line));
            if (str_starts_with($upper, 'BEGIN:')) {
                ++$depth;
                continue;
            }
            if (str_starts_with($upper, 'END:')) {
                --$depth;
                continue;
            }
            if ($depth !== 1) {
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $definition = substr($line, 0, $separator);
            $nameSeparator = strpos($definition, ';');
            $name = strtoupper($nameSeparator === false ? $definition : substr($definition, 0, $nameSeparator));
            $properties[$name][] = ['value' => substr($line, $separator + 1)];
        }

        return $properties;
    }

    /** @param array<string, list<array{value: string}>> $properties */
    private static function propertyValue(array $properties, string $name): string
    {
        return (string) ($properties[strtoupper($name)][0]['value'] ?? '');
    }
}
