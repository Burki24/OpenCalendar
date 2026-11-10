<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender/module.php';

final class DebugCalendarProbe extends Calendar
{
    public array $debug = [];
    public bool $brokenDebug = false;
    public bool $failRead = false;
    public int $cacheReads = 0;
    public function Legacy(): void
    {
        parent::SendDebug('LegacyHelper', 'synthetic-private-calendar-token', 0);
    }
    protected function ReadAttributeString(string $Name): string
    {
        if ($Name === 'CachedEvents') {
            ++$this->cacheReads;
            if ($this->failRead) {
                throw new RuntimeException('synthetic-private-calendar-token');
            }
            return '[{"summary":"synthetic-private-calendar-token","start":"2026-10-18","end":"2026-10-19"}]';
        }
        return '[]';
    }
    protected function SendDebug(string $Message, string $Data, int $Format): bool
    {
        if (!str_starts_with($Message, static::class . ' | ')) {
            return parent::SendDebug($Message, $Data, $Format);
        }
        if ($this->brokenDebug) {
            throw new RuntimeException('synthetic-private-calendar-token');
        }
        $this->debug[] = ['Message' => $Message, 'Data' => $Data];
        return true;
    }
}

function checkCalendarDebug(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$calendar = new DebugCalendarProbe(12345);
$events = json_decode($calendar->GetEvents(), true, 512, JSON_THROW_ON_ERROR);
checkCalendarDebug($events[0]['summary'] === 'synthetic-private-calendar-token', 'Export must retain real calendar content.');
checkCalendarDebug($calendar->cacheReads === 1, 'Debug must not trigger an additional cache read.');
$calendar->Legacy();
$calendar->failRead = true;
try {
    $calendar->GetEvents();
    throw new LogicException('Read error must retain the exception contract.');
} catch (RuntimeException $exception) {
    checkCalendarDebug($exception->getMessage() === 'synthetic-private-calendar-token', 'Original exception must be retained.');
}
$balance = [];
$operations = [];
foreach ($calendar->debug as $row) {
    checkCalendarDebug(!str_contains($row['Data'], 'synthetic-private-calendar-token'), 'Export, legacy and exception content must stay out of debug.');
    checkCalendarDebug(preg_match('/ \| (START|ENDE|FEHLER) \| [A-Za-z_]+:\d+$/', $row['Message']) === 1, 'Every diagnostic needs event, function and line.');
    $data = json_decode($row['Data'], true, 512, JSON_THROW_ON_ERROR);
    $key = $data['Vorgang'] . ':' . $data['Aufruf'];
    $operations[$data['Vorgang']] = true;
    $balance[$key] = ($balance[$key] ?? 0) + (str_contains($row['Message'], ' | START | ') ? 1 : (str_contains($row['Message'], ' | ENDE | ') ? -1 : 0));
}
checkCalendarDebug(count($operations) === 3 && array_sum(array_map('abs', $balance)) === 0, 'Separate success, legacy and exception flows need independent identifiers and balanced starts/ends.');
$calendar->failRead = false;
$calendar->brokenDebug = true;
checkCalendarDebug(json_decode($calendar->GetEvents(), true)[0]['summary'] === 'synthetic-private-calendar-token', 'Broken logging must not suppress exports.');
checkCalendarDebug($calendar->cacheReads === 3, 'Exception and broken-channel paths must not repeat reads.');
echo "OpenCalendar debug flow, private exports, exceptions and legacy protection passed.\n";
