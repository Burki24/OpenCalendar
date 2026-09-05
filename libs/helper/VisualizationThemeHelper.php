<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

/**
 * Provides shared CSS design tokens for Symcon HTML-SDK visualizations.
 *
 * The variables prefer colors exposed by the Symcon visualization and use
 * light/dark fallbacks when a host does not provide them. Module-specific
 * components deliberately remain outside this helper.
 *
 * @version 1.1.0
 */
trait VisualizationThemeHelper
{
    /**
     * Returns the shared Symcon-compatible visualization theme.
     *
     * @param array<string,string> $overrides Optional validated CSS token overrides.
     *
     * @return string CSS custom properties and a small common foundation.
     */
    protected function VisualizationThemeCSS(array $overrides = []): string
    {
        $css = <<<'CSS'
:root {
    color-scheme: light dark;
    --symc-font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    --symc-text: var(--content-color, light-dark(#202124, #f4f5f7));
    --symc-background: var(--card-color, light-dark(#ffffff, #333438));
    --symc-accent: var(--accent-color, #55cbb5);
    --symc-heading: var(--symc-text);
    --symc-subheading: color-mix(in srgb, var(--symc-text) 62%, transparent);
    --symc-muted: color-mix(in srgb, var(--symc-text) 62%, transparent);
    --symc-subtle: color-mix(in srgb, var(--symc-text) 44%, transparent);
    --symc-border: color-mix(in srgb, var(--symc-text) 15%, transparent);
    --symc-border-strong: color-mix(in srgb, var(--symc-text) 24%, transparent);
    --symc-surface: color-mix(in srgb, var(--symc-text) 7%, transparent);
    --symc-surface-raised: color-mix(in srgb, var(--symc-text) 10%, transparent);
    --symc-surface-hover: color-mix(in srgb, var(--symc-text) 13%, transparent);
    --symc-accent-soft: color-mix(in srgb, var(--symc-accent) 18%, transparent);
    --symc-success: #56c881;
    --symc-success-soft: color-mix(in srgb, var(--symc-success) 18%, transparent);
    --symc-warning: #e6a93f;
    --symc-warning-soft: color-mix(in srgb, var(--symc-warning) 18%, transparent);
    --symc-danger: #e36d6d;
    --symc-danger-soft: color-mix(in srgb, var(--symc-danger) 18%, transparent);
    --symc-info: #62aee8;
    --symc-info-soft: color-mix(in srgb, var(--symc-info) 18%, transparent);
    --symc-radius-small: 8px;
    --symc-radius-medium: 12px;
    --symc-radius-large: 16px;
    --symc-control-height: 38px;
    --symc-focus-ring: 0 0 0 2px color-mix(in srgb, var(--symc-accent) 42%, transparent);
}

html,
body {
    font-family: var(--symc-font-family);
}

button,
input,
select,
textarea {
    font: inherit;
}

button:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible {
    outline: none;
    box-shadow: var(--symc-focus-ring);
}
CSS;

        $declarations = [];
        foreach ($overrides as $token => $color) {
            if (!is_string($token) || preg_match('/^--symc-[a-z][a-z0-9-]*$/', $token) !== 1) {
                continue;
            }
            if (!is_string($color) || preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
                continue;
            }

            $declarations[] = '    ' . $token . ': ' . strtoupper($color) . ';';
        }

        if ($declarations === []) {
            return $css;
        }

        return $css . "\n\n:root {\n" . implode("\n", $declarations) . "\n}";
    }
}
