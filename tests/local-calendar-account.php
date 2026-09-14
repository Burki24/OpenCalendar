<?php

declare(strict_types=1);

class IPSModuleStrict
{
    /** @var array<string, int|string|bool> */
    private array $properties = [];

    public function __construct(public int $InstanceID)
    {
    }

    /** @param int|string|bool $value */
    public function SetTestProperty(string $name, int|string|bool $value): void
    {
        $this->properties[$name] = $value;
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
        return '';
    }

    protected function Translate(string $text): string
    {
        return $text;
    }
}

function IPS_GetInstance(int $instanceID): array
{
    return ['ConnectionID' => $instanceID === 100 ? 42 : 0];
}

function IPS_GetInstanceListByModuleID(string $moduleID): array
{
    return [];
}

/** @var array<string, mixed> */
$localAccountConfiguration = [];
/** @var list<array{instanceID: int, name: string, value: mixed}> */
$localAccountPropertyWrites = [];

function IPS_GetConfiguration(int $instanceID): string
{
    global $localAccountConfiguration;

    return json_encode($localAccountConfiguration, JSON_THROW_ON_ERROR);
}

function IPS_SetProperty(int $instanceID, string $name, mixed $value): bool
{
    global $localAccountPropertyWrites;

    $localAccountPropertyWrites[] = ['instanceID' => $instanceID, 'name' => $name, 'value' => $value];

    return true;
}

function IPS_GetProperty(int $instanceID, string $name): mixed
{
    return null;
}

require_once __DIR__ . '/../Kalender Konto/module.php';
require_once __DIR__ . '/../Kalender Konfigurator/module.php';
require_once __DIR__ . '/../Kalender/module.php';

function localAccountExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$form = json_decode((string) file_get_contents(__DIR__ . '/../Kalender Konto/form.json'), true, 512, JSON_THROW_ON_ERROR);
$providerOptions = $form['elements'][1]['options'] ?? [];
localAccountExpect(
    in_array(['caption' => 'Symcon - Calendar', 'value' => 5], $providerOptions, true),
    'Calendar Account must offer the local provider.'
);
localAccountExpect(
    array_column($form['elements'], 'name') !== []
        && in_array('LocalCalendarName', array_column($form['elements'], 'name'), true)
        && in_array('LocalCalendarColor', array_column($form['elements'], 'name'), true),
    'The local provider must have its own creation form fields.'
);
$localColorField = array_values(array_filter(
    $form['elements'],
    static fn (array $element): bool => ($element['name'] ?? '') === 'LocalCalendarColor'
))[0] ?? [];
localAccountExpect(
    ($localColorField['type'] ?? '') === 'SelectColor'
        && ($localColorField['allowTransparent'] ?? true) === false,
    'The local calendar color must use Symcon\'s mandatory color picker.'
);

$account = new CalendarAccount(42);
$account->SetTestProperty('Provider', 5);
$account->SetTestProperty('LocalCalendarName', 'Home');
$account->SetTestProperty('LocalCalendarColor', 0xFF0000);
$definitionMethod = new ReflectionMethod(CalendarAccount::class, 'localCalendarDefinition');
$definition = $definitionMethod->invoke($account);
localAccountExpect(
    $definition['id'] === 'local:42'
        && $definition['name'] === 'Home'
        && $definition['color'] === '#FF0000'
        && ($definition['localCalendar'] ?? false) === true
        && ($definition['capabilities']['createRecurrence'] ?? false) === true,
    'A local account must expose one writable local calendar with recurring-event support.'
);
$listedCalendars = json_decode($account->GetCalendars(), true, 512, JSON_THROW_ON_ERROR);
localAccountExpect(
    $listedCalendars === [$definition] && $account->ScheduledSynchronize(),
    'Local account discovery must be available without a provider request or schedule.'
);
$validateConfiguration = new ReflectionMethod(CalendarAccount::class, 'validateConfiguration');
localAccountExpect(
    $validateConfiguration->invoke($account) === '',
    'A complete local account configuration must be accepted without external credentials.'
);
$account->SetTestProperty('LocalCalendarColor', -1);
localAccountExpect(
    $validateConfiguration->invoke($account) === 'The local calendar color is invalid.',
    'Invalid local colors must be rejected before the configurator can create a calendar.'
);
$account->SetTestProperty('LocalCalendarColor', 0x4FB286);
$localAccountConfiguration = ['LocalCalendarColor' => '#4fb286'];
$migrateLegacyColor = new ReflectionMethod(CalendarAccount::class, 'migrateLegacyLocalCalendarColor');
$migrateLegacyColor->invoke($account);
localAccountExpect(
    $localAccountPropertyWrites === [[
        'instanceID' => 42,
        'name'       => 'LocalCalendarColor',
        'value'      => 0x4FB286
    ]],
    'Existing hexadecimal local calendar colors must migrate to SelectColor RGB values.'
);

$configurator = new CalendarConfigurator(100);
$buildValues = new ReflectionMethod(CalendarConfigurator::class, 'buildConfiguratorValues');
$values = $buildValues->invoke($configurator, [$definition]);
$configuration = $values[0]['create']['configuration'] ?? [];
localAccountExpect(
    ($configuration['LocalCalendar'] ?? false) === true
        && ($configuration['CalendarID'] ?? '') === 'local:42'
        && ($configuration['ProviderCalendarID'] ?? 'unexpected') === ''
        && ($configuration['UpdateSchedule'] ?? -1) === 11,
    'The configurator must create a local calendar child with no external provider identity or schedule.'
);
$localCalendar = new Calendar(100);
$localCalendar->SetTestProperty('LocalCalendar', true);
$localCalendar->SetTestProperty('ProviderCalendarID', '');
$localCalendar->SetTestProperty('CalendarURL', '');
$validateLocalCalendar = new ReflectionMethod(Calendar::class, 'validateConfiguration');
localAccountExpect(
    $validateLocalCalendar->invoke($localCalendar) === '',
    'A local calendar must not require a gateway connection after configurator creation.'
);
localAccountExpect(
    !is_file(__DIR__ . '/../Kalender Einrichtung/module.json'),
    'The separate local-calendar setup module must not remain.'
);

fwrite(STDOUT, "Local calendar account tests passed.\n");
