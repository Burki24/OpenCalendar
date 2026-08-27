<?php

declare(strict_types=1);

namespace IPSKalender;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

require_once __DIR__ . '/CalendarEventRecurrence.php';
require_once __DIR__ . '/CalendarEventReminder.php';
require_once __DIR__ . '/CalendarHttpClient.php';
require_once __DIR__ . '/MicrosoftCalendarProvider.php';

/**
 * Retrieves Microsoft calendar-view changes using opaque Graph delta links.
 */
final class MicrosoftCalendarIncrementalSync
{
    private const API_URL = 'https://graph.microsoft.com/v1.0';
    private const MAX_CHANGES = 100_000;
    private const MAX_PAGES = 100;

    /**
     * Creates an incremental Microsoft Calendar synchronizer.
     */
    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly string $accessToken
    ) {
        if (trim($accessToken) === '') {
            throw new MicrosoftCalendarProviderException('Microsoft 365 is not connected yet.', 401);
        }
    }

    /**
     * Reads one Microsoft event by its provider reference using immutable Graph IDs.
     *
     * @return array<string, mixed>
     */
    public function getEventByReference(string $calendarReference, string $eventReference): array
    {
        $calendarId = $this->calendarId($calendarReference);
        $eventReference = trim($eventReference);
        if ($eventReference === '') {
            throw new InvalidArgumentException('The Microsoft event reference is missing.');
        }

        $event = $this->mapEvent(
            $calendarId,
            $this->requestJsonUrl(
                self::API_URL . '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventReference)
            )
        );
        if ($event === null) {
            throw new MicrosoftCalendarProviderException('Microsoft Calendar did not return complete event data.');
        }

        return $event;
    }

    /**
     * Synchronizes one bounded Microsoft calendar view.
     *
     * @return array{items:list<array<string, mixed>>,syncToken:string,incremental:bool}
     */
    public function synchronize(
        string $calendarReference,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $deltaLink = ''
    ): array {
        if ($end <= $start) {
            throw new InvalidArgumentException('The event query end must be later than the start.');
        }

        $deltaLink = trim($deltaLink);
        if ($deltaLink !== '') {
            try {
                return $this->incrementalSync($calendarReference, $deltaLink);
            } catch (MicrosoftCalendarProviderException $exception) {
                if (!$this->requiresFullResynchronization($exception)) {
                    throw $exception;
                }
            }
        }

        return $this->fullSync($calendarReference, $start, $end);
    }

    /**
     * @return array{items:list<array<string, mixed>>,syncToken:string,incremental:bool}
     */
    private function fullSync(
        string $calendarReference,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        $calendarId = $this->calendarId($calendarReference);
        $query = http_build_query(
            [
                'startDateTime' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'endDateTime'   => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $round = $this->readDeltaRound(
            self::API_URL . '/me/calendars/' . rawurlencode($calendarId) . '/calendarView/delta?' . $query,
            $calendarId,
            false
        );

        // Use the proven calendarView endpoint for the authoritative full snapshot.
        // The delta round above is only used to establish a baseline token. Microsoft Graph
        // can transiently report only a series master while establishing that baseline,
        // whereas calendarView reliably expands the concrete occurrences in the same window.
        $provider = new MicrosoftCalendarProvider($this->httpClient, $this->accessToken);
        $events = $provider->getEvents($calendarReference, $start, $end);

        return [
            'items'       => $events,
            'syncToken'   => $round['deltaLink'],
            'incremental' => false
        ];
    }

    /**
     * @return array{items:list<array<string, mixed>>,syncToken:string,incremental:bool}
     */
    private function incrementalSync(string $calendarReference, string $deltaLink): array
    {
        $calendarId = $this->calendarId($calendarReference);
        $round = $this->readDeltaRound($deltaLink, $calendarId, true);

        return [
            'items'       => $round['items'],
            'syncToken'   => $round['deltaLink'],
            'incremental' => true
        ];
    }

    /**
     * @return array{items:list<array<string, mixed>>,deltaLink:string}
     */
    private function readDeltaRound(string $initialUrl, string $calendarId, bool $incremental): array
    {
        $url = $this->validatedGraphStateUrl($initialUrl);
        $pageCount = 0;
        $seenUrls = [];
        $changes = [];

        while (true) {
            if (++$pageCount > self::MAX_PAGES) {
                throw new MicrosoftCalendarProviderException(
                    'Microsoft Calendar delta pagination exceeded the safe page limit.'
                );
            }
            if (isset($seenUrls[$url])) {
                throw new MicrosoftCalendarProviderException(
                    'Microsoft Calendar returned a repeated delta pagination link.'
                );
            }
            $seenUrls[$url] = true;

            $data = $this->requestJsonUrl($url);
            foreach (is_array($data['value'] ?? null) ? $data['value'] : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $eventId = trim((string) ($item['id'] ?? ''));
                if ($eventId === '') {
                    continue;
                }

                $removed = is_array($item['@removed'] ?? null) || (bool) ($item['isCancelled'] ?? false);
                $type = strtolower(trim((string) ($item['type'] ?? '')));
                if (!$removed && $type === 'seriesmaster') {
                    if ($incremental) {
                        // A series-master change can replace multiple occurrence IDs. Refresh the bounded
                        // calendar view so no stale or partial instances survive in the local cache.
                        throw new MicrosoftCalendarProviderException(
                            'Microsoft recurring series changed and requires a full calendar-view synchronization.',
                            0,
                            'resyncRequired'
                        );
                    }

                    continue;
                }

                $changes[$eventId] = $this->deltaItem($calendarId, $item, $incremental);
                if (count($changes) > self::MAX_CHANGES) {
                    throw new MicrosoftCalendarProviderException(
                        'Microsoft Calendar returned too many event changes.'
                    );
                }
            }

            $nextLink = trim((string) ($data['@odata.nextLink'] ?? ''));
            $deltaLink = trim((string) ($data['@odata.deltaLink'] ?? ''));
            if ($nextLink !== '' && $deltaLink !== '') {
                throw new MicrosoftCalendarProviderException(
                    'Microsoft Calendar returned conflicting delta pagination links.'
                );
            }
            if ($nextLink !== '') {
                $url = $this->validatedGraphStateUrl($nextLink);
                continue;
            }
            if ($deltaLink === '') {
                throw new MicrosoftCalendarProviderException(
                    'Microsoft Calendar did not return a delta synchronization link.'
                );
            }

            return [
                'items'     => array_values($changes),
                'deltaLink' => $this->validatedGraphStateUrl($deltaLink)
            ];
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function deltaItem(string $calendarId, array $item, bool $refreshCurrent): array
    {
        $eventId = trim((string) ($item['id'] ?? ''));
        if (is_array($item['@removed'] ?? null) || (bool) ($item['isCancelled'] ?? false)) {
            return $this->deletionMarker($item);
        }

        if (!$refreshCurrent && $this->isCompleteCalendarViewItem($item)) {
            $mapped = $this->mapEvent($calendarId, $item);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        try {
            $current = $this->requestJsonUrl(
                self::API_URL . '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId)
            );
        } catch (MicrosoftCalendarProviderException $exception) {
            if ($exception->httpStatus === 404) {
                return $this->deletionMarker($item);
            }
            throw $exception;
        }

        $mapped = $this->mapEvent($calendarId, $current);
        if ($mapped === null) {
            throw new MicrosoftCalendarProviderException(
                'Microsoft Calendar did not return complete data for a changed event.'
            );
        }

        return $mapped;
    }

    /** @param array<string, mixed> $item */
    private function isCompleteCalendarViewItem(array $item): bool
    {
        foreach (['subject', 'type', 'isAllDay', 'start', 'end'] as $key) {
            if (!array_key_exists($key, $item)) {
                return false;
            }
        }
        if (!is_array($item['start']) || !is_array($item['end'])) {
            return false;
        }

        $type = strtolower(trim((string) $item['type']));
        if (in_array($type, ['occurrence', 'exception'], true)
            && trim((string) ($item['seriesMasterId'] ?? '')) === '') {
            return false;
        }
        if ($type === 'exception' && trim((string) ($item['originalStart'] ?? '')) === '') {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{_syncDeleted:bool,eventReference:string,seriesId:string}
     */
    private function deletionMarker(array $item): array
    {
        return [
            '_syncDeleted'   => true,
            'eventReference' => trim((string) ($item['id'] ?? '')),
            'seriesId'       => trim((string) ($item['seriesMasterId'] ?? ''))
        ];
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

        return array_merge([
            'id'             => hash('sha256', 'microsoft|' . $calendarId . '|' . $eventId),
            'uid'            => trim((string) ($item['iCalUId'] ?? $eventId)),
            'eventReference' => $eventId,
            'resourceUrl'    => $this->eventUrl($calendarId, $eventId),
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
            'onlineMeeting'  => (bool) ($item['isOnlineMeeting'] ?? false),
            'reminder'       => $this->mapReminder($item)
        ], $recurrenceIdentity);
    }

    /**
     * @param array<string, mixed> $item
     * @return array{mode:string,minutesBeforeStart:int|null,editable:bool}
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

    /** @return array<string, mixed> */
    private function requestJsonUrl(string $url): array
    {
        $url = $this->validatedGraphStateUrl($url);
        $response = $this->httpClient->request(
            'GET',
            $url,
            [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Prefer'        => 'odata.maxpagesize=1000, outlook.body-content-type="text", outlook.timezone="UTC", IdType="ImmutableId"'
            ]
        );
        if ($response->statusCode !== 200) {
            $this->throwApiError($response);
        }

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new MicrosoftCalendarProviderException(
                'Microsoft Calendar returned invalid JSON.',
                $response->statusCode
            );
        }
        if (!is_array($data)) {
            throw new MicrosoftCalendarProviderException(
                'Microsoft Calendar returned invalid data.',
                $response->statusCode
            );
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
        } elseif ($message === '') {
            $message = sprintf('Microsoft Calendar request failed with HTTP %d.', $response->statusCode);
        }

        throw new MicrosoftCalendarProviderException($message, $response->statusCode, $errorCode);
    }

    private function requiresFullResynchronization(MicrosoftCalendarProviderException $exception): bool
    {
        if (in_array($exception->httpStatus, [404, 410], true)) {
            return true;
        }

        return in_array(strtolower($exception->errorCode), [
            'errorinvalidsyncstatedata',
            'resyncchangesapplydifferences',
            'resyncchangesuploaddifferences',
            'resyncrequired',
            'syncstatenotfound'
        ], true);
    }

    private function validatedGraphStateUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new MicrosoftCalendarProviderException('The Microsoft delta synchronization link is missing.');
        }
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($scheme !== 'https' || $host !== 'graph.microsoft.com') {
            throw new MicrosoftCalendarProviderException('The Microsoft delta synchronization link is invalid.');
        }

        return $url;
    }

    private function calendarId(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new MicrosoftCalendarProviderException('The Microsoft calendar ID is missing.');
        }
        if (filter_var($reference, FILTER_VALIDATE_URL) !== false) {
            $path = (string) (parse_url($reference, PHP_URL_PATH) ?? '');
            if (preg_match('~/calendars/([^/]+)$~', $path, $matches) === 1) {
                return rawurldecode($matches[1]);
            }
            throw new MicrosoftCalendarProviderException('The Microsoft calendar reference is invalid.');
        }

        return $reference;
    }

    private function eventUrl(string $calendarId, string $eventId): string
    {
        return self::API_URL . '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId);
    }
}
