<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\MicrosoftTodoProvider;
use IPSKalender\MicrosoftTodoProviderException;

require_once __DIR__ . '/../libs/MicrosoftTodoProvider.php';

final class MicrosoftTodoTestHttpClient implements CalendarHttpClientInterface
{
    /** @var list<array{method:string,url:string,body:string}> */
    public array $requests = [];

    /** @param list<CalendarHttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $maxResponseBytes = 67_108_864
    ): CalendarHttpResponse {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
        return array_shift($this->responses)
            ?? throw new RuntimeException('Unexpected Microsoft To Do request.');
    }
}

function todoResponse(int $status, array $data = []): CalendarHttpResponse
{
    return new CalendarHttpResponse(
        $status,
        [],
        $status === 204 ? '' : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'https://graph.microsoft.com/v1.0/me/todo/lists'
    );
}

function assertMicrosoftTodo(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$client = new MicrosoftTodoTestHttpClient([
    todoResponse(200, ['value' => [[
        'id' => 'list-1', 'displayName' => 'Aufgaben', 'wellknownListName' => 'defaultList'
    ]]]),
    todoResponse(200, [
        'value' => [[
            'id' => 'task-1',
            '@odata.etag' => 'etag-1',
            'title' => 'Versicherung prüfen',
            'status' => 'notStarted',
            'importance' => 'high',
            'body' => ['contentType' => 'text', 'content' => 'Unterlagen suchen'],
            'dueDateTime' => ['dateTime' => '2026-09-20T00:00:00.0000000', 'timeZone' => 'Europe/Berlin'],
            'isReminderOn' => false
        ]],
        '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/todo/lists/list-1/tasks/delta?$deltatoken=abc'
    ]),
    todoResponse(201, [
        'id' => 'task-2', 'title' => 'Neue Aufgabe', 'status' => 'notStarted'
    ]),
    todoResponse(200, [
        'id' => 'task-2', 'title' => 'Neue Aufgabe', 'status' => 'completed'
    ]),
    todoResponse(204)
]);
$provider = new MicrosoftTodoProvider($client, 'access-token');

$lists = $provider->getTaskLists();
assertMicrosoftTodo(count($lists) === 1 && $lists[0]['name'] === 'Aufgaben', 'Task lists must be mapped.');

$sync = $provider->getTasks('list-1');
assertMicrosoftTodo(
    count($sync['tasks']) === 1
        && $sync['tasks'][0]['title'] === 'Versicherung prüfen'
        && $sync['tasks'][0]['dueDateTime']['timeZone'] === 'Europe/Berlin'
        && str_contains($sync['deltaLink'], '$deltatoken=abc'),
    'Native tasks and their delta link must be mapped without calendar title markers.'
);

$created = $provider->createTask('list-1', [
    'title' => 'Neue Aufgabe',
    'description' => 'Beschreibung',
    'dueDateTime' => ['dateTime' => '2026-09-21T00:00:00', 'timeZone' => 'Europe/Berlin']
]);
assertMicrosoftTodo($created['id'] === 'task-2', 'Created tasks must return their native Graph identity.');
$createPayload = json_decode($client->requests[2]['body'], true, 512, JSON_THROW_ON_ERROR);
assertMicrosoftTodo(
    $createPayload['title'] === 'Neue Aufgabe'
        && $createPayload['body']['content'] === 'Beschreibung'
        && $createPayload['dueDateTime']['timeZone'] === 'Europe/Berlin',
    'Task creation must use native To Do fields.'
);

$updated = $provider->updateTask('list-1', 'task-2', ['status' => 'completed']);
assertMicrosoftTodo($updated['status'] === 'completed', 'Task completion must use the native status field.');
assertMicrosoftTodo($provider->deleteTask('list-1', 'task-2'), 'Task deletion must accept Graph 204 responses.');

try {
    $provider->getTasks('list-1', 'https://example.com/stolen-token');
    throw new RuntimeException('Untrusted delta links must be rejected.');
} catch (MicrosoftTodoProviderException $exception) {
    assertMicrosoftTodo(
        str_contains($exception->getMessage(), 'continuation URL'),
        'Delta links must stay on graph.microsoft.com.'
    );
}

fwrite(STDOUT, "Microsoft To Do provider tests passed.\n");
