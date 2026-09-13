<?php

declare(strict_types=1);

use IPSKalender\CalDAVProvider;
use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\CalendarTaskEvent;

require_once dirname(__DIR__) . '/Kalender Konto/traits/ChildGatewayTrait.php';
require_once dirname(__DIR__) . '/libs/CalendarTaskEvent.php';

final class PendingTaskHttpClient implements CalendarHttpClientInterface
{
    public array $requests = [];

    public function __construct(public CalendarHttpResponse $response)
    {
    }

    public function request(string $method, string $url, array $headers = [], string $body = '', int $maxResponseBytes = 67_108_864): CalendarHttpResponse
    {
        $this->requests[] = compact('method', 'url');
        return $this->response;
    }
}

/** Exercise the real child gateway and CalDAV parser; fake only HTTP transport. */
final class PendingTaskGateway
{
    use KalenderKontoChildGatewayTrait {
        checkPendingTaskForChild as public check;
    }

    public function __construct(private CalDAVProvider $provider)
    {
    }

    private function resolveCalendar(string $calendarId): array
    {
        return ['id' => $calendarId];
    }

    private function calendarReference(array $calendar): string
    {
        return 'https://calendar.example/calendars/work/';
    }

    private function createProvider(): CalDAVProvider
    {
        return $this->provider;
    }
}

function pendingTaskIcal(string $summary, string $date = '20261101', string $uid = 'detached-task', string $extra = ''): string
{
    $end = (new DateTimeImmutable($date))->modify('+1 day')->format('Ymd');
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:" . $uid
        . "\r\nDTSTART;VALUE=DATE:" . $date . "\r\nDTEND;VALUE=DATE:" . $end
        . "\r\nSUMMARY:" . $summary . "\r\n" . $extra . "END:VEVENT\r\nEND:VCALENDAR\r\n";
}

$resource = 'https://calendar.example/calendars/work/task.ics';
$request = [
    'CalendarID' => 'work', 'ResourceURL' => $resource, 'UID' => 'detached-task',
    'Start'      => (new DateTimeImmutable('2026-09-12'))->getTimestamp(),
    'End'        => (new DateTimeImmutable('2026-09-13'))->getTimestamp()
];
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void
{
    if (!$condition) {
        $failures[] = $message;
    }
};
$lookup = static function (string $body, int $status = 200, ?array $customRequest = null) use ($resource, $request): array
{
    $http = new PendingTaskHttpClient(new CalendarHttpResponse($status, ['etag' => 'current-etag'], $body, $resource));
    $gateway = new PendingTaskGateway(new CalDAVProvider($http, 'https://calendar.example/'));
    return [$gateway->check($customRequest ?? $request), $http];
};

foreach (['20261101', '20260101'] as $date) {
    foreach (['[OC:DONE] Cleaning', '[OC:TODO] Cleaning', 'Cleaning'] as $summary) {
        [$result, $http] = $lookup(pendingTaskIcal($summary, $date));
        $event = is_array($result['event'] ?? null) ? CalendarTaskEvent::enrich($result['event']) : [];
        $check(($result['known'] ?? false) && ($event['summary'] ?? '') === $summary, 'Moved task must be found by identity outside the original date range: ' . $date . ' ' . $summary);
        $check(($event['taskCompleted'] ?? false) === str_starts_with($summary, '[OC:DONE]'), 'Completion status must come from the current resource.');
        $check(($event['task'] ?? false) === str_starts_with($summary, '[OC:'), 'Removed task marker must be detected.');
        $check($http->requests === [['method' => 'GET', 'url' => $resource]], 'Identity lookup must use exactly one resource GET.');
    }
}

foreach ([
    'wrong UID'              => pendingTaskIcal('[OC:DONE] Cleaning', '20261101', 'another-task'),
    'recurring master'       => pendingTaskIcal('[OC:DONE] Cleaning', '20261101', 'detached-task', "RRULE:FREQ=DAILY;COUNT=2\r\n"),
    'recurrence exception'   => pendingTaskIcal('[OC:DONE] Cleaning', '20261101', 'detached-task', "RECURRENCE-ID;VALUE=DATE:20260912\r\n"),
    'duplicate matching UID' => str_replace("END:VCALENDAR\r\n", "BEGIN:VEVENT\r\nUID:detached-task\r\nDTSTART;VALUE=DATE:20261201\r\nDTEND;VALUE=DATE:20261202\r\nSUMMARY:Other\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", pendingTaskIcal('[OC:DONE] Cleaning'))
] as $label => $body) {
    [$result] = $lookup($body);
    $check($result === ['known' => false, 'event' => null], $label . ' must remain unknown, never closed or deleted.');
}

foreach ([404, 410] as $status) {
    [$result] = $lookup('', $status);
    $check($result === ['known' => true, 'event' => null], 'HTTP ' . $status . ' must confirm the resource is gone.');
}
foreach ([401, 403, 429, 500, 503] as $status) {
    $thrown = false;
    try {
        $lookup('', $status);
    } catch (Throwable $exception) {
        $thrown = true;
        $check(($exception->httpStatus ?? 0) === $status, 'HTTP failure status must be preserved.');
    }
    $check($thrown, 'HTTP ' . $status . ' must propagate, never confirm deletion.');
}

$http = new PendingTaskHttpClient(new CalendarHttpResponse(200, [], pendingTaskIcal('[OC:DONE] Cleaning'), $resource));
$gateway = new PendingTaskGateway(new CalDAVProvider($http, 'https://calendar.example/'));
$thrown = false;
try {
    $gateway->check(array_replace($request, ['ResourceURL' => 'https://untrusted.example/task.ics']));
} catch (Throwable $exception) {
    $thrown = true;
}
$check($thrown && $http->requests === [], 'Untrusted resource origin must be rejected before any HTTP request.');

$http = new PendingTaskHttpClient(new CalendarHttpResponse(200, [], pendingTaskIcal('[OC:DONE] Cleaning'), 'https://untrusted.example/task.ics'));
$gateway = new PendingTaskGateway(new CalDAVProvider($http, 'https://calendar.example/'));
$thrown = false;
try {
    $gateway->check($request);
} catch (Throwable $exception) {
    $thrown = true;
}
$check($thrown && count($http->requests) === 1, 'Untrusted effective response origin must be rejected, never treated as a completed task.');

foreach (['UID', 'ResourceURL'] as $missingField) {
    [$result, $http] = $lookup(pendingTaskIcal('[OC:DONE] Cleaning'), 200, array_replace($request, [$missingField => '']));
    $check($result === ['known' => false, 'event' => null] && $http->requests === [], 'Missing ' . $missingField . ' must remain unknown without a request.');
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "CalDAV pending-task identity tests passed.\n");
