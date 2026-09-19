<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/helper/IPSViewFontCatalogHelper.php';

use Burki24\SymconModuleHelper\IPSViewFontCatalogHelper;

$manifest = json_decode(file_get_contents(__DIR__ . '/../libs/helper/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$count = 0;
foreach ($manifest['helpers']['IPSViewStyleHelper']['dependencies'] as $dependency) {
    if ($dependency['name'] !== 'IPSViewFontCatalogHelper') {
        continue;
    }
    foreach ($dependency['assets'] as $asset) {
        $path = __DIR__ . '/../' . $asset['path'];
        if (!is_file($path) || hash_file('sha256', $path) !== $asset['sha256']) {
            throw new RuntimeException('Missing or changed font asset: ' . $asset['path']);
        }
        $count++;
    }
}
if ($count !== 25) {
    throw new RuntimeException('Expected all font assets and license notices.');
}
foreach (IPSViewFontCatalogHelper::families() as $family) {
    foreach (IPSViewFontCatalogHelper::styles($family) as $style) {
        $css = IPSViewFontCatalogHelper::fontFaceCSS($family, $style);
        if (!str_contains($css, 'data:font/ttf;base64,') || substr_count($css, '@font-face') !== 1) {
            throw new RuntimeException('Font cut is not embedded: ' . $family . '/' . $style);
        }
    }
}
if (IPSViewFontCatalogHelper::fontFaceCSS('sans-serif', 'Regular') !== '') {
    throw new RuntimeException('System fonts must not load bundled assets.');
}
fwrite(STDOUT, "IPSView font embedding and asset integrity verified.\n");
