<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/ConstantStubs.php';

// Replace only Symcon configuration/instance services; exercise real module gates.
class IPSModuleStrict
{
    public array $properties = [];
    public function Translate(string $text): string
    {
        return $text;
    }
    protected function ReadPropertyInteger(string $name): int
    {
        return $this->properties[$name] ?? 0;
    }
    protected function ReadPropertyBoolean(string $name): bool
    {
        return $this->properties[$name] ?? false;
    }
    protected function ReadPropertyString(string $name): string
    {
        return $this->properties[$name] ?? '[]';
    }
    protected function ReadAttributeBoolean(string $name): bool
    {
        return false;
    }
    protected function ReadAttributeString(string $name): string
    {
        return '[]';
    }
}

function IPS_InstanceExists(int $id): bool
{
    return $id === 42;
}
function IPS_GetInstance(int $id): array
{
    return ['ModuleInfo' => ['ModuleID' => '{227B63E4-4223-316B-76E9-FD3849689562}']];
}
function IPS_GetName(int $id): string
{
    return 'Fixture calendar';
}
function IPS_GetProperty(int $id, string $name): mixed
{
    return $GLOBALS['policyCalendar']->properties[$name] ?? '';
}
function IPSKAL_GetCalendarStatus(int $id): string
{
    return '{"calendarColor":"#123456","canWrite":false,"microsoftTaskListId":""}';
}
function IPSKAL_CanAccessAttachments(int $id, string $operation, string $destination): bool
{
    ++$GLOBALS['policyCalls'];
    if ($GLOBALS['policyFailure']) {
        throw new RuntimeException('Unavailable calendar');
    }
    return $GLOBALS['policyCalendar']->CanAccessAttachments($operation, $destination);
}

require_once __DIR__ . '/../Kalender/module.php';
require_once __DIR__ . '/../Kalender Ansicht/module.php';

function expectAttachmentAccess(bool $value, bool $expected, string $case): void
{
    if ($value !== $expected) {
        throw new RuntimeException('Attachment module gate: ' . $case);
    }
}

$calendar = new Calendar();
$view = new CalendarView();
$GLOBALS['policyCalendar'] = $calendar;
$GLOBALS['policyCalls'] = 0;
$GLOBALS['policyFailure'] = false;
expectAttachmentAccess($view->CanAccessAttachments(42, 'download', 'local'), false, 'defaults');
$settings = ['AttachmentMode' => 2, 'AttachmentAllowLocal' => true, 'AttachmentAllowProvider' => true];
$calendar->properties = $settings + ['Active' => true, 'CanWrite' => false];
$view->properties = $settings + ['Calendars' => '[{"InstanceID":42,"Enabled":true}]'];
expectAttachmentAccess($view->CanAccessAttachments(42, 'upload', 'local'), true, 'local annotation of read-only source');
expectAttachmentAccess($view->CanAccessAttachments(42, 'upload', 'provider'), false, 'provider read-only');
expectAttachmentAccess($view->CanAccessAttachments(42, 'download', 'provider'), true, 'provider reading');
$calendar->properties['CanWrite'] = true;
expectAttachmentAccess($view->CanAccessAttachments(42, 'upload', 'provider'), true, 'provider writable');
$calendar->properties['LocalCalendar'] = true;
expectAttachmentAccess($view->CanAccessAttachments(42, 'download', 'provider'), false, 'no provider destination on local calendar');
$calendar->properties['LocalCalendar'] = false;
foreach (['AttachmentMode' => 0, 'AttachmentAllowLocal' => false, 'Active' => false] as $key => $value) {
    $previous = $calendar->properties[$key];
    $calendar->properties[$key] = $value;
    expectAttachmentAccess($view->CanAccessAttachments(42, 'download', 'local'), false, 'calendar ceiling ' . $key);
    $calendar->properties[$key] = $previous;
}
$calendar->properties['AttachmentMode'] = 1;
expectAttachmentAccess($view->CanAccessAttachments(42, 'delete', 'local'), false, 'calendar read-only ceiling');
$calendar->properties['AttachmentMode'] = 2;
$view->properties['AttachmentMode'] = 1;
expectAttachmentAccess($view->CanAccessAttachments(42, 'delete', 'local'), false, 'view read-only restriction');
expectAttachmentAccess($view->CanAccessAttachments(42, 'download', 'local'), true, 'view read-only download');
$view->properties['AttachmentMode'] = 2;
$view->properties['AttachmentIdentityMode'] = 1;
expectAttachmentAccess($view->CanAccessAttachments(42, 'download', 'local'), false, 'identity adapter unavailable');
$view->properties['AttachmentIdentityMode'] = 0;
$calls = $GLOBALS['policyCalls'];
expectAttachmentAccess($view->CanAccessAttachments(43, 'download', 'local'), false, 'forged calendar');
$view->properties['Calendars'] = '[{"InstanceID":42,"Enabled":false}]';
expectAttachmentAccess($view->CanAccessAttachments(42, 'download', 'local'), false, 'disabled calendar selection');
expectAttachmentAccess($GLOBALS['policyCalls'] === $calls, true, 'unselected calendars never reached');
$view->properties['Calendars'] = '[{"InstanceID":42,"Enabled":true}]';
$GLOBALS['policyFailure'] = true;
expectAttachmentAccess($view->CanAccessAttachments(42, 'download', 'local'), false, 'calendar failure');
$GLOBALS['policyFailure'] = false;
$view->properties['Calendars'] = '{';
expectAttachmentAccess($view->CanAccessAttachments(42, 'download', 'local'), false, 'malformed selection');
expectAttachmentAccess($calendar->CanAccessAttachments('upload', ''), false, 'explicit destination required');
fwrite(STDOUT, "Attachment module gates: calendar/view intersection, selection, destinations and revocation passed.\n");
