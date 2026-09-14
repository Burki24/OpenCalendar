<?php

declare(strict_types=1);

const KR_READY = 10103;

function IPS_GetKernelRunlevel(): int
{
    return KR_READY;
}

/** Minimal runtime boundary; migration and task logic below execute production methods. */
class IPSModuleStrict
{
    public function Migrate(string $JSONData): string
    {
        return '';
    }
}

require_once dirname(__DIR__) . '/Kalender Ansicht/module.php';
require_once dirname(__DIR__) . '/Kalender/module.php';

function assertUpgrade21(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Models restored attributes only, without pretending to boot a Symcon kernel. */
final class Upgrade21Calendar extends Calendar
{
    public array $attributes = [];
    public array $diagnostics = [];

    protected function SendDebug(string $Message, mixed $Data, int $Format): void
    {
        $this->diagnostics[] = $Data;
    }

    protected function ReadAttributeBoolean(string $Name): bool
    {
        return $Name === 'RuntimeReady';
    }

    protected function ReadAttributeString(string $Name): string
    {
        return (string) ($this->attributes[$Name] ?? '[]');
    }

    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        $this->attributes[$Name] = $Value;
        return true;
    }

    protected function HasActiveParent(): bool
    {
        return false;
    }
}

/** Models already persisted IPSView authentication attributes. */
final class Upgrade21View extends CalendarView
{
    public array $attributes = [];

    protected function ReadAttributeInteger(string $Name): int
    {
        return (int) ($this->attributes[$Name] ?? 0);
    }

    protected function WriteAttributeInteger(string $Name, int $Value): bool
    {
        $this->attributes[$Name] = $Value;
        return true;
    }
}

$selection = json_encode([
    ['InstanceID' => 12345, 'Enabled' => true],
    ['InstanceID' => 23456, 'Enabled' => false]
], JSON_THROW_ON_ERROR);
$tokens = ['IPSViewToken1' => 11, 'IPSViewToken2' => 22, 'IPSViewToken3' => 33, 'IPSViewToken4' => 44];
$snapshot = [
    'configuration' => [
        'Calendars'             => $selection, 'IPSViewStyleSource' => 2,
        'IPSViewStyleFontScale' => 150, 'ShowMonthOverflowDays' => false
    ],
    'attributes' => array_merge($tokens, [
        'CalendarSelectionBackup'     => $selection,
        'IPSViewHTMLVariableRegistry' => '{"IPSViewCalendar":"IPSView calendar"}'
    ]),
    'variables' => ['IPSViewCalendar' => ['ID' => 34567]]
];
$view = new Upgrade21View();
$json = json_encode($snapshot, JSON_THROW_ON_ERROR);
assertUpgrade21($view->Migrate($json) === '', 'Current 2.1 settings must need no destructive rewrite.');

// Force a legacy conversion too, to exercise the real rewrite rather than only its no-op branch.
$legacy = $snapshot;
$legacy['configuration']['ShowDayOfYear'] = false;
$migrated = json_decode($view->Migrate(json_encode($legacy, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
assertUpgrade21($migrated['attributes'] === $snapshot['attributes'], 'Migration must preserve selection backup, HTML variable identity and authentication tokens.');
assertUpgrade21($migrated['variables'] === $snapshot['variables'], 'Migration must leave existing variable identities untouched.');
foreach ($snapshot['configuration'] as $key => $value) {
    assertUpgrade21($migrated['configuration'][$key] === $value, 'Migration changed an existing 2.1 setting: ' . $key);
}
assertUpgrade21($view->Migrate(json_encode($migrated, JSON_THROW_ON_ERROR)) === '', 'Migration must be idempotent.');
$view->attributes = $tokens;
(new ReflectionMethod(CalendarView::class, 'ensureIPSViewToken'))->invoke($view);
assertUpgrade21($view->attributes === $tokens, 'Reinitializing an existing view must not rotate its IPSView tokens.');

$pending = [
    'series:original' => [
        'uid'            => 'detached', 'resourceUrl' => '/calendar/detached.ics', 'eventReference' => 'detached',
        'startTimestamp' => 1893456000, 'endTimestamp' => 1893542400
    ]
];
$calendar = new Upgrade21Calendar();
$calendar->attributes = [
    'PendingTaskSeries'   => json_encode($pending, JSON_THROW_ON_ERROR),
    'AnniversaryMetadata' => '[{"keys":["uid:birthday"],"type":"birthday","date":"2000-01-01","summary":"Birthday"}]',
    'BirthdayMetadata'    => '[{"keys":["uid:legacy"],"date":"1990-01-01","summary":"Birthday"}]'
];
$before = $calendar->attributes;
$events = [];
$pendingMethod = new ReflectionMethod(Calendar::class, 'pendingTaskSeries');
$open = $pendingMethod->invokeArgs($calendar, [&$events]);
assertUpgrade21(isset($open['series:original']), 'A restored out-of-window task must still block a second detached task while its provider is unavailable.');
assertUpgrade21($calendar->attributes === $before, 'Restart recovery must retain pending identities and anniversary metadata.');
assertUpgrade21(str_contains(json_encode($calendar->diagnostics, JSON_THROW_ON_ERROR), 'No active calendar account'), 'The out-of-window recovery must exercise provider unavailability, not a missing runtime stub.');

$cases = [
    '[OC:TODO]'        => [false, false], '[OC:DONE]' => [true, false],
    '[OC:TODO:FOLLOW]' => [false, true], '[OC:DONE:FOLLOW]' => [true, true],
    '☐'                => [false, false], '☑' => [true, false],
    '☐↻'               => [false, true], '☑↻' => [true, true]
];
foreach ($cases as $marker => [$completed, $follow]) {
    $event = IPSKalender\CalendarTaskEvent::enrich([
        'summary' => $marker . ' Existing task', 'status' => 'CONFIRMED', 'transparency' => 'TRANSPARENT'
    ]);
    assertUpgrade21($event['task'] && $event['taskCompleted'] === $completed && $event['taskFollowPlanned'] === $follow, 'Persisted 2.1 marker lost task semantics: ' . $marker);
    assertUpgrade21($event['status'] === 'CONFIRMED' && $event['transparency'] === 'TRANSPARENT', 'Task completion must not alter the independent 9.1 event state or availability.');
}

$events = [[
    'uid'     => 'detached', 'resourceUrl' => '/calendar/detached.ics', 'eventReference' => 'detached',
    'summary' => '[OC:TODO] Existing task'
]];
$open = $pendingMethod->invokeArgs($calendar, [&$events]);
assertUpgrade21(isset($open['series:original']) && $events[0]['taskRolledForward'], 'A restored visible task must recover its detached-series indicator.');
$events[0]['summary'] = '[OC:DONE] Existing task';
$open = $pendingMethod->invokeArgs($calendar, [&$events]);
assertUpgrade21($open === [], 'Confirmed completion must release the restored series association.');
assertUpgrade21($calendar->attributes['AnniversaryMetadata'] === $before['AnniversaryMetadata'], 'Task recovery must not change anniversary data.');

fwrite(STDOUT, "OpenCalendar 2.1 -> 3.0 migration and restored-task behavior passed (runtime boundary simulated).\n");
