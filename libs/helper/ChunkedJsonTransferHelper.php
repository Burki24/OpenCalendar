<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Provides temporary, size-limited JSON pages for Symcon module transfers.
 *
 * Large lists are encoded into encrypted, temporary file pages. Only small
 * transfer metadata is kept in module buffers. The calling module remains responsible for its request/response
 * protocol and for clearing a transfer after all pages were consumed.
 *
 * Intended for use in classes derived from IPSModule/IPSModuleStrict, which
 * provide GetBuffer(), GetBufferList() and SetBuffer(). Keys are transient;
 * transfers become unreadable when Symcon restarts. Expired files are removed
 * on the next transfer creation. PHP OpenSSL (AES-256-GCM) is required.
 *
 * @version 1.1.0
 */
trait ChunkedJsonTransferHelper
{
    /** Default page size of 192 KiB, safely below Symcon's 256 KiB buffer soft limit. */
    private const CHUNKED_JSON_TRANSFER_DEFAULT_PAGE_BYTES = 192 * 1024;

    /** Largest permitted page size; leaves headroom below the 256 KiB buffer soft limit. */
    private const CHUNKED_JSON_TRANSFER_MAX_PAGE_BYTES = 240 * 1024;

    /** Prevents impractically small pages and excessive buffer counts. */
    private const CHUNKED_JSON_TRANSFER_MIN_PAGE_BYTES = 1024;

    /** Default lifetime for abandoned transfers. */
    private const CHUNKED_JSON_TRANSFER_DEFAULT_TTL_SECONDS = 300;

    /** Upper lifetime limit for transient data. */
    private const CHUNKED_JSON_TRANSFER_MAX_TTL_SECONDS = 86400;

    /** Limits leaked or concurrently active transfers within one scope. */
    private const CHUNKED_JSON_TRANSFER_MAX_ACTIVE = 16;

    private const CHUNKED_JSON_TRANSFER_MAX_FILE_BYTES = 64 * 1024 * 1024;
    private const CHUNKED_JSON_TRANSFER_BUFFER_BUDGET = 240 * 1024;

    private const CHUNKED_JSON_TRANSFER_BUFFER_PREFIX = 'ChunkedJsonTransfer';

    private const CHUNKED_JSON_TRANSFER_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Creates a temporary paged transfer for a JSON list.
     *
     * The returned metadata is intentionally small and can be returned from a
     * module request. Pages are retrieved separately with
     * ReadChunkedJsonTransferPage().
     *
     * @param string      $scope        Module-defined transfer namespace.
     * @param list<mixed> $items        Ordered items to transfer.
     * @param int         $maxPageBytes Maximum encoded JSON bytes per page.
     * @param int         $ttlSeconds   Lifetime of an incomplete transfer.
     *
     * @return array{Token:string,PageCount:int,ItemCount:int,ExpiresAt:int}
     *
     * @throws InvalidArgumentException If an argument is outside the supported range.
     * @throws JsonException If an item cannot be encoded as JSON.
     * @throws RuntimeException If too many transfers are active in the scope.
     * @throws UnexpectedValueException If one item cannot fit into a page.
     */
    protected function CreateChunkedJsonTransfer(string $scope, array $items, int $maxPageBytes = self::CHUNKED_JSON_TRANSFER_DEFAULT_PAGE_BYTES, int $ttlSeconds = self::CHUNKED_JSON_TRANSFER_DEFAULT_TTL_SECONDS): array
    {
        return $this->WithChunkedJsonTransferLock(fn () => $this->CreateChunkedJsonTransferUnlocked($scope, $items, $maxPageBytes, $ttlSeconds));
    }

    /**
     * Reads one transfer page and returns transport-ready page metadata.
     *
     * @param string $scope Module-defined transfer namespace.
     * @param string $token Transfer token returned by CreateChunkedJsonTransfer().
     * @param int    $page  Zero-based page number.
     *
     * @return array{Token:string,Page:int,PageCount:int,ItemCount:int,Complete:bool,Items:list<mixed>}
     *
     * @throws InvalidArgumentException If scope, token or page is invalid.
     * @throws UnexpectedValueException If the transfer expired, is missing or contains invalid data.
     */
    protected function ReadChunkedJsonTransferPage(string $scope, string $token, int $page): array
    {
        return $this->WithChunkedJsonTransferLock(fn () => $this->ReadChunkedJsonTransferPageUnlocked($scope, $token, $page));
    }

    /**
     * Clears all buffers belonging to a transfer.
     *
     * @return bool True when transfer metadata existed, false otherwise.
     *
     * @throws InvalidArgumentException If scope or token is invalid.
     */
    protected function ClearChunkedJsonTransfer(string $scope, string $token): bool
    {
        return $this->WithChunkedJsonTransferLock(fn () => $this->ClearChunkedJsonTransferUnlocked($scope, $token));
    }

    /**
     * Removes transfers whose lifetime elapsed.
     *
     * @return int Number of removed transfers.
     *
     * @throws InvalidArgumentException If scope is invalid.
     */
    protected function CleanupExpiredChunkedJsonTransfers(string $scope): int
    {
        return $this->WithChunkedJsonTransferLock(fn () => $this->CleanupExpiredChunkedJsonTransfersUnlocked($scope));
    }

    /**
     * Provides the current timestamp and can be overridden by a test harness.
     */
    protected function GetChunkedJsonTransferTimestamp(): int
    {
        return time();
    }

    private function CreateChunkedJsonTransferUnlocked(
        string $scope,
        array $items,
        int $maxPageBytes = self::CHUNKED_JSON_TRANSFER_DEFAULT_PAGE_BYTES,
        int $ttlSeconds = self::CHUNKED_JSON_TRANSFER_DEFAULT_TTL_SECONDS
    ): array {
        $this->AssertChunkedJsonTransferScope($scope);
        if (!array_is_list($items)) {
            throw new InvalidArgumentException('Chunked JSON transfer items must be a list.');
        }
        if ($maxPageBytes < self::CHUNKED_JSON_TRANSFER_MIN_PAGE_BYTES
            || $maxPageBytes > self::CHUNKED_JSON_TRANSFER_MAX_PAGE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Chunked JSON transfer page size must be between %d and %d bytes.',
                self::CHUNKED_JSON_TRANSFER_MIN_PAGE_BYTES,
                self::CHUNKED_JSON_TRANSFER_MAX_PAGE_BYTES
            ));
        }
        if ($ttlSeconds < 1 || $ttlSeconds > self::CHUNKED_JSON_TRANSFER_MAX_TTL_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'Chunked JSON transfer lifetime must be between 1 and %d seconds.',
                self::CHUNKED_JSON_TRANSFER_MAX_TTL_SECONDS
            ));
        }

        $this->CleanupExpiredChunkedJsonTransfersUnlocked($scope);
        $this->CleanupChunkedJsonTransferFiles();
        $registry = $this->ReadChunkedJsonTransferRegistry($scope);
        if (count($registry) >= self::CHUNKED_JSON_TRANSFER_MAX_ACTIVE) {
            throw new RuntimeException('Too many chunked JSON transfers are active in this scope.');
        }

        do {
            $token = bin2hex(random_bytes(16));
        } while (isset($registry[$token]));

        $pageCount = 0;
        $expiresAt = $this->GetChunkedJsonTransferTimestamp() + $ttlSeconds;
        $storage = $this->CreateChunkedJsonTransferStorage($token, $expiresAt, $maxPageBytes);
        try {
            $pageCount = $this->WriteChunkedJsonTransferPages($scope, $token, $items, $maxPageBytes, $storage);
            $metadata = [
                'token'      => $token,
                'pageCount'  => $pageCount,
                'itemCount'  => count($items),
                'expiresAt'  => $expiresAt,
                'pageBytes'  => $maxPageBytes,
                'storage'    => $storage
            ];
            $this->SetChunkedJsonTransferBuffer(
                $this->ChunkedJsonTransferMetadataBufferName($scope, $token),
                json_encode($metadata, self::CHUNKED_JSON_TRANSFER_FLAGS)
            );

            $registry[$token] = $expiresAt;
            $this->WriteChunkedJsonTransferRegistry($scope, $registry);
        } catch (Throwable $exception) {
            $this->DeleteChunkedJsonTransferStorage($storage);
            $this->SetChunkedJsonTransferBuffer($this->ChunkedJsonTransferMetadataBufferName($scope, $token), '');
            throw $exception;
        }

        return [
            'Token'      => $token,
            'PageCount'  => $pageCount,
            'ItemCount'  => count($items),
            'ExpiresAt'  => $expiresAt
        ];
    }

    private function ReadChunkedJsonTransferPageUnlocked(string $scope, string $token, int $page): array
    {
        $this->AssertChunkedJsonTransferScope($scope);
        $this->AssertChunkedJsonTransferToken($token);
        if ($page < 0) {
            throw new InvalidArgumentException('Chunked JSON transfer page must not be negative.');
        }

        $metadata = $this->ReadChunkedJsonTransferMetadata($scope, $token);
        if ($metadata['expiresAt'] <= $this->GetChunkedJsonTransferTimestamp()) {
            $this->ClearChunkedJsonTransferUnlocked($scope, $token);
            throw new UnexpectedValueException('The chunked JSON transfer expired.');
        }
        if ($page >= $metadata['pageCount']) {
            throw new InvalidArgumentException('Chunked JSON transfer page is outside the available range.');
        }

        $raw = isset($metadata['storage'])
            ? $this->ReadChunkedJsonTransferFilePage($scope, $token, $page, $metadata['storage'])
            : $this->GetBuffer($this->ChunkedJsonTransferPageBufferName($scope, $token, $page));
        if ($raw === '') {
            throw new UnexpectedValueException('The requested chunked JSON transfer page is missing.');
        }

        try {
            $items = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                'The requested chunked JSON transfer page contains invalid JSON.',
                0,
                $exception
            );
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw new UnexpectedValueException('The requested chunked JSON transfer page must contain a JSON list.');
        }

        return [
            'Token'      => $token,
            'Page'       => $page,
            'PageCount'  => $metadata['pageCount'],
            'ItemCount'  => $metadata['itemCount'],
            'Complete'   => $page === $metadata['pageCount'] - 1,
            'Items'      => $items
        ];
    }

    private function ClearChunkedJsonTransferUnlocked(string $scope, string $token): bool
    {
        $this->AssertChunkedJsonTransferScope($scope);
        $this->AssertChunkedJsonTransferToken($token);

        $metadataName = $this->ChunkedJsonTransferMetadataBufferName($scope, $token);
        $rawMetadata = $this->GetBuffer($metadataName);
        $pageCount = 0;
        if ($rawMetadata !== '') {
            try {
                $metadata = json_decode($rawMetadata, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($metadata) && is_int($metadata['pageCount'] ?? null)) {
                    $pageCount = max(0, $metadata['pageCount']);
                    if (isset($metadata['storage'])) {
                        $this->DeleteChunkedJsonTransferStorage($metadata['storage']);
                        $pageCount = 0;
                    }
                }
            } catch (JsonException) {
                // Corrupt metadata is still removed; unknown page buffers expire with the module process.
            }
        }

        for ($page = 0; $page < $pageCount; ++$page) {
            $this->SetChunkedJsonTransferBuffer($this->ChunkedJsonTransferPageBufferName($scope, $token, $page), '');
        }
        $this->SetChunkedJsonTransferBuffer($metadataName, '');

        $registry = $this->ReadChunkedJsonTransferRegistry($scope);
        unset($registry[$token]);
        $this->WriteChunkedJsonTransferRegistry($scope, $registry);

        return $rawMetadata !== '';
    }

    private function CleanupExpiredChunkedJsonTransfersUnlocked(string $scope): int
    {
        $this->AssertChunkedJsonTransferScope($scope);
        $registry = $this->ReadChunkedJsonTransferRegistry($scope);
        $now = $this->GetChunkedJsonTransferTimestamp();
        $removed = 0;

        foreach ($registry as $token => $expiresAt) {
            if ($expiresAt > $now) {
                continue;
            }

            $this->ClearChunkedJsonTransferUnlocked($scope, $token);
            unset($registry[$token]);
            ++$removed;
        }

        if ($removed > 0) {
            $this->WriteChunkedJsonTransferRegistry($scope, $registry);
        }

        return $removed;
    }

    /**
     * @param list<mixed> $items
     * @return int Number of written pages.
     *
     * @throws JsonException If an item cannot be encoded as JSON.
     * @throws UnexpectedValueException If one item cannot fit into a page.
     */
    private function WriteChunkedJsonTransferPages(
        string $scope,
        string $token,
        array $items,
        int $maxPageBytes,
        array $storage
    ): int {
        $page = 0;
        $encodedItems = [];
        $pageBytes = 2;

        try {
            foreach ($items as $item) {
                $encodedItem = json_encode($item, self::CHUNKED_JSON_TRANSFER_FLAGS);
                $separatorBytes = $encodedItems === [] ? 0 : 1;
                $candidateBytes = $pageBytes + $separatorBytes + strlen($encodedItem);

                if ($candidateBytes <= $maxPageBytes) {
                    $encodedItems[] = $encodedItem;
                    $pageBytes = $candidateBytes;
                    continue;
                }

                if ($encodedItems === []) {
                    throw new UnexpectedValueException(sprintf(
                        'One chunked JSON transfer item exceeds the maximum page size of %d bytes.',
                        $maxPageBytes
                    ));
                }

                $this->WriteChunkedJsonTransferFilePage($scope, $token, $page, '[' . implode(',', $encodedItems) . ']', $storage);
                ++$page;
                $encodedItems = [$encodedItem];
                $pageBytes = 2 + strlen($encodedItem);
                if ($pageBytes > $maxPageBytes) {
                    throw new UnexpectedValueException(sprintf(
                        'One chunked JSON transfer item exceeds the maximum page size of %d bytes.',
                        $maxPageBytes
                    ));
                }
            }

            if ($encodedItems !== [] || $page === 0) {
                $this->WriteChunkedJsonTransferFilePage($scope, $token, $page, '[' . implode(',', $encodedItems) . ']', $storage);
                ++$page;
            }
        } catch (Throwable $exception) {
            // The creator removes the file on any encoding or write failure.
            throw $exception;
        }

        return $page;
    }

    /**
     * @return array{token:string,pageCount:int,itemCount:int,expiresAt:int,pageBytes:int,storage?:array}
     */
    private function ReadChunkedJsonTransferMetadata(string $scope, string $token): array
    {
        $raw = $this->GetBuffer($this->ChunkedJsonTransferMetadataBufferName($scope, $token));
        if ($raw === '') {
            throw new UnexpectedValueException('The chunked JSON transfer does not exist.');
        }

        try {
            $metadata = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The chunked JSON transfer metadata contains invalid JSON.', 0, $exception);
        }

        if (!is_array($metadata)
            || ($metadata['token'] ?? null) !== $token
            || !is_int($metadata['pageCount'] ?? null)
            || $metadata['pageCount'] < 1
            || !is_int($metadata['itemCount'] ?? null)
            || $metadata['itemCount'] < 0
            || !is_int($metadata['expiresAt'] ?? null)
            || !is_int($metadata['pageBytes'] ?? null)) {
            throw new UnexpectedValueException('The chunked JSON transfer metadata is invalid.');
        }

        /** @var array{token:string,pageCount:int,itemCount:int,expiresAt:int,pageBytes:int} $metadata */
        return $metadata;
    }

    /**
     * @return array<string,int>
     */
    private function ReadChunkedJsonTransferRegistry(string $scope): array
    {
        $raw = $this->GetBuffer($this->ChunkedJsonTransferRegistryBufferName($scope));
        if ($raw === '') {
            return [];
        }

        try {
            $registry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The chunked JSON transfer registry contains invalid JSON.', 0, $exception);
        }
        if (!is_array($registry)) {
            throw new UnexpectedValueException('The chunked JSON transfer registry is invalid.');
        }

        foreach ($registry as $token => $expiresAt) {
            if (!is_string($token)
                || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1
                || !is_int($expiresAt)) {
                throw new UnexpectedValueException('The chunked JSON transfer registry is invalid.');
            }
        }

        /** @var array<string,int> $registry */
        return $registry;
    }

    /**
     * @param array<string,int> $registry
     */
    private function WriteChunkedJsonTransferRegistry(string $scope, array $registry): void
    {
        $this->SetChunkedJsonTransferBuffer(
            $this->ChunkedJsonTransferRegistryBufferName($scope),
            $registry === [] ? '' : json_encode($registry, self::CHUNKED_JSON_TRANSFER_FLAGS)
        );
    }

    /** Serialize buffer registry changes for all transfer scopes of one instance. */
    private function WithChunkedJsonTransferLock(callable $operation): mixed
    {
        $lock = 'ChunkedJsonTransfer:' . $this->ChunkedJsonTransferOwner();
        $locked = function_exists('IPS_SemaphoreEnter');
        if ($locked && !IPS_SemaphoreEnter($lock, 5000)) {
            throw new RuntimeException('The chunked JSON transfer store is busy.');
        }
        try {
            return $operation();
        } finally {
            if ($locked) {
                IPS_SemaphoreLeave($lock);
            }
        }
    }

    /** Count every instance buffer, including buffers owned by other helpers. */
    private function SetChunkedJsonTransferBuffer(string $name, string $value): void
    {
        if ($value !== '') {
            $total = strlen($name) + strlen($value);
            foreach ($this->GetBufferList() as $existingName) {
                if ($existingName !== $name) {
                    $existing = $this->GetBuffer($existingName);
                    if ($existing !== '') {
                        $total += strlen($existingName) + strlen($existing);
                    }
                }
            }
            if ($total > self::CHUNKED_JSON_TRANSFER_BUFFER_BUDGET) {
                throw new RuntimeException('Insufficient instance buffer budget for a chunked JSON transfer.');
            }
        }
        $this->SetBuffer($name, $value);
        if ($this->GetBuffer($name) !== $value) {
            throw new RuntimeException('Unable to store chunked JSON transfer metadata.');
        }
    }

    private function ChunkedJsonTransferOwner(): string
    {
        $kernel = function_exists('IPS_GetKernelDir') ? IPS_GetKernelDir() : (string) getmypid();
        return substr(hash('sha256', $kernel . ':' . ($this->InstanceID ?? spl_object_id($this))), 0, 24);
    }

    /** @return array{file:string,key:string,slotBytes:int} */
    private function CreateChunkedJsonTransferStorage(string $token, int $expiresAt, int $pageBytes): array
    {
        $pattern = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'symcon-transfer-' . $this->ChunkedJsonTransferOwner() . '-*.bin';
        if (count(glob($pattern) ?: []) >= self::CHUNKED_JSON_TRANSFER_MAX_ACTIVE) {
            throw new RuntimeException('Too many chunked JSON transfers are active for this instance.');
        }
        if (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('Chunked JSON transfers require OpenSSL AES-256-GCM.');
        }
        $storage = [
            'file'      => 'symcon-transfer-' . $this->ChunkedJsonTransferOwner() . '-' . $expiresAt . '-' . $token . '.bin',
            'key'       => base64_encode(random_bytes(32)),
            'slotBytes' => $pageBytes + 32
        ];
        $path = $this->ChunkedJsonTransferStoragePath($storage);
        $file = @fopen($path, 'xb');
        if ($file === false) {
            throw new RuntimeException('Unable to create the temporary chunked JSON transfer file.');
        }
        fclose($file);
        if (PHP_OS_FAMILY !== 'Windows' && !@chmod($path, 0600)) {
            $this->DeleteChunkedJsonTransferStorage($storage);
            throw new RuntimeException('Unable to protect the temporary chunked JSON transfer file.');
        }
        return $storage;
    }

    private function ChunkedJsonTransferStoragePath(array $storage): string
    {
        $file = $storage['file'] ?? '';
        $pattern = '/^symcon-transfer-' . $this->ChunkedJsonTransferOwner() . '-[0-9]{1,12}-[a-f0-9]{32}\\.bin$/D';
        if (!is_string($file) || preg_match($pattern, $file) !== 1) {
            throw new UnexpectedValueException('Invalid chunked JSON transfer storage identity.');
        }
        $path = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $file;
        if (is_link($path)) {
            throw new UnexpectedValueException('Unsafe chunked JSON transfer storage.');
        }
        return $path;
    }

    private function ChunkedJsonTransferStorageKey(array $storage): string
    {
        $key = base64_decode((string) ($storage['key'] ?? ''), true);
        if ($key === false || strlen($key) !== 32) {
            throw new UnexpectedValueException('Invalid chunked JSON transfer storage key.');
        }
        return $key;
    }

    private function ChunkedJsonTransferSlotBytes(array $storage): int
    {
        $size = $storage['slotBytes'] ?? 0;
        if (!is_int($size) || $size < self::CHUNKED_JSON_TRANSFER_MIN_PAGE_BYTES + 32
            || $size > self::CHUNKED_JSON_TRANSFER_MAX_PAGE_BYTES + 32) {
            throw new UnexpectedValueException('Invalid chunked JSON transfer slot size.');
        }
        return $size;
    }

    private function WriteChunkedJsonTransferFilePage(string $scope, string $token, int $page, string $raw, array $storage): void
    {
        $slotBytes = $this->ChunkedJsonTransferSlotBytes($storage);
        if (($page + 1) * $slotBytes > self::CHUNKED_JSON_TRANSFER_MAX_FILE_BYTES) {
            throw new UnexpectedValueException('The chunked JSON transfer exceeds the temporary storage limit.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt(
            $raw,
            'aes-256-gcm',
            $this->ChunkedJsonTransferStorageKey($storage),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $scope . ':' . $token . ':' . $page
        );
        if ($encrypted === false || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt the chunked JSON transfer page.');
        }
        $record = $nonce . $tag . $encrypted;
        $record = pack('N', strlen($record)) . $record;
        if (strlen($record) > $slotBytes) {
            throw new UnexpectedValueException('The chunked JSON transfer page exceeds its storage slot.');
        }
        $file = @fopen($this->ChunkedJsonTransferStoragePath($storage), 'r+b');
        if ($file === false) {
            throw new RuntimeException('The temporary chunked JSON transfer file is unavailable.');
        }
        try {
            if (fseek($file, $page * $slotBytes) !== 0 || fwrite($file, $record) !== strlen($record) || !fflush($file)) {
                throw new RuntimeException('Unable to write the chunked JSON transfer page.');
            }
        } finally {
            fclose($file);
        }
    }

    private function ReadChunkedJsonTransferFilePage(string $scope, string $token, int $page, array $storage): string
    {
        $slotBytes = $this->ChunkedJsonTransferSlotBytes($storage);
        if (($page + 1) * $slotBytes > self::CHUNKED_JSON_TRANSFER_MAX_FILE_BYTES) {
            throw new UnexpectedValueException('Invalid chunked JSON transfer storage offset.');
        }
        $file = @fopen($this->ChunkedJsonTransferStoragePath($storage), 'rb');
        if ($file === false) {
            throw new UnexpectedValueException('The requested chunked JSON transfer page is missing.');
        }
        try {
            if (fseek($file, $page * $slotBytes) !== 0) {
                throw new UnexpectedValueException('Invalid chunked JSON transfer storage offset.');
            }
            $header = fread($file, 4);
            $length = strlen($header) === 4 ? unpack('Nlength', $header)['length'] : 0;
            if ($length < 28 || $length > $slotBytes - 4) {
                throw new UnexpectedValueException('Invalid chunked JSON transfer page length.');
            }
            $record = stream_get_contents($file, $length);
            if ($record === false || strlen($record) !== $length) {
                throw new UnexpectedValueException('The chunked JSON transfer page is truncated.');
            }
        } finally {
            fclose($file);
        }
        $raw = openssl_decrypt(
            substr($record, 28),
            'aes-256-gcm',
            $this->ChunkedJsonTransferStorageKey($storage),
            OPENSSL_RAW_DATA,
            substr($record, 0, 12),
            substr($record, 12, 16),
            $scope . ':' . $token . ':' . $page
        );
        if ($raw === false) {
            throw new UnexpectedValueException('The chunked JSON transfer page failed authentication.');
        }
        return $raw;
    }

    private function DeleteChunkedJsonTransferStorage(array $storage): void
    {
        $path = $this->ChunkedJsonTransferStoragePath($storage);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Unable to remove the temporary chunked JSON transfer file.');
        }
    }

    /** Remove only expired encrypted files owned by this instance, including restart orphans. */
    private function CleanupChunkedJsonTransferFiles(): void
    {
        $prefix = 'symcon-transfer-' . $this->ChunkedJsonTransferOwner() . '-';
        foreach (glob(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $prefix . '*.bin') ?: [] as $path) {
            $file = basename($path);
            if (preg_match('/^' . $prefix . '([0-9]{1,12})-[a-f0-9]{32}\\.bin$/D', $file, $match) === 1
                && (int) $match[1] <= $this->GetChunkedJsonTransferTimestamp() && !is_link($path)) {
                $this->DeleteChunkedJsonTransferStorage(['file' => $file]);
            }
        }
    }

    private function AssertChunkedJsonTransferScope(string $scope): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $scope) !== 1) {
            throw new InvalidArgumentException(
                'Chunked JSON transfer scope must contain 1 to 64 letters, digits, dots, underscores or hyphens.'
            );
        }
    }

    private function AssertChunkedJsonTransferToken(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Chunked JSON transfer token is invalid.');
        }
    }

    private function ChunkedJsonTransferRegistryBufferName(string $scope): string
    {
        return self::CHUNKED_JSON_TRANSFER_BUFFER_PREFIX . ':' . $this->ChunkedJsonTransferScopeID($scope) . ':Registry';
    }

    private function ChunkedJsonTransferMetadataBufferName(string $scope, string $token): string
    {
        return self::CHUNKED_JSON_TRANSFER_BUFFER_PREFIX . ':' . $this->ChunkedJsonTransferScopeID($scope)
            . ':' . $token . ':Metadata';
    }

    private function ChunkedJsonTransferPageBufferName(string $scope, string $token, int $page): string
    {
        return self::CHUNKED_JSON_TRANSFER_BUFFER_PREFIX . ':' . $this->ChunkedJsonTransferScopeID($scope)
            . ':' . $token . ':Page:' . $page;
    }

    private function ChunkedJsonTransferScopeID(string $scope): string
    {
        return substr(hash('sha256', $scope), 0, 16);
    }
}
