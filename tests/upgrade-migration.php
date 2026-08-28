<?php

declare(strict_types=1);

function assertUpgradeMigration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function upgradeMigrationSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Upgrade migration source could not be read: ' . $path);
    }

    return $source;
}

/**
 * @return array<string, mixed>
 */
function upgradeMigrationJson(string $path): array
{
    $data = json_decode(upgradeMigrationSource($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_is_list($data)) {
        throw new RuntimeException('Upgrade migration metadata must be a JSON object: ' . $path);
    }

    return $data;
}

/**
 * @return array<string, string>
 */
function registeredState(string $source, string $kind): array
{
    $pattern = sprintf(
        '/\\$this->Register%s(Boolean|Integer|Float|String)\\(\\s*[\'"]([^\'"]+)[\'"]/',
        preg_quote($kind, '/')
    );
    preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

    $result = [];
    foreach ($matches as $match) {
        $result[(string) $match[2]] = (string) $match[1];
    }

    return $result;
}

/**
 * @param array<string, string> $actual
 * @param array<string, string> $required
 */
function assertRegisteredState(array $actual, array $required, string $scope): void
{
    foreach ($required as $name => $type) {
        assertUpgradeMigration(
            isset($actual[$name]),
            sprintf('%s must retain persisted state "%s".', $scope, $name)
        );
        assertUpgradeMigration(
            $actual[$name] === $type,
            sprintf(
                '%s persisted state "%s" changed type from %s to %s.',
                $scope,
                $name,
                $type,
                $actual[$name]
            )
        );
    }
}

/**
 * @param list<string> $required
 */
function assertSourceContainsAll(string $source, array $required, string $scope): void
{
    foreach ($required as $needle) {
        assertUpgradeMigration(
            str_contains($source, $needle),
            sprintf('%s must retain "%s".', $scope, $needle)
        );
    }
}

$root = dirname(__DIR__);

$library = upgradeMigrationJson($root . '/library.json');
assertUpgradeMigration(
    (string) ($library['version'] ?? '') === '3.0',
    'The upgrade target must remain OpenCalendar 3.0.'
);
assertUpgradeMigration(
    version_compare((string) ($library['compatibility']['version'] ?? '0'), '9.1', '>='),
    'OpenCalendar 3.0 must require Symcon 9.1 or newer.'
);

$expectedModules = [
    'Kalender' => [
        'id'                 => '{227B63E4-4223-316B-76E9-FD3849689562}',
        'name'               => 'Calendar',
        'type'               => 3,
        'prefix'             => 'IPSKAL',
        'parentRequirements' => ['{4E535B1D-69C7-AC77-1372-0282B21BAEC9}'],
        'childRequirements'  => [],
        'implemented'        => ['{8ED646DD-88E9-ACE2-95D5-9766EED4B5B0}']
    ],
    'Kalender Konto' => [
        'id'                 => '{966D6119-7FF3-5CA5-06C3-536FBF8100C4}',
        'name'               => 'Calendar Account',
        'type'               => 2,
        'prefix'             => 'IPSKALACC',
        'parentRequirements' => [],
        'childRequirements'  => ['{8ED646DD-88E9-ACE2-95D5-9766EED4B5B0}'],
        'implemented'        => ['{4E535B1D-69C7-AC77-1372-0282B21BAEC9}']
    ],
    'Kalender Ansicht' => [
        'id'                 => '{1B19AB6B-9052-EA85-F158-86A13FE6F5BA}',
        'name'               => 'Calendar View',
        'type'               => 3,
        'prefix'             => 'IPSKALVIEW',
        'parentRequirements' => [],
        'childRequirements'  => [],
        'implemented'        => []
    ],
    'Kalender Konfigurator' => [
        'id'                 => '{4A013D9D-3611-9900-5815-A8EC8A91287D}',
        'name'               => 'Calendar Configurator',
        'type'               => 4,
        'prefix'             => 'IPSKALCFG',
        'parentRequirements' => ['{4E535B1D-69C7-AC77-1372-0282B21BAEC9}'],
        'childRequirements'  => [],
        'implemented'        => ['{8ED646DD-88E9-ACE2-95D5-9766EED4B5B0}']
    ]
];

foreach ($expectedModules as $directory => $expected) {
    $metadata = upgradeMigrationJson($root . '/' . $directory . '/module.json');
    foreach ($expected as $key => $value) {
        assertUpgradeMigration(
            ($metadata[$key] ?? null) === $value,
            sprintf('%s module metadata changed the 2.0 compatibility field "%s".', $directory, $key)
        );
    }
}

$accountSource = upgradeMigrationSource($root . '/Kalender Konto/module.php');
assertRegisteredState(
    registeredState($accountSource, 'Property'),
    [
        'Active'                      => 'Boolean',
        'Provider'                    => 'Integer',
        'ServerURL'                   => 'String',
        'Username'                    => 'String',
        'Password'                    => 'String',
        'CalendarName'                => 'String',
        'ICalendarAuthenticationMode' => 'Integer',
        'ICalendarTranslationProfile' => 'Integer',
        'ICalendarFeeds'              => 'String',
        'ICalendarFiles'              => 'String',
        'UpdateSchedule'              => 'Integer',
        'UpdateInterval'              => 'Integer',
        'VerifyTLS'                   => 'Boolean',
        'RequestTimeout'              => 'Integer'
    ],
    'Calendar Account properties'
);
assertRegisteredState(
    registeredState($accountSource, 'Attribute'),
    [
        'CachedCalendars'        => 'String',
        'ICalendarFeedCache'     => 'String',
        'LastSynchronization'    => 'Integer',
        'LastError'              => 'String',
        'GoogleRefreshToken'     => 'String',
        'GoogleAccount'          => 'String',
        'GoogleTokenClientID'    => 'String',
        'MicrosoftRefreshToken'  => 'String',
        'MicrosoftAccount'       => 'String',
        'PendingOAuthProvider'   => 'Integer',
        'PendingOAuthInstanceID' => 'Integer',
        'PendingOAuthStartedAt'  => 'Integer'
    ],
    'Calendar Account attributes'
);
assertSourceContainsAll(
    $accountSource,
    [
        "RegisterTimer('SynchronizationTimer'",
        "RegisterTimer('OAuthRegistrationTimer'"
    ],
    'Calendar Account timers'
);

$calendarSource = upgradeMigrationSource($root . '/Kalender/module.php');
assertRegisteredState(
    registeredState($calendarSource, 'Property'),
    [
        'Active'             => 'Boolean',
        'CalendarID'         => 'String',
        'ProviderCalendarID' => 'String',
        'CalendarURL'        => 'String',
        'CalendarColor'      => 'String',
        'CanWrite'           => 'Boolean',
        'UpdateSchedule'     => 'Integer',
        'UpdateInterval'     => 'Integer',
        'PastDays'           => 'Integer',
        'FutureDays'         => 'Integer'
    ],
    'Calendar properties'
);
assertRegisteredState(
    registeredState($calendarSource, 'Attribute'),
    [
        'AnniversaryMetadata'                     => 'String',
        'BirthdayMetadata'                        => 'String',
        'LastSynchronization'                     => 'Integer',
        'LastError'                               => 'String',
        'IncrementalSyncToken'                    => 'String',
        'IncrementalSyncWindowStart'              => 'Integer',
        'IncrementalSyncWindowEnd'                => 'Integer',
        'IncrementalSyncCalendarID'               => 'String',
        'CalendarMetadataAvailable'               => 'Boolean',
        'ResolvedCalendarID'                      => 'String',
        'DetectedCalendarColor'                   => 'String',
        'DetectedCanWrite'                        => 'Boolean',
        'DetectedCanCreateRecurrence'             => 'Boolean',
        'DetectedCanUpdateRecurrence'             => 'Boolean',
        'DetectedCanUpdateOccurrence'             => 'Boolean',
        'DetectedCanDeleteOccurrence'             => 'Boolean',
        'DetectedCanUpdateFollowing'              => 'Boolean',
        'DetectedCanUpdateSeries'                 => 'Boolean',
        'DetectedCanDeleteSeries'                 => 'Boolean',
        'DetectedCanUseDefaultReminder'           => 'Boolean',
        'DetectedCanCreateWithDefaultReminder'    => 'Boolean',
        'DetectedMaxReminders'                    => 'Integer',
        'DetectedDefaultReminder'                 => 'String',
        'DetectedCalendarTimezone'                => 'String',
        'DetectedWriteAccessKnown'                => 'Boolean',
        'RuntimeReady'                            => 'Boolean'
    ],
    'Calendar attributes'
);
assertSourceContainsAll(
    $calendarSource,
    [
        "RegisterPersistentJsonCache('CachedEvents')",
        "RegisterVariableInteger('EventCount'",
        "RegisterVariableInteger('TodayEventCount'",
        "'LastSynchronization',",
        "RegisterTimer('InitializationTimer'",
        "RegisterTimer('SynchronizationTimer'",
        "RegisterTimer('DayChangeTimer'"
    ],
    'Calendar runtime state'
);

$configuratorSource = upgradeMigrationSource($root . '/Kalender Konfigurator/module.php');
assertRegisteredState(
    registeredState($configuratorSource, 'Attribute'),
    [
        'CachedCalendars'         => 'String',
        'CachedCalendarParentID'  => 'Integer',
        'LastError'               => 'String'
    ],
    'Calendar Configurator attributes'
);
assertSourceContainsAll(
    $configuratorSource,
    ["RegisterTimer('InitializationTimer'"],
    'Calendar Configurator timers'
);

$viewSource = upgradeMigrationSource($root . '/Kalender Ansicht/module.php');
assertRegisteredState(
    registeredState($viewSource, 'Property'),
    [
        'Calendars'                   => 'String',
        'DefaultView'                 => 'Integer',
        'TileWeekOrientation'         => 'Integer',
        'TileFontScale'               => 'Integer',
        'PastDays'                    => 'Integer',
        'FutureDays'                  => 'Integer',
        'MaxEvents'                   => 'Integer',
        'AgendaPeriodDays'            => 'Integer',
        'ListPeriodDays'              => 'Integer',
        'ThreeDaysPeriodDays'         => 'Integer',
        'WeekPeriodWeeks'             => 'Integer',
        'MonthPeriodMonths'           => 'Integer',
        'ShowWeekends'                => 'Boolean',
        'ShowAgendaEventCount'        => 'Boolean',
        'ShowThreeDaysEventCount'     => 'Boolean',
        'ShowWeekEventCount'          => 'Boolean',
        'ShowAgendaCalendarWeek'      => 'Boolean',
        'ShowListCalendarWeek'        => 'Boolean',
        'ShowThreeDaysCalendarWeek'   => 'Boolean',
        'ShowWeekCalendarWeek'        => 'Boolean',
        'ShowMonthCalendarWeek'       => 'Boolean',
        'ShowAgendaDayOfYear'         => 'Boolean',
        'ShowListDayOfYear'           => 'Boolean',
        'ShowThreeDaysDayOfYear'      => 'Boolean',
        'ShowWeekDayOfYear'           => 'Boolean',
        'ShowMonthDayOfYear'          => 'Boolean',
        'ShowListDate'                => 'Boolean',
        'ShowListStart'               => 'Boolean',
        'ShowListEnd'                 => 'Boolean',
        'ShowListTitle'               => 'Boolean',
        'ShowListCalendarName'        => 'Boolean',
        'ShowListAnniversaryType'     => 'Boolean',
        'ShowListLocation'            => 'Boolean',
        'ShowListDescription'         => 'Boolean',
        'ShowListControls'            => 'Boolean',
        'ShowCalendarName'            => 'Boolean',
        'ShowAnniversaryType'         => 'Boolean',
        'ShowLocation'                => 'Boolean',
        'ShowDescription'             => 'Boolean',
        'IPSViewColorBarWidth'        => 'Integer',
        'IPSViewWeekOrientation'      => 'Integer'
    ],
    'Calendar View properties'
);
assertRegisteredState(
    registeredState($viewSource, 'Attribute'),
    [
        'RuntimeReady'            => 'Boolean',
        'CalendarSelectionBackup' => 'String'
    ],
    'Calendar View attributes'
);
assertSourceContainsAll(
    $viewSource,
    [
        '$this->RegisterIPSViewHTMLPageProperties();',
        '$this->RegisterIPSViewStyleProperties();',
        "RegisterTimer('InitializationTimer'",
        "'IPSViewCalendar',",
        "RegisterPropertyBoolean('ShowTileTitle', true);",
        "RegisterPropertyBoolean('ShowTileMaximizeButton', true);"
    ],
    'Calendar View migration contract'
);

$htmlHelperSource = upgradeMigrationSource($root . '/libs/helper/IPSViewHTMLPageHelper.php');
assertSourceContainsAll(
    $htmlHelperSource,
    [
        "\$this->RegisterPropertyBoolean(self::IPSVIEW_HTML_ENABLE_PROPERTY, false);",
        "\$this->RegisterAttributeString(self::IPSVIEW_HTML_VARIABLE_REGISTRY_ATTRIBUTE, '[]');"
    ],
    'IPSView HTML helper persistence'
);

$styleHelperSource = upgradeMigrationSource($root . '/libs/helper/IPSViewStyleHelper.php');
assertSourceContainsAll(
    $styleHelperSource,
    [
        "RegisterPropertyInteger('IPSViewStyleSource'",
        "RegisterPropertyInteger('IPSViewStyleMediaID'",
        "RegisterPropertyBoolean('IPSViewStyleTransparentBackground'",
        "RegisterPropertyInteger('IPSViewStyleFontScale'",
        "RegisterPropertyString('IPSViewStyleFontFamily'",
        "RegisterPropertyInteger('IPSViewStyleBaseFontSize'",
        "RegisterPropertyFloat('IPSViewStyleBorderRadius'",
        "RegisterPropertyFloat('IPSViewStyleBorderWidth'",
        "RegisterPropertyFloat('IPSViewStyleLineWidth'",
        "RegisterPropertyFloat('IPSViewStyleShadowBlur'",
        "RegisterPropertyFloat('IPSViewStyleShadowSpread'",
        "RegisterPropertyFloat('IPSViewStyleShadowOffsetX'",
        "RegisterPropertyFloat('IPSViewStyleShadowOffsetY'",
        "RegisterPropertyInteger('IPSViewStyleDisabledOpacity'",
        "RegisterPropertyInteger('IPSViewStyleGradientStrength'",
        "RegisterAttributeInteger('IPSViewStyleRegisteredMediaID'"
    ],
    'IPSView style helper persistence'
);

assertUpgradeMigration(
    !preg_match(
        '/unset\\(\\$configuration\\[[\'"](?:Calendars|DefaultView|TileWeekOrientation|TileFontScale|PastDays|FutureDays|MaxEvents|AgendaPeriodDays|ListPeriodDays|ThreeDaysPeriodDays|WeekPeriodWeeks|MonthPeriodMonths|ShowWeekends|ShowAgendaEventCount|ShowThreeDaysEventCount|ShowWeekEventCount|ShowAgendaCalendarWeek|ShowListCalendarWeek|ShowThreeDaysCalendarWeek|ShowWeekCalendarWeek|ShowMonthCalendarWeek|ShowAgendaDayOfYear|ShowListDayOfYear|ShowThreeDaysDayOfYear|ShowWeekDayOfYear|ShowMonthDayOfYear|ShowListDate|ShowListStart|ShowListEnd|ShowListTitle|ShowListCalendarName|ShowListAnniversaryType|ShowListLocation|ShowListDescription|ShowListControls|ShowCalendarName|ShowAnniversaryType|ShowLocation|ShowDescription|IPSViewColorBarWidth|IPSViewWeekOrientation)[\'"]\\]\\)/',
        $viewSource
    ),
    'Calendar View migration must not delete properties that belong to the OpenCalendar 2.0 persistence contract.'
);

fwrite(STDOUT, "OpenCalendar 2.0 -> 3.0 static upgrade contract passed.\n");
