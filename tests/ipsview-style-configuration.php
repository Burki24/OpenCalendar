<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\IPSViewControlThemeHelper;
use Burki24\SymconModuleHelper\IPSViewStyleConfigurationHelper;

require_once __DIR__ . '/../libs/helper/IPSViewControlThemeHelper.php';
require_once __DIR__ . '/../libs/helper/IPSViewStyleConfigurationHelper.php';

class IPSViewStyleConfigurationConsumer
{
    use IPSViewStyleConfigurationHelper {
        IPSViewStyleFormItems as public formItems;
        IPSViewStyleNativeOverrideProperties as public overrideProperties;
        IPSViewStyleNativeTheme as public nativeTheme;
    }

    protected function ReadPropertyInteger(string $name): int
    {
        return $name === 'IPSViewStyleSource' ? self::IPSVIEW_STYLE_SOURCE_LIGHT : 0;
    }

    protected function ReadPropertyBoolean(string $name): bool
    {
        return false;
    }

    protected function ReadPropertyFloat(string $name): float
    {
        return 0.0;
    }

    protected function ReadPropertyString(string $name): string
    {
        return '';
    }
}

final class FilteredIPSViewStyleConfigurationConsumer extends IPSViewStyleConfigurationConsumer
{
    protected function IPSViewStyleNativeFamilyNames(): array
    {
        return [IPSViewControlThemeHelper::FAMILY_BASE, IPSViewControlThemeHelper::FAMILY_CALENDAR, 'unknown'];
    }
}

function nativeStyleFormFamilies(IPSViewStyleConfigurationConsumer $consumer): array
{
    foreach ($consumer->formItems() as $item) {
        if (($item['name'] ?? '') === 'IPSViewStyleNativeColorsPanel') {
            return array_values(array_column($item['items'], 'name'));
        }
    }

    throw new RuntimeException('The native IPSView color panel is missing.');
}

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
$defaultConsumer = new IPSViewStyleConfigurationConsumer();
$filteredConsumer = new FilteredIPSViewStyleConfigurationConsumer();
assertIPSViewStyleConfiguration(
    nativeStyleFormFamilies($defaultConsumer) === array_map(
        static fn (string $family): string => 'IPSViewStyleNativeFamily_' . $family,
        array_keys(IPSViewControlThemeHelper::families())
    ),
    'The shared configuration helper must expose all 15 native IPSView color families by default.'
);
assertIPSViewStyleConfiguration(
    nativeStyleFormFamilies($filteredConsumer) === [
        'IPSViewStyleNativeFamily_' . IPSViewControlThemeHelper::FAMILY_BASE,
        'IPSViewStyleNativeFamily_' . IPSViewControlThemeHelper::FAMILY_CALENDAR
    ],
    'Consumers may select visible families; unknown family names must not produce invalid form controls.'
);
assertIPSViewStyleConfiguration(
    count($defaultConsumer->overrideProperties()) === 15
        && $filteredConsumer->overrideProperties() === $defaultConsumer->overrideProperties()
        && $filteredConsumer->nativeTheme() === $defaultConsumer->nativeTheme(),
    'Selecting form families must not remove stored override properties or narrow the native theme transport.'
);
assertIPSViewStyleConfiguration(
    str_contains($moduleSource, 'use Burki24\\SymconModuleHelper\\IPSViewStyleConfigurationHelper;')
        && str_contains($moduleSource, "require_once __DIR__ . '/../libs/helper/IPSViewStyleConfigurationHelper.php';")
        && str_contains($moduleSource, 'use IPSViewStyleConfigurationHelper;')
        && !str_contains($moduleSource, 'use Burki24\\SymconModuleHelper\\IPSViewStyleHelper;'),
    'Calendar View must use IPSViewStyleConfigurationHelper as its shared IPSView style layer.'
);

$configManifest = $helperManifest['helpers']['IPSViewStyleConfigurationHelper'] ?? null;
$configVersionMatch = [];
assertIPSViewStyleConfiguration(
    is_array($configManifest)
        && preg_match('/@version\s+(\d+\.\d+\.\d+)/', $configurationHelperSource, $configVersionMatch) === 1
        && ($configManifest['version'] ?? null) === $configVersionMatch[1]
        && ($configManifest['sha256'] ?? null) === hash('sha256', $configurationHelperSource),
    'The vendored IPSViewStyleConfigurationHelper must match its helper manifest entry.'
);

$controlThemeManifest = $helperManifest['helpers']['IPSViewControlThemeHelper'] ?? null;
if (!is_array($controlThemeManifest) && is_array($configManifest)) {
    foreach ($configManifest['dependencies'] ?? [] as $dependency) {
        if (is_array($dependency) && ($dependency['name'] ?? null) === 'IPSViewControlThemeHelper') {
            $controlThemeManifest = $dependency;
            break;
        }
    }
}
$controlThemeVersionMatch = [];
assertIPSViewStyleConfiguration(
    is_array($controlThemeManifest)
        && preg_match('/@version\s+(\d+\.\d+\.\d+)/', $controlThemeSource, $controlThemeVersionMatch) === 1
        && ($controlThemeManifest['version'] ?? null) === $controlThemeVersionMatch[1]
        && ($controlThemeManifest['sha256'] ?? null) === hash('sha256', $controlThemeSource),
    'The vendored IPSViewControlThemeHelper must match its helper manifest entry.'
);

fwrite(STDOUT, "Shared IPSView style configuration integration verified.\n");
