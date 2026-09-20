<?php

declare(strict_types=1);

require_once __DIR__ . '/local-calendar-module.php';

$gate = new ReflectionMethod(Calendar::class, 'verifiedLocalAttachmentOperation');
$calendar = localModuleNew(6042);
$calendar->properties['AttachmentMode'] = 2;
$calendar->properties['AttachmentAllowLocal'] = true;
$created = json_decode($calendar->CreateEvent(json_encode(localModuleEvent('Document owner'), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR)['event'];
$created = json_decode($calendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR)[0];
$selector = ['uid' => $created['uid'], 'startTimestamp' => $created['startTimestamp'], 'endTimestamp' => $created['endTimestamp']];
$request = ['requestId' => hash('sha256', 'upload'), 'name' => 'private.txt', 'content' => base64_encode('PRIVATE')];
$call = static fn (Calendar $instance, string $op, array $event, array $value = []): mixed => $gate->invoke($instance, $op, $event, $value);
$reject = static function (callable $callback): void
{
    try {
        $callback();
    } catch (RuntimeException | InvalidArgumentException) {
        return;
    }
    throw new LogicException('Expected authoritative ownership denial.');
};
$meta = $call($calendar, 'upload', $selector, $request);
$publicResult = json_decode($calendar->TransferLocalAttachment(json_encode([
    'operation' => 'download', 'selector' => $selector, 'data' => ['id' => $meta['id']]
], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
localModuleCheck(base64_decode($publicResult['content'], true) === 'PRIVATE', 'Trusted transfer API must use authoritative ownership.');
$reject(fn () => $calendar->TransferLocalAttachment(json_encode([
    'operation' => 'download', 'selector' => array_replace($selector, ['uid' => 'foreign']), 'data' => ['id' => $meta['id']]
], JSON_THROW_ON_ERROR)));
localModuleCheck($call($calendar, 'download', $selector, ['id' => $meta['id']]) === 'PRIVATE', 'Verified local binary roundtrip failed.');
$calendar->ClearCache();
localModuleCheck($call($calendar, 'list', $selector) === [$meta], 'Lookup must use originals, not event cache.');
$reject(fn () => $call($calendar, 'download', array_replace($selector, ['uid' => 'foreign']), ['id' => $meta['id']]));
$reject(fn () => $call($calendar, 'list', $selector + ['resourceUrl' => 'http://outside.invalid/']));
$reject(fn () => $call($calendar, 'list', $selector + ['owner' => hash('sha256', 'forged')]));
$reject(fn () => $call($calendar, 'list', array_replace($selector, ['endTimestamp' => $selector['startTimestamp'] + 8 * 86400])));
$other = localModuleNew(6043);
$other->properties['AttachmentMode'] = 2;
$other->properties['AttachmentAllowLocal'] = true;
$reject(fn () => $call($other, 'list', $selector));
$series = localModuleEvent('Series') + ['recurrence' => ['frequency' => 'DAILY', 'interval' => 1, 'endMode' => 'count', 'count' => 3]];
$other->CreateEvent(json_encode($series, JSON_THROW_ON_ERROR));
$occurrences = json_decode($other->GetEvents(), true, 512, JSON_THROW_ON_ERROR);
localModuleCheck(count($occurrences) === 3, 'Series fixture');
$slotSelector = static fn (array $event): array => array_intersect_key($event, array_flip(['uid', 'startTimestamp', 'endTimestamp', 'recurrenceId', 'originalStart']));
$firstSlot = $slotSelector($occurrences[0]);
$seriesMeta = $call($other, 'upload', $firstSlot, $request);
localModuleCheck($call($other, 'list', $firstSlot) === [$seriesMeta], 'First series occurrence owner');
localModuleCheck($call($other, 'list', $slotSelector($occurrences[1])) === [], 'Next occurrence must not inherit files.');
$withoutSlot = $firstSlot;
unset($withoutSlot['recurrenceId'], $withoutSlot['originalStart']);
$reject(fn () => $call($other, 'list', $withoutSlot));
$before = $calendar->attributes['LocalAttachmentOriginals'];
$GLOBALS['localLockHook'] = static function () use ($calendar): void
{
    $calendar->properties['AttachmentAllowLocal'] = false;
};
$reject(fn () => $call($calendar, 'list', $selector));
localModuleCheck($GLOBALS['localLocks'] === [], 'Revocation leaked lock.');
$calendar->properties['AttachmentAllowLocal'] = true;
$calendar->DeleteEvent(json_encode($created, JSON_THROW_ON_ERROR));
$reject(fn () => $call($calendar, 'download', $selector, ['id' => $meta['id']]));
localModuleCheck($before === $calendar->attributes['LocalAttachmentOriginals'], 'Deleting event must not silently erase originals.');
$calendar->properties['LocalCalendar'] = false;
$reject(fn () => $call($calendar, 'list', $selector));
localModuleCheck($GLOBALS['localLocks'] === [], 'Failed ownership check leaked lock.');
fwrite(STDOUT, "Authoritative local attachment access: originals, selection rejection, deletion and revocation passed.\n");
