<?php

declare(strict_types=1);

require_once is_file(__DIR__ . '/../src/IPSViewHTMLPageHelper.php')
    ? __DIR__ . '/../src/IPSViewHTMLPageHelper.php'
    : __DIR__ . '/../libs/helper/IPSViewHTMLPageHelper.php';

define('VARIABLETYPE_STRING', 3);
define('VARIABLE_PRESENTATION_WEB_CONTENT', 'web');
$objects = [];
$attributes = [];
$nextID = 1000;
function IPS_VariableExists(int $id): bool
{
    return isset($GLOBALS['objects'][$id]);
}
function IPS_GetVariable(int $id): array
{
    return ['VariableType' => $GLOBALS['objects'][$id]['type']];
}
function IPS_GetParent(int $id): int
{
    return $GLOBALS['objects'][$id]['parent'];
}
function IPS_GetObject(int $id): array
{
    return [
        'ParentID'         => $GLOBALS['objects'][$id]['parent'],
        'ObjectIdent'      => $GLOBALS['objects'][$id]['ident'],
        'ObjectIsReadOnly' => $GLOBALS['objects'][$id]['readOnly'] ?? false
    ];
}
function SetValueString(int $id, string $value): bool
{
    if (($GLOBALS['objects'][$id]['readOnly'] ?? false) || ($GLOBALS['objects'][$id]['writeFails'] ?? false)) return false;
    $GLOBALS['objects'][$id]['value'] = $value;
    return true;
}
function IPS_DeleteVariable(int $id): void
{
    unset($GLOBALS['objects'][$id]);
}

final class MovableHTMLView
{
    use Burki24\SymconModuleHelper\IPSViewHTMLPageHelper;
    public bool $enabled = true;
    public function __construct(public int $InstanceID)
    {
        $this->RegisterIPSViewHTMLPageProperties();
    }
    public function maintain(): bool
    {
        return $this->MaintainIPSViewHTMLVariable('HTML', 'Calendar', 10, 'initial');
    }
    public function update(string $html): bool
    {
        return $this->UpdateIPSViewHTMLVariable('HTML', $html);
    }
    public function regenerate(): bool
    {
        return $this->RegenerateIPSViewHTMLPages();
    }
    public function GetIPSViewHTML(): string
    {
        return 'regenerated';
    }
    public function remove(): void
    {
        $this->HandleIPSViewHTMLPageAction('IPSViewHTMLDeleteVariables', '');
    }
    protected function RegisterPropertyBoolean(string $n, bool $v): void
    {
    }
    protected function ReadPropertyBoolean(string $n): bool
    {
        return $this->enabled;
    }
    protected function RegisterAttributeString(string $n, string $v): void
    {
        $GLOBALS['attributes'][$this->InstanceID][$n] ??= $v;
    }
    protected function ReadAttributeString(string $n): string
    {
        return $GLOBALS['attributes'][$this->InstanceID][$n] ?? '[]';
    }
    protected function WriteAttributeString(string $n, string $v): void
    {
        $GLOBALS['attributes'][$this->InstanceID][$n] = $v;
    }
    protected function GetIDForIdent(string $ident): int
    {
        foreach ($GLOBALS['objects'] as $id => $object) {
            if ($object['parent'] === $this->InstanceID && $object['ident'] === $ident) return $id;
        }
        throw new RuntimeException('Missing child');
    }
    protected function VariableExists(string $ident): bool
    {
        try {
            $this->GetIDForIdent($ident);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
    protected function MaintainVariable($ident, $caption, $type, $presentation, $position, $keep): bool
    {
        if ($this->VariableExists($ident)) return false;
        $GLOBALS['objects'][++$GLOBALS['nextID']] = ['parent'=>$this->InstanceID, 'ident'=>$ident, 'type'=>$type, 'value'=>''];
        return true;
    }
    protected function SetValue(string $ident, string $value): void
    {
        $GLOBALS['objects'][$this->GetIDForIdent($ident)]['value'] = $value;
    }
    protected function UnregisterVariable(string $ident): void
    {
        IPS_DeleteVariable($this->GetIDForIdent($ident));
    }
}
function checkMoved(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

// IPSModuleStrict owns read-only status variables and must write them through SetValue().
$objects[500] = ['parent'=>103, 'ident'=>'HTML', 'type'=>3, 'value'=>'before', 'readOnly'=>true];
$strictView = new MovableHTMLView(103);
checkMoved(!$strictView->maintain(), 'Adopt an existing read-only module variable');
checkMoved($strictView->update('updated') && $objects[500]['value'] === 'updated', 'Update a read-only module variable');
$objects[500]['ident'] = 'RenamedHTML';
checkMoved($strictView->update('renamed') && $objects[500]['value'] === 'renamed', 'Update a renamed read-only module variable');
checkMoved($strictView->regenerate() && $objects[500]['value'] === 'regenerated', 'Regenerate a read-only module variable');
unset($objects[500], $attributes[103]);

// Legacy output is adopted in place, without overwriting its content.
$objects[501] = ['parent'=>101, 'ident'=>'HTML', 'type'=>3, 'value'=>'legacy'];
$view = new MovableHTMLView(101);
checkMoved(!$view->maintain() && $objects[501]['value'] === 'legacy', 'Adopt legacy child without replacing it');
$objects[501]['ident'] = 'Renamed';
checkMoved(!$view->maintain() && count($objects) === 1, 'Renaming must not create a duplicate');
checkMoved($view->update('renamed') && $objects[501]['value'] === 'renamed', 'Update renamed child by ID');
$objects[501]['parent'] = 999;
$view = new MovableHTMLView(101); // persisted identity survives a restart
checkMoved(!$view->maintain() && count($objects) === 1, 'Moving must not create a duplicate');
checkMoved($view->update('new') && $objects[501]['value'] === 'new', 'Update moved and renamed output');
checkMoved($view->regenerate() && $objects[501]['value'] === 'regenerated', 'Regenerate by persisted ID');
checkMoved($objects[501]['parent'] === 999, 'Do not move the output back');
$objects[501]['readOnly'] = true;
checkMoved(!$view->update('blocked') && $objects[501]['value'] === 'regenerated', 'Reject an unwritable moved output');
$objects[501]['readOnly'] = false;
$objects[501]['writeFails'] = true;
checkMoved(!$view->update('failed') && $objects[501]['value'] === 'regenerated', 'Report a failed moved-output write');
unset($objects[501]['writeFails']);
$view->enabled = false;
checkMoved(!$view->update('disabled') && !$view->maintain() && $objects[501]['value'] === 'regenerated', 'Disabled output stays intact');
$view->enabled = true;
checkMoved(!$view->maintain(), 'Re-enable without duplicate');

// Same ident in another instance must never steal an existing output.
$other = new MovableHTMLView(102);
checkMoved($other->maintain() && count($objects) === 2, 'Instances remain isolated');
$other->update('other');
checkMoved($objects[501]['value'] === 'regenerated', 'Other instance must not overwrite moved output');
$view->remove();
checkMoved(!isset($objects[501]) && count($objects) === 1, 'Explicit removal affects only owned output');
checkMoved($view->maintain() && count($objects) === 2, 'Deleted output can be recreated');
$newID = $nextID;
unset($objects[$newID]);
checkMoved($view->maintain() && count($objects) === 2, 'Externally deleted output is replaced once');
checkMoved(!$view->maintain() && count($objects) === 2, 'Repeated maintenance remains idempotent');
$objects[$nextID]['type'] = 1;
$rejected = false;
try {
    $view->maintain();
} catch (RuntimeException) {
    $rejected = true;
}
checkMoved($rejected && count($objects) === 2, 'Invalid tracked variable type must not be overwritten or replaced');
echo "Moved IPSView output identity tests passed.\n";
