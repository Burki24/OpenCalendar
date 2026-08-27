<?php

declare(strict_types=1);

use IPSKalender\CalendarProviderError;

require_once __DIR__ . '/../libs/CalendarProviderError.php';

function writeParityExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function writeParitySource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Write parity source could not be read: ' . $path);
    }

    return $source;
}

function writeParityMethod(string $source, string $signature): string
{
    $start = strpos($source, $signature);
    if ($start === false) {
        throw new RuntimeException('Write parity method could not be found: ' . $signature);
    }

    $end = strpos($source, "\n    /**", $start);
    if ($end === false) {
        throw new RuntimeException('Write parity method boundary could not be found: ' . $signature);
    }

    return substr($source, $start, $end - $start);
}

/**
 * @param list<string> $markers
 */
function writeParityMarkers(string $provider, string $source, array $markers): void
{
    foreach ($markers as $marker) {
        writeParityExpect(
            str_contains($source, $marker),
            sprintf('%s write parity marker is missing: %s', $provider, $marker)
        );
    }
}

/**
 * @param list<string> $markers
 */
function writeParityRejectMarkers(string $context, string $source, array $markers): void
{
    foreach ($markers as $marker) {
        writeParityExpect(
            !str_contains($source, $marker),
            sprintf('%s must remain provider-neutral and not contain: %s', $context, $marker)
        );
    }
}

$providers = [
    'Google'    => [
        'file'             => 'GoogleCalendarProvider.php',
        'class'            => 'GoogleCalendarProvider',
        'exception'        => 'GoogleCalendarProviderException',
        'createMarkers'    => ["'POST'", 'buildEventPayload($event, true)'],
        'updateMarkers'    => ["'PATCH'", "'If-Match' => \$etag"],
        'deleteMarkers'    => ["'If-Match' => \$etag"],
        'sourceMarkers'    => []
    ],
    'Microsoft' => [
        'file'             => 'MicrosoftCalendarProvider.php',
        'class'            => 'MicrosoftCalendarProvider',
        'exception'        => 'MicrosoftCalendarProviderException',
        'createMarkers'    => ["'POST'", 'buildEventPayload($event, true)'],
        'updateMarkers'    => ["'PATCH'", "'If-Match' => \$etag"],
        'deleteMarkers'    => ["'DELETE'", "'If-Match' => \$etag"],
        'sourceMarkers'    => []
    ],
    'CalDAV'    => [
        'file'             => 'CalDAVProvider.php',
        'class'            => 'CalDAVProvider',
        'exception'        => 'CalDAVProviderException',
        'createMarkers'    => ["'PUT'", "'If-None-Match' => '*'"],
        'updateMarkers'    => ["'PUT'", "\$headers['If-Match'] = \$currentEtag"],
        'deleteMarkers'    => ['$this->deleteResource(', '$this->putRecurringResource('],
        'sourceMarkers'    => ["\$headers['If-Match'] = \$etag"]
    ]
];

$writeCapabilities = [
    'create',
    'update',
    'delete',
    'createRecurrence',
    'updateRecurrence',
    'updateOccurrence',
    'deleteOccurrence',
    'updateFollowing',
    'updateSeries',
    'deleteSeries'
];

$createSignature = '/public function createEvent\(\s*string\s+\$[A-Za-z_][A-Za-z0-9_]*,\s*array\s+\$event\s*\): array/s';
$updateSignature = '/public function updateEvent\(\s*string\s+\$[A-Za-z_][A-Za-z0-9_]*,\s*string\s+\$[A-Za-z_][A-Za-z0-9_]*,\s*string\s+\$etag,\s*string\s+\$uid,\s*array\s+\$event,\s*array\s+\$recurrence\s*=\s*\[\]\s*\): array/s';
$deleteSignature = '/public function deleteEvent\(\s*string\s+\$[A-Za-z_][A-Za-z0-9_]*,\s*string\s+\$[A-Za-z_][A-Za-z0-9_]*,\s*string\s+\$etag,\s*string\s+\$recurrenceId\s*=\s*\'\',\s*array\s+\$recurrence\s*=\s*\[\]\s*\): bool/s';

foreach ($providers as $providerName => $contract) {
    $source = writeParitySource(__DIR__ . '/../libs/' . $contract['file']);
    $classDeclaration = 'final class ' . $contract['class'] . ' implements ';
    $classStart = strpos($source, $classDeclaration);
    writeParityExpect($classStart !== false, $providerName . ' provider declaration is missing.');
    $classLineEnd = $classStart === false ? false : strpos($source, "\n", $classStart);
    $classLine = $classLineEnd === false
        ? ''
        : substr($source, $classStart, $classLineEnd - $classStart);

    foreach (
        [
            'CalendarProviderInterface',
            'CalendarEventLookupProviderInterface',
            'RecurringCalendarProviderInterface'
        ] as $interface
    ) {
        writeParityExpect(
            str_contains($classLine, $interface),
            sprintf('%s must implement %s.', $providerName, $interface)
        );
    }

    writeParityExpect(
        preg_match($createSignature, $source) === 1
            && preg_match($updateSignature, $source) === 1
            && preg_match($deleteSignature, $source) === 1,
        $providerName . ' must keep the shared create/update/delete method contract.'
    );

    foreach ($writeCapabilities as $capability) {
        writeParityExpect(
            preg_match('/\'' . preg_quote($capability, '/') . '\'\s*=>\s*\$canWrite/', $source) === 1,
            sprintf('%s must expose %s through the common writable capability contract.', $providerName, $capability)
        );
    }

    $createBody = writeParityMethod($source, 'public function createEvent(');
    $updateBody = writeParityMethod($source, 'public function updateEvent(');
    $deleteBody = writeParityMethod($source, 'public function deleteEvent(');

    writeParityMarkers($providerName . ' create', $createBody, $contract['createMarkers']);
    writeParityMarkers($providerName . ' update', $updateBody, $contract['updateMarkers']);
    writeParityMarkers($providerName . ' delete', $deleteBody, $contract['deleteMarkers']);
    writeParityMarkers($providerName, $source, $contract['sourceMarkers']);

    foreach ([$updateBody, $deleteBody] as $writeBody) {
        writeParityExpect(
            str_contains($writeBody, 'CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING')
                && str_contains($writeBody, 'CalendarEventRecurrence::WRITE_SCOPE_SERIES'),
            $providerName . ' must support the common following/series write scopes for update and delete.'
        );
    }

    writeParityExpect(
        preg_match(
            '/final class\s+' . preg_quote((string) $contract['exception'], '/')
                . '\s+extends RuntimeException[\s\S]*?public readonly int \$httpStatus/',
            $source
        ) === 1,
        $providerName . ' must expose HTTP status metadata for the shared provider error contract.'
    );
}

final class WriteParityHttpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }
}

foreach (
    [
        412 => CalendarProviderError::TYPE_CONFLICT,
        404 => CalendarProviderError::TYPE_NOT_FOUND,
        401 => CalendarProviderError::TYPE_AUTHENTICATION,
        403 => CalendarProviderError::TYPE_ACCESS_DENIED
    ] as $status => $expectedType
) {
    $error = CalendarProviderError::fromThrowable(new WriteParityHttpException('Provider write failure.', $status));
    writeParityExpect(
        $error['type'] === $expectedType && $error['httpStatus'] === $status,
        sprintf('HTTP %d must keep the shared provider write error type %s.', $status, $expectedType)
    );
}

$calendarSource = writeParitySource(__DIR__ . '/../Kalender/module.php');
$gatewaySource = writeParitySource(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');

$calendarCreate = writeParityMethod($calendarSource, 'public function CreateEvent(string $EventJSON): string');
$calendarUpdate = writeParityMethod($calendarSource, 'public function UpdateEvent(string $EventJSON): string');
$calendarDelete = writeParityMethod($calendarSource, 'public function DeleteEvent(string $EventJSON): bool');
$postWriteRefresh = writeParityMethod(
    $calendarSource,
    'private function refreshEventAfterWrite(array $event, array $sourceEvent = []): bool'
);

writeParityExpect(
    str_contains($calendarCreate, '$this->refreshEventAfterWrite(array_merge($event, $created))')
        && str_contains($calendarUpdate, '$this->refreshEventAfterWrite($writtenEvent, $event)')
        && str_contains($postWriteRefresh, "\$this->sendRequest('GetEventAfterWrite', \$lookupIdentity)")
        && str_contains($postWriteRefresh, "\$this->sendRequest('GetEventForEdit', \$lookupIdentity)"),
    'Create and update must share one provider-neutral write-readback pipeline.'
);

writeParityExpect(
    str_contains($calendarDelete, 'CalendarEventDeletion::filter($events, $event, $recurrence)')
        && str_contains($calendarDelete, '$this->storeEventsAfterWrite($filteredEvents);')
        && !str_contains($calendarDelete, '$this->refreshAfterWrite();'),
    'Delete must share the provider-neutral local cache refresh without immediate provider re-read.'
);

writeParityRejectMarkers(
    'Calendar write pipeline',
    $calendarCreate . $calendarUpdate . $calendarDelete . $postWriteRefresh,
    ['PROVIDER_', 'GoogleCalendar', 'MicrosoftCalendar', 'CalDAV']
);

$gatewayCreate = writeParityMethod($gatewaySource, 'private function createEventForChild(array $request): array');
$gatewayUpdate = writeParityMethod($gatewaySource, 'private function updateEventForChild(array $request): array');
$gatewayDelete = writeParityMethod($gatewaySource, 'private function deleteEventForChild(array $request): bool');

writeParityExpect(
    str_contains($gatewayCreate, '$this->createProvider()->createEvent(')
        && str_contains($gatewayUpdate, '$this->createProvider()->updateEvent(')
        && str_contains($gatewayDelete, '$this->createProvider()->deleteEvent('),
    'The account gateway must route create, update and delete through the common provider interface.'
);
writeParityRejectMarkers(
    'Account write gateway',
    $gatewayCreate . $gatewayUpdate . $gatewayDelete,
    ['PROVIDER_', 'GoogleCalendar', 'MicrosoftCalendar', 'CalDAV']
);

writeParityExpect(
    str_contains($gatewaySource, 'CalendarProviderError::fromThrowable($exception)')
        && str_contains($gatewaySource, "'ErrorType'")
        && str_contains($calendarSource, 'throw new CalendarProviderErrorException($error, $errorType);')
        && str_contains($calendarSource, '$errorType === CalendarProviderError::TYPE_CONFLICT')
        && str_contains($calendarSource, '$errorType === CalendarProviderError::TYPE_INVALID_RESPONSE'),
    'All write operations must expose and consume the same normalized provider error contract.'
);

fwrite(STDOUT, "Provider write parity matrix tests passed.\n");
