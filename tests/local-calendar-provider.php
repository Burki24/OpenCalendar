<?php

declare(strict_types=1);

use IPSKalender\CalendarTaskEvent;
use IPSKalender\LocalCalendarProvider;
use IPSKalender\LocalCalendarResourceStore;

require_once __DIR__ . '/../libs/LocalCalendarProvider.php';
require_once __DIR__ . '/../libs/CalendarTaskEvent.php';

function localExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function localFails(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

$reference = 'https://opencalendar.invalid/local/12345/';
$provider = new LocalCalendarProvider([], $reference);
$start = new DateTimeImmutable('2026-09-01');
$end = new DateTimeImmutable('2026-10-01');
$created = $provider->createEvent($reference, [
    'summary' => 'Local appointment', 'start' => '2026-09-14', 'end' => '2026-09-15', 'allDay' => true,
    'status'  => 'TENTATIVE', 'transparency' => 'TRANSPARENT'
]);
localExpect(count($provider->getEvents($reference, $start, $end)) === 1, 'New local event must be visible.');
$originals = $provider->exportResources();
$provider = new LocalCalendarProvider($originals, $reference);
$event = $provider->getEventForEdit($reference, $created);
localExpect($event['status'] === 'TENTATIVE' && $event['transparency'] === 'TRANSPARENT', 'Restart must preserve independent event state.');
localExpect($provider->getEvents($reference, new DateTimeImmutable('2030-01-01'), new DateTimeImmutable('2030-02-01')) === [], 'Query must honor its date range.');
localExpect($provider->exportResources() === $originals, 'Out-of-range query must not remove originals.');
$updated = $provider->updateEvent($reference, $created['resourceUrl'], $created['etag'], $created['uid'], ['summary' => 'Changed']);
localFails(fn () => $provider->updateEvent($reference, $created['resourceUrl'], $created['etag'], $created['uid'], ['summary' => 'Stale edit']), 'Stale editor must not overwrite changes.');
localFails(fn () => $provider->deleteEvent($reference, $created['resourceUrl'], '', ''), 'Delete requires current validator.');
localExpect($provider->deleteEvent($reference, $updated['resourceUrl'], $updated['etag']), 'Current local event must be deletable.');
localExpect($provider->exportResources() === [], 'Delete must remove original resource.');
localFails(fn () => $provider->getEventForEdit($reference, $created), 'Deleted task lookup must fail.');

$task = CalendarTaskEvent::prepareWrite([
    'summary'    => 'Clean fridge', 'task' => true, 'taskCompleted' => false,
    'allDay'     => true, 'start' => '2026-09-01', 'end' => '2026-09-02',
    'recurrence' => ['frequency' => 'DAILY', 'interval' => 10, 'endMode' => 'count', 'count' => 5]
]);
$series = $provider->createEvent($reference, $task);
$events = $provider->getEvents($reference, $start, new DateTimeImmutable('2026-11-01'));
localExpect(array_column($events, 'start') === ['2026-09-01', '2026-09-11', '2026-09-21', '2026-10-01', '2026-10-11'], 'Every-ten-days series must have five correctly dated occurrences.');
localExpect($events[0]['canUpdateOccurrence'] && $events[0]['canUpdateFollowing'], 'Local occurrences must expose writable scopes.');
$second = $provider->getEventForEdit($reference, $events[1]);
$provider->updateEvent($reference, $second['resourceUrl'], $second['etag'], $second['uid'], ['summary' => '[OC:DONE] Clean fridge'], $second);
$events = $provider->getEvents($reference, $start, new DateTimeImmutable('2026-11-01'));
localExpect(CalendarTaskEvent::enrich($events[1])['taskCompleted'], 'Completion must affect the selected occurrence.');
localExpect(!CalendarTaskEvent::enrich($events[0])['taskCompleted'] && !CalendarTaskEvent::enrich($events[2])['taskCompleted'], 'Other occurrences must remain open.');
$first = $provider->getEventForEdit($reference, $events[0]);
$provider->deleteEvent($reference, $first['resourceUrl'], $first['etag'], $first['recurrenceId'], $first);
$events = $provider->getEvents($reference, $start, new DateTimeImmutable('2026-11-01'));
localExpect(count($events) === 4 && $events[0]['start'] === '2026-09-11', 'EXDATE must remove only the selected occurrence.');
$snapshot = $provider->exportResources();
$provider = new LocalCalendarProvider($snapshot, $reference);
localExpect(count($provider->getEvents($reference, $start, new DateTimeImmutable('2026-11-01'))) === 4, 'Exceptions must survive provider recreation.');
$following = $provider->getRecurringFollowing($reference, $series['uid'], $events[1]['occurrenceId'], $events[1]['originalStart'], $series['resourceUrl']);
$provider->updateEvent($reference, $following['resourceUrl'], $following['etag'], $following['uid'], [
    'start'      => '2026-09-23', 'end' => '2026-09-24', 'allDay' => true,
    'recurrence' => CalendarTaskEvent::shiftPlannedRecurrence($following['recurrenceSettings'], $following['originalStart'], '2026-09-23')
], $following);
$events = $provider->getEvents($reference, $start, new DateTimeImmutable('2026-11-01'));
localExpect(array_column($events, 'start') === ['2026-09-11', '2026-09-23', '2026-10-03', '2026-10-13'], 'Following shift must retain previous occurrence and remaining series length.');
localExpect(count($provider->exportResources()) === 2, 'Series split must persist both original resources.');

$future = $events[1];
$master = $provider->getRecurringSeries($reference, $future['seriesId'], $future['resourceUrl']);
$master['writeScope'] = 'series';
$provider->deleteEvent($reference, $master['resourceUrl'], $master['etag'], '', $master);
localExpect(array_column($provider->getEvents($reference, $start, new DateTimeImmutable('2026-11-01')), 'start') === ['2026-09-11'], 'Full series deletion must leave unrelated original series data intact.');

$firstOccurrenceProvider = new LocalCalendarProvider([], $reference);
$firstOccurrenceTask = CalendarTaskEvent::prepareWrite([
    'summary'           => 'Move first task', 'task' => true, 'taskCompleted' => false,
    'taskFollowPlanned' => true, 'allDay' => true,
    'start'             => '2026-09-01', 'end' => '2026-09-02',
    'recurrence'        => ['frequency' => 'DAILY', 'interval' => 1, 'endMode' => 'count', 'count' => 3]
]);
$firstOccurrenceSeries = $firstOccurrenceProvider->createEvent($reference, $firstOccurrenceTask);
$events = $firstOccurrenceProvider->getEvents($reference, $start, $end);
$first = $firstOccurrenceProvider->getEventForEdit($reference, $events[0]);
$firstOccurrenceProvider->updateEvent(
    $reference,
    $first['resourceUrl'],
    $first['etag'],
    $first['uid'],
    CalendarTaskEvent::prepareWrite(['task' => true, 'taskCompleted' => true], $first),
    $first
);
$events = $firstOccurrenceProvider->getEvents($reference, $start, $end);
$reopened = $firstOccurrenceProvider->getEventForEdit($reference, $events[0]);
$firstOccurrenceProvider->updateEvent(
    $reference,
    $reopened['resourceUrl'],
    $reopened['etag'],
    $reopened['uid'],
    CalendarTaskEvent::prepareWrite(['task' => true, 'taskCompleted' => false], $reopened),
    $reopened
);
$events = $firstOccurrenceProvider->getEvents($reference, $start, $end);
localExpect(
    $events[0]['recurring']
        && $events[0]['canUpdateOccurrence']
        && $events[0]['canUpdateFollowing']
        && $events[0]['occurrenceId'] !== ''
        && $events[0]['seriesId'] !== '',
    'Reopening the first local task occurrence must retain its writable recurrence identity.'
);
$reopened = $firstOccurrenceProvider->getEventForEdit($reference, $events[0]);
localExpect(
    $reopened['recurring']
        && $reopened['canUpdateOccurrence']
        && $reopened['canUpdateFollowing']
        && $reopened['occurrenceId'] !== ''
        && $reopened['seriesId'] !== '',
    'The local editor lookup must preserve the writable recurrence identity after reopening a task.'
);
$following = $firstOccurrenceProvider->getRecurringFollowing(
    $reference,
    $firstOccurrenceSeries['uid'],
    $reopened['occurrenceId'],
    $reopened['originalStart'],
    $firstOccurrenceSeries['resourceUrl']
);
$firstOccurrenceProvider->updateEvent($reference, $following['resourceUrl'], $following['etag'], $following['uid'], [
    'start'      => '2026-09-02', 'end' => '2026-09-03', 'allDay' => true,
    'recurrence' => CalendarTaskEvent::shiftPlannedRecurrence(
        $following['recurrenceSettings'],
        $following['originalStart'],
        '2026-09-02'
    )
], $following);
$events = $firstOccurrenceProvider->getEvents($reference, $start, $end);
localExpect(
    array_column($events, 'start') === ['2026-09-02', '2026-09-03', '2026-09-04'],
    'Moving a reopened first task occurrence must move the complete local series without retaining its former exception.'
);
localExpect(
    array_filter(array_map(CalendarTaskEvent::enrich(...), $events), static fn (array $event): bool => $event['taskCompleted']) === [],
    'Removing a completed former first occurrence must not complete the reanchored local series.'
);

$completedFirstProvider = new LocalCalendarProvider([], $reference);
$completedFirstTask = CalendarTaskEvent::prepareWrite([
    'summary'           => 'Keep completed first task', 'task' => true, 'taskCompleted' => false,
    'taskFollowPlanned' => true, 'allDay' => true,
    'start'             => '2026-09-01', 'end' => '2026-09-02',
    'recurrence'        => ['frequency' => 'DAILY', 'interval' => 1, 'endMode' => 'count', 'count' => 3]
]);
$completedFirstSeries = $completedFirstProvider->createEvent($reference, $completedFirstTask);
$events = $completedFirstProvider->getEvents($reference, $start, $end);
$completedFirst = $completedFirstProvider->getEventForEdit($reference, $events[0]);
$completedFirstProvider->updateEvent(
    $reference,
    $completedFirst['resourceUrl'],
    $completedFirst['etag'],
    $completedFirst['uid'],
    CalendarTaskEvent::prepareWrite(['task' => true, 'taskCompleted' => true], $completedFirst),
    $completedFirst
);
$events = $completedFirstProvider->getEvents($reference, $start, $end);
$completedFirst = $completedFirstProvider->getEventForEdit($reference, $events[0]);
$following = $completedFirstProvider->getRecurringFollowing(
    $reference,
    $completedFirstSeries['uid'],
    $completedFirst['occurrenceId'],
    $completedFirst['originalStart'],
    $completedFirstSeries['resourceUrl']
);
$completedFirstProvider->updateEvent($reference, $following['resourceUrl'], $following['etag'], $following['uid'], [
    'summary'    => '[OC:TODO:FOLLOW] Keep completed first task',
    'start'      => '2026-09-02', 'end' => '2026-09-03', 'allDay' => true,
    'recurrence' => CalendarTaskEvent::shiftPlannedRecurrence(
        $following['recurrenceSettings'],
        $following['originalStart'],
        '2026-09-02'
    )
], $following);
$events = array_map(
    CalendarTaskEvent::enrich(...),
    $completedFirstProvider->getEvents($reference, $start, $end)
);
localExpect(
    array_column($events, 'start') === ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'],
    'Moving a task series after completing its first occurrence must retain the completion and move the open series.'
);
localExpect(
    $events[0]['taskCompleted'] === true
        && array_filter(
            array_slice($events, 1),
            static fn (array $event): bool => $event['taskCompleted']
        ) === [],
    'Moving a task series must retain the completion without reopening it or completing the new series.'
);

$dst = new LocalCalendarProvider([], $reference);
$dst->createEvent($reference, [
    'summary'  => 'Weekly review', 'start' => '2026-10-18T09:00:00+02:00', 'end' => '2026-10-18T10:00:00+02:00',
    'timezone' => 'Europe/Berlin', 'recurrence' => ['frequency' => 'WEEKLY', 'interval' => 1, 'byDay' => ['SU'], 'endMode' => 'count', 'count' => 3],
    'reminder' => ['mode' => 'custom', 'minutesBeforeStart' => 15]
]);
$dstEvents = $dst->getEvents($reference, new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-11-15'));
localExpect(array_column($dstEvents, 'start') === ['2026-10-18T09:00:00+02:00', '2026-10-25T09:00:00+01:00', '2026-11-01T09:00:00+01:00'], 'Local series must retain local wall clock time across DST.');
localExpect(str_contains(implode('', $dst->exportResources()), 'BEGIN:VALARM'), 'Reminder data must persist in the original resource.');
$beforeLimit = $dst->exportResources();
localFails(fn () => $dst->createEvent($reference, ['summary' => 'Too large', 'start' => '2026-10-18', 'end' => '2026-10-19', 'allDay' => true, 'description' => str_repeat('a', 16_777_216)]), 'Storage limit must reject oversized original data.');
localExpect($dst->exportResources() === $beforeLimit, 'Storage rejection must not remove or change saved originals.');

localFails(fn () => new LocalCalendarProvider(['bad' => 'invalid'], $reference), 'Corrupt original data must not silently reset.');
localFails(fn () => new LocalCalendarProvider([$reference . 'broken.ics' => "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:Broken\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"], $reference), 'A damaged original event must not silently disappear.');
localFails(fn () => $provider->getEvents('https://opencalendar.invalid/local/54321/', $start, $end), 'Another calendar reference must be rejected.');
$store = new LocalCalendarResourceStore([], $reference);
localFails(fn () => $store->request('GET', 'https://example.org/event.ics'), 'Transport must reject external resources without a network fallback.');
localFails(fn () => $store->request('REPORT', $reference, [], '<!DOCTYPE x SYSTEM "file:///secret"><x/>'), 'Lookup must reject XML external entities.');

fwrite(STDOUT, "Local calendar originals, recurrence, task, concurrency and offline boundary tests passed.\n");
