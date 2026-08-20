<?php

declare(strict_types=1);

require_once __DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php';

final class MicrosoftEventEditMatchHarness
{
    use KalenderKontoChildGatewayTrait;

    /**
     * Exposes the gateway's private identity matcher for regression tests.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $request
     */
    public function matches(array $event, array $request, bool $microsoft): bool
    {
        return $this->eventMatchesEditRequest($event, $request, $microsoft);
    }
}

function assertMicrosoftEventEdit(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$calendarSource = (string) file_get_contents(__DIR__ . '/../Kalender/module.php');
assertMicrosoftEventEdit(
    str_contains($calendarSource, "'SeriesID'       => trim((string) (\$event['seriesId'] ?? ''))"),
    'Calendar event-edit requests must forward the recurring series identity.'
);

$matcher = new MicrosoftEventEditMatchHarness();
$freshOccurrence = [
    'occurrenceId'   => 'new-occurrence-id',
    'eventReference' => 'new-occurrence-id',
    'resourceUrl'    => 'https://graph.microsoft.com/v1.0/me/calendars/calendar/events/new-occurrence-id',
    'uid'            => 'occurrence-icaluid',
    'seriesId'       => 'series-master',
    'originalStart'  => '2026-08-20T15:30:00Z',
    'allDay'         => false
];
$staleOccurrenceRequest = [
    'OccurrenceID'   => 'old-occurrence-id',
    'EventReference' => 'old-occurrence-id',
    'ResourceURL'    => 'https://graph.microsoft.com/v1.0/me/calendars/calendar/events/old-occurrence-id',
    'UID'            => 'occurrence-icaluid',
    'SeriesID'       => 'series-master',
    'OriginalStart'  => '2026-08-20T17:30:00+02:00'
];
assertMicrosoftEventEdit(
    $matcher->matches($freshOccurrence, $staleOccurrenceRequest, true),
    'Microsoft recurring occurrences must survive stale Graph IDs when their occurrence iCalUId still matches.'
);
assertMicrosoftEventEdit(
    !$matcher->matches($freshOccurrence, $staleOccurrenceRequest, false),
    'Non-Microsoft provider matching must keep its strict primary-identity behavior.'
);

$staleOccurrenceRequest['UID'] = '';
assertMicrosoftEventEdit(
    $matcher->matches($freshOccurrence, $staleOccurrenceRequest, true),
    'Microsoft recurring occurrences must fall back to series ID and equivalent original-start instants.'
);

$freshSingle = [
    'eventReference' => 'new-single-id',
    'resourceUrl'    => 'https://graph.microsoft.com/v1.0/me/calendars/calendar/events/new-single-id',
    'uid'            => 'single-icaluid',
    'allDay'         => false
];
$staleSingleRequest = [
    'EventReference' => 'old-single-id',
    'ResourceURL'    => 'https://graph.microsoft.com/v1.0/me/calendars/calendar/events/old-single-id',
    'UID'            => 'single-icaluid'
];
assertMicrosoftEventEdit(
    $matcher->matches($freshSingle, $staleSingleRequest, true),
    'Microsoft single events must fall back to iCalUId when an older Graph event ID is cached.'
);

fwrite(STDOUT, "Microsoft event-edit identity tests passed.\n");
