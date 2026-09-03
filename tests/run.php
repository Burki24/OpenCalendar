<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$commands = [
    ['Verify vendored helper integrity', ['python3', 'tests/helper_integrity.py']],
    ['Verify DataFlowHelper integration', ['python3', 'tests/data_flow_integration.py']],
    ['Check safe debug integration', [PHP_BINARY, 'tests/debug-integration.php']],
    ['Check RFC recurrence diagnostics', [PHP_BINARY, 'tests/recurrence-diagnostics.php']],
    ['Check provider-neutral RFC recurrence handling', [PHP_BINARY, 'tests/recurrence-rrule.php']],
    ['Check RFC year/week recurrence rules', [PHP_BINARY, 'tests/recurrence-year-week.php']],
    ['Check RFC hour/minute recurrence rules', [PHP_BINARY, 'tests/recurrence-hour-minute.php']],
    ['Check RFC time-aware BYSETPOS rules', [PHP_BINARY, 'tests/recurrence-setpos-time.php']],
    ['Check RFC VTIMEZONE import', [PHP_BINARY, 'tests/vtimezone.php']],
    ['Check timezone and DST hardening', [PHP_BINARY, 'tests/timezone-dst.php']],
    ['Check provider timezone/DST parity', [PHP_BINARY, 'tests/provider-timezone-dst.php']],
    ['Run data load and recurrence stress tests', [PHP_BINARY, 'tests/load-stress.php']],
    ['Check module localization contract', [PHP_BINARY, 'tests/localization.php']],
    ['Verify chunked event transfers', [PHP_BINARY, 'tests/chunked-event-transfer.php']],
    ['Verify current-day event counting', [PHP_BINARY, 'tests/calendar-event-counter.php']],
    ['Verify annual-event metadata and calculations', [PHP_BINARY, 'tests/birthday.php']],
    ['Verify annual-event metadata synchronization', [PHP_BINARY, 'tests/anniversary-metadata-sync.php']],
    ['Verify Calendar View PHP API', [PHP_BINARY, 'tests/calendar-view-api.php']],
    ['Verify calendar configurator cache isolation', [PHP_BINARY, 'tests/configurator-cache.php']],
    ['Verify Google incremental synchronization', [PHP_BINARY, 'tests/google-incremental-sync.php']],
    ['Verify Microsoft incremental synchronization', [PHP_BINARY, 'tests/microsoft-incremental-sync.php']],
    ['Verify Microsoft incremental synchronization routing', [PHP_BINARY, 'tests/microsoft-full-sync-routing.php']],
    ['Verify Microsoft synchronization diagnostics', [PHP_BINARY, 'tests/microsoft-sync-diagnostics.php']],
    ['Verify Microsoft series-master synchronization', [PHP_BINARY, 'tests/microsoft-series-master-sync.php']],
    ['Verify Microsoft post-write synchronization', [PHP_BINARY, 'tests/microsoft-write-sync.php']],
    ['Verify provider-neutral post-write refresh', [PHP_BINARY, 'tests/post-write-refresh.php']],
    ['Verify provider-neutral post-delete refresh', [PHP_BINARY, 'tests/post-delete-refresh.php']],
    ['Verify provider-neutral error contract', [PHP_BINARY, 'tests/provider-error-contract.php']],
    ['Verify realistic provider error handling', [PHP_BINARY, 'tests/provider-real-errors.php']],
    ['Verify provider write parity matrix', [PHP_BINARY, 'tests/write-parity.php']],
    ['Verify Microsoft event-edit identity', [PHP_BINARY, 'tests/microsoft-event-edit.php']],
    ['Verify CalDAV incremental synchronization', [PHP_BINARY, 'tests/caldav-incremental-sync.php']],
    ['Verify provider parity matrix', [PHP_BINARY, 'tests/provider-parity.php']],
    ['Verify shared calendar provider type mapping', [PHP_BINARY, 'tests/provider-type.php']],
    ['Run OpenCalendar provider and integration tests', [PHP_BINARY, 'tests/calendar-provider.php']],
    ['Check shared visualization contract', [PHP_BINARY, 'tests/visualization.php']],
    ['Verify IPSView font role rendering', [PHP_BINARY, 'tests/ipsview-style-font-rendering.php']],
    ['Verify IPSView Assistant Style Profile V1 E2E', [PHP_BINARY, 'tests/style-profile-e2e.php']],
    ['Verify shared IPSView style configuration', [PHP_BINARY, 'tests/ipsview-style-configuration.php']],
    ['Verify IPSView documentation contract', [PHP_BINARY, 'tests/ipsview-documentation.php']],
    ['Check visualization UI regression contract', [PHP_BINARY, 'tests/ui-regression.php']],
    ['Run account module structure tests', [PHP_BINARY, 'tests/account-module.php']],
    ['Check public PHPDoc coverage', [PHP_BINARY, 'tests/phpdocs.php']],
    ['Check Symcon Strict compliance', [PHP_BINARY, 'tests/symcon-strict.php']],
    ['Verify live provider E2E harness contract', [PHP_BINARY, 'tests/live-provider-e2e-contract.php']],
    ['Audit Symcon 9.1 Rust runtime boundaries', [PHP_BINARY, 'tests/symcon-9.1-runtime.php']],
    ['Verify OpenCalendar 2.0 -> 3.0 upgrade contract', [PHP_BINARY, 'tests/upgrade-migration.php']],
    ['Verify upgrade ApplyChanges and restart contract', [PHP_BINARY, 'tests/upgrade-runtime-restart.php']],
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
