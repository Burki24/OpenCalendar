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
    $moduleSource = file_get_contents($root . '/' . $directory . '/module.php');
    $expectedClass = str_replace(' ', '', $englishName);

    assertLocalization(
        ($module['name'] ?? '') === $englishName,
        sprintf('%s/module.json must use the English module name.', $directory)
    );
    assertLocalization(
        preg_match(
            '/\bclass\s+' . preg_quote($expectedClass, '/') . '\s+extends\s+IPSModuleStrict\b/',
            $moduleSource
        ) === 1,
        sprintf('%s/module.php must declare class %s for module name %s.', $directory, $expectedClass, $englishName)
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
$viewScript = file_get_contents($root . '/Kalender Ansicht/visualization/app.js');
$requiredViewTranslations = [
    ['Configure optional IPSView HTML output.', 'Optionale IPSView-HTML-Ausgabe konfigurieren.'],
    [
        'Configure the shared IPSView style used by the standalone HTML page.',
        'Gemeinsamen IPSView-Stil für die eigenständige HTML-Seite konfigurieren.'
    ],
    ['Pattern', 'Muster'],
    ['Weekday position', 'Wochentagsposition'],
    ['Position', 'Position'],
    ['First', 'Erste'],
    ['Second', 'Zweite'],
    ['Third', 'Dritte'],
    ['Fourth', 'Vierte'],
    ['Last', 'Letzte'],
    ['Fixed date', 'Festes Datum'],
    ['Fixed day of month', 'Fester Monatstag'],
    ['Import ICS', 'ICS importieren'],
    ['ICS event imported.', 'ICS-Termin importiert.'],
    ['The ICS file is too large.', 'Die ICS-Datei ist zu groß.'],
    [
        'The selected file is not a valid single-event ICS file.',
        'Die ausgewählte Datei ist keine gültige ICS-Datei mit einem einzelnen Termin.'
    ],
    ['This ICS file contains multiple events.', 'Diese ICS-Datei enthält mehrere Termine.'],
    [
        'Recurring ICS invitations cannot be imported as a single event.',
        'Wiederkehrende ICS-Einladungen können hier nicht als Einzeltermin importiert werden.'
    ],
    ['Open in provider', 'Extern öffnen']
];

foreach ($requiredViewTranslations as [$source, $translation]) {
    assertLocalization(
        ($viewGerman[$source] ?? '') === $translation,
        sprintf('Calendar View is missing the German translation for "%s".', $source)
    );
}

assertLocalization(
    !str_contains($viewScript, 'const icsImportMessages =')
        && !str_contains($viewScript, 'const providerLinkMessages =')
        && !str_contains($viewScript, 'const german = document.documentElement.lang')
        && str_contains($viewScript, "modeLabel.textContent = t('Pattern');")
        && str_contains($viewScript, "relative.textContent = t('Weekday position');")
        && str_contains($viewScript, 'option.textContent = t(label);')
        && str_contains($viewScript, 'return t(value);'),
    'Calendar View visualization must use the shared translation pipeline instead of German-only fallback maps.'
);

fwrite(STDOUT, 'Localization contract tests passed.' . PHP_EOL);
