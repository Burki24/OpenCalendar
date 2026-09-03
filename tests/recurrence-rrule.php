<?php

declare(strict_types=1);

use IPSKalender\CalendarRecurrenceRule;

require_once __DIR__ . '/../libs/CalendarRecurrenceRule.php';

function recurrenceRRuleExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$start = new DateTimeImmutable('2026-09-03T10:30:00+02:00');
$recurrence = [
    'frequency' => 'WEEKLY',
    'interval'  => 2,
    'byDay'     => ['MO', 'WE', 'FR'],
    'weekStart' => 'SU',
    'endMode'   => 'count',
    'count'     => 7
];
$expected = 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE,FR;WKST=SU;COUNT=7';

recurrenceRRuleExpect(
    CalendarRecurrenceRule::toRRule($recurrence, $start, false, 'Europe/Berlin') === $expected,
    'Provider-neutral RRULE serialization changed.'
);
recurrenceRRuleExpect(
    CalendarRecurrenceRule::toICalendarRule($recurrence, $start, false, 'Europe/Berlin') === $expected,
    'iCalendar serialization must use the provider-neutral RRULE core.'
);
recurrenceRRuleExpect(
    CalendarRecurrenceRule::toGoogleLines($recurrence, $start, false, 'Europe/Berlin') === [$expected],
    'The Google recurrence compatibility wrapper changed.'
);

$parsed = CalendarRecurrenceRule::fromRRule($expected, false, 'Europe/Berlin');
recurrenceRRuleExpect(
    $parsed === [
        'frequency' => 'WEEKLY',
        'interval'  => 2,
        'endMode'   => 'count',
        'byDay'     => ['MO', 'WE', 'FR'],
        'weekStart' => 'SU',
        'count'     => 7
    ],
    'Provider-neutral RRULE parsing changed.'
);
recurrenceRRuleExpect(
    CalendarRecurrenceRule::fromGoogleRule($expected, false, 'Europe/Berlin') === $parsed,
    'The Google recurrence parser compatibility wrapper changed.'
);

$allDayRecurrence = [
    'frequency'  => 'YEARLY',
    'interval'   => 1,
    'dayOfMonth' => 3,
    'month'      => 9,
    'endMode'    => 'until',
    'until'      => '2030-09-03'
];
$allDayExpected = 'RRULE:FREQ=YEARLY;BYMONTH=9;BYMONTHDAY=3;UNTIL=20300903';
recurrenceRRuleExpect(
    CalendarRecurrenceRule::toRRule(
        $allDayRecurrence,
        new DateTimeImmutable('2026-09-03T00:00:00+00:00'),
        true,
        'Europe/Berlin'
    ) === $allDayExpected,
    'All-day RRULE serialization changed.'
);
recurrenceRRuleExpect(
    CalendarRecurrenceRule::fromRRule($allDayExpected, true, 'Europe/Berlin') === [
        'frequency'  => 'YEARLY',
        'interval'   => 1,
        'endMode'    => 'until',
        'dayOfMonth' => 3,
        'month'      => 9,
        'until'      => '2030-09-03'
    ],
    'All-day RRULE parsing changed.'
);

$source = file_get_contents(__DIR__ . '/../libs/CalendarRecurrenceRule.php');
recurrenceRRuleExpect(
    is_string($source)
        && str_contains($source, 'return [self::toRRule($recurrence, $start, $allDay, $timezone)];')
        && str_contains($source, 'return self::toRRule($recurrence, $start, $allDay, $timezone);')
        && str_contains($source, 'return self::fromRRule($rule, $allDay, $timezone);')
        && str_contains($source, 'if (self::fromRRule($rule, $allDay, $timezone) === null)'),
    'Legacy provider wrappers must delegate to the provider-neutral RRULE core.'
);

echo "Provider-neutral RFC recurrence handling verified.\n";
