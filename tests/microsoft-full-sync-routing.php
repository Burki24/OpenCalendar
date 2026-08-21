<?php

declare(strict_types=1);

function microsoftFullSyncRoutingExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function microsoftFullSyncRoutingMethod(string $source, string $signature): string
{
    $start = strpos($source, $signature);
    if ($start === false) {
        throw new RuntimeException('The inspected method could not be found: ' . $signature);
    }

    $end = strpos($source, "\n    /**", $start + strlen($signature));
    if ($end === false) {
        $end = strlen($source);
    }

    return substr($source, $start, $end - $start);
}

$gatewaySource = (string) file_get_contents(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');
$transferMethod = microsoftFullSyncRoutingMethod(
    $gatewaySource,
    'private function beginEventsTransferForChild(array $request): array'
);

microsoftFullSyncRoutingExpect(
    str_contains(
        $transferMethod,
        '[self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_GOOGLE, self::PROVIDER_MICROSOFT]'
    ),
    'Apple, CalDAV, Google, and Microsoft must use the incremental event-transfer path.'
);
microsoftFullSyncRoutingExpect(
    str_contains($transferMethod, '$providerType === self::PROVIDER_MICROSOFT')
        && str_contains($transferMethod, '$microsoftDebugClient = new MicrosoftCalendarDebugHttpClient(')
        && str_contains($transferMethod, 'new MicrosoftCalendarIncrementalSync(')
        && str_contains($transferMethod, '$microsoftDebugClient,')
        && str_contains($transferMethod, "\$debugName = 'MicrosoftEventSynchronization';"),
    'Microsoft 365 must route event transfers through the Microsoft incremental synchronizer.'
);
microsoftFullSyncRoutingExpect(
    str_contains($transferMethod, "'requestedIncremental' => \$syncToken !== ''")
        && str_contains($transferMethod, "'fallbackToFull'       => \$syncToken !== '' && !\$result['incremental']")
        && str_contains($transferMethod, "'deletedCount'         => \$deletedCount")
        && str_contains($transferMethod, "'recurringCount'       => \$recurringCount")
        && str_contains($transferMethod, "'syncTokenAdvanced'    => \$syncTokenAdvanced"),
    'Incremental transfer diagnostics must report request mode, fallback, changes, and token progress.'
);
microsoftFullSyncRoutingExpect(
    str_contains($gatewaySource, 'getEventByReference('),
    'Microsoft direct post-write event lookup must remain available.'
);

$incrementalSource = (string) file_get_contents(__DIR__ . '/../libs/MicrosoftCalendarIncrementalSync.php');
$fullSyncMethod = microsoftFullSyncRoutingMethod(
    $incrementalSource,
    'private function fullSync('
);
microsoftFullSyncRoutingExpect(
    str_contains($fullSyncMethod, "'/calendarView/delta?'")
        && str_contains($fullSyncMethod, 'new MicrosoftCalendarProvider(')
        && str_contains($fullSyncMethod, '$provider->getEvents($calendarReference, $start, $end)'),
    'The initial Microsoft sync must establish a delta token while keeping regular calendarView authoritative.'
);

$providerSource = (string) file_get_contents(__DIR__ . '/../libs/MicrosoftCalendarProvider.php');
$getEventsMethod = microsoftFullSyncRoutingMethod(
    $providerSource,
    'public function getEvents(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end): array'
);
microsoftFullSyncRoutingExpect(
    str_contains($getEventsMethod, "'/calendarView?'")
        && !str_contains($getEventsMethod, "'/calendarView/delta?'"),
    'The authoritative Microsoft full snapshot must continue to use regular calendarView.'
);

$calendarSource = (string) file_get_contents(__DIR__ . '/../Kalender/module.php');
$requestEventsMethod = microsoftFullSyncRoutingMethod(
    $calendarSource,
    'private function requestEvents(): array'
);
microsoftFullSyncRoutingExpect(
    str_contains($requestEventsMethod, '$nextSyncToken !== \'\'')
        && str_contains($requestEventsMethod, '$this->storeIncrementalSyncState(')
        && str_contains($requestEventsMethod, '$this->mergeIncrementalEvents($cachedEvents, $transferredEvents)'),
    'The calendar child must persist Microsoft delta state and merge incremental event changes.'
);

fwrite(STDOUT, "Microsoft incremental synchronization routing tests passed.\n");
