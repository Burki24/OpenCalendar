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
        'calendars',
        'summary'
    ],
    'OpenCalendar Discovery must provide provider setup and calendar selection wizard pages.'
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
foreach (['apple', 'caldav', 'google', 'microsoft', 'ics'] as $providerPageName) {
    assertDiscoveryWizard(
        ($providerPages[$providerPageName]['nextPage'] ?? '') === 'calendars',
        'Every provider setup page must continue to calendar selection.'
    );
}

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

$calendarPage = $providerPages['calendars'];
$calendarList = null;
foreach ($calendarPage['items'] ?? [] as $item) {
    if (($item['type'] ?? '') === 'List' && ($item['name'] ?? '') === 'WizardCalendars') {
        $calendarList = $item;
        break;
    }
}
assertDiscoveryWizard(is_array($calendarList), 'Calendar selection page must expose the discovered calendar list.');
$calendarColumns = [];
foreach ($calendarList['columns'] ?? [] as $column) {
    $calendarColumns[(string) ($column['name'] ?? '')] = $column;
}
assertDiscoveryWizard(
    isset(
        $calendarColumns['selected'],
        $calendarColumns['name'],
        $calendarColumns['access'],
        $calendarColumns['calendarId']
    )
        && (($calendarColumns['selected']['edit']['type'] ?? '') === 'CheckBox')
        && (($calendarColumns['calendarId']['visible'] ?? true) === false),
    'Calendar selection list must provide a checkbox and preserve the hidden calendar identity.'
);
assertDiscoveryWizard(
    str_contains((string) ($calendarList['onEdit'] ?? ''), 'WizardCalendarSelectionChanged')
        && str_contains((string) ($calendarList['onEdit'] ?? ''), '$WizardCalendars[\'calendarId\']')
        && str_contains((string) ($calendarList['onEdit'] ?? ''), '$WizardCalendars[\'selected\']'),
    'Calendar selection checkboxes must persist every edited row immediately.'
);
assertDiscoveryWizard(
    str_contains((string) ($calendarPage['validate'] ?? ''), 'ValidateWizardCalendarSelection')
        && !str_contains((string) ($calendarPage['validate'] ?? ''), 'json_encode($WizardCalendars')
        && str_contains((string) ($calendarPage['onConfirm'] ?? ''), 'WizardSelectCalendars')
        && str_contains((string) ($calendarPage['onUndo'] ?? ''), 'WizardCalendarSelectionUndo')
        && ($calendarPage['nextPage'] ?? '') === 'summary',
    'Calendar selection page must validate the persisted selection and continue to the summary.'
);

$summaryPage = $providerPages['summary'];
assertDiscoveryWizard(
    str_contains((string) ($summaryPage['validate'] ?? ''), 'ValidateWizardConfirmation')
        && str_contains((string) ($summaryPage['onConfirm'] ?? ''), 'WizardFinishAccount'),
    'Final page must create selected calendar instances and finalize the verified calendar account.'
);
$summaryCaptions = array_map(
    static fn (array $item): string => (string) ($item['caption'] ?? ''),
    $summaryPage['items'] ?? []
);
assertDiscoveryWizard(
    in_array(
        'With OK, OpenCalendar creates the selected calendars as calendar instances. Existing matching calendar instances are reused.',
        $summaryCaptions,
        true
    )
        && in_array(
            'New calendar instances are named with the provider prefix, for example O365 - Family or Apple - Private.',
            $summaryCaptions,
            true
        ),
    'Final page must explain calendar instance creation, reuse and provider-prefixed names.'
);

foreach ([
    "RegisterAttributeString('SelectedCalendarIDs', '[]')",
    "RegisterAttributeString('SelectedCalendarInstanceIDs', '[]')",
    "private const CALENDAR_MODULE_ID = '{227B63E4-4223-316B-76E9-FD3849689562}';",
    "'apple'     => 'Apple'",
    "'caldav'    => 'CalDAV'",
    "'google'    => 'Google'",
    "'microsoft' => 'O365'",
    "'ics'       => 'ICS'",
    'public function GetWizardProviderPage(): string',
    'public function ValidateWizardAppleConfiguration(',
    'public function ValidateWizardCalDAVConfiguration(',
    'public function ValidateWizardICalendarConfiguration(',
    'public function BeginWizardOAuth(): string',
    'public function ValidateWizardOAuthConnection(): string',
    'public function ValidateWizardCalendarSelection(): string',
    'private function defaultWizardSelectedCalendarIDs(array $calendars): array',
    'private function updateWizardCalendarSelection(mixed $value): void',
    'private function confirmWizardCalendarSelection(): void',
    'private function prepareSelectedCalendarInstances(',
    'private function existingCalendarInstancesForAccount(int $accountID): array',
    'private function createCalendarInstance(int $accountID, string $provider, array $calendar): int',
    'private function testWizardConnection(int $accountID): string',
    'private function discoverWizardCalendars(int $accountID): array',
    'private function wizardCalendarListValues(array $calendars): array',
    'IPSKALACC_TestConnection($accountID)',
    'IPSKALACC_Synchronize($accountID)',
    'IPSKALACC_GetCalendars($accountID)',
    '$wasActive = (bool) IPS_GetProperty($accountID, \'Active\');',
    'finally {',
    'IPS_SetProperty($accountID, \'Active\', false);',
    'IPSKALACC_ConnectGoogle($accountID)',
    'IPSKALACC_ConnectMicrosoft($accountID)',
    'IPSKALACC_GetAccountStatus($accountID)',
    'IPS_SetProperty($accountID, \'Active\', true)',
    'IPS_CreateInstance(self::CALENDAR_MODULE_ID)',
    'IPS_ConnectInstance($calendarInstanceID, $accountID)',
    'IPS_SetProperty($calendarInstanceID, \'CalendarID\', $calendarID)',
    "'ProviderCalendarID'",
    "'CalendarURL'",
    "'CalendarColor'",
    "'CanWrite'",
    "'UpdateSchedule'",
    "'UpdateInterval'",
    "WriteAttributeString('SelectedCalendarIDs'",
    "'SelectedCalendarInstanceIDs'",
    'array_reverse($createdCalendarInstanceIDs)',
    'cleanupPreparedWizardAccount()'
] as $requiredSource) {
    assertDiscoveryWizard(
        str_contains($moduleSource, $requiredSource),
        'Missing provider/calendar setup behavior: ' . $requiredSource
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
    'Choose calendars',
    'Available calendars',
    'Please select at least one calendar.',
    'With OK, OpenCalendar creates the selected calendars as calendar instances. Existing matching calendar instances are reused.',
    'New calendar instances are named with the provider prefix, for example O365 - Family or Apple - Private.',
    'The selected calendar instances could not be created.',
    'The calendar instance could not be connected to the calendar account.',
    'The calendar instance "%s" could not be created.',
    'The calendar account connection was verified successfully.',
    'The calendar account connection has not been verified yet.'
] as $translationKey) {
    assertDiscoveryWizard(
        is_array($germanTranslations) && array_key_exists($translationKey, $germanTranslations),
        'Missing German OpenCalendar Discovery translation: ' . $translationKey
    );
}

echo "OpenCalendar Discovery provider, calendar selection and instance creation tests passed.\n";
