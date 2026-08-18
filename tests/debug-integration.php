<?php

declare(strict_types=1);

function assertDebugIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$calendar = file_get_contents($root . '/Kalender/module.php');
$account = file_get_contents($root . '/Kalender Konto/module.php');
$gateway = file_get_contents($root . '/Kalender Konto/traits/ChildGatewayTrait.php');

assertDebugIntegration(is_string($calendar), 'Calendar module source must be readable.');
assertDebugIntegration(is_string($account), 'Calendar Account module source must be readable.');
assertDebugIntegration(is_string($gateway), 'Calendar Account child gateway source must be readable.');

foreach ([$calendar, $account] as $source) {
    assertDebugIntegration(
        str_contains($source, 'use Burki24\\SymconModuleHelper\\DebugHelper;'),
        'Calendar modules must import DebugHelper.'
    );
    assertDebugIntegration(
        str_contains($source, "require_once __DIR__ . '/../libs/helper/DebugHelper.php';"),
        'Calendar modules must load the vendored DebugHelper.'
    );
    assertDebugIntegration(
        str_contains($source, 'use DebugHelper;'),
        'Calendar modules must activate the DebugHelper trait.'
    );
    assertDebugIntegration(
        !str_contains($source, '->SendDebug('),
        'Calendar modules must use the safe debug wrapper instead of direct SendDebug calls.'
    );
}

foreach ([
    'SynchronizationStart',
    'SynchronizationCompleted',
    'CalendarMetadata',
    'EventTransferStart',
    'EventTransferMetadata',
    'EventTransferCompleted',
    'EventCreate',
    'EventUpdate',
    'EventDelete',
    'CalendarError'
] as $message) {
    assertDebugIntegration(
        str_contains($calendar, "SendSafeDebug('" . $message . "'"),
        'Calendar debug contract is missing message: ' . $message
    );
}

foreach ([
    'ConnectionTestStart',
    'ConnectionTestCompleted',
    'AccountSynchronizationStart',
    'AccountSynchronizationCompleted',
    'CalendarDiscoveryStart',
    'CalendarDiscoveryCompleted',
    'ProviderCreate',
    'ProviderError'
] as $message) {
    assertDebugIntegration(
        str_contains($account, "SendSafeDebug('" . $message . "'"),
        'Calendar Account debug contract is missing message: ' . $message
    );
}

assertDebugIntegration(
    !str_contains($gateway, '->SendDebug('),
    'Calendar Account gateway must use the safe debug wrapper instead of direct SendDebug calls.'
);
foreach (['ChildRequest', 'ChildRequestCompleted', 'ChildRequestError', 'ProviderEvents'] as $message) {
    assertDebugIntegration(
        str_contains($gateway, "SendSafeDebug('" . $message . "'"),
        'Calendar Account gateway debug contract is missing message: ' . $message
    );
}

assertDebugIntegration(
    str_contains($calendar, "'changedFields'      => array_values(array_keys(\$changes))"),
    'Event update debug output must log changed field names instead of event payload values.'
);
assertDebugIntegration(
    str_contains($gateway, "'requestFields'     => array_values(array_keys(\$request))"),
    'Gateway debug output must log request field names instead of request payload values.'
);
assertDebugIntegration(
    str_contains($gateway, "\$operation !== 'ReadEventsTransferPage'"),
    'Paged transfer reads must be excluded from per-request debug noise.'
);

fwrite(STDOUT, "OpenCalendar debug integration checks passed.\n");
