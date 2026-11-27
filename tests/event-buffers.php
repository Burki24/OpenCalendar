<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once dirname(__DIR__) . '/Kalender/module.php';

final class BufferCalendar extends Calendar
{
    public array $attributes = [];
    public array $events = [];
    public int $nextId = 1;
    public int $createCount = 0;
    public int $failCreateAt = 0;
    public bool $failLookups = false;
    public array $requests = [];
    public array $transferItems = [];

    protected function SendSafeDebug(string $message, mixed $data, int $maxLength = 16384, array $additionalSensitiveKeys = []): void
    {
    }

    protected function HasActiveParent(): bool
    {
        return true;
    }

    protected function ReadPropertyBoolean(string $Name): bool
    {
        return $Name !== 'LocalCalendar';
    }

    protected function ReadPropertyInteger(string $Name): int
    {
        return 30;
    }

    protected function ReadPropertyString(string $Name): string
    {
        return $Name === 'CalendarID' ? 'buffer-test' : '';
    }

    protected function ReadAttributeBoolean(string $Name): bool
    {
        return $Name === 'RuntimeReady';
    }

    protected function ReadAttributeInteger(string $Name): int
    {
        return (int) ($this->attributes[$Name] ?? 0);
    }

    protected function ReadAttributeString(string $Name): string
    {
        return (string) ($this->attributes[$Name] ?? '[]');
    }

    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        $this->attributes[$Name] = $Value;
        return true;
    }

    protected function WriteAttributeInteger(string $Name, int $Value): bool
    {
        $this->attributes[$Name] = $Value;
        return true;
    }

    protected function SetValue(string $Ident, mixed $Value): bool
    {
        return true;
    }

    protected function SetStatus(int $Status): bool
    {
        return true;
    }

    protected function SendDataToParent(string $Data): string
    {
        $request = json_decode($Data, true, 512, JSON_THROW_ON_ERROR);
        $this->requests[] = $request;
        $operation = $request['Operation'];
        if ($operation === 'CreateEvent') {
            ++$this->createCount;
            if ($this->failCreateAt === $this->createCount) {
                return json_encode(['Success' => false, 'Error' => 'Create rejected'], JSON_THROW_ON_ERROR);
            }
            $id = 'buffer-' . $this->nextId++;
            $event = array_merge($request['Event'], [
                'uid'  => $id, 'resourceUrl' => $id, 'eventReference' => $id,
                'etag' => 'v1', 'recurring' => false
            ]);
            $this->events[$id] = $event;
            $payload = $event;
        } elseif ($operation === 'GetEventAfterWrite' || $operation === 'GetEventForEdit') {
            $payload = $this->events[$request['ResourceURL']] ?? [];
        } elseif ($operation === 'CheckLinkedBufferEvent') {
            if ($this->failLookups) {
                return json_encode(['Success' => false, 'Error' => 'Temporary provider failure', 'ErrorType' => 'unavailable'], JSON_THROW_ON_ERROR);
            }
            $payload = ['known' => true, 'event' => $this->events[$request['ResourceURL']] ?? null];
        } elseif ($operation === 'UpdateEvent') {
            $id = $request['ResourceURL'];
            if (!isset($this->events[$id])) {
                return json_encode(['Success' => false, 'Error' => 'Missing event'], JSON_THROW_ON_ERROR);
            }
            $this->events[$id] = array_merge($this->events[$id], $request['Event'], ['etag' => 'v2']);
            $payload = $this->events[$id];
        } elseif ($operation === 'DeleteEvent') {
            unset($this->events[$request['ResourceURL']]);
            $payload = ['success' => true];
        } elseif ($operation === 'BeginEventsTransfer') {
            $this->transferItems = array_values(array_filter($this->events, static function (array $event) use ($request): bool
            {
                return strtotime($event['end']) > $request['Start'] && strtotime($event['start']) < $request['End'];
            }));
            $payload = ['Token' => str_repeat('a', 32), 'PageCount' => 1, 'ItemCount' => count($this->transferItems), 'SyncToken' => '', 'Incremental' => false];
        } elseif ($operation === 'ReadEventsTransferPage') {
            $payload = ['Token' => str_repeat('a', 32), 'Page' => 0, 'PageCount' => 1, 'ItemCount' => count($this->transferItems), 'Complete' => true, 'Items' => $this->transferItems];
        } elseif ($operation === 'FinishEventsTransfer') {
            $payload = ['success' => true];
        } elseif ($operation === 'GetCalendars') {
            $payload = [[
                'id'           => 'buffer-test', 'name' => 'Buffer test', 'color' => '#4F8EF7',
                'capabilities' => ['read' => true, 'create' => true, 'update' => true, 'delete' => true]
            ]];
        } else {
            $payload = [];
        }
        return json_encode(['Success' => true, 'Payload' => $payload], JSON_THROW_ON_ERROR);
    }
}

function bufferCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$main = [
    'summary' => 'Meeting', 'start' => '2026-03-29T00:30:00+01:00',
    'end'     => '2026-03-29T03:30:00+02:00', 'timezone' => 'Europe/Berlin',
    'allDay'  => false
];
$request = [
    'event'   => $main,
    'buffers' => [
        'before' => ['minutes' => 60, 'summary' => 'Arrival'],
        'after'  => ['minutes' => 30, 'summary' => 'Departure']
    ],
    'mode' => 'linked'
];
$invalid = new BufferCalendar(4);
foreach ([['allDay' => true], ['recurrence' => ['frequency' => 'DAILY']], ['task' => true]] as $forbidden) {
    $rejected = json_decode($invalid->CreateEventWithBuffers(json_encode(array_replace($request, ['event' => array_replace($main, $forbidden)]), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
    bufferCheck(!$rejected['success'] && $invalid->events === [], 'Unsupported appointment types must be rejected before any provider write.');
}
$calendar = new BufferCalendar(1);
$result = json_decode($calendar->CreateEventWithBuffers(json_encode($request, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
bufferCheck($result['success'] === true && count($calendar->events) === 3, 'Main and both buffers must be created.');
bufferCheck($calendar->events['buffer-2']['start'] === '2026-03-28T22:30:00Z', 'Before buffer must use elapsed minutes across DST.');
bufferCheck($calendar->events['buffer-3']['end'] === '2026-03-29T02:00:00Z', 'After buffer must use elapsed minutes across DST.');
bufferCheck(count(json_decode($calendar->attributes['LinkedEventBuffers'], true)) === 1, 'Linked mode must persist the relationship.');
bufferCheck($calendar->HasLinkedEventBuffers(json_encode($result['created']['main'], JSON_THROW_ON_ERROR)), 'Linked main event must be recognized before a calendar move.');
bufferCheck(!$calendar->HasLinkedEventBuffers(json_encode($result['created']['before'], JSON_THROW_ON_ERROR)), 'A companion must not be mistaken for a linked main event.');

$calendar->events['buffer-1']['start'] = '2026-03-29T04:30:00+02:00';
$calendar->events['buffer-1']['end'] = '2026-03-29T05:30:00+02:00';
$calendar->failLookups = true;
bufferCheck($calendar->Synchronize(), 'A transient linked lookup must not fail calendar synchronization.');
bufferCheck($calendar->events['buffer-2']['start'] === '2026-03-28T22:30:00Z', 'Transient lookup must preserve the buffer.');
$calendar->failLookups = false;
bufferCheck($calendar->Synchronize(), 'Synchronization must succeed after provider recovery.');
bufferCheck($calendar->events['buffer-2']['start'] === '2026-03-29T01:30:00Z', 'External main change must move the before buffer.');
bufferCheck($calendar->events['buffer-3']['end'] === '2026-03-29T04:00:00Z', 'External main change must move the after buffer.');

unset($calendar->events['buffer-1']);
bufferCheck($calendar->Synchronize(), 'Confirmed external deletion must reconcile.');
bufferCheck($calendar->events === [], 'Confirmed main deletion must remove both linked buffers.');
bufferCheck(json_decode($calendar->attributes['LinkedEventBuffers'], true) === [], 'Completed link must be removed.');

$independent = new BufferCalendar(2);
$request['mode'] = 'independent';
$result = json_decode($independent->CreateEventWithBuffers(json_encode($request, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
bufferCheck($result['success'] === true && !isset($independent->attributes['LinkedEventBuffers']), 'Independent mode must not persist a link.');
unset($independent->events['buffer-1']);
bufferCheck($independent->Synchronize() && count($independent->events) === 2, 'Independent buffers must survive main deletion.');

$partial = new BufferCalendar(3);
$partial->failCreateAt = 3;
$request['mode'] = 'linked';
$result = json_decode($partial->CreateEventWithBuffers(json_encode($request, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
bufferCheck($result['success'] === false && count($result['created']) === 2, 'Partial create must report confirmed writes.');
bufferCheck(count(json_decode($partial->attributes['LinkedEventBuffers'], true)) === 1, 'Confirmed companion must remain linked after a later failure.');
bufferCheck($partial->Synchronize() && count($partial->events) === 2, 'Synchronization must not duplicate a partially created bundle.');

$converted = new BufferCalendar(5);
$convertedRequest = $request;
unset($convertedRequest['buffers']['after']);
$result = json_decode($converted->CreateEventWithBuffers(json_encode($convertedRequest, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
bufferCheck($result['success'] && count($converted->events) === 2, 'A single before buffer must be supported.');
$converted->events['buffer-1']['allDay'] = true;
bufferCheck($converted->Synchronize(), 'All-day conversion must not break synchronization.');
bufferCheck(count($converted->events) === 2 && json_decode($converted->attributes['LinkedEventBuffers'], true) === [], 'Unsupported conversion must detach without deleting the buffer.');

echo "Event buffer tests passed.\n";
