<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;

/**
 * Provides a reusable IPSView style source, form controls and CSS tokens.
 *
 * The helper owns all common visual values for standalone IPSView HTML pages.
 * Consumers assign semantic roles such as positive, warning or critical to
 * their components, but do not define module-specific colors, gradients,
 * typography, borders or shadows.
 *
 * @version 1.0.0
 */
trait IPSViewStyleHelper
{
    public const IPSVIEW_STYLE_SOURCE_CUSTOM = 0;
    public const IPSVIEW_STYLE_SOURCE_MEDIA = 1;
    public const IPSVIEW_STYLE_SOURCE_LIGHT = 2;
    public const IPSVIEW_STYLE_SOURCE_DARK = 3;

    /** @var array<string,string> */
    private const IPSVIEW_STYLE_COLOR_PROPERTIES = [
        'ViewBackground'            => 'IPSViewStyleViewBackgroundColor',
        'PageBackground'            => 'IPSViewStylePageBackgroundColor',
        'LabelBackground'           => 'IPSViewStyleLabelBackgroundColor',
        'ControlBackground'         => 'IPSViewStyleControlBackgroundColor',
        'ControlActiveBackground'   => 'IPSViewStyleControlActiveBackgroundColor',
        'ControlInactiveBackground' => 'IPSViewStyleControlInactiveBackgroundColor',
        'Text'                      => 'IPSViewStyleTextColor',
        'TextActive'                => 'IPSViewStyleTextActiveColor',
        'TextInactive'              => 'IPSViewStyleTextInactiveColor',
        'LabelText'                 => 'IPSViewStyleLabelTextColor',
        'Icon'                      => 'IPSViewStyleIconColor',
        'Border'                    => 'IPSViewStyleBorderColor',
        'Line'                      => 'IPSViewStyleLineColor',
        'PopupBackground'           => 'IPSViewStylePopupBackgroundColor',
        'PopupBorder'               => 'IPSViewStylePopupBorderColor',
        'Accent'                    => 'IPSViewStyleAccentColor',
        'Information'               => 'IPSViewStyleInformationColor',
        'Positive'                  => 'IPSViewStylePositiveColor',
        'Warning'                   => 'IPSViewStyleWarningColor',
        'Critical'                  => 'IPSViewStyleCriticalColor',
        'Shadow'                    => 'IPSViewStyleShadowColor'
    ];

    /** @var array<string,int> */
    private const IPSVIEW_STYLE_CUSTOM_DEFAULTS = [
        'ViewBackground'            => 0xF4F5F7,
        'PageBackground'            => 0xF4F5F7,
        'LabelBackground'           => 0xFFFFFF,
        'ControlBackground'         => 0xFFFFFF,
        'ControlActiveBackground'   => 0xE9EDF2,
        'ControlInactiveBackground' => 0xF1F3F4,
        'Text'                      => 0x202124,
        'TextActive'                => 0x202124,
        'TextInactive'              => 0x6F7378,
        'LabelText'                 => 0x202124,
        'Icon'                      => 0x5F6368,
        'Border'                    => 0xC6CBD2,
        'Line'                      => 0xD8DDE4,
        'PopupBackground'           => 0xFFFFFF,
        'PopupBorder'               => 0xC6CBD2,
        'Accent'                    => 0x55CBB5,
        'Information'               => 0x4A90E2,
        'Positive'                  => 0x56C881,
        'Warning'                   => 0xE6A93F,
        'Critical'                  => 0xE36D6D,
        'Shadow'                    => 0x000000
    ];

    /** @var array<string,string> */
    private const IPSVIEW_STYLE_COLOR_CAPTIONS = [
        'ViewBackground'            => 'View background',
        'PageBackground'            => 'Page background',
        'LabelBackground'           => 'Label background',
        'ControlBackground'         => 'Control background',
        'ControlActiveBackground'   => 'Active control background',
        'ControlInactiveBackground' => 'Inactive control background',
        'Text'                      => 'Primary text',
        'TextActive'                => 'Active text',
        'TextInactive'              => 'Inactive text',
        'LabelText'                 => 'Label text',
        'Icon'                      => 'Icon color',
        'Border'                    => 'Border color',
        'Line'                      => 'Line color',
        'PopupBackground'           => 'Popup background',
        'PopupBorder'               => 'Popup border',
        'Accent'                    => 'Accent color',
        'Information'               => 'Information color',
        'Positive'                  => 'Positive status',
        'Warning'                   => 'Warning status',
        'Critical'                  => 'Critical status',
        'Shadow'                    => 'Shadow color'
    ];

    /** @var array<string,mixed> */
    private const IPSVIEW_STYLE_LIGHT_PRESET = [
        'ViewBackground'            => '#F4F5F7',
        'PageBackground'            => '#F4F5F7',
        'LabelBackground'           => 'rgba(255, 255, 255, 0.000)',
        'ControlBackground'         => '#FFFFFF',
        'ControlActiveBackground'   => '#E9EDF2',
        'ControlInactiveBackground' => '#F1F3F4',
        'Text'                      => '#202124',
        'TextActive'                => '#202124',
        'TextInactive'              => '#6F7378',
        'LabelText'                 => '#202124',
        'Icon'                      => '#5F6368',
        'Border'                    => '#C6CBD2',
        'Line'                      => '#D8DDE4',
        'PopupBackground'           => '#FFFFFF',
        'PopupBorder'               => '#C6CBD2',
        'PopupShadow'               => 'rgba(0, 0, 0, 0.320)',
        'Accent'                    => '#55CBB5',
        'Information'               => '#4A90E2',
        'Positive'                  => '#56C881',
        'Warning'                   => '#E6A93F',
        'Critical'                  => '#E36D6D',
        'FontFamily'                => '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
        'FontSize'                  => 16.0,
        'BorderRadius'              => 8.0,
        'BorderWidth'               => 1.0,
        'LineWidth'                 => 1.0,
        'ShadowColor'               => 'rgba(0, 0, 0, 0.180)',
        'ShadowBlur'                => 18.0,
        'ShadowSpread'              => 0.0,
        'ShadowOffsetX'             => 0.0,
        'ShadowOffsetY'             => 8.0
    ];

    /** @var array<string,mixed> */
    private const IPSVIEW_STYLE_DARK_PRESET = [
        'ViewBackground'            => '#101722',
        'PageBackground'            => '#101722',
        'LabelBackground'           => 'rgba(255, 255, 255, 0.000)',
        'ControlBackground'         => '#1B2639',
        'ControlActiveBackground'   => '#25334A',
        'ControlInactiveBackground' => '#182235',
        'Text'                      => '#F1F5F9',
        'TextActive'                => '#FFFFFF',
        'TextInactive'              => '#8A97AA',
        'LabelText'                 => '#F1F5F9',
        'Icon'                      => '#A7B4C6',
        'Border'                    => '#3B4A61',
        'Line'                      => '#334157',
        'PopupBackground'           => '#1B2639',
        'PopupBorder'               => '#46566E',
        'PopupShadow'               => 'rgba(0, 0, 0, 0.520)',
        'Accent'                    => '#78A4FF',
        'Information'               => '#64B5F6',
        'Positive'                  => '#63DC92',
        'Warning'                   => '#FFC15F',
        'Critical'                  => '#FF7B7B',
        'FontFamily'                => '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
        'FontSize'                  => 16.0,
        'BorderRadius'              => 8.0,
        'BorderWidth'               => 1.0,
        'LineWidth'                 => 1.0,
        'ShadowColor'               => 'rgba(0, 0, 0, 0.320)',
        'ShadowBlur'                => 20.0,
        'ShadowSpread'              => 0.0,
        'ShadowOffsetX'             => 0.0,
        'ShadowOffsetY'             => 10.0
    ];

    private const IPSVIEW_STYLE_TEXT_CONTRAST = 4.5;
    private const IPSVIEW_STYLE_INACTIVE_TEXT_CONTRAST = 3.0;

    /** Registers the complete shared IPSView style configuration. */
    protected function RegisterIPSViewStyleProperties(): void
    {
        $this->RegisterPropertyInteger('IPSViewStyleSource', self::IPSVIEW_STYLE_SOURCE_CUSTOM);
        $this->RegisterPropertyInteger('IPSViewStyleMediaID', 0);
        $this->RegisterPropertyBoolean('IPSViewStyleTransparentBackground', false);
        $this->RegisterPropertyInteger('IPSViewStyleFontScale', 100);

        foreach (self::IPSVIEW_STYLE_COLOR_PROPERTIES as $key => $propertyName) {
            $this->RegisterPropertyInteger($propertyName, self::IPSVIEW_STYLE_CUSTOM_DEFAULTS[$key]);
        }

        $this->RegisterPropertyString('IPSViewStyleFontFamily', '');
        $this->RegisterPropertyInteger('IPSViewStyleBaseFontSize', 16);
        $this->RegisterPropertyFloat('IPSViewStyleBorderRadius', 8.0);
        $this->RegisterPropertyFloat('IPSViewStyleBorderWidth', 1.0);
        $this->RegisterPropertyFloat('IPSViewStyleLineWidth', 1.0);
        $this->RegisterPropertyFloat('IPSViewStyleShadowBlur', 18.0);
        $this->RegisterPropertyFloat('IPSViewStyleShadowSpread', 0.0);
        $this->RegisterPropertyFloat('IPSViewStyleShadowOffsetX', 0.0);
        $this->RegisterPropertyFloat('IPSViewStyleShadowOffsetY', 8.0);
        $this->RegisterPropertyInteger('IPSViewStyleDisabledOpacity', 52);
        $this->RegisterPropertyInteger('IPSViewStyleGradientStrength', 28);
        $this->RegisterAttributeInteger('IPSViewStyleRegisteredMediaID', 0);
    }

    /**
     * Returns the complete, module-independent IPSView style form.
     *
     * @return array<int,array<string,mixed>> Symcon configuration-form items.
     *
     * @throws InvalidArgumentException If the requested color-control width is empty.
     */
    protected function IPSViewStyleFormItems(string $colorWidth = '240px'): array
    {
        $colorWidth = trim($colorWidth);
        if ($colorWidth === '') {
            throw new InvalidArgumentException('IPSView style color-control width must not be empty.');
        }

        $items = [
            [
                'type'    => 'Label',
                'caption' => 'Choose a shared IPSView style source. The same roles and effects are used by every consuming module.'
            ],
            [
                'type'  => 'RowLayout',
                'items' => [
                    [
                        'type'    => 'Select',
                        'name'    => 'IPSViewStyleSource',
                        'caption' => 'Style source',
                        'options' => [
                            ['caption' => 'Custom style', 'value' => self::IPSVIEW_STYLE_SOURCE_CUSTOM],
                            ['caption' => 'IPSView standard style', 'value' => self::IPSVIEW_STYLE_SOURCE_MEDIA],
                            ['caption' => 'Light preset', 'value' => self::IPSVIEW_STYLE_SOURCE_LIGHT],
                            ['caption' => 'Dark preset', 'value' => self::IPSVIEW_STYLE_SOURCE_DARK]
                        ],
                        'width' => '220px'
                    ],
                    [
                        'type'    => 'SelectMedia',
                        'name'    => 'IPSViewStyleMediaID',
                        'caption' => 'IPSView media object',
                        'width'   => '320px'
                    ],
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'IPSViewStyleTransparentBackground',
                        'caption' => 'Transparent background'
                    ],
                    [
                        'type'    => 'NumberSpinner',
                        'name'    => 'IPSViewStyleFontScale',
                        'caption' => 'Font scale (%)',
                        'minimum' => 60,
                        'maximum' => 200,
                        'suffix'  => ' %',
                        'width'   => '140px'
                    ]
                ]
            ],
            [
                'type'    => 'Label',
                'caption' => 'The media source imports the whitelisted standard style from an IPSView media object. Custom values below are used only for the custom source.'
            ],
            [
                'type'    => 'Label',
                'caption' => 'Universal colors'
            ]
        ];

        foreach (array_chunk(array_keys(self::IPSVIEW_STYLE_COLOR_PROPERTIES), 3) as $keys) {
            $row = [];
            foreach ($keys as $key) {
                $row[] = [
                    'type'             => 'SelectColor',
                    'name'             => self::IPSVIEW_STYLE_COLOR_PROPERTIES[$key],
                    'caption'          => self::IPSVIEW_STYLE_COLOR_CAPTIONS[$key],
                    'allowTransparent' => false,
                    'width'            => $colorWidth
                ];
            }

            $items[] = [
                'type'  => 'RowLayout',
                'items' => $row
            ];
        }

        $items[] = [
            'type'    => 'Label',
            'caption' => 'Typography, borders and effects'
        ];
        $items[] = [
            'type'  => 'RowLayout',
            'items' => [
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'IPSViewStyleFontFamily',
                    'caption' => 'Font family',
                    'width'   => '300px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleBaseFontSize',
                    'caption' => 'Base font size',
                    'minimum' => 8,
                    'maximum' => 32,
                    'suffix'  => ' px',
                    'width'   => '140px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleBorderRadius',
                    'caption' => 'Border radius',
                    'minimum' => 0,
                    'maximum' => 40,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '140px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleBorderWidth',
                    'caption' => 'Border width',
                    'minimum' => 0,
                    'maximum' => 10,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '140px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleLineWidth',
                    'caption' => 'Line width',
                    'minimum' => 0,
                    'maximum' => 10,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '140px'
                ]
            ]
        ];
        $items[] = [
            'type'  => 'RowLayout',
            'items' => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleShadowBlur',
                    'caption' => 'Shadow blur',
                    'minimum' => 0,
                    'maximum' => 80,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '140px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleShadowSpread',
                    'caption' => 'Shadow spread',
                    'minimum' => -20,
                    'maximum' => 40,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '140px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleShadowOffsetX',
                    'caption' => 'Shadow offset X',
                    'minimum' => -40,
                    'maximum' => 40,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '150px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleShadowOffsetY',
                    'caption' => 'Shadow offset Y',
                    'minimum' => -40,
                    'maximum' => 40,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '150px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleDisabledOpacity',
                    'caption' => 'Inactive opacity',
                    'minimum' => 10,
                    'maximum' => 100,
                    'suffix'  => ' %',
                    'width'   => '150px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleGradientStrength',
                    'caption' => 'Gradient strength',
                    'minimum' => 0,
                    'maximum' => 80,
                    'suffix'  => ' %',
                    'width'   => '150px'
                ]
            ]
        ];

        return $items;
    }

    /** Returns the configured IPSView style source. */
    protected function IPSViewStyleSource(): int
    {
        $source = $this->ReadPropertyInteger('IPSViewStyleSource');

        return in_array($source, [
            self::IPSVIEW_STYLE_SOURCE_CUSTOM,
            self::IPSVIEW_STYLE_SOURCE_MEDIA,
            self::IPSVIEW_STYLE_SOURCE_LIGHT,
            self::IPSVIEW_STYLE_SOURCE_DARK
        ], true) ? $source : self::IPSVIEW_STYLE_SOURCE_CUSTOM;
    }

    /** Returns the selected IPSView media object ID, or zero. */
    protected function IPSViewStyleMediaID(): int
    {
        return max(0, $this->ReadPropertyInteger('IPSViewStyleMediaID'));
    }

    /**
     * Registers update messages for the selected IPSView style media object.
     *
     * Consumers call this from ApplyChanges(). The method is intentionally a
     * no-op when the media source is not active or no valid media is selected.
     */
    protected function RegisterIPSViewStyleMediaMessages(): void
    {
        $message = defined('MM_UPDATE') ? constant('MM_UPDATE') : 10905;
        $registeredMediaID = $this->ReadAttributeInteger('IPSViewStyleRegisteredMediaID');
        $mediaID = $this->IPSViewStyleSource() === self::IPSVIEW_STYLE_SOURCE_MEDIA
            ? $this->IPSViewStyleMediaID()
            : 0;

        if ($registeredMediaID > 0 && $registeredMediaID !== $mediaID) {
            $this->UnregisterMessage($registeredMediaID, $message);
        }
        if ($mediaID > 0 && $mediaID !== $registeredMediaID) {
            $this->RegisterMessage($mediaID, $message);
        }
        if ($registeredMediaID !== $mediaID) {
            $this->WriteAttributeInteger('IPSViewStyleRegisteredMediaID', $mediaID);
        }
    }

    /** Returns true when a message belongs to the active IPSView style media. */
    protected function IsIPSViewStyleMediaUpdate(int $senderID, int $message): bool
    {
        $updateMessage = defined('MM_UPDATE') ? constant('MM_UPDATE') : 10905;

        return $this->IPSViewStyleSource() === self::IPSVIEW_STYLE_SOURCE_MEDIA
            && $senderID === $this->IPSViewStyleMediaID()
            && $message === $updateMessage;
    }

    /**
     * Resolves the active style source into universal, contrast-safe tokens.
     *
     * The optional document accepts raw JSON or base64-encoded JSON and is
     * primarily useful for tests. When omitted, the selected media object is
     * read through IPS_GetMediaContent().
     *
     * @return array<string,string|float> Resolved universal style values.
     */
    protected function IPSViewResolvedStyle(?string $document = null): array
    {
        $source = $this->IPSViewStyleSource();
        if ($source === self::IPSVIEW_STYLE_SOURCE_MEDIA) {
            $mediaStyle = $this->IPSViewStyleFromDocument($document ?? $this->ReadIPSViewStyleMediaContent());
            $base = $mediaStyle ?? self::IPSVIEW_STYLE_LIGHT_PRESET;
        } elseif ($source === self::IPSVIEW_STYLE_SOURCE_LIGHT) {
            $base = self::IPSVIEW_STYLE_LIGHT_PRESET;
        } elseif ($source === self::IPSVIEW_STYLE_SOURCE_DARK) {
            $base = self::IPSVIEW_STYLE_DARK_PRESET;
        } else {
            $base = $this->IPSViewCustomStyle();
        }

        return $this->IPSViewFinalizeStyle($base);
    }

    /**
     * Renders the active style as universal CSS custom properties.
     *
     * @throws InvalidArgumentException If the selector is empty or contains rule delimiters.
     */
    protected function IPSViewStyleCSSVariables(string $selector = ':root', ?string $document = null): string
    {
        $selector = trim($selector);
        if ($selector === '' || preg_match('/[{};]/', $selector) === 1) {
            throw new InvalidArgumentException('IPSView style CSS selector is invalid.');
        }

        $style = $this->IPSViewResolvedStyle($document);
        $transparent = $this->ReadPropertyBoolean('IPSViewStyleTransparentBackground');
        $fontScale = max(60, min(200, $this->ReadPropertyInteger('IPSViewStyleFontScale'))) / 100;
        $variables = [
            'color-scheme'                                => $style['ColorScheme'],
            '--ipsview-view-background'                   => $style['ViewBackground'],
            '--ipsview-page-background'                   => $style['PageBackground'],
            '--ipsview-background'                        => $transparent ? 'transparent' : $style['ViewBackground'],
            '--ipsview-label-background'                  => $style['LabelBackground'],
            '--ipsview-control-background'                => $style['ControlBackground'],
            '--ipsview-control-background-active'         => $style['ControlActiveBackground'],
            '--ipsview-control-background-inactive'       => $style['ControlInactiveBackground'],
            '--ipsview-control-background-soft'           => $style['ControlSoftBackground'],
            '--ipsview-popup-background'                  => $style['PopupBackground'],
            '--ipsview-text'                              => $style['Text'],
            '--ipsview-text-active'                       => $style['TextActive'],
            '--ipsview-text-inactive'                     => $style['TextInactive'],
            '--ipsview-text-label'                        => $style['LabelText'],
            '--ipsview-text-secondary'                    => $style['TextSecondary'],
            '--ipsview-text-faint'                        => $style['TextFaint'],
            '--ipsview-icon'                              => $style['Icon'],
            '--ipsview-border'                            => $style['Border'],
            '--ipsview-line'                              => $style['Line'],
            '--ipsview-popup-border'                      => $style['PopupBorder'],
            '--ipsview-accent'                            => $style['Accent'],
            '--ipsview-information'                       => $style['Information'],
            '--ipsview-positive'                          => $style['Positive'],
            '--ipsview-warning'                           => $style['Warning'],
            '--ipsview-critical'                          => $style['Critical'],
            '--ipsview-accent-soft'                       => $style['AccentSoft'],
            '--ipsview-information-soft'                  => $style['InformationSoft'],
            '--ipsview-positive-soft'                     => $style['PositiveSoft'],
            '--ipsview-warning-soft'                      => $style['WarningSoft'],
            '--ipsview-critical-soft'                     => $style['CriticalSoft'],
            '--ipsview-accent-contrast'                   => $style['AccentContrast'],
            '--ipsview-information-contrast'              => $style['InformationContrast'],
            '--ipsview-positive-contrast'                 => $style['PositiveContrast'],
            '--ipsview-warning-contrast'                  => $style['WarningContrast'],
            '--ipsview-critical-contrast'                 => $style['CriticalContrast'],
            '--ipsview-gradient-accent'                   => $style['GradientAccent'],
            '--ipsview-gradient-information'              => $style['GradientInformation'],
            '--ipsview-gradient-positive'                 => $style['GradientPositive'],
            '--ipsview-gradient-warning'                  => $style['GradientWarning'],
            '--ipsview-gradient-critical'                 => $style['GradientCritical'],
            '--ipsview-font-family'                       => $style['FontFamily'],
            '--ipsview-font-size'                         => $this->IPSViewFormatNumber((float) $style['FontSize']) . 'px',
            '--ipsview-font-scale'                        => $this->IPSViewFormatNumber($fontScale),
            '--ipsview-radius'                            => $this->IPSViewFormatNumber((float) $style['BorderRadius']) . 'px',
            '--ipsview-border-width'                      => $this->IPSViewFormatNumber((float) $style['BorderWidth']) . 'px',
            '--ipsview-line-width'                        => $this->IPSViewFormatNumber((float) $style['LineWidth']) . 'px',
            '--ipsview-disabled-opacity'                  => $this->IPSViewFormatNumber((float) $style['DisabledOpacity']),
            '--ipsview-shadow'                            => $style['Shadow'],
            '--ipsview-popup-shadow'                      => $style['PopupShadow'],
            '--ipsview-page'                              => $style['PageBackground'],
            '--ipsview-surface'                           => $style['ControlBackground'],
            '--ipsview-surface-strong'                    => $style['ControlActiveBackground'],
            '--ipsview-surface-soft'                      => $style['ControlSoftBackground'],
            '--ipsview-muted'                             => $style['TextSecondary'],
            '--ipsview-faint'                             => $style['TextFaint'],
            '--ipsview-success'                           => $style['Positive'],
            '--ipsview-success-soft'                      => $style['PositiveSoft'],
            '--ipsview-success-border'                    => $style['PositiveBorder'],
            '--ipsview-danger'                            => $style['Critical'],
            '--ipsview-danger-soft'                       => $style['CriticalSoft']
        ];

        $lines = [$selector . ' {'];
        foreach ($variables as $name => $value) {
            $lines[] = '    ' . $name . ': ' . $value . ';';
        }
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /** Reads and decodes the selected IPSView media object. */
    protected function ReadIPSViewStyleMediaContent(): string
    {
        $mediaID = $this->IPSViewStyleMediaID();
        if ($mediaID <= 0 || !function_exists('IPS_GetMediaContent')) {
            return '';
        }
        if (function_exists('IPS_MediaExists') && !IPS_MediaExists($mediaID)) {
            return '';
        }

        $encoded = IPS_GetMediaContent($mediaID);
        if (!is_string($encoded) || $encoded === '') {
            return '';
        }

        $decoded = base64_decode($encoded, true);

        return $decoded === false ? '' : $decoded;
    }

    /** @return array<string,mixed> */
    private function IPSViewCustomStyle(): array
    {
        $style = [];
        foreach (self::IPSVIEW_STYLE_COLOR_PROPERTIES as $key => $propertyName) {
            $value = $this->ReadPropertyInteger($propertyName);
            if (!$this->IPSViewIsValidColorInteger($value)) {
                $value = self::IPSVIEW_STYLE_CUSTOM_DEFAULTS[$key];
            }
            $style[$key] = $this->IPSViewColorIntegerToHex($value);
        }

        $style['PopupShadow'] = $this->IPSViewColorWithAlpha($style['Shadow'], 0.32);
        $style['FontFamily'] = $this->IPSViewNormalizeFontFamily($this->ReadPropertyString('IPSViewStyleFontFamily'));
        $style['FontSize'] = (float) max(8, min(32, $this->ReadPropertyInteger('IPSViewStyleBaseFontSize')));
        $style['BorderRadius'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleBorderRadius'), 0.0, 40.0);
        $style['BorderWidth'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleBorderWidth'), 0.0, 10.0);
        $style['LineWidth'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleLineWidth'), 0.0, 10.0);
        $style['ShadowColor'] = $this->IPSViewColorWithAlpha($style['Shadow'], 0.24);
        $style['ShadowBlur'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleShadowBlur'), 0.0, 80.0);
        $style['ShadowSpread'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleShadowSpread'), -20.0, 40.0);
        $style['ShadowOffsetX'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleShadowOffsetX'), -40.0, 40.0);
        $style['ShadowOffsetY'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleShadowOffsetY'), -40.0, 40.0);

        return $style;
    }

    /** @return array<string,mixed>|null */
    private function IPSViewStyleFromDocument(string $document): ?array
    {
        $document = trim($document);
        if ($document === '') {
            return null;
        }

        $decoded = json_decode($document, true);
        if (!is_array($decoded)) {
            $raw = base64_decode($document, true);
            if ($raw === false) {
                return null;
            }
            $decoded = json_decode($raw, true);
        }
        if (!is_array($decoded)) {
            return null;
        }

        $fallback = self::IPSVIEW_STYLE_LIGHT_PRESET;
        $style = [
            'ViewBackground'            => $this->IPSViewDocumentColor($decoded, 'ColorPage', $fallback['ViewBackground']),
            'PageBackground'            => $this->IPSViewDocumentColor($decoded, 'ColorPage', $fallback['PageBackground']),
            'LabelBackground'           => $this->IPSViewDocumentColor($decoded, 'ColorBackLabel', $fallback['LabelBackground']),
            'ControlBackground'         => $this->IPSViewDocumentColor($decoded, 'ColorBack', $fallback['ControlBackground']),
            'ControlActiveBackground'   => $this->IPSViewDocumentColor($decoded, 'ColorBackOn', $fallback['ControlActiveBackground']),
            'ControlInactiveBackground' => $this->IPSViewDocumentColor($decoded, 'ColorBackOff', $fallback['ControlInactiveBackground']),
            'Text'                      => $this->IPSViewDocumentColor($decoded, 'ColorText', $fallback['Text']),
            'TextActive'                => $this->IPSViewDocumentColor($decoded, 'ColorTextOn', $fallback['TextActive']),
            'TextInactive'              => $this->IPSViewDocumentColor($decoded, 'ColorTextOff', $fallback['TextInactive']),
            'LabelText'                 => $this->IPSViewDocumentColor($decoded, 'ColorTextLabel', $fallback['LabelText']),
            'Icon'                      => $this->IPSViewDocumentColor($decoded, 'ColorIcon', $fallback['Icon']),
            'Border'                    => $this->IPSViewDocumentColor($decoded, 'ColorBorder', $fallback['Border']),
            'Line'                      => $this->IPSViewDocumentColor($decoded, 'ColorLine', $fallback['Line']),
            'PopupBackground'           => $this->IPSViewDocumentColor($decoded, 'ColorPopupBack', $fallback['PopupBackground']),
            'PopupBorder'               => $this->IPSViewDocumentColor($decoded, 'ColorPopupBorder', $fallback['PopupBorder']),
            'PopupShadow'               => $this->IPSViewDocumentColor($decoded, 'ColorPopupShadow', $fallback['PopupShadow']),
            'Accent'                    => $this->IPSViewFirstDocumentColor(
                $decoded,
                ['SliderTrackColorActive', 'SwitchTrackColorActive', 'DialogDateTimePrimaryColor', 'ColorBackOn'],
                $fallback['Accent']
            ),
            'Information'               => $this->IPSViewFirstDocumentColor(
                $decoded,
                ['CalendarTodayHighlightColor', 'DialogDateTimePrimaryColor', 'SliderTrackColorActive'],
                $fallback['Information']
            ),
            'Positive'                  => $this->IPSViewFavoriteColor($decoded, ['grün', 'gruen', 'green', 'positive', 'success'], $fallback['Positive']),
            'Warning'                   => $this->IPSViewFavoriteColor($decoded, ['gelb', 'yellow', 'warning', 'warnung'], $fallback['Warning']),
            'Critical'                  => $this->IPSViewFavoriteColor($decoded, ['rot', 'red', 'critical', 'kritisch', 'danger', 'alarm', 'fehler'], $fallback['Critical']),
            'FontFamily'                => $this->IPSViewNormalizeFontFamily(is_string($decoded['DefaultFontFamily'] ?? null) ? $decoded['DefaultFontFamily'] : ''),
            'FontSize'                  => $this->IPSViewDocumentNumber($decoded, 'DefaultFontSize', (float) $fallback['FontSize'], 8.0, 32.0),
            'BorderRadius'              => $this->IPSViewDocumentNumber($decoded, 'DefaultBorderRadius', (float) $fallback['BorderRadius'], 0.0, 40.0),
            'BorderWidth'               => $this->IPSViewDocumentNumber($decoded, 'DefaultBorderWidth', (float) $fallback['BorderWidth'], 0.0, 10.0),
            'LineWidth'                 => $this->IPSViewDocumentNumber($decoded, 'LineWidth', (float) $fallback['LineWidth'], 0.0, 10.0),
            'ShadowColor'               => $this->IPSViewDocumentColor($decoded, 'ShadowColor', $fallback['ShadowColor']),
            'ShadowBlur'                => $this->IPSViewDocumentNumber($decoded, 'ShadowBlurRadius', (float) $fallback['ShadowBlur'], 0.0, 80.0),
            'ShadowSpread'              => $this->IPSViewDocumentNumber($decoded, 'ShadowSpreadRadius', (float) $fallback['ShadowSpread'], -20.0, 40.0),
            'ShadowOffsetX'             => $this->IPSViewDocumentNumber($decoded, 'ShadowOffsetX', (float) $fallback['ShadowOffsetX'], -40.0, 40.0),
            'ShadowOffsetY'             => $this->IPSViewDocumentNumber($decoded, 'ShadowOffsetY', (float) $fallback['ShadowOffsetY'], -40.0, 40.0)
        ];

        return $style;
    }

    /** @param array<string,mixed> $style
     *  @return array<string,string|float>
     */
    private function IPSViewFinalizeStyle(array $style): array
    {
        $control = $this->IPSViewCSSColorToRGB((string) $style['ControlBackground']);
        $controlActive = $this->IPSViewCSSColorToRGB((string) $style['ControlActiveBackground']);
        $controlInactive = $this->IPSViewCSSColorToRGB((string) $style['ControlInactiveBackground']);
        $popup = $this->IPSViewCSSColorToRGB((string) $style['PopupBackground']);
        $label = $this->IPSViewCSSColorToRGB((string) $style['LabelBackground']);
        $page = $this->IPSViewCSSColorToRGB((string) $style['PageBackground']);
        $text = $this->IPSViewEnsureContrast(
            $this->IPSViewCSSColorToRGB((string) $style['Text']),
            [$control, $page],
            self::IPSVIEW_STYLE_TEXT_CONTRAST
        );
        $textActive = $this->IPSViewEnsureContrast(
            $this->IPSViewCSSColorToRGB((string) $style['TextActive']),
            [$controlActive],
            self::IPSVIEW_STYLE_TEXT_CONTRAST
        );
        $textInactive = $this->IPSViewEnsureContrast(
            $this->IPSViewCSSColorToRGB((string) $style['TextInactive']),
            [$controlInactive],
            self::IPSVIEW_STYLE_INACTIVE_TEXT_CONTRAST
        );
        $labelText = $this->IPSViewEnsureContrast(
            $this->IPSViewCSSColorToRGB((string) $style['LabelText']),
            [$label['alpha'] < 0.05 ? $page : $label],
            self::IPSVIEW_STYLE_TEXT_CONTRAST
        );
        $secondary = $this->IPSViewEnsureContrast(
            $this->IPSViewMixRGB($text, $control, 0.24),
            [$control, $controlActive],
            3.5
        );
        $faint = $this->IPSViewEnsureContrast(
            $this->IPSViewMixRGB($secondary, $control, 0.18),
            [$control, $controlActive],
            3.0
        );
        $soft = $this->IPSViewMixRGB($control, $this->IPSViewCSSColorToRGB((string) $style['PageBackground']), 0.20);
        $gradientStrength = max(0, min(80, $this->ReadPropertyInteger('IPSViewStyleGradientStrength'))) / 100;
        $disabledOpacity = max(10, min(100, $this->ReadPropertyInteger('IPSViewStyleDisabledOpacity'))) / 100;
        $shadowColor = $this->IPSViewCSSColorToRGB((string) $style['ShadowColor']);
        $popupShadowColor = $this->IPSViewCSSColorToRGB((string) $style['PopupShadow']);
        $shadow = $this->IPSViewBoxShadow(
            $shadowColor,
            (float) $style['ShadowOffsetX'],
            (float) $style['ShadowOffsetY'],
            (float) $style['ShadowBlur'],
            (float) $style['ShadowSpread']
        );
        $popupShadow = $this->IPSViewBoxShadow(
            $popupShadowColor,
            0.0,
            max(8.0, (float) $style['ShadowOffsetY']),
            max(18.0, (float) $style['ShadowBlur']),
            (float) $style['ShadowSpread']
        );

        $resolved = [
            'ColorScheme'               => $this->IPSViewRelativeLuminance($control) >= 0.40 ? 'light' : 'dark',
            'ViewBackground'            => (string) $style['ViewBackground'],
            'PageBackground'            => (string) $style['PageBackground'],
            'LabelBackground'           => (string) $style['LabelBackground'],
            'ControlBackground'         => (string) $style['ControlBackground'],
            'ControlActiveBackground'   => (string) $style['ControlActiveBackground'],
            'ControlInactiveBackground' => (string) $style['ControlInactiveBackground'],
            'ControlSoftBackground'     => $this->IPSViewRGBToCSS($soft),
            'PopupBackground'           => (string) $style['PopupBackground'],
            'Text'                      => $this->IPSViewRGBToCSS($text),
            'TextActive'                => $this->IPSViewRGBToCSS($textActive),
            'TextInactive'              => $this->IPSViewRGBToCSS($textInactive),
            'LabelText'                 => $this->IPSViewRGBToCSS($labelText),
            'TextSecondary'             => $this->IPSViewRGBToCSS($secondary),
            'TextFaint'                 => $this->IPSViewRGBToCSS($faint),
            'Icon'                      => (string) $style['Icon'],
            'Border'                    => (string) $style['Border'],
            'Line'                      => (string) $style['Line'],
            'PopupBorder'               => (string) $style['PopupBorder'],
            'FontFamily'                => (string) $style['FontFamily'],
            'FontSize'                  => (float) $style['FontSize'],
            'BorderRadius'              => (float) $style['BorderRadius'],
            'BorderWidth'               => (float) $style['BorderWidth'],
            'LineWidth'                 => (float) $style['LineWidth'],
            'DisabledOpacity'           => $disabledOpacity,
            'Shadow'                    => $shadow,
            'PopupShadow'               => $popupShadow
        ];

        foreach (['Accent', 'Information', 'Positive', 'Warning', 'Critical'] as $role) {
            $roleColor = $this->IPSViewCSSColorToRGB((string) $style[$role]);
            $resolved[$role] = $this->IPSViewRGBToCSS($roleColor);
            $resolved[$role . 'Soft'] = $this->IPSViewRGBToCSS($roleColor, 0.18);
            $resolved[$role . 'Border'] = $this->IPSViewRGBToCSS($roleColor, 0.36);
            $resolved[$role . 'Contrast'] = $this->IPSViewContrastText($roleColor);
            $resolved['Gradient' . $role] = $this->IPSViewGradient($roleColor, $gradientStrength);
        }

        return $resolved;
    }

    /** @param array<string,mixed> $document */
    private function IPSViewDocumentColor(array $document, string $key, string $fallback): string
    {
        return $this->IPSViewColorObjectToCSS($document[$key] ?? null) ?? $fallback;
    }

    /** @param array<string,mixed> $document
     *  @param array<int,string>   $keys
     */
    private function IPSViewFirstDocumentColor(array $document, array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            $color = $this->IPSViewColorObjectToCSS($document[$key] ?? null);
            if ($color !== null) {
                return $color;
            }
        }

        return $fallback;
    }

    /** @param array<string,mixed> $document
     *  @param array<int,string>   $keywords
     */
    private function IPSViewFavoriteColor(array $document, array $keywords, string $fallback): string
    {
        $colors = $document['Colors'] ?? null;
        if (!is_array($colors)) {
            return $fallback;
        }

        foreach ($colors as $color) {
            if (!is_array($color) || !is_string($color['Name'] ?? null)) {
                continue;
            }
            $name = $this->IPSViewNormalizeSearchText($color['Name']);
            foreach ($keywords as $keyword) {
                if (str_contains($name, $this->IPSViewNormalizeSearchText($keyword))) {
                    return $this->IPSViewColorObjectToCSS($color) ?? $fallback;
                }
            }
        }

        return $fallback;
    }

    /** @param array<string,mixed> $document */
    private function IPSViewDocumentNumber(
        array $document,
        string $key,
        float $fallback,
        float $minimum,
        float $maximum
    ): float {
        $value = $document[$key] ?? null;
        if (!is_int($value) && !is_float($value)) {
            return $fallback;
        }

        return $this->IPSViewClampFloat((float) $value, $minimum, $maximum);
    }

    private function IPSViewColorObjectToCSS(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }
        foreach (['A', 'R', 'G', 'B'] as $channel) {
            if (!isset($value[$channel]) || !is_int($value[$channel])) {
                return null;
            }
            if ($value[$channel] < 0 || $value[$channel] > 255) {
                return null;
            }
        }

        return $this->IPSViewRGBToCSS([
            'red'   => (float) $value['R'],
            'green' => (float) $value['G'],
            'blue'  => (float) $value['B'],
            'alpha' => $value['A'] / 255
        ], $value['A'] / 255);
    }

    private function IPSViewNormalizeSearchText(string $value): string
    {
        $value = strtolower(trim($value));

        return str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $value);
    }

    private function IPSViewNormalizeFontFamily(string $fontFamily): string
    {
        $fontFamily = trim($fontFamily);
        if ($fontFamily === '' || preg_match('/[{};]/', $fontFamily) === 1) {
            return '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        }

        return $fontFamily;
    }

    private function IPSViewIsValidColorInteger(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 0xFFFFFF;
    }

    private function IPSViewColorIntegerToHex(int $value): string
    {
        return sprintf('#%06X', $value);
    }

    private function IPSViewColorWithAlpha(string $color, float $alpha): string
    {
        return $this->IPSViewRGBToCSS($this->IPSViewCSSColorToRGB($color), $alpha);
    }

    /** @return array{red:float,green:float,blue:float,alpha:float} */
    private function IPSViewCSSColorToRGB(string $color): array
    {
        if (preg_match('/^#([0-9A-Fa-f]{6})$/', trim($color), $matches) === 1) {
            return [
                'red'   => (float) hexdec(substr($matches[1], 0, 2)),
                'green' => (float) hexdec(substr($matches[1], 2, 2)),
                'blue'  => (float) hexdec(substr($matches[1], 4, 2)),
                'alpha' => 1.0
            ];
        }
        if (preg_match('/^rgba\((\d+),\s*(\d+),\s*(\d+),\s*([0-9.]+)\)$/', trim($color), $matches) === 1) {
            return [
                'red'   => (float) max(0, min(255, (int) $matches[1])),
                'green' => (float) max(0, min(255, (int) $matches[2])),
                'blue'  => (float) max(0, min(255, (int) $matches[3])),
                'alpha' => max(0.0, min(1.0, (float) $matches[4]))
            ];
        }

        return ['red' => 0.0, 'green' => 0.0, 'blue' => 0.0, 'alpha' => 1.0];
    }

    /** @param array{red:float,green:float,blue:float,alpha?:float} $color */
    private function IPSViewRGBToCSS(array $color, ?float $alpha = null): string
    {
        $red = (int) round(max(0.0, min(255.0, $color['red'])));
        $green = (int) round(max(0.0, min(255.0, $color['green'])));
        $blue = (int) round(max(0.0, min(255.0, $color['blue'])));
        $alpha = max(0.0, min(1.0, $alpha ?? ($color['alpha'] ?? 1.0)));

        if ($alpha >= 0.999) {
            return sprintf('#%02X%02X%02X', $red, $green, $blue);
        }

        return sprintf('rgba(%d, %d, %d, %.3f)', $red, $green, $blue, $alpha);
    }

    /** @param array{red:float,green:float,blue:float,alpha?:float} $first
     *  @param array{red:float,green:float,blue:float,alpha?:float} $second
     *  @return array{red:float,green:float,blue:float,alpha:float}
     */
    private function IPSViewMixRGB(array $first, array $second, float $amount): array
    {
        $ratio = max(0.0, min(1.0, $amount));

        return [
            'red'   => $first['red'] + (($second['red'] - $first['red']) * $ratio),
            'green' => $first['green'] + (($second['green'] - $first['green']) * $ratio),
            'blue'  => $first['blue'] + (($second['blue'] - $first['blue']) * $ratio),
            'alpha' => ($first['alpha'] ?? 1.0) + ((($second['alpha'] ?? 1.0) - ($first['alpha'] ?? 1.0)) * $ratio)
        ];
    }

    /** @param array{red:float,green:float,blue:float,alpha?:float} $color */
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

    /** @param array{red:float,green:float,blue:float,alpha?:float} $first
     *  @param array{red:float,green:float,blue:float,alpha?:float} $second
     */
    private function IPSViewContrastRatio(array $first, array $second): float
    {
        $firstLuminance = $this->IPSViewRelativeLuminance($first);
        $secondLuminance = $this->IPSViewRelativeLuminance($second);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /** @param array{red:float,green:float,blue:float,alpha?:float} $foreground
     *  @param array<int,array{red:float,green:float,blue:float,alpha?:float}> $backgrounds
     *  @return array{red:float,green:float,blue:float,alpha:float}
     */
    private function IPSViewEnsureContrast(array $foreground, array $backgrounds, float $minimum): array
    {
        $readable = true;
        foreach ($backgrounds as $background) {
            if ($this->IPSViewContrastRatio($foreground, $background) < $minimum) {
                $readable = false;
                break;
            }
        }
        if ($readable) {
            return [
                'red'   => $foreground['red'],
                'green' => $foreground['green'],
                'blue'  => $foreground['blue'],
                'alpha' => 1.0
            ];
        }

        $black = ['red' => 0.0, 'green' => 0.0, 'blue' => 0.0, 'alpha' => 1.0];
        $white = ['red' => 255.0, 'green' => 255.0, 'blue' => 255.0, 'alpha' => 1.0];
        $blackRatio = min(array_map(fn (array $background): float => $this->IPSViewContrastRatio($black, $background), $backgrounds));
        $whiteRatio = min(array_map(fn (array $background): float => $this->IPSViewContrastRatio($white, $background), $backgrounds));
        $target = $blackRatio >= $whiteRatio ? $black : $white;

        for ($step = 1; $step <= 100; $step++) {
            $candidate = $this->IPSViewMixRGB($foreground, $target, $step / 100);
            $valid = true;
            foreach ($backgrounds as $background) {
                if ($this->IPSViewContrastRatio($candidate, $background) < $minimum) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                $candidate['alpha'] = 1.0;

                return $candidate;
            }
        }

        return $target;
    }

    /** @param array{red:float,green:float,blue:float,alpha?:float} $background */
    private function IPSViewContrastText(array $background): string
    {
        $black = ['red' => 0.0, 'green' => 0.0, 'blue' => 0.0, 'alpha' => 1.0];
        $white = ['red' => 255.0, 'green' => 255.0, 'blue' => 255.0, 'alpha' => 1.0];

        return $this->IPSViewContrastRatio($black, $background) >= $this->IPSViewContrastRatio($white, $background)
            ? '#000000'
            : '#FFFFFF';
    }

    /** @param array{red:float,green:float,blue:float,alpha?:float} $color */
    private function IPSViewGradient(array $color, float $strength): string
    {
        $start = $this->IPSViewRGBToCSS($color, $strength);
        $middle = $this->IPSViewRGBToCSS($color, $strength * 0.40);

        return 'linear-gradient(135deg, ' . $start . ' 0%, ' . $middle . ' 42%, transparent 78%)';
    }

    /** @param array{red:float,green:float,blue:float,alpha?:float} $color */
    private function IPSViewBoxShadow(
        array $color,
        float $offsetX,
        float $offsetY,
        float $blur,
        float $spread
    ): string {
        return $this->IPSViewFormatNumber($offsetX) . 'px '
            . $this->IPSViewFormatNumber($offsetY) . 'px '
            . $this->IPSViewFormatNumber($blur) . 'px '
            . $this->IPSViewFormatNumber($spread) . 'px '
            . $this->IPSViewRGBToCSS($color);
    }

    private function IPSViewClampFloat(float $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, $value));
    }

    private function IPSViewFormatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }
}
