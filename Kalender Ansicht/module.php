<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\ConfigurationFormHelper;
use Burki24\SymconModuleHelper\IPSViewHTMLPageHelper;
use Burki24\SymconModuleHelper\IPSViewStyleHelper;
use Burki24\SymconModuleHelper\VariableHelper;
use Burki24\SymconModuleHelper\VisualizationAssetHelper;
use Burki24\SymconModuleHelper\VisualizationThemeHelper;
use IPSKalender\CalendarAppointmentRange;

require_once __DIR__ . '/../libs/CalendarAppointmentRange.php';
require_once __DIR__ . '/../libs/helper/ConfigurationFormHelper.php';
require_once __DIR__ . '/../libs/helper/IPSViewHTMLPageHelper.php';
require_once __DIR__ . '/../libs/helper/IPSViewStyleHelper.php';
require_once __DIR__ . '/../libs/helper/VariableHelper.php';
require_once __DIR__ . '/../libs/helper/VisualizationAssetHelper.php';
require_once __DIR__ . '/../libs/helper/VisualizationThemeHelper.php';

class KalenderAnsicht extends IPSModuleStrict
{
    use ConfigurationFormHelper;
    use IPSViewHTMLPageHelper;
    use IPSViewStyleHelper;
    use VariableHelper;
    use VisualizationAssetHelper;
    use VisualizationThemeHelper;

    private const CALENDAR_MODULE_ID = '{227B63E4-4223-316B-76E9-FD3849689562}';
    private const INITIALIZATION_DELAY_MS = 5_000;
    private const APPOINTMENT_LOOKAHEAD_DAYS = 1095;
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
        $this->RegisterPropertyBoolean('ShowListLocation', false);
        $this->RegisterPropertyBoolean('ShowListDescription', false);
        $this->RegisterPropertyBoolean('ShowListControls', true);
        $this->RegisterPropertyBoolean('ShowCalendarName', true);
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

        $this->broadcastState();

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
                $this->broadcastState();
            }

            return;
        }
        if (!$this->isRuntimeReady()) {
            return;
        }

        $this->broadcastState();
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
        $this->broadcastState();

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
     * Each result contains only summary, start, end, startTime and endTime.
     * Start and end are local YYYY-MM-DD dates. Timed appointments use local
     * HH:MM values while all-day appointments use the localized "All day"
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
        $appointments = $this->collectAppointmentsForRange($rangeStart, $rangeEnd);

        return json_encode(
            $this->compactAppointments($this->filterAppointmentsByCalendarInstanceId($appointments, $CalendarInstanceID)),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
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
        if ($Count < 1 || $Count > 1000) {
            throw new InvalidArgumentException('Count must be between 1 and 1000.');
        }

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
     * Returns the calendar instances selected and enabled in this Calendar View.
     *
     * The result contains instanceId, name, color, canWrite, timezone and recurring-event capabilities for each selected calendar.
     * Client-side temporary calendar filters do not alter this configured selection.
     *
     * @return string JSON-encoded selected calendar list.
     */
    public function GetSelectedCalendars(): string
    {
        return json_encode(
            $this->loadSelectedCalendars(),
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

    private function broadcastState(?array $state = null): void
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

        if ($this->IsIPSViewHTMLPageEnabled()) {
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
            'Start',
            'End',
            'Location',
            'Description',
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
            'Changes will apply to the entire recurring series.',
            'The recurrence pattern of this series cannot be edited here.',
            'Edit recurring event',
            'Which events do you want to edit?',
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
    private function buildState(): array
    {
        if (!$this->isRuntimeReady()) {
            return $this->emptyState();
        }

        $calendars = $this->loadSelectedCalendars();
        $events = [];
        $pastDays = max(0, min(1095, $this->ReadPropertyInteger('PastDays')));
        $futureDays = max(1, min(1095, $this->ReadPropertyInteger('FutureDays')));
        $rangeStart = (new DateTimeImmutable('today'))->modify('-' . $pastDays . ' days')->getTimestamp();
        $rangeEnd = (new DateTimeImmutable('today'))->modify('+' . ($futureDays + 1) . ' days')->getTimestamp();

        foreach ($calendars as $calendar) {
            try {
                $calendarEvents = $this->readCalendarEventsForRange(
                    $calendar['instanceId'],
                    $rangeStart,
                    $rangeEnd
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

        usort(
            $events,
            static fn (array $left, array $right): int => ((int) $left['startTimestamp'] <=> (int) $right['startTimestamp'])
                ?: strcasecmp((string) ($left['summary'] ?? ''), (string) ($right['summary'] ?? ''))
        );
        $events = array_slice($events, 0, max(1, min(1000, $this->ReadPropertyInteger('MaxEvents'))));

        return [
            'events'      => $events,
            'calendars'   => array_values($calendars),
            'generatedAt' => time(),
            'settings'    => $this->viewSettings()
        ];
    }

    /**
     * Collects provider-independent appointments from every selected calendar.
     *
     * @return list<array<string, mixed>>
     */
    private function collectAppointmentsForRange(
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd
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
     * Reduces full appointment data to the compact scripting representation.
     *
     * @param list<array<string, mixed>> $appointments Full normalized appointments.
     * @return list<array{summary: string, start: string, end: string, startTime: string, endTime: string}>
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
                'summary'   => (string) ($appointment['summary'] ?? ''),
                'start'     => $startDate,
                'end'       => $endDate,
                'startTime' => $allDay ? $this->Translate('All day') : $this->formatAppointmentTime($startTimestamp, $timezone),
                'endTime'   => $allDay ? '' : $this->formatAppointmentTime($endTimestamp, $timezone)
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
                default => 'agenda'
            },
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
            'showListLocation'          => $this->ReadPropertyBoolean('ShowListLocation'),
            'showListDescription'       => $this->ReadPropertyBoolean('ShowListDescription'),
            'showListControls'          => $this->ReadPropertyBoolean('ShowListControls'),
            'showCalendarName'          => $this->ReadPropertyBoolean('ShowCalendarName'),
            'showLocation'              => $this->ReadPropertyBoolean('ShowLocation'),
            'showDescription'           => $this->ReadPropertyBoolean('ShowDescription'),
            'tileFontScale'             => max(50, min(200, $this->ReadPropertyInteger('TileFontScale'))),
            'tileWeekOrientation'       => $this->ReadPropertyInteger('TileWeekOrientation') === 1
                ? 'vertical'
                : 'horizontal',
            'ipsViewWeekOrientation'    => $this->ReadPropertyInteger('IPSViewWeekOrientation') === 1
                ? 'vertical'
                : 'horizontal'
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
     * @return list<array{instanceId: int, name: string, color: string, canWrite: bool, timezone: string, canCreateRecurrence: bool, canUpdateSeries: bool, canDeleteSeries: bool}>
     */
    private function loadSelectedCalendars(): array
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

            $result[] = [
                'instanceId'          => $instanceId,
                'name'                => IPS_GetName($instanceId),
                'color'               => $color,
                'canWrite'            => (bool) ($calendarStatus['canWrite']
                    ?? IPS_GetProperty($instanceId, 'CanWrite')),
                'timezone'            => trim((string) ($calendarStatus['timezone'] ?? '')),
                'canCreateRecurrence' => (bool) ($calendarStatus['canCreateRecurrence'] ?? false),
                'canUpdateSeries'     => (bool) ($calendarStatus['canUpdateSeries'] ?? false),
                'canDeleteSeries'     => (bool) ($calendarStatus['canDeleteSeries'] ?? false)
            ];
        }

        return $result;
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
        $seriesEdit = null;

        switch ($ident) {
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

            case 'PrepareSeriesEdit':
                $request = $this->decodeActionValue($value);
                $instanceId = $this->requireWritableCalendar($request);
                $seriesId = trim((string) ($request['seriesId'] ?? ''));
                if ($seriesId === '') {
                    throw new InvalidArgumentException($this->Translate('The recurring series ID is missing.'));
                }
                $seriesEdit = json_decode(
                    IPSKAL_GetRecurringSeries($instanceId, $seriesId),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                if (!is_array($seriesEdit) || !($seriesEdit['canUpdateSeries'] ?? false)) {
                    throw new RuntimeException($this->Translate('Recurring series updates are not supported by this calendar.'));
                }
                $seriesEdit['calendarInstanceId'] = $instanceId;
                $seriesEdit['writeScope'] = 'series';
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
                if (!is_array($sourceEvent) || !is_array($event)) {
                    throw new InvalidArgumentException($this->Translate('The event data is invalid.'));
                }
                if (($sourceEvent['recurring'] ?? false) || trim((string) ($sourceEvent['recurrenceId'] ?? '')) !== '') {
                    throw new RuntimeException($this->Translate('Recurring events cannot be moved yet.'));
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
                        [
                            'resourceUrl'  => (string) ($sourceEvent['resourceUrl'] ?? ''),
                            'etag'         => (string) ($sourceEvent['etag'] ?? ''),
                            'recurrenceId' => (string) ($sourceEvent['recurrenceId'] ?? '')
                        ],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    )
                )) {
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

        $state = $this->buildState();
        if ($seriesEdit !== null) {
            $state['seriesEdit'] = $seriesEdit;
        }
        $this->broadcastState($state);

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

    private function sendToast(string $level, string $message): void
    {
        $this->UpdateVisualizationValue(json_encode(
            ['type' => 'toast', 'level' => $level, 'message' => $message],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }
}
