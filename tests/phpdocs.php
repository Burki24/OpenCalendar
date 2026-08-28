<?php

declare(strict_types=1);

$roots = [
    __DIR__ . '/../Kalender',
    __DIR__ . '/../Kalender Ansicht',
    __DIR__ . '/../Kalender Einrichtung',
    __DIR__ . '/../Kalender Konfigurator',
    __DIR__ . '/../Kalender Konto',
    __DIR__ . '/../libs'
];

$missing = [];
$invalid = [];

foreach ($roots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            throw new RuntimeException('Could not read ' . $file->getPathname());
        }

        preg_match_all(
            '/\bpublic\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[1] as $index => $methodMatch) {
            $method = $methodMatch[0];
            $declarationOffset = $matches[0][$index][1];
            $prefix = rtrim(substr($source, 0, $declarationOffset));
            $relativeMethod = relativePath($file->getPathname()) . '::' . $method;

            if (!str_ends_with($prefix, '*/')) {
                $missing[] = $relativeMethod;
                continue;
            }

            $docStart = strrpos($prefix, '/**');
            $commentStart = strrpos($prefix, '/*');
            if ($docStart === false || $commentStart !== $docStart) {
                $missing[] = $relativeMethod;
                continue;
            }

            $docBlock = substr($prefix, $docStart);
            $declaration = publicMethodDeclaration($source, $declarationOffset);
            if ($declaration === '') {
                $invalid[] = $relativeMethod . ' (could not parse declaration)';
                continue;
            }

            preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', methodParameterList($declaration), $parameterMatches);
            $parameters = array_values(array_unique($parameterMatches[1] ?? []));

            preg_match_all(
                '/@param\s+[^\s]+\s+\$([A-Za-z_][A-Za-z0-9_]*)\b/',
                $docBlock,
                $documentedParameterMatches
            );
            $documentedParameters = $documentedParameterMatches[1] ?? [];
            $paramTagCount = preg_match_all('/@param\b/', $docBlock);

            if ($paramTagCount !== count($documentedParameters)) {
                $invalid[] = $relativeMethod . ' (malformed @param tag)';
            }

            if (count($documentedParameters) !== count(array_unique($documentedParameters))) {
                $invalid[] = $relativeMethod . ' (duplicate @param tag)';
            }

            foreach ($documentedParameters as $documentedParameter) {
                if (!in_array($documentedParameter, $parameters, true)) {
                    $invalid[] = $relativeMethod . ' (unknown @param $' . $documentedParameter . ')';
                }
            }

            if (
                preg_match('/\)\s*:\s*void\b/', $declaration) === 1
                && preg_match('/@return\b/', $docBlock) === 1
            ) {
                $invalid[] = $relativeMethod . ' (@return used for void method)';
            }
        }
    }
}

if ($missing !== []) {
    fwrite(STDERR, "Missing PHPDoc for public methods:\n - " . implode("\n - ", $missing) . "\n");
}

if ($invalid !== []) {
    fwrite(STDERR, "Invalid PHPDoc for public methods:\n - " . implode("\n - ", array_values(array_unique($invalid))) . "\n");
}

if ($missing !== [] || $invalid !== []) {
    exit(1);
}

echo "Public PHPDoc coverage and tag validation: OK\n";

function publicMethodDeclaration(string $source, int $offset): string
{
    $braceOffset = strpos($source, '{', $offset);
    if ($braceOffset === false) {
        return '';
    }

    return trim(substr($source, $offset, $braceOffset - $offset));
}

function methodParameterList(string $declaration): string
{
    $open = strpos($declaration, '(');
    if ($open === false) {
        return '';
    }

    $depth = 0;
    $length = strlen($declaration);
    for ($index = $open; $index < $length; ++$index) {
        if ($declaration[$index] === '(') {
            ++$depth;
            continue;
        }

        if ($declaration[$index] !== ')') {
            continue;
        }

        --$depth;
        if ($depth === 0) {
            return substr($declaration, $open + 1, $index - $open - 1);
        }
    }

    return '';
}

function relativePath(string $path): string
{
    $root = realpath(__DIR__ . '/..');
    $realPath = realpath($path);
    if ($root === false || $realPath === false) {
        return $path;
    }

    return ltrim(str_replace('\\', '/', substr($realPath, strlen($root))), '/');
}
