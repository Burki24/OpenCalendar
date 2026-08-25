<?php

declare(strict_types=1);

namespace IPSKalender;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/CalendarProviderInterface.php';
require_once __DIR__ . '/RecurringCalendarProviderInterface.php';
require_once __DIR__ . '/CalendarHttpClient.php';
require_once __DIR__ . '/CalendarEventRecurrence.php';
require_once __DIR__ . '/CalendarEventReminder.php';
require_once __DIR__ . '/CalendarEventState.php';
require_once __DIR__ . '/CalendarRecurrenceRule.php';

final class MicrosoftCalendarProviderException extends RuntimeException
{
    /**
     * Creates a Microsoft Calendar provider exception with optional Graph error metadata.
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $errorCode = ''
    ) {
        parent::__construct($message);
    }
}

final class MicrosoftCalendarProvider implements CalendarProviderInterface, RecurringCalendarProviderInterface
{
    private const API_URL = 'https://graph.microsoft.com/v1.0';
    private const MAX_PAGES = 100;
    private const MAX_CALENDARS = 10_000;
    private const MAX_EVENTS = 100_000;

    /**
     * Creates a Microsoft Graph calendar provider using a delegated OAuth access token.
     */
    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly string $accessToken
    ) {
        if (trim($accessToken) === '') {
            throw new MicrosoftCalendarProviderException('Microsoft 365 is not connected yet.', 401);
        }
    }

    /** @inheritDoc */
    public function testConnection(): array
    {
        $calendars = $this->getCalendars();

        return [
            'success'       => true,
            'calendarCount' => count($calendars),
            'message'       => 'Connection successful.'
        ];
    }

    /** @inheritDoc */
    public function getCalendars(): array
    {
        $calendars = [];
        $url = self::API_URL . '/me/calendars?$top=100';
        $pageCount = 0;
        $seenPageUrls = [];

        while ($url !== '') {
            $this->assertPageUrlProgress($url, $seenPageUrls, ++$pageCount);
            $data = $this->requestJsonUrl('GET', $url);
            foreach (($data['value'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $providerId = trim((string) ($item['id'] ?? ''));
                if ($providerId === '') {
                    continue;
                }
                $canWrite = (bool) ($item['canEdit'] ?? false);
                $owner = is_array($item['owner'] ?? null) ? $item['owner'] : [];

                $calendars[] = [
                    'id'               => hash('sha256', 'microsoft|' . $providerId),
                    'providerId'       => $providerId,
                    'reference'        => $providerId,
                    'url'              => $this->calendarUrl($providerId),
                    'name'             => trim((string) ($item['name'] ?? $providerId)),
                    'description'      => '',
                    'color'            => $this->normalizeColor((string) ($item['hexColor'] ?? '')),
                    'etag'             => trim((string) ($item['changeKey'] ?? '')),
                    'syncToken'        => '',
                    'timezone'         => '',
                    'primary'          => (bool) ($item['isDefaultCalendar'] ?? false),
                    'accessRole'       => $canWrite ? 'writer' : 'reader',
                    'owner'            => trim((string) ($owner['address'] ?? '')),
                    'components'       => ['VEVENT'],
                    'writeAccessKnown' => true,
                    'capabilities'     => [
                        'read'                      => true,
                        'create'                    => $canWrite,
                        'update'                    => $canWrite,
                        'delete'                    => $canWrite,
                        'createRecurrence'          => $canWrite,
                        'updateRecurrence'          => $canWrite,
                        'updateOccurrence'          => $canWrite,
                        'deleteOccurrence'          => $canWrite,
                        'updateFollowing'           => $canWrite,
                        'updateSeries'              => $canWrite,
                        'deleteSeries'              => $canWrite,
                        'createWithDefaultReminder' => $canWrite,
                        'maxReminders'              => 1
                    ]
                ];
                if (count($calendars) > self::MAX_CALENDARS) {
                    throw new MicrosoftCalendarProviderException('Microsoft Calendar returned too many calendars.');
                }
            }

            $url = $this->nextLink($data);
        }

        usort($calendars, static function (array $left, array $right): int
        {
            return ((int) ($right['primary'] ?? false) <=> (int) ($left['primary'] ?? false))
                ?: strcasecmp((string) $left['name'], (string) $right['name']);
        });

        return $calendars;
    }

    /** @inheritDoc */
    public function getEvents(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($end <= $start) {
            throw new MicrosoftCalendarProviderException('The event query end must be later than the start.');
        }

        $calendarId = $this->calendarId($calendarReference);
        $query = http_build_query(
            [
                'startDateTime' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'endDateTime'   => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                '$top'          => '1000'
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $url = self::API_URL . '/me/calendars/' . rawurlencode($calendarId) . '/calendarView?' . $query;
        $events = [];
        $pageCount = 0;
        $seenPageUrls = [];

        while ($url !== '') {
            $this->assertPageUrlProgress($url, $seenPageUrls, ++$pageCount);
            $data = $this->requestJsonUrl(
                'GET',
                $url,
                null,
                ['Prefer' => 'outlook.body-content-type="text", outlook.timezone="UTC"']
            );
            foreach (($data['value'] ?? []) as $item) {
                if (!is_array($item) || (bool) ($item['isCancelled'] ?? false)) {
                    continue;
                }
                $mapped = $this->mapEvent($calendarId, $item);
                if ($mapped !== null) {
                    $events[] = $mapped;
                    if (count($events) > self::MAX_EVENTS) {
                        throw new MicrosoftCalendarProviderException('Microsoft Calendar returned too many events.');
                    }
                }
            }
            $url = $this->nextLink($data);
        }

        usort(
            $events,
            static fn (array $left, array $right): int => ($left['startTimestamp'] <=> $right['startTimestamp'])
                ?: strcasecmp((string) $left['summary'], (string) $right['summary'])
        );

        return $events;
    }

    /** @inheritDoc */
    public function getRecurringSeries(
        string $calendarReference,
        string $seriesId,
        string $resourceReference = ''
    ): array {
        $seriesData = $this->recurringSeriesData($calendarReference, $seriesId);
        $mapped = array_merge(
            $seriesData['event'],
            CalendarEventRecurrence::master(trim($seriesId), true, true)
        );
        $mapped['recurrenceEditable'] = $seriesData['settings'] !== null;
        $mapped['recurrenceSettings'] = $seriesData['settings'] ?? [];

        return $mapped;
    }

    /** @inheritDoc */
    public function getRecurringFollowing(
        string $calendarReference,
        string $seriesId,
        string $occurrenceId,
        string $originalStart,
        string $resourceReference = ''
    ): array {
        $seriesId = trim($seriesId);
        $seriesData = $this->recurringSeriesData($calendarReference, $seriesId);
        if ($seriesData['settings'] === null) {
            throw new MicrosoftCalendarProviderException('The recurrence pattern cannot be split safely.');
        }

        $target = $this->verifiedRecurringOccurrence(
            $seriesData['calendarId'],
            $seriesId,
            trim($occurrenceId),
            trim($originalStart)
        );
        $targetDate = $this->recurrenceTargetDate($target, $seriesData['item']);
        $settings = $seriesData['settings'];
        if (($settings['endMode'] ?? '') === 'count') {
            $settings['count'] = CalendarRecurrenceRule::remainingMicrosoftOccurrenceCount(
                $seriesData['recurrence'],
                $targetDate
            );
        }

        $series = $seriesData['event'];
        foreach (['start', 'end', 'startTimestamp', 'endTimestamp', 'allDay', 'timezone'] as $key) {
            $series[$key] = $target[$key];
        }
        $series['eventReference'] = $target['eventReference'];
        $series['resourceUrl'] = $target['resourceUrl'];
        $series['etag'] = $target['etag'];
        $series['recurrenceSettings'] = $settings;
        $series = array_merge(
            $series,
            CalendarEventRecurrence::occurrence(
                $seriesId,
                (string) $target['occurrenceId'],
                (string) $target['originalStart'],
                '',
                true,
                ($target['recurrenceType'] ?? '') === CalendarEventRecurrence::EXCEPTION,
                true,
                true,
                true
            )
        );
        $series['recurrenceEditable'] = true;
        $series['writeScope'] = CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING;

        return $series;
    }

    /** @inheritDoc */
    public function createEvent(string $calendarReference, array $event): array
    {
        $calendarId = $this->calendarId($calendarReference);
        $payload = $this->buildEventPayload($event, true);
        if ((bool) ($event['allDay'] ?? false)) {
            $payload['showAs'] = 'free';
        }
        $created = $this->requestJson(
            'POST',
            '/me/calendars/' . rawurlencode($calendarId) . '/events',
            $payload,
            [],
            [201]
        );

        return $this->writeResult($calendarId, $created);
    }

    /** @inheritDoc */
    public function updateEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $uid,
        array $event,
        array $recurrence = []
    ): array {
        $calendarId = $this->calendarId($calendarReference);
        $eventId = $this->eventId($eventReference);
        $identity = $this->assertWritableEventTarget($recurrence, $eventId, true);
        $recurrenceType = (string) ($identity['recurrenceType'] ?? CalendarEventRecurrence::SINGLE);
        if (($identity['writeScope'] ?? '') === CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING) {
            return $this->updateFollowingInstances($calendarId, $eventId, $event, $identity);
        }

        $seriesUpdate = ($identity['writeScope'] ?? '') === CalendarEventRecurrence::WRITE_SCOPE_SERIES;
        $singleRecurrenceUpdate = $recurrenceType === CalendarEventRecurrence::SINGLE
            && is_array($event['recurrence'] ?? null)
            && $event['recurrence'] !== [];
        $targetEventId = $seriesUpdate
            ? trim((string) ($identity['seriesId'] ?? ''))
            : $eventId;
        if ($targetEventId === '') {
            throw new MicrosoftCalendarProviderException('The recurring series ID is missing.');
        }
        if (array_key_exists('description', $event)) {
            $this->assertDescriptionEditable($calendarId, $targetEventId);
        }
        $headers = $etag !== '' ? ['If-Match' => $etag] : [];
        $updated = $this->requestJson(
            'PATCH',
            '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($targetEventId),
            $this->buildEventPayload($event, false, $seriesUpdate || $singleRecurrenceUpdate),
            $headers,
            [200]
        );

        return $this->writeResult($calendarId, $updated);
    }

    /** @inheritDoc */
    public function deleteEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $recurrenceId = '',
        array $recurrence = []
    ): bool {
        $calendarId = $this->calendarId($calendarReference);
        $eventId = $this->eventId($eventReference);
        $identity = $this->assertWritableEventTarget($recurrence, $eventId, false);
        if (($identity['writeScope'] ?? '') === CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING) {
            return $this->deleteFollowingInstances($calendarId, $eventId, $identity);
        }

        $seriesDelete = ($identity['writeScope'] ?? '') === CalendarEventRecurrence::WRITE_SCOPE_SERIES;
        $targetEventId = $seriesDelete
            ? trim((string) ($identity['seriesId'] ?? ''))
            : $eventId;
        if ($targetEventId === '') {
            throw new MicrosoftCalendarProviderException('The recurring series ID is missing.');
        }
        $headers = !$seriesDelete && $etag !== '' ? ['If-Match' => $etag] : [];
        $this->requestJson(
            'DELETE',
            '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($targetEventId),
            null,
            $headers,
            [204]
        );

        return true;
    }

    /**
     * Applies a "this and following" update by splitting a Microsoft recurring series.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    private function updateFollowingInstances(
        string $calendarId,
        string $eventId,
        array $event,
        array $identity
    ): array {
        $seriesId = trim((string) ($identity['seriesId'] ?? ''));
        $originalStart = trim((string) ($identity['originalStart'] ?? ''));
        $seriesData = $this->recurringSeriesData($calendarId, $seriesId);
        if ($seriesData['settings'] === null) {
            throw new MicrosoftCalendarProviderException('The recurrence pattern cannot be split safely.');
        }
        if (!is_array($event['recurrence'] ?? null) || $event['recurrence'] === []) {
            throw new MicrosoftCalendarProviderException(
                'The recurrence settings are required when splitting a recurring event.'
            );
        }

        $target = $this->verifiedRecurringOccurrence($calendarId, $seriesId, $eventId, $originalStart);
        $targetDate = $this->recurrenceTargetDate($target, $seriesData['item']);
        $position = CalendarRecurrenceRule::microsoftOccurrencePosition(
            $seriesData['recurrence'],
            $targetDate
        );
        $parentItem = $seriesData['item'];
        $parentEtag = trim((string) ($parentItem['@odata.etag'] ?? $parentItem['changeKey'] ?? ''));
        $parentHeaders = $parentEtag !== '' ? ['If-Match' => $parentEtag] : [];

        if ($position === 1) {
            if (array_key_exists('description', $event)) {
                $this->assertDescriptionEditable($calendarId, $seriesId);
            }
            $updated = $this->requestJson(
                'PATCH',
                '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId),
                $this->buildEventPayload($event, false, true),
                $parentHeaders,
                [200]
            );

            return $this->writeResult($calendarId, $updated);
        }

        $newEventPayload = array_replace(
            $this->splitEventBasePayload($parentItem),
            $this->buildEventPayload($event, true)
        );
        $trimmedRecurrence = CalendarRecurrenceRule::trimMicrosoftRecurrenceBefore(
            $seriesData['recurrence'],
            $targetDate
        );
        $trimmedParent = $this->requestJson(
            'PATCH',
            '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId),
            ['recurrence' => $trimmedRecurrence],
            $parentHeaders,
            [200]
        );

        try {
            $created = $this->requestJson(
                'POST',
                '/me/calendars/' . rawurlencode($calendarId) . '/events',
                $newEventPayload,
                [],
                [201]
            );
        } catch (Throwable $exception) {
            $rollbackEtag = trim((string) ($trimmedParent['@odata.etag'] ?? $trimmedParent['changeKey'] ?? ''));
            $rollbackHeaders = $rollbackEtag !== '' ? ['If-Match' => $rollbackEtag] : [];
            try {
                $this->requestJson(
                    'PATCH',
                    '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId),
                    ['recurrence' => $seriesData['recurrence']],
                    $rollbackHeaders,
                    [200]
                );
            } catch (Throwable) {
                throw new MicrosoftCalendarProviderException(
                    'The new recurring series could not be created and the original series could not be restored automatically.'
                );
            }

            throw $exception;
        }

        return $this->writeResult($calendarId, $created);
    }

    /**
     * Deletes a Microsoft recurring event from the selected occurrence onward.
     *
     * @param array<string, mixed> $identity
     */
    private function deleteFollowingInstances(string $calendarId, string $eventId, array $identity): bool
    {
        $seriesId = trim((string) ($identity['seriesId'] ?? ''));
        $originalStart = trim((string) ($identity['originalStart'] ?? ''));
        $seriesData = $this->recurringSeriesData($calendarId, $seriesId);
        if ($seriesData['settings'] === null) {
            throw new MicrosoftCalendarProviderException('The recurrence pattern cannot be split safely.');
        }

        $target = $this->verifiedRecurringOccurrence($calendarId, $seriesId, $eventId, $originalStart);
        $targetDate = $this->recurrenceTargetDate($target, $seriesData['item']);
        $position = CalendarRecurrenceRule::microsoftOccurrencePosition(
            $seriesData['recurrence'],
            $targetDate
        );
        $parentItem = $seriesData['item'];
        $parentEtag = trim((string) ($parentItem['@odata.etag'] ?? $parentItem['changeKey'] ?? ''));
        $parentHeaders = $parentEtag !== '' ? ['If-Match' => $parentEtag] : [];

        if ($position === 1) {
            $this->requestJson(
                'DELETE',
                '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId),
                null,
                $parentHeaders,
                [204]
            );

            return true;
        }

        $this->requestJson(
            'PATCH',
            '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId),
            [
                'recurrence' => CalendarRecurrenceRule::trimMicrosoftRecurrenceBefore(
                    $seriesData['recurrence'],
                    $targetDate
                )
            ],
            $parentHeaders,
            [200]
        );

        return true;
    }

    /**
     * Loads and validates the Microsoft series master and its supported recurrence settings.
     *
     * @return array{calendarId: string, item: array<string, mixed>, event: array<string, mixed>, recurrence: array<string, mixed>, settings: array<string, mixed>|null}
     */
    private function recurringSeriesData(string $calendarReference, string $seriesId): array
    {
        $calendarId = $this->calendarId($calendarReference);
        $seriesId = trim($seriesId);
        if ($seriesId === '') {
            throw new MicrosoftCalendarProviderException('The recurring series ID is missing.');
        }

        $item = $this->requestJson(
            'GET',
            '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId),
            null,
            ['Prefer' => 'outlook.body-content-type="text", outlook.timezone="UTC"']
        );
        $mapped = $this->mapEvent($calendarId, $item);
        if ($mapped === null
            || ($mapped['recurrenceType'] ?? '') !== CalendarEventRecurrence::MASTER
            || !hash_equals($seriesId, (string) ($mapped['seriesId'] ?? ''))) {
            throw new MicrosoftCalendarProviderException('Microsoft Calendar did not return the recurring parent event.');
        }

        $recurrence = is_array($item['recurrence'] ?? null) ? $item['recurrence'] : [];
        $range = is_array($recurrence['range'] ?? null) ? $recurrence['range'] : [];
        $rangeStart = trim((string) ($range['startDate'] ?? ''));
        $recurrenceStart = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $rangeStart) === 1
            ? new DateTimeImmutable($rangeStart . 'T00:00:00Z')
            : new DateTimeImmutable((string) ($mapped['start'] ?? 'now'));

        return [
            'calendarId' => $calendarId,
            'item'       => $item,
            'event'      => $mapped,
            'recurrence' => $recurrence,
            'settings'   => CalendarRecurrenceRule::fromMicrosoftRecurrence($recurrence, $recurrenceStart)
        ];
    }

    /**
     * Returns one verified occurrence or exception of the selected Microsoft series.
     *
     * @return array<string, mixed>
     */
    private function verifiedRecurringOccurrence(
        string $calendarId,
        string $seriesId,
        string $occurrenceId,
        string $originalStart
    ): array {
        if ($seriesId === '' || $occurrenceId === '' || $originalStart === '') {
            throw new MicrosoftCalendarProviderException('The recurring occurrence identity is incomplete.');
        }

        $item = $this->requestJson(
            'GET',
            '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($occurrenceId),
            null,
            ['Prefer' => 'outlook.body-content-type="text", outlook.timezone="UTC"']
        );
        $mapped = $this->mapEvent($calendarId, $item);
        if ($mapped === null
            || !CalendarEventRecurrence::isOccurrence($mapped)
            || !hash_equals($seriesId, (string) ($mapped['seriesId'] ?? ''))
            || !hash_equals($occurrenceId, (string) ($mapped['occurrenceId'] ?? ''))
            || !$this->sameOriginalStart($originalStart, (string) ($mapped['originalStart'] ?? ''), $mapped)) {
            throw new MicrosoftCalendarProviderException('The recurring target occurrence could not be verified.');
        }

        return $mapped;
    }

    /**
     * Returns the local recurrence date of the selected original occurrence start.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $parentItem
     */
    private function recurrenceTargetDate(array $target, array $parentItem): string
    {
        $originalStart = trim((string) ($target['originalStart'] ?? ''));
        if ($originalStart === '') {
            throw new MicrosoftCalendarProviderException('The recurring target start is missing.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $originalStart) === 1) {
            return $originalStart;
        }

        $recurrence = is_array($parentItem['recurrence'] ?? null) ? $parentItem['recurrence'] : [];
        $range = is_array($recurrence['range'] ?? null) ? $recurrence['range'] : [];
        $timezoneName = trim((string) ($range['recurrenceTimeZone']
            ?? $parentItem['originalStartTimeZone']
            ?? $target['timezone']
            ?? 'UTC'));
        try {
            return (new DateTimeImmutable($originalStart))
                ->setTimezone($this->timezone($timezoneName))
                ->format('Y-m-d');
        } catch (Throwable) {
            throw new MicrosoftCalendarProviderException('The recurring target start is invalid.');
        }
    }

    /**
     * Compares two provider occurrence starts without relying on their textual offset format.
     *
     * @param array<string, mixed> $event
     */
    private function sameOriginalStart(string $left, string $right, array $event): bool
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '' || $right === '') {
            return false;
        }
        $allDay = (bool) ($event['allDay'] ?? false);
        if ($allDay) {
            return substr($left, 0, 10) === substr($right, 0, 10);
        }

        try {
            return (new DateTimeImmutable($left))->getTimestamp() === (new DateTimeImmutable($right))->getTimestamp();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Builds the writable fields that are preserved when the future part becomes a new series.
     *
     * @param array<string, mixed> $parentItem
     * @return array<string, mixed>
     */
    private function splitEventBasePayload(array $parentItem): array
    {
        if ((bool) ($parentItem['isOnlineMeeting'] ?? false)) {
            throw new MicrosoftCalendarProviderException(
                'This and following updates are not supported for Microsoft online meetings.'
            );
        }
        if ((bool) ($parentItem['hasAttachments'] ?? false)) {
            throw new MicrosoftCalendarProviderException(
                'This and following updates are not supported for recurring events with attachments.'
            );
        }

        $payload = [];
        foreach ([
            'body',
            'location',
            'categories',
            'showAs',
            'sensitivity',
            'importance',
            'isReminderOn',
            'reminderMinutesBeforeStart',
            'responseRequested',
            'allowNewTimeProposals',
            'hideAttendees'
        ] as $key) {
            if (array_key_exists($key, $parentItem)) {
                $payload[$key] = $parentItem[$key];
            }
        }

        $attendees = [];
        foreach (is_array($parentItem['attendees'] ?? null) ? $parentItem['attendees'] : [] as $attendee) {
            if (!is_array($attendee) || !is_array($attendee['emailAddress'] ?? null)) {
                continue;
            }
            $address = trim((string) ($attendee['emailAddress']['address'] ?? ''));
            if ($address === '') {
                continue;
            }
            $emailAddress = ['address' => $address];
            $name = trim((string) ($attendee['emailAddress']['name'] ?? ''));
            if ($name !== '') {
                $emailAddress['name'] = $name;
            }
            $entry = ['emailAddress' => $emailAddress];
            $type = strtolower(trim((string) ($attendee['type'] ?? 'required')));
            if (in_array($type, ['required', 'optional', 'resource'], true)) {
                $entry['type'] = $type;
            }
            $attendees[] = $entry;
        }
        if ($attendees !== []) {
            $payload['attendees'] = $attendees;
        }

        return $payload;
    }

    /** @param array<string, true> $seenPageUrls */
    private function assertPageUrlProgress(string $url, array &$seenPageUrls, int $pageCount): void
    {
        if ($pageCount > self::MAX_PAGES) {
            throw new MicrosoftCalendarProviderException('Microsoft Calendar pagination exceeded the safe page limit.');
        }
        if (isset($seenPageUrls[$url])) {
            throw new MicrosoftCalendarProviderException('Microsoft Calendar returned a repeated pagination link.');
        }

        $seenPageUrls[$url] = true;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function mapEvent(string $calendarId, array $item): ?array
    {
        $eventId = trim((string) ($item['id'] ?? ''));
        $startData = is_array($item['start'] ?? null) ? $item['start'] : [];
        $endData = is_array($item['end'] ?? null) ? $item['end'] : [];
        if ($eventId === '' || $startData === []) {
            return null;
        }

        $allDay = (bool) ($item['isAllDay'] ?? false);
        $start = $this->parseGraphDateTime($startData, $allDay);
        if ($endData === []) {
            $end = $allDay ? $start->add(new DateInterval('P1D')) : $start;
        } else {
            $end = $this->parseGraphDateTime($endData, $allDay);
        }
        $timezone = trim((string) ($item['originalStartTimeZone'] ?? $startData['timeZone'] ?? 'UTC'));
        $body = is_array($item['body'] ?? null) ? $item['body'] : [];
        $location = is_array($item['location'] ?? null) ? $item['location'] : [];
        $seriesMasterId = trim((string) ($item['seriesMasterId'] ?? ''));
        $originalStart = trim((string) ($item['originalStart'] ?? ''));
        $type = strtolower(trim((string) ($item['type'] ?? 'singleInstance')));
        if ($originalStart === '' && $type === 'occurrence') {
            $originalStart = $allDay ? $start->format('Y-m-d') : $start->format(DATE_ATOM);
        }
        $recurrenceIdentity = match ($type) {
            'seriesmaster'            => CalendarEventRecurrence::master($eventId, true, true),
            'occurrence', 'exception' => CalendarEventRecurrence::occurrence(
                $seriesMasterId,
                $eventId,
                $originalStart,
                '',
                $seriesMasterId !== '',
                $type === 'exception',
                true,
                true,
                $seriesMasterId !== '' && $originalStart !== ''
            ),
            default => CalendarEventRecurrence::single()
        };
        $resourceUrl = $this->eventUrl($calendarId, $eventId);
        $status = (bool) ($item['isCancelled'] ?? false)
            ? CalendarEventState::STATUS_CANCELLED
            : CalendarEventState::STATUS_CONFIRMED;
        $showAs = strtolower(trim((string) ($item['showAs'] ?? '')));
        $transparency = $showAs === 'free'
            ? CalendarEventState::TRANSP_TRANSPARENT
            : CalendarEventState::TRANSP_OPAQUE;

        return array_merge([
            'id'             => hash('sha256', 'microsoft|' . $calendarId . '|' . $eventId),
            'uid'            => trim((string) ($item['iCalUId'] ?? $eventId)),
            'eventReference' => $eventId,
            'resourceUrl'    => $resourceUrl,
            'etag'           => trim((string) ($item['@odata.etag'] ?? $item['changeKey'] ?? '')),
            'summary'        => trim((string) ($item['subject'] ?? '')),
            'description'    => (string) ($body['content'] ?? $item['bodyPreview'] ?? ''),
            'location'       => trim((string) ($location['displayName'] ?? '')),
            'start'          => $allDay ? $start->format('Y-m-d') : $start->format(DATE_ATOM),
            'end'            => $allDay ? $end->format('Y-m-d') : $end->format(DATE_ATOM),
            'startTimestamp' => $start->getTimestamp(),
            'endTimestamp'   => $end->getTimestamp(),
            'allDay'         => $allDay,
            'timezone'       => $timezone !== '' ? $timezone : 'UTC',
            'status'         => $status,
            'transparency'   => $transparency,
            'recurrenceRule' => '',
            'sequence'       => 0,
            'created'        => trim((string) ($item['createdDateTime'] ?? '')),
            'lastModified'   => trim((string) ($item['lastModifiedDateTime'] ?? '')),
            'url'            => trim((string) ($item['webLink'] ?? '')),
            'onlineMeeting'  => (bool) ($item['isOnlineMeeting'] ?? false),
            'reminder'       => $this->mapReminder($item)
        ], $recurrenceIdentity);
    }

    /**
     * @param array<string, mixed> $item
     * @return array{mode: string, minutesBeforeStart: int|null, editable: bool}
     */
    private function mapReminder(array $item): array
    {
        if (!(bool) ($item['isReminderOn'] ?? false)) {
            return CalendarEventReminder::none();
        }

        $minutes = filter_var(
            $item['reminderMinutesBeforeStart'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => CalendarEventReminder::MAX_MINUTES_BEFORE_START]]
        );
        if ($minutes === false) {
            return CalendarEventReminder::complex();
        }

        return CalendarEventReminder::custom((int) $minutes);
    }

    /**
     * @param array<string, mixed> $recurrence
     * @return array<string, mixed> Normalized recurrence identity.
     */
    private function assertWritableEventTarget(array $recurrence, string $eventId, bool $updating): array
    {
        if ($recurrence === []) {
            return CalendarEventRecurrence::single();
        }

        $identity = CalendarEventRecurrence::fromEvent($recurrence);
        $type = (string) ($identity['recurrenceType'] ?? CalendarEventRecurrence::SINGLE);
        if ($type === CalendarEventRecurrence::SINGLE) {
            return $identity;
        }

        $writeScope = (string) ($identity['writeScope'] ?? CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE);
        if ($writeScope === CalendarEventRecurrence::WRITE_SCOPE_SERIES) {
            $capability = $updating ? 'canUpdateSeries' : 'canDeleteSeries';
            $seriesId = trim((string) ($identity['seriesId'] ?? ''));
            if ($type !== CalendarEventRecurrence::SINGLE
                && $seriesId !== ''
                && (bool) ($identity[$capability] ?? false)
                && (!$updating || hash_equals($seriesId, $eventId))) {
                return $identity;
            }

            throw new MicrosoftCalendarProviderException(
                'The complete Microsoft recurring series cannot be modified.'
            );
        }
        if ($writeScope === CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING) {
            $seriesId = trim((string) ($identity['seriesId'] ?? ''));
            $occurrenceId = trim((string) ($identity['occurrenceId'] ?? ''));
            $originalStart = trim((string) ($identity['originalStart'] ?? ''));
            if (in_array($type, [CalendarEventRecurrence::OCCURRENCE, CalendarEventRecurrence::EXCEPTION], true)
                && $seriesId !== ''
                && $occurrenceId !== ''
                && hash_equals($eventId, $occurrenceId)
                && $originalStart !== ''
                && (bool) ($identity['canUpdateFollowing'] ?? false)
                && ($updating || (bool) ($identity['canDeleteSeries'] ?? false))) {
                return $identity;
            }

            throw new MicrosoftCalendarProviderException(
                'This and following Microsoft recurring occurrences cannot be modified.'
            );
        }

        $capability = $updating ? 'canUpdateOccurrence' : 'canDeleteOccurrence';
        $occurrenceId = trim((string) ($identity['occurrenceId'] ?? ''));
        if (in_array($type, [CalendarEventRecurrence::OCCURRENCE, CalendarEventRecurrence::EXCEPTION], true)
            && trim((string) ($identity['seriesId'] ?? '')) !== ''
            && $occurrenceId !== ''
            && hash_equals($eventId, $occurrenceId)
            && (bool) ($identity[$capability] ?? false)) {
            return $identity;
        }

        throw new MicrosoftCalendarProviderException(
            'The Microsoft recurring occurrence cannot be modified.'
        );
    }

    private function assertDescriptionEditable(string $calendarId, string $eventId): void
    {
        $event = $this->requestJson(
            'GET',
            '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId)
                . '?$select=isOnlineMeeting'
        );
        if ((bool) ($event['isOnlineMeeting'] ?? false)) {
            throw new MicrosoftCalendarProviderException(
                'The description of Microsoft online meetings cannot be changed safely.'
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildEventPayload(array $data, bool $creating, bool $allowRecurrenceUpdate = false): array
    {
        $payload = [];
        if ($creating || array_key_exists('summary', $data)) {
            $summary = trim((string) ($data['summary'] ?? ''));
            if ($summary === '') {
                throw new InvalidArgumentException('The event summary is missing.');
            }
            $payload['subject'] = $summary;
        }
        if (array_key_exists('description', $data)) {
            $payload['body'] = [
                'contentType' => 'text',
                'content'     => (string) $data['description']
            ];
        }
        if (array_key_exists('location', $data)) {
            $payload['location'] = ['displayName' => (string) $data['location']];
        }
        if (array_key_exists('reminder', $data)) {
            $reminder = CalendarEventReminder::normalizeInput($data['reminder'], false, 1);
            if ($reminder['mode'] === CalendarEventReminder::MODE_NONE) {
                $payload['isReminderOn'] = false;
            } else {
                $payload['isReminderOn'] = true;
                $payload['reminderMinutesBeforeStart'] = $reminder['minutesBeforeStart'];
            }
        }

        $recurrenceProvided = array_key_exists('recurrence', $data);
        $recurrence = $data['recurrence'] ?? null;
        $recurring = $recurrence !== null && $recurrence !== [];
        $clearRecurrence = $recurrenceProvided && $recurrence === null;
        if ($clearRecurrence && ($creating || !$allowRecurrenceUpdate)) {
            throw new InvalidArgumentException('The recurrence settings are invalid.');
        }
        if ($recurring
            && ((!$creating && !$allowRecurrenceUpdate) || !is_array($recurrence) || array_is_list($recurrence))) {
            throw new InvalidArgumentException('The recurrence settings are invalid.');
        }

        $hasStart = array_key_exists('start', $data);
        $hasEnd = array_key_exists('end', $data);
        if ($creating && !$hasStart) {
            throw new InvalidArgumentException('The event start is missing.');
        }
        if ($hasStart || $hasEnd) {
            if (!$hasStart || !$hasEnd) {
                throw new InvalidArgumentException('The event start and end must be changed together.');
            }
            $allDay = (bool) ($data['allDay'] ?? false);
            $start = $this->inputDate($data['start'], $allDay);
            $end = $this->inputDate($data['end'], $allDay);
            if ($end <= $start) {
                throw new InvalidArgumentException('The event end must be later than the start.');
            }

            $payload['isAllDay'] = $allDay;
            $eventTimezone = trim((string) ($data['timezone'] ?? ''));
            if ($recurring && $eventTimezone === '') {
                $eventTimezone = date_default_timezone_get();
            }
            if ($allDay) {
                $recurrenceStart = $start;
                $payload['start'] = [
                    'dateTime' => $start->format('Y-m-d') . 'T00:00:00',
                    'timeZone' => 'UTC'
                ];
                $payload['end'] = [
                    'dateTime' => $end->format('Y-m-d') . 'T00:00:00',
                    'timeZone' => 'UTC'
                ];
            } elseif ($eventTimezone !== '') {
                $eventZone = $this->recurrenceTimezone($eventTimezone);
                $recurrenceStart = $start->setTimezone($eventZone);
                $eventEnd = $end->setTimezone($eventZone);
                $payload['start'] = [
                    'dateTime' => $recurrenceStart->format('Y-m-d\TH:i:s'),
                    'timeZone' => $eventTimezone
                ];
                $payload['end'] = [
                    'dateTime' => $eventEnd->format('Y-m-d\TH:i:s'),
                    'timeZone' => $eventTimezone
                ];
            } else {
                $recurrenceStart = $start;
                $payload['start'] = [
                    'dateTime' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'UTC'
                ];
                $payload['end'] = [
                    'dateTime' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'UTC'
                ];
            }

            if ($recurring) {
                $payload['recurrence'] = CalendarRecurrenceRule::toMicrosoftRecurrence(
                    $recurrence,
                    $recurrenceStart
                );
            }
        } elseif (array_key_exists('allDay', $data)) {
            throw new InvalidArgumentException('Changing all-day mode requires a start and end.');
        } elseif ($recurring) {
            throw new InvalidArgumentException('Recurring event changes require a start and end.');
        }

        if ($clearRecurrence) {
            $payload['recurrence'] = null;
        }

        if ($payload === []) {
            throw new InvalidArgumentException('No event changes were supplied.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param list<int> $expectedStatusCodes
     * @return array<string, mixed>
     */
    private function requestJson(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = [],
        array $expectedStatusCodes = [200]
    ): array {
        return $this->requestJsonUrl($method, self::API_URL . $path, $body, $headers, $expectedStatusCodes);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param list<int> $expectedStatusCodes
     * @return array<string, mixed>
     */
    private function requestJsonUrl(
        string $method,
        string $url,
        ?array $body = null,
        array $headers = [],
        array $expectedStatusCodes = [200]
    ): array {
        $this->assertGraphUrl($url);
        $headers['Accept'] = 'application/json';
        $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        $headers['Prefer'] = $this->mergePreferHeader($headers['Prefer'] ?? '');
        $encodedBody = '';
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
            $encodedBody = json_encode(
                $body,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }

        $response = $this->httpClient->request($method, $url, $headers, $encodedBody);
        if (!in_array($response->statusCode, $expectedStatusCodes, true)) {
            $this->throwApiError($response);
        }
        if ($response->body === '') {
            return [];
        }

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new MicrosoftCalendarProviderException('Microsoft Calendar returned invalid JSON.', $response->statusCode);
        }
        if (!is_array($data)) {
            throw new MicrosoftCalendarProviderException('Microsoft Calendar returned invalid data.', $response->statusCode);
        }

        return $data;
    }

    private function throwApiError(CalendarHttpResponse $response): never
    {
        $data = json_decode($response->body, true);
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $errorCode = trim((string) ($error['code'] ?? ''));
        $message = trim((string) ($error['message'] ?? ''));

        if ($response->statusCode === 401) {
            $message = 'Microsoft authorization expired. Connect the account again.';
        } elseif ($response->statusCode === 412) {
            $message = 'The event was changed by another client. Synchronize the calendar and try again.';
        } elseif ($response->statusCode === 403 && $message === '') {
            $message = 'Microsoft Calendar access was denied.';
        } elseif ($message === '') {
            $message = sprintf('Microsoft Calendar request failed with HTTP %d.', $response->statusCode);
        }

        throw new MicrosoftCalendarProviderException($message, $response->statusCode, $errorCode);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function parseGraphDateTime(array $value, bool $allDay): DateTimeImmutable
    {
        $rawDateTime = trim((string) ($value['dateTime'] ?? ''));
        if ($rawDateTime === '') {
            throw new MicrosoftCalendarProviderException('Microsoft Calendar returned an invalid event date.');
        }

        try {
            $rawDateTime = preg_replace('/(\.\d{6})\d+(?=(?:Z|[+-]\d{2}:\d{2})?$)/', '$1', $rawDateTime) ?? $rawDateTime;
            $timezoneName = trim((string) ($value['timeZone'] ?? 'UTC'));
            $timezone = $this->timezone($timezoneName);
            $date = new DateTimeImmutable($rawDateTime, $timezone);
            if ($allDay) {
                return DateTimeImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), new DateTimeZone('UTC'))
                    ?: $date;
            }
            return $date;
        } catch (Throwable) {
            throw new MicrosoftCalendarProviderException('Microsoft Calendar returned an invalid event date.');
        }
    }

    private function inputDate(mixed $value, bool $allDay): DateTimeImmutable
    {
        try {
            $rawValue = trim((string) $value);
            if ($rawValue === '') {
                throw new RuntimeException();
            }
            if ($allDay) {
                $rawDate = substr($rawValue, 0, 10);
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate, new DateTimeZone('UTC'));
                if ($date === false || $date->format('Y-m-d') !== $rawDate) {
                    throw new RuntimeException();
                }
                return $date;
            }
            return new DateTimeImmutable($rawValue);
        } catch (Throwable) {
            throw new InvalidArgumentException('The event contains an invalid date.');
        }
    }

    private function recurrenceTimezone(string $name): DateTimeZone
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('The recurring event timezone is missing.');
        }

        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            $ianaName = $this->windowsTimezoneToIana($name);
            if ($ianaName !== '') {
                return new DateTimeZone($ianaName);
            }
            throw new InvalidArgumentException('The recurring event timezone is invalid.');
        }
    }

    private function timezone(string $name): DateTimeZone
    {
        $name = trim($name);
        if ($name === '') {
            return new DateTimeZone('UTC');
        }

        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            $ianaName = $this->windowsTimezoneToIana($name);
            if ($ianaName !== '') {
                return new DateTimeZone($ianaName);
            }
            return new DateTimeZone('UTC');
        }
    }

    private function windowsTimezoneToIana(string $name): string
    {
        $timezones = [
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

        return $timezones[$name] ?? '';
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, string>
     */
    private function writeResult(string $calendarId, array $event): array
    {
        $eventId = trim((string) ($event['id'] ?? ''));
        if ($eventId === '') {
            throw new MicrosoftCalendarProviderException('Microsoft Calendar did not return an event ID.');
        }

        return [
            'uid'            => trim((string) ($event['iCalUId'] ?? $eventId)),
            'eventReference' => $eventId,
            'resourceUrl'    => $this->eventUrl($calendarId, $eventId),
            'etag'           => trim((string) ($event['@odata.etag'] ?? $event['changeKey'] ?? ''))
        ];
    }

    private function calendarId(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new MicrosoftCalendarProviderException('The Microsoft calendar ID is missing.');
        }
        if (filter_var($reference, FILTER_VALIDATE_URL) !== false) {
            $this->assertGraphUrl($reference);
            $path = (string) (parse_url($reference, PHP_URL_PATH) ?? '');
            if (preg_match('~/calendars/([^/]+)$~', $path, $matches) === 1) {
                return rawurldecode($matches[1]);
            }
            throw new MicrosoftCalendarProviderException('The Microsoft calendar reference is invalid.');
        }
        return $reference;
    }

    private function eventId(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new MicrosoftCalendarProviderException('The Microsoft event ID is missing.');
        }
        if (filter_var($reference, FILTER_VALIDATE_URL) !== false) {
            $this->assertGraphUrl($reference);
            $path = (string) (parse_url($reference, PHP_URL_PATH) ?? '');
            if (preg_match('~/events/([^/]+)$~', $path, $matches) === 1) {
                return rawurldecode($matches[1]);
            }
            throw new MicrosoftCalendarProviderException('The Microsoft event reference is invalid.');
        }
        return $reference;
    }

    private function calendarUrl(string $calendarId): string
    {
        return self::API_URL . '/me/calendars/' . rawurlencode($calendarId);
    }

    private function eventUrl(string $calendarId, string $eventId): string
    {
        return $this->calendarUrl($calendarId) . '/events/' . rawurlencode($eventId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function nextLink(array $data): string
    {
        $nextLink = trim((string) ($data['@odata.nextLink'] ?? ''));
        if ($nextLink !== '') {
            $this->assertGraphUrl($nextLink);
        }
        return $nextLink;
    }

    private function assertGraphUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'graph.microsoft.com'
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new MicrosoftCalendarProviderException('Microsoft Graph returned an untrusted URL.');
        }
    }

    private function mergePreferHeader(string $current): string
    {
        $preferences = [];
        foreach (array_filter(array_map('trim', explode(',', $current))) as $preference) {
            $preferences[$preference] = true;
        }
        $preferences['IdType="ImmutableId"'] = true;
        return implode(', ', array_keys($preferences));
    }

    private function normalizeColor(string $color): string
    {
        $color = strtoupper(trim($color));
        if ($color === '') {
            return '';
        }
        if ($color[0] !== '#') {
            $color = '#' . $color;
        }
        return preg_match('/^#[0-9A-F]{6}$/', $color) === 1 ? $color : '';
    }
}
