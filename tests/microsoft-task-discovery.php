<?php

declare(strict_types=1);

use IPSKalender\MicrosoftTodoProviderException;

const IS_ACTIVE = 102;
const IS_INACTIVE = 104;

class IPSModuleStrict
{
}

require_once __DIR__ . '/../Kalender Konto/module.php';

// Execute the actual account orchestration with isolated network/runtime boundaries.
class TaskDiscoveryRuntime
{
    protected const PROVIDER_LOCAL = 5;
    protected const PROVIDER_MICROSOFT = 3;
    protected const PROVIDER_GOOGLE = 2;
    protected const PROVIDER_ICS = 4;
    protected const STATUS_CONFIGURATION_MISSING = 201;
    protected const MICROSOFT_OAUTH_IDENTIFIER = 'test';
    protected const DATA_ID_TO_CHILD = 'test';

    public array $attributes = [];
    public int $taskCalls = 0;
    public int $taskError = 0;
    public int $responseStatus = 0;
    public bool $active = true;
    public bool $listsReadyAtNotification = false;

    public function __call(string $name, array $args): mixed
    {
        if (str_starts_with($name, 'WriteAttribute')) {
            $this->attributes[$args[0]] = $args[1];
            return null;
        }
        if (str_starts_with($name, 'ReadAttribute')) {
            return $this->attributes[$args[0]] ?? '';
        }
        return match ($name) {
            'ReadPropertyInteger'                                                => $args[0] === 'Provider' ? 3 : 30,
            'ReadPropertyBoolean'                                                => $this->active,
            'validateConfiguration'                                              => '',
            'getProviderName'                                                    => 'Microsoft 365',
            'createProvider', 'microsoftTodoProvider', 'createSymconOAuthClient' => new class($this) {
                public function __construct(private TaskDiscoveryRuntime $runtime)
                {
                }

                public function __call(string $method, array $arguments): mixed
                {
                    return $method === 'getTaskLists'
                        ? $this->runtime->fetchTaskLists()
                        : $this->runtime->__call($method, $arguments);
                }
            },
            'getCalendars'              => [['id' => 'calendar-1', 'owner' => 'test@example.invalid', 'primary' => true]],
            'testConnection'            => ['success' => true],
            'exchangeAuthorizationCode' => ['accessToken' => 'test', 'refreshToken' => 'test', 'expiresAt' => time() + 3600],
            'Translate'                 => $args[0],
            'EncodeDataFlowMessage'     => '{}',
            default                     => null
        };
    }

    public function fetchTaskLists(): array
    {
        ++$this->taskCalls;
        if ($this->taskError !== 0) {
            throw new MicrosoftTodoProviderException('Task discovery failed', $this->taskError);
        }
        return [['id' => 'list-1', 'name' => 'Tasks']];
    }

    public function SendDataToChildren(string $data): void
    {
        $this->listsReadyAtNotification = (bool) ($this->attributes['MicrosoftTasksAvailable'] ?? false);
    }

    public function SendHtmlTextResponse(int $status, string $message): void
    {
        $this->responseStatus = $status;
    }

    public function handleProviderError(Throwable $exception): string
    {
        return $this->attributes['LastError'] = $exception->getMessage();
    }
}

$methods = '';
foreach (['discoverCalendars', 'synchronizeMicrosoftTaskLists', 'Synchronize', 'TestConnection', 'GetTaskLists', 'ClearCache', 'processMicrosoftOAuthData', 'storeMicrosoftTokens'] as $name) {
    $method = new ReflectionMethod(CalendarAccount::class, $name);
    $lines = file($method->getFileName());
    $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    $methods .= preg_replace('/\b(private|protected) function/', 'public function', $body, 1) . "\n";
}
eval('use IPSKalender\\MicrosoftTodoProviderException; class TaskDiscoveryAccount extends TaskDiscoveryRuntime {' . $methods . '}');

function checkDiscovery(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['discoverCalendars', 'Synchronize', 'TestConnection', 'processMicrosoftOAuthData'] as $entry) {
    $account = new TaskDiscoveryAccount();
    $account->$entry(...($entry === 'processMicrosoftOAuthData' ? [['code' => 'test']] : []));
    checkDiscovery($account->taskCalls === 1, $entry . ' must load task lists exactly once on first use.');
    checkDiscovery(($account->attributes['MicrosoftTasksAvailable'] ?? false) === true, $entry . ' must expose tasks immediately.');
    checkDiscovery(count(json_decode($account->GetTaskLists(), true)) === 1, 'Task list cache must be populated.');
    if (in_array($entry, ['Synchronize', 'processMicrosoftOAuthData'], true)) {
        checkDiscovery($account->listsReadyAtNotification, 'Children must be notified only after task discovery.');
    }
}
foreach ([401, 403] as $status) {
    $account = new TaskDiscoveryAccount();
    $account->taskError = $status;
    checkDiscovery($account->Synchronize(), 'Missing task access must not disable calendars.');
    checkDiscovery($account->attributes['MicrosoftTasksAvailable'] === false, 'Denied task access must remain unavailable.');
}
$account = new TaskDiscoveryAccount();
$account->taskError = 503;
$account->processMicrosoftOAuthData(['code' => 'test']);
checkDiscovery($account->responseStatus === 200, 'Discovery failure must not invalidate successful OAuth.');
checkDiscovery($account->attributes['LastError'] !== '', 'Discovery failure must remain observable.');
$account = new TaskDiscoveryAccount();
$account->active = false;
$account->processMicrosoftOAuthData(['code' => 'test']);
checkDiscovery($account->taskCalls === 0, 'Inactive accounts must not synchronize automatically.');
fwrite(STDOUT, "Microsoft task first-discovery tests passed.\n");
