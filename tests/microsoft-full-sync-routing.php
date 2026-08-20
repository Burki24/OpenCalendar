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
        '[self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_GOOGLE]'
    ),
    'Only Apple, CalDAV, and Google may use the incremental event-transfer path.'
);
microsoftFullSyncRoutingExpect(
    !str_contains($transferMethod, 'self::PROVIDER_MICROSOFT'),
    'Microsoft 365 must not use the incremental event-transfer path.'
);
microsoftFullSyncRoutingExpect(
    str_contains($transferMethod, '$this->getEventsForChild($request)'),
    'Microsoft 365 must fall back to the regular full provider event transfer.'
);
microsoftFullSyncRoutingExpect(
    str_contains($gatewaySource, 'new MicrosoftCalendarIncrementalSync(')
        && str_contains($gatewaySource, 'getEventByReference('),
    'Microsoft direct post-write event lookup must remain available.'
);

$providerSource = (string) file_get_contents(__DIR__ . '/../libs/MicrosoftCalendarProvider.php');
$getEventsMethod = microsoftFullSyncRoutingMethod(
    $providerSource,
    'public function getEvents(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end): array'
);
microsoftFullSyncRoutingExpect(
    str_contains($getEventsMethod, "'/calendarView?'")
        && !str_contains($getEventsMethod, "'/calendarView/delta?'"),
    'The regular Microsoft event provider must retrieve the authoritative calendarView snapshot.'
);

$calendarSource = (string) file_get_contents(__DIR__ . '/../Kalender/module.php');
$requestEventsMethod = microsoftFullSyncRoutingMethod(
    $calendarSource,
    'private function requestEvents(): array'
);
microsoftFullSyncRoutingExpect(
    str_contains($requestEventsMethod, '$nextSyncToken !== \'\'')
        && str_contains($requestEventsMethod, '$this->clearIncrementalSyncState();'),
    'A full Microsoft transfer without a sync token must clear stale incremental state.'
);

fwrite(STDOUT, "Microsoft full synchronization routing tests passed.\n");
