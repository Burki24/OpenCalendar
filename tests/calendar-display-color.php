<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender/module.php';

class DisplayColorTestCalendar extends Calendar
{
    protected function LoadConfigurationForm(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../Kalender/form.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function getTime(): int
    {
        return time();
    }
}

function displayColorExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function calendarStatusColor(Calendar $calendar): string
{
    $status = json_decode($calendar->GetCalendarStatus(), true, 512, JSON_THROW_ON_ERROR);
    return (string) ($status['calendarColor'] ?? '');
}

IPS\Kernel::reset();
$calendarId = IPS\ObjectManager::registerObject(1);
IPS\InstanceManager::createInstance($calendarId, [
    'Class'      => DisplayColorTestCalendar::class,
    'ModuleID'   => '{227B63E4-4223-316B-76E9-FD3849689562}',
    'ModuleName' => 'Calendar',
    'ModuleType' => 3
]);
$calendar = IPS\InstanceManager::getInstanceInterface($calendarId);
$calendar->SetProperty('Active', false);
$calendar->SetProperty('CalendarID', 'read-only-feed');
$calendar->ApplyChanges();

$form = json_decode($calendar->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$fields = array_column($form['elements'], null, 'name');
displayColorExpect(
    ($fields['UseDisplayColor']['type'] ?? '') === 'CheckBox'
        && ($fields['UseDisplayColor']['visible'] ?? false)
        && ($fields['DisplayColor']['type'] ?? '') === 'SelectColor'
        && ($fields['DisplayColor']['visible'] ?? false),
    'An external read-only calendar must expose a selectable display color.'
);
displayColorExpect(calendarStatusColor($calendar) === '', 'Existing calendars must retain an empty source color by default.');

$calendar->SetProperty('UseDisplayColor', true);
$calendar->SetProperty('DisplayColor', 0x234567);
$calendar->ApplyChanges();
displayColorExpect(
    calendarStatusColor($calendar) === '#234567',
    'The chosen display color must be exposed to the calendar view even when an ICS feed has no source color.'
);

$writeBoolean = new ReflectionMethod(IPSModuleStrict::class, 'WriteAttributeBoolean');
$writeBoolean->invoke($calendar, 'CalendarMetadataAvailable', true);
$writeString = new ReflectionMethod(IPSModuleStrict::class, 'WriteAttributeString');
$writeString->invoke($calendar, 'DetectedCalendarColor', '#ABCDEF');
displayColorExpect(
    calendarStatusColor($calendar) === '#234567',
    'The explicit display color must take precedence over a later provider color.'
);

$calendar->SetProperty('UseDisplayColor', false);
$calendar->ApplyChanges();
displayColorExpect(
    calendarStatusColor($calendar) === '#ABCDEF',
    'Disabling the override must restore the provider color without changing it.'
);

$calendar->SetProperty('LocalCalendar', true);
$calendar->SetProperty('CalendarColor', '#445566');
$calendar->SetProperty('UseDisplayColor', true);
$calendar->ApplyChanges();
$writeBoolean->invoke($calendar, 'CalendarMetadataAvailable', false);
displayColorExpect(
    calendarStatusColor($calendar) === '#445566',
    'Local calendars must continue to use their existing calendar color.'
);
$form = json_decode($calendar->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$fields = array_column($form['elements'], null, 'name');
displayColorExpect(
    !($fields['UseDisplayColor']['visible'] ?? true)
        && !($fields['DisplayColor']['visible'] ?? true),
    'Local calendars must continue to use their existing color control.'
);

echo 'Calendar display color tests passed.' . PHP_EOL;
