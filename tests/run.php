<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$commands = [
    ['Verify vendored helper integrity', ['python3', 'tests/helper_integrity.py']],
    ['Verify DataFlowHelper integration', ['python3', 'tests/data_flow_integration.py']],
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
