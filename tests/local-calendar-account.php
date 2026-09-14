<?php

declare(strict_types=1);

class IPSModuleStrict
{
    /** @var array<string, int|string|bool> */
    protected array $properties = [];
    /** @var array<string, string> */
    protected array $attributes = [];

    public function __construct(public int $InstanceID)
    {
    }

    /** @param int|string|bool $value */
    public function SetTestProperty(string $name, int|string|bool $value): void
    {
        $this->properties[$name] = $value;
    }

    public function SetTestAttribute(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }

    protected function ReadPropertyInteger(string $name): int
    {
        return (int) ($this->properties[$name] ?? 0);
    }

    protected function ReadPropertyString(string $name): string
    {
        return (string) ($this->properties[$name] ?? '');
    }

    protected function ReadPropertyBoolean(string $name): bool
    {
        return (bool) ($this->properties[$name] ?? false);
    }

    protected function ReadAttributeString(string $name): string
    {
        return $this->attributes[$name] ?? '';
    }

    protected function Translate(string $text): string
    {
        return $text;
    }
}

function IPS_GetConfiguration(int $instanceID): string
{
    return '{}';
}

function IPS_SetProperty(int $instanceID, string $name, mixed $value): bool
{
    return true;
}

function IPS_GetKernelDir(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opencalendar-local-account-' . getmypid();
}

function IPS_SemaphoreEnter(string $name, int $milliseconds): bool
{
    return true;
}

function IPS_SemaphoreLeave(string $name): bool
{
    return true;
}

require_once __DIR__ . '/../Kalender Konto/module.php';
require_once __DIR__ . '/../Kalender/module.php';

function localAccountExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$accountForm = json_decode((string) file_get_contents(__DIR__ . '/../Kalender Konto/form.json'), true, 512, JSON_THROW_ON_ERROR);
$providerOptions = $accountForm['elements'][1]['options'] ?? [];
localAccountExpect(
    in_array(['caption' => 'Symcon - Calendar', 'value' => 5], $providerOptions, true),
    'Calendar Account must offer the local provider.'
);
localAccountExpect(
    in_array('LocalCalendarName', array_column($accountForm['elements'], 'name'), true)
        && in_array('LocalCalendarColor', array_column($accountForm['elements'], 'name'), true),
    'The local provider must have its own account form fields.'
);

$account = new CalendarAccount(42);
$account->SetTestProperty('Provider', 5);
$account->SetTestProperty('LocalCalendarName', 'Home');
$account->SetTestProperty('LocalCalendarColor', 0xFF0000);
$definition = (new ReflectionMethod(CalendarAccount::class, 'localCalendarDefinition'))->invoke($account);
localAccountExpect(
    $definition['id'] === 'local:42'
        && $definition['name'] === 'Home'
        && $definition['color'] === '#FF0000'
        && ($definition['localCalendar'] ?? false) === true
        && ($definition['capabilities']['updateFollowing'] ?? false) === true,
    'A local account must expose one writable local calendar with recurrence support.'
);
localAccountExpect(
    json_decode($account->GetCalendars(), true, 512, JSON_THROW_ON_ERROR) === [$definition]
        && $account->ScheduledSynchronize(),
    'Local calendar discovery must not use a provider request or synchronization schedule.'
);
localAccountExpect(
    (new ReflectionMethod(CalendarAccount::class, 'validateConfiguration'))->invoke($account) === '',
    'A complete local account configuration must work without external credentials.'
);

$configuratorSource = (string) file_get_contents(__DIR__ . '/../Kalender Konfigurator/module.php');
localAccountExpect(
    str_contains($configuratorSource, "['LocalCalendar'] = true")
        && str_contains($configuratorSource, "['ProviderCalendarID'] = ''"),
    'The configurator must create local calendars without an external identity.'
);

$calendar = new Calendar(100);
$calendar->SetTestProperty('LocalCalendar', true);
$calendar->SetTestProperty('CalendarID', 'local:42');
$calendar->SetTestProperty('ProviderCalendarID', '');
$calendar->SetTestProperty('CalendarURL', '');
localAccountExpect(
    (new ReflectionMethod(Calendar::class, 'validateConfiguration'))->invoke($calendar) === '',
    'A local calendar connected through Calendar Account must be valid.'
);
$calendar->SetTestAttribute('LocalCalendarResources', json_encode((object) [
    'https://opencalendar.invalid/local/100/export.ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:export-test\r\nDTSTART;VALUE=DATE:20260914\r\nDTEND;VALUE=DATE:20260915\r\nSUMMARY:Export test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
], JSON_THROW_ON_ERROR));
$path = $calendar->ExportLocalCalendar('local-backup.ics');
localAccountExpect(
    is_file($path) && str_contains((string) file_get_contents($path), 'UID:export-test'),
    'A local calendar must export original events as an ICS backup.'
);
unlink($path);
rmdir(dirname($path));
rmdir(dirname(dirname($path)));
rmdir(dirname(dirname(dirname($path))));

$setupForm = (string) file_get_contents(__DIR__ . '/../Kalender Einrichtung/form.json');
$setupSource = (string) file_get_contents(__DIR__ . '/../Kalender Einrichtung/module.php');
localAccountExpect(
    !str_contains($setupForm, 'LocalCalendarSetup') && !str_contains($setupSource, 'CreateLocalCalendar'),
    'The old local-calendar setup form must be removed without removing the discovery wizard.'
);

fwrite(STDOUT, "Local calendar account, configurator and console export verified.\n");
