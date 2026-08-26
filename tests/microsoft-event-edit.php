<?php

declare(strict_types=1);

function assertMicrosoftEventEdit(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$calendarSource = (string) file_get_contents(__DIR__ . '/../Kalender/module.php');
$visualizationSource = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/app.js');
$gatewaySource = (string) file_get_contents(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');
$providerSource = (string) file_get_contents(__DIR__ . '/../libs/MicrosoftCalendarProvider.php');

assertMicrosoftEventEdit(
    str_contains($calendarSource, "'SeriesID'       => trim((string) (\$event['seriesId'] ?? ''))"),
    'Calendar event-edit requests must forward the recurring series identity.'
);

assertMicrosoftEventEdit(
    str_contains($visualizationSource, 'seriesId,')
        && str_contains($visualizationSource, 'originalStart,')
        && str_contains($visualizationSource, 'function pendingEventEditMatches(eventEdit)')
        && str_contains($visualizationSource, 'function recurringOriginalStartMatches(left, right, allDay = false)')
        && str_contains($visualizationSource, "String(eventEdit?.seriesId || '') === pendingEventEdit.seriesId")
        && str_contains($visualizationSource, "openExistingEvent(eventEdit, 'occurrence');"),
    'The visualization must reopen a Microsoft recurring occurrence even when Graph returns refreshed event IDs.'
);

assertMicrosoftEventEdit(
    str_contains($gatewaySource, 'CalendarEventLookupProviderInterface')
        && str_contains($gatewaySource, 'eventLookupIdentityForChild($request)')
        && !str_contains($gatewaySource, 'microsoftEventMatchesEditRequest(')
        && !str_contains($gatewaySource, 'microsoftOriginalStartMatches('),
    'Microsoft-specific event identity matching must be owned by the provider, not the account gateway.'
);

assertMicrosoftEventEdit(
    str_contains(
        $providerSource,
        'final class MicrosoftCalendarProvider implements CalendarEventLookupProviderInterface, CalendarProviderInterface, RecurringCalendarProviderInterface'
    )
        && str_contains(
            $providerSource,
            'public function getEventForEdit(string $calendarReference, array $identity): array'
        )
        && str_contains(
            $providerSource,
            'private function eventMatchesLookupIdentity(array $event, array $identity): bool'
        )
        && str_contains($providerSource, '$this->eventMatchesLookupIdentity($event, $identity)')
        && str_contains($providerSource, '$this->eventLookupRange($identity)'),
    'Microsoft event-edit lookup must use the provider-neutral direct lookup and provider-owned stale-ID fallback.'
);

fwrite(STDOUT, "Microsoft event-edit identity tests passed.\n");
