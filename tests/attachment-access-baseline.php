<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender Ansicht/module.php';

// Exercise the real hook without a server, network, calendars or document data.
class AttachmentAccessBaselineView extends CalendarView
{
    public bool $enabled = true;
    public int $tokenPart = 1;

    protected function IsIPSViewHTMLPageEnabled(): bool
    {
        return $this->enabled;
    }

    protected function ReadAttributeInteger(string $name): int
    {
        return $this->tokenPart;
    }
}

$view = new AttachmentAccessBaselineView(99999);
$hook = new ReflectionMethod(CalendarView::class, 'ProcessHookData');
$validToken = str_repeat('00000001', 4);
$savedServer = $_SERVER;
$savedPost = $_POST;
$cases = [
    ['disabled', false, 1, 'POST', $validToken, '{}', 404],
    ['GET', true, 1, 'GET', $validToken, '{}', 405],
    ['missing token', true, 1, 'POST', '', '{}', 403],
    ['wrong token', true, 1, 'POST', str_repeat('f', 32), '{}', 403],
    ['uninitialized token', true, 0, 'POST', str_repeat('0', 32), '{}', 403],
    ['malformed value', true, 1, 'POST', $validToken, '{', 400],
    ['unknown action', true, 1, 'POST', $validToken, '{}', 400]
];
try {
    foreach ($cases as [$label, $enabled, $part, $method, $token, $value, $expected]) {
        $view->enabled = $enabled;
        $view->tokenPart = $part;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = ['token' => $token, 'action' => 'UnknownAttachmentProbe', 'value' => $value];
        ob_start();
        try {
            $hook->invoke($view);
            $response = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (http_response_code() !== $expected || !isset($payload['Error']) || isset($payload['payload'])) {
            throw new RuntimeException($label . ': expected rejection HTTP ' . $expected . ', got ' . http_response_code());
        }
    }
} finally {
    $_SERVER = $savedServer;
    $_POST = $savedPost;
}
fwrite(STDOUT, "Attachment access baseline: disabled bridge, method, tokens and invalid requests rejected.\n");
