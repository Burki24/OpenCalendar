<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\SymconOAuthException;
use IPSKalender\GoogleOAuthOriginPolicy;

trait KalenderKontoGoogleOAuthTrait
{
    /**
     * Starts Google authorization through the native Symcon OAuth handler.
     *
     * @return string Authorization URL, or a localized error message when startup fails.
     */
    public function ConnectGoogle(): string
    {
        try {
            $this->assertSymconConnectAvailable();
            $this->SetBuffer('GoogleAccessToken', '');
            $this->requestOAuthDispatch(self::PROVIDER_GOOGLE);

            return $this->createSymconOAuthClient(
                self::GOOGLE_OAUTH_IDENTIFIER,
                'Google Calendar'
            )->getAuthorizationUrl((string) IPS_GetLicensee());
        } catch (Throwable $exception) {
            $this->releaseOAuthDispatch();
            return $this->Translate('Google authorization could not be started') . ': '
                . $this->handleProviderError($exception);
        }
    }

    /**
     * Revokes the stored Google authorization when possible and clears local OAuth state.
     *
     * @return bool Always true after local Google authorization data has been cleared.
     */
    public function DisconnectGoogle(): bool
    {
        $refreshToken = $this->ReadAttributeString('GoogleRefreshToken');
        if ($refreshToken !== '') {
            try {
                $client = $this->createTrustedCloudHttpClient(new GoogleOAuthOriginPolicy());
                $client->request(
                    'POST',
                    'https://oauth2.googleapis.com/revoke',
                    ['Content-Type' => 'application/x-www-form-urlencoded'],
                    http_build_query(['token' => $refreshToken], '', '&', PHP_QUERY_RFC3986)
                );
            } catch (Throwable $exception) {
                $this->SendDebug('GoogleOAuthRevoke', $this->sanitizeError($exception->getMessage()), 0);
            }
        }

        $this->WriteAttributeString('GoogleRefreshToken', '');
        $this->WriteAttributeString('GoogleAccount', '');
        $this->WriteAttributeString('GoogleTokenClientID', '');
        $this->releaseOAuthDispatch();
        $this->SetBuffer('GoogleAccessToken', '');
        $this->ClearCache();
        $this->SetStatus($this->ReadPropertyBoolean('Active') ? self::STATUS_CONFIGURATION_MISSING : IS_INACTIVE);
        $this->ReloadForm();

        return true;
    }

    /**
     * Handles a Google callback forwarded by the native Symcon OAuth handler.
     */
    private function processGoogleOAuthData(array $oauthData): void
    {
        try {
            $error = trim((string) ($oauthData['error_description'] ?? $oauthData['error'] ?? ''));
            if ($error !== '') {
                throw new SymconOAuthException($error);
            }

            $tokens = $this->createSymconOAuthClient(
                self::GOOGLE_OAUTH_IDENTIFIER,
                'Google Calendar'
            )->exchangeAuthorizationCode((string) ($oauthData['code'] ?? ''));
            $this->storeGoogleTokens($tokens);
            $this->WriteAttributeString('GoogleAccount', '');
            $this->WriteAttributeString('LastError', '');
            $this->ClearCache();
            $this->SetStatus($this->ReadPropertyBoolean('Active') ? IS_ACTIVE : IS_INACTIVE);
            $this->ReloadForm();

            $this->SendHtmlTextResponse(
                200,
                $this->Translate('Google Calendar was connected successfully. You can close this window.')
            );
        } catch (Throwable $exception) {
            $message = $this->handleProviderError($exception);
            $this->SendHtmlTextResponse(
                400,
                $this->Translate('Google Calendar could not be connected') . ': ' . $message
            );
        }
    }

    private function getGoogleAccessToken(): string
    {
        if (!$this->isGoogleConnected()) {
            throw new SymconOAuthException('Google Calendar is not connected yet.');
        }

        $cached = json_decode($this->GetBuffer('GoogleAccessToken'), true);
        if (is_array($cached)
            && trim((string) ($cached['token'] ?? '')) !== ''
            && (int) ($cached['expiresAt'] ?? 0) > time() + 60) {
            return (string) $cached['token'];
        }

        $tokens = $this->createSymconOAuthClient(
            self::GOOGLE_OAUTH_IDENTIFIER,
            'Google Calendar'
        )->refreshAccessToken($this->ReadAttributeString('GoogleRefreshToken'));
        $this->storeGoogleTokens($tokens);

        return $tokens['accessToken'];
    }

    /**
     * @param array{accessToken: string, refreshToken: string, expiresAt: int} $tokens
     */
    private function storeGoogleTokens(array $tokens): void
    {
        if ($tokens['refreshToken'] !== '') {
            $this->WriteAttributeString('GoogleRefreshToken', $tokens['refreshToken']);
        }

        // An empty legacy client marker identifies tokens issued by the central Symcon OAuth application.
        $this->WriteAttributeString('GoogleTokenClientID', '');
        $this->SetBuffer('GoogleAccessToken', json_encode(
            ['token' => $tokens['accessToken'], 'expiresAt' => $tokens['expiresAt']],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function isGoogleConnected(): bool
    {
        return trim($this->ReadAttributeString('GoogleRefreshToken')) !== ''
            && trim($this->ReadAttributeString('GoogleTokenClientID')) === '';
    }

    private function googleStatusText(): string
    {
        if (!$this->isGoogleConnected()) {
            if (trim($this->ReadAttributeString('GoogleTokenClientID')) !== '') {
                return $this->Translate('Reconnect the Google account to migrate it to Symcon OAuth.');
            }

            return $this->Translate('Google account is not connected.');
        }

        $account = trim($this->ReadAttributeString('GoogleAccount'));

        return $account !== ''
            ? sprintf($this->Translate('Connected with %s.'), $account)
            : $this->Translate('Google account is connected.');
    }
}
