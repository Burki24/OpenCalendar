<?php

declare(strict_types=1);

namespace IPSKalender;

use RuntimeException;

/** Private ATTACH metadata only; never dereferences a URI or returns file content. */
final class ICalendarAttachmentMetadata
{
    public const MAX_RESOURCE_BYTES = 16 * 1024 * 1024;

    /**
     * Reads unfolded lines from one verified VEVENT, excluding nested alarms.
     *
     * @param list<string> $block Verified event component lines.
     * @return list<array<string,mixed>> Bounded metadata without source URLs or bytes.
     */
    public static function read(array $block): array
    {
        $files = [];
        $depth = 0;
        foreach ($block as $line) {
            if (str_starts_with(strtoupper($line), 'BEGIN:')) {
                $depth++;
                continue;
            }
            if (str_starts_with(strtoupper($line), 'END:')) {
                $depth--;
                continue;
            }
            if ($depth !== 1 || preg_match('/^ATTACH[;:]/i', $line) !== 1) {
                continue;
            }
            // Quoted parameter values may contain semicolons and colons.
            $quoted = false;
            $parts = [];
            $start = 0;
            $separator = null;
            for ($i = 0, $length = strlen($line); $i < $length; $i++) {
                if ($line[$i] === '"') {
                    $quoted = !$quoted;
                } elseif (!$quoted && ($line[$i] === ';' || $line[$i] === ':')) {
                    $parts[] = substr($line, $start, $i - $start);
                    $start = $i + 1;
                    if ($line[$i] === ':') {
                        $separator = $i;
                        break;
                    }
                }
            }
            if ($separator === null) {
                throw new RuntimeException('Invalid attachment property.');
            }
            array_shift($parts);
            $params = [];
            foreach ($parts as $part) {
                $pair = explode('=', $part, 2);
                $key = strtoupper($pair[0]);
                if (count($pair) !== 2 || isset($params[$key])) {
                    throw new RuntimeException('Invalid attachment parameters.');
                }
                $params[$key] = trim($pair[1], '"');
            }
            $value = substr($line, $separator + 1);
            $type = strtoupper($params['VALUE'] ?? 'URI');
            $size = null;
            if ($type === 'BINARY') {
                if (strtoupper($params['ENCODING'] ?? '') !== 'BASE64'
                    || strlen($value) % 4 !== 0 || preg_match('/[^A-Za-z0-9+\/=]/', $value)) {
                    throw new RuntimeException('Invalid embedded attachment.');
                }
                $bytes = base64_decode($value, true);
                if ($bytes === false || base64_encode($bytes) !== $value) {
                    throw new RuntimeException('Invalid embedded attachment encoding.');
                }
                $size = strlen($bytes);
                unset($bytes);
                $kind = 'embedded';
            } elseif ($type === 'URI' && !isset($params['ENCODING']) && $value !== ''
                && strlen($value) <= 16_384 && preg_match('/[\x00-\x20\x7f]/', $value) !== 1
                && preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/D', $value) === 1) {
                // URI schemes, credentials and query tokens are intentionally opaque.
                $kind = 'reference';
            } else {
                throw new RuntimeException('Unsupported attachment property.');
            }
            $name = $params['FILENAME'] ?? $params['X-FILENAME'] ?? $params['X-APPLE-FILENAME'] ?? '';
            $name = str_replace(['^n', "^'", '^^'], ["\n", '"', '^'], $name);
            if (strlen($name) > 1024 || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                throw new RuntimeException('Invalid attachment filename.');
            }
            $mime = $params['FMTTYPE'] ?? '';
            if (strlen($mime) > 255 || preg_match('/^[a-zA-Z0-9!#$&^_.+\-]+\/[a-zA-Z0-9!#$&^_.+\-]+$/D', $mime) !== 1) {
                $mime = 'application/octet-stream';
            }
            $files[] = [
                'id'          => hash('sha256', count($files) . '|' . $line),
                'name'        => $name !== '' ? $name : 'Attachment ' . (count($files) + 1),
                'size'        => $size, 'contentType' => $mime, 'kind' => $kind,
                'destination' => 'provider', 'isInline' => false
            ];
            if (count($files) > 100 || strlen(json_encode($files, JSON_THROW_ON_ERROR)) > 192 * 1024) {
                throw new RuntimeException('Attachment metadata exceeds the supported limit.');
            }
        }
        return $files;
    }
}
