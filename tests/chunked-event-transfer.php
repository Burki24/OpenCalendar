<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/helper/ChunkedJsonTransferHelper.php';

use Burki24\SymconModuleHelper\ChunkedJsonTransferHelper;

function assertChunkedTransfer(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class OpenCalendarChunkedTransferHarness
{
    use ChunkedJsonTransferHelper;

    /** @var array<string,string> */
    private array $buffers = [];

    /**
     * @param list<mixed> $items
     * @return array{Token:string,PageCount:int,ItemCount:int,ExpiresAt:int}
     */
    public function create(array $items): array
    {
        return $this->CreateChunkedJsonTransfer('OpenCalendarEvents', $items);
    }

    /** @return array{Token:string,Page:int,PageCount:int,ItemCount:int,Complete:bool,Items:list<mixed>} */
    public function page(string $token, int $page): array
    {
        return $this->ReadChunkedJsonTransferPage('OpenCalendarEvents', $token, $page);
    }

    public function clear(string $token): bool
    {
        return $this->ClearChunkedJsonTransfer('OpenCalendarEvents', $token);
    }

    /** @return array<string,string> */
    public function buffers(): array
    {
        return $this->buffers;
    }

    protected function GetBuffer(string $name): string
    {
        return $this->buffers[$name] ?? '';
    }

    protected function SetBuffer(string $name, string $value): void
    {
        $this->buffers[$name] = $value;
    }
}

$events = [];
for ($index = 0; $index < 400; ++$index) {
    $events[] = [
        'id'             => 'event-' . $index,
        'summary'        => 'Large transfer event ' . $index,
        'description'    => str_repeat(chr(65 + ($index % 26)), 4096),
        'startTimestamp' => 1_788_000_000 + ($index * 3600),
        'endTimestamp'   => 1_788_003_600 + ($index * 3600),
        'allDay'         => false
    ];
}
assertChunkedTransfer(
    strlen(json_encode($events, JSON_THROW_ON_ERROR)) > 1_048_576,
    'The transfer fixture must exceed Symcon\'s 1 MiB output limit.'
);

$helper = new OpenCalendarChunkedTransferHarness();
$metadata = $helper->create($events);
assertChunkedTransfer($metadata['PageCount'] > 1, 'Large event data must be split into multiple pages.');
assertChunkedTransfer($metadata['ItemCount'] === count($events), 'Transfer metadata must retain the event count.');

$received = [];
for ($page = 0; $page < $metadata['PageCount']; ++$page) {
    $payload = $helper->page($metadata['Token'], $page);
    assertChunkedTransfer(
        $payload['Complete'] === ($page === $metadata['PageCount'] - 1),
        'Only the final event page may be marked complete.'
    );
    array_push($received, ...$payload['Items']);
}
assertChunkedTransfer($received === $events, 'Chunked event pages must reconstruct the original event list.');

foreach ($helper->buffers() as $name => $value) {
    if (str_contains($name, ':Page:') && $value !== '') {
        assertChunkedTransfer(strlen($value) <= 192 * 1024, 'An event page exceeds the default 192 KiB limit.');
    }
}
assertChunkedTransfer($helper->clear($metadata['Token']), 'The completed event transfer must be removable.');

$accountSource = (string) file_get_contents(__DIR__ . '/../Kalender Konto/module.php');
$gatewaySource = (string) file_get_contents(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');
$calendarSource = (string) file_get_contents(__DIR__ . '/../Kalender/module.php');
$viewSource = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/module.php');

assertChunkedTransfer(
    str_contains($accountSource, 'use ChunkedJsonTransferHelper;')
        && str_contains($accountSource, "require_once __DIR__ . '/../libs/helper/ChunkedJsonTransferHelper.php';")
        && str_contains($gatewaySource, "'BeginEventsTransfer'")
        && str_contains($gatewaySource, "'ReadEventsTransferPage'")
        && str_contains($gatewaySource, "'FinishEventsTransfer'"),
    'The calendar account must expose the complete chunked event transfer protocol.'
);
assertChunkedTransfer(
    str_contains($calendarSource, 'use ChunkedJsonTransferHelper;')
        && str_contains($calendarSource, "'BeginEventsTransfer'")
        && str_contains($calendarSource, "'ReadEventsTransferPage'")
        && str_contains($calendarSource, "'FinishEventsTransfer'")
        && str_contains($calendarSource, 'public function BeginEventsTransfer(')
        && str_contains($calendarSource, 'public function ReadEventsTransferPage(')
        && str_contains($calendarSource, 'public function FinishEventsTransfer('),
    'The calendar module must consume account pages and expose cached event pages.'
);
assertChunkedTransfer(
    !str_contains($viewSource, 'IPSKAL_GetEvents(')
        && str_contains($viewSource, 'IPSKAL_BeginEventsTransfer(')
        && str_contains($viewSource, 'IPSKAL_ReadEventsTransferPage(')
        && str_contains($viewSource, 'IPSKAL_FinishEventsTransfer('),
    'The calendar view must consume event pages instead of the unbounded GetEvents response.'
);

fwrite(STDOUT, "Chunked OpenCalendar event transfer tests passed.\n");
