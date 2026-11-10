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
        str_contains($source, 'use \\Burki24\\SymconModuleHelper\\DebugFlowHelper;'),
        'Calendar modules must activate the DebugHelper trait.'
    );
    assertDebugIntegration(
        !str_contains($source, '->SendDebug('),
        'Calendar modules must use the safe debug wrapper instead of direct SendDebug calls.'
    );
}

foreach ([$calendar, $account, $gateway] as $source) {
    assertDebugIntegration(str_contains($source, 'DebugFlowStart(__FUNCTION__, __LINE__'), 'Debug starts must use the actual source location.');
    assertDebugIntegration(str_contains($source, 'finally {'), 'Every start must have a finally-protected end.');
    assertDebugIntegration(str_contains($source, 'DebugFlowEnd(__FUNCTION__, __LINE__'), 'Every instrumented function needs an end.');
    assertDebugIntegration(!str_contains($source, 'SendSafeDebugException('), 'Raw exception messages must not be passed to the legacy helper.');
}

assertDebugIntegration(
    !str_contains($gateway, '->SendDebug('),
    'Calendar Account gateway must use the safe debug wrapper instead of direct SendDebug calls.'
);
assertDebugIntegration(
    str_contains($calendar, "'GeänderteFelder'        => array_values(array_keys(\$changes))"),
    'Event update debug output must log changed field names instead of event payload values.'
);
assertDebugIntegration(
    str_contains($gateway, "'Anfragefelder'     => count(\$request)"),
    'Gateway debug output must log request field count instead of untrusted request content.'
);
assertDebugIntegration(
    str_contains($gateway, "\$operation !== 'ReadEventsTransferPage'"),
    'Paged transfer reads must be excluded from per-request debug noise.'
);
assertDebugIntegration(
    str_contains($gateway, 'ICalendarRecurrence::diagnostics($events)'),
    'ICS/Webcal event reads must summarize recurrence diagnostics without provider-level Symcon coupling.'
);
assertDebugIntegration(
    str_contains($gateway, "\$providerType = \$this->ReadPropertyInteger('Provider');")
        && str_contains($gateway, '$providerType === self::PROVIDER_ICS'),
    'Recurrence expansion diagnostics must remain scoped to locally expanded ICS/Webcal events.'
);

fwrite(STDOUT, "OpenCalendar debug integration checks passed.\n");
