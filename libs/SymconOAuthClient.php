<?php

declare(strict_types=1);

namespace IPSKalender;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/CalendarHttpClient.php';

final class SymconOAuthException extends RuntimeException
{
}

/**
 * OAuth client for provider authorizations routed through the Symcon OAuth service.
 *
 * Provider application secrets stay on the Symcon OAuth backend. OpenCalendar
 * only stores the user-specific refresh token returned for a connected account.
 */
final class SymconOAuthClient
{
    private const OAUTH_BASE_URL = 'https://oauth.ipmagic.de';

    /**
     * Creates a client for one centrally registered Symcon OAuth identifier.
     */
    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly string $identifier,
        private readonly string $providerName
    ) {
        if (preg_match('/^[a-z0-9_]+$/', $this->identifier) !== 1) {
            throw new SymconOAuthException('The Symcon OAuth identifier is invalid.');
        }
        if (trim($this->providerName) === '') {
            throw new SymconOAuthException('The OAuth provider name is missing.');
        }
    }

    /**
     * Returns the authorization URL for the current Symcon license account.
     */
    public function getAuthorizationUrl(string $licensee): string
    {
        $licensee = trim($licensee);
        if ($licensee === '') {
            throw new SymconOAuthException('The Symcon license account is unavailable.');
        }

        return self::OAUTH_BASE_URL . '/authorize/' . rawurlencode($this->identifier) . '?' . http_build_query(
            ['username' => $licensee],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    /**
     * Exchanges the authorization code forwarded by Symcon OAuth.
     *
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new SymconOAuthException('The authorization code is missing.');
        }

        return $this->requestToken(['code' => $code], true);
    }

    /**
     * Refreshes an access token through the Symcon OAuth service.
     *
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $refreshToken = trim($refreshToken);
        if ($refreshToken === '') {
            throw new SymconOAuthException($this->providerName . ' is not connected yet.');
        }

        return $this->requestToken(['refresh_token' => $refreshToken], false, $refreshToken);
    }

    /**
     * @param array<string, string> $fields
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    private function requestToken(
        array $fields,
        bool $requireRefreshToken,
        string $currentRefreshToken = ''
    ): array {
        $response = $this->httpClient->request(
            'POST',
            self::OAUTH_BASE_URL . '/access_token/' . rawurlencode($this->identifier),
            [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
        );

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new SymconOAuthException(
                'Symcon OAuth returned an invalid token response for ' . $this->providerName . '.'
            );
        }
        if (!is_array($data)) {
            throw new SymconOAuthException(
                'Symcon OAuth returned an invalid token response for ' . $this->providerName . '.'
            );
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300 || isset($data['error'])) {
            $message = trim((string) ($data['error_description'] ?? $data['error'] ?? ''));
            throw new SymconOAuthException(
                $message !== '' ? $message : $this->providerName . ' OAuth token request failed.'
            );
        }

        $accessToken = trim((string) ($data['access_token'] ?? ''));
        $tokenType = strtolower(trim((string) ($data['token_type'] ?? 'bearer')));
        $refreshToken = trim((string) ($data['refresh_token'] ?? $currentRefreshToken));
        if ($accessToken === '' || $tokenType !== 'bearer') {
            throw new SymconOAuthException($this->providerName . ' did not return a Bearer access token.');
        }
        if ($requireRefreshToken && $refreshToken === '') {
            throw new SymconOAuthException(
                $this->providerName . ' did not return a refresh token. Disconnect the account and connect it again.'
            );
        }

        return [
            'accessToken'  => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresAt'    => time() + max(60, (int) ($data['expires_in'] ?? 3600))
        ];
    }
}
