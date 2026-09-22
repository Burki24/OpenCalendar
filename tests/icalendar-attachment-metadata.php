<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ICalendarCodec.php';
require_once __DIR__ . '/../libs/ICalendarFileProvider.php';
require_once __DIR__ . '/../libs/ICalendarFeedProvider.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';

use IPSKalender\CalDAVOriginPolicy;
use IPSKalender\CalDAVProvider;
use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\ICalendarCodec;
use IPSKalender\ICalendarFeedProvider;
use IPSKalender\ICalendarFileProvider;

function icalAttachmentCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new LogicException($message);
    }
}

function icalAttachmentReject(callable $operation): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }
    throw new LogicException('Expected iCalendar attachment rejection.');
}

final class ICalendarAttachmentHttp implements CalendarHttpClientInterface
{
    public array $requests = [];

    public function __construct(public array $responses)
    {
    }

    public function request(string $method, string $url, array $headers = [], string $body = '', int $maxResponseBytes = 67_108_864): CalendarHttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'maxResponseBytes');
        if ($this->responses === []) {
            throw new LogicException('Unexpected iCalendar attachment request.');
        }
        $response = array_shift($this->responses);
        return new CalendarHttpResponse($response[0], $response[1] ?? [], $response[2], $url);
    }
}

$calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:one\r\nDTSTART:20260922T100000Z\r\nDTEND:20260922T110000Z\r\n" .
    "ATTACH;FMTTYPE=application/pdf;ENCODING=BASE64;VALUE=BINARY;FILENAME=Report.pdf:SGVsbG8=\r\n" .
    "ATTACH;FMTTYPE=text/plain;FILENAME=External.txt:https://files.invalid/private?token=SECRET\r\n" .
    "BEGIN:VALARM\r\nATTACH;FMTTYPE=audio/basic:https://files.invalid/alarm\r\nEND:VALARM\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$files = ICalendarCodec::attachmentMetadata($calendar, 'one');
icalAttachmentCheck(count($files) === 2, 'Top-level event attachments were not selected exactly.');
icalAttachmentCheck($files[0]['kind'] === 'embedded' && $files[0]['size'] === 5 && $files[0]['name'] === 'Report.pdf', 'Embedded metadata is incorrect.');
icalAttachmentCheck($files[1]['kind'] === 'reference' && $files[1]['size'] === null, 'External reference metadata is incorrect.');
$encoded = json_encode($files, JSON_THROW_ON_ERROR);
icalAttachmentCheck(!str_contains($encoded, 'files.invalid') && !str_contains($encoded, 'SECRET') && !str_contains($encoded, 'SGVsbG8'), 'Private URI or content escaped metadata.');

$series = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:series\r\nDTSTART:20260922T100000Z\r\nDTEND:20260922T110000Z\r\nRRULE:FREQ=DAILY;COUNT=3\r\nATTACH;FILENAME=Master.pdf:https://files.invalid/master\r\nEND:VEVENT\r\n" .
    "BEGIN:VEVENT\r\nUID:series\r\nRECURRENCE-ID:20260923T100000Z\r\nDTSTART:20260923T120000Z\r\nDTEND:20260923T130000Z\r\nATTACH;FILENAME=Exception.pdf:https://files.invalid/exception\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
icalAttachmentCheck(ICalendarCodec::attachmentMetadata($series, 'series', '20260923T100000Z')[0]['name'] === 'Exception.pdf', 'Exception attachment selection failed.');
icalAttachmentCheck(ICalendarCodec::attachmentMetadata($series, 'series', '20260924T100000')[0]['name'] === 'Master.pdf', 'Generated occurrence must inherit master attachment metadata.');
icalAttachmentReject(fn () => ICalendarCodec::attachmentMetadata($series, 'series', '20260930T100000Z'));
icalAttachmentReject(fn () => ICalendarCodec::attachmentMetadata(str_replace('SGVsbG8=', '***', $calendar), 'one'));
icalAttachmentReject(fn () => ICalendarCodec::attachmentMetadata(str_replace('https://files.invalid/private?token=SECRET', 'relative/private', $calendar), 'one'));
icalAttachmentReject(fn () => ICalendarCodec::attachmentMetadata(str_replace('END:VCALENDAR', "BEGIN:VEVENT\r\nUID:one\r\nDTSTART:20260922T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR", $calendar), 'one'));

$fileProvider = new ICalendarFileProvider(base64_encode($calendar), 'Fixture', 'fixture');
icalAttachmentCheck($fileProvider->getAttachmentMetadata('urn:ips-kalender:ics-file:fixture', 'one')[0]['name'] === 'Report.pdf', 'ICS file metadata failed.');

$feedHttp = new ICalendarAttachmentHttp([[200, [], $calendar]]);
$feed = new ICalendarFeedProvider($feedHttp, 'https://calendar.invalid/feed.ics');
icalAttachmentCheck(count($feed->getAttachmentMetadata('https://calendar.invalid/feed.ics', 'one')) === 2, 'ICS feed metadata failed.');
icalAttachmentCheck(count($feedHttp->requests) === 1 && $feedHttp->requests[0]['method'] === 'GET'
    && $feedHttp->requests[0]['maxResponseBytes'] === 16 * 1024 * 1024, 'ICS feed metadata must use one bounded on-demand request.');

$xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response>' .
    '<d:href>/cal/one.ics</d:href><d:propstat><d:prop><d:getetag>"e1"</d:getetag><c:calendar-data>' .
    htmlspecialchars($calendar, ENT_XML1) . '</c:calendar-data></d:prop></d:propstat></d:response></d:multistatus>';
$davHttp = new ICalendarAttachmentHttp([[207, [], $xml]]);
$dav = new CalDAVProvider($davHttp, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
icalAttachmentCheck(count($dav->getAttachmentMetadata('https://dav.invalid/cal/', 'one')) === 2, 'CalDAV metadata failed.');
icalAttachmentCheck(count($davHttp->requests) === 1 && $davHttp->requests[0]['method'] === 'REPORT'
    && $davHttp->requests[0]['maxResponseBytes'] === 16 * 1024 * 1024, 'CalDAV metadata must use one bounded event lookup.');

fwrite(STDOUT, "iCalendar/CalDAV attachment metadata: exact event identity, recurrence inheritance and opaque references passed.\n");
