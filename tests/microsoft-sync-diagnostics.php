<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\MicrosoftCalendarDebugHttpClient;

require_once __DIR__ . '/../libs/MicrosoftCalendarDebugHttpClient.php';

function microsoftSyncDiagnosticsExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class MicrosoftSyncDiagnosticsHttpClient implements CalendarHttpClientInterface
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $maxResponseBytes = 67_108_864
    ): CalendarHttpResponse {
        return new CalendarHttpResponse(
            200,
            ['content-type' => 'application/json'],
            json_encode(
                [
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/next-page',
                    'value'           => [
                        [
                            'id'          => 'single-event-id',
                            'subject'     => 'Single event',
                            'type'        => 'singleInstance',
                            'isCancelled' => false,
                            'start'       => [
                                'dateTime' => '2026-09-01T10:00:00.0000000',
                                'timeZone' => 'UTC'
                            ]
                        ],
                        [
                            'id'             => 'occurrence-event-id',
                            'seriesMasterId' => 'series-master-id',
                            'subject'        => 'Serie Test 6',
                            'type'           => 'occurrence',
                            'isCancelled'    => false,
                            'start'          => [
                                'dateTime' => '2026-09-07T09:00:00.0000000',
                                'timeZone' => 'UTC'
                            ]
                        ],
                        [
                            'id'             => 'exception-event-id',
                            'seriesMasterId' => 'series-master-id',
                            'subject'        => 'Serie Test 6 changed',
                            'type'           => 'exception',
                            'isCancelled'    => false,
                            'start'          => [
                                'dateTime' => '2026-09-08T09:00:00.0000000',
                                'timeZone' => 'UTC'
                            ]
                        ],
                        [
                            'id'          => 'cancelled-event-id',
                            'subject'     => 'Cancelled',
                            'type'        => 'singleInstance',
                            'isCancelled' => true,
                            'start'       => [
                                'dateTime' => '2026-09-09T09:00:00.0000000',
                                'timeZone' => 'UTC'
                            ]
                        ]
                    ]
                ],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            $url
        );
    }
}

$client = new MicrosoftCalendarDebugHttpClient(new MicrosoftSyncDiagnosticsHttpClient());
$client->request(
    'GET',
    'https://graph.microsoft.com/v1.0/me/calendars/calendar-id/calendarView?startDateTime=2026-08-20T00:00:00Z',
    ['Authorization' => 'Bearer secret-token']
);
$diagnostics = $client->diagnostics();

microsoftSyncDiagnosticsExpect($diagnostics['requestCount'] === 1, 'One calendarView request must be recorded.');
microsoftSyncDiagnosticsExpect($diagnostics['rawItemCount'] === 4, 'All raw Graph items must be counted.');
microsoftSyncDiagnosticsExpect($diagnostics['eligibleItemCount'] === 3, 'Non-cancelled items with identity and start must be eligible.');
microsoftSyncDiagnosticsExpect($diagnostics['cancelledCount'] === 1, 'Cancelled Graph items must be counted.');
microsoftSyncDiagnosticsExpect(($diagnostics['typeCounts']['occurrence'] ?? 0) === 1, 'Occurrences must be identified.');
microsoftSyncDiagnosticsExpect(($diagnostics['typeCounts']['exception'] ?? 0) === 1, 'Exceptions must be identified.');
microsoftSyncDiagnosticsExpect(count($diagnostics['recurringSamples']) === 2, 'Recurring samples must include occurrence and exception.');
microsoftSyncDiagnosticsExpect(
    ($diagnostics['recurringSamples'][0]['summary'] ?? '') === 'Serie Test 6',
    'The recurring sample summary must identify the test series.'
);
$encoded = json_encode($diagnostics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
microsoftSyncDiagnosticsExpect(!str_contains($encoded, 'secret-token'), 'Authorization data must never enter diagnostics.');
microsoftSyncDiagnosticsExpect(!str_contains($encoded, 'occurrence-event-id'), 'Complete provider IDs must never enter diagnostics.');
microsoftSyncDiagnosticsExpect(!str_contains($encoded, 'series-master-id'), 'Complete series IDs must never enter diagnostics.');

$gatewaySource = (string) file_get_contents(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');
microsoftSyncDiagnosticsExpect(
    str_contains($gatewaySource, 'new MicrosoftCalendarDebugHttpClient(')
        && str_contains($gatewaySource, "SendSafeDebug('MicrosoftGraphCalendarView'")
        && str_contains($gatewaySource, "SendSafeDebug('MicrosoftMappedEvents'"),
    'The Microsoft full synchronization path must emit raw and mapped diagnostics.'
);
microsoftSyncDiagnosticsExpect(
    str_contains($gatewaySource, "\$debugName = 'MicrosoftEventSynchronization';")
        && str_contains($gatewaySource, '$microsoftDebugClient = new MicrosoftCalendarDebugHttpClient(')
        && str_contains($gatewaySource, "'requestedIncremental' => \$syncToken !== ''")
        && str_contains($gatewaySource, "'fallbackToFull'       => \$syncToken !== '' && !\$result['incremental']")
        && str_contains($gatewaySource, "'deletedCount'         => \$deletedCount")
        && str_contains($gatewaySource, "'recurringCount'       => \$recurringCount")
        && str_contains($gatewaySource, "'syncTokenAdvanced'    => \$syncTokenAdvanced")
        && str_contains($gatewaySource, '$graphDiagnostics = $microsoftDebugClient->diagnostics();')
        && str_contains($gatewaySource, '$mappedItems = array_values(array_filter('),
    'Microsoft incremental synchronization must keep compact transfer diagnostics.'
);

fwrite(STDOUT, "Microsoft synchronization diagnostics tests passed.\n");
