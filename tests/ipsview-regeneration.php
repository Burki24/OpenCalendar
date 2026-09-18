<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender Ansicht/module.php';

/** Exercises the real Calendar View/helper workflow with only runtime storage and rendering replaced. */
final class RegenerationCalendarView extends CalendarView
{
    public bool $enabled = true;
    public bool $runtimeReady = true;
    public bool $initializationSucceeds = true;
    public int $initializations = 0;
    public int $renders = 0;
    public string $html = '<html>regenerated calendar</html>';
    public array $variables = [
        'Calendar'       => ['id' => 42001, 'value' => 'previous calendar'],
        'Companion'      => ['id' => 42002, 'value' => 'previous companion'],
        'Unregistered'   => ['id' => 42003, 'value' => 'unrelated output']
    ];
    public array $writes = [];
    public array $debug = [];

    public function Initialize(): bool
    {
        ++$this->initializations;
        $this->runtimeReady = $this->initializationSucceeds;

        return $this->initializationSucceeds;
    }

    public function GetIPSViewHTML(): string
    {
        ++$this->renders;

        return $this->html;
    }

    protected function ReadPropertyBoolean(string $Name): bool
    {
        return $Name === 'EnableIPSView' && $this->enabled;
    }

    protected function ReadAttributeBoolean(string $Name): bool
    {
        return $Name === 'RuntimeReady' && $this->runtimeReady;
    }

    protected function ReadAttributeString(string $Name): string
    {
        return $Name === 'IPSViewHTMLVariableRegistry'
            ? '{"Calendar":"Calendar","Companion":"Companion","Missing":"Missing output"}'
            : '[]';
    }

    protected function VariableExists(string $ident, ?int $parentID = null): bool
    {
        return isset($this->variables[$ident]);
    }

    protected function SetValue(string $Ident, mixed $Value): bool
    {
        if (!isset($this->variables[$Ident])) {
            throw new RuntimeException('Regeneration must not create missing variables.');
        }
        $this->writes[] = ['id' => $this->variables[$Ident]['id'], 'value' => $Value];
        $this->variables[$Ident]['value'] = $Value;

        return true;
    }

    protected function SendDebug(string $Message, string $Data, int $Format): bool
    {
        $this->debug[] = [$Message, $Data];

        return true;
    }
}

function assertIPSViewRegeneration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$view = new RegenerationCalendarView(9401);
$initialVariables = $view->variables;
assertIPSViewRegeneration($view->RegenerateIPSViewHTML(), 'The public regeneration API must report successful helper regeneration.');
assertIPSViewRegeneration(
    $view->writes === [
        ['id' => 42001, 'value' => $view->html],
        ['id' => 42002, 'value' => $view->html]
    ] && $view->renders === 1 && $view->initializations === 0,
    'Regeneration must render once and update every registered existing output through the shared helper.'
);
assertIPSViewRegeneration(
    array_column($view->variables, 'id') === array_column($initialVariables, 'id')
        && $view->variables['Unregistered'] === $initialVariables['Unregistered']
        && !isset($view->variables['Missing']),
    'Regeneration must preserve variable IDs, leave unrelated output untouched and never recreate missing variables.'
);

$view = new RegenerationCalendarView(9402);
$view->runtimeReady = false;
assertIPSViewRegeneration(
    $view->RegenerateIPSViewHTML() && $view->initializations === 1 && count($view->writes) === 2,
    'The Calendar View preparation hook must initialize unavailable runtime state before helper regeneration.'
);

$view = new RegenerationCalendarView(9403);
$view->runtimeReady = false;
$view->initializationSucceeds = false;
$initialVariables = $view->variables;
assertIPSViewRegeneration(
    !$view->RegenerateIPSViewHTML() && $view->initializations === 1 && $view->renders === 0
        && $view->writes === [] && $view->variables === $initialVariables,
    'Failed runtime preparation must neither render nor overwrite retained HTML.'
);

$view = new RegenerationCalendarView(9404);
$view->enabled = false;
$view->runtimeReady = false;
$initialVariables = $view->variables;
assertIPSViewRegeneration(
    !$view->RegenerateIPSViewHTML() && $view->initializations === 0 && $view->renders === 0
        && $view->writes === [] && $view->variables === $initialVariables,
    'Disabled IPSView output must preserve existing variables without starting runtime initialization.'
);

foreach (['', " \n\t"] as $emptyHtml) {
    $view = new RegenerationCalendarView(9405);
    $view->html = $emptyHtml;
    $initialVariables = $view->variables;
    assertIPSViewRegeneration(
        !$view->RegenerateIPSViewHTML() && $view->renders === 1
            && $view->writes === [] && $view->variables === $initialVariables,
        'Empty or whitespace-only rendering must never replace retained HTML.'
    );
}

$view = new RegenerationCalendarView(9406);
$view->RequestAction('IPSViewHTMLRegenerateVariables', '');
assertIPSViewRegeneration(
    $view->renders === 1 && count($view->writes) === 2 && $view->debug === [],
    'The shared configuration action must reach the same helper-backed regeneration workflow.'
);

fwrite(STDOUT, "Calendar View shared IPSView regeneration tests passed.\n");
