<?php

declare(strict_types=1);

if (!class_exists('IPSModuleStrict')) {
    class IPSModuleStrict
    {
    }
}

require_once __DIR__ . '/../Kalender Konto/module.php';

function assertAccountStructure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(KalenderKonto::class);
$traits = class_uses(KalenderKonto::class);
foreach ([
    KalenderKontoSymconOAuthTrait::class,
    KalenderKontoGoogleOAuthTrait::class,
    KalenderKontoMicrosoftOAuthTrait::class,
    KalenderKontoICalendarAccountTrait::class,
    KalenderKontoChildGatewayTrait::class
] as $trait) {
    assertAccountStructure(
        isset($traits[$trait]),
        sprintf('KalenderKonto must use %s.', $trait)
    );
}

foreach ([
    'Create',
    'GetConfigurationForm',
    'UpdateProviderForm',
    'UpdateScheduleForm',
    'MessageSink',
    'RequestAction',
    'ConnectGoogle',
    'DisconnectGoogle',
    'ConnectMicrosoft',
    'DisconnectMicrosoft',
    'ApplyChanges',
    'ScheduledSynchronize',
    'ForwardData',
    'TestConnection',
    'Synchronize',
    'GetCalendars',
    'GetAccountStatus',
    'ClearCache'
] as $method) {
    assertAccountStructure(
        $reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(),
        sprintf('Public account API method %s is missing.', $method)
    );
}

assertAccountStructure(
    $reflection->hasMethod('ProcessOAuthData') && $reflection->getMethod('ProcessOAuthData')->isProtected(),
    'The native Google/Microsoft OAuth handler must remain protected.'
);
assertAccountStructure(
    is_string(file_get_contents(__DIR__ . '/../Kalender Konto/module.php'))
        && str_contains(
            (string) file_get_contents(__DIR__ . '/../Kalender Konto/module.php'),
            "RegisterAttributeInteger('PendingOAuthProvider', -1)"
        ),
    'OAuth callbacks must retain their pending provider across form changes and restarts.'
);

$accountSource = file_get_contents(__DIR__ . '/../Kalender Konto/module.php');
$accountFormSource = file_get_contents(__DIR__ . '/../Kalender Konto/form.json');
$accountForm = json_decode((string) $accountFormSource, true, 512, JSON_THROW_ON_ERROR);

assertAccountStructure(
    is_array($accountForm)
        && str_contains((string) $accountFormSource, '/blob/main/PRIVACY.md')
        && !str_contains((string) $accountFormSource, '/blob/legal/PRIVACY.md'),
    'The privacy button must link to the published privacy policy on the main branch.'
);

assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, 'self::GOOGLE_OAUTH_IDENTIFIER, self::MICROSOFT_OAUTH_IDENTIFIER')
        && str_contains($accountSource, '$this->RegisterOAuth($identifier)')
        && str_contains($accountSource, 'RegisterMessage(0, IPS_KERNELSTARTED)')
        && str_contains($accountSource, 'IPS_GetKernelRunlevel() === KR_READY')
        && preg_match(
            '/public function Create\(\): void[\s\S]*?public function GetConfigurationForm/',
            $accountSource,
            $createMethod
        ) === 1
        && !str_contains($createMethod[0], 'RegisterOAuth(')
        && !str_contains($accountSource, "RegisterPropertyString('GoogleClientID'")
        && !str_contains($accountSource, "RegisterPropertyString('GoogleClientSecret'")
        && !str_contains($accountSource, "RegisterPropertyString('MicrosoftClientID'")
        && !str_contains($accountSource, "RegisterPropertyString('MicrosoftClientSecret'"),
    'Google and Microsoft OAuth must be registered after kernel readiness without per-user client credentials.'
);
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, 'foreach ([self::GOOGLE_OAUTH_IDENTIFIER, self::MICROSOFT_OAUTH_IDENTIFIER] as $identifier)')
        && str_contains($accountSource, 'if (!$this->RegisterOAuth($identifier))')
        && str_contains($accountSource, 'catch (Throwable $exception)')
        && str_contains($accountSource, "'OAuthRegistration'"),
    'A temporarily unavailable OAuth control must not abort account or library initialization.'
);

$googleOAuthSource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/GoogleOAuthTrait.php');
$microsoftOAuthSource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/MicrosoftOAuthTrait.php');
$sharedOAuthSource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/SymconOAuthTrait.php');
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, "require_once __DIR__ . '/../libs/helper/SymconOAuthHelper.php';")
        && is_string($sharedOAuthSource)
        && str_contains($sharedOAuthSource, 'use Burki24\\SymconModuleHelper\\SymconOAuthClient;')
        && !is_file(__DIR__ . '/../libs/SymconOAuthClient.php'),
    'The account module must use the shared vendored SymconOAuthHelper without retaining a duplicate client.'
);
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, 'new GoogleCalendarOriginPolicy()')
        && str_contains($accountSource, 'new MicrosoftGraphOriginPolicy()')
        && str_contains($accountSource, 'private function createTrustedCloudHttpClient')
        && preg_match('/createTrustedCloudHttpClient\([\s\S]*?new CalendarHttpClient\([\s\S]*?true,/', $accountSource) === 1,
    'Trusted Google/Microsoft cloud clients must always verify TLS and enforce a fixed origin policy.'
);
assertAccountStructure(
    is_string($googleOAuthSource)
        && str_contains($googleOAuthSource, 'new GoogleOAuthOriginPolicy()')
        && is_string($microsoftOAuthSource)
        && is_string($sharedOAuthSource)
        && str_contains($sharedOAuthSource, 'new SymconOAuthOriginPolicy()'),
    'OAuth token and revocation requests must use fixed trusted-origin policies.'
);
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, '$verifyTls = $provider === self::PROVIDER_APPLE')
        && str_contains($accountSource, '? true')
        && str_contains($accountSource, ': $this->ReadPropertyBoolean(\'VerifyTLS\')')
        && str_contains($accountSource, '$this->UpdateFormField(\'VerifyTLS\', \'visible\', $canConfigureTls)'),
    'VerifyTLS must only be user-configurable for custom CalDAV and ICS/Webcal endpoints; iCloud remains verified.'
);
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, '$rawMessage = $this->sanitizeError($exception->getMessage());')
        && str_contains($accountSource, '$this->SendDebug(\'ProviderError\', $rawMessage, 0);')
        && !str_contains($accountSource, '$this->SendDebug(\'ProviderError\', $exception->getMessage(), 0);'),
    'Provider debug output must only receive sanitized exception messages.'
);

fwrite(STDOUT, "KalenderKonto structure tests passed.\n");
