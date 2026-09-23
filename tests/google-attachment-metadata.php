<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\GoogleCalendarProvider;

final class GoogleAttachmentHttp implements CalendarHttpClientInterface
{
    /** @var list<array{method:string,url:string,maxResponseBytes:int}> */
    public array $requests = [];

    /** @param list<array<string,mixed>> $events */
    public function __construct(private array $events)
    {
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $maxResponseBytes = 67_108_864
    ): CalendarHttpResponse {
        $this->requests[] = compact('method', 'url', 'maxResponseBytes');
        if ($this->events === []) {
            throw new RuntimeException('No Google fixture remains.');
        }
        return new CalendarHttpResponse(
            200,
            [],
            json_encode(array_shift($this->events), JSON_THROW_ON_ERROR),
            $url
        );
    }
}

function googleAttachmentCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function googleAttachmentReject(callable $operation): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('Invalid Google attachment owner was accepted.');
}

$http = new GoogleAttachmentHttp([
    ['id' => 'event-1', 'status' => 'confirmed', 'attachments' => [
        ['fileId' => 'file-1', 'fileUrl' => 'https://drive.google.com/file/d/file-1/view', 'title' => 'Report.pdf'],
        ['fileId' => 'file-2', 'fileUrl' => 'https://docs.google.com/document/d/file-2/edit', 'title' => 'Minutes'],
        ['fileId' => 'file-3', 'fileUrl' => 'https://evil.invalid/private', 'title' => 'Untrusted link'],
        ['fileId' => 'file-4', 'fileUrl' => 'https://drive.google.com.evil.invalid/private', 'title' => 'Spoofed host'],
        ['fileId' => 'file-5', 'fileUrl' => 'https://user@drive.google.com/private', 'title' => 'Credentials'],
        ['fileId' => 'file-6', 'fileUrl' => 'http://drive.google.com/private', 'title' => 'Insecure link']
    ]],
    ['id' => 'another-event', 'status' => 'confirmed', 'attachments' => []],
    ['id' => 'event-1', 'status' => 'cancelled', 'attachments' => []]
]);
$provider = new GoogleCalendarProvider($http, 'calendar-token');
$files = $provider->getAttachmentMetadata('https://www.googleapis.com/calendar/v3/calendars/primary', 'event-1');
googleAttachmentCheck(count($files) === 6, 'Google Calendar attachment metadata was not listed.');
googleAttachmentCheck($files[0]['name'] === 'Report.pdf'
    && $files[0]['kind'] === 'reference'
    && $files[0]['url'] === 'https://drive.google.com/file/d/file-1/view'
    && $files[0]['size'] === null, 'Drive file must remain an external reference without file content.');
googleAttachmentCheck($files[1]['url'] === 'https://docs.google.com/document/d/file-2/edit', 'Google Docs link was lost.');
googleAttachmentCheck(
    array_reduce(array_slice($files, 2), static fn (bool $safe, array $file): bool => $safe && $file['url'] === '', true),
    'Untrusted URLs must not be opened from the view.'
);
googleAttachmentCheck(
    $http->requests[0]['method'] === 'GET'
    && str_contains($http->requests[0]['url'], '/calendar/v3/calendars/primary/events/event-1?')
    && $http->requests[0]['maxResponseBytes'] <= 262_144,
    'Attachment listing must read one bounded Calendar event, never Drive.'
);
googleAttachmentReject(fn () => $provider->getAttachmentMetadata('primary', 'event-1'));
googleAttachmentReject(fn () => $provider->getAttachmentMetadata('primary', 'event-1'));
googleAttachmentCheck(count($http->requests) === 3, 'Rejected events should require a fresh Calendar lookup.');

echo "Google Calendar attachment metadata and safe external links passed.\n";
