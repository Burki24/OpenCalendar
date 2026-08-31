<?php

declare(strict_types=1);

$stylePath = __DIR__ . '/../Kalender Ansicht/visualization/style.css';
$style = file_get_contents($stylePath);
if ($style === false) {
    throw new RuntimeException('Unable to read Calendar View stylesheet.');
}

foreach ([
    'font-style: var(--ipsview-role-font-style);',
    'font-weight: var(--ipsview-role-font-weight);'
] as $expectedRule) {
    if (!str_contains($style, $expectedRule)) {
        throw new RuntimeException('Missing IPSView font role mapping: ' . $expectedRule);
    }
}

fwrite(STDOUT, "IPSView font role rendering verified.\n");
