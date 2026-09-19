<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

/**
 * Provides the canonical IPSView font catalogue and supported font cuts.
 *
 * The catalogue also renders self-contained @font-face rules for standalone
 * HTML pages. Only the selected font cut is embedded in the generated CSS.
 *
 * @version 1.1.0
 */
final class IPSViewFontCatalogHelper
{
    public const FONT_ROBOTO = 'Roboto';
    public const FONT_ROBOTO_MONO = 'RobotoMono';
    public const FONT_DANCING_SCRIPT = 'DancingScript';
    public const FONT_INDIE_FLOWER = 'IndieFlower';
    public const FONT_OPEN_SANS = 'OpenSans';
    public const FONT_PT_SANS = 'PTSans';
    public const FONT_BEBAS_NEUE = 'BebasNeue';
    public const FONT_SEGMENT_7 = 'Segment7';

    public const STYLE_REGULAR = 'regular';
    public const STYLE_BOLD = 'bold';
    public const STYLE_ITALIC = 'italic';
    public const STYLE_BOLD_ITALIC = 'boldItalic';

    /**
     * @var array<string, array{label: string, bold: bool, italic: bool}>
     */
    private const FONT_CATALOG = [
        self::FONT_ROBOTO         => [
            'label'  => 'Roboto',
            'bold'   => true,
            'italic' => true
        ],
        self::FONT_ROBOTO_MONO    => [
            'label'  => 'Roboto Mono',
            'bold'   => true,
            'italic' => true
        ],
        self::FONT_DANCING_SCRIPT => [
            'label'  => 'Dancing Script',
            'bold'   => true,
            'italic' => false
        ],
        self::FONT_INDIE_FLOWER   => [
            'label'  => 'Indie Flower',
            'bold'   => false,
            'italic' => false
        ],
        self::FONT_OPEN_SANS      => [
            'label'  => 'Open Sans',
            'bold'   => true,
            'italic' => true
        ],
        self::FONT_PT_SANS        => [
            'label'  => 'PT Sans',
            'bold'   => true,
            'italic' => true
        ],
        self::FONT_BEBAS_NEUE     => [
            'label'  => 'Bebas Neue',
            'bold'   => false,
            'italic' => false
        ],
        self::FONT_SEGMENT_7      => [
            'label'  => 'Segment7',
            'bold'   => false,
            'italic' => false
        ]
    ];

    /**
     * @var array<string, array{fallback: string, faces: array<string, array{filename: string, style: string, weight: int}>}>
     */
    private const FONT_FACES = [
        self::FONT_ROBOTO => [
            'fallback' => 'sans-serif',
            'faces'    => [
                self::STYLE_REGULAR     => ['filename' => 'Roboto-Regular.ttf', 'style' => 'normal', 'weight' => 400],
                self::STYLE_BOLD        => ['filename' => 'Roboto-Bold.ttf', 'style' => 'normal', 'weight' => 700],
                self::STYLE_ITALIC      => ['filename' => 'Roboto-RegularItalic.ttf', 'style' => 'italic', 'weight' => 400],
                self::STYLE_BOLD_ITALIC => ['filename' => 'Roboto-BoldItalic.ttf', 'style' => 'italic', 'weight' => 700]
            ]
        ],
        self::FONT_ROBOTO_MONO => [
            'fallback' => 'monospace',
            'faces'    => [
                self::STYLE_REGULAR     => ['filename' => 'RobotoMono-Regular.ttf', 'style' => 'normal', 'weight' => 400],
                self::STYLE_BOLD        => ['filename' => 'RobotoMono-Bold.ttf', 'style' => 'normal', 'weight' => 700],
                self::STYLE_ITALIC      => ['filename' => 'RobotoMono-RegularItalic.ttf', 'style' => 'italic', 'weight' => 400],
                self::STYLE_BOLD_ITALIC => ['filename' => 'RobotoMono-BoldItalic.ttf', 'style' => 'italic', 'weight' => 700]
            ]
        ],
        self::FONT_DANCING_SCRIPT => [
            'fallback' => 'cursive',
            'faces'    => [
                self::STYLE_REGULAR => ['filename' => 'DancingScript-Regular.ttf', 'style' => 'normal', 'weight' => 400],
                self::STYLE_BOLD    => ['filename' => 'DancingScript-Bold.ttf', 'style' => 'normal', 'weight' => 700]
            ]
        ],
        self::FONT_INDIE_FLOWER => [
            'fallback' => 'cursive',
            'faces'    => [
                self::STYLE_REGULAR => ['filename' => 'IndieFlower-Regular.ttf', 'style' => 'normal', 'weight' => 400]
            ]
        ],
        self::FONT_OPEN_SANS => [
            'fallback' => 'sans-serif',
            'faces'    => [
                self::STYLE_REGULAR     => ['filename' => 'OpenSans-Regular.ttf', 'style' => 'normal', 'weight' => 400],
                self::STYLE_BOLD        => ['filename' => 'OpenSans-Bold.ttf', 'style' => 'normal', 'weight' => 700],
                self::STYLE_ITALIC      => ['filename' => 'OpenSans-RegularItalic.ttf', 'style' => 'italic', 'weight' => 400],
                self::STYLE_BOLD_ITALIC => ['filename' => 'OpenSans-BoldItalic.ttf', 'style' => 'italic', 'weight' => 700]
            ]
        ],
        self::FONT_PT_SANS => [
            'fallback' => 'sans-serif',
            'faces'    => [
                self::STYLE_REGULAR     => ['filename' => 'PTSans-Regular.ttf', 'style' => 'normal', 'weight' => 400],
                self::STYLE_BOLD        => ['filename' => 'PTSans-Bold.ttf', 'style' => 'normal', 'weight' => 700],
                self::STYLE_ITALIC      => ['filename' => 'PTSans-RegularItalic.ttf', 'style' => 'italic', 'weight' => 400],
                self::STYLE_BOLD_ITALIC => ['filename' => 'PTSans-BoldItalic.ttf', 'style' => 'italic', 'weight' => 700]
            ]
        ],
        self::FONT_BEBAS_NEUE => [
            'fallback' => 'sans-serif',
            'faces'    => [
                self::STYLE_REGULAR => ['filename' => 'BebasNeue-Regular.ttf', 'style' => 'normal', 'weight' => 400]
            ]
        ],
        self::FONT_SEGMENT_7 => [
            'fallback' => 'monospace',
            'faces'    => [
                self::STYLE_REGULAR => ['filename' => 'Segment7-Regular.ttf', 'style' => 'normal', 'weight' => 400]
            ]
        ]
    ];

    /** @var array<string, string> */
    private const FAMILY_ALIASES = [
        'roboto'         => self::FONT_ROBOTO,
        'robotomono'     => self::FONT_ROBOTO_MONO,
        'roboto mono'    => self::FONT_ROBOTO_MONO,
        'dancingscript'  => self::FONT_DANCING_SCRIPT,
        'dancing script' => self::FONT_DANCING_SCRIPT,
        'indieflower'    => self::FONT_INDIE_FLOWER,
        'indie flower'   => self::FONT_INDIE_FLOWER,
        'opensans'       => self::FONT_OPEN_SANS,
        'open sans'      => self::FONT_OPEN_SANS,
        'ptsans'         => self::FONT_PT_SANS,
        'pt sans'        => self::FONT_PT_SANS,
        'bebasneue'      => self::FONT_BEBAS_NEUE,
        'bebas neue'     => self::FONT_BEBAS_NEUE,
        'segment7'       => self::FONT_SEGMENT_7,
        'segment 7'      => self::FONT_SEGMENT_7
    ];

    /** @var array<string, string> */
    private const STYLE_ALIASES = [
        'regular'     => self::STYLE_REGULAR,
        'normal'      => self::STYLE_REGULAR,
        'bold'        => self::STYLE_BOLD,
        'italic'      => self::STYLE_ITALIC,
        'bolditalic'  => self::STYLE_BOLD_ITALIC,
        'bold italic' => self::STYLE_BOLD_ITALIC
    ];

    /** @var array<string, string> */
    private static array $fontData = [];

    /**
     * Returns the complete immutable font catalogue.
     *
     * @return array<string, array{label: string, bold: bool, italic: bool}>
     */
    public static function catalog(): array
    {
        return self::FONT_CATALOG;
    }

    /**
     * Returns all canonical IPSView font-family identifiers.
     *
     * @return list<string>
     */
    public static function families(): array
    {
        return array_keys(self::FONT_CATALOG);
    }

    /**
     * Returns generic Select options using canonical IPSView family values.
     *
     * @return list<array{caption: string, value: string}>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::FONT_CATALOG as $family => $font) {
            $options[] = [
                'caption' => $font['label'],
                'value'   => $family
            ];
        }

        return $options;
    }

    /** Returns the display label of a known IPSView font family. */
    public static function label(string $fontFamily): ?string
    {
        $fontFamily = self::normalizeFamily($fontFamily);

        return $fontFamily === null ? null : self::FONT_CATALOG[$fontFamily]['label'];
    }

    /**
     * Returns the bold/italic capabilities of a known family.
     *
     * Unknown families intentionally return null so a consumer can decide
     * whether a system or custom font should remain unrestricted.
     *
     * @return array{bold: bool, italic: bool, boldItalic: bool}|null
     */
    public static function capabilities(string $fontFamily): ?array
    {
        $fontFamily = self::normalizeFamily($fontFamily);
        if ($fontFamily === null) {
            return null;
        }

        $font = self::FONT_CATALOG[$fontFamily];

        return [
            'bold'       => $font['bold'],
            'italic'     => $font['italic'],
            'boldItalic' => $font['bold'] && $font['italic']
        ];
    }

    /**
     * Returns all available font cuts for a known family.
     *
     * @return list<string>
     */
    public static function styles(string $fontFamily): array
    {
        $capabilities = self::capabilities($fontFamily);
        if ($capabilities === null) {
            return [];
        }

        $styles = [self::STYLE_REGULAR];
        if ($capabilities['bold']) {
            $styles[] = self::STYLE_BOLD;
        }
        if ($capabilities['italic']) {
            $styles[] = self::STYLE_ITALIC;
        }
        if ($capabilities['boldItalic']) {
            $styles[] = self::STYLE_BOLD_ITALIC;
        }

        return $styles;
    }

    /** Returns true when the supplied family belongs to the IPSView catalogue. */
    public static function isValidFamily(string $fontFamily): bool
    {
        return self::normalizeFamily($fontFamily) !== null;
    }

    /** Returns true when the supplied cut exists for the selected family. */
    public static function isValidStyle(string $fontFamily, string $fontStyle): bool
    {
        $fontStyle = self::normalizeStyleName($fontStyle);

        return $fontStyle !== null && in_array($fontStyle, self::styles($fontFamily), true);
    }

    /**
     * Normalizes family aliases to the canonical IPSView identifier.
     *
     * The optional fallback is normalized with the same rules and is returned
     * only when the requested family is unknown.
     */
    public static function normalizeFamily(string $fontFamily, ?string $fallback = null): ?string
    {
        $normalized = self::normalizeLookupValue($fontFamily);
        if ($normalized !== '' && isset(self::FAMILY_ALIASES[$normalized])) {
            return self::FAMILY_ALIASES[$normalized];
        }

        if ($fallback === null) {
            return null;
        }

        $normalizedFallback = self::normalizeLookupValue($fallback);

        return $normalizedFallback === '' ? null : self::FAMILY_ALIASES[$normalizedFallback] ?? null;
    }

    /**
     * Normalizes a cut and validates that it exists for the selected family.
     */
    public static function normalizeStyle(
        string $fontFamily,
        string $fontStyle,
        ?string $fallback = self::STYLE_REGULAR
    ): ?string {
        $normalized = self::normalizeStyleName($fontStyle);
        if ($normalized !== null && self::isValidStyle($fontFamily, $normalized)) {
            return $normalized;
        }

        if ($fallback === null) {
            return null;
        }

        $normalizedFallback = self::normalizeStyleName($fallback);
        if ($normalizedFallback === null || !self::isValidStyle($fontFamily, $normalizedFallback)) {
            return null;
        }

        return $normalizedFallback;
    }

    /**
     * Returns a CSS font-family value with a generic fallback for a known font.
     *
     * Unknown or custom values are returned unchanged for backwards
     * compatibility with existing consumer configurations.
     */
    public static function cssFamily(string $fontFamily): string
    {
        $normalized = self::normalizeFamily($fontFamily);
        if ($normalized === null) {
            return $fontFamily;
        }

        return sprintf('"%s", %s', $normalized, self::FONT_FACES[$normalized]['fallback']);
    }

    /**
     * Renders a self-contained @font-face rule for the selected catalogue cut.
     *
     * An empty string is returned for system/custom fonts or when a bundled
     * asset is unavailable, allowing existing consumers to retain their
     * browser fallback behavior.
     */
    public static function fontFaceCSS(string $fontFamily, string $fontStyle): string
    {
        $fontFamily = self::normalizeFamily($fontFamily);
        if ($fontFamily === null) {
            return '';
        }

        $fontStyle = self::normalizeStyle($fontFamily, $fontStyle);
        if ($fontStyle === null) {
            return '';
        }

        $face = self::FONT_FACES[$fontFamily]['faces'][$fontStyle] ?? null;
        if ($face === null) {
            return '';
        }

        $path = __DIR__ . '/fonts/' . $face['filename'];
        if (!isset(self::$fontData[$path])) {
            if (!is_file($path) || !is_readable($path)) {
                return '';
            }

            $data = file_get_contents($path);
            if ($data === false) {
                return '';
            }
            self::$fontData[$path] = base64_encode($data);
        }

        return sprintf(
            '@font-face { font-family: "%s"; src: url("data:font/ttf;base64,%s") format("truetype"); font-style: %s; font-weight: %d; font-display: block; }',
            $fontFamily,
            self::$fontData[$path],
            $face['style'],
            $face['weight']
        );
    }

    private static function normalizeStyleName(string $fontStyle): ?string
    {
        $fontStyle = self::normalizeLookupValue($fontStyle);

        return $fontStyle === '' ? null : self::STYLE_ALIASES[$fontStyle] ?? null;
    }

    private static function normalizeLookupValue(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_replace('/\s+/', ' ', $value) ?? '';
    }
}
