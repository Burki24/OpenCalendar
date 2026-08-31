<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\IPSViewStyleHelper;
use Burki24\SymconModuleHelper\IPSViewStyleProfileHelper;

require_once __DIR__ . '/../libs/helper/IPSViewStyleHelper.php';
require_once __DIR__ . '/../libs/helper/IPSViewStyleProfileHelper.php';

final class OpenCalendarStyleProfileConsumer
{
    use IPSViewStyleHelper;

    /** @var array<string,mixed> */
    private array $properties = [
        'IPSViewStyleSource'                 => self::IPSVIEW_STYLE_SOURCE_PROFILE,
        'IPSViewStyleTransparentBackground' => false,
        'IPSViewStylePreset'                 => 'standard',
        'IPSViewStyleFontScale'              => 100,
        'IPSViewStyleDisabledOpacity'        => 52,
        'IPSViewStyleGradientStrength'       => 0
    ];

    /** @return array<string,string|float> */
    public function resolve(string $document): array
    {
        return $this->IPSViewResolvedStyle($document);
    }

    public function css(string $document): string
    {
        return $this->IPSViewStyleCSSVariables(':root', $document);
    }

    public function rootFontSize(string $document): string
    {
        return $this->IPSViewStyleRootFontSize($document);
    }

    protected function ReadPropertyInteger(string $name): int
    {
        return (int) ($this->properties[$name] ?? 0);
    }

    protected function ReadPropertyBoolean(string $name): bool
    {
        return (bool) ($this->properties[$name] ?? false);
    }

    protected function ReadPropertyFloat(string $name): float
    {
        return (float) ($this->properties[$name] ?? 0.0);
    }

    protected function ReadPropertyString(string $name): string
    {
        return (string) ($this->properties[$name] ?? '');
    }
}

function assertStyleProfileE2E(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixturePath = __DIR__ . '/fixtures/ipsview-assistant-style-profile-v1.json';
$json = (string) file_get_contents($fixturePath);
$profile = IPSViewStyleProfileHelper::decode($json);
assertStyleProfileE2E(
    ($profile['createdBy'] ?? '') === 'IPSView Assistant',
    'The reference fixture must be an IPSView Assistant export.'
);
assertStyleProfileE2E(
    count($profile['style']) === count(IPSViewStyleProfileHelper::styleFields()),
    'The Assistant reference profile must contain every Style Profile V1 field.'
);

$consumer = new OpenCalendarStyleProfileConsumer();
$resolved = $consumer->resolve($json);
$css = $consumer->css($json);

assertStyleProfileE2E($resolved['ViewBackground'] === 'rgba(16, 32, 48, 0.910)', 'View background opacity was not preserved.');
assertStyleProfileE2E($resolved['PageBackground'] === 'rgba(32, 48, 64, 0.860)', 'Page background opacity was not preserved.');
assertStyleProfileE2E($resolved['LabelBackground'] === 'rgba(48, 64, 80, 0.770)', 'Label background opacity was not preserved.');
assertStyleProfileE2E($resolved['ControlBackground'] === 'rgba(64, 80, 96, 0.830)', 'Control background opacity was not preserved.');
assertStyleProfileE2E($resolved['ControlActiveBackground'] === 'rgba(80, 96, 112, 0.880)', 'Active control opacity was not preserved.');
assertStyleProfileE2E($resolved['ControlInactiveBackground'] === 'rgba(96, 112, 128, 0.620)', 'Inactive control opacity was not preserved.');
assertStyleProfileE2E($resolved['PopupBackground'] === 'rgba(24, 40, 56, 0.940)', 'Popup background opacity was not preserved.');
assertStyleProfileE2E($resolved['Border'] === 'rgba(113, 128, 143, 0.730)', 'Border opacity was not preserved.');
assertStyleProfileE2E($resolved['Line'] === 'rgba(130, 145, 160, 0.580)', 'Line opacity was not preserved.');
assertStyleProfileE2E($resolved['PopupBorder'] === 'rgba(147, 162, 177, 0.810)', 'Popup border opacity was not preserved.');
assertStyleProfileE2E($resolved['FontFamily'] === 'RobotoMono', 'Font family was not preserved.');
assertStyleProfileE2E($resolved['FontStyle'] === 'boldItalic', 'Font style was not preserved.');
assertStyleProfileE2E($resolved['FontSize'] === 17.0, 'Font size was not preserved.');
assertStyleProfileE2E(abs((float) $resolved['FontScale'] - 1.35) < 0.0001, 'Font scale was not preserved.');
assertStyleProfileE2E($consumer->rootFontSize($json) === '23px', 'OpenCalendar root font sizing does not honor FontSize and FontScale.');
assertStyleProfileE2E(abs((float) $resolved['BorderRadius'] - 9.0) < 0.0001, 'Border radius was not preserved.');
assertStyleProfileE2E(abs((float) $resolved['BorderWidth'] - 1.5) < 0.0001, 'Border width was not preserved.');
assertStyleProfileE2E(abs((float) $resolved['LineWidth'] - 2.5) < 0.0001, 'Line width was not preserved.');
assertStyleProfileE2E(abs((float) $resolved['DisabledOpacity'] - 0.64) < 0.0001, 'Disabled opacity was not preserved.');
assertStyleProfileE2E(str_contains($resolved['Shadow'], '2px 8px 19px 1.5px'), 'Main shadow geometry was not preserved.');
assertStyleProfileE2E(str_contains($resolved['Shadow'], '0.410'), 'Main shadow opacity was not preserved.');
assertStyleProfileE2E(str_contains($resolved['PopupShadow'], '0px 8px 19px 1.5px'), 'Popup shadow geometry was not preserved.');
assertStyleProfileE2E(str_contains($resolved['PopupShadow'], '0.570'), 'Popup shadow opacity was not preserved.');

assertStyleProfileE2E(str_contains($css, '--ipsview-font-family: RobotoMono;'), 'Canonical font family CSS variable is missing.');
assertStyleProfileE2E(str_contains($css, '--ipsview-font-style: italic;'), 'Bold italic style did not produce italic CSS.');
assertStyleProfileE2E(str_contains($css, '--ipsview-font-weight: 700;'), 'Bold italic style did not produce bold CSS.');
assertStyleProfileE2E(str_contains($css, '--ipsview-radius: 9px;'), 'Profile radius is missing from CSS variables.');
assertStyleProfileE2E(str_contains($css, '--ipsview-border-width: 1.5px;'), 'Profile border width is missing from CSS variables.');
assertStyleProfileE2E(str_contains($css, '--ipsview-line-width: 2.5px;'), 'Profile line width is missing from CSS variables.');
assertStyleProfileE2E(str_contains($css, '--ipsview-gradient-accent: linear-gradient(135deg, rgba(63, 167, 214, 0.260)'), 'Gradient strength was not preserved.');
assertStyleProfileE2E(str_contains($css, '--ipsview-role-popup-background: var(--ipsview-popup-background);'), 'Popup semantic role is missing.');
assertStyleProfileE2E(str_contains($css, '--ipsview-role-popup-border: var(--ipsview-popup-border);'), 'Popup border semantic role is missing.');
assertStyleProfileE2E(str_contains($css, '--ipsview-role-popup-shadow: var(--ipsview-popup-shadow);'), 'Popup shadow semantic role is missing.');
assertStyleProfileE2E(str_contains($css, '--ipsview-role-font-style: var(--ipsview-font-style);'), 'Font-style semantic role is missing.');
assertStyleProfileE2E(str_contains($css, '--ipsview-role-font-weight: var(--ipsview-font-weight);'), 'Font-weight semantic role is missing.');

$consumerStyle = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/style.css');
foreach ([
    '--cal-view-background: var(--ipsview-role-view-background);',
    '--cal-page-background: var(--ipsview-role-page-background);',
    '--cal-dialog: var(--ipsview-role-popup-background);',
    '--cal-popup-border: var(--ipsview-role-popup-border);',
    '--cal-popup-shadow: var(--ipsview-role-popup-shadow);',
    '--cal-border-width: var(--ipsview-role-border-width);',
    '--cal-line-width: var(--ipsview-role-line-width);',
    '--cal-shadow: var(--ipsview-role-shadow);',
    '--cal-disabled-opacity: var(--ipsview-role-disabled-opacity);',
    '--cal-gradient-accent: var(--ipsview-role-gradient-accent);',
    '--cal-font-family: var(--ipsview-role-font-family);',
    'font-style: var(--ipsview-role-font-style);',
    'font-weight: var(--ipsview-role-font-weight);',
    'border: var(--cal-border-width, 1px) solid var(--cal-popup-border);',
    'box-shadow: var(--cal-popup-shadow);',
    'html.ipsview-mode #event-dialog',
    '@media (max-width: 560px)',
    '@media (max-width: 420px)',
    '@media (max-width: 800px)',
    '@media (max-height: 260px)'
] as $requiredStyleContract) {
    assertStyleProfileE2E(
        str_contains($consumerStyle, $requiredStyleContract),
        'OpenCalendar IPSView CSS is missing Style Profile V1 consumption: ' . $requiredStyleContract
    );
}

$roundTrip = IPSViewStyleProfileHelper::decode(IPSViewStyleProfileHelper::encode($profile));
assertStyleProfileE2E($roundTrip === $profile, 'Consumer-side decode/encode round-trip changed the canonical profile.');

echo "IPSView Assistant/OpenCalendar style profile E2E tests passed.\n";
