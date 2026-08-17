<?php

declare(strict_types=1);

const IS_ACTIVE = 102;
const IS_INACTIVE = 104;
const IPS_KERNELSTARTED = 10001;
const KR_READY = 10103;

function IPS_GetKernelRunlevel(): int
{
    return KR_READY;
}

/** @var array<int,array{ConnectionID:int,InstanceStatus:int}> */
$configuratorTestInstances = [
    100 => ['ConnectionID' => 10, 'InstanceStatus' => IS_ACTIVE],
    10  => ['ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE],
    20  => ['ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE]
];

function IPS_GetInstance(int $instanceId): array
{
    global $configuratorTestInstances;

    return $configuratorTestInstances[$instanceId]
        ?? ['ConnectionID' => 0, 'InstanceStatus' => 0];
}

function IPS_InstanceExists(int $instanceId): bool
{
    global $configuratorTestInstances;

    return isset($configuratorTestInstances[$instanceId]);
}

class IPSModuleStrict
{
    public int $InstanceID;

    /** @var array<string,int|string> */
    private array $attributes = [];

    private int $status = 0;

    /** @var array<string,int> */
    private array $timers = [];

    public function __construct(int $instanceId)
    {
        $this->InstanceID = $instanceId;
    }

    public function Create(): void
    {
    }

    public function ApplyChanges(): void
    {
    }

    /** @return int|string */
    public function TestAttribute(string $name): int|string
    {
        return $this->attributes[$name] ?? '';
    }

    public function TestStatus(): int
    {
        return $this->status;
    }

    public function TestTimer(string $name): int
    {
        return $this->timers[$name] ?? -1;
    }

    /** @param list<array<string,mixed>> $calendars */
    public function SeedCalendarCache(int $parentId, array $calendars): void
    {
        $this->attributes['CachedCalendarParentID'] = $parentId;
        $this->attributes['CachedCalendars'] = json_encode($calendars, JSON_THROW_ON_ERROR);
    }

    protected function RegisterAttributeInteger(string $name, int $defaultValue): bool
    {
        $this->attributes[$name] ??= $defaultValue;

        return true;
    }

    protected function RegisterAttributeString(string $name, string $defaultValue): bool
    {
        $this->attributes[$name] ??= $defaultValue;

        return true;
    }

    protected function RegisterMessage(int $senderId, int $message): bool
    {
        return true;
    }

    protected function RegisterTimer(string $name, int $milliseconds, string $script): bool
    {
        $this->timers[$name] = $milliseconds;

        return true;
    }

    protected function SetTimerInterval(string $name, int $milliseconds): bool
    {
        $this->timers[$name] = $milliseconds;

        return true;
    }

    protected function ReadAttributeInteger(string $name): int
    {
        return (int) ($this->attributes[$name] ?? 0);
    }

    protected function ReadAttributeString(string $name): string
    {
        return (string) ($this->attributes[$name] ?? '');
    }

    protected function WriteAttributeInteger(string $name, int $value): bool
    {
        $this->attributes[$name] = $value;

        return true;
    }

    protected function WriteAttributeString(string $name, string $value): bool
    {
        $this->attributes[$name] = $value;

        return true;
    }

    protected function SetStatus(int $status): bool
    {
        $this->status = $status;

        return true;
    }

    protected function Translate(string $text): string
    {
        return $text;
    }
}

require_once __DIR__ . '/../Kalender Konfigurator/module.php';

function assertConfiguratorSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . PHP_EOL
            . 'Expected: ' . var_export($expected, true) . PHP_EOL
            . 'Actual:   ' . var_export($actual, true));
    }
}

/** @return list<array<string,mixed>> */
function readConfiguratorCache(CalendarConfigurator $configurator): array
{
    $method = new ReflectionMethod(CalendarConfigurator::class, 'readCachedCalendars');

    return $method->invoke($configurator);
}

$configurator = new CalendarConfigurator(100);
$configurator->Create();
$configurator->SeedCalendarCache(10, [['id' => 'account-a']]);
$configurator->ApplyChanges();

assertConfiguratorSame(
    IS_INACTIVE,
    $configurator->TestStatus(),
    'ApplyChanges must keep the configurator inactive until its parent is initialized.'
);
assertConfiguratorSame(
    5_000,
    $configurator->TestTimer('InitializationTimer'),
    'Parent validation must be deferred so module reload order cannot leave the configurator in an error state.'
);
$configurator->Initialize();
assertConfiguratorSame(
    IS_ACTIVE,
    $configurator->TestStatus(),
    'Deferred initialization must activate the configurator once its parent account is ready.'
);

assertConfiguratorSame(
    [['id' => 'account-a']],
    readConfiguratorCache($configurator),
    'A calendar cache must remain available while the connected account is unchanged.'
);

$configuratorTestInstances[100]['ConnectionID'] = 20;
assertConfiguratorSame(
    [],
    readConfiguratorCache($configurator),
    'A calendar cache from the previous account must not be exposed before ApplyChanges.'
);

$configurator->ApplyChanges();
assertConfiguratorSame(
    20,
    $configurator->TestAttribute('CachedCalendarParentID'),
    'The cache owner must follow the newly connected calendar account.'
);
assertConfiguratorSame(
    '[]',
    $configurator->TestAttribute('CachedCalendars'),
    'Changing the connected calendar account must invalidate discovered calendars.'
);

$configurator->SeedCalendarCache(20, [['id' => 'account-b']]);
assertConfiguratorSame(
    [['id' => 'account-b']],
    readConfiguratorCache($configurator),
    'A cache belonging to the current calendar account must remain available.'
);

$configuratorTestInstances[100]['ConnectionID'] = 30;
$configurator->ApplyChanges();
assertConfiguratorSame(
    0,
    $configurator->TestAttribute('CachedCalendarParentID'),
    'Removing or selecting an unavailable account must clear the cache owner.'
);
assertConfiguratorSame(
    '[]',
    $configurator->TestAttribute('CachedCalendars'),
    'Removing or selecting an unavailable account must clear discovered calendars.'
);

fwrite(STDOUT, "Calendar configurator cache tests passed.\n");
