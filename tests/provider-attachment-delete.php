<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MicrosoftCalendarProvider.php';
require_once __DIR__ . '/../libs/MicrosoftTodoProvider.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\MicrosoftCalendarProvider;
use IPSKalender\MicrosoftTodoProvider;
use IPSKalender\CalDAVProvider;
use IPSKalender\CalDAVOriginPolicy;
use IPSKalender\ICalendarCodec;

final class AttachmentDeleteHttp implements CalendarHttpClientInterface
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
        [$status, $payload, $responseHeaders] = array_pad(array_shift($this->responses), 3, []);
        return new CalendarHttpResponse($status, $responseHeaders, is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR), $url);
    }
}

function deleteCheck(bool $result, string $message): void
{
    if (!$result) {
        throw new LogicException($message);
    }
}

function deleteReject(callable $action): void
{
    try {
        $action();
    } catch (RuntimeException | InvalidArgumentException) {
        return;
    }
    throw new LogicException('Expected attachment deletion to be rejected.');
}

$file = ['id' => 'file-1', 'name' => 'Proof.pdf', 'size' => 12, 'contentType' => 'application/pdf',
    '@odata.type' => '#microsoft.graph.fileAttachment', 'isInline' => false];
foreach ([false, true] as $task) {
    $http = new AttachmentDeleteHttp([[200, ['id' => 'event']], [200, $file], [204, '']]);
    $provider = $task ? new MicrosoftTodoProvider($http, 'token') : new MicrosoftCalendarProvider($http, 'token');
    $result = $task
        ? $provider->deleteAttachment('list', 'event', 'file-1')
        : $provider->deleteAttachment('calendar', 'event', 'file-1');
    deleteCheck(($result['deleted'] ?? null) === true, 'Microsoft must confirm attachment deletion.');
    deleteCheck(array_column($http->requests, 'method') === ['GET', 'GET', 'DELETE'],
        'The exact owner and attachment must be rechecked before DELETE.');
    deleteCheck(str_ends_with(parse_url($http->requests[2]['url'], PHP_URL_PATH), '/attachments/file-1'),
        'DELETE must target only the selected attachment.');
    $wrong = new AttachmentDeleteHttp([[200, ['id' => 'other']]]);
    $provider = $task ? new MicrosoftTodoProvider($wrong, 'token') : new MicrosoftCalendarProvider($wrong, 'token');
    deleteReject(fn () => $task
        ? $provider->deleteAttachment('list', 'event', 'file-1')
        : $provider->deleteAttachment('calendar', 'event', 'file-1'));
    deleteCheck(count($wrong->requests) === 1, 'A changed parent must prevent DELETE.');
}

foreach (['itemAttachment', 'referenceAttachment'] as $kind) {
    $metadata = [...$file, '@odata.type' => '#microsoft.graph.' . $kind];
    $http = new AttachmentDeleteHttp([[200, ['id' => 'event']], [200, $metadata], [204, '']]);
    $result = (new MicrosoftCalendarProvider($http, 'token'))->deleteAttachment('calendar', 'event', 'file-1');
    deleteCheck($result['deleted'] === true, 'A real Graph attachment type should be removable.');
}

foreach ([['id' => 'other'], ['isInline' => true], ['@odata.type' => '#microsoft.graph.unknownAttachment']] as $change) {
    $http = new AttachmentDeleteHttp([[200, ['id' => 'event']], [200, array_replace($file, $change)]]);
    deleteReject(fn () => (new MicrosoftCalendarProvider($http, 'token'))->deleteAttachment('calendar', 'event', 'file-1'));
    deleteCheck(count($http->requests) === 2, 'Unverified, inline or unsupported attachments must never be deleted.');
}

$http = new AttachmentDeleteHttp([]);
deleteReject(fn () => (new MicrosoftCalendarProvider($http, 'token'))->deleteAttachment('calendar', 'event', 'body-reference-' . str_repeat('a', 64)));
deleteCheck($http->requests === [], 'Description-only links must not be sent to Graph as attachments.');

$base = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event\r\nDTSTART:20260922T100000Z\r\nDTEND:20260922T110000Z\r\nSUMMARY:Keep me\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$inline = 'ATTACH;FMTTYPE=application/pdf;ENCODING=BASE64;VALUE=BINARY;FILENAME="A.pdf":' . base64_encode('pdf');
$external = 'ATTACH;FILENAME="B.pdf":https://files.invalid/b.pdf';
$withAttachments = str_replace('END:VEVENT', $inline . "\r\n" . $external . "\r\nEND:VEVENT", $base);
$metadata = ICalendarCodec::attachmentMetadata($withAttachments, 'event');
$removed = ICalendarCodec::removeAttachment($withAttachments, 'event', '', $metadata[0]['id']);
deleteCheck(count(ICalendarCodec::attachmentMetadata($removed['ical'], 'event')) === 1
    && str_contains($removed['ical'], $external) && str_contains($removed['ical'], 'SUMMARY:Keep me'),
    'Removing one ATTACH must preserve other properties and attachments.');
deleteReject(fn () => ICalendarCodec::removeAttachment($withAttachments, 'event', '', str_repeat('0', 64)));
$url = 'https://dav.invalid/calendar/event.ics';
$calendar = 'https://dav.invalid/calendar/';
$http = new AttachmentDeleteHttp([
    [200, $withAttachments, ['etag' => '"v1"']],
    [204, ''],
    [200, $removed['ical'], ['etag' => '"v2"']]
]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
$result = $dav->deleteAttachment($calendar, 'event', '', $url, $metadata[0]['id']);
deleteCheck($result['deleted'] === true && array_column($http->requests, 'method') === ['GET', 'PUT', 'GET']
    && ($http->requests[1]['headers']['If-Match'] ?? '') === '"v1"'
    && !str_contains($http->requests[1]['body'], $inline)
    && str_contains($http->requests[1]['body'], $external),
    'Inline CalDAV deletion must use conditional PUT on the exact resource.');

$withoutExternal = ICalendarCodec::removeAttachment($withAttachments, 'event', '', $metadata[1]['id']);
$http = new AttachmentDeleteHttp([
    [200, $withAttachments, ['etag' => '"v1"']], [204, ''],
    [200, $withoutExternal['ical'], ['etag' => '"v2"']]
]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
deleteCheck($dav->deleteAttachment($calendar, 'event', '', $url, $metadata[1]['id'])['deleted'] === true
    && $http->requests[1]['method'] === 'PUT'
    && $http->requests[1]['url'] === $url,
    'An unmanaged external ATTACH must be removed from iCalendar, never fetched or deleted by URI.');

$managedLine = 'ATTACH;MANAGED-ID=server-123;FMTTYPE=application/pdf;FILENAME="A.pdf":https://dav.invalid/attachments/123';
$managed = str_replace('END:VEVENT', $managedLine . "\r\nEND:VEVENT", $base);
$managedId = ICalendarCodec::attachmentMetadata($managed, 'event')[0]['id'];
foreach ([false, true] as $pending) {
    $http = new AttachmentDeleteHttp([
        [200, $managed, ['etag' => '"v1"']], [204, ''],
        [200, $pending ? $managed : $base, ['etag' => $pending ? '"v1"' : '"v2"']]
    ]);
    $dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
    $result = $dav->deleteAttachment($calendar, 'event', '', $url, $managedId);
    deleteCheck($result['deleted'] === true && ($result['pendingVerification'] ?? false) === $pending
        && $http->requests[1]['method'] === 'POST'
        && $http->requests[1]['url'] === $url . '?action=attachment-remove&managed-id=server-123'
        && ($http->requests[1]['headers']['If-Match'] ?? '') === '"v1"',
        'Managed deletion must POST the selected managed ID and handle delayed visibility.');
}
$appleUrl = 'https://p01-caldav.icloud.com/123/calendars/abc/event.ics';
$appleCalendar = 'https://p01-caldav.icloud.com/123/calendars/abc/';
$appleManaged = str_replace(
    'https://dav.invalid/attachments/123', 'https://gateway.icloud.com/caldav/123/attachments/456', $managed
);
$appleId = ICalendarCodec::attachmentMetadata($appleManaged, 'event')[0]['id'];
$http = new AttachmentDeleteHttp([
    [200, $appleManaged, ['etag' => '"v1"']], [204, ''],
    [200, $base, ['etag' => '"v2"']]
]);
$apple = new CalDAVProvider($http, 'https://caldav.icloud.com/', new CalDAVOriginPolicy('https://caldav.icloud.com/'));
deleteCheck($apple->deleteAttachment($appleCalendar, 'event', '', $appleUrl, $appleId)['deleted'] === true
    && $http->requests[1]['method'] === 'POST'
    && $http->requests[1]['url'] === $appleUrl . '?action=attachment-remove&managed-id=server-123',
    'Apple managed attachments must be removed using the event resource, never the gateway file URL.');

foreach ([[], ['etag' => 'W/"v1"']] as $headers) {
    $http = new AttachmentDeleteHttp([[200, $withAttachments, $headers]]);
    $dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
    deleteReject(fn () => $dav->deleteAttachment($calendar, 'event', '', $url, $metadata[0]['id']));
    deleteCheck(count($http->requests) === 1, 'A strong ETag is required before CalDAV deletion.');
}
$http = new AttachmentDeleteHttp([[200, $withAttachments, ['etag' => '"v1"']], [412, '']]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
deleteReject(fn () => $dav->deleteAttachment($calendar, 'event', '', $url, $metadata[0]['id']));
$http = new AttachmentDeleteHttp([[200, $withAttachments, ['etag' => '"v1"']], [204, ''],
    [200, $withAttachments, ['etag' => '"v2"']]]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
deleteReject(fn () => $dav->deleteAttachment($calendar, 'event', '', $url, $metadata[0]['id']));
$http = new AttachmentDeleteHttp([[200, $withAttachments, ['etag' => '"v1"']]]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
deleteReject(fn () => $dav->deleteAttachment($calendar, 'event', '20260923T100000Z', $url, $metadata[0]['id']));
deleteCheck(count($http->requests) === 1, 'Generated occurrences must not delete attachments from the series master.');

fwrite(STDOUT, "Microsoft and CalDAV provider attachment deletion checks passed.\n");
