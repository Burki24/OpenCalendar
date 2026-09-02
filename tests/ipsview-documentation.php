<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$documents = [
    'README.md'                  => [
        'IPSViewStyleConfigurationHelper',
        '109',
        '15 Gruppen',
        'Abweichend',
        'ColorView',
        'ColorPage',
        '#404040'
    ],
    'Kalender Ansicht/README.md' => [
        'IPSViewStyleConfigurationHelper',
        'IPSViewControlThemeHelper',
        '109',
        '15 Gruppen',
        'Abweichend',
        'ColorView',
        'ColorPage',
        '#404040'
    ]
];

foreach ($documents as $relativePath => $requiredTokens) {
    $path = $root . '/' . $relativePath;
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Could not read documentation file: ' . $relativePath);
    }

    foreach ($requiredTokens as $token) {
        if (!str_contains($content, $token)) {
            throw new RuntimeException(
                sprintf('Documentation contract is missing "%s" in %s.', $token, $relativePath)
            );
        }
    }
}

echo "IPSView documentation contract passed.\n";
