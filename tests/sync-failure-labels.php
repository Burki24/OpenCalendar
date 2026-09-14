<?php

declare(strict_types=1);

const KR_READY = 10103;
const IS_ACTIVE = 102;
const IS_INACTIVE = 104;

function IPS_GetKernelRunlevel(): int
{
    return KR_READY;
}
function IPS_InstanceExists(int $id): bool
{
    return $id === 1;
}
function IPS_GetInstance(int $id): array
{
    return ['ModuleInfo' => ['ModuleID' => '{227B63E4-4223-316B-76E9-FD3849689562}']];
}
function IPS_GetName(int $id): string
{
    return 'Test calendar';
}
function IPS_GetProperty(int $id, string $name): mixed
{
    return $name === 'Active';
}
function IPSKAL_GetCalendarStatus(int $id): mixed
{
    return $GLOBALS['calendarStatus'];
}

class IPSModuleStrict
{
    public array $attributes = ['RuntimeReady' => true, 'InitializationRefreshAttempts' => 0];
    public array $timers = [];
    public array $debug = [];
    public function SendDebug(string $name, mixed $data, int $format): void
    {
        $this->debug[] = $name;
    }
    protected function ReadAttributeBoolean(string $name): bool
    {
        return (bool) ($this->attributes[$name] ?? false);
    }
    protected function ReadAttributeInteger(string $name): int
    {
        return (int) ($this->attributes[$name] ?? 0);
    }
    protected function WriteAttributeInteger(string $name, int $value): void
    {
        $this->attributes[$name] = $value;
    }
    protected function ReadAttributeString(string $name): string
    {
        return '[]';
    }
    protected function ReadPropertyString(string $name): string
    {
        return $name === 'Calendars' ? '[{"InstanceID":1,"Enabled":true}]' : '';
    }
    protected function ReadPropertyBoolean(string $name): bool
    {
        return false;
    }
    protected function ReadPropertyInteger(string $name): int
    {
        return 1;
    }
    protected function SetTimerInterval(string $name, int $interval): void
    {
        $this->timers[$name] = $interval;
    }
    protected function Translate(string $value): string
    {
        return $value;
    }
}
require_once dirname(__DIR__) . '/Kalender Ansicht/module.php';

function startupExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function IPSKAL_Synchronize(int $id): bool
{
    if ($GLOBALS['syncThrows']) throw new RuntimeException('Provider unavailable.');
    return $GLOBALS['syncSuccess'];
}
$view = new CalendarView();
$calendarStatus = json_encode(['runtimeReady' => true]);
$syncSuccess = false;
$syncThrows = false;
$label = new ReflectionMethod(CalendarView::class, 'calendarSynchronizationLabel');
foreach (['google' => 'Google Calendar', 'microsoft' => 'Microsoft 365', 'apple' => 'Apple Calendar',
    'caldav'       => 'CalDAV', 'ics' => 'ICS/WebCal', 'other' => 'Unknown provider'] as $key => $expected) {
    startupExpect($label->invoke($view, ['provider' => $key, 'name' => 'Team']) === $expected . ' (Team)', 'Failure label must identify provider and calendar.');
    startupExpect($label->invoke($view, ['provider' => $key, 'name' => '']) === $expected, 'Unnamed calendars must still identify provider.');
}
$sync = new ReflectionMethod(CalendarView::class, 'synchronizeSelectedCalendars');
startupExpect(count($sync->invoke($view)) === 1, 'Rejected synchronization must return a calendar label.');
startupExpect($view->SynchronizeCalendars() === false, 'Public synchronization API must keep bool failure.');
$syncSuccess = true;
startupExpect($sync->invoke($view) === [], 'Successful synchronization must return no failures.');
startupExpect($view->SynchronizeCalendars() === true, 'Public synchronization API must keep bool success.');
$syncThrows = true;
startupExpect(count($sync->invoke($view)) === 1, 'Provider exceptions must retain a failure label.');
fwrite(STDOUT, "Synchronization label tests passed.\n");
