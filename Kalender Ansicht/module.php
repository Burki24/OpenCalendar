<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\ConfigurationFormHelper;
use Burki24\SymconModuleHelper\IPSViewHTMLPageHelper;
use Burki24\SymconModuleHelper\IPSViewStyleConfigurationHelper;
use Burki24\SymconModuleHelper\VariableHelper;
use Burki24\SymconModuleHelper\VisualizationAssetHelper;
use Burki24\SymconModuleHelper\VisualizationThemeHelper;
use IPSKalender\CalendarAppointmentRange;
use IPSKalender\CalendarEventReminder;

require_once __DIR__ . '/../libs/CalendarAppointmentRange.php';
require_once __DIR__ . '/../libs/CalendarEventReminder.php';
require_once __DIR__ . '/../libs/helper/ConfigurationFormHelper.php';
require_once __DIR__ . '/../libs/helper/IPSViewHTMLPageHelper.php';
require_once __DIR__ . '/../libs/helper/IPSViewStyleConfigurationHelper.php';
require_once __DIR__ . '/../libs/helper/VariableHelper.php';
require_once __DIR__ . '/../libs/helper/VisualizationAssetHelper.php';
require_once __DIR__ . '/../libs/helper/VisualizationThemeHelper.php';

class CalendarView extends IPSModuleStrict
{
    use ConfigurationFormHelper;
    use IPSViewHTMLPageHelper;
    use IPSViewStyleConfigurationHelper;
    use VariableHelper;
    use VisualizationAssetHelper;
    use VisualizationThemeHelper;

    private const CALENDAR_MODULE_ID = '{227B63E4-4223-316B-76E9-FD3849689562}';
    private const CALENDAR_ACCOUNT_MODULE_ID = '{966D6119-7FF3-5CA5-06C3-536FBF8100C4}';
    private const INITIALIZATION_DELAY_MS = 5_000;
    private const APPOINTMENT_LOOKAHEAD_DAYS = 1095;
    private const VISUALIZATION_BOOTSTRAP_PAST_DAYS = 7;
    private const VISUALIZATION_BOOTSTRAP_FUTURE_DAYS = 42;
    private const VISUALIZATION_MAX_RANGE_DAYS = 370;
    private const ATTRIBUTE_IPSVIEW_TOKEN_1 = 'IPSViewToken1';
    private const ATTRIBUTE_IPSVIEW_TOKEN_2 = 'IPSViewToken2';
    private const ATTRIBUTE_IPSVIEW_TOKEN_3 = 'IPSViewToken3';
    private const ATTRIBUTE_IPSVIEW_TOKEN_4 = 'IPSViewToken4';

    private const STATUS_NO_CALENDARS = 201;
    private const STATUS_INVALID_CONFIGURATION = 202;

    private const LEGACY_IPSVIEW_STRING_COLOR_PROPERTIES = [
        'IPSViewPageColor'          => 'IPSViewPageColorValue',
        'IPSViewSurfaceColor'       => 'IPSViewSurfaceColorValue',
        'IPSViewSurfaceStrongColor' => 'IPSViewSurfaceStrongColorValue',
        'IPSViewTextColor'          => 'IPSViewTextColorValue',
        'IPSViewMutedTextColor'     => 'IPSViewMutedTextColorValue',
        'IPSViewAccentColor'        => 'IPSViewAccentColorValue',
        'IPSViewSuccessColor'       => 'IPSViewSuccessColorValue',
        'IPSViewWarningColor'       => 'IPSViewWarningColorValue',
        'IPSViewDangerColor'        => 'IPSViewDangerColorValue'
    ];

    private const LEGACY_IPSVIEW_STYLE_PROPERTIES = [
        'IPSViewPageColorValue'          => [
            'IPSViewStyleViewBackgroundColor',
            'IPSViewStylePageBackgroundColor'
        ],
        'IPSViewSurfaceColorValue'       => [
            'IPSViewStyleControlBackgroundColor',
            'IPSViewStyleControlInactiveBackgroundColor'
        ],
        'IPSViewSurfaceStrongColorValue' => [
            'IPSViewStyleLabelBackgroundColor',
            'IPSViewStyleControlActiveBackgroundColor',
            'IPSViewStylePopupBackgroundColor'
        ],
        'IPSViewTextColorValue'          => [
            'IPSViewStyleTextColor',
            'IPSViewStyleTextActiveColor',
            'IPSViewStyleLabelTextColor',
            'IPSViewStyleIconColor'
        ],
        'IPSViewMutedTextColorValue'     => [
            'IPSViewStyleTextInactiveColor'
        ],
        'IPSViewAccentColorValue'        => [
            'IPSViewStyleAccentColor',
            'IPSViewStyleInformationColor'
        ],
        'IPSViewSuccessColorValue'       => [
            'IPSViewStylePositiveColor'
        ],
        'IPSViewWarningColorValue'       => [
            'IPSViewStyleWarningColor'
        ],
        'IPSViewDangerColorValue'        => [
            'IPSViewStyleCriticalColor'
        ]
    ];

    /**
     * Registers visualization properties, runtime attributes, and initialization state.
     */
    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterPropertyString('Calendars', '[]');
        $this->RegisterPropertyInteger('DefaultView', 0);
        $this->RegisterPropertyInteger('TileWeekOrientation', 0);
        $this->RegisterPropertyInteger('TileFontScale', 100);
        $this->RegisterPropertyInteger('PastDays', 0);
        $this->RegisterPropertyInteger('FutureDays', 31);
        $this->RegisterPropertyInteger('MaxEvents', 250);
        $this->RegisterPropertyInteger('AgendaPeriodDays', 14);
        $this->RegisterPropertyInteger('ListPeriodDays', 14);
        $this->RegisterPropertyInteger('ThreeDaysPeriodDays', 3);
        $this->RegisterPropertyInteger('WeekPeriodWeeks', 1);
        $this->RegisterPropertyInteger('MonthPeriodMonths', 1);
        $this->RegisterPropertyBoolean('ShowWeekends', true);
        $this->RegisterPropertyBoolean('ShowAgendaEventCount', true);
        $this->RegisterPropertyBoolean('ShowThreeDaysEventCount', true);
        $this->RegisterPropertyBoolean('ShowWeekEventCount', true);
        $this->RegisterPropertyBoolean('ShowAgendaCalendarWeek', false);
        $this->RegisterPropertyBoolean('ShowListCalendarWeek', false);
        $this->RegisterPropertyBoolean('ShowThreeDaysCalendarWeek', false);
        $this->RegisterPropertyBoolean('ShowWeekCalendarWeek', true);
        $this->RegisterPropertyBoolean('ShowMonthCalendarWeek', false);
        $this->RegisterPropertyBoolean('ShowAgendaDayOfYear', true);
        $this->RegisterPropertyBoolean('ShowListDayOfYear', false);
        $this->RegisterPropertyBoolean('ShowThreeDaysDayOfYear', true);
        $this->RegisterPropertyBoolean('ShowWeekDayOfYear', true);
        $this->RegisterPropertyBoolean('ShowMonthDayOfYear', true);
        $this->RegisterPropertyBoolean('ShowListDate', true);
        $this->RegisterPropertyBoolean('ShowListStart', true);
        $this->RegisterPropertyBoolean('ShowListEnd', true);
        $this->RegisterPropertyBoolean('ShowListTitle', true);
        $this->RegisterPropertyBoolean('ShowListCalendarName', true);
        $this->RegisterPropertyBoolean('ShowListAnniversaryType', true);
        $this->RegisterPropertyBoolean('ShowListLocation', false);
        $this->RegisterPropertyBoolean('ShowListDescription', false);
        $this->RegisterPropertyBoolean('ShowListControls', true);
        $this->RegisterPropertyBoolean('ShowCalendarName', true);
        $this->RegisterPropertyBoolean('ShowAnniversaryType', true);
        $this->RegisterPropertyBoolean('ShowLocation', true);
        $this->RegisterPropertyBoolean('ShowDescription', false);
        $this->RegisterIPSViewHTMLPageProperties();
        $this->RegisterPropertyInteger('IPSViewColorBarWidth', 7);
        $this->RegisterPropertyInteger('IPSViewWeekOrientation', 0);
        $this->RegisterIPSViewStyleProperties();
        $this->RegisterAttributeBoolean('RuntimeReady', false);
        $this->RegisterAttributeString('CalendarSelectionBackup', '[]');
        $this->RegisterAttributeInteger(self::ATTRIBUTE_IPSVIEW_TOKEN_1, 0);
        $this->RegisterAttributeInteger(self::ATTRIBUTE_IPSVIEW_TOKEN_2, 0);
        $this->RegisterAttributeInteger(self::ATTRIBUTE_IPSVIEW_TOKEN_3, 0);
        $this->RegisterAttributeInteger(self::ATTRIBUTE_IPSVIEW_TOKEN_4, 0);
        $this->ensureIPSViewToken();

        if (method_exists($this, 'RegisterHook')) {
            $this->RegisterHook($this->ipsViewHookAddress());
        }

        $this->SetVisualizationType(1);
        $this->RegisterTimer('InitializationTimer', 0, 'IPSKALVIEW_Initialize($_IPS[\'TARGET\']);');
    }

    /**
     * Builds the visualization configuration form and restores a backed-up calendar selection when needed.
     *
     * @return string JSON-encoded configuration form.
     */
    public function GetConfigurationForm(): string
    {
        $form = $this->LoadConfigurationForm();
        $configured = $this->decodeCalendarConfiguration($this->ReadPropertyString('Calendars'));
        if ($configured === []) {
            $backup = $this->decodeCalendarConfiguration($this->ReadAttributeString('CalendarSelectionBackup'));
            if ($backup !== []) {
                foreach ($form['elements'] as &$element) {
                    if (($element['name'] ?? '') === 'Calendars') {
                        $element['values'] = $backup;
                        break;
                    }
                }
                unset($element);
            }
        }
        if (isset($form['elements']) && is_array($form['elements'])) {
            $this->InsertIPSViewHTMLPageFormItems($form['elements']);
            $this->InsertIPSViewStyleFormItems($form['elements'], colorWidth: '220px');
        }

        return $this->EncodeConfigurationForm($form);
    }

    /**
     * Migrates legacy visualization settings to the current per-view and shared style properties.
     */
    public function Migrate(string $JSONData): string
    {
        parent::Migrate($JSONData);

        try {
            $persistence = json_decode($JSONData, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }

        if (
            !is_array($persistence)
            || !isset($persistence['configuration'])
            || !is_array($persistence['configuration'])
        ) {
            return '';
        }

        $configuration = &$persistence['configuration'];
        $changed = false;

        if (array_key_exists('ShowDayOfYear', $configuration)) {
            $legacyShowDayOfYear = (bool) $configuration['ShowDayOfYear'];
            foreach ([
                'ShowAgendaDayOfYear',
                'ShowThreeDaysDayOfYear',
                'ShowWeekDayOfYear',
                'ShowMonthDayOfYear'
            ] as $dayOfYearProperty) {
                if (!array_key_exists($dayOfYearProperty, $configuration)) {
                    $configuration[$dayOfYearProperty] = $legacyShowDayOfYear;
                }
            }
            unset($configuration['ShowDayOfYear']);
            $changed = true;
        }

        foreach (self::LEGACY_IPSVIEW_STRING_COLOR_PROPERTIES as $legacyProperty => $integerProperty) {
            if (!array_key_exists($legacyProperty, $configuration)) {
                continue;
            }

            $legacyValue = $configuration[$legacyProperty];
            if (is_string($legacyValue) && preg_match('/^#?([0-9a-fA-F]{6})$/', trim($legacyValue), $matches)) {
                $configuration[$integerProperty] = hexdec($matches[1]);
            }

            unset($configuration[$legacyProperty]);
            $changed = true;
        }

        $legacyTheme = $configuration['IPSViewTheme'] ?? null;
        if (is_int($legacyTheme) && !array_key_exists('IPSViewStyleSource', $configuration)) {
            $configuration['IPSViewStyleSource'] = match ($legacyTheme) {
                1       => self::IPSVIEW_STYLE_SOURCE_LIGHT,
                2       => self::IPSVIEW_STYLE_SOURCE_DARK,
                default => self::IPSVIEW_STYLE_SOURCE_CUSTOM
            };
        }
        if (array_key_exists('IPSViewTheme', $configuration)) {
            unset($configuration['IPSViewTheme']);
            $changed = true;
        }

        foreach ([
            'IPSViewTransparent' => 'IPSViewStyleTransparentBackground',
            'IPSViewFontScale'   => 'IPSViewStyleFontScale'
        ] as $legacyProperty => $styleProperty) {
            if (!array_key_exists($legacyProperty, $configuration)) {
                continue;
            }

            if (!array_key_exists($styleProperty, $configuration)) {
                $configuration[$styleProperty] = $configuration[$legacyProperty];
            }
            unset($configuration[$legacyProperty]);
            $changed = true;
        }

        foreach (self::LEGACY_IPSVIEW_STYLE_PROPERTIES as $legacyProperty => $styleProperties) {
            if (!array_key_exists($legacyProperty, $configuration)) {
                continue;
            }

            $value = $configuration[$legacyProperty];
            if (is_int($value) && $value >= 0 && $value <= 0xFFFFFF) {
                foreach ($styleProperties as $styleProperty) {
                    if (!array_key_exists($styleProperty, $configuration)) {
                        $configuration[$styleProperty] = $value;
                    }
                }
            }

            unset($configuration[$legacyProperty]);
            $changed = true;
        }

        if (!$changed) {
            return '';
        }

        try {
            return json_encode(
                $persistence,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            return '';
        }
    }

    /**
     * Applies visualization settings and schedules runtime initialization.
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $preservedIPSViewHTML = $this->existingIPSViewHTML();
        $this->ensureIPSViewToken();
        $this->WriteAttributeBoolean('RuntimeReady', false);
        $configured = $this->decodeCalendarConfiguration($this->ReadPropertyString('Calendars'));
        if ($configured !== []) {
            $this->storeCalendarSelectionBackup($configured);
        }
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterIPSViewStyleMediaMessages();
        $this->SetTimerInterval('InitializationTimer', 0);
        $this->MaintainIPSViewHTMLVariable(
            'IPSViewCalendar',
            $this->Translate('IPSView calendar'),
            10,
            $preservedIPSViewHTML
        );

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->scheduleInitialization();
    }

    /**
     * Initializes calendar subscriptions and broadcasts the initial visualization state.
     *
     * @return bool True when initialization was processed.
     */
    public function Initialize(): bool
    {
        $this->SetTimerInterval('InitializationTimer', 0);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return false;
        }

        $this->recoverCalendarSelectionFromMessages();
        foreach ($this->GetMessageList() as $senderId => $messageIds) {
            foreach ($messageIds as $messageId) {
                if ((int) $senderId === 0 && (int) $messageId === IPS_KERNELSTARTED) {
                    continue;
                }
                $this->UnregisterMessage($senderId, $messageId);
            }
        }
        $this->RegisterIPSViewStyleMediaMessages();

        $this->WriteAttributeBoolean('RuntimeReady', true);
        try {
            $calendars = $this->loadSelectedCalendars();
            foreach ($calendars as $calendar) {
                $instanceId = $calendar['instanceId'];
                $this->RegisterMessage($instanceId, OM_CHANGENAME);
                $synchronizationVariableId = $this->GetVariableIDByIdent('LastSynchronization', $instanceId);
                if ($synchronizationVariableId > 0) {
                    $this->RegisterMessage($synchronizationVariableId, VM_UPDATE);
                }
            }

            $this->SetStatus($calendars === [] ? self::STATUS_NO_CALENDARS : IS_ACTIVE);
        } catch (Throwable $exception) {
            $this->SendDebug('Configuration', $exception->getMessage(), 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
        }

        $this->broadcastState(null, true);

        return true;
    }

    /**
     * Reacts to kernel, calendar-name, and synchronization updates.
     *
     * @param array<int, mixed> $Data Message payload supplied by Symcon.
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($SenderID === 0 && $Message === IPS_KERNELSTARTED) {
            $this->scheduleInitialization();
            return;
        }
        if ($this->IsIPSViewStyleMediaUpdate($SenderID, $Message)) {
            if ($this->isRuntimeReady()) {
                $this->broadcastState(null, true);
            }

            return;
        }
        if (!$this->isRuntimeReady()) {
            return;
        }

        $this->broadcastStateInvalidation();
    }

    /**
     * Renders the HTML used by the native Symcon visualization tile.
     *
     * @return string Rendered calendar HTML.
     */
    public function GetVisualizationTile(): string
    {
        return $this->renderCalendarHtml($this->buildState(), false);
    }

    /**
     * Handles configuration-form and interactive visualization actions.
     *
     * @param string $Ident Action identifier supplied by Symcon or the visualization.
     * @param mixed  $Value Action payload.
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($this->HandleIPSViewHTMLPageAction($Ident, $Value)) {
            return;
        }

        try {
            switch ($Ident) {
                case 'FormSynchronizeCalendars':
                    $this->UpdateFormField(
                        $this->SynchronizeCalendars() ? 'SynchronizationSuccessPopup' : 'SynchronizationFailurePopup',
                        'visible',
                        true
                    );
                    break;

                case 'FormRestoreCalendars':
                    $this->UpdateFormField(
                        $this->SelectAllCalendars() ? 'CalendarSelectionRestoredPopup' : 'NoCalendarInstancesPopup',
                        'visible',
                        true
                    );
                    break;

                case 'FormRegenerateIPSViewHTML':
                    $this->UpdateFormField(
                        $this->RegenerateIPSViewHTML()
                            ? 'IPSViewRegenerationSuccessPopup'
                            : 'IPSViewRegenerationFailurePopup',
                        'visible',
                        true
                    );
                    break;

                default:
                    $result = $this->executeVisualizationAction($Ident, $Value);
                    $toast = $result['message'] !== ''
                        ? [
                            'level'   => $result['level'],
                            'message' => $result['message']
                        ]
                        : null;
                    $this->UpdateVisualizationValue($this->getFullUpdateMessage($result['state'], $toast));
                    break;
            }
        } catch (Throwable $exception) {
            $this->SendDebug('VisualizationAction', $exception->getMessage(), 0);
            $this->sendToast('error', $exception->getMessage());
        }
    }

    /**
     * Synchronizes all currently selected calendar instances and refreshes the visualization state.
     *
     * @return bool True when every selected calendar synchronized successfully.
     */
    public function SynchronizeCalendars(): bool
    {
        $success = $this->synchronizeSelectedCalendars();
        $this->broadcastStateInvalidation();

        return $success;
    }

    /**
     * Selects every available calendar instance in the currently open configuration form.
     *
     * The form selection is only changed in the editor and remains pending until the user
     * confirms it with Apply. Existing rows in the open form are replaced.
     *
     * @return bool False when no calendar instances are available.
     */
    public function SelectAllCalendars(): bool
    {
        $selection = array_map(
            static fn (int $instanceId): array => [
                'InstanceID' => $instanceId,
                'Enabled'    => true
            ],
            IPS_GetInstanceListByModuleID(self::CALENDAR_MODULE_ID)
        );
        if ($selection === []) {
            return false;
        }

        $this->UpdateFormField(
            'Calendars',
            'values',
            json_encode(
                $selection,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );

        return true;
    }

    /**
     * Returns the complete aggregated visualization state for the selected calendars.
     *
     * @return string JSON-encoded visualization state.
     */
    public function GetAggregatedEvents(): string
    {
        return json_encode(
            $this->buildState(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Returns appointments from all selected calendars that overlap one local calendar day.
     *
     * The supplied date uses the Symcon server's local timezone. All-day events use their
     * date-only boundaries so their exclusive provider end date does not spill into the
     * following day.
     *
     * @param string $Date Local date in YYYY-MM-DD format.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded appointment list.
     */
    public function GetDayAppointments(string $Date, int $CalendarInstanceID = 0): string
    {
        return $this->GetAppointments($Date, $Date, $CalendarInstanceID);
    }

    /**
     * Returns annual events from the calendars selected in this Calendar View.
     *
     * Calendar instance ID zero includes every selected calendar. A positive day
     * window only returns entries whose next occurrence is within that many days;
     * zero returns every annual event stored by OpenCalendar. The optional type
     * filter accepts birthday, anniversary, wedding, or death.
     *
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @param int $Days Optional look-ahead window in days. Zero returns all annual events.
     * @param string $Type Optional annual-event type. Empty returns every supported type.
     * @return string JSON-encoded annual-event list sorted by the next occurrence.
     */
    public function GetAnniversaryList(int $CalendarInstanceID = 0, int $Days = 0, string $Type = ''): string
    {
        if ($Days < 0) {
            throw new InvalidArgumentException('Annual-event look-ahead days must not be negative.');
        }
        $type = strtolower(trim($Type));
        if ($type !== '' && !in_array($type, ['birthday', 'anniversary', 'wedding', 'death'], true)) {
            throw new InvalidArgumentException('The annual-event type is invalid.');
        }

        $entries = [];
        foreach ($this->loadSelectedCalendars() as $calendar) {
            if ($CalendarInstanceID !== 0 && $calendar['instanceId'] !== $CalendarInstanceID) {
                continue;
            }
            try {
                $calendarEntries = json_decode(
                    IPSKAL_GetAnniversaryList($calendar['instanceId'], $Days, $type),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable $exception) {
                $this->SendDebug('AnniversaryList', $exception->getMessage(), 0);
                continue;
            }
            if (!is_array($calendarEntries) || !array_is_list($calendarEntries)) {
                continue;
            }
            foreach ($calendarEntries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entry['calendarInstanceId'] = $calendar['instanceId'];
                $entry['calendarName'] = $calendar['name'];
                $entry['calendarColor'] = $calendar['color'];
                $entries[] = $entry;
            }
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => ((int) ($left['daysUntil'] ?? PHP_INT_MAX)
                <=> (int) ($right['daysUntil'] ?? PHP_INT_MAX))
                ?: strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''))
                ?: strcasecmp((string) ($left['calendarName'] ?? ''), (string) ($right['calendarName'] ?? ''))
        );

        return json_encode(
            $entries,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Returns birthdays from the calendars selected in this Calendar View.
     *
     * This compatibility function delegates to GetAnniversaryList() with the birthday filter.
     *
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @param int $Days Optional look-ahead window in days. Zero returns all birthdays.
     * @return string JSON-encoded birthday list sorted by the next birthday.
     */
    public function GetBirthdayList(int $CalendarInstanceID = 0, int $Days = 0): string
    {
        return $this->GetAnniversaryList($CalendarInstanceID, $Days, 'birthday');
    }

    /**
     * Returns appointments from all selected calendars that overlap an inclusive local date range.
     *
     * This API deliberately ignores the visualization properties PastDays, FutureDays and
     * MaxEvents. It reads the cached events of every calendar selected in this Calendar View
     * and enriches each result with calendar instance, name, color and write capability.
     *
     * @param string $From First local date in YYYY-MM-DD format.
     * @param string $To Last local date in YYYY-MM-DD format, inclusive.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded appointment list.
     */
    public function GetAppointments(string $From, string $To, int $CalendarInstanceID = 0): string
    {
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates($From, $To);
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd);

        return $this->encodeAppointmentList(
            $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID)
        );
    }

    /**
     * Returns a compact appointment list for one local calendar day.
     *
     * Each result contains summary, start, end, startTime, endTime, hasReminder
     * and calendarName. Start and end are local YYYY-MM-DD dates. Timed appointments
     * use local HH:MM values while all-day appointments use the localized "All day"
     * label as startTime, an empty endTime and an inclusive visible end date.
     *
     * @param string $Date Local date in YYYY-MM-DD format.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded compact appointment list.
     */
    public function GetDayAppointmentsCompact(string $Date, int $CalendarInstanceID = 0): string
    {
        return $this->GetAppointmentsCompact($Date, $Date, $CalendarInstanceID);
    }

    /**
     * Returns a compact appointment list for an inclusive local date range.
     *
     * The selected calendars and range semantics are identical to GetAppointments(),
     * but provider metadata and technical timestamps are omitted.
     *
     * @param string $From First local date in YYYY-MM-DD format.
     * @param string $To Last local date in YYYY-MM-DD format, inclusive.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded compact appointment list.
     */
    public function GetAppointmentsCompact(string $From, string $To, int $CalendarInstanceID = 0): string
    {
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates($From, $To);
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd, true);

        return $this->encodeCompactAppointmentList(
            $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID)
        );
    }

    /**
     * Counts appointments that overlap one inclusive local calendar day.
     *
     * @param string $Date Local date in YYYY-MM-DD format.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return int Number of matching appointments.
     */
    public function GetDayAppointmentCount(string $Date, int $CalendarInstanceID = 0): int
    {
        return $this->GetAppointmentCount($Date, $Date, $CalendarInstanceID);
    }

    /**
     * Counts appointments that overlap an inclusive local date range.
     *
     * @param string $From First local date in YYYY-MM-DD format.
     * @param string $To Last local date in YYYY-MM-DD format, inclusive.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return int Number of matching appointments.
     */
    public function GetAppointmentCount(string $From, string $To, int $CalendarInstanceID = 0): int
    {
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates($From, $To);
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd);

        return count($this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID));
    }

    /**
     * Returns today's appointments that have not ended yet.
     *
     * Appointments currently in progress and all-day appointments are included.
     * Appointments that already ended are omitted.
     *
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded appointment list.
     */
    public function GetRemainingDayAppointments(int $CalendarInstanceID = 0): string
    {
        $now = new DateTimeImmutable('now');
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates(
            $now->format('Y-m-d'),
            $now->format('Y-m-d')
        );
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd);
        $appointments = $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID);

        return $this->encodeAppointmentList(
            $this->filterRemainingAppointments($appointments, $now->getTimestamp())
        );
    }

    /**
     * Counts today's appointments that have not ended yet.
     *
     * Appointments currently in progress and all-day appointments are included.
     * Appointments that already ended are omitted.
     *
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return int Number of remaining appointments today.
     */
    public function GetRemainingDayAppointmentCount(int $CalendarInstanceID = 0): int
    {
        $now = new DateTimeImmutable('now');
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates(
            $now->format('Y-m-d'),
            $now->format('Y-m-d')
        );
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd);
        $appointments = $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID);

        return count($this->filterRemainingAppointments($appointments, $now->getTimestamp()));
    }

    /**
     * Returns the next appointment that has not started yet.
     *
     * Currently running appointments are intentionally excluded and can be queried with
     * GetCurrentAppointments(). The search covers the maximum synchronized future range
     * supported by Calendar instances. If no upcoming appointment is cached, JSON null is returned.
     *
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded appointment object or null.
     */
    public function GetNextAppointment(int $CalendarInstanceID = 0): string
    {
        $now = new DateTimeImmutable('now');
        $today = $now->format('Y-m-d');
        $lastDay = $now->modify('+' . self::APPOINTMENT_LOOKAHEAD_DAYS . ' days')->format('Y-m-d');
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates($today, $lastDay);
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd);
        $appointments = $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID);

        return json_encode(
            $this->findNextAppointment($appointments, $now->getTimestamp()),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Returns appointments that are in progress right now.
     *
     * All-day appointments covering the current day are considered current.
     *
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded appointment list.
     */
    public function GetCurrentAppointments(int $CalendarInstanceID = 0): string
    {
        $now = new DateTimeImmutable('now');
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates(
            $now->format('Y-m-d'),
            $now->format('Y-m-d')
        );
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd);
        $appointments = $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID);

        return $this->encodeAppointmentList(
            $this->filterCurrentAppointments($appointments, $now->getTimestamp())
        );
    }

    /**
     * Counts appointments that are in progress right now.
     *
     * All-day appointments covering the current day are considered current.
     *
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return int Number of appointments currently in progress.
     */
    public function GetCurrentAppointmentCount(int $CalendarInstanceID = 0): int
    {
        $now = new DateTimeImmutable('now');
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates(
            $now->format('Y-m-d'),
            $now->format('Y-m-d')
        );
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd);
        $appointments = $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID);

        return count($this->filterCurrentAppointments($appointments, $now->getTimestamp()));
    }

    /**
     * Returns appointments starting within the next number of hours.
     *
     * Appointments already in progress are intentionally excluded. The time window starts
     * at the current timestamp and may extend across local calendar-day boundaries.
     *
     * @param int $Hours Number of hours to look ahead, from 1 up to the maximum synchronized future range.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded appointment list.
     */
    public function GetUpcomingAppointments(int $Hours, int $CalendarInstanceID = 0): string
    {
        $this->validateUpcomingHours($Hours);

        $now = new DateTimeImmutable('now');
        $until = $now->modify('+' . $Hours . ' hours');
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates(
            $now->format('Y-m-d'),
            $until->format('Y-m-d')
        );
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd);
        $appointments = $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID);

        return $this->encodeAppointmentList(
            $this->filterUpcomingAppointments($appointments, $now->getTimestamp(), $until->getTimestamp())
        );
    }

    /**
     * Returns a compact list of appointments starting within the next number of hours.
     *
     * The time-window and calendar-filter semantics are identical to GetUpcomingAppointments(),
     * but provider metadata and technical timestamps are omitted.
     *
     * @param int $Hours Number of hours to look ahead, from 1 up to the maximum synchronized future range.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded compact appointment list.
     */
    public function GetUpcomingAppointmentsCompact(int $Hours, int $CalendarInstanceID = 0): string
    {
        $this->validateUpcomingHours($Hours);

        $now = new DateTimeImmutable('now');
        $until = $now->modify('+' . $Hours . ' hours');
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates(
            $now->format('Y-m-d'),
            $until->format('Y-m-d')
        );
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd, true);
        $appointments = $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID);

        return $this->encodeCompactAppointmentList(
            $this->filterUpcomingAppointments(
                $appointments,
                $now->getTimestamp(),
                $until->getTimestamp()
            )
        );
    }

    /**
     * Counts appointments starting within the next number of hours.
     *
     * Appointments already in progress are intentionally excluded.
     *
     * @param int $Hours Number of hours to look ahead, from 1 up to the maximum synchronized future range.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return int Number of upcoming appointments in the requested time window.
     */
    public function GetUpcomingAppointmentCount(int $Hours, int $CalendarInstanceID = 0): int
    {
        $this->validateUpcomingHours($Hours);

        $now = new DateTimeImmutable('now');
        $until = $now->modify('+' . $Hours . ' hours');
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates(
            $now->format('Y-m-d'),
            $until->format('Y-m-d')
        );
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd);
        $appointments = $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID);

        return count($this->filterUpcomingAppointments(
            $appointments,
            $now->getTimestamp(),
            $until->getTimestamp()
        ));
    }

    /**
     * Returns the next requested number of appointments that have not started yet.
     *
     * Currently running appointments are intentionally excluded. The search covers the
     * maximum synchronized future range supported by Calendar instances.
     *
     * @param int $Count Number of future appointments to return, between 1 and 1000.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded appointment list.
     */
    public function GetNextAppointments(int $Count, int $CalendarInstanceID = 0): string
    {
        $this->validateAppointmentCount($Count);

        $now = new DateTimeImmutable('now');
        $today = $now->format('Y-m-d');
        $lastDay = $now->modify('+' . self::APPOINTMENT_LOOKAHEAD_DAYS . ' days')->format('Y-m-d');
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates($today, $lastDay);
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd);
        $appointments = $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID);

        return $this->encodeAppointmentList(
            array_slice($this->filterFutureAppointments($appointments, $now->getTimestamp()), 0, $Count)
        );
    }

    /**
     * Returns a compact list of the next requested appointments that have not started yet.
     *
     * The count and calendar-filter semantics are identical to GetNextAppointments(),
     * but provider metadata and technical timestamps are omitted.
     *
     * @param int $Count Number of future appointments to return, between 1 and 1000.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded compact appointment list.
     */
    public function GetNextAppointmentsCompact(int $Count, int $CalendarInstanceID = 0): string
    {
        $this->validateAppointmentCount($Count);

        $now = new DateTimeImmutable('now');
        $today = $now->format('Y-m-d');
        $lastDay = $now->modify('+' . self::APPOINTMENT_LOOKAHEAD_DAYS . ' days')->format('Y-m-d');
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates($today, $lastDay);
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd, true);
        $appointments = $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID);

        return $this->encodeCompactAppointmentList(
            array_slice(
                $this->filterFutureAppointments($appointments, $now->getTimestamp()),
                0,
                $Count
            )
        );
    }

    /**
     * Returns reminders whose effective trigger falls on one local calendar day.
     *
     * Only provider-neutral reminders with an exact trigger timestamp are returned.
     * Provider defaults are resolved through the selected calendar metadata when possible.
     *
     * @param string $Date Local reminder date in YYYY-MM-DD format.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded reminder list.
     */
    public function GetDayReminders(string $Date, int $CalendarInstanceID = 0): string
    {
        return $this->GetReminders($Date, $Date, $CalendarInstanceID);
    }

    /**
     * Returns reminders whose effective trigger falls inside an inclusive local date range.
     *
     * The queried range applies to the reminder trigger, not to the event start. Events may
     * therefore start after the requested range when their reminder falls inside it.
     * Disabled or complex reminder configurations without exact provider-neutral triggers
     * are intentionally omitted.
     *
     * @param string $From First local reminder date in YYYY-MM-DD format.
     * @param string $To Last local reminder date in YYYY-MM-DD format, inclusive.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded reminder list.
     */
    public function GetReminders(string $From, string $To, int $CalendarInstanceID = 0): string
    {
        [$rangeStart, $rangeEnd] = CalendarAppointmentRange::fromInclusiveDates($From, $To);

        return $this->encodeReminderList($this->collectRemindersForTimestampRange(
            $rangeStart->getTimestamp(),
            $rangeEnd->getTimestamp() - 1,
            $CalendarInstanceID
        ));
    }

    /**
     * Returns reminders becoming due within the next requested number of minutes.
     *
     * The current timestamp is included and the end of the requested window is inclusive.
     *
     * @param int $Minutes Number of minutes to look ahead.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded reminder list.
     */
    public function GetUpcomingReminders(int $Minutes, int $CalendarInstanceID = 0): string
    {
        $this->validateReminderMinutesWindow($Minutes);

        $now = new DateTimeImmutable('now');

        return $this->encodeReminderList($this->collectRemindersForTimestampRange(
            $now->getTimestamp(),
            $now->getTimestamp() + ($Minutes * 60),
            $CalendarInstanceID
        ));
    }

    /**
     * Returns the next reminder whose exact trigger has not passed yet.
     *
     * The search covers the maximum synchronized future range supported by Calendar instances.
     * If no determinable future reminder is cached, JSON null is returned.
     *
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded reminder object or null.
     */
    public function GetNextReminder(int $CalendarInstanceID = 0): string
    {
        $now = new DateTimeImmutable('now');
        $until = $now->modify('+' . self::APPOINTMENT_LOOKAHEAD_DAYS . ' days')->getTimestamp();
        $reminders = $this->collectRemindersForTimestampRange(
            $now->getTimestamp(),
            $until,
            $CalendarInstanceID
        );

        return json_encode(
            $reminders[0] ?? null,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Returns reminders that became due during the requested look-back tolerance.
     *
     * The function never returns future triggers. It is intended for cyclic scripts whose
     * execution may drift slightly. Callers can persist reminderId to suppress duplicate
     * processing when consecutive runs overlap.
     *
     * @param int $ToleranceMinutes Number of minutes to look back from now.
     * @param int $CalendarInstanceID Optional selected calendar instance ID. Zero includes all selected calendars.
     * @return string JSON-encoded reminder list.
     */
    public function GetDueReminders(int $ToleranceMinutes = 1, int $CalendarInstanceID = 0): string
    {
        $this->validateReminderMinutesWindow($ToleranceMinutes);

        $now = new DateTimeImmutable('now');

        return $this->encodeReminderList($this->collectRemindersForTimestampRange(
            $now->getTimestamp() - ($ToleranceMinutes * 60),
            $now->getTimestamp(),
            $CalendarInstanceID
        ));
    }

    /**
     * Returns the calendar instances selected and enabled in this Calendar View.
     *
     * In addition to display and capability metadata, the result contains the provider key,
     * last successful synchronization timestamp, current Symcon instance status, and last error.
     * Client-side temporary calendar filters do not alter this configured selection.
     *
     * @return string JSON-encoded selected calendar list.
     */
    public function GetSelectedCalendars(): string
    {
        return json_encode(
            $this->loadSelectedCalendars(true),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Renders the standalone HTML representation used by IPSView.
     *
     * @return string Rendered IPSView calendar HTML.
     */
    public function GetIPSViewHTML(): string
    {
        return $this->renderCalendarHtml($this->buildState(), true);
    }

    /**
     * Re-renders the existing IPSView WebContent variable without replacing it.
     *
     * @return bool True when the current HTML was written to the existing variable.
     */
    public function RegenerateIPSViewHTML(): bool
    {
        if (!$this->IsIPSViewHTMLPageEnabled()) {
            return false;
        }
        if (!$this->isRuntimeReady() && !$this->Initialize()) {
            return false;
        }

        try {
            $html = $this->renderNonEmptyIPSViewHTML($this->buildState(), 'IPSViewRegeneration');
            if ($html === null) {
                return false;
            }

            return $this->UpdateIPSViewHTMLVariable('IPSViewCalendar', $html);
        } catch (Throwable $exception) {
            $this->SendDebug('IPSViewRegeneration', $exception->getMessage(), 0);

            return false;
        }
    }

    /**
     * Handles the authenticated action bridge used by the IPSView HTML-Box.
     */
    protected function ProcessHookData(): void
    {
        if (!$this->IsIPSViewHTMLPageEnabled()) {
            $this->outputIPSViewResponse(['Error' => 'IPSView is disabled.'], 404);

            return;
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            $this->outputIPSViewResponse(['Error' => 'Method not allowed.'], 405);

            return;
        }

        $request = $_POST;
        if ($request === []) {
            parse_str((string) file_get_contents('php://input'), $request);
        }

        $token = is_string($request['token'] ?? null) ? $request['token'] : '';
        if ($token === '' || !hash_equals($this->ipsViewToken(), $token)) {
            $this->outputIPSViewResponse(['Error' => 'Unauthorized.'], 403);

            return;
        }

        $action = is_string($request['action'] ?? null) ? $request['action'] : '';
        if ($action === 'GetState') {
            try {
                $this->outputIPSViewResponse([
                    'type'    => 'state',
                    'payload' => $this->buildState()
                ]);
            } catch (Throwable $exception) {
                $this->SendDebug('IPSViewAction', $exception->getMessage(), 0);
                $this->outputIPSViewResponse(['Error' => 'State retrieval failed.'], 500);
            }

            return;
        }

        $rawValue = $request['value'] ?? 'null';
        $value = null;
        if (is_string($rawValue)) {
            try {
                $value = json_decode($rawValue, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->outputIPSViewResponse(['Error' => 'Invalid value.'], 400);

                return;
            }
        }

        try {
            $result = $this->executeVisualizationAction($action, $value);
            $response = [
                'type'    => 'state',
                'payload' => $result['state']
            ];
            if ($result['message'] !== '') {
                $response['toast'] = [
                    'level'   => $result['level'],
                    'message' => $result['message']
                ];
            }
            $this->outputIPSViewResponse($response);
        } catch (InvalidArgumentException $exception) {
            $this->outputIPSViewResponse(['Error' => $exception->getMessage()], 400);
        } catch (RuntimeException $exception) {
            $this->outputIPSViewResponse(['Error' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            $this->SendDebug('IPSViewAction', $exception->getMessage(), 0);
            $this->outputIPSViewResponse(['Error' => 'Action failed.'], 500);
        }
    }

    private function broadcastStateInvalidation(): void
    {
        if (!$this->isRuntimeReady()) {
            return;
        }

        try {
            $this->UpdateVisualizationValue(json_encode(
                ['type' => 'invalidate'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        } catch (Throwable $exception) {
            $this->SendDebug('VisualizationUpdate', $exception->getMessage(), 0);
        }
    }

    private function broadcastState(?array $state = null, bool $updateIPSViewHTML = false): void
    {
        if (!$this->isRuntimeReady()) {
            return;
        }

        if ($state === null) {
            try {
                $state = $this->buildState();
            } catch (Throwable $exception) {
                $this->SendDebug('CalendarState', $exception->getMessage(), 0);

                return;
            }
        }

        try {
            $this->UpdateVisualizationValue($this->getFullUpdateMessage($state));
        } catch (Throwable $exception) {
            $this->SendDebug('VisualizationUpdate', $exception->getMessage(), 0);
        }

        if ($updateIPSViewHTML && $this->IsIPSViewHTMLPageEnabled()) {
            try {
                $html = $this->renderNonEmptyIPSViewHTML($state, 'IPSViewUpdate');
                if ($html !== null) {
                    $this->UpdateIPSViewHTMLVariable('IPSViewCalendar', $html);
                }
            } catch (Throwable $exception) {
                $this->SendDebug('IPSViewUpdate', $exception->getMessage(), 0);
            }
        }
    }

    /**
     * @param array<string, mixed>|null $state Current visualization state.
     * @param array{level: string, message: string}|null $toast Optional toast included in the same native update.
     */
    private function getFullUpdateMessage(?array $state = null, ?array $toast = null): string
    {
        $message = [
            'type'    => 'state',
            'payload' => $state ?? $this->buildState()
        ];
        if ($toast !== null) {
            $message['toast'] = $toast;
        }

        return json_encode(
            $message,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function renderCalendarHtml(array $state, bool $ipsView): string
    {
        $translations = $ipsView
            ? $this->IPSViewTranslationsFromLocale($this->calendarVisualizationTranslationKeys())
            : [];

        $runtime = $ipsView
            ? [
                'endpoint' => '/hook/' . $this->ipsViewHookAddress(),
                'token'    => $this->ipsViewToken()
            ]
            : null;

        return $this->RenderVisualizationHTMLPage($ipsView, [
            'language'           => $this->Translate('Today') === 'Heute' ? 'de' : 'en',
            'classes'            => $ipsView ? ['ipsview-mode'] : [],
            'rootFontSize'       => $ipsView
                ? $this->IPSViewStyleRootFontSize()
                : max(50, min(200, $this->ReadPropertyInteger('TileFontScale'))) . '%',
            'title'              => $this->Translate('Calendar'),
            'visualizationTheme' => $this->VisualizationThemeCSS(),
            'ipsViewStyle'       => $ipsView ? $this->IPSViewStyleCSSVariables(':root') : '',
            'state'              => $state,
            'runtime'            => $runtime,
            'translations'       => $translations,
            'options'            => [
                'instanceId'           => $this->InstanceID,
                'agendaColorBarWidth'  => $ipsView
                    ? max(2, min(16, $this->ReadPropertyInteger('IPSViewColorBarWidth')))
                    : 5,
                'compactColorBarWidth' => $ipsView
                    ? max(2, min(16, $this->ReadPropertyInteger('IPSViewColorBarWidth')))
                    : 3
            ]
        ]);
    }

    /**
     * Renders a complete IPSView page without allowing a transient asset-loading
     * failure to replace the last working WebContent value with an empty string.
     *
     * @param array<string, mixed> $state        Current calendar state.
     * @param string               $debugContext Debug channel used when rendering is incomplete.
     *
     * @return string|null Complete HTML or null when the existing page must be preserved.
     */
    private function renderNonEmptyIPSViewHTML(array $state, string $debugContext): ?string
    {
        $html = $this->renderCalendarHtml($state, true);
        if ($html !== '') {
            return $html;
        }

        $this->SendDebug(
            $debugContext,
            'Rendering returned an empty document; preserving the existing IPSView HTML.',
            0
        );

        return null;
    }

    /**
     * Reads the current WebContent value before MaintainVariable() can recreate
     * its presentation during a module update.
     */
    private function existingIPSViewHTML(): string
    {
        try {
            $variableId = @$this->GetIDForIdent('IPSViewCalendar');
        } catch (Throwable) {
            return '';
        }
        if (!is_int($variableId) || $variableId <= 0 || !IPS_VariableExists($variableId)) {
            return '';
        }

        return GetValueString($variableId);
    }

    /** @return list<string> */
    private function calendarVisualizationTranslationKeys(): array
    {
        return [
            'Agenda',
            'List',
            'Days',
            'Week',
            'Weeks',
            'Full week',
            'Work week',
            'Month',
            'Months',
            'CW',
            'Day',
            'Previous',
            'Today',
            'Next',
            'Refresh',
            'No calendars selected',
            'Select at least one calendar in the instance configuration.',
            'No events',
            'There are no events in this period.',
            'All day',
            'Event',
            'Events',
            'Untitled event',
            'more',
            'Create event',
            'Create event on this day',
            'New event',
            'No writable calendar available',
            'Event details',
            'Edit event',
            'Edit',
            'Calendar',
            'Date',
            'Title',
            'Occasion',
            'Birthday',
            'Anniversary',
            'Wedding anniversary',
            'Death anniversary',
            'Start',
            'End',
            'Location',
            'Description',
            'Reminder',
            'Calendar default',
            'No reminder',
            'Custom',
            'Before start',
            'Unit',
            'Minute',
            'Minutes',
            'Hour',
            'Hours',
            'Existing reminder settings',
            'Add reminder',
            'Remove reminder',
            'This calendar supports up to %d reminders.',
            'Reminder times must be unique.',
            'The selected calendar does not support these reminder settings.',
            'Repeat',
            'Does not repeat',
            'Daily',
            'Weekly',
            'Monthly',
            'Yearly',
            'Interval',
            'Ends',
            'Never',
            'After occurrences',
            'On date',
            'Weekdays',
            'Occurrences',
            'End date',
            'Year',
            'Years',
            'Cancel',
            'Save',
            'Move',
            'Event moved.',
            'Events with complex reminder settings cannot be moved safely.',
            'Delete',
            'Delete event',
            'Do you really want to delete this event?',
            'Recurring event',
            'Only this event',
            'Entire series',
            'Close',
            'Day events',
            'Filter calendars',
            'Select all',
            'Select none',
            'Apply',
            'No calendars visible',
            'Select one or more calendars in the calendar filter.',
            'This filter only changes the current view on this browser or monitor.',
            'Tomorrow',
            'Yesterday',
            'Recurring event creation is not supported by this calendar.',
            'Recurring occurrences are currently read-only.',
            'Only this occurrence of the recurring event will be changed.',
            'Changes will apply to this and all following occurrences.',
            'Existing exceptions from this occurrence onward will be reset.',
            'Changes will apply to the entire recurring series.',
            'The recurrence pattern of this series cannot be edited here.',
            'Edit recurring event',
            'Which events do you want to edit?',
            'This and following events',
            'Continue',
            'This calendar is read-only.',
            'Editing events is unavailable because no action bridge is configured.',
            'Action failed.',
            'The description of Microsoft online meetings is protected and cannot be edited here.'
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildState(?int $requestedRangeStart = null, ?int $requestedRangeEnd = null, int $eventOffset = 0): array
    {
        if (!$this->isRuntimeReady()) {
            return $this->emptyState();
        }

        [$rangeStart, $rangeEnd] = $this->resolveVisualizationRange(
            $requestedRangeStart,
            $requestedRangeEnd
        );
        [$queryStart, $queryEnd] = $this->visualizationQueryRange($rangeStart, $rangeEnd);

        $calendars = $this->loadSelectedCalendars();
        $events = [];
        if ($queryEnd > $queryStart) {
            foreach ($calendars as $calendar) {
                try {
                    $calendarEvents = $this->readCalendarEventsForRange(
                        $calendar['instanceId'],
                        $queryStart,
                        $queryEnd
                    );
                } catch (Throwable $exception) {
                    $this->SendDebug('CalendarData', $exception->getMessage(), 0);
                    continue;
                }

                foreach ($calendarEvents as $event) {
                    $startTimestamp = (int) ($event['startTimestamp'] ?? 0);
                    $endTimestamp = (int) ($event['endTimestamp'] ?? $startTimestamp);
                    if ($startTimestamp <= 0 || $endTimestamp < $rangeStart || $startTimestamp >= $rangeEnd) {
                        continue;
                    }
                    $event['calendarInstanceId'] = $calendar['instanceId'];
                    $event['calendarName'] = $calendar['name'];
                    $event['calendarColor'] = $calendar['color'];
                    $event['canWrite'] = $calendar['canWrite'];
                    if (($event['recurrenceType'] ?? '') === 'occurrence'
                        && trim((string) ($event['originalStart'] ?? '')) === '') {
                        $event['originalStart'] = trim((string) ($event['start'] ?? ''));
                    }
                    $recurringOccurrence = (bool) ($event['recurring'] ?? false)
                        && trim((string) ($event['occurrenceId'] ?? '')) !== ''
                        && trim((string) ($event['seriesId'] ?? '')) !== '';
                    $event['canUpdateOccurrence'] = (bool) ($event['canUpdateOccurrence'] ?? false)
                        || ($recurringOccurrence && $calendar['canUpdateOccurrence']);
                    $event['canDeleteOccurrence'] = (bool) ($event['canDeleteOccurrence'] ?? false)
                        || ($recurringOccurrence && $calendar['canDeleteOccurrence']);
                    $event['canUpdateFollowing'] = (bool) ($event['canUpdateFollowing'] ?? false)
                        || ((bool) ($event['recurring'] ?? false)
                            && trim((string) ($event['occurrenceId'] ?? '')) !== ''
                            && trim((string) ($event['seriesId'] ?? '')) !== ''
                            && $calendar['canUpdateFollowing']);
                    $event['canUpdateSeries'] = (bool) ($event['canUpdateSeries'] ?? false)
                        || ((bool) ($event['recurring'] ?? false)
                            && trim((string) ($event['seriesId'] ?? '')) !== ''
                            && $calendar['canUpdateSeries']);
                    $event['canDeleteSeries'] = (bool) ($event['canDeleteSeries'] ?? false)
                        || ((bool) ($event['recurring'] ?? false)
                            && trim((string) ($event['seriesId'] ?? '')) !== ''
                            && $calendar['canDeleteSeries']);
                    $events[] = $event;
                }
            }
        }

        usort(
            $events,
            static fn (array $left, array $right): int => ((int) $left['startTimestamp'] <=> (int) $right['startTimestamp'])
                ?: strcasecmp((string) ($left['summary'] ?? ''), (string) ($right['summary'] ?? ''))
        );
        $totalEventCount = count($events);
        $maximumEvents = max(1, min(1000, $this->ReadPropertyInteger('MaxEvents')));
        $eventOffset = max(0, $eventOffset);
        $events = array_slice($events, $eventOffset, $maximumEvents);
        $nextOffset = $eventOffset + count($events);
        $hasMore = $nextOffset < $totalEventCount;

        return [
            'events'      => $events,
            'calendars'   => array_values($calendars),
            'generatedAt' => time(),
            'eventRange'  => [
                'start'      => $rangeStart,
                'end'        => $rangeEnd,
                'offset'     => $eventOffset,
                'pageCount'  => count($events),
                'truncated'  => $hasMore,
                'hasMore'    => $hasMore,
                'nextOffset' => $hasMore ? $nextOffset : null,
                'totalCount' => $totalEventCount
            ],
            'settings'    => $this->viewSettings()
        ];
    }

    /**
     * Resolves the event interval embedded into one visualization state.
     *
     * The initial HTML only carries a compact bootstrap interval. After the client
     * restores its view and cursor it requests the exact visible interval through
     * the action bridge.
     *
     * @return array{0: int, 1: int}
     */
    private function resolveVisualizationRange(?int $requestedRangeStart, ?int $requestedRangeEnd): array
    {
        if ($requestedRangeStart === null || $requestedRangeEnd === null) {
            $today = new DateTimeImmutable('today');

            return [
                $today->modify('-' . self::VISUALIZATION_BOOTSTRAP_PAST_DAYS . ' days')->getTimestamp(),
                $today->modify('+' . (self::VISUALIZATION_BOOTSTRAP_FUTURE_DAYS + 1) . ' days')->getTimestamp()
            ];
        }

        if ($requestedRangeStart <= 0 || $requestedRangeEnd <= $requestedRangeStart) {
            throw new InvalidArgumentException($this->Translate('The visualization request is invalid.'));
        }
        if (($requestedRangeEnd - $requestedRangeStart) > self::VISUALIZATION_MAX_RANGE_DAYS * 86400) {
            throw new InvalidArgumentException($this->Translate('The visualization request is invalid.'));
        }

        return [$requestedRangeStart, $requestedRangeEnd];
    }

    /**
     * Intersects a requested visualization interval with the configured cache window.
     *
     * The requested interval itself remains part of the returned state so a client
     * outside the configured past/future limits does not repeatedly request the same
     * empty range.
     *
     * @return array{0: int, 1: int}
     */
    private function visualizationQueryRange(int $rangeStart, int $rangeEnd): array
    {
        $pastDays = max(0, min(1095, $this->ReadPropertyInteger('PastDays')));
        $futureDays = max(1, min(1095, $this->ReadPropertyInteger('FutureDays')));
        $today = new DateTimeImmutable('today');
        $configuredStart = $today->modify('-' . $pastDays . ' days')->getTimestamp();
        $configuredEnd = $today->modify('+' . ($futureDays + 1) . ' days')->getTimestamp();

        $queryStart = max($rangeStart, $configuredStart);
        $queryEnd = min($rangeEnd, $configuredEnd);

        return $queryEnd > $queryStart
            ? [$queryStart, $queryEnd]
            : [$rangeStart, $rangeStart];
    }

    /**
     * Collects provider-independent appointments from every selected calendar.
     *
     * @return list<array<string, mixed>>
     */
    private function collectAppointmentsForRange(
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd,
        bool $includeCompactMetadata = false
    ): array {
        $events = [];
        foreach ($this->loadSelectedCalendars() as $calendar) {
            $calendarEvents = $this->readCalendarEventsForRange(
                $calendar['instanceId'],
                $rangeStart->getTimestamp(),
                $rangeEnd->getTimestamp()
            );

            foreach ($calendarEvents as $event) {
                if (!CalendarAppointmentRange::eventOverlaps($event, $rangeStart, $rangeEnd)) {
                    continue;
                }

                $event['calendarInstanceId'] = $calendar['instanceId'];
                $event['calendarName'] = $calendar['name'];
                $event['calendarColor'] = $calendar['color'];
                $event['canWrite'] = $calendar['canWrite'];
                if (($event['recurrenceType'] ?? '') === 'occurrence'
                    && trim((string) ($event['originalStart'] ?? '')) === '') {
                    $event['originalStart'] = trim((string) ($event['start'] ?? ''));
                }
                $recurringOccurrence = (bool) ($event['recurring'] ?? false)
                    && trim((string) ($event['occurrenceId'] ?? '')) !== ''
                    && trim((string) ($event['seriesId'] ?? '')) !== '';
                $event['canUpdateOccurrence'] = (bool) ($event['canUpdateOccurrence'] ?? false)
                    || ($recurringOccurrence && $calendar['canUpdateOccurrence']);
                $event['canDeleteOccurrence'] = (bool) ($event['canDeleteOccurrence'] ?? false)
                    || ($recurringOccurrence && $calendar['canDeleteOccurrence']);
                $event['canUpdateFollowing'] = (bool) ($event['canUpdateFollowing'] ?? false)
                    || ((bool) ($event['recurring'] ?? false)
                        && trim((string) ($event['occurrenceId'] ?? '')) !== ''
                        && trim((string) ($event['seriesId'] ?? '')) !== ''
                        && $calendar['canUpdateFollowing']);
                $event['canUpdateSeries'] = (bool) ($event['canUpdateSeries'] ?? false)
                    || ((bool) ($event['recurring'] ?? false)
                        && trim((string) ($event['seriesId'] ?? '')) !== ''
                        && $calendar['canUpdateSeries']);
                $event['canDeleteSeries'] = (bool) ($event['canDeleteSeries'] ?? false)
                    || ((bool) ($event['recurring'] ?? false)
                        && trim((string) ($event['seriesId'] ?? '')) !== ''
                        && $calendar['canDeleteSeries']);
                if ($includeCompactMetadata) {
                    $event['hasReminder'] = $this->appointmentHasReminder($event, $calendar);
                }
                $events[] = $event;
            }
        }

        usort(
            $events,
            static fn (array $left, array $right): int => ((int) ($left['startTimestamp'] ?? 0)
                <=> (int) ($right['startTimestamp'] ?? 0))
                ?: strcasecmp((string) ($left['summary'] ?? ''), (string) ($right['summary'] ?? ''))
                ?: strcasecmp((string) ($left['calendarName'] ?? ''), (string) ($right['calendarName'] ?? ''))
        );

        return $events;
    }

    /**
     * Returns whether an appointment has an effective reminder configuration.
     *
     * Provider-default reminders are resolved against the selected calendar metadata.
     * Complex reminder configurations count as active even when their exact trigger
     * cannot be represented by the provider-neutral reminder API.
     *
     * @param array<string, mixed> $appointment Full normalized appointment.
     * @param array<string, mixed> $calendar Selected calendar metadata.
     */
    private function appointmentHasReminder(array $appointment, array $calendar): bool
    {
        $reminder = $appointment['reminder'] ?? null;
        if (!is_array($reminder) || array_is_list($reminder)) {
            return false;
        }

        $mode = strtolower(trim((string) ($reminder['mode'] ?? '')));
        if ($mode === CalendarEventReminder::MODE_NONE || $mode === '') {
            return false;
        }
        if (in_array(
            $mode,
            [CalendarEventReminder::MODE_CUSTOM, CalendarEventReminder::MODE_MULTIPLE, CalendarEventReminder::MODE_COMPLEX],
            true
        )) {
            return true;
        }
        if ($mode !== CalendarEventReminder::MODE_DEFAULT || !(bool) ($calendar['canUseDefaultReminder'] ?? false)) {
            return false;
        }

        $defaultReminder = $calendar['defaultReminder'] ?? null;
        if (!is_array($defaultReminder) || array_is_list($defaultReminder)) {
            return false;
        }
        $defaultMode = strtolower(trim((string) ($defaultReminder['mode'] ?? '')));

        return in_array(
            $defaultMode,
            [CalendarEventReminder::MODE_CUSTOM, CalendarEventReminder::MODE_MULTIPLE, CalendarEventReminder::MODE_COMPLEX],
            true
        );
    }

    /**
     * Filters appointments by their selected Calendar instance ID.
     *
     * A zero ID keeps all appointments for backwards-compatible API calls.
     * Any other ID only keeps appointments originating from that selected calendar.
     *
     * @param list<array<string, mixed>> $appointments Full normalized appointments.
     * @return list<array<string, mixed>>
     */
    private function filterAppointmentsByCalendarInstanceId(array $appointments, int $CalendarInstanceID): array
    {
        if ($CalendarInstanceID === 0) {
            return $appointments;
        }

        return array_values(array_filter(
            $appointments,
            static fn (array $appointment): bool => (int) ($appointment['calendarInstanceId'] ?? 0) === $CalendarInstanceID
        ));
    }

    /**
     * Collects provider-neutral reminder triggers inside an inclusive timestamp range.
     *
     * Event loading is extended by the maximum supported reminder lead time so a reminder
     * can be returned even when its event starts after the requested reminder range.
     *
     * @return list<array<string, mixed>>
     */
    private function collectRemindersForTimestampRange(
        int $fromTimestamp,
        int $toTimestamp,
        int $CalendarInstanceID
    ): array {
        if ($fromTimestamp <= 0 || $toTimestamp < $fromTimestamp) {
            throw new InvalidArgumentException('The reminder time range is invalid.');
        }

        $timezone = new DateTimeZone(date_default_timezone_get());
        $eventRangeStartDate = (new DateTimeImmutable('@' . $fromTimestamp))
            ->setTimezone($timezone)
            ->format('Y-m-d');
        $eventRangeEndDate = (new DateTimeImmutable(
            '@' . ($toTimestamp + (CalendarEventReminder::MAX_MINUTES_BEFORE_START * 60))
        ))
            ->setTimezone($timezone)
            ->format('Y-m-d');
        [$eventRangeStart, $eventRangeEnd] = CalendarAppointmentRange::fromInclusiveDates(
            $eventRangeStartDate,
            $eventRangeEndDate
        );

        $appointments = $this->collectAppointmentsForRange($eventRangeStart, $eventRangeEnd);
        $appointments = $this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID);

        $calendars = [];
        foreach ($this->loadSelectedCalendars() as $calendar) {
            $calendars[(int) $calendar['instanceId']] = $calendar;
        }

        $reminders = [];
        foreach ($appointments as $appointment) {
            $calendarInstanceId = (int) ($appointment['calendarInstanceId'] ?? 0);
            if (!isset($calendars[$calendarInstanceId])) {
                continue;
            }

            foreach ($this->buildReminderRecords($appointment, $calendars[$calendarInstanceId]) as $reminder) {
                $reminderTimestamp = (int) ($reminder['reminderTimestamp'] ?? 0);
                if ($reminderTimestamp < $fromTimestamp || $reminderTimestamp > $toTimestamp) {
                    continue;
                }

                $reminders[] = $reminder;
            }
        }

        usort(
            $reminders,
            static fn (array $left, array $right): int => ((int) ($left['reminderTimestamp'] ?? 0)
                <=> (int) ($right['reminderTimestamp'] ?? 0))
                ?: ((int) ($left['startTimestamp'] ?? 0) <=> (int) ($right['startTimestamp'] ?? 0))
                ?: strcasecmp((string) ($left['summary'] ?? ''), (string) ($right['summary'] ?? ''))
                ?: strcasecmp((string) ($left['calendarName'] ?? ''), (string) ($right['calendarName'] ?? ''))
        );

        return $reminders;
    }

    /**
     * Builds the first script-friendly reminder record from a normalized appointment.
     *
     * Kept as a small compatibility wrapper for existing internal tests and callers.
     * Multiple reminder configurations are exposed completely through buildReminderRecords().
     *
     * @param array<string, mixed> $appointment Full normalized appointment.
     * @param array<string, mixed> $calendar Selected calendar metadata.
     * @return array<string, mixed>|null Exact reminder record or null when no exact trigger can be determined.
     */
    private function buildReminderRecord(array $appointment, array $calendar): ?array
    {
        return $this->buildReminderRecords($appointment, $calendar)[0] ?? null;
    }

    /**
     * Builds all script-friendly reminder records from a normalized appointment.
     *
     * @param array<string, mixed> $appointment Full normalized appointment.
     * @param array<string, mixed> $calendar Selected calendar metadata.
     * @return list<array<string, mixed>> Exact reminder records.
     */
    private function buildReminderRecords(array $appointment, array $calendar): array
    {
        $startTimestamp = (int) ($appointment['startTimestamp'] ?? 0);
        $calendarInstanceId = (int) ($appointment['calendarInstanceId'] ?? $calendar['instanceId'] ?? 0);
        $reminder = $appointment['reminder'] ?? null;
        if ($startTimestamp <= 0 || $calendarInstanceId <= 0 || !is_array($reminder) || array_is_list($reminder)) {
            return [];
        }

        try {
            $normalizedReminder = CalendarEventReminder::normalizeInput($reminder, true);
        } catch (InvalidArgumentException) {
            return [];
        }

        $sourceMode = (string) ($normalizedReminder['mode'] ?? '');
        $effectiveReminder = $normalizedReminder;
        if ($sourceMode === CalendarEventReminder::MODE_DEFAULT) {
            if (!(bool) ($calendar['canUseDefaultReminder'] ?? false)) {
                return [];
            }

            try {
                $effectiveReminder = CalendarEventReminder::normalizeInput($calendar['defaultReminder'] ?? null);
            } catch (InvalidArgumentException) {
                return [];
            }
        }

        $minutesBeforeStartValues = CalendarEventReminder::minutesBeforeStartValues($effectiveReminder);
        if ($minutesBeforeStartValues === []) {
            return [];
        }

        $timezone = new DateTimeZone(date_default_timezone_get());
        $eventIdentity = $this->reminderEventIdentity($appointment);
        $reminderCount = count($minutesBeforeStartValues);
        $records = [];
        foreach ($minutesBeforeStartValues as $reminderIndex => $minutesBeforeStart) {
            $reminderTimestamp = $startTimestamp - ($minutesBeforeStart * 60);
            $reminderId = hash(
                'sha256',
                $calendarInstanceId . '|' . $eventIdentity . '|' . $startTimestamp . '|' . $reminderTimestamp
            );

            $records[] = [
                'reminderId'         => $reminderId,
                'summary'            => (string) ($appointment['summary'] ?? ''),
                'calendarInstanceId' => $calendarInstanceId,
                'calendarName'       => (string) ($appointment['calendarName'] ?? $calendar['name'] ?? ''),
                'calendarColor'      => (string) ($appointment['calendarColor'] ?? $calendar['color'] ?? ''),
                'start'              => (string) ($appointment['start'] ?? ''),
                'startTimestamp'     => $startTimestamp,
                'allDay'             => (bool) ($appointment['allDay'] ?? false),
                'location'           => (string) ($appointment['location'] ?? ''),
                'reminderMode'       => $sourceMode,
                'reminderIndex'      => $reminderIndex,
                'reminderCount'      => $reminderCount,
                'minutesBeforeStart' => $minutesBeforeStart,
                'reminderTimestamp'  => $reminderTimestamp,
                'reminderDateTime'   => (new DateTimeImmutable('@' . $reminderTimestamp))
                    ->setTimezone($timezone)
                    ->format(DATE_ATOM)
            ];
        }

        return $records;
    }

    /**
     * Returns a stable provider-independent identity input for reminder IDs.
     *
     * @param array<string, mixed> $appointment Full normalized appointment.
     */
    private function reminderEventIdentity(array $appointment): string
    {
        foreach (['occurrenceId', 'eventReference', 'uid', 'resourceUrl', 'id'] as $key) {
            $value = trim((string) ($appointment[$key] ?? ''));
            if ($value !== '') {
                return $key . ':' . $value;
            }
        }

        $seriesId = trim((string) ($appointment['seriesId'] ?? ''));
        $originalStart = trim((string) ($appointment['originalStart'] ?? ''));
        if ($seriesId !== '' || $originalStart !== '') {
            return 'series:' . $seriesId . '|' . $originalStart;
        }

        return 'fallback:' . hash(
            'sha256',
            (string) ($appointment['summary'] ?? '')
                . '|'
                . (string) ($appointment['start'] ?? '')
                . '|'
                . (string) ($appointment['end'] ?? '')
        );
    }

    /**
     * Encodes provider-neutral reminder records for PHP callers.
     *
     * @param list<array<string, mixed>> $reminders Reminder records sorted by trigger timestamp.
     */
    private function encodeReminderList(array $reminders): string
    {
        return json_encode(
            $reminders,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Validates minute windows used by upcoming and due reminder queries.
     */
    private function validateReminderMinutesWindow(int $Minutes): void
    {
        $maximumMinutes = self::APPOINTMENT_LOOKAHEAD_DAYS * 24 * 60;
        if ($Minutes < 1 || $Minutes > $maximumMinutes) {
            throw new InvalidArgumentException(
                'Minutes must be between 1 and ' . $maximumMinutes . '.'
            );
        }
    }

    /**
     * Keeps appointments that have not ended at the supplied timestamp.
     *
     * @param list<array<string, mixed>> $appointments Full normalized appointments.
     * @return list<array<string, mixed>>
     */
    private function filterRemainingAppointments(array $appointments, int $now): array
    {
        return array_values(array_filter(
            $appointments,
            fn (array $appointment): bool => $this->appointmentEndTimestamp($appointment) > $now
        ));
    }

    /**
     * Keeps appointments that are in progress at the supplied timestamp.
     *
     * @param list<array<string, mixed>> $appointments Full normalized appointments.
     * @return list<array<string, mixed>>
     */
    private function filterCurrentAppointments(array $appointments, int $now): array
    {
        return array_values(array_filter(
            $appointments,
            fn (array $appointment): bool => (int) ($appointment['startTimestamp'] ?? 0) > 0
                && (int) ($appointment['startTimestamp'] ?? 0) <= $now
                && $this->appointmentEndTimestamp($appointment) > $now
        ));
    }

    /**
     * Finds the first appointment whose start is still in the future.
     *
     * @param list<array<string, mixed>> $appointments Chronologically sorted normalized appointments.
     * @return array<string, mixed>|null
     */
    private function findNextAppointment(array $appointments, int $now): ?array
    {
        return $this->filterFutureAppointments($appointments, $now)[0] ?? null;
    }

    /**
     * Keeps appointments whose start is still in the future.
     *
     * @param list<array<string, mixed>> $appointments Chronologically sorted normalized appointments.
     * @return list<array<string, mixed>>
     */
    private function filterFutureAppointments(array $appointments, int $now): array
    {
        return array_values(array_filter(
            $appointments,
            static fn (array $appointment): bool => (int) ($appointment['startTimestamp'] ?? 0) > $now
        ));
    }

    /**
     * Keeps future appointments starting no later than the supplied end timestamp.
     *
     * @param list<array<string, mixed>> $appointments Chronologically sorted normalized appointments.
     * @return list<array<string, mixed>>
     */
    private function filterUpcomingAppointments(array $appointments, int $now, int $until): array
    {
        return array_values(array_filter(
            $appointments,
            static fn (array $appointment): bool => (int) ($appointment['startTimestamp'] ?? 0) > $now
                && (int) ($appointment['startTimestamp'] ?? 0) <= $until
        ));
    }

    /**
     * Validates the requested number of future appointments.
     */
    private function validateAppointmentCount(int $Count): void
    {
        if ($Count < 1 || $Count > 1000) {
            throw new InvalidArgumentException('Count must be between 1 and 1000.');
        }
    }

    /**
     * Validates the requested upcoming appointment time window.
     */
    private function validateUpcomingHours(int $Hours): void
    {
        if ($Hours < 1 || $Hours > self::APPOINTMENT_LOOKAHEAD_DAYS * 24) {
            throw new InvalidArgumentException(
                'Hours must be between 1 and ' . (self::APPOINTMENT_LOOKAHEAD_DAYS * 24) . '.'
            );
        }
    }

    /**
     * Returns a safe exclusive end timestamp for appointment state comparisons.
     *
     * Zero-duration appointments are treated as one-second events, matching the
     * overlap semantics of CalendarAppointmentRange.
     *
     * @param array<string, mixed> $appointment Full normalized appointment.
     */
    private function appointmentEndTimestamp(array $appointment): int
    {
        $startTimestamp = (int) ($appointment['startTimestamp'] ?? 0);
        if ($startTimestamp <= 0) {
            return 0;
        }

        $endTimestamp = (int) ($appointment['endTimestamp'] ?? $startTimestamp);

        return $endTimestamp > $startTimestamp ? $endTimestamp : $startTimestamp + 1;
    }

    /**
     * Encodes a full provider-independent appointment list for PHP callers.
     *
     * @param list<array<string, mixed>> $appointments Full normalized appointments.
     */
    private function encodeAppointmentList(array $appointments): string
    {
        return json_encode(
            $appointments,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Encodes compact provider-independent appointments for PHP callers.
     *
     * @param list<array<string, mixed>> $appointments Full normalized appointments.
     */
    private function encodeCompactAppointmentList(array $appointments): string
    {
        return json_encode(
            $this->compactAppointments($appointments),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Reduces full appointment data to the compact scripting representation.
     *
     * @param list<array<string, mixed>> $appointments Full normalized appointments.
     * @return list<array{summary: string, start: string, end: string, startTime: string, endTime: string, hasReminder: bool, calendarName: string}>
     */
    private function compactAppointments(array $appointments): array
    {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $result = [];

        foreach ($appointments as $appointment) {
            $allDay = (bool) ($appointment['allDay'] ?? false);
            $startTimestamp = (int) ($appointment['startTimestamp'] ?? 0);
            $endTimestamp = (int) ($appointment['endTimestamp'] ?? 0);
            $startDate = $allDay
                ? $this->formatAllDayAppointmentDate((string) ($appointment['start'] ?? ''), $startTimestamp, $timezone)
                : $this->formatAppointmentDate($startTimestamp, $timezone);
            $endDate = $allDay
                ? $this->formatAllDayAppointmentEndDate(
                    (string) ($appointment['end'] ?? ''),
                    $endTimestamp,
                    $startDate,
                    $timezone
                )
                : $this->formatAppointmentDate($endTimestamp, $timezone);

            $result[] = [
                'summary'      => (string) ($appointment['summary'] ?? ''),
                'start'        => $startDate,
                'end'          => $endDate,
                'startTime'    => $allDay ? $this->Translate('All day') : $this->formatAppointmentTime($startTimestamp, $timezone),
                'endTime'      => $allDay ? '' : $this->formatAppointmentTime($endTimestamp, $timezone),
                'hasReminder'  => (bool) ($appointment['hasReminder'] ?? false),
                'calendarName' => (string) ($appointment['calendarName'] ?? '')
            ];
        }

        return $result;
    }

    /**
     * Formats a Unix timestamp as a local calendar date.
     */
    private function formatAppointmentDate(int $timestamp, DateTimeZone $timezone): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('Y-m-d');
    }

    /**
     * Returns the provider date of an all-day boundary without timezone shifting.
     */
    private function formatAllDayAppointmentDate(string $value, int $timestamp, DateTimeZone $timezone): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }

        return $this->formatAppointmentDate($timestamp, $timezone);
    }

    /**
     * Converts an all-day provider end boundary from exclusive to inclusive.
     */
    private function formatAllDayAppointmentEndDate(
        string $value,
        int $timestamp,
        string $startDate,
        DateTimeZone $timezone
    ): string {
        $endDate = $this->formatAllDayAppointmentDate($value, $timestamp, $timezone);
        if ($endDate === '') {
            return $startDate;
        }
        if ($startDate !== '' && $endDate <= $startDate) {
            return $startDate;
        }

        return (new DateTimeImmutable($endDate, $timezone))->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Formats a Unix timestamp as a local 24-hour clock value.
     */
    private function formatAppointmentTime(int $timestamp, DateTimeZone $timezone): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('H:i');
    }

    /**
     * Reads cached events from one calendar through size-limited transfer pages.
     *
     * @return list<array<string, mixed>>
     */
    private function readCalendarEventsForRange(int $instanceId, int $rangeStart, int $rangeEnd): array
    {
        $metadata = json_decode(
            IPSKAL_BeginEventsTransfer($instanceId, $rangeStart, $rangeEnd),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($metadata)) {
            throw new UnexpectedValueException('The calendar returned invalid event transfer metadata.');
        }

        $token = trim((string) ($metadata['Token'] ?? ''));
        $pageCount = (int) ($metadata['PageCount'] ?? 0);
        $itemCount = (int) ($metadata['ItemCount'] ?? -1);
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1
            || $pageCount < 1
            || $pageCount > 10_000
            || $itemCount < 0) {
            throw new UnexpectedValueException('The calendar returned invalid event transfer metadata.');
        }

        $events = [];
        try {
            for ($page = 0; $page < $pageCount; ++$page) {
                $payload = json_decode(
                    IPSKAL_ReadEventsTransferPage($instanceId, $token, $page),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                if (!is_array($payload)
                    || ($payload['Token'] ?? null) !== $token
                    || (int) ($payload['Page'] ?? -1) !== $page
                    || (int) ($payload['PageCount'] ?? 0) !== $pageCount
                    || (int) ($payload['ItemCount'] ?? -1) !== $itemCount
                    || (bool) ($payload['Complete'] ?? false) !== ($page === $pageCount - 1)
                    || !is_array($payload['Items'] ?? null)
                    || !array_is_list($payload['Items'])) {
                    throw new UnexpectedValueException('The calendar returned an invalid event transfer page.');
                }

                foreach ($payload['Items'] as $event) {
                    if (!is_array($event) || array_is_list($event)) {
                        throw new UnexpectedValueException('The calendar returned invalid event data.');
                    }
                    $events[] = $event;
                }
            }
        } finally {
            try {
                IPSKAL_FinishEventsTransfer($instanceId, $token);
            } catch (Throwable $exception) {
                $this->SendDebug('EventTransferCleanup', $exception->getMessage(), 0);
            }
        }

        if (count($events) !== $itemCount) {
            throw new UnexpectedValueException('The calendar returned an incomplete event transfer.');
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyState(): array
    {
        return [
            'events'      => [],
            'calendars'   => [],
            'generatedAt' => time(),
            'eventRange'  => null,
            'settings'    => $this->viewSettings()
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function viewSettings(): array
    {
        return [
            'defaultView'             => match ($this->ReadPropertyInteger('DefaultView')) {
                1       => 'week',
                2       => 'month',
                3       => 'threeDays',
                4       => 'list',
                5       => 'workWeek',
                default => 'agenda'
            },
            'pastDays'                  => max(0, min(1095, $this->ReadPropertyInteger('PastDays'))),
            'futureDays'                => max(1, min(1095, $this->ReadPropertyInteger('FutureDays'))),
            'agendaPeriodDays'          => max(1, min(366, $this->ReadPropertyInteger('AgendaPeriodDays'))),
            'listPeriodDays'            => max(1, min(366, $this->ReadPropertyInteger('ListPeriodDays'))),
            'threeDaysPeriodDays'       => max(1, min(31, $this->ReadPropertyInteger('ThreeDaysPeriodDays'))),
            'weekPeriodWeeks'           => max(1, min(12, $this->ReadPropertyInteger('WeekPeriodWeeks'))),
            'monthPeriodMonths'         => max(1, min(12, $this->ReadPropertyInteger('MonthPeriodMonths'))),
            'showWeekends'              => $this->ReadPropertyBoolean('ShowWeekends'),
            'showAgendaEventCount'      => $this->ReadPropertyBoolean('ShowAgendaEventCount'),
            'showThreeDaysEventCount'   => $this->ReadPropertyBoolean('ShowThreeDaysEventCount'),
            'showWeekEventCount'        => $this->ReadPropertyBoolean('ShowWeekEventCount'),
            'showAgendaCalendarWeek'    => $this->ReadPropertyBoolean('ShowAgendaCalendarWeek'),
            'showListCalendarWeek'      => $this->ReadPropertyBoolean('ShowListCalendarWeek'),
            'showThreeDaysCalendarWeek' => $this->ReadPropertyBoolean('ShowThreeDaysCalendarWeek'),
            'showWeekCalendarWeek'      => $this->ReadPropertyBoolean('ShowWeekCalendarWeek'),
            'showMonthCalendarWeek'     => $this->ReadPropertyBoolean('ShowMonthCalendarWeek'),
            'showAgendaDayOfYear'       => $this->ReadPropertyBoolean('ShowAgendaDayOfYear'),
            'showListDayOfYear'         => $this->ReadPropertyBoolean('ShowListDayOfYear'),
            'showThreeDaysDayOfYear'    => $this->ReadPropertyBoolean('ShowThreeDaysDayOfYear'),
            'showWeekDayOfYear'         => $this->ReadPropertyBoolean('ShowWeekDayOfYear'),
            'showMonthDayOfYear'        => $this->ReadPropertyBoolean('ShowMonthDayOfYear'),
            'showListDate'              => $this->ReadPropertyBoolean('ShowListDate'),
            'showListStart'             => $this->ReadPropertyBoolean('ShowListStart'),
            'showListEnd'               => $this->ReadPropertyBoolean('ShowListEnd'),
            'showListTitle'             => $this->ReadPropertyBoolean('ShowListTitle'),
            'showListCalendarName'      => $this->ReadPropertyBoolean('ShowListCalendarName'),
            'showListAnniversaryType'   => $this->ReadPropertyBoolean('ShowListAnniversaryType'),
            'showListLocation'          => $this->ReadPropertyBoolean('ShowListLocation'),
            'showListDescription'       => $this->ReadPropertyBoolean('ShowListDescription'),
            'showListControls'          => $this->ReadPropertyBoolean('ShowListControls'),
            'showCalendarName'          => $this->ReadPropertyBoolean('ShowCalendarName'),
            'showAnniversaryType'       => $this->ReadPropertyBoolean('ShowAnniversaryType'),
            'showLocation'              => $this->ReadPropertyBoolean('ShowLocation'),
            'showDescription'           => $this->ReadPropertyBoolean('ShowDescription'),
            'tileFontScale'             => max(50, min(200, $this->ReadPropertyInteger('TileFontScale')))
        ];
    }

    private function scheduleInitialization(): void
    {
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->SetTimerInterval('InitializationTimer', self::INITIALIZATION_DELAY_MS);
        }
    }

    private function isRuntimeReady(): bool
    {
        return IPS_GetKernelRunlevel() === KR_READY
            && $this->ReadAttributeBoolean('RuntimeReady');
    }

    /**
     * @return list<array{instanceId: int, name: string, color: string, canWrite: bool, timezone: string, canCreateRecurrence: bool, canUpdateRecurrence: bool, canUpdateOccurrence: bool, canDeleteOccurrence: bool, canUpdateFollowing: bool, canUpdateSeries: bool, canDeleteSeries: bool, canUseDefaultReminder: bool, canCreateWithDefaultReminder: bool, defaultReminder: array<string, mixed>, maxReminders: int, provider?: string, lastSynchronization?: int, status?: int, lastError?: string}>
     */
    private function loadSelectedCalendars(bool $includeOperationalMetadata = false): array
    {
        $configuration = $this->effectiveCalendarConfiguration();

        $result = [];
        $usedIds = [];
        foreach ($configuration as $row) {
            if (!is_array($row) || !($row['Enabled'] ?? true)) {
                continue;
            }
            $instanceId = (int) ($row['InstanceID'] ?? 0);
            if ($instanceId <= 0 || isset($usedIds[$instanceId]) || !IPS_InstanceExists($instanceId)) {
                continue;
            }
            $instance = IPS_GetInstance($instanceId);
            if (($instance['ModuleInfo']['ModuleID'] ?? '') !== self::CALENDAR_MODULE_ID) {
                continue;
            }
            $usedIds[$instanceId] = true;
            $calendarStatus = [];
            try {
                $decodedStatus = json_decode(IPSKAL_GetCalendarStatus($instanceId), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decodedStatus)) {
                    $calendarStatus = $decodedStatus;
                }
            } catch (Throwable $exception) {
                $this->SendDebug('CalendarStatus', $exception->getMessage(), 0);
            }

            $color = strtoupper(trim((string) ($calendarStatus['calendarColor']
                ?? IPS_GetProperty($instanceId, 'CalendarColor'))));
            if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
                $palette = ['#4F8EF7', '#4FB286', '#E09F3E', '#D65DB1', '#7B61FF', '#EF6F6C', '#2CA6A4'];
                $color = $palette[abs(crc32((string) $instanceId)) % count($palette)];
            }

            $calendar = [
                'instanceId'                   => $instanceId,
                'name'                         => IPS_GetName($instanceId),
                'color'                        => $color,
                'canWrite'                     => (bool) ($calendarStatus['canWrite']
                    ?? IPS_GetProperty($instanceId, 'CanWrite')),
                'timezone'                     => trim((string) ($calendarStatus['timezone'] ?? '')),
                'canCreateRecurrence'          => (bool) ($calendarStatus['canCreateRecurrence'] ?? false),
                'canUpdateRecurrence'          => (bool) ($calendarStatus['canUpdateRecurrence'] ?? false),
                'canUpdateOccurrence'          => (bool) ($calendarStatus['canUpdateOccurrence'] ?? false),
                'canDeleteOccurrence'          => (bool) ($calendarStatus['canDeleteOccurrence'] ?? false),
                'canUpdateFollowing'           => (bool) ($calendarStatus['canUpdateFollowing'] ?? false),
                'canUpdateSeries'              => (bool) ($calendarStatus['canUpdateSeries'] ?? false),
                'canDeleteSeries'              => (bool) ($calendarStatus['canDeleteSeries'] ?? false),
                'canUseDefaultReminder'        => (bool) ($calendarStatus['canUseDefaultReminder'] ?? false),
                'canCreateWithDefaultReminder' => (bool) ($calendarStatus['canCreateWithDefaultReminder'] ?? false),
                'defaultReminder'              => is_array($calendarStatus['defaultReminder'] ?? null)
                    && !array_is_list($calendarStatus['defaultReminder'])
                    ? $calendarStatus['defaultReminder']
                    : [],
                'maxReminders'                 => max(1, min(CalendarEventReminder::MAX_REMINDERS, (int) ($calendarStatus['maxReminders'] ?? 1)))
            ];
            if ($includeOperationalMetadata) {
                $calendar['provider'] = $this->calendarProviderKey($instance);
                $calendar['lastSynchronization'] = max(0, (int) ($calendarStatus['lastSynchronization'] ?? 0));
                $calendar['status'] = (int) ($instance['InstanceStatus'] ?? 0);
                $calendar['lastError'] = trim((string) ($calendarStatus['lastError'] ?? ''));
            }
            $result[] = $calendar;
        }

        return $result;
    }

    /**
     * Returns the stable provider key for one Calendar instance.
     *
     * @param array<string, mixed> $calendarInstance Calendar instance metadata returned by Symcon.
     */
    private function calendarProviderKey(array $calendarInstance): string
    {
        $accountInstanceId = (int) ($calendarInstance['ConnectionID'] ?? 0);
        if ($accountInstanceId <= 0 || !IPS_InstanceExists($accountInstanceId)) {
            return 'unknown';
        }

        $accountInstance = IPS_GetInstance($accountInstanceId);
        if (($accountInstance['ModuleInfo']['ModuleID'] ?? '') !== self::CALENDAR_ACCOUNT_MODULE_ID) {
            return 'unknown';
        }

        return match ((int) IPS_GetProperty($accountInstanceId, 'Provider')) {
            0       => 'apple',
            1       => 'caldav',
            2       => 'google',
            3       => 'microsoft',
            4       => 'ics',
            default => 'unknown'
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function effectiveCalendarConfiguration(): array
    {
        $configured = $this->decodeCalendarConfiguration($this->ReadPropertyString('Calendars'));
        return $configured !== []
            ? $configured
            : $this->decodeCalendarConfiguration($this->ReadAttributeString('CalendarSelectionBackup'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeCalendarConfiguration(string $json): array
    {
        $configuration = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($configuration)) {
            throw new UnexpectedValueException($this->Translate('The calendar selection is invalid.'));
        }

        return array_values(array_filter($configuration, 'is_array'));
    }

    /**
     * @param list<array<string, mixed>> $selection
     */
    private function storeCalendarSelectionBackup(array $selection): void
    {
        $this->WriteAttributeString(
            'CalendarSelectionBackup',
            json_encode(
                $selection,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
    }

    private function recoverCalendarSelectionFromMessages(): void
    {
        if ($this->effectiveCalendarConfiguration() !== []) {
            return;
        }

        $selection = [];
        foreach (array_keys($this->GetMessageList()) as $senderId) {
            $instanceId = (int) $senderId;
            if ($instanceId <= 0 || !IPS_InstanceExists($instanceId)) {
                continue;
            }
            $instance = IPS_GetInstance($instanceId);
            if (($instance['ModuleInfo']['ModuleID'] ?? '') !== self::CALENDAR_MODULE_ID) {
                continue;
            }
            $selection[] = [
                'InstanceID' => $instanceId,
                'Enabled'    => true
            ];
        }

        if ($selection !== []) {
            $this->SendDebug(
                'CalendarSelection',
                'Recovered the calendar selection from existing message subscriptions.',
                0
            );
            $this->storeCalendarSelectionBackup($selection);
        }
    }

    /**
     * Executes the explicitly supported calendar actions for both Symcon and IPSView.
     *
     * @return array{state: array<string, mixed>, level: string, message: string}
     */
    private function executeVisualizationAction(string $ident, mixed $value): array
    {
        $level = 'success';
        $message = '';
        $eventEdit = null;
        $seriesEdit = null;

        switch ($ident) {
            case 'LoadRange':
                $this->validateVisualizationActionRange($value);
                break;

            case 'Refresh':
                $success = $this->synchronizeSelectedCalendars();
                $level = $success ? 'success' : 'error';
                $message = $success ? 'Calendars synchronized.' : 'Synchronization failed.';
                break;

            case 'CreateEvent':
                $request = $this->decodeActionValue($value);
                $instanceId = $this->requireWritableCalendar($request);
                $event = $request['event'] ?? null;
                if (!is_array($event)) {
                    throw new InvalidArgumentException($this->Translate('The event data is invalid.'));
                }
                $result = json_decode(
                    IPSKAL_CreateEvent(
                        $instanceId,
                        json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    ),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                if (!is_array($result) || !($result['success'] ?? false)) {
                    throw new RuntimeException((string) ($result['error'] ?? $this->Translate('Event creation failed.')));
                }
                $message = 'Event created.';
                break;

            case 'PrepareEventEdit':
                $request = $this->decodeActionValue($value);
                $instanceId = $this->requireWritableCalendar($request);
                $event = $request['event'] ?? null;
                if (!is_array($event) || array_is_list($event)) {
                    throw new InvalidArgumentException($this->Translate('The event data is invalid.'));
                }

                $eventEdit = json_decode(
                    IPSKAL_GetEventForEdit(
                        $instanceId,
                        json_encode(
                            $event,
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                        )
                    ),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                if (!is_array($eventEdit) || array_is_list($eventEdit)) {
                    throw new RuntimeException($this->Translate('The event data is invalid.'));
                }

                $calendar = null;
                foreach ($this->loadSelectedCalendars() as $selectedCalendar) {
                    if ($selectedCalendar['instanceId'] === $instanceId) {
                        $calendar = $selectedCalendar;
                        break;
                    }
                }
                if ($calendar === null) {
                    throw new RuntimeException($this->Translate('The selected calendar is not writable.'));
                }

                $eventEdit['calendarInstanceId'] = $instanceId;
                $eventEdit['calendarName'] = $calendar['name'];
                $eventEdit['calendarColor'] = $calendar['color'];
                $eventEdit['canWrite'] = true;
                if (($eventEdit['recurrenceType'] ?? '') === 'occurrence'
                    && trim((string) ($eventEdit['originalStart'] ?? '')) === '') {
                    $eventEdit['originalStart'] = trim((string) ($eventEdit['start'] ?? ''));
                }
                $recurringOccurrence = (bool) ($eventEdit['recurring'] ?? false)
                    && trim((string) ($eventEdit['occurrenceId'] ?? '')) !== ''
                    && trim((string) ($eventEdit['seriesId'] ?? '')) !== '';
                $eventEdit['canUpdateOccurrence'] = (bool) ($eventEdit['canUpdateOccurrence'] ?? false)
                    || ($recurringOccurrence && $calendar['canUpdateOccurrence']);
                $eventEdit['canDeleteOccurrence'] = (bool) ($eventEdit['canDeleteOccurrence'] ?? false)
                    || ($recurringOccurrence && $calendar['canDeleteOccurrence']);
                $eventEdit['canUpdateFollowing'] = (bool) ($eventEdit['canUpdateFollowing'] ?? false)
                    || ($recurringOccurrence && $calendar['canUpdateFollowing']);
                $eventEdit['canUpdateSeries'] = (bool) ($eventEdit['canUpdateSeries'] ?? false)
                    || ((bool) ($eventEdit['recurring'] ?? false)
                        && trim((string) ($eventEdit['seriesId'] ?? '')) !== ''
                        && $calendar['canUpdateSeries']);
                $eventEdit['canDeleteSeries'] = (bool) ($eventEdit['canDeleteSeries'] ?? false)
                    || ((bool) ($eventEdit['recurring'] ?? false)
                        && trim((string) ($eventEdit['seriesId'] ?? '')) !== ''
                        && $calendar['canDeleteSeries']);
                $eventEdit['writeScope'] = 'occurrence';
                break;

            case 'PrepareSeriesEdit':
                $request = $this->decodeActionValue($value);
                $instanceId = $this->requireWritableCalendar($request);
                $seriesId = trim((string) ($request['seriesId'] ?? ''));
                $resourceUrl = trim((string) ($request['resourceUrl'] ?? ''));
                $writeScope = strtolower(trim((string) ($request['writeScope'] ?? 'series')));
                if ($seriesId === '') {
                    throw new InvalidArgumentException($this->Translate('The recurring series ID is missing.'));
                }
                if (!in_array($writeScope, ['following', 'series'], true)) {
                    throw new InvalidArgumentException($this->Translate('The event data is invalid.'));
                }

                if ($writeScope === 'following') {
                    $occurrenceId = trim((string) ($request['occurrenceId'] ?? ''));
                    $originalStart = trim((string) ($request['originalStart'] ?? ''));
                    if ($occurrenceId === '' || $originalStart === '') {
                        throw new InvalidArgumentException($this->Translate('The event data is invalid.'));
                    }
                    $seriesEdit = json_decode(
                        IPSKAL_GetRecurringFollowing($instanceId, $seriesId, $occurrenceId, $originalStart, $resourceUrl),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                    if (!is_array($seriesEdit) || !($seriesEdit['canUpdateFollowing'] ?? false)) {
                        throw new RuntimeException(
                            $this->Translate('This and following updates are not supported by this calendar.')
                        );
                    }
                } else {
                    $seriesEdit = json_decode(
                        IPSKAL_GetRecurringSeries($instanceId, $seriesId, $resourceUrl),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                    if (!is_array($seriesEdit) || !($seriesEdit['canUpdateSeries'] ?? false)) {
                        throw new RuntimeException(
                            $this->Translate('Recurring series updates are not supported by this calendar.')
                        );
                    }
                }
                $seriesEdit['calendarInstanceId'] = $instanceId;
                $seriesEdit['canWrite'] = true;
                $seriesEdit['writeScope'] = $writeScope;
                break;

            case 'UpdateEvent':
                $request = $this->decodeActionValue($value);
                $instanceId = $this->requireWritableCalendar($request);
                $event = $request['event'] ?? null;
                if (!is_array($event)) {
                    throw new InvalidArgumentException($this->Translate('The event data is invalid.'));
                }
                $result = json_decode(
                    IPSKAL_UpdateEvent(
                        $instanceId,
                        json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    ),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                if (!is_array($result) || !($result['success'] ?? false)) {
                    throw new RuntimeException((string) ($result['error'] ?? $this->Translate('Event update failed.')));
                }
                $message = 'Event updated.';
                break;

            case 'MoveEvent':
                $request = $this->decodeActionValue($value);
                $sourceInstanceId = $this->requireWritableCalendar([
                    'calendarInstanceId' => (int) ($request['sourceCalendarInstanceId'] ?? 0)
                ]);
                $targetInstanceId = $this->requireWritableCalendar([
                    'calendarInstanceId' => (int) ($request['targetCalendarInstanceId'] ?? 0)
                ]);
                if ($sourceInstanceId === $targetInstanceId) {
                    throw new InvalidArgumentException($this->Translate('The source and target calendars must be different.'));
                }
                $sourceEvent = $request['sourceEvent'] ?? null;
                $event = $request['event'] ?? null;
                if (!is_array($sourceEvent) || array_is_list($sourceEvent)
                    || !is_array($event) || array_is_list($event)) {
                    throw new InvalidArgumentException($this->Translate('The event data is invalid.'));
                }
                $sourceReminder = is_array($sourceEvent['reminder'] ?? null)
                    ? $sourceEvent['reminder']
                    : [];
                if (($sourceReminder['mode'] ?? '') === 'complex'
                    || ($sourceReminder !== [] && ($sourceReminder['editable'] ?? true) === false)) {
                    throw new RuntimeException(
                        $this->Translate('Events with complex reminder settings cannot be moved safely.')
                    );
                }
                if (($sourceReminder['mode'] ?? '') === CalendarEventReminder::MODE_DEFAULT) {
                    $event['reminder'] = $this->defaultReminderForMove($sourceInstanceId);
                }
                if (array_key_exists('reminder', $event)) {
                    $this->assertReminderSupportedByCalendar($targetInstanceId, $event['reminder']);
                }

                $sourceRecurring = (bool) ($sourceEvent['recurring'] ?? false);
                $writeScope = strtolower(trim((string) ($sourceEvent['writeScope'] ?? '')));
                $targetRecurrenceProvided = array_key_exists('recurrence', $event);
                $targetRecurrence = $event['recurrence'] ?? null;
                $targetRecurring = is_array($targetRecurrence) && $targetRecurrence !== [];
                if ($targetRecurrenceProvided
                    && $targetRecurrence !== null
                    && (!is_array($targetRecurrence) || $targetRecurrence === [] || array_is_list($targetRecurrence))) {
                    throw new InvalidArgumentException($this->Translate('The recurrence settings are invalid.'));
                }

                if ($sourceRecurring) {
                    if (!in_array($writeScope, ['occurrence', 'following', 'series'], true)) {
                        throw new InvalidArgumentException($this->Translate('The event data is invalid.'));
                    }
                    if ($writeScope === 'following' && !$targetRecurring) {
                        throw new RuntimeException($this->Translate('The recurrence pattern cannot be split safely.'));
                    }
                    if ($writeScope === 'series' && !$targetRecurrenceProvided) {
                        throw new RuntimeException($this->Translate('The recurrence pattern cannot be edited safely.'));
                    }
                    if ($writeScope === 'occurrence' && $targetRecurring) {
                        throw new RuntimeException($this->Translate('The recurrence settings are invalid.'));
                    }
                } elseif ($targetRecurring) {
                    $writeScope = '';
                }

                if ($targetRecurring) {
                    $this->requireRecurrenceCreationCalendar($targetInstanceId);
                } elseif ($targetRecurrenceProvided && $targetRecurrence === null) {
                    unset($event['recurrence']);
                }

                $creationResult = json_decode(
                    IPSKAL_CreateEvent(
                        $targetInstanceId,
                        json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    ),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                if (!is_array($creationResult) || !($creationResult['success'] ?? false)) {
                    throw new RuntimeException((string) ($creationResult['error'] ?? $this->Translate('Event move failed.')));
                }

                if (!IPSKAL_DeleteEvent(
                    $sourceInstanceId,
                    json_encode(
                        $sourceEvent,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    )
                )) {
                    if ($this->rollbackMovedTargetEvent($targetInstanceId, $creationResult, $targetRecurring)) {
                        throw new RuntimeException($this->Translate('Event move failed.'));
                    }
                    throw new RuntimeException($this->Translate(
                        'The event was created in the target calendar, but could not be deleted from the source calendar. Please check both calendars.'
                    ));
                }
                $message = 'Event moved.';
                break;

            case 'DeleteEvent':
                $request = $this->decodeActionValue($value);
                $instanceId = $this->requireWritableCalendar($request);
                $event = $request['event'] ?? null;
                if (!is_array($event)) {
                    throw new InvalidArgumentException($this->Translate('The event data is invalid.'));
                }
                if (!IPSKAL_DeleteEvent(
                    $instanceId,
                    json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                )) {
                    $status = json_decode(IPSKAL_GetCalendarStatus($instanceId), true, 512, JSON_THROW_ON_ERROR);
                    $lastError = is_array($status) ? trim((string) ($status['lastError'] ?? '')) : '';
                    throw new RuntimeException(
                        $lastError !== '' ? $lastError : $this->Translate('Event deletion failed.')
                    );
                }
                $message = 'Event deleted.';
                break;

            default:
                throw new InvalidArgumentException(sprintf(
                    $this->Translate('Unsupported visualization action: %s'),
                    $ident
                ));
        }

        $state = $this->buildStateForActionValue($value);
        if ($eventEdit !== null) {
            $state['eventEdit'] = $eventEdit;
        }
        if ($seriesEdit !== null) {
            $state['seriesEdit'] = $seriesEdit;
        }
        if ($ident !== 'LoadRange') {
            $this->broadcastState($state);
        }

        return [
            'state'   => $state,
            'level'   => $level,
            'message' => $message
        ];
    }

    private function synchronizeSelectedCalendars(): bool
    {
        $success = true;
        foreach ($this->loadSelectedCalendars() as $calendar) {
            if (!IPSKAL_Synchronize($calendar['instanceId'])) {
                $success = false;
            }
        }

        return $success;
    }

    private function ipsViewHookAddress(): string
    {
        return 'opencalendar/view/' . $this->InstanceID;
    }

    private function ensureIPSViewToken(): void
    {
        if (
            !method_exists($this, 'ReadAttributeInteger')
            || !method_exists($this, 'WriteAttributeInteger')
        ) {
            return;
        }

        if ($this->ipsViewToken() !== str_repeat('0', 32)) {
            return;
        }

        foreach ([
            self::ATTRIBUTE_IPSVIEW_TOKEN_1,
            self::ATTRIBUTE_IPSVIEW_TOKEN_2,
            self::ATTRIBUTE_IPSVIEW_TOKEN_3,
            self::ATTRIBUTE_IPSVIEW_TOKEN_4
        ] as $attribute) {
            $this->WriteAttributeInteger($attribute, random_int(1, 0x7FFFFFFF));
        }
    }

    private function ipsViewToken(): string
    {
        if (!method_exists($this, 'ReadAttributeInteger')) {
            return str_repeat('0', 32);
        }

        return implode('', array_map(
            fn (string $attribute): string => sprintf('%08x', $this->ReadAttributeInteger($attribute)),
            [
                self::ATTRIBUTE_IPSVIEW_TOKEN_1,
                self::ATTRIBUTE_IPSVIEW_TOKEN_2,
                self::ATTRIBUTE_IPSVIEW_TOKEN_3,
                self::ATTRIBUTE_IPSVIEW_TOKEN_4
            ]
        ));
    }

    /** @param array<string, mixed> $payload */
    private function outputIPSViewResponse(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');

        echo json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Rejects malformed or oversized explicit range requests.
     *
     * LoadRange is the only action whose sole purpose is to retrieve one client-specific
     * interval, so silently falling back to the bootstrap range would return unrelated
     * data and can make concurrent clients overwrite each other's visible state.
     */
    private function validateVisualizationActionRange(mixed $value): void
    {
        $range = $this->visualizationRangeFromActionValue($value);
        if ($range === null) {
            throw new InvalidArgumentException($this->Translate('The visualization request is invalid.'));
        }

        $this->resolveVisualizationRange($range[0], $range[1]);
    }

    /**
     * Builds the visualization state for the range supplied by an interactive action.
     *
     * @return array<string, mixed>
     */
    private function buildStateForActionValue(mixed $value): array
    {
        $range = $this->visualizationRangeFromActionValue($value);
        if ($range === null) {
            return $this->buildState();
        }

        return $this->buildState(
            $range[0],
            $range[1],
            $this->visualizationEventOffsetFromActionValue($value)
        );
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function visualizationRangeFromActionValue(mixed $value): ?array
    {
        $request = $value;
        if (is_string($value)) {
            try {
                $request = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }
        if (!is_array($request) || array_is_list($request)) {
            return null;
        }

        $range = $request['_viewRange'] ?? null;
        if (!is_array($range) || array_is_list($range)) {
            return null;
        }

        $start = (int) ($range['start'] ?? 0);
        $end = (int) ($range['end'] ?? 0);
        if ($start <= 0 || $end <= $start) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * Returns the zero-based event offset requested for a paged visualization range.
     */
    private function visualizationEventOffsetFromActionValue(mixed $value): int
    {
        $request = $value;
        if (is_string($value)) {
            try {
                $request = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return 0;
            }
        }
        if (!is_array($request) || array_is_list($request)) {
            return 0;
        }

        return max(0, (int) ($request['_eventOffset'] ?? 0));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeActionValue(mixed $value): array
    {
        $request = $value;
        if (is_string($value)) {
            $request = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($request) || array_is_list($request)) {
            throw new InvalidArgumentException($this->Translate('The visualization request is invalid.'));
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function requireWritableCalendar(array $request): int
    {
        $instanceId = (int) ($request['calendarInstanceId'] ?? 0);
        foreach ($this->loadSelectedCalendars() as $calendar) {
            if ($calendar['instanceId'] === $instanceId && $calendar['canWrite']) {
                return $instanceId;
            }
        }
        throw new RuntimeException($this->Translate('The selected calendar is not writable.'));
    }

    private function requireRecurrenceCreationCalendar(int $instanceId): void
    {
        foreach ($this->loadSelectedCalendars() as $calendar) {
            if ($calendar['instanceId'] === $instanceId
                && $calendar['canWrite']
                && $calendar['canCreateRecurrence']) {
                return;
            }
        }
        throw new RuntimeException($this->Translate('Recurring event creation is not supported by this calendar.'));
    }

    private function assertReminderSupportedByCalendar(int $instanceId, mixed $value): void
    {
        foreach ($this->loadSelectedCalendars() as $calendar) {
            if ($calendar['instanceId'] !== $instanceId) {
                continue;
            }

            $maxReminders = max(1, min(CalendarEventReminder::MAX_REMINDERS, (int) ($calendar['maxReminders'] ?? 1)));
            try {
                $normalized = CalendarEventReminder::normalizeInput($value, true);
                $reminderCount = count(CalendarEventReminder::minutesBeforeStartValues($normalized));
                if ($reminderCount > $maxReminders) {
                    throw new RuntimeException(sprintf(
                        $this->Translate('This calendar supports up to %d reminders.'),
                        $maxReminders
                    ));
                }
                CalendarEventReminder::normalizeInput(
                    $value,
                    (bool) ($calendar['canUseDefaultReminder'] ?? false),
                    $maxReminders
                );
                return;
            } catch (RuntimeException $exception) {
                throw $exception;
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException(
                    $this->Translate('The selected calendar does not support these reminder settings.'),
                    0,
                    $exception
                );
            }
        }

        throw new RuntimeException($this->Translate('The selected calendar is not writable.'));
    }

    /** @return array<string, mixed> */
    private function defaultReminderForMove(int $instanceId): array
    {
        foreach ($this->loadSelectedCalendars() as $calendar) {
            if ($calendar['instanceId'] !== $instanceId) {
                continue;
            }
            if (!$calendar['canUseDefaultReminder']) {
                break;
            }

            try {
                return CalendarEventReminder::normalizeInput($calendar['defaultReminder']);
            } catch (InvalidArgumentException) {
                break;
            }
        }

        throw new RuntimeException(
            $this->Translate('Events with complex reminder settings cannot be moved safely.')
        );
    }

    /** @param array<string, mixed> $creationResult */
    private function rollbackMovedTargetEvent(int $instanceId, array $creationResult, bool $recurring): bool
    {
        $createdEvent = $creationResult['event'] ?? null;
        if (!is_array($createdEvent) || array_is_list($createdEvent)) {
            return false;
        }

        $rollbackEvent = [
            'uid'         => trim((string) ($createdEvent['uid'] ?? '')),
            'resourceUrl' => trim((string) ($createdEvent['resourceUrl'] ?? '')),
            'etag'        => trim((string) ($createdEvent['etag'] ?? ''))
        ];
        if ($recurring) {
            $seriesId = trim((string) ($createdEvent['eventReference'] ?? ''));
            if ($seriesId === '') {
                $seriesId = $rollbackEvent['uid'];
            }
            if ($seriesId === '') {
                return false;
            }
            $rollbackEvent = array_merge($rollbackEvent, [
                'recurrenceType'  => 'master',
                'seriesId'        => $seriesId,
                'recurring'       => true,
                'canDeleteSeries' => true,
                'writeScope'      => 'series'
            ]);
        }

        try {
            return IPSKAL_DeleteEvent(
                $instanceId,
                json_encode(
                    $rollbackEvent,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                )
            );
        } catch (Throwable $exception) {
            $this->SendDebug('MoveRollback', $exception->getMessage(), 0);
            return false;
        }
    }

    private function sendToast(string $level, string $message): void
    {
        $this->UpdateVisualizationValue(json_encode(
            ['type' => 'toast', 'level' => $level, 'message' => $message],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }
}
