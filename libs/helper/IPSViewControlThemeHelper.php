<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;
use stdClass;

require_once __DIR__ . '/IPSViewStylePresetHelper.php';

/**
 * Provides the canonical native IPSView color-field catalogue and lossless theme transport.
 *
 * The helper keeps the portable semantic style layer separate from concrete IPSView properties.
 * It currently knows 109 native color fields across 15 IPSView families. Every known field maps
 * to one semantic Style Profile color field and, where applicable, to one preset role.
 *
 * Native color objects retain alpha, type, pattern, empty/name flags, optional secondary gradient
 * colors and unknown future properties. Unknown top-level IPSView color fields are transported in
 * a dedicated extension section instead of being discarded.
 *
 * @version 1.0.2
 */
final class IPSViewControlThemeHelper
{
    public const SCHEMA = 'burki24.ipsview-native-theme';
    public const VERSION = 1;

    public const FAMILY_BASE = 'base';
    public const FAMILY_ASSOCIATION = 'association';
    public const FAMILY_TABS = 'tabs';
    public const FAMILY_SWITCH = 'switch';
    public const FAMILY_SLIDER = 'slider';
    public const FAMILY_PROGRESSBAR = 'progressbar';
    public const FAMILY_CIRCLE = 'circle';
    public const FAMILY_FLOW = 'flow';
    public const FAMILY_GAUGE = 'gauge';
    public const FAMILY_SHADOW_GRID = 'shadowGrid';
    public const FAMILY_DIALOG = 'dialog';
    public const FAMILY_CHART = 'chart';
    public const FAMILY_SCHEDULE = 'schedule';
    public const FAMILY_EVENT = 'event';
    public const FAMILY_CALENDAR = 'calendar';

    /** @var array<string,list<string>> */
    private const FAMILY_FIELDS = [
        self::FAMILY_BASE        => [
            'ColorText',
            'ColorTextOn',
            'ColorTextOff',
            'ColorTextLabel',
            'ColorBack',
            'ColorBackOff',
            'ColorBackOn',
            'ColorBackLabel',
            'ColorBorder',
            'ColorLine',
            'ColorBorderLabel',
            'ColorView',
            'ColorPage',
            'ColorIcon',
            'ColorPopupShadow',
            'ColorPopupBack',
            'ColorPopupBorder'
        ],
        self::FAMILY_ASSOCIATION => [
            'ColorAssocTextOn',
            'ColorAssocTextOff',
            'ColorAssocBackOn',
            'ColorAssocBackOff',
            'ColorAssocBorder',
            'ColorAssocShadow'
        ],
        self::FAMILY_TABS        => [
            'ColorTabTextOn',
            'ColorTabTextOff',
            'ColorTabBackOn'
        ],
        self::FAMILY_SWITCH      => [
            'SwitchTrackColorActive',
            'SwitchTrackColorInactive',
            'SwitchThumbColorActive',
            'SwitchThumbColorInactive'
        ],
        self::FAMILY_SLIDER      => [
            'SliderTrackColorActive',
            'SliderTrackColorInactive',
            'SliderTickColorActive',
            'SliderTickColorInactive',
            'SliderThumbColorInner',
            'SliderThumbColorOuter'
        ],
        self::FAMILY_PROGRESSBAR => [
            'ProgressbarTrackColorActive',
            'ProgressbarTrackColorInactive',
            'ProgressbarTickColorActive',
            'ProgressbarTickColorInactive',
            'ProgressbarThumbColorInner',
            'ProgressbarThumbColorOuter'
        ],
        self::FAMILY_CIRCLE      => [
            'CircleTrackColorActive',
            'CircleTrackColorInactive',
            'CircleTickColorActive',
            'CircleTickColorInactive',
            'CircleThumbColorInner',
            'CircleThumbColorOuter'
        ],
        self::FAMILY_FLOW        => [
            'FlowBorderColorPositive',
            'FlowBorderColorNegative',
            'FlowBorderColorNeutral',
            'FlowLineColorPositive',
            'FlowLineColorNegative',
            'FlowLineColorNeutral',
            'FlowTextColorPositive',
            'FlowTextColorNegative',
            'FlowTextColorNeutral',
            'FlowAnimationColorPositive',
            'FlowAnimationColorNegative',
            'FlowAnimationColorNeutral'
        ],
        self::FAMILY_GAUGE       => [
            'GaugeTrackColor',
            'GaugeTickColor',
            'GaugeLabelColor',
            'GaugeNeedleColor',
            'GaugeKnobColor',
            'GaugeTrackPointerColor'
        ],
        self::FAMILY_SHADOW_GRID => [
            'ShadowBackColor',
            'ShadowBorderColor',
            'ShadowColor',
            'GridLineColor'
        ],
        self::FAMILY_DIALOG      => [
            'DialogBackColor',
            'DialogTextColor',
            'DialogButtonBackColor',
            'DialogButtonTextColor',
            'DialogButtonTextColorEnabled',
            'DialogButtonTextColorDisabled',
            'DialogDateTimePrimaryColor',
            'DialogDateTimeSecondaryColor',
            'DialogHeaderTextColor'
        ],
        self::FAMILY_CHART       => [
            'ChartDotBorderColor',
            'ChartDotFillColor',
            'ChartGraphFillColor',
            'ChartGraphLineColor',
            'ChartGraphLineColorMax',
            'ChartGraphLineColorMin',
            'ChartGridColor',
            'ChartScaleFontColor',
            'ChartScaleLineColor'
        ],
        self::FAMILY_SCHEDULE    => [
            'ScheduleDayOfWeekColor',
            'ScheduleItemsColor',
            'ScheduleLegendColor',
            'ScheduleNowFontColor',
            'ScheduleNowIndicatorColor',
            'ScheduleTimeColor'
        ],
        self::FAMILY_EVENT       => [
            'EventHeaderColor',
            'EventIconColor',
            'EventTextColor',
            'EventTextColorOff',
            'EventTextColorOn'
        ],
        self::FAMILY_CALENDAR    => [
            'CalendarDayBackColor',
            'CalendarDayFontColor',
            'CalendarGridColor',
            'CalendarHeaderFontColor',
            'CalendarOffBackColor',
            'CalendarOffFontColor',
            'CalendarTimeFontColor',
            'CalendarTodayFontColor',
            'CalendarTodayHighlightColor',
            'CalendarWeekNumberFontColor'
        ]
    ];

    /**
     * Maps the concrete native fields to the existing portable Style Profile color fields.
     *
     * Information remains a valid Style Profile color even though no currently known native
     * IPSView property has an unambiguous one-to-one information role.
     *
     * @var array<string,list<string>>
     */
    private const STYLE_FIELD_FIELDS = [
        'ViewBackground'            => [
            'ColorView'
        ],
        'PageBackground'            => [
            'ColorPage',
            'CalendarDayBackColor',
            'CalendarOffBackColor'
        ],
        'LabelBackground'           => [
            'ColorBackLabel'
        ],
        'ControlBackground'         => [
            'ColorBack',
            'ShadowBackColor',
            'DialogButtonBackColor'
        ],
        'ControlActiveBackground'   => [
            'ColorBackOn',
            'ColorAssocBackOn',
            'ColorTabBackOn',
            'GaugeTrackPointerColor'
        ],
        'ControlInactiveBackground' => [
            'ColorBackOff',
            'ColorAssocBackOff',
            'SwitchTrackColorInactive',
            'SwitchThumbColorInactive',
            'SliderTrackColorInactive',
            'SliderTickColorActive',
            'ProgressbarTrackColorInactive',
            'ProgressbarTickColorActive',
            'CircleTrackColorInactive',
            'CircleTickColorActive',
            'DialogDateTimeSecondaryColor',
            'FlowLineColorNeutral'
        ],
        'Text'                      => [
            'ColorText',
            'FlowTextColorPositive',
            'FlowTextColorNegative',
            'GaugeTickColor',
            'GaugeLabelColor',
            'GaugeKnobColor',
            'ChartDotFillColor',
            'ScheduleDayOfWeekColor',
            'ScheduleLegendColor',
            'ScheduleItemsColor',
            'EventHeaderColor',
            'EventTextColor',
            'CalendarHeaderFontColor',
            'CalendarTodayFontColor',
            'CalendarDayFontColor',
            'DialogHeaderTextColor',
            'DialogTextColor',
            'DialogButtonTextColor'
        ],
        'TextActive'                => [
            'ColorTextOn',
            'ColorAssocTextOn',
            'ColorTabTextOn',
            'EventTextColorOn',
            'DialogButtonTextColorEnabled'
        ],
        'TextInactive'              => [
            'ColorTextOff',
            'ColorAssocTextOff',
            'ColorTabTextOff',
            'FlowTextColorNeutral',
            'FlowAnimationColorNeutral',
            'ChartScaleFontColor',
            'ScheduleTimeColor',
            'EventTextColorOff',
            'CalendarWeekNumberFontColor',
            'CalendarOffFontColor',
            'CalendarTimeFontColor',
            'DialogButtonTextColorDisabled'
        ],
        'LabelText'                 => [
            'ColorTextLabel'
        ],
        'Icon'                      => [
            'ColorIcon',
            'EventIconColor'
        ],
        'Border'                    => [
            'ColorBorder',
            'ColorBorderLabel',
            'ColorAssocBorder',
            'FlowBorderColorPositive',
            'FlowBorderColorNegative',
            'FlowBorderColorNeutral',
            'GaugeTrackColor',
            'ChartDotBorderColor',
            'ShadowBorderColor',
            'SliderThumbColorOuter',
            'ProgressbarThumbColorOuter',
            'CircleThumbColorOuter'
        ],
        'Line'                      => [
            'ColorLine',
            'ChartGridColor',
            'ChartScaleLineColor',
            'CalendarGridColor',
            'GridLineColor'
        ],
        'PopupBackground'           => [
            'ColorPopupBack',
            'DialogBackColor'
        ],
        'PopupBorder'               => [
            'ColorPopupBorder'
        ],
        'Accent'                    => [
            'SwitchTrackColorActive',
            'SwitchThumbColorActive',
            'SliderTrackColorActive',
            'SliderTickColorInactive',
            'SliderThumbColorInner',
            'ProgressbarTrackColorActive',
            'ProgressbarTickColorInactive',
            'ProgressbarThumbColorInner',
            'CircleTrackColorActive',
            'CircleTickColorInactive',
            'CircleThumbColorInner',
            'GaugeNeedleColor',
            'ChartGraphFillColor',
            'ChartGraphLineColor',
            'ChartGraphLineColorMin',
            'CalendarTodayHighlightColor',
            'DialogDateTimePrimaryColor'
        ],
        'Information'               => [],
        'Positive'                  => [
            'FlowLineColorPositive',
            'FlowAnimationColorPositive'
        ],
        'Warning'                   => [
            'ScheduleNowFontColor',
            'ScheduleNowIndicatorColor'
        ],
        'Critical'                  => [
            'FlowLineColorNegative',
            'FlowAnimationColorNegative',
            'ChartGraphLineColorMax'
        ],
        'ShadowColor'               => [
            'ColorPopupShadow',
            'ColorAssocShadow',
            'ShadowColor'
        ]
    ];

    /** @var array<string,string|null> */
    private const STYLE_FIELD_PRESET_ROLES = [
        'ViewBackground'            => IPSViewStylePresetHelper::ROLE_VIEW_BACKGROUND,
        'PageBackground'            => IPSViewStylePresetHelper::ROLE_PAGE_BACKGROUND,
        'LabelBackground'           => IPSViewStylePresetHelper::ROLE_SURFACE,
        'ControlBackground'         => IPSViewStylePresetHelper::ROLE_SURFACE,
        'ControlActiveBackground'   => IPSViewStylePresetHelper::ROLE_ACTIVE,
        'ControlInactiveBackground' => IPSViewStylePresetHelper::ROLE_INACTIVE,
        'Text'                      => IPSViewStylePresetHelper::ROLE_PRIMARY_TEXT,
        'TextActive'                => IPSViewStylePresetHelper::ROLE_PRIMARY_TEXT,
        'TextInactive'              => IPSViewStylePresetHelper::ROLE_SECONDARY_TEXT,
        'LabelText'                 => IPSViewStylePresetHelper::ROLE_PRIMARY_TEXT,
        'Icon'                      => IPSViewStylePresetHelper::ROLE_PRIMARY_TEXT,
        'Border'                    => IPSViewStylePresetHelper::ROLE_BORDER,
        'Line'                      => IPSViewStylePresetHelper::ROLE_BORDER,
        'PopupBackground'           => IPSViewStylePresetHelper::ROLE_PAGE_BACKGROUND,
        'PopupBorder'               => IPSViewStylePresetHelper::ROLE_BORDER,
        'Accent'                    => IPSViewStylePresetHelper::ROLE_ACCENT,
        'Information'               => IPSViewStylePresetHelper::ROLE_ACCENT,
        'Positive'                  => IPSViewStylePresetHelper::ROLE_SUCCESS,
        'Warning'                   => IPSViewStylePresetHelper::ROLE_WARNING,
        'Critical'                  => IPSViewStylePresetHelper::ROLE_ERROR,
        'ShadowColor'               => null
    ];

    /** @var list<string> */
    private const LEGACY_FIELDS = [
        'DialogButtonTextColor'
    ];

    /** @var list<string> */
    private const NATIVE_COLOR_KEYS = [
        'A',
        'R',
        'G',
        'B',
        'Type',
        'Pattern',
        'IsEmpty',
        'Name',
        'A2',
        'R2',
        'G2',
        'B2'
    ];

    /** @return list<string> */
    public static function fields(): array
    {
        $fields = [];
        foreach (self::FAMILY_FIELDS as $familyFields) {
            array_push($fields, ...$familyFields);
        }

        return $fields;
    }

    /** @return array<string,list<string>> */
    public static function families(): array
    {
        return self::FAMILY_FIELDS;
    }

    /** @return list<string> */
    public static function styleFields(): array
    {
        return array_keys(self::STYLE_FIELD_FIELDS);
    }

    /** @return list<string> */
    public static function requiredStyleFields(): array
    {
        $fields = [];
        foreach (self::STYLE_FIELD_FIELDS as $styleField => $nativeFields) {
            if ($nativeFields !== []) {
                $fields[] = $styleField;
            }
        }

        return $fields;
    }

    /**
     * Returns metadata for all currently known native IPSView color fields.
     *
     * @return array<string,array{family:string,styleField:string,presetRole:string|null,legacy:bool}>
     */
    public static function catalog(): array
    {
        $familyByField = self::reverseGroups(self::FAMILY_FIELDS);
        $styleFieldByField = self::reverseGroups(self::STYLE_FIELD_FIELDS);
        $catalog = [];

        foreach (self::fields() as $field) {
            $styleField = $styleFieldByField[$field];
            $catalog[$field] = [
                'family'     => $familyByField[$field],
                'styleField' => $styleField,
                'presetRole' => self::STYLE_FIELD_PRESET_ROLES[$styleField],
                'legacy'     => in_array($field, self::LEGACY_FIELDS, true)
            ];
        }

        return $catalog;
    }

    /** Returns metadata for one known native field or null when it is unknown. */
    public static function definition(string $field): ?array
    {
        $catalog = self::catalog();

        return $catalog[$field] ?? null;
    }

    /** Returns true when the native field belongs to the current catalogue. */
    public static function isKnownField(string $field): bool
    {
        return array_key_exists($field, self::catalog());
    }

    /** @return list<string> */
    public static function fieldsForFamily(string $family): array
    {
        if (!array_key_exists($family, self::FAMILY_FIELDS)) {
            throw new InvalidArgumentException('Unknown IPSView native color family: ' . $family);
        }

        return self::FAMILY_FIELDS[$family];
    }

    /** @return list<string> */
    public static function fieldsForStyleField(string $styleField): array
    {
        if (!array_key_exists($styleField, self::STYLE_FIELD_FIELDS)) {
            throw new InvalidArgumentException('Unknown IPSView Style Profile color field: ' . $styleField);
        }

        return self::STYLE_FIELD_FIELDS[$styleField];
    }

    /** @return list<string> */
    public static function fieldsForPresetRole(string $presetRole): array
    {
        $fields = [];

        foreach (self::catalog() as $field => $definition) {
            if ($definition['presetRole'] === $presetRole) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Resolves the portable Style Profile field for one native color.
     *
     * The document argument is retained for API compatibility and future format-specific rules.
     * Native field semantics are currently stable: ColorView is the View background and ColorPage
     * is the page background. Missing fields never change the meaning of fields that are present.
     */
    public static function styleFieldForDocument(array|object $document, string $field): ?string
    {
        unset($document);

        $definition = self::definition($field);

        return $definition['styleField'] ?? null;
    }

    /** Resolves the semantic preset role for one native color in the context of a document. */
    public static function presetRoleForDocument(array|object $document, string $field): ?string
    {
        $styleField = self::styleFieldForDocument($document, $field);
        if ($styleField === null) {
            return null;
        }

        return self::STYLE_FIELD_PRESET_ROLES[$styleField];
    }

    /**
     * Returns the complete native property mapping grouped by semantic preset role.
     *
     * @return array<string,list<string>>
     */
    public static function presetRoleMappingForDocument(array|object $document): array
    {
        $mapping = [];

        foreach (self::fields() as $field) {
            $presetRole = self::presetRoleForDocument($document, $field);
            if ($presetRole === null) {
                continue;
            }

            $mapping[$presetRole] ??= [];
            $mapping[$presetRole][] = $field;
        }

        return $mapping;
    }

    /**
     * Builds a complete 109-field native theme from the portable semantic color fields.
     *
     * Individual native overrides may use known or unknown future field names. Known overrides
     * replace the derived value; unknown overrides are preserved in unknownColors.
     *
     * @param array<string,mixed> $styleColors
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    public static function fromStyleColors(array $styleColors, array $overrides = []): array
    {
        $missing = array_values(array_diff(self::requiredStyleFields(), array_keys($styleColors)));
        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Missing IPSView semantic color fields: ' . implode(', ', $missing)
            );
        }

        $colors = [];
        foreach (self::catalog() as $field => $definition) {
            $semanticColor = $styleColors[$definition['styleField']] ?? null;
            if (!is_string($semanticColor)) {
                throw new InvalidArgumentException(
                    'IPSView semantic color field ' . $definition['styleField'] . ' must contain #RRGGBB.'
                );
            }

            $colors[$field] = self::createColor($semanticColor);
        }

        $unknownColors = [];
        foreach ($overrides as $field => $value) {
            if (!is_string($field) || trim($field) === '') {
                throw new InvalidArgumentException('IPSView native override names must be non-empty strings.');
            }

            $normalized = self::normalizeColor($value);
            if (self::isKnownField($field)) {
                $colors[$field] = $normalized;
            } else {
                $unknownColors[$field] = $normalized;
            }
        }

        $theme = [
            'schema'  => self::SCHEMA,
            'version' => self::VERSION,
            'colors'  => $colors
        ];
        if ($unknownColors !== []) {
            $theme['unknownColors'] = $unknownColors;
        }

        return self::normalizeTheme($theme);
    }

    /**
     * Creates one native IPSView color object.
     *
     * @return array<string,mixed>
     */
    public static function createColor(
        string $color,
        int $alpha = 255,
        int $type = 0,
        string $pattern = '12',
        bool $isEmpty = false,
        string $name = '',
        ?string $secondaryColor = null,
        ?int $secondaryAlpha = null
    ): array {
        [$red, $green, $blue] = self::hexToRgb($color);
        self::assertByte($alpha, 'A');
        if ($type < 0) {
            throw new InvalidArgumentException('IPSView native color Type must be zero or greater.');
        }

        $normalized = [
            'A'       => $alpha,
            'R'       => $red,
            'G'       => $green,
            'B'       => $blue,
            'Type'    => $type,
            'Pattern' => $pattern,
            'IsEmpty' => $isEmpty,
            'Name'    => $name
        ];

        if ($secondaryColor !== null) {
            [$red2, $green2, $blue2] = self::hexToRgb($secondaryColor);
            $secondaryAlpha ??= 255;
            self::assertByte($secondaryAlpha, 'A2');
            $normalized['A2'] = $secondaryAlpha;
            $normalized['R2'] = $red2;
            $normalized['G2'] = $green2;
            $normalized['B2'] = $blue2;
        } elseif ($secondaryAlpha !== null) {
            throw new InvalidArgumentException('Secondary alpha requires a secondary IPSView color.');
        }

        return $normalized;
    }

    /**
     * Normalizes one IPSView native color object while retaining unknown future properties.
     *
     * @return array<string,mixed>
     */
    public static function normalizeColor(mixed $color): array
    {
        if (is_string($color)) {
            return self::createColor($color);
        }
        if (is_object($color)) {
            $color = get_object_vars($color);
        }
        if (!is_array($color)) {
            throw new InvalidArgumentException('IPSView native colors must be arrays or objects.');
        }

        foreach (['R', 'G', 'B'] as $required) {
            if (!array_key_exists($required, $color)) {
                throw new InvalidArgumentException('IPSView native color is missing ' . $required . '.');
            }
        }

        $normalized = [
            'A'       => self::normalizeByte($color['A'] ?? 255, 'A'),
            'R'       => self::normalizeByte($color['R'], 'R'),
            'G'       => self::normalizeByte($color['G'], 'G'),
            'B'       => self::normalizeByte($color['B'], 'B'),
            'Type'    => self::normalizeType($color['Type'] ?? 0),
            'Pattern' => self::normalizePattern($color['Pattern'] ?? '12'),
            'IsEmpty' => self::normalizeBoolean($color['IsEmpty'] ?? false, 'IsEmpty'),
            'Name'    => self::normalizeName($color['Name'] ?? '')
        ];

        $secondaryKeys = ['A2', 'R2', 'G2', 'B2'];
        $presentSecondaryKeys = array_values(array_filter(
            $secondaryKeys,
            static fn (string $key): bool => array_key_exists($key, $color)
        ));
        if ($presentSecondaryKeys !== [] && count($presentSecondaryKeys) !== count($secondaryKeys)) {
            throw new InvalidArgumentException(
                'IPSView secondary native colors require A2, R2, G2 and B2 together.'
            );
        }
        if ($presentSecondaryKeys !== []) {
            foreach ($secondaryKeys as $key) {
                $normalized[$key] = self::normalizeByte($color[$key], $key);
            }
        }

        foreach ($color as $key => $value) {
            if (!is_string($key) || in_array($key, self::NATIVE_COLOR_KEYS, true)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** Returns one normalized native color as #RRGGBB. */
    public static function colorToHex(mixed $color): string
    {
        $color = self::normalizeColor($color);

        return sprintf('#%02X%02X%02X', $color['R'], $color['G'], $color['B']);
    }

    /** Returns the primary alpha channel as a percentage in the range 0..100. */
    public static function alphaPercent(mixed $color): float
    {
        $color = self::normalizeColor($color);

        return round(($color['A'] / 255) * 100, 3);
    }

    /**
     * Extracts all top-level native colors from a decoded IPSView document.
     *
     * Known fields, unknown future fields and the free named Colors palette are kept separate.
     *
     * @return array<string,mixed>
     */
    public static function extract(array|object $document): array
    {
        $document = is_array($document) ? $document : get_object_vars($document);
        $knownColors = [];
        $unknownColors = [];

        foreach ($document as $field => $value) {
            if (!is_string($field) || $field === 'Colors' || !self::looksLikeColor($value)) {
                continue;
            }

            $normalized = self::normalizeColor($value);
            if (self::isKnownField($field)) {
                $knownColors[$field] = $normalized;
            } else {
                $unknownColors[$field] = $normalized;
            }
        }

        $theme = [
            'schema'  => self::SCHEMA,
            'version' => self::VERSION,
            'colors'  => $knownColors
        ];
        if ($unknownColors !== []) {
            $theme['unknownColors'] = $unknownColors;
        }

        if (array_key_exists('Colors', $document)) {
            if (!is_array($document['Colors'])) {
                throw new InvalidArgumentException('IPSView named Colors must be an array.');
            }

            $namedColors = [];
            foreach ($document['Colors'] as $color) {
                if (!self::looksLikeColor($color)) {
                    throw new InvalidArgumentException('IPSView named Colors contains an invalid color entry.');
                }

                $namedColors[] = self::normalizeColor($color);
            }
            $theme['namedColors'] = $namedColors;
        }

        return self::normalizeTheme($theme);
    }

    /**
     * Validates and canonicalizes one native theme document.
     *
     * @return array<string,mixed>
     */
    public static function normalizeTheme(array $theme): array
    {
        if (($theme['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('Unsupported IPSView native theme schema.');
        }
        if (($theme['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported IPSView native theme version.');
        }
        if (!isset($theme['colors']) || !is_array($theme['colors'])) {
            throw new InvalidArgumentException('IPSView native theme colors must be an object/map.');
        }

        $knownColors = [];
        $unknownColors = [];

        foreach ($theme['colors'] as $field => $value) {
            self::assertFieldName($field);
            $normalized = self::normalizeColor($value);
            if (self::isKnownField($field)) {
                $knownColors[$field] = $normalized;
            } else {
                $unknownColors[$field] = $normalized;
            }
        }

        if (array_key_exists('unknownColors', $theme)) {
            if (!is_array($theme['unknownColors'])) {
                throw new InvalidArgumentException('IPSView unknownColors must be an object/map.');
            }

            foreach ($theme['unknownColors'] as $field => $value) {
                self::assertFieldName($field);
                if (array_key_exists($field, $knownColors) || array_key_exists($field, $unknownColors)) {
                    throw new InvalidArgumentException('Duplicate IPSView native color field: ' . $field);
                }

                $normalized = self::normalizeColor($value);
                if (self::isKnownField($field)) {
                    $knownColors[$field] = $normalized;
                } else {
                    $unknownColors[$field] = $normalized;
                }
            }
        }

        $orderedKnown = [];
        foreach (self::fields() as $field) {
            if (array_key_exists($field, $knownColors)) {
                $orderedKnown[$field] = $knownColors[$field];
            }
        }
        ksort($unknownColors, SORT_STRING);

        $normalizedTheme = [
            'schema'  => self::SCHEMA,
            'version' => self::VERSION,
            'colors'  => $orderedKnown
        ];
        if ($unknownColors !== []) {
            $normalizedTheme['unknownColors'] = $unknownColors;
        }

        if (array_key_exists('namedColors', $theme)) {
            if (!is_array($theme['namedColors'])) {
                throw new InvalidArgumentException('IPSView native theme namedColors must be an array.');
            }

            $namedColors = [];
            foreach ($theme['namedColors'] as $color) {
                $namedColors[] = self::normalizeColor($color);
            }
            $normalizedTheme['namedColors'] = $namedColors;
        }

        return $normalizedTheme;
    }

    /** Decodes one native theme JSON document. */
    public static function decode(string $json): array
    {
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
        $json = trim($json);
        if ($json === '') {
            throw new InvalidArgumentException('IPSView native theme JSON must not be empty.');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('IPSView native theme JSON must contain an object.');
        }

        return self::normalizeTheme($decoded);
    }

    /** Encodes one native theme as deterministic UTF-8 JSON. */
    public static function encode(array $theme, bool $pretty = true): string
    {
        $options = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }

        $encoded = json_encode(self::normalizeTheme($theme), $options);

        return $pretty ? $encoded . "\n" : $encoded;
    }

    /**
     * Applies a normalized native theme to a decoded stdClass IPSView document.
     *
     * By default only properties already present in the document are changed. Set createMissing
     * to true when creating a new complete IPSView document or deliberately adding newer fields.
     *
     * @return array{knownApplied:int,unknownApplied:int,namedColorsApplied:int}
     */
    public static function apply(stdClass $document, array $theme, bool $createMissing = false): array
    {
        $theme = self::normalizeTheme($theme);
        $report = [
            'knownApplied'       => 0,
            'unknownApplied'     => 0,
            'namedColorsApplied' => 0
        ];

        foreach ($theme['colors'] as $field => $color) {
            if (!$createMissing && !property_exists($document, $field)) {
                continue;
            }

            $document->{$field} = (object) $color;
            ++$report['knownApplied'];
        }

        foreach ($theme['unknownColors'] ?? [] as $field => $color) {
            if (!$createMissing && !property_exists($document, $field)) {
                continue;
            }

            $document->{$field} = (object) $color;
            ++$report['unknownApplied'];
        }

        if (array_key_exists('namedColors', $theme)
            && ($createMissing || property_exists($document, 'Colors'))) {
            $document->Colors = array_map(
                static fn (array $color): stdClass => (object) $color,
                $theme['namedColors']
            );
            $report['namedColorsApplied'] = count($theme['namedColors']);
        }

        return $report;
    }

    /** @param array<string,list<string>> $groups */
    private static function reverseGroups(array $groups): array
    {
        $reverse = [];

        foreach ($groups as $group => $fields) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $reverse)) {
                    throw new InvalidArgumentException('Duplicate IPSView native color field: ' . $field);
                }

                $reverse[$field] = $group;
            }
        }

        return $reverse;
    }

    private static function looksLikeColor(mixed $value): bool
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value)
            && array_key_exists('R', $value)
            && array_key_exists('G', $value)
            && array_key_exists('B', $value);
    }

    private static function assertFieldName(mixed $field): void
    {
        if (!is_string($field) || trim($field) === '') {
            throw new InvalidArgumentException('IPSView native color field names must be non-empty strings.');
        }
    }

    private static function normalizeByte(mixed $value, string $field): int
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('IPSView native color ' . $field . ' must be numeric.');
        }
        if (!is_finite((float) $value) || $value < 0 || $value > 255 || floor((float) $value) !== (float) $value) {
            throw new InvalidArgumentException('IPSView native color ' . $field . ' must be an integer from 0 to 255.');
        }

        return (int) $value;
    }

    private static function assertByte(int $value, string $field): void
    {
        if ($value < 0 || $value > 255) {
            throw new InvalidArgumentException('IPSView native color ' . $field . ' must be between 0 and 255.');
        }
    }

    private static function normalizeType(mixed $value): int
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('IPSView native color Type must be numeric.');
        }
        if (!is_finite((float) $value) || $value < 0 || floor((float) $value) !== (float) $value) {
            throw new InvalidArgumentException('IPSView native color Type must be a non-negative integer.');
        }

        return (int) $value;
    }

    private static function normalizePattern(mixed $value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('IPSView native color Pattern must be textual or numeric.');
        }

        return (string) $value;
    }

    private static function normalizeBoolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('IPSView native color ' . $field . ' must be boolean.');
        }

        return $value;
    }

    private static function normalizeName(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('IPSView native color Name must be a string.');
        }

        return $value;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hexToRgb(string $color): array
    {
        $color = strtoupper(trim($color));
        if (preg_match('/^#?[0-9A-F]{6}$/', $color) !== 1) {
            throw new InvalidArgumentException('IPSView semantic colors must use #RRGGBB.');
        }
        $color = ltrim($color, '#');

        return [
            hexdec(substr($color, 0, 2)),
            hexdec(substr($color, 2, 2)),
            hexdec(substr($color, 4, 2))
        ];
    }
}
