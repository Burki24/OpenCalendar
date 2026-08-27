<?php

declare(strict_types=1);

use IPSKalender\ICalendarCodec;

require_once __DIR__ . '/../libs/ICalendarCodec.php';

function assertVTimezone(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL
                . 'Expected: ' . var_export($expected, true) . PHP_EOL
                . 'Actual:   ' . var_export($actual, true)
        );
    }
}

function vTimezoneDefinition(string $timezoneId, string $location = ''): string
{
    $locationLine = $location !== '' ? 'X-LIC-LOCATION:' . $location . "\r\n" : '';

    return "BEGIN:VTIMEZONE\r\n"
        . 'TZID:' . $timezoneId . "\r\n"
        . $locationLine
        . "BEGIN:STANDARD\r\n"
        . "DTSTART:20251026T030000\r\n"
        . "RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\n"
        . "TZOFFSETFROM:+0200\r\n"
        . "TZOFFSETTO:+0100\r\n"
        . "TZNAME:CET\r\n"
        . "END:STANDARD\r\n"
        . "BEGIN:DAYLIGHT\r\n"
        . "DTSTART:20250330T020000\r\n"
        . "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\n"
        . "TZOFFSETFROM:+0100\r\n"
        . "TZOFFSETTO:+0200\r\n"
        . "TZNAME:CEST\r\n"
        . "END:DAYLIGHT\r\n"
        . "END:VTIMEZONE\r\n";
}

$resolvedFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . vTimezoneDefinition('Custom-Berlin', 'Europe/Berlin')
    . "BEGIN:VEVENT\r\n"
    . "UID:resolved-single@example.com\r\n"
    . "DTSTART;TZID=Custom-Berlin:20260701T090000\r\n"
    . "DTEND;TZID=Custom-Berlin:20260701T100000\r\n"
    . "SUMMARY:Resolved custom alias\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:resolved-series@example.com\r\n"
    . "DTSTART;TZID=Custom-Berlin:20260328T090000\r\n"
    . "DTEND;TZID=Custom-Berlin:20260328T100000\r\n"
    . "RRULE:FREQ=DAILY;COUNT=3\r\n"
    . "SUMMARY:Resolved series\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";

$resolvedEvents = ICalendarCodec::parseEvents($resolvedFeed, 'https://calendar.example/resolved.ics', '');
$resolvedSingle = array_values(array_filter(
    $resolvedEvents,
    static fn (array $event): bool => ($event['uid'] ?? '') === 'resolved-single@example.com'
))[0];
assertVTimezone('Europe/Berlin', $resolvedSingle['timezone'], 'X-LIC-LOCATION must resolve the embedded TZID to IANA.');
assertVTimezone('Custom-Berlin', $resolvedSingle['timezoneReference'], 'The original embedded TZID must be retained.');
assertVTimezone(true, $resolvedSingle['timezoneResolved'], 'The IANA alias must be marked as resolved.');
assertVTimezone(
    (new DateTimeImmutable('2026-07-01T07:00:00Z'))->getTimestamp(),
    $resolvedSingle['startTimestamp'],
    'The embedded daylight offset must resolve the July event to the correct instant.'
);

$resolvedSeries = ICalendarCodec::parseEventsInRange(
    $resolvedFeed,
    'https://calendar.example/resolved.ics',
    '',
    new DateTimeImmutable('2026-03-27T00:00:00Z'),
    new DateTimeImmutable('2026-04-01T00:00:00Z')
);
$resolvedSeriesStarts = array_values(array_map(
    static fn (array $event): string => (string) $event['start'],
    array_filter($resolvedSeries, static fn (array $event): bool => ($event['uid'] ?? '') === 'resolved-series@example.com')
));
assertVTimezone(
    [
        '2026-03-28T09:00:00+01:00',
        '2026-03-29T09:00:00+02:00',
        '2026-03-30T09:00:00+02:00'
    ],
    $resolvedSeriesStarts,
    'Resolved VTIMEZONE aliases must keep the local wall-clock time across DST.'
);

$windowsFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . vTimezoneDefinition('W. Europe Standard Time')
    . "BEGIN:VEVENT\r\n"
    . "UID:windows-tzid@example.com\r\n"
    . "DTSTART;TZID=W. Europe Standard Time:20260701T090000\r\n"
    . "DTEND;TZID=W. Europe Standard Time:20260701T100000\r\n"
    . "SUMMARY:Windows TZID\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$windowsEvent = ICalendarCodec::parseEvents($windowsFeed, 'https://calendar.example/windows.ics', '')[0];
assertVTimezone('Europe/Berlin', $windowsEvent['timezone'], 'Known Windows TZIDs must resolve to their IANA counterpart.');
assertVTimezone(true, $windowsEvent['timezoneResolved'], 'Known Windows TZIDs must remain recurrence-safe.');

$misleadingDefinition = str_replace(
    ['TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200'],
    ['TZOFFSETFROM:+0400', 'TZOFFSETTO:+0300', 'TZOFFSETFROM:+0300', 'TZOFFSETTO:+0400'],
    vTimezoneDefinition('Misleading Alias', 'Europe/Berlin')
);
$misleadingFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . $misleadingDefinition
    . "BEGIN:VEVENT\r\n"
    . "UID:misleading-alias@example.com\r\n"
    . "DTSTART;TZID=Misleading Alias:20260701T090000\r\n"
    . "DTEND;TZID=Misleading Alias:20260701T100000\r\n"
    . "SUMMARY:Misleading alias\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$misleadingEvent = ICalendarCodec::parseEvents($misleadingFeed, 'https://calendar.example/misleading.ics', '')[0];
assertVTimezone('+04:00', $misleadingEvent['timezone'], 'Embedded observances must take precedence over a conflicting IANA hint.');
assertVTimezone(false, $misleadingEvent['timezoneResolved'], 'A conflicting IANA hint must not make recurrence expansion safe.');
assertVTimezone(
    (new DateTimeImmutable('2026-07-01T05:00:00Z'))->getTimestamp(),
    $misleadingEvent['startTimestamp'],
    'A conflicting alias must still use the embedded VTIMEZONE offset for the actual instant.'
);

$customFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . vTimezoneDefinition('Factory Shift Time')
    . "BEGIN:VEVENT\r\n"
    . "UID:custom-single@example.com\r\n"
    . "DTSTART;TZID=Factory Shift Time:20260701T090000\r\n"
    . "DTEND;TZID=Factory Shift Time:20260701T100000\r\n"
    . "SUMMARY:Custom single\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:custom-gap@example.com\r\n"
    . "DTSTART;TZID=Factory Shift Time:20260329T023000\r\n"
    . "DTEND;TZID=Factory Shift Time:20260329T033000\r\n"
    . "SUMMARY:Custom gap\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:custom-fold@example.com\r\n"
    . "DTSTART;TZID=Factory Shift Time:20261025T023000\r\n"
    . "DTEND;TZID=Factory Shift Time:20261025T033000\r\n"
    . "SUMMARY:Custom fold\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:custom-series@example.com\r\n"
    . "DTSTART;TZID=Factory Shift Time:20260328T090000\r\n"
    . "DTEND;TZID=Factory Shift Time:20260328T100000\r\n"
    . "RRULE:FREQ=DAILY;COUNT=3\r\n"
    . "SUMMARY:Unsupported custom series\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";

$customEvents = ICalendarCodec::parseEvents($customFeed, 'https://calendar.example/custom.ics', '');
$customByUid = [];
foreach ($customEvents as $event) {
    $customByUid[(string) $event['uid']] = $event;
}
assertVTimezone('+02:00', $customByUid['custom-single@example.com']['timezone'], 'A custom summer observance must expose its active offset.');
assertVTimezone('Factory Shift Time', $customByUid['custom-single@example.com']['timezoneReference'], 'Custom TZID must be retained.');
assertVTimezone(false, $customByUid['custom-single@example.com']['timezoneResolved'], 'A custom zone without an IANA mapping must not be guessed.');
assertVTimezone(
    (new DateTimeImmutable('2026-07-01T07:00:00Z'))->getTimestamp(),
    $customByUid['custom-single@example.com']['startTimestamp'],
    'A custom VTIMEZONE must resolve an ordinary local time from its observances.'
);
assertVTimezone(
    (new DateTimeImmutable('2026-03-29T01:30:00Z'))->getTimestamp(),
    $customByUid['custom-gap@example.com']['startTimestamp'],
    'A nonexistent local time must use the offset before the DST gap.'
);
assertVTimezone(
    '2026-03-29T03:30:00+02:00',
    $customByUid['custom-gap@example.com']['start'],
    'A nonexistent local time must normalize to the resulting post-gap wall time.'
);
assertVTimezone(
    (new DateTimeImmutable('2026-10-25T00:30:00Z'))->getTimestamp(),
    $customByUid['custom-fold@example.com']['startTimestamp'],
    'An ambiguous local time must select the first occurrence before the fallback.'
);

$customSeries = ICalendarCodec::parseEventsInRange(
    $customFeed,
    'https://calendar.example/custom.ics',
    '',
    new DateTimeImmutable('2026-03-27T00:00:00Z'),
    new DateTimeImmutable('2026-04-01T00:00:00Z')
);
$customSeriesEvents = array_values(array_filter(
    $customSeries,
    static fn (array $event): bool => ($event['uid'] ?? '') === 'custom-series@example.com'
));
assertVTimezone(1, count($customSeriesEvents), 'An unmapped custom recurring TZID must keep only explicit DTSTART.');
assertVTimezone(false, $customSeriesEvents[0]['recurrenceExpansionSupported'], 'Custom recurring TZID must be marked unsupported.');
assertVTimezone(['TZID'], $customSeriesEvents[0]['recurrenceUnsupportedRuleParts'], 'TZID must explain the safe recurrence fallback.');
assertVTimezone(
    '2026-03-28T09:00:00+01:00',
    $customSeriesEvents[0]['start'],
    'The explicit DTSTART must retain its correctly resolved custom-zone wall time.'
);

$ianaFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:iana-direct@example.com\r\n"
    . "DTSTART;TZID=Europe/Berlin:20260701T090000\r\n"
    . "DTEND;TZID=Europe/Berlin:20260701T100000\r\n"
    . "SUMMARY:Direct IANA\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$ianaEvent = ICalendarCodec::parseEvents($ianaFeed, 'https://calendar.example/iana.ics', '')[0];
assertVTimezone('Europe/Berlin', $ianaEvent['timezone'], 'Direct IANA TZIDs must remain unchanged.');
assertVTimezone(true, $ianaEvent['timezoneResolved'], 'Direct IANA TZIDs must remain recurrence-safe.');

fwrite(STDOUT, "VTIMEZONE import checks passed.\n");
