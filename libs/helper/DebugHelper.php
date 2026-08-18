<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use Stringable;
use Throwable;

/**
 * Provides privacy-conscious debug output for Symcon modules.
 *
 * Intended for classes derived from IPSModule/IPSModuleStrict. The helper keeps
 * the native SendDebug() channel while normalizing structured data, masking
 * common secret fields and credentials, and limiting oversized messages.
 *
 * @version 1.0.1
 */
trait DebugHelper
{
    private const DEBUG_DEFAULT_MAX_LENGTH = 16_384;
    private const DEBUG_MAX_DEPTH = 8;
    private const DEBUG_MASK = '***';

    /** @var list<string> */
    private const DEBUG_SENSITIVE_KEYS = [
        'accesskey',
        'accesstoken',
        'apikey',
        'authorization',
        'clientsecret',
        'cookie',
        'idtoken',
        'password',
        'passwd',
        'privatekey',
        'proxyauthorization',
        'pwd',
        'refreshtoken',
        'secret',
        'sessionid',
        'setcookie'
    ];

    /**
     * Sends text or structured data through Symcon's native debug channel after
     * sanitizing common credentials and applying a safe size limit.
     *
     * @param string       $message                 Debug message/category shown by Symcon.
     * @param mixed        $data                    Text, scalar, array, object, or exception to log.
     * @param int          $maxLength               Maximum UTF-8 byte length of the rendered payload.
     * @param list<string> $additionalSensitiveKeys Optional module-specific keys that must be masked.
     */
    protected function SendSafeDebug(
        string $message,
        mixed $data,
        int $maxLength = self::DEBUG_DEFAULT_MAX_LENGTH,
        array $additionalSensitiveKeys = []
    ): void {
        $this->SendDebug(
            $message,
            $this->FormatSafeDebugData($data, $maxLength, $additionalSensitiveKeys),
            0
        );
    }

    /**
     * Sends a compact exception description without a stack trace or arguments.
     *
     * @param list<string> $additionalSensitiveKeys Optional module-specific keys that must be masked.
     */
    protected function SendSafeDebugException(
        string $message,
        Throwable $exception,
        int $maxLength = self::DEBUG_DEFAULT_MAX_LENGTH,
        array $additionalSensitiveKeys = []
    ): void {
        $this->SendSafeDebug(
            $message,
            [
                'type'    => $exception::class,
                'message' => $exception->getMessage(),
                'code'    => $exception->getCode(),
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine()
            ],
            $maxLength,
            $additionalSensitiveKeys
        );
    }

    /**
     * Formats debug data without sending it. Useful for tests or when a module
     * needs to embed sanitized diagnostic data in a larger debug message.
     *
     * @param list<string> $additionalSensitiveKeys Optional module-specific keys that must be masked.
     */
    protected function FormatSafeDebugData(
        mixed $data,
        int $maxLength = self::DEBUG_DEFAULT_MAX_LENGTH,
        array $additionalSensitiveKeys = []
    ): string {
        if ($maxLength < 64) {
            throw new InvalidArgumentException('The debug output limit must be at least 64 bytes.');
        }

        $sensitiveKeys = $this->DebugSensitiveKeyMap($additionalSensitiveKeys);
        $sanitized = $this->SanitizeDebugValue($data, $sensitiveKeys, 0);

        if (is_string($sanitized)) {
            $rendered = $sanitized;
        } else {
            try {
                $rendered = json_encode(
                    $sanitized,
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_PRESERVE_ZERO_FRACTION
                        | JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                $rendered = '[unserializable debug data]';
            }
        }

        return $this->LimitDebugText($rendered, $maxLength);
    }

    /**
     * @param list<string> $additionalSensitiveKeys
     * @return array<string, true>
     */
    private function DebugSensitiveKeyMap(array $additionalSensitiveKeys): array
    {
        $keys = [];
        foreach ([...self::DEBUG_SENSITIVE_KEYS, ...$additionalSensitiveKeys] as $key) {
            $normalized = $this->NormalizeDebugKey((string) $key);
            if ($normalized !== '') {
                $keys[$normalized] = true;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, true> $sensitiveKeys
     */
    private function SanitizeDebugValue(mixed $value, array $sensitiveKeys, int $depth): mixed
    {
        if ($depth >= self::DEBUG_MAX_DEPTH) {
            return '[maximum debug depth reached]';
        }

        if (is_string($value)) {
            return $this->SanitizeDebugText($value);
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NAN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'INF' : '-INF';
            }

            return $value;
        }
        if ($value instanceof Throwable) {
            return $this->SanitizeDebugValue(
                [
                    'type'    => $value::class,
                    'message' => $value->getMessage(),
                    'code'    => $value->getCode(),
                    'file'    => $value->getFile(),
                    'line'    => $value->getLine()
                ],
                $sensitiveKeys,
                $depth + 1
            );
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof Stringable) {
            return $this->SanitizeDebugText((string) $value);
        }
        if (is_resource($value)) {
            return sprintf('[resource:%s]', get_resource_type($value));
        }
        if (is_object($value)) {
            return $this->SanitizeDebugValue(
                ['__class' => $value::class, ...get_object_vars($value)],
                $sensitiveKeys,
                $depth + 1
            );
        }
        if (!is_array($value)) {
            return sprintf('[unsupported:%s]', get_debug_type($value));
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->IsSensitiveDebugKey($key, $sensitiveKeys)) {
                $sanitized[$key] = self::DEBUG_MASK;
                continue;
            }
            $sanitized[$key] = $this->SanitizeDebugValue($item, $sensitiveKeys, $depth + 1);
        }

        return $sanitized;
    }

    /** @param array<string, true> $sensitiveKeys */
    private function IsSensitiveDebugKey(string $key, array $sensitiveKeys): bool
    {
        $normalized = $this->NormalizeDebugKey($key);
        if ($normalized === '') {
            return false;
        }
        if (isset($sensitiveKeys[$normalized])) {
            return true;
        }

        return str_ends_with($normalized, 'password')
            || str_ends_with($normalized, 'secret')
            || str_ends_with($normalized, 'token')
            || str_ends_with($normalized, 'apikey');
    }

    private function NormalizeDebugKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim($key)));
    }

    private function SanitizeDebugText(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (preg_match('//u', $text) !== 1) {
            return sprintf('[binary data: %d bytes]', strlen($text));
        }

        $patterns = [
            [
                '/(?im)\b(Authorization|Proxy-Authorization)\s*:\s*[^\r\n]+/',
                '$1: ' . self::DEBUG_MASK
            ],
            [
                '/(?im)\b(Cookie|Set-Cookie)\s*:\s*[^\r\n]+/',
                '$1: ' . self::DEBUG_MASK
            ],
            [
                '/(?i)\bBearer\s+[A-Za-z0-9._~+\/-]+={0,2}/',
                'Bearer ' . self::DEBUG_MASK
            ],
            [
                '/(?i)\bBasic\s+[A-Za-z0-9+\/=]+/',
                'Basic ' . self::DEBUG_MASK
            ],
            [
                '/(?i)(:\/\/[^:\/\s]+:)[^@\s]+@/',
                '$1' . self::DEBUG_MASK . '@'
            ],
            [
                '/(?i)([?&](?:access_token|refresh_token|id_token|client_secret|api_key|apikey|password|passwd|pwd)=)[^&#\s]+/',
                '$1' . self::DEBUG_MASK
            ],
            [
                '/(?i)\b(access[_-]?token|refresh[_-]?token|id[_-]?token|client[_-]?secret|api[_-]?key|password|passwd|pwd)\b(\s*[:=]\s*)(["\']?)[^,\s}"\']+\3/',
                '$1$2$3' . self::DEBUG_MASK . '$3'
            ]
        ];

        foreach ($patterns as [$pattern, $replacement]) {
            $sanitized = preg_replace($pattern, $replacement, $text);
            if (is_string($sanitized)) {
                $text = $sanitized;
            }
        }

        return $text;
    }

    private function LimitDebugText(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $suffix = '… [truncated]';
        $limit = max(1, $maxLength - strlen($suffix));
        if (function_exists('mb_strcut')) {
            $prefix = mb_strcut($text, 0, $limit, 'UTF-8');
        } else {
            $prefix = substr($text, 0, $limit);
            while ($prefix !== '' && preg_match('//u', $prefix) !== 1) {
                $prefix = substr($prefix, 0, -1);
            }
        }

        return $prefix . $suffix;
    }
}
