<?php

declare(strict_types=1);

namespace IPSKalender;

use JsonException;
use RuntimeException;
use Throwable;

final class CalendarProviderErrorException extends RuntimeException
{
    /**
     * Creates a child-side provider error while preserving its normalized error type.
     */
    public function __construct(string $message, public readonly string $errorType)
    {
        parent::__construct($message);
    }
}

final class CalendarProviderError
{
    public const TYPE_PROVIDER = 'provider';
    public const TYPE_CONFLICT = 'conflict';
    public const TYPE_NOT_FOUND = 'not_found';
    public const TYPE_AUTHENTICATION = 'authentication';
    public const TYPE_ACCESS_DENIED = 'access_denied';
    public const TYPE_INVALID_RESPONSE = 'invalid_response';
    public const TYPE_RATE_LIMITED = 'rate_limited';
    public const TYPE_UNAVAILABLE = 'unavailable';
    public const TYPE_TRANSPORT = 'transport';

    public const MESSAGE_CONFLICT = 'The event was changed by another client. Synchronize the calendar and try again.';
    public const MESSAGE_NOT_FOUND = 'The selected event is no longer available.';

    private const TYPES = [
        self::TYPE_PROVIDER,
        self::TYPE_CONFLICT,
        self::TYPE_NOT_FOUND,
        self::TYPE_AUTHENTICATION,
        self::TYPE_ACCESS_DENIED,
        self::TYPE_INVALID_RESPONSE,
        self::TYPE_RATE_LIMITED,
        self::TYPE_UNAVAILABLE,
        self::TYPE_TRANSPORT
    ];

    private const RATE_LIMIT_CODES = [
        'ratelimitexceeded',
        'userratelimitexceeded',
        'quotaexceeded',
        'toomanyrequests',
        'activitylimitreached'
    ];

    private const UNAVAILABLE_CODES = [
        'serviceunavailable',
        'temporarilyunavailable',
        'backenderror'
    ];

    /**
     * Normalizes provider-specific exceptions into a stable child-gateway error contract.
     *
     * @return array{type:string,message:string,httpStatus:int}
     */
    public static function fromThrowable(Throwable $exception): array
    {
        $httpStatus = self::httpStatus($exception);
        $message = self::rawMessage($exception);
        $type = self::classify(
            $message,
            $httpStatus,
            $exception instanceof JsonException,
            self::providerCode($exception),
            self::isTransportException($exception)
        );

        return [
            'type'       => $type,
            'message'    => self::messageFor($type, $message),
            'httpStatus' => $httpStatus
        ];
    }

    /**
     * Validates a provider-neutral error type received through the child gateway.
     */
    public static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, self::TYPES, true) ? $type : '';
    }

    /**
     * Classifies an error message when transport metadata is unavailable.
     */
    public static function classifyMessage(string $message): string
    {
        return self::classify(self::normalizeMessage($message), 0, false, '', false);
    }

    /**
     * Returns a stable user-facing message for normalized conflicts and missing events.
     */
    public static function messageFor(string $type, string $fallback): string
    {
        return match (self::normalizeType($type)) {
            self::TYPE_CONFLICT  => self::MESSAGE_CONFLICT,
            self::TYPE_NOT_FOUND => self::MESSAGE_NOT_FOUND,
            default              => self::normalizeMessage($fallback)
        };
    }

    private static function classify(
        string $message,
        int $httpStatus,
        bool $jsonError,
        string $providerCode,
        bool $transportError
    ): string {
        if ($jsonError) {
            return self::TYPE_INVALID_RESPONSE;
        }
        if (in_array($providerCode, self::RATE_LIMIT_CODES, true) || $httpStatus === 429) {
            return self::TYPE_RATE_LIMITED;
        }
        if (in_array($providerCode, self::UNAVAILABLE_CODES, true)
            || ($httpStatus >= 500 && $httpStatus <= 599)) {
            return self::TYPE_UNAVAILABLE;
        }
        if ($httpStatus === 408) {
            return self::TYPE_TRANSPORT;
        }
        if (in_array($httpStatus, [409, 412, 428], true)) {
            return self::TYPE_CONFLICT;
        }
        if (in_array($httpStatus, [404, 410], true)) {
            return self::TYPE_NOT_FOUND;
        }
        if ($httpStatus === 401) {
            return self::TYPE_AUTHENTICATION;
        }
        if ($httpStatus === 403) {
            return self::TYPE_ACCESS_DENIED;
        }
        if ($transportError) {
            return self::TYPE_TRANSPORT;
        }

        $normalized = strtolower($message);
        if (str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'too many requests')
            || str_contains($normalized, 'quota exceeded')) {
            return self::TYPE_RATE_LIMITED;
        }
        if (str_contains($normalized, 'service unavailable')
            || str_contains($normalized, 'temporarily unavailable')
            || str_contains($normalized, 'backend error')) {
            return self::TYPE_UNAVAILABLE;
        }
        if (str_contains($normalized, 'http request failed')
            || str_contains($normalized, 'operation timed out')
            || str_contains($normalized, 'could not resolve host')
            || str_contains($normalized, 'failed to connect')
            || str_contains($normalized, 'connection refused')) {
            return self::TYPE_TRANSPORT;
        }
        if (str_contains($normalized, 'changed by another client')
            || str_contains($normalized, 'calendar object changed')
            || str_contains($normalized, 'precondition failed')
            || str_contains($normalized, 'etag conflict')) {
            return self::TYPE_CONFLICT;
        }
        if (str_contains($normalized, 'no longer available')
            || str_contains($normalized, 'not found')
            || str_contains($normalized, 'does not exist')
            || str_contains($normalized, 'itemnotfound')
            || str_contains($normalized, 'resourcenotfound')) {
            return self::TYPE_NOT_FOUND;
        }
        if (str_contains($normalized, 'authorization expired')
            || str_contains($normalized, 'authentication failed')
            || str_contains($normalized, 'not connected yet')) {
            return self::TYPE_AUTHENTICATION;
        }
        if (str_contains($normalized, 'access was denied')
            || str_contains($normalized, 'access denied')) {
            return self::TYPE_ACCESS_DENIED;
        }
        if (str_contains($normalized, 'invalid json')
            || str_contains($normalized, 'invalid data')
            || str_contains($normalized, 'invalid xml')
            || str_contains($normalized, 'malformed xml')
            || str_contains($normalized, 'invalid icalendar')
            || str_contains($normalized, 'invalid calendar data')
            || str_contains($normalized, 'event transfer')) {
            return self::TYPE_INVALID_RESPONSE;
        }

        return self::TYPE_PROVIDER;
    }

    private static function httpStatus(Throwable $exception): int
    {
        $properties = get_object_vars($exception);
        $status = (int) ($properties['httpStatus'] ?? 0);
        if ($status >= 100 && $status <= 599) {
            return $status;
        }

        $code = (int) $exception->getCode();
        return $code >= 100 && $code <= 599 ? $code : 0;
    }

    private static function providerCode(Throwable $exception): string
    {
        $properties = get_object_vars($exception);
        foreach (['reason', 'errorCode'] as $property) {
            $value = trim((string) ($properties[$property] ?? ''));
            if ($value !== '') {
                return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
            }
        }

        return '';
    }

    private static function isTransportException(Throwable $exception): bool
    {
        return str_ends_with($exception::class, '\\CalendarHttpException');
    }

    private static function rawMessage(Throwable $exception): string
    {
        $message = self::normalizeMessage($exception->getMessage());
        return $message !== '' ? $message : 'Unknown calendar error.';
    }

    private static function normalizeMessage(string $message): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $message));
    }
}
