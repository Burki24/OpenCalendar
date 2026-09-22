<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender Ansicht/module.php';

// Exercise the real hook without a server, network, calendars or document data.
class AttachmentAccessBaselineView extends CalendarView
{
    public bool $enabled = true;
    public int $tokenPart = 1;
    public bool $allowHttp = false;
    public bool $allowPolicy = false;
    public int $policyChecks = 0;

    public function CanAccessAttachments(int $CalendarID, string $Operation, string $Destination): bool
    {
        ++$this->policyChecks;
        return $this->allowPolicy && $CalendarID === 42 && $Operation === 'download' && $Destination === 'local';
    }

    protected function ReadPropertyBoolean(string $name): bool
    {
        return $name === 'AttachmentAllowLocalHttp' && $this->allowHttp;
    }

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
    $view->enabled = true;
    $view->tokenPart = 1;
    $value = '{"calendarId":42,"operation":"download","destination":"local"}';
    foreach ([
        [false, true, '', $validToken, $value, 403],
        [true, true, '', $validToken, $value, 200],
        [true, false, '', $validToken, $value, 403],
        [false, true, 'on', $validToken, $value, 200],
        [true, true, '', 'wrong', $value, 403],
        [true, true, '', $validToken, '{', 400],
        [true, true, '', $validToken, '{"calendarId":43,"operation":"download","destination":"local"}', 403],
    ] as [$http, $policy, $https, $token, $rawValue, $expected]) {
        $view->allowHttp = $http;
        $view->allowPolicy = $policy;
        $view->policyChecks = 0;
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '192.168.178.2', 'HTTPS' => $https];
        $_POST = ['token' => $token, 'action' => 'CheckAttachmentAccess', 'value' => $rawValue];
        ob_start();
        try {
            $hook->invoke($view);
            $payload = json_decode((string) ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
        if (http_response_code() !== $expected || isset($payload['payload'])
            || ($expected === 200 && $payload !== ['policyAllowed' => true, 'transferAvailable' => true])) {
            throw new RuntimeException('Attachment preflight must remain request-scoped and report the protected transfer route.');
        }
        if (($token === 'wrong' || (!$http && $https === '')) && $view->policyChecks !== 0) {
            throw new RuntimeException('Authentication/transport rejection must precede policy lookup.');
        }
    }
} finally {
    $_SERVER = $savedServer;
    $_POST = $savedPost;
}
fwrite(STDOUT, "Attachment access baseline: disabled bridge, method, tokens and invalid requests rejected.\n");
