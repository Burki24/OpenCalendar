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
$view = new CalendarView();
$calendarStatus = json_encode(['runtimeReady' => false]);
startupExpect(!$view->RefreshInitialization(), 'Pending calendars must trigger another startup check.');
startupExpect($view->timers['InitializationRefreshTimer'] === 1000, 'Retry must be scheduled after one second.');
startupExpect($view->attributes['InitializationRefreshAttempts'] === 1, 'Retry attempts must be counted.');
for ($attempt = 2; $attempt <= 29; ++$attempt) {
    startupExpect(!$view->RefreshInitialization(), 'Pending startup must retry below its bound.');
}
startupExpect($view->RefreshInitialization(), 'The bounded startup cycle must finish at its limit.');
startupExpect($view->timers['InitializationRefreshTimer'] === 0, 'Startup retries must stop at the limit.');
startupExpect(in_array('Initialization', $view->debug, true), 'Exhausted retries must emit a diagnostic.');
$view->attributes['InitializationRefreshAttempts'] = 0;
$calendarStatus = false;
startupExpect(!$view->RefreshInitialization(), 'Invalid bridge result must be treated as pending, not crash.');
$calendarStatus = json_encode(['runtimeReady' => true]);
startupExpect($view->RefreshInitialization(), 'Ready calendars must finish the startup check.');
startupExpect($view->timers['InitializationRefreshTimer'] === 0, 'Ready calendars must stop retries.');
$view->attributes['RuntimeReady'] = false;
startupExpect(!$view->RefreshInitialization(), 'A view not ready must not publish.');
startupExpect($view->timers['InitializationRefreshTimer'] === 0, 'Inactive view must not reschedule.');
$bootstrap = new ReflectionMethod(CalendarView::class, 'buildTileBootstrapState');
$state = $bootstrap->invoke($view);
startupExpect($state['events'] === [] && $state['eventRange'] === null, 'Initial tile state must not embed events or claim a loaded range.');
$view->attributes['RuntimeReady'] = true;
$calendarStatus = json_encode(['runtimeReady' => true, 'canWriteStatus' => true, 'canWriteTransparency' => true]);
$state = $bootstrap->invoke($view);
startupExpect($state['events'] === [] && $state['eventRange'] === null, 'Ready bootstrap must defer all event loading.');
startupExpect(count($state['calendars']) === 1 && $state['calendars'][0]['canWriteStatus']
    && $state['calendars'][0]['canWriteTransparency'], 'Bootstrap must retain 9.1 calendar capabilities.');
fwrite(STDOUT, "Calendar startup recovery tests passed.\n");
