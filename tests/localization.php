<?php

declare(strict_types=1);

function assertLocalization(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$modules = [
    'Kalender'              => ['Calendar', 'Kalender'],
    'Kalender Ansicht'      => ['Calendar View', 'Kalender Ansicht'],
    'Kalender Konto'        => ['Calendar Account', 'Kalender Konto'],
    'Kalender Konfigurator' => ['Calendar Configurator', 'Kalender Konfigurator']
];

foreach ($modules as $directory => [$englishName, $germanName]) {
    $module = json_decode(
        file_get_contents($root . '/' . $directory . '/module.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $locale = json_decode(
        file_get_contents($root . '/' . $directory . '/locale.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    assertLocalization(
        ($module['name'] ?? '') === $englishName,
        sprintf('%s/module.json must use the English module name.', $directory)
    );
    assertLocalization(
        (($locale['translations']['de'][$englishName] ?? '') === $germanName),
        sprintf('%s/locale.json must translate the module name to German.', $directory)
    );
    assertLocalization(
        !isset($locale['translations']['en'][$germanName]),
        sprintf('%s/locale.json must not translate a German source module name to English.', $directory)
    );
}

$viewLocale = json_decode(
    file_get_contents($root . '/Kalender Ansicht/locale.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$viewGerman = $viewLocale['translations']['de'] ?? [];
$requiredViewTranslations = [
    ['Configure optional IPSView HTML output.', 'Optionale IPSView-HTML-Ausgabe konfigurieren.'],
    [
        'Configure the shared IPSView style used by the standalone HTML page.',
        'Gemeinsamen IPSView-Stil für die eigenständige HTML-Seite konfigurieren.'
    ]
];

foreach ($requiredViewTranslations as [$source, $translation]) {
    assertLocalization(
        ($viewGerman[$source] ?? '') === $translation,
        sprintf('Calendar View is missing the German translation for "%s".', $source)
    );
}

fwrite(STDOUT, 'Localization contract tests passed.' . PHP_EOL);
