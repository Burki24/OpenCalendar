<?php

declare(strict_types=1);

function postWriteRefreshExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function postWriteRefreshSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Post-write refresh source could not be read: ' . $path);
    }

    return $source;
}

function postWriteRefreshMethod(string $source, string $signature): string
{
    $start = strpos($source, $signature);
    if ($start === false) {
        throw new RuntimeException('Post-write refresh method could not be found: ' . $signature);
    }

    $end = strpos($source, "\n    /**", $start);
    if ($end === false) {
        throw new RuntimeException('Post-write refresh method boundary could not be found: ' . $signature);
    }

    return substr($source, $start, $end - $start);
}

$calendarSource = postWriteRefreshSource(__DIR__ . '/../Kalender/module.php');
$gatewaySource = postWriteRefreshSource(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');
$viewSource = postWriteRefreshSource(__DIR__ . '/../Kalender Ansicht/module.php');

$createBody = postWriteRefreshMethod($calendarSource, 'public function CreateEvent(string $EventJSON): string');
$updateBody = postWriteRefreshMethod($calendarSource, 'public function UpdateEvent(string $EventJSON): string');
$refreshBody = postWriteRefreshMethod(
    $calendarSource,
    'private function refreshEventAfterWrite(array $event, array $sourceEvent = []): bool'
);
$fallbackBody = postWriteRefreshMethod($calendarSource, 'private function refreshAfterWrite(): void');

postWriteRefreshExpect(
    str_contains($createBody, '$this->refreshEventAfterWrite(array_merge($event, $created))')
        && str_contains($createBody, '$this->refreshAfterWrite();')
        && !str_contains($createBody, '$simpleSingleWrite'),
    'CreateEvent must use the provider-neutral post-write readback for single, recurring, and annual events.'
);

postWriteRefreshExpect(
    str_contains($updateBody, '$writtenEvent = array_merge($cachedEvent ?? $event, $changes, $updated);')
        && str_contains($updateBody, '$this->refreshEventAfterWrite($writtenEvent, $event)')
        && str_contains($updateBody, '$this->refreshAfterWrite();')
        && !str_contains($updateBody, '$simpleSingleWrite'),
    'UpdateEvent must use the same provider-neutral post-write readback for every write scope.'
);

postWriteRefreshExpect(
    str_contains($refreshBody, "'SeriesID'")
        && str_contains($refreshBody, "'OccurrenceID'")
        && str_contains($refreshBody, '$hasLookupIdentity')
        && str_contains($refreshBody, "\$this->sendRequest('GetEventAfterWrite', \$lookupIdentity)")
        && str_contains($refreshBody, "\$this->sendRequest('GetEventForEdit', \$lookupIdentity)")
        && str_contains($refreshBody, "\$currentEvent['recurring']")
        && str_contains($refreshBody, 'CalendarEventRecurrence::SINGLE')
        && str_contains($refreshBody, '$this->storeEventsAfterWrite($events);'),
    'Post-write readback must use the complete provider-neutral identity and update single-event cache entries directly.'
);

postWriteRefreshExpect(
    !str_contains($fallbackBody, 'clearIncrementalSyncState()')
        && str_contains($fallbackBody, '$events = $this->requestEvents();')
        && str_contains($fallbackBody, '$this->storeEvents($events);'),
    'Recurring post-write fallback must refresh the configured range without resetting incremental synchronization state.'
);

postWriteRefreshExpect(
    str_contains($gatewaySource, "'GetEventAfterWrite'")
        && str_contains($gatewaySource, 'private function getEventAfterWriteForChild(array $request): array')
        && str_contains($gatewaySource, 'CalendarEventLookupProviderInterface')
        && str_contains($gatewaySource, 'eventLookupIdentityForChild($request)'),
    'The account gateway must route post-write readback through the provider-neutral lookup capability.'
);

$moveStart = strpos($viewSource, "case 'MoveEvent':");
$moveEnd = $moveStart === false ? false : strpos($viewSource, "case 'DeleteEvent':", $moveStart);
postWriteRefreshExpect(
    $moveStart !== false
        && $moveEnd !== false
        && str_contains(substr($viewSource, $moveStart, $moveEnd - $moveStart), 'IPSKAL_CreateEvent(')
        && str_contains(substr($viewSource, $moveStart, $moveEnd - $moveStart), '$targetInstanceId')
        && str_contains(substr($viewSource, $moveStart, $moveEnd - $moveStart), 'IPSKAL_DeleteEvent('),
    'MoveEvent must create the target through Calendar::CreateEvent so the target uses the unified post-write readback.'
);

postWriteRefreshExpect(
    str_contains($viewSource, '$state = $this->buildStateForActionValue($value);')
        && str_contains($viewSource, '$this->broadcastState($state);'),
    'Visualization actions must rebuild and broadcast state after successful writes.'
);

fwrite(STDOUT, "Provider-neutral post-write refresh tests passed.\n");
