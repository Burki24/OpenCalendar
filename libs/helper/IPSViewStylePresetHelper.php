<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;

/**
 * Provides the canonical predefined IPSView semantic color presets.
 *
 * The helper owns the preset identities, labels and semantic role palettes.
 * Consumers remain responsible for deciding how those roles are applied to
 * their document or UI model.
 *
 * @version 1.0.1
 */
final class IPSViewStylePresetHelper
{
    public const PRESET_STANDARD = 'standard';
    public const PRESET_LIGHT = 'light';
    public const PRESET_DARK = 'dark';
    public const PRESET_WARM = 'warm';
    public const PRESET_COOL = 'cool';
    public const PRESET_EARTHY = 'earthy';
    public const PRESET_WATER = 'water';
    public const PRESET_SUNNY = 'sunny';

    public const ROLE_VIEW_BACKGROUND = 'viewBackground';
    public const ROLE_PAGE_BACKGROUND = 'pageBackground';
    public const ROLE_SURFACE = 'surface';
    public const ROLE_PRIMARY_TEXT = 'primaryText';
    public const ROLE_SECONDARY_TEXT = 'secondaryText';
    public const ROLE_BORDER = 'border';
    public const ROLE_ACCENT = 'accent';
    public const ROLE_ACTIVE = 'active';
    public const ROLE_INACTIVE = 'inactive';
    public const ROLE_SUCCESS = 'success';
    public const ROLE_WARNING = 'warning';
    public const ROLE_ERROR = 'error';

    /** @var array<string,string> */
    private const PRESET_LABELS = [
        self::PRESET_STANDARD => 'IPSView Standard',
        self::PRESET_LIGHT    => 'Light',
        self::PRESET_DARK     => 'Dark',
        self::PRESET_WARM     => 'Warm',
        self::PRESET_COOL     => 'Cool',
        self::PRESET_EARTHY   => 'Earthy',
        self::PRESET_WATER    => 'Water',
        self::PRESET_SUNNY    => 'Sunny'
    ];

    /** @var array<string,array<string,string>> */
    private const PRESET_PALETTES = [
        self::PRESET_STANDARD => [
            self::ROLE_VIEW_BACKGROUND => '#404040',
            self::ROLE_PAGE_BACKGROUND => '#404040',
            self::ROLE_SURFACE         => '#606060',
            self::ROLE_PRIMARY_TEXT    => '#FFFFFF',
            self::ROLE_SECONDARY_TEXT  => '#A4A4A4',
            self::ROLE_BORDER          => '#7F7F7F',
            self::ROLE_ACCENT          => '#007AFF',
            self::ROLE_ACTIVE          => '#0ABE0A',
            self::ROLE_INACTIVE        => '#BE0A0A',
            self::ROLE_SUCCESS         => '#0ABE0A',
            self::ROLE_WARNING         => '#FF0000',
            self::ROLE_ERROR           => '#BE0A0A'
        ],
        self::PRESET_LIGHT => [
            self::ROLE_VIEW_BACKGROUND => '#E9EEF5',
            self::ROLE_PAGE_BACKGROUND => '#F6F8FB',
            self::ROLE_SURFACE         => '#FFFFFF',
            self::ROLE_PRIMARY_TEXT    => '#1F2937',
            self::ROLE_SECONDARY_TEXT  => '#667085',
            self::ROLE_BORDER          => '#D0D5DD',
            self::ROLE_ACCENT          => '#2563EB',
            self::ROLE_ACTIVE          => '#16A34A',
            self::ROLE_INACTIVE        => '#98A2B3',
            self::ROLE_SUCCESS         => '#15803D',
            self::ROLE_WARNING         => '#D97706',
            self::ROLE_ERROR           => '#DC2626'
        ],
        self::PRESET_DARK => [
            self::ROLE_VIEW_BACKGROUND => '#111827',
            self::ROLE_PAGE_BACKGROUND => '#1F2937',
            self::ROLE_SURFACE         => '#273449',
            self::ROLE_PRIMARY_TEXT    => '#F9FAFB',
            self::ROLE_SECONDARY_TEXT  => '#AEB8C7',
            self::ROLE_BORDER          => '#475569',
            self::ROLE_ACCENT          => '#3B82F6',
            self::ROLE_ACTIVE          => '#22C55E',
            self::ROLE_INACTIVE        => '#64748B',
            self::ROLE_SUCCESS         => '#22C55E',
            self::ROLE_WARNING         => '#F59E0B',
            self::ROLE_ERROR           => '#EF4444'
        ],
        self::PRESET_WARM => [
            self::ROLE_VIEW_BACKGROUND => '#3B2420',
            self::ROLE_PAGE_BACKGROUND => '#4A2E27',
            self::ROLE_SURFACE         => '#5C3A31',
            self::ROLE_PRIMARY_TEXT    => '#FFF7ED',
            self::ROLE_SECONDARY_TEXT  => '#D6B8A8',
            self::ROLE_BORDER          => '#8A5A44',
            self::ROLE_ACCENT          => '#F59E0B',
            self::ROLE_ACTIVE          => '#E76F51',
            self::ROLE_INACTIVE        => '#8D6E63',
            self::ROLE_SUCCESS         => '#7BA05B',
            self::ROLE_WARNING         => '#F4A261',
            self::ROLE_ERROR           => '#D64545'
        ],
        self::PRESET_COOL => [
            self::ROLE_VIEW_BACKGROUND => '#0F1B2D',
            self::ROLE_PAGE_BACKGROUND => '#17263A',
            self::ROLE_SURFACE         => '#21354D',
            self::ROLE_PRIMARY_TEXT    => '#F1F7FF',
            self::ROLE_SECONDARY_TEXT  => '#A9BCD0',
            self::ROLE_BORDER          => '#49647E',
            self::ROLE_ACCENT          => '#38BDF8',
            self::ROLE_ACTIVE          => '#2DD4BF',
            self::ROLE_INACTIVE        => '#64748B',
            self::ROLE_SUCCESS         => '#22C55E',
            self::ROLE_WARNING         => '#FBBF24',
            self::ROLE_ERROR           => '#F87171'
        ],
        self::PRESET_EARTHY => [
            self::ROLE_VIEW_BACKGROUND => '#2D2A20',
            self::ROLE_PAGE_BACKGROUND => '#3A3528',
            self::ROLE_SURFACE         => '#4A4433',
            self::ROLE_PRIMARY_TEXT    => '#F4EBD0',
            self::ROLE_SECONDARY_TEXT  => '#C8B894',
            self::ROLE_BORDER          => '#766A4E',
            self::ROLE_ACCENT          => '#B08968',
            self::ROLE_ACTIVE          => '#7A9E5A',
            self::ROLE_INACTIVE        => '#8B7D6B',
            self::ROLE_SUCCESS         => '#6B8E4E',
            self::ROLE_WARNING         => '#D4A373',
            self::ROLE_ERROR           => '#B75D5D'
        ],
        self::PRESET_WATER => [
            self::ROLE_VIEW_BACKGROUND => '#06283D',
            self::ROLE_PAGE_BACKGROUND => '#0B3A53',
            self::ROLE_SURFACE         => '#10546D',
            self::ROLE_PRIMARY_TEXT    => '#E6F7FF',
            self::ROLE_SECONDARY_TEXT  => '#9CC9D8',
            self::ROLE_BORDER          => '#347D91',
            self::ROLE_ACCENT          => '#00B4D8',
            self::ROLE_ACTIVE          => '#48CAE4',
            self::ROLE_INACTIVE        => '#5B7F8C',
            self::ROLE_SUCCESS         => '#2EC4B6',
            self::ROLE_WARNING         => '#FFD166',
            self::ROLE_ERROR           => '#EF476F'
        ],
        self::PRESET_SUNNY => [
            self::ROLE_VIEW_BACKGROUND => '#FFF3B0',
            self::ROLE_PAGE_BACKGROUND => '#FFF9DB',
            self::ROLE_SURFACE         => '#FFFFFF',
            self::ROLE_PRIMARY_TEXT    => '#5B3A00',
            self::ROLE_SECONDARY_TEXT  => '#8A6A2B',
            self::ROLE_BORDER          => '#E7C86E',
            self::ROLE_ACCENT          => '#F59E0B',
            self::ROLE_ACTIVE          => '#84CC16',
            self::ROLE_INACTIVE        => '#C7B37A',
            self::ROLE_SUCCESS         => '#22C55E',
            self::ROLE_WARNING         => '#F97316',
            self::ROLE_ERROR           => '#DC2626'
        ]
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::PRESET_LABELS);
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return array_keys(self::PRESET_PALETTES[self::PRESET_STANDARD]);
    }

    /** @return list<array{caption:string,value:string}> */
    public static function options(): array
    {
        $options = [];
        foreach (self::PRESET_LABELS as $preset => $label) {
            $options[] = [
                'caption' => $label,
                'value'   => $preset
            ];
        }

        return $options;
    }

    /** Returns the display label for a preset, or null when unsupported. */
    public static function label(string $preset): ?string
    {
        $preset = self::normalize($preset);

        return $preset === null ? null : self::PRESET_LABELS[$preset];
    }

    /** Returns true when the preset identifier is supported. */
    public static function isValid(string $preset): bool
    {
        return self::normalize($preset) !== null;
    }

    /** Returns the normalized preset identifier or the optional fallback. */
    public static function normalize(string $preset, ?string $fallback = null): ?string
    {
        $normalized = strtolower(trim($preset));
        if (array_key_exists($normalized, self::PRESET_LABELS)) {
            return $normalized;
        }

        if ($fallback === null) {
            return null;
        }

        $normalizedFallback = strtolower(trim($fallback));

        return array_key_exists($normalizedFallback, self::PRESET_LABELS)
            ? $normalizedFallback
            : null;
    }

    /** @return array<string,string> */
    public static function palette(string $preset): array
    {
        $preset = self::normalize($preset);
        if ($preset === null) {
            throw new InvalidArgumentException('The selected IPSView style preset is not supported.');
        }

        return self::PRESET_PALETTES[$preset];
    }

    /** @return array<string,array{label:string,palette:array<string,string>}> */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::PRESET_LABELS as $preset => $label) {
            $catalog[$preset] = [
                'label'   => $label,
                'palette' => self::PRESET_PALETTES[$preset]
            ];
        }

        return $catalog;
    }
}
