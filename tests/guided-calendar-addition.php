<?php

declare(strict_types=1);

use IPSKalender\CalendarProviderType;

require_once __DIR__ . '/../libs/CalendarProviderType.php';

const ACCOUNT_MODULE = '{966D6119-7FF3-5CA5-06C3-536FBF8100C4}';
const CALENDAR_MODULE = '{227B63E4-4223-316B-76E9-FD3849689562}';
const VIEW_MODULE = '{1B19AB6B-9052-EA85-F158-86A13FE6F5BA}';

/** @var array<int, array{module: string, name: string, properties: array<string, mixed>, connection: int}> */
$instances = [
    100 => ['module' => ACCOUNT_MODULE, 'name' => 'Work', 'properties' => ['Provider' => CalendarProviderType::GOOGLE, 'Active' => true], 'connection' => 0],
    101 => ['module' => ACCOUNT_MODULE, 'name' => 'Inactive', 'properties' => ['Provider' => CalendarProviderType::GOOGLE, 'Active' => false], 'connection' => 0],
    102 => ['module' => ACCOUNT_MODULE, 'name' => 'Feeds', 'properties' => [
        'Provider'       => CalendarProviderType::ICS,
        'Active'         => true,
        'ServerURL'      => 'https://caldav.icloud.com',
        'ICalendarFeeds' => '[{"Active":true,"Name":"Existing feed","URL":"https://example.invalid/old.ics","AuthenticationMode":1,"Username":"","Password":"","Color":"","TranslationProfile":0,"UpdateSchedule":7,"UpdateInterval":15}]',
        'RequestTimeout' => 5,
        'VerifyTLS'      => true,
        'UpdateSchedule' => 7,
        'UpdateInterval' => 15
    ], 'connection' => 0],
    200 => ['module' => CALENDAR_MODULE, 'name' => 'Existing', 'properties' => ['CalendarID' => 'old'], 'connection' => 100],
    300 => ['module' => VIEW_MODULE, 'name' => 'Main view', 'properties' => ['Calendars' => '[{"InstanceID":200,"Enabled":true}]'], 'connection' => 0]
];
$failNextViewApply = false;

function IPS_GetInstanceListByModuleID(string $moduleID): array
{
    global $instances;
    return array_keys(array_filter($instances, static fn (array $instance): bool => $instance['module'] === $moduleID));
}

function IPS_InstanceExists(int $instanceID): bool
{
    global $instances;
    return isset($instances[$instanceID]);
}

function IPS_GetProperty(int $instanceID, string $name): mixed
{
    global $instances;
    return $instances[$instanceID]['properties'][$name] ?? null;
}

function IPS_GetName(int $instanceID): string
{
    global $instances;
    return $instances[$instanceID]['name'];
}

function IPS_GetInstance(int $instanceID): array
{
    global $instances;
    return ['ConnectionID' => $instances[$instanceID]['connection']];
}

function IPS_GetParent(int $instanceID): int
{
    return 10;
}

function IPS_SetProperty(int $instanceID, string $name, mixed $value): void
{
    global $instances;
    $instances[$instanceID]['properties'][$name] = $value;
}

function IPS_ApplyChanges(int $instanceID): void
{
    global $failNextViewApply;
    if ($instanceID === 300 && $failNextViewApply) {
        $failNextViewApply = false;
        throw new RuntimeException('Simulated view update failure.');
    }
}

function IPS_CreateInstance(string $moduleID): int
{
    global $instances;
    $instanceID = max(array_keys($instances)) + 1;
    $instances[$instanceID] = ['module' => $moduleID, 'name' => '', 'properties' => [], 'connection' => 0];
    return $instanceID;
}

function IPS_SetName(int $instanceID, string $name): void
{
    global $instances;
    $instances[$instanceID]['name'] = $name;
}

function IPS_SetParent(int $instanceID, int $parentID): void
{
}

function IPS_ConnectInstance(int $instanceID, int $parentID): bool
{
    global $instances;
    $instances[$instanceID]['connection'] = $parentID;
    return true;
}

function IPS_DeleteInstance(int $instanceID): void
{
    global $instances;
    unset($instances[$instanceID]);
}

function IPSKALACC_TestConnection(int $accountID): string
{
    global $instances;
    if (($instances[$accountID]['properties']['Provider'] ?? null) === CalendarProviderType::LOCAL) {
        $name = trim((string) ($instances[$accountID]['properties']['LocalCalendarName'] ?? ''));
        $color = $instances[$accountID]['properties']['LocalCalendarColor'] ?? null;
        return json_encode(['success' => $name !== '' && is_int($color) && $color >= 0 && $color <= 0xFFFFFF], JSON_THROW_ON_ERROR);
    }
    return json_encode(['success' => true], JSON_THROW_ON_ERROR);
}

function IPSKALACC_Synchronize(int $accountID): bool
{
    return true;
}

function IPSKALACC_GetCalendars(int $accountID): string
{
    global $instances;
    if (($instances[$accountID]['properties']['Provider'] ?? null) === CalendarProviderType::LOCAL) {
        return json_encode([[
            'id'            => 'local:' . $accountID,
            'name'          => $instances[$accountID]['properties']['LocalCalendarName'],
            'color'         => sprintf('#%06X', $instances[$accountID]['properties']['LocalCalendarColor']),
            'localCalendar' => true,
            'capabilities'  => ['create' => true, 'update' => true, 'delete' => true]
        ]], JSON_THROW_ON_ERROR);
    }
    return json_encode([
        ['id' => 'old', 'name' => 'Existing', 'primary' => true, 'capabilities' => ['create' => true]],
        ['id' => 'new', 'name' => 'New', 'primary' => false, 'capabilities' => ['create' => true]]
    ], JSON_THROW_ON_ERROR);
}

function IPSKAL_Synchronize(int $calendarID): bool
{
    return true;
}

function IPSKALVIEW_Initialize(int $viewID): bool
{
    return true;
}

class IPSModuleStrict
{
    /** @var array<string, array<string, mixed>> */
    public array $updatedFields = [];
    /** @var array<string, int|string> */
    private array $attributes = [];
    /** @var array<string, string> */
    private array $buffers = [];

    public function Create(): void
    {
    }

    protected function RegisterAttributeInteger(string $name, int $value): void
    {
        $this->attributes[$name] = $value;
    }

    protected function RegisterAttributeString(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }

    protected function ReadAttributeString(string $name): string
    {
        return (string) ($this->attributes[$name] ?? '');
    }

    protected function WriteAttributeString(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }

    protected function WriteAttributeInteger(string $name, int $value): void
    {
        $this->attributes[$name] = $value;
    }

    protected function SetBuffer(string $name, string $value): void
    {
        $this->buffers[$name] = $value;
    }

    protected function GetBuffer(string $name): string
    {
        return $this->buffers[$name] ?? '';
    }

    protected function Translate(string $caption): string
    {
        return $caption;
    }

    protected function UpdateFormField(string $name, string $key, mixed $value): void
    {
        $this->updatedFields[$name][$key] = $value;
    }
}

require_once __DIR__ . '/../Kalender Einrichtung/module.php';

class TestOpenCalendarDiscovery extends OpenCalendarDiscovery
{
    public int $InstanceID = 400;

    /** @param array<string, mixed> $feed */
    protected function inspectWizardICSFeed(int $accountID, array $feed): array
    {
        if ($accountID !== 102 || ($feed['url'] ?? '') !== 'https://example.invalid/new.ics?key=private') {
            throw new RuntimeException('Unexpected feed preview.');
        }
        return ['id' => hash('sha256', 'ics|' . $feed['url']), 'color' => ''];
    }
}

function checkGuidedAddition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$discovery = new TestOpenCalendarDiscovery();
$discovery->Create();
$form = json_decode($discovery->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$actions = array_column($form['actions'], null, 'name');
checkGuidedAddition(isset($actions['SetupWizard'], $actions['AddCalendarWizard']), 'Both setup flows must be available.');
checkGuidedAddition($actions['SetupWizard']['caption'] === 'Start setup', 'The main setup button must have a short caption.');
checkGuidedAddition($actions['AddCalendarWizard']['caption'] === 'Add calendars', 'The calendar button must have a short caption.');
checkGuidedAddition($actions['AddICSSubscriptionWizard']['caption'] === 'Add ICS/Webcal', 'The ICS button must have a short caption.');

$pages = array_column($actions['AddCalendarWizard']['popup']['pages'], null, 'name');
checkGuidedAddition(array_keys($pages) === ['existingAccount', 'calendars', 'view', 'summary', 'result'], 'The extension flow must skip provider credentials.');
$accountField = $pages['existingAccount']['items'][1];
checkGuidedAddition(array_column($accountField['options'], 'value') === [0, 100, 102], 'Only active supported accounts may be offered.');

$icsPages = array_column($actions['AddICSSubscriptionWizard']['popup']['pages'], null, 'name');
checkGuidedAddition(array_keys($icsPages) === ['icsAccount', 'icsFeed', 'view', 'summary', 'result'], 'The ICS flow must continue through the calendar view and result.');
checkGuidedAddition(array_column($icsPages['icsAccount']['items'][1]['options'], 'value') === [0, 102], 'Only active ICS accounts may be offered for a feed subscription.');
checkGuidedAddition(
    str_contains($icsPages['view']['validate'], '$ICSViewMode')
        && str_contains($icsPages['view']['onConfirm'], '$ICSExistingViewID')
        && str_contains($icsPages['summary']['onConfirm'], 'WizardFinishICSSubscription'),
    'The ICS flow must pass its own view and feed fields to finalization.'
);

$calendarList = $pages['calendars']['items'][1];
checkGuidedAddition($calendarList['name'] === 'ExtensionCalendars', 'The extension list must have a distinct form name.');
checkGuidedAddition(str_contains($calendarList['onEdit'], '$ExtensionCalendars'), 'Calendar edits must refer to the extension list.');
checkGuidedAddition($pages['view']['items'][0]['value'] === 'existing', 'An existing view should be the default when available.');
checkGuidedAddition($pages['result']['nextPage'] === -1, 'The extension result must close the popup.');

checkGuidedAddition($discovery->ValidateWizardExtensionAccount(101) !== '', 'Inactive accounts must be rejected.');
checkGuidedAddition($discovery->ValidateWizardExtensionAccount(100) === '', 'An active account must pass connection and discovery checks.');
$values = json_decode($discovery->updatedFields['ExtensionCalendars']['values'], true, 512, JSON_THROW_ON_ERROR);
checkGuidedAddition($values[0]['setupState'] === 'Already configured' && $values[0]['selected'] === false, 'Existing calendars must be identifiable and not selected automatically.');
checkGuidedAddition($values[1]['setupState'] === 'New calendar' && $values[1]['selected'] === true, 'A single new calendar should be selected automatically.');
checkGuidedAddition($discovery->ValidateWizardCalendarSelection() === '', 'The selected new calendar must pass the shared selection validation.');

$originalFeeds = $instances[102]['properties']['ICalendarFeeds'];
checkGuidedAddition($discovery->ValidateWizardICSSubscriptionAccount(102) === '', 'An active ICS account must be selectable.');
checkGuidedAddition(
    $discovery->ValidateWizardICSSubscriptionFeed('https://example.invalid/old.ics', 'Duplicate', 1, '', '') !== '',
    'The guided import must reject an already configured URL before any network request.'
);
checkGuidedAddition(
    $discovery->ValidateWizardICSSubscriptionFeed('webcal://example.invalid/old.ics', 'Duplicate', 1, '', '') !== '',
    'Webcal and HTTPS addresses of the same feed must be recognized as duplicates.'
);
checkGuidedAddition($instances[102]['properties']['ICalendarFeeds'] === $originalFeeds, 'Validation must not change existing feeds.');

$newURL = 'https://example.invalid/new.ics?key=private';
$newCalendarID = hash('sha256', 'ics|' . $newURL);
checkGuidedAddition(
    $discovery->ValidateWizardICSSubscriptionFeed($newURL, 'New feed', 1, '', '') === '',
    'A valid new feed must pass preview without changing the account.'
);
checkGuidedAddition($instances[102]['properties']['ICalendarFeeds'] === $originalFeeds, 'The successful preview must not save the feed early.');
$discovery->RequestAction('WizardSelectCalendarView', json_encode([
    'mode'           => 'existing',
    'existingViewID' => 300,
    'viewName'       => ''
], JSON_THROW_ON_ERROR));
$newFeed = json_encode([
    'url'                => $newURL,
    'name'               => 'New feed',
    'authenticationMode' => 1,
    'username'           => '',
    'password'           => ''
], JSON_THROW_ON_ERROR);
$changedFeed = json_encode([
    'url'                => $newURL,
    'name'               => 'Changed after preview',
    'authenticationMode' => 1,
    'username'           => '',
    'password'           => ''
], JSON_THROW_ON_ERROR);
try {
    $discovery->RequestAction('WizardFinishICSSubscription', $changedFeed);
    throw new RuntimeException('Changed feed details were accepted without another preview.');
} catch (InvalidArgumentException $exception) {
    checkGuidedAddition(str_contains($exception->getMessage(), 'changed'), 'The changed preview must be rejected.');
}
checkGuidedAddition($instances[102]['properties']['ICalendarFeeds'] === $originalFeeds, 'Rejected changes must not save a feed.');

$instances[102]['properties']['Active'] = false;
try {
    $discovery->RequestAction('WizardFinishICSSubscription', $newFeed);
    throw new RuntimeException('An inactive account accepted the new feed.');
} catch (InvalidArgumentException $exception) {
    checkGuidedAddition(str_contains($exception->getMessage(), 'active'), 'The account must still be active at finalization.');
}
checkGuidedAddition($instances[102]['properties']['ICalendarFeeds'] === $originalFeeds, 'An inactive account must not be changed.');
$instances[102]['properties']['Active'] = true;

$failNextViewApply = true;
try {
    $discovery->RequestAction('WizardFinishICSSubscription', $newFeed);
    throw new RuntimeException('The simulated view failure did not stop setup.');
} catch (RuntimeException $exception) {
    checkGuidedAddition(str_contains($exception->getMessage(), 'calendar view'), 'A view failure must be reported.');
}
$feedsAfterFailure = json_decode($instances[102]['properties']['ICalendarFeeds'], true, 512, JSON_THROW_ON_ERROR);
checkGuidedAddition(count($feedsAfterFailure) === 2, 'A confirmed feed must remain available after a view failure.');
checkGuidedAddition(count(IPS_GetInstanceListByModuleID(CALENDAR_MODULE)) === 1, 'A failed view update must roll back the new calendar instance.');

$discovery->RequestAction('WizardFinishICSSubscription', $newFeed);
$resultField = $discovery->updatedFields['ICSResultStatus']['caption'] ?? '';
checkGuidedAddition(str_contains($resultField, 'successfully'), 'The ICS wizard must receive its own completion result.');
$feeds = json_decode($instances[102]['properties']['ICalendarFeeds'], true, 512, JSON_THROW_ON_ERROR);
checkGuidedAddition(count($feeds) === 2 && $feeds[0]['URL'] === 'https://example.invalid/old.ics', 'Adding a feed must preserve existing subscriptions.');
checkGuidedAddition($feeds[1]['URL'] === $newURL, 'The selected feed must be appended.');
$calendarInstances = IPS_GetInstanceListByModuleID(CALENDAR_MODULE);
$newInstanceID = max($calendarInstances);
checkGuidedAddition($instances[$newInstanceID]['properties']['CalendarID'] === $newCalendarID, 'The new calendar must retain the feed identity.');
checkGuidedAddition($instances[$newInstanceID]['properties']['CalendarURL'] === '', 'The secret feed URL must remain in the account only.');
$viewCalendars = json_decode($instances[300]['properties']['Calendars'], true, 512, JSON_THROW_ON_ERROR);
checkGuidedAddition(array_column($viewCalendars, 'InstanceID') === [200, $newInstanceID], 'The chosen view must retain old calendars and include the new one.');
$discovery->RequestAction('WizardFinishICSSubscription', $newFeed);
$feedsAgain = json_decode($instances[102]['properties']['ICalendarFeeds'], true, 512, JSON_THROW_ON_ERROR);
checkGuidedAddition(count($feedsAgain) === 2, 'Repeating the finish action must not create a duplicate feed.');

unset($instances[102]);
$formWithoutICS = json_decode($discovery->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$actionsWithoutICS = array_column($formWithoutICS['actions'], null, 'name');
$icsFallback = $actionsWithoutICS['AddICSSubscriptionWizard'];
checkGuidedAddition(($icsFallback['enabled'] ?? true) === true, 'The ICS shortcut must be available without an active ICS account.');
$fallbackPages = array_column($icsFallback['popup']['pages'], null, 'name');
checkGuidedAddition(isset($fallbackPages['welcome'], $fallbackPages['provider'], $fallbackPages['account']), 'The ICS shortcut must offer new-account setup.');
$providerField = array_column($fallbackPages['provider']['items'], null, 'name')['Provider'];
checkGuidedAddition($providerField['value'] === 'ics', 'The fallback must preselect ICS/Webcal.');
checkGuidedAddition(array_column($providerField['options'], 'value') === ['ics'], 'The fallback must remain an ICS/Webcal flow.');

unset($instances[100], $instances[101]);
$formWithoutAccount = json_decode($discovery->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$actionsWithoutAccount = array_column($formWithoutAccount['actions'], null, 'name');
checkGuidedAddition($actionsWithoutAccount['AddCalendarWizard']['enabled'] === false, 'The shortcut must be disabled when no active account exists.');
checkGuidedAddition(($actionsWithoutAccount['AddICSSubscriptionWizard']['enabled'] ?? true) === true, 'The ICS shortcut must remain available without any account.');

$localDiscovery = new TestOpenCalendarDiscovery();
$localDiscovery->Create();
$localForm = json_decode($localDiscovery->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$localPages = array_column(array_column($localForm['actions'], null, 'name')['SetupWizard']['popup']['pages'], null, 'name');
checkGuidedAddition(in_array('local', array_column($localPages['provider']['items'][0]['options'], 'value'), true), 'The main wizard must offer Symcon-local calendars.');
checkGuidedAddition(isset($localPages['local']) && str_contains($localPages['local']['validate'], 'ValidateWizardLocalConfiguration'), 'The local provider must have a configuration page.');
$localDiscovery->RequestAction('WizardProvider', json_encode(['provider' => 'local', 'createConfigurator' => true], JSON_THROW_ON_ERROR));
checkGuidedAddition($localDiscovery->GetWizardProviderPage() === 'local', 'Local accounts must route to the local provider page.');
$localDiscovery->RequestAction('WizardPrepareAccount', json_encode(['mode' => 'new', 'existingAccountID' => 0, 'accountName' => 'Private account'], JSON_THROW_ON_ERROR));
$localAccountID = max(IPS_GetInstanceListByModuleID(ACCOUNT_MODULE));
checkGuidedAddition($instances[$localAccountID]['properties']['Provider'] === CalendarProviderType::LOCAL && !$instances[$localAccountID]['properties']['Active'], 'A local account must start inactive without provider credentials.');
checkGuidedAddition($localDiscovery->ValidateWizardLocalConfiguration('', 0x123456) !== '', 'An empty local calendar name must be rejected.');
checkGuidedAddition($localDiscovery->ValidateWizardLocalConfiguration('Private', -1) !== '', 'An invalid local calendar color must be rejected.');
checkGuidedAddition($localDiscovery->ValidateWizardLocalConfiguration('Private', 0x123456) === '', 'A valid local calendar must pass without a provider connection.');
checkGuidedAddition($instances[$localAccountID]['properties']['LocalCalendarName'] === 'Private' && $instances[$localAccountID]['properties']['LocalCalendarColor'] === 0x123456, 'The local account must retain the selected name and color.');
$localValues = json_decode($localDiscovery->updatedFields['WizardCalendars']['values'], true, 512, JSON_THROW_ON_ERROR);
checkGuidedAddition(count($localValues) === 1 && $localValues[0]['selected'] && $localValues[0]['calendarId'] === 'local:' . $localAccountID, 'The local account must offer its sole calendar for selection.');
$localDiscovery->RequestAction('WizardSelectCalendars', '');
$localDiscovery->RequestAction('WizardSelectCalendarView', json_encode(['mode' => 'existing', 'existingViewID' => 300, 'viewName' => ''], JSON_THROW_ON_ERROR));
$localDiscovery->RequestAction('WizardFinishAccount', '');
$localInstances = array_values(array_filter(IPS_GetInstanceListByModuleID(CALENDAR_MODULE), static fn (int $id): bool => $instances[$id]['connection'] === $localAccountID));
checkGuidedAddition(count($localInstances) === 1, 'The local calendar instance must be created exactly once.');
$localCalendar = $instances[$localInstances[0]]['properties'];
checkGuidedAddition(($localCalendar['LocalCalendar'] ?? false) === true && $localCalendar['CalendarID'] === 'local:' . $localAccountID, 'The calendar must use the local storage contract and stable account identity.');
checkGuidedAddition($localCalendar['ProviderCalendarID'] === '' && $localCalendar['UpdateSchedule'] === 11 && $localCalendar['CanWrite'] === true, 'Local calendars must be writable and have no provider ID or network schedule.');
checkGuidedAddition($localCalendar['CalendarColor'] === '#123456', 'The local calendar color must reach the calendar instance.');
checkGuidedAddition($instances[$localAccountID]['properties']['Active'] === true, 'A completed local account must be active.');
$localConfigurators = array_values(array_filter(IPS_GetInstanceListByModuleID('{4A013D9D-3611-9900-5815-A8EC8A91287D}'), static fn (int $id): bool => $instances[$id]['connection'] === $localAccountID));
checkGuidedAddition(count($localConfigurators) === 1, 'The optional local configurator must be connected to the new account.');
$localViewCalendars = json_decode($instances[300]['properties']['Calendars'], true, 512, JSON_THROW_ON_ERROR);
checkGuidedAddition(in_array($localInstances[0], array_column($localViewCalendars, 'InstanceID'), true), 'The existing view must include the local calendar without dropping previous assignments.');
$localDiscovery->RequestAction('WizardFinishAccount', '');
checkGuidedAddition(count(array_filter(IPS_GetInstanceListByModuleID(CALENDAR_MODULE), static fn (int $id): bool => $instances[$id]['connection'] === $localAccountID)) === 1, 'Repeating the finish action must not duplicate the local calendar.');

$reusedLocal = new TestOpenCalendarDiscovery();
$reusedLocal->Create();
$reusedLocal->RequestAction('WizardProvider', 'local');
$reusedLocal->RequestAction('WizardPrepareAccount', json_encode(['mode' => 'existing', 'existingAccountID' => $localAccountID, 'accountName' => ''], JSON_THROW_ON_ERROR));
checkGuidedAddition($reusedLocal->ValidateWizardLocalConfiguration('Ignored', 0xABCDEF) === '', 'An existing local account must be usable by the main wizard.');
checkGuidedAddition($instances[$localAccountID]['properties']['LocalCalendarName'] === 'Private' && $instances[$localAccountID]['properties']['LocalCalendarColor'] === 0x123456, 'The wizard must preserve the settings of an existing local account.');
checkGuidedAddition(($reusedLocal->updatedFields['WizardLocalCalendarName']['visible'] ?? true) === false, 'Existing local accounts must not offer an ineffective name field.');

$failedLocal = new TestOpenCalendarDiscovery();
$failedLocal->Create();
$failedLocal->RequestAction('WizardProvider', 'local');
$failedLocal->RequestAction('WizardPrepareAccount', json_encode(['mode' => 'new', 'existingAccountID' => 0, 'accountName' => 'Rollback account'], JSON_THROW_ON_ERROR));
$failedAccountID = max(IPS_GetInstanceListByModuleID(ACCOUNT_MODULE));
checkGuidedAddition($failedLocal->ValidateWizardLocalConfiguration('Rollback', 0x654321) === '', 'A second local account must be configurable.');
$failedLocal->RequestAction('WizardSelectCalendars', '');
$failedLocal->RequestAction('WizardSelectCalendarView', json_encode(['mode' => 'existing', 'existingViewID' => 300, 'viewName' => ''], JSON_THROW_ON_ERROR));
$failNextViewApply = true;
try {
    $failedLocal->RequestAction('WizardFinishAccount', '');
    throw new RuntimeException('The simulated local view failure did not stop setup.');
} catch (RuntimeException $exception) {
    checkGuidedAddition(str_contains($exception->getMessage(), 'calendar view'), 'A local view failure must be reported.');
}
checkGuidedAddition($instances[$failedAccountID]['properties']['Active'] === false, 'A failed local setup must deactivate its new account.');
checkGuidedAddition(count(array_filter(IPS_GetInstanceListByModuleID(CALENDAR_MODULE), static fn (int $id): bool => $instances[$id]['connection'] === $failedAccountID)) === 0, 'A failed local setup must remove the new calendar instance.');
$failedLocal->RequestAction('WizardFinishAccount', '');
checkGuidedAddition(count(array_filter(IPS_GetInstanceListByModuleID(CALENDAR_MODULE), static fn (int $id): bool => $instances[$id]['connection'] === $failedAccountID)) === 1, 'Retrying local setup must create exactly one calendar instance.');

fwrite(STDOUT, "Guided calendar addition tests passed.\n");
