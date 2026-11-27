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
    public bool $failPrivateSave = false;
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
        return (string) ($this->attributes[$Name] ?? ($Name === 'LocalBufferResources' ? '{}' : '[]'));
    }

    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        if ($Name === 'LocalBufferResources' && $this->failPrivateSave) {
            return false;
        }
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

    protected function GetBufferList(): array
    {
        return [];
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
$badStorage = $request;
$badStorage['storage'] = 'unexpected';
$rejected = json_decode($invalid->CreateEventWithBuffers(json_encode($badStorage, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
bufferCheck(!$rejected['success'] && $invalid->events === [], 'Invalid storage must be rejected before the main provider event is created.');
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

$private = new BufferCalendar(6);
$privateRequest = $request;
$privateRequest['storage'] = 'local';
$createdPrivate = json_decode($private->CreateEventWithBuffers(json_encode($privateRequest, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
bufferCheck($createdPrivate['success'] && count($private->events) === 1 && $private->createCount === 1, 'Private buffers must never be created at the provider.');
$privateEvents = json_decode($private->GetEvents(), true, 512, JSON_THROW_ON_ERROR);
bufferCheck(count($privateEvents) === 2, 'Private buffers must appear in the public event feed.');
$privateBefore = $createdPrivate['created']['before'];
bufferCheck(str_contains($privateBefore['resourceUrl'], 'opencalendar.invalid/local/'), 'Private buffer identity must belong to Symcon.');
bufferCheck($private->IsLocalBufferEvent(json_encode($privateBefore, JSON_THROW_ON_ERROR)), 'Private buffer must be recognized for write routing.');
bufferCheck(!$private->IsLocalBufferEvent(json_encode($createdPrivate['created']['main'], JSON_THROW_ON_ERROR)), 'Provider main event must not be mistaken for a private buffer.');
$privateEdit = json_decode($private->GetEventForEdit(json_encode($privateBefore, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
bufferCheck(($privateEdit['sourceType'] ?? '') === 'symcon-buffer', 'Private buffers must be editable without provider lookup.');
$privateUpdate = json_decode($private->UpdateEvent(json_encode(array_merge($privateEdit, ['changes' => ['summary' => 'Private arrival']]), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
bufferCheck($privateUpdate['success'] && count($private->events) === 1, 'Editing a private buffer must not write to the provider.');
bufferCheck(!in_array('UpdateEvent', array_column($private->requests, 'Operation'), true), 'Private edits must not call the provider update operation.');
$privateTransfer = json_decode($private->BeginEventsTransfer(strtotime('2026-03-28'), strtotime('2026-03-31')), true, 512, JSON_THROW_ON_ERROR);
$privatePage = json_decode($private->ReadEventsTransferPage($privateTransfer['Token'], 0), true, 512, JSON_THROW_ON_ERROR);
bufferCheck(count($privatePage['Items']) === 2, 'Private buffers must appear in the paged view transfer.');
$private->FinishEventsTransfer($privateTransfer['Token']);
$private->events['buffer-1']['start'] = '2026-03-29T04:30:00+02:00';
$private->events['buffer-1']['end'] = '2026-03-29T05:30:00+02:00';
bufferCheck($private->Synchronize(), 'External main changes must reconcile local buffers.');
$privateEvents = json_decode($private->GetEvents(), true, 512, JSON_THROW_ON_ERROR);
bufferCheck(count($private->events) === 1 && count($privateEvents) === 2, 'Synchronizing the provider must preserve private buffers.');
bufferCheck(count(array_filter($privateEvents, static fn (array $event): bool => ($event['summary'] ?? '') === 'Private arrival'
    && strtotime((string) $event['start']) === strtotime('2026-03-29T01:30:00Z'))) === 1, 'External changes must move the linked private buffer without losing its title.');
$restarted = new BufferCalendar(6);
$restarted->attributes = $private->attributes;
$restarted->events = $private->events;
bufferCheck(count(json_decode($restarted->GetEvents(), true, 512, JSON_THROW_ON_ERROR)) === 2, 'Private buffers must survive an instance restart.');
bufferCheck($private->DeleteEvent(json_encode($createdPrivate['created']['main'], JSON_THROW_ON_ERROR)), 'Main event deletion must succeed.');
bufferCheck(json_decode($private->GetEvents(), true, 512, JSON_THROW_ON_ERROR) === [], 'Confirmed main deletion must remove linked private buffers.');

$singlePrivate = new BufferCalendar(9);
$singleRequest = $request;
$singleRequest['storage'] = 'local';
unset($singleRequest['buffers']['after']);
$singleCreated = json_decode($singlePrivate->CreateEventWithBuffers(json_encode($singleRequest, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
$singlePrivate->failLookups = true;
bufferCheck($singlePrivate->DeleteEvent(json_encode($singleCreated['created']['before'], JSON_THROW_ON_ERROR)), 'The last private buffer must be deletable without provider access.');
bufferCheck(!$singlePrivate->HasLinkedEventBuffers(json_encode($singleCreated['created']['main'], JSON_THROW_ON_ERROR)), 'Deleting the last private buffer must release the main link immediately.');
bufferCheck(count($singlePrivate->events) === 1, 'Deleting a private buffer must preserve the provider main event.');
$singleRestarted = new BufferCalendar(9);
$singleRestarted->attributes = $singlePrivate->attributes;
bufferCheck(!$singleRestarted->HasLinkedEventBuffers(json_encode($singleCreated['created']['main'], JSON_THROW_ON_ERROR)), 'A deleted private buffer must not restore its link after restart.');

$stalePrivate = new BufferCalendar(11);
$staleCreated = json_decode($stalePrivate->CreateEventWithBuffers(json_encode($singleRequest, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
$staleBuffer = $staleCreated['created']['before'];
$staleBuffer['etag'] = 'stale';
bufferCheck(!$stalePrivate->DeleteEvent(json_encode($staleBuffer, JSON_THROW_ON_ERROR)), 'A stale private buffer deletion must be rejected.');
bufferCheck($stalePrivate->HasLinkedEventBuffers(json_encode($staleCreated['created']['main'], JSON_THROW_ON_ERROR)), 'A rejected private buffer deletion must preserve the link.');

$twoPrivate = new BufferCalendar(10);
$twoRequest = $request;
$twoRequest['storage'] = 'local';
$twoCreated = json_decode($twoPrivate->CreateEventWithBuffers(json_encode($twoRequest, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
$twoPrivate->failLookups = true;
bufferCheck($twoPrivate->DeleteEvent(json_encode($twoCreated['created']['before'], JSON_THROW_ON_ERROR)), 'The first private buffer must be deletable offline.');
bufferCheck($twoPrivate->HasLinkedEventBuffers(json_encode($twoCreated['created']['main'], JSON_THROW_ON_ERROR)), 'The remaining private buffer must stay linked.');
bufferCheck(count(json_decode($twoPrivate->attributes['LinkedEventBuffers'], true, 512, JSON_THROW_ON_ERROR)[0]['companions']) === 1, 'Only the deleted side must leave the link.');
bufferCheck($twoPrivate->DeleteEvent(json_encode($twoCreated['created']['after'], JSON_THROW_ON_ERROR)), 'The remaining private buffer must be deletable offline.');
bufferCheck(!$twoPrivate->HasLinkedEventBuffers(json_encode($twoCreated['created']['main'], JSON_THROW_ON_ERROR)), 'Deleting both private buffers must clear the link without synchronization.');

$independentPrivate = new BufferCalendar(7);
$privateRequest['mode'] = 'independent';
$privateCreated = json_decode($independentPrivate->CreateEventWithBuffers(json_encode($privateRequest, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
bufferCheck($privateCreated['success'] && count($independentPrivate->events) === 1, 'Independent private buffers must leave only the main event at the provider.');
bufferCheck($independentPrivate->DeleteEvent(json_encode($privateCreated['created']['before'], JSON_THROW_ON_ERROR)), 'Private buffer deletion must succeed without provider access.');
bufferCheck(count($independentPrivate->events) === 1 && count(json_decode($independentPrivate->GetEvents(), true, 512, JSON_THROW_ON_ERROR)) === 1, 'Deleting one private buffer must preserve the provider event and other private buffer.');
$independentPrivate->ClearCache();
bufferCheck(count(json_decode($independentPrivate->GetEvents(), true, 512, JSON_THROW_ON_ERROR)) === 1, 'Clearing the display cache must preserve private buffer originals.');

$failedPrivateSave = new BufferCalendar(8);
$failedPrivateSave->failPrivateSave = true;
$failedResult = json_decode($failedPrivateSave->CreateEventWithBuffers(json_encode($privateRequest, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
bufferCheck(
    !$failedResult['success'] && count($failedResult['created']) === 1
    && count($failedPrivateSave->events) === 1
    && json_decode($failedPrivateSave->GetEvents(), true, 512, JSON_THROW_ON_ERROR) === [],
    'Failed private persistence must report only the confirmed provider main and must not expose a phantom buffer.'
);

echo "Event buffer tests passed.\n";
