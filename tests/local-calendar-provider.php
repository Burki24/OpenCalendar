<?php

declare(strict_types=1);

use IPSKalender\CalendarTaskEvent;
use IPSKalender\LocalCalendarProvider;
use IPSKalender\LocalCalendarResourceStore;

require_once __DIR__ . '/../libs/LocalCalendarProvider.php';

function localExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
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
$end = new DateTimeImmutable('2026-11-01');
$created = $provider->createEvent($reference, [
    'summary' => 'Local appointment', 'start' => '2026-09-14', 'end' => '2026-09-15', 'allDay' => true
]);
$originals = $provider->exportResources();
$provider = new LocalCalendarProvider($originals, $reference);
$event = $provider->getEvents($reference, $start, $end)[0];
localExpect($event['summary'] === 'Local appointment', 'Restart must preserve local originals.');
localExpect($provider->getEvents($reference, new DateTimeImmutable('2030-01-01'), new DateTimeImmutable('2030-02-01')) === [], 'Query must honor its range.');
localExpect($provider->exportResources() === $originals, 'Out-of-range query must not remove originals.');
$updated = $provider->updateEvent($reference, $event['resourceUrl'], $event['etag'], $event['uid'], ['summary' => 'Changed']);
localFails(fn () => $provider->updateEvent($reference, $created['resourceUrl'], $created['etag'], $created['uid'], ['summary' => 'Stale edit']), 'Stale editor must not overwrite changes.');
localExpect($provider->deleteEvent($reference, $updated['resourceUrl'], $updated['etag']), 'Current local event must be deletable.');
localExpect($provider->exportResources() === [], 'Delete must remove original resource.');

$task = CalendarTaskEvent::prepareWrite([
    'summary'    => 'Clean fridge', 'task' => true, 'taskCompleted' => false, 'allDay' => true,
    'start'      => '2026-09-01', 'end' => '2026-09-02',
    'recurrence' => ['frequency' => 'DAILY', 'interval' => 10, 'endMode' => 'count', 'count' => 5]
]);
$series = $provider->createEvent($reference, $task);
$events = $provider->getEvents($reference, $start, $end);
localExpect(array_column($events, 'start') === ['2026-09-01', '2026-09-11', '2026-09-21', '2026-10-01', '2026-10-11'], 'Task series must expand correctly.');
localExpect($events[0]['canUpdateOccurrence'] && $events[0]['canUpdateFollowing'], 'Local occurrences must expose writable scopes.');
$second = $provider->getEventForIdentity($reference, $events[1]);
$provider->updateEvent($reference, $second['resourceUrl'], $second['etag'], $second['uid'], ['summary' => '[OC:DONE] Clean fridge'], $second);
$events = $provider->getEvents($reference, $start, $end);
localExpect(CalendarTaskEvent::enrich($events[1])['taskCompleted'], 'Completion must affect only the selected occurrence.');
localExpect(!CalendarTaskEvent::enrich($events[0])['taskCompleted'], 'Other task occurrences must remain open.');
$first = $provider->getEventForIdentity($reference, $events[0]);
$provider->deleteEvent($reference, $first['resourceUrl'], $first['etag'], $first['recurrenceId'], $first);
$events = $provider->getEvents($reference, $start, $end);
localExpect(count($events) === 4 && $events[0]['start'] === '2026-09-11', 'EXDATE must remove only the selected occurrence.');
$following = $provider->getRecurringFollowing($reference, $series['uid'], $events[1]['occurrenceId'], $events[1]['originalStart'], $series['resourceUrl']);
$provider->updateEvent($reference, $following['resourceUrl'], $following['etag'], $following['uid'], [
    'start'      => '2026-09-23', 'end' => '2026-09-24', 'allDay' => true,
    'recurrence' => CalendarTaskEvent::shiftPlannedRecurrence($following['recurrenceSettings'], $following['originalStart'], '2026-09-23')
], $following);
$events = $provider->getEvents($reference, $start, $end);
localExpect(array_column($events, 'start') === ['2026-09-11', '2026-09-23', '2026-10-03', '2026-10-13'], 'Following shift must retain remaining series length.');

$beforeLimit = $provider->exportResources();
localFails(fn () => $provider->createEvent($reference, ['summary' => 'Too large', 'start' => '2026-10-18', 'end' => '2026-10-19', 'allDay' => true, 'description' => str_repeat('a', 16_777_216)]), 'Storage limit must reject oversized originals.');
localExpect($provider->exportResources() === $beforeLimit, 'Storage rejection must preserve originals.');
localFails(fn () => new LocalCalendarProvider(['bad' => 'invalid'], $reference), 'Corrupt originals must not silently reset.');
$store = new LocalCalendarResourceStore([], $reference);
localFails(fn () => $store->request('GET', 'https://example.org/event.ics'), 'Transport must reject external resources without a network fallback.');
localFails(fn () => $store->request('REPORT', $reference, [], '<!DOCTYPE x SYSTEM "file:///secret"><x/>'), 'Lookup must reject XML external entities.');

fwrite(STDOUT, "Local calendar provider tests passed.\n");
