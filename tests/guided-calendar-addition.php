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
    200 => ['module' => CALENDAR_MODULE, 'name' => 'Existing', 'properties' => ['CalendarID' => 'old'], 'connection' => 100],
    300 => ['module' => VIEW_MODULE, 'name' => 'Main view', 'properties' => [], 'connection' => 0]
];

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

function checkGuidedAddition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$discovery = new OpenCalendarDiscovery();
$discovery->Create();
$form = json_decode($discovery->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$actions = array_column($form['actions'], null, 'name');
checkGuidedAddition(isset($actions['SetupWizard'], $actions['AddCalendarWizard']), 'Both setup flows must be available.');

$pages = array_column($actions['AddCalendarWizard']['popup']['pages'], null, 'name');
checkGuidedAddition(array_keys($pages) === ['existingAccount', 'calendars', 'view', 'summary', 'result'], 'The extension flow must skip provider credentials.');
$accountField = $pages['existingAccount']['items'][1];
checkGuidedAddition(array_column($accountField['options'], 'value') === [0, 100], 'Only active supported accounts may be offered.');

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

unset($instances[100], $instances[101]);
$formWithoutAccount = json_decode($discovery->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$actionsWithoutAccount = array_column($formWithoutAccount['actions'], null, 'name');
checkGuidedAddition($actionsWithoutAccount['AddCalendarWizard']['enabled'] === false, 'The shortcut must be disabled when no active account exists.');

fwrite(STDOUT, "Guided calendar addition tests passed.\n");
