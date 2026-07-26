<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

/**
 * Provides reusable HTTP response helpers for Symcon module WebHooks.
 *
 * Intended for classes derived from IPSModule/IPSModuleStrict. The helper
 * sends small HTTP responses with explicit status codes and secure default
 * headers without depending on module-specific state.
 *
 * @version 1.1.0
 */
trait HttpResponseHelper
{
    /**
     * Sends a plain-text HTTP response.
     *
     * @param int    $statusCode HTTP status code to send.
     * @param string $message    Plain-text response body.
     *
     * @return void No return value.
     */
    protected function SendPlainTextResponse(int $statusCode, string $message): void
    {
        $this->SendResponse($statusCode, 'text/plain', $message);
    }

    /**
     * Sends a safely escaped HTML text response.
     *
     * The provided message is escaped before it is emitted, so callers can
     * safely include translated or provider-supplied text without creating
     * executable HTML markup.
     *
     * @param int    $statusCode HTTP status code to send.
     * @param string $message    Text to escape and send as an HTML response.
     *
     * @return void No return value.
     */
    protected function SendHtmlTextResponse(int $statusCode, string $message): void
    {
        $this->SendResponse(
            $statusCode,
            'text/html',
            htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    /**
     * Sends an HTTP response with common security and cache headers.
     *
     * @param int    $statusCode HTTP status code to send.
     * @param string $contentType MIME type without charset parameter.
     * @param string $body        Response body to emit.
     *
     * @return void No return value.
     */
    private function SendResponse(int $statusCode, string $contentType, string $body): void
    {
        http_response_code($statusCode);
        header('Content-Type: ' . $contentType . '; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $body;
    }
}
