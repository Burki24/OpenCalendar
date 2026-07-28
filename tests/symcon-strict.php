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



$helperManifestPath = $root . '/libs/helper/manifest.json';
assertSymconStrict(is_file($helperManifestPath), 'The vendored helper manifest is missing.');
$helperManifest = json_decode(
    (string) file_get_contents($helperManifestPath),
    true,
    512,
    JSON_THROW_ON_ERROR
);
foreach ([
    'PersistentJsonCacheHelper',
    'ConfigurationFormHelper',
    'DataFlowHelper',
    'VariableHelper',
    'VisualizationAssetHelper',
    'ParentConnectionHelper',
    'HttpResponseHelper',
    'SymconOAuthHelper'
] as $helperName) {
    $helperPath = $root . '/libs/helper/' . $helperName . '.php';
    assertSymconStrict(is_file($helperPath), 'The vendored ' . $helperName . ' is missing.');
    assertSymconStrict(
        isset($helperManifest['helpers'][$helperName]),
        'The helper manifest is missing ' . $helperName . '.'
    );
}

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

$accountSource = (string) file_get_contents($root . '/Kalender Konto/module.php');
assertSymconStrict(
    str_contains($accountSource, 'use HttpResponseHelper;'),
    'The calendar account must use the shared HttpResponseHelper.'
);
$googleOAuthSource = (string) file_get_contents($root . '/Kalender Konto/traits/GoogleOAuthTrait.php');
$microsoftOAuthSource = (string) file_get_contents($root . '/Kalender Konto/traits/MicrosoftOAuthTrait.php');
assertSymconStrict(
    str_contains($googleOAuthSource, 'SendHtmlTextResponse(')
        && !str_contains($googleOAuthSource, "header('Content-Type: text/html; charset=utf-8')"),
    'Google OAuth responses must use HttpResponseHelper.'
);
assertSymconStrict(
    str_contains($microsoftOAuthSource, 'SendHtmlTextResponse(')
        && !str_contains($microsoftOAuthSource, "header('Content-Type: text/html; charset=utf-8')"),
    'Microsoft OAuth responses must use HttpResponseHelper.'
);

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
    str_contains($calendarSource, 'use VariableHelper;')
        && str_contains($calendarSource, '$this->VariableExists(\'Events\')')
        && !str_contains($calendarSource, "IPS_GetObjectIDByIdent('Events'"),
    'The calendar module must use VariableHelper for the legacy Events variable.'
);
assertSymconStrict(
    str_contains($viewSource, 'use VariableHelper;')
        && str_contains($viewSource, 'GetVariableIDByIdent(\'LastSynchronization\', $instanceId)')
        && str_contains($viewSource, "VariableExists('IPSViewCalendar')")
        && !str_contains($viewSource, 'findChildByIdent('),
    'The calendar view must use VariableHelper for variable lookups across calendar instances.'
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
