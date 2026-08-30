<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;
use JsonException;

require_once __DIR__ . '/IPSViewFontCatalogHelper.php';

/**
 * Defines, validates and serializes the canonical IPSView style profile format.
 *
 * Profiles contain only portable, editable source values. Derived CSS values
 * such as contrast colors, soft role colors, gradients and box-shadow strings
 * are intentionally not stored because consumers can reproduce them from the
 * canonical source fields.
 *
 * @version 1.0.0
 */
final class IPSViewStyleProfileHelper
{
    public const SCHEMA = 'burki24.ipsview-style';
    public const VERSION = 1;
    public const FONT_SYSTEM = 'system';

    private const MAX_NAME_LENGTH = 120;
    private const MAX_DESCRIPTION_LENGTH = 1000;
    private const MAX_CREATED_BY_LENGTH = 120;

    /**
     * @var array<string, array{type: string, minimum?: int|float, maximum?: int|float}>
     */
    private const STYLE_CONTRACT = [
        'ViewBackground'            => ['type' => 'color'],
        'PageBackground'            => ['type' => 'color'],
        'LabelBackground'           => ['type' => 'color'],
        'ControlBackground'         => ['type' => 'color'],
        'ControlActiveBackground'   => ['type' => 'color'],
        'ControlInactiveBackground' => ['type' => 'color'],
        'Text'                      => ['type' => 'color'],
        'TextActive'                => ['type' => 'color'],
        'TextInactive'              => ['type' => 'color'],
        'LabelText'                 => ['type' => 'color'],
        'Icon'                      => ['type' => 'color'],
        'Border'                    => ['type' => 'color'],
        'Line'                      => ['type' => 'color'],
        'PopupBackground'           => ['type' => 'color'],
        'PopupBorder'               => ['type' => 'color'],
        'Accent'                    => ['type' => 'color'],
        'Information'               => ['type' => 'color'],
        'Positive'                  => ['type' => 'color'],
        'Warning'                   => ['type' => 'color'],
        'Critical'                  => ['type' => 'color'],
        'ShadowColor'               => ['type' => 'color'],
        'ViewBackgroundOpacity'     => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'PageBackgroundOpacity'     => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'LabelBackgroundOpacity'    => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'ControlBackgroundOpacity'  => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'ControlActiveOpacity'      => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'ControlInactiveOpacity'    => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'PopupBackgroundOpacity'    => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'BorderOpacity'             => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'LineOpacity'               => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'PopupBorderOpacity'        => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'ShadowOpacity'             => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'PopupShadowOpacity'        => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'FontFamily'                => ['type' => 'fontFamily'],
        'FontStyle'                 => ['type' => 'fontStyle'],
        'FontSize'                  => ['type' => 'integer', 'minimum' => 8, 'maximum' => 32],
        'FontScale'                 => ['type' => 'integer', 'minimum' => 60, 'maximum' => 200],
        'BorderRadius'              => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 40.0],
        'BorderWidth'               => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 10.0],
        'LineWidth'                 => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 10.0],
        'ShadowBlur'                => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 80.0],
        'ShadowSpread'              => ['type' => 'number', 'minimum' => -20.0, 'maximum' => 40.0],
        'ShadowOffsetX'             => ['type' => 'number', 'minimum' => -40.0, 'maximum' => 40.0],
        'ShadowOffsetY'             => ['type' => 'number', 'minimum' => -40.0, 'maximum' => 40.0],
        'DisabledOpacity'           => ['type' => 'integer', 'minimum' => 10, 'maximum' => 100],
        'GradientStrength'          => ['type' => 'integer', 'minimum' => 0, 'maximum' => 80]
    ];

    /** @return array{schema: string, version: int, required: list<string>, optional: list<string>, style: array<string, array{type: string, minimum?: int|float, maximum?: int|float}>} */
    public static function contract(): array
    {
        return [
            'schema'   => self::SCHEMA,
            'version'  => self::VERSION,
            'required' => ['schema', 'version', 'name', 'style'],
            'optional' => ['description', 'createdBy', 'createdAt'],
            'style'    => self::STYLE_CONTRACT
        ];
    }

    /** @return list<string> */
    public static function styleFields(): array
    {
        return array_keys(self::STYLE_CONTRACT);
    }

    /**
     * Creates and normalizes one canonical style profile.
     *
     * @param array<string,mixed> $style
     * @param array<string,mixed> $metadata Optional description, createdBy and createdAt values.
     *
     * @return array<string,mixed>
     */
    public static function create(string $name, array $style, array $metadata = []): array
    {
        $profile = [
            'schema'  => self::SCHEMA,
            'version' => self::VERSION,
            'name'    => $name
        ];

        foreach (['description', 'createdBy', 'createdAt'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $profile[$key] = $metadata[$key];
            }
        }
        $profile['style'] = $style;

        return self::normalize($profile);
    }

    /**
     * Decodes and normalizes one JSON style profile.
     *
     * Unknown fields are tolerated and discarded. Unsupported profile versions
     * are rejected so future incompatible contracts cannot be misinterpreted.
     *
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public static function decode(string $json): array
    {
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
        $json = trim($json);
        if ($json === '') {
            throw new InvalidArgumentException('IPSView style profile JSON must not be empty.');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('IPSView style profile JSON must contain an object.');
        }

        return self::normalize($decoded);
    }

    /**
     * Encodes one normalized style profile as deterministic UTF-8 JSON.
     *
     * @param array<string,mixed> $profile
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public static function encode(array $profile, bool $pretty = true): string
    {
        $options = JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION;
        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }

        $encoded = json_encode(self::normalize($profile), $options);

        return $pretty ? $encoded . "\n" : $encoded;
    }

    /** @param array<string,mixed> $profile */
    public static function isValid(array $profile): bool
    {
        try {
            self::normalize($profile);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    public static function isValidJson(string $json): bool
    {
        try {
            self::decode($json);
        } catch (InvalidArgumentException | JsonException) {
            return false;
        }

        return true;
    }

    /**
     * Validates and normalizes one profile to the canonical field order.
     *
     * @param array<string,mixed> $profile
     *
     * @return array<string,mixed>
     */
    public static function normalize(array $profile): array
    {
        if (($profile['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('Unsupported IPSView style profile schema.');
        }

        $version = $profile['version'] ?? null;
        if (!is_int($version) || $version !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported IPSView style profile version.');
        }

        $normalized = [
            'schema'  => self::SCHEMA,
            'version' => self::VERSION,
            'name'    => self::normalizeText(
                $profile['name'] ?? null,
                'name',
                self::MAX_NAME_LENGTH,
                false
            )
        ];

        if (array_key_exists('description', $profile) && $profile['description'] !== null) {
            $description = self::normalizeText(
                $profile['description'],
                'description',
                self::MAX_DESCRIPTION_LENGTH,
                true
            );
            if ($description !== '') {
                $normalized['description'] = $description;
            }
        }

        if (array_key_exists('createdBy', $profile) && $profile['createdBy'] !== null) {
            $createdBy = self::normalizeText(
                $profile['createdBy'],
                'createdBy',
                self::MAX_CREATED_BY_LENGTH,
                true
            );
            if ($createdBy !== '') {
                $normalized['createdBy'] = $createdBy;
            }
        }

        if (array_key_exists('createdAt', $profile) && $profile['createdAt'] !== null) {
            $createdAt = self::normalizeText($profile['createdAt'], 'createdAt', 40, false);
            if (!self::isAtomTimestamp($createdAt)) {
                throw new InvalidArgumentException('IPSView style profile createdAt must use an RFC 3339 timestamp with timezone.');
            }
            $normalized['createdAt'] = $createdAt;
        }

        $style = $profile['style'] ?? null;
        if (!is_array($style)) {
            throw new InvalidArgumentException('IPSView style profile style must contain an object.');
        }
        $normalized['style'] = self::normalizeStyle($style);

        return $normalized;
    }

    /**
     * Validates and normalizes the complete portable style snapshot.
     *
     * @param array<string,mixed> $style
     *
     * @return array<string,string|int|float>
     */
    public static function normalizeStyle(array $style): array
    {
        $normalized = [];
        foreach (self::STYLE_CONTRACT as $field => $definition) {
            if (!array_key_exists($field, $style)) {
                throw new InvalidArgumentException('IPSView style profile is missing style field ' . $field . '.');
            }

            $value = $style[$field];
            $normalized[$field] = match ($definition['type']) {
                'color'      => self::normalizeColor($value, $field),
                'integer'    => self::normalizeInteger(
                    $value,
                    $field,
                    (int) $definition['minimum'],
                    (int) $definition['maximum']
                ),
                'number'     => self::normalizeNumber(
                    $value,
                    $field,
                    (float) $definition['minimum'],
                    (float) $definition['maximum']
                ),
                'fontFamily' => self::normalizeFontFamily($value),
                'fontStyle'  => self::normalizeFontStyle($value, (string) ($normalized['FontFamily'] ?? '')),
                default      => throw new InvalidArgumentException('Unsupported IPSView style profile contract field type.')
            };
        }

        return $normalized;
    }

    private static function normalizeText(mixed $value, string $field, int $maximumLength, bool $allowEmpty): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('IPSView style profile ' . $field . ' must be a string.');
        }

        $value = trim($value);
        if (!$allowEmpty && $value === '') {
            throw new InvalidArgumentException('IPSView style profile ' . $field . ' must not be empty.');
        }
        if (strlen($value) > $maximumLength) {
            throw new InvalidArgumentException('IPSView style profile ' . $field . ' is too long.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            throw new InvalidArgumentException('IPSView style profile ' . $field . ' contains invalid control characters.');
        }

        return $value;
    }

    private static function normalizeColor(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^#[0-9A-Fa-f]{6}$/', trim($value)) !== 1) {
            throw new InvalidArgumentException('IPSView style profile ' . $field . ' must be a #RRGGBB color.');
        }

        return strtoupper(trim($value));
    }

    private static function normalizeInteger(mixed $value, string $field, int $minimum, int $maximum): int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(
                sprintf('IPSView style profile %s must be an integer between %d and %d.', $field, $minimum, $maximum)
            );
        }

        return $value;
    }

    private static function normalizeNumber(mixed $value, string $field, float $minimum, float $maximum): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('IPSView style profile ' . $field . ' must be numeric.');
        }

        $value = (float) $value;
        if (!is_finite($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(
                sprintf('IPSView style profile %s must be between %s and %s.', $field, $minimum, $maximum)
            );
        }

        return $value;
    }

    private static function normalizeFontFamily(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('IPSView style profile FontFamily must be a string.');
        }

        $fontFamily = trim($value);
        $systemLookup = strtolower(preg_replace('/\s+/', ' ', $fontFamily) ?? '');
        if (
            $fontFamily === ''
            || $systemLookup === self::FONT_SYSTEM
            || $systemLookup === '-apple-system, blinkmacsystemfont, "segoe ui", sans-serif'
        ) {
            return self::FONT_SYSTEM;
        }

        $normalized = IPSViewFontCatalogHelper::normalizeFamily($fontFamily);
        if ($normalized === null) {
            throw new InvalidArgumentException('IPSView style profile FontFamily is not supported.');
        }

        return $normalized;
    }

    private static function normalizeFontStyle(mixed $value, string $fontFamily): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('IPSView style profile FontStyle must be a string.');
        }

        if ($fontFamily === self::FONT_SYSTEM) {
            $lookup = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
            $style = match ($lookup) {
                'regular', 'normal'         => IPSViewFontCatalogHelper::STYLE_REGULAR,
                'bold'                      => IPSViewFontCatalogHelper::STYLE_BOLD,
                'italic'                    => IPSViewFontCatalogHelper::STYLE_ITALIC,
                'bolditalic', 'bold italic' => IPSViewFontCatalogHelper::STYLE_BOLD_ITALIC,
                default                     => null
            };
            if ($style === null) {
                throw new InvalidArgumentException('IPSView style profile FontStyle is not supported.');
            }

            return $style;
        }

        $style = IPSViewFontCatalogHelper::normalizeStyle($fontFamily, $value, null);
        if ($style === null) {
            throw new InvalidArgumentException('IPSView style profile FontStyle is not available for the selected FontFamily.');
        }

        return $style;
    }

    private static function isAtomTimestamp(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return false;
        }

        try {
            new \DateTimeImmutable($value);
        } catch (\Exception) {
            return false;
        }

        return true;
    }
}
