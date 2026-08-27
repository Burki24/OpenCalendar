<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpException;
use IPSKalender\CalendarProviderError;

require_once __DIR__ . '/../libs/CalendarHttpClient.php';
require_once __DIR__ . '/../libs/CalendarProviderError.php';

function providerErrorContractExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function providerErrorContractSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Provider error contract source could not be read: ' . $path);
    }

    return $source;
}

final class ProviderErrorContractException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $reason = '',
        public readonly string $errorCode = ''
    ) {
        parent::__construct($message);
    }
}

$conflict = CalendarProviderError::fromThrowable(
    new ProviderErrorContractException('Provider-specific precondition failure.', 412)
);
providerErrorContractExpect(
    $conflict['type'] === CalendarProviderError::TYPE_CONFLICT
        && $conflict['message'] === CalendarProviderError::MESSAGE_CONFLICT
        && $conflict['httpStatus'] === 412,
    'HTTP 412 must map to the shared write-conflict contract.'
);

$conflict409 = CalendarProviderError::fromThrowable(
    new ProviderErrorContractException('Provider-specific conflict.', 409)
);
providerErrorContractExpect(
    $conflict409['type'] === CalendarProviderError::TYPE_CONFLICT
        && $conflict409['message'] === CalendarProviderError::MESSAGE_CONFLICT,
    'HTTP 409 must map to the shared write-conflict contract.'
);

$notFound = CalendarProviderError::fromThrowable(
    new ProviderErrorContractException('Provider-specific stale identifier.', 404)
);
providerErrorContractExpect(
    $notFound['type'] === CalendarProviderError::TYPE_NOT_FOUND
        && $notFound['message'] === CalendarProviderError::MESSAGE_NOT_FOUND,
    'HTTP 404 must map to the shared missing-event contract.'
);

providerErrorContractExpect(
    CalendarProviderError::classifyMessage('The selected event is no longer available.')
        === CalendarProviderError::TYPE_NOT_FOUND,
    'Missing-event messages must remain classifiable when HTTP metadata is unavailable.'
);
providerErrorContractExpect(
    CalendarProviderError::classifyMessage('The calendar object changed before the write completed.')
        === CalendarProviderError::TYPE_CONFLICT,
    'CalDAV conflict wording must map to the same write-conflict contract.'
);
providerErrorContractExpect(
    CalendarProviderError::fromThrowable(new JsonException('Malformed provider JSON.'))['type']
        === CalendarProviderError::TYPE_INVALID_RESPONSE,
    'JSON errors must map to the shared invalid-response contract.'
);
providerErrorContractExpect(
    CalendarProviderError::classifyMessage('The CalDAV server returned invalid XML.')
        === CalendarProviderError::TYPE_INVALID_RESPONSE,
    'Invalid CalDAV XML must map to the shared invalid-response contract.'
);
providerErrorContractExpect(
    CalendarProviderError::fromThrowable(
        new ProviderErrorContractException('Provider authentication failed.', 401)
    )['type'] === CalendarProviderError::TYPE_AUTHENTICATION,
    'HTTP 401 must be classified as an authentication failure.'
);
providerErrorContractExpect(
    CalendarProviderError::fromThrowable(
        new ProviderErrorContractException('Provider access denied.', 403)
    )['type'] === CalendarProviderError::TYPE_ACCESS_DENIED,
    'HTTP 403 must be classified as an access-denied failure.'
);
providerErrorContractExpect(
    CalendarProviderError::fromThrowable(
        new ProviderErrorContractException('Provider throttled the request.', 429)
    )['type'] === CalendarProviderError::TYPE_RATE_LIMITED,
    'HTTP 429 must be classified as a rate-limit failure.'
);
providerErrorContractExpect(
    CalendarProviderError::fromThrowable(
        new ProviderErrorContractException(
            'Rate Limit Exceeded',
            403,
            'rateLimitExceeded'
        )
    )['type'] === CalendarProviderError::TYPE_RATE_LIMITED,
    'Provider-specific throttling metadata must take precedence over a generic HTTP 403 classification.'
);
providerErrorContractExpect(
    CalendarProviderError::fromThrowable(
        new ProviderErrorContractException('Provider service unavailable.', 503)
    )['type'] === CalendarProviderError::TYPE_UNAVAILABLE,
    'HTTP 5xx responses must be classified as temporary provider unavailability.'
);
providerErrorContractExpect(
    CalendarProviderError::fromThrowable(
        new ProviderErrorContractException(
            'Provider backend problem.',
            0,
            '',
            'ServiceUnavailable'
        )
    )['type'] === CalendarProviderError::TYPE_UNAVAILABLE,
    'Provider-specific service-unavailable codes must remain classifiable without HTTP metadata.'
);
providerErrorContractExpect(
    CalendarProviderError::fromThrowable(
        new CalendarHttpException('HTTP request failed (28): Operation timed out.')
    )['type'] === CalendarProviderError::TYPE_TRANSPORT,
    'Calendar HTTP transport exceptions must map to the shared transport-error contract.'
);
providerErrorContractExpect(
    CalendarProviderError::classifyMessage('Could not resolve host: calendar.example')
        === CalendarProviderError::TYPE_TRANSPORT,
    'Network failures must remain classifiable when exception type metadata is unavailable.'
);

foreach ([
    CalendarProviderError::TYPE_RATE_LIMITED,
    CalendarProviderError::TYPE_UNAVAILABLE,
    CalendarProviderError::TYPE_TRANSPORT
] as $type) {
    providerErrorContractExpect(
        CalendarProviderError::normalizeType($type) === $type,
        'The provider-neutral error type must survive child-gateway normalization: ' . $type
    );
}

$gatewaySource = providerErrorContractSource(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');
providerErrorContractExpect(
    str_contains($gatewaySource, 'CalendarProviderError::fromThrowable($exception)')
        && str_contains($gatewaySource, "'ErrorType'")
        && str_contains($gatewaySource, "'errorType'  => \$providerError['type']")
        && str_contains($gatewaySource, "'httpStatus' => \$providerError['httpStatus']"),
    'The account gateway must preserve normalized provider error type metadata for child calendars.'
);

$calendarSource = providerErrorContractSource(__DIR__ . '/../Kalender/module.php');
providerErrorContractExpect(
    str_contains($calendarSource, "CalendarProviderError::normalizeType((string) (\$response['ErrorType'] ?? ''))")
        && str_contains($calendarSource, 'throw new CalendarProviderErrorException($error, $errorType);')
        && str_contains($calendarSource, '$errorType === CalendarProviderError::TYPE_CONFLICT')
        && str_contains($calendarSource, '$errorType === CalendarProviderError::TYPE_INVALID_RESPONSE')
        && str_contains($calendarSource, 'CalendarProviderError::messageFor($errorType, $rawMessage)')
        && !str_contains($calendarSource, "str_contains(strtolower(\$rawMessage), 'changed by another client')"),
    'Calendar instances must consume the shared provider error contract instead of classifying provider wording directly.'
);

foreach (
    [
        'GoogleCalendarProvider.php',
        'MicrosoftCalendarProvider.php',
        'CalDAVProvider.php'
    ] as $providerFile
) {
    $providerSource = providerErrorContractSource(__DIR__ . '/../libs/' . $providerFile);
    providerErrorContractExpect(
        str_contains($providerSource, 'public readonly int $httpStatus'),
        $providerFile . ' must expose HTTP status metadata for provider-neutral error classification.'
    );
}

fwrite(STDOUT, "Provider-neutral error contract tests passed.\n");
