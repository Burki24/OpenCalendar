<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$commands = [
    ['Verify vendored helper integrity', ['python3', 'tests/helper_integrity.py']],
    ['Verify DataFlowHelper integration', ['python3', 'tests/data_flow_integration.py']],
    ['Check safe debug integration', [PHP_BINARY, 'tests/debug-integration.php']],
    ['Check RFC recurrence diagnostics', [PHP_BINARY, 'tests/recurrence-diagnostics.php']],
    ['Check RFC year/week recurrence rules', [PHP_BINARY, 'tests/recurrence-year-week.php']],
    ['Check RFC hour/minute recurrence rules', [PHP_BINARY, 'tests/recurrence-hour-minute.php']],
    ['Check module localization contract', [PHP_BINARY, 'tests/localization.php']],
    ['Verify chunked event transfers', [PHP_BINARY, 'tests/chunked-event-transfer.php']],
    ['Verify current-day event counting', [PHP_BINARY, 'tests/calendar-event-counter.php']],
    ['Verify annual-event metadata and calculations', [PHP_BINARY, 'tests/birthday.php']],
    ['Verify Calendar View PHP API', [PHP_BINARY, 'tests/calendar-view-api.php']],
    ['Verify calendar configurator cache isolation', [PHP_BINARY, 'tests/configurator-cache.php']],
    ['Run OpenCalendar provider and integration tests', [PHP_BINARY, 'tests/calendar-provider.php']],
    ['Check shared visualization contract', [PHP_BINARY, 'tests/visualization.php']],
    ['Run account module structure tests', [PHP_BINARY, 'tests/account-module.php']],
    ['Check public PHPDoc coverage', [PHP_BINARY, 'tests/phpdocs.php']],
    ['Check Symcon Strict compliance', [PHP_BINARY, 'tests/symcon-strict.php']],
    ['Run CalDAV provider tests', [PHP_BINARY, 'tests/caldav.php']],
    ['Run CalDAV HTTP integration tests', ['bash', 'tests/run-caldav-http.sh']]
];

foreach ($commands as [$label, $command]) {
    fwrite(STDOUT, PHP_EOL . '==> ' . $label . PHP_EOL);

    $escapedCommand = implode(' ', array_map(
        static fn (string $argument): string => escapeshellarg($argument),
        $command
    ));

    passthru('cd ' . escapeshellarg($root) . ' && ' . $escapedCommand, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, PHP_EOL . 'Test step failed: ' . $label . PHP_EOL);
        exit($exitCode);
    }
}

fwrite(STDOUT, PHP_EOL . "All OpenCalendar test suites passed.\n");
