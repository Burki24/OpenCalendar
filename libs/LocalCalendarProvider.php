<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

require_once __DIR__ . '/CalDAVProvider.php';
require_once __DIR__ . '/LocalCalendarResourceStore.php';
require_once __DIR__ . '/CalendarTaskEvent.php';

/**
 * Offline calendar backed by original iCalendar resources, never by an event cache.
 *
 * The existing DAV writer is used only as an iCalendar transaction engine. Its
 * transport is an in-memory resource store with no sockets, credentials or HTTP
 * client. The owner persists exportResources() after a complete successful operation.
 */
final class LocalCalendarProvider implements CalendarProviderInterface, RecurringCalendarProviderInterface
{
    private readonly LocalCalendarResourceStore $store;
    private readonly CalDAVProvider $writer;

    /**
     * @param array<string, string> $resources Original resource URL to VCALENDAR content.
     * @param string $calendarReference Stable internal reference for one local calendar.
     */
    public function __construct(array $resources, private readonly string $calendarReference)
    {
        $this->store = new LocalCalendarResourceStore($resources, $calendarReference);
        $this->writer = new CalDAVProvider($this->store, $calendarReference);
    }

    /** @inheritDoc */
    public function testConnection(): array
    {
        return ['success' => true, 'calendarCount' => 1, 'message' => 'Local calendar ready.'];
    }

    /** @inheritDoc */
    public function getCalendars(): array
    {
        return [[
            'id'                        => $this->calendarReference,
            'providerCalendarId'        => $this->calendarReference,
            'url'                       => $this->calendarReference,
            'name'                      => 'Local calendar',
            'provider'                  => 'local',
            'color'                     => '',
            'writeAccessKnown'          => true,
            'timezone'                  => date_default_timezone_get(),
            'capabilities'              => [
                'create'             => true, 'update' => true, 'delete' => true,
                'createRecurrence'   => true, 'updateRecurrence' => true,
                'updateOccurrence'   => true, 'deleteOccurrence' => true,
                'updateFollowing'    => true, 'updateSeries' => true, 'deleteSeries' => true,
                'useDefaultReminder' => false, 'createWithDefaultReminder' => false,
                'maxReminders'       => CalendarEventReminder::MAX_REMINDERS
            ]
        ]];
    }

    /** @inheritDoc */
    public function getEvents(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $this->assertCalendar($calendarReference);
        if ($end <= $start || $end->getTimestamp() - $start->getTimestamp() > 6 * 366 * 86400) {
            throw new InvalidArgumentException('The requested event time range is invalid.');
        }
        $events = [];
        foreach ($this->store->exportResources() as $url => $ical) {
            foreach (ICalendarCodec::parseEventsInRange($ical, $url, $this->store->etag($url), $start, $end) as $event) {
                $events[] = $this->enableOccurrenceWrites($event);
            }
        }
        usort($events, static fn (array $left, array $right): int => ($left['startTimestamp'] <=> $right['startTimestamp'])
            ?: strcmp((string) $left['id'], (string) $right['id']));
        return $events;
    }

    /**
     * Resolves one current event using the same identity fields used by the calendar module.
     *
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    public function getEventForIdentity(string $calendarReference, array $identity): array
    {
        $this->assertCalendar($calendarReference);
        $startTimestamp = (int) ($identity['startTimestamp'] ?? 0);
        $endTimestamp = (int) ($identity['endTimestamp'] ?? 0);
        if ($startTimestamp <= 0) {
            throw new InvalidArgumentException('The selected event start is invalid.');
        }
        if ($endTimestamp <= $startTimestamp) {
            $endTimestamp = $startTimestamp + 1;
        }
        $start = new DateTimeImmutable('@' . max(1, $startTimestamp - 86400));
        $end = new DateTimeImmutable('@' . ($endTimestamp + 86400));
        $events = $this->getEvents($calendarReference, $start, $end);
        $matches = array_values(array_filter($events, fn (array $event): bool => $this->matchesIdentity($event, $identity)));
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches === []) {
            throw new CalDAVProviderException('The selected event is no longer available.', 404);
        }
        throw new RuntimeException('The selected event could not be identified uniquely.');
    }

    /** @inheritDoc */
    public function createEvent(string $calendarReference, array $event): array
    {
        $this->assertCalendar($calendarReference);
        $this->assertInputSize($event);
        return $this->writer->createEvent($calendarReference, $event);
    }

    /** @inheritDoc */
    public function updateEvent(string $calendarReference, string $eventReference, string $etag, string $uid, array $event, array $recurrence = []): array
    {
        $this->assertCalendar($calendarReference);
        $this->assertInputSize($event);
        $this->assertVersion($eventReference, $etag);
        return $this->writer->updateEvent($calendarReference, $eventReference, $etag, $uid, $event, $recurrence);
    }

    /** @inheritDoc */
    public function deleteEvent(string $calendarReference, string $eventReference, string $etag, string $recurrenceId = '', array $recurrence = []): bool
    {
        $this->assertCalendar($calendarReference);
        $this->assertVersion($eventReference, $etag);
        return $this->writer->deleteEvent($calendarReference, $eventReference, $etag, $recurrenceId, $recurrence);
    }

    /** @inheritDoc */
    public function getRecurringSeries(string $calendarReference, string $seriesId, string $resourceReference = ''): array
    {
        $this->assertCalendar($calendarReference);
        return $this->writer->getRecurringSeries($calendarReference, $seriesId, $resourceReference);
    }

    /** @inheritDoc */
    public function getRecurringFollowing(string $calendarReference, string $seriesId, string $occurrenceId, string $originalStart, string $resourceReference = ''): array
    {
        $this->assertCalendar($calendarReference);
        return $this->writer->getRecurringFollowing($calendarReference, $seriesId, $occurrenceId, $originalStart, $resourceReference);
    }

    /** @return array<string, string> Original resources for one atomic durable save. */
    public function exportResources(): array
    {
        return $this->store->exportResources();
    }

    /**
     * Adds the earliest open overdue task from each original resource to a local refresh.
     *
     * @return list<array<string, mixed>>
     */
    public function getEventsWithOverdueTasks(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $today): array
    {
        $events = $this->getEvents($calendarReference, $start, $end);
        $byId = array_column($events, null, 'id');
        foreach ($this->store->exportResources() as $url => $ical) {
            $oldest = null;
            foreach (ICalendarCodec::parseEvents($ical, $url, $this->store->etag($url)) as $event) {
                $task = CalendarTaskEvent::enrich($event);
                if (($task['task'] ?? false) && !($task['taskCompleted'] ?? false)
                    && strtoupper((string) ($task['status'] ?? '')) !== 'CANCELLED'
                    && $task['startTimestamp'] < $today->getTimestamp()) {
                    $oldest = $oldest === null ? $task['startTimestamp'] : min($oldest, $task['startTimestamp']);
                }
            }
            if ($oldest === null) {
                continue;
            }
            $candidates = ICalendarCodec::parseEventsInRange($ical, $url, $this->store->etag($url), new DateTimeImmutable('@' . $oldest), $today);
            usort($candidates, static fn (array $left, array $right): int => $left['startTimestamp'] <=> $right['startTimestamp']);
            foreach ($candidates as $candidate) {
                $task = CalendarTaskEvent::enrich($candidate);
                if (!($task['task'] ?? false) || ($task['taskCompleted'] ?? false)
                    || strtoupper((string) ($task['status'] ?? '')) === 'CANCELLED') {
                    continue;
                }
                $candidate = $this->enableOccurrenceWrites($candidate);
                $byId[$candidate['id']] = $candidate;
                break;
            }
        }
        return array_values($byId);
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private function enableOccurrenceWrites(array $event): array
    {
        if ((bool) ($event['recurring'] ?? false)) {
            $originalStart = (string) ($event['originalStart'] ?? $event['start']);
            $recurrenceId = (string) ($event['recurrenceId'] ?? '');
            return array_merge($event, CalendarEventRecurrence::occurrence(
                (string) $event['uid'],
                $event['uid'] . '|' . ($recurrenceId !== '' ? $recurrenceId : $originalStart),
                $originalStart,
                $recurrenceId,
                true,
                false,
                true,
                true,
                true
            ));
        }
        return $event;
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $identity */
    private function matchesIdentity(array $event, array $identity): bool
    {
        $primary = [
            'occurrenceId'   => 'occurrenceId',
            'eventReference' => 'eventReference',
            'resourceUrl'    => 'resourceUrl',
            'uid'            => 'uid'
        ];
        $matched = false;
        foreach ($primary as $identityKey => $eventKey) {
            $expected = trim((string) ($identity[$identityKey] ?? ''));
            $actual = trim((string) ($event[$eventKey] ?? ''));
            if ($expected === '' || $actual === '') {
                continue;
            }
            if (!hash_equals($expected, $actual)) {
                return false;
            }
            $matched = true;
            break;
        }
        if (!$matched) {
            return false;
        }
        foreach (['originalStart', 'recurrenceId'] as $key) {
            $expected = trim((string) ($identity[$key] ?? ''));
            $actual = trim((string) ($event[$key] ?? ''));
            if ($expected !== '' && $actual !== '' && !hash_equals($expected, $actual)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $event */
    private function assertInputSize(array $event): void
    {
        $bytes = 0;
        array_walk_recursive($event, static function (mixed $value) use (&$bytes): void
        {
            if (is_string($value)) {
                $bytes += strlen($value);
            }
        });
        if ($bytes > 16_777_216) {
            throw new UnexpectedValueException('Local calendar storage exceeds 16 MiB.');
        }
    }

    private function assertCalendar(string $reference): void
    {
        if (!hash_equals($this->calendarReference, $reference)) {
            throw new InvalidArgumentException('The local calendar identity is invalid.');
        }
    }

    private function assertVersion(string $resource, string $etag): void
    {
        $current = $this->store->etag($resource);
        if ($current === '') {
            throw new CalDAVProviderException('The selected event is no longer available.', 404);
        }
        if ($etag === '' || !hash_equals($current, $etag)) {
            throw new CalDAVProviderException('The event was changed by another client.', 412);
        }
    }
}
