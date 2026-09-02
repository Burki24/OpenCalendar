<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\IPSViewControlThemeHelper;

require_once __DIR__ . '/../libs/helper/IPSViewControlThemeHelper.php';

function assertIPSViewStyleConfiguration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$moduleSource = (string) file_get_contents($root . '/Kalender Ansicht/module.php');
$configurationHelperSource = (string) file_get_contents($root . '/libs/helper/IPSViewStyleConfigurationHelper.php');
$controlThemeSource = (string) file_get_contents($root . '/libs/helper/IPSViewControlThemeHelper.php');
$helperManifest = json_decode(
    (string) file_get_contents($root . '/libs/helper/manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);

$fields = IPSViewControlThemeHelper::fields();
assertIPSViewStyleConfiguration(
    count($fields) === 109 && count(array_unique($fields)) === 109,
    'The shared IPSView native color catalogue must contain exactly 109 unique fields.'
);
assertIPSViewStyleConfiguration(
    count(IPSViewControlThemeHelper::families()) === 15,
    'The 109 native IPSView colors must remain grouped into 15 families.'
);
assertIPSViewStyleConfiguration(
    IPSViewControlThemeHelper::styleFieldForDocument([], 'ColorView') === 'ViewBackground'
        && IPSViewControlThemeHelper::styleFieldForDocument([], 'ColorPage') === 'PageBackground',
    'ColorView and ColorPage must keep separate View/Page semantics.'
);
assertIPSViewStyleConfiguration(
    !((bool) (IPSViewControlThemeHelper::definition('ColorView')['legacy'] ?? true)),
    'ColorView must remain a current native IPSView color field.'
);

assertIPSViewStyleConfiguration(
    str_contains($configurationHelperSource, "\$decoded['ColorView'] ?? null")
        && str_contains($configurationHelperSource, "\$viewColor = '#404040';")
        && !str_contains($configurationHelperSource, "\$decoded['ColorPage'] ?? \$decoded['ColorView']"),
    'Missing ColorView must resolve to the IPSView default #404040 and must never fall back to ColorPage.'
);
assertIPSViewStyleConfiguration(
    str_contains($configurationHelperSource, 'foreach (IPSViewControlThemeHelper::families() as $family => $fields)')
        && str_contains($configurationHelperSource, "IPSViewControlThemeHelper::FAMILY_CALENDAR    => 'IPSViewStyleNativeCalendarColors'"),
    'The shared configuration helper must expose all 15 native IPSView color families.'
);
assertIPSViewStyleConfiguration(
    str_contains($moduleSource, 'use Burki24\\SymconModuleHelper\\IPSViewStyleConfigurationHelper;')
        && str_contains($moduleSource, "require_once __DIR__ . '/../libs/helper/IPSViewStyleConfigurationHelper.php';")
        && str_contains($moduleSource, 'use IPSViewStyleConfigurationHelper;')
        && !str_contains($moduleSource, 'use Burki24\\SymconModuleHelper\\IPSViewStyleHelper;'),
    'Calendar View must use IPSViewStyleConfigurationHelper as its shared IPSView style layer.'
);

$configManifest = $helperManifest['helpers']['IPSViewStyleConfigurationHelper'] ?? null;
assertIPSViewStyleConfiguration(
    is_array($configManifest)
        && ($configManifest['version'] ?? null) === '1.0.4'
        && ($configManifest['sha256'] ?? null) === hash('sha256', $configurationHelperSource),
    'The vendored IPSViewStyleConfigurationHelper must match its helper manifest entry.'
);
assertIPSViewStyleConfiguration(
    hash('sha256', $controlThemeSource) === '099e3e32d0840d13a6ca95277b31fdaa7378137db3d88ba96c3617582df3eae0',
    'The vendored IPSViewControlThemeHelper must match the shared 1.0.2 helper source.'
);

fwrite(STDOUT, "Shared IPSView style configuration integration verified.\n");
