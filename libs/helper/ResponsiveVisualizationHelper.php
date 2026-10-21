<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;
use Throwable;

/**
 * Establishes a container-query contract for responsive HTML-SDK visualizations.
 *
 * The actual layout remains module-specific. This helper deliberately only marks
 * the visualization root as a query container and supplies shared dimension
 * tokens and an optional viewport frame with native tile heading metadata, so
 * layouts adapt to the available tile dimensions in every Symcon client.
 *
 * @version 1.0.1
 */
trait ResponsiveVisualizationHelper
{
    /**
     * Returns the shared responsive foundation for one visualization root.
     *
     * Consumers can use `@container <name> (max-width: ...)` rules in their
     * own stylesheet. Container queries respond to the tile width rather than
     * the browser viewport and therefore also work in the Symcon mobile apps.
     * Set $fillViewport to opt into a full-height flex frame. Apply the inset
     * from ResponsiveVisualizationLayoutOptions() to --symc-tile-header-inset;
     * the root then reserves that space once as its top padding.
     */
    protected function ResponsiveVisualizationCSS(string $rootSelector, string $containerName = 'symcon-visualization', bool $fillViewport = false): string
    {
        $rootSelector = trim($rootSelector);
        $containerName = trim($containerName);

        if (preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/', $rootSelector) !== 1) {
            throw new InvalidArgumentException('The responsive visualization root must be an ID selector.');
        }
        if (preg_match('/^[a-z][a-z0-9-]*$/', $containerName) !== 1) {
            throw new InvalidArgumentException('The responsive visualization container name is invalid.');
        }

        $css = <<<CSS
{$rootSelector} {
    container-name: {$containerName};
    container-type: inline-size;
    min-inline-size: 0;
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
    --symc-responsive-gap: clamp(0.5rem, 2cqi, 1rem);
    --symc-responsive-padding: clamp(0.625rem, 2.5cqi, 1.25rem);
    --symc-responsive-touch-target: 44px;
}
CSS;
        if ($fillViewport) {
            $css .= <<<CSS

html, body {
    width: 100%;
    height: 100%;
    margin: 0;
    overflow: hidden;
}
{$rootSelector} {
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100%;
    min-height: 0;
    padding: var(--symc-responsive-padding, 12px);
    padding-top: calc(var(--symc-responsive-padding, 12px) + var(--symc-tile-header-inset, 0px));
    overflow: hidden;
}
CSS;
        }

        return $css;
    }

    /**
     * Returns the shared native HTML-SDK heading reservation in CSS pixels.
     *
     * Native tiles reserve 58px when their object title is visible. An absent
     * object field or a failed object lookup conservatively keeps that space.
     * IPSView/standalone pages pass false and do not query the Symcon object.
     * This is presentation metadata only; consumers forward it to their client.
     *
     * @return array{tileHeaderVisible: bool, tileHeaderInset: int}
     */
    protected function ResponsiveVisualizationLayoutOptions(bool $nativeTile = true): array
    {
        $visible = $nativeTile;
        if ($nativeTile) {
            try {
                $visible = !(IPS_GetObject($this->InstanceID)['ObjectIsHiddenTitle'] ?? false);
            } catch (Throwable) {
                $visible = true;
            }
        }

        return ['tileHeaderVisible' => $visible, 'tileHeaderInset' => $visible ? 58 : 0];
    }
}
