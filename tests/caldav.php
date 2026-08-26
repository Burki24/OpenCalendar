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

function singleEventWithAlarmIcal(): string
{
    return "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "PRODID:-//External Client//Calendar//EN\r\n"
        . "X-WR-CALNAME:Preserved calendar data\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:convert-single@example.com\r\n"
        . "DTSTAMP:20260801T000000Z\r\n"
        . "SEQUENCE:2\r\n"
        . "DTSTART:20260817T100000Z\r\n"
        . "DTEND:20260817T110000Z\r\n"
        . "SUMMARY:Single before conversion\r\n"
        . "BEGIN:VALARM\r\n"
        . "ACTION:DISPLAY\r\n"
        . "TRIGGER:-PT15M\r\n"
        . "DESCRIPTION:Keep this reminder\r\n"
        . "END:VALARM\r\n"
        . "END:VEVENT\r\n"
        . "END:VCALENDAR\r\n";
}

function recurringSeriesWithAlarmIcal(): string
{
    return "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "PRODID:-//External Client//Calendar//EN\r\n"
        . "X-WR-CALNAME:Preserved calendar data\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:series-1@example.com\r\n"
        . "DTSTAMP:20260801T000000Z\r\n"
        . "SEQUENCE:1\r\n"
        . "DTSTART:20260817T100000Z\r\n"
        . "DTEND:20260817T110000Z\r\n"
        . "RRULE:FREQ=WEEKLY;COUNT=4\r\n"
        . "EXDATE:20260831T100000Z\r\n"
        . "SUMMARY:Recurring before\r\n"
        . "BEGIN:VALARM\r\n"
        . "ACTION:DISPLAY\r\n"
        . "TRIGGER:-PT10M\r\n"
        . "DESCRIPTION:Keep master reminder\r\n"
        . "END:VALARM\r\n"
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
assertCalDAVSame(true, $calendars[0]['capabilities']['updateRecurrence'], 'Writable CalDAV calendars must advertise recurrence conversion.');
assertCalDAVSame(true, $calendars[0]['capabilities']['updateOccurrence'], 'Writable CalDAV calendars must advertise recurring occurrence updates.');
assertCalDAVSame(true, $calendars[0]['capabilities']['updateFollowing'], 'Writable CalDAV calendars must advertise this-and-following updates.');
assertCalDAVSame(true, $calendars[0]['capabilities']['deleteOccurrence'], 'Writable CalDAV calendars must advertise recurring occurrence deletion.');
assertCalDAVSame(true, $calendars[0]['capabilities']['updateSeries'], 'Writable CalDAV calendars must advertise recurring series updates.');
assertCalDAVSame(true, $calendars[0]['capabilities']['deleteSeries'], 'Writable CalDAV calendars must advertise recurring series deletion.');
assertCalDAVSame(5, $calendars[0]['capabilities']['maxReminders'], 'CalDAV calendars must advertise support for up to five editable OpenCalendar reminders.');
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
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['updateRecurrence'], 'Unknown DAV privileges must not hide recurrence conversion optimistically.');
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['updateOccurrence'], 'Unknown DAV privileges must not hide recurring occurrence updates optimistically.');
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['updateFollowing'], 'Unknown DAV privileges must not hide this-and-following updates optimistically.');
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['deleteOccurrence'], 'Unknown DAV privileges must not hide recurring occurrence deletion optimistically.');
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['updateSeries'], 'Unknown DAV privileges must not hide recurring series updates optimistically.');
assertCalDAVSame(true, $unknownPrivilegeCalendars[0]['capabilities']['deleteSeries'], 'Unknown DAV privileges must not hide recurring series deletion optimistically.');
assertCalDAVSame(5, $unknownPrivilegeCalendars[0]['capabilities']['maxReminders'], 'Unknown DAV privileges must not reduce the provider-neutral CalDAV reminder limit.');

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

// Edit preparation can read an already known calendar object directly instead of
// repeating a calendar-wide REPORT. The current GET ETag must be retained.
$directEditResourceUrl = 'https://calendar.example/calendars/user/work/event-1.ics';
$directEditClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(
        200,
        ['etag' => '"direct-edit-etag"'],
        singleEventIcal(),
        $directEditResourceUrl
    )
]);
$provider = new CalDAVProvider($directEditClient, 'https://calendar.example/dav/');
$directEditEvent = $provider->getEventForEdit(
    'https://calendar.example/calendars/user/work/',
    [
        'resourceUrl'    => $directEditResourceUrl,
        'uid'            => 'event-1@example.com',
        'startTimestamp' => (new DateTimeImmutable('2026-07-24T10:00:00Z'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-07-24T11:00:00Z'))->getTimestamp()
    ]
);
assertCalDAVSame('Before update', $directEditEvent['summary'], 'Direct CalDAV edit lookup must parse the current resource body.');
assertCalDAVSame('"direct-edit-etag"', $directEditEvent['etag'], 'Direct CalDAV edit lookup must retain the current GET ETag.');
assertCalDAVSame($directEditResourceUrl, $directEditEvent['resourceUrl'], 'Direct CalDAV edit lookup must retain the effective resource URL.');
assertCalDAVSame(1, count($directEditClient->requests), 'Direct CalDAV edit lookup must use exactly one resource request.');
assertCalDAVSame('GET', $directEditClient->requests[0]['method'], 'Direct CalDAV edit lookup must avoid a calendar REPORT.');

$directRecurringEditClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(
        200,
        ['etag' => '"direct-recurring-edit-etag"'],
        recurringSeriesIcal(),
        'https://calendar.example/calendars/user/work/series-1.ics'
    )
]);
$provider = new CalDAVProvider($directRecurringEditClient, 'https://calendar.example/dav/');
$directRecurringEditEvent = $provider->getEventForEdit(
    'https://calendar.example/calendars/user/work/',
    [
        'resourceUrl'    => 'https://calendar.example/calendars/user/work/series-1.ics',
        'uid'            => 'series-1@example.com',
        'seriesId'       => 'series-1@example.com',
        'startTimestamp' => (new DateTimeImmutable('2026-08-24T10:00:00Z'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-24T11:00:00Z'))->getTimestamp()
    ]
);
assertCalDAVSame('occurrence', $directRecurringEditEvent['recurrenceType'], 'A directly loaded recurring resource must expose the selected occurrence.');
assertCalDAVSame(true, $directRecurringEditEvent['canUpdateOccurrence'], 'Directly loaded CalDAV occurrences must remain individually editable.');
assertCalDAVSame(true, $directRecurringEditEvent['canDeleteOccurrence'], 'Directly loaded CalDAV occurrences must remain individually deletable.');
assertCalDAVSame('2026-08-24T10:00:00+00:00', $directRecurringEditEvent['originalStart'], 'Direct recurring edit lookup must retain the immutable occurrence start.');

$uidLookupClient = new FakeCalDAVHttpClient([
    caldavResponse(
        207,
        eventQueryResponseXml('/calendars/user/work/event-1.ics'),
        'https://calendar.example/calendars/user/work/'
    )
]);
$uidLookupEvent = (new CalDAVProvider($uidLookupClient, 'https://calendar.example/dav/'))->getEventForEdit(
    'https://calendar.example/calendars/user/work/',
    [
        'uid'            => 'event-1@example.com',
        'startTimestamp' => (new DateTimeImmutable('2026-07-24T10:00:00Z'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-07-24T11:00:00Z'))->getTimestamp()
    ]
);
assertCalDAVSame('CalDAV test', $uidLookupEvent['summary'], 'CalDAV direct lookup must resolve a missing resource URL through the provider-owned UID query.');
assertCalDAVSame(1, count($uidLookupClient->requests), 'CalDAV UID fallback must remain inside the provider and use one bounded resource query.');
assertCalDAVSame('REPORT', $uidLookupClient->requests[0]['method'], 'CalDAV UID fallback must use a UID calendar-query when no resource URL is known.');
assertCalDAVTrue(str_contains($uidLookupClient->requests[0]['body'], 'event-1@example.com'), 'CalDAV UID fallback must query the exact normalized event UID.');

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
assertCalDAVSame(true, $recurringEvents[0]['canUpdateFollowing'], 'Expanded CalDAV occurrences must advertise this-and-following updates.');
assertCalDAVSame(true, $recurringEvents[0]['canUpdateSeries'], 'Expanded CalDAV occurrences must advertise complete-series updates.');
assertCalDAVSame(true, $recurringEvents[0]['canDeleteSeries'], 'Expanded CalDAV occurrences must advertise complete-series deletion.');
assertCalDAVSame('2026-08-24T10:00:00+00:00', $recurringEvents[1]['originalStart'], 'RECURRENCE-ID must remain the immutable original occurrence start.');

// iCloud rejects calendar-query UID prop-filter lookups with HTTP 412. When the
// synchronized occurrence already carries its calendar object URL, complete-series
// editing must open that exact resource directly and must not issue a UID REPORT.
$directSeriesClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(
        200,
        ['etag' => '"series-direct-etag"'],
        recurringSeriesIcal(),
        $recurringResourceUrl
    )
]);
$provider = new CalDAVProvider($directSeriesClient, 'https://calendar.example/dav/');
$directRecurringSeries = $provider->getRecurringSeries(
    'https://calendar.example/calendars/user/work/',
    'series-1@example.com',
    $recurringResourceUrl
);
assertCalDAVSame('master', $directRecurringSeries['recurrenceType'], 'A known CalDAV resource URL must resolve the recurring master directly.');
assertCalDAVSame('"series-direct-etag"', $directRecurringSeries['etag'], 'Direct recurring series retrieval must retain the current GET ETag.');
assertCalDAVSame(1, count($directSeriesClient->requests), 'A known recurring resource URL must avoid an additional UID calendar-query.');
assertCalDAVSame('GET', $directSeriesClient->requests[0]['method'], 'A known recurring resource URL must be retrieved directly with GET.');
assertCalDAVSame($recurringResourceUrl, $directSeriesClient->requests[0]['url'], 'Complete-series editing must GET the exact synchronized CalDAV object URL.');

// This-and-following preparation must use the exact known object resource as well.
$followingPrepareClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(
        200,
        ['etag' => '"following-prepare-etag"'],
        recurringSeriesWithOverrideIcal(),
        $recurringResourceUrl
    )
]);
$provider = new CalDAVProvider($followingPrepareClient, 'https://calendar.example/dav/');
$followingPrepared = $provider->getRecurringFollowing(
    'https://calendar.example/calendars/user/work/',
    'series-1@example.com',
    (string) ($recurringEvents[1]['occurrenceId'] ?? ''),
    (string) ($recurringEvents[1]['originalStart'] ?? ''),
    $recurringResourceUrl
);
assertCalDAVSame('exception', $followingPrepared['recurrenceType'], 'This-and-following preparation must preserve the selected recurring exception.');
assertCalDAVSame('following', $followingPrepared['writeScope'], 'This-and-following preparation must select the following write scope.');
assertCalDAVSame(true, $followingPrepared['canUpdateFollowing'], 'A supported CalDAV occurrence must allow this-and-following updates.');
assertCalDAVSame(true, $followingPrepared['canDeleteSeries'], 'A supported CalDAV occurrence must retain the series deletion capability needed for following deletion.');
assertCalDAVSame(3, $followingPrepared['recurrenceSettings']['count'] ?? 0, 'A numbered series must expose the remaining occurrence count from the selected split point.');
assertCalDAVSame('2026-08-24T12:00:00+00:00', $followingPrepared['start'], 'A detached selected occurrence must retain its current edited start when preparing the future series.');
assertCalDAVSame('"following-prepare-etag"', $followingPrepared['etag'], 'This-and-following preparation must use the current direct GET ETag.');
assertCalDAVSame(1, count($followingPrepareClient->requests), 'A known CalDAV resource must prepare this-and-following editing without a UID REPORT.');
assertCalDAVSame('GET', $followingPrepareClient->requests[0]['method'], 'This-and-following preparation must read the exact calendar object directly.');

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

// Existing single CalDAV events can be converted into recurring series in place.
$singleToSeriesUrl = 'https://calendar.example/calendars/user/work/convert-single.ics';
$singleToSeriesClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"single-current-etag"'], singleEventWithAlarmIcal(), $singleToSeriesUrl),
    caldavResponseWithHeaders(204, ['etag' => '"single-series-etag"'], '', $singleToSeriesUrl)
]);
$provider = new CalDAVProvider($singleToSeriesClient, 'https://calendar.example/dav/');
$convertedSeries = $provider->updateEvent(
    'https://calendar.example/calendars/user/work/',
    $singleToSeriesUrl,
    '"single-editor-etag"',
    'convert-single@example.com',
    [
        'summary'    => 'Series after conversion',
        'allDay'     => false,
        'start'      => '2026-08-17T10:00:00Z',
        'end'        => '2026-08-17T11:00:00Z',
        'timezone'   => 'Europe/Berlin',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 1,
            'byDay'     => ['MO'],
            'endMode'   => 'count',
            'count'     => 3
        ]
    ]
);
assertCalDAVSame(2, count($singleToSeriesClient->requests), 'Converting a single CalDAV event to a series must update the existing resource in place.');
assertCalDAVSame('GET', $singleToSeriesClient->requests[0]['method'], 'Single-to-series conversion must read the current resource first.');
assertCalDAVSame('PUT', $singleToSeriesClient->requests[1]['method'], 'Single-to-series conversion must rewrite the existing resource.');
assertCalDAVSame('"single-current-etag"', $singleToSeriesClient->requests[1]['headers']['If-Match'] ?? '', 'Single-to-series conversion must use the fresh GET ETag.');
assertCalDAVTrue(
    str_contains($singleToSeriesClient->requests[1]['body'], 'UID:convert-single@example.com')
        && str_contains($singleToSeriesClient->requests[1]['body'], 'DTSTART;TZID=Europe/Berlin:20260817T120000')
        && str_contains($singleToSeriesClient->requests[1]['body'], 'DTEND;TZID=Europe/Berlin:20260817T130000')
        && str_contains($singleToSeriesClient->requests[1]['body'], 'RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=3')
        && str_contains($singleToSeriesClient->requests[1]['body'], 'BEGIN:VTIMEZONE')
        && str_contains($singleToSeriesClient->requests[1]['body'], 'TZID:Europe/Berlin'),
    'Single-to-series conversion must preserve the existing UID while adding a timezone-safe recurrence rule.'
);
assertCalDAVTrue(
    str_contains($singleToSeriesClient->requests[1]['body'], 'BEGIN:VALARM')
        && str_contains($singleToSeriesClient->requests[1]['body'], 'TRIGGER:-PT15M')
        && str_contains($singleToSeriesClient->requests[1]['body'], 'X-WR-CALNAME:Preserved calendar data'),
    'Single-to-series conversion must preserve nested VALARM and unrelated calendar data.'
);
assertCalDAVSame('convert-single@example.com', $convertedSeries['uid'], 'Single-to-series conversion must retain the existing UID.');
assertCalDAVSame('"single-series-etag"', $convertedSeries['etag'], 'Single-to-series conversion must return the new ETag.');

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
        ['etag' => '"series-current-etag"'],
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
    '"series-editor-etag"',
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
assertCalDAVSame(
    '"series-current-etag"',
    $seriesUpdateClient->requests[1]['headers']['If-Match'] ?? '',
    'Full CalDAV series updates must use the fresh GET ETag instead of the older editor ETag.'
);
assertCalDAVTrue(
    str_contains($seriesUpdateClient->requests[1]['body'], 'SUMMARY:Recurring after')
        && str_contains($seriesUpdateClient->requests[1]['body'], 'DTSTART:20260817T110000Z')
        && str_contains($seriesUpdateClient->requests[1]['body'], 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO;COUNT=3')
        && str_contains($seriesUpdateClient->requests[1]['body'], 'RECURRENCE-ID:20260824T100000Z')
        && str_contains($seriesUpdateClient->requests[1]['body'], 'SUMMARY:Detached override'),
    'Updating a complete CalDAV series must change only the master while preserving detached overrides.'
);
assertCalDAVSame('"series-after-full-update"', $updatedSeries['etag'], 'Full CalDAV series updates must return the new ETag.');

// A complete CalDAV series can be converted back into one single event.
$seriesToSingleClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(
        200,
        ['etag' => '"series-single-current"'],
        recurringSeriesWithAlarmIcal(),
        $recurringResourceUrl
    ),
    caldavResponseWithHeaders(204, ['etag' => '"series-single-after"'], '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($seriesToSingleClient, 'https://calendar.example/dav/');
$seriesToSingleIdentity = $recurringSeries;
$seriesToSingleIdentity['writeScope'] = 'series';
$convertedSingle = $provider->updateEvent(
    'https://calendar.example/calendars/user/work/',
    $recurringResourceUrl,
    '"series-single-editor"',
    'series-1@example.com',
    [
        'summary'    => 'Single after series',
        'recurrence' => null
    ],
    $seriesToSingleIdentity
);
assertCalDAVSame(2, count($seriesToSingleClient->requests), 'Series-to-single conversion must update the existing CalDAV resource in place.');
assertCalDAVSame('GET', $seriesToSingleClient->requests[0]['method'], 'Series-to-single conversion must read the current resource first.');
assertCalDAVSame('PUT', $seriesToSingleClient->requests[1]['method'], 'Series-to-single conversion must rewrite the existing resource.');
assertCalDAVSame('"series-single-current"', $seriesToSingleClient->requests[1]['headers']['If-Match'] ?? '', 'Series-to-single conversion must use the fresh GET ETag.');
assertCalDAVTrue(
    str_contains($seriesToSingleClient->requests[1]['body'], 'UID:series-1@example.com')
        && str_contains($seriesToSingleClient->requests[1]['body'], 'SUMMARY:Single after series')
        && !str_contains($seriesToSingleClient->requests[1]['body'], 'RRULE:')
        && !str_contains($seriesToSingleClient->requests[1]['body'], 'RDATE:')
        && !str_contains($seriesToSingleClient->requests[1]['body'], 'EXDATE:')
        && !str_contains($seriesToSingleClient->requests[1]['body'], 'RECURRENCE-ID:'),
    'Series-to-single conversion must remove all recurrence rules and detached overrides.'
);
assertCalDAVSame(
    1,
    substr_count($seriesToSingleClient->requests[1]['body'], 'UID:series-1@example.com'),
    'Series-to-single conversion must retain exactly one VEVENT with the original UID.'
);
assertCalDAVTrue(
    str_contains($seriesToSingleClient->requests[1]['body'], 'BEGIN:VALARM')
        && str_contains($seriesToSingleClient->requests[1]['body'], 'TRIGGER:-PT10M')
        && str_contains($seriesToSingleClient->requests[1]['body'], 'X-WR-CALNAME:Preserved calendar data'),
    'Series-to-single conversion must preserve the master VALARM and unrelated calendar data.'
);
assertCalDAVSame('series-1@example.com', $convertedSingle['uid'], 'Series-to-single conversion must retain the existing UID.');
assertCalDAVSame('"series-single-after"', $convertedSingle['etag'], 'Series-to-single conversion must return the new ETag.');

// This-and-following updates split the calendar object into an old shortened series and a new future series.
$followingUpdateClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(
        200,
        ['etag' => '"following-current-etag"'],
        recurringSeriesWithOverrideIcal(),
        $recurringResourceUrl
    ),
    caldavResponseWithHeaders(201, ['etag' => '"future-series-etag"'], '', ''),
    caldavResponseWithHeaders(204, ['etag' => '"shortened-series-etag"'], '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($followingUpdateClient, 'https://calendar.example/dav/');
$followingIdentity = $followingPrepared;
$followingIdentity['writeScope'] = 'following';
$followingUpdated = $provider->updateEvent(
    'https://calendar.example/calendars/user/work/',
    $recurringResourceUrl,
    '"following-editor-etag"',
    'series-1@example.com',
    [
        'summary'    => 'Future series after split',
        'allDay'     => false,
        'start'      => '2026-08-24T13:00:00Z',
        'end'        => '2026-08-24T14:00:00Z',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 1,
            'byDay'     => ['MO'],
            'endMode'   => 'count',
            'count'     => 3
        ]
    ],
    $followingIdentity
);
assertCalDAVSame(3, count($followingUpdateClient->requests), 'Splitting a CalDAV series must use one GET and two protected PUT writes.');
assertCalDAVSame('GET', $followingUpdateClient->requests[0]['method'], 'This-and-following updates must start from the current resource.');
assertCalDAVSame('PUT', $followingUpdateClient->requests[1]['method'], 'This-and-following updates must create the future series first.');
assertCalDAVSame('*', $followingUpdateClient->requests[1]['headers']['If-None-Match'] ?? '', 'The new future CalDAV resource must be protected against accidental overwrite.');
assertCalDAVSame('PUT', $followingUpdateClient->requests[2]['method'], 'This-and-following updates must shorten the original resource after creating the future resource.');
assertCalDAVSame('"following-current-etag"', $followingUpdateClient->requests[2]['headers']['If-Match'] ?? '', 'The original series must be shortened with the ETag from the immediately preceding GET.');
assertCalDAVTrue(
    str_contains($followingUpdateClient->requests[1]['body'], 'SUMMARY:Future series after split')
        && str_contains($followingUpdateClient->requests[1]['body'], 'DTSTART:20260824T130000Z')
        && str_contains($followingUpdateClient->requests[1]['body'], 'RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=3')
        && !str_contains($followingUpdateClient->requests[1]['body'], 'RECURRENCE-ID:20260824T100000Z')
        && !str_contains($followingUpdateClient->requests[1]['body'], 'UID:series-1@example.com'),
    'The future CalDAV resource must be a new recurring master at the selected occurrence and reset old detached exceptions.'
);
assertCalDAVTrue(
    str_contains($followingUpdateClient->requests[2]['body'], 'UID:series-1@example.com')
        && str_contains($followingUpdateClient->requests[2]['body'], 'RRULE:FREQ=WEEKLY;UNTIL=20260824T095959Z')
        && str_contains($followingUpdateClient->requests[2]['body'], 'SUMMARY:Recurring before')
        && !str_contains($followingUpdateClient->requests[2]['body'], 'RECURRENCE-ID:20260824T100000Z'),
    'The original CalDAV resource must end before the split target and discard detached overrides at or after the split.'
);
assertCalDAVTrue(
    ($followingUpdated['uid'] ?? '') !== ''
        && ($followingUpdated['uid'] ?? '') !== 'series-1@example.com'
        && str_ends_with((string) ($followingUpdated['resourceUrl'] ?? ''), rawurlencode((string) $followingUpdated['uid']) . '.ics')
        && ($followingUpdated['etag'] ?? '') === '"future-series-etag"',
    'A successful CalDAV split must return the new future-series identity and ETag.'
);

// If shortening races with another write, remove the temporary future series and retry from a fresh resource.
$followingRetryClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"following-retry-old-1"'], recurringSeriesWithOverrideIcal(), $recurringResourceUrl),
    caldavResponseWithHeaders(201, ['etag' => '"following-retry-new-1"'], '', ''),
    caldavResponse(412, '', $recurringResourceUrl),
    caldavResponse(204, '', ''),
    caldavResponseWithHeaders(200, ['etag' => '"following-retry-old-2"'], recurringSeriesWithOverrideIcal(), $recurringResourceUrl),
    caldavResponseWithHeaders(201, ['etag' => '"following-retry-new-2"'], '', ''),
    caldavResponseWithHeaders(204, ['etag' => '"following-retry-after"'], '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($followingRetryClient, 'https://calendar.example/dav/');
$retriedFollowing = $provider->updateEvent(
    'https://calendar.example/calendars/user/work/',
    $recurringResourceUrl,
    '',
    'series-1@example.com',
    [
        'summary'    => 'Future series after retry',
        'allDay'     => false,
        'start'      => '2026-08-24T13:00:00Z',
        'end'        => '2026-08-24T14:00:00Z',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 1,
            'byDay'     => ['MO'],
            'endMode'   => 'count',
            'count'     => 3
        ]
    ],
    $followingIdentity
);
assertCalDAVSame(7, count($followingRetryClient->requests), 'A CalDAV following split conflict must roll back once and retry exactly once.');
assertCalDAVSame('DELETE', $followingRetryClient->requests[3]['method'], 'A failed original-series trim must remove the temporary future series before retrying.');
assertCalDAVSame('"following-retry-old-1"', $followingRetryClient->requests[2]['headers']['If-Match'] ?? '', 'The first trim attempt must use the first fresh ETag.');
assertCalDAVSame('"following-retry-old-2"', $followingRetryClient->requests[6]['headers']['If-Match'] ?? '', 'The retried trim must use the refreshed ETag.');
assertCalDAVTrue($followingRetryClient->requests[1]['url'] !== $followingRetryClient->requests[5]['url'], 'A retried split must use a fresh future resource identity after rollback.');
assertCalDAVSame('"following-retry-new-2"', $retriedFollowing['etag'], 'A successful split retry must return the final future-series ETag.');

// Selecting the first occurrence for this-and-following is equivalent to updating the existing whole series in place.
$firstFollowingUpdateClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"first-following-etag"'], recurringSeriesIcal(), $recurringResourceUrl),
    caldavResponseWithHeaders(204, ['etag' => '"first-following-after"'], '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($firstFollowingUpdateClient, 'https://calendar.example/dav/');
$firstFollowingIdentity = $recurringEvents[0];
$firstFollowingIdentity['writeScope'] = 'following';
$provider->updateEvent(
    'https://calendar.example/calendars/user/work/',
    $recurringResourceUrl,
    '"old-first-following-etag"',
    'series-1@example.com',
    [
        'summary'    => 'All occurrences from first',
        'recurrence' => [
            'frequency' => 'weekly',
            'interval'  => 1,
            'byDay'     => ['MO'],
            'endMode'   => 'count',
            'count'     => 4
        ]
    ],
    $firstFollowingIdentity
);
assertCalDAVSame(2, count($firstFollowingUpdateClient->requests), 'This-and-following from the first occurrence must not create a second resource.');
assertCalDAVSame('PUT', $firstFollowingUpdateClient->requests[1]['method'], 'This-and-following from the first occurrence must update the existing series.');
assertCalDAVSame('"first-following-etag"', $firstFollowingUpdateClient->requests[1]['headers']['If-Match'] ?? '', 'The first-occurrence update must use the current GET ETag.');
assertCalDAVTrue(
    str_contains($firstFollowingUpdateClient->requests[1]['body'], 'SUMMARY:All occurrences from first')
        && str_contains($firstFollowingUpdateClient->requests[1]['body'], 'UID:series-1@example.com'),
    'This-and-following from the first occurrence must retain the existing series identity.'
);

$followingDeleteClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"following-delete-etag"'], recurringSeriesWithOverrideIcal(), $recurringResourceUrl),
    caldavResponseWithHeaders(204, ['etag' => '"following-delete-after"'], '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($followingDeleteClient, 'https://calendar.example/dav/');
assertCalDAVSame(
    true,
    $provider->deleteEvent(
        'https://calendar.example/calendars/user/work/',
        $recurringResourceUrl,
        '"stale-following-delete-etag"',
        '',
        $followingIdentity
    ),
    'Deleting a CalDAV occurrence and all following occurrences must succeed.'
);
assertCalDAVSame('GET', $followingDeleteClient->requests[0]['method'], 'Following deletion must verify the current recurring resource first.');
assertCalDAVSame('PUT', $followingDeleteClient->requests[1]['method'], 'Following deletion after the first occurrence must shorten the existing resource.');
assertCalDAVSame('"following-delete-etag"', $followingDeleteClient->requests[1]['headers']['If-Match'] ?? '', 'Following deletion must use the fresh GET ETag.');
assertCalDAVTrue(
    str_contains($followingDeleteClient->requests[1]['body'], 'RRULE:FREQ=WEEKLY;UNTIL=20260824T095959Z')
        && !str_contains($followingDeleteClient->requests[1]['body'], 'RECURRENCE-ID:20260824T100000Z'),
    'Following deletion must remove the selected and future occurrences, including future detached overrides.'
);

$firstFollowingDeleteClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(200, ['etag' => '"first-following-delete-etag"'], recurringSeriesIcal(), $recurringResourceUrl),
    caldavResponse(204, '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($firstFollowingDeleteClient, 'https://calendar.example/dav/');
$firstFollowingDeleteIdentity = $recurringEvents[0];
$firstFollowingDeleteIdentity['writeScope'] = 'following';
assertCalDAVSame(
    true,
    $provider->deleteEvent(
        'https://calendar.example/calendars/user/work/',
        $recurringResourceUrl,
        '"old-first-following-delete-etag"',
        '',
        $firstFollowingDeleteIdentity
    ),
    'Deleting this and following from the first CalDAV occurrence must delete the complete series resource.'
);
assertCalDAVSame('DELETE', $firstFollowingDeleteClient->requests[1]['method'], 'Following deletion from the first occurrence must delete the whole calendar object.');
assertCalDAVSame('"first-following-delete-etag"', $firstFollowingDeleteClient->requests[1]['headers']['If-Match'] ?? '', 'First-occurrence following deletion must use the current resource ETag.');

// A transient 412 between GET and PUT must trigger one fresh read and retry.
$seriesRetryClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(
        200,
        ['etag' => '"series-retry-etag-1"'],
        recurringSeriesWithOverrideIcal(),
        $recurringResourceUrl
    ),
    caldavResponse(412, '', $recurringResourceUrl),
    caldavResponseWithHeaders(
        200,
        ['etag' => '"series-retry-etag-2"'],
        recurringSeriesWithOverrideIcal(),
        $recurringResourceUrl
    ),
    caldavResponseWithHeaders(204, ['etag' => '"series-after-retry"'], '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($seriesRetryClient, 'https://calendar.example/dav/');
$retriedSeries = $provider->updateEvent(
    'https://calendar.example/calendars/user/work/',
    $recurringResourceUrl,
    '"series-editor-etag"',
    'series-1@example.com',
    ['summary' => 'Recurring after retry'],
    $seriesUpdateIdentity
);
assertCalDAVSame('GET', $seriesRetryClient->requests[0]['method'], 'A CalDAV retry must start with a fresh GET.');
assertCalDAVSame('PUT', $seriesRetryClient->requests[1]['method'], 'The first CalDAV write attempt must use PUT.');
assertCalDAVSame('GET', $seriesRetryClient->requests[2]['method'], 'HTTP 412 must trigger exactly one additional GET before retrying.');
assertCalDAVSame('PUT', $seriesRetryClient->requests[3]['method'], 'The CalDAV retry must issue one second PUT.');
assertCalDAVSame(
    '"series-retry-etag-1"',
    $seriesRetryClient->requests[1]['headers']['If-Match'] ?? '',
    'The first write attempt must use the ETag from the immediately preceding GET.'
);
assertCalDAVSame(
    '"series-retry-etag-2"',
    $seriesRetryClient->requests[3]['headers']['If-Match'] ?? '',
    'The retry must use the refreshed ETag from the second GET.'
);
assertCalDAVTrue(
    str_contains($seriesRetryClient->requests[3]['body'], 'SUMMARY:Recurring after retry'),
    'The retry must re-apply the requested series changes to the freshly retrieved resource.'
);
assertCalDAVSame('"series-after-retry"', $retriedSeries['etag'], 'A successful retry must return the final server ETag.');

// Repeated 412 responses remain a conflict, but the message must not blame another client.
$seriesRetryConflictClient = new FakeCalDAVHttpClient([
    caldavResponseWithHeaders(
        200,
        ['etag' => '"series-conflict-etag-1"'],
        recurringSeriesIcal(),
        $recurringResourceUrl
    ),
    caldavResponse(412, '', $recurringResourceUrl),
    caldavResponseWithHeaders(
        200,
        ['etag' => '"series-conflict-etag-2"'],
        recurringSeriesIcal(),
        $recurringResourceUrl
    ),
    caldavResponse(412, '', $recurringResourceUrl)
]);
$provider = new CalDAVProvider($seriesRetryConflictClient, 'https://calendar.example/dav/');
$seriesRetryConflict = assertCalDAVThrows(
    static fn () => $provider->updateEvent(
        'https://calendar.example/calendars/user/work/',
        $recurringResourceUrl,
        '"series-editor-etag"',
        'series-1@example.com',
        ['summary' => 'Will still conflict'],
        $seriesUpdateIdentity
    ),
    CalDAVProviderException::class,
    'changed before OpenCalendar could complete the write',
    'Repeated HTTP 412 responses must remain distinguishable without blaming another client.'
);
assertCalDAVSame(412, $seriesRetryConflict->httpStatus, 'Repeated CalDAV update conflicts must retain HTTP status 412.');
assertCalDAVSame(4, count($seriesRetryConflictClient->requests), 'A CalDAV update conflict must retry only once.');

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

// HTTP 412 during a read-only UID lookup is not a write conflict. This is
// especially important for iCloud, which rejects UID prop-filter REPORT queries.
$lookupPreconditionClient = new FakeCalDAVHttpClient([
    caldavResponse(412, '', 'https://calendar.example/calendars/user/work/')
]);
$provider = new CalDAVProvider($lookupPreconditionClient, 'https://calendar.example/dav/');
$lookupPrecondition = assertCalDAVThrows(
    static fn () => $provider->getRecurringSeries(
        'https://calendar.example/calendars/user/work/',
        'series-1@example.com'
    ),
    CalDAVProviderException::class,
    'Unexpected CalDAV response during recurring series lookup: HTTP 412.',
    'HTTP 412 during recurring-series discovery must not be reported as a save conflict.'
);
assertCalDAVSame(412, $lookupPrecondition->httpStatus, 'A CalDAV lookup precondition failure must retain HTTP status 412.');
assertCalDAVSame('REPORT', $lookupPreconditionClient->requests[0]['method'], 'The compatibility fallback still uses UID REPORT when no resource URL is known.');

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
    'changed before OpenCalendar could complete the write',
    'HTTP 412 must be reported as a neutral optimistic-locking conflict.'
);
assertCalDAVSame(412, $conflict->httpStatus, 'The CalDAV conflict exception must retain HTTP status 412.');
assertCalDAVSame('GET', $conflictClient->requests[0]['method'], 'Deletes must inspect the current resource before deciding between resource and occurrence deletion.');
assertCalDAVSame('DELETE', $conflictClient->requests[1]['method'], 'A confirmed non-recurring event must still be deleted as a resource.');
assertCalDAVSame('"current-etag"', $conflictClient->requests[1]['headers']['If-Match'] ?? '', 'Deletes must use the current GET ETag through If-Match.');

echo "All CalDAV provider tests passed.\n";
