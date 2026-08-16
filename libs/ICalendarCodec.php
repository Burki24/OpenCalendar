<?php

declare(strict_types=1);

namespace IPSKalender;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/ICalendarRecurrence.php';
require_once __DIR__ . '/CalendarEventRecurrence.php';
require_once __DIR__ . '/CalendarRecurrenceRule.php';

final class ICalendarCodec
{
    /**
     * Parses VEVENT components from an iCalendar resource into normalized event records.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseEvents(string $ical, string $resourceUrl, string $etag): array
    {
        $events = [];
        foreach (self::extractEventBlocks(self::unfoldLines($ical)) as $block) {
            $properties = self::readTopLevelProperties($block);
            $uid = self::propertyValue($properties, 'UID');
            $startProperty = self::firstProperty($properties, 'DTSTART');
            if ($uid === '' || $startProperty === null) {
                continue;
            }

            $start = self::parseDateProperty($startProperty);
            $endProperty = self::firstProperty($properties, 'DTEND');
            $end = $endProperty !== null
                ? self::parseDateProperty($endProperty)
                : self::endFromDuration($start, self::propertyValue($properties, 'DURATION'));
            $recurrenceIdProperty = self::firstProperty($properties, 'RECURRENCE-ID');
            $recurrenceId = $recurrenceIdProperty['value'] ?? '';
            $parsedRecurrenceId = $recurrenceIdProperty !== null
                ? self::parseDateProperty($recurrenceIdProperty)
                : null;

            $recurrenceRule = self::propertyValue($properties, 'RRULE');
            $recurrenceDates = self::parseDatePropertyList($properties['RDATE'] ?? []);
            $recurrenceIdentity = $recurrenceId !== ''
                ? CalendarEventRecurrence::occurrence(
                    $uid,
                    $uid . '|' . $recurrenceId,
                    (string) ($parsedRecurrenceId['value'] ?? ''),
                    $recurrenceId,
                    false,
                    true
                )
                : ($recurrenceRule !== '' || $recurrenceDates !== []
                    ? CalendarEventRecurrence::master($uid)
                    : CalendarEventRecurrence::single());

            $events[] = array_merge([
                'id'                    => hash('sha256', $resourceUrl . '|' . $uid . '|' . $recurrenceId . '|' . $start['value']),
                'uid'                   => $uid,
                'resourceUrl'           => $resourceUrl,
                'etag'                  => $etag,
                'summary'               => self::unescapeText(self::propertyValue($properties, 'SUMMARY')),
                'description'           => self::unescapeText(self::propertyValue($properties, 'DESCRIPTION')),
                'location'              => self::unescapeText(self::propertyValue($properties, 'LOCATION')),
                'start'                 => $start['value'],
                'end'                   => $end['value'],
                'startTimestamp'        => $start['timestamp'],
                'endTimestamp'          => $end['timestamp'],
                'allDay'                => $start['allDay'],
                'timezone'              => $start['timezone'],
                'status'                => strtoupper(self::propertyValue($properties, 'STATUS')),
                'recurrenceRule'        => $recurrenceRule,
                'recurrenceIdTimestamp' => $parsedRecurrenceId['timestamp'] ?? null,
                'exceptionDates'        => self::parseDatePropertyList($properties['EXDATE'] ?? []),
                'recurrenceDates'       => $recurrenceDates,
                'sequence'              => (int) self::propertyValue($properties, 'SEQUENCE'),
                'created'               => self::parseOptionalDate(self::firstProperty($properties, 'CREATED')),
                'lastModified'          => self::parseOptionalDate(self::firstProperty($properties, 'LAST-MODIFIED')),
                'url'                   => self::propertyValue($properties, 'URL')
            ], $recurrenceIdentity);
        }

        return $events;
    }

    /**
     * Parses an iCalendar resource and expands recurring events within the requested range.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseEventsInRange(
        string $ical,
        string $resourceUrl,
        string $etag,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        return ICalendarRecurrence::expand(
            self::parseEvents($ical, $resourceUrl, $etag),
            $start,
            $end
        );
    }

    /**
     * Creates a standalone VCALENDAR document containing one VEVENT.
     *
     * Recurring timed events retain their local wall-clock time through an
     * RFC 5545 TZID reference plus a matching VTIMEZONE component.
     *
     * @param array<string, mixed> $data
     * @return array{uid: string, ical: string}
     */
    public static function createEvent(array $data): array
    {
        $summary = trim((string) ($data['summary'] ?? ''));
        if ($summary === '') {
            throw new InvalidArgumentException('The event summary is missing.');
        }
        if (!array_key_exists('start', $data)) {
            throw new InvalidArgumentException('The event start is missing.');
        }

        $allDay = (bool) ($data['allDay'] ?? false);
        $start = self::inputDate($data['start'], $allDay);
        $end = array_key_exists('end', $data)
            ? self::inputDate($data['end'], $allDay)
            : ($allDay ? $start->add(new DateInterval('P1D')) : $start->add(new DateInterval('PT1H')));
        if ($end <= $start) {
            throw new InvalidArgumentException('The event end must be later than the start.');
        }

        $recurrence = $data['recurrence'] ?? null;
        $recurring = $recurrence !== null && $recurrence !== [];
        if ($recurring && (!is_array($recurrence) || array_is_list($recurrence))) {
            throw new InvalidArgumentException('The recurrence settings are invalid.');
        }

        $timezoneName = trim((string) ($data['timezone'] ?? ''));
        $timezoneLines = [];
        $useTimezoneReference = false;
        if ($recurring && !$allDay) {
            if ($timezoneName === '') {
                $timezoneName = date_default_timezone_get();
            }
            $timezone = self::strictTimezone($timezoneName);
            $start = $start->setTimezone($timezone);
            $end = $end->setTimezone($timezone);
            $timezoneLines = self::timezoneComponent($timezone, $start, $recurrence);
            $useTimezoneReference = $timezoneLines !== [];
        }

        $uid = bin2hex(random_bytes(16)) . '@ips-kalender';
        $now = gmdate('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//OpenCalendar//Calendar Module//EN',
            'CALSCALE:GREGORIAN'
        ];
        array_push($lines, ...$timezoneLines);
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . $uid;
        $lines[] = 'DTSTAMP:' . $now;
        $lines[] = 'CREATED:' . $now;
        $lines[] = 'LAST-MODIFIED:' . $now;
        $lines[] = 'SEQUENCE:0';
        $lines[] = self::formatEventDateLine('DTSTART', $start, $allDay, $useTimezoneReference ? $timezoneName : '');
        $lines[] = self::formatEventDateLine('DTEND', $end, $allDay, $useTimezoneReference ? $timezoneName : '');
        $lines[] = 'SUMMARY:' . self::escapeText($summary);

        if ($recurring) {
            $lines[] = CalendarRecurrenceRule::toICalendarRule(
                $recurrence,
                $start,
                $allDay,
                $timezoneName !== '' ? $timezoneName : 'UTC'
            );
        }

        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $key => $property) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                $lines[] = $property . ':' . self::escapeText($value);
            }
        }

        $status = self::normalizeStatus((string) ($data['status'] ?? 'CONFIRMED'));
        if ($status !== '') {
            $lines[] = 'STATUS:' . $status;
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return ['uid' => $uid, 'ical' => self::foldLines($lines)];
    }

    /**
     * Updates a non-recurring VEVENT inside an existing iCalendar resource.
     *
     * @param array<string, mixed> $data
     */
    public static function updateEvent(string $ical, string $uid, array $data): string
    {
        $lines = self::unfoldLines($ical);
        $blocks = self::extractEventBlocksWithOffsets($lines);
        $target = null;

        foreach ($blocks as $block) {
            $properties = self::readTopLevelProperties($block['lines']);
            if (self::propertyValue($properties, 'UID') !== $uid) {
                continue;
            }
            if (self::propertyValue($properties, 'RRULE') !== ''
                || self::propertyValue($properties, 'RDATE') !== ''
                || self::propertyValue($properties, 'RECURRENCE-ID') !== '') {
                throw new RuntimeException('Recurring events cannot be modified as single events.');
            }
            $target = $block;
            break;
        }

        if ($target === null) {
            throw new RuntimeException('The event was not found in the calendar resource.');
        }

        $block = $target['lines'];
        self::applyEventChangesToBlock($block, $data);
        array_splice($lines, $target['start'], $target['end'] - $target['start'] + 1, $block);

        return self::foldLines($lines);
    }

    /**
     * Updates the master VEVENT of a recurring iCalendar resource.
     *
     * Detached RECURRENCE-ID overrides and other calendar components are retained.
     * A replacement RRULE is accepted only when the existing recurrence can be
     * represented losslessly by the common OpenCalendar recurrence editor.
     *
     * @param array<string, mixed> $data
     */
    public static function updateRecurringSeries(string $ical, string $uid, array $data): string
    {
        $uid = trim($uid);
        if ($uid === '') {
            throw new InvalidArgumentException('The recurring event UID is missing.');
        }

        $lines = self::unfoldLines($ical);
        $blocks = self::extractEventBlocksWithOffsets($lines);
        $master = self::recurringMaster($blocks, $uid);
        $block = $master['lines'];
        $properties = $master['properties'];
        $recurrenceProvided = array_key_exists('recurrence', $data);
        $recurrence = $data['recurrence'] ?? null;

        if ($recurrenceProvided) {
            if (!is_array($recurrence) || $recurrence === [] || array_is_list($recurrence)) {
                throw new InvalidArgumentException('The recurrence settings are invalid.');
            }
            $startProperty = self::firstProperty($properties, 'DTSTART');
            $currentRule = self::propertyValue($properties, 'RRULE');
            if ($startProperty === null
                || count($properties['RRULE'] ?? []) !== 1
                || self::propertyValue($properties, 'RDATE') !== ''
                || self::propertyValue($properties, 'EXRULE') !== '') {
                throw new RuntimeException('The recurrence pattern cannot be edited safely.');
            }
            $currentStart = self::parseDateProperty($startProperty);
            if (CalendarRecurrenceRule::fromGoogleRule(
                $currentRule,
                $currentStart['allDay'],
                $currentStart['timezone']
            ) === null) {
                throw new RuntimeException('The recurrence pattern cannot be edited safely.');
            }
        }

        self::applyEventChangesToBlock($block, $data);

        if ($recurrenceProvided && is_array($recurrence)) {
            $updatedProperties = self::readTopLevelProperties($block);
            $updatedStartProperty = self::firstProperty($updatedProperties, 'DTSTART');
            if ($updatedStartProperty === null) {
                throw new RuntimeException('The recurring event master has no start.');
            }
            $updatedStart = self::parseDateProperty($updatedStartProperty);
            $timezone = self::timezone($updatedStart['timezone']);
            $start = (new DateTimeImmutable('@' . $updatedStart['timestamp']))->setTimezone($timezone);
            self::replaceProperty(
                $block,
                'RRULE',
                CalendarRecurrenceRule::toICalendarRule(
                    $recurrence,
                    $start,
                    $updatedStart['allDay'],
                    $updatedStart['timezone']
                )
            );
        }

        array_splice($lines, $master['start'], $master['end'] - $master['start'] + 1, $block);

        return self::foldLines($lines);
    }

    /**
     * Shortens one supported recurring series so it ends before the selected occurrence.
     *
     * Detached overrides at or after the split boundary are removed because they belong
     * to the future part of the series. Overrides before the boundary are retained.
     */
    public static function trimRecurringSeriesBefore(
        string $ical,
        string $uid,
        string $originalStart
    ): string {
        $context = self::recurringSplitContext($ical, $uid, $originalStart);
        $targetStart = $context['targetStart'];
        $masterStart = $context['masterStart'];
        if ($targetStart->getTimestamp() === $masterStart->getTimestamp()) {
            throw new RuntimeException('The recurring series cannot be shortened before its first occurrence.');
        }

        $lines = $context['lines'];
        $master = $context['master'];
        $masterBlock = $master['lines'];
        self::replaceProperty(
            $masterBlock,
            'RRULE',
            CalendarRecurrenceRule::trimGoogleRuleBefore(
                'RRULE:' . $context['rule'],
                $context['allDay'] ? $targetStart->format('Y-m-d') : $targetStart->format(DATE_ATOM),
                $context['allDay'],
                $context['timezone']
            )
        );
        self::touchEventBlock($masterBlock);

        $operations = [[
            'start'       => $master['start'],
            'length'      => $master['end'] - $master['start'] + 1,
            'replacement' => $masterBlock
        ]];
        foreach ($context['blocks'] as $block) {
            $properties = self::readTopLevelProperties($block['lines']);
            if (!hash_equals($context['uid'], self::propertyValue($properties, 'UID'))) {
                continue;
            }
            $recurrenceId = self::firstProperty($properties, 'RECURRENCE-ID');
            if ($recurrenceId === null) {
                continue;
            }
            try {
                $timestamp = self::parseDateProperty($recurrenceId)['timestamp'];
            } catch (Throwable) {
                continue;
            }
            if ($timestamp < $targetStart->getTimestamp()) {
                continue;
            }
            $operations[] = [
                'start'       => $block['start'],
                'length'      => $block['end'] - $block['start'] + 1,
                'replacement' => []
            ];
        }

        usort(
            $operations,
            static fn (array $left, array $right): int => $right['start'] <=> $left['start']
        );
        foreach ($operations as $operation) {
            array_splice(
                $lines,
                $operation['start'],
                $operation['length'],
                $operation['replacement']
            );
        }

        return self::foldLines($lines);
    }

    /**
     * Splits one supported recurring series at the selected occurrence.
     *
     * The original resource is shortened before the selected occurrence and all
     * following detached exceptions are removed. The future portion is returned as
     * a new self-contained VCALENDAR resource with a new UID so a CalDAV provider can
     * create it atomically before shortening the original resource.
     *
     * @param array<string, mixed> $data Changes and recurrence settings for the new future series.
     * @return array{originalIcal: string, newIcal: string, newUid: string}
     */
    public static function splitRecurringSeries(
        string $ical,
        string $uid,
        string $originalStart,
        array $data
    ): array {
        $recurrence = $data['recurrence'] ?? null;
        if (!is_array($recurrence) || $recurrence === [] || array_is_list($recurrence)) {
            throw new InvalidArgumentException('The recurrence settings are required when splitting a recurring event.');
        }

        $context = self::recurringSplitContext($ical, $uid, $originalStart);
        $targetStart = $context['targetStart'];
        if ($targetStart->getTimestamp() === $context['masterStart']->getTimestamp()) {
            throw new RuntimeException('The first occurrence must update the existing recurring series.');
        }

        $originalIcal = self::trimRecurringSeriesBefore($ical, $uid, $originalStart);

        $newBlock = self::createOccurrenceOverrideBlock($context['master']['lines'], $targetStart);
        self::replaceProperty($newBlock, 'RECURRENCE-ID', null);
        $newUid = bin2hex(random_bytes(16)) . '@ips-kalender';
        self::replaceProperty($newBlock, 'UID', 'UID:' . $newUid);
        self::applyEventChangesToBlock($newBlock, $data);

        $updatedProperties = self::readTopLevelProperties($newBlock);
        $updatedStartProperty = self::firstProperty($updatedProperties, 'DTSTART');
        if ($updatedStartProperty === null) {
            throw new RuntimeException('The split recurring event has no start.');
        }
        $updatedStart = self::parseDateProperty($updatedStartProperty);
        $updatedTimezone = self::timezone($updatedStart['timezone']);
        $updatedStartDate = (new DateTimeImmutable('@' . $updatedStart['timestamp']))->setTimezone($updatedTimezone);
        self::replaceProperty(
            $newBlock,
            'RRULE',
            CalendarRecurrenceRule::toICalendarRule(
                $recurrence,
                $updatedStartDate,
                $updatedStart['allDay'],
                $updatedStart['timezone']
            )
        );

        $now = gmdate('Ymd\\THis\\Z');
        self::replaceProperty($newBlock, 'SEQUENCE', 'SEQUENCE:0');
        self::replaceProperty($newBlock, 'CREATED', 'CREATED:' . $now);
        self::replaceProperty($newBlock, 'DTSTAMP', 'DTSTAMP:' . $now);
        self::replaceProperty($newBlock, 'LAST-MODIFIED', 'LAST-MODIFIED:' . $now);

        $newLines = $context['lines'];
        foreach (array_reverse($context['blocks']) as $block) {
            array_splice($newLines, $block['start'], $block['end'] - $block['start'] + 1);
        }
        self::insertEventBlock($newLines, $newBlock);

        return [
            'originalIcal' => $originalIcal,
            'newIcal'      => self::foldLines($newLines),
            'newUid'       => $newUid
        ];
    }

    /**
     * Checks whether an iCalendar resource contains a recurring VEVENT master.
     */
    public static function hasRecurringEvent(string $ical, string $uid = ''): bool
    {
        $uid = trim($uid);
        foreach (self::extractEventBlocks(self::unfoldLines($ical)) as $block) {
            $properties = self::readTopLevelProperties($block);
            if ($uid !== '' && self::propertyValue($properties, 'UID') !== $uid) {
                continue;
            }
            if (self::propertyValue($properties, 'RECURRENCE-ID') === ''
                && (self::propertyValue($properties, 'RRULE') !== ''
                    || self::propertyValue($properties, 'RDATE') !== '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates or updates a detached VEVENT override for one recurrence instance.
     *
     * The RECURRENCE-ID always retains the original scheduled start while DTSTART
     * and DTEND may be changed independently for the selected occurrence.
     *
     * @param array<string, mixed> $data
     */
    public static function updateRecurringOccurrence(
        string $ical,
        string $uid,
        string $originalStart,
        array $data
    ): string {
        $uid = trim($uid);
        if ($uid === '') {
            throw new InvalidArgumentException('The recurring event UID is missing.');
        }

        $lines = self::unfoldLines($ical);
        $blocks = self::extractEventBlocksWithOffsets($lines);
        $master = self::recurringMaster($blocks, $uid);
        $masterStartProperty = self::firstProperty($master['properties'], 'DTSTART');
        if ($masterStartProperty === null) {
            throw new RuntimeException('The recurring event master has no start.');
        }
        $targetStart = self::occurrenceOriginalStart($originalStart, $masterStartProperty);
        $override = self::recurrenceOverride($blocks, $uid, $targetStart);

        if ($override !== null) {
            $block = $override['lines'];
            self::applyEventChangesToBlock($block, $data);
            array_splice($lines, $override['start'], $override['end'] - $override['start'] + 1, $block);

            return self::foldLines($lines);
        }

        $block = self::createOccurrenceOverrideBlock($master['lines'], $targetStart);
        self::applyEventChangesToBlock($block, $data);
        self::insertEventBlock($lines, $block);

        return self::foldLines($lines);
    }

    /**
     * Excludes one recurrence instance from a recurring VEVENT resource.
     *
     * Existing detached overrides for the same RECURRENCE-ID are removed and an
     * EXDATE is added to the master. The master DTSTART remains intact, including
     * when the first recurrence instance is excluded.
     */
    public static function deleteRecurringOccurrence(
        string $ical,
        string $uid,
        string $originalStart
    ): string {
        $lines = self::unfoldLines($ical);
        $blocks = self::extractEventBlocksWithOffsets($lines);
        $master = self::recurringMaster($blocks, trim($uid));
        $masterUid = self::propertyValue($master['properties'], 'UID');
        $masterStartProperty = self::firstProperty($master['properties'], 'DTSTART');
        if ($masterUid === '' || $masterStartProperty === null) {
            throw new RuntimeException('The recurring event master is incomplete.');
        }
        $targetStart = self::occurrenceOriginalStart($originalStart, $masterStartProperty);

        $masterBlock = $master['lines'];
        if (!self::hasExceptionDate($master['properties'], $targetStart)) {
            self::appendTopLevelProperty(
                $masterBlock,
                self::formatDateLineLike(
                    'EXDATE',
                    $targetStart,
                    self::parseDateProperty($masterStartProperty)['allDay'],
                    $masterStartProperty
                )
            );
        }
        self::touchEventBlock($masterBlock);

        $operations = [[
            'start'       => $master['start'],
            'length'      => $master['end'] - $master['start'] + 1,
            'replacement' => $masterBlock
        ]];
        foreach (self::recurrenceOverrides($blocks, $masterUid, $targetStart) as $override) {
            $operations[] = [
                'start'       => $override['start'],
                'length'      => $override['end'] - $override['start'] + 1,
                'replacement' => []
            ];
        }
        usort(
            $operations,
            static fn (array $left, array $right): int => $right['start'] <=> $left['start']
        );
        foreach ($operations as $operation) {
            array_splice(
                $lines,
                $operation['start'],
                $operation['length'],
                $operation['replacement']
            );
        }

        return self::foldLines($lines);
    }

    /**
     * Validates and resolves one supported recurring split boundary.
     *
     * @return array{
     *     uid: string,
     *     lines: list<string>,
     *     blocks: list<array{start: int, end: int, lines: list<string>}>,
     *     master: array{start: int, end: int, lines: list<string>, properties: array<string, list<array{value: string, params: array<string, string>}>>},
     *     masterStart: DateTimeImmutable,
     *     targetStart: DateTimeImmutable,
     *     rule: string,
     *     settings: array<string, mixed>,
     *     allDay: bool,
     *     timezone: string,
     *     position: int
     * }
     */
    private static function recurringSplitContext(string $ical, string $uid, string $originalStart): array
    {
        $uid = trim($uid);
        if ($uid === '') {
            throw new InvalidArgumentException('The recurring event UID is missing.');
        }

        $lines = self::unfoldLines($ical);
        $blocks = self::extractEventBlocksWithOffsets($lines);
        $master = self::recurringMaster($blocks, $uid);
        $startProperty = self::firstProperty($master['properties'], 'DTSTART');
        $rule = self::propertyValue($master['properties'], 'RRULE');
        if ($startProperty === null
            || count($master['properties']['RRULE'] ?? []) !== 1
            || $rule === ''
            || self::propertyValue($master['properties'], 'RDATE') !== ''
            || self::propertyValue($master['properties'], 'EXRULE') !== '') {
            throw new RuntimeException('The recurrence pattern cannot be split safely.');
        }

        $parsedStart = self::parseDateProperty($startProperty);
        $settings = CalendarRecurrenceRule::fromGoogleRule(
            $rule,
            $parsedStart['allDay'],
            $parsedStart['timezone']
        );
        if ($settings === null) {
            throw new RuntimeException('The recurrence pattern cannot be split safely.');
        }

        $timezone = self::timezone($parsedStart['timezone']);
        $masterStart = (new DateTimeImmutable('@' . $parsedStart['timestamp']))->setTimezone($timezone);
        $targetStart = self::occurrenceOriginalStart($originalStart, $startProperty)->setTimezone($timezone);
        if ($targetStart < $masterStart
            || (!$parsedStart['allDay'] && $targetStart->format('H:i:s') !== $masterStart->format('H:i:s'))) {
            throw new RuntimeException('The recurring target occurrence is not part of the series pattern.');
        }

        try {
            $microsoftRecurrence = CalendarRecurrenceRule::toMicrosoftRecurrence($settings, $masterStart);
            $position = CalendarRecurrenceRule::microsoftOccurrencePosition(
                $microsoftRecurrence,
                $targetStart->format('Y-m-d')
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('The recurring target occurrence is not part of the series pattern.', 0, $exception);
        }
        if ($position < 1) {
            throw new RuntimeException('The recurring target occurrence is not part of the series pattern.');
        }

        return [
            'uid'         => $uid,
            'lines'       => $lines,
            'blocks'      => $blocks,
            'master'      => $master,
            'masterStart' => $masterStart,
            'targetStart' => $targetStart,
            'rule'        => $rule,
            'settings'    => $settings,
            'allDay'      => $parsedStart['allDay'],
            'timezone'    => $parsedStart['timezone'],
            'position'    => $position
        ];
    }

    /**
     * @param list<array{start: int, end: int, lines: list<string>}> $blocks
     * @return array{start: int, end: int, lines: list<string>, properties: array<string, list<array{value: string, params: array<string, string>}>>}
     */
    private static function recurringMaster(array $blocks, string $uid): array
    {
        $matches = [];
        foreach ($blocks as $block) {
            $properties = self::readTopLevelProperties($block['lines']);
            $blockUid = self::propertyValue($properties, 'UID');
            if ($blockUid === '' || ($uid !== '' && !hash_equals($uid, $blockUid))) {
                continue;
            }
            if (self::propertyValue($properties, 'RECURRENCE-ID') !== '') {
                continue;
            }
            if (self::propertyValue($properties, 'RRULE') === ''
                && self::propertyValue($properties, 'RDATE') === '') {
                continue;
            }
            $matches[] = array_merge($block, ['properties' => $properties]);
        }

        if (count($matches) !== 1) {
            throw new RuntimeException(
                $matches === []
                    ? 'The recurring event master was not found in the calendar resource.'
                    : 'The calendar resource contains multiple recurring event masters.'
            );
        }

        return $matches[0];
    }

    /**
     * @param list<array{start: int, end: int, lines: list<string>}> $blocks
     * @return array{start: int, end: int, lines: list<string>}|null
     */
    private static function recurrenceOverride(
        array $blocks,
        string $uid,
        DateTimeImmutable $targetStart
    ): ?array {
        $matches = self::recurrenceOverrides($blocks, $uid, $targetStart);
        if (count($matches) > 1) {
            throw new RuntimeException('The calendar resource contains duplicate recurrence overrides.');
        }

        return $matches[0] ?? null;
    }

    /**
     * @param list<array{start: int, end: int, lines: list<string>}> $blocks
     * @return list<array{start: int, end: int, lines: list<string>}>
     */
    private static function recurrenceOverrides(
        array $blocks,
        string $uid,
        DateTimeImmutable $targetStart
    ): array {
        $matches = [];
        foreach ($blocks as $block) {
            $properties = self::readTopLevelProperties($block['lines']);
            if (!hash_equals($uid, self::propertyValue($properties, 'UID'))) {
                continue;
            }
            $recurrenceId = self::firstProperty($properties, 'RECURRENCE-ID');
            if ($recurrenceId === null) {
                continue;
            }
            try {
                if (self::parseDateProperty($recurrenceId)['timestamp'] === $targetStart->getTimestamp()) {
                    $matches[] = $block;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $matches;
    }

    /** @param array{value: string, params: array<string, string>} $masterStartProperty */
    private static function occurrenceOriginalStart(
        string $originalStart,
        array $masterStartProperty
    ): DateTimeImmutable {
        $masterStart = self::parseDateProperty($masterStartProperty);
        $timezone = self::timezone($masterStart['timezone']);
        $originalStart = trim($originalStart);
        if ($originalStart === '') {
            return (new DateTimeImmutable('@' . $masterStart['timestamp']))->setTimezone($timezone);
        }

        try {
            if ($masterStart['allDay'] && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $originalStart) === 1) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $originalStart, $timezone);
                if ($date !== false && $date->format('Y-m-d') === $originalStart) {
                    return $date;
                }
            }
            if (preg_match('/^\d{8}(?:T\d{4}(?:\d{2})?Z?)?$/D', strtoupper($originalStart)) === 1) {
                return (new DateTimeImmutable(
                    '@' . self::parseDateProperty([
                        'value'  => $originalStart,
                        'params' => $masterStartProperty['params']
                    ])['timestamp']
                ))->setTimezone($timezone);
            }

            return (new DateTimeImmutable($originalStart, $timezone))->setTimezone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The recurring occurrence start is invalid.', 0, $exception);
        }
    }

    /**
     * @param list<string> $masterBlock
     * @return list<string>
     */
    private static function createOccurrenceOverrideBlock(
        array $masterBlock,
        DateTimeImmutable $targetStart
    ): array {
        $properties = self::readTopLevelProperties($masterBlock);
        $masterStartProperty = self::firstProperty($properties, 'DTSTART');
        if ($masterStartProperty === null) {
            throw new RuntimeException('The recurring event master has no start.');
        }
        $masterStart = self::parseDateProperty($masterStartProperty);
        $targetStart = $targetStart->setTimezone(self::timezone($masterStart['timezone']));
        $masterEndProperty = self::firstProperty($properties, 'DTEND');
        $masterEnd = $masterEndProperty !== null
            ? self::parseDateProperty($masterEndProperty)
            : self::endFromDuration($masterStart, self::propertyValue($properties, 'DURATION'));

        if ($masterStart['allDay']) {
            $masterStartDate = (new DateTimeImmutable('@' . $masterStart['timestamp']))
                ->setTimezone(self::timezone($masterStart['timezone']));
            $masterEndDate = (new DateTimeImmutable('@' . $masterEnd['timestamp']))
                ->setTimezone(self::timezone($masterStart['timezone']));
            $durationDays = max(1, (int) $masterStartDate->diff($masterEndDate)->format('%a'));
            $targetEnd = $targetStart->add(new DateInterval('P' . $durationDays . 'D'));
        } else {
            $durationSeconds = max(0, $masterEnd['timestamp'] - $masterStart['timestamp']);
            $targetEnd = (new DateTimeImmutable('@' . ($targetStart->getTimestamp() + $durationSeconds)))
                ->setTimezone(self::timezone($masterStart['timezone']));
        }

        $block = $masterBlock;
        foreach (['RRULE', 'RDATE', 'EXDATE', 'EXRULE', 'RECURRENCE-ID'] as $property) {
            self::replaceProperty($block, $property, null);
        }
        self::replaceProperty(
            $block,
            'RECURRENCE-ID',
            self::formatDateLineLike(
                'RECURRENCE-ID',
                $targetStart,
                $masterStart['allDay'],
                $masterStartProperty
            )
        );
        self::replaceProperty(
            $block,
            'DTSTART',
            self::formatDateLineLike('DTSTART', $targetStart, $masterStart['allDay'], $masterStartProperty)
        );
        if ($masterEndProperty !== null || self::propertyValue($properties, 'DURATION') !== '') {
            self::replaceProperty($block, 'DURATION', null);
            self::replaceProperty(
                $block,
                'DTEND',
                self::formatDateLineLike(
                    'DTEND',
                    $targetEnd,
                    $masterStart['allDay'],
                    $masterEndProperty ?? $masterStartProperty
                )
            );
        } else {
            self::replaceProperty($block, 'DTEND', null);
        }

        return $block;
    }

    /**
     * @param list<string> $block
     * @param array<string, mixed> $data
     */
    private static function applyEventChangesToBlock(array &$block, array $data): void
    {
        if (array_key_exists('summary', $data)) {
            $summary = trim((string) $data['summary']);
            if ($summary === '') {
                throw new InvalidArgumentException('The event summary must not be empty.');
            }
            self::replaceProperty($block, 'SUMMARY', 'SUMMARY:' . self::escapeText($summary));
        }
        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $key => $property) {
            if (array_key_exists($key, $data)) {
                $value = trim((string) $data[$key]);
                self::replaceProperty(
                    $block,
                    $property,
                    $value === '' ? null : $property . ':' . self::escapeText($value)
                );
            }
        }
        if (array_key_exists('status', $data)) {
            $status = self::normalizeStatus((string) $data['status']);
            self::replaceProperty($block, 'STATUS', $status === '' ? null : 'STATUS:' . $status);
        }

        $properties = self::readTopLevelProperties($block);
        $currentStart = self::firstProperty($properties, 'DTSTART');
        $currentEnd = self::firstProperty($properties, 'DTEND');
        $allDay = array_key_exists('allDay', $data)
            ? (bool) $data['allDay']
            : ($currentStart !== null && self::parseDateProperty($currentStart)['allDay']);

        if (array_key_exists('start', $data)) {
            $start = self::inputDate($data['start'], $allDay);
            self::replaceProperty(
                $block,
                'DTSTART',
                self::formatDateLineLike(
                    'DTSTART',
                    $start,
                    $allDay,
                    $currentStart ?? ['value' => '', 'params' => []]
                )
            );
        }
        if (array_key_exists('end', $data)) {
            $end = self::inputDate($data['end'], $allDay);
            self::replaceProperty($block, 'DURATION', null);
            self::replaceProperty(
                $block,
                'DTEND',
                self::formatDateLineLike(
                    'DTEND',
                    $end,
                    $allDay,
                    $currentEnd ?? $currentStart ?? ['value' => '', 'params' => []]
                )
            );
        }

        $updatedProperties = self::readTopLevelProperties($block);
        $updatedStart = self::firstProperty($updatedProperties, 'DTSTART');
        $updatedEnd = self::firstProperty($updatedProperties, 'DTEND');
        if ($updatedStart !== null && $updatedEnd !== null
            && self::parseDateProperty($updatedEnd)['timestamp'] <= self::parseDateProperty($updatedStart)['timestamp']) {
            throw new InvalidArgumentException('The event end must be later than the start.');
        }

        self::touchEventBlock($block);
    }

    /** @param list<string> $block */
    private static function touchEventBlock(array &$block): void
    {
        $properties = self::readTopLevelProperties($block);
        $sequence = (int) self::propertyValue($properties, 'SEQUENCE');
        self::replaceProperty($block, 'SEQUENCE', 'SEQUENCE:' . ($sequence + 1));
        self::replaceProperty($block, 'DTSTAMP', 'DTSTAMP:' . gmdate('Ymd\THis\Z'));
        self::replaceProperty($block, 'LAST-MODIFIED', 'LAST-MODIFIED:' . gmdate('Ymd\THis\Z'));
    }

    /**
     * @param array<string, list<array{value: string, params: array<string, string>}>> $properties
     */
    private static function hasExceptionDate(array $properties, DateTimeImmutable $targetStart): bool
    {
        foreach (self::parseDatePropertyList($properties['EXDATE'] ?? []) as $exception) {
            if ($exception['timestamp'] === $targetStart->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{value: string, params: array<string, string>} $template
     */
    private static function formatDateLineLike(
        string $property,
        DateTimeImmutable $date,
        bool $allDay,
        array $template
    ): string {
        if ($allDay) {
            return $property . ';VALUE=DATE:' . $date->format('Ymd');
        }

        $timezoneName = trim((string) ($template['params']['TZID'] ?? ''));
        if ($timezoneName !== '' && preg_match('/^[A-Za-z0-9._+\/-]+$/D', $timezoneName) === 1) {
            return $property . ';TZID=' . $timezoneName . ':'
                . $date->setTimezone(self::timezone($timezoneName))->format('Ymd\THis');
        }

        if (str_ends_with(strtoupper(trim((string) ($template['value'] ?? ''))), 'Z')) {
            return $property . ':' . $date->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        }

        $timezone = 'UTC';
        try {
            $timezone = self::parseDateProperty($template)['timezone'];
        } catch (Throwable) {
            $timezone = date_default_timezone_get();
        }

        return $property . ':' . $date->setTimezone(self::timezone($timezone))->format('Ymd\THis');
    }

    /** @param list<string> $block */
    private static function appendTopLevelProperty(array &$block, string $line): void
    {
        $depth = 0;
        $insertAt = count($block) - 1;
        foreach ($block as $index => $currentLine) {
            $upper = strtoupper($currentLine);
            if (str_starts_with($upper, 'BEGIN:')) {
                if ($depth === 1) {
                    $insertAt = $index;
                    break;
                }
                ++$depth;
                continue;
            }
            if (str_starts_with($upper, 'END:')) {
                if ($depth === 1) {
                    $insertAt = $index;
                    break;
                }
                --$depth;
            }
        }

        array_splice($block, $insertAt, 0, [$line]);
    }

    /**
     * @param list<string> $lines
     * @param list<string> $block
     */
    private static function insertEventBlock(array &$lines, array $block): void
    {
        $insertAt = array_search('END:VCALENDAR', array_map('strtoupper', $lines), true);
        if ($insertAt === false) {
            throw new RuntimeException('The calendar resource is missing END:VCALENDAR.');
        }

        array_splice($lines, $insertAt, 0, $block);
    }

    private static function formatEventDateLine(
        string $property,
        DateTimeImmutable $date,
        bool $allDay,
        string $timezoneName
    ): string {
        if ($allDay || $timezoneName === '') {
            return self::formatDateLine($property, $date, $allDay);
        }

        return $property . ';TZID=' . $timezoneName . ':' . $date->format('Ymd\THis');
    }

    private static function strictTimezone(string $name): DateTimeZone
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^[A-Za-z0-9._+\/-]+$/D', $name) !== 1) {
            throw new InvalidArgumentException('The recurring event timezone is invalid.');
        }

        try {
            return new DateTimeZone($name);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The recurring event timezone is invalid.', 0, $exception);
        }
    }

    /**
     * Builds a compact VTIMEZONE component from PHP timezone transitions.
     *
     * Transition dates are emitted explicitly so the calendar object remains
     * self-contained as required for TZID references in CalDAV resources.
     *
     * @param array<string, mixed> $recurrence
     * @return list<string>
     */
    private static function timezoneComponent(
        DateTimeZone $timezone,
        DateTimeImmutable $start,
        array $recurrence
    ): array {
        $timezoneName = $timezone->getName();
        if (in_array(strtoupper($timezoneName), ['UTC', 'GMT', 'ETC/UTC', 'ETC/GMT'], true)) {
            return [];
        }

        $windowStart = $start->modify('-2 years')->getTimestamp();
        $windowEnd = self::timezoneTransitionEnd($start, $recurrence)->getTimestamp();
        $transitions = $timezone->getTransitions($windowStart, $windowEnd);
        if ($transitions === false || count($transitions) < 2) {
            return [];
        }

        $groups = [];
        $previous = $transitions[0];
        foreach (array_slice($transitions, 1) as $transition) {
            $fromOffset = (int) ($previous['offset'] ?? 0);
            $toOffset = (int) ($transition['offset'] ?? 0);
            if ($fromOffset === $toOffset) {
                $previous = $transition;
                continue;
            }

            $type = (bool) ($transition['isdst'] ?? false) ? 'DAYLIGHT' : 'STANDARD';
            $name = trim((string) ($transition['abbr'] ?? ''));
            $key = implode('|', [$type, $fromOffset, $toOffset, $name]);
            $localTransition = gmdate(
                'Ymd\THis',
                (int) ($transition['ts'] ?? 0) + $fromOffset
            );
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'type'       => $type,
                    'fromOffset' => $fromOffset,
                    'toOffset'   => $toOffset,
                    'name'       => $name,
                    'dates'      => []
                ];
            }
            $groups[$key]['dates'][] = $localTransition;
            $previous = $transition;
        }

        if ($groups === []) {
            return [];
        }

        $lines = [
            'BEGIN:VTIMEZONE',
            'TZID:' . $timezoneName
        ];
        foreach ($groups as $group) {
            $dates = $group['dates'];
            if ($dates === []) {
                continue;
            }
            $lines[] = 'BEGIN:' . $group['type'];
            $lines[] = 'DTSTART:' . array_shift($dates);
            $lines[] = 'TZOFFSETFROM:' . self::timezoneOffset((int) $group['fromOffset']);
            $lines[] = 'TZOFFSETTO:' . self::timezoneOffset((int) $group['toOffset']);
            if ($group['name'] !== '') {
                $lines[] = 'TZNAME:' . self::escapeText((string) $group['name']);
            }
            if ($dates !== []) {
                $lines[] = 'RDATE:' . implode(',', $dates);
            }
            $lines[] = 'END:' . $group['type'];
        }
        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }

    /** @param array<string, mixed> $recurrence */
    private static function timezoneTransitionEnd(DateTimeImmutable $start, array $recurrence): DateTimeImmutable
    {
        $maximum = $start->modify('+100 years');
        if (strtolower(trim((string) ($recurrence['endMode'] ?? 'never'))) !== 'until') {
            return $maximum;
        }

        $until = trim((string) ($recurrence['until'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until) !== 1) {
            return $maximum;
        }
        $untilDate = DateTimeImmutable::createFromFormat('!Y-m-d', $until, $start->getTimezone());
        if ($untilDate === false || $untilDate->format('Y-m-d') !== $until) {
            return $maximum;
        }

        $end = $untilDate->modify('+2 years');
        return $end < $maximum ? $end : $maximum;
    }

    private static function timezoneOffset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        $value = sprintf('%s%02d%02d', $sign, $hours, $minutes);
        return $remainingSeconds === 0
            ? $value
            : $value . sprintf('%02d', $remainingSeconds);
    }

    /**
     * @return list<string>
     */
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
    private static function extractEventBlocks(array $lines): array
    {
        return array_map(
            static fn (array $block): array => $block['lines'],
            self::extractEventBlocksWithOffsets($lines)
        );
    }

    /**
     * @param list<string> $lines
     * @return list<array{start: int, end: int, lines: list<string>}>
     */
    private static function extractEventBlocksWithOffsets(array $lines): array
    {
        $blocks = [];
        $start = null;
        $depth = 0;

        foreach ($lines as $index => $line) {
            $upper = strtoupper($line);
            if ($upper === 'BEGIN:VEVENT' && $start === null) {
                $start = $index;
                $depth = 1;
                continue;
            }
            if ($start === null) {
                continue;
            }
            if (str_starts_with($upper, 'BEGIN:')) {
                $depth++;
            } elseif (str_starts_with($upper, 'END:')) {
                $depth--;
                if ($depth === 0) {
                    $blocks[] = [
                        'start' => $start,
                        'end'   => $index,
                        'lines' => array_slice($lines, $start, $index - $start + 1)
                    ];
                    $start = null;
                }
            }
        }

        return $blocks;
    }

    /**
     * @param list<string> $block
     * @return array<string, list<array{value: string, params: array<string, string>}>>
     */
    private static function readTopLevelProperties(array $block): array
    {
        $properties = [];
        $depth = 0;

        foreach ($block as $line) {
            $upper = strtoupper($line);
            if (str_starts_with($upper, 'BEGIN:')) {
                $depth++;
                continue;
            }
            if (str_starts_with($upper, 'END:')) {
                $depth--;
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
            $value = substr($line, $separator + 1);
            $parts = explode(';', $definition);
            $name = strtoupper((string) array_shift($parts));
            $params = [];
            foreach ($parts as $parameter) {
                $parameterSeparator = strpos($parameter, '=');
                if ($parameterSeparator !== false) {
                    $params[strtoupper(substr($parameter, 0, $parameterSeparator))] = trim(
                        substr($parameter, $parameterSeparator + 1),
                        '"'
                    );
                }
            }
            $properties[$name][] = ['value' => $value, 'params' => $params];
        }

        return $properties;
    }

    /**
     * @param array<string, list<array{value: string, params: array<string, string>}>> $properties
     * @return array{value: string, params: array<string, string>}|null
     */
    private static function firstProperty(array $properties, string $name): ?array
    {
        return $properties[strtoupper($name)][0] ?? null;
    }

    /**
     * @param array<string, list<array{value: string, params: array<string, string>}>> $properties
     */
    private static function propertyValue(array $properties, string $name): string
    {
        return (string) (self::firstProperty($properties, $name)['value'] ?? '');
    }

    /**
     * @param array{value: string, params: array<string, string>} $property
     * @return array{value: string, timestamp: int, allDay: bool, timezone: string}
     */
    private static function parseDateProperty(array $property): array
    {
        $raw = trim($property['value']);
        $allDay = strtoupper($property['params']['VALUE'] ?? '') === 'DATE'
            || preg_match('/^\d{8}$/', $raw) === 1;
        $timezoneName = $property['params']['TZID'] ?? '';

        try {
            if ($allDay) {
                $timezone = self::timezone($timezoneName);
                $date = DateTimeImmutable::createFromFormat('!Ymd', $raw, $timezone);
            } elseif (str_ends_with(strtoupper($raw), 'Z')) {
                $timezone = new DateTimeZone('UTC');
                $format = strlen($raw) === 14 ? '!Ymd\THi\Z' : '!Ymd\THis\Z';
                $date = DateTimeImmutable::createFromFormat($format, strtoupper($raw), $timezone);
            } else {
                $timezone = self::timezone($timezoneName);
                $format = strlen($raw) === 13 ? '!Ymd\THi' : '!Ymd\THis';
                $date = DateTimeImmutable::createFromFormat($format, $raw, $timezone);
            }
        } catch (Throwable) {
            $date = false;
        }

        if ($date === false) {
            throw new RuntimeException('The calendar contains an invalid date value: ' . $raw);
        }

        return [
            'value'     => $allDay ? $date->format('Y-m-d') : $date->format(DATE_ATOM),
            'timestamp' => $date->getTimestamp(),
            'allDay'    => $allDay,
            'timezone'  => $date->getTimezone()->getName()
        ];
    }

    /**
     * @param list<array{value: string, params: array<string, string>}> $properties
     * @return list<array{value: string, timestamp: int, allDay: bool, timezone: string}>
     */
    private static function parseDatePropertyList(array $properties): array
    {
        $result = [];
        foreach ($properties as $property) {
            foreach (explode(',', $property['value']) as $value) {
                $value = trim($value);
                if ($value === '' || str_contains($value, '/')) {
                    continue;
                }
                try {
                    $result[] = self::parseDateProperty([
                        'value'  => $value,
                        'params' => $property['params']
                    ]);
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return $result;
    }

    /**
     * @param array{value: string, timestamp: int, allDay: bool, timezone: string} $start
     * @return array{value: string, timestamp: int, allDay: bool, timezone: string}
     */
    private static function defaultEnd(array $start): array
    {
        if (!$start['allDay']) {
            return $start;
        }

        $timezone = self::timezone($start['timezone']);
        $end = (new DateTimeImmutable('@' . $start['timestamp']))
            ->setTimezone($timezone)
            ->add(new DateInterval('P1D'));

        return [
            'value'     => $end->format('Y-m-d'),
            'timestamp' => $end->getTimestamp(),
            'allDay'    => true,
            'timezone'  => $start['timezone']
        ];
    }

    /**
     * @param array{value: string, timestamp: int, allDay: bool, timezone: string} $start
     * @return array{value: string, timestamp: int, allDay: bool, timezone: string}
     */
    private static function endFromDuration(array $start, string $duration): array
    {
        $duration = strtoupper(trim($duration));
        if ($duration === '') {
            return self::defaultEnd($start);
        }

        try {
            if (str_starts_with($duration, '-')) {
                throw new RuntimeException('Negative event duration.');
            }
            $timezone = self::timezone($start['timezone']);
            $date = (new DateTimeImmutable('@' . $start['timestamp']))
                ->setTimezone($timezone)
                ->add(new DateInterval($duration));

            return [
                'value'     => $start['allDay'] ? $date->format('Y-m-d') : $date->format(DATE_ATOM),
                'timestamp' => $date->getTimestamp(),
                'allDay'    => $start['allDay'],
                'timezone'  => $date->getTimezone()->getName()
            ];
        } catch (Throwable) {
            return self::defaultEnd($start);
        }
    }

    /**
     * @param array{value: string, params: array<string, string>}|null $property
     */
    private static function parseOptionalDate(?array $property): string
    {
        if ($property === null) {
            return '';
        }

        try {
            return self::parseDateProperty($property)['value'];
        } catch (Throwable) {
            return '';
        }
    }

    private static function timezone(string $name): DateTimeZone
    {
        $name = trim($name, " \t\n\r\0\x0B\"");
        try {
            return new DateTimeZone($name !== '' ? $name : date_default_timezone_get());
        } catch (Throwable) {
            foreach (timezone_identifiers_list() as $identifier) {
                if (str_ends_with($name, '/' . $identifier)) {
                    return new DateTimeZone($identifier);
                }
            }

            return new DateTimeZone('UTC');
        }
    }

    private static function inputDate(mixed $value, bool $allDay): DateTimeImmutable
    {
        try {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return (new DateTimeImmutable('@' . (string) $value))->setTimezone(new DateTimeZone('UTC'));
            }
            $text = trim((string) $value);
            if ($text === '') {
                throw new InvalidArgumentException('Empty date.');
            }
            if ($allDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, self::timezone(''));
                if ($date !== false) {
                    return $date;
                }
            }
            return new DateTimeImmutable($text, self::timezone(''));
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The event contains an invalid date.', 0, $exception);
        }
    }

    private static function formatDateLine(string $property, DateTimeImmutable $date, bool $allDay): string
    {
        return $allDay
            ? $property . ';VALUE=DATE:' . $date->format('Ymd')
            : $property . ':' . $date->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private static function normalizeStatus(string $status): string
    {
        $status = strtoupper(trim($status));
        if ($status === '') {
            return '';
        }
        if (!in_array($status, ['TENTATIVE', 'CONFIRMED', 'CANCELLED'], true)) {
            throw new InvalidArgumentException('The event status is invalid.');
        }
        return $status;
    }

    /**
     * @param list<string> $block
     */
    private static function replaceProperty(array &$block, string $name, ?string $replacement): void
    {
        $name = strtoupper($name);
        $depth = 0;
        $matches = [];
        $insertAt = count($block) - 1;

        foreach ($block as $index => $line) {
            $upper = strtoupper($line);
            if (str_starts_with($upper, 'BEGIN:')) {
                if ($depth === 1 && $insertAt === count($block) - 1) {
                    $insertAt = $index;
                }
                $depth++;
                continue;
            }
            if (str_starts_with($upper, 'END:')) {
                $depth--;
                continue;
            }
            if ($depth !== 1) {
                continue;
            }
            $separator = strcspn($line, ';:');
            if (strtoupper(substr($line, 0, $separator)) === $name) {
                $matches[] = $index;
            }
        }

        if ($matches !== []) {
            $first = array_shift($matches);
            if ($replacement === null) {
                array_splice($block, $first, 1);
                $matches = array_map(static fn (int $index): int => $index - 1, $matches);
            } else {
                $block[$first] = $replacement;
            }
            foreach (array_reverse($matches) as $index) {
                array_splice($block, $index, 1);
            }
            return;
        }

        if ($replacement !== null) {
            array_splice($block, $insertAt, 0, [$replacement]);
        }
    }

    private static function escapeText(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\r", "\n", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value
        );
    }

    private static function unescapeText(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\([nN,;\\\\])/',
            static fn (array $matches): string => match ($matches[1]) {
                'n', 'N' => "\n",
                default  => $matches[1]
            },
            $value
        );
    }

    /**
     * @param list<string> $lines
     */
    private static function foldLines(array $lines): string
    {
        $folded = [];
        foreach ($lines as $line) {
            $first = true;
            while (strlen($line) > ($first ? 75 : 74)) {
                $limit = $first ? 75 : 74;
                $part = function_exists('mb_strcut')
                    ? mb_strcut($line, 0, $limit, 'UTF-8')
                    : substr($line, 0, $limit);
                $folded[] = ($first ? '' : ' ') . $part;
                $line = substr($line, strlen($part));
                $first = false;
            }
            $folded[] = ($first ? '' : ' ') . $line;
        }

        return implode("\r\n", $folded) . "\r\n";
    }
}
