<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/ConstantStubs.php';
require_once __DIR__ . '/stubs/MessageStubs.php';

$GLOBALS['localConnections'] = [];
$GLOBALS['localLocks'] = [];
$GLOBALS['localLockDenied'] = false;
$GLOBALS['localLockHook'] = null;
function IPS_GetKernelRunlevel(): int
{
    return KR_READY;
}
function IPS_GetInstance(int $id): array
{
    return ['ConnectionID' => $GLOBALS['localConnections'][$id] ?? 0];
}
function IPS_GetName(int $id): string
{
    return 'Local test';
}
function IPS_GetObjectIDByIdent(string $ident, int $id): int
{
    return 0;
}
function IPS_SemaphoreEnter(string $name, int $timeout): bool
{
    if ($GLOBALS['localLockDenied'] || isset($GLOBALS['localLocks'][$name])) {
        return false;
    }
    $GLOBALS['localLocks'][$name] = true;
    if ($GLOBALS['localLockHook'] !== null) {
        $hook = $GLOBALS['localLockHook'];
        $GLOBALS['localLockHook'] = null;
        $hook();
    }
    return true;
}
function IPS_SemaphoreLeave(string $name): void
{
    unset($GLOBALS['localLocks'][$name]);
}

/** Models only the platform boundary; all calendar/provider/codec workflows are real. */
class IPSModuleStrict
{
    public array $attributes = [];
    public array $properties = [];
    public array $buffers = [];
    public array $timers = [];
    public array $values = [];
    public bool $failOriginalSave = false;
    public int $status = 0;
    public function __construct(protected int $InstanceID)
    {
    }
    public function __call(string $method, array $arguments): mixed
    {
        $key = $arguments[0] ?? '';
        if (str_starts_with($method, 'RegisterProperty')) {
            $this->properties[$key] ??= $arguments[1];
            return null;
        }
        if (str_starts_with($method, 'RegisterAttribute')) {
            $this->attributes[$key] ??= $arguments[1];
            return null;
        }
        if (str_starts_with($method, 'ReadProperty')) {
            return $this->properties[$key];
        }
        if (str_starts_with($method, 'ReadAttribute')) {
            return $this->attributes[$key];
        }
        if (str_starts_with($method, 'WriteAttribute')) {
            if ($key === 'LocalCalendarResources' && $this->failOriginalSave) {
                return false;
            }
            if ($key === 'LocalCalendarResources' && $GLOBALS['localLocks'] === []) {
                throw new RuntimeException('Original write was not protected by a lock.');
            }
            $this->attributes[$key] = $arguments[1];
            return true;
        }
        return match ($method) {
            'RegisterMessage', 'RegisterVariableInteger', 'RegisterTimer', 'SendDebug', 'UpdateFormField' => null,
            'SetTimerInterval'                                                                            => $this->timers[$key] = $arguments[1],
            'SetStatus'                                                                                   => $this->status = $key,
            'SetValue'                                                                                    => $this->values[$key] = $arguments[1],
            'GetBuffer'                                                                                   => $this->buffers[$key] ?? '',
            'SetBuffer'                                                                                   => $this->buffers[$key] = $arguments[1],
            'Translate'                                                                                   => $key,
            'HasActiveParent', 'SendDataToParent'                                                         => throw new RuntimeException('Local mode must never access an account.'),
            default                                                                                       => throw new RuntimeException('Unimplemented platform boundary: ' . $method)
        };
    }
    public function Create(): void
    {
    }
    public function ApplyChanges(): void
    {
    }
}

require_once dirname(__DIR__) . '/Kalender/module.php';

function localModuleCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function localModuleEvent(string $title, string $day = 'today'): array
{
    $start = new DateTimeImmutable($day);
    return ['summary' => $title, 'allDay' => true, 'start' => $start->format('Y-m-d'),
        'end'         => $start->modify('+1 day')->format('Y-m-d')];
}
function localModuleNew(int $id): Calendar
{
    $calendar = new Calendar($id);
    $calendar->Create();
    $calendar->properties['LocalCalendar'] = true;
    $calendar->ApplyChanges();
    localModuleCheck($calendar->Initialize(), 'Local initialization without account failed: ' . $calendar->attributes['LastError']);
    return $calendar;
}

$calendar = localModuleNew(42);
localModuleCheck($calendar->timers['SynchronizationTimer'] === 0, 'Local mode must not schedule provider polling.');
localModuleCheck($calendar->timers['DayChangeTimer'] > 0, 'Local mode must schedule day changes.');
$status = json_decode($calendar->GetCalendarStatus(), true, 512, JSON_THROW_ON_ERROR);
localModuleCheck($status['localCalendar'] && $status['canWrite'] && $status['canUpdateFollowing'] && $status['canWriteStatus'], 'Local capabilities were not exposed.');

$created = json_decode($calendar->CreateEvent(json_encode(localModuleEvent('Original'), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
localModuleCheck($created['success'] && ($created['event']['uid'] ?? '') !== '', 'Creating the original event failed: ' . $created['error']);
$originals = $calendar->attributes['LocalCalendarResources'];
localModuleCheck(count(json_decode($originals, true, 512, JSON_THROW_ON_ERROR)) === 1, 'An original object must be stored permanently.');
localModuleCheck(count(json_decode($calendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR)) === 1, 'Create must update the visible cache.');
$calendar->ClearCache();
localModuleCheck($calendar->attributes['LocalCalendarResources'] === $originals, 'ClearCache erased originals.');
localModuleCheck(json_decode($calendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR) === [], 'ClearCache should clear derived events only.');
$calendar->ApplyChanges();
localModuleCheck($calendar->attributes['LocalCalendarResources'] === $originals, 'ApplyChanges erased originals.');
localModuleCheck($calendar->Initialize() && count(json_decode($calendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR)) === 1, 'Initialization must rebuild events from originals.');

$restart = new Calendar(42);
$restart->Create();
$restart->properties = $calendar->properties;
$restart->attributes = $calendar->attributes;
$restart->ApplyChanges();
localModuleCheck($restart->Initialize(), 'Restored originals did not survive a simulated restart.');
localModuleCheck($restart->attributes['LocalCalendarResources'] === $originals, 'Restart changed originals.');

$before = $restart->attributes['LocalCalendarResources'];
$restart->failOriginalSave = true;
$result = json_decode($restart->CreateEvent(json_encode(localModuleEvent('Must not succeed'), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
localModuleCheck(!$result['success'] && str_contains($result['error'], 'could not be saved'), 'A rejected durable save was reported as successful: ' . $result['error']);
localModuleCheck($restart->attributes['LocalCalendarResources'] === $before && $GLOBALS['localLocks'] === [], 'Failure must preserve originals and release its lock.');
$restart->failOriginalSave = false;
$GLOBALS['localLockDenied'] = true;
$result = json_decode($restart->CreateEvent(json_encode(localModuleEvent('Busy'), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
localModuleCheck(!$result['success'] && str_contains($result['error'], 'busy'), 'Lock rejection must report busy.');
$GLOBALS['localLockDenied'] = false;
localModuleCheck($restart->attributes['LocalCalendarResources'] === $before, 'Busy failure mutated originals.');

// Publish another completed transaction immediately before our lock was acquired.
// Reading the store before entering the semaphore would lose this event.
$other = new IPSKalender\LocalCalendarProvider(json_decode($before, true, 512, JSON_THROW_ON_ERROR), $status['calendarId']);
$other->createEvent($status['calendarId'], localModuleEvent('Concurrent predecessor'));
$concurrentResources = json_encode((object) $other->exportResources(), JSON_THROW_ON_ERROR);
$GLOBALS['localLockHook'] = static function () use ($restart, $concurrentResources): void
{
    $restart->attributes['LocalCalendarResources'] = $concurrentResources;
};
$restart->CreateEvent(json_encode(localModuleEvent('Subsequent transaction'), JSON_THROW_ON_ERROR));
localModuleCheck(count(json_decode($restart->attributes['LocalCalendarResources'], true, 512, JSON_THROW_ON_ERROR)) === 3, 'A transaction lost the previous locked write.');
localModuleCheck($GLOBALS['localLocks'] === [], 'Successful operations must release locks.');

$restart->ClearCache();
localModuleCheck($restart->RefreshTodayEventCount() && count(json_decode($restart->GetEvents(), true, 512, JSON_THROW_ON_ERROR)) === 3, 'Day change must reload source events rather than an empty cache.');
$restart->properties['LocalCalendar'] = false;
$restart->ApplyChanges();
localModuleCheck($restart->status === 201 && str_contains($restart->attributes['LastError'], 'original events'), 'Switching a populated local calendar to provider mode must be rejected.');
foreach (['ProviderCalendarID', 'CalendarURL'] as $property) {
    $invalid = localModuleNew(43);
    $invalid->properties[$property] = 'external';
    $invalid->ApplyChanges();
    localModuleCheck($invalid->status === 201 && !$invalid->Initialize(), 'An external identity must not become local: ' . $property);
}
$invalid = localModuleNew(44);
$GLOBALS['localConnections'][44] = 99;
$invalid->ApplyChanges();
localModuleCheck($invalid->Initialize(), 'A local calendar may be connected through a local Calendar Account.');

$corrupt = localModuleNew(45);
$corrupt->attributes['LocalCalendarResources'] = '{broken';
localModuleCheck(!$corrupt->Synchronize() && $corrupt->attributes['LocalCalendarResources'] === '{broken', 'Invalid source data must not silently reset.');
localModuleCheck($GLOBALS['localLocks'] === [], 'Corrupt input must release the semaphore.');

$editable = localModuleNew(46);
$editable->CreateEvent(json_encode(localModuleEvent('Edit me'), JSON_THROW_ON_ERROR));
$event = json_decode($editable->GetEvents(), true, 512, JSON_THROW_ON_ERROR)[0];
$result = json_decode($editable->UpdateEvent(json_encode($event + ['changes' => ['summary' => 'Changed', 'status' => 'TENTATIVE', 'transparency' => 'TRANSPARENT']], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
localModuleCheck($result['success'], 'Local update failed: ' . $result['error']);
$editable->ClearCache();
$editable->Synchronize();
$updated = json_decode($editable->GetEvents(), true, 512, JSON_THROW_ON_ERROR)[0];
localModuleCheck($updated['summary'] === 'Changed' && $updated['status'] === 'TENTATIVE' && $updated['transparency'] === 'TRANSPARENT', 'Updated 9.1 state must persist in originals.');
$result = json_decode($editable->UpdateEvent(json_encode($event + ['changes' => ['summary' => 'Stale overwrite']], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
localModuleCheck(!$result['success'], 'A stale etag must not overwrite a newer local original.');
localModuleCheck($editable->DeleteEvent(json_encode($updated, JSON_THROW_ON_ERROR)), 'Local deletion failed.');
localModuleCheck($editable->attributes['LocalCalendarResources'] === '{}', 'Confirmed deletion must remove the original object.');

$task = localModuleEvent('Daily duty', 'yesterday') + ['task' => true];
$editable->CreateEvent(json_encode($task, JSON_THROW_ON_ERROR));
localModuleCheck($editable->RefreshTodayEventCount(), 'Local overdue task rollover failed.');
$task = json_decode($editable->GetEvents(), true, 512, JSON_THROW_ON_ERROR)[0];
localModuleCheck($task['task'] && !$task['taskCompleted'] && substr($task['start'], 0, 10) === date('Y-m-d'), 'An overdue task must move to today as an open task.');
$result = json_decode($editable->UpdateEvent(json_encode($task + ['changes' => ['taskCompleted' => true]], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
localModuleCheck($result['success'], 'Completing a local task failed: ' . $result['error']);
$editable->ClearCache();
$editable->Synchronize();
$task = json_decode($editable->GetEvents(), true, 512, JSON_THROW_ON_ERROR)[0];
localModuleCheck($task['taskCompleted'] && $task['status'] === 'CONFIRMED', 'Task completion must persist independently of appointment status.');

$outside = localModuleNew(47);
$outside->CreateEvent(json_encode(localModuleEvent('Older original', '-40 days'), JSON_THROW_ON_ERROR));
localModuleCheck(json_decode($outside->GetEvents(), true, 512, JSON_THROW_ON_ERROR) === [], 'An out-of-range original must not enter the display cache.');
localModuleCheck(count(json_decode($outside->attributes['LocalCalendarResources'], true, 512, JSON_THROW_ON_ERROR)) === 1, 'An out-of-range event must remain durable.');
$outside->properties['PastDays'] = 60;
$outside->ApplyChanges();
$outside->Initialize();
localModuleCheck(count(json_decode($outside->GetEvents(), true, 512, JSON_THROW_ON_ERROR)) === 1, 'Expanding the view range must recover the original event.');

$seriesCalendar = localModuleNew(48);
$seriesTask = localModuleEvent('Recurring duty', '-3 days') + [
    'task'       => true, 'taskFollowPlanned' => false,
    'recurrence' => ['frequency' => 'DAILY', 'interval' => 1, 'endMode' => 'count', 'count' => 6]
];
$result = json_decode($seriesCalendar->CreateEvent(json_encode($seriesTask, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
localModuleCheck($result['success'], 'Creating a task series failed: ' . $result['error']);
localModuleCheck($seriesCalendar->RefreshTodayEventCount(), 'Initial local series task rollover failed: ' . $seriesCalendar->attributes['LastError']);
localModuleCheck(count(json_decode($seriesCalendar->attributes['LocalCalendarResources'], true, 512, JSON_THROW_ON_ERROR)) === 2, 'Rollover must detach exactly one open task while retaining its original series.');
localModuleCheck($seriesCalendar->RefreshTodayEventCount(), 'Repeated day processing failed.');
localModuleCheck(count(json_decode($seriesCalendar->attributes['LocalCalendarResources'], true, 512, JSON_THROW_ON_ERROR)) === 2, 'An unfinished detached task must block duplicate task creation.');
$taskEvents = json_decode($seriesCalendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR);
$detached = array_values(array_filter($taskEvents, static fn (array $event): bool => $event['task'] && !($event['recurring'] ?? false)));
localModuleCheck(count($detached) === 1 && $detached[0]['taskRolledForward'], 'Detached task must be visibly marked.');
$result = json_decode($seriesCalendar->UpdateEvent(json_encode($detached[0] + ['changes' => ['taskCompleted' => true]], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
localModuleCheck($result['success'], 'Completing the detached task failed: ' . $result['error']);
localModuleCheck($seriesCalendar->RefreshTodayEventCount(), 'Rollover of the next overdue occurrence failed: ' . $seriesCalendar->attributes['LastError']);
localModuleCheck(count(json_decode($seriesCalendar->attributes['LocalCalendarResources'], true, 512, JSON_THROW_ON_ERROR)) === 3, 'Completion must release exactly one further overdue occurrence.');
localModuleCheck($GLOBALS['localLocks'] === [], 'Series task processing leaked a transaction lock.');

// Task processing is independent of the disposable display window, including after downtime.
foreach ([[49, 0, 'yesterday'], [50, 30, '-40 days']] as [$id, $pastDays, $day]) {
    $source = localModuleNew($id);
    $source->properties['PastDays'] = $pastDays;
    foreach ([
        localModuleEvent('Still open', $day) + ['task' => true],
        localModuleEvent('Already done', $day) + ['task' => true, 'taskCompleted' => true],
        localModuleEvent('Ordinary old event', $day)
    ] as $oldEvent) {
        $result = json_decode($source->CreateEvent(json_encode($oldEvent, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
        localModuleCheck($result['success'], 'Preparing out-of-window originals failed: ' . $result['error']);
    }
    $beforeOriginals = json_decode($source->attributes['LocalCalendarResources'], true, 512, JSON_THROW_ON_ERROR);
    $source->ClearCache();
    $restored = new Calendar($id);
    $restored->Create();
    $restored->properties = $source->properties;
    $restored->attributes = $source->attributes;
    $restored->ApplyChanges();
    localModuleCheck($restored->Initialize(), 'Initialization after downtime failed: ' . $restored->attributes['LastError']);
    $events = json_decode($restored->GetEvents(), true, 512, JSON_THROW_ON_ERROR);
    localModuleCheck(count($events) === 1 && $events[0]['task'] && !$events[0]['taskCompleted']
        && substr($events[0]['start'], 0, 10) === date('Y-m-d'), 'An overdue task outside PastDays must roll forward after cache clear and restart.');
    $afterOriginals = json_decode($restored->attributes['LocalCalendarResources'], true, 512, JSON_THROW_ON_ERROR);
    localModuleCheck(count($afterOriginals) === 3, 'Recovery must preserve all local original resources.');
    foreach ($beforeOriginals as $url => $ical) {
        if (!str_contains($ical, '[OC:TODO] Still open')) {
            localModuleCheck($afterOriginals[$url] === $ical, 'Completed tasks and ordinary old events must not be moved by overdue recovery.');
        }
    }
    localModuleCheck($restored->RefreshTodayEventCount(), 'Repeated local day processing failed.');
    localModuleCheck($restored->attributes['LocalCalendarResources'] === json_encode((object) $afterOriginals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'A repeated refresh must not duplicate or re-edit the recovered task.');
}

fwrite(STDOUT, "Local calendar module persistence, cache/restart/day-change, isolation and transaction locking passed.\n");
