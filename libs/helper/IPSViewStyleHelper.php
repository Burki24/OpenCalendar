<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;

require_once __DIR__ . '/HelperTranslationHelper.php';
require_once __DIR__ . '/IPSViewFontCatalogHelper.php';
require_once __DIR__ . '/IPSViewStylePresetHelper.php';
require_once __DIR__ . '/IPSViewStyleProfileHelper.php';

/**
 * Provides a reusable IPSView style source, form controls and CSS tokens.
 *
 * The helper owns all common visual values for standalone IPSView HTML pages.
 * Consumers assign semantic roles such as positive, warning or critical to
 * their components, but do not define module-specific colors, gradients,
 * typography, borders or shadows.
 *
 * @version 1.6.2
 */
trait IPSViewStyleHelper
{
    use HelperTranslationHelper;

    public const IPSVIEW_STYLE_SOURCE_CUSTOM = 0;
    public const IPSVIEW_STYLE_SOURCE_MEDIA = 1;
    public const IPSVIEW_STYLE_SOURCE_LIGHT = 2;
    public const IPSVIEW_STYLE_SOURCE_DARK = 3;
    public const IPSVIEW_STYLE_SOURCE_PROFILE = 4;
    /** Legacy generic preset source kept for stored configurations from v1.6.1. */
    public const IPSVIEW_STYLE_SOURCE_PRESET = 5;
    public const IPSVIEW_STYLE_SOURCE_PRESET_LIGHT = 6;
    public const IPSVIEW_STYLE_SOURCE_PRESET_DARK = 7;
    public const IPSVIEW_STYLE_SOURCE_PRESET_WARM = 8;
    public const IPSVIEW_STYLE_SOURCE_PRESET_COOL = 9;
    public const IPSVIEW_STYLE_SOURCE_PRESET_EARTHY = 10;
    public const IPSVIEW_STYLE_SOURCE_PRESET_WATER = 11;
    public const IPSVIEW_STYLE_SOURCE_PRESET_SUNNY = 12;

    private const IPSVIEW_STYLE_SYSTEM_FONT_FAMILY = '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

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

    /** @var array<string,string> */
    private const IPSVIEW_STYLE_OPACITY_PROPERTIES = [
        'ViewBackground'            => 'IPSViewStyleViewBackgroundOpacity',
        'PageBackground'            => 'IPSViewStylePageBackgroundOpacity',
        'LabelBackground'           => 'IPSViewStyleLabelBackgroundOpacity',
        'ControlBackground'         => 'IPSViewStyleControlBackgroundOpacity',
        'ControlActiveBackground'   => 'IPSViewStyleControlActiveBackgroundOpacity',
        'ControlInactiveBackground' => 'IPSViewStyleControlInactiveBackgroundOpacity',
        'PopupBackground'           => 'IPSViewStylePopupBackgroundOpacity',
        'Border'                    => 'IPSViewStyleBorderOpacity',
        'Line'                      => 'IPSViewStyleLineOpacity',
        'PopupBorder'               => 'IPSViewStylePopupBorderOpacity',
        'ShadowColor'               => 'IPSViewStyleShadowOpacity',
        'PopupShadow'               => 'IPSViewStylePopupShadowOpacity'
    ];

    /** @var array<string,int> */
    private const IPSVIEW_STYLE_OPACITY_DEFAULTS = [
        'ViewBackground'            => 100,
        'PageBackground'            => 100,
        'LabelBackground'           => 100,
        'ControlBackground'         => 100,
        'ControlActiveBackground'   => 100,
        'ControlInactiveBackground' => 100,
        'PopupBackground'           => 100,
        'Border'                    => 100,
        'Line'                      => 100,
        'PopupBorder'               => 100,
        'ShadowColor'               => 24,
        'PopupShadow'               => 32
    ];

    /** @var array<string,string> */
    private const IPSVIEW_STYLE_OPACITY_CAPTIONS = [
        'ViewBackground'            => 'opacity.view_background',
        'PageBackground'            => 'opacity.page_background',
        'LabelBackground'           => 'opacity.label_background',
        'ControlBackground'         => 'opacity.control_background',
        'ControlActiveBackground'   => 'opacity.control_active',
        'ControlInactiveBackground' => 'opacity.control_inactive',
        'PopupBackground'           => 'opacity.popup_background',
        'Border'                    => 'opacity.border',
        'Line'                      => 'opacity.line',
        'PopupBorder'               => 'opacity.popup_border',
        'ShadowColor'               => 'opacity.shadow',
        'PopupShadow'               => 'opacity.popup_shadow'
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
        'ViewBackground'            => 'color.view_background',
        'PageBackground'            => 'color.page_background',
        'LabelBackground'           => 'color.label_background',
        'ControlBackground'         => 'color.control_background',
        'ControlActiveBackground'   => 'color.control_active_background',
        'ControlInactiveBackground' => 'color.control_inactive_background',
        'Text'                      => 'color.text_primary',
        'TextActive'                => 'color.text_active',
        'TextInactive'              => 'color.text_inactive',
        'LabelText'                 => 'color.label_text',
        'Icon'                      => 'color.icon',
        'Border'                    => 'color.border',
        'Line'                      => 'color.line',
        'PopupBackground'           => 'color.popup_background',
        'PopupBorder'               => 'color.popup_border',
        'Accent'                    => 'color.accent',
        'Information'               => 'color.information',
        'Positive'                  => 'color.positive',
        'Warning'                   => 'color.warning',
        'Critical'                  => 'color.critical',
        'Shadow'                    => 'color.shadow'
    ];

    /** @var array<string,string> */
    private const IPSVIEW_STYLE_TRANSLATION_SOURCES = [
        'description.choose_source'         => 'Choose a shared IPSView style source. The same roles and effects are used by every consuming module.',
        'description.media_source'          => 'The media source imports the whitelisted standard style from an IPSView media object. Custom values below are used only for the custom source.',
        'description.profile_source'        => 'The style profile source loads a complete validated Style Profile V1 from a media object. Invalid profiles fall back safely to the light preset.',
        'description.preset_source'         => 'The centralized IPSView Assistant presets are selected directly from the style source list.',
        'section.universal_colors'          => 'Universal colors',
        'section.surface_transparency'      => 'Surface transparency',
        'section.typography_effects'        => 'Typography, borders and effects',
        'field.style_source'                => 'Style source',
        'field.media_object'                => 'IPSView media object',
        'field.profile_media_object'        => 'Style profile media object',
        'field.style_preset'                => 'Shared preset',
        'field.transparent_background'      => 'Transparent background',
        'field.font_scale'                  => 'Font scale (%)',
        'option.custom_style'               => 'Custom style',
        'option.ipsview_standard_style'     => 'IPSView standard style',
        'option.light_preset'               => 'Light preset',
        'option.dark_preset'                => 'Dark preset',
        'option.style_profile'              => 'Style profile',
        'option.shared_preset'              => 'Shared preset',
        'option.previous_selection'         => 'previous selection',
        'option.system_font'                => 'System default',
        'option.legacy_font'                => 'Legacy/custom',
        'preset.standard'                   => 'IPSView Standard',
        'preset.light'                      => 'Light',
        'preset.dark'                       => 'Dark',
        'preset.warm'                       => 'Warm',
        'preset.cool'                       => 'Cool',
        'preset.earthy'                     => 'Earthy',
        'preset.water'                      => 'Water',
        'preset.sunny'                      => 'Sunny',
        'color.view_background'             => 'View background',
        'color.page_background'             => 'Page background',
        'color.label_background'            => 'Label background',
        'color.control_background'          => 'Control background',
        'color.control_active_background'   => 'Active control background',
        'color.control_inactive_background' => 'Inactive control background',
        'color.text_primary'                => 'Primary text',
        'color.text_active'                 => 'Active text',
        'color.text_inactive'               => 'Inactive text',
        'color.label_text'                  => 'Label text',
        'color.icon'                        => 'Icon color',
        'color.border'                      => 'Border color',
        'color.line'                        => 'Line color',
        'color.popup_background'            => 'Popup background',
        'color.popup_border'                => 'Popup border',
        'color.accent'                      => 'Accent color',
        'color.information'                 => 'Information color',
        'color.positive'                    => 'Positive status',
        'color.warning'                     => 'Warning status',
        'color.critical'                    => 'Critical status',
        'color.shadow'                      => 'Shadow color',
        'opacity.view_background'           => 'View background opacity',
        'opacity.page_background'           => 'Page background opacity',
        'opacity.label_background'          => 'Label background opacity',
        'opacity.control_background'        => 'Control background opacity',
        'opacity.control_active'            => 'Active control opacity',
        'opacity.control_inactive'          => 'Inactive control opacity',
        'opacity.popup_background'          => 'Popup background opacity',
        'opacity.border'                    => 'Border opacity',
        'opacity.line'                      => 'Line opacity',
        'opacity.popup_border'              => 'Popup border opacity',
        'opacity.shadow'                    => 'Shadow opacity',
        'opacity.popup_shadow'              => 'Popup shadow opacity',
        'field.font_family'                 => 'Font family',
        'field.base_font_size'              => 'Base font size',
        'field.border_radius'               => 'Border radius',
        'field.border_width'                => 'Border width',
        'field.line_width'                  => 'Line width',
        'field.shadow_blur'                 => 'Shadow blur',
        'field.shadow_spread'               => 'Shadow spread',
        'field.shadow_offset_x'             => 'Shadow offset X',
        'field.shadow_offset_y'             => 'Shadow offset Y',
        'field.inactive_opacity'            => 'Inactive opacity',
        'field.gradient_strength'           => 'Gradient strength'
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
        'FontFamily'                => self::IPSVIEW_STYLE_SYSTEM_FONT_FAMILY,
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
        'FontFamily'                => self::IPSVIEW_STYLE_SYSTEM_FONT_FAMILY,
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

    private const IPSVIEW_STYLE_FORM_MARKER = 'Configure the shared IPSView style used by the standalone HTML page.';

    /** Registers the complete shared IPSView style configuration. */
    protected function RegisterIPSViewStyleProperties(): void
    {
        $this->RegisterPropertyInteger('IPSViewStyleSource', self::IPSVIEW_STYLE_SOURCE_CUSTOM);
        $this->RegisterPropertyInteger('IPSViewStyleMediaID', 0);
        $this->RegisterPropertyInteger('IPSViewStyleProfileMediaID', 0);
        $this->RegisterPropertyString('IPSViewStylePreset', IPSViewStylePresetHelper::PRESET_STANDARD);
        $this->RegisterPropertyBoolean('IPSViewStyleTransparentBackground', false);
        $this->RegisterPropertyInteger('IPSViewStyleFontScale', 100);

        foreach (self::IPSVIEW_STYLE_COLOR_PROPERTIES as $key => $propertyName) {
            $this->RegisterPropertyInteger($propertyName, self::IPSVIEW_STYLE_CUSTOM_DEFAULTS[$key]);
        }
        foreach (self::IPSVIEW_STYLE_OPACITY_PROPERTIES as $key => $propertyName) {
            $this->RegisterPropertyInteger($propertyName, self::IPSVIEW_STYLE_OPACITY_DEFAULTS[$key]);
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
                'caption' => $this->IPSViewStyleText('description.choose_source')
            ],
            [
                'type'  => 'RowLayout',
                'items' => [
                    [
                        'type'    => 'Select',
                        'name'    => 'IPSViewStyleSource',
                        'caption' => $this->IPSViewStyleText('field.style_source'),
                        'options' => $this->IPSViewStyleSourceOptions(),
                        'width'   => '220px'
                    ],
                    [
                        'type'    => 'SelectMedia',
                        'name'    => 'IPSViewStyleMediaID',
                        'caption' => $this->IPSViewStyleText('field.media_object'),
                        'width'   => '320px'
                    ],
                    [
                        'type'    => 'SelectMedia',
                        'name'    => 'IPSViewStyleProfileMediaID',
                        'caption' => $this->IPSViewStyleText('field.profile_media_object'),
                        'width'   => '320px'
                    ]
                ]
            ],
            [
                'type'  => 'RowLayout',
                'items' => [
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'IPSViewStyleTransparentBackground',
                        'caption' => $this->IPSViewStyleText('field.transparent_background'),
                        'width'   => '260px'
                    ],
                    [
                        'type'    => 'NumberSpinner',
                        'name'    => 'IPSViewStyleFontScale',
                        'caption' => $this->IPSViewStyleText('field.font_scale'),
                        'minimum' => 60,
                        'maximum' => 200,
                        'suffix'  => ' %',
                        'width'   => '180px'
                    ]
                ]
            ],
            [
                'type'    => 'Label',
                'caption' => $this->IPSViewStyleText('description.media_source')
            ],
            [
                'type'    => 'Label',
                'caption' => $this->IPSViewStyleText('description.profile_source')
            ],
            [
                'type'    => 'Label',
                'caption' => $this->IPSViewStyleText('description.preset_source')
            ],
            [
                'type'    => 'Label',
                'caption' => $this->IPSViewStyleText('section.universal_colors')
            ]
        ];

        foreach (array_chunk(array_keys(self::IPSVIEW_STYLE_COLOR_PROPERTIES), 3) as $keys) {
            $row = [];
            foreach ($keys as $key) {
                $row[] = [
                    'type'             => 'SelectColor',
                    'name'             => self::IPSVIEW_STYLE_COLOR_PROPERTIES[$key],
                    'caption'          => $this->IPSViewStyleText(self::IPSVIEW_STYLE_COLOR_CAPTIONS[$key]),
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
            'caption' => $this->IPSViewStyleText('section.surface_transparency')
        ];
        foreach (array_chunk(array_keys(self::IPSVIEW_STYLE_OPACITY_PROPERTIES), 3) as $keys) {
            $row = [];
            foreach ($keys as $key) {
                $row[] = [
                    'type'    => 'NumberSpinner',
                    'name'    => self::IPSVIEW_STYLE_OPACITY_PROPERTIES[$key],
                    'caption' => $this->IPSViewStyleText(self::IPSVIEW_STYLE_OPACITY_CAPTIONS[$key]),
                    'minimum' => 0,
                    'maximum' => 100,
                    'suffix'  => ' %',
                    'width'   => '260px'
                ];
            }

            $items[] = [
                'type'  => 'RowLayout',
                'items' => $row
            ];
        }

        $items[] = [
            'type'    => 'Label',
            'caption' => $this->IPSViewStyleText('section.typography_effects')
        ];
        $items[] = [
            'type'  => 'RowLayout',
            'items' => [
                [
                    'type'    => 'Select',
                    'name'    => 'IPSViewStyleFontFamily',
                    'caption' => $this->IPSViewStyleText('field.font_family'),
                    'options' => $this->IPSViewStyleFontFamilyOptions(),
                    'width'   => '300px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleBaseFontSize',
                    'caption' => $this->IPSViewStyleText('field.base_font_size'),
                    'minimum' => 8,
                    'maximum' => 32,
                    'suffix'  => ' px',
                    'width'   => '220px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleBorderRadius',
                    'caption' => $this->IPSViewStyleText('field.border_radius'),
                    'minimum' => 0,
                    'maximum' => 40,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '220px'
                ]
            ]
        ];
        $items[] = [
            'type'  => 'RowLayout',
            'items' => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleBorderWidth',
                    'caption' => $this->IPSViewStyleText('field.border_width'),
                    'minimum' => 0,
                    'maximum' => 10,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '220px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleLineWidth',
                    'caption' => $this->IPSViewStyleText('field.line_width'),
                    'minimum' => 0,
                    'maximum' => 10,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '220px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleShadowBlur',
                    'caption' => $this->IPSViewStyleText('field.shadow_blur'),
                    'minimum' => 0,
                    'maximum' => 80,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '220px'
                ]
            ]
        ];
        $items[] = [
            'type'  => 'RowLayout',
            'items' => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleShadowSpread',
                    'caption' => $this->IPSViewStyleText('field.shadow_spread'),
                    'minimum' => -20,
                    'maximum' => 40,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '220px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleShadowOffsetX',
                    'caption' => $this->IPSViewStyleText('field.shadow_offset_x'),
                    'minimum' => -40,
                    'maximum' => 40,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '220px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleShadowOffsetY',
                    'caption' => $this->IPSViewStyleText('field.shadow_offset_y'),
                    'minimum' => -40,
                    'maximum' => 40,
                    'digits'  => 1,
                    'suffix'  => ' px',
                    'width'   => '220px'
                ]
            ]
        ];
        $items[] = [
            'type'  => 'RowLayout',
            'items' => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleDisabledOpacity',
                    'caption' => $this->IPSViewStyleText('field.inactive_opacity'),
                    'minimum' => 10,
                    'maximum' => 100,
                    'suffix'  => ' %',
                    'width'   => '220px'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'IPSViewStyleGradientStrength',
                    'caption' => $this->IPSViewStyleText('field.gradient_strength'),
                    'minimum' => 0,
                    'maximum' => 80,
                    'suffix'  => ' %',
                    'width'   => '220px'
                ]
            ]
        ];

        return $items;
    }

    /**
     * Replaces a nested form marker with the complete shared IPSView style form.
     *
     * Consumers can keep the insertion point in their static form.json and call
     * this method after loading the configuration form. The marker is removed
     * before the form is returned, so its untranslated caption is never shown.
     *
     * @param array<int,array<string,mixed>> $elements Form elements to search recursively.
     *
     * @throws InvalidArgumentException If the marker caption is empty.
     */
    protected function InsertIPSViewStyleFormItems(
        array &$elements,
        string $markerCaption = self::IPSVIEW_STYLE_FORM_MARKER,
        string $colorWidth = '240px'
    ): bool {
        $markerCaption = trim($markerCaption);
        if ($markerCaption === '') {
            throw new InvalidArgumentException('IPSView style form marker caption must not be empty.');
        }

        foreach ($elements as $index => &$element) {
            if (
                ($element['type'] ?? null) === 'Label'
                && ($element['caption'] ?? null) === $markerCaption
            ) {
                array_splice($elements, $index, 1, $this->IPSViewStyleFormItems($colorWidth));
                unset($element);

                return true;
            }

            if (
                isset($element['items'])
                && is_array($element['items'])
                && $this->InsertIPSViewStyleFormItems($element['items'], $markerCaption, $colorWidth)
            ) {
                unset($element);

                return true;
            }
        }
        unset($element);

        return false;
    }

    /**
     * Returns the scaled root font size for a standalone IPSView HTML document.
     *
     * The resolved base font size is combined with the shared font-scale
     * property and normalized to a safe integer range for responsive pages.
     */
    protected function IPSViewStyleRootFontSize(?string $document = null): string
    {
        $style = $this->IPSViewResolvedStyle($document);
        $fontScale = (float) $style['FontScale'];
        $fontSize = max(8, min(64, (int) round((float) $style['FontSize'] * $fontScale)));

        return $fontSize . 'px';
    }

    /** Returns the configured IPSView style source. */
    protected function IPSViewStyleSource(): int
    {
        $source = $this->ReadPropertyInteger('IPSViewStyleSource');

        return in_array($source, [
            self::IPSVIEW_STYLE_SOURCE_CUSTOM,
            self::IPSVIEW_STYLE_SOURCE_MEDIA,
            self::IPSVIEW_STYLE_SOURCE_LIGHT,
            self::IPSVIEW_STYLE_SOURCE_DARK,
            self::IPSVIEW_STYLE_SOURCE_PROFILE,
            self::IPSVIEW_STYLE_SOURCE_PRESET,
            self::IPSVIEW_STYLE_SOURCE_PRESET_LIGHT,
            self::IPSVIEW_STYLE_SOURCE_PRESET_DARK,
            self::IPSVIEW_STYLE_SOURCE_PRESET_WARM,
            self::IPSVIEW_STYLE_SOURCE_PRESET_COOL,
            self::IPSVIEW_STYLE_SOURCE_PRESET_EARTHY,
            self::IPSVIEW_STYLE_SOURCE_PRESET_WATER,
            self::IPSVIEW_STYLE_SOURCE_PRESET_SUNNY
        ], true) ? $source : self::IPSVIEW_STYLE_SOURCE_CUSTOM;
    }

    /** Returns the selected IPSView media object ID, or zero. */
    protected function IPSViewStyleMediaID(): int
    {
        return max(0, $this->ReadPropertyInteger('IPSViewStyleMediaID'));
    }

    /** Returns the selected Style Profile media object ID, or zero. */
    protected function IPSViewStyleProfileMediaID(): int
    {
        return max(0, $this->ReadPropertyInteger('IPSViewStyleProfileMediaID'));
    }

    /** Returns the selected centralized IPSView preset identifier. */
    protected function IPSViewStylePreset(): string
    {
        return IPSViewStylePresetHelper::normalize(
            $this->ReadPropertyString('IPSViewStylePreset'),
            IPSViewStylePresetHelper::PRESET_STANDARD
        ) ?? IPSViewStylePresetHelper::PRESET_STANDARD;
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
        $mediaID = $this->IPSViewStyleActiveMediaID();

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

        $mediaID = $this->IPSViewStyleActiveMediaID();

        return $mediaID > 0
            && $senderID === $mediaID
            && $message === $updateMessage;
    }

    /**
     * Resolves the active style source into universal semantic tokens.
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
        } elseif ($source === self::IPSVIEW_STYLE_SOURCE_PROFILE) {
            $profileStyle = $this->IPSViewStyleFromProfile($document ?? $this->ReadIPSViewStyleProfileMediaContent());
            $base = $profileStyle ?? self::IPSVIEW_STYLE_LIGHT_PRESET;
        } elseif (($preset = $this->IPSViewStylePresetForSource($source)) !== null) {
            $base = $this->IPSViewStyleFromPreset($preset);
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
        $fontScale = (float) $style['FontScale'];
        $fontCut = (string) $style['FontStyle'];
        $fontStyle = in_array($fontCut, [IPSViewFontCatalogHelper::STYLE_ITALIC, IPSViewFontCatalogHelper::STYLE_BOLD_ITALIC], true)
            ? 'italic'
            : 'normal';
        $fontWeight = in_array($fontCut, [IPSViewFontCatalogHelper::STYLE_BOLD, IPSViewFontCatalogHelper::STYLE_BOLD_ITALIC], true)
            ? '700'
            : '400';
        $variables = [
            'color-scheme'                                  => $style['ColorScheme'],
            '--ipsview-view-background'                     => $this->IPSViewCSSVariableColor((string) $style['ViewBackground']),
            '--ipsview-page-background'                     => $this->IPSViewCSSVariableColor((string) $style['PageBackground']),
            '--ipsview-background'                          => $transparent ? 'transparent' : $this->IPSViewCSSVariableColor((string) $style['ViewBackground']),
            '--ipsview-label-background'                    => $this->IPSViewCSSVariableColor((string) $style['LabelBackground']),
            '--ipsview-control-background'                  => $this->IPSViewCSSVariableColor((string) $style['ControlBackground']),
            '--ipsview-control-background-active'           => $this->IPSViewCSSVariableColor((string) $style['ControlActiveBackground']),
            '--ipsview-control-background-inactive'         => $this->IPSViewCSSVariableColor((string) $style['ControlInactiveBackground']),
            '--ipsview-control-background-soft'             => $this->IPSViewCSSVariableColor((string) $style['ControlSoftBackground']),
            '--ipsview-popup-background'                    => $this->IPSViewCSSVariableColor((string) $style['PopupBackground']),
            '--ipsview-view-background-opacity'             => $this->IPSViewFormatNumber((float) $style['ViewBackgroundOpacity']),
            '--ipsview-page-background-opacity'             => $this->IPSViewFormatNumber((float) $style['PageBackgroundOpacity']),
            '--ipsview-label-background-opacity'            => $this->IPSViewFormatNumber((float) $style['LabelBackgroundOpacity']),
            '--ipsview-control-background-opacity'          => $this->IPSViewFormatNumber((float) $style['ControlBackgroundOpacity']),
            '--ipsview-control-background-active-opacity'   => $this->IPSViewFormatNumber((float) $style['ControlActiveOpacity']),
            '--ipsview-control-background-inactive-opacity' => $this->IPSViewFormatNumber((float) $style['ControlInactiveOpacity']),
            '--ipsview-popup-background-opacity'            => $this->IPSViewFormatNumber((float) $style['PopupBackgroundOpacity']),
            '--ipsview-border-opacity'                      => $this->IPSViewFormatNumber((float) $style['BorderOpacity']),
            '--ipsview-line-opacity'                        => $this->IPSViewFormatNumber((float) $style['LineOpacity']),
            '--ipsview-popup-border-opacity'                => $this->IPSViewFormatNumber((float) $style['PopupBorderOpacity']),
            '--ipsview-shadow-opacity'                      => $this->IPSViewFormatNumber((float) $style['ShadowOpacity']),
            '--ipsview-popup-shadow-opacity'                => $this->IPSViewFormatNumber((float) $style['PopupShadowOpacity']),
            '--ipsview-text'                                => $this->IPSViewCSSVariableColor((string) $style['Text']),
            '--ipsview-text-active'                         => $this->IPSViewCSSVariableColor((string) $style['TextActive']),
            '--ipsview-text-inactive'                       => $this->IPSViewCSSVariableColor((string) $style['TextInactive']),
            '--ipsview-text-label'                          => $this->IPSViewCSSVariableColor((string) $style['LabelText']),
            '--ipsview-text-secondary'                      => $this->IPSViewCSSVariableColor((string) $style['TextSecondary']),
            '--ipsview-text-faint'                          => $this->IPSViewCSSVariableColor((string) $style['TextFaint']),
            '--ipsview-icon'                                => $this->IPSViewCSSVariableColor((string) $style['Icon']),
            '--ipsview-border'                              => $this->IPSViewCSSVariableColor((string) $style['Border']),
            '--ipsview-line'                                => $this->IPSViewCSSVariableColor((string) $style['Line']),
            '--ipsview-popup-border'                        => $this->IPSViewCSSVariableColor((string) $style['PopupBorder']),
            '--ipsview-accent'                              => $this->IPSViewCSSVariableColor((string) $style['Accent']),
            '--ipsview-information'                         => $this->IPSViewCSSVariableColor((string) $style['Information']),
            '--ipsview-positive'                            => $this->IPSViewCSSVariableColor((string) $style['Positive']),
            '--ipsview-warning'                             => $this->IPSViewCSSVariableColor((string) $style['Warning']),
            '--ipsview-critical'                            => $this->IPSViewCSSVariableColor((string) $style['Critical']),
            '--ipsview-accent-soft'                         => $this->IPSViewCSSVariableColor((string) $style['AccentSoft']),
            '--ipsview-information-soft'                    => $this->IPSViewCSSVariableColor((string) $style['InformationSoft']),
            '--ipsview-positive-soft'                       => $this->IPSViewCSSVariableColor((string) $style['PositiveSoft']),
            '--ipsview-warning-soft'                        => $this->IPSViewCSSVariableColor((string) $style['WarningSoft']),
            '--ipsview-critical-soft'                       => $this->IPSViewCSSVariableColor((string) $style['CriticalSoft']),
            '--ipsview-accent-contrast'                     => $this->IPSViewCSSVariableColor((string) $style['AccentContrast']),
            '--ipsview-information-contrast'                => $this->IPSViewCSSVariableColor((string) $style['InformationContrast']),
            '--ipsview-positive-contrast'                   => $this->IPSViewCSSVariableColor((string) $style['PositiveContrast']),
            '--ipsview-warning-contrast'                    => $this->IPSViewCSSVariableColor((string) $style['WarningContrast']),
            '--ipsview-critical-contrast'                   => $this->IPSViewCSSVariableColor((string) $style['CriticalContrast']),
            '--ipsview-gradient-accent'                     => $style['GradientAccent'],
            '--ipsview-gradient-information'                => $style['GradientInformation'],
            '--ipsview-gradient-positive'                   => $style['GradientPositive'],
            '--ipsview-gradient-warning'                    => $style['GradientWarning'],
            '--ipsview-gradient-critical'                   => $style['GradientCritical'],
            '--ipsview-font-family'                         => $style['FontFamily'],
            '--ipsview-font-size'                           => $this->IPSViewFormatNumber((float) $style['FontSize']) . 'px',
            '--ipsview-font-style'                          => $fontStyle,
            '--ipsview-font-weight'                         => $fontWeight,
            '--ipsview-font-scale'                          => $this->IPSViewFormatNumber($fontScale),
            '--ipsview-radius'                              => $this->IPSViewFormatNumber((float) $style['BorderRadius']) . 'px',
            '--ipsview-border-width'                        => $this->IPSViewFormatNumber((float) $style['BorderWidth']) . 'px',
            '--ipsview-line-width'                          => $this->IPSViewFormatNumber((float) $style['LineWidth']) . 'px',
            '--ipsview-disabled-opacity'                    => $this->IPSViewFormatNumber((float) $style['DisabledOpacity']),
            '--ipsview-shadow'                              => $style['Shadow'],
            '--ipsview-popup-shadow'                        => $style['PopupShadow'],
            '--ipsview-role-view-background'                => 'var(--ipsview-background)',
            '--ipsview-role-page-background'                => 'var(--ipsview-page-background)',
            '--ipsview-role-label-background'               => 'var(--ipsview-label-background)',
            '--ipsview-role-control-background'             => 'var(--ipsview-control-background)',
            '--ipsview-role-control-active-background'      => 'var(--ipsview-control-background-active)',
            '--ipsview-role-control-inactive-background'    => 'var(--ipsview-control-background-inactive)',
            '--ipsview-role-control-soft-background'        => 'var(--ipsview-control-background-soft)',
            '--ipsview-role-popup-background'               => 'var(--ipsview-popup-background)',
            '--ipsview-role-text-primary'                   => 'var(--ipsview-text)',
            '--ipsview-role-text-active'                    => 'var(--ipsview-text-active)',
            '--ipsview-role-text-inactive'                  => 'var(--ipsview-text-inactive)',
            '--ipsview-role-text-label'                     => 'var(--ipsview-text-label)',
            '--ipsview-role-text-secondary'                 => 'var(--ipsview-text-secondary)',
            '--ipsview-role-text-faint'                     => 'var(--ipsview-text-faint)',
            '--ipsview-role-icon'                           => 'var(--ipsview-icon)',
            '--ipsview-role-border'                         => 'var(--ipsview-border)',
            '--ipsview-role-line'                           => 'var(--ipsview-line)',
            '--ipsview-role-popup-border'                   => 'var(--ipsview-popup-border)',
            '--ipsview-role-accent'                         => 'var(--ipsview-accent)',
            '--ipsview-role-information'                    => 'var(--ipsview-information)',
            '--ipsview-role-positive'                       => 'var(--ipsview-positive)',
            '--ipsview-role-warning'                        => 'var(--ipsview-warning)',
            '--ipsview-role-critical'                       => 'var(--ipsview-critical)',
            '--ipsview-role-accent-soft'                    => 'var(--ipsview-accent-soft)',
            '--ipsview-role-information-soft'               => 'var(--ipsview-information-soft)',
            '--ipsview-role-positive-soft'                  => 'var(--ipsview-positive-soft)',
            '--ipsview-role-positive-border'                => 'var(--ipsview-success-border)',
            '--ipsview-role-warning-soft'                   => 'var(--ipsview-warning-soft)',
            '--ipsview-role-critical-soft'                  => 'var(--ipsview-critical-soft)',
            '--ipsview-role-accent-contrast'                => 'var(--ipsview-accent-contrast)',
            '--ipsview-role-information-contrast'           => 'var(--ipsview-information-contrast)',
            '--ipsview-role-positive-contrast'              => 'var(--ipsview-positive-contrast)',
            '--ipsview-role-warning-contrast'               => 'var(--ipsview-warning-contrast)',
            '--ipsview-role-critical-contrast'              => 'var(--ipsview-critical-contrast)',
            '--ipsview-role-gradient-accent'                => 'var(--ipsview-gradient-accent)',
            '--ipsview-role-gradient-information'           => 'var(--ipsview-gradient-information)',
            '--ipsview-role-gradient-positive'              => 'var(--ipsview-gradient-positive)',
            '--ipsview-role-gradient-warning'               => 'var(--ipsview-gradient-warning)',
            '--ipsview-role-gradient-critical'              => 'var(--ipsview-gradient-critical)',
            '--ipsview-role-font-family'                    => 'var(--ipsview-font-family)',
            '--ipsview-role-font-size'                      => 'var(--ipsview-font-size)',
            '--ipsview-role-font-style'                     => 'var(--ipsview-font-style)',
            '--ipsview-role-font-weight'                    => 'var(--ipsview-font-weight)',
            '--ipsview-role-radius'                         => 'var(--ipsview-radius)',
            '--ipsview-role-border-width'                   => 'var(--ipsview-border-width)',
            '--ipsview-role-line-width'                     => 'var(--ipsview-line-width)',
            '--ipsview-role-disabled-opacity'               => 'var(--ipsview-disabled-opacity)',
            '--ipsview-role-shadow'                         => 'var(--ipsview-shadow)',
            '--ipsview-role-popup-shadow'                   => 'var(--ipsview-popup-shadow)',
            '--ipsview-page'                                => 'var(--ipsview-page-background)',
            '--ipsview-surface'                             => 'var(--ipsview-control-background)',
            '--ipsview-surface-strong'                      => 'var(--ipsview-control-background-active)',
            '--ipsview-surface-soft'                        => 'var(--ipsview-control-background-soft)',
            '--ipsview-muted'                               => 'var(--ipsview-text-secondary)',
            '--ipsview-faint'                               => 'var(--ipsview-text-faint)',
            '--ipsview-success'                             => 'var(--ipsview-positive)',
            '--ipsview-success-soft'                        => 'var(--ipsview-positive-soft)',
            '--ipsview-success-border'                      => $this->IPSViewCSSVariableColor((string) $style['PositiveBorder']),
            '--ipsview-danger'                              => 'var(--ipsview-critical)',
            '--ipsview-danger-soft'                         => 'var(--ipsview-critical-soft)'
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
        return $this->ReadIPSViewStyleMediaObjectContent($this->IPSViewStyleMediaID());
    }

    /** Reads and decodes the selected Style Profile media object. */
    protected function ReadIPSViewStyleProfileMediaContent(): string
    {
        return $this->ReadIPSViewStyleMediaObjectContent($this->IPSViewStyleProfileMediaID());
    }

    /** Returns the media ID used by the currently active media-backed source. */
    private function IPSViewStyleActiveMediaID(): int
    {
        return match ($this->IPSViewStyleSource()) {
            self::IPSVIEW_STYLE_SOURCE_MEDIA   => $this->IPSViewStyleMediaID(),
            self::IPSVIEW_STYLE_SOURCE_PROFILE => $this->IPSViewStyleProfileMediaID(),
            default                            => 0
        };
    }

    /** Reads and decodes one Symcon media object. */
    private function ReadIPSViewStyleMediaObjectContent(int $mediaID): string
    {
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

    /** Returns one translated IPSView style label. */
    private function IPSViewStyleText(string $key): string
    {
        $fallback = self::IPSVIEW_STYLE_TRANSLATION_SOURCES[$key] ?? $key;

        return $this->TranslateHelperText('IPSViewStyleHelper', $key, $fallback);
    }

    /** @return list<array{caption:string,value:int}> */
    private function IPSViewStyleSourceOptions(): array
    {
        $options = [
            ['caption' => $this->IPSViewStyleText('option.custom_style'), 'value' => self::IPSVIEW_STYLE_SOURCE_CUSTOM],
            ['caption' => $this->IPSViewStyleText('option.ipsview_standard_style'), 'value' => self::IPSVIEW_STYLE_SOURCE_MEDIA],
            ['caption' => $this->IPSViewStyleText('option.light_preset'), 'value' => self::IPSVIEW_STYLE_SOURCE_LIGHT],
            ['caption' => $this->IPSViewStyleText('option.dark_preset'), 'value' => self::IPSVIEW_STYLE_SOURCE_DARK],
            ['caption' => $this->IPSViewStyleText('option.style_profile'), 'value' => self::IPSVIEW_STYLE_SOURCE_PROFILE],
            ['caption' => $this->IPSViewStyleText('preset.light'), 'value' => self::IPSVIEW_STYLE_SOURCE_PRESET_LIGHT],
            ['caption' => $this->IPSViewStyleText('preset.dark'), 'value' => self::IPSVIEW_STYLE_SOURCE_PRESET_DARK],
            ['caption' => $this->IPSViewStyleText('preset.warm'), 'value' => self::IPSVIEW_STYLE_SOURCE_PRESET_WARM],
            ['caption' => $this->IPSViewStyleText('preset.cool'), 'value' => self::IPSVIEW_STYLE_SOURCE_PRESET_COOL],
            ['caption' => $this->IPSViewStyleText('preset.earthy'), 'value' => self::IPSVIEW_STYLE_SOURCE_PRESET_EARTHY],
            ['caption' => $this->IPSViewStyleText('preset.water'), 'value' => self::IPSVIEW_STYLE_SOURCE_PRESET_WATER],
            ['caption' => $this->IPSViewStyleText('preset.sunny'), 'value' => self::IPSVIEW_STYLE_SOURCE_PRESET_SUNNY]
        ];

        if ($this->ReadPropertyInteger('IPSViewStyleSource') === self::IPSVIEW_STYLE_SOURCE_PRESET) {
            $preset = $this->IPSViewStylePreset();
            $translationKeys = [
                IPSViewStylePresetHelper::PRESET_STANDARD => 'preset.standard',
                IPSViewStylePresetHelper::PRESET_LIGHT    => 'preset.light',
                IPSViewStylePresetHelper::PRESET_DARK     => 'preset.dark',
                IPSViewStylePresetHelper::PRESET_WARM     => 'preset.warm',
                IPSViewStylePresetHelper::PRESET_COOL     => 'preset.cool',
                IPSViewStylePresetHelper::PRESET_EARTHY   => 'preset.earthy',
                IPSViewStylePresetHelper::PRESET_WATER    => 'preset.water',
                IPSViewStylePresetHelper::PRESET_SUNNY    => 'preset.sunny'
            ];
            $options[] = [
                'caption' => $this->IPSViewStyleText($translationKeys[$preset]) . ' (' . $this->IPSViewStyleText('option.previous_selection') . ')',
                'value'   => self::IPSVIEW_STYLE_SOURCE_PRESET
            ];
        }

        return $options;
    }

    private function IPSViewStylePresetForSource(int $source): ?string
    {
        return match ($source) {
            self::IPSVIEW_STYLE_SOURCE_PRESET        => $this->IPSViewStylePreset(),
            self::IPSVIEW_STYLE_SOURCE_PRESET_LIGHT  => IPSViewStylePresetHelper::PRESET_LIGHT,
            self::IPSVIEW_STYLE_SOURCE_PRESET_DARK   => IPSViewStylePresetHelper::PRESET_DARK,
            self::IPSVIEW_STYLE_SOURCE_PRESET_WARM   => IPSViewStylePresetHelper::PRESET_WARM,
            self::IPSVIEW_STYLE_SOURCE_PRESET_COOL   => IPSViewStylePresetHelper::PRESET_COOL,
            self::IPSVIEW_STYLE_SOURCE_PRESET_EARTHY => IPSViewStylePresetHelper::PRESET_EARTHY,
            self::IPSVIEW_STYLE_SOURCE_PRESET_WATER  => IPSViewStylePresetHelper::PRESET_WATER,
            self::IPSVIEW_STYLE_SOURCE_PRESET_SUNNY  => IPSViewStylePresetHelper::PRESET_SUNNY,
            default                                  => null
        };
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

        foreach (self::IPSVIEW_STYLE_OPACITY_PROPERTIES as $key => $propertyName) {
            if (in_array($key, ['ShadowColor', 'PopupShadow'], true)) {
                continue;
            }

            $style[$key] = $this->IPSViewColorWithAlpha($style[$key], $this->IPSViewOpacityProperty($key));
        }

        $style['PopupShadow'] = $this->IPSViewColorWithAlpha($style['Shadow'], $this->IPSViewOpacityProperty('PopupShadow'));
        $style['FontFamily'] = $this->IPSViewNormalizeFontFamily($this->ReadPropertyString('IPSViewStyleFontFamily'));
        $style['FontSize'] = (float) max(8, min(32, $this->ReadPropertyInteger('IPSViewStyleBaseFontSize')));
        $style['BorderRadius'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleBorderRadius'), 0.0, 40.0);
        $style['BorderWidth'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleBorderWidth'), 0.0, 10.0);
        $style['LineWidth'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleLineWidth'), 0.0, 10.0);
        $style['ShadowColor'] = $this->IPSViewColorWithAlpha($style['Shadow'], $this->IPSViewOpacityProperty('ShadowColor'));
        $style['ShadowBlur'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleShadowBlur'), 0.0, 80.0);
        $style['ShadowSpread'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleShadowSpread'), -20.0, 40.0);
        $style['ShadowOffsetX'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleShadowOffsetX'), -40.0, 40.0);
        $style['ShadowOffsetY'] = $this->IPSViewClampFloat($this->ReadPropertyFloat('IPSViewStyleShadowOffsetY'), -40.0, 40.0);

        return $style;
    }

    /** @return array<string,mixed> */
    private function IPSViewStyleFromPreset(string $preset): array
    {
        $palette = IPSViewStylePresetHelper::palette($preset);
        $shadowRGB = $this->IPSViewMixRGB(
            $this->IPSViewCSSColorToRGB($palette[IPSViewStylePresetHelper::ROLE_VIEW_BACKGROUND]),
            $this->IPSViewCSSColorToRGB('#000000'),
            0.68
        );
        $shadowColor = $this->IPSViewRGBToCSS($shadowRGB);

        return [
            'ViewBackground'            => $palette[IPSViewStylePresetHelper::ROLE_VIEW_BACKGROUND],
            'PageBackground'            => $palette[IPSViewStylePresetHelper::ROLE_PAGE_BACKGROUND],
            'LabelBackground'           => $palette[IPSViewStylePresetHelper::ROLE_SURFACE],
            'ControlBackground'         => $palette[IPSViewStylePresetHelper::ROLE_SURFACE],
            'ControlActiveBackground'   => $palette[IPSViewStylePresetHelper::ROLE_ACTIVE],
            'ControlInactiveBackground' => $palette[IPSViewStylePresetHelper::ROLE_INACTIVE],
            'Text'                      => $palette[IPSViewStylePresetHelper::ROLE_PRIMARY_TEXT],
            'TextActive'                => $palette[IPSViewStylePresetHelper::ROLE_PRIMARY_TEXT],
            'TextInactive'              => $palette[IPSViewStylePresetHelper::ROLE_SECONDARY_TEXT],
            'LabelText'                 => $palette[IPSViewStylePresetHelper::ROLE_PRIMARY_TEXT],
            'Icon'                      => $palette[IPSViewStylePresetHelper::ROLE_PRIMARY_TEXT],
            'Border'                    => $palette[IPSViewStylePresetHelper::ROLE_BORDER],
            'Line'                      => $palette[IPSViewStylePresetHelper::ROLE_BORDER],
            'PopupBackground'           => $palette[IPSViewStylePresetHelper::ROLE_PAGE_BACKGROUND],
            'PopupBorder'               => $palette[IPSViewStylePresetHelper::ROLE_BORDER],
            'PopupShadow'               => $this->IPSViewColorWithAlpha($shadowColor, 0.32),
            'Accent'                    => $palette[IPSViewStylePresetHelper::ROLE_ACCENT],
            'Information'               => $palette[IPSViewStylePresetHelper::ROLE_ACCENT],
            'Positive'                  => $palette[IPSViewStylePresetHelper::ROLE_SUCCESS],
            'Warning'                   => $palette[IPSViewStylePresetHelper::ROLE_WARNING],
            'Critical'                  => $palette[IPSViewStylePresetHelper::ROLE_ERROR],
            'FontFamily'                => self::IPSVIEW_STYLE_SYSTEM_FONT_FAMILY,
            'FontStyle'                 => IPSViewFontCatalogHelper::STYLE_REGULAR,
            'FontSize'                  => 16.0,
            'BorderRadius'              => 8.0,
            'BorderWidth'               => 1.0,
            'LineWidth'                 => 1.0,
            'ShadowColor'               => $this->IPSViewColorWithAlpha($shadowColor, 0.24),
            'ShadowBlur'                => 18.0,
            'ShadowSpread'              => 0.0,
            'ShadowOffsetX'             => 0.0,
            'ShadowOffsetY'             => 8.0
        ];
    }

    /** @return array<string,mixed>|null */
    private function IPSViewStyleFromProfile(string $document): ?array
    {
        $document = trim($document);
        if ($document === '') {
            return null;
        }

        try {
            $profile = IPSViewStyleProfileHelper::decode($document);
        } catch (InvalidArgumentException | \JsonException) {
            $raw = base64_decode($document, true);
            if ($raw === false) {
                return null;
            }

            try {
                $profile = IPSViewStyleProfileHelper::decode($raw);
            } catch (InvalidArgumentException | \JsonException) {
                return null;
            }
        }

        $style = $profile['style'];
        if (!is_array($style)) {
            return null;
        }

        $fontFamily = (string) $style['FontFamily'];
        if ($fontFamily === IPSViewStyleProfileHelper::FONT_SYSTEM) {
            $fontFamily = self::IPSVIEW_STYLE_SYSTEM_FONT_FAMILY;
        }

        return [
            'ViewBackground'            => $this->IPSViewColorWithAlpha((string) $style['ViewBackground'], (int) $style['ViewBackgroundOpacity'] / 100),
            'PageBackground'            => $this->IPSViewColorWithAlpha((string) $style['PageBackground'], (int) $style['PageBackgroundOpacity'] / 100),
            'LabelBackground'           => $this->IPSViewColorWithAlpha((string) $style['LabelBackground'], (int) $style['LabelBackgroundOpacity'] / 100),
            'ControlBackground'         => $this->IPSViewColorWithAlpha((string) $style['ControlBackground'], (int) $style['ControlBackgroundOpacity'] / 100),
            'ControlActiveBackground'   => $this->IPSViewColorWithAlpha((string) $style['ControlActiveBackground'], (int) $style['ControlActiveOpacity'] / 100),
            'ControlInactiveBackground' => $this->IPSViewColorWithAlpha((string) $style['ControlInactiveBackground'], (int) $style['ControlInactiveOpacity'] / 100),
            'Text'                      => (string) $style['Text'],
            'TextActive'                => (string) $style['TextActive'],
            'TextInactive'              => (string) $style['TextInactive'],
            'LabelText'                 => (string) $style['LabelText'],
            'Icon'                      => (string) $style['Icon'],
            'Border'                    => $this->IPSViewColorWithAlpha((string) $style['Border'], (int) $style['BorderOpacity'] / 100),
            'Line'                      => $this->IPSViewColorWithAlpha((string) $style['Line'], (int) $style['LineOpacity'] / 100),
            'PopupBackground'           => $this->IPSViewColorWithAlpha((string) $style['PopupBackground'], (int) $style['PopupBackgroundOpacity'] / 100),
            'PopupBorder'               => $this->IPSViewColorWithAlpha((string) $style['PopupBorder'], (int) $style['PopupBorderOpacity'] / 100),
            'PopupShadow'               => $this->IPSViewColorWithAlpha((string) $style['ShadowColor'], (int) $style['PopupShadowOpacity'] / 100),
            'Accent'                    => (string) $style['Accent'],
            'Information'               => (string) $style['Information'],
            'Positive'                  => (string) $style['Positive'],
            'Warning'                   => (string) $style['Warning'],
            'Critical'                  => (string) $style['Critical'],
            'FontFamily'                => $fontFamily,
            'FontStyle'                 => (string) $style['FontStyle'],
            'FontSize'                  => (float) $style['FontSize'],
            'FontScale'                 => (int) $style['FontScale'],
            'BorderRadius'              => (float) $style['BorderRadius'],
            'BorderWidth'               => (float) $style['BorderWidth'],
            'LineWidth'                 => (float) $style['LineWidth'],
            'ShadowColor'               => $this->IPSViewColorWithAlpha((string) $style['ShadowColor'], (int) $style['ShadowOpacity'] / 100),
            'ShadowBlur'                => (float) $style['ShadowBlur'],
            'ShadowSpread'              => (float) $style['ShadowSpread'],
            'ShadowOffsetX'             => (float) $style['ShadowOffsetX'],
            'ShadowOffsetY'             => (float) $style['ShadowOffsetY'],
            'DisabledOpacity'           => (int) $style['DisabledOpacity'],
            'GradientStrength'          => (int) $style['GradientStrength']
        ];
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

    /**
     * @param array<string,mixed> $style
     *
     * @return array<string,string|float>
     */
    private function IPSViewFinalizeStyle(array $style): array
    {
        $control = $this->IPSViewCSSColorToRGB((string) $style['ControlBackground']);
        $controlActive = $this->IPSViewCSSColorToRGB((string) $style['ControlActiveBackground']);
        $controlInactive = $this->IPSViewCSSColorToRGB((string) $style['ControlInactiveBackground']);
        $popup = $this->IPSViewCSSColorToRGB((string) $style['PopupBackground']);
        $label = $this->IPSViewCSSColorToRGB((string) $style['LabelBackground']);
        $text = $this->IPSViewCSSColorToRGB((string) $style['Text']);
        $textActive = $this->IPSViewCSSColorToRGB((string) $style['TextActive']);
        $textInactive = $this->IPSViewCSSColorToRGB((string) $style['TextInactive']);
        $labelText = $this->IPSViewCSSColorToRGB((string) $style['LabelText']);
        $secondary = $text;
        $secondary['alpha'] = 0.72;
        $faint = $text;
        $faint['alpha'] = 0.52;
        $soft = $this->IPSViewMixRGB($control, $this->IPSViewCSSColorToRGB((string) $style['PageBackground']), 0.20);
        $gradientStrength = max(0, min(80, (int) ($style['GradientStrength'] ?? $this->ReadPropertyInteger('IPSViewStyleGradientStrength')))) / 100;
        $disabledOpacity = max(10, min(100, (int) ($style['DisabledOpacity'] ?? $this->ReadPropertyInteger('IPSViewStyleDisabledOpacity')))) / 100;
        $fontScale = max(60, min(200, (int) ($style['FontScale'] ?? $this->ReadPropertyInteger('IPSViewStyleFontScale')))) / 100;
        $fontStyle = is_string($style['FontStyle'] ?? null)
            ? (string) $style['FontStyle']
            : IPSViewFontCatalogHelper::STYLE_REGULAR;
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
            'FontStyle'                 => $fontStyle,
            'FontSize'                  => (float) $style['FontSize'],
            'FontScale'                 => $fontScale,
            'BorderRadius'              => (float) $style['BorderRadius'],
            'BorderWidth'               => (float) $style['BorderWidth'],
            'LineWidth'                 => (float) $style['LineWidth'],
            'DisabledOpacity'           => $disabledOpacity,
            'Shadow'                    => $shadow,
            'PopupShadow'               => $popupShadow,
            'ViewBackgroundOpacity'     => $this->IPSViewCSSColorToRGB((string) $style['ViewBackground'])['alpha'],
            'PageBackgroundOpacity'     => $this->IPSViewCSSColorToRGB((string) $style['PageBackground'])['alpha'],
            'LabelBackgroundOpacity'    => $label['alpha'],
            'ControlBackgroundOpacity'  => $control['alpha'],
            'ControlActiveOpacity'      => $controlActive['alpha'],
            'ControlInactiveOpacity'    => $controlInactive['alpha'],
            'PopupBackgroundOpacity'    => $popup['alpha'],
            'BorderOpacity'             => $this->IPSViewCSSColorToRGB((string) $style['Border'])['alpha'],
            'LineOpacity'               => $this->IPSViewCSSColorToRGB((string) $style['Line'])['alpha'],
            'PopupBorderOpacity'        => $this->IPSViewCSSColorToRGB((string) $style['PopupBorder'])['alpha'],
            'ShadowOpacity'             => $shadowColor['alpha'],
            'PopupShadowOpacity'        => $popupShadowColor['alpha']
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

    /**
     * @param array<string,mixed> $document
     * @param array<int,string>   $keys
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

    /**
     * @param array<string,mixed> $document
     * @param array<int,string>   $keywords
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

    private function IPSViewOpacityProperty(string $key): float
    {
        $propertyName = self::IPSVIEW_STYLE_OPACITY_PROPERTIES[$key] ?? null;
        $default = self::IPSVIEW_STYLE_OPACITY_DEFAULTS[$key] ?? 100;
        if ($propertyName === null) {
            return $default / 100;
        }

        return max(0, min(100, $this->ReadPropertyInteger($propertyName))) / 100;
    }

    /**
     * Returns font-family options from the shared IPSView catalogue.
     *
     * Existing safe custom values are appended as a compatibility option so
     * updating the helper does not silently replace a user's stored setting.
     *
     * @return list<array{caption:string,value:string}>
     */
    private function IPSViewStyleFontFamilyOptions(): array
    {
        $options = [
            [
                'caption' => $this->IPSViewStyleText('option.system_font'),
                'value'   => ''
            ]
        ];

        foreach (IPSViewFontCatalogHelper::options() as $option) {
            $options[] = $option;
        }

        $configured = trim($this->ReadPropertyString('IPSViewStyleFontFamily'));
        if (
            $configured !== ''
            && !$this->IPSViewFontOptionExists($options, $configured)
            && $this->IPSViewIsSafeLegacyFontFamily($configured)
        ) {
            $options[] = [
                'caption' => $configured . ' (' . $this->IPSViewStyleText('option.legacy_font') . ')',
                'value'   => $configured
            ];
        }

        return $options;
    }

    /**
     * @param list<array{caption:string,value:string}> $options
     */
    private function IPSViewFontOptionExists(array $options, string $fontFamily): bool
    {
        foreach ($options as $option) {
            if ($option['value'] === $fontFamily) {
                return true;
            }
        }

        return false;
    }

    private function IPSViewNormalizeFontFamily(string $fontFamily): string
    {
        $fontFamily = trim($fontFamily);
        if ($fontFamily === '') {
            return self::IPSVIEW_STYLE_SYSTEM_FONT_FAMILY;
        }

        $catalogFamily = IPSViewFontCatalogHelper::normalizeFamily($fontFamily);
        if ($catalogFamily !== null) {
            return $catalogFamily;
        }

        return $this->IPSViewIsSafeLegacyFontFamily($fontFamily)
            ? $fontFamily
            : self::IPSVIEW_STYLE_SYSTEM_FONT_FAMILY;
    }

    private function IPSViewIsSafeLegacyFontFamily(string $fontFamily): bool
    {
        return preg_match('/[{};\x00-\x1F\x7F]/', $fontFamily) !== 1;
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

    private function IPSViewCSSVariableColor(string $color): string
    {
        $resolved = $this->IPSViewCSSColorToRGB($color);
        $alpha = max(0.0, min(1.0, $resolved['alpha']));
        if ($alpha <= 0.001) {
            return 'transparent';
        }
        if ($alpha >= 0.999) {
            return $this->IPSViewRGBToCSS($resolved, 1.0);
        }

        $red = (int) round(max(0.0, min(255.0, $resolved['red'])));
        $green = (int) round(max(0.0, min(255.0, $resolved['green'])));
        $blue = (int) round(max(0.0, min(255.0, $resolved['blue'])));
        $percentage = $this->IPSViewFormatNumber($alpha * 100);

        return sprintf('rgba(%d, %d, %d, %s%%)', $red, $green, $blue, $percentage);
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

    /**
     * @param array{red:float,green:float,blue:float,alpha?:float} $first
     * @param array{red:float,green:float,blue:float,alpha?:float} $second
     *
     * @return array{red:float,green:float,blue:float,alpha:float}
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

    /**
     * @param array{red:float,green:float,blue:float,alpha?:float} $first
     * @param array{red:float,green:float,blue:float,alpha?:float} $second
     */
    private function IPSViewContrastRatio(array $first, array $second): float
    {
        $firstLuminance = $this->IPSViewRelativeLuminance($first);
        $secondLuminance = $this->IPSViewRelativeLuminance($second);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
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
