<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;

/**
 * Establishes a container-query contract for responsive HTML-SDK visualizations.
 *
 * The actual layout remains module-specific. This helper deliberately only marks
 * the visualization root as a query container and supplies shared dimension
 * tokens, so layouts adapt to the available tile width in every Symcon client.
 *
 * @version 1.0.0
 */
trait ResponsiveVisualizationHelper
{
    /**
     * Returns the shared responsive foundation for one visualization root.
     *
     * Consumers can use `@container <name> (max-width: ...)` rules in their
     * own stylesheet. Container queries respond to the tile width rather than
     * the browser viewport and therefore also work in the Symcon mobile apps.
     */
    protected function ResponsiveVisualizationCSS(string $rootSelector, string $containerName = 'symcon-visualization'): string
    {
        $rootSelector = trim($rootSelector);
        $containerName = trim($containerName);

        if (preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/', $rootSelector) !== 1) {
            throw new InvalidArgumentException('The responsive visualization root must be an ID selector.');
        }
        if (preg_match('/^[a-z][a-z0-9-]*$/', $containerName) !== 1) {
            throw new InvalidArgumentException('The responsive visualization container name is invalid.');
        }

        return <<<CSS
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
    }
}
