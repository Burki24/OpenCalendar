<?php

declare(strict_types=1);

namespace IPSKalender;

use InvalidArgumentException;

require_once __DIR__ . '/LocalAttachmentStore.php';

/** Conservative upload admission, not a malware scanner or document sanitizer. */
final class AttachmentUploadPolicy
{
    /**
     * Validate before persistence; client MIME declarations are not trusted.
     * Downloads must remain attachment-only, without inline previews.
     *
     * @param string $name Display filename, never a filesystem path.
     * @param string $base64 Canonical bounded content.
     * @return string Server-assigned media type; not proof of safe content.
     */
    public static function validate(string $name, string $base64): string
    {
        if ($name === '' || strlen($name) > 180 || trim($name) !== $name
            || preg_match('//u', $name) !== 1
            || preg_match('/[\p{Cc}\p{Cf}\/\\\\:<>"|?*]/u', $name)
            || str_ends_with($name, '.')
            || preg_match('/^(?:CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(?:\.|$)/i', $name)) {
            throw new InvalidArgumentException('Invalid attachment filename.');
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $types = ['txt' => 'text/plain', 'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
        if (!isset($types[$extension])) {
            throw new InvalidArgumentException('Supported attachment formats are TXT, PDF, PNG and JPEG.');
        }
        if (strlen($base64) > 4 * (int) ceil(LocalAttachmentStore::MAX_FILE_BYTES / 3)) {
            throw new InvalidArgumentException('Attachment exceeds 2 MiB.');
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || base64_encode($bytes) !== $base64
            || strlen($bytes) > LocalAttachmentStore::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('Invalid or oversized attachment content.');
        }
        $matches = match ($extension) {
            'txt' => preg_match('//u', $bytes) === 1 && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $bytes),
            'pdf' => preg_match('/^%PDF-[12]\.[0-9][\r\n]/', $bytes) === 1
                && preg_match('/%%EOF\s*$/D', $bytes) === 1,
            'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
                && (self::imageType($bytes) === IMAGETYPE_PNG),
            'jpg', 'jpeg' => str_starts_with($bytes, "\xff\xd8\xff")
                && (self::imageType($bytes) === IMAGETYPE_JPEG)
        };
        if (!$matches) {
            throw new InvalidArgumentException('Attachment content does not match its filename format.');
        }
        return $types[$extension];
    }

    private static function imageType(string $bytes): int
    {
        $image = @getimagesizefromstring($bytes);
        // Reject extreme dimensions without allocating a decompressed bitmap.
        if ($image === false || $image[0] < 1 || $image[1] < 1
            || $image[0] > 10000 || $image[1] > 10000 || $image[0] * $image[1] > 40000000) {
            return 0;
        }
        return $image[2];
    }
}
