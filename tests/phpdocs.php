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

        foreach (publicMethods($source) as $method) {
            $relativeMethod = relativePath($file->getPathname()) . '::' . $method['name'];
            $docBlock = $method['docBlock'];

            if ($docBlock === null) {
                $missing[] = $relativeMethod;
                continue;
            }

            $parameterTags = documentedParameterTags($docBlock);
            $documentedParameters = [];

            foreach ($parameterTags as $parameterTag) {
                preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\b/', $parameterTag, $variableMatches);
                $variables = $variableMatches[1] ?? [];
                if ($variables === []) {
                    $invalid[] = $relativeMethod . ' (malformed @param tag)';
                    continue;
                }

                // Complex PHPDoc types may contain named callable parameters.
                // The documented method parameter is the final variable in the tag.
                $documentedParameters[] = $variables[array_key_last($variables)];
            }

            if (count($documentedParameters) !== count(array_unique($documentedParameters))) {
                $invalid[] = $relativeMethod . ' (duplicate @param tag)';
            }

            foreach ($documentedParameters as $documentedParameter) {
                if (!in_array($documentedParameter, $method['parameters'], true)) {
                    $invalid[] = $relativeMethod . ' (unknown @param $' . $documentedParameter . ')';
                }
            }

            if ($method['returnsVoid'] && preg_match('/@return\b/', $docBlock) === 1) {
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

/**
 * @return list<array{name: string, parameters: list<string>, returnsVoid: bool, docBlock: ?string}>
 */
function publicMethods(string $source): array
{
    $tokens = token_get_all($source);
    $methods = [];
    $braceDepth = 0;
    $classDepths = [];
    $pendingClassLike = false;

    foreach ($tokens as $index => $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $pendingClassLike = true;
                continue;
            }

            if ($token[0] !== T_FUNCTION || $classDepths === []) {
                continue;
            }

            $name = namedFunctionName($tokens, $index);
            if ($name === null || !isPublicFunction($tokens, $index)) {
                continue;
            }

            $signature = functionSignature($tokens, $index);
            if ($signature === null) {
                throw new RuntimeException('Could not parse public method declaration: ' . $name);
            }

            $methods[] = [
                'name'        => $name,
                'parameters'  => signatureParameterNames($signature),
                'returnsVoid' => signatureReturnsVoid($signature),
                'docBlock'    => precedingMethodDocBlock($tokens, $index)
            ];
            continue;
        }

        if ($token === '{') {
            ++$braceDepth;
            if ($pendingClassLike) {
                $classDepths[] = $braceDepth;
                $pendingClassLike = false;
            }
            continue;
        }

        if ($token !== '}') {
            continue;
        }

        if ($classDepths !== [] && end($classDepths) === $braceDepth) {
            array_pop($classDepths);
        }
        --$braceDepth;
    }

    return $methods;
}

/**
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function namedFunctionName(array $tokens, int $functionIndex): ?string
{
    $count = count($tokens);
    for ($index = $functionIndex + 1; $index < $count; ++$index) {
        $token = $tokens[$index];

        if (is_array($token) && $token[0] === T_WHITESPACE) {
            continue;
        }
        if ($token === '&') {
            continue;
        }

        return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
    }

    return null;
}

/**
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function isPublicFunction(array $tokens, int $functionIndex): bool
{
    for ($index = $functionIndex - 1; $index >= 0; --$index) {
        $token = $tokens[$index];

        if (is_array($token) && in_array(
            $token[0],
            [T_WHITESPACE, T_STATIC, T_ABSTRACT, T_FINAL, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY],
            true
        )) {
            if ($token[0] === T_PRIVATE || $token[0] === T_PROTECTED) {
                return false;
            }
            if ($token[0] === T_PUBLIC) {
                return true;
            }
            continue;
        }

        break;
    }

    // PHP methods without an explicit visibility modifier are public by default.
    return true;
}

/**
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function precedingMethodDocBlock(array $tokens, int $functionIndex): ?string
{
    for ($index = $functionIndex - 1; $index >= 0; --$index) {
        $token = $tokens[$index];

        if (is_array($token) && in_array(
            $token[0],
            [T_WHITESPACE, T_STATIC, T_ABSTRACT, T_FINAL, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY],
            true
        )) {
            continue;
        }

        return is_array($token) && $token[0] === T_DOC_COMMENT ? $token[1] : null;
    }

    return null;
}

/**
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 * @return list<array{0: int, 1: string, 2: int}|string>|null
 */
function functionSignature(array $tokens, int $functionIndex): ?array
{
    $signature = [];
    $parenthesisDepth = 0;
    $seenParameters = false;
    $count = count($tokens);

    for ($index = $functionIndex; $index < $count; ++$index) {
        $token = $tokens[$index];
        $signature[] = $token;

        if ($token === '(') {
            ++$parenthesisDepth;
            $seenParameters = true;
            continue;
        }

        if ($token === ')') {
            --$parenthesisDepth;
            continue;
        }

        if ($seenParameters && $parenthesisDepth === 0 && ($token === '{' || $token === ';')) {
            return $signature;
        }
    }

    return null;
}

/**
 * @param list<array{0: int, 1: string, 2: int}|string> $signature
 * @return list<string>
 */
function signatureParameterNames(array $signature): array
{
    $parameters = [];
    $parenthesisDepth = 0;
    $seenParameters = false;

    foreach ($signature as $token) {
        if ($token === '(') {
            ++$parenthesisDepth;
            $seenParameters = true;
            continue;
        }

        if ($token === ')') {
            --$parenthesisDepth;
            continue;
        }

        if (
            $seenParameters
            && $parenthesisDepth === 1
            && is_array($token)
            && $token[0] === T_VARIABLE
        ) {
            $parameters[] = substr($token[1], 1);
        }
    }

    return array_values(array_unique($parameters));
}

/**
 * @param list<array{0: int, 1: string, 2: int}|string> $signature
 */
function signatureReturnsVoid(array $signature): bool
{
    $parenthesisDepth = 0;
    $seenParameters = false;
    $afterParameters = false;
    $returnType = '';

    foreach ($signature as $token) {
        if ($token === '(') {
            ++$parenthesisDepth;
            $seenParameters = true;
            continue;
        }

        if ($token === ')') {
            --$parenthesisDepth;
            if ($seenParameters && $parenthesisDepth === 0) {
                $afterParameters = true;
            }
            continue;
        }

        if (!$afterParameters || $parenthesisDepth !== 0) {
            continue;
        }

        if ($token === '{' || $token === ';') {
            break;
        }

        $returnType .= is_array($token) ? $token[1] : $token;
    }

    return preg_match('/:\s*void\b/i', $returnType) === 1;
}

/**
 * @return list<string>
 */
function documentedParameterTags(string $docBlock): array
{
    $lines = preg_split('/\R/', $docBlock) ?: [];
    $tags = [];
    $current = null;

    foreach ($lines as $line) {
        $line = preg_replace('/^\s*\/?\*+\s?|\s*\*\/\s*$/', '', $line) ?? $line;

        if (preg_match('/^@param\b(.*)$/', $line, $matches) === 1) {
            if ($current !== null) {
                $tags[] = $current;
            }
            $current = trim($matches[1]);
            continue;
        }

        if (preg_match('/^@[A-Za-z_]/', $line) === 1) {
            if ($current !== null) {
                $tags[] = $current;
                $current = null;
            }
            continue;
        }

        if ($current !== null && trim($line) !== '') {
            $current .= ' ' . trim($line);
        }
    }

    if ($current !== null) {
        $tags[] = $current;
    }

    return $tags;
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
