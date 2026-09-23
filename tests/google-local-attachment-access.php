<?php

declare(strict_types=1);

require_once __DIR__ . '/local-calendar-module.php';

function IPS_GetProperty(int $instanceId, string $property): mixed
{
    return $property === 'Provider' ? ($GLOBALS['googleAccountProvider'][$instanceId] ?? -1) : null;
}

function IPSKALACC_GetAccountStatus(int $instanceId): string
{
    return json_encode([
        'connected' => $GLOBALS['googleAccountConnected'][$instanceId] ?? false,
        'account'   => $GLOBALS['googleAccountName'][$instanceId] ?? ''
    ], JSON_THROW_ON_ERROR);
}

$calendar = localModuleNew(6044);
$calendar->properties['LocalCalendar'] = false;
$calendar->properties['CalendarID'] = 'https://www.googleapis.com/calendar/v3/calendars/primary';
$calendar->properties['AttachmentMode'] = 2;
$calendar->properties['AttachmentAllowLocal'] = true;
$GLOBALS['localConnections'][6044] = 900;
$GLOBALS['googleAccountProvider'][900] = 2;
$GLOBALS['googleAccountConnected'][900] = true;
$GLOBALS['googleAccountName'][900] = 'tester@example.invalid';
$GLOBALS['localParentReply'] = static function (string $json): string
{
    $request = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    localModuleCheck(
        $request['Operation'] === 'GetEventForEdit'
        && $request['CalendarID'] === 'https://www.googleapis.com/calendar/v3/calendars/primary',
        'Google local storage must use the selected provider calendar.'
    );
    $eventId = (string) ($request['EventReference'] ?? '');
    $event = $eventId === 'event-1'
        ? ['eventReference' => 'event-1', 'recurrenceType' => 'single', 'status' => 'confirmed']
        : ['eventReference' => 'another-event', 'recurrenceType' => 'single', 'status' => 'confirmed'];
    return json_encode(['Success' => true, 'Payload' => $event], JSON_THROW_ON_ERROR);
};
$selector = ['eventReference' => 'event-1'];
$request = ['requestId' => hash('sha256', 'google-local-upload'), 'name' => 'private.txt', 'content' => base64_encode('PRIVATE')];
$meta = json_decode($calendar->TransferLocalAttachment(json_encode([
    'operation' => 'upload', 'selector' => $selector, 'data' => $request
], JSON_THROW_ON_ERROR)), true, 8, JSON_THROW_ON_ERROR)['result'];
$files = json_decode($calendar->TransferLocalAttachment(json_encode([
    'operation' => 'list', 'selector' => $selector, 'data' => []
], JSON_THROW_ON_ERROR)), true, 8, JSON_THROW_ON_ERROR)['result'];
localModuleCheck($files === [$meta], 'Google local upload/list roundtrip failed.');
$download = json_decode($calendar->TransferLocalAttachment(json_encode([
    'operation' => 'download', 'selector' => $selector, 'data' => ['id' => $meta['id']]
], JSON_THROW_ON_ERROR)), true, 8, JSON_THROW_ON_ERROR)['content'];
localModuleCheck(base64_decode($download, true) === 'PRIVATE', 'Google local download failed.');
$reject = static function (callable $callback): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('Unverified Google local attachment access was allowed.');
};
$call = static fn (array $event): string => $calendar->TransferLocalAttachment(json_encode([
    'operation' => 'list', 'selector' => $event, 'data' => []
], JSON_THROW_ON_ERROR));
$reject(fn () => $call(['eventReference' => 'missing']));
$reject(fn () => $call($selector + ['account' => 'forged']));
$GLOBALS['googleAccountName'][900] = 'another@example.invalid';
localModuleCheck(
    json_decode($call($selector), true, 8, JSON_THROW_ON_ERROR)['result'] === [],
    'A different Google account must not inherit the previous account files.'
);
$GLOBALS['googleAccountName'][900] = 'tester@example.invalid';
$GLOBALS['googleAccountConnected'][900] = false;
$reject(fn () => $call($selector));
$GLOBALS['googleAccountConnected'][900] = true;
$previousReply = $GLOBALS['localParentReply'];
$GLOBALS['localParentReply'] = static function (string $json) use ($previousReply): string
{
    $GLOBALS['googleAccountName'][900] = 'switched@example.invalid';
    return $previousReply($json);
};
$reject(fn () => $call($selector));
$GLOBALS['localParentReply'] = $previousReply;
$GLOBALS['googleAccountName'][900] = 'tester@example.invalid';
$calendar->properties['AttachmentAllowLocal'] = false;
$reject(fn () => $call($selector));
$calendar->properties['AttachmentAllowLocal'] = true;
$GLOBALS['googleAccountProvider'][900] = 3;
$reject(fn () => $call($selector));

fwrite(STDOUT, "Google local attachment storage: live owner verification, account isolation and revocation passed.\n");
