<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/CalendarProviderInterface.php';
require_once __DIR__ . '/RecurringCalendarProviderInterface.php';
require_once __DIR__ . '/CalendarEventRecurrence.php';
require_once __DIR__ . '/CalendarEventReminder.php';
require_once __DIR__ . '/CalendarRecurrenceRule.php';
require_once __DIR__ . '/CalendarHttpClient.php';
require_once __DIR__ . '/CalDAVOriginPolicy.php';
require_once __DIR__ . '/ICalendarCodec.php';

final class CalDAVProviderException extends RuntimeException
{
    /**
     * Creates a CalDAV provider exception with an optional HTTP status code.
     */
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}

final class CalDAVProvider implements CalendarProviderInterface, RecurringCalendarProviderInterface
{
    private const DAV_NAMESPACE = 'DAV:';
    private const CALDAV_NAMESPACE = 'urn:ietf:params:xml:ns:caldav';
    private const APPLE_NAMESPACE = 'http://apple.com/ns/ical/';

    private readonly CalDAVOriginPolicy $originPolicy;

    /**
     * Creates a CalDAV provider bound to the supplied server trust policy.
     *
     * @param CalendarHttpClientInterface $httpClient   HTTP transport used for DAV requests.
     * @param string                      $serverUrl    Configured CalDAV server URL.
     * @param CalDAVOriginPolicy|null     $originPolicy Optional preconfigured origin policy.
     */
    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        string $serverUrl,
        ?CalDAVOriginPolicy $originPolicy = null
    ) {
        $this->originPolicy = $originPolicy ?? new CalDAVOriginPolicy($serverUrl);
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
        $entryUrls = $this->getEntryUrls();
        $lastException = null;

        foreach ($entryUrls as $entryUrl) {
            try {
                $principalUrl = $this->discoverPrincipal($entryUrl);
                $homeSetUrl = $this->discoverCalendarHomeSet($principalUrl);

                return $this->discoverCalendars($homeSetUrl);
            } catch (CalDAVProviderException $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? new CalDAVProviderException('CalDAV discovery failed.');
    }

    /** @inheritDoc */
    public function getEvents(string $calendarUrl, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($end <= $start) {
            throw new CalDAVProviderException('The event query end must be later than the start.');
        }

        $calendarUrl = $this->normalizeAbsoluteUrl($calendarUrl);
        $utc = new DateTimeZone('UTC');
        $startValue = $start->setTimezone($utc)->format('Ymd\THis\Z');
        $endValue = $end->setTimezone($utc)->format('Ymd\THis\Z');
        $body = '<?xml version="1.0" encoding="utf-8" ?>' .
            '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' .
            '<d:prop><d:getetag/><c:calendar-data><c:expand start="' . $startValue . '" end="' . $endValue . '"/>' .
            '</c:calendar-data></d:prop>' .
            '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">' .
            '<c:time-range start="' . $startValue . '" end="' . $endValue . '"/>' .
            '</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';

        $response = $this->httpClient->request(
            'REPORT',
            $calendarUrl,
            [
                'Accept'       => 'application/xml, text/xml',
                'Content-Type' => 'application/xml; charset=utf-8',
                'Depth'        => '1'
            ],
            $body
        );
        $this->assertResponseStatus($response, [207], 'calendar query');
        $effectiveCalendarUrl = $this->trustedEffectiveUrl($response, $calendarUrl);

        $document = $this->parseXml($response->body);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV_NAMESPACE);
        $xpath->registerNamespace('c', self::CALDAV_NAMESPACE);
        $events = [];
        $responses = $xpath->query('//d:multistatus/d:response');
        if ($responses === false) {
            return [];
        }

        foreach ($responses as $eventResponse) {
            if (!$eventResponse instanceof DOMElement) {
                continue;
            }
            $href = $this->firstNodeValue($xpath, './d:href', $eventResponse);
            $calendarData = $this->firstNodeValue($xpath, './/c:calendar-data', $eventResponse);
            if ($href === '' || $calendarData === '') {
                continue;
            }
            $resourceUrl = $this->resolveUrl($effectiveCalendarUrl, $href);
            $this->assertResourceBelongsToCalendar($calendarUrl, $resourceUrl);
            $etag = $this->firstNodeValue($xpath, './/d:getetag', $eventResponse);
            array_push(
                $events,
                ...$this->enableExpandedOccurrenceWrites(
                    ICalendarCodec::parseEvents($calendarData, $resourceUrl, $etag)
                )
            );
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
        string $calendarUrl,
        string $seriesId,
        string $resourceReference = ''
    ): array {
        $calendarUrl = $this->normalizeAbsoluteUrl($calendarUrl);
        $seriesId = trim($seriesId);
        if ($seriesId === '') {
            throw new CalDAVProviderException('The recurring series ID is missing.');
        }

        $resourceReference = trim($resourceReference);
        $fallbackEtag = '';
        if ($resourceReference !== '') {
            $resourceReference = $this->normalizeAbsoluteUrl($resourceReference);
            $this->assertResourceBelongsToCalendar($calendarUrl, $resourceReference);
        } else {
            // Keep UID lookup only as a compatibility fallback for callers that do not
            // already know the calendar object URL. iCloud rejects UID prop-filter
            // calendar-query REPORT requests with HTTP 412, so the visualization path
            // must pass the resource URL obtained during event synchronization.
            $resource = $this->findRecurringResource($calendarUrl, $seriesId);
            $resourceReference = $resource['resourceUrl'];
            $fallbackEtag = $resource['etag'];
        }

        $getResponse = $this->httpClient->request(
            'GET',
            $resourceReference,
            ['Accept' => 'text/calendar']
        );
        $this->assertResponseStatus($getResponse, [200], 'recurring series retrieval');
        $resourceUrl = $this->trustedEffectiveUrl($getResponse, $resourceReference);
        $this->assertResourceBelongsToCalendar($calendarUrl, $resourceUrl);
        $resourceEtag = trim((string) ($getResponse->headers['etag'] ?? ''));
        if ($resourceEtag === '') {
            $resourceEtag = $fallbackEtag;
        }

        $masters = array_values(array_filter(
            ICalendarCodec::parseEvents($getResponse->body, $resourceUrl, $resourceEtag),
            static fn (array $event): bool => ($event['recurrenceType'] ?? '') === CalendarEventRecurrence::MASTER
                && hash_equals($seriesId, trim((string) ($event['seriesId'] ?? '')))
        ));
        if (count($masters) !== 1) {
            throw new CalDAVProviderException('CalDAV did not return one recurring parent event.');
        }

        $master = $masters[0];
        $recurrenceSettings = null;
        if (trim((string) ($master['recurrenceRule'] ?? '')) !== ''
            && (is_array($master['recurrenceDates'] ?? null) ? $master['recurrenceDates'] : []) === []) {
            $recurrenceSettings = CalendarRecurrenceRule::fromGoogleRule(
                (string) $master['recurrenceRule'],
                (bool) ($master['allDay'] ?? false),
                (string) ($master['timezone'] ?? '')
            );
        }

        $master = array_merge($master, CalendarEventRecurrence::master($seriesId, true, true));
        $master['recurrenceEditable'] = $recurrenceSettings !== null;
        $master['recurrenceSettings'] = $recurrenceSettings ?? [];

        return $master;
    }

    /** @inheritDoc */
    public function getRecurringFollowing(
        string $calendarUrl,
        string $seriesId,
        string $occurrenceId,
        string $originalStart,
        string $resourceReference = ''
    ): array {
        $calendarUrl = $this->normalizeAbsoluteUrl($calendarUrl);
        $seriesId = trim($seriesId);
        $occurrenceId = trim($occurrenceId);
        $originalStart = trim($originalStart);
        if ($seriesId === '' || $occurrenceId === '' || $originalStart === '') {
            throw new CalDAVProviderException('The recurring occurrence identity is incomplete.');
        }
        if (!str_starts_with($occurrenceId, $seriesId . '|')) {
            throw new CalDAVProviderException('The recurring occurrence identity does not match the series.');
        }

        $resourceReference = trim($resourceReference);
        $fallbackEtag = '';
        if ($resourceReference !== '') {
            $resourceReference = $this->normalizeAbsoluteUrl($resourceReference);
            $this->assertResourceBelongsToCalendar($calendarUrl, $resourceReference);
        } else {
            $resource = $this->findRecurringResource($calendarUrl, $seriesId);
            $resourceReference = $resource['resourceUrl'];
            $fallbackEtag = $resource['etag'];
        }

        $getResponse = $this->httpClient->request('GET', $resourceReference, ['Accept' => 'text/calendar']);
        $this->assertResponseStatus($getResponse, [200], 'recurring following retrieval');
        $resourceUrl = $this->trustedEffectiveUrl($getResponse, $resourceReference);
        $this->assertResourceBelongsToCalendar($calendarUrl, $resourceUrl);
        $resourceEtag = trim((string) ($getResponse->headers['etag'] ?? ''));
        if ($resourceEtag === '') {
            $resourceEtag = $fallbackEtag;
        }

        $events = ICalendarCodec::parseEvents($getResponse->body, $resourceUrl, $resourceEtag);
        $master = $this->recurringMasterEvent($events, $seriesId);
        $settings = $this->recurrenceSettingsForSplit($master);
        $targetStart = $this->recurringOriginalStart($master, $originalStart);
        $target = $this->recurringTargetEvent(
            $getResponse->body,
            $resourceUrl,
            $resourceEtag,
            $events,
            $seriesId,
            $targetStart
        );

        if (($settings['endMode'] ?? '') === 'count') {
            $declaredCount = (int) ($settings['count'] ?? 0);
            $position = $this->recurringOccurrencePosition($master, $settings, $targetStart);
            $remaining = $declaredCount - $position + 1;
            if ($declaredCount < 1 || $remaining < 1) {
                throw new CalDAVProviderException('The recurring series count could not be verified.');
            }
            $settings['count'] = $remaining;
        }

        $following = $master;
        foreach (['start', 'end', 'startTimestamp', 'endTimestamp', 'allDay', 'timezone'] as $key) {
            $following[$key] = $target[$key];
        }
        $following['resourceUrl'] = $resourceUrl;
        $following['etag'] = $resourceEtag;
        $following['recurrenceSettings'] = $settings;
        $following = array_merge(
            $following,
            CalendarEventRecurrence::occurrence(
                $seriesId,
                $occurrenceId,
                $originalStart,
                trim((string) ($target['recurrenceId'] ?? '')),
                true,
                ($target['recurrenceType'] ?? '') === CalendarEventRecurrence::EXCEPTION,
                true,
                true,
                true
            )
        );
        $following['recurrenceEditable'] = true;
        $following['writeScope'] = CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING;

        return $following;
    }

    /** @inheritDoc */
    public function createEvent(string $calendarUrl, array $event): array
    {
        $calendarUrl = $this->normalizeAbsoluteUrl($calendarUrl);
        $created = ICalendarCodec::createEvent($event);
        $resourceUrl = rtrim($calendarUrl, '/') . '/' . rawurlencode($created['uid']) . '.ics';
        $response = $this->httpClient->request(
            'PUT',
            $resourceUrl,
            [
                'Content-Type'  => 'text/calendar; charset=utf-8',
                'If-None-Match' => '*'
            ],
            $created['ical']
        );
        $this->assertResponseStatus($response, [200, 201, 204], 'event creation');
        $effectiveResourceUrl = $this->trustedEffectiveUrl($response, $resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $effectiveResourceUrl);

        return [
            'uid'         => $created['uid'],
            'resourceUrl' => $effectiveResourceUrl,
            'etag'        => (string) ($response->headers['etag'] ?? '')
        ];
    }

    /** @inheritDoc */
    public function updateEvent(
        string $calendarUrl,
        string $resourceUrl,
        string $etag,
        string $uid,
        array $event,
        array $recurrence = []
    ): array {
        $calendarUrl = $this->normalizeAbsoluteUrl($calendarUrl);
        $resourceUrl = $this->normalizeAbsoluteUrl($resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $resourceUrl);
        $uid = trim($uid);
        if ($uid === '') {
            throw new CalDAVProviderException('The event UID is missing.');
        }

        $identity = CalendarEventRecurrence::fromEvent($recurrence);
        $recurrenceType = (string) ($identity['recurrenceType'] ?? CalendarEventRecurrence::SINGLE);
        if (($identity['writeScope'] ?? '') === CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING) {
            $this->assertWritableFollowing($identity, $uid, true);
            return $this->updateFollowingInstances($calendarUrl, $resourceUrl, $etag, $uid, $event, $identity);
        }
        if (($identity['writeScope'] ?? '') === CalendarEventRecurrence::WRITE_SCOPE_SERIES) {
            $this->assertWritableSeries($identity, $uid, true);
        } elseif ($recurrenceType !== CalendarEventRecurrence::SINGLE) {
            $this->assertWritableOccurrence($identity, $uid, true);
        }

        // CalDAV updates always replace the complete calendar object resource. Read the
        // resource immediately before every PUT and use the ETag returned by that GET.
        // The ETag supplied by the editor is only a snapshot from when editing started
        // and must not override the fresher resource validator obtained here.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $getResponse = $this->httpClient->request('GET', $resourceUrl, ['Accept' => 'text/calendar']);
            $this->assertResponseStatus($getResponse, [200], 'event retrieval');
            $effectiveResourceUrl = $this->trustedEffectiveUrl($getResponse, $resourceUrl);
            $this->assertResourceBelongsToCalendar($calendarUrl, $effectiveResourceUrl);

            if (($identity['writeScope'] ?? '') === CalendarEventRecurrence::WRITE_SCOPE_SERIES) {
                $updatedIcal = ICalendarCodec::updateRecurringSeries(
                    $getResponse->body,
                    trim((string) ($identity['seriesId'] ?? '')),
                    $event
                );
            } elseif ($recurrenceType !== CalendarEventRecurrence::SINGLE) {
                $updatedIcal = ICalendarCodec::updateRecurringOccurrence(
                    $getResponse->body,
                    $uid,
                    trim((string) ($identity['originalStart'] ?? '')),
                    $event
                );
            } elseif (ICalendarCodec::hasRecurringEvent($getResponse->body, $uid)) {
                // CALDAV:expand may omit RECURRENCE-ID on the first instance. If the
                // backing resource is recurring, an apparently single first instance
                // must still be written as an exception instead of changing the master.
                $updatedIcal = ICalendarCodec::updateRecurringOccurrence($getResponse->body, $uid, '', $event);
            } elseif (is_array($event['recurrence'] ?? null) && $event['recurrence'] !== []) {
                $updatedIcal = ICalendarCodec::convertEventToRecurringSeries($getResponse->body, $uid, $event);
            } else {
                $updatedIcal = ICalendarCodec::updateEvent($getResponse->body, $uid, $event);
            }

            $currentEtag = trim((string) ($getResponse->headers['etag'] ?? ''));
            if ($currentEtag === '') {
                // RFC 4791 requires a strong ETag on a calendar object GET. Keep the
                // editor value only as a compatibility fallback for non-conforming servers.
                $currentEtag = trim($etag);
            }
            $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
            if ($currentEtag !== '') {
                $headers['If-Match'] = $currentEtag;
            }

            $putResponse = $this->httpClient->request('PUT', $effectiveResourceUrl, $headers, $updatedIcal);
            if ($putResponse->statusCode === 412 && $attempt === 0) {
                // A resource can legitimately change between the GET and PUT. Re-read it
                // once, re-apply the requested changes and retry with the new ETag.
                $resourceUrl = $effectiveResourceUrl;
                continue;
            }

            $this->assertResponseStatus($putResponse, [200, 201, 204], 'event update');
            $updatedResourceUrl = $this->trustedEffectiveUrl($putResponse, $effectiveResourceUrl);
            $this->assertResourceBelongsToCalendar($calendarUrl, $updatedResourceUrl);

            return [
                'uid'         => $uid,
                'resourceUrl' => $updatedResourceUrl,
                'etag'        => (string) ($putResponse->headers['etag'] ?? '')
            ];
        }

        throw new CalDAVProviderException('The calendar object could not be updated.', 412);
    }

    /** @inheritDoc */
    public function deleteEvent(
        string $calendarUrl,
        string $resourceUrl,
        string $etag,
        string $recurrenceId = '',
        array $recurrence = []
    ): bool {
        $calendarUrl = $this->normalizeAbsoluteUrl($calendarUrl);
        $resourceUrl = $this->normalizeAbsoluteUrl($resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $resourceUrl);

        $identity = CalendarEventRecurrence::fromEvent($recurrence);
        $recurrenceType = (string) ($identity['recurrenceType'] ?? CalendarEventRecurrence::SINGLE);
        $seriesId = trim((string) ($identity['seriesId'] ?? ''));
        $originalStart = trim((string) ($identity['originalStart'] ?? ''));
        if (($identity['writeScope'] ?? '') === CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING) {
            $this->assertWritableFollowing($identity, $seriesId, false);
            return $this->deleteFollowingInstances($calendarUrl, $resourceUrl, $etag, $identity);
        }

        $getResponse = $this->httpClient->request('GET', $resourceUrl, ['Accept' => 'text/calendar']);
        $this->assertResponseStatus($getResponse, [200], 'event retrieval');
        $effectiveResourceUrl = $this->trustedEffectiveUrl($getResponse, $resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $effectiveResourceUrl);
        $currentEtag = trim((string) ($getResponse->headers['etag'] ?? ''));
        if ($currentEtag === '') {
            $currentEtag = trim($etag);
        }

        if (($identity['writeScope'] ?? '') === CalendarEventRecurrence::WRITE_SCOPE_SERIES) {
            $this->assertWritableSeries($identity, $seriesId, false);
            if (!ICalendarCodec::hasRecurringEvent($getResponse->body, $seriesId)) {
                throw new CalDAVProviderException('The CalDAV recurring series could not be verified.');
            }

            return $this->deleteResource(
                $calendarUrl,
                $effectiveResourceUrl,
                $currentEtag,
                'recurring series deletion'
            );
        }
        if ($recurrenceType !== CalendarEventRecurrence::SINGLE) {
            $this->assertWritableOccurrence($identity, $seriesId, false);
            $updatedIcal = ICalendarCodec::deleteRecurringOccurrence(
                $getResponse->body,
                $seriesId,
                $originalStart
            );

            return $this->putRecurringResource(
                $calendarUrl,
                $effectiveResourceUrl,
                $currentEtag,
                $updatedIcal,
                'recurring occurrence deletion'
            );
        }

        if ($recurrenceId !== '') {
            $updatedIcal = ICalendarCodec::deleteRecurringOccurrence(
                $getResponse->body,
                '',
                trim($recurrenceId)
            );

            return $this->putRecurringResource(
                $calendarUrl,
                $effectiveResourceUrl,
                $currentEtag,
                $updatedIcal,
                'recurring occurrence deletion'
            );
        }

        if (ICalendarCodec::hasRecurringEvent($getResponse->body)) {
            // The first expanded instance is allowed to arrive without
            // RECURRENCE-ID. Exclude only that first instance, never the resource.
            $updatedIcal = ICalendarCodec::deleteRecurringOccurrence($getResponse->body, '', '');

            return $this->putRecurringResource(
                $calendarUrl,
                $effectiveResourceUrl,
                $currentEtag,
                $updatedIcal,
                'recurring occurrence deletion'
            );
        }

        return $this->deleteResource($calendarUrl, $effectiveResourceUrl, $currentEtag, 'event deletion');
    }

    /**
     * Marks server-expanded recurrence instances as individually writable.
     *
     * CALDAV:expand strips RRULE/RDATE/EXDATE from returned components. All
     * non-initial instances carry RECURRENCE-ID, while the initial instance may
     * omit it. Multiple components with the same UID therefore identify the
     * initial component as part of the same recurring resource as well.
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function enableExpandedOccurrenceWrites(array $events): array
    {
        $groups = [];
        foreach ($events as $index => $event) {
            $uid = trim((string) ($event['uid'] ?? ''));
            if ($uid !== '') {
                $groups[$uid][] = $index;
            }
        }

        foreach ($groups as $uid => $indexes) {
            $expandedRecurring = count($indexes) > 1;
            foreach ($indexes as $index) {
                if (trim((string) ($events[$index]['recurrenceId'] ?? '')) !== '') {
                    $expandedRecurring = true;
                    break;
                }
            }
            if (!$expandedRecurring) {
                continue;
            }

            foreach ($indexes as $index) {
                $originalStart = trim((string) ($events[$index]['originalStart'] ?? ''));
                if ($originalStart === '') {
                    $originalStart = trim((string) ($events[$index]['start'] ?? ''));
                }
                if ($originalStart === '') {
                    continue;
                }
                $recurrenceId = trim((string) ($events[$index]['recurrenceId'] ?? ''));
                $events[$index] = array_merge(
                    $events[$index],
                    CalendarEventRecurrence::occurrence(
                        $uid,
                        $uid . '|' . ($recurrenceId !== '' ? $recurrenceId : $originalStart),
                        $originalStart,
                        $recurrenceId,
                        true,
                        false,
                        true,
                        true,
                        true
                    )
                );
            }
        }

        return $events;
    }

    /**
     * Applies a "this and following" update by splitting one CalDAV recurring resource.
     *
     * A new future resource is created first. Only after that succeeds is the original
     * series shortened. If shortening fails, the new resource is removed again before
     * a retry or error is returned.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    private function updateFollowingInstances(
        string $calendarUrl,
        string $resourceUrl,
        string $etag,
        string $uid,
        array $event,
        array $identity
    ): array {
        $seriesId = trim((string) ($identity['seriesId'] ?? ''));
        $originalStart = trim((string) ($identity['originalStart'] ?? ''));
        if (!is_array($event['recurrence'] ?? null) || $event['recurrence'] === []) {
            throw new CalDAVProviderException(
                'The recurrence settings are required when splitting a recurring event.'
            );
        }

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $getResponse = $this->httpClient->request('GET', $resourceUrl, ['Accept' => 'text/calendar']);
            $this->assertResponseStatus($getResponse, [200], 'recurring following retrieval');
            $effectiveResourceUrl = $this->trustedEffectiveUrl($getResponse, $resourceUrl);
            $this->assertResourceBelongsToCalendar($calendarUrl, $effectiveResourceUrl);
            $currentEtag = trim((string) ($getResponse->headers['etag'] ?? ''));
            if ($currentEtag === '') {
                $currentEtag = trim($etag);
            }

            $master = $this->recurringMasterEvent(
                ICalendarCodec::parseEvents($getResponse->body, $effectiveResourceUrl, $currentEtag),
                $seriesId
            );
            $settings = $this->recurrenceSettingsForSplit($master);
            $targetStart = $this->recurringOriginalStart($master, $originalStart);
            $position = $this->recurringOccurrencePosition($master, $settings, $targetStart);

            if ($position === 1) {
                $updatedIcal = ICalendarCodec::updateRecurringSeries($getResponse->body, $seriesId, $event);
                $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
                if ($currentEtag !== '') {
                    $headers['If-Match'] = $currentEtag;
                }
                $putResponse = $this->httpClient->request('PUT', $effectiveResourceUrl, $headers, $updatedIcal);
                if ($putResponse->statusCode === 412 && $attempt === 0) {
                    $resourceUrl = $effectiveResourceUrl;
                    continue;
                }
                $this->assertResponseStatus($putResponse, [200, 201, 204], 'event update');
                $updatedResourceUrl = $this->trustedEffectiveUrl($putResponse, $effectiveResourceUrl);
                $this->assertResourceBelongsToCalendar($calendarUrl, $updatedResourceUrl);

                return [
                    'uid'         => $uid,
                    'resourceUrl' => $updatedResourceUrl,
                    'etag'        => (string) ($putResponse->headers['etag'] ?? '')
                ];
            }

            $split = ICalendarCodec::splitRecurringSeries(
                $getResponse->body,
                $seriesId,
                $originalStart,
                $event
            );
            $newResourceUrl = rtrim($calendarUrl, '/') . '/' . rawurlencode($split['newUid']) . '.ics';
            $createResponse = $this->httpClient->request(
                'PUT',
                $newResourceUrl,
                [
                    'Content-Type'  => 'text/calendar; charset=utf-8',
                    'If-None-Match' => '*'
                ],
                $split['newIcal']
            );
            if ($createResponse->statusCode === 412 && $attempt === 0) {
                continue;
            }
            $this->assertResponseStatus($createResponse, [200, 201, 204], 'recurring split creation');
            $createdResourceUrl = $this->trustedEffectiveUrl($createResponse, $newResourceUrl);
            $this->assertResourceBelongsToCalendar($calendarUrl, $createdResourceUrl);
            $createdEtag = trim((string) ($createResponse->headers['etag'] ?? ''));

            $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
            if ($currentEtag !== '') {
                $headers['If-Match'] = $currentEtag;
            }
            $trimResponse = $this->httpClient->request(
                'PUT',
                $effectiveResourceUrl,
                $headers,
                $split['originalIcal']
            );
            if (!in_array($trimResponse->statusCode, [200, 201, 204], true)) {
                try {
                    $this->deleteResource(
                        $calendarUrl,
                        $createdResourceUrl,
                        $createdEtag,
                        'recurring split rollback'
                    );
                } catch (Throwable) {
                    throw new CalDAVProviderException(
                        'The recurring series could not be split and the temporary future series could not be removed automatically.'
                    );
                }

                if ($trimResponse->statusCode === 412 && $attempt === 0) {
                    $resourceUrl = $effectiveResourceUrl;
                    continue;
                }
                $this->assertResponseStatus($trimResponse, [200, 201, 204], 'event update');
            }

            $trimmedResourceUrl = $this->trustedEffectiveUrl($trimResponse, $effectiveResourceUrl);
            $this->assertResourceBelongsToCalendar($calendarUrl, $trimmedResourceUrl);

            return [
                'uid'         => $split['newUid'],
                'resourceUrl' => $createdResourceUrl,
                'etag'        => $createdEtag
            ];
        }

        throw new CalDAVProviderException('The recurring series could not be split safely.', 412);
    }

    /**
     * Deletes the selected CalDAV occurrence and every following occurrence.
     *
     * @param array<string, mixed> $identity
     */
    private function deleteFollowingInstances(
        string $calendarUrl,
        string $resourceUrl,
        string $etag,
        array $identity
    ): bool {
        $seriesId = trim((string) ($identity['seriesId'] ?? ''));
        $originalStart = trim((string) ($identity['originalStart'] ?? ''));

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $getResponse = $this->httpClient->request('GET', $resourceUrl, ['Accept' => 'text/calendar']);
            $this->assertResponseStatus($getResponse, [200], 'recurring following retrieval');
            $effectiveResourceUrl = $this->trustedEffectiveUrl($getResponse, $resourceUrl);
            $this->assertResourceBelongsToCalendar($calendarUrl, $effectiveResourceUrl);
            $currentEtag = trim((string) ($getResponse->headers['etag'] ?? ''));
            if ($currentEtag === '') {
                $currentEtag = trim($etag);
            }

            $master = $this->recurringMasterEvent(
                ICalendarCodec::parseEvents($getResponse->body, $effectiveResourceUrl, $currentEtag),
                $seriesId
            );
            $settings = $this->recurrenceSettingsForSplit($master);
            $targetStart = $this->recurringOriginalStart($master, $originalStart);
            $position = $this->recurringOccurrencePosition($master, $settings, $targetStart);

            if ($position === 1) {
                $headers = [];
                if ($currentEtag !== '') {
                    $headers['If-Match'] = $currentEtag;
                }
                $deleteResponse = $this->httpClient->request('DELETE', $effectiveResourceUrl, $headers);
                if ($deleteResponse->statusCode === 412 && $attempt === 0) {
                    $resourceUrl = $effectiveResourceUrl;
                    continue;
                }
                $this->assertResponseStatus($deleteResponse, [200, 204], 'recurring series deletion');
                $deletedResourceUrl = $this->trustedEffectiveUrl($deleteResponse, $effectiveResourceUrl);
                $this->assertResourceBelongsToCalendar($calendarUrl, $deletedResourceUrl);

                return true;
            }

            $trimmedIcal = ICalendarCodec::trimRecurringSeriesBefore(
                $getResponse->body,
                $seriesId,
                $originalStart
            );
            $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
            if ($currentEtag !== '') {
                $headers['If-Match'] = $currentEtag;
            }
            $putResponse = $this->httpClient->request('PUT', $effectiveResourceUrl, $headers, $trimmedIcal);
            if ($putResponse->statusCode === 412 && $attempt === 0) {
                $resourceUrl = $effectiveResourceUrl;
                continue;
            }
            $this->assertResponseStatus($putResponse, [200, 201, 204], 'event update');
            $updatedResourceUrl = $this->trustedEffectiveUrl($putResponse, $effectiveResourceUrl);
            $this->assertResourceBelongsToCalendar($calendarUrl, $updatedResourceUrl);

            return true;
        }

        throw new CalDAVProviderException('The recurring series could not be shortened safely.', 412);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function recurringMasterEvent(array $events, string $seriesId): array
    {
        $masters = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['recurrenceType'] ?? '') === CalendarEventRecurrence::MASTER
                && hash_equals($seriesId, trim((string) ($event['seriesId'] ?? '')))
        ));
        if (count($masters) !== 1) {
            throw new CalDAVProviderException('CalDAV did not return one recurring parent event.');
        }

        return $masters[0];
    }

    /**
     * @param array<string, mixed> $master
     * @return array<string, mixed>
     */
    private function recurrenceSettingsForSplit(array $master): array
    {
        if (trim((string) ($master['recurrenceRule'] ?? '')) === ''
            || (is_array($master['recurrenceDates'] ?? null) ? $master['recurrenceDates'] : []) !== []) {
            throw new CalDAVProviderException('The recurrence pattern cannot be split safely.');
        }
        $settings = CalendarRecurrenceRule::fromGoogleRule(
            (string) $master['recurrenceRule'],
            (bool) ($master['allDay'] ?? false),
            (string) ($master['timezone'] ?? '')
        );
        if ($settings === null) {
            throw new CalDAVProviderException('The recurrence pattern cannot be split safely.');
        }

        return $settings;
    }

    /** @param array<string, mixed> $master */
    private function recurringOriginalStart(array $master, string $originalStart): DateTimeImmutable
    {
        $timezoneName = trim((string) ($master['timezone'] ?? ''));
        try {
            $timezone = new DateTimeZone($timezoneName !== '' ? $timezoneName : 'UTC');
            if ((bool) ($master['allDay'] ?? false)
                && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', trim($originalStart)) === 1) {
                $target = DateTimeImmutable::createFromFormat('!Y-m-d', trim($originalStart), $timezone);
                if ($target !== false && $target->format('Y-m-d') === trim($originalStart)) {
                    return $target;
                }
            }

            return (new DateTimeImmutable(trim($originalStart), $timezone))->setTimezone($timezone);
        } catch (Throwable) {
            throw new CalDAVProviderException('The recurring target start is invalid.');
        }
    }

    /**
     * @param array<string, mixed> $master
     * @param array<string, mixed> $settings
     */
    private function recurringOccurrencePosition(
        array $master,
        array $settings,
        DateTimeImmutable $targetStart
    ): int {
        $timezoneName = trim((string) ($master['timezone'] ?? ''));
        try {
            $timezone = new DateTimeZone($timezoneName !== '' ? $timezoneName : 'UTC');
            $masterStart = (new DateTimeImmutable('@' . (int) ($master['startTimestamp'] ?? 0)))
                ->setTimezone($timezone);
            if (!(bool) ($master['allDay'] ?? false)
                && $masterStart->format('H:i:s') !== $targetStart->format('H:i:s')) {
                throw new RuntimeException('Recurring target time does not match the series pattern.');
            }
            $microsoftRecurrence = CalendarRecurrenceRule::toMicrosoftRecurrence($settings, $masterStart);
            return CalendarRecurrenceRule::microsoftOccurrencePosition(
                $microsoftRecurrence,
                $targetStart->format('Y-m-d')
            );
        } catch (Throwable) {
            throw new CalDAVProviderException('The recurring target occurrence is not part of the series pattern.');
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function recurringTargetEvent(
        string $ical,
        string $resourceUrl,
        string $etag,
        array $events,
        string $seriesId,
        DateTimeImmutable $targetStart
    ): array {
        foreach ($events as $event) {
            if (!hash_equals($seriesId, trim((string) ($event['seriesId'] ?? $event['uid'] ?? '')))) {
                continue;
            }
            if (($event['recurrenceIdTimestamp'] ?? null) !== $targetStart->getTimestamp()) {
                continue;
            }
            if (strtoupper(trim((string) ($event['status'] ?? ''))) === 'CANCELLED') {
                throw new CalDAVProviderException('The selected recurring occurrence is no longer available.');
            }

            return $event;
        }

        $rangeStart = (new DateTimeImmutable('@' . $targetStart->getTimestamp()))->modify('-1 second');
        $rangeEnd = (new DateTimeImmutable('@' . $targetStart->getTimestamp()))->modify('+1 second');
        foreach (ICalendarCodec::parseEventsInRange($ical, $resourceUrl, $etag, $rangeStart, $rangeEnd) as $event) {
            if (!hash_equals($seriesId, trim((string) ($event['seriesId'] ?? '')))
                || ($event['recurrenceIdTimestamp'] ?? null) !== $targetStart->getTimestamp()) {
                continue;
            }

            return $event;
        }

        throw new CalDAVProviderException('The recurring target occurrence could not be verified.');
    }

    /** @param array<string, mixed> $identity */
    private function assertWritableFollowing(array $identity, string $expectedSeriesId, bool $updating): void
    {
        $seriesId = trim((string) ($identity['seriesId'] ?? ''));
        if (!CalendarEventRecurrence::isOccurrence($identity)
            || ($identity['writeScope'] ?? '') !== CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING
            || $seriesId === ''
            || ($expectedSeriesId !== '' && !hash_equals($expectedSeriesId, $seriesId))
            || trim((string) ($identity['occurrenceId'] ?? '')) === ''
            || trim((string) ($identity['originalStart'] ?? '')) === ''
            || !(bool) ($identity['canUpdateFollowing'] ?? false)
            || (!$updating && !(bool) ($identity['canDeleteSeries'] ?? false))) {
            throw new CalDAVProviderException('The CalDAV recurring event cannot be split at this occurrence.');
        }
    }

    /** @param array<string, mixed> $identity */
    private function assertWritableOccurrence(array $identity, string $expectedSeriesId, bool $updating): void
    {
        $capability = $updating ? 'canUpdateOccurrence' : 'canDeleteOccurrence';
        $seriesId = trim((string) ($identity['seriesId'] ?? ''));
        if (!CalendarEventRecurrence::isOccurrence($identity)
            || ($identity['writeScope'] ?? '') !== CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE
            || $seriesId === ''
            || ($expectedSeriesId !== '' && !hash_equals($expectedSeriesId, $seriesId))
            || trim((string) ($identity['occurrenceId'] ?? '')) === ''
            || trim((string) ($identity['originalStart'] ?? '')) === ''
            || !(bool) ($identity[$capability] ?? false)) {
            throw new CalDAVProviderException('The CalDAV recurring occurrence cannot be modified.');
        }
    }

    /** @param array<string, mixed> $identity */
    private function assertWritableSeries(array $identity, string $expectedSeriesId, bool $updating): void
    {
        $capability = $updating ? 'canUpdateSeries' : 'canDeleteSeries';
        $seriesId = trim((string) ($identity['seriesId'] ?? ''));
        if (($identity['recurrenceType'] ?? '') !== CalendarEventRecurrence::MASTER
            || ($identity['writeScope'] ?? '') !== CalendarEventRecurrence::WRITE_SCOPE_SERIES
            || $seriesId === ''
            || ($expectedSeriesId !== '' && !hash_equals($expectedSeriesId, $seriesId))
            || !(bool) ($identity[$capability] ?? false)) {
            throw new CalDAVProviderException('The complete CalDAV recurring series cannot be modified.');
        }
    }

    private function putRecurringResource(
        string $calendarUrl,
        string $resourceUrl,
        string $etag,
        string $ical,
        string $operation
    ): bool {
        $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
        if ($etag !== '') {
            $headers['If-Match'] = $etag;
        }
        $response = $this->httpClient->request('PUT', $resourceUrl, $headers, $ical);
        $this->assertResponseStatus($response, [200, 201, 204], $operation);
        $updatedResourceUrl = $this->trustedEffectiveUrl($response, $resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $updatedResourceUrl);

        return true;
    }

    private function deleteResource(
        string $calendarUrl,
        string $resourceUrl,
        string $etag,
        string $operation
    ): bool {
        $headers = [];
        if ($etag !== '') {
            $headers['If-Match'] = $etag;
        }
        $response = $this->httpClient->request('DELETE', $resourceUrl, $headers);
        $this->assertResponseStatus($response, [200, 204], $operation);
        $deletedResourceUrl = $this->trustedEffectiveUrl($response, $resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $deletedResourceUrl);

        return true;
    }

    /**
     * Finds the CalDAV object resource containing the requested recurring UID.
     *
     * @return array{resourceUrl: string, etag: string, ical: string}
     */
    private function findRecurringResource(string $calendarUrl, string $seriesId): array
    {
        $escapedSeriesId = htmlspecialchars($seriesId, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $body = '<?xml version="1.0" encoding="utf-8" ?>' .
            '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' .
            '<d:prop><d:getetag/><c:calendar-data/></d:prop>' .
            '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">' .
            '<c:prop-filter name="UID"><c:text-match collation="i;octet">' . $escapedSeriesId . '</c:text-match>' .
            '</c:prop-filter></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
        $response = $this->httpClient->request(
            'REPORT',
            $calendarUrl,
            [
                'Accept'       => 'application/xml, text/xml',
                'Content-Type' => 'application/xml; charset=utf-8',
                'Depth'        => '1'
            ],
            $body
        );
        $this->assertResponseStatus($response, [207], 'recurring series lookup');
        $effectiveCalendarUrl = $this->trustedEffectiveUrl($response, $calendarUrl);

        $document = $this->parseXml($response->body);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV_NAMESPACE);
        $xpath->registerNamespace('c', self::CALDAV_NAMESPACE);
        $matches = [];
        $responses = $xpath->query('//d:multistatus/d:response');
        if ($responses === false) {
            throw new CalDAVProviderException('CalDAV did not return the recurring series resource.');
        }

        foreach ($responses as $eventResponse) {
            if (!$eventResponse instanceof DOMElement) {
                continue;
            }
            $href = $this->firstNodeValue($xpath, './d:href', $eventResponse);
            $calendarData = $this->firstNodeValue($xpath, './/c:calendar-data', $eventResponse);
            if ($href === '' || $calendarData === '') {
                continue;
            }
            $resourceUrl = $this->resolveUrl($effectiveCalendarUrl, $href);
            $this->assertResourceBelongsToCalendar($calendarUrl, $resourceUrl);
            if (!ICalendarCodec::hasRecurringEvent($calendarData, $seriesId)) {
                continue;
            }
            $matches[] = [
                'resourceUrl' => $resourceUrl,
                'etag'        => $this->firstNodeValue($xpath, './/d:getetag', $eventResponse),
                'ical'        => $calendarData
            ];
        }

        if (count($matches) !== 1) {
            throw new CalDAVProviderException(
                $matches === []
                    ? 'CalDAV did not return the recurring series resource.'
                    : 'CalDAV returned multiple resources for the recurring series.'
            );
        }

        return $matches[0];
    }

    private function discoverPrincipal(string $url): string
    {
        $response = $this->propfind(
            $url,
            0,
            '<?xml version="1.0" encoding="utf-8" ?>' .
            '<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>'
        );

        $document = $this->parseXml($response->body);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV_NAMESPACE);
        $href = $this->firstNodeValue($xpath, '//d:current-user-principal/d:href');

        if ($href === '') {
            throw new CalDAVProviderException('The CalDAV server did not return a current-user-principal.');
        }

        return $this->resolveUrl($this->trustedEffectiveUrl($response, $url), $href);
    }

    private function discoverCalendarHomeSet(string $principalUrl): string
    {
        $response = $this->propfind(
            $principalUrl,
            0,
            '<?xml version="1.0" encoding="utf-8" ?>' .
            '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' .
            '<d:prop><c:calendar-home-set/></d:prop></d:propfind>'
        );

        $document = $this->parseXml($response->body);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV_NAMESPACE);
        $xpath->registerNamespace('c', self::CALDAV_NAMESPACE);
        $href = $this->firstNodeValue($xpath, '//c:calendar-home-set/d:href');

        if ($href === '') {
            throw new CalDAVProviderException('The CalDAV server did not return a calendar-home-set.');
        }

        return $this->resolveUrl($this->trustedEffectiveUrl($response, $principalUrl), $href);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverCalendars(string $homeSetUrl): array
    {
        $response = $this->propfind(
            $homeSetUrl,
            1,
            '<?xml version="1.0" encoding="utf-8" ?>' .
            '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/">' .
            '<d:prop>' .
            '<d:resourcetype/><d:displayname/><d:getetag/><d:sync-token/><d:current-user-privilege-set/>' .
            '<c:calendar-description/><c:supported-calendar-component-set/><a:calendar-color/>' .
            '</d:prop></d:propfind>'
        );

        $document = $this->parseXml($response->body);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV_NAMESPACE);
        $xpath->registerNamespace('c', self::CALDAV_NAMESPACE);
        $xpath->registerNamespace('a', self::APPLE_NAMESPACE);

        $calendars = [];
        $responses = $xpath->query('//d:multistatus/d:response');
        if ($responses === false) {
            return [];
        }

        foreach ($responses as $calendarResponse) {
            if (!$calendarResponse instanceof DOMElement) {
                continue;
            }

            $calendarTypeNodes = $xpath->query('.//d:resourcetype/c:calendar', $calendarResponse);
            if ($calendarTypeNodes === false || $calendarTypeNodes->length === 0) {
                continue;
            }

            $href = $this->firstNodeValue($xpath, './d:href', $calendarResponse);
            if ($href === '') {
                continue;
            }

            $components = [];
            $componentNodes = $xpath->query('.//c:supported-calendar-component-set/c:comp', $calendarResponse);
            if ($componentNodes !== false) {
                foreach ($componentNodes as $componentNode) {
                    if ($componentNode instanceof DOMElement) {
                        $name = strtoupper($componentNode->getAttribute('name'));
                        if ($name !== '') {
                            $components[] = $name;
                        }
                    }
                }
            }

            if ($components !== [] && !in_array('VEVENT', $components, true)) {
                continue;
            }

            $privileges = [];
            $privilegeNodes = $xpath->query('.//d:current-user-privilege-set/d:privilege/*', $calendarResponse);
            if ($privilegeNodes !== false) {
                foreach ($privilegeNodes as $privilegeNode) {
                    $privileges[] = $privilegeNode->localName;
                }
            }

            $writeAccessKnown = $privileges !== [];
            $canWrite = !$writeAccessKnown
                || count(array_intersect($privileges, ['write', 'write-content', 'bind', 'unbind'])) > 0;
            $name = $this->firstNodeValue($xpath, './/d:displayname', $calendarResponse);
            $url = $this->resolveUrl($this->trustedEffectiveUrl($response, $homeSetUrl), $href);

            $calendars[] = [
                'id'               => hash('sha256', $url),
                'providerId'       => $url,
                'reference'        => $url,
                'url'              => $url,
                'name'             => $name !== '' ? $name : basename(rtrim(rawurldecode($href), '/')),
                'description'      => $this->firstNodeValue($xpath, './/c:calendar-description', $calendarResponse),
                'color'            => $this->normalizeColor($this->firstNodeValue($xpath, './/a:calendar-color', $calendarResponse)),
                'etag'             => $this->firstNodeValue($xpath, './/d:getetag', $calendarResponse),
                'syncToken'        => $this->firstNodeValue($xpath, './/d:sync-token', $calendarResponse),
                'components'       => array_values(array_unique($components)),
                'writeAccessKnown' => $writeAccessKnown,
                'capabilities'     => [
                    'read'             => true,
                    'create'           => $canWrite,
                    'update'           => $canWrite,
                    'delete'           => $canWrite,
                    'createRecurrence' => $canWrite,
                    'updateRecurrence' => $canWrite,
                    'updateOccurrence' => $canWrite,
                    'deleteOccurrence' => $canWrite,
                    'updateFollowing'  => $canWrite,
                    'updateSeries'     => $canWrite,
                    'deleteSeries'     => $canWrite,
                    'maxReminders'     => CalendarEventReminder::MAX_REMINDERS
                ]
            ];
        }

        usort($calendars, static fn (array $left, array $right): int => strcasecmp((string) $left['name'], (string) $right['name']));

        return $calendars;
    }

    private function propfind(string $url, int $depth, string $body): CalendarHttpResponse
    {
        $response = $this->httpClient->request(
            'PROPFIND',
            $url,
            [
                'Accept'       => 'application/xml, text/xml',
                'Content-Type' => 'application/xml; charset=utf-8',
                'Depth'        => (string) $depth
            ],
            $body
        );

        if ($response->statusCode === 401 || $response->statusCode === 403) {
            throw new CalDAVProviderException('Authentication failed or calendar access was denied.', $response->statusCode);
        }

        if ($response->statusCode !== 207) {
            throw new CalDAVProviderException(
                sprintf('Unexpected CalDAV response: HTTP %d.', $response->statusCode),
                $response->statusCode
            );
        }

        $effectiveUrl = $this->trustedEffectiveUrl($response, $url);

        return new CalendarHttpResponse(
            $response->statusCode,
            $response->headers,
            $response->body,
            $effectiveUrl
        );
    }

    /**
     * @param list<int> $expectedStatusCodes
     */
    private function assertResponseStatus(
        CalendarHttpResponse $response,
        array $expectedStatusCodes,
        string $operation
    ): void {
        if (in_array($response->statusCode, [401, 403], true)) {
            throw new CalDAVProviderException('Authentication failed or calendar access was denied.', $response->statusCode);
        }
        if ($response->statusCode === 412 && in_array(
            $operation,
            ['event update', 'event deletion', 'recurring occurrence deletion', 'recurring series deletion'],
            true
        )) {
            throw new CalDAVProviderException(
                'The calendar object changed before OpenCalendar could complete the write. Please try again.',
                412
            );
        }
        if (!in_array($response->statusCode, $expectedStatusCodes, true)) {
            throw new CalDAVProviderException(
                sprintf('Unexpected CalDAV response during %s: HTTP %d.', $operation, $response->statusCode),
                $response->statusCode
            );
        }
    }

    private function normalizeAbsoluteUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new CalDAVProviderException('The CalDAV resource URL is invalid.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new CalDAVProviderException('Credentials and fragments are not allowed in CalDAV resource URLs.');
        }
        if (!$this->originPolicy->isAllowedUrl($url)) {
            throw new CalDAVProviderException('The CalDAV resource URL belongs to an untrusted origin.');
        }

        return $url;
    }

    private function assertResourceBelongsToCalendar(string $calendarUrl, string $resourceUrl): void
    {
        $calendar = parse_url($calendarUrl);
        $resource = parse_url($resourceUrl);
        if ($calendar === false || $resource === false) {
            throw new CalDAVProviderException('The CalDAV resource URL is invalid.');
        }

        $calendarPort = $calendar['port'] ?? (strtolower((string) ($calendar['scheme'] ?? '')) === 'https' ? 443 : 80);
        $resourcePort = $resource['port'] ?? (strtolower((string) ($resource['scheme'] ?? '')) === 'https' ? 443 : 80);
        $calendarPath = rtrim($this->normalizePath((string) ($calendar['path'] ?? '/')), '/') . '/';
        $resourcePath = $this->normalizePath((string) ($resource['path'] ?? '/'));

        if (strcasecmp((string) ($calendar['scheme'] ?? ''), (string) ($resource['scheme'] ?? '')) !== 0
            || strcasecmp((string) ($calendar['host'] ?? ''), (string) ($resource['host'] ?? '')) !== 0
            || $calendarPort !== $resourcePort
            || !str_starts_with($resourcePath, $calendarPath)) {
            throw new CalDAVProviderException('The event resource does not belong to the configured calendar.');
        }
    }

    private function trustedEffectiveUrl(CalendarHttpResponse $response, string $requestedUrl): string
    {
        $effectiveUrl = trim($response->effectiveUrl) !== '' ? $response->effectiveUrl : $requestedUrl;

        return $this->normalizeAbsoluteUrl($effectiveUrl);
    }

    private function parseXml(string $xml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new CalDAVProviderException('The CalDAV server returned invalid XML.');
        }

        return $document;
    }

    private function firstNodeValue(DOMXPath $xpath, string $expression, ?DOMNode $contextNode = null): string
    {
        $nodes = $xpath->query($expression, $contextNode);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim((string) $nodes->item(0)?->textContent);
    }

    /**
     * @return list<string>
     */
    private function getEntryUrls(): array
    {
        $url = $this->originPolicy->getServerUrl();
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new CalDAVProviderException('The CalDAV server URL is invalid.');
        }

        $path = $parts['path'] ?? '';
        if ($path === '' || $path === '/') {
            $origin = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }

            return [$origin . '/.well-known/caldav', $origin . '/'];
        }

        return [$url];
    }

    private function resolveUrl(string $baseUrl, string $reference): string
    {
        try {
            $url = $this->originPolicy->resolveUrl($baseUrl, $reference);
        } catch (\InvalidArgumentException) {
            throw new CalDAVProviderException('Could not resolve a CalDAV URL.');
        }

        return $this->normalizeAbsoluteUrl($url);
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments) . (str_ends_with($path, '/') ? '/' : '');
    }

    private function normalizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9a-f]{6}/i', $color, $matches)) {
            return strtoupper($matches[0]);
        }

        return '';
    }
}
