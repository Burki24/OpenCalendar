<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;

/**
 * Provides reusable IPSView color properties, form controls and CSS tokens.
 *
 * The helper registers nine SelectColor-compatible integer properties and
 * resolves them into a contrast-safe palette for standalone IPSView HTML
 * pages. Module-specific layouts, gradients and disabled-control opacity
 * deliberately remain outside this helper.
 *
 * @version 1.0.0
 */
trait IPSViewColorPaletteHelper
{
    /** @var array<string,string> */
    private const IPSVIEW_COLOR_PROPERTY_NAMES = [
        'Page'          => 'IPSViewPageColorValue',
        'Surface'       => 'IPSViewSurfaceColorValue',
        'SurfaceStrong' => 'IPSViewSurfaceStrongColorValue',
        'Text'          => 'IPSViewTextColorValue',
        'MutedText'     => 'IPSViewMutedTextColorValue',
        'Accent'        => 'IPSViewAccentColorValue',
        'Success'       => 'IPSViewSuccessColorValue',
        'Warning'       => 'IPSViewWarningColorValue',
        'Danger'        => 'IPSViewDangerColorValue'
    ];

    /** @var array<string,int> */
    private const IPSVIEW_COLOR_DEFAULTS = [
        'Page'          => 0xF4F5F7,
        'Surface'       => 0xFFFFFF,
        'SurfaceStrong' => 0xE9EDF2,
        'Text'          => 0x202124,
        'MutedText'     => 0x5F6368,
        'Accent'        => 0x55CBB5,
        'Success'       => 0x56C881,
        'Warning'       => 0xE6A93F,
        'Danger'        => 0xE36D6D
    ];

    /** @var array<string,string> */
    private const IPSVIEW_COLOR_CAPTIONS = [
        'Page'          => 'Page/background color',
        'Surface'       => 'Cards and controls',
        'SurfaceStrong' => 'Highlighted surface',
        'Text'          => 'Primary text color',
        'MutedText'     => 'Secondary text color',
        'Accent'        => 'Accent/active color',
        'Success'       => 'Ready/active color',
        'Warning'       => 'Warning/delay color',
        'Danger'        => 'Alarm/error color'
    ];

    private const IPSVIEW_TEXT_CONTRAST_RATIO = 4.5;
    private const IPSVIEW_FAINT_CONTRAST_RATIO = 3.5;

    /**
     * Registers the shared IPSView SelectColor properties.
     *
     * Defaults are supplied by palette key, for example Page, Surface or Text.
     * Omitted values use the neutral helper defaults.
     *
     * @param array<string,int> $defaults Module-specific RGB defaults in the range 0x000000..0xFFFFFF.
     *
     * @throws InvalidArgumentException If an unknown palette key or invalid RGB value is supplied.
     */
    protected function RegisterIPSViewColorProperties(array $defaults = []): void
    {
        foreach ($this->NormalizeIPSViewColorDefaults($defaults) as $key => $value) {
            $this->RegisterPropertyInteger(self::IPSVIEW_COLOR_PROPERTY_NAMES[$key], $value);
        }
    }

    /**
     * Returns reusable configuration-form items for the nine color properties.
     *
     * The captions remain plain strings so the consuming module can translate
     * them through its own locale.json file.
     *
     * @return array<int,array<string,mixed>> Symcon configuration-form items.
     *
     * @throws InvalidArgumentException If the requested control width is empty.
     */
    protected function IPSViewColorFormItems(string $width = '250px'): array
    {
        $width = trim($width);
        if ($width === '') {
            throw new InvalidArgumentException('IPSView color-control width must not be empty.');
        }

        $rows = [];
        foreach (array_chunk(array_keys(self::IPSVIEW_COLOR_PROPERTY_NAMES), 3) as $keys) {
            $items = [];
            foreach ($keys as $key) {
                $items[] = [
                    'type'             => 'SelectColor',
                    'name'             => self::IPSVIEW_COLOR_PROPERTY_NAMES[$key],
                    'caption'          => self::IPSVIEW_COLOR_CAPTIONS[$key],
                    'allowTransparent' => false,
                    'width'            => $width
                ];
            }

            $rows[] = [
                'type'  => 'RowLayout',
                'items' => $items
            ];
        }

        return [
            [
                'type'    => 'Label',
                'caption' => 'Choose the IPSView palette directly. The colors are stored in the module configuration.'
            ],
            ...$rows,
            [
                'type'    => 'Label',
                'caption' => 'Card surfaces and text colors are corrected only when needed for readable contrast. Module-specific opacity and gradients remain unchanged.'
            ]
        ];
    }

    /**
     * Reads the configured IPSView palette as normalized CSS hex colors.
     *
     * @return array<string,string> Configured colors keyed by Page, Surface, SurfaceStrong, Text,
     *                              MutedText, Accent, Success, Warning and Danger.
     */
    protected function IPSViewColorPalette(): array
    {
        $palette = [];
        foreach (self::IPSVIEW_COLOR_PROPERTY_NAMES as $key => $propertyName) {
            $value = $this->ReadPropertyInteger($propertyName);
            if (!$this->IsValidIPSViewColorValue($value)) {
                $value = self::IPSVIEW_COLOR_DEFAULTS[$key];
            }

            $palette[$key] = $this->IPSViewColorIntegerToHex($value);
        }

        return $palette;
    }

    /**
     * Resolves the configured colors into contrast-safe CSS values.
     *
     * The three surface colors are adjusted together so their relative visual
     * hierarchy is preserved. Text colors are adjusted only when surface
     * correction alone cannot satisfy the target contrast.
     *
     * @return array<string,string> Resolved palette including derived surface, text, border and soft status colors.
     */
    protected function IPSViewResolvedColorPalette(): array
    {
        $configured = $this->IPSViewColorPalette();
        $page = $this->IPSViewHexToRGB($configured['Page']);
        $configuredText = $this->IPSViewHexToRGB($configured['Text']);
        $configuredMuted = $this->IPSViewHexToRGB($configured['MutedText']);
        $configuredSurfaces = [
            $this->IPSViewHexToRGB($configured['Surface']),
            $this->IPSViewHexToRGB($configured['SurfaceStrong']),
            $this->IPSViewMixRGB($this->IPSViewHexToRGB($configured['Surface']), $page, 0.24)
        ];
        [$surface, $surfaceStrong, $surfaceSoft] = $this->IPSViewAdjustBackgroundsForContrast(
            $configuredSurfaces,
            [$configuredText, $configuredMuted],
            self::IPSVIEW_TEXT_CONTRAST_RATIO
        );
        $backgrounds = [$surface, $surfaceStrong, $surfaceSoft];
        $text = $this->IPSViewEnsureForegroundContrast(
            $configuredText,
            $backgrounds,
            self::IPSVIEW_TEXT_CONTRAST_RATIO
        );
        $muted = $this->IPSViewEnsureForegroundContrast(
            $configuredMuted,
            $backgrounds,
            self::IPSVIEW_TEXT_CONTRAST_RATIO
        );
        $faint = $this->IPSViewEnsureForegroundContrast(
            $this->IPSViewMixRGB($muted, $surface, 0.18),
            $backgrounds,
            self::IPSVIEW_FAINT_CONTRAST_RATIO
        );
        $accent = $this->IPSViewHexToRGB($configured['Accent']);
        $success = $this->IPSViewHexToRGB($configured['Success']);
        $warning = $this->IPSViewHexToRGB($configured['Warning']);
        $danger = $this->IPSViewHexToRGB($configured['Danger']);

        return [
            'ColorScheme'   => $this->IPSViewRelativeLuminance($surface) >= 0.40 ? 'light' : 'dark',
            'Page'          => $this->IPSViewRGBToCSS($page),
            'Surface'       => $this->IPSViewRGBToCSS($surface),
            'SurfaceStrong' => $this->IPSViewRGBToCSS($surfaceStrong),
            'SurfaceSoft'   => $this->IPSViewRGBToCSS($surfaceSoft),
            'Text'          => $this->IPSViewRGBToCSS($text),
            'MutedText'     => $this->IPSViewRGBToCSS($muted),
            'FaintText'     => $this->IPSViewRGBToCSS($faint),
            'Border'        => $this->IPSViewRGBToCSS($text, 0.16),
            'Accent'        => $this->IPSViewRGBToCSS($accent),
            'AccentSoft'    => $this->IPSViewRGBToCSS($accent, 0.22),
            'Success'       => $this->IPSViewRGBToCSS($success),
            'SuccessSoft'   => $this->IPSViewRGBToCSS($success, 0.17),
            'SuccessBorder' => $this->IPSViewRGBToCSS($success, 0.34),
            'Warning'       => $this->IPSViewRGBToCSS($warning),
            'WarningSoft'   => $this->IPSViewRGBToCSS($warning, 0.18),
            'Danger'        => $this->IPSViewRGBToCSS($danger),
            'DangerSoft'    => $this->IPSViewRGBToCSS($danger, 0.18)
        ];
    }

    /**
     * Renders the resolved IPSView palette as reusable CSS custom properties.
     *
     * @param bool   $transparent True to expose a transparent page background token.
     * @param string $selector    CSS selector receiving the variables.
     *
     * @return string CSS rule containing the shared --ipsview-* variables.
     *
     * @throws InvalidArgumentException If the selector is empty or contains rule delimiters.
     */
    protected function IPSViewColorCSSVariables(bool $transparent = false, string $selector = ':root'): string
    {
        $selector = trim($selector);
        if ($selector === '' || preg_match('/[{};]/', $selector) === 1) {
            throw new InvalidArgumentException('IPSView color CSS selector is invalid.');
        }

        $palette = $this->IPSViewResolvedColorPalette();
        $variables = [
            'color-scheme'                   => $palette['ColorScheme'],
            '--ipsview-page'                 => $palette['Page'],
            '--ipsview-background'           => $transparent ? 'transparent' : $palette['Page'],
            '--ipsview-surface'              => $palette['Surface'],
            '--ipsview-surface-strong'       => $palette['SurfaceStrong'],
            '--ipsview-surface-soft'         => $palette['SurfaceSoft'],
            '--ipsview-text'                 => $palette['Text'],
            '--ipsview-muted'                => $palette['MutedText'],
            '--ipsview-faint'                => $palette['FaintText'],
            '--ipsview-border'               => $palette['Border'],
            '--ipsview-accent'               => $palette['Accent'],
            '--ipsview-accent-soft'          => $palette['AccentSoft'],
            '--ipsview-success'              => $palette['Success'],
            '--ipsview-success-soft'         => $palette['SuccessSoft'],
            '--ipsview-success-border'       => $palette['SuccessBorder'],
            '--ipsview-warning'              => $palette['Warning'],
            '--ipsview-warning-soft'         => $palette['WarningSoft'],
            '--ipsview-danger'               => $palette['Danger'],
            '--ipsview-danger-soft'          => $palette['DangerSoft']
        ];

        $lines = [$selector . ' {'];
        foreach ($variables as $name => $value) {
            $lines[] = '    ' . $name . ': ' . $value . ';';
        }
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /** @param array<string,int> $defaults */
    private function NormalizeIPSViewColorDefaults(array $defaults): array
    {
        $normalized = self::IPSVIEW_COLOR_DEFAULTS;
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, self::IPSVIEW_COLOR_PROPERTY_NAMES)) {
                throw new InvalidArgumentException('Unknown IPSView palette key: ' . $key);
            }
            if (!$this->IsValidIPSViewColorValue($value)) {
                throw new InvalidArgumentException('IPSView color values must be integers between 0x000000 and 0xFFFFFF.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function IsValidIPSViewColorValue(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 0xFFFFFF;
    }

    private function IPSViewColorIntegerToHex(int $value): string
    {
        return sprintf('#%06X', $value);
    }

    /** @return array{red:float,green:float,blue:float} */
    private function IPSViewHexToRGB(string $color): array
    {
        return [
            'red'   => (float) hexdec(substr($color, 1, 2)),
            'green' => (float) hexdec(substr($color, 3, 2)),
            'blue'  => (float) hexdec(substr($color, 5, 2))
        ];
    }

    /**
     * @param array{red:float,green:float,blue:float} $first
     * @param array{red:float,green:float,blue:float} $second
     *
     * @return array{red:float,green:float,blue:float}
     */
    private function IPSViewMixRGB(array $first, array $second, float $amount): array
    {
        $ratio = max(0.0, min(1.0, $amount));

        return [
            'red'   => $first['red'] + (($second['red'] - $first['red']) * $ratio),
            'green' => $first['green'] + (($second['green'] - $first['green']) * $ratio),
            'blue'  => $first['blue'] + (($second['blue'] - $first['blue']) * $ratio)
        ];
    }

    /** @param array{red:float,green:float,blue:float} $color */
    private function IPSViewRGBToCSS(array $color, float $alpha = 1.0): string
    {
        $red = (int) round(max(0.0, min(255.0, $color['red'])));
        $green = (int) round(max(0.0, min(255.0, $color['green'])));
        $blue = (int) round(max(0.0, min(255.0, $color['blue'])));
        $alpha = max(0.0, min(1.0, $alpha));

        if ($alpha >= 0.999) {
            return sprintf('#%02X%02X%02X', $red, $green, $blue);
        }

        return sprintf('rgba(%d, %d, %d, %.3f)', $red, $green, $blue, $alpha);
    }

    /** @param array{red:float,green:float,blue:float} $color */
    private function IPSViewRelativeLuminance(array $color): float
    {
        $channel = static function (float $value): float
        {
            $normalized = $value / 255.0;

            return $normalized <= 0.03928
                ? $normalized / 12.92
                : (($normalized + 0.055) / 1.055) ** 2.4;
        };

        return (0.2126 * $channel($color['red']))
            + (0.7152 * $channel($color['green']))
            + (0.0722 * $channel($color['blue']));
    }

    /**
     * @param array{red:float,green:float,blue:float} $first
     * @param array{red:float,green:float,blue:float} $second
     */
    private function IPSViewContrastRatio(array $first, array $second): float
    {
        $firstLuminance = $this->IPSViewRelativeLuminance($first);
        $secondLuminance = $this->IPSViewRelativeLuminance($second);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * @param array{red:float,green:float,blue:float}            $foreground
     * @param array<int,array{red:float,green:float,blue:float}> $backgrounds
     */
    private function IPSViewMeetsContrast(array $foreground, array $backgrounds, float $minimumRatio): bool
    {
        foreach ($backgrounds as $background) {
            if ($this->IPSViewContrastRatio($foreground, $background) < $minimumRatio) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,array{red:float,green:float,blue:float}> $backgrounds
     * @param array<int,array{red:float,green:float,blue:float}> $foregrounds
     *
     * @return array<int,array{red:float,green:float,blue:float}>
     */
    private function IPSViewAdjustBackgroundsForContrast(
        array $backgrounds,
        array $foregrounds,
        float $minimumRatio
    ): array {
        $alreadyReadable = true;
        foreach ($backgrounds as $background) {
            foreach ($foregrounds as $foreground) {
                if ($this->IPSViewContrastRatio($foreground, $background) < $minimumRatio) {
                    $alreadyReadable = false;
                    break 2;
                }
            }
        }
        if ($alreadyReadable) {
            return $backgrounds;
        }

        $targets = [
            ['red' => 0.0, 'green' => 0.0, 'blue' => 0.0],
            ['red' => 255.0, 'green' => 255.0, 'blue' => 255.0]
        ];
        $bestMatch = null;
        $bestAmount = 2.0;

        foreach ($targets as $target) {
            for ($step = 1; $step <= 100; $step++) {
                $amount = $step / 100;
                $candidates = array_map(
                    fn (array $background): array => $this->IPSViewMixRGB($background, $target, $amount),
                    $backgrounds
                );
                $valid = true;
                foreach ($candidates as $candidate) {
                    foreach ($foregrounds as $foreground) {
                        if ($this->IPSViewContrastRatio($foreground, $candidate) < $minimumRatio) {
                            $valid = false;
                            break 2;
                        }
                    }
                }
                if (!$valid) {
                    continue;
                }

                if ($amount < $bestAmount) {
                    $bestMatch = $candidates;
                    $bestAmount = $amount;
                }
                break;
            }
        }

        return $bestMatch ?? $backgrounds;
    }

    /**
     * @param array{red:float,green:float,blue:float}            $foreground
     * @param array<int,array{red:float,green:float,blue:float}> $backgrounds
     *
     * @return array{red:float,green:float,blue:float}
     */
    private function IPSViewEnsureForegroundContrast(
        array $foreground,
        array $backgrounds,
        float $minimumRatio
    ): array {
        if ($this->IPSViewMeetsContrast($foreground, $backgrounds, $minimumRatio)) {
            return $foreground;
        }

        $targets = [
            ['red' => 0.0, 'green' => 0.0, 'blue' => 0.0],
            ['red' => 255.0, 'green' => 255.0, 'blue' => 255.0]
        ];
        $bestMatch = null;
        $bestAmount = 2.0;

        foreach ($targets as $target) {
            for ($step = 1; $step <= 100; $step++) {
                $amount = $step / 100;
                $candidate = $this->IPSViewMixRGB($foreground, $target, $amount);
                if (!$this->IPSViewMeetsContrast($candidate, $backgrounds, $minimumRatio)) {
                    continue;
                }

                if ($amount < $bestAmount) {
                    $bestMatch = $candidate;
                    $bestAmount = $amount;
                }
                break;
            }
        }

        return $bestMatch ?? $foreground;
    }
}
