<?php

declare(strict_types=1);

function liveProviderContractExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$liveTestPath = __DIR__ . '/live-provider-e2e.php';
$liveTest = file_get_contents($liveTestPath);
liveProviderContractExpect(
    is_string($liveTest) && $liveTest !== '',
    'The opt-in live provider end-to-end harness is missing.'
);

$runSource = file_get_contents(__DIR__ . '/run.php');
liveProviderContractExpect(
    is_string($runSource)
        && !str_contains($runSource, "'tests/live-provider-e2e.php'"),
    'The destructive live provider harness must never run from the normal CI test suite.'
);

foreach ([
    'OPENCALENDAR_LIVE_PROVIDER',
    'OPENCALENDAR_LIVE_CONFIRM_WRITE',
    'OPENCALENDAR_LIVE_ACCESS_TOKEN',
    'OPENCALENDAR_LIVE_SERVER_URL',
    'OPENCALENDAR_LIVE_USERNAME',
    'OPENCALENDAR_LIVE_PASSWORD',
    'OPENCALENDAR_LIVE_CALENDAR',
    'OPENCALENDAR_LIVE_TIMEZONE'
] as $environmentVariable) {
    liveProviderContractExpect(
        str_contains($liveTest, $environmentVariable),
        'The live provider harness is missing environment configuration: ' . $environmentVariable
    );
}

foreach ([
    'if ($providerName === \'google\')',
    'if ($providerName === \'microsoft\')',
    'in_array($providerName, [\'caldav\', \'apple\'], true)',
    'GoogleCalendarProvider',
    'MicrosoftCalendarProvider',
    'CalDAVProvider'
] as $providerMarker) {
    liveProviderContractExpect(
        str_contains($liveTest, $providerMarker),
        'The live provider harness is missing provider coverage: ' . $providerMarker
    );
}

foreach ([
    '->testConnection()',
    '->getCalendars()',
    '->createEvent(',
    '->getEventForEdit(',
    '->updateEvent(',
    '->deleteEvent(',
    '->getRecurringSeries(',
    '->getRecurringFollowing(',
    'CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE',
    'CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING',
    'CalendarEventRecurrence::WRITE_SCOPE_SERIES',
    "'allDay'   => true",
    'CalendarEventState::TRANSP_TRANSPARENT',
    'CalendarEventState::STATUS_TENTATIVE',
    'liveE2ECleanupTaggedEvents('
] as $scenarioMarker) {
    liveProviderContractExpect(
        str_contains($liveTest, $scenarioMarker),
        'The live provider harness is missing an end-to-end scenario marker: ' . $scenarioMarker
    );
}

liveProviderContractExpect(
    str_contains($liveTest, "liveE2EEnv('OPENCALENDAR_LIVE_CONFIRM_WRITE') === 'YES'")
        && str_contains($liveTest, "'OpenCalendar E2E ' . gmdate('Ymd-His')")
        && str_contains($liveTest, 'finally {'),
    'Live provider writes must require explicit confirmation, use unique test tags, and always attempt cleanup.'
);

$documentation = file_get_contents(__DIR__ . '/LIVE_PROVIDER_E2E.md');
liveProviderContractExpect(
    is_string($documentation)
        && str_contains($documentation, 'OPENCALENDAR_LIVE_CONFIRM_WRITE=YES')
        && str_contains($documentation, 'Google Calendar')
        && str_contains($documentation, 'Microsoft 365')
        && str_contains($documentation, 'Apple iCloud')
        && str_contains($documentation, 'CalDAV')
        && str_contains($documentation, 'temporary test events'),
    'Live provider end-to-end test documentation is incomplete.'
);

fwrite(STDOUT, "Live provider E2E harness contract tests passed.\n");
