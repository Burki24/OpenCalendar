<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MicrosoftCalendarProvider.php';
require_once __DIR__ . '/../libs/MicrosoftTodoProvider.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\CalDAVOriginPolicy;
use IPSKalender\CalDAVProvider;
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
    uploadCheck(($body['name'] ?? '') === $name && ($body['contentBytes'] ?? '') === $content
        && ($body['@odata.type'] ?? '') === ($task ? '#microsoft.graph.taskFileAttachment' : '#microsoft.graph.fileAttachment'),
        'Microsoft upload body or attachment kind is wrong.');
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
$http = new AttachmentUploadHttp([[200, $ical, ['etag' => '"v1"']], [204, '', ['etag' => '"v2"']]]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
$result = $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '', $url, $name, $content);
uploadCheck(($result['uploaded'] ?? false) === true && count($http->requests) === 2, 'CalDAV upload failed.');
uploadCheck($http->requests[1]['method'] === 'PUT' && $http->requests[1]['url'] === $url
    && ($http->requests[1]['headers']['If-Match'] ?? '') === '"v1"', 'CalDAV PUT must use fresh ETag and exact resource.');
$unfolded = preg_replace('/\r\n[ \t]/', '', $http->requests[1]['body']);
uploadCheck(str_contains($unfolded, 'ATTACH;FMTTYPE=application/pdf;ENCODING=BASE64;VALUE=BINARY;FILENAME="Proof.pdf":' . $content)
    && str_contains($unfolded, 'SUMMARY:Test'), 'CalDAV upload must preserve the event and append inline ATTACH.');
$listed = IPSKalender\ICalendarCodec::attachmentMetadata($http->requests[1]['body'], 'event');
uploadCheck(count($listed) === 1 && $listed[0]['name'] === $name,
    'Uploaded CalDAV attachment must be readable again by OpenCalendar.');

$http = new AttachmentUploadHttp([[200, $ical, []]]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
uploadReject(fn () => $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '', $url, $name, $content));
uploadCheck(count($http->requests) === 1, 'CalDAV without a strong ETag must not write.');

$http = new AttachmentUploadHttp([[200, $ical, ['etag' => '"v1"']]]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
uploadReject(fn () => $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '20260923T100000Z', $url, $name, $content));
uploadCheck(count($http->requests) === 1, 'A generated CalDAV occurrence must not be attached to the series master.');

$http = new AttachmentUploadHttp([[200, $ical, ['etag' => '"v1"']], [412, '', []]]);
$dav = new CalDAVProvider($http, 'https://dav.invalid/', new CalDAVOriginPolicy('https://dav.invalid/'));
uploadReject(fn () => $dav->uploadAttachment('https://dav.invalid/calendar/', 'event', '', $url, $name, $content));
uploadCheck(count($http->requests) === 2, 'A conflicting CalDAV update must be rejected.');

fwrite(STDOUT, "Provider attachment uploads: Microsoft event/To Do parent checks and CalDAV conditional update passed.\n");
