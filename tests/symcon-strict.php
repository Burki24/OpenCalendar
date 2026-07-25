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

fwrite(STDOUT, "Symcon Strict compliance checks passed.\n");
