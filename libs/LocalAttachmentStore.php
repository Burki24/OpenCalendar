<?php

declare(strict_types=1);

namespace IPSKalender;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Bounded working copy of local originals, never a cache or an authorization layer.
 * The runtime adapter must authenticate, resolve ownership and atomically persist
 * exportSnapshot() under a calendar lock before acknowledging a mutation.
 */
final class LocalAttachmentStore
{
    public const MAX_FILE_BYTES = 2_097_152;
    public const MAX_TOTAL_BYTES = 8_388_608;
    public const MAX_FILES = 100;
    private array $records = [];

    /**
     * Restores originals without accepting corrupt or unbounded state.
     *
     * @param string $snapshot Private persisted snapshot; never log or publish it.
     */
    public function __construct(string $snapshot = '')
    {
        if ($snapshot === '') {
            return;
        }
        if (strlen($snapshot) > 12_000_000) {
            throw new UnexpectedValueException('Attachment storage exceeds its encoded limit.');
        }
        $state = json_decode($snapshot, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['version'] ?? null) !== 1
            || !is_array($state['records'] ?? null) || !array_is_list($state['records'])
            || count($state['records']) > self::MAX_FILES) {
            throw new UnexpectedValueException('Invalid attachment storage. Restore a backup; do not reset it.');
        }
        $total = 0;
        $requests = [];
        foreach ($state['records'] as $record) {
            if (!is_array($record)) {
                throw new UnexpectedValueException('Invalid attachment record.');
            }
            foreach (['id', 'owner', 'requestId', 'name', 'content', 'revision'] as $key) {
                if (!is_string($record[$key] ?? null)) {
                    throw new UnexpectedValueException('Invalid attachment record.');
                }
            }
            $this->assertKey($record['id']);
            $this->assertKey($record['owner']);
            $this->assertKey($record['requestId']);
            $bytes = $this->decode($record['content']);
            $this->assertName($record['name']);
            if (isset($this->records[$record['id']]) || isset($requests[$record['requestId']])
                || ($record['size'] ?? null) !== strlen($bytes)
                || $record['revision'] !== hash('sha256', $bytes)) {
                throw new UnexpectedValueException('Inconsistent attachment storage.');
            }
            $total += strlen($bytes);
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new UnexpectedValueException('Attachment storage quota exceeded.');
            }
            $requests[$record['requestId']] = true;
            // Keep only known fields; never propagate injected paths or URLs.
            $this->records[$record['id']] = array_intersect_key($record, array_flip([
                'id', 'owner', 'requestId', 'name', 'content', 'revision', 'size'
            ]));
        }
    }

    /**
     * Stages an opaque file as download-only data. No preview or malware guarantee.
     *
     * @param string $owner Server-resolved, SHA-256-bound calendar/event identity.
     * @param string $requestId Random 256-bit retry identifier, scoped to this store.
     * @param string $name Display filename only, never used as a path.
     * @param string $base64 Canonical base64, bounded before decoding.
     * @return array<string, mixed> Metadata without original bytes or retry credentials.
     */
    public function add(string $owner, string $requestId, string $name, string $base64): array
    {
        $this->assertKey($owner);
        $this->assertKey($requestId);
        $this->assertName($name);
        $bytes = $this->decode($base64);
        $revision = hash('sha256', $bytes);
        foreach ($this->records as $record) {
            if ($record['requestId'] === $requestId) {
                if ($record['owner'] !== $owner || $record['name'] !== $name || $record['revision'] !== $revision) {
                    throw new RuntimeException('Attachment retry conflicts with an earlier upload.');
                }
                return $this->metadata($record);
            }
        }
        if (count($this->records) >= self::MAX_FILES
            || array_sum(array_column($this->records, 'size')) + strlen($bytes) > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('Attachment storage quota exceeded.');
        }
        do {
            $id = bin2hex(random_bytes(32));
        } while (isset($this->records[$id]));
        $record = ['id' => $id, 'owner' => $owner, 'requestId' => $requestId,
            'name'      => $name, 'content' => $base64, 'revision' => $revision, 'size' => strlen($bytes)];
        $this->records[$id] = $record;
        return $this->metadata($record);
    }

    /**
     * Lists only the supplied verified owner's records; never includes file content.
     *
     * @param string $owner Verified server-side identity.
     * @return list<array<string, mixed>> Safe metadata, still subject to authorization.
     */
    public function listForOwner(string $owner): array
    {
        $this->assertKey($owner);
        return array_values(array_map($this->metadata(...), array_filter(
            $this->records,
            static fn (array $record): bool => $record['owner'] === $owner
        )));
    }

    /**
     * Reads private bytes for a dedicated authenticated download response only.
     *
     * @param string $owner Verified server-side identity.
     * @param string $id Opaque attachment identifier.
     * @return string Original bytes, never place in shared visualization state.
     */
    public function read(string $owner, string $id): string
    {
        return $this->decode($this->ownedRecord($owner, $id)['content']);
    }

    /**
     * Stages deliberate removal; stale revisions never delete a changed record.
     *
     * @param string $owner Verified server-side identity.
     * @param string $id Opaque attachment identifier.
     * @param string $revision Revision obtained from a current metadata listing.
     */
    public function remove(string $owner, string $id, string $revision): void
    {
        $record = $this->ownedRecord($owner, $id);
        if (!hash_equals($record['revision'], $revision)) {
            throw new RuntimeException('Attachment revision conflict.');
        }
        unset($this->records[$id]);
    }

    /** @return string Private original snapshot, not a public export or API response. */
    public function exportSnapshot(): string
    {
        return json_encode(['version' => 1, 'records' => array_values($this->records)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Lists all originals for trusted administrator maintenance, including orphaned files.
     * This is not an event/view API and does not determine whether an owner still exists.
     *
     * @return array{revision:string,totalBytes:int,files:list<array<string,mixed>>} Metadata only.
     */
    public function inventory(): array
    {
        return [
            'revision'   => hash('sha256', $this->exportSnapshot()),
            'totalBytes' => array_sum(array_column($this->records, 'size')),
            'files'      => array_values(array_map($this->metadata(...), $this->records))
        ];
    }

    /**
     * Stages administrator-selected deletions against an unchanged inventory.
     * Validates the complete selection before removing anything from the working copy.
     *
     * @param list<string> $ids Exact IDs deliberately selected by the administrator.
     * @param string $revision Snapshot revision from inventory(), not a file revision.
     * @return int Number of selected originals removed.
     */
    public function removeSelected(array $ids, string $revision): int
    {
        $this->assertKey($revision);
        if ($ids === [] || !array_is_list($ids) || count($ids) > self::MAX_FILES) {
            throw new InvalidArgumentException('Invalid attachment cleanup selection.');
        }
        $seen = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new InvalidArgumentException('Invalid attachment cleanup selection.');
            }
            $this->assertKey($id);
            if (isset($seen[$id]) || !isset($this->records[$id])) {
                throw new InvalidArgumentException('Duplicate or missing attachment in cleanup selection.');
            }
            $seen[$id] = true;
        }
        if (!hash_equals(hash('sha256', $this->exportSnapshot()), $revision)) {
            throw new RuntimeException('Attachment inventory changed. Refresh before deleting originals.');
        }
        foreach ($ids as $id) {
            unset($this->records[$id]);
        }
        return count($ids);
    }

    private function assertKey(string $key): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid attachment identity.');
        }
    }

    private function assertName(string $name): void
    {
        if ($name === '' || strlen($name) > 180 || trim($name) !== $name
            || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f\/\\\\:]/', $name)
            || in_array($name, ['.', '..'], true)) {
            throw new InvalidArgumentException('Invalid attachment filename.');
        }
    }

    private function decode(string $base64): string
    {
        if (strlen($base64) > 4 * (int) ceil(self::MAX_FILE_BYTES / 3)) {
            throw new InvalidArgumentException('Attachment exceeds 2 MiB.');
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || base64_encode($bytes) !== $base64 || strlen($bytes) > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('Invalid or oversized attachment content.');
        }
        return $bytes;
    }

    /** @return array<string, mixed> */
    private function ownedRecord(string $owner, string $id): array
    {
        $this->assertKey($owner);
        $this->assertKey($id);
        $record = $this->records[$id] ?? null;
        if ($record === null || $record['owner'] !== $owner) {
            throw new RuntimeException('Attachment not found.');
        }
        return $record;
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function metadata(array $record): array
    {
        return array_intersect_key($record, array_flip(['id', 'name', 'revision', 'size']));
    }
}
