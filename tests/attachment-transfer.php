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
$_SERVER = $savedServer;
$_POST = $savedPost;
fwrite(STDOUT, "Attachment transfer hook authentication, transport, selection and post-read revocation passed.\n");
