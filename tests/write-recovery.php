<?php

declare(strict_types=1);

use IPSKalender\CalendarEventRecurrence;

require_once __DIR__ . '/stubs/autoload.php';
require_once dirname(__DIR__) . '/Kalender/module.php';

/** Exercises real Calendar workflows with failures only at the provider boundary. */
final class RecoveryCalendar extends Calendar
{
    public array $attributes = [];
    public array $requests = [];
    public array $items = [];
    public bool $failReads = false;
    public bool $failWrites = false;
    public bool $incremental = false;
    public bool $failCacheWrite = false;
    public string $nextSyncToken = 'delta-next';

    protected function HasActiveParent(): bool
    {
        return true;
    }

    protected function ReadPropertyBoolean(string $Name): bool
    {
        return true;
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
        if ($operation === 'GetCalendars'
            || ($this->failReads && in_array($operation, ['BeginEventsTransfer', 'GetEventAfterWrite', 'GetEventForEdit'], true))
            || ($this->failWrites && in_array($operation, ['CreateEvent', 'UpdateEvent', 'DeleteEvent'], true))) {
            return json_encode(['Success' => false, 'Error' => 'Injected provider failure: ' . $operation], JSON_THROW_ON_ERROR);
        }
        $token = str_repeat('a', 32);
        $payload = match ($operation) {
            'BeginEventsTransfer' => [
                'Token'     => $token, 'PageCount' => 1, 'ItemCount' => count($this->items),
                'SyncToken' => $this->nextSyncToken, 'Incremental' => $this->incremental
            ],
            'ReadEventsTransferPage' => [
                'Token'     => $token, 'Page' => 0, 'PageCount' => 1,
                'ItemCount' => count($this->items), 'Complete' => true, 'Items' => $this->items
            ],
            'FinishEventsTransfer', 'DeleteEvent' => ['success' => true],
            'UpdateEvent'                         => ['uid' => $request['UID'], 'resourceUrl' => $request['ResourceURL'], 'etag' => 'updated-etag'],
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

$event = recoveryEvent('event', 'Normal event');
foreach (['CreateEvent', 'UpdateEvent', 'DeleteEvent'] as $operation) {
    foreach ([false, true] as $failWrites) {
        $calendar = new RecoveryCalendar(9091);
        $calendar->failWrites = $failWrites;
        $calendar->failReads = true;
        $calendar->failCacheWrite = $operation === 'DeleteEvent';
        $calendar->attributes['CachedEvents'] = json_encode([$event], JSON_THROW_ON_ERROR);
        $input = $operation === 'UpdateEvent'
            ? array_merge($event, ['changes' => ['summary' => 'Changed']])
            : $event;
        $result = $calendar->$operation(json_encode($input, JSON_THROW_ON_ERROR));
        $success = is_bool($result) ? $result : json_decode($result, true, 512, JSON_THROW_ON_ERROR)['success'];
        $check($success === !$failWrites, $operation . ': confirmed writes must survive subsequent local/refresh failures; rejected writes must fail.');
        $check(($calendar->attributes['LastError'] ?? '') !== '', $operation . ': failure diagnostic must be retained.');
        if ($operation === 'DeleteEvent') {
            $check(!in_array('BeginEventsTransfer', array_column($calendar->requests, 'Operation'), true), 'Deletion must retain the 9.1 local-cache optimization.');
        }
    }
}
$calendar = new RecoveryCalendar(9092);
$calendar->items = [$event];
$check($calendar->Synchronize(), 'Initial synchronization must succeed.');
$previousToken = $calendar->attributes['IncrementalSyncToken'];
$calendar->nextSyncToken = 'delta-new';
$calendar->incremental = true;
$calendar->items = [recoveryEvent('new-event', 'New event')];
$calendar->failCacheWrite = true;
$check(!$calendar->Synchronize(), 'Injected cache persistence failure must fail synchronization.');
$check($calendar->attributes['IncrementalSyncToken'] === $previousToken, 'Failed cache persistence must not advance the delta token.');
if ($failures !== []) {
    throw new RuntimeException(implode(PHP_EOL, $failures));
}
fwrite(STDOUT, "Confirmed write and sync persistence recovery tests passed.\n");
