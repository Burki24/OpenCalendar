<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender/module.php';
require_once __DIR__ . '/../libs/ICalendarCodec.php';

class ResourceIdentityTestCalendar extends Calendar
{
    protected function ReadPropertyInteger(string $name): int
    {
        return 365;
    }
}

$calendar = new ResourceIdentityTestCalendar(99999);
$merge = new ReflectionMethod(Calendar::class, 'mergeIncrementalEvents');
$start = gmdate('Ymd', strtotime('tomorrow')) . 'T090000Z';
$end = gmdate('Ymd', strtotime('tomorrow')) . 'T100000Z';
$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:test@ips-kalender\r\nDTSTART:$start\r\nDTEND:$end\r\nSUMMARY:Test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$base = 'https://owncloud.example/remote.php/dav/calendars/test/default/';
$parse = static fn (string $url): array => IPSKalender\ICalendarCodec::parseEvents($ics, $url, '"etag"');
$cached = $parse($base . 'test%40ips-kalender.ics');
$changes = $parse($base . 'test@ips-kalender.ics');
$changes[0]['_syncReplaceResource'] = true;
$result = $merge->invoke($calendar, $cached, $changes);
if (count($result) !== 1) {
    throw new RuntimeException('Encoded and literal @ created duplicate cache entries.');
}
if ($result[0]['resourceUrl'] !== $changes[0]['resourceUrl']) {
    throw new RuntimeException('Keep the provider URL unchanged for subsequent requests.');
}
$result = $merge->invoke($calendar, array_merge($cached, $result), $changes);
if (count($result) !== 1 || count($merge->invoke($calendar, $result, $changes)) !== 1) {
    throw new RuntimeException('Repeated sync must repair existing duplicates and remain idempotent.');
}
$deleted = [['_syncDeleted' => true, 'resourceUrl' => $base . 'test@ips-kalender.ics']];
if ($merge->invoke($calendar, $cached, $deleted) !== []) {
    throw new RuntimeException('Deletion must also match an encoded cached URL.');
}
foreach (['https://other.example/test@ips-kalender.ics', $base . 'other/test@ips-kalender.ics',
    $base . 'test%2540ips-kalender.ics', $base . 'test@ips-kalender.ics?other=1'] as $url) {
    if (count($merge->invoke($calendar, $parse($url), $changes)) !== 2) {
        throw new RuntimeException('Distinct resources must not be merged: ' . $url);
    }
}
$seriesIcs = str_replace('SUMMARY:Test', "RRULE:FREQ=DAILY;COUNT=3\r\nSUMMARY:Test", $ics);
$from = new DateTimeImmutable('today');
$to = $from->modify('+7 days');
$series = IPSKalender\ICalendarCodec::parseEventsInRange($seriesIcs, $base . 'series%40test.ics', '', $from, $to);
$seriesChanges = IPSKalender\ICalendarCodec::parseEventsInRange($seriesIcs, $base . 'series@test.ics', '', $from, $to);
foreach ($seriesChanges as &$event) {
    $event['_syncReplaceResource'] = true;
}
unset($event);
if (count($series) !== 3 || count($merge->invoke($calendar, $series, $seriesChanges)) !== 3) {
    throw new RuntimeException('Replacing a series must preserve its three distinct occurrences.');
}
fwrite(STDOUT, "CalDAV cache resource identity and series replacement tests passed.\n");
