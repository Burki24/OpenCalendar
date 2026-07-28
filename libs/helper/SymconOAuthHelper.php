<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use Closure;
use JsonException;
use RuntimeException;

final class SymconOAuthException extends RuntimeException
{
}

/**
 * Provides reusable OAuth token handling through the central Symcon OAuth service.
 *
 * Provider client credentials remain on the Symcon OAuth backend. Consumers
 * inject their existing trusted HTTP transport and only store the delegated
 * refresh token returned for an authorized user.
 *
 * @version 1.0.0
 */
final class SymconOAuthClient
{
    private const OAUTH_BASE_URL = 'https://oauth.ipmagic.de';

    /**
     * @var Closure(string,string,array<string,string>,string):array{statusCode:int,body:string}
     */
    private readonly Closure $httpRequest;

    /**
     * Creates a client for one centrally registered Symcon OAuth identifier.
     *
     * @param callable(string,string,array<string,string>,string):array{statusCode:int,body:string} $httpRequest
     *        Trusted HTTP transport receiving method, URL, headers and body.
     * @param string $identifier   Lowercase Symcon OAuth endpoint identifier.
     * @param string $providerName Human-readable provider name used in errors.
     *
     * @throws SymconOAuthException If the identifier or provider name is invalid.
     */
    public function __construct(
        callable $httpRequest,
        private readonly string $identifier,
        private readonly string $providerName
    ) {
        if (preg_match('/^[a-z0-9_]+$/', $this->identifier) !== 1) {
            throw new SymconOAuthException('The Symcon OAuth identifier is invalid.');
        }
        if (trim($this->providerName) === '') {
            throw new SymconOAuthException('The OAuth provider name is missing.');
        }

        $this->httpRequest = Closure::fromCallable($httpRequest);
    }

    /**
     * Returns the authorization URL for the current Symcon license account.
     *
     * @param string $licensee Symcon license account used for callback routing.
     *
     * @return string Absolute Symcon OAuth authorization URL.
     *
     * @throws SymconOAuthException If the license account is empty.
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
     * Exchanges an authorization code forwarded by the Symcon OAuth service.
     *
     * @param string $code Authorization code received by ProcessOAuthData().
     *
     * @return array{accessToken:string,refreshToken:string,expiresAt:int} Normalized OAuth tokens.
     *
     * @throws SymconOAuthException If the code or token response is invalid.
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
     * If the provider does not rotate its refresh token, the current token is
     * retained in the normalized response.
     *
     * @param string $refreshToken Current delegated refresh token.
     *
     * @return array{accessToken:string,refreshToken:string,expiresAt:int} Normalized OAuth tokens.
     *
     * @throws SymconOAuthException If the refresh token or response is invalid.
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
     * @param array<string,string> $fields OAuth token request fields.
     *
     * @return array{accessToken:string,refreshToken:string,expiresAt:int} Normalized OAuth tokens.
     */
    private function requestToken(
        array $fields,
        bool $requireRefreshToken,
        string $currentRefreshToken = ''
    ): array {
        $response = ($this->httpRequest)(
            'POST',
            self::OAUTH_BASE_URL . '/access_token/' . rawurlencode($this->identifier),
            [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
        );

        if (!is_array($response)
            || !isset($response['statusCode'], $response['body'])
            || !is_int($response['statusCode'])
            || !is_string($response['body'])) {
            throw new SymconOAuthException(
                'Symcon OAuth returned an invalid transport response for ' . $this->providerName . '.'
            );
        }

        try {
            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SymconOAuthException(
                'Symcon OAuth returned an invalid token response for ' . $this->providerName . '.'
            );
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new SymconOAuthException(
                'Symcon OAuth returned an invalid token response for ' . $this->providerName . '.'
            );
        }

        if ($response['statusCode'] < 200 || $response['statusCode'] >= 300 || isset($data['error'])) {
            $message = $this->readString($data['error_description'] ?? $data['error'] ?? null);
            throw new SymconOAuthException(
                $message !== '' ? $message : $this->providerName . ' OAuth token request failed.'
            );
        }

        $accessToken = $this->readString($data['access_token'] ?? null);
        $tokenType = strtolower($this->readString($data['token_type'] ?? 'bearer'));
        $refreshToken = $this->readString($data['refresh_token'] ?? $currentRefreshToken);
        if ($accessToken === '' || $tokenType !== 'bearer') {
            throw new SymconOAuthException($this->providerName . ' did not return a Bearer access token.');
        }
        if ($requireRefreshToken && $refreshToken === '') {
            throw new SymconOAuthException(
                $this->providerName . ' did not return a refresh token. Disconnect the account and connect it again.'
            );
        }

        $expiresIn = $data['expires_in'] ?? 3600;
        $lifetime = is_int($expiresIn) || is_string($expiresIn) && ctype_digit($expiresIn)
            ? (int) $expiresIn
            : 3600;

        return [
            'accessToken'  => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresAt'    => time() + max(60, $lifetime)
        ];
    }

    /**
     * Normalizes a scalar OAuth response value to a trimmed string.
     */
    private function readString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
