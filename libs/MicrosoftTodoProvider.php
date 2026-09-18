<?php

declare(strict_types=1);

namespace IPSKalender;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/CalendarHttpClient.php';
require_once __DIR__ . '/MicrosoftTodoTaskProjection.php';

final class MicrosoftTodoProviderException extends RuntimeException
{
    /**
     * Creates a Microsoft To Do exception with optional Graph error metadata.
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $errorCode = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

/**
 * Provides native Microsoft To Do access through Microsoft Graph v1.0.
 */
final class MicrosoftTodoProvider
{
    private const API_URL = 'https://graph.microsoft.com/v1.0';
    private const MAX_PAGES = 100;
    private const MAX_ITEMS = 100_000;

    /**
     * Creates a Microsoft To Do provider using a delegated OAuth access token.
     */
    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly string $accessToken
    ) {
        if (trim($accessToken) === '') {
            throw new MicrosoftTodoProviderException('Microsoft 365 is not connected yet.', 401);
        }
    }

    /** @return list<array<string, mixed>> */
    public function getTaskLists(): array
    {
        $lists = [];
        $url = self::API_URL . '/me/todo/lists?$top=100';
        $seen = [];

        while ($url !== '') {
            $this->assertNextLink($url, $seen);
            $data = $this->requestJsonUrl('GET', $url);
            foreach (($data['value'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = trim((string) ($item['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $lists[] = [
                    'id'            => $id,
                    'name'          => trim((string) ($item['displayName'] ?? $id)),
                    'wellknownName' => trim((string) ($item['wellknownListName'] ?? 'none')),
                    'shared'        => (bool) ($item['isShared'] ?? false),
                    'owner'         => (bool) ($item['isOwner'] ?? true),
                    'etag'          => trim((string) ($item['@odata.etag'] ?? ''))
                ];
                if (count($lists) > self::MAX_ITEMS) {
                    throw new MicrosoftTodoProviderException('Microsoft To Do returned too many task lists.');
                }
            }
            $url = trim((string) ($data['@odata.nextLink'] ?? ''));
        }

        return $lists;
    }

    /**
     * @return array{tasks:list<array<string, mixed>>, deltaLink:string, fullSnapshot:bool}
     */
    public function getTasks(string $listId, string $deltaLink = ''): array
    {
        $listId = $this->requiredId($listId, 'task list');
        $url = trim($deltaLink);
        if ($url !== '' && !$this->isDeltaQueryUrl($url)) {
            return $this->readTasks($listId, $url, true, $this->taskCollectionUrl($listId));
        }
        if ($url === '') {
            $url = $this->taskDeltaUrl($listId);
        }

        try {
            return $this->readTasks($listId, $url, false);
        } catch (MicrosoftTodoProviderException $exception) {
            if (!$this->isUnsupportedDeltaQuery($exception)) {
                throw $exception;
            }

            $snapshotUrl = $this->taskCollectionUrl($listId);
            return $this->readTasks($listId, $snapshotUrl, true, $snapshotUrl);
        }
    }

    /** @param array<string, mixed> $task @return array<string, mixed> */
    public function createTask(string $listId, array $task): array
    {
        $listId = $this->requiredId($listId, 'task list');
        $data = $this->requestJson(
            'POST',
            '/me/todo/lists/' . rawurlencode($listId) . '/tasks',
            $this->buildTaskPayload($task, true),
            [201]
        );

        return $this->mapTask($listId, $data)
            ?? throw new MicrosoftTodoProviderException('Microsoft To Do returned an invalid created task.');
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function updateTask(string $listId, string $taskId, array $changes): array
    {
        $listId = $this->requiredId($listId, 'task list');
        $taskId = $this->requiredId($taskId, 'task');
        $payload = $this->buildTaskPayload($changes, false);
        if ($payload === []) {
            throw new InvalidArgumentException('The task update is empty.');
        }
        $path = '/me/todo/lists/' . rawurlencode($listId) . '/tasks/' . rawurlencode($taskId);
        if (is_array($payload['dueDateTime'] ?? null)) {
            // The cache can be older than a completion performed in Microsoft's UI.
            $current = $this->requestJson('GET', $path, null, [200]);
            $requestedDate = MicrosoftTodoTaskProjection::localDateTime($payload['dueDateTime']);
            $currentDate = MicrosoftTodoTaskProjection::localDateTime(
                is_array($current['dueDateTime'] ?? null) ? $current['dueDateTime'] : []
            );
            if ($requestedDate === null) {
                throw new InvalidArgumentException('The Microsoft To Do due date is invalid.');
            }
            if ($requestedDate->format('Y-m-d') === $currentDate?->format('Y-m-d')) {
                unset($payload['dueDateTime']);
            } elseif (is_array($current['recurrence'] ?? null)
                && ($current['status'] ?? '') !== 'completed') {
                // Writing dueDateTime on a recurring task can create another native task.
                // Microsoft To Do instead realigns the active recurrence when its date moves.
                $payload['recurrence'] = $this->moveRecurrence($current['recurrence'], $requestedDate, $currentDate);
                unset($payload['dueDateTime']);
            }
            if ($payload === []) {
                return $this->mapTask($listId, $current)
                    ?? throw new MicrosoftTodoProviderException('Microsoft To Do returned an invalid task.');
            }
        }
        // Finish a moved task only after the recurrence write has succeeded. A single
        // request with both properties does not specify their processing order.
        $completeAfterMove = isset($payload['recurrence']) && ($payload['status'] ?? '') === 'completed';
        if ($completeAfterMove) {
            unset($payload['status']);
        }
        $data = $this->requestJson(
            'PATCH',
            $path,
            $payload,
            [200]
        );
        if ($completeAfterMove) {
            $data = $this->requestJson('PATCH', $path, ['status' => 'completed'], [200]);
        }

        return $this->mapTask($listId, $data)
            ?? throw new MicrosoftTodoProviderException('Microsoft To Do returned an invalid updated task.');
    }

    /**
     * Deletes one native task from a Microsoft To Do list.
     */
    public function deleteTask(string $listId, string $taskId): bool
    {
        $listId = $this->requiredId($listId, 'task list');
        $taskId = $this->requiredId($taskId, 'task');
        $this->requestJson(
            'DELETE',
            '/me/todo/lists/' . rawurlencode($listId) . '/tasks/' . rawurlencode($taskId),
            null,
            [204]
        );
        return true;
    }

    /** @param array<string, mixed> $recurrence @return array<string, mixed> */
    private function moveRecurrence(
        array $recurrence,
        \DateTimeImmutable $date,
        ?\DateTimeImmutable $previousDate
    ): array {
        $pattern = $recurrence['pattern'] ?? [];
        $weekday = strtolower($date->format('l'));
        switch ($pattern['type'] ?? '') {
            case 'daily':
                break;
            case 'weekly':
                $days = $pattern['daysOfWeek'] ?? [];
                if (count($days) > 1 && $previousDate !== null) {
                    $weekdays = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                    $offset = (int) $date->format('w') - (int) $previousDate->format('w');
                    $pattern['daysOfWeek'] = array_map(static function (string $day) use ($weekdays, $offset): string
                    {
                        $index = array_search(strtolower($day), $weekdays, true);
                        if ($index === false) {
                            throw new InvalidArgumentException('Invalid Microsoft To Do recurrence weekday.');
                        }
                        return $weekdays[($index + $offset + 7) % 7];
                    }, $days);
                } else {
                    $pattern['daysOfWeek'] = [$weekday];
                }
                break;
            case 'absoluteMonthly':
            case 'absoluteYearly':
                $pattern['dayOfMonth'] = (int) $date->format('j');
                if ($pattern['type'] === 'absoluteYearly') {
                    $pattern['month'] = (int) $date->format('n');
                }
                break;
            case 'relativeMonthly':
            case 'relativeYearly':
                $pattern['daysOfWeek'] = [$weekday];
                $ordinal = intdiv((int) $date->format('j') - 1, 7);
                $pattern['index'] = ['first', 'second', 'third', 'fourth', 'last'][$ordinal];
                if (($recurrence['pattern']['index'] ?? '') === 'last'
                    && $date->modify('+7 days')->format('m') !== $date->format('m')) {
                    $pattern['index'] = 'last';
                }
                if ($pattern['type'] === 'relativeYearly') {
                    $pattern['month'] = (int) $date->format('n');
                }
                break;
            default:
                throw new InvalidArgumentException('Invalid Microsoft To Do recurrence pattern.');
        }
        // To Do already decrements numberOfOccurrences after completion while
        // retaining the original range start. Preserve this remaining count;
        // calendar-series occurrence arithmetic would subtract completed tasks twice.
        $recurrence['pattern'] = $pattern;
        $recurrence['range']['startDate'] = $date->format('Y-m-d');
        return $recurrence;
    }

    /**
     * @return array{tasks:list<array<string, mixed>>, deltaLink:string, fullSnapshot:bool}
     */
    private function readTasks(
        string $listId,
        string $url,
        bool $fullSnapshot,
        string $nextSynchronizationUrl = ''
    ): array {
        $tasks = [];
        $seen = [];
        $finalDeltaLink = '';
        while ($url !== '') {
            $this->assertNextLink($url, $seen);
            $data = $this->requestJsonUrl('GET', $url);
            foreach (($data['value'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $mapped = $this->mapTask($listId, $item);
                if ($mapped !== null) {
                    $tasks[] = $mapped;
                }
                if (count($tasks) > self::MAX_ITEMS) {
                    throw new MicrosoftTodoProviderException('Microsoft To Do returned too many tasks.');
                }
            }
            $finalDeltaLink = trim((string) ($data['@odata.deltaLink'] ?? $finalDeltaLink));
            $url = trim((string) ($data['@odata.nextLink'] ?? ''));
        }

        return [
            'tasks'        => $tasks,
            'deltaLink'    => $fullSnapshot ? $nextSynchronizationUrl : $finalDeltaLink,
            'fullSnapshot' => $fullSnapshot
        ];
    }

    /** @param array<string, mixed> $item @return array<string, mixed>|null */
    private function mapTask(string $listId, array $item): ?array
    {
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        if (isset($item['@removed'])) {
            return ['id' => $id, 'listId' => $listId, 'deleted' => true];
        }
        $body = is_array($item['body'] ?? null) ? $item['body'] : [];

        return [
            'id'                    => $id,
            'listId'                => $listId,
            'title'                 => trim((string) ($item['title'] ?? '')),
            'description'           => (string) ($body['content'] ?? ''),
            'status'                => trim((string) ($item['status'] ?? 'notStarted')),
            'importance'            => trim((string) ($item['importance'] ?? 'normal')),
            'categories'            => array_values(array_map('strval', is_array($item['categories'] ?? null) ? $item['categories'] : [])),
            'startDateTime'         => $this->mapDateTime($item['startDateTime'] ?? null),
            'dueDateTime'           => $this->mapDateTime($item['dueDateTime'] ?? null),
            'completedDateTime'     => $this->mapDateTime($item['completedDateTime'] ?? null),
            'reminderDateTime'      => $this->mapDateTime($item['reminderDateTime'] ?? null),
            'reminder'              => (bool) ($item['isReminderOn'] ?? false),
            'recurrence'            => is_array($item['recurrence'] ?? null) ? $item['recurrence'] : null,
            'created'               => trim((string) ($item['createdDateTime'] ?? '')),
            'lastModified'          => trim((string) ($item['lastModifiedDateTime'] ?? '')),
            'etag'                  => trim((string) ($item['@odata.etag'] ?? '')),
            'deleted'               => false
        ];
    }

    /** @param array<string, mixed> $task @return array<string, mixed> */
    private function buildTaskPayload(array $task, bool $creating): array
    {
        $payload = [];
        if ($creating || array_key_exists('title', $task)) {
            $title = trim((string) ($task['title'] ?? ''));
            if ($title === '') {
                throw new InvalidArgumentException('The task title is missing.');
            }
            $payload['title'] = $title;
        }
        if (array_key_exists('description', $task)) {
            $payload['body'] = ['contentType' => 'text', 'content' => (string) $task['description']];
        }
        foreach (['status', 'importance'] as $key) {
            if (array_key_exists($key, $task)) {
                $payload[$key] = trim((string) $task[$key]);
            }
        }
        if (array_key_exists('categories', $task)) {
            if (!is_array($task['categories'])) {
                throw new InvalidArgumentException('The task categories are invalid.');
            }
            $payload['categories'] = array_values(array_map('strval', $task['categories']));
        }
        foreach (['startDateTime', 'dueDateTime', 'completedDateTime', 'reminderDateTime'] as $key) {
            if (array_key_exists($key, $task)) {
                $payload[$key] = $this->taskDateTime($task[$key]);
            }
        }
        if (array_key_exists('reminder', $task)) {
            $payload['isReminderOn'] = (bool) $task['reminder'];
        }
        if (array_key_exists('recurrence', $task)) {
            if ($task['recurrence'] !== null && !is_array($task['recurrence'])) {
                throw new InvalidArgumentException('The task recurrence is invalid.');
            }
            $payload['recurrence'] = $task['recurrence'];
        }
        return $payload;
    }

    /** @return array{dateTime:string,timeZone:string}|null */
    private function mapDateTime(mixed $value): ?array
    {
        if (!is_array($value) || trim((string) ($value['dateTime'] ?? '')) === '') {
            return null;
        }
        return [
            'dateTime' => trim((string) $value['dateTime']),
            'timeZone' => trim((string) ($value['timeZone'] ?? 'UTC')) ?: 'UTC'
        ];
    }

    /** @return array{dateTime:string,timeZone:string}|null */
    private function taskDateTime(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('The task date and time are invalid.');
        }
        $mapped = $this->mapDateTime($value);
        if ($mapped === null) {
            throw new InvalidArgumentException('The task date and time are invalid.');
        }
        return $mapped;
    }

    private function requiredId(string $id, string $type): string
    {
        $id = trim($id);
        if ($id === '') {
            throw new InvalidArgumentException('The Microsoft ' . $type . ' ID is missing.');
        }
        return $id;
    }

    private function taskCollectionUrl(string $listId): string
    {
        return self::API_URL . '/me/todo/lists/' . rawurlencode($listId) . '/tasks?$top=100';
    }

    private function taskDeltaUrl(string $listId): string
    {
        return self::API_URL . '/me/todo/lists/' . rawurlencode($listId) . '/tasks/delta?$top=100';
    }

    private function isDeltaQueryUrl(string $url): bool
    {
        return str_ends_with((string) parse_url($url, PHP_URL_PATH), '/tasks/delta');
    }

    private function isUnsupportedDeltaQuery(MicrosoftTodoProviderException $exception): bool
    {
        return $exception->httpStatus === 400
            && str_contains(strtolower($exception->getMessage()), 'delta query is not supported');
    }

    /** @param array<string, bool> $seen */
    private function assertNextLink(string $url, array &$seen): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'graph.microsoft.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new MicrosoftTodoProviderException('Microsoft To Do returned an invalid continuation URL.');
        }
        if (isset($seen[$url]) || count($seen) >= self::MAX_PAGES) {
            throw new MicrosoftTodoProviderException('Microsoft To Do pagination did not make progress.');
        }
        $seen[$url] = true;
    }

    /** @param array<string, mixed>|null $body @param list<int> $expected */
    private function requestJson(string $method, string $path, ?array $body, array $expected): array
    {
        return $this->requestJsonUrl($method, self::API_URL . $path, $body, $expected);
    }

    /** @param array<string, mixed>|null $body @param list<int> $expected */
    private function requestJsonUrl(
        string $method,
        string $url,
        ?array $body = null,
        array $expected = [200]
    ): array {
        $headers = [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Accept'        => 'application/json'
        ];
        $encodedBody = '';
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $response = $this->httpClient->request($method, $url, $headers, $encodedBody);
        if (!in_array($response->statusCode, $expected, true)) {
            $errorCode = '';
            $message = 'Microsoft To Do request failed.';
            if ($response->body !== '') {
                try {
                    $error = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($error['error'] ?? null)) {
                        $errorCode = trim((string) ($error['error']['code'] ?? ''));
                        $message = trim((string) ($error['error']['message'] ?? $message));
                    }
                } catch (JsonException) {
                }
            }
            throw new MicrosoftTodoProviderException($message, $response->statusCode, $errorCode);
        }
        if ($response->body === '') {
            return [];
        }
        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MicrosoftTodoProviderException('Microsoft To Do returned invalid JSON.', $response->statusCode, '', $exception);
        }
        if (!is_array($data)) {
            throw new MicrosoftTodoProviderException('Microsoft To Do returned an invalid response.', $response->statusCode);
        }
        return $data;
    }
}
