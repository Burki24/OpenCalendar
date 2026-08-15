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
require_once __DIR__ . '/CalendarHttpClient.php';
require_once __DIR__ . '/CalendarEventRecurrence.php';
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

final class MicrosoftCalendarProvider implements CalendarProviderInterface
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
                        'read'             => true,
                        'create'           => $canWrite,
                        'update'           => $canWrite,
                        'delete'           => $canWrite,
                        'createRecurrence' => $canWrite
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
    public function createEvent(string $calendarReference, array $event): array
    {
        $calendarId = $this->calendarId($calendarReference);
        $created = $this->requestJson(
            'POST',
            '/me/calendars/' . rawurlencode($calendarId) . '/events',
            $this->buildEventPayload($event, true),
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
        $this->assertWritableEventTarget($recurrence, $eventId, true);
        if (array_key_exists('description', $event)) {
            $this->assertDescriptionEditable($calendarId, $eventId);
        }
        $headers = $etag !== '' ? ['If-Match' => $etag] : [];
        $updated = $this->requestJson(
            'PATCH',
            '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            $this->buildEventPayload($event, false),
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
        $this->assertWritableEventTarget($recurrence, $eventId, false);
        $headers = $etag !== '' ? ['If-Match' => $etag] : [];
        $this->requestJson(
            'DELETE',
            '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            null,
            $headers,
            [204]
        );

        return true;
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
        $recurrenceIdentity = match ($type) {
            'seriesmaster'            => CalendarEventRecurrence::master($eventId),
            'occurrence', 'exception' => CalendarEventRecurrence::occurrence(
                $seriesMasterId,
                $eventId,
                $originalStart,
                '',
                $seriesMasterId !== '' && $originalStart !== '',
                $type === 'exception'
            ),
            default => CalendarEventRecurrence::single()
        };
        $resourceUrl = $this->eventUrl($calendarId, $eventId);

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
            'status'         => (bool) ($item['isCancelled'] ?? false) ? 'CANCELLED' : 'CONFIRMED',
            'recurrenceRule' => '',
            'sequence'       => 0,
            'created'        => trim((string) ($item['createdDateTime'] ?? '')),
            'lastModified'   => trim((string) ($item['lastModifiedDateTime'] ?? '')),
            'url'            => trim((string) ($item['webLink'] ?? '')),
            'onlineMeeting'  => (bool) ($item['isOnlineMeeting'] ?? false)
        ], $recurrenceIdentity);
    }

    /** @param array<string, mixed> $recurrence */
    private function assertWritableEventTarget(array $recurrence, string $eventId, bool $updating): void
    {
        if ($recurrence === []) {
            return;
        }

        $identity = CalendarEventRecurrence::fromEvent($recurrence);
        $type = (string) ($identity['recurrenceType'] ?? CalendarEventRecurrence::SINGLE);
        if ($type === CalendarEventRecurrence::SINGLE) {
            return;
        }

        $capability = $updating ? 'canUpdateOccurrence' : 'canDeleteOccurrence';
        $occurrenceId = trim((string) ($identity['occurrenceId'] ?? ''));
        if (!in_array($type, [CalendarEventRecurrence::OCCURRENCE, CalendarEventRecurrence::EXCEPTION], true)
            || ($identity['writeScope'] ?? '') !== CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE
            || trim((string) ($identity['seriesId'] ?? '')) === ''
            || trim((string) ($identity['originalStart'] ?? '')) === ''
            || $occurrenceId === ''
            || !hash_equals($eventId, $occurrenceId)
            || !(bool) ($identity[$capability] ?? false)) {
            throw new MicrosoftCalendarProviderException(
                'Only individual Microsoft recurring occurrences can be modified at this time.'
            );
        }
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
    private function buildEventPayload(array $data, bool $creating): array
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

        $recurrence = $data['recurrence'] ?? null;
        $recurring = $recurrence !== null && $recurrence !== [];
        if ($recurring && (!$creating || !is_array($recurrence) || array_is_list($recurrence))) {
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
            $recurrenceTimezone = trim((string) ($data['timezone'] ?? ''));
            if ($recurring && $recurrenceTimezone === '') {
                $recurrenceTimezone = date_default_timezone_get();
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
            } elseif ($recurring) {
                $recurrenceZone = $this->recurrenceTimezone($recurrenceTimezone);
                $recurrenceStart = $start->setTimezone($recurrenceZone);
                $recurrenceEnd = $end->setTimezone($recurrenceZone);
                $payload['start'] = [
                    'dateTime' => $recurrenceStart->format('Y-m-d\TH:i:s'),
                    'timeZone' => $recurrenceTimezone
                ];
                $payload['end'] = [
                    'dateTime' => $recurrenceEnd->format('Y-m-d\TH:i:s'),
                    'timeZone' => $recurrenceTimezone
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
            throw new InvalidArgumentException('Recurring event creation requires a start and end.');
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
