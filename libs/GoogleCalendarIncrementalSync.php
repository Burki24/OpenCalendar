<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

require_once __DIR__ . '/CalendarHttpClient.php';
require_once __DIR__ . '/GoogleCalendarProvider.php';

/**
 * Retrieves Google Calendar changes using sync tokens while keeping the existing
 * GoogleCalendarProvider responsible for normalizing complete event objects.
 */
final class GoogleCalendarIncrementalSync
{
    private const API_URL = 'https://www.googleapis.com/calendar/v3';
    private const MAX_PAGES = 100;
    private const MAX_CHANGES = 100_000;

    /**
     * Creates an incremental Google Calendar synchronizer.
     */
    public function __construct(
        private readonly GoogleCalendarProvider $provider,
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly string $accessToken
    ) {
        if (trim($accessToken) === '') {
            throw new GoogleCalendarProviderException('Google Calendar is not connected yet.', 401);
        }
    }

    /**
     * Synchronizes one bounded event collection.
     *
     * @return array{items:list<array<string, mixed>>,syncToken:string,incremental:bool}
     */
    public function synchronize(
        string $calendarReference,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $syncToken = ''
    ): array {
        if ($end <= $start) {
            throw new InvalidArgumentException('The event query end must be later than the start.');
        }

        $syncToken = trim($syncToken);
        if ($syncToken !== '') {
            try {
                return $this->incrementalSync($calendarReference, $syncToken);
            } catch (GoogleCalendarProviderException $exception) {
                if ($exception->httpStatus !== 410) {
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
        // Acquire the token before loading the full event set. Changes made after
        // this point are either already present in the full result or replayed by
        // the next incremental request, so no update can fall into a gap.
        $syncToken = $this->requestInitialSyncToken($calendarReference, $start, $end);
        $events = $this->provider->getEvents($calendarReference, $start, $end);

        return [
            'items'       => $events,
            'syncToken'   => $syncToken,
            'incremental' => false
        ];
    }

    /**
     * @return array{items:list<array<string, mixed>>,syncToken:string,incremental:bool}
     */
    private function incrementalSync(string $calendarReference, string $syncToken): array
    {
        $calendarId = $this->calendarId($calendarReference);
        $changes = [];
        $pageToken = '';
        $pageCount = 0;
        $seenPageTokens = [];
        $seenEventIds = [];
        $nextSyncToken = '';

        do {
            ++$pageCount;
            $query = [
                'maxResults'   => '2500',
                'singleEvents' => 'true',
                'syncToken'    => $syncToken
            ];
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            $data = $this->requestJson(
                '/calendars/' . rawurlencode($calendarId) . '/events?' . http_build_query($query)
            );
            foreach (($data['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $eventId = trim((string) ($item['id'] ?? ''));
                if ($eventId === '' || isset($seenEventIds[$eventId])) {
                    continue;
                }
                $seenEventIds[$eventId] = true;

                if (strtolower(trim((string) ($item['status'] ?? ''))) === 'cancelled') {
                    $changes[] = $this->deletionMarker($item);
                } else {
                    try {
                        $changes[] = $this->provider->getEventForEdit($calendarReference, $eventId);
                    } catch (GoogleCalendarProviderException $exception) {
                        if ($exception->httpStatus !== 404) {
                            throw $exception;
                        }
                        $changes[] = $this->deletionMarker($item);
                    }
                }

                if (count($changes) > self::MAX_CHANGES) {
                    throw new GoogleCalendarProviderException('Google Calendar returned too many event changes.');
                }
            }

            $pageToken = $this->validatedNextPageToken($data, $seenPageTokens, $pageCount);
            if ($pageToken === '') {
                $nextSyncToken = trim((string) ($data['nextSyncToken'] ?? ''));
            }
        } while ($pageToken !== '');

        if ($nextSyncToken === '') {
            throw new GoogleCalendarProviderException('Google Calendar did not return a synchronization token.');
        }

        return [
            'items'       => $changes,
            'syncToken'   => $nextSyncToken,
            'incremental' => true
        ];
    }

    private function requestInitialSyncToken(
        string $calendarReference,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): string {
        $calendarId = $this->calendarId($calendarReference);
        $pageToken = '';
        $pageCount = 0;
        $seenPageTokens = [];
        $nextSyncToken = '';

        do {
            ++$pageCount;
            $query = [
                'timeMin'      => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'timeMax'      => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'singleEvents' => 'true',
                'maxResults'   => '2500'
            ];
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            $data = $this->requestJson(
                '/calendars/' . rawurlencode($calendarId) . '/events?' . http_build_query($query)
            );
            $pageToken = $this->validatedNextPageToken($data, $seenPageTokens, $pageCount);
            if ($pageToken === '') {
                $nextSyncToken = trim((string) ($data['nextSyncToken'] ?? ''));
            }
        } while ($pageToken !== '');

        if ($nextSyncToken === '') {
            throw new GoogleCalendarProviderException('Google Calendar did not return a synchronization token.');
        }

        return $nextSyncToken;
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
            'seriesId'       => trim((string) ($item['recurringEventId'] ?? ''))
        ];
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

    /** @return array<string, mixed> */
    private function requestJson(string $path): array
    {
        $response = $this->httpClient->request(
            'GET',
            self::API_URL . $path,
            [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->accessToken
            ]
        );
        if ($response->statusCode !== 200) {
            $this->throwApiError($response);
        }

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new GoogleCalendarProviderException(
                'Google Calendar returned invalid JSON.',
                $response->statusCode
            );
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
        } elseif ($response->statusCode === 410) {
            $message = 'Google Calendar synchronization token expired.';
        } elseif ($message === '') {
            $message = sprintf('Google Calendar request failed with HTTP %d.', $response->statusCode);
        }

        throw new GoogleCalendarProviderException($message, $response->statusCode, $reason);
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
}
