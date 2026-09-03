<?php

declare(strict_types=1);

use IPSKalender\CalendarProviderType;

require_once __DIR__ . '/../libs/CalendarProviderType.php';

function providerTypeExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$providers = [
    'apple'     => CalendarProviderType::APPLE,
    'caldav'    => CalendarProviderType::CALDAV,
    'google'    => CalendarProviderType::GOOGLE,
    'microsoft' => CalendarProviderType::MICROSOFT,
    'ics'       => CalendarProviderType::ICS
];

providerTypeExpect(
    array_values($providers) === [0, 1, 2, 3, 4],
    'Persisted calendar provider IDs must remain backward compatible.'
);

foreach ($providers as $key => $provider) {
    providerTypeExpect(
        CalendarProviderType::fromKey($key) === $provider,
        sprintf('Provider key %s must resolve to its persisted provider type.', $key)
    );
    providerTypeExpect(
        CalendarProviderType::isSupportedKey($key),
        sprintf('Provider key %s must remain supported.', $key)
    );
    providerTypeExpect(
        CalendarProviderType::isValid($provider),
        sprintf('Provider type %d must remain valid.', $provider)
    );
}

providerTypeExpect(
    CalendarProviderType::fromKey('unknown') === null
        && !CalendarProviderType::isSupportedKey('unknown')
        && !CalendarProviderType::isValid(-1)
        && !CalendarProviderType::isValid(5),
    'Unsupported provider keys and provider types must be rejected.'
);

$accountSource = (string) file_get_contents(__DIR__ . '/../Kalender Konto/module.php');
$discoverySource = (string) file_get_contents(__DIR__ . '/../Kalender Einrichtung/module.php');

providerTypeExpect(
    str_contains($accountSource, 'use IPSKalender\\CalendarProviderType;')
        && str_contains($accountSource, 'private const PROVIDER_APPLE = CalendarProviderType::APPLE;')
        && str_contains($accountSource, 'private const PROVIDER_CALDAV = CalendarProviderType::CALDAV;')
        && str_contains($accountSource, 'private const PROVIDER_GOOGLE = CalendarProviderType::GOOGLE;')
        && str_contains($accountSource, 'private const PROVIDER_MICROSOFT = CalendarProviderType::MICROSOFT;')
        && str_contains($accountSource, 'private const PROVIDER_ICS = CalendarProviderType::ICS;'),
    'Calendar Account provider aliases must use the shared provider type catalogue.'
);

providerTypeExpect(
    str_contains($discoverySource, 'use IPSKalender\\CalendarProviderType;')
        && !str_contains($discoverySource, 'private const PROVIDERS = [')
        && str_contains($discoverySource, 'CalendarProviderType::fromKey($provider)')
        && str_contains($discoverySource, 'CalendarProviderType::isSupportedKey($provider)'),
    'Calendar Setup must resolve provider keys through the shared provider type catalogue.'
);

echo "Shared calendar provider type mapping verified.\n";
