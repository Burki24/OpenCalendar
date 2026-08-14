<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\SymconOAuthException;

trait KalenderKontoMicrosoftOAuthTrait
{
    /**
     * Starts Microsoft authorization through the native Symcon OAuth handler.
     *
     * @return string Authorization URL, or a localized error message when startup fails.
     */
    public function ConnectMicrosoft(): string
    {
        try {
            $this->assertSymconConnectAvailable();
            $this->SetBuffer('MicrosoftAccessToken', '');
            $this->requestOAuthDispatch(self::PROVIDER_MICROSOFT);

            return $this->createSymconOAuthClient(
                self::MICROSOFT_OAUTH_IDENTIFIER,
                'Microsoft 365'
            )->getAuthorizationUrl((string) IPS_GetLicensee());
        } catch (Throwable $exception) {
            $this->releaseOAuthDispatch();
            return $this->Translate('Microsoft authorization could not be started') . ': '
                . $this->handleProviderError($exception);
        }
    }

    /**
     * Clears locally stored Microsoft OAuth credentials and account state.
     *
     * @return bool Always true after local Microsoft authorization data has been cleared.
     */
    public function DisconnectMicrosoft(): bool
    {
        $this->WriteAttributeString('MicrosoftRefreshToken', '');
        $this->WriteAttributeString('MicrosoftAccount', '');
        $this->releaseOAuthDispatch();
        $this->SetBuffer('MicrosoftAccessToken', '');
        $this->ClearCache();
        $this->SetStatus($this->ReadPropertyBoolean('Active') ? self::STATUS_CONFIGURATION_MISSING : IS_INACTIVE);
        $this->ReloadForm();

        return true;
    }

    /**
     * Handles a Microsoft callback forwarded by the native Symcon OAuth handler.
     */
    private function processMicrosoftOAuthData(array $oauthData): void
    {
        try {
            $error = trim((string) ($oauthData['error_description'] ?? $oauthData['error'] ?? ''));
            if ($error !== '') {
                throw new SymconOAuthException($error);
            }

            $tokens = $this->createSymconOAuthClient(
                self::MICROSOFT_OAUTH_IDENTIFIER,
                'Microsoft 365'
            )->exchangeAuthorizationCode((string) ($oauthData['code'] ?? ''));
            $this->storeMicrosoftTokens($tokens);
            $this->WriteAttributeString('MicrosoftAccount', '');
            $this->WriteAttributeString('LastError', '');
            $this->ClearCache();
            $this->SetStatus($this->ReadPropertyBoolean('Active') ? IS_ACTIVE : IS_INACTIVE);
            $this->ReloadForm();

            $this->SendHtmlTextResponse(
                200,
                $this->Translate('Microsoft 365 was connected successfully. You can close this window.')
            );
        } catch (Throwable $exception) {
            $message = $this->handleProviderError($exception);
            $this->SendHtmlTextResponse(
                400,
                $this->Translate('Microsoft 365 could not be connected') . ': ' . $message
            );
        }
    }

    private function getMicrosoftAccessToken(): string
    {
        if (!$this->isMicrosoftConnected()) {
            throw new SymconOAuthException('Microsoft 365 is not connected yet.');
        }

        $cached = json_decode($this->GetBuffer('MicrosoftAccessToken'), true);
        if (is_array($cached)
            && trim((string) ($cached['token'] ?? '')) !== ''
            && (int) ($cached['expiresAt'] ?? 0) > time() + 60) {
            return (string) $cached['token'];
        }

        $tokens = $this->createSymconOAuthClient(
            self::MICROSOFT_OAUTH_IDENTIFIER,
            'Microsoft 365'
        )->refreshAccessToken($this->ReadAttributeString('MicrosoftRefreshToken'));
        $this->storeMicrosoftTokens($tokens);

        return $tokens['accessToken'];
    }

    /**
     * @param array{accessToken: string, refreshToken: string, expiresAt: int} $tokens
     */
    private function storeMicrosoftTokens(array $tokens): void
    {
        if ($tokens['refreshToken'] !== '') {
            $this->WriteAttributeString('MicrosoftRefreshToken', $tokens['refreshToken']);
        }
        $this->SetBuffer('MicrosoftAccessToken', json_encode(
            ['token' => $tokens['accessToken'], 'expiresAt' => $tokens['expiresAt']],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function isMicrosoftConnected(): bool
    {
        return trim($this->ReadAttributeString('MicrosoftRefreshToken')) !== '';
    }

    private function microsoftStatusText(): string
    {
        if (!$this->isMicrosoftConnected()) {
            return $this->Translate('Microsoft account is not connected.');
        }

        $account = trim($this->ReadAttributeString('MicrosoftAccount'));

        return $account !== ''
            ? sprintf($this->Translate('Connected with %s.'), $account)
            : $this->Translate('Microsoft account is connected.');
    }
}
