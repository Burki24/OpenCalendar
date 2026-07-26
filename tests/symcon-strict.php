<?php

declare(strict_types=1);

function assertSymconStrict(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$library = json_decode(
    (string) file_get_contents($root . '/library.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertSymconStrict(
    version_compare((string) ($library['compatibility']['version'] ?? '0'), '9.0', '>='),
    'The library must require Symcon 9.0 or newer.'
);

foreach (glob($root . '/*/module.json') ?: [] as $moduleJson) {
    $moduleDirectory = dirname($moduleJson);
    $moduleSourcePath = $moduleDirectory . '/module.php';
    assertSymconStrict(is_file($moduleSourcePath), basename($moduleDirectory) . ' is missing module.php.');

    $moduleSource = (string) file_get_contents($moduleSourcePath);
    assertSymconStrict(
        preg_match('/class\s+[A-Za-z0-9_]+\s+extends\s+IPSModuleStrict\b/', $moduleSource) === 1,
        basename($moduleDirectory) . ' must extend IPSModuleStrict.'
    );

    assertSymconStrict(
        preg_match(
            '/RegisterVariable(?:Boolean|Integer|Float|String)\([^;\n]*,\s*[\'\"][^\'\"]*[\'\"]\s*,\s*\d+\s*\);/',
            $moduleSource
        ) !== 1,
        basename($moduleDirectory) . ' still uses the legacy string presentation argument with RegisterVariable*().'
    );

    assertSymconStrict(
        preg_match('/MaintainVariable\([\s\S]*?[\'\"]~[A-Za-z0-9_.-]+[\'\"]/', $moduleSource) !== 1,
        basename($moduleDirectory) . ' still uses a legacy variable profile with MaintainVariable().'
    );
}

$testsWorkflow = (string) file_get_contents($root . '/.github/workflows/tests.yml');
assertSymconStrict(
    str_contains($testsWorkflow, "php-version: '8.5'"),
    'The test workflow must run on PHP 8.5 to match Symcon 9.0.'
);



$helperSourcePath = $root . '/libs/helper/PersistentJsonCacheHelper.php';
assertSymconStrict(
    is_file($helperSourcePath),
    'The vendored PersistentJsonCacheHelper is missing.'
);
$helperSource = (string) file_get_contents($helperSourcePath);
assertSymconStrict(
    str_contains($helperSource, '@version 1.0.0'),
    'OpenCalendar must vendor the reviewed PersistentJsonCacheHelper version 1.0.0.'
);
assertSymconStrict(
    hash_file('sha256', $helperSourcePath) === 'adbc7680abe814dc6c15a9cda1312cc30023073595052006662716bc0d65f2a4',
    'The vendored PersistentJsonCacheHelper must match upstream version 1.0.0 exactly.'
);

$configurationFormHelperSourcePath = $root . '/libs/helper/ConfigurationFormHelper.php';
assertSymconStrict(
    is_file($configurationFormHelperSourcePath),
    'The vendored ConfigurationFormHelper is missing.'
);
$configurationFormHelperSource = (string) file_get_contents($configurationFormHelperSourcePath);
assertSymconStrict(
    str_contains($configurationFormHelperSource, '@version 1.0.0'),
    'OpenCalendar must vendor the reviewed ConfigurationFormHelper version 1.0.0.'
);
assertSymconStrict(
    hash_file('sha256', $configurationFormHelperSourcePath) === 'fa87dd4c67f43a3838fe87110387e4c1a1b98685c13403eeb52c189246045678',
    'The vendored ConfigurationFormHelper must match upstream version 1.0.0 exactly.'
);

$visualizationAssetHelperSourcePath = $root . '/libs/helper/VisualizationAssetHelper.php';
assertSymconStrict(
    is_file($visualizationAssetHelperSourcePath),
    'The vendored VisualizationAssetHelper is missing.'
);
$visualizationAssetHelperSource = (string) file_get_contents($visualizationAssetHelperSourcePath);
assertSymconStrict(
    str_contains($visualizationAssetHelperSource, '@version 1.0.0'),
    'OpenCalendar must vendor the reviewed VisualizationAssetHelper version 1.0.0.'
);
assertSymconStrict(
    hash_file('sha256', $visualizationAssetHelperSourcePath) === '1693b2399bcf95d270a6d9a01df6534caad906497bd4ba9489916a951abaffcc',
    'The vendored VisualizationAssetHelper must match upstream version 1.0.0 exactly.'
);

foreach ([
    'Kalender',
    'Kalender Konto',
    'Kalender Ansicht',
    'Kalender Konfigurator'
] as $moduleDirectory) {
    $moduleSource = (string) file_get_contents($root . '/' . $moduleDirectory . '/module.php');
    assertSymconStrict(
        str_contains($moduleSource, 'use ConfigurationFormHelper;'),
        $moduleDirectory . ' must use the shared ConfigurationFormHelper.'
    );
    assertSymconStrict(
        str_contains($moduleSource, '$this->LoadConfigurationForm()'),
        $moduleDirectory . ' must load form.json through ConfigurationFormHelper.'
    );
    assertSymconStrict(
        str_contains($moduleSource, '$this->EncodeConfigurationForm($form)'),
        $moduleDirectory . ' must serialize dynamic forms through ConfigurationFormHelper.'
    );
    assertSymconStrict(
        !str_contains($moduleSource, "file_get_contents(__DIR__ . '/form.json')"),
        $moduleDirectory . ' must not read form.json directly.'
    );
}

$calendarSource = (string) file_get_contents($root . '/Kalender/module.php');
assertSymconStrict(
    !str_contains($calendarSource, "RegisterVariableString('Events'"),
    'Calendar event payloads must not be mirrored into a String status variable.'
);
assertSymconStrict(
    !str_contains($calendarSource, "SetValue('Events'"),
    'Calendar event payloads must remain in the internal cache and be exposed through GetEvents().'
);
assertSymconStrict(
    str_contains($calendarSource, 'use PersistentJsonCacheHelper;'),
    'The calendar module must use the shared PersistentJsonCacheHelper.'
);
assertSymconStrict(
    str_contains($calendarSource, "RegisterPersistentJsonCache('CachedEvents')"),
    'CachedEvents must be registered through PersistentJsonCacheHelper.'
);
assertSymconStrict(
    str_contains($calendarSource, "WritePersistentJsonCache('CachedEvents', \$events)"),
    'CachedEvents must be written through PersistentJsonCacheHelper.'
);
assertSymconStrict(
    str_contains($calendarSource, "ReadPersistentJsonCache('CachedEvents')"),
    'CachedEvents must be read through PersistentJsonCacheHelper.'
);
assertSymconStrict(
    !str_contains($calendarSource, "RegisterAttributeString('CachedEvents'")
        && !str_contains($calendarSource, "WriteAttributeString('CachedEvents'")
        && !str_contains($calendarSource, "ReadAttributeString('CachedEvents'"),
    'CachedEvents must not bypass PersistentJsonCacheHelper.'
);
assertSymconStrict(
    str_contains($calendarSource, "UnregisterVariable('Events')"),
    'Existing legacy Events status variables must be removed during ApplyChanges().'
);

$viewSource = (string) file_get_contents($root . '/Kalender Ansicht/module.php');
assertSymconStrict(
    str_contains($viewSource, 'findChildByIdent($instanceId, \'LastSynchronization\')'),
    'The calendar view must use the small synchronization status variable as its update signal.'
);
assertSymconStrict(
    str_contains($viewSource, 'use VisualizationAssetHelper;')
        && str_contains($viewSource, '$this->VisualizationAsset(\'module.html\')')
        && !str_contains($viewSource, "file_get_contents(__DIR__ . '/module.html')"),
    'The calendar view must load visualization files through VisualizationAssetHelper.'
);
assertSymconStrict(
    is_file($root . '/Kalender Ansicht/visualization/module.html')
        && !is_file($root . '/Kalender Ansicht/module.html'),
    'The calendar view template must live in the visualization directory.'
);

fwrite(STDOUT, "Symcon Strict compliance checks passed.\n");
