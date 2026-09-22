<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MicrosoftCalendarProvider.php';
require_once __DIR__ . '/../libs/MicrosoftTodoProvider.php';

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\MicrosoftCalendarProvider;
use IPSKalender\MicrosoftTodoProvider;

final class AttachmentMetadataHttp implements CalendarHttpClientInterface
{
    public array $requests = [];

    public function __construct(public array $responses)
    {
    }

    public function request(string $method, string $url, array $headers = [], string $body = '', int $maxResponseBytes = 67_108_864): CalendarHttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'maxResponseBytes');
        if ($this->responses === []) {
            throw new LogicException('Unexpected attachment HTTP request.');
        }
        [$status, $data] = array_shift($this->responses);
        return new CalendarHttpResponse($status, [], is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR), $url);
    }
}

function attachmentMetadataCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new LogicException($message);
    }
}

function attachmentMetadataReject(callable $operation): void
{
    try {
        $operation();
    } catch (RuntimeException | InvalidArgumentException | JsonException) {
        return;
    }
    throw new LogicException('Expected attachment metadata rejection.');
}

$file = ['id'     => 'file-1', 'name' => 'Unterlagen.pdf', 'size' => 1234, 'contentType' => 'application/pdf',
    '@odata.type' => '#microsoft.graph.fileAttachment', 'contentBytes' => 'MUST_NOT_ESCAPE', 'sourceUrl' => 'http://127.0.0.1/private'];

foreach ([false, true] as $task) {
    $base = 'https://graph.microsoft.com/v1.0/me/' . ($task ? 'todo/lists/list%2B1/tasks/task%3D1' : 'calendars/cal%2B1/events/event%3D1');
    $id = $task ? 'task=1' : 'event=1';
    $item = $file;
    $item['@odata.type'] = $task ? '#microsoft.graph.taskFileAttachment' : '#microsoft.graph.fileAttachment';
    $second = array_replace($item, ['id' => 'file-2', 'name' => 'Änderung.txt']);
    $client = new AttachmentMetadataHttp([
        [200, ['id' => $id]],
        [200, ['value' => [$item], '@odata.nextLink' => $base . '/attachments?$skiptoken=next']],
        [200, ['value' => [$second]]]
    ]);
    $provider = $task ? new MicrosoftTodoProvider($client, 'secret') : new MicrosoftCalendarProvider($client, 'secret');
    attachmentMetadataCheck($client->requests === [], 'Construction must not eagerly load attachments.');
    $list = $provider->getAttachmentMetadata($task ? 'list+1' : 'cal+1', $id);
    attachmentMetadataCheck(count($list) === 2 && $list[0]['destination'] === 'provider' && $list[0]['kind'] === 'file', 'Metadata mapping/pagination');
    attachmentMetadataCheck(!str_contains(json_encode($list), 'MUST_NOT_ESCAPE') && !str_contains(json_encode($list), '127.0.0.1'), 'Body/URLs must not leave the provider.');
    foreach ($client->requests as $request) {
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
        attachmentMetadataCheck($request['method'] === 'GET' && $request['body'] === '' && $request['maxResponseBytes'] === 262144, 'Only bounded GET requests are allowed.');
        attachmentMetadataCheck(isset($query['$select']) && !str_contains($query['$select'], 'contentBytes'), 'Every page must request metadata only.');
        attachmentMetadataCheck(str_starts_with($request['url'], $base), 'Requests must stay on the selected owner.');
    }

    // Deleted, mismatched or inaccessible parents cannot produce an empty success.
    foreach ([[404, ['error' => ['message' => 'private']]], [403, []], [200, ['id' => 'wrong']], [200, str_repeat('x', 262145)]] as $parent) {
        $client = new AttachmentMetadataHttp([$parent]);
        $provider = $task ? new MicrosoftTodoProvider($client, 'secret') : new MicrosoftCalendarProvider($client, 'secret');
        attachmentMetadataReject(fn () => $provider->getAttachmentMetadata($task ? 'list+1' : 'cal+1', $id));
        attachmentMetadataCheck(count($client->requests) === 1, 'Invalid parent must stop before listing attachments.');
    }

    foreach ([
        'http://graph.microsoft.com/v1.0/me/attachments',
        'https://outside.invalid/files',
        $base . '/attachments#fragment',
        str_replace($id === 'task=1' ? 'task%3D1' : 'event%3D1', 'other-owner', $base) . '/attachments',
        $base . '/attachments?$select=id,contentBytes',
        $base . '/attachments?$expand=item',
        $base . '/attachments?$skiptoken[]=bad'
    ] as $next) {
        $client = new AttachmentMetadataHttp([[200, ['id' => $id]], [200, ['value' => [], '@odata.nextLink' => $next]]]);
        $provider = $task ? new MicrosoftTodoProvider($client, 'secret') : new MicrosoftCalendarProvider($client, 'secret');
        attachmentMetadataReject(fn () => $provider->getAttachmentMetadata($task ? 'list+1' : 'cal+1', $id));
        attachmentMetadataCheck(count($client->requests) === 2, 'Unsafe continuation must be rejected before HTTP.');
    }
    foreach ([['value' => [$item, $item]], ['value' => [array_replace($item, ['size' => -1])]], ['value' => [array_replace($item, ['name' => "bad\r\nname"])]], ['value' => null], ['value' => [], '@odata.nextLink' => []]] as $bad) {
        $client = new AttachmentMetadataHttp([[200, ['id' => $id]], [200, $bad]]);
        $provider = $task ? new MicrosoftTodoProvider($client, 'secret') : new MicrosoftCalendarProvider($client, 'secret');
        attachmentMetadataReject(fn () => $provider->getAttachmentMetadata($task ? 'list+1' : 'cal+1', $id));
    }
}

$types = [];
foreach (['fileAttachment', 'itemAttachment', 'referenceAttachment', 'unknown'] as $type) {
    $types[] = array_replace($file, ['id' => $type, '@odata.type' => '#microsoft.graph.' . $type]);
}
$client = new AttachmentMetadataHttp([[200, ['id' => 'event']], [200, ['value' => $types]]]);
$list = (new MicrosoftCalendarProvider($client, 'secret'))->getAttachmentMetadata('calendar', 'event');
attachmentMetadataCheck(array_column($list, 'kind') === ['file', 'item', 'reference', 'unsupported'], 'Non-file attachment types must be distinguished.');
$client = new AttachmentMetadataHttp([[200, ['id' => 'event', 'isCancelled' => true]]]);
attachmentMetadataReject(fn () => (new MicrosoftCalendarProvider($client, 'secret'))->getAttachmentMetadata('calendar', 'event'));

// Repeated pagination and oversized collections must fail instead of returning partial lists.
$next = 'https://graph.microsoft.com/v1.0/me/calendars/cal/events/evt/attachments?$skiptoken=same';
$client = new AttachmentMetadataHttp([[200, ['id' => 'evt']], [200, ['value' => [], '@odata.nextLink' => $next]], [200, ['value' => [], '@odata.nextLink' => $next]]]);
attachmentMetadataReject(fn () => (new MicrosoftCalendarProvider($client, 'secret'))->getAttachmentMetadata('cal', 'evt'));
$large = [];
for ($i = 0; $i < 101; ++$i) {
    $large[] = array_replace($file, ['id' => 'id-' . $i]);
}
$client = new AttachmentMetadataHttp([[200, ['id' => 'evt']], [200, ['value' => $large]]]);
attachmentMetadataReject(fn () => (new MicrosoftCalendarProvider($client, 'secret'))->getAttachmentMetadata('cal', 'evt'));

foreach ([false, true] as $task) {
    $content = "BINARY\0DATA";
    $item = array_replace($file, [
        'id'          => 'download', 'name' => 'Download.bin', 'size' => strlen($content),
        'contentType' => 'application/octet-stream',
        '@odata.type' => $task ? '#microsoft.graph.taskFileAttachment' : '#microsoft.graph.fileAttachment'
    ]);
    $client = new AttachmentMetadataHttp([[200, ['id' => 'owner']], [200, $item], [200, $content]]);
    $provider = $task ? new MicrosoftTodoProvider($client, 'secret') : new MicrosoftCalendarProvider($client, 'secret');
    $download = $task
        ? $provider->getAttachmentContent('list', 'owner', 'download')
        : $provider->getAttachmentContent('calendar', 'owner', 'download');
    attachmentMetadataCheck($download['content'] === $content && $download['name'] === 'Download.bin', 'Microsoft file download failed.');
    attachmentMetadataCheck(
        count($client->requests) === 3
        && str_ends_with($client->requests[2]['url'], '/attachments/download/$value')
        && $client->requests[2]['maxResponseBytes'] === 3 * 1024 * 1024,
        'Microsoft download must use the exact bounded raw-content endpoint.'
    );

    $reference = array_replace($item, ['@odata.type' => '#microsoft.graph.referenceAttachment']);
    $client = new AttachmentMetadataHttp([[200, ['id' => 'owner']], [200, $reference]]);
    $provider = $task ? new MicrosoftTodoProvider($client, 'secret') : new MicrosoftCalendarProvider($client, 'secret');
    attachmentMetadataReject(fn () => $task
        ? $provider->getAttachmentContent('list', 'owner', 'download')
        : $provider->getAttachmentContent('calendar', 'owner', 'download'));
    attachmentMetadataCheck(count($client->requests) === 2, 'Reference attachments must be rejected before raw download.');

    $oversized = array_replace($item, ['size' => 3 * 1024 * 1024 + 1]);
    $client = new AttachmentMetadataHttp([[200, ['id' => 'owner']], [200, $oversized]]);
    $provider = $task ? new MicrosoftTodoProvider($client, 'secret') : new MicrosoftCalendarProvider($client, 'secret');
    attachmentMetadataReject(fn () => $task
        ? $provider->getAttachmentContent('list', 'owner', 'download')
        : $provider->getAttachmentContent('calendar', 'owner', 'download'));
    attachmentMetadataCheck(count($client->requests) === 2, 'Oversized attachments must be rejected before raw download.');

    $changed = array_replace($item, ['size' => strlen($content) + 1]);
    $client = new AttachmentMetadataHttp([[200, ['id' => 'owner']], [200, $changed], [200, $content]]);
    $provider = $task ? new MicrosoftTodoProvider($client, 'secret') : new MicrosoftCalendarProvider($client, 'secret');
    attachmentMetadataReject(fn () => $task
        ? $provider->getAttachmentContent('list', 'owner', 'download')
        : $provider->getAttachmentContent('calendar', 'owner', 'download'));
}
fwrite(STDOUT, "Microsoft attachment metadata: scoped parents, bounded metadata-only requests, paging and failure isolation passed.\n");
