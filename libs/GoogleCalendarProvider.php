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
require_once __DIR__ . '/CalendarEventLookupProviderInterface.php';
require_once __DIR__ . '/RecurringCalendarProviderInterface.php';
require_once __DIR__ . '/CalendarHttpClient.php';
require_once __DIR__ . '/CalendarEventRecurrence.php';
require_once __DIR__ . '/CalendarRecurrenceRule.php';

final class GoogleCalendarProviderException extends RuntimeException
{
    /**
     * Creates a Google Calendar provider exception with optional HTTP error metadata.
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $reason = ''
    ) {
        parent::__construct($message);
    }
}

final class GoogleCalendarProvider implements CalendarEventLookupProviderInterface, CalendarProviderInterface, RecurringCalendarProviderInterface
{
    private const API_URL = 'https://www.googleapis.com/calendar/v3';
    private const MAX_PAGES = 100;
    private const MAX_CALENDARS = 10_000;
    private const MAX_EVENTS = 100_000;

    /**
     * Creates a Google Calendar provider using an OAuth access token.
     */
    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly string $accessToken
    ) {
        if (trim($accessToken) === '') {
            throw new GoogleCalendarProviderException('Google Calendar is not connected yet.', 401);
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
        $pageToken = '';
        $pageCount = 0;
        $seenPageTokens = [];

        do {
            $pageCount++;
            $query = ['maxResults' => '250'];
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }
            $data = $this->requestJson('GET', '/users/me/calendarList?' . http_build_query($query));

            foreach (($data['items'] ?? []) as $item) {
                if (!is_array($item) || (bool) ($item['deleted'] ?? false)) {
                    continue;
                }
                $providerId = trim((string) ($item['id'] ?? ''));
                $accessRole = trim((string) ($item['accessRole'] ?? ''));
                if ($providerId === '' || $accessRole === 'freeBusyReader') {
                    continue;
                }
                $canRead = in_array($accessRole, ['reader', 'writer', 'owner'], true);
                $canWrite = in_array($accessRole, ['writer', 'owner'], true);
                if (!$canRead) {
                    continue;
                }

                $name = trim((string) ($item['summaryOverride'] ?? ''));
                if ($name === '') {
                    $name = trim((string) ($item['summary'] ?? $providerId));
                }
                $reference = $this->calendarUrl($providerId);
                $calendars[] = [
                    'id'               => hash('sha256', 'google|' . $providerId),
                    'providerId'       => $providerId,
                    'reference'        => $providerId,
                    'url'              => $reference,
                    'name'             => $name,
                    'description'      => trim((string) ($item['description'] ?? '')),
                    'color'            => $this->normalizeColor((string) ($item['backgroundColor'] ?? '')),
                    'etag'             => trim((string) ($item['etag'] ?? '')),
                    'syncToken'        => '',
                    'timezone'         => trim((string) ($item['timeZone'] ?? '')),
                    'primary'          => (bool) ($item['primary'] ?? false),
                    'accessRole'       => $accessRole,
                    'components'       => ['VEVENT'],
                    'writeAccessKnown' => true,
                    'capabilities'     => [
                        'read'             => true,
                        'create'           => $canWrite,
                        'update'           => $canWrite,
                        'delete'           => $canWrite,
                        'createRecurrence' => $canWrite,
                        'updateRecurrence' => $canWrite,
                        'updateFollowing'  => $canWrite,
                        'updateSeries'     => $canWrite,
                        'deleteSeries'     => $canWrite
                    ]
                ];
                if (count($calendars) > self::MAX_CALENDARS) {
                    throw new GoogleCalendarProviderException('Google Calendar returned too many calendars.');
                }
            }

            $pageToken = $this->validatedNextPageToken($data, $seenPageTokens, $pageCount);
        } while ($pageToken !== '');

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
            throw new GoogleCalendarProviderException('The event query end must be later than the start.');
        }

        $calendarId = $this->calendarId($calendarReference);
        $events = [];
        $pageToken = '';
        $pageCount = 0;
        $seenPageTokens = [];
        do {
            $pageCount++;
            $query = [
                'timeMin'      => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'timeMax'      => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'singleEvents' => 'true',
                'orderBy'      => 'startTime',
                'showDeleted'  => 'false',
                'maxResults'   => '2500'
            ];
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }
            $data = $this->requestJson(
                'GET',
                '/calendars/' . rawurlencode($calendarId) . '/events?' . http_build_query($query)
            );
            foreach (($data['items'] ?? []) as $item) {
                if (!is_array($item) || ($item['status'] ?? '') === 'cancelled') {
                    continue;
                }
                $mapped = $this->mapEvent($calendarId, $item, (string) ($data['timeZone'] ?? ''));
                if ($mapped !== null) {
                    $events[] = $mapped;
                    if (count($events) > self::MAX_EVENTS) {
                        throw new GoogleCalendarProviderException('Google Calendar returned too many events.');
                    }
                }
            }
            $pageToken = $this->validatedNextPageToken($data, $seenPageTokens, $pageCount);
        } while ($pageToken !== '');

        usort(
            $events,
            static fn (array $left, array $right): int => ($left['startTimestamp'] <=> $right['startTimestamp'])
                ?: strcasecmp((string) $left['summary'], (string) $right['summary'])
        );

        return $events;
    }

    /** @inheritDoc */
    public function getEventForEdit(string $calendarReference, string $eventReference): array
    {
        $calendarId = $this->calendarId($calendarReference);
        $eventId = $this->eventId($eventReference);
        $item = $this->requestJson(
            'GET',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId)
        );
        if (($item['status'] ?? '') === 'cancelled') {
            throw new GoogleCalendarProviderException('The selected event is no longer available.');
        }

        $event = $this->mapEvent($calendarId, $item, '');
        if ($event === null || !hash_equals($eventId, (string) ($event['eventReference'] ?? ''))) {
            throw new GoogleCalendarProviderException('Google Calendar did not return the selected event.');
        }

        return $event;
    }

    /** @inheritDoc */
    public function getRecurringSeries(
        string $calendarReference,
        string $seriesId,
        string $resourceReference = ''
    ): array {
        $calendarId = $this->calendarId($calendarReference);
        $seriesId = trim($seriesId);
        if ($seriesId === '') {
            throw new GoogleCalendarProviderException('The recurring series ID is missing.');
        }

        $item = $this->requestJson(
            'GET',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId)
        );
        $mapped = $this->mapEvent($calendarId, $item, '');
        if ($mapped === null
            || ($mapped['recurrenceType'] ?? '') !== CalendarEventRecurrence::MASTER
            || !hash_equals($seriesId, (string) ($mapped['seriesId'] ?? ''))) {
            throw new GoogleCalendarProviderException('Google Calendar did not return the recurring parent event.');
        }

        $recurrenceLines = array_values(array_filter(
            is_array($item['recurrence'] ?? null) ? $item['recurrence'] : [],
            'is_string'
        ));
        $recurrenceSettings = null;
        if (count($recurrenceLines) === 1) {
            $recurrenceSettings = CalendarRecurrenceRule::fromGoogleRule(
                $recurrenceLines[0],
                (bool) ($mapped['allDay'] ?? false),
                (string) ($mapped['timezone'] ?? '')
            );
        }

        $mapped['recurrenceEditable'] = $recurrenceSettings !== null;
        $mapped['recurrenceSettings'] = $recurrenceSettings ?? [];

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
        $calendarId = $this->calendarId($calendarReference);
        $series = $this->getRecurringSeries($calendarReference, $seriesId);
        if (($series['recurrenceEditable'] ?? false) !== true
            || !is_array($series['recurrenceSettings'] ?? null)) {
            throw new GoogleCalendarProviderException('The recurrence pattern cannot be split safely.');
        }

        $target = $this->verifiedRecurringOccurrence(
            $calendarId,
            trim($seriesId),
            trim($occurrenceId),
            trim($originalStart)
        );
        $settings = $series['recurrenceSettings'];
        if (($settings['endMode'] ?? '') === 'count') {
            $declaredCount = (int) ($settings['count'] ?? 0);
            $settings['count'] = $this->remainingOccurrenceCount(
                $calendarId,
                trim($seriesId),
                trim($originalStart),
                $declaredCount,
                (bool) ($series['allDay'] ?? false),
                (string) ($series['timezone'] ?? '')
            );
        }

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
                trim($seriesId),
                (string) $target['occurrenceId'],
                (string) $target['originalStart'],
                '',
                true,
                false,
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
        $created = $this->requestJson(
            'POST',
            '/calendars/' . rawurlencode($calendarId) . '/events',
            $payload,
            [],
            [200]
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
        $identity = $this->assertWritableRecurrence($recurrence, true, $eventId);
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
            throw new GoogleCalendarProviderException('The recurring series ID is missing.');
        }
        $headers = $etag !== '' ? ['If-Match' => $etag] : [];
        $updated = $this->requestJson(
            'PATCH',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($targetEventId),
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
        $identity = $this->assertWritableRecurrence($recurrence, false, $eventId);
        if (($identity['writeScope'] ?? '') === CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING) {
            return $this->deleteFollowingInstances($calendarId, $eventId, $identity);
        }

        $seriesDelete = ($identity['writeScope'] ?? '') === CalendarEventRecurrence::WRITE_SCOPE_SERIES;
        $targetEventId = $seriesDelete
            ? trim((string) ($identity['seriesId'] ?? ''))
            : $eventId;
        if ($targetEventId === '') {
            throw new GoogleCalendarProviderException('The recurring series ID is missing.');
        }
        $occurrenceDelete = !$seriesDelete && CalendarEventRecurrence::isOccurrence($identity);
        $headers = !$seriesDelete && $etag !== '' ? ['If-Match' => $etag] : [];
        if ($occurrenceDelete) {
            $this->requestJson(
                'PATCH',
                '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($targetEventId),
                ['status' => 'cancelled'],
                $headers,
                [200]
            );

            return true;
        }

        $this->requestJson(
            'DELETE',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($targetEventId),
            null,
            $headers,
            [204]
        );

        return true;
    }

    /**
     * Deletes one recurring Google event from the selected occurrence onward.
     *
     * The existing parent is shortened before the selected occurrence. When the
     * selected occurrence is the first one, the complete recurring parent is removed.
     *
     * @param array<string, mixed> $identity
     */
    private function deleteFollowingInstances(
        string $calendarId,
        string $eventId,
        array $identity
    ): bool {
        $seriesId = trim((string) ($identity['seriesId'] ?? ''));
        $originalStart = trim((string) ($identity['originalStart'] ?? ''));
        $target = $this->verifiedRecurringOccurrence($calendarId, $seriesId, $eventId, $originalStart);
        $parentItem = $this->requestJson(
            'GET',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId)
        );
        $parent = $this->mapEvent($calendarId, $parentItem, '');
        if ($parent === null
            || ($parent['recurrenceType'] ?? '') !== CalendarEventRecurrence::MASTER
            || !hash_equals($seriesId, (string) ($parent['seriesId'] ?? ''))) {
            throw new GoogleCalendarProviderException('Google Calendar did not return the recurring parent event.');
        }

        $recurrenceLines = array_values(array_filter(
            is_array($parentItem['recurrence'] ?? null) ? $parentItem['recurrence'] : [],
            'is_string'
        ));
        if (count($recurrenceLines) !== 1
            || CalendarRecurrenceRule::fromGoogleRule(
                $recurrenceLines[0],
                (bool) ($parent['allDay'] ?? false),
                (string) ($parent['timezone'] ?? '')
            ) === null) {
            throw new GoogleCalendarProviderException('The recurrence pattern cannot be split safely.');
        }

        $parentEtag = trim((string) ($parentItem['etag'] ?? ''));
        $parentHeaders = $parentEtag !== '' ? ['If-Match' => $parentEtag] : [];
        if ((int) ($parent['startTimestamp'] ?? 0) === $this->originalStartTimestamp(
            $target,
            (bool) ($parent['allDay'] ?? false),
            (string) ($parent['timezone'] ?? '')
        )) {
            $this->requestJson(
                'DELETE',
                '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId),
                null,
                $parentHeaders,
                [204]
            );

            return true;
        }

        $trimmedRule = CalendarRecurrenceRule::trimGoogleRuleBefore(
            $recurrenceLines[0],
            (string) ($target['originalStart'] ?? ''),
            (bool) ($parent['allDay'] ?? false),
            (string) ($parent['timezone'] ?? '')
        );
        $this->requestJson(
            'PATCH',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId),
            ['recurrence' => [$trimmedRule]],
            $parentHeaders,
            [200]
        );

        return true;
    }

    /**
     * Applies a "this and following" update by splitting one recurring Google event.
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
        $target = $this->verifiedRecurringOccurrence($calendarId, $seriesId, $eventId, $originalStart);
        $parentItem = $this->requestJson(
            'GET',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId)
        );
        $parent = $this->mapEvent($calendarId, $parentItem, '');
        if ($parent === null
            || ($parent['recurrenceType'] ?? '') !== CalendarEventRecurrence::MASTER
            || !hash_equals($seriesId, (string) ($parent['seriesId'] ?? ''))) {
            throw new GoogleCalendarProviderException('Google Calendar did not return the recurring parent event.');
        }

        $recurrenceLines = array_values(array_filter(
            is_array($parentItem['recurrence'] ?? null) ? $parentItem['recurrence'] : [],
            'is_string'
        ));
        if (count($recurrenceLines) !== 1
            || CalendarRecurrenceRule::fromGoogleRule(
                $recurrenceLines[0],
                (bool) ($parent['allDay'] ?? false),
                (string) ($parent['timezone'] ?? '')
            ) === null) {
            throw new GoogleCalendarProviderException('The recurrence pattern cannot be split safely.');
        }
        if (!is_array($event['recurrence'] ?? null) || $event['recurrence'] === []) {
            throw new GoogleCalendarProviderException('The recurrence settings are required when splitting a recurring event.');
        }

        $newEventPayload = array_replace(
            $this->splitEventBasePayload($parentItem),
            $this->buildEventPayload($event, true)
        );
        $parentEtag = trim((string) ($parentItem['etag'] ?? ''));
        $parentHeaders = $parentEtag !== '' ? ['If-Match' => $parentEtag] : [];
        if ((int) ($parent['startTimestamp'] ?? 0) === $this->originalStartTimestamp(
            $target,
            (bool) ($parent['allDay'] ?? false),
            (string) ($parent['timezone'] ?? '')
        )) {
            $updated = $this->requestJson(
                'PATCH',
                '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId),
                $this->buildEventPayload($event, false, true),
                $parentHeaders,
                [200]
            );

            return $this->writeResult($calendarId, $updated);
        }

        $trimmedRule = CalendarRecurrenceRule::trimGoogleRuleBefore(
            $recurrenceLines[0],
            (string) ($target['originalStart'] ?? ''),
            (bool) ($parent['allDay'] ?? false),
            (string) ($parent['timezone'] ?? '')
        );
        $trimmedParent = $this->requestJson(
            'PATCH',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId),
            ['recurrence' => [$trimmedRule]],
            $parentHeaders,
            [200]
        );

        $insertPath = '/calendars/' . rawurlencode($calendarId) . '/events';
        if (array_key_exists('attachments', $newEventPayload)) {
            $insertPath .= '?supportsAttachments=true';
        }
        try {
            $created = $this->requestJson('POST', $insertPath, $newEventPayload, [], [200]);
        } catch (Throwable $exception) {
            $rollbackEtag = trim((string) ($trimmedParent['etag'] ?? ''));
            $rollbackHeaders = $rollbackEtag !== '' ? ['If-Match' => $rollbackEtag] : [];
            try {
                $this->requestJson(
                    'PATCH',
                    '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($seriesId),
                    ['recurrence' => $recurrenceLines],
                    $rollbackHeaders,
                    [200]
                );
            } catch (Throwable $rollbackException) {
                throw new GoogleCalendarProviderException(
                    'The new recurring series could not be created and the original series could not be restored automatically.'
                );
            }

            throw $exception;
        }

        return $this->writeResult($calendarId, $created);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedRecurringOccurrence(
        string $calendarId,
        string $seriesId,
        string $occurrenceId,
        string $originalStart
    ): array {
        if ($seriesId === '' || $occurrenceId === '' || $originalStart === '') {
            throw new GoogleCalendarProviderException('The recurring occurrence identity is incomplete.');
        }

        $item = $this->requestJson(
            'GET',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($occurrenceId)
        );
        $mapped = $this->mapEvent($calendarId, $item, '');
        if ($mapped === null
            || !CalendarEventRecurrence::isOccurrence($mapped)
            || !hash_equals($seriesId, (string) ($mapped['seriesId'] ?? ''))
            || !hash_equals($occurrenceId, (string) ($mapped['occurrenceId'] ?? ''))
            || !hash_equals($originalStart, (string) ($mapped['originalStart'] ?? ''))) {
            throw new GoogleCalendarProviderException('The recurring target occurrence could not be verified.');
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $parentItem
     * @return array<string, mixed>
     */
    private function splitEventBasePayload(array $parentItem): array
    {
        if (is_array($parentItem['conferenceData'] ?? null) && $parentItem['conferenceData'] !== []) {
            throw new GoogleCalendarProviderException(
                'This and following updates are not supported for recurring events with conference data.'
            );
        }
        $eventType = trim((string) ($parentItem['eventType'] ?? 'default'));
        if ($eventType !== '' && $eventType !== 'default') {
            throw new GoogleCalendarProviderException(
                'This and following updates are not supported for this Google event type.'
            );
        }

        $payload = [];
        foreach ([
            'summary',
            'description',
            'location',
            'colorId',
            'status',
            'attendees',
            'reminders',
            'visibility',
            'transparency',
            'guestsCanInviteOthers',
            'guestsCanModify',
            'guestsCanSeeOtherGuests',
            'extendedProperties',
            'attachments'
        ] as $key) {
            if (array_key_exists($key, $parentItem)) {
                $payload[$key] = $parentItem[$key];
            }
        }

        return $payload;
    }

    private function remainingOccurrenceCount(
        string $calendarId,
        string $seriesId,
        string $targetOriginalStart,
        int $declaredCount,
        bool $allDay,
        string $timezone
    ): int {
        if ($declaredCount < 1 || $declaredCount > 9999) {
            throw new GoogleCalendarProviderException('The recurring series count is invalid.');
        }

        $instances = [];
        $pageToken = '';
        $pageCount = 0;
        $seenPageTokens = [];
        do {
            ++$pageCount;
            $query = [
                'maxResults'  => '2500',
                'showDeleted' => 'true'
            ];
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }
            $data = $this->requestJson(
                'GET',
                '/calendars/' . rawurlencode($calendarId)
                    . '/events/' . rawurlencode($seriesId)
                    . '/instances?' . http_build_query($query)
            );
            foreach (($data['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $original = is_array($item['originalStartTime'] ?? null) ? $item['originalStartTime'] : [];
                if ($original === []) {
                    continue;
                }
                $date = $this->parseEventDate($original, $timezone, $allDay);
                $key = $allDay ? $date->format('Y-m-d') : $date->format(DATE_ATOM);
                $instances[$key] = $date->getTimestamp();
            }
            $pageToken = $this->validatedNextPageToken($data, $seenPageTokens, $pageCount);
        } while ($pageToken !== '');

        if (count($instances) !== $declaredCount || !array_key_exists($targetOriginalStart, $instances)) {
            throw new GoogleCalendarProviderException('The recurring series instances could not be verified completely.');
        }
        asort($instances, SORT_NUMERIC);
        $ordered = array_keys($instances);
        $targetIndex = array_search($targetOriginalStart, $ordered, true);
        if ($targetIndex === false) {
            throw new GoogleCalendarProviderException('The recurring target occurrence could not be located.');
        }

        return count($ordered) - $targetIndex;
    }

    /**
     * @param array<string, mixed> $target
     */
    private function originalStartTimestamp(array $target, bool $allDay, string $timezone): int
    {
        $value = trim((string) ($target['originalStart'] ?? ''));
        if ($value === '') {
            throw new GoogleCalendarProviderException('The recurring target start is missing.');
        }
        $dateData = $allDay
            ? ['date' => $value, 'timeZone' => $timezone]
            : ['dateTime' => $value, 'timeZone' => $timezone];

        return $this->parseEventDate($dateData, $timezone, $allDay)->getTimestamp();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, true>  $seenPageTokens
     */
    private function validatedNextPageToken(array $data, array &$seenPageTokens, int $pageCount): string
    {
        $pageToken = trim((string) ($data['nextPageToken'] ?? ''));
        if ($pageToken === '') {
            return '';
        }
        if ($pageCount >= self::MAX_PAGES) {
            throw new GoogleCalendarProviderException('Google Calendar pagination exceeded the safe page limit.');
        }
        if (isset($seenPageTokens[$pageToken])) {
            throw new GoogleCalendarProviderException('Google Calendar returned a repeated page token.');
        }

        $seenPageTokens[$pageToken] = true;
        return $pageToken;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function mapEvent(string $calendarId, array $item, string $calendarTimezone): ?array
    {
        $eventId = trim((string) ($item['id'] ?? ''));
        $startData = is_array($item['start'] ?? null) ? $item['start'] : [];
        $endData = is_array($item['end'] ?? null) ? $item['end'] : [];
        if ($eventId === '' || ($startData === [])) {
            return null;
        }

        $allDay = isset($startData['date']);
        $start = $this->parseEventDate($startData, $calendarTimezone, $allDay);
        if ($endData === []) {
            $end = $allDay ? $start->add(new DateInterval('P1D')) : $start;
        } else {
            $end = $this->parseEventDate($endData, $calendarTimezone, $allDay);
        }
        $timezone = trim((string) ($startData['timeZone'] ?? $calendarTimezone));
        if ($timezone === '') {
            $timezone = $start->getTimezone()->getName();
        }

        $recurrence = is_array($item['recurrence'] ?? null) ? $item['recurrence'] : [];
        $recurrenceRule = '';
        foreach ($recurrence as $rule) {
            if (is_string($rule) && str_starts_with(strtoupper($rule), 'RRULE:')) {
                $recurrenceRule = substr($rule, 6);
                break;
            }
        }
        $recurringEventId = trim((string) ($item['recurringEventId'] ?? ''));
        $originalStartData = is_array($item['originalStartTime'] ?? null) ? $item['originalStartTime'] : [];
        $originalStart = '';
        if ($originalStartData !== []) {
            $originalAllDay = isset($originalStartData['date']);
            $parsedOriginalStart = $this->parseEventDate($originalStartData, $calendarTimezone, $originalAllDay);
            $originalStart = $originalAllDay
                ? $parsedOriginalStart->format('Y-m-d')
                : $parsedOriginalStart->format(DATE_ATOM);
        }
        $recurrenceIdentity = $recurringEventId !== ''
            ? CalendarEventRecurrence::occurrence(
                $recurringEventId,
                $eventId,
                $originalStart,
                '',
                true,
                false,
                true,
                true,
                true
            )
            : ($recurrence !== []
                ? CalendarEventRecurrence::master($eventId, true, true)
                : CalendarEventRecurrence::single());
        $resourceUrl = $this->eventUrl($calendarId, $eventId);

        return array_merge([
            'id'             => hash('sha256', 'google|' . $calendarId . '|' . $eventId),
            'uid'            => trim((string) ($item['iCalUID'] ?? $eventId)),
            'eventReference' => $eventId,
            'resourceUrl'    => $resourceUrl,
            'etag'           => trim((string) ($item['etag'] ?? '')),
            'summary'        => trim((string) ($item['summary'] ?? '')),
            'description'    => (string) ($item['description'] ?? ''),
            'location'       => trim((string) ($item['location'] ?? '')),
            'start'          => $allDay ? $start->format('Y-m-d') : $start->format(DATE_ATOM),
            'end'            => $allDay ? $end->format('Y-m-d') : $end->format(DATE_ATOM),
            'startTimestamp' => $start->getTimestamp(),
            'endTimestamp'   => $end->getTimestamp(),
            'allDay'         => $allDay,
            'timezone'       => $timezone,
            'status'         => strtoupper(trim((string) ($item['status'] ?? ''))),
            'recurrenceRule' => $recurrenceRule,
            'sequence'       => (int) ($item['sequence'] ?? 0),
            'created'        => trim((string) ($item['created'] ?? '')),
            'lastModified'   => trim((string) ($item['updated'] ?? '')),
            'url'            => trim((string) ($item['htmlLink'] ?? ''))
        ], $recurrenceIdentity);
    }

    /**
     * @param array<string, mixed> $recurrence
     * @return array<string, mixed> Normalized recurrence identity.
     */
    private function assertWritableRecurrence(array $recurrence, bool $updating, string $eventId): array
    {
        if ($recurrence === []) {
            return CalendarEventRecurrence::single();
        }

        $identity = CalendarEventRecurrence::fromEvent($recurrence);
        if (($identity['recurrenceType'] ?? '') === CalendarEventRecurrence::SINGLE) {
            return $identity;
        }
        $writeScope = (string) ($identity['writeScope'] ?? CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE);
        if ($writeScope === CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING) {
            if (!CalendarEventRecurrence::isOccurrence($identity)
                || !(bool) ($identity['canUpdateFollowing'] ?? false)
                || (!$updating && !(bool) ($identity['canDeleteSeries'] ?? false))
                || trim((string) ($identity['seriesId'] ?? '')) === ''
                || trim((string) ($identity['originalStart'] ?? '')) === '') {
                throw new GoogleCalendarProviderException('The recurring event cannot be split by this calendar.');
            }
            if ($eventId !== '' && hash_equals((string) ($identity['occurrenceId'] ?? ''), $eventId)) {
                return $identity;
            }

            throw new GoogleCalendarProviderException('The recurring occurrence identity does not match the event.');
        }
        if ($writeScope === CalendarEventRecurrence::WRITE_SCOPE_SERIES) {
            $capability = $updating ? 'canUpdateSeries' : 'canDeleteSeries';
            if ((bool) ($identity[$capability] ?? false) && trim((string) ($identity['seriesId'] ?? '')) !== '') {
                return $identity;
            }

            throw new GoogleCalendarProviderException('The recurring series cannot be modified by this calendar.');
        }

        $capability = $updating ? 'canUpdateOccurrence' : 'canDeleteOccurrence';
        if (CalendarEventRecurrence::isOccurrence($identity) && (bool) ($identity[$capability] ?? false)) {
            if ($eventId !== '' && hash_equals((string) ($identity['occurrenceId'] ?? ''), $eventId)) {
                return $identity;
            }

            throw new GoogleCalendarProviderException('The recurring occurrence identity does not match the event.');
        }

        throw new GoogleCalendarProviderException('Recurring series cannot be modified yet.');
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
            $payload['summary'] = $summary;
        }
        foreach (['description', 'location'] as $property) {
            if (array_key_exists($property, $data)) {
                $payload[$property] = (string) $data[$property];
            }
        }
        if (array_key_exists('status', $data)) {
            $status = strtolower(trim((string) $data['status']));
            if (!in_array($status, ['confirmed', 'tentative', 'cancelled'], true)) {
                throw new InvalidArgumentException('The event status is invalid.');
            }
            $payload['status'] = $status;
        }

        $recurrence = null;
        $clearRecurrence = false;
        if (array_key_exists('recurrence', $data)) {
            if ($data['recurrence'] === null) {
                if ($creating || !$allowRecurrenceUpdate) {
                    throw new InvalidArgumentException('The recurrence settings cannot be changed for this event.');
                }
                $clearRecurrence = true;
            } else {
                if (!is_array($data['recurrence']) || array_is_list($data['recurrence'])) {
                    throw new InvalidArgumentException('The recurrence settings are invalid.');
                }
                if ($data['recurrence'] !== []) {
                    if (!$creating && !$allowRecurrenceUpdate) {
                        throw new InvalidArgumentException('The recurrence settings cannot be changed for this event.');
                    }
                    $recurrence = $data['recurrence'];
                }
            }
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

            $eventTimezone = trim((string) ($data['timezone'] ?? ''));
            if (($recurrence !== null || $allowRecurrenceUpdate) && !$allDay) {
                $zone = $this->inputTimezone($eventTimezone);
                $start = $start->setTimezone($zone);
                $end = $end->setTimezone($zone);
            }

            if ($allDay) {
                $payload['start'] = ['date' => $start->format('Y-m-d')];
                $payload['end'] = ['date' => $end->format('Y-m-d')];
            } else {
                $payload['start'] = ['dateTime' => $start->format(DATE_RFC3339)];
                $payload['end'] = ['dateTime' => $end->format(DATE_RFC3339)];
                if ($recurrence !== null || $allowRecurrenceUpdate) {
                    $payload['start']['timeZone'] = $eventTimezone;
                    $payload['end']['timeZone'] = $eventTimezone;
                }
            }

            if ($recurrence !== null) {
                $payload['recurrence'] = CalendarRecurrenceRule::toGoogleLines(
                    $recurrence,
                    $start,
                    $allDay,
                    $eventTimezone
                );
            } elseif ($clearRecurrence) {
                $payload['recurrence'] = [];
            }
        } elseif (array_key_exists('allDay', $data)) {
            throw new InvalidArgumentException('Changing all-day mode requires a start and end.');
        } elseif ($recurrence !== null || $clearRecurrence) {
            throw new InvalidArgumentException('Changing recurrence requires a start and end.');
        }

        if ($payload === []) {
            throw new InvalidArgumentException('No event changes were supplied.');
        }

        return $payload;
    }

    private function inputTimezone(string $name): DateTimeZone
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
        $headers['Accept'] = 'application/json';
        $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        $encodedBody = '';
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
            $encodedBody = json_encode(
                $body,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }

        $response = $this->httpClient->request($method, self::API_URL . $path, $headers, $encodedBody);
        if (!in_array($response->statusCode, $expectedStatusCodes, true)) {
            $this->throwApiError($response);
        }
        if ($response->body === '') {
            return [];
        }

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new GoogleCalendarProviderException('Google Calendar returned invalid JSON.', $response->statusCode);
        }
        if (!is_array($data)) {
            throw new GoogleCalendarProviderException('Google Calendar returned invalid data.', $response->statusCode);
        }

        return $data;
    }

    private function throwApiError(CalendarHttpResponse $response): never
    {
        $data = json_decode($response->body, true);
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $details = is_array($error['errors'] ?? null) ? $error['errors'] : [];
        $reason = is_array($details[0] ?? null) ? trim((string) ($details[0]['reason'] ?? '')) : '';
        $message = trim((string) ($error['message'] ?? ''));

        if ($response->statusCode === 401) {
            $message = 'Google authorization expired. Connect the account again.';
        } elseif ($response->statusCode === 412) {
            $message = 'The event was changed by another client. Synchronize the calendar and try again.';
        } elseif ($message === '') {
            $message = sprintf('Google Calendar request failed with HTTP %d.', $response->statusCode);
        }

        throw new GoogleCalendarProviderException($message, $response->statusCode, $reason);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function parseEventDate(array $value, string $fallbackTimezone, bool $allDay): DateTimeImmutable
    {
        try {
            if ($allDay) {
                $timezone = $this->timezone((string) ($value['timeZone'] ?? $fallbackTimezone));
                $rawDate = trim((string) ($value['date'] ?? ''));
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate, $timezone);
                if ($date === false || $date->format('Y-m-d') !== $rawDate) {
                    throw new RuntimeException();
                }
                return $date;
            }

            $rawDateTime = trim((string) ($value['dateTime'] ?? ''));
            if ($rawDateTime === '') {
                throw new RuntimeException();
            }
            return new DateTimeImmutable(
                $rawDateTime,
                $this->timezone((string) ($value['timeZone'] ?? $fallbackTimezone))
            );
        } catch (Throwable) {
            throw new GoogleCalendarProviderException('Google Calendar returned an invalid event date.');
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
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
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

    private function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name !== '' ? $name : date_default_timezone_get());
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, string>
     */
    private function writeResult(string $calendarId, array $event): array
    {
        $eventId = trim((string) ($event['id'] ?? ''));
        if ($eventId === '') {
            throw new GoogleCalendarProviderException('Google Calendar did not return an event ID.');
        }

        return [
            'uid'            => trim((string) ($event['iCalUID'] ?? $eventId)),
            'eventReference' => $eventId,
            'resourceUrl'    => $this->eventUrl($calendarId, $eventId),
            'etag'           => trim((string) ($event['etag'] ?? ''))
        ];
    }

    private function calendarId(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new GoogleCalendarProviderException('The Google calendar ID is missing.');
        }
        $path = (string) (parse_url($reference, PHP_URL_PATH) ?? '');
        if (preg_match('~/calendars/([^/]+)$~', $path, $matches) === 1) {
            return rawurldecode($matches[1]);
        }
        return $reference;
    }

    private function eventId(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new GoogleCalendarProviderException('The Google event ID is missing.');
        }
        $path = (string) (parse_url($reference, PHP_URL_PATH) ?? '');
        if (preg_match('~/events/([^/]+)$~', $path, $matches) === 1) {
            return rawurldecode($matches[1]);
        }
        return $reference;
    }

    private function calendarUrl(string $calendarId): string
    {
        return self::API_URL . '/calendars/' . rawurlencode($calendarId);
    }

    private function eventUrl(string $calendarId, string $eventId): string
    {
        return $this->calendarUrl($calendarId) . '/events/' . rawurlencode($eventId);
    }

    private function normalizeColor(string $color): string
    {
        $color = strtoupper(trim($color));
        return preg_match('/^#[0-9A-F]{6}$/', $color) === 1 ? $color : '';
    }
}
