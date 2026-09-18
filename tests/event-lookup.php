<?php

declare(strict_types=1);

use IPSKalender\CalendarEventLookup;
use IPSKalender\CalendarEventLookupException;

require_once __DIR__ . '/../libs/CalendarEventLookup.php';

function assertEventLookup(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertEventLookupTimestamp(int $expected, DateTimeImmutable $actual, string $message): void
{
    assertEventLookup($actual->getTimestamp() === $expected, $message);
}

assertEventLookup(
    CalendarEventLookup::range([]) === null,
    'Event lookup without a positive start timestamp must not create an unbounded range.'
);
assertEventLookup(
    CalendarEventLookup::range(['startTimestamp' => 0, 'endTimestamp' => 10]) === null,
    'Event lookup with a non-positive start timestamp must not create a range.'
);

$startTimestamp = 1_787_132_400;
$endTimestamp = $startTimestamp + 3600;
$range = CalendarEventLookup::range([
    'startTimestamp' => $startTimestamp,
    'endTimestamp'   => $endTimestamp
]);
assertEventLookup(is_array($range), 'A valid event identity must create a lookup range.');
assertEventLookupTimestamp(
    $startTimestamp - 86400,
    $range[0],
    'Event lookup must include one day before the selected event.'
);
assertEventLookupTimestamp(
    $endTimestamp + 86400,
    $range[1],
    'Event lookup must include one day after the selected event.'
);

$normalizedRange = CalendarEventLookup::range([
    'startTimestamp' => $startTimestamp,
    'endTimestamp'   => $startTimestamp
]);
assertEventLookup(is_array($normalizedRange), 'A missing event duration must still create a bounded lookup range.');
assertEventLookupTimestamp(
    $startTimestamp + 1 + 86400,
    $normalizedRange[1],
    'Event lookup must normalize an empty duration to one second before applying padding.'
);

$epochRange = CalendarEventLookup::range([
    'startTimestamp' => 1,
    'endTimestamp'   => 2
]);
assertEventLookup(is_array($epochRange), 'Early positive timestamps must remain valid lookup identities.');
assertEventLookupTimestamp(1, $epochRange[0], 'Lookup padding must never create a non-positive Unix timestamp.');

$maximumRangeSeconds = 6 * 366 * 86400;
$maximumEventDuration = $maximumRangeSeconds - (2 * 86400);
$maximumRange = CalendarEventLookup::range([
    'startTimestamp' => $startTimestamp,
    'endTimestamp'   => $startTimestamp + $maximumEventDuration
]);
assertEventLookup(is_array($maximumRange), 'The maximum supported padded lookup range must remain valid.');
assertEventLookup(
    $maximumRange[1]->getTimestamp() - $maximumRange[0]->getTimestamp() === $maximumRangeSeconds,
    'The maximum supported lookup range must preserve the existing six-year safety boundary.'
);

try {
    CalendarEventLookup::range([
        'startTimestamp' => $startTimestamp,
        'endTimestamp'   => $startTimestamp + $maximumEventDuration + 1
    ]);
    throw new RuntimeException('An oversized event lookup range was accepted.');
} catch (CalendarEventLookupException $exception) {
    assertEventLookup(
        $exception->getMessage() === 'The selected event time range is too large.',
        'Oversized provider-neutral lookup ranges must retain the established error message.'
    );
}

$sourceOccurrence = array_merge(
    IPSKalender\CalendarEventRecurrence::occurrence('series', 'old-id', '2026-09-20', '', true),
    [
        'uid'            => 'occurrence-uid',
        'resourceUrl'    => 'https://graph.microsoft.com/v1.0/me/calendars/calendar/events/old-id',
        'eventReference' => 'old-id'
    ]
);
$writtenOccurrence = array_merge($sourceOccurrence, [
    'eventReference' => 'new-id',
    'occurrenceId'   => 'new-id',
    'resourceUrl'    => 'https://graph.microsoft.com/v1.0/me/calendars/calendar/events/new-id',
    'originalStart'  => ''
]);
$readback = CalendarEventLookup::resolveOccurrenceReadback($sourceOccurrence, $writtenOccurrence, 'new-id');
assertEventLookup(
    ($readback['originalStart'] ?? '') === '2026-09-20',
    'Confirmed occurrence readback must restore the immutable original anchor when a new provider ID omits it.'
);
assertEventLookup(
    CalendarEventLookup::sameOccurrence($sourceOccurrence, $writtenOccurrence),
    'Occurrence-specific UIDs must match a new exception ID within the same calendar and series.'
);
foreach ([
    'wrong returned ID'  => [$sourceOccurrence, $writtenOccurrence, 'different-id'],
    'different series'   => [$sourceOccurrence, array_merge($writtenOccurrence, ['seriesId' => 'other']), 'new-id'],
    'different calendar' => [$sourceOccurrence, array_merge(
        $writtenOccurrence,
        ['resourceUrl' => 'https://graph.microsoft.com/v1.0/me/calendars/other/events/new-id']
    ), 'new-id'],
    'series-wide write' => [array_merge($sourceOccurrence, ['writeScope' => 'series']), $writtenOccurrence, 'new-id'],
    'CalDAV shared UID' => [array_merge($sourceOccurrence, ['resourceUrl' => 'https://caldav.example/series.ics']),
        array_merge($writtenOccurrence, ['resourceUrl' => 'https://caldav.example/series.ics']), 'new-id']
] as $label => [$source, $current, $reference]) {
    assertEventLookup(
        CalendarEventLookup::resolveOccurrenceReadback($source, $current, $reference) === null,
        'Direct readback must reject unsafe occurrence identity: ' . $label
    );
}

foreach ([
    'GoogleCalendarProvider.php'    => 'GoogleCalendarProviderException',
    'MicrosoftCalendarProvider.php' => 'MicrosoftCalendarProviderException',
    'CalDAVProvider.php'            => 'CalDAVProviderException'
] as $providerFile => $providerException) {
    $source = (string) file_get_contents(__DIR__ . '/../libs/' . $providerFile);
    assertEventLookup(
        str_contains($source, "require_once __DIR__ . '/CalendarEventLookup.php';"),
        $providerFile . ' must load the provider-neutral event lookup helper.'
    );
    assertEventLookup(
        str_contains($source, 'return CalendarEventLookup::range($identity);'),
        $providerFile . ' must delegate lookup range construction to CalendarEventLookup.'
    );
    assertEventLookup(
        str_contains($source, 'catch (CalendarEventLookupException $exception)')
            && str_contains($source, 'throw new ' . $providerException . '($exception->getMessage());'),
        $providerFile . ' must preserve its existing provider-specific exception contract.'
    );
}

fwrite(STDOUT, "Provider-neutral event lookup range tests passed.\n");
