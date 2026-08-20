<?php

declare(strict_types=1);

use IPSKalender\CalDAVIncrementalSync;
use IPSKalender\CalDAVOriginPolicy;
use IPSKalender\CalDAVProvider;
use IPSKalender\CalendarEventLookupProviderInterface;
use IPSKalender\CalendarEventRecurrence;
use IPSKalender\CalendarHttpClient;
use IPSKalender\GoogleCalendarIncrementalSync;
use IPSKalender\GoogleCalendarOriginPolicy;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\ICalendarRecurrence;
use IPSKalender\MicrosoftCalendarIncrementalSync;
use IPSKalender\MicrosoftGraphOriginPolicy;
use IPSKalender\RecurringCalendarProviderInterface;

require_once __DIR__ . '/../../libs/CalDAVIncrementalSync.php';
require_once __DIR__ . '/../../libs/GoogleCalendarIncrementalSync.php';
require_once __DIR__ . '/../../libs/MicrosoftCalendarIncrementalSync.php';

trait KalenderKontoChildGatewayTrait
{
    /**
     * Processes a request received from a child calendar or configurator instance.
     *
     * @param string $JSONString JSON-encoded gateway request.
     * @return string JSON-encoded gateway response.
     */
    public function ForwardData(string $JSONString): string
    {
        $startedAt = microtime(true);

        try {
            $request = $this->DecodeDataFlowMessage($JSONString, self::DATA_ID_FROM_CHILD);

            $operation = (string) ($request['Operation'] ?? '');
            $requestID = (string) ($request['RequestID'] ?? '');
            $debugRequest = $operation !== 'ReadEventsTransferPage';
            if ($debugRequest) {
                $this->SendSafeDebug('ChildRequest', [
                    'operation'         => $operation,
                    'provider'          => $this->getProviderName($this->ReadPropertyInteger('Provider')),
                    'calendarSpecified' => trim((string) ($request['CalendarID'] ?? '')) !== '',
                    'requestFields'     => array_values(array_keys($request))
                ]);
            }

            $payload = match ($operation) {
                'GetCalendars'           => json_decode($this->GetCalendars(), true, 512, JSON_THROW_ON_ERROR),
                'DiscoverCalendars'      => $this->discoverCalendars(),
                'GetEvents'              => $this->getEventsForChild($request),
                'BeginEventsTransfer'    => $this->beginEventsTransferForChild($request),
                'ReadEventsTransferPage' => $this->readEventsTransferPageForChild($request),
                'FinishEventsTransfer'   => [
                    'success' => $this->finishEventsTransferForChild($request)
                ],
                'GetEventForEdit'        => $this->getEventForEditForChild($request),
                'GetEventAfterWrite'     => $this->getEventAfterWriteForChild($request),
                'CheckRecurringSeries'   => $this->checkRecurringSeriesForChild($request),
                'GetRecurringSeries'     => $this->getRecurringSeriesForChild($request),
                'GetRecurringFollowing'  => $this->getRecurringFollowingForChild($request),
                'CreateEvent'            => $this->createEventForChild($request),
                'UpdateEvent'            => $this->updateEventForChild($request),
                'DeleteEvent'            => ['success' => $this->deleteEventForChild($request)],
                'Synchronize'            => ['success' => $this->Synchronize()],
                'TestConnection'         => json_decode($this->TestConnection(), true, 512, JSON_THROW_ON_ERROR),
                default                  => throw new InvalidArgumentException('Unsupported operation: ' . $operation)
            };

            if ($debugRequest) {
                $this->SendSafeDebug('ChildRequestCompleted', [
                    'operation'  => $operation,
                    'durationMs' => (int) round((microtime(true) - $startedAt) * 1000)
                ]);
            }

            return $this->encodeResponse(true, $operation, $requestID, $payload);
        } catch (Throwable $exception) {
            $this->SendSafeDebug('ChildRequestError', [
                'operation' => isset($operation) ? $operation : '',
                'type'      => $exception::class,
                'message'   => $this->sanitizeError($exception->getMessage()),
                'code'      => $exception->getCode()
            ]);

            return $this->encodeResponse(
                false,
                isset($operation) ? $operation : '',
                isset($requestID) ? $requestID : '',
                null,
                $exception instanceof JsonException
                    ? $this->Translate('Invalid JSON data.')
                    : $this->translateErrorMessage($exception->getMessage())
            );
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return list<array<string, mixed>>
     */
    private function getEventsForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $startTimestamp = (int) ($request['Start'] ?? 0);
        $endTimestamp = (int) ($request['End'] ?? 0);
        if ($startTimestamp <= 0 || $endTimestamp <= $startTimestamp) {
            throw new InvalidArgumentException('The requested event time range is invalid.');
        }
        if (($endTimestamp - $startTimestamp) > 6 * 366 * 86400) {
            throw new InvalidArgumentException('The requested event time range is too large.');
        }

        $startedAt = microtime(true);
        $events = $this->createProvider()->getEvents(
            $this->calendarReference($calendar),
            new DateTimeImmutable('@' . $startTimestamp),
            new DateTimeImmutable('@' . $endTimestamp)
        );
        $this->SendSafeDebug('ProviderEvents', [
            'provider'   => $this->getProviderName($this->ReadPropertyInteger('Provider')),
            'start'      => (new DateTimeImmutable('@' . $startTimestamp))->format(DATE_ATOM),
            'end'        => (new DateTimeImmutable('@' . $endTimestamp))->format(DATE_ATOM),
            'eventCount' => count($events),
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000)
        ]);
        if ($this->ReadPropertyInteger('Provider') === self::PROVIDER_ICS) {
            $recurrenceDiagnostics = ICalendarRecurrence::diagnostics($events);
            if ($recurrenceDiagnostics['seriesCount'] > 0) {
                $this->SendSafeDebug('RecurrenceExpansion', $recurrenceDiagnostics);
            }
        }

        return $events;
    }

    /**
     * Creates a temporary, paged transfer for the requested calendar events.
     *
     * @param array<string, mixed> $request
     * @return array{Token:string,PageCount:int,ItemCount:int,ExpiresAt:int,SyncToken?:string,Incremental?:bool}
     */
    private function beginEventsTransferForChild(array $request): array
    {
        $providerType = $this->ReadPropertyInteger('Provider');
        if (!in_array(
            $providerType,
            [self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_GOOGLE, self::PROVIDER_MICROSOFT],
            true
        )) {
            return $this->CreateChunkedJsonTransfer(
                self::EVENT_TRANSFER_SCOPE,
                $this->getEventsForChild($request)
            );
        }

        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $startTimestamp = (int) ($request['Start'] ?? 0);
        $endTimestamp = (int) ($request['End'] ?? 0);
        if ($startTimestamp <= 0 || $endTimestamp <= $startTimestamp) {
            throw new InvalidArgumentException('The requested event time range is invalid.');
        }
        if (($endTimestamp - $startTimestamp) > 6 * 366 * 86400) {
            throw new InvalidArgumentException('The requested event time range is too large.');
        }

        $startedAt = microtime(true);
        $calendarReference = $this->calendarReference($calendar);
        $start = new DateTimeImmutable('@' . $startTimestamp);
        $end = new DateTimeImmutable('@' . $endTimestamp);
        $syncToken = trim((string) ($request['SyncToken'] ?? ''));
        if ($providerType === self::PROVIDER_GOOGLE) {
            $accessToken = $this->getGoogleAccessToken();
            $httpClient = $this->createTrustedCloudHttpClient(new GoogleCalendarOriginPolicy());
            $provider = new GoogleCalendarProvider($httpClient, $accessToken);
            $synchronizer = new GoogleCalendarIncrementalSync($provider, $httpClient, $accessToken);
            $debugName = 'GoogleEventSynchronization';
        } elseif ($providerType === self::PROVIDER_MICROSOFT) {
            $accessToken = $this->getMicrosoftAccessToken();
            $httpClient = $this->createTrustedCloudHttpClient(new MicrosoftGraphOriginPolicy());
            $synchronizer = new MicrosoftCalendarIncrementalSync($httpClient, $accessToken);
            $debugName = 'MicrosoftEventSynchronization';
        } else {
            $serverUrl = $providerType === self::PROVIDER_APPLE
                ? self::APPLE_CALDAV_URL
                : trim($this->ReadPropertyString('ServerURL'));
            $originPolicy = new CalDAVOriginPolicy($serverUrl);
            $httpClient = new CalendarHttpClient(
                max(5, min(120, $this->ReadPropertyInteger('RequestTimeout'))),
                $providerType === self::PROVIDER_APPLE ? true : $this->ReadPropertyBoolean('VerifyTLS'),
                trim($this->ReadPropertyString('Username')),
                $this->ReadPropertyString('Password'),
                $originPolicy
            );
            $provider = new CalDAVProvider($httpClient, $serverUrl, $originPolicy);
            $synchronizer = new CalDAVIncrementalSync($provider, $httpClient, $originPolicy);
            $debugName = 'CalDAVEventSynchronization';
        }
        $result = $synchronizer->synchronize($calendarReference, $start, $end, $syncToken);

        $transfer = $this->CreateChunkedJsonTransfer(self::EVENT_TRANSFER_SCOPE, $result['items']);
        $transfer['SyncToken'] = $result['syncToken'];
        $transfer['Incremental'] = $result['incremental'];
        $this->SendSafeDebug($debugName, [
            'incremental' => $result['incremental'],
            'itemCount'   => count($result['items']),
            'durationMs'  => (int) round((microtime(true) - $startedAt) * 1000)
        ]);

        return $transfer;
    }

    /**
     * Reads one page from a previously created event transfer.
     *
     * @param array<string, mixed> $request
     * @return array{Token:string,Page:int,PageCount:int,ItemCount:int,Complete:bool,Items:list<mixed>}
     */
    private function readEventsTransferPageForChild(array $request): array
    {
        return $this->ReadChunkedJsonTransferPage(
            self::EVENT_TRANSFER_SCOPE,
            (string) ($request['Token'] ?? ''),
            (int) ($request['Page'] ?? -1)
        );
    }

    /**
     * Removes a completed or aborted event transfer.
     *
     * @param array<string, mixed> $request
     */
    private function finishEventsTransferForChild(array $request): bool
    {
        return $this->ClearChunkedJsonTransfer(
            self::EVENT_TRANSFER_SCOPE,
            (string) ($request['Token'] ?? '')
        );
    }

    /**
     * Returns one freshly written event by its provider reference when direct lookup is available.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function getEventAfterWriteForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $eventReference = trim((string) ($request['EventReference'] ?? ''));
        if ($eventReference === '') {
            throw new InvalidArgumentException('The selected event reference is missing.');
        }

        if ($this->ReadPropertyInteger('Provider') === self::PROVIDER_MICROSOFT) {
            $synchronizer = new MicrosoftCalendarIncrementalSync(
                $this->createTrustedCloudHttpClient(new MicrosoftGraphOriginPolicy()),
                $this->getMicrosoftAccessToken()
            );

            return $synchronizer->getEventByReference(
                $this->calendarReference($calendar),
                $eventReference
            );
        }

        $provider = $this->createProvider();
        if ($provider instanceof CalendarEventLookupProviderInterface) {
            return $provider->getEventForEdit(
                $this->calendarReference($calendar),
                $eventReference
            );
        }

        throw new RuntimeException('Direct event lookup is not supported by this calendar provider.');
    }

    /**
     * Returns the current provider version of one event before it is edited.
     *
     * Providers with a direct lookup capability are queried by their stable event
     * reference. Other providers are queried in a narrow time window and the
     * normalized result is matched against the event identity supplied by the child.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function getEventForEditForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $provider = $this->createProvider();
        $eventReference = trim((string) ($request['EventReference'] ?? ''));
        if ($eventReference !== '' && $provider instanceof CalendarEventLookupProviderInterface) {
            return $provider->getEventForEdit(
                $this->calendarReference($calendar),
                $eventReference
            );
        }

        $startTimestamp = (int) ($request['Start'] ?? 0);
        $endTimestamp = (int) ($request['End'] ?? 0);
        if ($startTimestamp <= 0) {
            throw new InvalidArgumentException('The selected event start is invalid.');
        }
        if ($endTimestamp <= $startTimestamp) {
            $endTimestamp = $startTimestamp + 1;
        }

        $rangeStart = max(1, $startTimestamp - 86400);
        $rangeEnd = $endTimestamp + 86400;
        if (($rangeEnd - $rangeStart) > 6 * 366 * 86400) {
            throw new InvalidArgumentException('The selected event time range is too large.');
        }

        $matches = array_values(array_filter(
            $provider->getEvents(
                $this->calendarReference($calendar),
                new DateTimeImmutable('@' . $rangeStart),
                new DateTimeImmutable('@' . $rangeEnd)
            ),
            fn (mixed $event): bool => is_array($event)
                && $this->eventMatchesEditRequest(
                    $event,
                    $request,
                    $this->ReadPropertyInteger('Provider') === self::PROVIDER_MICROSOFT
                )
        ));

        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches === []) {
            throw new RuntimeException(
                'The selected event is no longer available. Synchronize the calendar and try again.'
            );
        }

        throw new RuntimeException('The selected event could not be identified uniquely.');
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $request
     */
    private function eventMatchesEditRequest(
        array $event,
        array $request,
        bool $stableMicrosoftIdentity = false
    ): bool {
        if ($stableMicrosoftIdentity) {
            return $this->microsoftEventMatchesEditRequest($event, $request);
        }

        $primaryIdentity = [
            'OccurrenceID'   => 'occurrenceId',
            'EventReference' => 'eventReference',
            'ResourceURL'    => 'resourceUrl',
            'UID'            => 'uid'
        ];
        $matchedPrimaryKey = '';
        foreach ($primaryIdentity as $requestKey => $eventKey) {
            $expected = trim((string) ($request[$requestKey] ?? ''));
            if ($expected === '') {
                continue;
            }
            $actual = trim((string) ($event[$eventKey] ?? ''));
            if ($actual === '') {
                continue;
            }
            if (!hash_equals($expected, $actual)) {
                return false;
            }
            $matchedPrimaryKey = $eventKey;
            break;
        }
        if ($matchedPrimaryKey === '') {
            return false;
        }

        foreach ([
            'OriginalStart' => 'originalStart',
            'RecurrenceID'  => 'recurrenceId'
        ] as $requestKey => $eventKey) {
            $expected = trim((string) ($request[$requestKey] ?? ''));
            $actual = trim((string) ($event[$eventKey] ?? ''));
            if ($expected !== '' && $actual !== '' && !hash_equals($expected, $actual)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Matches a Microsoft event without treating a stale Graph event ID as authoritative.
     *
     * Microsoft exposes immutable IDs when requested, but existing cached occurrences can
     * still carry an older identity after a series change. The per-occurrence iCalUId is a
     * stable secondary identity. Recurring events additionally fall back to their series ID
     * and original occurrence start so a refreshed calendarView result can still be edited.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $request
     */
    private function microsoftEventMatchesEditRequest(array $event, array $request): bool
    {
        foreach ([
            'OccurrenceID'   => 'occurrenceId',
            'EventReference' => 'eventReference',
            'ResourceURL'    => 'resourceUrl',
            'UID'            => 'uid'
        ] as $requestKey => $eventKey) {
            $expected = trim((string) ($request[$requestKey] ?? ''));
            $actual = trim((string) ($event[$eventKey] ?? ''));
            if ($expected !== '' && $actual !== '' && hash_equals($expected, $actual)) {
                return true;
            }
        }

        $expectedSeriesId = trim((string) ($request['SeriesID'] ?? ''));
        $actualSeriesId = trim((string) ($event['seriesId'] ?? ''));
        $expectedOriginalStart = trim((string) ($request['OriginalStart'] ?? ''));
        $actualOriginalStart = trim((string) ($event['originalStart'] ?? ''));

        return $expectedSeriesId !== ''
            && $actualSeriesId !== ''
            && hash_equals($expectedSeriesId, $actualSeriesId)
            && $this->microsoftOriginalStartMatches(
                $expectedOriginalStart,
                $actualOriginalStart,
                (bool) ($event['allDay'] ?? false)
            );
    }

    private function microsoftOriginalStartMatches(string $left, string $right, bool $allDay): bool
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '' || $right === '') {
            return false;
        }
        if (hash_equals($left, $right)) {
            return true;
        }
        if ($allDay
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $left) === 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $right) === 1) {
            return substr($left, 0, 10) === substr($right, 0, 10);
        }

        try {
            return (new DateTimeImmutable($left))->getTimestamp()
                === (new DateTimeImmutable($right))->getTimestamp();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Verifies whether a recurring parent event still exists without turning a
     * provider-side deletion into an error response for the child calendar.
     *
     * @param array<string, mixed> $request
     * @return array{supported: bool, exists: bool}
     */
    private function checkRecurringSeriesForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $provider = $this->createProvider();
        if (!$provider instanceof RecurringCalendarProviderInterface) {
            return ['supported' => false, 'exists' => false];
        }

        $seriesId = trim((string) ($request['SeriesID'] ?? ''));
        if ($seriesId === '') {
            throw new InvalidArgumentException('The recurring series ID is missing.');
        }

        try {
            $provider->getRecurringSeries(
                $this->calendarReference($calendar),
                $seriesId,
                trim((string) ($request['ResourceURL'] ?? ''))
            );
        } catch (Throwable $exception) {
            $httpStatus = property_exists($exception, 'httpStatus')
                ? (int) $exception->httpStatus
                : 0;
            if (in_array($httpStatus, [404, 410], true)) {
                return ['supported' => true, 'exists' => false];
            }

            throw $exception;
        }

        return ['supported' => true, 'exists' => true];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function getRecurringSeriesForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $provider = $this->createProvider();
        if (!$provider instanceof RecurringCalendarProviderInterface) {
            throw new InvalidArgumentException('Recurring series are not supported by this calendar provider.');
        }

        return $provider->getRecurringSeries(
            $this->calendarReference($calendar),
            trim((string) ($request['SeriesID'] ?? '')),
            trim((string) ($request['ResourceURL'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function getRecurringFollowingForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $provider = $this->createProvider();
        if (!$provider instanceof RecurringCalendarProviderInterface) {
            throw new InvalidArgumentException('Recurring series are not supported by this calendar provider.');
        }

        return $provider->getRecurringFollowing(
            $this->calendarReference($calendar),
            trim((string) ($request['SeriesID'] ?? '')),
            trim((string) ($request['OccurrenceID'] ?? '')),
            trim((string) ($request['OriginalStart'] ?? '')),
            trim((string) ($request['ResourceURL'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function createEventForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $event = $request['Event'] ?? null;
        if (!is_array($event)) {
            throw new InvalidArgumentException('The event data is invalid.');
        }

        return $this->createProvider()->createEvent($this->calendarReference($calendar), $event);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function updateEventForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $event = $request['Event'] ?? null;
        if (!is_array($event)) {
            throw new InvalidArgumentException('The event data is invalid.');
        }

        return $this->createProvider()->updateEvent(
            $this->calendarReference($calendar),
            trim((string) ($request['ResourceURL'] ?? '')),
            trim((string) ($request['ETag'] ?? '')),
            trim((string) ($request['UID'] ?? '')),
            $event,
            CalendarEventRecurrence::fromEvent(
                is_array($request['Recurrence'] ?? null) ? $request['Recurrence'] : []
            )
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function deleteEventForChild(array $request): bool
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));

        return $this->createProvider()->deleteEvent(
            $this->calendarReference($calendar),
            trim((string) ($request['ResourceURL'] ?? '')),
            trim((string) ($request['ETag'] ?? '')),
            trim((string) ($request['RecurrenceID'] ?? '')),
            CalendarEventRecurrence::fromEvent(
                is_array($request['Recurrence'] ?? null) ? $request['Recurrence'] : []
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCalendar(string $calendarId): array
    {
        $calendars = json_decode($this->ReadAttributeString('CachedCalendars'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($calendars)) {
            $calendars = [];
        }
        if ($calendarId !== '') {
            foreach ($calendars as $calendar) {
                if (is_array($calendar) && (string) ($calendar['id'] ?? '') === $calendarId
                    && $this->calendarReference($calendar) !== '') {
                    return $calendar;
                }
            }
        }
        $fallback = $this->singleCalendarFallback($calendars);
        if ($fallback !== null) {
            return $fallback;
        }

        $calendars = $this->discoverCalendars();
        if ($calendarId !== '') {
            foreach ($calendars as $calendar) {
                if ((string) ($calendar['id'] ?? '') === $calendarId
                    && $this->calendarReference($calendar) !== '') {
                    return $calendar;
                }
            }
        }
        $fallback = $this->singleCalendarFallback($calendars);
        if ($fallback !== null) {
            return $fallback;
        }

        if ($calendarId === '') {
            throw new InvalidArgumentException('The calendar ID is missing.');
        }

        throw new RuntimeException('The selected calendar is no longer available in this account.');
    }

    /**
     * A single-feed ICS/Webcal account always exposes exactly one calendar.
     * Keep an existing child usable when its gateway or the feed URL changes
     * and its URL-derived calendar ID is missing or no longer matches.
     *
     * @param array<mixed> $calendars
     * @return array<string, mixed>|null
     */
    private function singleCalendarFallback(array $calendars): ?array
    {
        if ($this->ReadPropertyInteger('Provider') !== self::PROVIDER_ICS) {
            return null;
        }

        $available = array_values(array_filter(
            $calendars,
            fn (mixed $calendar): bool => is_array($calendar) && $this->calendarReference($calendar) !== ''
        ));
        if (count($available) !== 1) {
            return null;
        }

        $this->SendSafeDebug(
            'CalendarResolution',
            'Using the only calendar exposed by the ICS/Webcal account because the stored calendar ID is missing or no longer matches.'
        );

        return $available[0];
    }

    /**
     * @param array<string, mixed> $calendar
     */
    private function calendarReference(array $calendar): string
    {
        return trim((string) ($calendar['reference'] ?? $calendar['url'] ?? ''));
    }

    private function encodeResponse(
        bool $success,
        string $operation,
        string $requestID,
        mixed $payload = null,
        string $error = ''
    ): string {
        return json_encode(
            [
                'Success'   => $success,
                'Operation' => $operation,
                'RequestID' => $requestID,
                'Payload'   => $payload,
                'Error'     => $error
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
