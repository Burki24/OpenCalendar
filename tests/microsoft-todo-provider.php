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
            'id'           => 'task-1',
            '@odata.etag'  => 'etag-1',
            'title'        => 'Versicherung prüfen',
            'status'       => 'notStarted',
            'importance'   => 'high',
            'body'         => ['contentType' => 'text', 'content' => 'Unterlagen suchen'],
            'dueDateTime'  => ['dateTime' => '2026-09-20T00:00:00.0000000', 'timeZone' => 'Europe/Berlin'],
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
        && ($sync['fullSnapshot'] ?? true) === false
        && str_contains($sync['deltaLink'], '$deltatoken=abc'),
    'Native tasks and their delta link must be mapped without calendar title markers.'
);

$fallbackClient = new MicrosoftTodoTestHttpClient([
    todoResponse(400, [
        'error' => [
            'code'    => 'ErrorInvalidRequest',
            'message' => 'Invalid request. Delta query is not supported by this resource.'
        ]
    ]),
    todoResponse(200, [
        'value' => [[
            'id' => 'fallback-1', 'title' => 'First fallback task', 'status' => 'notStarted'
        ]],
        '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/todo/lists/list-1/tasks?$skiptoken=next'
    ]),
    todoResponse(200, [
        'value' => [[
            'id' => 'fallback-2', 'title' => 'Second fallback task', 'status' => 'notStarted'
        ]]
    ]),
    todoResponse(200, [
        'value' => [[
            'id' => 'fallback-1', 'title' => 'First fallback task', 'status' => 'notStarted'
        ]]
    ])
]);
$fallbackProvider = new MicrosoftTodoProvider($fallbackClient, 'access-token');
$fallbackSync = $fallbackProvider->getTasks('list-1');
assertMicrosoftTodo(
    array_column($fallbackSync['tasks'], 'id') === ['fallback-1', 'fallback-2']
        && ($fallbackSync['fullSnapshot'] ?? false) === true
        && $fallbackSync['deltaLink'] === 'https://graph.microsoft.com/v1.0/me/todo/lists/list-1/tasks?$top=100'
        && str_contains($fallbackClient->requests[0]['url'], '/tasks/delta')
        && $fallbackClient->requests[1]['url'] === $fallbackSync['deltaLink']
        && str_contains($fallbackClient->requests[2]['url'], '$skiptoken=next'),
    'An unsupported delta query must fall back to a complete paginated task-list request.'
);
$repeatedFallbackSync = $fallbackProvider->getTasks('list-1', $fallbackSync['deltaLink']);
assertMicrosoftTodo(
    array_column($repeatedFallbackSync['tasks'], 'id') === ['fallback-1']
        && ($repeatedFallbackSync['fullSnapshot'] ?? false) === true
        && !str_contains($fallbackClient->requests[3]['url'], '/delta'),
    'A saved full-snapshot cursor must avoid retrying an unsupported delta query on every synchronization.'
);

$created = $provider->createTask('list-1', [
    'title'       => 'Neue Aufgabe',
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

$originalTimezone = date_default_timezone_get();
date_default_timezone_set('Europe/Berlin');
foreach ([
    ['type' => 'daily', 'interval' => 1],
    ['type' => 'weekly', 'interval' => 2, 'daysOfWeek' => ['tuesday'], 'firstDayOfWeek' => 'sunday'],
    ['type' => 'absoluteMonthly', 'interval' => 1, 'dayOfMonth' => 22],
    ['type' => 'absoluteYearly', 'interval' => 1, 'month' => 9, 'dayOfMonth' => 22]
] as $pattern) {
    $before = [
        'id' => 'series', 'title' => 'Series', 'status' => 'notStarted',
        'dueDateTime' => ['dateTime' => '2026-09-21T22:00:00.0000000', 'timeZone' => 'UTC'],
        'recurrence' => [
            'pattern' => $pattern,
            'range' => ['type' => 'noEnd', 'startDate' => '2026-09-22', 'recurrenceTimeZone' => 'UTC']
        ]
    ];
    $moveClient = new MicrosoftTodoTestHttpClient([todoResponse(200, $before), todoResponse(200, $before)]);
    (new MicrosoftTodoProvider($moveClient, 'access-token'))->updateTask('list-1', 'series', [
        'title' => 'Renamed series',
        'dueDateTime' => ['dateTime' => '2026-09-24T00:00:00', 'timeZone' => 'Europe/Berlin']
    ]);
    assertMicrosoftTodo(count($moveClient->requests) === 2 && $moveClient->requests[0]['method'] === 'GET',
        'A due-date edit must read the current server recurrence before writing.');
    $move = json_decode($moveClient->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
    assertMicrosoftTodo(!array_key_exists('dueDateTime', $move)
        && $move['title'] === 'Renamed series'
        && $move['recurrence']['range']['startDate'] === '2026-09-24'
        && $move['recurrence']['pattern']['interval'] === $pattern['interval'],
        'Moving a native series must re-anchor recurrence without a duplicating dueDateTime PATCH.');
    if ($pattern['type'] === 'weekly') {
        assertMicrosoftTodo($move['recurrence']['pattern']['daysOfWeek'] === ['thursday']
            && $move['recurrence']['pattern']['firstDayOfWeek'] === 'sunday',
            'Moving a weekly task must align its weekday, preserving interval and week start.');
    }
    if (str_starts_with($pattern['type'], 'absolute')) {
        assertMicrosoftTodo($move['recurrence']['pattern']['dayOfMonth'] === 24,
            'Absolute recurrence must follow the new day of month.');
    }
    $sameClient = new MicrosoftTodoTestHttpClient([todoResponse(200, $before), todoResponse(200, $before)]);
    (new MicrosoftTodoProvider($sameClient, 'access-token'))->updateTask('list-1', 'series', [
        'title' => 'Only title',
        'dueDateTime' => ['dateTime' => '2026-09-22T00:00:00', 'timeZone' => 'Europe/Berlin']
    ]);
    $same = json_decode($sameClient->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
    assertMicrosoftTodo($same === ['title' => 'Only title'],
        'An unchanged local calendar day must not rewrite due date or recurrence (UTC date differs).');
}
$daily = [
    'id' => 'series', 'title' => 'Series', 'status' => 'notStarted',
    'dueDateTime' => ['dateTime' => '2026-09-23T22:00:00.0000000', 'timeZone' => 'UTC'],
    'recurrence' => [
        'pattern' => ['type' => 'daily', 'interval' => 1],
        'range' => ['type' => 'numbered', 'startDate' => '2026-09-22', 'numberOfOccurrences' => 5]
    ]
];
$sequenceClient = new MicrosoftTodoTestHttpClient([
    todoResponse(200, $daily), todoResponse(200, $daily), todoResponse(200, $daily)
]);
(new MicrosoftTodoProvider($sequenceClient, 'access-token'))->updateTask('list-1', 'series', [
    'dueDateTime' => ['dateTime' => '2026-09-26T00:00:00', 'timeZone' => 'Europe/Berlin'],
    'status' => 'completed'
]);
$sequenceMove = json_decode($sequenceClient->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
assertMicrosoftTodo(count($sequenceClient->requests) === 3
    && !isset($sequenceMove['status'])
    && $sequenceMove['recurrence']['range']['numberOfOccurrences'] === 3
    && json_decode($sequenceClient->requests[2]['body'], true) === ['status' => 'completed'],
    'Move before completing, and retain only the remaining occurrences of a bounded series.');

foreach (['single', 'completed'] as $kind) {
    $task = $daily;
    if ($kind === 'single') {
        $task['recurrence'] = null;
    } else {
        $task['status'] = 'completed';
    }
    $plainClient = new MicrosoftTodoTestHttpClient([todoResponse(200, $task), todoResponse(200, $task)]);
    $due = ['dateTime' => '2026-09-26T00:00:00', 'timeZone' => 'Europe/Berlin'];
    (new MicrosoftTodoProvider($plainClient, 'access-token'))->updateTask('list-1', 'series', ['dueDateTime' => $due]);
    assertMicrosoftTodo(json_decode($plainClient->requests[1]['body'], true) === ['dueDateTime' => $due],
        'A single task or completed history entry must not re-anchor the active recurrence.');
}
$noopClient = new MicrosoftTodoTestHttpClient([todoResponse(200, $daily)]);
(new MicrosoftTodoProvider($noopClient, 'access-token'))->updateTask('list-1', 'series', [
    'dueDateTime' => ['dateTime' => '2026-09-24T00:00:00', 'timeZone' => 'Europe/Berlin']
]);
assertMicrosoftTodo(count($noopClient->requests) === 1, 'A date-only no-op must not issue an empty PATCH.');

$failedReadClient = new MicrosoftTodoTestHttpClient([todoResponse(404, ['error' => ['message' => 'Task missing']])]);
try {
    (new MicrosoftTodoProvider($failedReadClient, 'access-token'))->updateTask('list-1', 'series', [
        'dueDateTime' => ['dateTime' => '2026-09-26T00:00:00', 'timeZone' => 'Europe/Berlin']
    ]);
    throw new RuntimeException('A failed preflight must not continue to PATCH.');
} catch (MicrosoftTodoProviderException $exception) {
    assertMicrosoftTodo($exception->httpStatus === 404 && count($failedReadClient->requests) === 1,
        'Missing tasks must stop the update without another write.');
}
date_default_timezone_set($originalTimezone);

fwrite(STDOUT, "Microsoft To Do provider tests passed.\n");
