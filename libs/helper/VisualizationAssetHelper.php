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
 * @version 1.0.0
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
        $path = $this->ResolveVisualizationAssetPath($filename);
        $content = @file_get_contents($path);
        if ($content === false) {
            $this->SendDebug(__FUNCTION__, 'Visualization asset could not be loaded: ' . $path, 0);

            return '';
        }

        return $content;
    }

    /**
     * Resolves an asset path relative to the concrete module class.
     *
     * @param string $filename File name relative to the module's visualization directory.
     *
     * @return string Absolute path to the requested visualization asset.
     *
     * @throws RuntimeException If the concrete module file cannot be determined.
     */
    private function ResolveVisualizationAssetPath(string $filename): string
    {
        $reflection = new ReflectionClass($this);
        $moduleFile = $reflection->getFileName();
        if (!is_string($moduleFile) || $moduleFile === '') {
            throw new RuntimeException('The module file path could not be determined.');
        }

        return dirname($moduleFile)
            . DIRECTORY_SEPARATOR
            . 'visualization'
            . DIRECTORY_SEPARATOR
            . $filename;
    }
}
