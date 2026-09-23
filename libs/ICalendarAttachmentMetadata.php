<?php

declare(strict_types=1);

namespace IPSKalender;

use RuntimeException;

/** Private ATTACH metadata only; never dereferences a URI or returns file content. */
final class ICalendarAttachmentMetadata
{
    public const MAX_RESOURCE_BYTES = 16 * 1024 * 1024;
    public const MAX_DOWNLOAD_BYTES = 3 * 1024 * 1024;

    /**
     * Reads unfolded lines from one verified VEVENT, excluding nested alarms.
     *
     * @param list<string> $block Verified event component lines.
     * @return list<array<string,mixed>> Bounded metadata without source URLs or bytes.
     */
    public static function read(array $block): array
    {
        return array_map(static function (array $record): array
        {
            unset($record['_content'], $record['_uri'], $record['_managedId'], $record['_lineIndex'], $record['_line']);
            return $record;
        }, self::records($block));
    }

    /** @param list<string> $block @return array{lines:list<string>,kind:string,managedId:string,line:string} */
    public static function remove(array $block, string $attachmentId): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $attachmentId) !== 1) {
            throw new RuntimeException('Invalid attachment identity.');
        }
        foreach (self::records($block) as $record) {
            if (!hash_equals($attachmentId, $record['id'])) {
                continue;
            }
            array_splice($block, $record['_lineIndex'], 1);
            return ['lines' => $block, 'kind' => $record['kind'],
                'managedId' => $record['_managedId'], 'line' => $record['_line']];
        }
        throw new RuntimeException('Attachment is no longer available.');
    }

    /**
     * Returns one embedded attachment. URI references are never dereferenced.
     *
     * @param list<string> $block Verified event component lines.
     * @return array{name:string,contentType:string,content:string} Raw bounded file content.
     */
    public static function download(array $block, string $attachmentId): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $attachmentId) !== 1) {
            throw new RuntimeException('Invalid attachment identity.');
        }
        foreach (self::records($block) as $record) {
            if (!hash_equals($attachmentId, $record['id'])) {
                continue;
            }
            if ($record['kind'] !== 'embedded' || !is_string($record['_content'])
                || strlen($record['_content']) > self::MAX_DOWNLOAD_BYTES) {
                throw new RuntimeException('Attachment is unavailable for download.');
            }
            return ['name' => $record['name'], 'contentType' => $record['contentType'], 'content' => $record['_content']];
        }
        throw new RuntimeException('Attachment is no longer available.');
    }

    /** @param list<string> $block @return array{name:string,contentType:string,size:?int,uri:string,managedId:string} */
    public static function managedReference(array $block, string $attachmentId): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $attachmentId) !== 1) {
            throw new RuntimeException('Invalid attachment identity.');
        }
        foreach (self::records($block) as $record) {
            if (hash_equals($attachmentId, $record['id']) && $record['kind'] === 'reference'
                && $record['_managedId'] !== '' && $record['_uri'] !== '') {
                return [
                    'name' => $record['name'], 'contentType' => $record['contentType'],
                    'size' => $record['size'], 'uri' => $record['_uri'], 'managedId' => $record['_managedId']
                ];
            }
        }
        throw new RuntimeException('Managed attachment is unavailable.');
    }

    /** @param list<string> $block @return list<array<string,mixed>> */
    private static function records(array $block): array
    {
        $files = [];
        $metadataBytes = 2;
        $depth = 0;
        foreach ($block as $lineIndex => $line) {
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
                $content = $bytes;
                $kind = 'embedded';
                $uri = '';
            } elseif ($type === 'URI' && !isset($params['ENCODING']) && $value !== ''
                && strlen($value) <= 16_384 && preg_match('/[\x00-\x20\x7f]/', $value) !== 1
                && preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/D', $value) === 1) {
                // URI schemes, credentials and query tokens are intentionally opaque.
                $kind = 'reference';
                $content = null;
                $uri = $value;
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
            if ($kind === 'reference' && isset($params['SIZE'])
                && preg_match('/^(?:0|[1-9][0-9]{0,8})$/D', $params['SIZE']) === 1) {
                $size = (int) $params['SIZE'];
            }
            $managedId = $kind === 'reference' ? ($params['MANAGED-ID'] ?? '') : '';
            if (strlen($managedId) > 512 || preg_match('/[\x00-\x20\x7f]/', $managedId)) {
                $managedId = '';
            }
            $record = [
                'id'          => hash('sha256', count($files) . '|' . $line),
                'name'        => $name !== '' ? $name : 'Attachment ' . (count($files) + 1),
                'size'        => $size, 'contentType' => $mime, 'kind' => $kind,
                'destination' => 'provider', 'isInline' => false, '_content' => $content,
                '_uri'        => $uri, '_managedId' => $managedId,
                '_lineIndex'  => $lineIndex, '_line' => $line
            ];
            $public = $record;
            unset($public['_content'], $public['_uri'], $public['_managedId'], $public['_lineIndex'], $public['_line']);
            $metadataBytes += strlen(json_encode($public, JSON_THROW_ON_ERROR)) + 1;
            $files[] = $record;
            if (count($files) > 100 || $metadataBytes > 192 * 1024) {
                throw new RuntimeException('Attachment metadata exceeds the supported limit.');
            }
        }
        return $files;
    }
}
