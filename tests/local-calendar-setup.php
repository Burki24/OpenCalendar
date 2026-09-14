<?php

declare(strict_types=1);

/** Minimal Symcon boundary fake; setup creation and view merging execute the real module. */
class IPSModuleStrict
{
    public int $InstanceID = 10;
    public array $updates = [];

    public function Translate(string $text): string
    {
        return $text;
    }

    public function UpdateFormField(string $name, string $field, mixed $value): void
    {
        $this->updates[$name][$field] = $value;
    }
}

$instances = [];
$nextID = 100;
$failApply = false;
function IPS_CreateInstance(string $moduleID): int
{
    global $instances, $nextID;
    $id = $nextID++;
    $instances[$id] = ['module' => $moduleID, 'properties' => [], 'name' => '', 'parent' => 0];
    return $id;
}
function IPS_SetName(int $id, string $name): void
{
    $GLOBALS['instances'][$id]['name'] = $name;
}
function IPS_GetParent(int $id): int
{
    return $id === 10 ? 5 : (int) ($GLOBALS['instances'][$id]['parent'] ?? 0);
}
function IPS_SetParent(int $id, int $parent): void
{
    $GLOBALS['instances'][$id]['parent'] = $parent;
}
function IPS_SetProperty(int $id, string $property, mixed $value): void
{
    $GLOBALS['instances'][$id]['properties'][$property] = $value;
}
function IPS_GetProperty(int $id, string $property): mixed
{
    return $GLOBALS['instances'][$id]['properties'][$property] ?? '';
}
function IPS_ApplyChanges(int $id): void
{
    if ($GLOBALS['failApply']) {
        throw new RuntimeException('Storage unavailable');
    }
}
function IPS_InstanceExists(int $id): bool
{
    return isset($GLOBALS['instances'][$id]);
}
function IPS_DeleteInstance(int $id): void
{
    unset($GLOBALS['instances'][$id]);
}
function IPS_GetInstanceListByModuleID(string $moduleID): array
{
    return array_keys(array_filter($GLOBALS['instances'], static fn (array $instance): bool => $instance['module'] === $moduleID));
}

require_once __DIR__ . '/../Kalender Einrichtung/module.php';

function checkLocalSetup(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$module = new OpenCalendarDiscovery();
$payload = ['name' => 'Home', 'color' => 0x4FB286, 'mode' => 'new', 'viewName' => 'Overview'];
$module->RequestAction('CreateLocalCalendar', json_encode($payload, JSON_THROW_ON_ERROR));
checkLocalSetup(count($instances) === 2, 'Create exactly one calendar and one view, no account or configurator.');
$calendar = $instances[100];
checkLocalSetup($calendar['properties']['LocalCalendar'] === true && $calendar['properties']['Active'] === true, 'Local calendar must be active.');
checkLocalSetup($calendar['parent'] === 5 && $instances[101]['parent'] === 5, 'Objects must be created beside the setup instance.');
foreach (['CalendarID', 'ProviderCalendarID', 'CalendarURL'] as $property) {
    checkLocalSetup(!isset($calendar['properties'][$property]), 'Local setup must not set external provider identity.');
}
checkLocalSetup(json_decode($instances[101]['properties']['Calendars'], true) === [['InstanceID' => 100, 'Enabled' => true]], 'New view must select the local calendar.');
checkLocalSetup($module->updates['CreateLocalCalendarButton']['enabled'] === false, 'Completion must prevent an accidental second click.');

// Existing disabled selections must stay untouched while the new local calendar is appended.
$instances[101]['properties']['Calendars'] = '[{"InstanceID":100,"Enabled":false}]';
$payload['mode'] = 'existing';
$payload['existingViewID'] = 101;
$module->RequestAction('CreateLocalCalendar', json_encode($payload, JSON_THROW_ON_ERROR));
checkLocalSetup(count($instances) === 3, 'Existing view must be reused without an account.');
checkLocalSetup(json_decode($instances[101]['properties']['Calendars'], true) === [['InstanceID' => 100, 'Enabled' => false], ['InstanceID' => 102, 'Enabled' => true]], 'Preserve existing view selection and append local calendar.');

$before = $instances;
foreach (['invalid json', json_encode(['name' => '', 'mode' => 'new', 'viewName' => 'Test']), json_encode(['name' => 'Invalid view', 'mode' => 'existing', 'existingViewID' => 100])] as $invalid) {
    try {
        $module->RequestAction('CreateLocalCalendar', $invalid);
        throw new RuntimeException('Invalid configuration accepted');
    } catch (InvalidArgumentException) {
        checkLocalSetup($instances === $before, 'Validation must precede all mutations.');
    }
}
$failApply = true;
try {
    $module->RequestAction('CreateLocalCalendar', json_encode($payload, JSON_THROW_ON_ERROR));
    throw new LogicException('Failed creation accepted');
} catch (RuntimeException $exception) {
    checkLocalSetup($exception->getMessage() === 'Storage unavailable', 'Retain the creation error.');
    checkLocalSetup($instances === $before, 'Failed empty calendar creation must be rolled back.');
}

require_once __DIR__ . '/../Kalender Ansicht/module.php';
$label = new ReflectionMethod(CalendarView::class, 'calendarSynchronizationLabel');
$view = new CalendarView();
checkLocalSetup($label->invoke($view, ['provider' => 'local', 'name' => 'Home']) === 'Local calendar (Home)', 'Local refresh errors must identify the local calendar.');
checkLocalSetup($label->invoke($view, ['provider' => 'microsoft', 'name' => 'Work']) === 'Microsoft 365 (Work)', 'External provider labels must remain unchanged.');
echo "Local calendar setup tests passed.\n";
