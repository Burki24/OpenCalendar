<?php

declare(strict_types=1);

namespace IPSKalender;

use RuntimeException;

require_once __DIR__ . '/MicrosoftGraphOriginPolicy.php';

/** Bounded metadata-only Graph collection reader; never follows document links. */
final class MicrosoftAttachmentCollection
{
    public const MAX_RESPONSE_BYTES = 262_144;
    public const MAX_DOWNLOAD_BYTES = 3 * 1024 * 1024;
    private const SELECT = 'id,name,size,contentType,lastModifiedDateTime';

    /**
     * Reads only the attachment collection of one server-selected event or task.
     * The callback must use the trusted Graph HTTP client and the response limit.
     *
     * @param string $collectionUrl Calendar/event or list/task-scoped collection URL.
     * @param bool $task Whether Graph's taskFileAttachment contract applies.
     * @param callable(string):array $request Authenticated, size-limited GET request.
     * @return list<array<string,mixed>> Metadata without bodies, URLs or download credentials.
     */
    public static function read(string $collectionUrl, bool $task, callable $request): array
    {
        $policy = new MicrosoftGraphOriginPolicy();
        if (!$policy->isAllowedUrl($collectionUrl) || parse_url($collectionUrl, PHP_URL_QUERY) !== null
            || parse_url($collectionUrl, PHP_URL_FRAGMENT) !== null
            || !str_ends_with($collectionUrl, '/attachments')) {
            throw new RuntimeException('Invalid attachment collection.');
        }
        $select = self::SELECT . ($task ? '' : ',isInline');
        $url = $collectionUrl . '?' . http_build_query(['$select' => $select, '$top' => 100], '', '&', PHP_QUERY_RFC3986);
        $seen = [];
        $files = [];
        $ids = [];
        do {
            if (isset($seen[$url]) || count($seen) >= 32) {
                throw new RuntimeException('Attachment pagination did not make progress.');
            }
            $seen[$url] = true;
            $data = $request($url);
            if (!is_array($data['value'] ?? null) || !array_is_list($data['value'])) {
                throw new RuntimeException('Invalid attachment collection response.');
            }
            foreach ($data['value'] as $item) {
                $metadata = self::normalize($item, $task);
                if (isset($ids[$metadata['id']])) {
                    throw new RuntimeException('Duplicate attachment metadata.');
                }
                $ids[$metadata['id']] = true;
                $files[] = $metadata;
                if (count($files) > 100 || strlen(json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) > 192 * 1024) {
                    throw new RuntimeException('Attachment metadata exceeds the supported limit.');
                }
            }
            $next = $data['@odata.nextLink'] ?? '';
            if (!is_string($next) || strlen($next) > 16_384) {
                throw new RuntimeException('Invalid attachment continuation.');
            }
            if ($next === '') {
                break;
            }
            if (!$policy->isAllowedUrl($next) || parse_url($next, PHP_URL_FRAGMENT) !== null
                || parse_url($next, PHP_URL_PATH) !== parse_url($collectionUrl, PHP_URL_PATH)) {
                throw new RuntimeException('Attachment continuation changed its owner.');
            }
            parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
            if (array_diff(array_keys($query), ['$select', '$top', '$skip', '$skiptoken']) !== []
                || (isset($query['$select']) && $query['$select'] !== $select)) {
                throw new RuntimeException('Attachment continuation requested unsupported fields.');
            }
            foreach ($query as $value) {
                if (!is_string($value)) {
                    throw new RuntimeException('Invalid attachment continuation parameters.');
                }
            }
            // Reassert metadata-only selection even if the service omits it in nextLink.
            $query['$select'] = $select;
            $query['$top'] = '100';
            ksort($query);
            $url = $collectionUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        } while (true);
        return $files;
    }

    /**
     * Confirms a selected attachment directly below its verified owner before download.
     *
     * @param callable(string):array $request Authenticated, size-limited JSON GET request.
     * @return array{id:string,name:string,size:int,contentType:string,kind:string,destination:string,isInline:bool}
     */
    public static function selectDownload(string $collectionUrl, string $attachmentId, bool $task, callable $request): array
    {
        self::assertCollection($collectionUrl);
        if ($attachmentId === '' || strlen($attachmentId) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $attachmentId)) {
            throw new RuntimeException('Invalid attachment identity.');
        }
        $select = 'id,name,size,contentType' . ($task ? '' : ',isInline');
        $url = $collectionUrl . '/' . rawurlencode($attachmentId) . '?$select=' . rawurlencode($select);
        $metadata = self::normalize($request($url), $task);
        if (!hash_equals($attachmentId, $metadata['id']) || $metadata['kind'] !== 'file'
            || $metadata['size'] > self::MAX_DOWNLOAD_BYTES) {
            throw new RuntimeException('Attachment is unavailable for download.');
        }
        return $metadata;
    }

    private static function assertCollection(string $collectionUrl): void
    {
        $policy = new MicrosoftGraphOriginPolicy();
        if (!$policy->isAllowedUrl($collectionUrl) || parse_url($collectionUrl, PHP_URL_QUERY) !== null
            || parse_url($collectionUrl, PHP_URL_FRAGMENT) !== null
            || !str_ends_with($collectionUrl, '/attachments')) {
            throw new RuntimeException('Invalid attachment collection.');
        }
    }

    /** @return array{id:string,name:string,size:int,contentType:string,kind:string,destination:string,isInline:bool} */
    private static function normalize(mixed $item, bool $task): array
    {
        if (!is_array($item) || !is_string($item['id'] ?? null) || $item['id'] === '' || strlen($item['id']) > 2048
            || !is_string($item['name'] ?? null) || strlen($item['name']) > 1024
            || preg_match('//u', $item['name']) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $item['name'])
            || !is_int($item['size'] ?? null) || $item['size'] < 0) {
            throw new RuntimeException('Invalid attachment metadata.');
        }
        $type = $item['@odata.type'] ?? ($task ? '#microsoft.graph.taskFileAttachment' : '');
        $kind = match ($type) {
            '#microsoft.graph.fileAttachment', '#microsoft.graph.taskFileAttachment' => 'file',
            '#microsoft.graph.itemAttachment'                                        => 'item',
            '#microsoft.graph.referenceAttachment'                                   => 'reference',
            default                                                                  => 'unsupported'
        };
        $contentType = $item['contentType'] ?? '';
        if (!is_string($contentType) || strlen($contentType) > 255
            || preg_match('/^[a-zA-Z0-9!#$&^_.+\-]+\/[a-zA-Z0-9!#$&^_.+\-]+$/D', $contentType) !== 1) {
            $contentType = 'application/octet-stream';
        }
        return [
            'id'          => $item['id'], 'name' => $item['name'], 'size' => $item['size'],
            'contentType' => $contentType, 'kind' => $kind, 'destination' => 'provider',
            'isInline'    => !$task && ($item['isInline'] ?? false) === true
        ];
    }
}
