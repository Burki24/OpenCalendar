<?php

declare(strict_types=1);

use IPSKalender\CalDAVOriginPolicy;
use IPSKalender\CalDAVProvider;
use IPSKalender\CalendarEventLookupProviderInterface;
use IPSKalender\CalendarEventRecurrence;
use IPSKalender\CalendarEventState;
use IPSKalender\CalendarHttpClient;
use IPSKalender\CalendarProviderError;
use IPSKalender\CalendarProviderInterface;
use IPSKalender\GoogleCalendarOriginPolicy;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\MicrosoftCalendarProvider;
use IPSKalender\MicrosoftGraphOriginPolicy;
use IPSKalender\RecurringCalendarProviderInterface;

require_once __DIR__ . '/../libs/CalendarHttpClient.php';
require_once __DIR__ . '/../libs/CalendarProviderError.php';
require_once __DIR__ . '/../libs/CalendarEventRecurrence.php';
require_once __DIR__ . '/../libs/CalendarEventState.php';
require_once __DIR__ . '/../libs/CalDAVOriginPolicy.php';
require_once __DIR__ . '/../libs/GoogleCalendarOriginPolicy.php';
require_once __DIR__ . '/../libs/MicrosoftGraphOriginPolicy.php';
require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/MicrosoftCalendarProvider.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';

function liveE2EExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function liveE2EEnv(string $name, string $default = ''): string
{
    $value = getenv($name);

    return is_string($value) ? trim($value) : $default;
}

function liveE2EBoolEnv(string $name, bool $default): bool
{
    $value = strtolower(liveE2EEnv($name));
    if ($value === '') {
        return $default;
    }

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function liveE2EWrite(string $message): void
{
    fwrite(STDOUT, '[OpenCalendar live E2E] ' . $message . PHP_EOL);
}

/**
 * @return array{provider: CalendarProviderInterface, name: string}
 */
function liveE2EProvider(): array
{
    $providerName = strtolower(liveE2EEnv('OPENCALENDAR_LIVE_PROVIDER'));
    if ($providerName === '') {
        liveE2EWrite('Skipped: OPENCALENDAR_LIVE_PROVIDER is not set.');
        exit(0);
    }
    liveE2EExpect(
        liveE2EEnv('OPENCALENDAR_LIVE_CONFIRM_WRITE') === 'YES',
        'Set OPENCALENDAR_LIVE_CONFIRM_WRITE=YES to confirm that temporary live events may be created, changed, and deleted.'
    );

    $timeout = filter_var(
        liveE2EEnv('OPENCALENDAR_LIVE_TIMEOUT', '30'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 5, 'max_range' => 120]]
    );
    liveE2EExpect($timeout !== false, 'OPENCALENDAR_LIVE_TIMEOUT must be an integer between 5 and 120.');

    if ($providerName === 'google') {
        $accessToken = liveE2EEnv('OPENCALENDAR_LIVE_ACCESS_TOKEN');
        liveE2EExpect($accessToken !== '', 'OPENCALENDAR_LIVE_ACCESS_TOKEN is required for Google Calendar.');
        $originPolicy = new GoogleCalendarOriginPolicy();
        $httpClient = new CalendarHttpClient((int) $timeout, true, '', '', $originPolicy);

        return [
            'provider' => new GoogleCalendarProvider($httpClient, $accessToken),
            'name'     => 'Google Calendar'
        ];
    }

    if ($providerName === 'microsoft') {
        $accessToken = liveE2EEnv('OPENCALENDAR_LIVE_ACCESS_TOKEN');
        liveE2EExpect($accessToken !== '', 'OPENCALENDAR_LIVE_ACCESS_TOKEN is required for Microsoft 365.');
        $originPolicy = new MicrosoftGraphOriginPolicy();
        $httpClient = new CalendarHttpClient((int) $timeout, true, '', '', $originPolicy);

        return [
            'provider' => new MicrosoftCalendarProvider($httpClient, $accessToken),
            'name'     => 'Microsoft 365'
        ];
    }

    if (in_array($providerName, ['caldav', 'apple'], true)) {
        $serverUrl = liveE2EEnv('OPENCALENDAR_LIVE_SERVER_URL');
        if ($providerName === 'apple' && $serverUrl === '') {
            $serverUrl = 'https://caldav.icloud.com';
        }
        $username = liveE2EEnv('OPENCALENDAR_LIVE_USERNAME');
        $password = liveE2EEnv('OPENCALENDAR_LIVE_PASSWORD');
        liveE2EExpect($serverUrl !== '', 'OPENCALENDAR_LIVE_SERVER_URL is required for CalDAV.');
        liveE2EExpect($username !== '', 'OPENCALENDAR_LIVE_USERNAME is required for CalDAV.');
        liveE2EExpect($password !== '', 'OPENCALENDAR_LIVE_PASSWORD is required for CalDAV.');
        $originPolicy = new CalDAVOriginPolicy($serverUrl);
        $httpClient = new CalendarHttpClient(
            (int) $timeout,
            liveE2EBoolEnv('OPENCALENDAR_LIVE_VERIFY_TLS', true),
            $username,
            $password,
            $originPolicy
        );

        return [
            'provider' => new CalDAVProvider($httpClient, $serverUrl, $originPolicy),
            'name'     => $providerName === 'apple' ? 'Apple iCloud' : 'CalDAV'
        ];
    }

    throw new InvalidArgumentException(
        'OPENCALENDAR_LIVE_PROVIDER must be google, microsoft, caldav, or apple.'
    );
}

/**
 * @return array<string, mixed>
 */
function liveE2ESelectCalendar(CalendarProviderInterface $provider): array
{
    $selector = strtolower(liveE2EEnv('OPENCALENDAR_LIVE_CALENDAR'));
    $requiredCapabilities = [
        'create',
        'update',
        'delete',
        'createRecurrence',
        'updateOccurrence',
        'deleteOccurrence',
        'updateFollowing',
        'updateSeries',
        'deleteSeries',
        'writeTransparency'
    ];

    foreach ($provider->getCalendars() as $calendar) {
        if (!is_array($calendar)) {
            continue;
        }
        if ($selector !== '') {
            $matches = false;
            foreach (['id', 'providerId', 'reference', 'url', 'name'] as $key) {
                $value = strtolower(trim((string) ($calendar[$key] ?? '')));
                if ($value !== '' && ($value === $selector || str_contains($value, $selector))) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
        }

        $capabilities = is_array($calendar['capabilities'] ?? null) ? $calendar['capabilities'] : [];
        $writable = true;
        foreach ($requiredCapabilities as $capability) {
            if (($capabilities[$capability] ?? false) !== true) {
                $writable = false;
                break;
            }
        }
        if (!$writable) {
            continue;
        }

        $reference = trim((string) ($calendar['reference'] ?? $calendar['url'] ?? ''));
        if ($reference !== '') {
            return $calendar;
        }
    }

    throw new RuntimeException(
        $selector === ''
            ? 'No writable calendar with complete recurring-write capabilities was found.'
            : 'The selected live-test calendar was not found or is not fully writable.'
    );
}

function liveE2ECalendarReference(array $calendar): string
{
    $reference = trim((string) ($calendar['reference'] ?? $calendar['url'] ?? ''));
    liveE2EExpect($reference !== '', 'The selected calendar has no provider reference.');

    return $reference;
}

function liveE2EEventReference(array $event): string
{
    $reference = trim((string) ($event['eventReference'] ?? ''));
    if ($reference === '') {
        $reference = trim((string) ($event['resourceUrl'] ?? ''));
    }
    liveE2EExpect($reference !== '', 'The live event has no writable event reference.');

    return $reference;
}

/**
 * @return array<string, mixed>
 */
function liveE2ELookupIdentity(array $event): array
{
    $identity = [];
    foreach ([
        'eventReference',
        'resourceUrl',
        'uid',
        'seriesId',
        'occurrenceId',
        'originalStart',
        'recurrenceId',
        'startTimestamp',
        'endTimestamp'
    ] as $key) {
        if (array_key_exists($key, $event) && $event[$key] !== '' && $event[$key] !== 0) {
            $identity[$key] = $event[$key];
        }
    }

    return $identity;
}

function liveE2EEventually(callable $probe, string $message, int $attempts = 12): mixed
{
    $lastException = null;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            $result = $probe();
            if ($result !== null) {
                return $result;
            }
        } catch (Throwable $exception) {
            $lastException = $exception;
        }
        if ($attempt < $attempts) {
            usleep(500_000);
        }
    }

    if ($lastException instanceof Throwable) {
        throw new RuntimeException($message . ' Last error: ' . $lastException->getMessage(), 0, $lastException);
    }

    throw new RuntimeException($message);
}

/**
 * @return array<string, mixed>
 */
function liveE2EReadback(
    CalendarProviderInterface $provider,
    string $calendarReference,
    array $identity,
    ?callable $validator = null
): array {
    liveE2EExpect(
        $provider instanceof CalendarEventLookupProviderInterface,
        'The selected provider does not expose direct event lookup.'
    );

    return liveE2EEventually(
        static function () use ($provider, $calendarReference, $identity, $validator): ?array {
            $event = $provider->getEventForEdit($calendarReference, liveE2ELookupIdentity($identity));
            if ($validator !== null && !$validator($event)) {
                return null;
            }

            return $event;
        },
        'The provider did not return the expected event state after the write.'
    );
}

function liveE2EWaitMissing(
    CalendarProviderInterface $provider,
    string $calendarReference,
    array $identity
): void {
    liveE2EExpect(
        $provider instanceof CalendarEventLookupProviderInterface,
        'The selected provider does not expose direct event lookup.'
    );

    liveE2EEventually(
        static function () use ($provider, $calendarReference, $identity): ?bool {
            try {
                $provider->getEventForEdit($calendarReference, liveE2ELookupIdentity($identity));
            } catch (Throwable $exception) {
                $error = CalendarProviderError::fromThrowable($exception);
                if (($error['type'] ?? '') === CalendarProviderError::TYPE_NOT_FOUND) {
                    return true;
                }
                throw $exception;
            }

            return null;
        },
        'The deleted event remained available at the provider.'
    );
}

/**
 * @return list<array<string, mixed>>
 */
function liveE2EVisibleEvents(array $events): array
{
    return array_values(array_filter(
        $events,
        static fn (mixed $event): bool => is_array($event)
            && !CalendarEventState::isCancelled($event['status'] ?? '')
    ));
}

/**
 * @return list<array<string, mixed>>
 */
function liveE2ESortedEvents(array $events): array
{
    usort(
        $events,
        static fn (array $left, array $right): int => ((int) ($left['startTimestamp'] ?? 0))
            <=> ((int) ($right['startTimestamp'] ?? 0))
    );

    return $events;
}

/**
 * @return list<array<string, mixed>>
 */
function liveE2EWaitUidEvents(
    CalendarProviderInterface $provider,
    string $calendarReference,
    string $uid,
    DateTimeImmutable $start,
    DateTimeImmutable $end,
    callable $validator
): array {
    return liveE2EEventually(
        static function () use ($provider, $calendarReference, $uid, $start, $end, $validator): ?array {
            $events = liveE2EVisibleEvents($provider->getEvents($calendarReference, $start, $end));
            $events = array_values(array_filter(
                $events,
                static fn (array $event): bool => hash_equals($uid, trim((string) ($event['uid'] ?? '')))
            ));
            $events = liveE2ESortedEvents($events);

            return $validator($events) ? $events : null;
        },
        'The provider did not expose the expected recurring event state.'
    );
}

/**
 * @return list<array<string, mixed>>
 */
function liveE2EWaitTaggedEvents(
    CalendarProviderInterface $provider,
    string $calendarReference,
    string $tag,
    DateTimeImmutable $start,
    DateTimeImmutable $end,
    callable $validator
): array {
    return liveE2EEventually(
        static function () use ($provider, $calendarReference, $tag, $start, $end, $validator): ?array {
            $events = liveE2EVisibleEvents($provider->getEvents($calendarReference, $start, $end));
            $events = array_values(array_filter(
                $events,
                static fn (array $event): bool => str_contains((string) ($event['summary'] ?? ''), $tag)
            ));
            $events = liveE2ESortedEvents($events);

            return $validator($events) ? $events : null;
        },
        'The provider did not expose the expected tagged event state.'
    );
}

/**
 * @return array<string, mixed>
 */
function liveE2ERecurrence(array $event, string $writeScope): array
{
    $recurrence = CalendarEventRecurrence::fromEvent($event);
    $recurrence['writeScope'] = $writeScope;

    return $recurrence;
}

function liveE2ESameOccurrence(array $left, array $right): bool
{
    $leftOccurrence = trim((string) ($left['occurrenceId'] ?? ''));
    $rightOccurrence = trim((string) ($right['occurrenceId'] ?? ''));
    if ($leftOccurrence !== '' && $rightOccurrence !== '') {
        return hash_equals($leftOccurrence, $rightOccurrence);
    }

    $leftOriginalStart = trim((string) ($left['originalStart'] ?? ''));
    $rightOriginalStart = trim((string) ($right['originalStart'] ?? ''));

    return $leftOriginalStart !== ''
        && $rightOriginalStart !== ''
        && hash_equals($leftOriginalStart, $rightOriginalStart);
}

/**
 * @return array<string, mixed>
 */
function liveE2EFindOccurrence(array $events, array $target): array
{
    foreach ($events as $event) {
        if (is_array($event) && liveE2ESameOccurrence($event, $target)) {
            return $event;
        }
    }

    throw new RuntimeException('The selected recurring occurrence could not be found after the write.');
}

function liveE2EDeleteSeries(
    CalendarProviderInterface $provider,
    string $calendarReference,
    array $event
): void {
    $recurrence = liveE2ERecurrence($event, CalendarEventRecurrence::WRITE_SCOPE_SERIES);
    liveE2EExpect(
        (bool) ($recurrence['canDeleteSeries'] ?? false),
        'The live recurring event does not permit complete-series deletion.'
    );
    liveE2EExpect(
        $provider->deleteEvent(
            $calendarReference,
            liveE2EEventReference($event),
            trim((string) ($event['etag'] ?? '')),
            trim((string) ($event['recurrenceId'] ?? '')),
            $recurrence
        ),
        'The provider rejected complete-series deletion.'
    );
}

function liveE2EAssertDefaultState(array $calendar, array $event, bool $allDay): void
{
    $expectedStatus = CalendarEventState::normalizeStatus($calendar['defaultStatus'] ?? '');
    if ($expectedStatus !== '') {
        liveE2EExpect(
            CalendarEventState::normalizeStatus($event['status'] ?? '') === $expectedStatus,
            'The provider returned an unexpected default event status.'
        );
    }

    $defaultKey = $allDay ? 'defaultAllDayTransparency' : 'defaultTransparency';
    $expectedTransparency = CalendarEventState::normalizeTransparency($calendar[$defaultKey] ?? '');
    liveE2EExpect(
        CalendarEventState::normalizeTransparency($event['transparency'] ?? '') === $expectedTransparency,
        'The provider returned an unexpected default event transparency.'
    );
}

function liveE2ETimedScenario(
    CalendarProviderInterface $provider,
    array $calendar,
    string $calendarReference,
    string $tag,
    DateTimeZone $timezone
): void {
    liveE2EWrite('Timed event: create -> direct readback -> update -> readback -> delete.');
    $start = (new DateTimeImmutable('now', $timezone))->modify('+2 days')->setTime(11, 10);
    $end = $start->modify('+45 minutes');
    $summary = $tag . ' timed';
    $created = $provider->createEvent($calendarReference, [
        'summary'     => $summary,
        'description' => 'OpenCalendar live E2E – äöü ÄÖÜ ß',
        'location'    => 'OpenCalendar E2E',
        'start'       => $start->format(DATE_RFC3339),
        'end'         => $end->format(DATE_RFC3339),
        'allDay'      => false,
        'timezone'    => $timezone->getName()
    ]);
    $current = liveE2EReadback(
        $provider,
        $calendarReference,
        $created,
        static fn (array $event): bool => ($event['summary'] ?? '') === $summary
    );
    liveE2EExpect(
        ($current['description'] ?? '') === 'OpenCalendar live E2E – äöü ÄÖÜ ß',
        'UTF-8 event text did not survive the live provider round-trip.'
    );
    liveE2EAssertDefaultState($calendar, $current, false);

    $updatedSummary = $tag . ' timed updated';
    $changes = [
        'summary'      => $updatedSummary,
        'location'     => 'OpenCalendar E2E updated',
        'transparency' => CalendarEventState::TRANSP_TRANSPARENT
    ];
    $capabilities = is_array($calendar['capabilities'] ?? null) ? $calendar['capabilities'] : [];
    if (($capabilities['writeStatus'] ?? false) === true) {
        $changes['status'] = CalendarEventState::STATUS_TENTATIVE;
    }
    $updated = $provider->updateEvent(
        $calendarReference,
        liveE2EEventReference($current),
        trim((string) ($current['etag'] ?? '')),
        trim((string) ($current['uid'] ?? '')),
        $changes,
        CalendarEventRecurrence::single()
    );
    $current = liveE2EReadback(
        $provider,
        $calendarReference,
        array_merge($current, $updated),
        static fn (array $event): bool => ($event['summary'] ?? '') === $updatedSummary
    );
    liveE2EExpect(
        CalendarEventState::normalizeTransparency($current['transparency'] ?? '')
            === CalendarEventState::TRANSP_TRANSPARENT,
        'Timed event transparency was not updated provider-neutrally.'
    );
    if (($capabilities['writeStatus'] ?? false) === true) {
        liveE2EExpect(
            CalendarEventState::normalizeStatus($current['status'] ?? '') === CalendarEventState::STATUS_TENTATIVE,
            'Writable provider status did not round-trip as TENTATIVE.'
        );
    }

    liveE2EExpect(
        $provider->deleteEvent(
            $calendarReference,
            liveE2EEventReference($current),
            trim((string) ($current['etag'] ?? '')),
            '',
            CalendarEventRecurrence::single()
        ),
        'Timed event deletion failed.'
    );
    liveE2EWaitMissing($provider, $calendarReference, $current);
}

function liveE2EAllDayScenario(
    CalendarProviderInterface $provider,
    array $calendar,
    string $calendarReference,
    string $tag,
    DateTimeZone $timezone
): void {
    liveE2EWrite('All-day event: create -> default-state readback -> delete.');
    $start = (new DateTimeImmutable('today', $timezone))->modify('+4 days');
    $end = $start->modify('+1 day');
    $summary = $tag . ' all-day';
    $created = $provider->createEvent($calendarReference, [
        'summary'  => $summary,
        'start'    => $start->format('Y-m-d'),
        'end'      => $end->format('Y-m-d'),
        'allDay'   => true,
        'timezone' => $timezone->getName()
    ]);
    $current = liveE2EReadback(
        $provider,
        $calendarReference,
        $created,
        static fn (array $event): bool => ($event['summary'] ?? '') === $summary
            && ($event['allDay'] ?? false) === true
    );
    liveE2EAssertDefaultState($calendar, $current, true);

    liveE2EExpect(
        $provider->deleteEvent(
            $calendarReference,
            liveE2EEventReference($current),
            trim((string) ($current['etag'] ?? '')),
            '',
            CalendarEventRecurrence::single()
        ),
        'All-day event deletion failed.'
    );
    liveE2EWaitMissing($provider, $calendarReference, $current);
}

function liveE2EOccurrenceScenario(
    CalendarProviderInterface $provider,
    string $calendarReference,
    string $tag,
    DateTimeZone $timezone
): void {
    liveE2EWrite('Recurring occurrence: create series -> update one -> delete one -> delete complete series.');
    $start = (new DateTimeImmutable('now', $timezone))->modify('+6 days')->setTime(10, 20);
    $end = $start->modify('+40 minutes');
    $rangeStart = $start->modify('-1 day');
    $rangeEnd = $start->modify('+8 days');
    $summary = $tag . ' occurrence';
    $created = $provider->createEvent($calendarReference, [
        'summary'    => $summary,
        'start'      => $start->format(DATE_RFC3339),
        'end'        => $end->format(DATE_RFC3339),
        'allDay'     => false,
        'timezone'   => $timezone->getName(),
        'recurrence' => [
            'frequency' => 'DAILY',
            'interval'  => 1,
            'endMode'   => 'count',
            'count'     => 4
        ]
    ]);
    $uid = trim((string) ($created['uid'] ?? ''));
    liveE2EExpect($uid !== '', 'The provider did not return a UID for the recurring event.');
    $events = liveE2EWaitUidEvents(
        $provider,
        $calendarReference,
        $uid,
        $rangeStart,
        $rangeEnd,
        static fn (array $items): bool => count($items) === 4
    );
    $target = $events[1];
    $recurrence = liveE2ERecurrence($target, CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE);
    liveE2EExpect(
        (bool) ($recurrence['canUpdateOccurrence'] ?? false)
            && (bool) ($recurrence['canDeleteOccurrence'] ?? false),
        'The provider did not expose writable occurrence metadata.'
    );

    $updatedSummary = $tag . ' occurrence updated';
    $provider->updateEvent(
        $calendarReference,
        liveE2EEventReference($target),
        trim((string) ($target['etag'] ?? '')),
        trim((string) ($target['uid'] ?? '')),
        ['summary' => $updatedSummary],
        $recurrence
    );
    $events = liveE2EWaitUidEvents(
        $provider,
        $calendarReference,
        $uid,
        $rangeStart,
        $rangeEnd,
        static function (array $items) use ($target, $updatedSummary): bool {
            foreach ($items as $item) {
                if (liveE2ESameOccurrence($item, $target) && ($item['summary'] ?? '') === $updatedSummary) {
                    return true;
                }
            }

            return false;
        }
    );
    $target = liveE2EFindOccurrence($events, $target);
    $recurrence = liveE2ERecurrence($target, CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE);
    liveE2EExpect(
        $provider->deleteEvent(
            $calendarReference,
            liveE2EEventReference($target),
            trim((string) ($target['etag'] ?? '')),
            trim((string) ($target['recurrenceId'] ?? '')),
            $recurrence
        ),
        'Recurring occurrence deletion failed.'
    );
    $events = liveE2EWaitUidEvents(
        $provider,
        $calendarReference,
        $uid,
        $rangeStart,
        $rangeEnd,
        static fn (array $items): bool => count($items) === 3
    );
    liveE2EDeleteSeries($provider, $calendarReference, $events[0]);
    liveE2EWaitUidEvents(
        $provider,
        $calendarReference,
        $uid,
        $rangeStart,
        $rangeEnd,
        static fn (array $items): bool => $items === []
    );
}

function liveE2ESeriesScenario(
    CalendarProviderInterface $provider,
    string $calendarReference,
    string $tag,
    DateTimeZone $timezone
): void {
    liveE2EWrite('Complete series: create -> provider-neutral master lookup -> update series -> delete series.');
    liveE2EExpect(
        $provider instanceof RecurringCalendarProviderInterface,
        'The selected provider does not expose recurring-series lookup.'
    );
    $start = (new DateTimeImmutable('now', $timezone))->modify('+12 days')->setTime(9, 30);
    $end = $start->modify('+30 minutes');
    $rangeStart = $start->modify('-1 day');
    $rangeEnd = $start->modify('+8 days');
    $summary = $tag . ' series';
    $created = $provider->createEvent($calendarReference, [
        'summary'    => $summary,
        'start'      => $start->format(DATE_RFC3339),
        'end'        => $end->format(DATE_RFC3339),
        'allDay'     => false,
        'timezone'   => $timezone->getName(),
        'recurrence' => [
            'frequency' => 'DAILY',
            'interval'  => 1,
            'endMode'   => 'count',
            'count'     => 4
        ]
    ]);
    $uid = trim((string) ($created['uid'] ?? ''));
    $events = liveE2EWaitUidEvents(
        $provider,
        $calendarReference,
        $uid,
        $rangeStart,
        $rangeEnd,
        static fn (array $items): bool => count($items) === 4
    );
    $seriesId = trim((string) ($events[1]['seriesId'] ?? ''));
    liveE2EExpect($seriesId !== '', 'The provider did not expose a recurring series ID.');
    $master = $provider->getRecurringSeries(
        $calendarReference,
        $seriesId,
        trim((string) ($events[1]['resourceUrl'] ?? ''))
    );
    $recurrence = liveE2ERecurrence($master, CalendarEventRecurrence::WRITE_SCOPE_SERIES);
    liveE2EExpect(
        (bool) ($recurrence['canUpdateSeries'] ?? false)
            && (bool) ($recurrence['canDeleteSeries'] ?? false),
        'The provider did not expose writable complete-series metadata.'
    );
    $updatedSummary = $tag . ' series updated';
    $provider->updateEvent(
        $calendarReference,
        liveE2EEventReference($master),
        trim((string) ($master['etag'] ?? '')),
        trim((string) ($master['uid'] ?? '')),
        ['summary' => $updatedSummary],
        $recurrence
    );
    $events = liveE2EWaitUidEvents(
        $provider,
        $calendarReference,
        $uid,
        $rangeStart,
        $rangeEnd,
        static fn (array $items): bool => count($items) === 4
            && count(array_filter(
                $items,
                static fn (array $item): bool => ($item['summary'] ?? '') === $updatedSummary
            )) === 4
    );
    liveE2EDeleteSeries($provider, $calendarReference, $events[0]);
    liveE2EWaitUidEvents(
        $provider,
        $calendarReference,
        $uid,
        $rangeStart,
        $rangeEnd,
        static fn (array $items): bool => $items === []
    );
}

function liveE2EFollowingScenario(
    CalendarProviderInterface $provider,
    string $calendarReference,
    string $tag,
    DateTimeZone $timezone
): void {
    liveE2EWrite('This and following: create series -> verified following lookup -> split/update tail.');
    liveE2EExpect(
        $provider instanceof RecurringCalendarProviderInterface,
        'The selected provider does not expose recurring following lookup.'
    );
    $start = (new DateTimeImmutable('now', $timezone))->modify('+18 days')->setTime(14, 15);
    $end = $start->modify('+30 minutes');
    $rangeStart = $start->modify('-1 day');
    $rangeEnd = $start->modify('+8 days');
    $summary = $tag . ' following';
    $provider->createEvent($calendarReference, [
        'summary'    => $summary,
        'start'      => $start->format(DATE_RFC3339),
        'end'        => $end->format(DATE_RFC3339),
        'allDay'     => false,
        'timezone'   => $timezone->getName(),
        'recurrence' => [
            'frequency' => 'DAILY',
            'interval'  => 1,
            'endMode'   => 'count',
            'count'     => 4
        ]
    ]);
    $events = liveE2EWaitTaggedEvents(
        $provider,
        $calendarReference,
        $summary,
        $rangeStart,
        $rangeEnd,
        static fn (array $items): bool => count($items) === 4
    );
    $target = $events[2];
    $seriesId = trim((string) ($target['seriesId'] ?? ''));
    $occurrenceId = trim((string) ($target['occurrenceId'] ?? ''));
    $originalStart = trim((string) ($target['originalStart'] ?? ''));
    liveE2EExpect(
        $seriesId !== '' && $occurrenceId !== '' && $originalStart !== '',
        'The provider did not expose the identity required for this-and-following writes.'
    );
    $following = $provider->getRecurringFollowing(
        $calendarReference,
        $seriesId,
        $occurrenceId,
        $originalStart,
        trim((string) ($target['resourceUrl'] ?? ''))
    );
    $recurrence = liveE2ERecurrence($following, CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING);
    liveE2EExpect(
        (bool) ($recurrence['canUpdateFollowing'] ?? false),
        'The provider did not expose this-and-following update capability.'
    );
    $tailSummary = $tag . ' following tail';
    $provider->updateEvent(
        $calendarReference,
        liveE2EEventReference($following),
        trim((string) ($following['etag'] ?? '')),
        trim((string) ($following['uid'] ?? '')),
        [
            'summary'  => $tailSummary,
            'start'    => (string) ($following['start'] ?? ''),
            'end'      => (string) ($following['end'] ?? ''),
            'allDay'   => (bool) ($following['allDay'] ?? false),
            'timezone' => (string) ($following['timezone'] ?? $timezone->getName())
        ],
        $recurrence
    );
    liveE2EWaitTaggedEvents(
        $provider,
        $calendarReference,
        $tag . ' following',
        $rangeStart,
        $rangeEnd,
        static function (array $items) use ($summary, $tailSummary): bool {
            if (count($items) !== 4) {
                return false;
            }

            return ($items[0]['summary'] ?? '') === $summary
                && ($items[1]['summary'] ?? '') === $summary
                && ($items[2]['summary'] ?? '') === $tailSummary
                && ($items[3]['summary'] ?? '') === $tailSummary;
        }
    );
}

function liveE2ECleanupTaggedEvents(
    CalendarProviderInterface $provider,
    string $calendarReference,
    string $tag,
    DateTimeImmutable $start,
    DateTimeImmutable $end
): void {
    try {
        $events = $provider->getEvents($calendarReference, $start, $end);
    } catch (Throwable $exception) {
        fwrite(STDERR, '[OpenCalendar live E2E] Cleanup query failed: ' . $exception->getMessage() . PHP_EOL);
        return;
    }

    $series = [];
    $singles = [];
    foreach ($events as $event) {
        if (!is_array($event) || !str_contains((string) ($event['summary'] ?? ''), $tag)) {
            continue;
        }
        $recurrence = CalendarEventRecurrence::fromEvent($event);
        $seriesId = trim((string) ($recurrence['seriesId'] ?? ''));
        if ($seriesId !== '' && (bool) ($recurrence['canDeleteSeries'] ?? false)) {
            $series[$seriesId] = $event;
        } elseif (($recurrence['recurrenceType'] ?? '') === CalendarEventRecurrence::SINGLE) {
            $singles[] = $event;
        }
    }

    foreach ($series as $event) {
        try {
            liveE2EDeleteSeries($provider, $calendarReference, $event);
        } catch (Throwable $exception) {
            fwrite(STDERR, '[OpenCalendar live E2E] Series cleanup failed: ' . $exception->getMessage() . PHP_EOL);
        }
    }
    foreach ($singles as $event) {
        try {
            $provider->deleteEvent(
                $calendarReference,
                liveE2EEventReference($event),
                trim((string) ($event['etag'] ?? '')),
                '',
                CalendarEventRecurrence::single()
            );
        } catch (Throwable $exception) {
            fwrite(STDERR, '[OpenCalendar live E2E] Event cleanup failed: ' . $exception->getMessage() . PHP_EOL);
        }
    }
}

try {
    $providerConfiguration = liveE2EProvider();
    $provider = $providerConfiguration['provider'];
    liveE2EExpect(
        $provider instanceof CalendarEventLookupProviderInterface
            && $provider instanceof RecurringCalendarProviderInterface,
        'The live E2E suite requires lookup and recurring-provider capabilities.'
    );
    $connection = $provider->testConnection();
    liveE2EExpect(($connection['success'] ?? false) === true, 'The live provider connection test failed.');

    $calendar = liveE2ESelectCalendar($provider);
    $calendarReference = liveE2ECalendarReference($calendar);
    $calendarName = trim((string) ($calendar['name'] ?? ''));
    $timezoneName = liveE2EEnv('OPENCALENDAR_LIVE_TIMEZONE', 'Europe/Berlin');
    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Throwable $exception) {
        throw new InvalidArgumentException('OPENCALENDAR_LIVE_TIMEZONE is invalid.', 0, $exception);
    }

    $tag = 'OpenCalendar E2E ' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $cleanupStart = (new DateTimeImmutable('now', $timezone))->modify('-1 day');
    $cleanupEnd = $cleanupStart->modify('+40 days');
    liveE2EWrite(
        sprintf(
            'Provider: %s; calendar: %s; tag: %s',
            $providerConfiguration['name'],
            $calendarName !== '' ? $calendarName : '(unnamed)',
            $tag
        )
    );

    try {
        liveE2ETimedScenario($provider, $calendar, $calendarReference, $tag, $timezone);
        liveE2EAllDayScenario($provider, $calendar, $calendarReference, $tag, $timezone);
        liveE2EOccurrenceScenario($provider, $calendarReference, $tag, $timezone);
        liveE2ESeriesScenario($provider, $calendarReference, $tag, $timezone);
        liveE2EFollowingScenario($provider, $calendarReference, $tag, $timezone);
    } finally {
        liveE2ECleanupTaggedEvents($provider, $calendarReference, $tag, $cleanupStart, $cleanupEnd);
    }

    liveE2EWrite('All live provider end-to-end scenarios passed.');
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        '[OpenCalendar live E2E] FAILED: ' . $exception::class . ': ' . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}
