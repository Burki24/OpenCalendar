<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\CalendarTaskEvent;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\ICalendarCodec;

require_once __DIR__ . '/../libs/CalendarTaskEvent.php';
require_once __DIR__ . '/../libs/ICalendarCodec.php';
require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';

final class TaskMetadataHttpClient implements CalendarHttpClientInterface
{
    /** @var list<array{method:string,url:string,body:string}> */
    public array $requests = [];

    /** @param list<CalendarHttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $maxResponseBytes = 67_108_864
    ): CalendarHttpResponse {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
        return array_shift($this->responses) ?? throw new RuntimeException('Unexpected Google request.');
    }
}

function assertTaskMetadata(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$task = CalendarTaskEvent::prepareWrite([
    'summary' => 'Versicherung prüfen',
    'task' => true,
    'taskCompleted' => false,
    'taskRollForwardScope' => CalendarTaskEvent::ROLL_FORWARD_SCOPE_FOLLOWING,
    'allDay' => true,
    'start' => '2026-09-20',
    'end' => '2026-09-21'
]);

$created = ICalendarCodec::createEvent($task);
assertTaskMetadata(
    str_contains($created['ical'], "SUMMARY:Versicherung prüfen\r\n")
        && !str_contains($created['ical'], '[OC:TODO')
        && str_contains($created['ical'], "X-OPENCALENDAR-TASK:TRUE\r\n")
        && str_contains($created['ical'], "X-OPENCALENDAR-TASK-STATUS:OPEN\r\n")
        && str_contains($created['ical'], "X-OPENCALENDAR-ROLL-FORWARD:FOLLOWING\r\n"),
    'iCalendar tasks must use X-properties and keep SUMMARY clean.'
);
$parsed = ICalendarCodec::parseEvents($created['ical'], 'https://calendar.example/task.ics', 'etag')[0];
assertTaskMetadata(
    $parsed['summary'] === 'Versicherung prüfen'
        && ($parsed['task'] ?? false) === true
        && ($parsed['taskCompleted'] ?? true) === false
        && ($parsed['taskRollForwardScope'] ?? '') === 'following',
    'iCalendar task X-properties must round-trip as normalized metadata.'
);

$updatedIcal = ICalendarCodec::updateEvent($created['ical'], $created['uid'], [
    'summary' => '[OC:DONE:KEEP] Versicherung prüfen',
    'task' => true,
    'taskCompleted' => true,
    'taskStatus' => 'completed',
    'taskRollForwardScope' => 'disabled'
]);
assertTaskMetadata(
    str_contains($updatedIcal, "SUMMARY:Versicherung prüfen\r\n")
        && str_contains($updatedIcal, "X-OPENCALENDAR-TASK-STATUS:COMPLETED\r\n")
        && str_contains($updatedIcal, "X-OPENCALENDAR-ROLL-FORWARD:DISABLED\r\n"),
    'Updating an iCalendar task must replace its structured metadata.'
);

$googleResponse = [
    'id' => 'google-task',
    'iCalUID' => 'google-task@example.com',
    'summary' => 'Versicherung prüfen',
    'status' => 'confirmed',
    'start' => ['date' => '2026-09-20'],
    'end' => ['date' => '2026-09-21'],
    'extendedProperties' => ['private' => [
        'opencalendarTask' => 'true',
        'opencalendarTaskStatus' => 'open',
        'opencalendarRollForward' => 'following'
    ]]
];
$googleClient = new TaskMetadataHttpClient([
    new CalendarHttpResponse(200, [], json_encode($googleResponse, JSON_THROW_ON_ERROR), 'https://www.googleapis.com/calendar/v3/calendars/cal/events')
]);
$google = new GoogleCalendarProvider($googleClient, 'token');
$google->createEvent('cal', $task);
$googlePayload = json_decode($googleClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
assertTaskMetadata(
    $googlePayload['summary'] === 'Versicherung prüfen'
        && $googlePayload['extendedProperties']['private']['opencalendarTask'] === 'true'
        && $googlePayload['extendedProperties']['private']['opencalendarTaskStatus'] === 'open'
        && $googlePayload['extendedProperties']['private']['opencalendarRollForward'] === 'following',
    'Google tasks must use private extended properties and keep the visible title clean.'
);

$googleReadClient = new TaskMetadataHttpClient([
    new CalendarHttpResponse(
        200,
        [],
        json_encode(['timeZone' => 'Europe/Berlin', 'items' => [$googleResponse]], JSON_THROW_ON_ERROR),
        'https://www.googleapis.com/calendar/v3/calendars/cal/events'
    )
]);
$googleReader = new GoogleCalendarProvider($googleReadClient, 'token');
$googleEvents = $googleReader->getEvents(
    'cal',
    new DateTimeImmutable('2026-09-01T00:00:00+02:00'),
    new DateTimeImmutable('2026-10-01T00:00:00+02:00')
);
assertTaskMetadata(
    count($googleEvents) === 1
        && ($googleEvents[0]['task'] ?? false) === true
        && ($googleEvents[0]['taskRollForwardScope'] ?? '') === 'following',
    'Google private task properties must be mapped back to normalized metadata.'
);

fwrite(STDOUT, "Provider task metadata tests passed.\n");
