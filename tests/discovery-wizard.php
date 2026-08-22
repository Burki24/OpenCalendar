<?php

declare(strict_types=1);

function assertDiscoveryWizard(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$moduleDirectory = $root . '/Kalender Einrichtung';

$library = json_decode(
    (string) file_get_contents($root . '/library.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$moduleMetadata = json_decode(
    (string) file_get_contents($moduleDirectory . '/module.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$form = json_decode(
    (string) file_get_contents($moduleDirectory . '/form.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$locale = json_decode(
    (string) file_get_contents($moduleDirectory . '/locale.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$moduleSource = (string) file_get_contents($moduleDirectory . '/module.php');

assertDiscoveryWizard(
    ($library['compatibility']['version'] ?? '') === '9.1'
        && ($library['version'] ?? '') === '3.0',
    'OpenCalendar Discovery requires the Symcon 9.1 / OpenCalendar 3.0 development baseline.'
);
assertDiscoveryWizard(
    ($moduleMetadata['id'] ?? '') === '{3CD8AB0E-BDB4-4B3F-A838-B4003A3BAD81}',
    'OpenCalendar Discovery module GUID changed unexpectedly.'
);
assertDiscoveryWizard(
    ($moduleMetadata['type'] ?? null) === 5
        && ($moduleMetadata['prefix'] ?? '') === 'IPSKALDISCOVERY'
        && ($moduleMetadata['name'] ?? '') === 'OpenCalendar Discovery',
    'OpenCalendar Discovery must use the expected type, prefix and technical name.'
);
assertDiscoveryWizard(
    str_contains($moduleSource, 'class OpenCalendarDiscovery extends IPSModuleStrict'),
    'OpenCalendar Discovery class must match the technical module name without spaces.'
);
assertDiscoveryWizard(
    ($moduleMetadata['url'] ?? '') === 'https://github.com/Burki24/OpenCalendar/blob/main/Kalender%20Einrichtung/README.md',
    'OpenCalendar Discovery documentation URL must match the repository folder.'
);

$wizard = null;
foreach ($form['actions'] ?? [] as $action) {
    if (($action['type'] ?? '') === 'PopupButton' && ($action['name'] ?? '') === 'SetupWizard') {
        $wizard = $action;
        break;
    }
}
assertDiscoveryWizard(is_array($wizard), 'OpenCalendar Discovery must expose the setup PopupButton.');

$pages = $wizard['popup']['pages'] ?? [];
assertDiscoveryWizard(
    is_array($pages)
        && count($pages) === 3
        && ($pages[0]['name'] ?? '') === 'welcome'
        && ($pages[1]['name'] ?? '') === 'provider'
        && ($pages[2]['name'] ?? '') === 'summary',
    'OpenCalendar Discovery must provide the initial three-page wizard flow.'
);
assertDiscoveryWizard(
    ($pages[0]['nextPage'] ?? '') === 'provider'
        && ($pages[1]['nextPage'] ?? '') === 'summary',
    'OpenCalendar Discovery wizard pages must use explicit named navigation.'
);

$providerSelector = null;
foreach ($pages[1]['items'] ?? [] as $item) {
    if (($item['type'] ?? '') === 'RadioButtonGroup' && ($item['name'] ?? '') === 'Provider') {
        $providerSelector = $item;
        break;
    }
}
assertDiscoveryWizard(is_array($providerSelector), 'Provider selection must use the Symcon 9.1 RadioButtonGroup.');

$providerValues = array_map(
    static fn (array $option): string => (string) ($option['value'] ?? ''),
    $providerSelector['options'] ?? []
);
assertDiscoveryWizard(
    $providerValues === ['', 'google', 'microsoft', 'apple', 'caldav', 'ics'],
    'Provider selection must contain the expected OpenCalendar providers.'
);
assertDiscoveryWizard(
    str_contains((string) ($pages[1]['validate'] ?? ''), '$Provider')
        && str_contains((string) ($pages[1]['validate'] ?? ''), 'Please select a calendar provider.'),
    'Provider page must block navigation until a provider was selected.'
);

$formSource = (string) file_get_contents($moduleDirectory . '/form.json');
foreach (['IPS_CreateInstance', 'IPS_SetProperty', 'IPS_ApplyChanges'] as $writeOperation) {
    assertDiscoveryWizard(
        !str_contains($formSource, $writeOperation),
        'Initial wizard scaffold must not create or modify Symcon objects yet: ' . $writeOperation
    );
}

$germanTranslations = $locale['translations']['de'] ?? [];
assertDiscoveryWizard(
    ($germanTranslations['OpenCalendar Discovery'] ?? '') === 'Kalender Einrichtung',
    'German module name translation must be Kalender Einrichtung.'
);

foreach ([
    'Start OpenCalendar setup',
    'Welcome to OpenCalendar',
    'Choose calendar provider',
    'Calendar provider',
    'Please select a calendar provider.',
    'Setup overview'
] as $translationKey) {
    assertDiscoveryWizard(
        is_array($germanTranslations) && array_key_exists($translationKey, $germanTranslations),
        'Missing German OpenCalendar Discovery translation: ' . $translationKey
    );
}

echo "OpenCalendar Discovery wizard scaffold tests passed.\n";
