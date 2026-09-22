<?php

declare(strict_types=1);

require_once __DIR__ . '/local-calendar-module.php';

use IPSKalender\LocalAttachmentStore;

foreach (['GetLocalAttachmentInventory', 'BeginLocalAttachmentBackup', 'ReadLocalAttachmentBackupPage', 'FinishLocalAttachmentBackup', 'DeleteLocalAttachmentOriginals'] as $method) {
    localModuleCheck(method_exists(Calendar::class, $method), 'Missing attachment maintenance API: ' . $method);
}

$reject = static function (callable $callback): void
{
    try {
        $callback();
    } catch (RuntimeException | InvalidArgumentException | JsonException) {
        return;
    }
    throw new LogicException('Expected attachment maintenance rejection.');
};
$decode = static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$calendar = localModuleNew(7042);
$empty = $decode($calendar->GetLocalAttachmentInventory());
localModuleCheck($empty['files'] === [] && $empty['totalBytes'] === 0, 'Empty inventory must work with attachments disabled.');
$emptyBackup = $decode($calendar->BeginLocalAttachmentBackup());
$emptyPage = $decode($calendar->ReadLocalAttachmentBackupPage($emptyBackup['Token'], 0));
localModuleCheck(base64_decode($emptyPage['Items'][0], true) === '{"version":1,"records":[]}', 'Empty backup must still be a valid restorable snapshot.');
$calendar->FinishLocalAttachmentBackup($emptyBackup['Token']);

// Recover a real attachment after its event has been removed, with view access disabled.
$calendar->properties['AttachmentMode'] = 2;
$calendar->properties['AttachmentAllowLocal'] = true;
$calendar->CreateEvent(json_encode(localModuleEvent('Recovery owner'), JSON_THROW_ON_ERROR));
$event = $decode($calendar->GetEvents())[0];
$selector = array_intersect_key($event, array_flip(['uid', 'startTimestamp', 'endTimestamp']));
$uploaded = $decode($calendar->TransferLocalAttachment(json_encode([
    'operation' => 'upload', 'selector' => $selector,
    'data'      => ['requestId' => hash('sha256', 'recovery'), 'name' => 'recovery.txt', 'content' => base64_encode('RECOVERY_PRIVATE')]
], JSON_THROW_ON_ERROR)))['result'];
$calendar->DeleteEvent(json_encode($event, JSON_THROW_ON_ERROR));
$calendar->properties['AttachmentMode'] = 0;
$calendar->properties['AttachmentAllowLocal'] = false;
$calendar->properties['Active'] = false;
$inventory = $decode($calendar->GetLocalAttachmentInventory());
localModuleCheck($inventory['files'] === [$uploaded], 'Deleted-event files must remain administratively accessible.');
localModuleCheck(!str_contains(json_encode($inventory), 'RECOVERY_PRIVATE') && !isset($inventory['files'][0]['owner']), 'Inventory must contain metadata only.');
$original = $calendar->attributes['LocalAttachmentOriginals'];

foreach (['[]', '["bad"]', json_encode([$uploaded['id'], $uploaded['id']]), json_encode([$uploaded['id'], str_repeat('f', 64)]), '{}', str_repeat('x', 8193)] as $selection) {
    $reject(fn () => $calendar->DeleteLocalAttachmentOriginals($selection, $inventory['revision']));
    localModuleCheck($calendar->attributes['LocalAttachmentOriginals'] === $original, 'Invalid selection must not partially delete originals.');
}
$ids = json_encode([$uploaded['id']], JSON_THROW_ON_ERROR);
$reject(fn () => $calendar->DeleteLocalAttachmentOriginals($ids, $empty['revision']));
$calendar->failAttachmentSave = true;
$reject(fn () => $calendar->DeleteLocalAttachmentOriginals($ids, $inventory['revision']));
localModuleCheck($calendar->attributes['LocalAttachmentOriginals'] === $original, 'Failed persistence must preserve originals.');
$calendar->failAttachmentSave = false;
$GLOBALS['localLockDenied'] = true;
$reject(fn () => $calendar->GetLocalAttachmentInventory());
$reject(fn () => $calendar->BeginLocalAttachmentBackup());
$reject(fn () => $calendar->DeleteLocalAttachmentOriginals($ids, $inventory['revision']));
$GLOBALS['localLockDenied'] = false;

// Read the latest committed store after waiting for the same upload/delete lock.
$concurrent = new LocalAttachmentStore($original);
$other = $concurrent->add(hash('sha256', 'other owner'), hash('sha256', 'other upload'), 'other.txt', base64_encode('OTHER_PRIVATE'));
$GLOBALS['localLockHook'] = static function () use ($calendar, $concurrent): void
{
    $calendar->attributes['LocalAttachmentOriginals'] = $concurrent->exportSnapshot();
};
$reject(fn () => $calendar->DeleteLocalAttachmentOriginals($ids, $inventory['revision']));
localModuleCheck(count($decode($calendar->GetLocalAttachmentInventory())['files']) === 2, 'Stale cleanup must preserve concurrent uploads.');
$current = $decode($calendar->GetLocalAttachmentInventory());
localModuleCheck($calendar->DeleteLocalAttachmentOriginals($ids, $current['revision']) === 1, 'Explicit cleanup must delete selected originals.');
localModuleCheck($decode($calendar->GetLocalAttachmentInventory())['files'] === [$other], 'Cleanup must preserve unrelated files.');
$reject(fn () => $calendar->DeleteLocalAttachmentOriginals($ids, $current['revision']));

// Full quota, including binary bytes, must fit bounded script responses and tiny buffers.
$store = new LocalAttachmentStore();
$bytes = str_repeat("PRIVATE_BINARY\0\xff", 131072);
localModuleCheck(strlen($bytes) === LocalAttachmentStore::MAX_FILE_BYTES, 'Large binary fixture');
$owner = hash('sha256', 'backup owner');
for ($index = 0; $index < 4; ++$index) {
    $store->add($owner, hash('sha256', 'large-' . $index), 'large-' . $index . '.bin', base64_encode($bytes));
}
$calendar->attributes['LocalAttachmentOriginals'] = $store->exportSnapshot();
$metadata = $decode($calendar->BeginLocalAttachmentBackup());
localModuleCheck($metadata['PageCount'] > 1 && $metadata['CalendarInstanceID'] === 7042, 'Backup must be paged and identify its source instance.');
localModuleCheck($metadata['Format'] === 'OpenCalendar.LocalAttachments' && $metadata['Version'] === 1, 'Versioned backup format');
localModuleCheck(array_sum(array_map('strlen', $calendar->buffers)) < 16 * 1024, 'Backup must not fill instance buffers.');
localModuleCheck(!str_contains(json_encode($calendar->buffers), base64_encode('PRIVATE_BINARY')), 'No original bodies in buffers.');
$elsewhere = localModuleNew(7043);
$reject(fn () => $elsewhere->ReadLocalAttachmentBackupPage($metadata['Token'], 0));
$reject(fn () => $calendar->ReadEventsTransferPage($metadata['Token'], 0));
$reject(fn () => $calendar->ReadLocalAttachmentBackupPage($metadata['Token'], -1));
$reject(fn () => $calendar->ReadLocalAttachmentBackupPage($metadata['Token'], $metadata['PageCount']));

// The backup is an immutable point-in-time snapshot even if cleanup happens during download.
$current = $decode($calendar->GetLocalAttachmentInventory());
$calendar->DeleteLocalAttachmentOriginals(json_encode([$current['files'][0]['id']]), $current['revision']);
$snapshot = '';
try {
    for ($page = 0; $page < $metadata['PageCount']; ++$page) {
        $raw = $calendar->ReadLocalAttachmentBackupPage($metadata['Token'], $page);
        localModuleCheck(strlen($raw) < 200 * 1024, 'Backup response must remain below the Symcon transfer limit.');
        $result = $decode($raw);
        localModuleCheck($result['Complete'] === ($page === $metadata['PageCount'] - 1), 'Backup completion flag');
        foreach ($result['Items'] as $chunk) {
            $decoded = base64_decode($chunk, true);
            localModuleCheck(is_string($decoded), 'Backup chunks must be valid base64.');
            $snapshot .= $decoded;
        }
    }
} finally {
    localModuleCheck($calendar->FinishLocalAttachmentBackup($metadata['Token']), 'Backup temporary data must be removable.');
}
localModuleCheck(strlen($snapshot) === $metadata['Bytes'] && hash('sha256', $snapshot) === $metadata['SHA256'], 'Backup length and digest must match.');
localModuleCheck($snapshot === $store->exportSnapshot(), 'Full backup must preserve every original and binding exactly.');
$restored = new LocalAttachmentStore($snapshot);
foreach ($restored->listForOwner($owner) as $file) {
    localModuleCheck($restored->read($owner, $file['id']) === $bytes, 'Restore must preserve exact binary bytes.');
}
localModuleCheck(count($restored->listForOwner($owner)) === 4, 'Cleanup must not alter an already started backup.');
$reject(fn () => $calendar->ReadLocalAttachmentBackupPage($metadata['Token'], 0));
localModuleCheck(!$calendar->FinishLocalAttachmentBackup($metadata['Token']), 'Repeated backup cleanup must be harmless.');

class AttachmentBackupClockCalendar extends Calendar
{
    public int $timestamp = 1_800_000_000;

    protected function GetChunkedJsonTransferTimestamp(): int
    {
        return $this->timestamp;
    }
}
$clock = new AttachmentBackupClockCalendar(7044);
$clock->Create();
$expired = $decode($clock->BeginLocalAttachmentBackup());
$clock->timestamp += 301;
$reject(fn () => $clock->ReadLocalAttachmentBackupPage($expired['Token'], 0));
localModuleCheck(!$clock->FinishLocalAttachmentBackup($expired['Token']), 'Expired backup must clean up its transfer metadata.');

$calendar->attributes['LocalAttachmentOriginals'] = '{broken';
$reject(fn () => $calendar->GetLocalAttachmentInventory());
$reject(fn () => $calendar->BeginLocalAttachmentBackup());
$reject(fn () => $calendar->DeleteLocalAttachmentOriginals($ids, $current['revision']));
localModuleCheck($calendar->attributes['LocalAttachmentOriginals'] === '{broken' && $GLOBALS['localLocks'] === [], 'Corruption must not erase originals or leak locks.');
fwrite(STDOUT, "Attachment maintenance: disabled/deleted-owner recovery, full-quota backup, isolation, atomic cleanup and stale-write protection passed.\n");
