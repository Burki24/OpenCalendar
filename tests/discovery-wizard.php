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
        && count($pages) === 4
        && ($pages[0]['name'] ?? '') === 'welcome'
        && ($pages[1]['name'] ?? '') === 'provider'
        && ($pages[2]['name'] ?? '') === 'account'
        && ($pages[3]['name'] ?? '') === 'summary',
    'OpenCalendar Discovery must provide the four-page account setup flow.'
);
assertDiscoveryWizard(
    ($pages[0]['nextPage'] ?? '') === 'provider'
        && ($pages[1]['nextPage'] ?? '') === 'account'
        && ($pages[2]['nextPage'] ?? '') === 'summary',
    'OpenCalendar Discovery wizard pages must use explicit named navigation.'
);
assertDiscoveryWizard(
    str_contains((string) ($pages[1]['onConfirm'] ?? ''), 'WizardProvider')
        && str_contains((string) ($pages[1]['onUndo'] ?? ''), 'WizardProviderUndo'),
    'Provider page must preserve and undo wizard provider state.'
);

$accountMode = null;
$existingAccount = null;
foreach ($pages[2]['items'] ?? [] as $item) {
    if (($item['name'] ?? '') === 'AccountMode') {
        $accountMode = $item;
    }
    if (($item['name'] ?? '') === 'ExistingAccountID') {
        $existingAccount = $item;
    }
}

assertDiscoveryWizard(
    is_array($accountMode) && ($accountMode['type'] ?? '') === 'RadioButtonGroup',
    'Calendar account mode must use a RadioButtonGroup.'
);
assertDiscoveryWizard(
    is_array($existingAccount)
        && ($existingAccount['type'] ?? '') === 'SelectModule'
        && ($existingAccount['moduleID'] ?? '') === '{966D6119-7FF3-5CA5-06C3-536FBF8100C4}',
    'Existing calendar account selection must be restricted to Calendar Account instances.'
);
assertDiscoveryWizard(
    str_contains((string) ($pages[2]['validate'] ?? ''), 'ValidateWizardAccountSelection')
        && str_contains((string) ($pages[2]['onConfirm'] ?? ''), 'WizardAccountSelection')
        && str_contains((string) ($pages[2]['onUndo'] ?? ''), 'WizardAccountSelectionUndo'),
    'Calendar account page must validate, preserve and undo its selection.'
);
assertDiscoveryWizard(
    str_contains((string) ($pages[3]['validate'] ?? ''), 'ValidateWizardConfirmation')
        && str_contains((string) ($pages[3]['onConfirm'] ?? ''), 'WizardConfirmAccount'),
    'Final page must validate and confirm the calendar account selection.'
);

foreach ([
    'IPS_CreateInstance(self::CALENDAR_ACCOUNT_MODULE_ID)',
    'IPS_SetProperty($accountID, \'Provider\'',
    'IPS_SetProperty($accountID, \'Active\', false)',
    'IPS_ApplyChanges($accountID)',
    'IPS_DeleteInstance($accountID)',
    'IPS_GetInstanceListByModuleID(self::CALENDAR_ACCOUNT_MODULE_ID)',
    'RegisterAttributeInteger(\'SelectedCalendarAccountID\', 0)'
] as $requiredSource) {
    assertDiscoveryWizard(
        str_contains($moduleSource, $requiredSource),
        'Missing calendar account setup behavior: ' . $requiredSource
    );
}

$germanTranslations = $locale['translations']['de'] ?? [];
foreach ([
    'OpenCalendar Discovery',
    'Choose calendar account',
    'Create a new calendar account',
    'Use an existing calendar account',
    'Please enter a name for the calendar account.',
    'The selected calendar account uses a different calendar provider.',
    'Calendar account setup'
] as $translationKey) {
    assertDiscoveryWizard(
        is_array($germanTranslations) && array_key_exists($translationKey, $germanTranslations),
        'Missing German OpenCalendar Discovery translation: ' . $translationKey
    );
}

echo "OpenCalendar Discovery wizard tests passed.\n";
