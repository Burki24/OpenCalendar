<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AttachmentOwnerIdentity.php';
require_once __DIR__ . '/../libs/MicrosoftTodoTaskProjection.php';
require_once __DIR__ . '/../libs/LocalAttachmentStore.php';

use IPSKalender\AttachmentOwnerIdentity;
use IPSKalender\MicrosoftTodoTaskProjection;

function ownerCheck(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Attachment owner isolation/stability failed.');
    }
}
function ownerRejects(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Ambiguous attachment identity was accepted.');
}

$scope = ['instanceId' => 42, 'provider' => 'caldav', 'accountId' => 'account1', 'calendarId' => 'calendar1'];
$single = ['uid' => 'uid1', 'recurrenceType' => 'single', 'summary' => 'same'];
$key = AttachmentOwnerIdentity::key($scope, $single);
ownerCheck(strlen($key) === 64);
ownerCheck($key === AttachmentOwnerIdentity::key($scope, array_replace($single, ['summary' => 'new', 'start' => '2030-01-01', 'etag' => 'new'])));
foreach (['instanceId' => 43, 'accountId' => 'account2', 'calendarId' => 'calendar2', 'provider' => 'ical'] as $field => $value) {
    ownerCheck($key !== AttachmentOwnerIdentity::key(array_replace($scope, [$field => $value]), $single));
}
ownerCheck($key !== AttachmentOwnerIdentity::key($scope, array_replace($single, ['uid' => 'uid2'])));
$occurrence = array_replace($single, ['recurrenceType' => 'occurrence', 'recurrenceId' => '20260920T090000Z']);
$occurrenceKey = AttachmentOwnerIdentity::key($scope, $occurrence);
ownerCheck($key !== $occurrenceKey);
ownerCheck($occurrenceKey === AttachmentOwnerIdentity::key($scope, array_replace($occurrence, ['recurrenceType' => 'exception', 'start' => '2030-01-01'])));
ownerCheck($occurrenceKey !== AttachmentOwnerIdentity::key($scope, array_replace($occurrence, ['recurrenceId' => '20260921T090000Z'])));
ownerCheck($occurrenceKey !== AttachmentOwnerIdentity::key($scope, array_replace($single, ['recurrenceType' => 'master'])));
ownerRejects(fn () => AttachmentOwnerIdentity::key($scope, ['summary' => 'same']));
ownerRejects(fn () => AttachmentOwnerIdentity::key($scope, array_replace($single, ['recurrenceType' => 'unknown'])));
ownerRejects(fn () => AttachmentOwnerIdentity::key($scope, array_replace($single, ['recurrenceType' => 'occurrence'])));
ownerRejects(fn () => AttachmentOwnerIdentity::key($scope, array_replace($single, ['recurring' => true])));
ownerRejects(fn () => AttachmentOwnerIdentity::key(array_replace($scope, ['accountId' => '']), $single));
ownerRejects(fn () => AttachmentOwnerIdentity::key(array_replace($scope, ['provider' => 'google']), $single));

$google = array_replace($scope, ['provider' => 'google']);
$googleEvent = ['eventReference' => 'event1', 'recurrenceType' => 'single'];
$googleKey = AttachmentOwnerIdentity::key($google, $googleEvent);
ownerCheck($googleKey === AttachmentOwnerIdentity::key($google, array_replace($googleEvent, ['summary' => 'changed'])));
ownerCheck($googleKey !== AttachmentOwnerIdentity::key(array_replace($google, ['accountId' => 'other']), $googleEvent));
ownerCheck($googleKey !== AttachmentOwnerIdentity::key($google, array_replace($googleEvent, ['eventReference' => 'event2'])));
$googleOccurrence = ['seriesId' => 'series1', 'eventReference' => 'instance1', 'originalStart' => '2026-09-20T09:00:00Z', 'recurrenceType' => 'occurrence'];
ownerCheck(AttachmentOwnerIdentity::key($google, $googleOccurrence) === AttachmentOwnerIdentity::key($google,
    array_replace($googleOccurrence, ['recurrenceType' => 'exception', 'eventReference' => 'instance2'])));

$ms = array_replace($scope, ['provider' => 'microsoft']);
$event = ['eventReference' => 'event1', 'recurrenceType' => 'single'];
$eventKey = AttachmentOwnerIdentity::key($ms, $event);
$msOccurrence = ['seriesId' => 'series1', 'eventReference' => 'instance1', 'originalStart' => '2026-09-20T09:00:00Z', 'recurrenceType' => 'occurrence'];
ownerCheck(AttachmentOwnerIdentity::key($ms, $msOccurrence) === AttachmentOwnerIdentity::key($ms, array_replace($msOccurrence, ['recurrenceType' => 'exception', 'eventReference' => 'changed-instance'])));
$task = ['id' => 'task1', 'listId' => 'list1', 'title' => 'same', 'dueDateTime' => ['dateTime' => '2026-09-20T00:00:00', 'timeZone' => 'UTC']];
$projection = MicrosoftTodoTaskProjection::project([$task])[0];
$taskKey = AttachmentOwnerIdentity::key($ms, $projection);
ownerCheck($taskKey !== $eventKey);
$completed = MicrosoftTodoTaskProjection::project([array_replace($task, ['status' => 'completed'])])[0];
ownerCheck($taskKey === AttachmentOwnerIdentity::key($ms, $completed));
foreach (['id' => 'next-task', 'listId' => 'other-list'] as $field => $value) {
    $other = MicrosoftTodoTaskProjection::project([array_replace($task, [$field => $value])])[0];
    ownerCheck($taskKey !== AttachmentOwnerIdentity::key($ms, $other));
}
ownerRejects(fn () => AttachmentOwnerIdentity::key($scope, $projection));
$store = new IPSKalender\LocalAttachmentStore();
$document = $store->add($occurrenceKey, hash('sha256', 'attachment retry'), 'private.txt', base64_encode('private'));
$restored = new IPSKalender\LocalAttachmentStore($store->exportSnapshot());
$movedKey = AttachmentOwnerIdentity::key($scope, array_replace($occurrence, ['recurrenceType' => 'exception', 'start' => '2030-01-01']));
ownerCheck($restored->read($movedKey, $document['id']) === 'private');
ownerCheck($restored->listForOwner($key) === []);
ownerCheck($restored->listForOwner(AttachmentOwnerIdentity::key(array_replace($scope, ['accountId' => 'other']), $occurrence)) === []);
fwrite(STDOUT, "Attachment owner identities: source isolation, series exceptions and real To Do projection passed.\n");
