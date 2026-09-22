<?php

declare(strict_types=1);

require_once __DIR__ . '/attachment-access-baseline.php';

$GLOBALS['attachmentCalls'] = 0;
$GLOBALS['attachmentRevoke'] = false;
function IPSKAL_TransferLocalAttachment(int $id, string $request): string
{
    ++$GLOBALS['attachmentCalls'];
    if ($GLOBALS['attachmentRevoke']) {
        $GLOBALS['transferView']->allowPolicy = false;
    }
    return json_encode(['content' => base64_encode('PRIVATE DOWNLOAD')], JSON_THROW_ON_ERROR);
}
$view = new AttachmentAccessBaselineView(99999);
$GLOBALS['transferView'] = $view;
$view->allowPolicy = true;
foreach ([
    ['on', $validToken, 42, false, 200, 1],
    ['', $validToken, 42, false, 403, 0],
    ['on', 'wrong', 42, false, 403, 0],
    ['on', $validToken, 43, false, 403, 0],
    ['on', $validToken, 42, true, 400, 1]
] as [$https, $token, $calendarId, $revoke, $status, $calls]) {
    $view->allowPolicy = true;
    $GLOBALS['attachmentCalls'] = 0;
    $GLOBALS['attachmentRevoke'] = $revoke;
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTPS' => $https];
    $_POST = ['token' => $token, 'action' => 'TransferAttachment', 'value' => json_encode([
        'calendarId' => $calendarId, 'operation' => 'download', 'destination' => 'local',
        'selector'   => ['uid' => 'fixture'], 'data' => ['id' => str_repeat('a', 64)]
    ], JSON_THROW_ON_ERROR)];
    ob_start();
    $hook->invoke($view);
    $response = ob_get_clean();
    if (http_response_code() !== $status || $GLOBALS['attachmentCalls'] !== $calls
        || ($status === 200 ? $response !== 'PRIVATE DOWNLOAD' : str_contains($response, 'PRIVATE DOWNLOAD'))) {
        throw new RuntimeException('Transfer isolation/authentication/revocation failed.');
    }
}
// A shared view credential must never grant administrative recovery/cleanup access.
$GLOBALS['attachmentRevoke'] = false;
$GLOBALS['attachmentCalls'] = 0;
$view->allowPolicy = true;
foreach (['GetLocalAttachmentInventory', 'BeginLocalAttachmentBackup', 'ReadLocalAttachmentBackupPage', 'FinishLocalAttachmentBackup', 'DeleteLocalAttachmentOriginals'] as $action) {
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTPS' => 'on'];
    $_POST = ['token' => $validToken, 'action' => $action, 'value' => '{}'];
    ob_start();
    try {
        $hook->invoke($view);
        $response = json_decode((string) ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        ob_end_clean();
    }
    if (http_response_code() !== 400 || !isset($response['Error']) || isset($response['payload']) || $GLOBALS['attachmentCalls'] !== 0) {
        throw new RuntimeException('View token must not authorize attachment maintenance.');
    }
}
class ProviderAttachmentListView extends AttachmentAccessBaselineView
{
    public function CanAccessAttachments(int $CalendarID, string $Operation, string $Destination): bool
    {
        return $this->allowPolicy && $CalendarID === 42
            && in_array($Operation, ['list', 'download'], true) && $Destination === 'provider';
    }
}
function IPSKAL_ListProviderAttachments(int $id, string $selector): string
{
    ++$GLOBALS['providerAttachmentCalls'];
    if ($GLOBALS['providerAttachmentRevoke']) {
        $GLOBALS['providerAttachmentView']->allowPolicy = false;
    }
    return '{"result":[{"id":"file","name":"PRIVATE_PROVIDER_NAME.pdf","kind":"file","destination":"provider"}]}';
}
function IPSKAL_DownloadProviderAttachment(int $id, string $request): string
{
    ++$GLOBALS['providerAttachmentCalls'];
    if ($GLOBALS['providerAttachmentRevoke']) {
        $GLOBALS['providerAttachmentView']->allowPolicy = false;
    }
    return json_encode([
        'name'    => 'Private Report.pdf', 'contentType' => 'application/pdf',
        'content' => base64_encode('PRIVATE PROVIDER DOWNLOAD')
    ], JSON_THROW_ON_ERROR);
}
$providerView = new ProviderAttachmentListView(99998);
$GLOBALS['providerAttachmentView'] = $providerView;
foreach ([
    ['on', $validToken, 42, 'list', [], false, 200, 1, 'PRIVATE_PROVIDER_NAME'],
    ['on', $validToken, 42, 'download', ['id' => 'file'], false, 200, 1, 'PRIVATE PROVIDER DOWNLOAD'],
    ['', $validToken, 42, 'list', [], false, 403, 0, ''],
    ['on', 'wrong', 42, 'list', [], false, 403, 0, ''],
    ['on', $validToken, 43, 'list', [], false, 403, 0, ''],
    ['on', $validToken, 42, 'download', [], false, 400, 0, ''],
    ['on', $validToken, 42, 'download', ['id' => "bad\r\nid"], false, 400, 0, ''],
    ['on', $validToken, 42, 'upload', [], false, 400, 0, ''],
    ['on', $validToken, 42, 'list', ['url' => 'forged'], false, 400, 0, ''],
    ['on', $validToken, 42, 'list', [], true, 400, 1, ''],
    ['on', $validToken, 42, 'download', ['id' => 'file'], true, 400, 1, '']
] as [$https, $token, $calendarId, $operation, $data, $revoke, $status, $calls, $expected]) {
    $providerView->allowPolicy = true;
    $GLOBALS['providerAttachmentCalls'] = 0;
    $GLOBALS['providerAttachmentRevoke'] = $revoke;
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTPS' => $https];
    $_POST = ['token' => $token, 'action' => 'TransferAttachment', 'value' => json_encode([
        'calendarId' => $calendarId, 'operation' => $operation, 'destination' => 'provider',
        'selector'   => ['eventReference' => 'evt'], 'data' => $data
    ], JSON_THROW_ON_ERROR)];
    ob_start();
    try {
        $hook->invoke($providerView);
        $response = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    if (http_response_code() !== $status || $GLOBALS['providerAttachmentCalls'] !== $calls
        || ($expected !== '' ? $response !== $expected && !str_contains($response, $expected) : str_contains($response, 'PRIVATE'))
        || str_contains($response, '"payload"')) {
        throw new RuntimeException('Provider metadata hook leaked data, ignored rights or accepted unsupported operations.');
    }
}
$_SERVER = $savedServer;
$_POST = $savedPost;
fwrite(STDOUT, "Attachment transfer hook authentication, transport, selection and post-read revocation passed.\n");
