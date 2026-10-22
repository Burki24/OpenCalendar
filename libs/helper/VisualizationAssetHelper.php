<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use ReflectionClass;
use RuntimeException;

/**
 * Loads visualization assets relative to the concrete Symcon module.
 *
 * Intended for classes derived from IPSModule/IPSModuleStrict. The helper
 * resolves the directory of the concrete module class via reflection so a
 * vendored helper can reliably access files from that module's visualization
 * directory.
 *
 * @version 1.0.2
 */
trait VisualizationAssetHelper
{
    /**
     * Loads a file from the visualization directory of the concrete module.
     *
     * @param string $filename File name relative to the module's visualization directory.
     *
     * @return string File contents, or an empty string if the asset cannot be read.
     *
     * @throws RuntimeException If the concrete module file cannot be determined.
     */
    protected function VisualizationAsset(string $filename): string
    {
        $path = $this->VisualizationAssetDirectory() . DIRECTORY_SEPARATOR . $filename;
        $content = @file_get_contents($path);
        if ($content === false) {
            $this->SendDebug(__FUNCTION__, 'Visualization asset could not be loaded: ' . $path, 0);

            return '';
        }

        return $content;
    }

    /** Returns the shared HTTP origin resolver for embedded HTML documents. */
    protected function VisualizationHTTPOriginScript(): string
    {
        $script = file_get_contents(__DIR__ . '/visualization/http-origin.js');
        if ($script === false) {
            throw new RuntimeException('The HTTP origin resolver asset could not be loaded.');
        }
        return $script;
    }

    /** Returns shared wheel routing, scrolling and direct iframe UI forwarding. */
    protected function VisualizationInteractionScript(): string
    {
        $script = file_get_contents(__DIR__ . '/visualization/interaction.min.js');
        if ($script === false) {
            throw new RuntimeException('The visualization interaction asset could not be loaded.');
        }
        return $script;
    }

    /**
     * Resolves an asset path relative to the concrete module class.
     *
     * Override with __DIR__ . "/visualization" in the consumer when Symcon evaluates
     * the module class in a WebHook context with a synthetic reflection filename.
     *
     * @return string Absolute path to the module visualization directory.
     *
     * @throws RuntimeException If the concrete module file cannot be determined.
     */
    protected function VisualizationAssetDirectory(): string
    {
        $reflection = new ReflectionClass($this);
        $moduleFile = $reflection->getFileName();
        if (!is_string($moduleFile) || $moduleFile === '') {
            throw new RuntimeException('The module file path could not be determined.');
        }

        return dirname($moduleFile)
            . DIRECTORY_SEPARATOR
            . 'visualization';
    }
}
