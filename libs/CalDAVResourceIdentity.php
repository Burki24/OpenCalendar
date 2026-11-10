<?php

declare(strict_types=1);

namespace IPSKalender;

/** Comparison-only identity for CalDAV href spellings; never a request URL. */
final class CalDAVResourceIdentity
{
    /**
     * Normalizes unreserved path characters and the @ used in calendar filenames.
     * Keeps origins, query strings, path separators and double encoding distinct.
     */
    public static function key(string $url): string
    {
        $url = trim($url);
        if (preg_match('~^(https?://[^/]+)(/[^?#]*)(.*)$~D', $url, $parts) !== 1) {
            return $url;
        }
        $path = preg_replace_callback('/%([0-9a-f]{2})/i', static function (array $match): string
        {
            $character = chr((int) hexdec($match[1]));
            return preg_match('/^[a-z0-9._~@-]$/iD', $character) === 1
                ? $character
                : '%' . strtoupper($match[1]);
        }, $parts[2]);

        return $parts[1] . $path . $parts[3];
    }
}
