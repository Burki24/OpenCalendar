<?php

declare(strict_types=1);

use IPSKalender\CalendarEventLookupProviderInterface;
use IPSKalender\CalendarEventRecurrence;
use IPSKalender\ICalendarRecurrence;
use IPSKalender\RecurringCalendarProviderInterface;

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
     * @return array{Token:string,PageCount:int,ItemCount:int,ExpiresAt:int}
     */
    private function beginEventsTransferForChild(array $request): array
    {
        return $this->CreateChunkedJsonTransfer(
            self::EVENT_TRANSFER_SCOPE,
            $this->getEventsForChild($request)
        );
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
                && $this->eventMatchesEditRequest($event, $request)
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
    private function eventMatchesEditRequest(array $event, array $request): bool
    {
        $primaryIdentity = [
            'OccurrenceID'   => 'occurrenceId',
            'EventReference' => 'eventReference',
            'ResourceURL'    => 'resourceUrl',
            'UID'            => 'uid'
        ];
        $matchedPrimary = false;
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
            $matchedPrimary = true;
            break;
        }
        if (!$matchedPrimary) {
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
