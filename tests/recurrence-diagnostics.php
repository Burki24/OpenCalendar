<?php

declare(strict_types=1);

use IPSKalender\ICalendarRecurrence;

require_once __DIR__ . '/../libs/ICalendarRecurrence.php';

function assertRecurrenceDiagnostic(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL
                . 'Expected: ' . var_export($expected, true) . PHP_EOL
                . 'Actual:   ' . var_export($actual, true)
        );
    }
}

function diagnosticTimestamp(string $value): int
{
    return (new DateTimeImmutable($value))->getTimestamp();
}

$supportedMaster = [
    'uid'             => 'supported@example.com',
    'summary'         => 'Supported private event title',
    'start'           => '2026-07-01T09:00:00+00:00',
    'end'             => '2026-07-01T10:00:00+00:00',
    'startTimestamp'  => diagnosticTimestamp('2026-07-01T09:00:00Z'),
    'endTimestamp'    => diagnosticTimestamp('2026-07-01T10:00:00Z'),
    'allDay'          => false,
    'timezone'        => 'UTC',
    'recurrenceRule'  => 'FREQ=DAILY;COUNT=4',
    'recurrenceDates' => [
        ['timestamp' => diagnosticTimestamp('2026-07-05T09:00:00Z')]
    ],
    'exceptionDates'  => [
        ['timestamp' => diagnosticTimestamp('2026-07-02T09:00:00Z')]
    ]
];
$supportedOverride = [
    'uid'                   => 'supported@example.com',
    'summary'               => 'Moved private event title',
    'start'                 => '2026-07-03T10:00:00+00:00',
    'end'                   => '2026-07-03T11:00:00+00:00',
    'startTimestamp'        => diagnosticTimestamp('2026-07-03T10:00:00Z'),
    'endTimestamp'          => diagnosticTimestamp('2026-07-03T11:00:00Z'),
    'allDay'                => false,
    'timezone'              => 'UTC',
    'recurrenceId'          => '20260703T090000',
    'recurrenceIdTimestamp' => diagnosticTimestamp('2026-07-03T09:00:00Z'),
    'originalStart'         => '2026-07-03T09:00:00+00:00'
];
$unsupportedMaster = [
    'uid'             => 'unsupported@example.com',
    'summary'         => 'Unsupported private event title',
    'start'           => '2026-07-10T09:00:00+00:00',
    'end'             => '2026-07-10T10:00:00+00:00',
    'startTimestamp'  => diagnosticTimestamp('2026-07-10T09:00:00Z'),
    'endTimestamp'    => diagnosticTimestamp('2026-07-10T10:00:00Z'),
    'allDay'          => false,
    'timezone'        => 'UTC',
    'recurrenceRule'  => 'FREQ=DAILY;BYHOUR=9,17;COUNT=4',
    'recurrenceDates' => [
        ['timestamp' => diagnosticTimestamp('2026-07-12T09:00:00Z')]
    ],
    'exceptionDates'  => []
];

$rangeStart = new DateTimeImmutable('2026-07-01T00:00:00Z');
$rangeEnd = new DateTimeImmutable('2026-07-20T00:00:00Z');
$supportedEvents = ICalendarRecurrence::expand(
    [$supportedMaster, $supportedOverride],
    $rangeStart,
    $rangeEnd
);
$unsupportedEvents = ICalendarRecurrence::expand([$unsupportedMaster], $rangeStart, $rangeEnd);
$diagnostics = ICalendarRecurrence::diagnostics([...$supportedEvents, ...$unsupportedEvents]);

assertRecurrenceDiagnostic(2, $diagnostics['seriesCount'], 'Two recurrence series must be summarized.');
assertRecurrenceDiagnostic(1, $diagnostics['supportedSeriesCount'], 'One supported recurrence series must be reported.');
assertRecurrenceDiagnostic(1, $diagnostics['unsupportedSeriesCount'], 'One unsupported recurrence series must be reported.');
assertRecurrenceDiagnostic(2, count($diagnostics['rules']), 'Different recurrence rules must have separate summaries.');

$rules = [];
foreach ($diagnostics['rules'] as $rule) {
    $rules[$rule['rule']] = $rule;
}
$supported = $rules['FREQ=DAILY;COUNT=4'] ?? null;
$unsupported = $rules['FREQ=DAILY;BYHOUR=9,17;COUNT=4'] ?? null;
assertRecurrenceDiagnostic(true, is_array($supported), 'Supported RRULE diagnostics are missing.');
assertRecurrenceDiagnostic(true, is_array($unsupported), 'Unsupported RRULE diagnostics are missing.');
assertRecurrenceDiagnostic(true, $supported['supported'], 'Supported RRULE must remain marked as supported.');
assertRecurrenceDiagnostic([], $supported['unsupportedParts'], 'Supported RRULE must not report unsupported parts.');
assertRecurrenceDiagnostic(1, $supported['seriesCount'], 'Supported RRULE series count is incorrect.');
assertRecurrenceDiagnostic(4, $supported['occurrencesInRange'], 'Supported RRULE occurrence count is incorrect.');
assertRecurrenceDiagnostic(1, $supported['overrideOccurrencesInRange'], 'RFC override count is incorrect.');
assertRecurrenceDiagnostic(1, $supported['rDateCount'], 'RDATE count is incorrect.');
assertRecurrenceDiagnostic(1, $supported['exDateCount'], 'EXDATE count is incorrect.');
assertRecurrenceDiagnostic('UTC', $supported['timezone'], 'Recurrence timezone must be retained in diagnostics.');

assertRecurrenceDiagnostic(false, $unsupported['supported'], 'Unsupported RRULE must be marked as unsupported.');
assertRecurrenceDiagnostic(['BYHOUR'], $unsupported['unsupportedParts'], 'Unsupported RRULE parts are incorrect.');
assertRecurrenceDiagnostic(2, $unsupported['occurrencesInRange'], 'Unsupported RRULE must only count safe explicit occurrences.');
assertRecurrenceDiagnostic(0, $unsupported['overrideOccurrencesInRange'], 'Unsupported RRULE override count is incorrect.');
assertRecurrenceDiagnostic(1, $unsupported['rDateCount'], 'Unsupported RRULE RDATE count is incorrect.');
assertRecurrenceDiagnostic(0, $unsupported['exDateCount'], 'Unsupported RRULE EXDATE count is incorrect.');

$encodedDiagnostics = json_encode($diagnostics, JSON_THROW_ON_ERROR);
assertRecurrenceDiagnostic(
    false,
    str_contains($encodedDiagnostics, '@example.com')
        || str_contains($encodedDiagnostics, 'private event title'),
    'Recurrence diagnostics must not expose event identity or titles.'
);
assertRecurrenceDiagnostic(
    [
        'seriesCount'            => 0,
        'supportedSeriesCount'   => 0,
        'unsupportedSeriesCount' => 0,
        'rules'                  => []
    ],
    ICalendarRecurrence::diagnostics([[
        'uid'            => 'single@example.com',
        'startTimestamp' => diagnosticTimestamp('2026-07-15T09:00:00Z'),
        'recurring'      => false
    ]]),
    'Non-recurring events must not create recurrence diagnostics.'
);

fwrite(STDOUT, "RFC recurrence diagnostics checks passed.\n");
