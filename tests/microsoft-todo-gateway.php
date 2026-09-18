<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpOriginPolicyInterface;
use IPSKalender\CalendarHttpResponse;

require_once __DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php';

final class MicrosoftTodoGatewayHttpClient implements CalendarHttpClientInterface
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
        return array_shift($this->responses) ?? throw new RuntimeException('Unexpected To Do gateway request.');
    }
}

final class MicrosoftTodoGatewayHarness
{
    use KalenderKontoChildGatewayTrait;

    private const PROVIDER_MICROSOFT = 3;

    public function __construct(private readonly CalendarHttpClientInterface $httpClient)
    {
    }

    public function ReadPropertyInteger(string $name): int
    {
        return $name === 'Provider' ? self::PROVIDER_MICROSOFT : 0;
    }

    private function createTrustedCloudHttpClient(
        CalendarHttpOriginPolicyInterface $originPolicy
    ): CalendarHttpClientInterface {
        return $this->httpClient;
    }

    private function getMicrosoftAccessToken(): string
    {
        return 'access-token';
    }
}

function todoGatewayResponse(int $status, array $data = []): CalendarHttpResponse
{
    return new CalendarHttpResponse(
        $status,
        [],
        $status === 204 ? '' : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'https://graph.microsoft.com/v1.0/me/todo/lists/list-1/tasks'
    );
}

function assertTodoGateway(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$client = new MicrosoftTodoGatewayHttpClient([
    todoGatewayResponse(200, [
        'value' => [[
            'id' => 'task-1', 'title' => 'Native task', 'status' => 'notStarted'
        ]],
        '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/todo/lists/list-1/tasks/delta?$deltatoken=next'
    ]),
    todoGatewayResponse(201, ['id' => 'task-2', 'title' => 'Created task', 'status' => 'notStarted']),
    todoGatewayResponse(200, ['id' => 'task-2', 'title' => 'Created task', 'status' => 'completed']),
    todoGatewayResponse(204)
]);
$gateway = new MicrosoftTodoGatewayHarness($client);

$syncMethod = new ReflectionMethod(MicrosoftTodoGatewayHarness::class, 'synchronizeTasksForChild');
$sync = $syncMethod->invoke($gateway, ['TaskListID' => 'list-1']);
assertTodoGateway(
    count($sync['tasks']) === 1
        && $sync['tasks'][0]['title'] === 'Native task'
        && str_contains($sync['deltaLink'], '$deltatoken=next'),
    'The account gateway must expose native tasks and their delta link.'
);

$createMethod = new ReflectionMethod(MicrosoftTodoGatewayHarness::class, 'createTaskForChild');
$created = $createMethod->invoke($gateway, [
    'TaskListID' => 'list-1',
    'Task'       => ['title' => 'Created task']
]);
assertTodoGateway($created['id'] === 'task-2', 'The account gateway must create native tasks.');

$updateMethod = new ReflectionMethod(MicrosoftTodoGatewayHarness::class, 'updateTaskForChild');
$updated = $updateMethod->invoke($gateway, [
    'TaskListID' => 'list-1',
    'TaskID'     => 'task-2',
    'Changes'    => ['status' => 'completed']
]);
assertTodoGateway($updated['status'] === 'completed', 'The account gateway must update native tasks.');

$deleteMethod = new ReflectionMethod(MicrosoftTodoGatewayHarness::class, 'deleteTaskForChild');
assertTodoGateway(
    $deleteMethod->invoke($gateway, ['TaskListID' => 'list-1', 'TaskID' => 'task-2']) === true,
    'The account gateway must delete native tasks.'
);

assertTodoGateway(
    $client->requests[0]['method'] === 'GET'
        && str_contains($client->requests[0]['url'], '/me/todo/lists/list-1/tasks/delta')
        && $client->requests[1]['method'] === 'POST'
        && $client->requests[2]['method'] === 'PATCH'
        && $client->requests[3]['method'] === 'DELETE',
    'The account gateway must route task operations to the Microsoft To Do endpoints.'
);

fwrite(STDOUT, "Microsoft To Do account gateway tests passed.\n");
