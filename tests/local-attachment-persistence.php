<?php

declare(strict_types=1);

// Reuse the platform double; executes the local calendar regression suite too.
require_once __DIR__ . '/local-calendar-module.php';

$operation = new ReflectionMethod(Calendar::class, 'localAttachmentOperation');
$calendar = localModuleNew(5042);
$calendar->properties['AttachmentMode'] = 2;
$calendar->properties['AttachmentAllowLocal'] = true;
$owner = hash('sha256', 'fixture verified calendar5042/event1');
$payload = ['requestId' => hash('sha256', 'request1'), 'name' => 'private.txt', 'content' => base64_encode('PRIVATE_ATTACHMENT_BYTES')];
$call = static fn (Calendar $instance, string $op, array $value = []): mixed => $operation->invoke($instance, $op, $owner, $value);
$reject = static function (callable $callback): void
{
    try {
        $callback();
    } catch (RuntimeException | InvalidArgumentException | JsonException) {
        return;
    }
    throw new LogicException('Expected persistence rejection.');
};
$meta = $call($calendar, 'upload', $payload);
$original = $calendar->attributes['LocalAttachmentOriginals'];
localModuleCheck($original !== '' && $GLOBALS['localLocks'] === [], 'Upload must persist and unlock.');
localModuleCheck($call($calendar, 'download', ['id' => $meta['id']]) === 'PRIVATE_ATTACHMENT_BYTES', 'Binary read');
localModuleCheck($call($calendar, 'upload', $payload) === $meta, 'Retry must not duplicate.');
$calendar->ClearCache();
$calendar->ApplyChanges();
localModuleCheck($calendar->attributes['LocalAttachmentOriginals'] === $original, 'Cache/lifecycle must preserve attachments.');
localModuleCheck(!str_contains($calendar->GetEvents(), 'PRIVATE_ATTACHMENT_BYTES'), 'No original bytes in events.');
$restart = new Calendar(5042);
$restart->properties = $calendar->properties;
$restart->attributes = $calendar->attributes;
$restart->Create();
$restart->ApplyChanges();
localModuleCheck($call($restart, 'list') === [$meta], 'Simulated restart restores attachment metadata.');
$restart->failAttachmentSave = true;
$reject(fn () => $call($restart, 'delete', ['id' => $meta['id'], 'revision' => $meta['revision']]));
localModuleCheck($restart->attributes['LocalAttachmentOriginals'] === $original && $GLOBALS['localLocks'] === [], 'Failed write preserves originals and unlocks.');
$restart->failAttachmentSave = false;
$GLOBALS['localLockDenied'] = true;
$reject(fn () => $call($restart, 'list'));
$GLOBALS['localLockDenied'] = false;

// Rights can be revoked while the request waits for its lock.
$GLOBALS['localLockHook'] = static function () use ($restart): void
{
    $restart->properties['AttachmentAllowLocal'] = false;
};
$reject(fn () => $call($restart, 'download', ['id' => $meta['id']]));
localModuleCheck($GLOBALS['localLocks'] === [] && $restart->attributes['LocalAttachmentOriginals'] === $original, 'Revocation must fail closed.');
$restart->properties['AttachmentAllowLocal'] = true;
$restart->properties['AttachmentMode'] = 1;
$reject(fn () => $call($restart, 'delete', ['id' => $meta['id'], 'revision' => $meta['revision']]));
localModuleCheck($call($restart, 'list') === [$meta], 'Read-only listing');
$restart->properties['AttachmentMode'] = 2;

// Another completed transaction becomes visible at lock acquisition.
$snapshot = new IPSKalender\LocalAttachmentStore($original);
$snapshot->add($owner, hash('sha256', 'other request'), 'other.txt', base64_encode('OTHER'));
$GLOBALS['localLockHook'] = static function () use ($restart, $snapshot): void
{
    $restart->attributes['LocalAttachmentOriginals'] = $snapshot->exportSnapshot();
};
$call($restart, 'delete', ['id' => $meta['id'], 'revision' => $meta['revision']]);
localModuleCheck(count($call($restart, 'list')) === 1, 'Transaction must read the latest snapshot under lock.');
$restart->attributes['LocalAttachmentOriginals'] = '{broken';
$reject(fn () => $call($restart, 'list'));
localModuleCheck($restart->attributes['LocalAttachmentOriginals'] === '{broken' && $GLOBALS['localLocks'] === [], 'Corruption must never reset originals.');
fwrite(STDOUT, "Attachment persistence: module lifecycle, simulated restart, revocation, locked updates and failed-save recovery passed.\n");
