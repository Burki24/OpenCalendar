<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use RuntimeException;

require_once __DIR__ . '/CalendarProviderInterface.php';
require_once __DIR__ . '/ICalendarCodec.php';

final class ICalendarFileProviderException extends RuntimeException
{
    /**
     * Creates a local iCalendar file exception.
     */
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}

/**
 * Provides one read-only iCalendar calendar from file content stored in Symcon.
 */
final class ICalendarFileProvider implements CalendarProviderInterface
{
    private const MAX_FILE_SIZE = 16 * 1024 * 1024;

    private string $calendarReference;
    private string $contentHash;
    private string $ical;

    /**
     * Creates a read-only provider from Base64-encoded iCalendar file content.
     */
    public function __construct(
        string $encodedFile,
        private readonly string $configuredName = '',
        string $sourceId = ''
    ) {
        $this->ical = $this->decodeFile($encodedFile);
        $this->validateCalendar($this->ical);
        $this->contentHash = hash('sha256', $this->ical);
        $sourceId = trim($sourceId) !== '' ? trim($sourceId) : $this->contentHash;
        $this->calendarReference = 'urn:ips-kalender:ics-file:' . $sourceId;
    }

    /** @inheritDoc */
    public function testConnection(): array
    {
        return [
            'success'       => true,
            'calendarCount' => 1,
            'eventCount'    => count(ICalendarCodec::parseEvents(
                $this->ical,
                $this->eventResourceReference(),
                $this->contentHash
            )),
            'message'       => 'Local iCalendar file is valid.'
        ];
    }

    /** @inheritDoc */
    public function getCalendars(): array
    {
        $name = trim($this->configuredName);
        if ($name === '') {
            $name = $this->calendarProperty($this->ical, 'X-WR-CALNAME');
        }
        if ($name === '') {
            $name = 'iCalendar';
        }

        return [[
            'id'               => hash('sha256', 'ics-file|' . $this->calendarReference),
            'providerId'       => hash('sha256', 'ics-file|' . $this->calendarReference),
            'reference'        => $this->calendarReference,
            'url'              => '',
            'name'             => $name,
            'description'      => $this->calendarProperty($this->ical, 'X-WR-CALDESC'),
            'color'            => $this->normalizeColor($this->calendarProperty($this->ical, 'X-APPLE-CALENDAR-COLOR')),
            'etag'             => $this->contentHash,
            'components'       => ['VEVENT'],
            'writeAccessKnown' => true,
            'capabilities'     => [
                'read'   => true,
                'create' => false,
                'update' => false,
                'delete' => false
            ]
        ]];
    }

    /** @inheritDoc */
    public function getEvents(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($end <= $start) {
            throw new ICalendarFileProviderException('The event query end must be later than the start.');
        }
        if (trim($calendarReference) !== $this->calendarReference) {
            throw new ICalendarFileProviderException('The requested calendar does not belong to this local iCalendar file.');
        }

        $events = ICalendarCodec::parseEventsInRange(
            $this->ical,
            $this->eventResourceReference(),
            $this->contentHash,
            $start,
            $end
        );

        usort(
            $events,
            static fn (array $left, array $right): int => ($left['startTimestamp'] <=> $right['startTimestamp'])
                ?: strcasecmp((string) $left['summary'], (string) $right['summary'])
        );

        return $events;
    }

    /** @inheritDoc */
    public function createEvent(string $calendarReference, array $event): array
    {
        throw new ICalendarFileProviderException('Local iCalendar files are read-only.');
    }

    /** @inheritDoc */
    public function updateEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $uid,
        array $event
    ): array {
        throw new ICalendarFileProviderException('Local iCalendar files are read-only.');
    }

    /** @inheritDoc */
    public function deleteEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $recurrenceId = ''
    ): bool {
        throw new ICalendarFileProviderException('Local iCalendar files are read-only.');
    }

    private function decodeFile(string $encodedFile): string
    {
        $encodedFile = trim($encodedFile);
        if ($encodedFile === '') {
            throw new ICalendarFileProviderException('No local iCalendar file was selected.');
        }

        if (str_starts_with(strtolower($encodedFile), 'data:')) {
            $separator = strpos($encodedFile, ',');
            if ($separator === false || !str_contains(strtolower(substr($encodedFile, 0, $separator)), ';base64')) {
                throw new ICalendarFileProviderException('The selected local iCalendar file could not be decoded.');
            }
            $encodedFile = substr($encodedFile, $separator + 1);
        }

        $maximumEncodedSize = (int) ceil(self::MAX_FILE_SIZE * 4 / 3) + 4;
        if (strlen($encodedFile) > $maximumEncodedSize) {
            throw new ICalendarFileProviderException('The selected local iCalendar file is too large.');
        }

        $decoded = base64_decode($encodedFile, true);
        if (!is_string($decoded)) {
            throw new ICalendarFileProviderException('The selected local iCalendar file could not be decoded.');
        }
        if (strlen($decoded) > self::MAX_FILE_SIZE) {
            throw new ICalendarFileProviderException('The selected local iCalendar file is too large.');
        }

        if (str_starts_with($decoded, "\xEF\xBB\xBF")) {
            $decoded = substr($decoded, 3);
        }

        return $decoded;
    }

    private function validateCalendar(string $ical): void
    {
        if (preg_match('/(?:^|\R)BEGIN:VCALENDAR(?:\R|$)/i', $ical) !== 1
            || preg_match('/(?:^|\R)END:VCALENDAR(?:\R|$)/i', $ical) !== 1) {
            throw new ICalendarFileProviderException('The selected file is not a valid iCalendar file.');
        }
    }

    private function calendarProperty(string $ical, string $property): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $ical);
        $unfolded = preg_replace("/\n[ \t]/", '', $normalized);
        if (!is_string($unfolded)
            || preg_match('/(?:^|\n)' . preg_quote($property, '/') . '(?:;[^:]*)?:(.*)$/mi', $unfolded, $matches) !== 1) {
            return '';
        }

        return trim(str_replace(['\\n', '\\N', '\\,', '\\;'], ["\n", "\n", ',', ';'], $matches[1]));
    }

    private function normalizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9a-f]{6}(?:[0-9a-f]{2})?$/i', $color) !== 1) {
            return '';
        }

        return strtoupper(substr($color, 0, 7));
    }

    private function eventResourceReference(): string
    {
        return $this->calendarReference . ':event';
    }
}
