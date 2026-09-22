<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MicrosoftCalendarProvider.php';
require_once __DIR__ . '/../libs/MicrosoftTodoProvider.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';

use IPSKalender\CalDAVOriginPolicy;
use IPSKalender\CalDAVProvider;
use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\MicrosoftCalendarProvider;
use IPSKalender\MicrosoftTodoProvider;

final class AttachmentUploadHttp implements CalendarHttpClientInterface
{
    public array $requests = [];

    public function __construct(public array $responses)
    {
    }

    public function request(string $method, string $url, array $headers = [], string $body = '', int $maxResponseBytes = 67_108_864): CalendarHttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'maxResponseBytes');
        if ($this->responses === []) {
            throw new LogicException('Unexpected provider request.');
        }
        [$status, $response, $responseHeaders] = array_shift($this->responses);
        return new CalendarHttpResponse($status, $responseHeaders, is_string($response) ? $response : json_encode($response, JSON_THROW_ON_ERROR), $url);
    }
}

function uploadCheck(bool $value, string $message): void
{
    if (!$value) {
        throw new LogicException($message);
    }
}

function uploadReject(callable $call): void
{
    try {
        $call();
    } catch (RuntimeException | InvalidArgumentException) {
        return;
    }
    throw new LogicException('Expected provider upload rejection.');
}

$name = 'Proof.pdf';
$bytes = "%PDF-1.7\n%%EOF\n";
$content = base64_encode($bytes);
foreach ([false, true] as $task) {
    $http = new AttachmentUploadHttp([
        [200, ['id' => 'event'], []],
        [201, ['id' => 'new-file'], []]
    ]);
    $provider = $task ? new MicrosoftTodoProvider($http, 'secret') : new MicrosoftCalendarProvider($http, 'secret');
    $result = $task
        ? $provider->uploadAttachment('list', 'event', $name, $content)
        : $provider->uploadAttachment('calendar', 'event', $name, $content);
    uploadCheck(($result['uploaded'] ?? false) === true, 'Microsoft provider upload did not confirm creation.');
    uploadCheck(count($http->requests) === 2 && $http->requests[0]['method'] === 'GET'
        && $http->requests[1]['method'] === 'POST', 'Microsoft parent must be verified before POST.');
    $body = json_decode($http->requests[1]['body'], true, 8, JSON_THROW_ON_ERROR);
    uploadCheck(
        ($body['name'] ?? '') === $name && ($body['contentBytes'] ?? '') === $content
        && ($body['@odata.type'] ?? '') === ($task ? '#microsoft.graph.taskFileAttachment' : '#microsoft.graph.fileAttachment'),
        'Microsoft upload body or attachment kind is wrong.'
    );
    uploadCheck(str_ends_with(parse_url($http->requests[1]['url'], PHP_URL_PATH), '/attachments'), 'Attachment POST changed its owner.');
    $denied = new AttachmentUploadHttp([[200, ['id' => 'wrong'], []]]);
    $provider = $task ? new MicrosoftTodoProvider($denied, 'secret') : new MicrosoftCalendarProvider($denied, 'secret');
    uploadReject(fn () => $task
        ? $provider->uploadAttachment('list', 'event', $name, $content)
        : $provider->uploadAttachment('calendar', 'event', $name, $content));
    uploadCheck(count($denied->requests) === 1, 'Missing Microsoft owner must prevent POST.');
}

$ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event\r\nDTSTART:20260922T100000Z\r\nDTEND:20260922T110000Z\r\nSUMMARY:Test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$url = 'https://dav.invalid/calendar/event.ics';
$updated = IPSKalender\ICalendarCodec::appendAttachment($ical, 'event', '', $name, $content);
$noManaged = [[405, '', []], [404, '', []]];
$http = new AttachmentUploadHttp(array_merge(
    [[200, $ical, ['etag' => '"v1"']]],
    $noManaged,
    [[204, '', ['etag' => '"v2"']], [200, $updated, ['etag' => '"v2"']]]
));
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
$result = $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '', $url, $name, $content);
uploadCheck(($result['uploaded'] ?? false) === true && count($http->requests) === 5, 'CalDAV upload failed.');
uploadCheck($http->requests[3]['method'] === 'PUT' && $http->requests[3]['url'] === $url
    && ($http->requests[3]['headers']['If-Match'] ?? '') === '"v1"', 'CalDAV PUT must use fresh ETag and exact resource.');
$unfolded = preg_replace('/\r\n[ \t]/', '', $http->requests[3]['body']);
uploadCheck(str_contains($unfolded, 'ATTACH;FMTTYPE=application/pdf;ENCODING=BASE64;VALUE=BINARY;FILENAME="Proof.pdf":' . $content)
    && str_contains($unfolded, 'SUMMARY:Test'), 'CalDAV upload must preserve the event and append inline ATTACH.');
$listed = IPSKalender\ICalendarCodec::attachmentMetadata($http->requests[3]['body'], 'event');
uploadCheck(
    count($listed) === 1 && $listed[0]['name'] === $name,
    'Uploaded CalDAV attachment must be readable again by OpenCalendar.'
);

$http = new AttachmentUploadHttp(array_merge([[200, $ical, []]], $noManaged));
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
uploadReject(fn () => $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '', $url, $name, $content));
uploadCheck(count($http->requests) === 3, 'CalDAV without a strong ETag must not write.');

$http = new AttachmentUploadHttp(array_merge([[200, $ical, ['etag' => '"v1"']]], $noManaged));
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
uploadReject(fn () => $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '20260923T100000Z', $url, $name, $content));
uploadCheck(count($http->requests) === 1, 'A generated CalDAV occurrence must not be attached to the series master.');

$http = new AttachmentUploadHttp(array_merge([[200, $ical, ['etag' => '"v1"']]], $noManaged, [[412, '', []]]));
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
uploadReject(fn () => $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '', $url, $name, $content));
uploadCheck(count($http->requests) === 4, 'A conflicting CalDAV update must be rejected.');

$http = new AttachmentUploadHttp(array_merge(
    [[200, $ical, ['etag' => '"v1"']]],
    $noManaged,
    [[204, '', []], [200, $ical, ['etag' => '"v2"']]]
));
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
uploadReject(fn () => $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '', $url, $name, $content));
uploadCheck(count($http->requests) === 5, 'A server-dropped inline attachment must not be reported as uploaded.');

$managed = 'ATTACH;MANAGED-ID=server-123;FMTTYPE=application/pdf;SIZE=' . strlen($bytes)
    . ";FILENAME=Proof.pdf:https://dav.invalid/attachments/123\r\n";
$withManaged = str_replace('END:VEVENT', $managed . 'END:VEVENT', $ical);
$http = new AttachmentUploadHttp([
    [200, $ical, ['etag' => '"v1"']],
    [200, '', ['dav' => '1, calendar-access, calendar-managed-attachments']],
    [200, '', ['cal-managed-id' => 'server-123']],
    [200, $withManaged, ['etag' => '"v2"']]
]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
$result = $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '', $url, $name, $content);
uploadCheck(($result['uploaded'] ?? false) === true && ($result['pendingVerification'] ?? null) === false
    && $http->requests[2]['method'] === 'POST'
    && $http->requests[2]['url'] === $url . '?action=attachment-add'
    && $http->requests[2]['body'] === $bytes, 'Managed upload must POST the file bytes to the event.');

$http = new AttachmentUploadHttp([
    [200, $ical, ['etag' => '"v1"']],
    [200, '', ['dav' => 'calendar-managed-attachments']],
    [200, '', ['cal-managed-id' => 'server-123']],
    [200, $ical, ['etag' => '"v1"']],
    [200, $withManaged, ['etag' => '"v2"']]
]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
$result = $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '', $url, $name, $content);
uploadCheck(($result['uploaded'] ?? false) === true && ($result['pendingVerification'] ?? null) === true
    && count($http->requests) === 4,
    'An acknowledged managed attachment absent from the first read must be reported as pending without another upload.');
$eventuallyListed = $dav->getAttachmentMetadata('https://dav.invalid/calendar/', 'event', '', $url);
uploadCheck(count($eventuallyListed) === 1 && $eventuallyListed[0]['kind'] === 'file'
    && count($http->requests) === 5,
    'A later independent read must reveal the attachment without repeating the POST.');

$http = new AttachmentUploadHttp([
    [200, $ical, ['etag' => '"v1"']],
    [200, '', ['dav' => 'calendar-managed-attachments']],
    [200, '', ['cal-managed-id' => 'server-123']],
    [200, $ical, ['etag' => '"v2"']]
]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
$result = $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '', $url, $name, $content);
uploadCheck(($result['pendingVerification'] ?? null) === true && count($http->requests) === 4,
    'A managed attachment missing on readback must remain visibly unverified.');

$appleUrl = 'https://p01-caldav.icloud.com/123/calendars/abc/event.ics';
$appleCalendar = 'https://p01-caldav.icloud.com/123/calendars/abc/';
$appleAttachmentUrl = 'https://gateway.icloud.com/caldav/123/attachments/456';
$http = new AttachmentUploadHttp([[200, $ical, ['etag' => '"v1"']], [405, '', []], [404, '', []]]);
$apple = new CalDAVProvider($http, 'https://caldav.icloud.com/', new CalDAVOriginPolicy('https://caldav.icloud.com/'));
uploadReject(fn () => $apple->uploadAttachment($appleCalendar, 'event', '', $appleUrl, $name, $content));
uploadCheck(count($http->requests) === 3, 'iCloud must never fall back to invisible inline uploads.');
$appleIcal = str_replace(
    ['UID:event', 'END:VEVENT'],
    ['UID:apple-event', 'ATTACH;MANAGED-ID=apple-1;FMTTYPE=application/pdf;SIZE=' . strlen($bytes)
        . ';FILENAME=Proof.pdf:' . $appleAttachmentUrl . "\r\nEND:VEVENT"],
    $ical
);
$principalXml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop>'
    . '<d:current-user-principal><d:href>/123/principal/</d:href></d:current-user-principal>'
    . '</d:prop></d:propstat></d:response></d:multistatus>';
$homeXml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
    . '<d:response><d:propstat><d:prop><c:calendar-home-set><d:href>/123/calendars/</d:href>'
    . '</c:calendar-home-set></d:prop></d:propstat></d:response></d:multistatus>';
$http = new AttachmentUploadHttp([
    [200, str_replace('UID:event', 'UID:apple-event', $ical), ['etag' => '"v1"']],
    [200, '', ['dav' => 'calendar-access']],
    [207, $principalXml, []],
    [207, $homeXml, []],
    [200, '', ['dav' => 'calendar-access, calendar-managed-attachments']],
    [204, '', ['cal-managed-id' => 'apple-1']],
    [200, $appleIcal, ['etag' => '"v2"']]
]);
$apple = new CalDAVProvider($http, 'https://caldav.icloud.com/', new CalDAVOriginPolicy('https://caldav.icloud.com/'));
$result = $apple->uploadAttachment($appleCalendar, 'apple-event', '', $appleUrl, $name, $content);
uploadCheck(
    ($result['uploaded'] ?? false) === true && $http->requests[5]['method'] === 'POST'
    && $http->requests[4]['url'] === 'https://p01-caldav.icloud.com/123/calendars/',
    'iCloud upload must discover managed attachments on the calendar home.'
);
$http = new AttachmentUploadHttp([[200, $appleIcal, ['etag' => '"v1"']]]);
$apple = new CalDAVProvider($http, 'https://caldav.icloud.com/', new CalDAVOriginPolicy('https://caldav.icloud.com/'));
$metadata = $apple->getAttachmentMetadata($appleCalendar, 'apple-event', '', $appleUrl);
uploadCheck(
    count($metadata) === 1 && $metadata[0]['kind'] === 'file'
    && !str_contains(json_encode($metadata, JSON_THROW_ON_ERROR), 'gateway.icloud.com'),
    'Apple managed attachment must be downloadable without exposing its private URI.'
);
$http->responses = [[200, $appleIcal, ['etag' => '"v1"']], [200, $bytes, ['content-type' => 'application/pdf']]];
$download = $apple->getAttachmentContent($appleCalendar, 'apple-event', '', $metadata[0]['id'], $appleUrl);
uploadCheck(
    $download['content'] === $bytes && $http->requests[2]['url'] === $appleAttachmentUrl,
    'Apple managed attachment must be fetched by its trusted server URI.'
);

$untrustedIcal = str_replace($appleAttachmentUrl, 'https://private.invalid/secret', $appleIcal);
$http = new AttachmentUploadHttp([[200, $untrustedIcal, ['etag' => '"v1"']]]);
$apple = new CalDAVProvider($http, 'https://caldav.icloud.com/', new CalDAVOriginPolicy('https://caldav.icloud.com/'));
$metadata = $apple->getAttachmentMetadata($appleCalendar, 'apple-event', '', $appleUrl);
uploadCheck($metadata[0]['kind'] === 'reference', 'An untrusted managed URI must remain unavailable.');
$http->responses = [[200, $untrustedIcal, ['etag' => '"v1"']]];
uploadReject(fn () => $apple->getAttachmentContent($appleCalendar, 'apple-event', '', $metadata[0]['id'], $appleUrl));
uploadCheck(count($http->requests) === 2, 'Untrusted attachment URI must never be requested.');

fwrite(STDOUT, "Provider attachment uploads: Microsoft event/To Do parent checks and CalDAV conditional update passed.\n");
