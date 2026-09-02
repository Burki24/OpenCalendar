<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;
use JsonException;
use stdClass;

require_once __DIR__ . '/IPSViewStyleHelper.php';
require_once __DIR__ . '/IPSViewControlThemeHelper.php';

/**
 * Extends the shared IPSView style configuration with optional native color overrides.
 *
 * Consumers keep using RegisterIPSViewStyleProperties(), IPSViewStyleFormItems() and
 * InsertIPSViewStyleFormItems(). The base style stays compact and portable while the
 * collapsed advanced section exposes all known native IPSView color fields grouped by family.
 * Disabled native overrides always inherit their current semantic base color.
 *
 * @version 1.0.4
 */
trait IPSViewStyleConfigurationHelper
{
    use IPSViewStyleHelper {
        RegisterIPSViewStyleProperties as private RegisterIPSViewBaseStyleProperties;
        IPSViewStyleFormItems as private IPSViewBaseStyleFormItems;
        IPSViewResolvedStyle as private IPSViewBaseResolvedStyle;
    }

    private const IPSVIEW_NATIVE_FORM_PANEL = 'IPSViewStyleNativeColorsPanel';

    /** @var array<string,string> */
    private const IPSVIEW_NATIVE_OVERRIDE_PROPERTIES = [
        IPSViewControlThemeHelper::FAMILY_BASE        => 'IPSViewStyleNativeBaseColors',
        IPSViewControlThemeHelper::FAMILY_ASSOCIATION => 'IPSViewStyleNativeAssociationColors',
        IPSViewControlThemeHelper::FAMILY_TABS        => 'IPSViewStyleNativeTabColors',
        IPSViewControlThemeHelper::FAMILY_SWITCH      => 'IPSViewStyleNativeSwitchColors',
        IPSViewControlThemeHelper::FAMILY_SLIDER      => 'IPSViewStyleNativeSliderColors',
        IPSViewControlThemeHelper::FAMILY_PROGRESSBAR => 'IPSViewStyleNativeProgressbarColors',
        IPSViewControlThemeHelper::FAMILY_CIRCLE      => 'IPSViewStyleNativeCircleColors',
        IPSViewControlThemeHelper::FAMILY_FLOW        => 'IPSViewStyleNativeFlowColors',
        IPSViewControlThemeHelper::FAMILY_GAUGE       => 'IPSViewStyleNativeGaugeColors',
        IPSViewControlThemeHelper::FAMILY_SHADOW_GRID => 'IPSViewStyleNativeShadowGridColors',
        IPSViewControlThemeHelper::FAMILY_DIALOG      => 'IPSViewStyleNativeDialogColors',
        IPSViewControlThemeHelper::FAMILY_CHART       => 'IPSViewStyleNativeChartColors',
        IPSViewControlThemeHelper::FAMILY_SCHEDULE    => 'IPSViewStyleNativeScheduleColors',
        IPSViewControlThemeHelper::FAMILY_EVENT       => 'IPSViewStyleNativeEventColors',
        IPSViewControlThemeHelper::FAMILY_CALENDAR    => 'IPSViewStyleNativeCalendarColors'
    ];

    /** @var array<string,string> */
    private const IPSVIEW_NATIVE_FAMILY_LABELS = [
        IPSViewControlThemeHelper::FAMILY_BASE        => 'Base colors',
        IPSViewControlThemeHelper::FAMILY_ASSOCIATION => 'Associations',
        IPSViewControlThemeHelper::FAMILY_TABS        => 'Tabs',
        IPSViewControlThemeHelper::FAMILY_SWITCH      => 'Switches',
        IPSViewControlThemeHelper::FAMILY_SLIDER      => 'Sliders',
        IPSViewControlThemeHelper::FAMILY_PROGRESSBAR => 'Progress bars',
        IPSViewControlThemeHelper::FAMILY_CIRCLE      => 'Circle controls',
        IPSViewControlThemeHelper::FAMILY_FLOW        => 'Flow controls',
        IPSViewControlThemeHelper::FAMILY_GAUGE       => 'Gauges',
        IPSViewControlThemeHelper::FAMILY_SHADOW_GRID => 'Shadows and grid',
        IPSViewControlThemeHelper::FAMILY_DIALOG      => 'Dialogs',
        IPSViewControlThemeHelper::FAMILY_CHART       => 'Charts',
        IPSViewControlThemeHelper::FAMILY_SCHEDULE    => 'Schedules',
        IPSViewControlThemeHelper::FAMILY_EVENT       => 'Events',
        IPSViewControlThemeHelper::FAMILY_CALENDAR    => 'Calendar'
    ];

    /** @var array<string,string> */
    private const IPSVIEW_NATIVE_STYLE_FIELD_LABEL_KEYS = [
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
        'ShadowColor'               => 'color.shadow'
    ];

    /** Registers the shared style plus all native override lists. */
    protected function RegisterIPSViewStyleProperties(): void
    {
        $this->RegisterIPSViewBaseStyleProperties();

        foreach (self::IPSVIEW_NATIVE_OVERRIDE_PROPERTIES as $propertyName) {
            $this->RegisterPropertyString($propertyName, '[]');
        }
    }

    /**
     * Returns the shared style form with one collapsed advanced native-color section.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function IPSViewStyleFormItems(string $colorWidth = '240px'): array
    {
        $items = $this->IPSViewBaseStyleFormItems($colorWidth);
        $style = $this->IPSViewResolvedStyle();
        if ($this->IPSViewStyleSource() === self::IPSVIEW_STYLE_SOURCE_MEDIA) {
            $this->IPSViewStyleConfigurationPatchMediaForm($items, $style);
        }
        $editable = $this->IPSViewStyleSource() === self::IPSVIEW_STYLE_SOURCE_CUSTOM;
        $familyItems = [
            [
                'type'    => 'Label',
                'caption' => $this->IPSViewStyleConfigurationText(
                    'description.native_overrides',
                    'Optional native overrides are derived from the base colors above. Enable only the individual IPSView fields that should use a different color.'
                )
            ]
        ];

        foreach (IPSViewControlThemeHelper::families() as $family => $fields) {
            $familyItems[] = [
                'type'     => 'ExpansionPanel',
                'name'     => 'IPSViewStyleNativeFamily_' . $family,
                'caption'  => $this->IPSViewStyleConfigurationText(
                    'family.' . $family,
                    self::IPSVIEW_NATIVE_FAMILY_LABELS[$family] ?? $family
                ),
                'expanded' => false,
                'items'    => [
                    $this->IPSViewStyleNativeFamilyList($family, $fields, $style, $editable)
                ]
            ];
        }

        $items[] = [
            'type'     => 'ExpansionPanel',
            'name'     => self::IPSVIEW_NATIVE_FORM_PANEL,
            'caption'  => $this->IPSViewStyleConfigurationText(
                'section.native_colors',
                'Advanced IPSView colors'
            ),
            'expanded' => false,
            'items'    => $familyItems
        ];

        return $items;
    }

    /**
     * Resolves the shared style while preserving native IPSView View/Page semantics.
     *
     * Current IPSView documents may omit ColorView until the View color differs from
     * the IPSView default. In that case the View background is #404040; ColorPage
     * remains the independent page background and is never used as a View fallback.
     *
     * @return array<string,string|float>
     */
    protected function IPSViewResolvedStyle(?string $document = null): array
    {
        $style = $this->IPSViewBaseResolvedStyle($document);
        if ($this->IPSViewStyleSource() !== self::IPSVIEW_STYLE_SOURCE_MEDIA) {
            return $style;
        }

        $source = $document ?? $this->ReadIPSViewStyleMediaContent();
        $decoded = $this->IPSViewStyleConfigurationDecodeDocument($source);
        if ($decoded === null) {
            return $style;
        }

        $viewColor = $this->IPSViewStyleConfigurationNativeColorToCSS($decoded['ColorView'] ?? null);
        if ($viewColor === null) {
            $viewColor = '#404040';
        }

        $style['ViewBackground'] = $viewColor;
        $style['ViewBackgroundOpacity'] = $this->IPSViewStyleConfigurationColorAlpha($viewColor);

        return $style;
    }

    /**
     * Returns only enabled native color overrides of the active custom style.
     *
     * @return array<string,string> Native field => #RRGGBB.
     */
    protected function IPSViewStyleNativeColorOverrides(): array
    {
        if ($this->IPSViewStyleSource() !== self::IPSVIEW_STYLE_SOURCE_CUSTOM) {
            return [];
        }

        $overrides = [];
        foreach (self::IPSVIEW_NATIVE_OVERRIDE_PROPERTIES as $family => $propertyName) {
            foreach ($this->IPSViewStyleNativeStoredRows($propertyName) as $row) {
                $field = isset($row['Field']) && is_string($row['Field']) ? trim($row['Field']) : '';
                if ($field === '' || !IPSViewControlThemeHelper::isKnownField($field)) {
                    continue;
                }

                $definition = IPSViewControlThemeHelper::definition($field);
                if (($definition['family'] ?? null) !== $family || !$this->IPSViewStyleNativeBoolean($row['Override'] ?? false)) {
                    continue;
                }

                $overrides[$field] = $this->IPSViewStyleConfigurationColorToHex($row['Color'] ?? null);
            }
        }

        return $overrides;
    }

    /**
     * Resolves the complete known native IPSView color theme from the active shared style.
     *
     * @return array<string,mixed>
     */
    protected function IPSViewStyleNativeTheme(?string $document = null): array
    {
        $style = $this->IPSViewResolvedStyle($document);
        $styleColors = [];

        foreach (IPSViewControlThemeHelper::requiredStyleFields() as $styleField) {
            $styleColors[$styleField] = $this->IPSViewStyleNativeSourceColor($style, $styleField);
        }

        return IPSViewControlThemeHelper::fromStyleColors(
            $styleColors,
            $this->IPSViewStyleNativeColorOverrides()
        );
    }

    /**
     * Applies the active shared native color theme to an IPSView document.
     *
     * @return array{knownApplied:int,unknownApplied:int,namedColorsApplied:int}
     */
    protected function ApplyIPSViewStyleNativeTheme(
        stdClass $document,
        ?string $styleDocument = null,
        bool $createMissing = false
    ): array {
        return IPSViewControlThemeHelper::apply(
            $document,
            $this->IPSViewStyleNativeTheme($styleDocument),
            $createMissing
        );
    }

    /** @return array<string,string> */
    protected function IPSViewStyleNativeOverrideProperties(): array
    {
        return self::IPSVIEW_NATIVE_OVERRIDE_PROPERTIES;
    }

    /**
     * @param list<string>              $fields
     * @param array<string,string|float> $style
     *
     * @return array<string,mixed>
     */
    private function IPSViewStyleNativeFamilyList(
        string $family,
        array $fields,
        array $style,
        bool $editable
    ): array {
        $propertyName = self::IPSVIEW_NATIVE_OVERRIDE_PROPERTIES[$family] ?? null;
        if ($propertyName === null) {
            throw new InvalidArgumentException('Unsupported IPSView native color family: ' . $family);
        }

        $stored = [];
        if ($editable) {
            foreach ($this->IPSViewStyleNativeStoredRows($propertyName) as $row) {
                $field = isset($row['Field']) && is_string($row['Field']) ? trim($row['Field']) : '';
                if ($field !== '') {
                    $stored[$field] = $row;
                }
            }
        }

        $values = [];
        foreach ($fields as $field) {
            $definition = IPSViewControlThemeHelper::definition($field);
            if ($definition === null) {
                continue;
            }

            $styleField = $definition['styleField'];
            $baseColor = $this->IPSViewStyleConfigurationColorToInt(
                $this->IPSViewStyleNativeSourceColor($style, $styleField)
            );
            $row = $stored[$field] ?? [];
            $override = $editable && $this->IPSViewStyleNativeBoolean($row['Override'] ?? false);
            $color = $override
                ? $this->IPSViewStyleConfigurationColorToInt($row['Color'] ?? $baseColor)
                : $baseColor;

            $values[] = [
                'Override'    => $override,
                'Field'       => $field,
                'DerivedFrom' => $this->IPSViewStyleNativeStyleFieldCaption($styleField),
                'Color'       => $color
            ];
        }

        $overrideColumn = [
            'caption' => $this->IPSViewStyleConfigurationText('column.override', 'Override'),
            'name'    => 'Override',
            'width'   => '90px'
        ];
        $colorColumn = [
            'caption' => $this->IPSViewStyleConfigurationText('column.color', 'Color'),
            'name'    => 'Color',
            'width'   => '150px'
        ];
        if ($editable) {
            $overrideColumn['edit'] = ['type' => 'CheckBox'];
            $colorColumn['edit'] = [
                'type'             => 'SelectColor',
                'allowTransparent' => false
            ];
        }

        return [
            'type'        => 'List',
            'name'        => $propertyName,
            'caption'     => '',
            'rowCount'    => min(8, max(3, count($values))),
            'add'         => false,
            'delete'      => false,
            'changeOrder' => false,
            'values'      => $values,
            'columns'     => [
                $overrideColumn,
                [
                    'caption' => $this->IPSViewStyleConfigurationText('column.field', 'IPSView field'),
                    'name'    => 'Field',
                    'width'   => '280px'
                ],
                [
                    'caption' => $this->IPSViewStyleConfigurationText('column.derived_from', 'Derived from'),
                    'name'    => 'DerivedFrom',
                    'width'   => '220px'
                ],
                $colorColumn
            ]
        ];
    }

    /**
     * Corrects the two read-only View values generated by the base helper for an IPSView media source.
     *
     * The base helper predates the verified ColorView/ColorPage separation. Keeping this adapter in
     * the shared configuration layer makes the common form and its "copy to custom" action follow
     * the same native semantics as the resolved style until all direct base-helper consumers migrate.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<string,string|float>      $style
     */
    private function IPSViewStyleConfigurationPatchMediaForm(array &$items, array $style): void
    {
        $viewColor = $this->IPSViewStyleConfigurationColorToInt($style['ViewBackground'] ?? '#404040');
        $viewOpacity = (int) round(
            max(0.0, min(1.0, (float) ($style['ViewBackgroundOpacity'] ?? 1.0))) * 100
        );
        $viewCaption = $this->IPSViewStyleText('color.view_background');
        $viewOpacityCaption = $this->IPSViewStyleText('opacity.view_background');
        $copyCaption = $this->IPSViewStyleText('action.copy_to_custom');

        foreach ($items as &$item) {
            if (($item['type'] ?? null) === 'SelectColor' && ($item['caption'] ?? null) === $viewCaption) {
                $item['value'] = $viewColor;
            } elseif (($item['type'] ?? null) === 'NumberSpinner' && ($item['caption'] ?? null) === $viewOpacityCaption) {
                $item['value'] = $viewOpacity;
            } elseif (($item['type'] ?? null) === 'Button' && ($item['caption'] ?? null) === $copyCaption) {
                $this->IPSViewStyleConfigurationPatchCopyScript($item, $viewColor, $viewOpacity);
            }

            if (isset($item['items']) && is_array($item['items'])) {
                $this->IPSViewStyleConfigurationPatchMediaForm($item['items'], $style);
            }
        }
        unset($item);
    }

    /** @param array<string,mixed> $button */
    private function IPSViewStyleConfigurationPatchCopyScript(array &$button, int $viewColor, int $viewOpacity): void
    {
        if (!isset($button['onClick']) || !is_array($button['onClick'])) {
            return;
        }

        foreach ($button['onClick'] as &$line) {
            if (!is_string($line)) {
                continue;
            }
            if (str_contains($line, "'IPSViewStyleViewBackgroundColor'")) {
                $line = sprintf(
                    'IPS_SetProperty($id, %s, %d);',
                    var_export('IPSViewStyleViewBackgroundColor', true),
                    $viewColor
                );
            } elseif (str_contains($line, "'IPSViewStyleViewBackgroundOpacity'")) {
                $line = sprintf(
                    'IPS_SetProperty($id, %s, %d);',
                    var_export('IPSViewStyleViewBackgroundOpacity', true),
                    $viewOpacity
                );
            }
        }
        unset($line);
    }

    /** @return list<array<string,mixed>> */
    private function IPSViewStyleNativeStoredRows(string $propertyName): array
    {
        $json = trim($this->ReadPropertyString($propertyName));
        if ($json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /** @param array<string,string|float> $style */
    private function IPSViewStyleNativeSourceColor(array $style, string $styleField): string
    {
        if (array_key_exists($styleField, $style)) {
            return $this->IPSViewStyleConfigurationColorToHex($style[$styleField]);
        }

        if ($styleField === 'ShadowColor' && is_string($style['Shadow'] ?? null)) {
            $shadow = trim($style['Shadow']);
            if (preg_match(
                '/(#[0-9A-Fa-f]{6}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*[0-9.]+)?\s*\))\s*$/i',
                $shadow,
                $matches
            ) === 1) {
                return $this->IPSViewStyleConfigurationColorToHex($matches[1]);
            }
        }

        throw new InvalidArgumentException(
            'Resolved IPSView style is missing native source field ' . $styleField . '.'
        );
    }

    /** @return array<string,mixed>|null */
    private function IPSViewStyleConfigurationDecodeDocument(string $document): ?array
    {
        $document = trim($document);
        if ($document === '') {
            return null;
        }

        $decoded = json_decode($document, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $raw = base64_decode($document, true);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function IPSViewStyleConfigurationNativeColorToCSS(mixed $color): ?string
    {
        if (!is_array($color)) {
            return null;
        }
        foreach (['A', 'R', 'G', 'B'] as $channel) {
            if (!isset($color[$channel]) || !is_int($color[$channel])) {
                return null;
            }
            if ($color[$channel] < 0 || $color[$channel] > 255) {
                return null;
            }
        }

        if ($color['A'] >= 255) {
            return sprintf('#%02X%02X%02X', $color['R'], $color['G'], $color['B']);
        }

        return sprintf(
            'rgba(%d, %d, %d, %.3f)',
            $color['R'],
            $color['G'],
            $color['B'],
            $color['A'] / 255
        );
    }

    private function IPSViewStyleConfigurationColorAlpha(string $color): float
    {
        if (preg_match('/^rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*([0-9.]+)\s*\)$/i', $color, $matches) === 1) {
            return max(0.0, min(1.0, (float) $matches[1]));
        }

        return 1.0;
    }

    private function IPSViewStyleNativeStyleFieldCaption(string $styleField): string
    {
        $key = self::IPSVIEW_NATIVE_STYLE_FIELD_LABEL_KEYS[$styleField] ?? null;
        if ($key === null) {
            return $styleField;
        }

        return $this->IPSViewStyleText($key);
    }

    private function IPSViewStyleConfigurationText(string $key, string $fallback): string
    {
        return $this->TranslateHelperText(
            'IPSViewStyleConfigurationHelper',
            $key,
            $fallback
        );
    }

    private function IPSViewStyleConfigurationColorToHex(mixed $color): string
    {
        if (is_int($color) || is_float($color)) {
            $value = max(0, min(0xFFFFFF, (int) $color));

            return sprintf('#%06X', $value);
        }
        if (!is_string($color)) {
            return '#000000';
        }

        $color = trim($color);
        if (preg_match('/^#?([0-9A-Fa-f]{6})$/', $color, $matches) === 1) {
            return '#' . strtoupper($matches[1]);
        }
        if (preg_match(
            '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*[0-9.]+)?\s*\)$/i',
            $color,
            $matches
        ) === 1) {
            return sprintf(
                '#%02X%02X%02X',
                max(0, min(255, (int) $matches[1])),
                max(0, min(255, (int) $matches[2])),
                max(0, min(255, (int) $matches[3]))
            );
        }

        return '#000000';
    }

    private function IPSViewStyleConfigurationColorToInt(mixed $color): int
    {
        return hexdec(substr($this->IPSViewStyleConfigurationColorToHex($color), 1));
    }

    private function IPSViewStyleNativeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
