<?php

declare(strict_types=1);

namespace IPSKalender;

/** Transport policy only; never authenticates a caller or authorizes a document. */
final class CalendarAttachmentTransport
{
    /**
     * Checks runtime-owned connection information; forwarded headers never prove TLS.
     * Private peers are not proof of an end-to-end private route (e.g. hidden proxies).
     *
     * @param array<string, mixed> $server Server request environment, never request JSON.
     * @param bool $allowLocalHttp Explicit administrator acceptance of plaintext risk.
     * @return bool Whether the connection meets the configured transport requirement.
     */
    public static function allows(array $server, bool $allowLocalHttp): bool
    {
        if (in_array($server['HTTPS'] ?? null, ['on', '1', 1], true)) {
            return true;
        }
        if (!$allowLocalHttp) {
            return false;
        }
        foreach (['HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_REAL_IP'] as $header) {
            if (array_key_exists($header, $server)) {
                return false;
            }
        }
        $address = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        if (strlen($packed) === 4) {
            $first = ord($packed[0]);
            $second = ord($packed[1]);
            return $first === 10 || $first === 127
                || ($first === 172 && $second >= 16 && $second <= 31)
                || ($first === 192 && $second === 168);
        }
        return $packed === str_repeat("\0", 15) . "\1" || (ord($packed[0]) & 0xfe) === 0xfc;
    }
}
