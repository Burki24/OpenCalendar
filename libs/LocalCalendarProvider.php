<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use InvalidArgumentException;

require_once __DIR__ . '/CalDAVProvider.php';
require_once __DIR__ . '/LocalCalendarResourceStore.php';
require_once __DIR__ . '/CalendarTaskEvent.php';

/**
 * Offline calendar backed by original iCalendar resources, never by an event cache.
 *
 * The existing DAV writer is used only as an iCalendar transaction engine. Its
 * transport is an in-memory resource store with no sockets, credentials or HTTP
 * client. The owner persists exportResources() after a complete successful
 * operation while holding its storage lock. Discard the instance after failure.
 */
final class LocalCalendarProvider implements CalendarEventLookupProviderInterface, CalendarProviderInterface, RecurringCalendarProviderInterface
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
            'defaultStatus'             => 'CONFIRMED',
            'defaultTransparency'       => 'OPAQUE',
            'defaultAllDayTransparency' => 'OPAQUE',
            'capabilities'              => [
                'create'             => true, 'update' => true, 'delete' => true,
                'createRecurrence'   => true, 'updateRecurrence' => true,
                'updateOccurrence'   => true, 'deleteOccurrence' => true,
                'updateFollowing'    => true, 'updateSeries' => true, 'deleteSeries' => true,
                'writeStatus'        => true, 'writeTransparency' => true,
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
                if ((bool) ($event['recurring'] ?? false)) {
                    $originalStart = (string) ($event['originalStart'] ?? $event['start']);
                    $recurrenceId = (string) ($event['recurrenceId'] ?? '');
                    $event = array_merge($event, CalendarEventRecurrence::occurrence(
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
                $events[] = $event;
            }
        }
        usort($events, static fn (array $left, array $right): int => ($left['startTimestamp'] <=> $right['startTimestamp'])
            ?: strcmp((string) $left['id'], (string) $right['id']));
        return $events;
    }

    /** @inheritDoc */
    public function getEventForEdit(string $calendarReference, array $identity): array
    {
        $this->assertCalendar($calendarReference);
        return $this->writer->getEventForEdit($calendarReference, $identity);
    }

    /** @inheritDoc */
    public function createEvent(string $calendarReference, array $event): array
    {
        $this->assertCalendar($calendarReference);
        $this->assertInputSize($event);
        $event += ['status' => 'CONFIRMED', 'transparency' => 'OPAQUE'];
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
     * Adds the earliest open overdue task from each original resource to a local
     * refresh. Display windows must never prevent tasks from recovering after
     * downtime; ordinary out-of-window events remain excluded.
     *
     * @return list<array<string, mixed>> Visible events and pending task candidates.
     */
    public function getEventsWithOverdueTasks(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $today): array
    {
        $events = $this->getEvents($calendarReference, $start, $end);
        $byId = array_column($events, null, 'id');
        foreach ($this->store->exportResources() as $url => $ical) {
            $originals = ICalendarCodec::parseEvents($ical, $url, $this->store->etag($url));
            $oldest = null;
            foreach ($originals as $original) {
                $task = CalendarTaskEvent::enrich($original);
                if (($task['task'] ?? false) && !($task['taskCompleted'] ?? false)
                    && !CalendarEventState::isCancelled($task['status'] ?? '')
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
                    || CalendarEventState::isCancelled($task['status'] ?? '')) {
                    continue;
                }
                // The direct lookup provides the same writable occurrence identity
                // as the editor, including for a one-occurrence query window.
                $candidate = $this->writer->getEventForEdit($calendarReference, $candidate);
                $byId[$candidate['id']] = $candidate;
                break;
            }
        }
        return array_values($byId);
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
            throw new \UnexpectedValueException('Local calendar storage exceeds 16 MiB.');
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
