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

$wizard = null;
foreach ($form['actions'] ?? [] as $action) {
    if (($action['type'] ?? '') === 'PopupButton' && ($action['name'] ?? '') === 'SetupWizard') {
        $wizard = $action;
        break;
    }
}
assertDiscoveryWizard(is_array($wizard), 'OpenCalendar Discovery must expose the setup PopupButton.');

$pages = $wizard['popup']['pages'] ?? [];
$pageNames = array_map(
    static fn (array $page): string => (string) ($page['name'] ?? ''),
    $pages
);
assertDiscoveryWizard(
    $pageNames === [
        'welcome',
        'provider',
        'account',
        'apple',
        'caldav',
        'google',
        'microsoft',
        'ics',
        'summary'
    ],
    'OpenCalendar Discovery must provide all provider-specific wizard pages.'
);

$accountPage = $pages[2];
assertDiscoveryWizard(
    str_contains((string) ($accountPage['validate'] ?? ''), 'ValidateWizardAccountSelection')
        && str_contains((string) ($accountPage['onConfirm'] ?? ''), 'WizardPrepareAccount')
        && str_contains((string) ($accountPage['onUndo'] ?? ''), 'WizardAccountSelectionUndo')
        && str_contains((string) ($accountPage['nextPage'] ?? ''), 'GetWizardProviderPage'),
    'Calendar account page must prepare the account and route to the selected provider.'
);

$providerPages = [];
foreach ($pages as $page) {
    $providerPages[(string) ($page['name'] ?? '')] = $page;
}

assertDiscoveryWizard(
    str_contains((string) ($providerPages['apple']['validate'] ?? ''), 'ValidateWizardAppleConfiguration')
        && str_contains((string) ($providerPages['caldav']['validate'] ?? ''), 'ValidateWizardCalDAVConfiguration')
        && str_contains((string) ($providerPages['ics']['validate'] ?? ''), 'ValidateWizardICalendarConfiguration'),
    'Password and iCalendar providers must validate and test their account configuration.'
);
assertDiscoveryWizard(
    str_contains((string) ($providerPages['google']['validate'] ?? ''), 'ValidateWizardOAuthConnection')
        && str_contains((string) ($providerPages['microsoft']['validate'] ?? ''), 'ValidateWizardOAuthConnection'),
    'OAuth provider pages must verify the completed authorization.'
);

foreach (['google', 'microsoft'] as $providerPageName) {
    $oauthButton = null;
    foreach ($providerPages[$providerPageName]['items'] ?? [] as $item) {
        if (($item['type'] ?? '') === 'Button') {
            $oauthButton = $item;
            break;
        }
    }
    assertDiscoveryWizard(
        is_array($oauthButton)
            && ($oauthButton['link'] ?? false) === true
            && str_contains((string) ($oauthButton['onClick'] ?? ''), 'BeginWizardOAuth'),
        'OAuth provider pages must open the native authorization URL.'
    );
}

$summaryPage = $providerPages['summary'];
assertDiscoveryWizard(
    str_contains((string) ($summaryPage['validate'] ?? ''), 'ValidateWizardConfirmation')
        && str_contains((string) ($summaryPage['onConfirm'] ?? ''), 'WizardFinishAccount'),
    'Final page must activate and finalize the verified calendar account.'
);

foreach ([
    'public function GetWizardProviderPage(): string',
    'public function ValidateWizardAppleConfiguration(',
    'public function ValidateWizardCalDAVConfiguration(',
    'public function ValidateWizardICalendarConfiguration(',
    'public function BeginWizardOAuth(): string',
    'public function ValidateWizardOAuthConnection(): string',
    'private function testWizardConnection(int $accountID): string',
    'IPSKALACC_TestConnection($accountID)',
    'IPSKALACC_ConnectGoogle($accountID)',
    'IPSKALACC_ConnectMicrosoft($accountID)',
    'IPSKALACC_GetAccountStatus($accountID)',
    'IPS_SetProperty($accountID, \'Active\', true)',
    'cleanupPreparedWizardAccount()'
] as $requiredSource) {
    assertDiscoveryWizard(
        str_contains($moduleSource, $requiredSource),
        'Missing provider setup behavior: ' . $requiredSource
    );
}

$germanTranslations = $locale['translations']['de'] ?? [];
foreach ([
    'Configure Apple iCloud',
    'App-specific password',
    'Configure CalDAV',
    'Connect Google Calendar',
    'Connect Microsoft 365',
    'Configure ICS / Webcal',
    'Set up later',
    'The calendar account connection was verified successfully.',
    'The calendar account connection has not been verified yet.'
] as $translationKey) {
    assertDiscoveryWizard(
        is_array($germanTranslations) && array_key_exists($translationKey, $germanTranslations),
        'Missing German OpenCalendar Discovery translation: ' . $translationKey
    );
}

echo "OpenCalendar Discovery provider setup tests passed.\n";
