<?php

declare(strict_types=1);

use IPSKalender\CalendarAppointmentRange;
use IPSKalender\CalendarEventReminder;

require_once __DIR__ . '/../libs/CalendarAppointmentRange.php';

function assertCalendarViewApi(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertCalendarViewApiThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('Europe/Berlin');

try {
    [$dayStart, $dayEnd] = CalendarAppointmentRange::fromInclusiveDates('2026-08-11', '2026-08-11');
    assertCalendarViewApi(
        $dayStart->format('Y-m-d H:i:s P') === '2026-08-11 00:00:00 +02:00'
            && $dayEnd->format('Y-m-d H:i:s P') === '2026-08-12 00:00:00 +02:00',
        'A one-day appointment query must use local midnight boundaries.'
    );

    [$dstStart, $dstEnd] = CalendarAppointmentRange::fromInclusiveDates('2026-10-25', '2026-10-25');
    assertCalendarViewApi(
        ($dstEnd->getTimestamp() - $dstStart->getTimestamp()) === 25 * 3600,
        'Local date ranges must retain daylight-saving transitions instead of assuming 24-hour days.'
    );

    assertCalendarViewApi(
        !CalendarAppointmentRange::eventOverlaps([
            'allDay' => true,
            'start'  => '2026-08-10',
            'end'    => '2026-08-11'
        ], $dayStart, $dayEnd),
        'An all-day event ending exclusively on the queried date must not spill into that day.'
    );
    assertCalendarViewApi(
        CalendarAppointmentRange::eventOverlaps([
            'allDay' => true,
            'start'  => '2026-08-11',
            'end'    => '2026-08-12'
        ], $dayStart, $dayEnd),
        'An all-day event covering the queried date must be returned.'
    );
    assertCalendarViewApi(
        CalendarAppointmentRange::eventOverlaps([
            'allDay'         => false,
            'startTimestamp' => (new DateTimeImmutable('2026-08-10T23:30:00+02:00'))->getTimestamp(),
            'endTimestamp'   => (new DateTimeImmutable('2026-08-11T00:30:00+02:00'))->getTimestamp()
        ], $dayStart, $dayEnd),
        'Timed appointments spanning midnight must be returned on every overlapping day.'
    );

    assertCalendarViewApiThrows(
        static fn (): array => CalendarAppointmentRange::fromInclusiveDates('2026-02-30', '2026-03-01'),
        'Invalid local dates must be rejected.'
    );
    assertCalendarViewApiThrows(
        static fn (): array => CalendarAppointmentRange::fromInclusiveDates('2026-08-12', '2026-08-11'),
        'An appointment range ending before it starts must be rejected.'
    );
} finally {
    date_default_timezone_set($previousTimezone);
}

$moduleSource = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/module.php');
$calendarModuleSource = (string) file_get_contents(__DIR__ . '/../Kalender/module.php');
$readmeSource = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/README.md');
$moduleMetadata = json_decode(
    (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/module.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
assertCalendarViewApi(
    is_array($moduleMetadata) && ($moduleMetadata['prefix'] ?? '') === 'IPSKALVIEW',
    'Calendar View must keep the public Symcon wrapper prefix IPSKALVIEW.'
);

$supportedScriptApiMethods = [
    'SynchronizeCalendars',
    'SelectAllCalendars',
    'GetAggregatedEvents',
    'GetDayAppointments',
    'GetAnniversaryList',
    'GetBirthdayList',
    'GetAppointments',
    'GetDayAppointmentsCompact',
    'GetAppointmentsCompact',
    'GetDayAppointmentCount',
    'GetAppointmentCount',
    'GetRemainingDayAppointments',
    'GetRemainingDayAppointmentCount',
    'GetNextAppointment',
    'GetCurrentAppointments',
    'GetCurrentAppointmentCount',
    'GetUpcomingAppointments',
    'GetUpcomingAppointmentsCompact',
    'GetUpcomingAppointmentCount',
    'GetNextAppointments',
    'GetNextAppointmentsCompact',
    'GetDayReminders',
    'GetReminders',
    'GetUpcomingReminders',
    'GetNextReminder',
    'GetDueReminders',
    'GetSelectedCalendars',
    'GetIPSViewHTML',
    'RegenerateIPSViewHTML'
];
$frameworkPublicMethods = [
    'Create',
    'GetConfigurationForm',
    'Migrate',
    'ApplyChanges',
    'Initialize',
    'MessageSink',
    'GetVisualizationTile',
    'RequestAction'
];

preg_match_all('/^\s+public function ([A-Za-z0-9_]+)\s*\(/m', $moduleSource, $publicMethodMatches);
$declaredPublicMethods = array_values(array_unique($publicMethodMatches[1] ?? []));
sort($declaredPublicMethods, SORT_STRING);
$expectedPublicMethods = array_merge($frameworkPublicMethods, $supportedScriptApiMethods);
sort($expectedPublicMethods, SORT_STRING);
assertCalendarViewApi(
    $declaredPublicMethods === $expectedPublicMethods,
    'Every Calendar View public method must be classified as framework lifecycle or supported script API.'
);

foreach ($supportedScriptApiMethods as $method) {
    assertCalendarViewApi(
        str_contains($moduleSource, 'public function ' . $method . '(')
            && str_contains($readmeSource, 'IPSKALVIEW_' . $method . '('),
        'Every supported Calendar View script API method must have a matching IPSKALVIEW wrapper in the PHP reference.'
    );
}

preg_match_all('/\bIPSKALVIEW_([A-Za-z0-9_]+)\s*\(/', $readmeSource, $documentedWrapperMatches);
$documentedScriptApiMethods = array_values(array_unique($documentedWrapperMatches[1] ?? []));
sort($documentedScriptApiMethods, SORT_STRING);
$expectedDocumentedScriptApiMethods = $supportedScriptApiMethods;
sort($expectedDocumentedScriptApiMethods, SORT_STRING);
assertCalendarViewApi(
    $documentedScriptApiMethods === $expectedDocumentedScriptApiMethods,
    'Calendar View PHP reference must document every supported wrapper exactly and must not expose internal lifecycle callbacks.'
);
assertCalendarViewApi(
    str_contains($readmeSource, 'Der Modul-Prefix lautet `IPSKALVIEW`')
        && str_contains($readmeSource, 'events, calendars, eventRange und settings')
        && str_contains($readmeSource, 'liefert keinen nackten Termin-Array')
        && str_contains($readmeSource, '$state = IPSKALVIEW_GetAggregatedEvents(12345);'),
    'Calendar View PHP reference must explain the wrapper prefix and the complete GetAggregatedEvents state payload.'
);
$calendarFilterReferenceCalls = [
    "IPSKALVIEW_GetDayAppointments(12345, '2026-08-11', 0)",
    "IPSKALVIEW_GetAppointments(12345, '2026-08-11', '2026-08-17', 0)",
    "IPSKALVIEW_GetDayAppointmentsCompact(12345, '2026-08-11', 0)",
    "IPSKALVIEW_GetAppointmentsCompact(12345, '2026-08-11', '2026-08-17', 0)",
    "IPSKALVIEW_GetDayAppointmentCount(12345, '2026-08-11', 0)",
    "IPSKALVIEW_GetAppointmentCount(12345, '2026-08-11', '2026-08-17', 0)",
    'IPSKALVIEW_GetRemainingDayAppointments(12345, 0)',
    'IPSKALVIEW_GetRemainingDayAppointmentCount(12345, 0)',
    'IPSKALVIEW_GetNextAppointment(12345, 0)',
    'IPSKALVIEW_GetCurrentAppointments(12345, 0)',
    'IPSKALVIEW_GetCurrentAppointmentCount(12345, 0)',
    'IPSKALVIEW_GetUpcomingAppointments(12345, 24, 0)',
    'IPSKALVIEW_GetUpcomingAppointmentsCompact(12345, 24, 0)',
    'IPSKALVIEW_GetUpcomingAppointmentCount(12345, 24, 0)',
    'IPSKALVIEW_GetNextAppointments(12345, 3, 0)',
    'IPSKALVIEW_GetNextAppointmentsCompact(12345, 3, 0)',
    "IPSKALVIEW_GetAnniversaryList(12345, 0, 0, '')",
    'IPSKALVIEW_GetBirthdayList(12345, 0, 45)',
    "IPSKALVIEW_GetDayReminders(12345, '2026-08-11', 0)",
    "IPSKALVIEW_GetReminders(12345, '2026-08-11', '2026-08-17', 0)",
    'IPSKALVIEW_GetUpcomingReminders(12345, 30, 0)',
    'IPSKALVIEW_GetNextReminder(12345, 0)',
    'IPSKALVIEW_GetDueReminders(12345, 2, 0)'
];
foreach ($calendarFilterReferenceCalls as $referenceCall) {
    assertCalendarViewApi(
        str_contains($readmeSource, $referenceCall),
        'Calendar View PHP reference must show CalendarInstanceID zero explicitly for all-calendar examples.'
    );
}
assertCalendarViewApi(
    str_contains($readmeSource, '`0` berücksichtigt alle in dieser Kalender Ansicht ausgewählten Kalender')
        && str_contains($readmeSource, '`hasReminder`')
        && str_contains($readmeSource, '`calendarName`'),
    'Calendar View PHP reference must explain the zero calendar filter and compact reminder/calendar fields.'
);
assertCalendarViewApi(
    str_contains($readmeSource, 'Einzeltermine sowie unterstützte Bereiche von Serienterminen')
        && str_contains($readmeSource, 'Termin** als einzelner Termin ins Ziel verschoben werden.')
        && str_contains($readmeSource, 'folgenden Termine** sowie die **Gesamte Serie** lassen sich verschieben')
        && str_contains($readmeSource, 'Zielkalender neue Serientermine anlegen kann.')
        && str_contains($readmeSource, 'Schlägt das Löschen im Quellkalender fehl')
        && str_contains($readmeSource, 'gerade im Ziel angelegten Termin wieder zu löschen. Nur wenn auch dieser Rollback')
        && str_contains($readmeSource, 'scheitert, bleibt die Zielkopie bestehen'),
    'Calendar View documentation must describe supported recurring moves and the target rollback behavior accurately.'
);

assertCalendarViewApi(
    str_contains($calendarModuleSource, "RegisterAttributeString('AnniversaryMetadata', '[]')")
        && str_contains($calendarModuleSource, 'public function GetAnniversaryList(int $Days = 0, string $Type = \'\'): string')
        && str_contains($calendarModuleSource, 'public function GetBirthdayList(int $Days = 0): string')
        && str_contains($calendarModuleSource, "'anniversaryType'")
        && str_contains($calendarModuleSource, "'anniversaryDate'")
        && str_contains($calendarModuleSource, "sprintf('%s (%dJ)'")
        && str_contains($calendarModuleSource, "'frequency' => 'YEARLY'"),
    'Calendar instances must persist provider-neutral annual-event metadata, filter it by type, and preserve the birthday compatibility API.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetAnniversaryList(int $CalendarInstanceID = 0, int $Days = 0, string $Type = \'\'): string')
        && str_contains($moduleSource, "IPSKAL_GetAnniversaryList(\$calendar['instanceId'], \$Days, \$type)")
        && str_contains($moduleSource, "\$entry['calendarInstanceId'] = \$calendar['instanceId'];")
        && str_contains($moduleSource, "\$entry['calendarName'] = \$calendar['name'];")
        && str_contains($moduleSource, "if (\$CalendarInstanceID !== 0 && \$calendar['instanceId'] !== \$CalendarInstanceID)")
        && str_contains($moduleSource, "return \$this->GetAnniversaryList(\$CalendarInstanceID, \$Days, 'birthday');"),
    'Calendar View must aggregate annual-event lists with calendar, day-window, and type filters while retaining GetBirthdayList().'
);

assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetDayAppointments(string $Date, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'return $this->GetAppointments($Date, $Date, $CalendarInstanceID);'),
    'Calendar View must expose a provider-independent GetDayAppointments PHP function with optional calendar filtering.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetAppointments(string $From, string $To, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'CalendarAppointmentRange::fromInclusiveDates($From, $To)')
        && str_contains($moduleSource, '$this->collectAppointmentsForRange($rangeStart, $rangeEnd)')
        && str_contains($moduleSource, '$this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID)'),
    'Calendar View must expose an inclusive provider-independent appointment range function with optional calendar filtering.'
);
assertCalendarViewApi(
    str_contains($moduleSource, '$event[\'calendarInstanceId\'] = $calendar[\'instanceId\'];')
        && str_contains($moduleSource, '$event[\'calendarName\'] = $calendar[\'name\'];')
        && str_contains($moduleSource, '$event[\'calendarColor\'] = $calendar[\'color\'];')
        && str_contains($moduleSource, '$event[\'canWrite\'] = $calendar[\'canWrite\'];'),
    'Calendar View appointment results must identify their source calendar.'
);

assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetDayAppointmentsCompact(string $Date, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'return $this->GetAppointmentsCompact($Date, $Date, $CalendarInstanceID);'),
    'Calendar View must expose a compact provider-independent day appointment function.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetAppointmentsCompact(string $From, string $To, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, '$this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID)')
        && str_contains($moduleSource, 'private function encodeCompactAppointmentList(array $appointments): string')
        && substr_count($moduleSource, '$this->encodeCompactAppointmentList(') >= 3,
    'Compact appointment APIs must share one provider-independent encoding path.'
);
assertCalendarViewApi(
    str_contains($moduleSource, "'summary'      =>")
        && str_contains($moduleSource, "'start'        =>")
        && str_contains($moduleSource, "'end'          =>")
        && str_contains($moduleSource, "'startTime'    =>")
        && str_contains($moduleSource, "'endTime'      =>")
        && str_contains($moduleSource, "'hasReminder'  =>")
        && str_contains($moduleSource, "'calendarName' =>")
        && str_contains($moduleSource, "Translate('All day')")
        && str_contains($moduleSource, "->format('Y-m-d');")
        && str_contains($moduleSource, "->modify('-1 day')->format('Y-m-d');")
        && str_contains($moduleSource, "->format('H:i');"),
    'Compact appointment results must expose script-friendly dates, reminder state and source calendar.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'bool $includeCompactMetadata = false')
        && str_contains($moduleSource, '$this->collectAppointmentsForRange($rangeStart, $rangeEnd, true)')
        && str_contains($moduleSource, "\$event['hasReminder'] = \$this->appointmentHasReminder(\$event, \$calendar);")
        && str_contains($moduleSource, 'private function appointmentHasReminder(array $appointment, array $calendar): bool'),
    'Compact appointment collection must resolve effective reminders with source calendar metadata.'
);

assertCalendarViewApi(
    str_contains($moduleSource, 'private function filterAppointmentsByCalendarInstanceId(array $appointments, int $CalendarInstanceID): array')
        && str_contains($moduleSource, 'if ($CalendarInstanceID === 0)')
        && str_contains($moduleSource, '(int) ($appointment[\'calendarInstanceId\'] ?? 0) === $CalendarInstanceID'),
    'Appointment APIs must optionally filter by selected calendar instance ID while zero keeps all calendars.'
);

assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetDayAppointmentCount(string $Date, int $CalendarInstanceID = 0): int')
        && str_contains($moduleSource, 'return $this->GetAppointmentCount($Date, $Date, $CalendarInstanceID);')
        && str_contains($moduleSource, 'public function GetAppointmentCount(string $From, string $To, int $CalendarInstanceID = 0): int'),
    'Calendar View must expose provider-independent appointment count functions with optional calendar filtering.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetRemainingDayAppointments(int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'public function GetRemainingDayAppointmentCount(int $CalendarInstanceID = 0): int')
        && str_contains($moduleSource, '$this->filterRemainingAppointments($appointments, $now->getTimestamp())'),
    'Calendar View must expose remaining-today appointment list and count functions.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetNextAppointment(int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'self::APPOINTMENT_LOOKAHEAD_DAYS')
        && str_contains($moduleSource, '$this->findNextAppointment($appointments, $now->getTimestamp())'),
    'Calendar View must expose the next cached appointment with optional calendar filtering.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetCurrentAppointments(int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, '$this->filterCurrentAppointments($appointments, $now->getTimestamp())')
        && str_contains($moduleSource, 'public function GetCurrentAppointmentCount(int $CalendarInstanceID = 0): int'),
    'Calendar View must expose appointments that are currently in progress and their count.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetUpcomingAppointments(int $Hours, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'public function GetUpcomingAppointmentsCompact(int $Hours, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'public function GetUpcomingAppointmentCount(int $Hours, int $CalendarInstanceID = 0): int')
        && str_contains($moduleSource, '$this->filterUpcomingAppointments(')
        && str_contains($moduleSource, '$this->validateUpcomingHours($Hours);')
        && substr_count($moduleSource, '$this->collectAppointmentsForRange($rangeStart, $rangeEnd, true)') >= 3,
    'Calendar View must expose full and compact upcoming appointment lists plus the count with one bounded hour window.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetNextAppointments(int $Count, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'public function GetNextAppointmentsCompact(int $Count, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'private function validateAppointmentCount(int $Count): void')
        && substr_count($moduleSource, '$this->validateAppointmentCount($Count);') >= 2
        && substr_count($moduleSource, "throw new InvalidArgumentException('Count must be between 1 and 1000.');") === 1
        && str_contains($moduleSource, 'array_slice($this->filterFutureAppointments($appointments, $now->getTimestamp()), 0, $Count)'),
    'Full and compact next-appointment APIs must share one count-validation path.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetDayReminders(string $Date, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'return $this->GetReminders($Date, $Date, $CalendarInstanceID);')
        && str_contains($moduleSource, 'public function GetReminders(string $From, string $To, int $CalendarInstanceID = 0): string'),
    'Calendar View must expose provider-neutral day and date-range reminder functions.'
);
assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetUpcomingReminders(int $Minutes, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'public function GetNextReminder(int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, 'public function GetDueReminders(int $ToleranceMinutes = 1, int $CalendarInstanceID = 0): string')
        && str_contains($moduleSource, '$this->collectRemindersForTimestampRange(')
        && str_contains($moduleSource, 'CalendarEventReminder::MAX_MINUTES_BEFORE_START'),
    'Calendar View must expose upcoming, next and due reminder functions using the provider-neutral reminder model.'
);
assertCalendarViewApi(
    str_contains($moduleSource, "'reminderId'")
        && str_contains($moduleSource, "'reminderMode'")
        && str_contains($moduleSource, "'minutesBeforeStart'")
        && str_contains($moduleSource, "'reminderTimestamp'")
        && str_contains($moduleSource, "'reminderDateTime'")
        && str_contains($moduleSource, "'reminderIndex'")
        && str_contains($moduleSource, "'reminderCount'"),
    'Reminder API results must expose stable IDs, effective lead time, trigger timestamps and per-event reminder positions.'
);
$normalizedModuleSource = preg_replace('/\s+/', ' ', $moduleSource) ?? $moduleSource;
assertCalendarViewApi(
    str_contains($moduleSource, "case 'LoadRange':")
        && str_contains($moduleSource, '$this->validateVisualizationActionRange($value);')
        && str_contains($moduleSource, 'private function validateVisualizationActionRange(mixed $value): void')
        && str_contains($moduleSource, '$this->Translate(\'The visualization request is invalid.\')')
        && str_contains($moduleSource, '$this->resolveVisualizationRange($range[0], $range[1]);'),
    'Explicit visualization range loads must reject malformed or oversized client ranges instead of falling back to bootstrap data.'
);

assertCalendarViewApi(
    str_contains($moduleSource, 'public function GetSelectedCalendars(): string')
        && str_contains($moduleSource, '$this->loadSelectedCalendars(true)')
        && str_contains($moduleSource, 'private function loadSelectedCalendars(bool $includeOperationalMetadata = false): array')
        && str_contains($moduleSource, "private const CALENDAR_ACCOUNT_MODULE_ID = '{966D6119-7FF3-5CA5-06C3-536FBF8100C4}';")
        && str_contains($moduleSource, 'private function calendarProviderKey(array $calendarInstance): string')
        && str_contains($normalizedModuleSource, "'timezone' => trim((string) (\$calendarStatus['timezone'] ?? ''))")
        && str_contains($normalizedModuleSource, "'canCreateRecurrence' => (bool) (\$calendarStatus['canCreateRecurrence'] ?? false)")
        && str_contains($normalizedModuleSource, "'canDeleteSeries' => (bool) (\$calendarStatus['canDeleteSeries'] ?? false)")
        && str_contains($normalizedModuleSource, "'maxReminders' => max(1, min(CalendarEventReminder::MAX_REMINDERS, (int) (\$calendarStatus['maxReminders'] ?? 1)))")
        && str_contains($moduleSource, "\$calendar['provider'] = \$this->calendarProviderKey(\$instance);")
        && str_contains($moduleSource, "\$calendar['lastSynchronization'] = max(0, (int) (\$calendarStatus['lastSynchronization'] ?? 0));")
        && str_contains($moduleSource, "\$calendar['status'] = (int) (\$instance['InstanceStatus'] ?? 0);")
        && str_contains($moduleSource, "\$calendar['lastError'] = trim((string) (\$calendarStatus['lastError'] ?? ''));")
        && str_contains($moduleSource, "0       => 'apple'")
        && str_contains($moduleSource, "1       => 'caldav'")
        && str_contains($moduleSource, "2       => 'google'")
        && str_contains($moduleSource, "3       => 'microsoft'")
        && str_contains($moduleSource, "4       => 'ics'"),
    'Calendar View must expose selected calendar capabilities and opt-in provider, synchronization, status, and error metadata.'
);

require_once __DIR__ . '/stubs/ModuleStrictStubs.php';
require_once __DIR__ . '/../Kalender Ansicht/module.php';

$compactMethod = new ReflectionMethod(CalendarView::class, 'compactAppointments');
$compactMethod->setAccessible(true);
$hasReminderMethod = new ReflectionMethod(CalendarView::class, 'appointmentHasReminder');
$hasReminderMethod->setAccessible(true);
$filterMethod = new ReflectionMethod(CalendarView::class, 'filterAppointmentsByCalendarInstanceId');
$filterMethod->setAccessible(true);
$remainingMethod = new ReflectionMethod(CalendarView::class, 'filterRemainingAppointments');
$remainingMethod->setAccessible(true);
$currentMethod = new ReflectionMethod(CalendarView::class, 'filterCurrentAppointments');
$currentMethod->setAccessible(true);
$nextMethod = new ReflectionMethod(CalendarView::class, 'findNextAppointment');
$nextMethod->setAccessible(true);
$futureMethod = new ReflectionMethod(CalendarView::class, 'filterFutureAppointments');
$futureMethod->setAccessible(true);
$upcomingMethod = new ReflectionMethod(CalendarView::class, 'filterUpcomingAppointments');
$upcomingMethod->setAccessible(true);
$validateUpcomingHoursMethod = new ReflectionMethod(CalendarView::class, 'validateUpcomingHours');
$validateUpcomingHoursMethod->setAccessible(true);
$buildReminderRecordMethod = new ReflectionMethod(CalendarView::class, 'buildReminderRecord');
$buildReminderRecordMethod->setAccessible(true);
$buildReminderRecordsMethod = new ReflectionMethod(CalendarView::class, 'buildReminderRecords');
$buildReminderRecordsMethod->setAccessible(true);
$validateReminderMinutesWindowMethod = new ReflectionMethod(CalendarView::class, 'validateReminderMinutesWindow');
$validateReminderMinutesWindowMethod->setAccessible(true);
$validateVisualizationActionRangeMethod = new ReflectionMethod(CalendarView::class, 'validateVisualizationActionRange');
$validateVisualizationActionRangeMethod->setAccessible(true);
$calendarView = new CalendarView(1);
$previousTimezone = date_default_timezone_get();
date_default_timezone_set('Europe/Berlin');

try {
    $compact = $compactMethod->invoke($calendarView, [[
        'summary'        => 'Timed event',
        'start'          => '2026-08-12T09:00:00+02:00',
        'end'            => '2026-08-12T10:30:00+02:00',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T09:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-12T10:30:00+02:00'))->getTimestamp(),
        'allDay'         => false,
        'hasReminder'    => true,
        'calendarName'   => 'Work'
    ], [
        'summary'        => 'All-day event',
        'start'          => '2026-08-12',
        'end'            => '2026-08-13',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T00:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-13T00:00:00+02:00'))->getTimestamp(),
        'allDay'         => true,
        'hasReminder'    => false,
        'calendarName'   => 'Private'
    ], [
        'summary'        => 'Overnight event',
        'start'          => '2026-08-12T23:30:00+02:00',
        'end'            => '2026-08-13T00:30:00+02:00',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T23:30:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-13T00:30:00+02:00'))->getTimestamp(),
        'allDay'         => false,
        'hasReminder'    => true,
        'calendarName'   => 'Work'
    ], [
        'summary'        => 'Multi-day all-day event',
        'start'          => '2026-08-12',
        'end'            => '2026-08-15',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T00:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-15T00:00:00+02:00'))->getTimestamp(),
        'allDay'         => true,
        'hasReminder'    => false,
        'calendarName'   => 'Holidays'
    ]]);
} finally {
    date_default_timezone_set($previousTimezone);
}

assertCalendarViewApi(
    $hasReminderMethod->invoke(
        $calendarView,
        ['reminder' => CalendarEventReminder::custom(15)],
        []
    ) === true,
    'A custom event reminder must set the compact reminder flag.'
);
assertCalendarViewApi(
    $hasReminderMethod->invoke(
        $calendarView,
        ['reminder' => CalendarEventReminder::none()],
        []
    ) === false,
    'A disabled event reminder must clear the compact reminder flag.'
);
assertCalendarViewApi(
    $hasReminderMethod->invoke(
        $calendarView,
        ['reminder' => CalendarEventReminder::providerDefault()],
        [
            'canUseDefaultReminder' => true,
            'defaultReminder'       => CalendarEventReminder::custom(30)
        ]
    ) === true,
    'An active calendar-default reminder must set the compact reminder flag.'
);
assertCalendarViewApi(
    $hasReminderMethod->invoke(
        $calendarView,
        ['reminder' => CalendarEventReminder::providerDefault()],
        [
            'canUseDefaultReminder' => true,
            'defaultReminder'       => CalendarEventReminder::none()
        ]
    ) === false,
    'A disabled calendar-default reminder must clear the compact reminder flag.'
);
assertCalendarViewApi(
    $hasReminderMethod->invoke(
        $calendarView,
        ['reminder' => CalendarEventReminder::complex()],
        []
    ) === true,
    'A complex provider reminder must still report that an event reminder exists.'
);

$filterSource = [
    ['summary' => 'Calendar A', 'calendarInstanceId' => 111],
    ['summary' => 'Calendar B', 'calendarInstanceId' => 222],
    ['summary' => 'Calendar A second', 'calendarInstanceId' => 111]
];
assertCalendarViewApi(
    $filterMethod->invoke($calendarView, $filterSource, 0) === $filterSource,
    'Calendar instance ID zero must keep all compact appointments.'
);
assertCalendarViewApi(
    $filterMethod->invoke($calendarView, $filterSource, 111) === [$filterSource[0], $filterSource[2]],
    'A compact calendar filter must keep only appointments from the requested calendar instance.'
);
assertCalendarViewApi(
    $filterMethod->invoke($calendarView, $filterSource, 999) === [],
    'An unknown compact calendar filter must return an empty appointment list.'
);

assertCalendarViewApi(
    $compact[0] === [
        'summary'      => 'Timed event',
        'start'        => '2026-08-12',
        'end'          => '2026-08-12',
        'startTime'    => '09:00',
        'endTime'      => '10:30',
        'hasReminder'  => true,
        'calendarName' => 'Work'
    ],
    'Compact timed appointments must expose local dates and readable start and end times.'
);
assertCalendarViewApi(
    $compact[1] === [
        'summary'      => 'All-day event',
        'start'        => '2026-08-12',
        'end'          => '2026-08-12',
        'startTime'    => 'All day',
        'endTime'      => '',
        'hasReminder'  => false,
        'calendarName' => 'Private'
    ],
    'Compact all-day appointments must expose the visible inclusive end date and not invent clock times.'
);
assertCalendarViewApi(
    $compact[2] === [
        'summary'      => 'Overnight event',
        'start'        => '2026-08-12',
        'end'          => '2026-08-13',
        'startTime'    => '23:30',
        'endTime'      => '00:30',
        'hasReminder'  => true,
        'calendarName' => 'Work'
    ],
    'Compact overnight appointments must keep separate local start and end dates.'
);
assertCalendarViewApi(
    $compact[3] === [
        'summary'      => 'Multi-day all-day event',
        'start'        => '2026-08-12',
        'end'          => '2026-08-14',
        'startTime'    => 'All day',
        'endTime'      => '',
        'hasReminder'  => false,
        'calendarName' => 'Holidays'
    ],
    'Compact multi-day all-day appointments must convert the exclusive provider end to the visible inclusive end date.'
);

$now = (new DateTimeImmutable('2026-08-12T12:00:00+02:00'))->getTimestamp();
$stateAppointments = [
    [
        'summary'        => 'Ended',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T09:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-12T10:00:00+02:00'))->getTimestamp()
    ],
    [
        'summary'        => 'Current',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T11:30:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-12T12:30:00+02:00'))->getTimestamp()
    ],
    [
        'summary'        => 'All day',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T00:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-13T00:00:00+02:00'))->getTimestamp(),
        'allDay'         => true
    ],
    [
        'summary'        => 'Next',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T14:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-12T15:00:00+02:00'))->getTimestamp()
    ],
    [
        'summary'        => 'Later',
        'startTimestamp' => (new DateTimeImmutable('2026-08-12T16:00:00+02:00'))->getTimestamp(),
        'endTimestamp'   => (new DateTimeImmutable('2026-08-12T17:00:00+02:00'))->getTimestamp()
    ]
];

$remaining = $remainingMethod->invoke($calendarView, $stateAppointments, $now);
assertCalendarViewApi(
    array_column($remaining, 'summary') === ['Current', 'All day', 'Next', 'Later'],
    'Remaining-day filtering must keep current, all-day and future appointments while excluding ended appointments.'
);

$current = $currentMethod->invoke($calendarView, $stateAppointments, $now);
assertCalendarViewApi(
    array_column($current, 'summary') === ['Current', 'All day'],
    'Current appointment filtering must include timed and all-day appointments covering the supplied timestamp.'
);

$chronologicalAppointments = [
    $stateAppointments[2],
    $stateAppointments[0],
    $stateAppointments[1],
    $stateAppointments[3],
    $stateAppointments[4]
];
$next = $nextMethod->invoke($calendarView, $chronologicalAppointments, $now);
assertCalendarViewApi(
    is_array($next) && ($next['summary'] ?? '') === 'Next',
    'Next appointment lookup must skip ended and currently running appointments and return the next future start.'
);
assertCalendarViewApi(
    $nextMethod->invoke($calendarView, [$stateAppointments[0], $stateAppointments[1]], $now) === null,
    'Next appointment lookup must return null when no future appointment is available.'
);

$future = $futureMethod->invoke($calendarView, $chronologicalAppointments, $now);
assertCalendarViewApi(
    array_column($future, 'summary') === ['Next', 'Later'],
    'Future appointment filtering must exclude ended and currently running appointments.'
);

$upcomingUntil = (new DateTimeImmutable('2026-08-12T15:00:00+02:00'))->getTimestamp();
$upcoming = $upcomingMethod->invoke($calendarView, $chronologicalAppointments, $now, $upcomingUntil);
assertCalendarViewApi(
    array_column($upcoming, 'summary') === ['Next'],
    'Upcoming appointment filtering must keep only future starts inside the requested hour window.'
);

$validateUpcomingHoursMethod->invoke($calendarView, 1);
$validateUpcomingHoursMethod->invoke($calendarView, 1095 * 24);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateUpcomingHoursMethod->invoke($calendarView, 0),
    'Upcoming appointment queries must reject a zero-hour window.'
);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateUpcomingHoursMethod->invoke($calendarView, (1095 * 24) + 1),
    'Upcoming appointment queries must reject windows beyond the maximum synchronized future range.'
);

$previousReminderTimezone = date_default_timezone_get();
date_default_timezone_set('Europe/Berlin');

$reminderCalendar = [
    'instanceId'            => 111,
    'name'                  => 'Calendar A',
    'color'                 => '#123456',
    'canUseDefaultReminder' => true,
    'maxReminders'          => 5,
    'defaultReminder'       => [
        'mode'               => 'custom',
        'minutesBeforeStart' => 15,
        'editable'           => true
    ]
];
$reminderStart = (new DateTimeImmutable('2026-08-12T10:00:00+02:00'))->getTimestamp();
$customReminderAppointment = [
    'uid'                => 'custom-reminder@example.com',
    'summary'            => 'Custom reminder',
    'calendarInstanceId' => 111,
    'calendarName'       => 'Calendar A',
    'calendarColor'      => '#123456',
    'start'              => '2026-08-12T10:00:00+02:00',
    'startTimestamp'     => $reminderStart,
    'allDay'             => false,
    'location'           => 'Office',
    'reminder'           => [
        'mode'               => 'custom',
        'minutesBeforeStart' => 30,
        'editable'           => true
    ]
];
$customReminderRecord = $buildReminderRecordMethod->invoke(
    $calendarView,
    $customReminderAppointment,
    $reminderCalendar
);
assertCalendarViewApi(
    is_array($customReminderRecord)
        && ($customReminderRecord['summary'] ?? '') === 'Custom reminder'
        && ($customReminderRecord['reminderMode'] ?? '') === 'custom'
        && ($customReminderRecord['minutesBeforeStart'] ?? -1) === 30
        && ($customReminderRecord['reminderTimestamp'] ?? 0) === $reminderStart - (30 * 60)
        && ($customReminderRecord['reminderDateTime'] ?? '') === '2026-08-12T09:30:00+02:00'
        && strlen((string) ($customReminderRecord['reminderId'] ?? '')) === 64,
    'Custom reminder records must expose their exact provider-neutral trigger.'
);
assertCalendarViewApi(
    $buildReminderRecordMethod->invoke($calendarView, $customReminderAppointment, $reminderCalendar)
        === $customReminderRecord,
    'Reminder IDs and normalized output must remain stable for unchanged appointment data.'
);

$multipleReminderAppointment = $customReminderAppointment;
$multipleReminderAppointment['uid'] = 'multiple-reminders@example.com';
$multipleReminderAppointment['summary'] = 'Multiple reminders';
$multipleReminderAppointment['reminder'] = [
    'mode'               => 'multiple',
    'minutesBeforeStart' => null,
    'reminders'          => [
        ['minutesBeforeStart' => 10],
        ['minutesBeforeStart' => 60]
    ],
    'editable'           => true
];
$multipleReminderRecords = $buildReminderRecordsMethod->invoke(
    $calendarView,
    $multipleReminderAppointment,
    $reminderCalendar
);
assertCalendarViewApi(
    is_array($multipleReminderRecords)
        && count($multipleReminderRecords) === 2
        && ($multipleReminderRecords[0]['reminderMode'] ?? '') === 'multiple'
        && ($multipleReminderRecords[0]['minutesBeforeStart'] ?? -1) === 10
        && ($multipleReminderRecords[0]['reminderIndex'] ?? -1) === 0
        && ($multipleReminderRecords[0]['reminderCount'] ?? 0) === 2
        && ($multipleReminderRecords[1]['minutesBeforeStart'] ?? -1) === 60
        && ($multipleReminderRecords[1]['reminderIndex'] ?? -1) === 1
        && ($multipleReminderRecords[1]['reminderCount'] ?? 0) === 2
        && ($multipleReminderRecords[0]['reminderTimestamp'] ?? 0) === $reminderStart - (10 * 60)
        && ($multipleReminderRecords[1]['reminderTimestamp'] ?? 0) === $reminderStart - (60 * 60)
        && ($multipleReminderRecords[0]['reminderId'] ?? '') !== ($multipleReminderRecords[1]['reminderId'] ?? ''),
    'One event with multiple reminders must produce one stable PHP API record per exact trigger.'
);
assertCalendarViewApi(
    $buildReminderRecordsMethod->invoke($calendarView, $multipleReminderAppointment, $reminderCalendar)
        === $multipleReminderRecords,
    'Multiple-reminder API IDs and ordering must remain stable for unchanged appointment data.'
);

$defaultReminderAppointment = $customReminderAppointment;
$defaultReminderAppointment['uid'] = 'default-reminder@example.com';
$defaultReminderAppointment['summary'] = 'Default reminder';
$defaultReminderAppointment['reminder'] = [
    'mode'               => 'default',
    'minutesBeforeStart' => null,
    'editable'           => true
];
$defaultReminderRecord = $buildReminderRecordMethod->invoke(
    $calendarView,
    $defaultReminderAppointment,
    $reminderCalendar
);
assertCalendarViewApi(
    is_array($defaultReminderRecord)
        && ($defaultReminderRecord['reminderMode'] ?? '') === 'default'
        && ($defaultReminderRecord['minutesBeforeStart'] ?? -1) === 15
        && ($defaultReminderRecord['reminderTimestamp'] ?? 0) === $reminderStart - (15 * 60),
    'Calendar-default reminders must resolve to the selected calendar default when it has one exact trigger.'
);

$multipleDefaultReminderCalendar = $reminderCalendar;
$multipleDefaultReminderCalendar['defaultReminder'] = [
    'mode'               => 'multiple',
    'minutesBeforeStart' => null,
    'reminders'          => [
        ['minutesBeforeStart' => 5],
        ['minutesBeforeStart' => 45]
    ],
    'editable'           => true
];
$multipleDefaultReminderRecords = $buildReminderRecordsMethod->invoke(
    $calendarView,
    $defaultReminderAppointment,
    $multipleDefaultReminderCalendar
);
assertCalendarViewApi(
    count($multipleDefaultReminderRecords) === 2
        && array_column($multipleDefaultReminderRecords, 'minutesBeforeStart') === [5, 45]
        && array_column($multipleDefaultReminderRecords, 'reminderMode') === ['default', 'default'],
    'Calendar-default reminder APIs must emit every exact trigger when the Google calendar default contains multiple reminders.'
);

$noneReminderAppointment = $customReminderAppointment;
$noneReminderAppointment['reminder'] = [
    'mode'               => 'none',
    'minutesBeforeStart' => null,
    'editable'           => true
];
assertCalendarViewApi(
    $buildReminderRecordMethod->invoke($calendarView, $noneReminderAppointment, $reminderCalendar) === null,
    'Disabled reminders must not produce due-reminder records.'
);

$complexReminderAppointment = $customReminderAppointment;
$complexReminderAppointment['reminder'] = [
    'mode'               => 'complex',
    'minutesBeforeStart' => null,
    'editable'           => false
];
assertCalendarViewApi(
    $buildReminderRecordMethod->invoke($calendarView, $complexReminderAppointment, $reminderCalendar) === null,
    'Complex reminder settings without one exact provider-neutral trigger must not be guessed.'
);

$noDefaultReminderCalendar = $reminderCalendar;
$noDefaultReminderCalendar['defaultReminder'] = [
    'mode'               => 'none',
    'minutesBeforeStart' => null,
    'editable'           => true
];
assertCalendarViewApi(
    $buildReminderRecordMethod->invoke(
        $calendarView,
        $defaultReminderAppointment,
        $noDefaultReminderCalendar
    ) === null,
    'A calendar default without an active reminder must not create an artificial reminder trigger.'
);

$rangeStartTimestamp = (new DateTimeImmutable('2026-08-01T00:00:00+02:00'))->getTimestamp();
$validateVisualizationActionRangeMethod->invoke(
    $calendarView,
    [
        '_viewRange' => [
            'start' => $rangeStartTimestamp,
            'end'   => $rangeStartTimestamp + (380 * 86400)
        ]
    ]
);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateVisualizationActionRangeMethod->invoke($calendarView, []),
    'LoadRange validation must reject a missing explicit view range.'
);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateVisualizationActionRangeMethod->invoke(
        $calendarView,
        [
            '_viewRange' => [
                'start' => $rangeStartTimestamp,
                'end'   => $rangeStartTimestamp
            ]
        ]
    ),
    'LoadRange validation must reject empty or reversed view ranges.'
);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateVisualizationActionRangeMethod->invoke(
        $calendarView,
        [
            '_viewRange' => [
                'start' => $rangeStartTimestamp,
                'end'   => $rangeStartTimestamp + (380 * 86400) + 1
            ]
        ]
    ),
    'LoadRange validation must reject ranges beyond the visualization safety limit.'
);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateVisualizationActionRangeMethod->invoke($calendarView, '{invalid json'),
    'LoadRange validation must reject malformed JSON action values.'
);

$validateReminderMinutesWindowMethod->invoke($calendarView, 1);
$validateReminderMinutesWindowMethod->invoke($calendarView, 1095 * 24 * 60);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateReminderMinutesWindowMethod->invoke($calendarView, 0),
    'Reminder windows must reject zero minutes.'
);
assertCalendarViewApiThrows(
    static fn (): mixed => $validateReminderMinutesWindowMethod->invoke(
        $calendarView,
        (1095 * 24 * 60) + 1
    ),
    'Reminder windows must reject values beyond the synchronized future range.'
);

date_default_timezone_set($previousReminderTimezone);

fwrite(STDOUT, 'Calendar View PHP API checks passed.' . PHP_EOL);
