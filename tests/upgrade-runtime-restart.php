<?php

declare(strict_types=1);

function assertUpgradeRuntime(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function upgradeRuntimeSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Upgrade runtime source could not be read: ' . $path);
    }

    return $source;
}

function upgradeRuntimeMethod(string $source, string $signature): string
{
    $offset = strpos($source, $signature);
    if ($offset === false) {
        throw new RuntimeException('Method signature not found: ' . $signature);
    }

    $brace = strpos($source, '{', $offset);
    if ($brace === false) {
        throw new RuntimeException('Method body not found: ' . $signature);
    }

    $depth = 0;
    $length = strlen($source);
    for ($index = $brace; $index < $length; ++$index) {
        if ($source[$index] === '{') {
            ++$depth;
        } elseif ($source[$index] === '}') {
            --$depth;
            if ($depth === 0) {
                return substr($source, $offset, $index - $offset + 1);
            }
        }
    }

    throw new RuntimeException('Unterminated method body: ' . $signature);
}

/**
 * @param list<string> $needles
 */
function assertUpgradeRuntimeContains(string $source, array $needles, string $scope): void
{
    foreach ($needles as $needle) {
        assertUpgradeRuntime(
            str_contains($source, $needle),
            $scope . ' must retain: ' . $needle
        );
    }
}

/**
 * @param list<string> $needles
 */
function assertUpgradeRuntimeNotContains(string $source, array $needles, string $scope): void
{
    foreach ($needles as $needle) {
        assertUpgradeRuntime(
            !str_contains($source, $needle),
            $scope . ' must not contain: ' . $needle
        );
    }
}

$root = dirname(__DIR__);

$accountSource = upgradeRuntimeSource($root . '/Kalender Konto/module.php');
$accountApply = upgradeRuntimeMethod($accountSource, 'public function ApplyChanges(): void');
$accountMessageSink = upgradeRuntimeMethod(
    $accountSource,
    'public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void'
);

assertUpgradeRuntimeContains(
    $accountApply,
    [
        "\$this->SetTimerInterval('OAuthRegistrationTimer', 0);",
        'if (IPS_GetKernelRunlevel() === KR_READY)',
        '$this->scheduleOAuthRegistration();',
        "\$this->SetTimerInterval(\n            'SynchronizationTimer',",
        'SynchronizationSchedule::timerInterval('
    ],
    'Calendar Account ApplyChanges()'
);
assertUpgradeRuntimeNotContains(
    $accountApply,
    [
        "WriteAttributeString('GoogleRefreshToken'",
        "WriteAttributeString('MicrosoftRefreshToken'",
        "WriteAttributeString('GoogleAccount'",
        "WriteAttributeString('MicrosoftAccount'",
        "WriteAttributeString('CachedCalendars'",
        "WriteAttributeString('ICalendarFeedCache'",
        'ClearCache()'
    ],
    'Calendar Account ApplyChanges()'
);
assertUpgradeRuntimeContains(
    $accountMessageSink,
    [
        '$SenderID === 0 && $Message === IPS_KERNELSTARTED',
        '$this->scheduleOAuthRegistration();'
    ],
    'Calendar Account restart handling'
);

$calendarSource = upgradeRuntimeSource($root . '/Kalender/module.php');
$calendarApply = upgradeRuntimeMethod($calendarSource, 'public function ApplyChanges(): void');
$calendarInitialize = upgradeRuntimeMethod($calendarSource, 'public function Initialize(): bool');
$calendarMessageSink = upgradeRuntimeMethod(
    $calendarSource,
    'public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void'
);
$clearIncremental = upgradeRuntimeMethod($calendarSource, 'private function clearIncrementalSyncState(): void');

assertUpgradeRuntimeContains(
    $calendarApply,
    [
        '$this->clearIncrementalSyncState();',
        '$this->updateEventCounters($this->readEvents());',
        "\$this->SetTimerInterval('InitializationTimer', 0);",
        "\$this->SetTimerInterval('SynchronizationTimer', 0);",
        "\$this->SetTimerInterval('DayChangeTimer', 0);",
        '$this->scheduleInitialization();'
    ],
    'Calendar ApplyChanges()'
);
assertUpgradeRuntimeNotContains(
    $calendarApply,
    [
        "ClearPersistentJsonCache('CachedEvents')",
        "WritePersistentJsonCache('CachedEvents'",
        "WriteAttributeString('CachedEvents'",
        "WriteAttributeString('AnniversaryMetadata'",
        "WriteAttributeString('BirthdayMetadata'"
    ],
    'Calendar ApplyChanges()'
);
assertUpgradeRuntimeContains(
    $clearIncremental,
    [
        "WriteAttributeString('IncrementalSyncToken', '')",
        "WriteAttributeInteger('IncrementalSyncWindowStart', 0)",
        "WriteAttributeInteger('IncrementalSyncWindowEnd', 0)",
        "WriteAttributeString('IncrementalSyncCalendarID', '')"
    ],
    'Calendar incremental reset'
);
assertUpgradeRuntimeNotContains(
    $clearIncremental,
    [
        'CachedEvents',
        'AnniversaryMetadata',
        'BirthdayMetadata',
        'LastSynchronization'
    ],
    'Calendar incremental reset'
);
assertUpgradeRuntimeContains(
    $calendarMessageSink,
    [
        '$SenderID === 0 && $Message === IPS_KERNELSTARTED',
        '$this->scheduleInitialization();',
        '$this->scheduleTodayEventCountRefresh();'
    ],
    'Calendar restart handling'
);
assertUpgradeRuntimeContains(
    $calendarInitialize,
    [
        "\$this->WriteAttributeBoolean('RuntimeReady', true);",
        "\$this->SetTimerInterval(\n            'SynchronizationTimer',",
        '$this->refreshCalendarMetadataSafely();',
        '$this->updateEventCounters($this->readEvents());',
        '$this->scheduleTodayEventCountRefresh();'
    ],
    'Calendar Initialize()'
);

$persistentCacheSource = upgradeRuntimeSource($root . '/libs/helper/PersistentJsonCacheHelper.php');
assertUpgradeRuntimeContains(
    $persistentCacheSource,
    [
        '$this->RegisterAttributeString($name, $this->EncodePersistentJsonCache($default));',
        '$raw = $this->ReadAttributeString($name);',
        '$this->WriteAttributeString($name, $encoded);'
    ],
    'Persistent calendar cache helper'
);

$configuratorSource = upgradeRuntimeSource($root . '/Kalender Konfigurator/module.php');
$configuratorApply = upgradeRuntimeMethod($configuratorSource, 'public function ApplyChanges(): void');
$configuratorMessageSink = upgradeRuntimeMethod(
    $configuratorSource,
    'public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void'
);
$configuratorCacheParent = upgradeRuntimeMethod(
    $configuratorSource,
    'private function synchronizeCalendarCacheParent(): void'
);

assertUpgradeRuntimeContains(
    $configuratorApply,
    [
        '$this->synchronizeCalendarCacheParent();',
        "\$this->SetTimerInterval('InitializationTimer', 0);",
        '$this->scheduleInitialization();'
    ],
    'Calendar Configurator ApplyChanges()'
);
assertUpgradeRuntimeContains(
    $configuratorCacheParent,
    [
        "if (\$this->ReadAttributeInteger('CachedCalendarParentID') === \$parentId) {",
        'return;',
        "\$this->WriteAttributeString('CachedCalendars', '[]');",
        "\$this->WriteAttributeInteger('CachedCalendarParentID', \$parentId);"
    ],
    'Calendar Configurator parent cache isolation'
);
assertUpgradeRuntimeContains(
    $configuratorMessageSink,
    [
        '$SenderID === 0 && $Message === IPS_KERNELSTARTED',
        '$this->scheduleInitialization();'
    ],
    'Calendar Configurator restart handling'
);

$viewSource = upgradeRuntimeSource($root . '/Kalender Ansicht/module.php');
$viewApply = upgradeRuntimeMethod($viewSource, 'public function ApplyChanges(): void');
$viewInitialize = upgradeRuntimeMethod($viewSource, 'public function Initialize(): bool');
$viewMessageSink = upgradeRuntimeMethod(
    $viewSource,
    'public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void'
);
$recoverSelection = upgradeRuntimeMethod(
    $viewSource,
    'private function recoverCalendarSelectionFromMessages(): void'
);

assertUpgradeRuntimeContains(
    $viewApply,
    [
        '$this->applyTileObjectVisibility();',
        '$preservedIPSViewHTML = $this->existingIPSViewHTML();',
        '$this->ensureIPSViewToken();',
        "\$this->WriteAttributeBoolean('RuntimeReady', false);",
        '$this->storeCalendarSelectionBackup($configured);',
        "\$this->SetTimerInterval('InitializationTimer', 0);",
        "\$this->MaintainIPSViewHTMLVariable(\n            'IPSViewCalendar',",
        '$preservedIPSViewHTML',
        '$this->scheduleInitialization();'
    ],
    'Calendar View ApplyChanges()'
);
assertUpgradeRuntimeNotContains(
    $viewApply,
    [
        "WriteAttributeString('CalendarSelectionBackup', '[]')",
        "WriteAttributeInteger('IPSViewToken1', 0)",
        "WriteAttributeInteger('IPSViewToken2', 0)",
        "WriteAttributeInteger('IPSViewToken3', 0)",
        "WriteAttributeInteger('IPSViewToken4', 0)"
    ],
    'Calendar View ApplyChanges()'
);
assertUpgradeRuntimeContains(
    $viewMessageSink,
    [
        '$SenderID === 0 && $Message === IPS_KERNELSTARTED',
        '$this->scheduleInitialization();'
    ],
    'Calendar View restart handling'
);
assertUpgradeRuntimeContains(
    $viewInitialize,
    [
        '$this->recoverCalendarSelectionFromMessages();',
        "\$this->WriteAttributeBoolean('RuntimeReady', true);",
        '$this->loadSelectedCalendars();',
        '$this->RegisterMessage($instanceId, OM_CHANGENAME);'
    ],
    'Calendar View Initialize()'
);
assertUpgradeRuntimeContains(
    $recoverSelection,
    [
        'if ($this->effectiveCalendarConfiguration() !== []) {',
        'return;',
        '$this->GetMessageList()',
        '$this->storeCalendarSelectionBackup($selection);'
    ],
    'Calendar View selection recovery'
);

assertUpgradeRuntimeContains(
    $viewSource,
    [
        "\$this->RegisterPropertyBoolean('ShowTileTitle', true);",
        "\$this->RegisterPropertyBoolean('ShowTileMaximizeButton', true);",
        'IPS_SetHiddenTitle($objectID, $hideTitle);',
        'IPS_SetHiddenMaximize($objectID, $hideMaximize);'
    ],
    'Calendar View tile migration defaults'
);

fwrite(STDOUT, "OpenCalendar upgrade runtime/restart contract passed.\n");
