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
    return json_encode(['success' => true], JSON_THROW_ON_ERROR);
}

function IPSKALACC_Synchronize(int $accountID): bool
{
    return true;
}

function IPSKALACC_GetCalendars(int $accountID): string
{
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

unset($instances[100], $instances[101], $instances[102]);
$formWithoutAccount = json_decode($discovery->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$actionsWithoutAccount = array_column($formWithoutAccount['actions'], null, 'name');
checkGuidedAddition($actionsWithoutAccount['AddCalendarWizard']['enabled'] === false, 'The shortcut must be disabled when no active account exists.');
checkGuidedAddition($actionsWithoutAccount['AddICSSubscriptionWizard']['enabled'] === false, 'The ICS shortcut must be disabled without an active ICS account.');

fwrite(STDOUT, "Guided calendar addition tests passed.\n");
