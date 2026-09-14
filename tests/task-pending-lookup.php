<?php

declare(strict_types=1);

use IPSKalender\CalDAVProvider;
use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;

require_once __DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php';

/** Real gateway and provider; only the HTTP transport is replaced. */
final class TaskPendingLookupHttp implements CalendarHttpClientInterface
{
    public array $methods = [];

    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $headers = [], string $body = '', int $maxResponseBytes = 67_108_864): CalendarHttpResponse
    {
        $this->methods[] = $method;
        $response = array_shift($this->responses);
        if (!$response instanceof CalendarHttpResponse) {
            throw new RuntimeException('Unexpected HTTP request.');
        }
        return $response;
    }
}

final class TaskPendingLookupGateway
{
    use KalenderKontoChildGatewayTrait;

    private const DATA_ID_FROM_CHILD = 'test';

    public function __construct(private CalDAVProvider $provider)
    {
    }

    private function DecodeDataFlowMessage(string $json, string $dataID): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function SendSafeDebug(string $name, mixed $data): void
    {
    }

    private function ReadPropertyInteger(string $name): int
    {
        return 0;
    }

    private function getProviderName(int $provider): string
    {
        return 'CalDAV';
    }

    private function sanitizeError(string $message): string
    {
        return $message;
    }

    private function translateErrorMessage(string $message): string
    {
        return $message;
    }

    private function resolveCalendar(string $id): array
    {
        return ['reference' => 'https://calendar.example/work/'];
    }

    private function createProvider(): CalDAVProvider
    {
        return $this->provider;
    }
}

function taskPendingExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function taskPendingIcal(string $date, string $summary = '[OC:TODO] Task', string $extra = ''): string
{
    $end = (new DateTimeImmutable($date))->modify('+1 day')->format('Ymd');
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:task\r\nDTSTART;VALUE=DATE:$date\r\nDTEND;VALUE=DATE:$end\r\nSUMMARY:$summary\r\n$extra" . "END:VEVENT\r\nEND:VCALENDAR\r\n";
}

$resource = 'https://calendar.example/work/task.ics';
$request = [
    'Operation'   => 'CheckPendingTask', 'CalendarID' => 'work', 'UID' => 'task',
    'ResourceURL' => $resource, 'Start' => strtotime('2026-09-12'), 'End' => strtotime('2026-09-13')
];
$lookup = static function (array $responses, ?array $custom = null) use ($request): array
{
    $http = new TaskPendingLookupHttp($responses);
    $gateway = new TaskPendingLookupGateway(new CalDAVProvider($http, 'https://calendar.example/'));
    $result = json_decode($gateway->ForwardData(json_encode($custom ?? $request, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
    return [$result, $http->methods];
};

foreach (['20260101', '20261101'] as $date) {
    foreach (['[OC:TODO] Task', '[OC:DONE] Task', 'Task'] as $summary) {
        [$result, $methods] = $lookup([new CalendarHttpResponse(200, [], taskPendingIcal($date, $summary), $resource)]);
        taskPendingExpect($result['Success'] && $result['Payload']['known'], 'Moved task must resolve outside its former date window.');
        taskPendingExpect($result['Payload']['event']['summary'] === $summary, 'Current completion/removed marker must reach the child.');
        taskPendingExpect($methods === ['GET'], 'Known identity must use a single GET.');
    }
}

foreach ([401 => 'authentication', 403 => 'access_denied', 409 => 'conflict', 412 => 'conflict', 429 => 'rate_limited', 500 => 'unavailable', 503 => 'unavailable'] as $status => $type) {
    [$result, $methods] = $lookup([new CalendarHttpResponse($status, [], '', $resource)]);
    taskPendingExpect(!$result['Success'] && $result['ErrorType'] === $type, 'Structured error must survive HTTP ' . $status);
    taskPendingExpect($methods === ['GET'], 'Unsafe fallback must not mask HTTP ' . $status);
}

foreach ([404, 410] as $status) {
    [$result] = $lookup([new CalendarHttpResponse($status, [], '', $resource)], array_replace($request, ['UID' => '']));
    taskPendingExpect($result['Success'] && $result['Payload'] === ['known' => true, 'event' => null], 'Explicit missing resource must be acknowledged.');
}

foreach (["RRULE:FREQ=DAILY;COUNT=2\r\n", "RECURRENCE-ID;VALUE=DATE:20260912\r\n"] as $extra) {
    [$result] = $lookup([new CalendarHttpResponse(200, [], taskPendingIcal('20261101', '[OC:DONE] Task', $extra), $resource)]);
    taskPendingExpect($result['Success'] && !$result['Payload']['known'], 'Recurring data must not release a detached task.');
}

$duplicate = str_replace('END:VCALENDAR', "BEGIN:VEVENT\r\nUID:task\r\nDTSTART;VALUE=DATE:20261201\r\nDTEND;VALUE=DATE:20261202\r\nSUMMARY:Other\r\nEND:VEVENT\r\nEND:VCALENDAR", taskPendingIcal('20261101'));
foreach ([$duplicate, str_replace('UID:task', 'UID:other', taskPendingIcal('20261101'))] as $body) {
    [$result] = $lookup([new CalendarHttpResponse(200, [], $body, $resource)]);
    taskPendingExpect(!$result['Success'], 'Ambiguous or mismatched identity must not confirm deletion.');
}

[$result, $methods] = $lookup([], array_replace($request, ['ResourceURL' => 'https://untrusted.example/task.ics']));
taskPendingExpect(!$result['Success'] && $methods === [], 'Untrusted resource must fail before transport, without fallback.');
[$result, $methods] = $lookup([new CalendarHttpResponse(200, [], taskPendingIcal('20261101'), 'https://untrusted.example/task.ics')]);
taskPendingExpect(!$result['Success'] && $methods === ['GET'], 'Untrusted effective resource must fail without fallback.');

$moved = 'https://calendar.example/work/moved.ics';
$xml = '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response><d:href>' . $moved . '</d:href><d:propstat><d:prop><c:calendar-data>' . htmlspecialchars(taskPendingIcal('20261101'), ENT_XML1) . '</c:calendar-data></d:prop></d:propstat></d:response></d:multistatus>';
[$result, $methods] = $lookup([
    new CalendarHttpResponse(404, [], '', $resource),
    new CalendarHttpResponse(207, [], $xml, 'https://calendar.example/work/')
]);
taskPendingExpect($result['Success'] && $result['Payload']['known'] && $result['Payload']['event']['resourceUrl'] === $moved, 'Stale URL must recover the exact UID at its new location.');
taskPendingExpect($methods === ['GET', 'REPORT'], 'Only explicit stale URLs may use UID recovery.');

[$result, $methods] = $lookup([], array_replace($request, ['ResourceURL' => '', 'UID' => '']));
taskPendingExpect($result['Success'] && !$result['Payload']['known'] && $methods === [], 'Missing identity must remain unknown without transport.');

fwrite(STDOUT, "Pending-task provider-neutral gateway tests passed.\n");
