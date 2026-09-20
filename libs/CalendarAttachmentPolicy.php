<?php

declare(strict_types=1);

namespace IPSKalender;

/** Configuration authorization only, never a file transfer or identity credential. */
final class CalendarAttachmentPolicy
{
    /**
     * @param int $mode 0 disabled, 1 read, 2 manage; unknown values deny access.
     * @param bool $local Allow access to locally stored attachments.
     * @param bool $provider Allow access to provider-stored attachments.
     * @param int $identityMode 0 shared view; other modes require an unavailable identity adapter.
     */
    public function __construct(
        private readonly int $mode = 0,
        private readonly bool $local = false,
        private readonly bool $provider = false,
        private readonly int $identityMode = 0
    ) {
    }

    /**
     * Evaluates server-owned configuration, not browser-supplied permission claims.
     * Callers must additionally authenticate and verify selection, ownership and capabilities.
     *
     * @param string $operation One of list, download, upload, delete.
     * @param string $destination Explicit local or provider destination, never inferred.
     * @return bool Whether this policy permits the operation.
     */
    public function allows(string $operation, string $destination): bool
    {
        if ($this->identityMode !== 0 || !in_array($this->mode, [1, 2], true)) {
            return false;
        }
        if (!in_array($operation, ['list', 'download', 'upload', 'delete'], true)) {
            return false;
        }
        if (in_array($operation, ['upload', 'delete'], true) && $this->mode !== 2) {
            return false;
        }
        return match ($destination) {
            'local'    => $this->local,
            'provider' => $this->provider,
            default    => false
        };
    }
}
