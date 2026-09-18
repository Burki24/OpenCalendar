<?php

declare(strict_types=1);

use IPSKalender\CalendarEventRecurrence;

require_once __DIR__ . '/stubs/autoload.php';
require_once dirname(__DIR__) . '/Kalender/module.php';
require_once dirname(__DIR__) . '/Kalender Konto/traits/ChildGatewayTrait.php';

final class RecoveryHttpException extends RuntimeException
{
    public function __construct(public int $httpStatus)
    {
        parent::__construct('Injected HTTP failure.');
    }
}

/** Keep the real gateway classification; replace only the direct provider lookup. */
final class RecoveryGateway
{
    use KalenderKontoChildGatewayTrait {
        checkPendingTaskForChild as public check;
    }

    public int $httpStatus = 200;
    public ?array $event = null;

    private function resolveCalendar(string $calendarId): array
    {
        return ['id' => $calendarId];
    }

    private function createProvider(): object
    {
        return new stdClass();
    }

    private function directEventForEditForChild(array $calendar, object $provider, array $request): ?array
    {
        if ($this->httpStatus !== 200) {
            throw new RecoveryHttpException($this->httpStatus);
        }
        return $this->event;
    }
}

/** Exercises real Calendar workflows with failures only at the provider boundary. */
final class RecoveryCalendar extends Calendar
{
    public array $attributes = [];
    public array $requests = [];
    public array $items = [];
    public bool $failReads = false;
    public bool $failWrites = false;
    public bool $incremental = false;
    public array $pendingResult = ['known' => false];
    public bool $failPendingLookup = false;
    public bool $failCacheWrite = false;
    public string $nextSyncToken = 'delta-next';
    public int $failUpdateNumber = 0;
    public int $updateCount = 0;
    public ?array $eventAfterWrite = null;

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
        return $Name === 'CalendarID' ? 'recovery-calendar' : '';
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
        if ($Name === 'CachedEvents' && $this->failCacheWrite) {
            throw new RuntimeException('Injected cache persistence failure.');
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

    protected function SendDataToParent(string $Data): string
    {
        $request = json_decode($Data, true, 512, JSON_THROW_ON_ERROR);
        $this->requests[] = $request;
        $operation = $request['Operation'];
        if ($operation === 'UpdateEvent') {
            ++$this->updateCount;
        }
        if ($operation === 'GetCalendars'
            || ($operation === 'CheckPendingTask' && $this->failPendingLookup)
            || ($operation === 'UpdateEvent' && $this->failUpdateNumber === $this->updateCount)
            || ($this->failReads && in_array($operation, ['BeginEventsTransfer', 'GetEventAfterWrite', 'GetEventForEdit'], true))
            || ($this->failWrites && in_array($operation, ['CreateEvent', 'UpdateEvent', 'DeleteEvent'], true))) {
            return json_encode(['Success' => false, 'Error' => 'Injected provider failure: ' . $operation], JSON_THROW_ON_ERROR);
        }
        $token = str_repeat('a', 32);
        $payload = match ($operation) {
            'CheckPendingTask'    => $this->pendingResult,
            'BeginEventsTransfer' => [
                'Token'     => $token, 'PageCount' => 1, 'ItemCount' => count($this->items),
                'SyncToken' => $this->nextSyncToken, 'Incremental' => $this->incremental
            ],
            'ReadEventsTransferPage' => [
                'Token'     => $token, 'Page' => 0, 'PageCount' => 1,
                'ItemCount' => count($this->items), 'Complete' => true, 'Items' => $this->items
            ],
            'FinishEventsTransfer', 'DeleteEvent' => ['success' => true],
            'GetEventAfterWrite'                  => $this->eventAfterWrite ?? [],
            'UpdateEvent'                         => $this->eventAfterWrite !== null
                ? array_intersect_key($this->eventAfterWrite, array_flip(['uid', 'resourceUrl', 'eventReference', 'etag']))
                : ['uid' => $request['UID'], 'resourceUrl' => $request['ResourceURL'], 'etag' => 'updated-etag'],
            'CreateEvent'                         => ['uid' => 'written', 'resourceUrl' => 'written'],
            default                               => []
        };
        return json_encode(['Success' => true, 'Payload' => $payload], JSON_THROW_ON_ERROR);
    }
}

function recoveryEvent(string $uid, string $summary, string $relativeDay = 'today'): array
{
    $start = new DateTimeImmutable($relativeDay);
    return array_merge(CalendarEventRecurrence::single(), [
        'uid'            => $uid, 'resourceUrl' => $uid, 'summary' => $summary, 'allDay' => true,
        'start'          => $start->format('Y-m-d'), 'end' => $start->modify('+1 day')->format('Y-m-d'),
        'startTimestamp' => $start->getTimestamp(), 'endTimestamp' => $start->modify('+1 day')->getTimestamp()
    ]);
}

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void
{
    if (!$condition) {
        $failures[] = $message;
    }
};

// A remote deletion is irreversible even if the following refresh fails.
$calendar = new RecoveryCalendar(9091);
$calendar->failReads = true;
$event = recoveryEvent('deleted', 'Normal event');
$check($calendar->DeleteEvent(json_encode($event, JSON_THROW_ON_ERROR)), 'Confirmed deletion must remain successful after a refresh failure.');
$check(str_contains($calendar->attributes['LastError'] ?? '', 'Injected provider failure'), 'A failed post-write refresh must retain its diagnostic.');
$calendar->failWrites = true;
$check(!$calendar->DeleteEvent(json_encode($event, JSON_THROW_ON_ERROR)), 'An unconfirmed provider deletion must still fail.');
$calendar->failWrites = false;
$calendar->requests = [];
$calendar->attributes['LastError'] = '';
$created = json_decode($calendar->CreateEvent(json_encode($event, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
$check(($created['success'] ?? false) === true, 'Confirmed creation must remain successful after a refresh failure.');
$check(str_contains($calendar->attributes['LastError'] ?? '', 'Injected provider failure: BeginEventsTransfer'), 'Creation must retain the actual refresh-failure diagnostic.');
$check(in_array('BeginEventsTransfer', array_column($calendar->requests, 'Operation'), true), 'Creation must actually reach the failing refresh request.');
$calendar->requests = [];
$calendar->attributes['LastError'] = '';
$updated = json_decode($calendar->UpdateEvent(json_encode(array_merge($event, ['changes' => ['summary' => 'Changed']]), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
$check(($updated['success'] ?? false) === true, 'Confirmed update must remain successful after a refresh failure.');
$check(str_contains($calendar->attributes['LastError'] ?? '', 'Injected provider failure: BeginEventsTransfer'), 'Update must retain the actual refresh-failure diagnostic.');
$check(in_array('BeginEventsTransfer', array_column($calendar->requests, 'Operation'), true), 'Update must actually reach the failing refresh request.');

// The delta token and fetched cache must stay consistent when task processing fails.
$calendar = new RecoveryCalendar(9092);
$calendar->items = [recoveryEvent('old', 'Existing event')];
$check($calendar->Synchronize(), 'The initial full synchronization must succeed.');
$calendar->incremental = true;
$calendar->items = [recoveryEvent('new', 'New normal event'), recoveryEvent('task', '[OC:TODO] Overdue task', 'yesterday')];
$calendar->failWrites = true;
$check(!$calendar->Synchronize(), 'The injected overdue task write must fail synchronization.');
$writes = array_filter($calendar->requests, static fn (array $r): bool => $r['Operation'] === 'UpdateEvent');
$check(count($writes) === 1, 'The failed sync must reach the real task provider write.');
$calendar->items = [];
$calendar->failWrites = false;
$check($calendar->Synchronize(), 'An empty subsequent delta must recover successfully.');
$events = json_decode($calendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR);
$check(in_array('new', array_column($events, 'uid'), true), 'A received normal event must survive a failed task write and an empty next delta.');
$check(count($events) === 3, 'Both received events and the existing event must survive recovery.');

$calendar = new RecoveryCalendar(9097);
$calendar->items = [recoveryEvent('cached', 'Previously cached')];
$calendar->nextSyncToken = 'before-cache-failure';
$check($calendar->Synchronize(), 'The cache-failure baseline must synchronize.');
$cacheBefore = $calendar->attributes['CachedEvents'];
$calendar->incremental = true;
$calendar->items = [recoveryEvent('unstored', 'Not yet persisted')];
$calendar->nextSyncToken = 'after-cache-failure';
$calendar->failCacheWrite = true;
$check(!$calendar->Synchronize(), 'A failed cache write must fail synchronization.');
$check($calendar->attributes['CachedEvents'] === $cacheBefore, 'Failed persistence must leave the previous cache intact.');
$check($calendar->attributes['IncrementalSyncToken'] === 'before-cache-failure', 'A failed cache write must not advance the provider delta token.');
$calendar->failCacheWrite = false;
$check($calendar->Synchronize(), 'Retrying the uncommitted delta must recover.');
$check(count(json_decode($calendar->GetEvents(), true)) === 2, 'Retry must retain both the old and newly received event.');

$calendar = new RecoveryCalendar(9098);
$calendar->items = [recoveryEvent('first', '[OC:TODO] First overdue', '-2 days'), recoveryEvent('second', '[OC:TODO] Second overdue', 'yesterday')];
$calendar->failUpdateNumber = 2;
$check(!$calendar->Synchronize(), 'The second rollover write failure must be reported.');
$check($calendar->updateCount === 2, 'The partial-rollover fixture must perform one successful and one failed write.');
$events = array_column(json_decode($calendar->GetEvents(), true), null, 'uid');
$check(($events['first']['start'] ?? '') === (new DateTimeImmutable('today'))->format('Y-m-d'), 'The first confirmed rollover must remain in cache when the second write fails.');
$check(($events['first']['etag'] ?? '') === 'updated-etag', 'The first confirmed rollover must retain its new ETag after a later failure.');

// Absence from a bounded result is not evidence of deletion or completion.
$calendar = new RecoveryCalendar(9093);
$detached = recoveryEvent('detached', '[OC:TODO] Pending detached task', '+40 days');
$identity = ['uid' => 'detached', 'resourceUrl' => 'detached', 'eventReference' => ''];
$calendar->attributes['PendingTaskSeries'] = json_encode(['series:source' => $identity], JSON_THROW_ON_ERROR);
$occurrence = array_merge(
    recoveryEvent('next', '[OC:TODO] Next task', 'yesterday'),
    CalendarEventRecurrence::occurrence('source', 'next', (new DateTimeImmutable('yesterday'))->format('Y-m-d'), '', true, false, true, true, true)
);
$calendar->items = [$occurrence];
$check($calendar->Synchronize(), 'A window without the detached task must synchronize.');
$writes = array_filter($calendar->requests, static fn (array $r): bool => in_array($r['Operation'], ['CreateEvent', 'UpdateEvent', 'DeleteEvent'], true));
$check($writes === [], 'A detached task outside the window must still block further source-series rollover.');
$check(isset(json_decode($calendar->attributes['PendingTaskSeries'], true)['series:source']), 'A bounded result must retain the pending series identity.');
$calendar->items = [$detached];
$check($calendar->Synchronize(), 'The detached task must be accepted when it reappears.');
$events = json_decode($calendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR);
$check(($events[0]['taskRolledForward'] ?? false) === true, 'The reappearing task must retain its rolled-forward marker.');
$calendar->items[0]['summary'] = '[OC:DONE] Completed detached task';
$check($calendar->Synchronize(), 'A confirmed completed task must synchronize.');
$check(json_decode($calendar->attributes['PendingTaskSeries'], true) === [], 'Confirmed task completion must release the source-series block.');

$calendar->attributes['PendingTaskSeries'] = json_encode(['series:source' => $identity], JSON_THROW_ON_ERROR);
$calendar->failReads = true;
$calendar->failWrites = true;
$check(!$calendar->DeleteEvent(json_encode($detached, JSON_THROW_ON_ERROR)), 'Rejected detached-task deletion must fail.');
$check(isset(json_decode($calendar->attributes['PendingTaskSeries'], true)['series:source']), 'Rejected deletion must retain the source-series block.');
$calendar->failWrites = false;
$check($calendar->DeleteEvent(json_encode($detached, JSON_THROW_ON_ERROR)), 'Explicit detached-task deletion must succeed.');
$check(json_decode($calendar->attributes['PendingTaskSeries'], true) === [], 'Confirmed explicit deletion must release the source-series block.');

$calendar = new RecoveryCalendar(9094);
$calendar->attributes['PendingTaskSeries'] = json_encode(['series:source' => $identity], JSON_THROW_ON_ERROR);
$calendar->items = [$detached];
$check($calendar->Synchronize(), 'The detached task baseline must synchronize.');
$calendar->incremental = true;
$calendar->items = [array_merge($identity, ['_syncDeleted' => true])];
$check($calendar->Synchronize(), 'A provider-confirmed deletion delta must synchronize.');
$check(json_decode($calendar->attributes['PendingTaskSeries'], true) === [], 'A provider deletion tombstone must release the source-series block.');

// An external completion/deletion outside the date window needs direct evidence.
$outsideCases = [
    'deleted'             => [['known' => true, 'event' => null], false, false],
    'completed'           => [['known' => true, 'event' => array_merge($detached, ['summary' => '[OC:DONE] Done'])], false, false],
    'converted to normal' => [['known' => true, 'event' => array_merge($detached, ['summary' => 'Normal appointment'])], false, false],
    'still open'          => [['known' => true, 'event' => $detached], false, true],
    'unknown'             => [['known' => false], false, true],
    'failed lookup'       => [['known' => false], true, true],
    'mismatched identity' => [['known' => true, 'event' => recoveryEvent('another-task', '[OC:DONE] Unrelated')], false, true]
];
foreach ($outsideCases as $label => [$result, $fail, $retain]) {
    $calendar = new RecoveryCalendar(9095);
    $calendar->attributes['PendingTaskSeries'] = json_encode(['series:source' => $identity], JSON_THROW_ON_ERROR);
    $calendar->pendingResult = $result;
    $calendar->failPendingLookup = $fail;
    $check($calendar->Synchronize(), 'Absent task lookup (' . $label . ') must not fail independent synchronization.');
    $remaining = json_decode($calendar->attributes['PendingTaskSeries'], true);
    $check(isset($remaining['series:source']) === $retain, 'Absent task lookup (' . $label . ') must retain/release only with trustworthy evidence.');
    $lookups = array_values(array_filter($calendar->requests, static fn (array $r): bool => $r['Operation'] === 'CheckPendingTask'));
    $check($lookups !== [], 'Absent pending tasks must receive a direct provider lookup: ' . $label . '.');
    if ($lookups !== []) {
        $check(($lookups[0]['UID'] ?? '') === 'detached' && ($lookups[0]['ResourceURL'] ?? '') === 'detached', 'Pending lookup must use the saved task identity.');
    }
}

$calendar = new RecoveryCalendar(9096);
$calendar->attributes['PendingTaskSeries'] = json_encode(['series:source' => $identity], JSON_THROW_ON_ERROR);
$calendar->failReads = true;
$result = json_decode($calendar->UpdateEvent(json_encode(array_merge($detached, ['changes' => ['task' => true, 'taskCompleted' => true]]), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
$check(($result['success'] ?? false) === true, 'Explicit completion outside the cache window must remain successful when refresh fails.');
$check(json_decode($calendar->attributes['PendingTaskSeries'], true) === [], 'Confirmed explicit completion outside the cache window must release its source-series block.');

$gateway = new RecoveryGateway();
foreach ([404, 410] as $httpStatus) {
    $gateway->httpStatus = $httpStatus;
    $check($gateway->check(['CalendarID' => 'calendar']) === ['known' => true, 'event' => null], 'Only explicit missing-resource HTTP status may confirm external deletion.');
}
foreach ([401, 403, 429, 500, 503] as $httpStatus) {
    $gateway->httpStatus = $httpStatus;
    $thrown = false;
    try {
        $gateway->check(['CalendarID' => 'calendar']);
    } catch (RecoveryHttpException $exception) {
        $thrown = $exception->httpStatus === $httpStatus;
    }
    $check($thrown, 'HTTP ' . $httpStatus . ' must not be mistaken for a deleted task.');
}
$gateway->httpStatus = 200;
$check($gateway->check(['CalendarID' => 'calendar']) === ['known' => false, 'event' => null], 'An empty bounded provider lookup is inconclusive, not deletion evidence.');
$gateway->event = $detached;
$check($gateway->check(['CalendarID' => 'calendar']) === ['known' => true, 'event' => $detached], 'A directly retrieved task must be returned for status and identity verification.');

// Microsoft may return a new exception ID for the same logical occurrence.
$calendar = new RecoveryCalendar(9099);
$original = array_merge(
    recoveryEvent('occurrence-uid', 'Microsoft recurring event', '+2 days'),
    CalendarEventRecurrence::occurrence('ms-series', 'old-id', (new DateTimeImmutable('+2 days'))->format('Y-m-d'), '', true, false, true, true, true),
    ['eventReference' => 'old-id', 'resourceUrl' => 'https://graph.microsoft.com/v1.0/me/calendars/test/events/old-id']
);
$following = array_merge($original, array_intersect_key(
    recoveryEvent('following-uid', 'Microsoft recurring event', '+9 days'),
    array_flip(['uid', 'start', 'end', 'startTimestamp', 'endTimestamp'])
), [
    'eventReference' => 'following-id', 'occurrenceId' => 'following-id',
    'resourceUrl'    => 'https://graph.microsoft.com/v1.0/me/calendars/test/events/following-id',
    'originalStart'  => (new DateTimeImmutable('+9 days'))->format('Y-m-d')
]);
$moved = array_merge($original, array_intersect_key(
    recoveryEvent('occurrence-uid', 'Microsoft recurring event', '+4 days'),
    array_flip(['uid', 'start', 'end', 'startTimestamp', 'endTimestamp'])
), [
    'eventReference' => 'new-id', 'occurrenceId' => 'new-id', 'recurrenceType' => 'exception',
    'resourceUrl'    => 'https://graph.microsoft.com/v1.0/me/calendars/test/events/new-id', 'etag' => 'new-etag'
]);
$calendar->items = [$original, $following];
$check($calendar->Synchronize(), 'The recurring-event cache baseline must synchronize.');
$calendar->incremental = true;
$calendar->items = []; // The delta endpoint has not caught up with the write yet.
$calendar->eventAfterWrite = $moved;
$calendar->eventAfterWrite['originalStart'] = '';
$calendar->requests = [];
$result = json_decode($calendar->UpdateEvent(json_encode(array_merge($original, [
    'writeScope' => 'occurrence',
    'changes'    => ['start' => $moved['start'], 'end' => $moved['end'], 'allDay' => true]
]), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
$events = array_column(json_decode($calendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR), null, 'eventReference');
$check(($result['success'] ?? false) === true, 'A confirmed recurring occurrence update must succeed.');
$check(count($events) === 2 && !isset($events['old-id']) && isset($events['new-id'], $events['following-id']), 'A moved Microsoft occurrence must replace the old cache entry immediately, without losing siblings.');
$check(($events['new-id']['originalStart'] ?? '') === $original['originalStart'], 'A direct exception lookup must preserve the original occurrence anchor.');
$check(in_array('GetEventAfterWrite', array_column($calendar->requests, 'Operation'), true), 'Microsoft occurrence writes must use the authoritative direct lookup instead of a delayed delta.');

// A subsequent delta must also repair an old/new duplicate already in the cache.
$calendar = new RecoveryCalendar(9100);
$calendar->items = [$original, $moved, $following];
$check($calendar->Synchronize(), 'The duplicate-cache baseline must synchronize.');
$calendar->incremental = true;
$calendar->items = [$moved];
$check($calendar->Synchronize(), 'The moved exception delta must synchronize.');
$events = json_decode($calendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR);
$check(count($events) === 2 && !in_array('old-id', array_column($events, 'eventReference'), true), 'A new exception ID must replace the former occurrence, including pre-existing cached duplicates.');
$calendar->items = [];
$check($calendar->Synchronize() && count(json_decode($calendar->GetEvents(), true)) === 2, 'An empty next delta must preserve the repaired occurrence cache.');

// Secondary identity must never collapse unrelated series or non-Microsoft events.
$cases = [
    'missing original start'      => [$original, array_merge($moved, ['originalStart' => '']), 1],
    'original anchor without UID' => [array_merge($original, ['uid' => '']), array_merge($moved, ['uid' => '']), 1],
    'equivalent UTC anchor'       => [
        array_merge($original, ['uid' => '', 'allDay' => false, 'originalStart' => '2026-09-20T10:00:00+02:00']),
        array_merge($moved, ['uid' => '', 'allDay' => false, 'originalStart' => '2026-09-20T08:00:00Z']), 1
    ],
    'different series'        => [$original, array_merge($moved, ['seriesId' => 'other-series']), 2],
    'different calendar'      => [$original, array_merge($moved, ['resourceUrl' => 'https://graph.microsoft.com/v1.0/me/calendars/other/events/new-id']), 2],
    'unrelated occurrence'    => [$original, array_merge($moved, ['uid' => 'other-uid', 'originalStart' => $following['originalStart']]), 2],
    'unknown anchor timezone' => [
        array_merge($original, ['uid' => '', 'originalStart' => '2026-09-20']),
        array_merge($moved, ['uid' => '', 'originalStart' => '2026-09-20T22:00:00Z']), 2
    ],
    'CalDAV shared UID' => [
        array_merge($original, ['resourceUrl' => 'https://caldav.example/one.ics']),
        array_merge($moved, ['resourceUrl' => 'https://caldav.example/two.ics']), 2
    ],
    'Google shared UID' => [
        array_merge($original, ['resourceUrl' => 'https://www.googleapis.com/calendar/v3/calendars/test/events/one']),
        array_merge($moved, ['resourceUrl' => 'https://www.googleapis.com/calendar/v3/calendars/test/events/two']), 2
    ]
];
foreach ($cases as $label => [$before, $after, $expectedCount]) {
    $calendar = new RecoveryCalendar(9101);
    $calendar->items = [$before];
    $check($calendar->Synchronize(), 'Identity baseline must synchronize: ' . $label);
    $calendar->incremental = true;
    $calendar->items = [$after];
    $check($calendar->Synchronize(), 'Identity delta must synchronize: ' . $label);
    $events = json_decode($calendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR);
    $check(count($events) === $expectedCount, 'Occurrence identity must respect provider, calendar and series boundaries: ' . $label);
    if ($label === 'missing original start') {
        $check(($events[0]['originalStart'] ?? '') === $original['originalStart'], 'An ID-changing delta must restore the occurrence anchor using its Microsoft UID.');
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Calendar recovery tests passed.\n");
