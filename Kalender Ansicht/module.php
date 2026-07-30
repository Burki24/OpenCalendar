<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\ConfigurationFormHelper;
use Burki24\SymconModuleHelper\IPSViewHTMLPageHelper;
use Burki24\SymconModuleHelper\IPSViewStyleHelper;
use Burki24\SymconModuleHelper\VariableHelper;
use Burki24\SymconModuleHelper\VisualizationAssetHelper;
use Burki24\SymconModuleHelper\VisualizationThemeHelper;

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
        $this->RegisterPropertyInteger('PastDays', 0);
        $this->RegisterPropertyInteger('FutureDays', 31);
        $this->RegisterPropertyInteger('MaxEvents', 250);
        $this->RegisterPropertyBoolean('ShowWeekends', true);
        $this->RegisterPropertyBoolean('ShowDayOfYear', true);
        $this->RegisterPropertyBoolean('ShowCalendarName', true);
        $this->RegisterPropertyBoolean('ShowLocation', true);
        $this->RegisterPropertyBoolean('ShowDescription', false);
        $this->RegisterIPSViewHTMLPageProperties();
        $this->RegisterPropertyInteger('IPSViewColorBarWidth', 7);
        $this->RegisterPropertyInteger('IPSViewWeekOrientation', 0);
        $this->RegisterIPSViewStyleProperties();
        $this->RegisterAttributeBoolean('RuntimeReady', false);
        $this->RegisterAttributeString('CalendarSelectionBackup', '[]');

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
     * Migrates the former calendar-specific IPSView palette to the shared style system.
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
            10
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
            $calendars = $this->getSelectedCalendars();
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

                case 'Refresh':
                    $success = $this->SynchronizeCalendars();
                    $this->sendToast(
                        $success ? 'success' : 'error',
                        $success ? 'Calendars synchronized.' : 'Synchronization failed.'
                    );
                    break;

                case 'CreateEvent':
                    $request = $this->decodeActionValue($Value);
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
                    $this->sendToast('success', 'Event created.');
                    $this->broadcastState();
                    break;

                case 'UpdateEvent':
                    $request = $this->decodeActionValue($Value);
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
                    $this->sendToast('success', 'Event updated.');
                    $this->broadcastState();
                    break;

                case 'DeleteEvent':
                    $request = $this->decodeActionValue($Value);
                    $instanceId = $this->requireWritableCalendar($request);
                    $event = $request['event'] ?? null;
                    if (!is_array($event)) {
                        throw new InvalidArgumentException($this->Translate('The event data is invalid.'));
                    }
                    if (!IPSKAL_DeleteEvent(
                        $instanceId,
                        json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    )) {
                        throw new RuntimeException($this->Translate('Event deletion failed.'));
                    }
                    $this->sendToast('success', 'Event deleted.');
                    $this->broadcastState();
                    break;

                default:
                    throw new InvalidArgumentException(sprintf($this->Translate('Unsupported visualization action: %s'), $Ident));
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
        $success = true;
        foreach ($this->getSelectedCalendars() as $calendar) {
            if (!IPSKAL_Synchronize($calendar['instanceId'])) {
                $success = false;
            }
        }
        $this->broadcastState();
        return $success;
    }

    /**
     * Selects every available calendar instance and reapplies the visualization configuration.
     *
     * @return bool False when no calendar instances are available.
     */
    public function SelectAllCalendars(): bool
    {
        $selection = array_map(
            static fn(int $instanceId): array => [
                'InstanceID' => $instanceId,
                'Enabled'    => true
            ],
            IPS_GetInstanceListByModuleID(self::CALENDAR_MODULE_ID)
        );
        if ($selection === []) {
            return false;
        }

        $encoded = json_encode(
            $selection,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        IPS_SetProperty($this->InstanceID, 'Calendars', $encoded);
        $this->WriteAttributeString('CalendarSelectionBackup', $encoded);
        IPS_ApplyChanges($this->InstanceID);

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
     * Renders the standalone HTML representation used by IPSView.
     *
     * @return string Rendered IPSView calendar HTML.
     */
    public function GetIPSViewHTML(): string
    {
        return $this->renderCalendarHtml($this->buildState(), true);
    }

    private function broadcastState(): void
    {
        if (!$this->isRuntimeReady()) {
            return;
        }

        try {
            $state = $this->buildState();
        } catch (Throwable $exception) {
            $this->SendDebug('CalendarState', $exception->getMessage(), 0);
            return;
        }

        try {
            $this->UpdateVisualizationValue($this->getFullUpdateMessage($state));
        } catch (Throwable $exception) {
            $this->SendDebug('VisualizationUpdate', $exception->getMessage(), 0);
        }

        if ($this->IsIPSViewHTMLPageEnabled()) {
            try {
                $this->UpdateIPSViewHTMLVariable(
                    'IPSViewCalendar',
                    $this->renderCalendarHtml($state, true)
                );
            } catch (Throwable $exception) {
                $this->SendDebug('IPSViewUpdate', $exception->getMessage(), 0);
            }
        }
    }

    /**
     * @param array<string, mixed>|null $state
     */
    private function getFullUpdateMessage(?array $state = null): string
    {
        return json_encode(
            ['type' => 'state', 'payload' => $state ?? $this->buildState()],
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

        return $this->RenderVisualizationHTMLPage($ipsView, [
            'language'           => $this->Translate('Today') === 'Heute' ? 'de' : 'en',
            'classes'            => $ipsView ? ['ipsview-mode'] : [],
            'rootFontSize'       => $ipsView ? $this->IPSViewStyleRootFontSize() : '100%',
            'title'              => $this->Translate('Calendar'),
            'visualizationTheme' => $this->VisualizationThemeCSS(),
            'ipsViewStyle'       => $ipsView ? $this->IPSViewStyleCSSVariables(':root') : '',
            'state'              => $state,
            'runtime'            => null,
            'translations'       => $translations,
            'options'            => [
                'agendaColorBarWidth' => $ipsView
                    ? max(2, min(16, $this->ReadPropertyInteger('IPSViewColorBarWidth')))
                    : 5,
                'compactColorBarWidth' => $ipsView
                    ? max(2, min(16, $this->ReadPropertyInteger('IPSViewColorBarWidth')))
                    : 3
            ]
        ]);
    }

    /** @return list<string> */
    private function calendarVisualizationTranslationKeys(): array
    {
        return [
            'Agenda',
            '3 Days',
            'Week',
            'Month',
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
            'Untitled event',
            'more',
            'Create event',
            'Event details',
            'Calendar',
            'Title',
            'Start',
            'End',
            'Location',
            'Description',
            'Cancel',
            'Save',
            'Delete',
            'Close',
            'Tomorrow',
            'Yesterday',
            'Recurring occurrences are currently read-only.',
            'This calendar is read-only.',
            'Editing events is only available in the Symcon tile.',
            'The description of Microsoft online meetings is protected and cannot be edited here.',
            'Delete this event?'
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

        $calendars = $this->getSelectedCalendars();
        $events = [];
        $pastDays = max(0, min(1095, $this->ReadPropertyInteger('PastDays')));
        $futureDays = max(1, min(1095, $this->ReadPropertyInteger('FutureDays')));
        $rangeStart = (new DateTimeImmutable('today'))->modify('-' . $pastDays . ' days')->getTimestamp();
        $rangeEnd = (new DateTimeImmutable('today'))->modify('+' . ($futureDays + 1) . ' days')->getTimestamp();

        foreach ($calendars as $calendar) {
            try {
                $calendarEvents = json_decode(IPSKAL_GetEvents($calendar['instanceId']), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                $this->SendDebug('CalendarData', $exception->getMessage(), 0);
                continue;
            }
            if (!is_array($calendarEvents)) {
                continue;
            }

            foreach ($calendarEvents as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $startTimestamp = (int) ($event['startTimestamp'] ?? 0);
                $endTimestamp = (int) ($event['endTimestamp'] ?? $startTimestamp);
                if ($startTimestamp <= 0 || $endTimestamp < $rangeStart || $startTimestamp >= $rangeEnd) {
                    continue;
                }
                $event['calendarInstanceId'] = $calendar['instanceId'];
                $event['calendarName'] = $calendar['name'];
                $event['calendarColor'] = $calendar['color'];
                $event['canWrite'] = $calendar['canWrite'];
                $events[] = $event;
            }
        }

        usort(
            $events,
            static fn(array $left, array $right): int => ((int) $left['startTimestamp'] <=> (int) $right['startTimestamp'])
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
            'defaultView'      => match ($this->ReadPropertyInteger('DefaultView')) {
                1       => 'week',
                2       => 'month',
                3       => 'threeDays',
                default => 'agenda'
            },
            'showWeekends'     => $this->ReadPropertyBoolean('ShowWeekends'),
            'showDayOfYear'    => $this->ReadPropertyBoolean('ShowDayOfYear'),
            'showCalendarName' => $this->ReadPropertyBoolean('ShowCalendarName'),
            'showLocation'     => $this->ReadPropertyBoolean('ShowLocation'),
            'showDescription'  => $this->ReadPropertyBoolean('ShowDescription'),
            'tileWeekOrientation' => $this->ReadPropertyInteger('TileWeekOrientation') === 1
                ? 'vertical'
                : 'horizontal',
            'ipsViewWeekOrientation' => $this->ReadPropertyInteger('IPSViewWeekOrientation') === 1
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
     * @return list<array{instanceId: int, name: string, color: string, canWrite: bool}>
     */
    private function getSelectedCalendars(): array
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
                'instanceId' => $instanceId,
                'name'       => IPS_GetName($instanceId),
                'color'      => $color,
                'canWrite'   => (bool) ($calendarStatus['canWrite']
                    ?? IPS_GetProperty($instanceId, 'CanWrite'))
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
     * @return array<string, mixed>
     */
    private function decodeActionValue(mixed $value): array
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($this->Translate('The visualization request is invalid.'));
        }
        $request = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
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
        foreach ($this->getSelectedCalendars() as $calendar) {
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
