<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once dirname(__DIR__) . '/Kalender Ansicht/module.php';
require_once dirname(__DIR__) . '/libs/CalendarEventRecurrence.php';

$lookupResult = null;
$rollbackRequests = [];
function IPSKAL_DeleteEvent(int $instanceId, string $eventJson): bool
{
    $GLOBALS['rollbackRequests'][] = [$instanceId, json_decode($eventJson, true, 512, JSON_THROW_ON_ERROR)];
    return true;
}
function IPSKAL_GetRecurringFollowing(int $instanceId, string $seriesId, string $occurrenceId, string $originalStart, string $resourceUrl): mixed
{
    return $GLOBALS['lookupResult'];
}

function taskTransferExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$view = new CalendarView(9014);
$prepare = new ReflectionMethod(CalendarView::class, 'prepareTaskSeriesTransfer');
$original = array_merge(
    IPSKalender\CalendarEventRecurrence::occurrence('series', 'first', '2026-09-12', '', true, false, true, true, true),
    ['uid' => 'first', 'resourceUrl' => 'first', 'summary' => '[OC:TODO] Task']
);
$verified = array_merge($original, [
    'etag'               => 'fresh', 'timezone' => 'Europe/Berlin',
    'recurrenceSettings' => ['frequency' => 'DAILY', 'interval' => 10, 'endMode' => 'count', 'count' => 4]
]);
$lookupResult = json_encode($verified, JSON_THROW_ON_ERROR);
foreach ([true, false] as $follow) {
    foreach (['2026-09-10', '2026-09-12', '2026-09-17'] as $date) {
        $source = $original;
        $target = ['task' => true, 'taskCompleted' => false, 'taskFollowPlanned' => $follow,
            'start'       => $date, 'summary' => 'Task', 'status' => 'TENTATIVE', 'transparency' => 'TRANSPARENT'];
        $prepare->invokeArgs($view, [9013, &$source, &$target]);
        taskTransferExpect($source['writeScope'] === ($follow ? 'following' : 'occurrence'), 'Transfer must respect the selected series scope.');
        taskTransferExpect(!$target['taskCompleted'] && $target['status'] === 'TENTATIVE'
            && $target['transparency'] === 'TRANSPARENT', 'Transfer must preserve independent task and event states.');
        taskTransferExpect($follow
            ? $target['recurrence']['count'] === 4 && $target['recurrence']['interval'] === 10
            : !isset($target['recurrence']), 'Transfer must retain the verified series interval and remaining count only when requested.');
    }
}
foreach ([false, '', '{', 'null', '{}', json_encode(array_merge($verified, ['canUpdateFollowing' => false]))] as $invalidResult) {
    $lookupResult = $invalidResult;
    $source = $original;
    $target = ['task' => true, 'taskFollowPlanned' => true, 'start' => '2026-09-17'];
    $thrown = false;
    try {
        $prepare->invokeArgs($view, [9013, &$source, &$target]);
    } catch (RuntimeException) {
        $thrown = true;
    }
    taskTransferExpect($thrown, 'Unsafe or failed series lookup must fail before creating a target event.');
    taskTransferExpect($source === $original && !isset($target['recurrence']), 'Failed preparation must not change source scope or target recurrence.');
}
$rollback = new ReflectionMethod(CalendarView::class, 'rollbackMovedTargetEvent');
$nativeCreated = ['sourceType' => 'microsoft-todo', 'taskProvider' => 'microsoft-todo',
    'taskId'                   => 'created-task', 'taskListId' => 'target-list', 'uid' => 'microsoft-todo:created-task'];
foreach ([false, true] as $recurring) {
    taskTransferExpect($rollback->invoke($view, 9015, ['event' => $nativeCreated], $recurring), 'Native target task rollback must succeed.');
    $rollbackRequest = $rollbackRequests[array_key_last($rollbackRequests)][1];
    taskTransferExpect(($rollbackRequest['sourceType'] ?? '') === 'microsoft-todo'
        && ($rollbackRequest['taskId'] ?? '') === 'created-task'
        && ($rollbackRequest['taskListId'] ?? '') === 'target-list'
        && !isset($rollbackRequest['seriesId']), 'Rollback must retain task-list identity, never route a native task as a calendar series.');
}
$rollback->invoke($view, 9015, ['event' => ['uid' => 'normal', 'eventReference' => 'series', 'resourceUrl' => 'url']], true);
$rollbackRequest = $rollbackRequests[array_key_last($rollbackRequests)][1];
taskTransferExpect(($rollbackRequest['writeScope'] ?? '') === 'series'
    && ($rollbackRequest['seriesId'] ?? '') === 'series', 'Normal calendar series rollback must remain unchanged.');
fwrite(STDOUT, "Task series transfer tests passed.\n");
