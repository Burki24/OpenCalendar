<?php

declare(strict_types=1);

use IPSKalender\CalDAVProvider;
use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpException;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\CalendarProviderError;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\MicrosoftCalendarProvider;

require_once __DIR__ . '/../libs/CalendarProviderError.php';
require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/MicrosoftCalendarProvider.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';

final class RealProviderErrorHttpClient implements CalendarHttpClientInterface
{
    /** @var list<CalendarHttpResponse|Throwable> */
    private array $outcomes;

    /** @param list<CalendarHttpResponse|Throwable> $outcomes */
    public function __construct(array $outcomes)
    {
        $this->outcomes = $outcomes;
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $maxResponseBytes = 67_108_864
    ): CalendarHttpResponse {
        if ($this->outcomes === []) {
            throw new RuntimeException('The realistic provider-error test has no queued HTTP outcome.');
        }

        $outcome = array_shift($this->outcomes);
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}

function realProviderErrorResponse(
    int $statusCode,
    string $body,
    string $effectiveUrl = ''
): CalendarHttpResponse {
    return new CalendarHttpResponse($statusCode, [], $body, $effectiveUrl);
}

function realProviderErrorExpect(
    callable $operation,
    string $expectedType,
    int $expectedStatus,
    string $message
): void {
    try {
        $operation();
    } catch (Throwable $exception) {
        $error = CalendarProviderError::fromThrowable($exception);
        if ($error['type'] !== $expectedType || $error['httpStatus'] !== $expectedStatus) {
            throw new RuntimeException(sprintf(
                '%s Expected %s/%d, got %s/%d from %s: %s',
                $message,
                $expectedType,
                $expectedStatus,
                $error['type'],
                $error['httpStatus'],
                $exception::class,
                $exception->getMessage()
            ));
        }

        return;
    }

    throw new RuntimeException($message . ' The provider operation unexpectedly succeeded.');
}

realProviderErrorExpect(
    static function (): void
    {
        $http = new RealProviderErrorHttpClient([
            realProviderErrorResponse(
                401,
                '{"error":{"code":401,"message":"Invalid Credentials"}}'
            )
        ]);
        (new GoogleCalendarProvider($http, 'expired-token'))->getCalendars();
    },
    CalendarProviderError::TYPE_AUTHENTICATION,
    401,
    'A real Google HTTP 401 response must propagate as an authentication failure.'
);

realProviderErrorExpect(
    static function (): void
    {
        $http = new RealProviderErrorHttpClient([
            realProviderErrorResponse(
                403,
                '{"error":{"code":403,"message":"Rate Limit Exceeded","errors":[{"reason":"rateLimitExceeded"}]}}'
            )
        ]);
        (new GoogleCalendarProvider($http, 'access-token'))->getCalendars();
    },
    CalendarProviderError::TYPE_RATE_LIMITED,
    403,
    'Google rateLimitExceeded metadata must not be mistaken for a permission failure.'
);

realProviderErrorExpect(
    static function (): void
    {
        $http = new RealProviderErrorHttpClient([
            new CalendarHttpException('HTTP request failed (28): Operation timed out after 30000 milliseconds.')
        ]);
        (new GoogleCalendarProvider($http, 'access-token'))->getCalendars();
    },
    CalendarProviderError::TYPE_TRANSPORT,
    0,
    'A real calendar HTTP timeout must propagate as a transport failure.'
);

realProviderErrorExpect(
    static function (): void
    {
        $http = new RealProviderErrorHttpClient([
            realProviderErrorResponse(200, '{"items":')
        ]);
        (new GoogleCalendarProvider($http, 'access-token'))->getCalendars();
    },
    CalendarProviderError::TYPE_INVALID_RESPONSE,
    200,
    'Malformed Google JSON must propagate as an invalid provider response.'
);

realProviderErrorExpect(
    static function (): void
    {
        $http = new RealProviderErrorHttpClient([
            realProviderErrorResponse(
                403,
                '{"error":{"code":"ErrorAccessDenied","message":"Access is denied."}}'
            )
        ]);
        (new MicrosoftCalendarProvider($http, 'access-token'))->getCalendars();
    },
    CalendarProviderError::TYPE_ACCESS_DENIED,
    403,
    'A real Microsoft Graph HTTP 403 response must propagate as an access-denied failure.'
);

realProviderErrorExpect(
    static function (): void
    {
        $http = new RealProviderErrorHttpClient([
            realProviderErrorResponse(
                429,
                '{"error":{"code":"TooManyRequests","message":"Please retry later."}}'
            )
        ]);
        (new MicrosoftCalendarProvider($http, 'access-token'))->getCalendars();
    },
    CalendarProviderError::TYPE_RATE_LIMITED,
    429,
    'A real Microsoft Graph HTTP 429 response must propagate as a rate-limit failure.'
);

realProviderErrorExpect(
    static function (): void
    {
        $http = new RealProviderErrorHttpClient([
            realProviderErrorResponse(
                503,
                '{"error":{"code":"ServiceUnavailable","message":"Service temporarily unavailable."}}'
            )
        ]);
        (new MicrosoftCalendarProvider($http, 'access-token'))->getCalendars();
    },
    CalendarProviderError::TYPE_UNAVAILABLE,
    503,
    'A real Microsoft Graph HTTP 503 response must propagate as temporary unavailability.'
);

realProviderErrorExpect(
    static function (): void
    {
        $http = new RealProviderErrorHttpClient([
            realProviderErrorResponse(200, '{"value":')
        ]);
        (new MicrosoftCalendarProvider($http, 'access-token'))->getCalendars();
    },
    CalendarProviderError::TYPE_INVALID_RESPONSE,
    200,
    'Malformed Microsoft Graph JSON must propagate as an invalid provider response.'
);

$calendarUrl = 'https://calendar.example/calendars/user/work/';
$rangeStart = new DateTimeImmutable('2026-08-01T00:00:00Z');
$rangeEnd = new DateTimeImmutable('2026-09-01T00:00:00Z');

realProviderErrorExpect(
    static function () use ($calendarUrl, $rangeStart, $rangeEnd): void
    {
        $http = new RealProviderErrorHttpClient([
            realProviderErrorResponse(503, '', $calendarUrl)
        ]);
        (new CalDAVProvider($http, 'https://calendar.example/'))->getEvents(
            $calendarUrl,
            $rangeStart,
            $rangeEnd
        );
    },
    CalendarProviderError::TYPE_UNAVAILABLE,
    503,
    'A real CalDAV HTTP 503 response must propagate as temporary unavailability.'
);

realProviderErrorExpect(
    static function () use ($calendarUrl, $rangeStart, $rangeEnd): void
    {
        $http = new RealProviderErrorHttpClient([
            realProviderErrorResponse(207, '<d:multistatus', $calendarUrl)
        ]);
        (new CalDAVProvider($http, 'https://calendar.example/'))->getEvents(
            $calendarUrl,
            $rangeStart,
            $rangeEnd
        );
    },
    CalendarProviderError::TYPE_INVALID_RESPONSE,
    0,
    'Malformed CalDAV XML must propagate as an invalid provider response.'
);

fwrite(STDOUT, "Realistic provider error handling tests passed.\n");
