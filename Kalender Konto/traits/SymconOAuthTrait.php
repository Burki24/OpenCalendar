<?php

declare(strict_types=1);

use IPSKalender\SymconOAuthClient;
use IPSKalender\SymconOAuthException;
use IPSKalender\SymconOAuthOriginPolicy;

trait KalenderKontoSymconOAuthTrait
{
    /**
     * Creates a trusted client for one centrally registered Symcon OAuth provider.
     */
    private function createSymconOAuthClient(string $identifier, string $providerName): SymconOAuthClient
    {
        return new SymconOAuthClient(
            $this->createTrustedCloudHttpClient(new SymconOAuthOriginPolicy()),
            $identifier,
            $providerName
        );
    }

    /**
     * Ensures that the Symcon Connect transport required for OAuth is active.
     */
    private function assertSymconConnectAvailable(): void
    {
        foreach (IPS_GetInstanceListByModuleID(self::CONNECT_CONTROL_MODULE_ID) as $connectId) {
            $instance = IPS_GetInstance($connectId);
            if ((int) ($instance['InstanceStatus'] ?? 0) === IS_ACTIVE) {
                return;
            }
        }

        throw new SymconOAuthException('An active Symcon Connect connection is required for OAuth.');
    }

    /**
     * Reads an OAuth callback forwarded by the native Symcon OAuth handler.
     *
     * @return array<string, string>
     */
    private function readSymconOAuthData(): array
    {
        $rawInput = trim((string) file_get_contents('php://input'));
        $data = [];

        if ($rawInput !== '') {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_scalar($value)) {
                        $data[(string) $key] = (string) $value;
                    }
                }
            } elseif (str_contains($rawInput, 'code=') || str_contains($rawInput, 'error=')) {
                $formData = [];
                parse_str($rawInput, $formData);
                foreach ($formData as $key => $value) {
                    if (is_scalar($value)) {
                        $data[(string) $key] = (string) $value;
                    }
                }
            } else {
                $data['code'] = $rawInput;
            }
        }

        foreach (['code', 'error', 'error_description'] as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
                $data[$key] = (string) $_GET[$key];
            } elseif (isset($_POST[$key]) && is_scalar($_POST[$key])) {
                $data[$key] = (string) $_POST[$key];
            }
        }

        return $data;
    }
}
