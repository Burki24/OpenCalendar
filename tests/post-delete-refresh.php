<?php

declare(strict_types=1);

use IPSKalender\CalendarEventDeletion;

require_once __DIR__ . '/../libs/CalendarEventDeletion.php';

function postDeleteRefreshExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function postDeleteRefreshSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Post-delete refresh source could not be read: ' . $path);
    }

    return $source;
}

function postDeleteRefreshMethod(string $source, string $signature): string
{
    $start = strpos($source, $signature);
    if ($start === false) {
        throw new RuntimeException('Post-delete refresh method could not be found: ' . $signature);
    }

    $end = strpos($source, "\n    /**", $start);
    if ($end === false) {
        throw new RuntimeException('Post-delete refresh method boundary could not be found: ' . $signature);
    }

    return substr($source, $start, $end - $start);
}

$singleEvents = [
    [
        'eventReference' => 'single-a',
        'uid'            => 'single-a@example.test',
        'summary'        => 'Delete me'
    ],
    [
        'eventReference' => 'single-b',
        'uid'            => 'single-b@example.test',
        'summary'        => 'Keep me'
    ]
];
$singleFiltered = CalendarEventDeletion::filter(
    $singleEvents,
    $singleEvents[0],
    ['recurrenceType' => 'single', 'writeScope' => '']
);
postDeleteRefreshExpect(
    count($singleFiltered) === 1 && ($singleFiltered[0]['eventReference'] ?? '') === 'single-b',
    'A confirmed single-event deletion must remove exactly the matching cached event.'
);

$seriesEvents = [
    [
        'eventReference'  => 'occurrence-1',
        'uid'             => 'series@example.test',
        'seriesId'        => 'series-1',
        'occurrenceId'    => 'occurrence-1',
        'originalStart'   => '2026-08-20T08:00:00+02:00',
        'start'           => '2026-08-20T08:00:00+02:00',
        'recurrenceType'  => 'occurrence',
        'recurring'       => true
    ],
    [
        'eventReference'  => 'occurrence-2',
        'uid'             => 'series@example.test',
        'seriesId'        => 'series-1',
        'occurrenceId'    => 'occurrence-2',
        'originalStart'   => '2026-08-27T08:00:00+02:00',
        'start'           => '2026-08-27T08:00:00+02:00',
        'recurrenceType'  => 'occurrence',
        'recurring'       => true
    ],
    [
        'eventReference'  => 'occurrence-3',
        'uid'             => 'series@example.test',
        'seriesId'        => 'series-1',
        'occurrenceId'    => 'occurrence-3',
        'originalStart'   => '2026-09-03T08:00:00+02:00',
        'start'           => '2026-09-03T08:00:00+02:00',
        'recurrenceType'  => 'exception',
        'recurring'       => true
    ],
    [
        'eventReference' => 'unrelated',
        'uid'            => 'unrelated@example.test',
        'summary'        => 'Unrelated'
    ]
];

$occurrenceIdentity = $seriesEvents[1];
$occurrenceRecurrence = [
    'recurrenceType' => 'occurrence',
    'seriesId'       => 'series-1',
    'occurrenceId'   => 'occurrence-2',
    'originalStart'  => '2026-08-27T08:00:00+02:00',
    'recurrenceId'   => '',
    'writeScope'     => 'occurrence'
];
$occurrenceFiltered = CalendarEventDeletion::filter($seriesEvents, $occurrenceIdentity, $occurrenceRecurrence);
postDeleteRefreshExpect(
    count($occurrenceFiltered) === 3
        && !in_array('occurrence-2', array_column($occurrenceFiltered, 'eventReference'), true)
        && in_array('occurrence-1', array_column($occurrenceFiltered, 'eventReference'), true)
        && in_array('occurrence-3', array_column($occurrenceFiltered, 'eventReference'), true),
    'Deleting one occurrence must not remove sibling occurrences that share the same series UID.'
);

$seriesRecurrence = $occurrenceRecurrence;
$seriesRecurrence['writeScope'] = 'series';
$seriesFiltered = CalendarEventDeletion::filter($seriesEvents, $occurrenceIdentity, $seriesRecurrence);
postDeleteRefreshExpect(
    count($seriesFiltered) === 1 && ($seriesFiltered[0]['eventReference'] ?? '') === 'unrelated',
    'Deleting a complete series must remove all cached occurrences and exceptions from that series.'
);

$followingRecurrence = $occurrenceRecurrence;
$followingRecurrence['writeScope'] = 'following';
$followingFiltered = CalendarEventDeletion::filter($seriesEvents, $occurrenceIdentity, $followingRecurrence);
postDeleteRefreshExpect(
    count($followingFiltered) === 2
        && in_array('occurrence-1', array_column($followingFiltered, 'eventReference'), true)
        && !in_array('occurrence-2', array_column($followingFiltered, 'eventReference'), true)
        && !in_array('occurrence-3', array_column($followingFiltered, 'eventReference'), true)
        && in_array('unrelated', array_column($followingFiltered, 'eventReference'), true),
    'Deleting this and following occurrences must retain earlier series instances and remove the selected and later instances.'
);

$calendarSource = postDeleteRefreshSource(__DIR__ . '/../Kalender/module.php');
$gatewaySource = postDeleteRefreshSource(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');
$deleteBody = postDeleteRefreshMethod($calendarSource, 'public function DeleteEvent(string $EventJSON): bool');

postDeleteRefreshExpect(
    str_contains($deleteBody, 'CalendarEventDeletion::filter($events, $event, $recurrence)')
        && str_contains($deleteBody, '$this->storeEventsAfterWrite($filteredEvents);')
        && str_contains($deleteBody, "'EventDeleteCacheUpdated'")
        && !str_contains($deleteBody, '$this->refreshAfterWrite();')
        && !str_contains($deleteBody, 'clearIncrementalSyncState()')
        && !str_contains($deleteBody, 'removeSingleEventFromCache'),
    'Confirmed deletions must update the local cache immediately without provider re-read or incremental-state reset.'
);

postDeleteRefreshExpect(
    str_contains($gatewaySource, "'DeleteEvent'            => ['success' => \$this->deleteEventForChild(\$request)]")
        && str_contains($gatewaySource, 'return $this->createProvider()->deleteEvent(')
        && !str_contains(
            postDeleteRefreshMethod($gatewaySource, 'private function deleteEventForChild(array $request): bool'),
            'PROVIDER_'
        ),
    'The account gateway must route deletion through the common CalendarProviderInterface without provider-specific branches.'
);

fwrite(STDOUT, "Provider-neutral post-delete refresh tests passed.\n");
