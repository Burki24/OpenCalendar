<?php

declare(strict_types=1);

function calendarMetadataExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function calendarMetadataSourceBlock(string $source, string $start, string $end): string
{
    $startOffset = strpos($source, $start);
    if ($startOffset === false) {
        throw new RuntimeException('Calendar metadata source block could not be found: ' . $start);
    }

    $endOffset = strpos($source, $end, $startOffset + strlen($start));
    if ($endOffset === false) {
        throw new RuntimeException('Calendar metadata source block boundary could not be found: ' . $end);
    }

    return substr($source, $startOffset, $endOffset - $startOffset);
}

$source = file_get_contents(__DIR__ . '/../Kalender/module.php');
if (!is_string($source) || $source === '') {
    throw new RuntimeException('Calendar module source could not be read.');
}

$attributeNames = [
    'CalendarMetadataAvailable',
    'ResolvedCalendarID',
    'DetectedCalendarColor',
    'DetectedCanWrite',
    'DetectedCanCreateRecurrence',
    'DetectedCanUpdateRecurrence',
    'DetectedCanUpdateOccurrence',
    'DetectedCanDeleteOccurrence',
    'DetectedCanUpdateFollowing',
    'DetectedCanUpdateSeries',
    'DetectedCanDeleteSeries',
    'DetectedCanUseDefaultReminder',
    'DetectedCanCreateWithDefaultReminder',
    'DetectedCanWriteStatus',
    'DetectedCanWriteTransparency',
    'DetectedDefaultStatus',
    'DetectedDefaultTransparency',
    'DetectedDefaultAllDayTransparency',
    'DetectedMaxReminders',
    'DetectedDefaultReminder',
    'DetectedCalendarTimezone',
    'DetectedWriteAccessKnown'
];

$catalog = calendarMetadataSourceBlock(
    $source,
    'private const CALENDAR_METADATA_ATTRIBUTES = [',
    "\n    ];"
);
foreach ($attributeNames as $attributeName) {
    calendarMetadataExpect(
        str_contains($catalog, "'name' => '" . $attributeName . "'"),
        'The shared calendar metadata catalog must retain the persisted attribute ' . $attributeName . '.'
    );
}

$create = calendarMetadataSourceBlock(
    $source,
    'public function Create(): void',
    'public function GetConfigurationForm(): string'
);
calendarMetadataExpect(
    str_contains($create, '$this->registerCalendarMetadataAttributes();'),
    'Calendar::Create must delegate metadata attribute registration to the shared metadata helper.'
);

$registration = calendarMetadataSourceBlock(
    $source,
    'private function registerCalendarMetadataAttributes(): void',
    'private function readCalendarMetadata(): array'
);
foreach ($attributeNames as $attributeName) {
    calendarMetadataExpect(
        str_contains($registration, "'" . $attributeName . "'"),
        'Calendar metadata registration must retain the persisted attribute ' . $attributeName . '.'
    );
}

$status = calendarMetadataSourceBlock(
    $source,
    'public function GetCalendarStatus(): string',
    'private function refreshCalendarMetadataSafely(): void'
);
calendarMetadataExpect(
    str_contains($status, '$metadata = $this->readCalendarMetadata();')
        && str_contains($status, "(bool) \$metadata['canWrite']")
        && str_contains($status, "(string) \$metadata['defaultReminderJson']"),
    'Calendar status must consume the centralized metadata state instead of rebuilding attribute reads.'
);
calendarMetadataExpect(
    str_contains(
        $status,
        "\$writeAccessKnown = \$metadataAvailable && (bool) \$metadata['writeAccessKnown'];"
    )
        && str_contains($status, "? (bool) \$metadata['canWrite']")
        && str_contains(
            $status,
            ": (bool) \$metadata['canWrite'] || \$this->ReadPropertyBoolean('CanWrite')"
        ),
    'Unknown write access must preserve the legacy CanWrite fallback after metadata centralization.'
);

$apply = calendarMetadataSourceBlock(
    $source,
    'private function applyCalendarMetadata(array $calendars): void',
    'private function storeCalendarMetadata(array $calendar): void'
);
calendarMetadataExpect(
    str_contains($apply, '$this->resetCalendarMetadataResolution();'),
    'Unresolved calendar metadata must use the centralized reset path.'
);

$store = calendarMetadataSourceBlock(
    $source,
    'private function storeCalendarMetadata(array $calendar): void',
    'private function calendarMetadataFromProvider(array $calendar): array'
);
calendarMetadataExpect(
    str_contains($store, '$metadata = $this->calendarMetadataFromProvider($calendar);')
        && str_contains($store, '$this->writeCalendarMetadata($metadata);'),
    'Detected provider metadata must be normalized and written through the centralized metadata state.'
);

$reset = calendarMetadataSourceBlock(
    $source,
    'private function resetCalendarMetadataResolution(): void',
    'private function requestEvents(): array'
);
calendarMetadataExpect(
    str_contains($reset, "'available'                    => false")
        && str_contains($reset, "'resolvedCalendarId'           => ''")
        && str_contains($reset, "'defaultReminderJson'          => '{}'")
        && str_contains($reset, "'writeAccessKnown'             => false"),
    'The centralized reset must restore the established unresolved metadata defaults.'
);
calendarMetadataExpect(
    !str_contains($reset, "'canWrite'") && !str_contains($reset, "'calendarColor'"),
    'The unresolved reset must preserve the previously detected write and color values exactly as before.'
);

$writer = calendarMetadataSourceBlock(
    $source,
    'private function writeCalendarMetadata(array $metadata): void',
    'private function resetCalendarMetadataResolution(): void'
);
calendarMetadataExpect(
    str_contains($writer, 'array_key_exists($key, $metadata)')
        && str_contains($writer, 'WriteAttributeBoolean')
        && str_contains($writer, 'WriteAttributeInteger')
        && str_contains($writer, 'WriteAttributeString'),
    'The metadata writer must support partial updates while retaining all persisted Symcon attribute types.'
);

fwrite(STDOUT, "Calendar metadata state handling verified.\n");
