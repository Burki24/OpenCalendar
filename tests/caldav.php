<?php

declare(strict_types=1);

use IPSKalender\CalDAVOriginPolicy;
use IPSKalender\CalDAVProvider;
use IPSKalender\CalDAVProviderException;
use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;

require_once __DIR__ . '/../libs/CalDAVProvider.php';

if (!class_exists(DOMDocument::class)) {
    throw new RuntimeException('The CalDAV provider test requires the PHP DOM extension.');
}

final class FakeCalDAVHttpClient implements CalendarHttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string, maxResponseBytes: int}> */
    public array $requests = [];

    /** @var list<CalendarHttpResponse|Throwable> */
    private array $responses;

    /** @param list<CalendarHttpResponse|Throwable> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $maxResponseBytes = 67_108_864
    ): CalendarHttpResponse {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'maxResponseBytes');
        if ($this->responses === []) {
            throw new RuntimeException('No fake CalDAV response was queued.');
        }

        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

function caldavResponse(int $statusCode, string $body = '', string $effectiveUrl = 'https://calendar.example/'): CalendarHttpResponse
{
    return new CalendarHttpResponse($statusCode, [], $body, $effectiveUrl);
}

function caldavResponseWithHeaders(
    int $statusCode,
    array $headers,
    string $body,
    string $effectiveUrl
): CalendarHttpResponse {
    return new CalendarHttpResponse($statusCode, $headers, $body, $effectiveUrl);
}

function assertCalDAVSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL
            . 'Expected: ' . var_export($expected, true) . PHP_EOL
            . 'Actual:   ' . var_export($actual, true)
        );
    }
}

function assertCalDAVTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @template T of Throwable
 * @param class-string<T> $exceptionClass
 * @return T
 */
function assertCalDAVThrows(callable $callback, string $exceptionClass, string $messageContains, string $message): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if (!$exception instanceof $exceptionClass) {
            throw new RuntimeException(
                $message . PHP_EOL . 'Unexpected exception: ' . $exception::class . ': ' . $exception->getMessage(),
                0,
                $exception
            );
        }
        if ($messageContains !== '' && !str_contains($exception->getMessage(), $messageContains)) {
            throw new RuntimeException(
                $message . PHP_EOL . 'Unexpected exception message: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        return $exception;
    }

    throw new RuntimeException($message . PHP_EOL . 'No exception was thrown.');
}

function principalResponseXml(string $href): string
{
    return '<?xml version="1.0" encoding="utf-8" ?>'
        . '<d:multistatus xmlns:d="DAV:">'
        . '<d:response><d:href>/</d:href><d:propstat><d:prop>'
        . '<d:current-user-principal><d:href>' . htmlspecialchars($href, ENT_XML1) . '</d:href></d:current-user-principal>'
        . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
        . '</d:multistatus>';
}

function homeSetResponseXml(string $href): string
{
    return '<?xml version="1.0" encoding="utf-8" ?>'
        . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        . '<d:response><d:href>/</d:href><d:propstat><d:prop>'
        . '<c:calendar-home-set><d:href>' . htmlspecialchars($href, ENT_XML1) . '</d:href></c:calendar-home-set>'
        . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
        . '</d:multistatus>';
}

function calendarsResponseXml(): string
{
    return '<?xml version="1.0" encoding="utf-8" ?>'
        . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/">'
        . '<d:response><d:href>/calendars/user/tasks/</d:href><d:propstat><d:prop>'
        . '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype>'
        . '<d:displayname>Tasks</d:displayname>'
        . '<c:supported-calendar-component-set><c:comp name="VTODO"/></c:supported-calendar-component-set>'
        . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
        . '<d:response><d:href>/calendars/user/work/</d:href><d:propstat><d:prop>'
        . '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype>'
        . '<d:displayname>Work</d:displayname>'
        . '<c:calendar-description>Work calendar</c:calendar-description>'
        . '<a:calendar-color>#123456FF</a:calendar-color>'
        . '<d:getetag>"calendar-etag"</d:getetag><d:sync-token>sync-1</d:sync-token>'
        . '<c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>'
        . '<d:current-user-privilege-set>'
        . '<d:privilege><d:read/></d:privilege><d:privilege><d:write-content/></d:privilege>'
        . '</d:current-user-privilege-set>'
        . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
        . '</d:multistatus>';
}

function eventQueryResponseXml(string $href): string
{
    $ical = "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:event-1@example.com\r\n"
        . "DTSTART:20260724T100000Z\r\n"
        . "DTEND:20260724T110000Z\r\n"
        . "SUMMARY:CalDAV test\r\n"
        . "END:VEVENT\r\n"
        . "END:VCALENDAR\r\n";

    return '<?xml version="1.0" encoding="utf-8" ?>'
        . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        . '<d:response><d:href>' . htmlspecialchars($href, ENT_XML1) . '</d:href><d:propstat><d:prop>'
        . '<d:getetag>"event-etag"</d:getetag>'
        . '<c:calendar-data><![CDATA[' . $ical . ']]></c:calendar-data>'
        . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
        . '</d:multistatus>';
}

function singleEventIcal(): string
{
    return "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:event-1@example.com\r\n"
        . "DTSTART:20260724T100000Z\r\n"
        . "DTEND:20260724T110000Z\r\n"
        . "SUMMARY:Before update\r\n"
        . "END:VEVENT\r\n"
        . "END:VCALENDAR\r\n";
}

function recurringSeriesIcal(): string
{
    return "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:series-1@example.com\r\n"
        . "DTSTAMP:20260801T000000Z\r\n"
        . "SEQUENCE:1\r\n"
        . "DTSTART:20260817T100000Z\r\n"
        . "DTEND:20260817T110000Z\r\n"
        . "RRULE:FREQ=WEEKLY;COUNT=4\r\n"
        . "SUMMARY:Recurring before\r\n"
        . "END:VEVENT\r\n"
        . "END:VCALENDAR\r\n";
}

function recurringSeriesWithOverrideIcal(): string
{
    return "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:series-1@example.com\r\n"
        . "DTSTAMP:20260801T000000Z\r\n"
        . "SEQUENCE:1\r\n"
        . "DTSTART:20260817T100000Z\r\n"
        . "DTEND:20260817T110000Z\r\n"
        . "RRULE:FREQ=WEEKLY;COUNT=4\r\n"
        . "SUMMARY:Recurring before\r\n"
        . "END:VEVENT\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:series-1@example.com\r\n"
        . "RECURRENCE-ID:20260824T100000Z\r\n"
        . "DTSTART:20260824T120000Z\r\n"
        . "DTEND:20260824T130000Z\r\n"
        . "SUMMARY:Detached override\r\n"
        . "END:VEVENT\r\n"
        . "END:VCALENDAR\r\n";
}

function recurringSeriesLookupResponseXml(string $href): string
{
    return '<?xml version="1.0" encoding="utf-8" ?>'
        . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        . '<d:response><d:href>' . htmlspecialchars($href, ENT_XML1) . '</d:href><d:propstat><d:prop>'
        . '<d:getetag>"series-etag"</d:getetag>'
        . '<c:calendar-data><![CDATA[' . recurringSeriesIcal() . ']]></c:calendar-data>'
        . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
        . '</d:multistatus>';
}

function recurringEventQueryResponseXml(string $href, bool $includeSecondOccurrence = true): string
{
    $ical = "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:series-1@example.com\r\n"
        . "DTSTART:20260817T100000Z\r\n"
        . "DTEND:20260817T110000Z\r\n"
        . "SUMMARY:Recurring before\r\n"
        . "END:VEVENT\r\n";
    if ($includeSecondOccurrence) {
        $ical .= "BEGIN:VEVENT\r\n"
            . "UID:series-1@example.com\r\n"
            . "RECURRENCE-ID:20260824T100000Z\r\n"
            . "DTSTART:20260824T100000Z\r\n"
            . "DTEND:20260824T110000Z\r\n"
            . "SUMMARY:Recurring before\r\n"
            . "END:VEVENT\r\n";
    }
    $ical .= "END:VCALENDAR\r\n";

    return '<?xml version="1.0" encoding="utf-8" ?>'
        . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        . '<d:response><d:href>' . htmlspecialchars($href, ENT_XML1) . '</d:href><d:propstat><d:prop>'
        . '<d:getetag>"series-etag"</d:getetag>'
        . '<c:calendar-data><![CDATA[' . $ical . ']]></c:calendar-data>'
        . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
        . '</d:multistatus>';
}

// Origin policy: strict same-origin handling for regular CalDAV servers.
$originPolicy = new CalDAVOriginPolicy('https://calendar.example/dav/');
assertCalDAVTrue($originPolicy->isAllowedUrl('https://calendar.example/dav/'), 'The configured CalDAV origin must be trusted.');
assertCalDAVTrue($originPolicy->isAllowedUrl('https://calendar.example:443/other/'), 'The explicit default HTTPS port must remain the same origin.');
assertCalDAVTrue(!$originPolicy->isAllowedUrl('http://calendar.example/dav/'), 'A scheme downgrade must be blocked.');
assertCalDAVTrue(!$originPolicy->isAllowedUrl('https://calendar.example:8443/dav/'), 'A port change must be blocked.');
assertCalDAVTrue(!$originPolicy->isAllowedUrl('https://other.example/dav/'), 'A host change must be blocked.');
assertCalDAVTrue(!$originPolicy->isAllowedUrl('https://user:secret@calendar.example/dav/'), 'Credentials embedded in URLs must be blocked.');
assertCalDAVTrue(!$originPolicy->isAllowedUrl('https://calendar.example/dav/#fragment'), 'URL fragments must be blocked.');
assertCalDAVSame(
    'https://calendar.example/calendars/user/',
    $originPolicy->resolveUrl('https://calendar.example/principals/user/', '../../calendars/user/'),
    'Relative DAV href values must be normalized safely.'
);
assertCalDAVSame(
    'https://calendar.example/root/path',
    $originPolicy->resolveUrl('https://calendar.example/dav/user/', '/root/./folder/../path'),
    'Absolute-path DAV href values must normalize dot segments.'
);

// Apple redirects between caldav.icloud.com and pNN-caldav.icloud.com shards.
$iCloudPolicy = new CalDAVOriginPolicy('https://caldav.icloud.com/');
assertCalDAVTrue($iCloudPolicy->isAllowedUrl('https://p12-caldav.icloud.com/123/calendars/'), 'Known iCloud CalDAV shards must be trusted.');
assertCalDAVTrue($iCloudPolicy->isAllowedUrl('https://caldav.icloud.com/'), 'The canonical iCloud CalDAV host must remain trusted.');
assertCalDAVTrue(!$iCloudPolicy->isAllowedUrl('https://evil-caldav.icloud.com/'), 'Arbitrary iCloud-looking host names must not be trusted.');
assertCalDAVTrue(!$iCloudPolicy->isAllowedUrl('https://p12-caldav.icloud.com:8443/'), 'iCloud shards must only be trusted on HTTPS port 443.');
assertCalDAVTrue(!$iCloudPolicy->isAllowedUrl('http://p12-caldav.icloud.com/'), 'iCloud shards must never be trusted over plain HTTP.');

// Complete discovery through .well-known/caldav.
$discoveryClient = new FakeCalDAVHttpClient([
    caldavResponse(207, principalResponseXml('/principals/user/'), 'https://calendar.example/.well-known/caldav'),
    caldavResponse(207, homeSetResponseXml('/calendars/user/'), 'https://calendar.example/principals/user/'),
    caldavResponse(207, calendarsResponseXml(), 'https://calendar.example/calendars/user/')
]);
$provider = new CalDAVProvider($discoveryClient, 'https://calendar.example');
$calendars = $provider->getCalendars();
assertCalDAVSame(1, count($calendars), 'Calendar discovery must ignore collections that do not support VEVENT.');
assertCalDAVSame('Work', $calendars[0]['name'], 'The CalDAV display name must be returned.');
assertCalDAVSame('#123456', $calendars[0]['color'], 'Apple eight-digit calendar colors must be normalized.');
assertCalDAVSame(true, $calendars[0]['capabilities']['create'], 'Write privileges must enable event creation.');
assertCalDAVSame(true, $calendars[0]['capabilities']['createRecurrence'], 'Writable CalDAV calendars must advertise recurring event creation.');
assertCalDAVSame(true, $calendars[0]['capabilities']['updateOccurrence'], 'Writable CalDAV calendars must advertise recurring occurrence updates.');
assertCalDAVSame(true, $calendars[0]['capabilities']['deleteOccurrence'], 'Writable CalDAV calendars must advertise recurring occurrence deletion.');
assertCalDAVSame(true, $calendars[0]['capabilities']['updateSeries'], 'Writable CalDAV calendars must advertise recurring series updates.');
assertCalDAVSame(true, $calendars[0]['capabilities']['deleteSeries'], 'Writable CalDAV calendars must advertise recurring series deletion.');
assertCalDAVSame(true, $calendars[0]['writeAccessKnown'], 'Returned privileges must mark write access as known.');

$calendarXmlWithoutPrivileges = preg_replace(
    '/<d:current-user-privilege-set>.*?<\/d:current-user-privilege-set>/',
    '',
    calendarsResponseXml()
);
assertCalDAVTrue(is_string($calendarXmlWithoutPrivileges), 'The privilege-free CalDAV fixture must be generated.');
$unknownPrivilegeClient = new FakeCalDAVHttpClient([
    caldavResponse(207, principalResponseXml('/principals/user/'), 'https://calendar.example/.well-known/caldav'),
    caldavResponse(207, homeSetResponseXml('/calendars/user/'), 'https://calendar.example/principals/user/'),
    caldavResponse(207, $calendarXmlWithoutPrivileges, 'https://calendar.example/calendars/user/')
]);
$unknownPrivilegeCalendars = (new CalDAVProvider($unknownPrivilegeClient, 'https://calendar.example'))->getCalendars();
assertCalDAVSame(false, $unknownPrivilegeCalendars[0]['writeAccessKnown'], 'Missing DAV privileges must remain distinguishable from explicit read-only access.');
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['create'], 'Missing DAV privileges must not disable event creation optimistically.');
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['createRecurrence'], 'Unknown DAV privileges must not hide recurring creation optimistically.');
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['updateOccurrence'], 'Unknown DAV privileges must not hide recurring occurrence updates optimistically.');
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['deleteOccurrence'], 'Unknown DAV privileges must not hide recurring occurrence deletion optimistically.');
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['updateSeries'], 'Unknown DAV privileges must not hide recurring series updates optimistically.');
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['deleteSeries'], 'Unknown DAV privileges must not hide recurring series deletion optimistically.');

assertCalDAVSame('https://calendar.example/.well-known/caldav', $discoveryClient->requests[0]['url'], 'Root server URLs must start discovery at .well-known/caldav.');
assertCalDAVSame('https://calendar.example/principals/user/', $discoveryClient->requests[1]['url'], 'The discovered principal must be queried.');
assertCalDAVSame('https://calendar.example/calendars/user/', $discoveryClient->requests[2]['url'], 'The discovered calendar home set must be queried.');
assertCalDAVSame('0', $discoveryClient->requests[0]['headers']['Depth'] ?? '', 'Principal discovery must use Depth 0.');
assertCalDAVSame('1', $discoveryClient->requests[2]['headers']['Depth'] ?? '', 'Calendar discovery must use Depth 1.');

// If .well-known is unavailable, the provider must fall back to the configured origin root.
$fallbackClient = new FakeCalDAVHttpClient([
    caldavResponse(404, '', 'https://calendar.example/.well-known/caldav'),
    caldavResponse(207, principalResponseXml('/principals/user/'), 'https://calendar.example/'),
    caldavResponse(207, homeSetResponseXml('/calendars/user/'), 'https://calendar.example/principals/user/'),
    caldavResponse(207, calendarsResponseXml(), 'https://calendar.example/calendars/user/')
]);
$provider = new CalDAVProvider($fallbackClient, 'https://calendar.example');
assertCalDAVSame(1, count($provider->getCalendars()), 'Discovery must fall back to the origin root when .well-known/caldav is unavailable.');
assertCalDAVSame('https://calendar.example/', $fallbackClient->requests[1]['url'], 'The second discovery attempt must use the origin root.');

// A DAV href must never move authenticated requests to another origin.
$foreignPrincipalClient = new FakeCalDAVHttpClient([
    caldavResponse(207, principalResponseXml('https://attacker.example/principals/user/'), 'https://calendar.example/dav/')
]);
$provider = new CalDAVProvider($foreignPrincipalClient, 'https://calendar.example/dav/');
assertCalDAVThrows(
    static fn () => $provider->getCalendars(),
    CalDAVProviderException::class,
    'untrusted origin',
    'An absolute current-user-principal on another origin must be rejected.'
);
assertCalDAVSame(1, count($foreignPrincipalClient->requests), 'No request may be sent to a foreign principal URL.');

// The effective response URL is security-sensitive as well.
$foreignEffectiveClient = new FakeCalDAVHttpClient([
    caldavResponse(207, principalResponseXml('/principals/user/'), 'https://attacker.example/dav/')
]);
$provider = new CalDAVProvider($foreignEffectiveClient, 'https://calendar.example/dav/');
assertCalDAVThrows(
    static fn () => $provider->getCalendars(),
    CalDAVProviderException::class,
    'untrusted origin',
    'A foreign effective URL returned by the HTTP layer must be rejected.'
);
assertCalDAVSame(1, count($foreignEffectiveClient->requests), 'A foreign effective URL must stop discovery immediately.');

// Event REPORT href values must stay on the trusted origin and inside the selected calendar.
$foreignEventClient = new FakeCalDAVHttpClient([
    caldavResponse(207, eventQueryResponseXml('https://attacker.example/event.ics'), 'https://calendar.example/calendars/user/work/')
]);
$provider = new CalDAVProvider($foreignEventClient, 'https://calendar.example/dav/');
assertCalDAVThrows(
    static fn () => $provider->getEvents(
        'https://calendar.example/calendars/user/work/',
        new DateTimeImmutable('2026-07-24T00:00:00Z'),
        new DateTimeImmutable('2026-07-25T00:00:00Z')
    ),
    CalDAVProviderException::class,
    'untrusted origin',
    'A foreign event href returned by REPORT must be rejected.'
);

$outsideCalendarClient = new FakeCalDAVHttpClient([
    caldavResponse(207, eventQueryResponseXml('/calendars/user/private/event.ics'), 'https://calendar.example/calendars/user/work/')
]);
$provider = new CalDAVProvider($outsideCalendarClient, 'https://calendar.example/dav/');
assertCalDAVThrows(
    static fn () => $provider->getEvents(
        'https://calendar.example/calendars/user/work/',
        new DateTimeImmutable('2026-07-24T00:00:00Z'),
        new DateTimeImmutable('2026-07-25T00:00:00Z')
    ),
    CalDAVProviderException::class,
    'does not belong to the configured calendar',
    'A same-origin event href outside the selected calendar must be rejected.'
);

// A normal REPORT response must still parse events and keep the resource URL and ETag.
$eventClient = new FakeCalDAVHttpClient([
    caldavResponse(207, eventQueryResponseXml('/calendars/user/work/event-1.ics'), 'https://calendar.example/calendars/user/work/')
]);
$provider = new CalDAVProvider($eventClient, 'https://calendar.example/dav/');
$events = $provider->getEvents(
    'https://calendar.example/calendars/user/work/',
    new DateTimeImmutable('2026-07-24T00:00:00Z'),
    new DateTimeImmutable('2026-07-25T00:00:00Z')
);
assertCalDAVSame(1, count($events), 'A valid CalDAV REPORT must return the contained event.');
assertCalDAVSame('CalDAV test', $events[0]['summary'], 'The iCalendar event title must be parsed.');
assertCalDAVSame('https://calendar.example/calendars/user/work/event-1.ics', $events[0]['resourceUrl'], 'The normalized DAV resource URL must be retained.');
assertCalDAVSame('"event-etag"', $events[0]['etag'], 'The DAV ETag must be retained for conflict detection.');
assertCalDAVSame('REPORT', $eventClient->requests[0]['method'], 'Events must be queried via REPORT.');
assertCalDAVSame('1', $eventClient->requests[0]['headers']['Depth'] ?? '', 'Calendar REPORT requests must use Depth 1.');
assertCalDAVTrue(str_contains($eventClient->requests[0]['body'], '20260724T000000Z'), 'The REPORT body must contain the UTC start boundary.');
assertCalDAVTrue(str_contains($eventClient->requests[0]['body'], '20260725T000000Z'), 'The REPORT body must contain the UTC end boundary.');

$recurringResourceUrl = 'https://calendar.example/calendars/user/work/series-1.ics';
$recurringEventClient = new FakeCalDAVHttpClient([
    caldavResponse(
        207,
        recurringEventQueryResponseXml('/calendars/user/work/series-1.ics'),
        'https://calendar.example/calendars/user/work/'
    )
]);
$provider = new CalDAVProvider($recurringEventClient, 'https://calendar.example/dav/');
$recurringEvents = $provider->getEvents(
    'https://calendar.example/calendars/user/work/',
    new DateTimeImmutable('2026-08-17T00:00:00Z'),
    new DateTimeImmutable('2026-08-25T00:00:00Z')
);
assertCalDAVSame(2, count($recurringEvents), 'Expanded CalDAV recurrence instances must remain separate events.');
assertCalDAVSame('occurrence', $recurringEvents[0]['recurrenceType'], 'The first expanded instance must be recognized as recurring when sibling instances share its UID.');
assertCalDAVSame('series-1@example.com', $recurringEvents[0]['seriesId'], 'CalDAV occurrence metadata must retain the recurring UID as series identity.');
assertCalDAVSame(true, $recurringEvents[0]['canUpdateOccurrence'], 'Expanded CalDAV occurrences must advertise individual updates.');
assertCalDAVSame(true, $recurringEvents[0]['canDeleteOccurrence'], 'Expanded CalDAV occurrences must advertise individual deletion.');
assertCalDAVSame('2026-08-24T10:00:00+00:00', $recurringEvents[1]['originalStart'], 'RECURRENCE-ID must remain the immutable original occurrence start.');

$seriesLookupClient = new FakeCalDAVHttpClient([
    caldavResponse(
        207,
        recurringSeriesLookupResponseXml('/calendars/user/work/series-1.ics'),
        'https://calendar.example/calendars/user/work/'
    ),
    caldavResponseWithHeaders(
        200,
        ['etag' => '"series-get-etag"'],
        recurringSeriesIcal(),
        $recurringResourceUrl
    )
]);
$provider = new CalDAVProvider($seriesLookupClient, 'https://calendar.example/dav/');
$recurringSeries = $provider->getRecurringSeries(
    'https://calendar.example/calendars/user/work/',
    'series-1@example.com'
);
assertCalDAVSame('master', $recurringSeries['recurrenceType'], 'CalDAV recurring series lookup must return the master event.');
assertCalDAVSame(true, $recurringSeries['canUpdateSeries'], 'CalDAV recurring series lookup must enable full-series updates.');
assertCalDAVSame(true, $recurringSeries['canDeleteSeries'], 'CalDAV recurring series lookup must enable full-series deletion.');
assertCalDAVSame(true, $recurringSeries['recurrenceEditable'], 'Simple CalDAV RRULEs must be editable in the common recurrence editor.');
assertCalDAVSame('WEEKLY', $recurringSeries['recurrenceSettings']['frequency'] ?? '', 'CalDAV recurring series lookup must expose the normalized frequency.');
assertCalDAVSame(4, $recurringSeries['recurrenceSettings']['count'] ?? 0, 'CalDAV recurring series lookup must expose the normalized occurrence count.');
assertCalDAVSame($recurringResourceUrl, $recurringSeries['resourceUrl'], 'CalDAV recurring series lookup must retain the object resource URL.');
assertCalDAVSame('"series-get-etag"', $recurringSeries['etag'], 'CalDAV recurring series lookup must use the current resource ETag from GET.');
assertCalDAVSame('REPORT', $seriesLookupClient->requests[0]['method'], 'CalDAV recurring series lookup must use a calendar REPORT.');
assertCalDAVSame('GET', $seriesLookupClient->requests[1]['method'], 'CalDAV recurring series lookup must retrieve the matched resource directly before editing.');
assertCalDAVSame($recurringResourceUrl, $seriesLookupClient->requests[1]['url'], 'CalDAV recurring series lookup must GET the exact matched object resource.');
assertCalDAVTrue(
    str_contains($seriesLookupClient->requests[0]['body'], '<c:prop-filter name="UID">')
        && str_contains($seriesLookupClient->requests[0]['body'], 'series-1@example.com'),
    'CalDAV recurring series lookup must filter the calendar query by the exact UID.'
);

// Updates must read the current resource, retain unrelated iCalendar data and use an ETag for optimistic locking.
$resourceUrl = 'https://calendar.example/calendars/user/work/event-1.ics';
$updateClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"etag-from-get"'], singleEventIcal(), $resourceUrl),
    caldavResponseWithHeaders(204, ['etag' => '"etag-after-put"'], '', $resourceUrl)
]);
$provider = new CalDAVProvider($updateClient, 'https://calendar.example/dav/');
$updated = $provider->updateEvent(
    'https://calendar.example/calendars/user/work/',
    $resourceUrl,
    '',
    'event-1@example.com',
    ['summary' => 'After update']
);
assertCalDAVSame('GET', $updateClient->requests[0]['method'], 'CalDAV updates must retrieve the current resource first.');
assertCalDAVSame('PUT', $updateClient->requests[1]['method'], 'CalDAV updates must write the modified resource via PUT.');
assertCalDAVSame('"etag-from-get"', $updateClient->requests[1]['headers']['If-Match'] ?? '', 'The current ETag must protect an update when no stored ETag is available.');
assertCalDAVTrue(str_contains($updateClient->requests[1]['body'], 'SUMMARY:After update'), 'The updated iCalendar body must contain the changed title.');
assertCalDAVSame('"etag-after-put"', $updated['etag'], 'The updated ETag must be returned to the caller.');

// Recurring CalDAV events must be serialized as one RFC 5545 calendar object resource.
$recurringCreateClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(201, ['etag' => '"series-etag"'], '', '')
]);
$provider = new CalDAVProvider($recurringCreateClient, 'https://calendar.example/dav/');
$createdSeries = $provider->createEvent(
    'https://calendar.example/calendars/user/work/',
    [
        'summary'    => 'Weekly meeting',
        'allDay'     => false,
        'start'      => '2026-10-19T08:00:00Z',
        'end'        => '2026-10-19T09:00:00Z',
        'timezone'   => 'Europe/Berlin',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 2,
            'byDay'     => ['TH', 'MO'],
            'endMode'   => 'until',
            'until'     => '2026-11-30'
        ]
    ]
);
assertCalDAVSame('PUT', $recurringCreateClient->requests[0]['method'], 'Recurring CalDAV events must be created via PUT.');
assertCalDAVSame('*', $recurringCreateClient->requests[0]['headers']['If-None-Match'] ?? '', 'Recurring CalDAV creation must keep If-None-Match protection.');
assertCalDAVTrue(
    str_contains($recurringCreateClient->requests[0]['body'], 'DTSTART;TZID=Europe/Berlin:20261019T100000'),
    'Recurring CalDAV events must preserve their local wall-clock start time.'
);
assertCalDAVTrue(
    str_contains($recurringCreateClient->requests[0]['body'], 'BEGIN:VTIMEZONE')
        && str_contains($recurringCreateClient->requests[0]['body'], 'TZID:Europe/Berlin'),
    'TZID-based CalDAV events must carry a matching VTIMEZONE component.'
);
assertCalDAVTrue(
    str_contains(
        $recurringCreateClient->requests[0]['body'],
        'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;UNTIL=20261130T090000Z'
    ),
    'Recurring CalDAV events must serialize the normalized recurrence rule as RFC 5545.'
);
assertCalDAVSame('"series-etag"', $createdSeries['etag'], 'Recurring CalDAV creation must return the server ETag.');

$occurrenceUpdateClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"series-etag"'], recurringSeriesIcal(), $recurringResourceUrl),
    caldavResponseWithHeaders(204, ['etag' => '"series-after-update"'], '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($occurrenceUpdateClient, 'https://calendar.example/dav/');
$updatedOccurrence = $provider->updateEvent(
    'https://calendar.example/calendars/user/work/',
    $recurringResourceUrl,
    '"series-etag"',
    'series-1@example.com',
    [
        'summary' => 'Changed only this occurrence',
        'allDay'  => false,
        'start'   => '2026-08-24T12:00:00Z',
        'end'     => '2026-08-24T13:00:00Z'
    ],
    $recurringEvents[1]
);
assertCalDAVSame('GET', $occurrenceUpdateClient->requests[0]['method'], 'Recurring CalDAV occurrence updates must read the complete calendar resource first.');
assertCalDAVSame('PUT', $occurrenceUpdateClient->requests[1]['method'], 'Recurring CalDAV occurrence updates must rewrite the complete resource via PUT.');
assertCalDAVSame('"series-etag"', $occurrenceUpdateClient->requests[1]['headers']['If-Match'] ?? '', 'Recurring occurrence updates must keep optimistic ETag protection.');
assertCalDAVTrue(
    str_contains($occurrenceUpdateClient->requests[1]['body'], 'RRULE:FREQ=WEEKLY;COUNT=4')
        && str_contains($occurrenceUpdateClient->requests[1]['body'], 'RECURRENCE-ID:20260824T100000Z')
        && str_contains($occurrenceUpdateClient->requests[1]['body'], 'SUMMARY:Changed only this occurrence'),
    'Updating one CalDAV occurrence must preserve the master and add a detached override.'
);
assertCalDAVSame('"series-after-update"', $updatedOccurrence['etag'], 'Recurring CalDAV occurrence updates must return the new ETag.');

$firstOccurrenceUpdateClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"series-etag"'], recurringSeriesIcal(), $recurringResourceUrl),
    caldavResponseWithHeaders(204, ['etag' => '"series-after-first-update"'], '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($firstOccurrenceUpdateClient, 'https://calendar.example/dav/');
$provider->updateEvent(
    'https://calendar.example/calendars/user/work/',
    $recurringResourceUrl,
    '"series-etag"',
    'series-1@example.com',
    ['summary' => 'Changed first occurrence']
);
assertCalDAVSame('PUT', $firstOccurrenceUpdateClient->requests[1]['method'], 'An initial expanded recurrence instance without RECURRENCE-ID must be updated inside the recurring resource.');
assertCalDAVTrue(
    str_contains($firstOccurrenceUpdateClient->requests[1]['body'], 'RECURRENCE-ID:20260817T100000Z')
        && str_contains($firstOccurrenceUpdateClient->requests[1]['body'], 'SUMMARY:Changed first occurrence')
        && str_contains($firstOccurrenceUpdateClient->requests[1]['body'], 'RRULE:FREQ=WEEKLY;COUNT=4'),
    'Updating an apparently single first recurrence instance must create an override without changing the master.'
);

$occurrenceDeleteClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"series-etag"'], recurringSeriesIcal(), $recurringResourceUrl),
    caldavResponseWithHeaders(204, ['etag' => '"series-after-delete"'], '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($occurrenceDeleteClient, 'https://calendar.example/dav/');
assertCalDAVSame(
    true,
    $provider->deleteEvent(
        'https://calendar.example/calendars/user/work/',
        $recurringResourceUrl,
        '"series-etag"',
        (string) ($recurringEvents[1]['recurrenceId'] ?? ''),
        $recurringEvents[1]
    ),
    'Deleting one CalDAV occurrence must succeed.'
);
assertCalDAVSame('GET', $occurrenceDeleteClient->requests[0]['method'], 'Recurring CalDAV occurrence deletion must read the complete resource first.');
assertCalDAVSame('PUT', $occurrenceDeleteClient->requests[1]['method'], 'Recurring CalDAV occurrence deletion must update the recurring resource instead of deleting it.');
assertCalDAVTrue(
    str_contains($occurrenceDeleteClient->requests[1]['body'], 'EXDATE:20260824T100000Z')
        && str_contains($occurrenceDeleteClient->requests[1]['body'], 'RRULE:FREQ=WEEKLY;COUNT=4'),
    'Deleting one CalDAV occurrence must add EXDATE without removing the recurring master.'
);

$firstOccurrenceDeleteClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"series-etag"'], recurringSeriesIcal(), $recurringResourceUrl),
    caldavResponseWithHeaders(204, ['etag' => '"series-after-first-delete"'], '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($firstOccurrenceDeleteClient, 'https://calendar.example/dav/');
assertCalDAVSame(
    true,
    $provider->deleteEvent(
        'https://calendar.example/calendars/user/work/',
        $recurringResourceUrl,
        '"series-etag"'
    ),
    'An initial expanded recurrence instance without RECURRENCE-ID must still delete only that occurrence.'
);
assertCalDAVSame('PUT', $firstOccurrenceDeleteClient->requests[1]['method'], 'The first recurring instance must never trigger deletion of the complete calendar resource.');
assertCalDAVTrue(
    str_contains($firstOccurrenceDeleteClient->requests[1]['body'], 'EXDATE:20260817T100000Z'),
    'Deleting an apparently single first recurrence instance must exclude the master DTSTART with EXDATE.'
);

$seriesUpdateClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(
        200,
        ['etag' => '"series-etag"'],
        recurringSeriesWithOverrideIcal(),
        $recurringResourceUrl
    ),
    caldavResponseWithHeaders(204, ['etag' => '"series-after-full-update"'], '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($seriesUpdateClient, 'https://calendar.example/dav/');
$seriesUpdateIdentity = $recurringSeries;
$seriesUpdateIdentity['writeScope'] = 'series';
$updatedSeries = $provider->updateEvent(
    'https://calendar.example/calendars/user/work/',
    $recurringResourceUrl,
    '"series-etag"',
    'series-1@example.com',
    [
        'summary'    => 'Recurring after',
        'allDay'     => false,
        'start'      => '2026-08-17T11:00:00Z',
        'end'        => '2026-08-17T12:00:00Z',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 2,
            'byDay'     => ['MO'],
            'endMode'   => 'count',
            'count'     => 3
        ]
    ],
    $seriesUpdateIdentity
);
assertCalDAVSame('GET', $seriesUpdateClient->requests[0]['method'], 'Full CalDAV series updates must retrieve the current resource first.');
assertCalDAVSame('PUT', $seriesUpdateClient->requests[1]['method'], 'Full CalDAV series updates must rewrite the resource via PUT.');
assertCalDAVTrue(
    str_contains($seriesUpdateClient->requests[1]['body'], 'SUMMARY:Recurring after')
        && str_contains($seriesUpdateClient->requests[1]['body'], 'DTSTART:20260817T110000Z')
        && str_contains($seriesUpdateClient->requests[1]['body'], 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO;COUNT=3')
        && str_contains($seriesUpdateClient->requests[1]['body'], 'RECURRENCE-ID:20260824T100000Z')
        && str_contains($seriesUpdateClient->requests[1]['body'], 'SUMMARY:Detached override'),
    'Updating a complete CalDAV series must change only the master while preserving detached overrides.'
);
assertCalDAVSame('"series-after-full-update"', $updatedSeries['etag'], 'Full CalDAV series updates must return the new ETag.');

$seriesDeleteClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"series-etag"'], recurringSeriesIcal(), $recurringResourceUrl),
    caldavResponse(204, '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($seriesDeleteClient, 'https://calendar.example/dav/');
$seriesDeleteIdentity = $recurringSeries;
$seriesDeleteIdentity['writeScope'] = 'series';
assertCalDAVSame(
    true,
    $provider->deleteEvent(
        'https://calendar.example/calendars/user/work/',
        $recurringResourceUrl,
        '"series-etag"',
        '',
        $seriesDeleteIdentity
    ),
    'Deleting a complete CalDAV series must succeed.'
);
assertCalDAVSame('GET', $seriesDeleteClient->requests[0]['method'], 'Full CalDAV series deletion must verify the current resource first.');
assertCalDAVSame('DELETE', $seriesDeleteClient->requests[1]['method'], 'Full CalDAV series deletion must delete the calendar object resource.');
assertCalDAVSame('"series-etag"', $seriesDeleteClient->requests[1]['headers']['If-Match'] ?? '', 'Full CalDAV series deletion must keep optimistic ETag protection.');

// A server must not be able to redirect the resulting resource outside the selected calendar path.
$createOutsideClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(
        201,
        ['etag' => '"new"'],
        '',
        'https://calendar.example/calendars/user/private/created.ics'
    )
]);
$provider = new CalDAVProvider($createOutsideClient, 'https://calendar.example/dav/');
assertCalDAVThrows(
    static fn () => $provider->createEvent(
        'https://calendar.example/calendars/user/work/',
        [
            'summary' => 'Created event',
            'allDay'  => false,
            'start'   => '2026-07-24T10:00:00Z',
            'end'     => '2026-07-24T11:00:00Z'
        ]
    ),
    CalDAVProviderException::class,
    'does not belong to the configured calendar',
    'A created resource outside the selected calendar path must be rejected.'
);

// HTTP 412 is the CalDAV conflict signal and must remain distinguishable.
$conflictClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"current-etag"'], singleEventIcal(), $resourceUrl),
    caldavResponse(412, '', $resourceUrl)
]);
$provider = new CalDAVProvider($conflictClient, 'https://calendar.example/dav/');
$conflict = assertCalDAVThrows(
    static fn () => $provider->deleteEvent(
        'https://calendar.example/calendars/user/work/',
        $resourceUrl,
        '"old-etag"'
    ),
    CalDAVProviderException::class,
    'changed by another client',
    'HTTP 412 must be reported as an optimistic-locking conflict.'
);
assertCalDAVSame(412, $conflict->httpStatus, 'The CalDAV conflict exception must retain HTTP status 412.');
assertCalDAVSame('GET', $conflictClient->requests[0]['method'], 'Deletes must inspect the current resource before deciding between resource and occurrence deletion.');
assertCalDAVSame('DELETE', $conflictClient->requests[1]['method'], 'A confirmed non-recurring event must still be deleted as a resource.');
assertCalDAVSame('"old-etag"', $conflictClient->requests[1]['headers']['If-Match'] ?? '', 'Deletes must keep the stored ETag through If-Match.');

echo "All CalDAV provider tests passed.\n";
