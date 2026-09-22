<?php

declare(strict_types=1);

use IPSKalender\AttachmentOwnerIdentity;
use IPSKalender\AttachmentUploadPolicy;
use IPSKalender\CalendarAttachmentPolicy;
use IPSKalender\LocalAttachmentStore;

require_once __DIR__ . '/../libs/CalendarAttachmentPolicy.php';
require_once __DIR__ . '/../libs/AttachmentOwnerIdentity.php';
require_once __DIR__ . '/../libs/LocalAttachmentStore.php';
require_once __DIR__ . '/../libs/AttachmentUploadPolicy.php';

use Burki24\SymconModuleHelper\ChunkedJsonTransferHelper;
use Burki24\SymconModuleHelper\ConfigurationFormHelper;
use Burki24\SymconModuleHelper\DataFlowHelper;
use Burki24\SymconModuleHelper\DebugHelper;
use Burki24\SymconModuleHelper\PersistentJsonCacheHelper;
use Burki24\SymconModuleHelper\VariableHelper;
use IPSKalender\CalDAVResourceIdentity;
use IPSKalender\CalendarEventCounter;
use IPSKalender\CalendarEventDeletion;
use IPSKalender\CalendarEventLookup;
use IPSKalender\CalendarEventRecurrence;
use IPSKalender\CalendarEventState;
use IPSKalender\CalendarProviderError;
use IPSKalender\CalendarProviderErrorException;
use IPSKalender\CalendarRecurrenceRule;
use IPSKalender\CalendarTaskEvent;
use IPSKalender\LocalCalendarProvider;
use IPSKalender\MicrosoftTodoTaskProjection;
use IPSKalender\SynchronizationSchedule;

require_once __DIR__ . '/../libs/helper/ChunkedJsonTransferHelper.php';
require_once __DIR__ . '/../libs/helper/ConfigurationFormHelper.php';
require_once __DIR__ . '/../libs/helper/DataFlowHelper.php';
require_once __DIR__ . '/../libs/helper/DebugHelper.php';
require_once __DIR__ . '/../libs/helper/PersistentJsonCacheHelper.php';
require_once __DIR__ . '/../libs/helper/VariableHelper.php';
require_once __DIR__ . '/../libs/CalendarEventCounter.php';
require_once __DIR__ . '/../libs/CalDAVResourceIdentity.php';
require_once __DIR__ . '/../libs/CalendarTaskEvent.php';
require_once __DIR__ . '/../libs/CalendarEventDeletion.php';
require_once __DIR__ . '/../libs/CalendarEventLookup.php';
require_once __DIR__ . '/../libs/CalendarEventRecurrence.php';
require_once __DIR__ . '/../libs/CalendarRecurrenceRule.php';
require_once __DIR__ . '/../libs/CalendarEventState.php';
require_once __DIR__ . '/../libs/CalendarProviderError.php';
require_once __DIR__ . '/../libs/SynchronizationSchedule.php';
require_once __DIR__ . '/../libs/LocalCalendarProvider.php';
require_once __DIR__ . '/../libs/MicrosoftTodoTaskProjection.php';

class Calendar extends IPSModuleStrict
{
    use ChunkedJsonTransferHelper;
    use ConfigurationFormHelper;
    use DataFlowHelper;
    use DebugHelper;
    use PersistentJsonCacheHelper;
    use VariableHelper;

    private const DATA_ID_TO_PARENT = '{4E535B1D-69C7-AC77-1372-0282B21BAEC9}';
    private const DATA_ID_FROM_PARENT = '{8ED646DD-88E9-ACE2-95D5-9766EED4B5B0}';
    private const EVENT_TRANSFER_SCOPE = 'CalendarCachedEvents';
    private const LOCAL_EVENT_TRANSFER_SCOPE = 'LocalCalendarEvents';
    private const ATTACHMENT_BACKUP_TRANSFER_SCOPE = 'LocalAttachmentBackup';
    private const INITIALIZATION_DELAY_MS = 3_000;
    private const LOCAL_EXPORT_DIRECTORY = 'media' . DIRECTORY_SEPARATOR . 'OpenCalendar';

    private const STATUS_CONFIGURATION_MISSING = 201;
    private const STATUS_SYNCHRONIZATION_FAILED = 202;
    private const STATUS_INVALID_RESPONSE = 203;
    private const STATUS_WRITE_CONFLICT = 204;

    private const ANNIVERSARY_TYPE_BIRTHDAY = 'birthday';
    private const ANNIVERSARY_TYPE_ANNIVERSARY = 'anniversary';
    private const ANNIVERSARY_TYPE_WEDDING = 'wedding';
    private const ANNIVERSARY_TYPE_DEATH = 'death';
    private const ANNIVERSARY_TYPES = [
        self::ANNIVERSARY_TYPE_BIRTHDAY,
        self::ANNIVERSARY_TYPE_ANNIVERSARY,
        self::ANNIVERSARY_TYPE_WEDDING,
        self::ANNIVERSARY_TYPE_DEATH
    ];

    private const CALENDAR_METADATA_ATTRIBUTES = [
        'resolvedCalendarId'           => ['name' => 'ResolvedCalendarID', 'type' => 'string', 'default' => ''],
        'calendarColor'                => ['name' => 'DetectedCalendarColor', 'type' => 'string', 'default' => ''],
        'canWrite'                     => ['name' => 'DetectedCanWrite', 'type' => 'boolean', 'default' => false],
        'canCreateRecurrence'          => ['name' => 'DetectedCanCreateRecurrence', 'type' => 'boolean', 'default' => false],
        'canUpdateRecurrence'          => ['name' => 'DetectedCanUpdateRecurrence', 'type' => 'boolean', 'default' => false],
        'canUpdateOccurrence'          => ['name' => 'DetectedCanUpdateOccurrence', 'type' => 'boolean', 'default' => false],
        'canDeleteOccurrence'          => ['name' => 'DetectedCanDeleteOccurrence', 'type' => 'boolean', 'default' => false],
        'canUpdateFollowing'           => ['name' => 'DetectedCanUpdateFollowing', 'type' => 'boolean', 'default' => false],
        'canUpdateSeries'              => ['name' => 'DetectedCanUpdateSeries', 'type' => 'boolean', 'default' => false],
        'canDeleteSeries'              => ['name' => 'DetectedCanDeleteSeries', 'type' => 'boolean', 'default' => false],
        'canUseDefaultReminder'        => ['name' => 'DetectedCanUseDefaultReminder', 'type' => 'boolean', 'default' => false],
        'canCreateWithDefaultReminder' => ['name' => 'DetectedCanCreateWithDefaultReminder', 'type' => 'boolean', 'default' => false],
        'canWriteStatus'               => ['name' => 'DetectedCanWriteStatus', 'type' => 'boolean', 'default' => false],
        'canWriteTransparency'         => ['name' => 'DetectedCanWriteTransparency', 'type' => 'boolean', 'default' => false],
        'defaultStatus'                => ['name' => 'DetectedDefaultStatus', 'type' => 'string', 'default' => CalendarEventState::STATUS_CONFIRMED],
        'defaultTransparency'          => ['name' => 'DetectedDefaultTransparency', 'type' => 'string', 'default' => CalendarEventState::TRANSP_OPAQUE],
        'defaultAllDayTransparency'    => ['name' => 'DetectedDefaultAllDayTransparency', 'type' => 'string', 'default' => CalendarEventState::TRANSP_OPAQUE],
        'maxReminders'                 => ['name' => 'DetectedMaxReminders', 'type' => 'integer', 'default' => 1],
        'defaultReminderJson'          => ['name' => 'DetectedDefaultReminder', 'type' => 'string', 'default' => '{}'],
        'calendarTimezone'             => ['name' => 'DetectedCalendarTimezone', 'type' => 'string', 'default' => ''],
        'writeAccessKnown'             => ['name' => 'DetectedWriteAccessKnown', 'type' => 'boolean', 'default' => false],
        'available'                    => ['name' => 'CalendarMetadataAvailable', 'type' => 'boolean', 'default' => false]
    ];

    /**
     * Registers properties, attributes, variables, and timers for the calendar instance.
     */
    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyInteger('AttachmentMode', 0);
        $this->RegisterPropertyBoolean('AttachmentAllowLocal', false);
        $this->RegisterPropertyBoolean('AttachmentAllowProvider', false);
        $this->RegisterPropertyBoolean('LocalCalendar', false);
        $this->RegisterPropertyString('CalendarID', '');
        $this->RegisterPropertyString('ProviderCalendarID', '');
        $this->RegisterPropertyString('CalendarURL', '');
        $this->RegisterPropertyString('CalendarColor', '');
        $this->RegisterPropertyBoolean('CanWrite', false);
        $this->RegisterPropertyString('MicrosoftTaskListID', '');
        $this->RegisterPropertyInteger('MicrosoftTaskReopenMode', 0);
        $this->RegisterPropertyInteger('UpdateSchedule', SynchronizationSchedule::CUSTOM);
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyInteger('PastDays', 30);
        $this->RegisterPropertyInteger('FutureDays', 365);

        $this->RegisterPersistentJsonCache('CachedEvents');
        $this->RegisterPersistentJsonCache('CachedMicrosoftTasks');
        $this->RegisterAttributeString('AnniversaryMetadata', '[]');
        $this->RegisterAttributeString('BirthdayMetadata', '[]');
        $this->RegisterAttributeString('PendingTaskSeries', '{}');
        // Original calendar objects, never part of the disposable event cache.
        $this->RegisterAttributeString('LocalCalendarResources', '{}');
        $this->RegisterAttributeString('LocalAttachmentOriginals', '');
        $this->RegisterAttributeInteger('LastSynchronization', 0);
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeString('IncrementalSyncToken', '');
        $this->RegisterAttributeInteger('IncrementalSyncWindowStart', 0);
        $this->RegisterAttributeInteger('IncrementalSyncWindowEnd', 0);
        $this->RegisterAttributeString('IncrementalSyncCalendarID', '');
        $this->RegisterAttributeString('MicrosoftTaskDeltaLink', '');
        $this->RegisterAttributeString('MicrosoftTaskSyncListID', '');
        $this->RegisterAttributeString('MicrosoftTaskLastError', '');
        $this->registerCalendarMetadataAttributes();
        $this->RegisterAttributeBoolean('RuntimeReady', false);

        $this->RegisterVariableInteger('EventCount', $this->Translate('Event count'), [], 10);
        $this->RegisterVariableInteger('TodayEventCount', $this->Translate("Today's events"), [], 20);
        $this->RegisterVariableInteger(
            'LastSynchronization',
            $this->Translate('Last synchronization'),
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
                'TEMPLATE'     => VARIABLE_TEMPLATE_DATE_TIME
            ],
            30
        );

        $this->RegisterTimer('InitializationTimer', 0, 'IPSKAL_Initialize($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SynchronizationTimer', 0, 'IPSKAL_ScheduledSynchronize($_IPS[\'TARGET\']);');
        $this->RegisterTimer('DayChangeTimer', 0, 'IPSKAL_RefreshTodayEventCount($_IPS[\'TARGET\']);');
    }

    /**
     * Builds the configuration form with schedule-dependent field visibility.
     *
     * @return string JSON-encoded configuration form.
     */
    public function GetConfigurationForm(): string
    {
        $form = $this->LoadConfigurationForm();
        $customSchedule = $this->ReadPropertyInteger('UpdateSchedule') === SynchronizationSchedule::CUSTOM;
        $local = $this->ReadPropertyBoolean('LocalCalendar');
        $taskListOptions = $this->microsoftTaskListOptions();
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'UpdateInterval') {
                $element['visible'] = !$local && $customSchedule;
            }
            if (($element['name'] ?? '') === 'UpdateSchedule') {
                $element['visible'] = !$local;
            }
            if (($element['name'] ?? '') === 'LocalCalendarNotice') {
                $element['visible'] = $local;
            }
            if (($element['name'] ?? '') === 'MicrosoftTaskListID') {
                $element['visible'] = !$local && count($taskListOptions) > 1;
                $element['options'] = $taskListOptions;
            }
            if (($element['name'] ?? '') === 'MicrosoftTaskReopenSettings') {
                $element['visible'] = !$local && (count($taskListOptions) > 1
                    || trim($this->ReadPropertyString('MicrosoftTaskListID')) !== '');
            }
            if (($element['caption'] ?? '') === 'Calendar identity') {
                foreach ($element['items'] as &$item) {
                    if (($item['name'] ?? '') === 'CalendarColor') {
                        $item['enabled'] = $local;
                    }
                }
                unset($item);
            }
        }
        unset($element);

        if ($local) {
            foreach ($form['actions'] as &$action) {
                if (($action['caption'] ?? '') === 'Synchronize now') {
                    $action['caption'] = 'Refresh local calendar';
                }
                if (($action['name'] ?? '') === 'SynchronizationSuccessPopup') {
                    $action['popup']['items'][0]['caption'] = 'Local calendar refreshed.';
                }
                if (($action['name'] ?? '') === 'SynchronizationFailurePopup') {
                    $action['popup']['items'][0]['caption'] = 'Local calendar refresh failed. Please check the instance status for details.';
                }
            }
            unset($action);
        }

        return $this->EncodeConfigurationForm($form);
    }

    /**
     * Updates the custom interval field for the selected synchronization schedule.
     */
    public function UpdateScheduleForm(int $schedule): void
    {
        $this->UpdateFormField(
            'UpdateInterval',
            'visible',
            $schedule === SynchronizationSchedule::CUSTOM
        );
    }

    /**
     * Handles actions triggered from the instance configuration form.
     *
     * @param string $Ident Action identifier supplied by Symcon.
     * @param mixed  $Value Action value supplied by Symcon.
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'FormSynchronize':
                $this->UpdateFormField(
                    $this->Synchronize() ? 'SynchronizationSuccessPopup' : 'SynchronizationFailurePopup',
                    'visible',
                    true
                );
                break;

            default:
                throw new InvalidArgumentException('Unsupported form action: ' . $Ident);
        }
    }

    /**
     * Applies the current configuration and schedules runtime initialization.
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->removeLegacyEventsVariable();
        $this->WriteAttributeBoolean('RuntimeReady', false);
        $this->clearIncrementalSyncState();
        $this->resetMicrosoftTaskSyncForSelection();
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->SetTimerInterval('InitializationTimer', 0);
        $this->SetTimerInterval('SynchronizationTimer', 0);
        $this->SetTimerInterval('DayChangeTimer', 0);
        $this->updateEventCounters($this->readEvents());

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->scheduleTodayEventCountRefresh();
        }

        $validationError = $this->validateConfiguration();
        if ($validationError !== '') {
            $this->WriteAttributeString('LastError', $validationError);
            $this->SetStatus(self::STATUS_CONFIGURATION_MISSING);
            return;
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->scheduleInitialization();
    }

    /**
     * Handles Symcon messages relevant to calendar initialization.
     *
     * @param array<int, mixed> $Data Message payload supplied by Symcon.
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($SenderID === 0 && $Message === IPS_KERNELSTARTED) {
            $this->scheduleInitialization();
            $this->scheduleTodayEventCountRefresh();
        }
    }

    /**
     * Initializes runtime state, metadata, and synchronization timers after the kernel is ready.
     *
     * @return bool True when initialization completed.
     */
    public function Initialize(): bool
    {
        $this->SetTimerInterval('InitializationTimer', 0);
        if (IPS_GetKernelRunlevel() !== KR_READY
            || !$this->ReadPropertyBoolean('Active')
            || $this->validateConfiguration() !== '') {
            return false;
        }

        $this->WriteAttributeBoolean('RuntimeReady', true);
        $this->SetTimerInterval(
            'SynchronizationTimer',
            $this->ReadPropertyBoolean('LocalCalendar') ? 0 : SynchronizationSchedule::timerInterval(
                $this->ReadPropertyInteger('UpdateSchedule'),
                $this->ReadPropertyInteger('UpdateInterval')
            )
        );
        $this->refreshCalendarMetadataSafely();
        $this->updateEventCounters($this->readEvents());
        $this->scheduleTodayEventCountRefresh();
        $this->SetStatus(IS_ACTIVE);

        if ($this->ReadPropertyBoolean('LocalCalendar')) {
            return $this->Synchronize();
        }

        return true;
    }

    /**
     * Runs synchronization when the configured schedule is due.
     *
     * @return bool True when no synchronization was due or synchronization succeeded.
     */
    public function ScheduledSynchronize(): bool
    {
        if (!SynchronizationSchedule::isDue(
            $this->ReadPropertyInteger('UpdateSchedule'),
            $this->ReadPropertyInteger('UpdateInterval'),
            $this->ReadAttributeInteger('LastSynchronization')
        )) {
            return true;
        }

        return $this->Synchronize();
    }

    /**
     * Handles the local day change and recalculates the current-day event count.
     *
     * Open overdue task appointments are moved to the new local day before the
     * counter is updated.
     *
     * @return bool True when the day-change processing completed.
     */
    public function RefreshTodayEventCount(): bool
    {
        $this->SetTimerInterval('DayChangeTimer', 0);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return false;
        }
        if (!$this->ReadPropertyBoolean('Active') || !$this->isRuntimeReady()) {
            $this->updateEventCounters($this->readEvents());
            $this->scheduleTodayEventCountRefresh();
            return true;
        }

        try {
            if ($this->ReadPropertyBoolean('LocalCalendar')) {
                return $this->Synchronize();
            }
            $moved = 0;
            $events = $this->rollForwardTaskEvents($this->readEvents(), $moved);
            if ($moved > 0) {
                $this->storeEventsAfterWrite($events);
            } else {
                $this->updateEventCounters($events);
            }
            return true;
        } catch (Throwable $exception) {
            $this->handleError($exception);
            return false;
        } finally {
            $this->scheduleTodayEventCountRefresh();
        }
    }

    /**
     * Receives calendar metadata notifications from the parent account instance.
     *
     * @param string $JSONString JSON-encoded parent notification.
     * @return string Empty response required by the Symcon data flow.
     */
    public function ReceiveData(string $JSONString): string
    {
        if ($this->ReadPropertyBoolean('LocalCalendar')) {
            return '';
        }
        try {
            $message = $this->DecodeDataFlowMessage($JSONString, self::DATA_ID_FROM_PARENT);
            if (($message['Operation'] ?? '') === 'CalendarsUpdated'
                && is_array($message['Payload'] ?? null)) {
                $this->applyCalendarMetadata($message['Payload']);
            }
        } catch (Throwable $exception) {
            $this->SendSafeDebugException('CalendarMetadataError', $exception);
        }

        return '';
    }

    /**
     * Synchronizes the local event cache with the configured calendar provider.
     *
     * @return bool True when synchronization succeeded.
     */
    public function Synchronize(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }

        $startedAt = microtime(true);
        $this->SendSafeDebug('SynchronizationStart', [
            'pastDays'   => max(0, min(1095, $this->ReadPropertyInteger('PastDays'))),
            'futureDays' => max(1, min(1095, $this->ReadPropertyInteger('FutureDays')))
        ]);

        try {
            $this->refreshCalendarMetadataSafely();
            $events = $this->rollForwardTaskEvents($this->requestEvents());
            $this->storeEvents($events);
            $taskCount = $this->synchronizeMicrosoftTasksSafely();
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus(IS_ACTIVE);
            $this->SendSafeDebug('SynchronizationCompleted', [
                'eventCount' => count($events),
                'taskCount'  => $taskCount,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000)
            ]);
            return true;
        } catch (Throwable $exception) {
            $this->handleError($exception);
            return false;
        }
    }

    /**
     * Returns the cached calendar events.
     *
     * @return string JSON-encoded event list.
     */
    public function GetEvents(): string
    {
        return json_encode(
            $this->readEvents(),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Returns the cached native Microsoft To Do tasks without converting them into calendar events.
     *
     * @return string JSON-encoded task list.
     */
    public function GetMicrosoftTasks(): string
    {
        return json_encode(
            $this->readMicrosoftTasks(),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Returns annual events managed locally by OpenCalendar.
     *
     * A zero day window returns every stored annual event. Positive values only return
     * entries whose next occurrence is within the requested number of calendar days.
     * The optional type filter accepts birthday, anniversary, wedding, or death.
     *
     * @param int $Days Optional look-ahead window in days. Zero returns all annual events.
     * @param string $Type Optional annual-event type. Empty returns every supported type.
     * @return string JSON-encoded annual-event list sorted by the next occurrence.
     */
    public function GetAnniversaryList(int $Days = 0, string $Type = ''): string
    {
        if ($Days < 0) {
            throw new InvalidArgumentException('Annual-event look-ahead days must not be negative.');
        }
        $type = $this->normalizeAnniversaryType($Type, true);

        $today = new DateTimeImmutable('today');
        $entries = [];
        foreach ($this->readAnniversaryMetadata() as $metadata) {
            if ($type !== '' && $metadata['type'] !== $type) {
                continue;
            }
            $anniversaryDate = $this->normalizeAnniversaryDate((string) ($metadata['date'] ?? ''));
            if ($anniversaryDate === '') {
                continue;
            }
            $nextDate = $this->nextAnniversaryDate($anniversaryDate, $today);
            $daysUntil = (int) $today->diff($nextDate)->format('%a');
            if ($Days > 0 && $daysUntil > $Days) {
                continue;
            }

            $startYear = (int) substr($anniversaryDate, 0, 4);
            $years = max(0, (int) $nextDate->format('Y') - $startYear);
            $name = trim((string) ($metadata['summary'] ?? ''));
            $entry = [
                'name'            => $name,
                'anniversaryType' => $metadata['type'],
                'anniversaryDate' => $anniversaryDate,
                'nextDate'        => $nextDate->format('Y-m-d'),
                'years'           => $years,
                'displayName'     => $name !== '' ? sprintf('%s (%dJ)', $name, $years) : sprintf('(%dJ)', $years),
                'daysUntil'       => $daysUntil
            ];
            if ($metadata['type'] === self::ANNIVERSARY_TYPE_BIRTHDAY) {
                $entry['birthDate'] = $anniversaryDate;
                $entry['nextBirthday'] = $entry['nextDate'];
                $entry['age'] = $years;
            }
            $entries[] = $entry;
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => ((int) $left['daysUntil'] <=> (int) $right['daysUntil'])
                ?: strcasecmp((string) $left['name'], (string) $right['name'])
                ?: strcasecmp((string) $left['anniversaryType'], (string) $right['anniversaryType'])
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
     * Returns birthdays managed locally by OpenCalendar.
     *
     * This compatibility function delegates to GetAnniversaryList() with the birthday filter.
     *
     * @param int $Days Optional look-ahead window in days. Zero returns all birthdays.
     * @return string JSON-encoded birthday list sorted by the next birthday.
     */
    public function GetBirthdayList(int $Days = 0): string
    {
        return $this->GetAnniversaryList($Days, self::ANNIVERSARY_TYPE_BIRTHDAY);
    }

    /**
     * Marks an existing recurring series as an annual event managed locally by OpenCalendar.
     *
     * The provider event itself is not modified. The event identity should come from
     * GetEvents() or, preferably for a complete series, GetRecurringSeries().
     * Supported types are birthday, anniversary, wedding, and death.
     *
     * @param string $EventJSON JSON-encoded recurring event identity.
     * @param string $Type Annual-event type: birthday, anniversary, wedding, or death.
     * @param string $Date Original annual-event date in YYYY-MM-DD format.
     * @return bool True when the local annual-event metadata was stored.
     */
    public function SetAnniversary(string $EventJSON, string $Type, string $Date): bool
    {
        $event = $this->decodeObject($EventJSON, 'event');
        $type = $this->normalizeAnniversaryType($Type, true);
        $date = $this->normalizeAnniversaryDate($Date);
        if ($type === '') {
            throw new InvalidArgumentException('The annual-event type is missing.');
        }
        if ($date === '' || $date > date('Y-m-d')) {
            throw new InvalidArgumentException('The annual-event date is invalid.');
        }
        $recurrence = CalendarEventRecurrence::fromEvent($event);
        if (!(bool) ($recurrence['recurring'] ?? false)
            && trim((string) ($event['seriesId'] ?? '')) === '') {
            throw new InvalidArgumentException('Annual-event metadata requires a recurring series.');
        }

        $this->upsertAnniversaryMetadata(
            $event,
            $type,
            $date,
            trim((string) ($event['summary'] ?? ''))
        );
        $events = $this->enrichAnniversaryEvents($this->readEvents());
        $this->WritePersistentJsonCache('CachedEvents', $events);

        return true;
    }

    /**
     * Returns the current provider version of one event before it is edited.
     *
     * Reads the provider's current ETag and write-relevant identity fields. Known
     * tasks may use their cached record only after a classified transient failure;
     * authorization, conflict, missing-event and invalid-response errors still fail.
     *
     * @param string $EventJSON JSON-encoded event identity and current time range.
     * @return string JSON-encoded normalized provider event or matching cached task.
     */
    public function GetEventForEdit(string $EventJSON): string
    {
        try {
            $event = $this->decodeObject($EventJSON, 'event');
            if ($this->isMicrosoftTodoEvent($event)) {
                return json_encode(
                    $this->microsoftTodoEventForEdit($event),
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_PRESERVE_ZERO_FRACTION
                        | JSON_THROW_ON_ERROR
                );
            }
            $startTimestamp = (int) ($event['startTimestamp'] ?? 0);
            $endTimestamp = (int) ($event['endTimestamp'] ?? 0);
            if ($startTimestamp <= 0) {
                throw new InvalidArgumentException('The selected event start is invalid.');
            }

            try {
                $currentEvent = $this->sendRequest('GetEventForEdit', [
                    'ResourceURL'    => trim((string) ($event['resourceUrl'] ?? '')),
                    'EventReference' => trim((string) ($event['eventReference'] ?? '')),
                    'UID'            => trim((string) ($event['uid'] ?? '')),
                    'SeriesID'       => trim((string) ($event['seriesId'] ?? '')),
                    'OccurrenceID'   => trim((string) ($event['occurrenceId'] ?? '')),
                    'OriginalStart'  => trim((string) ($event['originalStart'] ?? '')),
                    'RecurrenceID'   => trim((string) ($event['recurrenceId'] ?? '')),
                    'Start'          => $startTimestamp,
                    'End'            => $endTimestamp
                ]);
            } catch (Throwable $exception) {
                $currentEvent = $this->cachedTaskEventForEdit($event, $exception);
                if ($currentEvent === null) {
                    throw $exception;
                }
            }
            $this->assertEventAvailable($currentEvent);
            $currentEvent = $this->enrichAnniversaryEvent(CalendarTaskEvent::enrich($currentEvent));

            return json_encode(
                $currentEvent,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new RuntimeException($this->handleError($exception), 0, $exception);
        }
    }

    /**
     * Returns the verified parent event for a recurring series.
     *
     * @param string $SeriesID Provider-specific recurring parent event identifier.
     * @param string $ResourceURL Optional provider-specific resource URL already known for the series.
     * @return string JSON-encoded normalized recurring parent event.
     */
    public function GetRecurringSeries(string $SeriesID, string $ResourceURL = ''): string
    {
        try {
            $seriesId = trim($SeriesID);
            if ($seriesId === '') {
                throw new InvalidArgumentException('The recurring series ID is missing.');
            }
            if (!$this->ReadAttributeBoolean('CalendarMetadataAvailable')
                || !$this->ReadAttributeBoolean('DetectedCanUpdateSeries')) {
                $this->refreshCalendarMetadataSafely();
            }
            if (!$this->ReadAttributeBoolean('DetectedCanUpdateSeries')) {
                throw new InvalidArgumentException('Recurring series updates are not supported by this calendar.');
            }

            $series = $this->sendRequest(
                'GetRecurringSeries',
                [
                    'SeriesID'    => $seriesId,
                    'ResourceURL' => trim($ResourceURL)
                ]
            );
            $this->assertEventAvailable($series);
            $series = $this->enrichAnniversaryEvent(CalendarTaskEvent::enrich($series));
            return json_encode(
                $series,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new RuntimeException($this->handleError($exception), 0, $exception);
        }
    }

    /**
     * Returns an editable recurring event starting at the selected occurrence.
     *
     * @param string $SeriesID Provider-specific recurring parent event identifier.
     * @param string $OccurrenceID Provider-specific target occurrence identifier.
     * @param string $OriginalStart Immutable original start of the target occurrence.
     * @param string $ResourceURL Optional provider-specific resource URL already known for the series.
     * @return string JSON-encoded normalized recurring target event.
     */
    public function GetRecurringFollowing(
        string $SeriesID,
        string $OccurrenceID,
        string $OriginalStart,
        string $ResourceURL = ''
    ): string {
        try {
            $seriesId = trim($SeriesID);
            $occurrenceId = trim($OccurrenceID);
            $originalStart = trim($OriginalStart);
            if ($seriesId === '' || $occurrenceId === '' || $originalStart === '') {
                throw new InvalidArgumentException('The recurring occurrence identity is incomplete.');
            }
            if (!$this->ReadAttributeBoolean('CalendarMetadataAvailable')
                || !$this->ReadAttributeBoolean('DetectedCanUpdateFollowing')) {
                $this->refreshCalendarMetadataSafely();
            }
            if (!$this->ReadAttributeBoolean('DetectedCanUpdateFollowing')) {
                throw new InvalidArgumentException('This and following updates are not supported by this calendar.');
            }

            $following = $this->sendRequest(
                'GetRecurringFollowing',
                [
                    'SeriesID'      => $seriesId,
                    'OccurrenceID'  => $occurrenceId,
                    'OriginalStart' => $originalStart,
                    'ResourceURL'   => trim($ResourceURL)
                ]
            );
            $this->assertEventAvailable($following);
            $following = $this->enrichAnniversaryEvent(CalendarTaskEvent::enrich($following));
            return json_encode(
                $following,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new RuntimeException($this->handleError($exception), 0, $exception);
        }
    }

    /**
     * Creates a temporary paged transfer for cached events overlapping a time range.
     *
     * This is the preferred API for module consumers because each subsequent
     * response remains safely below Symcon's PHP output limit.
     *
     * @param int $StartTimestamp Inclusive start of the requested Unix time range.
     * @param int $EndTimestamp Exclusive end of the requested Unix time range.
     * @return string JSON-encoded transfer metadata.
     */
    public function BeginEventsTransfer(int $StartTimestamp, int $EndTimestamp): string
    {
        if ($StartTimestamp <= 0 || $EndTimestamp <= $StartTimestamp) {
            throw new InvalidArgumentException('The requested event time range is invalid.');
        }
        if (($EndTimestamp - $StartTimestamp) > 6 * 366 * 86400) {
            throw new InvalidArgumentException('The requested event time range is too large.');
        }

        $events = array_values(array_filter(
            array_merge(
                $this->readEvents(),
                MicrosoftTodoTaskProjection::project($this->readMicrosoftTasks())
            ),
            static function (array $event) use ($StartTimestamp, $EndTimestamp): bool
            {
                $startTimestamp = (int) ($event['startTimestamp'] ?? 0);
                $endTimestamp = (int) ($event['endTimestamp'] ?? $startTimestamp);

                return $startTimestamp > 0
                    && $endTimestamp >= $StartTimestamp
                    && $startTimestamp < $EndTimestamp;
            }
        ));

        return json_encode(
            $this->CreateChunkedJsonTransfer(self::EVENT_TRANSFER_SCOPE, $events),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Reads one page from a cached event transfer.
     *
     * @param string $Token Transfer token returned by BeginEventsTransfer().
     * @param int $Page Zero-based page number.
     * @return string JSON-encoded transfer page.
     */
    public function ReadEventsTransferPage(string $Token, int $Page): string
    {
        return json_encode(
            $this->ReadChunkedJsonTransferPage(self::EVENT_TRANSFER_SCOPE, $Token, $Page),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Removes a completed or aborted cached event transfer.
     *
     * @param string $Token Transfer token returned by BeginEventsTransfer().
     * @return bool True when the transfer existed.
     */
    public function FinishEventsTransfer(string $Token): bool
    {
        return $this->ClearChunkedJsonTransfer(self::EVENT_TRANSFER_SCOPE, $Token);
    }

    /**
     * Creates an event in the configured calendar.
     * A confirmed provider write remains successful if subsequent local processing fails;
     * the result error and LastError retain the follow-up diagnostic.
     *
     * @param string $EventJSON JSON-encoded event data.
     * @return string JSON-encoded operation result.
     */
    public function CreateEvent(string $EventJSON): string
    {
        $writeConfirmed = false;
        $created = null;
        try {
            $event = $this->decodeObject($EventJSON, 'event');
            if ($this->mustCreateMicrosoftTodoTask($event)) {
                $created = $this->createMicrosoftTodoTask($event);
                $writeConfirmed = true;
                $this->storeMicrosoftTaskAfterWrite($created);
                $projected = MicrosoftTodoTaskProjection::project([$created]);
                if ($projected === []) {
                    throw new UnexpectedValueException('The calendar account returned invalid Microsoft task data.');
                }

                return $this->encodeResult(true, $projected[0]);
            }
            $anniversary = $this->anniversaryInput($event);
            if ($anniversary !== null && $anniversary['enabled']) {
                $recurrenceInput = $event['recurrence'] ?? null;
                if (!is_array($recurrenceInput) || $recurrenceInput === []) {
                    $this->applyAnniversaryEventDefaults($event, $anniversary['date']);
                } else {
                    $this->assertAnniversaryRecurrence($event);
                }
            }
            $event = CalendarTaskEvent::prepareWrite($event);
            $recurrence = $event['recurrence'] ?? null;
            if ($recurrence !== null && $recurrence !== []) {
                if (!is_array($recurrence) || array_is_list($recurrence)) {
                    throw new InvalidArgumentException('The recurrence settings are invalid.');
                }
                if (!$this->ReadAttributeBoolean('CalendarMetadataAvailable')
                    || !$this->ReadAttributeBoolean('DetectedCanCreateRecurrence')) {
                    $this->refreshCalendarMetadataSafely();
                }
                if (!$this->ReadAttributeBoolean('DetectedCanCreateRecurrence')) {
                    throw new InvalidArgumentException('Recurring event creation is not supported by this calendar.');
                }
                if (trim((string) ($event['timezone'] ?? '')) === '') {
                    $timezone = trim($this->ReadAttributeString('DetectedCalendarTimezone'));
                    if ($timezone === '') {
                        $timezone = date_default_timezone_get();
                    }
                    $event['timezone'] = $timezone;
                }
            }
            $providerEvent = $event;
            unset(
                $providerEvent['anniversaryType'],
                $providerEvent['anniversaryDate'],
                $providerEvent['birthday'],
                $providerEvent['birthDate']
            );
            $this->SendSafeDebug('EventCreate', [
                'allDay'      => (bool) ($event['allDay'] ?? false),
                'recurring'   => is_array($recurrence) && $recurrence !== [],
                'frequency'   => is_array($recurrence)
                    ? strtoupper(trim((string) ($recurrence['frequency'] ?? '')))
                    : '',
                'timezone'    => trim((string) ($event['timezone'] ?? '')),
                'annualEvent' => $anniversary !== null && $anniversary['enabled']
            ]);
            $created = $this->sendRequest('CreateEvent', ['Event' => $providerEvent]);
            $writeConfirmed = true;
            if ($anniversary !== null && $anniversary['enabled']) {
                $this->upsertAnniversaryMetadata(
                    array_merge($event, is_array($created) ? $created : []),
                    $anniversary['type'],
                    $anniversary['date'],
                    trim((string) ($event['summary'] ?? ''))
                );
            }
            if (!$this->refreshEventAfterWrite(array_merge($event, $created))) {
                $this->refreshAfterWrite();
            }

            return $this->encodeResult(true, $created);
        } catch (Throwable $exception) {
            return $this->encodeResult($writeConfirmed, $created, $this->handleError($exception));
        }
    }

    /**
     * Updates an existing event in the configured calendar.
     * A confirmed provider write remains successful if subsequent local processing fails;
     * the result error and LastError retain the follow-up diagnostic.
     *
     * @param string $EventJSON JSON-encoded event metadata and changes.
     * @return string JSON-encoded operation result.
     */
    public function UpdateEvent(string $EventJSON): string
    {
        $writeConfirmed = false;
        $updated = null;
        try {
            $event = $this->decodeObject($EventJSON, 'event');
            $changes = $event['changes'] ?? $event;
            if (!is_array($changes)) {
                throw new InvalidArgumentException('The event changes are invalid.');
            }
            if ($this->isMicrosoftTodoEvent($event)) {
                $updated = $this->updateMicrosoftTodoEvent($event, $changes);
                $writeConfirmed = true;
                $this->storeMicrosoftTaskAfterWrite($updated);
                return $this->encodeResult(true, MicrosoftTodoTaskProjection::project([$updated])[0] ?? $updated);
            }
            $recurrence = $this->resolveWriteRecurrence($event, true);
            $anniversary = $this->anniversaryInput($changes);
            $existingAnniversary = $this->anniversaryMetadataForEvent($event);
            $writeScope = (string) ($recurrence['writeScope'] ?? '');
            if ($anniversary !== null && $anniversary['enabled']) {
                if (in_array(
                    $writeScope,
                    [CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE, CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING],
                    true
                )) {
                    throw new InvalidArgumentException('Annual-event settings can only be changed for a complete recurring series.');
                }
                $this->applyAnniversaryEventDefaults($changes, $anniversary['date']);
            }
            $taskSource = $event;
            if (array_key_exists('task', $changes)
                || array_key_exists('taskCompleted', $changes)
                || array_key_exists('taskStatus', $changes)
                || array_key_exists('taskFollowPlanned', $changes)
                || array_key_exists('taskRollForwardScope', $changes)) {
                $cachedEvent = $this->cachedEventForIdentity($event);
                if ($cachedEvent !== null) {
                    $taskSource = array_merge($cachedEvent, $event);
                }
            }
            $changes = CalendarTaskEvent::prepareWrite($changes, $taskSource);
            $taskAfterWrite = CalendarTaskEvent::enrich(array_merge($taskSource, $changes));
            if (CalendarEventRecurrence::isOccurrence($recurrence)
                && $writeScope === CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE
                && (bool) ($taskAfterWrite['taskFollowPlanned'] ?? false)
                && array_key_exists('start', $changes)) {
                if (!(bool) ($recurrence['canUpdateFollowing'] ?? false)) {
                    throw new InvalidArgumentException('This and following updates are not supported by this calendar.');
                }
                $followingChanges = $changes;
                $following = $this->prepareTaskFollowingMove(array_merge($taskSource, $recurrence), $followingChanges);
                $followingTask = CalendarTaskEvent::enrich($following);
                if ((bool) ($taskAfterWrite['task'] ?? false)
                    && (bool) ($followingTask['task'] ?? false)
                    && (bool) ($taskAfterWrite['taskCompleted'] ?? false) !== (bool) ($followingTask['taskCompleted'] ?? false)) {
                    // A completed first occurrence is a detached task-state override.
                    // Reanchoring the series must not turn every following task into a
                    // completed task when that override is removed with the former start.
                    $followingChanges['task'] = true;
                    $followingChanges['taskCompleted'] = (bool) $followingTask['taskCompleted'];
                    $followingChanges['taskStatus'] = (bool) $followingTask['taskCompleted'] ? 'completed' : 'open';
                    $followingChanges['taskFollowPlanned'] = (bool) ($followingTask['taskFollowPlanned'] ?? false);
                    $followingChanges['taskRollForwardScope'] = (string) (
                        $followingTask['taskRollForwardScope'] ?? CalendarTaskEvent::ROLL_FORWARD_SCOPE_OCCURRENCE
                    );
                    $followingChanges = CalendarTaskEvent::prepareWrite($followingChanges, $following);
                }
                $providerStart = $this->taskEventDate($following, 'start');
                if ($providerStart === null) {
                    throw new InvalidArgumentException('The task series contains an invalid occurrence date.');
                }
                if ($this->taskEventDate($changes, 'start') != $providerStart) {
                    $event = $following;
                    $changes = $followingChanges;
                    $recurrence = CalendarEventRecurrence::fromEvent($event);
                    $writeScope = (string) $recurrence['writeScope'];
                }
            }
            $requestedRecurrence = $changes['recurrence'] ?? null;
            $recurrenceType = (string) ($recurrence['recurrenceType'] ?? CalendarEventRecurrence::SINGLE);
            $convertingSingleToSeries = $recurrenceType === CalendarEventRecurrence::SINGLE
                && is_array($requestedRecurrence)
                && $requestedRecurrence !== [];
            if ($convertingSingleToSeries) {
                if (!$this->ReadAttributeBoolean('CalendarMetadataAvailable')
                    || !$this->ReadAttributeBoolean('DetectedCanUpdateRecurrence')) {
                    $this->refreshCalendarMetadataSafely();
                }
                if (!$this->ReadAttributeBoolean('DetectedCanUpdateRecurrence')) {
                    throw new InvalidArgumentException(
                        'Converting this event into a recurring series is not supported by this calendar.'
                    );
                }
                if (trim((string) ($changes['timezone'] ?? '')) === '') {
                    $timezone = trim($this->ReadAttributeString('DetectedCalendarTimezone'));
                    if ($timezone === '') {
                        $timezone = date_default_timezone_get();
                    }
                    $changes['timezone'] = $timezone;
                }
            }
            foreach ([
                'uid',
                'resourceUrl',
                'etag',
                'recurrenceType',
                'seriesId',
                'occurrenceId',
                'originalStart',
                'recurrenceId',
                'recurring',
                'canUpdateOccurrence',
                'canDeleteOccurrence',
                'canUpdateFollowing',
                'canUpdateSeries',
                'canDeleteSeries',
                'writeScope',
                'changes'
            ] as $metadataKey) {
                unset($changes[$metadataKey]);
            }
            $anniversaryEnabled = $anniversary !== null && $anniversary['enabled'];
            $anniversaryDisabled = $anniversary !== null && !$anniversary['enabled'];
            $anniversaryType = $anniversaryEnabled
                ? $anniversary['type']
                : (string) ($existingAnniversary['type'] ?? '');
            $anniversaryDate = $anniversaryEnabled
                ? $anniversary['date']
                : (string) ($existingAnniversary['date'] ?? '');
            unset($changes['anniversaryType'], $changes['anniversaryDate'], $changes['birthday'], $changes['birthDate']);
            if ($changes === [] && !$anniversaryDisabled) {
                throw new InvalidArgumentException('No event changes were supplied.');
            }
            $cachedEvent = $recurrenceType === CalendarEventRecurrence::SINGLE
                || ($writeScope === CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE
                    && CalendarEventLookup::supportsOccurrenceReadback($event))
                ? $this->cachedEventForIdentity($event)
                : null;

            $this->SendSafeDebug('EventUpdate', [
                'recurrenceType'     => $recurrenceType,
                'writeScope'         => $writeScope,
                'convertingToSeries' => $convertingSingleToSeries,
                'changedFields'      => array_values(array_keys($changes)),
                'annualEventChange'  => $anniversary !== null
            ]);

            $updated = $changes === []
                ? []
                : $this->sendRequest(
                    'UpdateEvent',
                    [
                        'UID'         => trim((string) ($event['uid'] ?? '')),
                        'ResourceURL' => trim((string) ($event['resourceUrl'] ?? '')),
                        'ETag'        => trim((string) ($event['etag'] ?? '')),
                        'Event'       => $changes,
                        'Recurrence'  => $recurrence
                    ]
                );

            $writeConfirmed = $changes !== [];
            if (array_key_exists('summary', $changes)) {
                $task = CalendarTaskEvent::enrich($changes);
                if (!(bool) ($task['task'] ?? false) || (bool) ($task['taskCompleted'] ?? false)) {
                    $this->forgetPendingTask($event);
                }
            }
            if ($anniversaryDisabled) {
                $this->removeAnniversaryMetadata($event);
            } elseif ($anniversaryEnabled || $existingAnniversary !== null) {
                $summary = trim((string) ($changes['summary'] ?? $existingAnniversary['summary'] ?? $event['summary'] ?? ''));
                $this->upsertAnniversaryMetadata(
                    array_merge($event, is_array($updated) ? $updated : []),
                    $anniversaryType,
                    $anniversaryDate,
                    $summary,
                    $event
                );
            }
            $writtenEvent = array_merge($cachedEvent ?? $event, $changes, $updated);
            if (!$this->refreshEventAfterWrite($writtenEvent, $event)) {
                $this->refreshAfterWrite();
            }

            return $this->encodeResult(true, $updated);
        } catch (Throwable $exception) {
            return $this->encodeResult($writeConfirmed, $updated, $this->handleError($exception));
        }
    }

    /**
     * Deletes an event from the configured calendar.
     * A confirmed provider deletion remains successful even if local cache maintenance fails.
     *
     * @param string $EventJSON JSON-encoded event metadata.
     * @return bool True when the event was deleted successfully.
     */
    public function DeleteEvent(string $EventJSON): bool
    {
        $writeConfirmed = false;
        try {
            $event = $this->decodeObject($EventJSON, 'event');
            if ($this->isMicrosoftTodoEvent($event)) {
                [$listId, $taskId] = $this->microsoftTodoIdentity($event);
                $result = $this->sendRequest('DeleteTask', [
                    'TaskListID' => $listId,
                    'TaskID'     => $taskId
                ]);
                if (!(bool) ($result['success'] ?? false)) {
                    throw new RuntimeException('The calendar account did not confirm the Microsoft task deletion.');
                }
                $writeConfirmed = true;
                $this->removeMicrosoftTaskFromCache($listId, $taskId);
                return true;
            }
            $recurrence = $this->resolveWriteRecurrence($event, false);
            $writeScope = (string) ($recurrence['writeScope'] ?? '');
            $recurrenceType = (string) ($recurrence['recurrenceType'] ?? CalendarEventRecurrence::SINGLE);
            $this->SendSafeDebug('EventDelete', [
                'recurrenceType' => $recurrenceType,
                'writeScope'     => $writeScope
            ]);
            $result = $this->sendRequest(
                'DeleteEvent',
                [
                    'ResourceURL'  => trim((string) ($event['resourceUrl'] ?? '')),
                    'ETag'         => trim((string) ($event['etag'] ?? '')),
                    'RecurrenceID' => trim((string) ($recurrence['recurrenceId'] ?? '')),
                    'Recurrence'   => $recurrence
                ]
            );
            if (!(bool) ($result['success'] ?? false)) {
                throw new RuntimeException('The calendar account did not confirm the deletion.');
            }
            $writeConfirmed = true;
            $this->forgetPendingTask($event);
            if (!CalendarEventRecurrence::isOccurrence($recurrence)
                || in_array(
                    $writeScope,
                    [CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING, CalendarEventRecurrence::WRITE_SCOPE_SERIES],
                    true
                )) {
                $this->removeAnniversaryMetadata($event);
            }
            $events = $this->readEvents();
            $filteredEvents = CalendarEventDeletion::filter($events, $event, $recurrence);
            $this->storeEventsAfterWrite($filteredEvents);
            $this->SendSafeDebug('EventDeleteCacheUpdated', [
                'removedCount' => count($events) - count($filteredEvents),
                'writeScope'   => $writeScope
            ]);
            return true;
        } catch (Throwable $exception) {
            $this->handleError($exception);
            return $writeConfirmed;
        }
    }

    /**
     * Clears cached events and synchronization metadata.
     */
    public function ClearCache(): void
    {
        $this->clearIncrementalSyncState();
        $this->clearMicrosoftTaskSyncState();
        $this->ClearPersistentJsonCache('CachedMicrosoftTasks');
        $this->storeEvents([]);
        $this->WriteAttributeInteger('LastSynchronization', 0);
        $this->SetValue('LastSynchronization', 0);
        $this->WriteAttributeString('LastError', '');
    }

    /**
     * Exports the original resources of a local calendar as an ICS backup file.
     *
     * @param string $fileName Safe target file name including the .ics extension.
     * @param bool   $overwrite Whether an existing file may be overwritten.
     * @return string Absolute path of the exported ICS file.
     */
    public function ExportLocalCalendar(string $fileName, bool $overwrite = false): string
    {
        if (!$this->ReadPropertyBoolean('LocalCalendar')) {
            throw new LogicException('Only local calendars can be exported.');
        }
        if (!$this->isValidLocalCalendarExportFileName($fileName)) {
            throw new InvalidArgumentException('The ICS export file name is invalid.');
        }

        $directory = rtrim(IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR . self::LOCAL_EXPORT_DIRECTORY;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The local calendar export directory could not be created.');
        }

        $path = $directory . DIRECTORY_SEPARATOR . $fileName;
        if (file_exists($path) && !$overwrite) {
            throw new RuntimeException('The ICS export file already exists.');
        }
        if (file_put_contents($path, $this->localCalendarExportContent(), LOCK_EX) === false) {
            throw new RuntimeException('The local calendar could not be exported.');
        }

        return $path;
    }

    /**
     * Returns runtime metadata and synchronization status for this calendar.
     *
     * @return string JSON-encoded calendar status.
     */
    public function GetCalendarStatus(): string
    {
        if ($this->isRuntimeReady()) {
            $this->refreshCalendarMetadataSafely();
        }
        $metadata = $this->readCalendarMetadata();
        $metadataAvailable = (bool) $metadata['available'];
        $writeAccessKnown = $metadataAvailable && (bool) $metadata['writeAccessKnown'];
        $detectedColor = (string) $metadata['calendarColor'];
        $defaultReminder = json_decode((string) $metadata['defaultReminderJson'], true);
        if (!is_array($defaultReminder) || array_is_list($defaultReminder)) {
            $defaultReminder = [];
        }
        $events = $this->readEvents();

        return json_encode(
            [
                'localCalendar'                => $this->ReadPropertyBoolean('LocalCalendar'),
                'calendarId'                   => $this->effectiveCalendarId(),
                'calendarColor'                => $metadataAvailable && $detectedColor !== ''
                    ? $detectedColor
                    : $this->ReadPropertyString('CalendarColor'),
                'canWrite'                     => $metadataAvailable
                    ? ($writeAccessKnown
                        ? (bool) $metadata['canWrite']
                        : (bool) $metadata['canWrite'] || $this->ReadPropertyBoolean('CanWrite'))
                    : $this->ReadPropertyBoolean('CanWrite'),
                'timezone'                      => $metadataAvailable ? (string) $metadata['calendarTimezone'] : '',
                'canCreateRecurrence'           => $metadataAvailable && (bool) $metadata['canCreateRecurrence'],
                'canUpdateRecurrence'           => $metadataAvailable && (bool) $metadata['canUpdateRecurrence'],
                'canUpdateOccurrence'           => $metadataAvailable && (bool) $metadata['canUpdateOccurrence'],
                'canDeleteOccurrence'           => $metadataAvailable && (bool) $metadata['canDeleteOccurrence'],
                'canUpdateFollowing'            => $metadataAvailable && (bool) $metadata['canUpdateFollowing'],
                'canUpdateSeries'               => $metadataAvailable && (bool) $metadata['canUpdateSeries'],
                'canDeleteSeries'               => $metadataAvailable && (bool) $metadata['canDeleteSeries'],
                'canUseDefaultReminder'         => $metadataAvailable && (bool) $metadata['canUseDefaultReminder'],
                'canCreateWithDefaultReminder'  => $metadataAvailable && (bool) $metadata['canCreateWithDefaultReminder'],
                'canWriteStatus'                => $metadataAvailable && (bool) $metadata['canWriteStatus'],
                'canWriteTransparency'          => $metadataAvailable && (bool) $metadata['canWriteTransparency'],
                'defaultStatus'                 => $metadataAvailable
                    ? CalendarEventState::normalizeStatus(
                        (string) $metadata['defaultStatus'],
                        CalendarEventState::STATUS_CONFIRMED
                    )
                    : CalendarEventState::STATUS_CONFIRMED,
                'defaultTransparency'           => $metadataAvailable
                    ? CalendarEventState::normalizeTransparency(
                        (string) $metadata['defaultTransparency'],
                        CalendarEventState::TRANSP_OPAQUE
                    )
                    : CalendarEventState::TRANSP_OPAQUE,
                'defaultAllDayTransparency'     => $metadataAvailable
                    ? CalendarEventState::normalizeTransparency(
                        (string) $metadata['defaultAllDayTransparency'],
                        CalendarEventState::TRANSP_OPAQUE
                    )
                    : CalendarEventState::TRANSP_OPAQUE,
                'defaultReminder'               => $metadataAvailable ? $defaultReminder : [],
                'maxReminders'                  => $metadataAvailable
                    ? max(1, min(5, (int) $metadata['maxReminders']))
                    : 1,
                'eventCount'                    => count($events),
                'microsoftTaskListId'           => trim($this->ReadPropertyString('MicrosoftTaskListID')),
                'microsoftTaskCount'            => count($this->readMicrosoftTasks()),
                'microsoftTaskLastError'        => $this->ReadAttributeString('MicrosoftTaskLastError'),
                'todayEventCount'               => CalendarEventCounter::countForDay(
                    $events,
                    new DateTimeImmutable('today')
                ),
                'lastSynchronization'           => $this->ReadAttributeInteger('LastSynchronization'),
                'lastError'                     => $this->ReadAttributeString('LastError')
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Checks the calendar's attachment policy ceiling without transferring files.
     * This is not event ownership validation or a reusable access credential.
     *
     * @param string $Operation Requested list, download, upload or delete operation.
     * @param string $Destination Explicit local or provider storage destination.
     * @return bool Whether calendar configuration permits this operation.
     */
    public function CanAccessAttachments(string $Operation, string $Destination): bool
    {
        $policy = new CalendarAttachmentPolicy(
            $this->ReadPropertyInteger('AttachmentMode'),
            $this->ReadPropertyBoolean('AttachmentAllowLocal'),
            $this->ReadPropertyBoolean('AttachmentAllowProvider')
        );
        if (!$this->ReadPropertyBoolean('Active') || !$policy->allows($Operation, $Destination)) {
            return false;
        }
        if ($Destination === 'provider') {
            if ($this->ReadPropertyBoolean('LocalCalendar')) {
                return false;
            }
            if (in_array($Operation, ['upload', 'delete'], true) && !$this->calendarCanWrite()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Transfers local originals for trusted Symcon callers; browser authentication
     * and view rights must additionally be enforced by the requesting view hook.
     *
     * @param string $Request Bounded JSON with operation, selector and data.
     * @return string Private JSON result, never broadcast or log it.
     */
    public function TransferLocalAttachment(string $Request): string
    {
        if (strlen($Request) > 3_000_000) {
            throw new InvalidArgumentException('Attachment request is too large.');
        }
        $value = json_decode($Request, true, 12, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_diff(array_keys($value), ['operation', 'selector', 'data']) !== []
            || !is_string($value['operation'] ?? null) || !is_array($value['selector'] ?? null)
            || !is_array($value['data'] ?? null)) {
            throw new InvalidArgumentException('Invalid attachment request.');
        }
        $fields = match ($value['operation']) {
            'list'   => [], 'download' => ['id'], 'delete' => ['id', 'revision'],
            'upload' => ['name', 'content', 'requestId'],
            default  => throw new InvalidArgumentException('Invalid attachment operation.')
        };
        if (array_diff(array_keys($value['data']), $fields) !== []) {
            throw new InvalidArgumentException('Invalid attachment fields.');
        }
        $result = $this->verifiedLocalAttachmentOperation($value['operation'], $value['selector'], $value['data']);
        return json_encode($value['operation'] === 'download' ? ['content' => base64_encode($result)] : ['result' => $result], JSON_THROW_ON_ERROR);
    }

    /**
     * Lists provider attachment metadata on demand under calendar attachment policy.
     * The caller must additionally authenticate the requesting view and transport.
     *
     * @param string $Selector JSON provider event identity or sourceType=microsoft-todo with taskId/taskListId.
     * @return string Private JSON result, never shared through visualization state or caches.
     */
    public function ListProviderAttachments(string $Selector): string
    {
        if (!$this->CanAccessAttachments('list', 'provider') || strlen($Selector) > 8192) {
            throw new RuntimeException('Provider attachment access denied.');
        }
        $request = $this->providerAttachmentRequest($Selector);
        $calendarId = $this->effectiveCalendarId();
        $connectionId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $result = $this->sendRequest('ListProviderAttachments', $request);
        if (!$this->CanAccessAttachments('list', 'provider') || $calendarId !== $this->effectiveCalendarId()
            || $connectionId !== IPS_GetInstance($this->InstanceID)['ConnectionID']
            || (isset($request['TaskListID']) && $request['TaskListID'] !== trim($this->ReadPropertyString('MicrosoftTaskListID')))) {
            throw new RuntimeException('Attachment access changed.');
        }
        return json_encode(['result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Downloads one provider attachment after resolving its owner server-side.
     *
     * @param string $Request JSON containing selector and exact attachment ID.
     * @return string Private JSON with Base64 content and validated filename/type.
     */
    public function DownloadProviderAttachment(string $Request): string
    {
        if (!$this->CanAccessAttachments('download', 'provider') || strlen($Request) > 12_000) {
            throw new RuntimeException('Provider attachment access denied.');
        }
        $value = json_decode($Request, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($value) || count($value) !== 2 || array_diff(array_keys($value), ['selector', 'id']) !== []
            || !is_array($value['selector'])
            || !is_string($value['id']) || $value['id'] === '' || strlen($value['id']) > 2048
            || preg_match('/[\x00-\x1f\x7f]/', $value['id'])) {
            throw new InvalidArgumentException('Invalid provider attachment download.');
        }
        $request = $this->providerAttachmentRequest(json_encode($value['selector'], JSON_THROW_ON_ERROR));
        $request['AttachmentID'] = $value['id'];
        $calendarId = $this->effectiveCalendarId();
        $connectionId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $result = $this->sendRequest('DownloadProviderAttachment', $request);
        if (!$this->CanAccessAttachments('download', 'provider') || $calendarId !== $this->effectiveCalendarId()
            || $connectionId !== IPS_GetInstance($this->InstanceID)['ConnectionID']
            || (isset($request['TaskListID']) && $request['TaskListID'] !== trim($this->ReadPropertyString('MicrosoftTaskListID')))) {
            throw new RuntimeException('Attachment access changed.');
        }
        if (!is_string($result['name'] ?? null) || !is_string($result['contentType'] ?? null)
            || !is_string($result['content'] ?? null)) {
            throw new RuntimeException('Invalid provider attachment response.');
        }
        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Lists local originals for trusted administrator scripts, including deleted-event files.
     * Deliberately works when calendar/view attachment access is disabled. Never expose
     * this recovery API through visualization actions, hooks or shared state.
     *
     * @return string Private JSON inventory with snapshot revision, total bytes and file metadata.
     */
    public function GetLocalAttachmentInventory(): string
    {
        return json_encode($this->localAttachmentMaintenance('inventory'), JSON_THROW_ON_ERROR);
    }

    /**
     * Starts a point-in-time backup of all local originals for trusted administrator scripts.
     * Pages contain private base64 chunks of the versioned store snapshot. Credentials
     * and bytes must never be logged, broadcast or placed in public media directories.
     * Works independently of end-user attachment permissions for disaster recovery.
     *
     * @return string JSON transfer metadata, source instance, snapshot size and SHA-256.
     */
    public function BeginLocalAttachmentBackup(): string
    {
        return json_encode($this->localAttachmentMaintenance('backup'), JSON_THROW_ON_ERROR);
    }

    /**
     * Reads one private backup page for trusted administrator scripts only.
     *
     * @param string $Token Transfer token from BeginLocalAttachmentBackup().
     * @param int $Page Zero-based page index.
     * @return string Bounded JSON page; concatenate decoded Items in page order.
     */
    public function ReadLocalAttachmentBackupPage(string $Token, int $Page): string
    {
        return json_encode($this->ReadChunkedJsonTransferPage(self::ATTACHMENT_BACKUP_TRANSFER_SCOPE, $Token, $Page), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Removes a backup's temporary encrypted transfer data without changing originals.
     *
     * @param string $Token Transfer token from BeginLocalAttachmentBackup().
     * @return bool Whether the transfer existed.
     */
    public function FinishLocalAttachmentBackup(string $Token): bool
    {
        return $this->ClearChunkedJsonTransfer(self::ATTACHMENT_BACKUP_TRANSFER_SCOPE, $Token);
    }

    /**
     * Permanently removes explicitly selected local originals for trusted administrators.
     * Works with attachments disabled; does not check event ownership or infer orphans.
     * Does not delete provider files, backup copies or active backup transfers.
     *
     * @param string $Selection JSON list of exact file IDs from GetLocalAttachmentInventory().
     * @param string $ExpectedRevision Inventory revision reviewed before confirming deletion.
     * @return int Number of removed originals after successful persistence.
     */
    public function DeleteLocalAttachmentOriginals(string $Selection, string $ExpectedRevision): int
    {
        if (strlen($Selection) > 8192) {
            throw new InvalidArgumentException('Attachment cleanup selection is too large.');
        }
        $ids = json_decode($Selection, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($ids)) {
            throw new InvalidArgumentException('Invalid attachment cleanup selection.');
        }
        return $this->localAttachmentMaintenance('delete', $ids, $ExpectedRevision)['deleted'];
    }

    /** @return array<string,string> Server-bound provider request fields. */
    private function providerAttachmentRequest(string $selectorJson): array
    {
        $selector = json_decode($selectorJson, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($selector)) {
            throw new InvalidArgumentException('Invalid attachment selector.');
        }
        if (($selector['sourceType'] ?? '') === 'microsoft-todo') {
            $listId = trim($this->ReadPropertyString('MicrosoftTaskListID'));
            if (array_diff(array_keys($selector), ['sourceType', 'taskId', 'taskListId']) !== []
                || $listId === '' || ($selector['taskListId'] ?? null) !== $listId
                || !is_string($selector['taskId'] ?? null) || trim($selector['taskId']) === '') {
                throw new InvalidArgumentException('Invalid attachment task selector.');
            }
            return ['TaskListID' => $listId, 'TaskID' => $selector['taskId']];
        }
        if (array_keys($selector) === ['eventReference']) {
            if (!is_string($selector['eventReference']) || trim($selector['eventReference']) === ''
                || strlen($selector['eventReference']) > 8192) {
                throw new InvalidArgumentException('Invalid attachment event selector.');
            }
            return ['EventReference' => $selector['eventReference']];
        }
        if (array_diff(array_keys($selector), ['uid', 'recurrenceId']) !== []
            || !is_string($selector['uid'] ?? null) || trim($selector['uid']) === ''
            || strlen($selector['uid']) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $selector['uid'])
            || (isset($selector['recurrenceId']) && (!is_string($selector['recurrenceId'])
                || ($selector['recurrenceId'] !== ''
                    && preg_match('/^\d{8}(?:T\d{6}Z?)?$/D', $selector['recurrenceId']) !== 1)))) {
            throw new InvalidArgumentException('Invalid attachment event selector.');
        }
        $request = ['UID' => $selector['uid'], 'RecurrenceID' => $selector['recurrenceId'] ?? ''];
        $resourceUrl = $this->providerAttachmentResourceUrl($selector['uid']);
        if ($resourceUrl !== '') {
            $request['ResourceURL'] = $resourceUrl;
        }
        return $request;
    }

    /**
     * Resolves a provider resource only from the synchronized server-side cache.
     * Browser selectors deliberately cannot supply URLs. Ambiguous or non-HTTP
     * resources retain the provider's UID lookup fallback.
     */
    private function providerAttachmentResourceUrl(string $uid): string
    {
        $resources = [];
        foreach ($this->readEvents() as $event) {
            $cachedUid = trim((string) ($event['uid'] ?? ''));
            if ($cachedUid === '' || !hash_equals($uid, $cachedUid)) {
                continue;
            }
            $resourceUrl = trim((string) ($event['resourceUrl'] ?? ''));
            $scheme = strtolower((string) parse_url($resourceUrl, PHP_URL_SCHEME));
            if ($resourceUrl !== '' && in_array($scheme, ['http', 'https'], true)) {
                $resources[$resourceUrl] = true;
            }
        }

        return count($resources) === 1 ? (string) array_key_first($resources) : '';
    }

    /**
     * Administrative recovery boundary. Unlike end-user transfers this deliberately
     * ignores view/calendar policy, but uses the exact same original-data lock.
     *
     * @param list<string> $ids Explicit cleanup selection.
     * @return array<string, mixed>
     */
    private function localAttachmentMaintenance(string $operation, array $ids = [], string $revision = ''): array
    {
        $lock = 'OpenCalendar.Attachments.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 5000)) {
            throw new RuntimeException('Attachment storage is busy. Please try again.');
        }
        try {
            $store = new LocalAttachmentStore($this->ReadAttributeString('LocalAttachmentOriginals'));
            if ($operation === 'inventory') {
                return $store->inventory();
            }
            if ($operation === 'backup') {
                $snapshot = $store->exportSnapshot();
                $chunks = array_map(base64_encode(...), str_split($snapshot, 96 * 1024));
                $transfer = $this->CreateChunkedJsonTransfer(self::ATTACHMENT_BACKUP_TRANSFER_SCOPE, $chunks);
                return $transfer + [
                    'Format'             => 'OpenCalendar.LocalAttachments', 'Version' => 1,
                    'CalendarInstanceID' => $this->InstanceID,
                    'Bytes'              => strlen($snapshot), 'SHA256' => hash('sha256', $snapshot)
                ];
            }
            if ($operation !== 'delete') {
                throw new InvalidArgumentException('Invalid attachment maintenance operation.');
            }
            $removed = $store->removeSelected($ids, $revision);
            if (!$this->WriteAttributeString('LocalAttachmentOriginals', $store->exportSnapshot())) {
                throw new RuntimeException('Attachment originals could not be saved.');
            }
            return ['deleted' => $removed];
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Internal persistence boundary, not a public script or browser API.
     * The caller must first authenticate and resolve a verified owner binding.
     * Files remain in a dedicated durable attribute, never in caches/media/state.
     *
     * @param string $operation Policy operation: list, download, upload or delete.
     * @param string $owner Server-resolved owner hash, never browser input.
     * @param array<string, mixed> $request Operation fields after request validation.
     * @return array<mixed>|string Metadata or private download bytes.
     */
    private function localAttachmentOperation(string $operation, string $owner, array $request): array|string
    {
        if (!$this->CanAccessAttachments($operation, 'local')) {
            throw new RuntimeException('Attachment access denied.');
        }
        $lock = 'OpenCalendar.Attachments.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 5000)) {
            throw new RuntimeException('Attachment storage is busy. Please try again.');
        }
        try {
            // A request may have waited while the administrator revoked access.
            if (!$this->CanAccessAttachments($operation, 'local')) {
                throw new RuntimeException('Attachment access denied.');
            }
            $original = $this->ReadAttributeString('LocalAttachmentOriginals');
            $store = new LocalAttachmentStore($original);
            $field = static function (string $name) use ($request): string
            {
                if (!is_string($request[$name] ?? null)) {
                    throw new InvalidArgumentException('Invalid attachment request.');
                }
                return $request[$name];
            };
            if ($operation === 'upload') {
                AttachmentUploadPolicy::validate($field('name'), $field('content'));
            }
            $result = match ($operation) {
                'list'     => $store->listForOwner($owner),
                'download' => $store->read($owner, $field('id')),
                'upload'   => $store->add($owner, $field('requestId'), $field('name'), $field('content')),
                'delete'   => (static function () use ($store, $owner, $field): array
                {
                    $store->remove($owner, $field('id'), $field('revision'));
                    return ['deleted' => true];
                })(),
                default => throw new InvalidArgumentException('Invalid attachment operation.')
            };
            if (!$this->CanAccessAttachments($operation, 'local')) {
                throw new RuntimeException('Attachment access denied.');
            }
            if (in_array($operation, ['upload', 'delete'], true)) {
                $updated = $store->exportSnapshot();
                if ($updated !== $original && !$this->WriteAttributeString('LocalAttachmentOriginals', $updated)) {
                    throw new RuntimeException('Attachment originals could not be saved.');
                }
            }
            return $result;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Resolves an untrusted local event selector from current originals, then
     * runs the attachment transaction while preventing concurrent event deletion.
     * No public entry point exists yet; future callers must enforce view/session
     * and transport rights separately. Lock order: calendar, then attachments.
     *
     * @param string $operation Requested attachment operation.
     * @param array<string, mixed> $selector UID, bounded time window and optional original slot.
     * @param array<string, mixed> $request Attachment fields, never owner/source claims.
     * @return array<mixed>|string Metadata or private file bytes.
     */
    private function verifiedLocalAttachmentOperation(string $operation, array $selector, array $request): array|string
    {
        if (!$this->ReadPropertyBoolean('LocalCalendar') || !$this->CanAccessAttachments($operation, 'local')) {
            throw new RuntimeException('Local attachment access denied.');
        }
        if (array_diff(array_keys($selector), ['uid', 'startTimestamp', 'endTimestamp', 'recurrenceId', 'originalStart']) !== []
            || !is_string($selector['uid'] ?? null) || $selector['uid'] === '' || strlen($selector['uid']) > 8192
            || !is_int($selector['startTimestamp'] ?? null) || !is_int($selector['endTimestamp'] ?? null)
            || $selector['startTimestamp'] <= 0 || $selector['endTimestamp'] <= $selector['startTimestamp']
            || $selector['endTimestamp'] - $selector['startTimestamp'] > 7 * 86400) {
            throw new InvalidArgumentException('Invalid attachment event selector.');
        }
        foreach (['recurrenceId', 'originalStart'] as $slot) {
            if (isset($selector[$slot]) && (!is_string($selector[$slot]) || strlen($selector[$slot]) > 128)) {
                throw new InvalidArgumentException('Invalid attachment occurrence selector.');
            }
        }
        $lock = 'OpenCalendar.LocalCalendar.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 5000)) {
            throw new RuntimeException('The local calendar is busy. Please try again.');
        }
        try {
            if (!$this->ReadPropertyBoolean('LocalCalendar') || !$this->CanAccessAttachments($operation, 'local')) {
                throw new RuntimeException('Local attachment access denied.');
            }
            $reference = $this->effectiveCalendarId();
            $resources = json_decode($this->ReadAttributeString('LocalCalendarResources'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($resources)) {
                throw new UnexpectedValueException('Invalid local calendar originals.');
            }
            $provider = new LocalCalendarProvider($resources, $reference);
            $matches = [];
            foreach ($provider->getEvents($reference, new DateTimeImmutable('@' . $selector['startTimestamp']), new DateTimeImmutable('@' . $selector['endTimestamp'])) as $event) {
                if (($event['uid'] ?? '') !== $selector['uid']) {
                    continue;
                }
                $recurring = in_array($event['recurrenceType'] ?? '', ['occurrence', 'exception'], true) || !empty($event['recurring']);
                if ($recurring && empty($selector['recurrenceId']) && empty($selector['originalStart'])) {
                    continue;
                }
                foreach (['recurrenceId', 'originalStart'] as $slot) {
                    if (!empty($selector[$slot]) && ($event[$slot] ?? '') !== $selector[$slot]) {
                        continue 2;
                    }
                }
                $matches[] = $event;
            }
            if (count($matches) !== 1) {
                throw new RuntimeException('The attachment event is missing or ambiguous.');
            }
            $owner = AttachmentOwnerIdentity::key([
                'instanceId' => $this->InstanceID, 'provider' => 'local',
                'accountId'  => 'local:' . $this->InstanceID, 'calendarId' => $reference
            ], $matches[0]);
            return $this->localAttachmentOperation($operation, $owner, $request);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function refreshCalendarMetadataSafely(): void
    {
        try {
            $this->applyCalendarMetadata($this->sendRequest('GetCalendars'));
        } catch (Throwable $exception) {
            $this->SendSafeDebugException('CalendarMetadataError', $exception);
        }
    }

    /**
     * @param list<array<string, mixed>> $calendars
     */
    private function applyCalendarMetadata(array $calendars): void
    {
        $calendarId = $this->effectiveCalendarId();
        $providerCalendarId = $this->ReadPropertyString('ProviderCalendarID');
        $calendarUrl = $this->ReadPropertyString('CalendarURL');
        $availableCalendars = [];

        foreach ($calendars as $calendar) {
            if (!is_array($calendar)) {
                continue;
            }
            $availableCalendars[] = $calendar;
            $matches = ($calendarId !== '' && (string) ($calendar['id'] ?? '') === $calendarId)
                || ($providerCalendarId !== '' && (string) ($calendar['providerId'] ?? '') === $providerCalendarId)
                || ($calendarUrl !== '' && (string) ($calendar['url'] ?? '') === $calendarUrl);
            if (!$matches) {
                continue;
            }

            $this->storeCalendarMetadata($calendar);
            return;
        }

        $instanceName = trim(IPS_GetName($this->InstanceID));
        if ($instanceName !== '') {
            $nameMatches = array_values(array_filter(
                $availableCalendars,
                static fn (array $calendar): bool => strcasecmp(
                    trim((string) ($calendar['name'] ?? '')),
                    $instanceName
                ) === 0
            ));
            if (count($nameMatches) === 1) {
                $this->SendSafeDebug(
                    'CalendarResolution',
                    'Recovered the calendar identity from the unique instance name.'
                );
                $this->storeCalendarMetadata($nameMatches[0]);
                return;
            }
        }

        if (count($availableCalendars) === 1) {
            $this->storeCalendarMetadata($availableCalendars[0]);
            return;
        }

        if ($availableCalendars !== []) {
            $this->resetCalendarMetadataResolution();
        }
    }

    /**
     * @param array<string, mixed> $calendar
     */
    private function storeCalendarMetadata(array $calendar): void
    {
        $metadata = $this->calendarMetadataFromProvider($calendar);
        $this->writeCalendarMetadata($metadata);
        $this->SendSafeDebug('CalendarMetadata', [
            'canWrite'                  => $metadata['canWrite'],
            'writeAccessKnown'          => $metadata['writeAccessKnown'],
            'timezone'                  => $metadata['calendarTimezone'],
            'canCreateRecurrence'       => $metadata['canCreateRecurrence'],
            'canUpdateRecurrence'       => $metadata['canUpdateRecurrence'],
            'canUpdateOccurrence'       => $metadata['canUpdateOccurrence'],
            'canUpdateFollowing'        => $metadata['canUpdateFollowing'],
            'canUpdateSeries'           => $metadata['canUpdateSeries'],
            'canDeleteSeries'           => $metadata['canDeleteSeries'],
            'maxReminders'              => $metadata['maxReminders'],
            'canUseDefaultReminder'     => $metadata['canUseDefaultReminder'],
            'canCreateDefaultReminder'  => $metadata['canCreateWithDefaultReminder'],
            'canWriteStatus'            => $metadata['canWriteStatus'],
            'canWriteTransparency'      => $metadata['canWriteTransparency'],
            'defaultStatus'             => $metadata['defaultStatus'],
            'defaultTransparency'       => $metadata['defaultTransparency'],
            'defaultAllDayTransparency' => $metadata['defaultAllDayTransparency']
        ]);
    }

    /**
     * @param array<string, mixed> $calendar
     * @return array<string, mixed>
     */
    private function calendarMetadataFromProvider(array $calendar): array
    {
        $capabilities = is_array($calendar['capabilities'] ?? null) ? $calendar['capabilities'] : [];
        $canUseDefaultReminder = (bool) ($capabilities['useDefaultReminder'] ?? false);
        $defaultTransparency = CalendarEventState::normalizeTransparency(
            $calendar['defaultTransparency'] ?? '',
            CalendarEventState::TRANSP_OPAQUE
        );
        $defaultReminder = is_array($calendar['defaultReminder'] ?? null)
            && !array_is_list($calendar['defaultReminder'])
            ? $calendar['defaultReminder']
            : [];

        // Cached calendar metadata created before writeAccessKnown existed cannot
        // distinguish an explicit read-only result from incomplete DAV privilege
        // discovery. Keep it unknown so the persisted CanWrite value can recover
        // existing writable calendar instances after an update.
        $writeAccessKnown = array_key_exists('writeAccessKnown', $calendar)
            && (bool) $calendar['writeAccessKnown'];

        return [
            'available'                    => true,
            'resolvedCalendarId'           => trim((string) ($calendar['id'] ?? '')),
            'calendarColor'                => trim((string) ($calendar['color'] ?? '')),
            'canWrite'                     => (bool) ($capabilities['create'] ?? false)
                || (bool) ($capabilities['update'] ?? false)
                || (bool) ($capabilities['delete'] ?? false),
            'canCreateRecurrence'          => (bool) ($capabilities['createRecurrence'] ?? false),
            'canUpdateRecurrence'          => (bool) ($capabilities['updateRecurrence'] ?? false),
            'canUpdateOccurrence'          => (bool) ($capabilities['updateOccurrence'] ?? false),
            'canDeleteOccurrence'          => (bool) ($capabilities['deleteOccurrence'] ?? false),
            'canUpdateFollowing'           => (bool) ($capabilities['updateFollowing'] ?? false),
            'canUpdateSeries'              => (bool) ($capabilities['updateSeries'] ?? false),
            'canDeleteSeries'              => (bool) ($capabilities['deleteSeries'] ?? false),
            'canUseDefaultReminder'        => $canUseDefaultReminder,
            'canCreateWithDefaultReminder' => (bool) ($capabilities['createWithDefaultReminder'] ?? false),
            'canWriteStatus'               => (bool) ($capabilities['writeStatus'] ?? false),
            'canWriteTransparency'         => (bool) ($capabilities['writeTransparency'] ?? false),
            'defaultStatus'                => CalendarEventState::normalizeStatus(
                $calendar['defaultStatus'] ?? '',
                CalendarEventState::STATUS_CONFIRMED
            ),
            'defaultTransparency'          => $defaultTransparency,
            'defaultAllDayTransparency'    => CalendarEventState::normalizeTransparency(
                $calendar['defaultAllDayTransparency'] ?? '',
                $defaultTransparency
            ),
            'maxReminders'                 => max(1, min(5, (int) ($capabilities['maxReminders'] ?? 1))),
            'defaultReminderJson'          => json_encode(
                $canUseDefaultReminder ? $defaultReminder : [],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'calendarTimezone'             => trim((string) ($calendar['timezone'] ?? '')),
            'writeAccessKnown'             => $writeAccessKnown
        ];
    }

    private function registerCalendarMetadataAttributes(): void
    {
        $this->RegisterAttributeBoolean('CalendarMetadataAvailable', false);
        $this->RegisterAttributeString('ResolvedCalendarID', '');
        $this->RegisterAttributeString('DetectedCalendarColor', '');
        $this->RegisterAttributeBoolean('DetectedCanWrite', false);
        $this->RegisterAttributeBoolean('DetectedCanCreateRecurrence', false);
        $this->RegisterAttributeBoolean('DetectedCanUpdateRecurrence', false);
        $this->RegisterAttributeBoolean('DetectedCanUpdateOccurrence', false);
        $this->RegisterAttributeBoolean('DetectedCanDeleteOccurrence', false);
        $this->RegisterAttributeBoolean('DetectedCanUpdateFollowing', false);
        $this->RegisterAttributeBoolean('DetectedCanUpdateSeries', false);
        $this->RegisterAttributeBoolean('DetectedCanDeleteSeries', false);
        $this->RegisterAttributeBoolean('DetectedCanUseDefaultReminder', false);
        $this->RegisterAttributeBoolean('DetectedCanCreateWithDefaultReminder', false);
        $this->RegisterAttributeBoolean('DetectedCanWriteStatus', false);
        $this->RegisterAttributeBoolean('DetectedCanWriteTransparency', false);
        $this->RegisterAttributeString('DetectedDefaultStatus', CalendarEventState::STATUS_CONFIRMED);
        $this->RegisterAttributeString('DetectedDefaultTransparency', CalendarEventState::TRANSP_OPAQUE);
        $this->RegisterAttributeString('DetectedDefaultAllDayTransparency', CalendarEventState::TRANSP_OPAQUE);
        $this->RegisterAttributeInteger('DetectedMaxReminders', 1);
        $this->RegisterAttributeString('DetectedDefaultReminder', '{}');
        $this->RegisterAttributeString('DetectedCalendarTimezone', '');
        $this->RegisterAttributeBoolean('DetectedWriteAccessKnown', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function readCalendarMetadata(): array
    {
        $metadata = [];
        foreach (self::CALENDAR_METADATA_ATTRIBUTES as $key => $definition) {
            $metadata[$key] = match ($definition['type']) {
                'boolean' => $this->ReadAttributeBoolean($definition['name']),
                'integer' => $this->ReadAttributeInteger($definition['name']),
                'string'  => $this->ReadAttributeString($definition['name']),
                default   => $definition['default']
            };
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function writeCalendarMetadata(array $metadata): void
    {
        foreach (self::CALENDAR_METADATA_ATTRIBUTES as $key => $definition) {
            if (!array_key_exists($key, $metadata)) {
                continue;
            }

            switch ($definition['type']) {
                case 'boolean':
                    $this->WriteAttributeBoolean($definition['name'], (bool) $metadata[$key]);
                    break;

                case 'integer':
                    $this->WriteAttributeInteger($definition['name'], (int) $metadata[$key]);
                    break;

                case 'string':
                    $this->WriteAttributeString($definition['name'], (string) $metadata[$key]);
                    break;

                default:
                    throw new LogicException('Unsupported calendar metadata attribute type.');
            }
        }
    }

    private function resetCalendarMetadataResolution(): void
    {
        $this->writeCalendarMetadata([
            'available'                    => false,
            'resolvedCalendarId'           => '',
            'canCreateRecurrence'          => false,
            'canUpdateRecurrence'          => false,
            'canUpdateOccurrence'          => false,
            'canDeleteOccurrence'          => false,
            'canUpdateFollowing'           => false,
            'canUpdateSeries'              => false,
            'canDeleteSeries'              => false,
            'canUseDefaultReminder'        => false,
            'canCreateWithDefaultReminder' => false,
            'canWriteStatus'               => false,
            'canWriteTransparency'         => false,
            'defaultStatus'                => CalendarEventState::STATUS_CONFIRMED,
            'defaultTransparency'          => CalendarEventState::TRANSP_OPAQUE,
            'defaultAllDayTransparency'    => CalendarEventState::TRANSP_OPAQUE,
            'maxReminders'                 => 1,
            'defaultReminderJson'          => '{}',
            'calendarTimezone'             => '',
            'writeAccessKnown'             => false
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestEvents(): array
    {
        $pastDays = max(0, min(1095, $this->ReadPropertyInteger('PastDays')));
        $futureDays = max(1, min(1095, $this->ReadPropertyInteger('FutureDays')));
        $today = new DateTimeImmutable('today');
        $start = $today->modify('-' . $pastDays . ' days');
        $end = $today->modify('+' . ($futureDays + 1) . ' days');
        $startTimestamp = $start->getTimestamp();
        $endTimestamp = $end->getTimestamp();
        $syncToken = $this->incrementalSyncTokenForWindow($startTimestamp, $endTimestamp);
        $startedAt = microtime(true);
        $this->SendSafeDebug('EventTransferStart', [
            'start'                => $start->format(DATE_ATOM),
            'end'                  => $end->format(DATE_ATOM),
            'incrementalRequested' => $syncToken !== ''
        ]);
        $transfer = $this->sendRequest(
            'BeginEventsTransfer',
            [
                'Start'     => $startTimestamp,
                'End'       => $endTimestamp,
                'SyncToken' => $syncToken
            ]
        );
        $token = trim((string) ($transfer['Token'] ?? ''));
        $pageCount = (int) ($transfer['PageCount'] ?? 0);
        $itemCount = (int) ($transfer['ItemCount'] ?? -1);
        $nextSyncToken = trim((string) ($transfer['SyncToken'] ?? ''));
        $incremental = (bool) ($transfer['Incremental'] ?? false);
        if ($incremental && $syncToken === '') {
            throw new UnexpectedValueException('The calendar account returned an unexpected incremental event transfer.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1
            || $pageCount < 1
            || $pageCount > 10_000
            || $itemCount < 0) {
            throw new UnexpectedValueException('The calendar account returned invalid event transfer metadata.');
        }
        $this->SendSafeDebug('EventTransferMetadata', [
            'pageCount'   => $pageCount,
            'itemCount'   => $itemCount,
            'incremental' => $incremental
        ]);

        $transferredEvents = [];
        try {
            for ($page = 0; $page < $pageCount; ++$page) {
                $payload = $this->sendRequest(
                    'ReadEventsTransferPage',
                    ['Token' => $token, 'Page' => $page]
                );
                if (($payload['Token'] ?? null) !== $token
                    || (int) ($payload['Page'] ?? -1) !== $page
                    || (int) ($payload['PageCount'] ?? 0) !== $pageCount
                    || (int) ($payload['ItemCount'] ?? -1) !== $itemCount
                    || (bool) ($payload['Complete'] ?? false) !== ($page === $pageCount - 1)
                    || !is_array($payload['Items'] ?? null)
                    || !array_is_list($payload['Items'])) {
                    throw new UnexpectedValueException('The calendar account returned an invalid event transfer page.');
                }

                foreach ($payload['Items'] as $event) {
                    if (!is_array($event) || array_is_list($event)) {
                        throw new UnexpectedValueException('The calendar account returned invalid event data.');
                    }
                    $transferredEvents[] = $event;
                }
            }
        } finally {
            $this->finishEventTransfer($token);
        }

        if (count($transferredEvents) !== $itemCount) {
            throw new UnexpectedValueException('The calendar account returned an incomplete event transfer.');
        }

        $cachedEvents = $this->readEvents();
        $events = $incremental
            ? $this->mergeIncrementalEvents($cachedEvents, $transferredEvents)
            : array_values(array_filter(
                $transferredEvents,
                static fn (array $event): bool => !($event['_syncDeleted'] ?? false)
            ));
        $events = CalendarEventState::filterVisibleEvents($events);
        $this->reconcileAnniversaryMetadataAfterSynchronization($events, $cachedEvents);
        $events = CalendarTaskEvent::preservePendingSeries($events, $cachedEvents, $today);
        // Persist the fetched state before advancing its delta cursor.
        $this->storeEvents($events);
        foreach ($transferredEvents as $transferredEvent) {
            if ((bool) ($transferredEvent['_syncDeleted'] ?? false)) {
                $this->forgetPendingTask($transferredEvent);
            }
        }
        if ($nextSyncToken !== '') {
            $this->storeIncrementalSyncState($nextSyncToken, $startTimestamp, $endTimestamp);
        } else {
            $this->clearIncrementalSyncState();
        }

        $this->SendSafeDebug('EventTransferCompleted', [
            'eventCount'        => count($events),
            'transferredCount'  => count($transferredEvents),
            'incremental'       => $incremental,
            'durationMs'        => (int) round((microtime(true) - $startedAt) * 1000)
        ]);

        return $events;
    }

    private function incrementalSyncTokenForWindow(int $startTimestamp, int $endTimestamp): string
    {
        $token = trim($this->ReadAttributeString('IncrementalSyncToken'));
        if ($token === ''
            || $this->ReadAttributeInteger('IncrementalSyncWindowStart') !== $startTimestamp
            || $this->ReadAttributeInteger('IncrementalSyncWindowEnd') !== $endTimestamp
            || !hash_equals(
                $this->ReadAttributeString('IncrementalSyncCalendarID'),
                $this->effectiveCalendarId()
            )) {
            return '';
        }

        return $token;
    }

    private function storeIncrementalSyncState(string $token, int $startTimestamp, int $endTimestamp): void
    {
        $this->WriteAttributeString('IncrementalSyncToken', trim($token));
        $this->WriteAttributeInteger('IncrementalSyncWindowStart', $startTimestamp);
        $this->WriteAttributeInteger('IncrementalSyncWindowEnd', $endTimestamp);
        $this->WriteAttributeString('IncrementalSyncCalendarID', $this->effectiveCalendarId());
    }

    private function clearIncrementalSyncState(): void
    {
        $this->WriteAttributeString('IncrementalSyncToken', '');
        $this->WriteAttributeInteger('IncrementalSyncWindowStart', 0);
        $this->WriteAttributeInteger('IncrementalSyncWindowEnd', 0);
        $this->WriteAttributeString('IncrementalSyncCalendarID', '');
    }

    /**
     * Synchronizes a selected Microsoft To Do list without making calendar-event synchronization fail.
     */
    private function synchronizeMicrosoftTasksSafely(): int
    {
        $listId = trim($this->ReadPropertyString('MicrosoftTaskListID'));
        if ($this->ReadPropertyBoolean('LocalCalendar') || $listId === '') {
            $this->clearMicrosoftTaskSyncState();
            $this->ClearPersistentJsonCache('CachedMicrosoftTasks');
            return 0;
        }

        try {
            $tasks = $this->synchronizeMicrosoftTasks($listId);
            $this->WriteAttributeString('MicrosoftTaskLastError', '');
            return count($tasks);
        } catch (Throwable $exception) {
            $message = trim((string) preg_replace('/\s+/', ' ', $exception->getMessage()));
            if ($message === '') {
                $message = 'Microsoft To Do synchronization failed.';
            }
            $this->WriteAttributeString('MicrosoftTaskLastError', $this->Translate($message));
            $this->SendSafeDebugException('MicrosoftTaskSynchronizationError', $exception);
            return count($this->readMicrosoftTasks());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function synchronizeMicrosoftTasks(string $listId): array
    {
        $sameList = hash_equals($this->ReadAttributeString('MicrosoftTaskSyncListID'), $listId);
        $deltaLink = $sameList ? trim($this->ReadAttributeString('MicrosoftTaskDeltaLink')) : '';
        $result = $this->sendRequest('SynchronizeTasks', [
            'TaskListID' => $listId,
            'DeltaLink'  => $deltaLink
        ]);
        if (!isset($result['tasks']) || !is_array($result['tasks']) || !array_is_list($result['tasks'])) {
            throw new UnexpectedValueException('The calendar account returned invalid Microsoft task data.');
        }
        $nextDeltaLink = trim((string) ($result['deltaLink'] ?? ''));
        if ($nextDeltaLink === '') {
            throw new UnexpectedValueException('The calendar account returned no Microsoft task delta link.');
        }
        $fullSnapshot = (bool) ($result['fullSnapshot'] ?? false);

        $changes = [];
        foreach ($result['tasks'] as $task) {
            if (!is_array($task) || array_is_list($task) || trim((string) ($task['id'] ?? '')) === '') {
                throw new UnexpectedValueException('The calendar account returned invalid Microsoft task data.');
            }
            $changes[] = $task;
        }

        $tasks = $deltaLink === '' || $fullSnapshot ? $this->activeMicrosoftTasks($changes) : $this->mergeMicrosoftTaskChanges(
            $sameList ? $this->readMicrosoftTasks() : [],
            $changes
        );
        // Persist task data before advancing the Graph delta cursor.
        $this->WritePersistentJsonCache('CachedMicrosoftTasks', $tasks);
        $this->WriteAttributeString('MicrosoftTaskSyncListID', $listId);
        $this->WriteAttributeString('MicrosoftTaskDeltaLink', $nextDeltaLink);

        return $tasks;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function activeMicrosoftTasks(array $tasks): array
    {
        return array_values(array_filter(
            $tasks,
            static fn (array $task): bool => !(bool) ($task['deleted'] ?? false)
        ));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @param list<array<string, mixed>> $changes
     * @return list<array<string, mixed>>
     */
    private function mergeMicrosoftTaskChanges(array $tasks, array $changes): array
    {
        $indexed = [];
        foreach ($tasks as $task) {
            $id = trim((string) ($task['id'] ?? ''));
            if ($id !== '') {
                $indexed[$id] = $task;
            }
        }
        foreach ($changes as $change) {
            $id = trim((string) $change['id']);
            if ((bool) ($change['deleted'] ?? false)) {
                unset($indexed[$id]);
                continue;
            }
            $indexed[$id] = $change;
        }

        return array_values($indexed);
    }

    private function resetMicrosoftTaskSyncForSelection(): void
    {
        $selectedListId = trim($this->ReadPropertyString('MicrosoftTaskListID'));
        $synchronizedListId = trim($this->ReadAttributeString('MicrosoftTaskSyncListID'));
        if ($synchronizedListId === '' || hash_equals($synchronizedListId, $selectedListId)) {
            return;
        }
        $this->clearMicrosoftTaskSyncState();
        $this->ClearPersistentJsonCache('CachedMicrosoftTasks');
    }

    private function clearMicrosoftTaskSyncState(): void
    {
        $this->WriteAttributeString('MicrosoftTaskDeltaLink', '');
        $this->WriteAttributeString('MicrosoftTaskSyncListID', '');
        $this->WriteAttributeString('MicrosoftTaskLastError', '');
    }

    /** @param array<string, mixed> $event */
    private function isMicrosoftTodoEvent(array $event): bool
    {
        return strtolower(trim((string) ($event['sourceType'] ?? ''))) === 'microsoft-todo'
            || strtolower(trim((string) ($event['taskProvider'] ?? ''))) === 'microsoft-todo';
    }

    /** @param array<string, mixed> $event */
    private function mustCreateMicrosoftTodoTask(array $event): bool
    {
        return !$this->ReadPropertyBoolean('LocalCalendar')
            && trim($this->ReadPropertyString('MicrosoftTaskListID')) !== ''
            && (bool) ($event['task'] ?? false);
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function createMicrosoftTodoTask(array $event): array
    {
        // Reuse the shared task validation without persisting its calendar title marker.
        CalendarTaskEvent::prepareWrite($event);

        $listId = trim($this->ReadPropertyString('MicrosoftTaskListID'));
        $title = trim((string) ($event['summary'] ?? ''));
        $timezone = trim((string) ($event['timezone'] ?? ''));
        if ($timezone === '') {
            $timezone = trim($this->ReadAttributeString('DetectedCalendarTimezone'));
        }
        if ($timezone === '') {
            $timezone = date_default_timezone_get();
        }

        $task = [
            'title'       => $title,
            'description' => (string) ($event['description'] ?? ''),
            'status'      => (bool) ($event['taskCompleted'] ?? false) ? 'completed' : 'notStarted',
            'dueDateTime' => $this->microsoftTodoDueDateTime(
                (string) ($event['start'] ?? ''),
                ['timeZone' => $timezone]
            )
        ];

        $recurrence = $event['recurrence'] ?? null;
        if ($recurrence !== null && $recurrence !== []) {
            if (!is_array($recurrence) || array_is_list($recurrence)) {
                throw new InvalidArgumentException('The recurrence settings are invalid.');
            }
            if (trim((string) ($recurrence['recurrenceTimeZone'] ?? '')) === '') {
                $recurrence['recurrenceTimeZone'] = $timezone;
            }
            $startDate = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                substr(trim((string) ($event['start'] ?? '')), 0, 10),
                new DateTimeZone('UTC')
            );
            if ($startDate === false) {
                throw new InvalidArgumentException('The Microsoft To Do due date is invalid.');
            }
            $task['recurrence'] = CalendarRecurrenceRule::toMicrosoftRecurrence($recurrence, $startDate);
        }

        return $this->sendRequest('CreateTask', [
            'TaskListID' => $listId,
            'Task'       => $task
        ]);
    }

    /**
     * @param array<string, mixed> $event
     * @return array{0:string,1:string}
     */
    private function microsoftTodoIdentity(array $event): array
    {
        if (!$this->isMicrosoftTodoEvent($event)) {
            throw new InvalidArgumentException('The selected item is not a Microsoft To Do task.');
        }
        $listId = trim((string) ($event['taskListId'] ?? ''));
        $taskId = trim((string) ($event['taskId'] ?? ''));
        $configuredListId = trim($this->ReadPropertyString('MicrosoftTaskListID'));
        if ($listId === '' || $taskId === '' || $configuredListId === '' || !hash_equals($configuredListId, $listId)) {
            throw new InvalidArgumentException('The Microsoft To Do task identity is invalid.');
        }
        foreach ($this->readMicrosoftTasks() as $task) {
            if (hash_equals($taskId, trim((string) ($task['id'] ?? '')))
                && hash_equals($listId, trim((string) ($task['listId'] ?? '')))) {
                return [$listId, $taskId];
            }
        }
        throw new RuntimeException('The Microsoft To Do task is no longer available. Synchronize the calendar and try again.');
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function microsoftTodoEventForEdit(array $event): array
    {
        [$listId, $taskId] = $this->microsoftTodoIdentity($event);
        foreach ($this->readMicrosoftTasks() as $task) {
            if (hash_equals($taskId, trim((string) ($task['id'] ?? '')))
                && hash_equals($listId, trim((string) ($task['listId'] ?? '')))) {
                $projected = MicrosoftTodoTaskProjection::project([$task]);
                if ($projected !== []) {
                    return $projected[0];
                }
                throw new RuntimeException('The Microsoft To Do task has no valid due date.');
            }
        }
        throw new RuntimeException('The Microsoft To Do task is no longer available. Synchronize the calendar and try again.');
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function updateMicrosoftTodoEvent(array $event, array $changes): array
    {
        [$listId, $taskId] = $this->microsoftTodoIdentity($event);
        $sourceTask = null;
        foreach ($this->readMicrosoftTasks() as $task) {
            if (hash_equals($taskId, trim((string) ($task['id'] ?? '')))
                && hash_equals($listId, trim((string) ($task['listId'] ?? '')))) {
                $sourceTask = $task;
                break;
            }
        }
        if ($sourceTask === null) {
            throw new RuntimeException('The Microsoft To Do task is no longer available. Synchronize the calendar and try again.');
        }
        if (array_key_exists('task', $changes) && !(bool) $changes['task']) {
            throw new InvalidArgumentException('A Microsoft To Do task cannot be converted into a calendar event.');
        }
        if (array_key_exists('recurrence', $changes)) {
            throw new InvalidArgumentException('Microsoft To Do recurrence editing is not supported yet.');
        }

        $taskChanges = [];
        if (array_key_exists('summary', $changes)) {
            $taskChanges['title'] = trim((string) $changes['summary']);
        }
        if (array_key_exists('description', $changes)) {
            $taskChanges['description'] = (string) $changes['description'];
        }
        if (array_key_exists('start', $changes)) {
            $currentDue = is_array($sourceTask['dueDateTime'] ?? null) ? $sourceTask['dueDateTime'] : [];
            $dueDateTime = $this->microsoftTodoDueDateTime(
                (string) $changes['start'],
                $currentDue
            );
            if ($dueDateTime !== $currentDue) {
                $taskChanges['dueDateTime'] = $dueDateTime;
            }
        }
        if (array_key_exists('taskCompleted', $changes) || array_key_exists('taskStatus', $changes)) {
            $requestedCompleted = array_key_exists('taskStatus', $changes)
                ? strtolower(trim((string) $changes['taskStatus'])) === 'completed'
                : (bool) ($changes['taskCompleted'] ?? false);
            $currentlyCompleted = strcasecmp(trim((string) ($sourceTask['status'] ?? '')), 'completed') === 0;
            if ($requestedCompleted !== $currentlyCompleted) {
                $taskChanges['status'] = $requestedCompleted ? 'completed' : 'notStarted';
                if (!$requestedCompleted && $this->ReadPropertyInteger('MicrosoftTaskReopenMode') === 1) {
                    $taskChanges['reopenAsSingle'] = true;
                }
            }
        }
        if ($taskChanges === []) {
            return $sourceTask;
        }

        return $this->sendRequest('UpdateTask', [
            'TaskListID' => $listId,
            'TaskID'     => $taskId,
            'Changes'    => $taskChanges
        ]);
    }

    /**
     * @param array<string, mixed> $currentDueDateTime
     * @return array{dateTime:string,timeZone:string}
     */
    private function microsoftTodoDueDateTime(string $value, array $currentDueDateTime): array
    {
        $date = substr(trim($value), 0, 10);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('The Microsoft To Do due date is invalid.');
        }
        $currentValue = trim((string) ($currentDueDateTime['dateTime'] ?? ''));
        $timezone = trim((string) ($currentDueDateTime['timeZone'] ?? ''));
        $timezone = $timezone !== '' ? $timezone : date_default_timezone_get();
        if ($currentValue === '') {
            return ['dateTime' => $date . 'T00:00:00', 'timeZone' => $timezone];
        }
        $sourceDate = MicrosoftTodoTaskProjection::dateTime($currentDueDateTime);
        if ($sourceDate === null) {
            throw new InvalidArgumentException('The Microsoft To Do due date is invalid.');
        }
        $displayDate = $sourceDate->setTimezone(new DateTimeZone(date_default_timezone_get()));
        if ($displayDate->format('Y-m-d') === $date) {
            return $currentDueDateTime;
        }
        // The editor changes a displayed local date, not the date component of Graph's UTC timestamp.
        // Keep the local wall time while resolving the selected date's own daylight-saving offset.
        $movedDate = $displayDate->setDate((int) $parsed->format('Y'), (int) $parsed->format('m'), (int) $parsed->format('d'));
        $movedDate = $movedDate->setTimezone(MicrosoftTodoTaskProjection::timezone($timezone));
        $fraction = preg_match('/(\.\d+)(?:Z|[+-]\d{2}:\d{2})?$/D', $currentValue, $matches) === 1
            ? $matches[1]
            : '';
        return [
            'dateTime' => $movedDate->format('Y-m-d\TH:i:s') . $fraction,
            'timeZone' => $timezone
        ];
    }

    /** @param array<string, mixed> $updatedTask */
    private function storeMicrosoftTaskAfterWrite(array $updatedTask): void
    {
        $taskId = trim((string) ($updatedTask['id'] ?? ''));
        $listId = trim((string) ($updatedTask['listId'] ?? ''));
        if ($taskId === '' || $listId === '') {
            throw new UnexpectedValueException('The calendar account returned invalid Microsoft task data.');
        }
        $tasks = $this->readMicrosoftTasks();
        $replaced = false;
        foreach ($tasks as &$task) {
            if (hash_equals($taskId, trim((string) ($task['id'] ?? '')))
                && hash_equals($listId, trim((string) ($task['listId'] ?? '')))) {
                $task = $updatedTask;
                $replaced = true;
                break;
            }
        }
        unset($task);
        if (!$replaced) {
            $tasks[] = $updatedTask;
        }
        $this->WritePersistentJsonCache('CachedMicrosoftTasks', $tasks);
        $this->afterMicrosoftTaskWrite();
    }

    private function removeMicrosoftTaskFromCache(string $listId, string $taskId): void
    {
        $tasks = array_values(array_filter(
            $this->readMicrosoftTasks(),
            static fn (array $task): bool => !(
                hash_equals($taskId, trim((string) ($task['id'] ?? '')))
                && hash_equals($listId, trim((string) ($task['listId'] ?? '')))
            )
        ));
        $this->WritePersistentJsonCache('CachedMicrosoftTasks', $tasks);
        $this->afterMicrosoftTaskWrite();
    }

    private function afterMicrosoftTaskWrite(): void
    {
        $this->WriteAttributeString('MicrosoftTaskLastError', '');
        $this->WriteAttributeString('LastError', '');
        $this->SetStatus($this->ReadPropertyBoolean('Active') ? IS_ACTIVE : IS_INACTIVE);
        $this->SetValue('LastSynchronization', $this->ReadAttributeInteger('LastSynchronization'));
    }

    /**
     * @return list<array{caption:string,value:string}>
     */
    private function microsoftTaskListOptions(): array
    {
        $options = [['caption' => 'Do not include Microsoft To Do tasks', 'value' => '']];
        $selectedListId = trim($this->ReadPropertyString('MicrosoftTaskListID'));
        if ($this->ReadPropertyBoolean('LocalCalendar') || !$this->HasActiveParent()) {
            return $this->appendMissingMicrosoftTaskListOption($options, $selectedListId);
        }

        try {
            $taskLists = $this->requestMicrosoftTaskListsForForm();
            foreach ($taskLists as $taskList) {
                $id = trim((string) ($taskList['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $name = trim((string) ($taskList['name'] ?? ''));
                $options[] = ['caption' => $name !== '' ? $name : $id, 'value' => $id];
            }
        } catch (Throwable $exception) {
            $this->SendSafeDebugException('MicrosoftTaskListDiscoveryError', $exception);
        }

        return $this->appendMissingMicrosoftTaskListOption($options, $selectedListId);
    }

    /**
     * @param list<array{caption:string,value:string}> $options
     * @return list<array{caption:string,value:string}>
     */
    private function appendMissingMicrosoftTaskListOption(array $options, string $selectedListId): array
    {
        if ($selectedListId === '' || in_array($selectedListId, array_column($options, 'value'), true)) {
            return $options;
        }
        $options[] = [
            'caption' => sprintf($this->Translate('Configured task list (%s)'), $selectedListId),
            'value'   => $selectedListId
        ];
        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestMicrosoftTaskListsForForm(): array
    {
        $responseJson = $this->SendDataToParent($this->EncodeDataFlowMessage(
            self::DATA_ID_TO_PARENT,
            ['Operation' => 'GetTaskLists', 'RequestID' => bin2hex(random_bytes(8))]
        ));
        if ($responseJson === '') {
            throw new RuntimeException('The calendar account did not return a response.');
        }
        $response = json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($response) || !($response['Success'] ?? false) || !is_array($response['Payload'] ?? null)) {
            throw new UnexpectedValueException('The calendar account returned invalid Microsoft task data.');
        }
        return array_values(array_filter($response['Payload'], 'is_array'));
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $changes
     * @return list<array<string, mixed>>
     */
    private function mergeIncrementalEvents(array $events, array $changes): array
    {
        $cachedOccurrenceIdentities = $this->cachedOccurrenceIdentities($events);
        $replacedResources = [];
        foreach ($changes as $change) {
            if ((bool) ($change['_syncDeleted'] ?? false)
                || !(bool) ($change['_syncReplaceResource'] ?? false)) {
                continue;
            }
            $resourceUrl = trim((string) ($change['resourceUrl'] ?? ''));
            if ($resourceUrl !== '') {
                $replacedResources[CalDAVResourceIdentity::key($resourceUrl)] = true;
            }
        }
        if ($replacedResources !== []) {
            $events = array_values(array_filter(
                $events,
                static function (array $event) use ($replacedResources): bool
                {
                    $resourceUrl = trim((string) ($event['resourceUrl'] ?? ''));
                    return $resourceUrl === '' || !isset($replacedResources[CalDAVResourceIdentity::key($resourceUrl)]);
                }
            ));
        }

        foreach ($changes as $change) {
            $change = $this->restoreMissingOccurrenceIdentity($change, $cachedOccurrenceIdentities);
            $eventReference = trim((string) ($change['eventReference'] ?? ''));
            $resourceUrl = trim((string) ($change['resourceUrl'] ?? ''));
            if ((bool) ($change['_syncDeleted'] ?? false)) {
                if ($eventReference === '' && $resourceUrl === '') {
                    continue;
                }
                $deletedSeriesId = trim((string) ($change['seriesId'] ?? ''));
                $events = array_values(array_filter(
                    $events,
                    static function (array $event) use (
                        $eventReference,
                        $resourceUrl,
                        $deletedSeriesId
                    ): bool {
                        $candidateResource = trim((string) ($event['resourceUrl'] ?? ''));
                        if ($resourceUrl !== ''
                            && $candidateResource !== ''
                            && hash_equals(CalDAVResourceIdentity::key($resourceUrl), CalDAVResourceIdentity::key($candidateResource))) {
                            return false;
                        }

                        if ($eventReference === '') {
                            return true;
                        }
                        $candidateReference = trim((string) ($event['eventReference'] ?? ''));
                        $candidateOccurrence = trim((string) ($event['occurrenceId'] ?? ''));
                        if (($candidateReference !== '' && hash_equals($eventReference, $candidateReference))
                            || ($candidateOccurrence !== '' && hash_equals($eventReference, $candidateOccurrence))) {
                            return false;
                        }

                        $candidateSeries = trim((string) ($event['seriesId'] ?? ''));
                        return $deletedSeriesId !== ''
                            || $candidateSeries === ''
                            || !hash_equals($eventReference, $candidateSeries);
                    }
                ));
                continue;
            }

            $occurrenceId = trim((string) ($change['occurrenceId'] ?? ''));
            $replaceResource = (bool) ($change['_syncReplaceResource'] ?? false);
            if ($eventReference === '' && $resourceUrl === '' && $occurrenceId === '') {
                continue;
            }
            $events = array_values(array_filter(
                $events,
                function (array $event) use (
                    $eventReference,
                    $resourceUrl,
                    $occurrenceId,
                    $replaceResource,
                    $change
                ): bool {
                    foreach ([
                        [$eventReference, trim((string) ($event['eventReference'] ?? ''))],
                        [$replaceResource ? '' : CalDAVResourceIdentity::key($resourceUrl), CalDAVResourceIdentity::key((string) ($event['resourceUrl'] ?? ''))],
                        [$occurrenceId, trim((string) ($event['occurrenceId'] ?? ''))]
                    ] as [$expected, $actual]) {
                        if ($expected !== '' && $actual !== '' && hash_equals($expected, $actual)) {
                            return false;
                        }
                    }

                    return !CalendarEventLookup::sameOccurrence($event, $change);
                }
            ));
            if ($this->eventOverlapsConfiguredRange($change)) {
                unset($change['_syncReplaceResource']);
                $events[] = $change;
            }
        }

        usort(
            $events,
            static fn (array $left, array $right): int => ((int) ($left['startTimestamp'] ?? 0)
                <=> (int) ($right['startTimestamp'] ?? 0))
                ?: strcasecmp((string) ($left['summary'] ?? ''), (string) ($right['summary'] ?? ''))
        );

        return $events;
    }

    /**
     * Retains immutable occurrence anchors when a provider omits them in a delta response.
     *
     * @param list<array<string, mixed>> $events
     * @return array<string, array{originalStart:string, canUpdateFollowing:bool}>
     */
    private function cachedOccurrenceIdentities(array $events): array
    {
        $identities = [];
        foreach ($events as $event) {
            if (!CalendarEventRecurrence::isOccurrence($event)) {
                continue;
            }
            $seriesId = trim((string) ($event['seriesId'] ?? ''));
            $originalStart = trim((string) ($event['originalStart'] ?? ''));
            if ($seriesId === '' || $originalStart === '') {
                continue;
            }
            foreach (['eventReference', 'occurrenceId'] as $key) {
                $reference = trim((string) ($event[$key] ?? ''));
                if ($reference !== '') {
                    $identities[$seriesId . '|' . $reference] = [
                        'originalStart'      => $originalStart,
                        'canUpdateFollowing' => (bool) ($event['canUpdateFollowing'] ?? false)
                    ];
                }
            }
            $uid = trim((string) ($event['uid'] ?? ''));
            if ($uid !== '' && CalendarEventLookup::supportsOccurrenceReadback($event)) {
                $identities[$seriesId . '|microsoft-uid:' . $uid] = [
                    'originalStart'      => $originalStart,
                    'canUpdateFollowing' => (bool) ($event['canUpdateFollowing'] ?? false)
                ];
            }
        }

        return $identities;
    }

    /**
     * @param array<string, mixed> $change
     * @param array<string, array{originalStart:string, canUpdateFollowing:bool}> $cachedIdentities
     * @return array<string, mixed>
     */
    private function restoreMissingOccurrenceIdentity(array $change, array $cachedIdentities): array
    {
        if ((bool) ($change['_syncDeleted'] ?? false)
            || !CalendarEventRecurrence::isOccurrence($change)
            || trim((string) ($change['originalStart'] ?? '')) !== '') {
            return $change;
        }

        $seriesId = trim((string) ($change['seriesId'] ?? ''));
        if ($seriesId === '') {
            return $change;
        }
        foreach (['eventReference', 'occurrenceId'] as $key) {
            $reference = trim((string) ($change[$key] ?? ''));
            $identity = $reference !== '' ? ($cachedIdentities[$seriesId . '|' . $reference] ?? null) : null;
            if ($identity === null) {
                continue;
            }
            $change['originalStart'] = $identity['originalStart'];
            if ($identity['canUpdateFollowing']) {
                $change['canUpdateFollowing'] = true;
            }
            break;
        }
        if (trim((string) ($change['originalStart'] ?? '')) === '' && CalendarEventLookup::supportsOccurrenceReadback($change)) {
            $uid = trim((string) ($change['uid'] ?? ''));
            $identity = $uid !== '' ? ($cachedIdentities[$seriesId . '|microsoft-uid:' . $uid] ?? null) : null;
            if ($identity !== null) {
                $change['originalStart'] = $identity['originalStart'];
                $change['canUpdateFollowing'] = $identity['canUpdateFollowing'];
            }
        }

        return $change;
    }

    private function finishEventTransfer(string $token): void
    {
        try {
            $this->sendRequest('FinishEventsTransfer', ['Token' => $token]);
        } catch (Throwable $exception) {
            $this->SendSafeDebugException('EventTransferCleanupError', $exception);
        }
    }

    /**
     * @param array<string, mixed> $additionalData
     * @return array<mixed>
     */
    private function sendRequest(string $operation, array $additionalData = []): array
    {
        if (!$this->isRuntimeReady()) {
            throw new RuntimeException('The calendar instance is still initializing.');
        }
        if ($this->ReadPropertyBoolean('LocalCalendar')) {
            $error = $this->validateConfiguration();
            if ($error !== '') {
                throw new RuntimeException($error);
            }
            return $this->sendLocalRequest($operation, $additionalData);
        }
        if (!$this->HasActiveParent()) {
            throw new RuntimeException('No active calendar account is connected.');
        }

        $request = array_merge(
            [
                'Operation'  => $operation,
                'RequestID'  => bin2hex(random_bytes(8)),
                'CalendarID' => $this->effectiveCalendarId()
            ],
            $additionalData
        );
        $responseJson = $this->SendDataToParent(
            $this->EncodeDataFlowMessage(self::DATA_ID_TO_PARENT, $request)
        );
        if ($responseJson === '') {
            throw new RuntimeException('The calendar account did not return a response.');
        }

        $response = json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($response) || !($response['Success'] ?? false)) {
            $error = is_array($response) ? trim((string) ($response['Error'] ?? '')) : '';
            if ($error === '') {
                $error = 'The calendar account rejected the request.';
            }
            $errorType = is_array($response)
                ? CalendarProviderError::normalizeType((string) ($response['ErrorType'] ?? ''))
                : '';
            if ($errorType === '') {
                $errorType = CalendarProviderError::classifyMessage($error);
            }
            throw new CalendarProviderErrorException($error, $errorType);
        }
        $payload = $response['Payload'] ?? null;
        if (!is_array($payload)) {
            throw new UnexpectedValueException('The calendar account returned invalid data.');
        }

        return $payload;
    }

    /**
     * Executes one local operation against durable originals under an instance lock.
     *
     * A provider mutates its private working copy. Only a fully successful operation
     * publishes that copy, including multi-resource series splits. Cache refreshes
     * and transfer buffers never replace these original objects.
     *
     * @param array<string, mixed> $request
     * @return array<mixed>
     */
    private function sendLocalRequest(string $operation, array $request): array
    {
        $lock = 'OpenCalendar.LocalCalendar.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 5000)) {
            throw new RuntimeException('The local calendar is busy. Please try again.');
        }

        try {
            $resources = json_decode($this->ReadAttributeString('LocalCalendarResources'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($resources) || ($resources !== [] && array_is_list($resources))) {
                throw new UnexpectedValueException('The local calendar original data is invalid. Restore a backup; do not clear it.');
            }
            $reference = $this->effectiveCalendarId();
            $provider = new LocalCalendarProvider($resources, $reference);
            $identity = [
                'eventReference' => trim((string) ($request['EventReference'] ?? '')),
                'resourceUrl'    => trim((string) ($request['ResourceURL'] ?? '')),
                'uid'            => trim((string) ($request['UID'] ?? '')),
                'seriesId'       => trim((string) ($request['SeriesID'] ?? '')),
                'occurrenceId'   => trim((string) ($request['OccurrenceID'] ?? '')),
                'originalStart'  => trim((string) ($request['OriginalStart'] ?? '')),
                'recurrenceId'   => trim((string) ($request['RecurrenceID'] ?? '')),
                'startTimestamp' => (int) ($request['Start'] ?? 0),
                'endTimestamp'   => (int) ($request['End'] ?? 0)
            ];
            $result = match ($operation) {
                'GetCalendars'        => $provider->getCalendars(),
                'BeginEventsTransfer' => $this->CreateChunkedJsonTransfer(
                    self::LOCAL_EVENT_TRANSFER_SCOPE,
                    $provider->getEventsWithOverdueTasks(
                        $reference,
                        new DateTimeImmutable('@' . (int) ($request['Start'] ?? 0)),
                        new DateTimeImmutable('@' . (int) ($request['End'] ?? 0)),
                        new DateTimeImmutable('today')
                    )
                ),
                'ReadEventsTransferPage' => $this->ReadChunkedJsonTransferPage(
                    self::LOCAL_EVENT_TRANSFER_SCOPE,
                    (string) ($request['Token'] ?? ''),
                    (int) ($request['Page'] ?? -1)
                ),
                'FinishEventsTransfer' => ['success' => $this->ClearChunkedJsonTransfer(
                    self::LOCAL_EVENT_TRANSFER_SCOPE,
                    (string) ($request['Token'] ?? '')
                )],
                'GetEventForEdit', 'GetEventAfterWrite' => $provider->getEventForEdit($reference, $identity),
                'CheckPendingTask'                      => $this->checkLocalPendingTask($provider, $reference, $identity),
                'CheckRecurringSeries'                  => $this->checkLocalRecurringSeries($provider, $reference, $identity),
                'GetRecurringSeries'                    => $provider->getRecurringSeries($reference, $identity['seriesId'], $identity['resourceUrl']),
                'GetRecurringFollowing'                 => $provider->getRecurringFollowing(
                    $reference,
                    $identity['seriesId'],
                    $identity['occurrenceId'],
                    $identity['originalStart'],
                    $identity['resourceUrl']
                ),
                'CreateEvent' => $provider->createEvent($reference, $request['Event'] ?? []),
                'UpdateEvent' => $provider->updateEvent(
                    $reference,
                    $identity['resourceUrl'],
                    (string) ($request['ETag'] ?? ''),
                    $identity['uid'],
                    $request['Event'] ?? [],
                    $request['Recurrence'] ?? []
                ),
                'DeleteEvent' => ['success' => $provider->deleteEvent(
                    $reference,
                    $identity['resourceUrl'],
                    (string) ($request['ETag'] ?? ''),
                    $identity['recurrenceId'],
                    $request['Recurrence'] ?? []
                )],
                default => throw new InvalidArgumentException('Unsupported local calendar operation: ' . $operation)
            };

            if ($operation === 'GetCalendars' && trim($this->ReadPropertyString('CalendarColor')) !== '') {
                foreach ($result as &$calendar) {
                    $calendar['color'] = trim($this->ReadPropertyString('CalendarColor'));
                }
                unset($calendar);
            }
            $updatedResources = $provider->exportResources();
            if ($updatedResources !== $resources) {
                $encoded = json_encode((object) $updatedResources, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!$this->WriteAttributeString('LocalCalendarResources', $encoded)) {
                    throw new RuntimeException('The local calendar original data could not be saved.');
                }
            }
            return $result;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{known: bool, event: ?array<string, mixed>}
     */
    private function checkLocalPendingTask(LocalCalendarProvider $provider, string $reference, array $identity): array
    {
        if ($identity['eventReference'] === '' && $identity['resourceUrl'] === '' && $identity['uid'] === '') {
            return ['known' => false, 'event' => null];
        }
        $identity['startTimestamp'] = 0;
        $identity['endTimestamp'] = 0;
        try {
            $event = $provider->getEventForEdit($reference, $identity);
        } catch (Throwable $exception) {
            if (in_array(CalendarProviderError::fromThrowable($exception)['httpStatus'], [404, 410], true)) {
                return ['known' => true, 'event' => null];
            }
            throw $exception;
        }
        if ((bool) ($event['recurring'] ?? false)
            || trim((string) ($event['seriesId'] ?? '')) !== ''
            || trim((string) ($event['recurrenceId'] ?? '')) !== ''
            || ($identity['uid'] !== '' && !hash_equals($identity['uid'], (string) ($event['uid'] ?? '')))) {
            return ['known' => false, 'event' => null];
        }
        return ['known' => true, 'event' => $event];
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{supported: bool, exists: bool}
     */
    private function checkLocalRecurringSeries(LocalCalendarProvider $provider, string $reference, array $identity): array
    {
        try {
            $provider->getRecurringSeries($reference, $identity['seriesId'], $identity['resourceUrl']);
        } catch (Throwable $exception) {
            if (in_array(CalendarProviderError::fromThrowable($exception)['httpStatus'], [404, 410], true)) {
                return ['supported' => true, 'exists' => false];
            }
            throw $exception;
        }
        return ['supported' => true, 'exists' => true];
    }

    private function scheduleInitialization(): void
    {
        if (IPS_GetKernelRunlevel() === KR_READY && $this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('InitializationTimer', self::INITIALIZATION_DELAY_MS);
        }
    }

    private function isRuntimeReady(): bool
    {
        return IPS_GetKernelRunlevel() === KR_READY
            && $this->ReadAttributeBoolean('RuntimeReady');
    }

    private function effectiveCalendarId(): string
    {
        if ($this->ReadPropertyBoolean('LocalCalendar')) {
            return 'https://opencalendar.invalid/local/' . $this->InstanceID . '/';
        }
        $calendarId = trim($this->ReadPropertyString('CalendarID'));
        return $calendarId !== ''
            ? $calendarId
            : trim($this->ReadAttributeString('ResolvedCalendarID'));
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function storeEvents(array $events): void
    {
        $timestamp = time();
        $events = CalendarEventState::filterVisibleEvents($events);
        $events = $this->enrichAnniversaryEvents($this->enrichTaskEvents($events));
        $this->WritePersistentJsonCache('CachedEvents', $events);
        $this->WriteAttributeInteger('LastSynchronization', $timestamp);
        $this->updateEventCounters($events);
        $this->SetValue('LastSynchronization', $timestamp);
    }

    /** @param list<array<string, mixed>> $events */
    private function updateEventCounters(array $events): void
    {
        $this->SetValue('EventCount', count($events));
        $this->SetValue(
            'TodayEventCount',
            CalendarEventCounter::countForDay($events, new DateTimeImmutable('today'))
        );
    }

    private function scheduleTodayEventCountRefresh(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $now = new DateTimeImmutable();
        $nextDay = $now->modify('tomorrow')->setTime(0, 0, 1);
        $this->SetTimerInterval(
            'DayChangeTimer',
            max(1_000, ($nextDay->getTimestamp() - $now->getTimestamp()) * 1_000)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEvents(): array
    {
        try {
            $events = $this->ReadPersistentJsonCache('CachedEvents');
            $events = $this->enrichAnniversaryEvents($this->enrichTaskEvents($events));
            return CalendarEventState::filterVisibleEvents($events);
        } catch (UnexpectedValueException) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readMicrosoftTasks(): array
    {
        try {
            $tasks = $this->ReadPersistentJsonCache('CachedMicrosoftTasks');
            return array_values(array_filter($tasks, 'is_array'));
        } catch (UnexpectedValueException) {
            return [];
        }
    }

    /**
     * @return list<array{keys: list<string>, type: string, date: string, summary: string}>
     */
    private function readAnniversaryMetadata(): array
    {
        $decoded = $this->decodeAnniversaryMetadata($this->ReadAttributeString('AnniversaryMetadata'));
        if ($decoded !== []) {
            return $decoded;
        }

        return $this->decodeAnniversaryMetadata($this->ReadAttributeString('BirthdayMetadata'), true);
    }

    /**
     * @return list<array{keys: list<string>, type: string, date: string, summary: string}>
     */
    private function decodeAnniversaryMetadata(string $json, bool $legacyBirthday = false): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !array_is_list($entry['keys'] ?? [])) {
                continue;
            }
            $type = $legacyBirthday
                ? self::ANNIVERSARY_TYPE_BIRTHDAY
                : $this->normalizeAnniversaryType((string) ($entry['type'] ?? ''));
            $date = $this->normalizeAnniversaryDate((string) ($entry['date'] ?? $entry['birthDate'] ?? ''));
            $keys = array_values(array_unique(array_filter(
                array_map(static fn (mixed $key): string => trim((string) $key), $entry['keys']),
                static fn (string $key): bool => $key !== ''
            )));
            if ($type === '' || $date === '' || $keys === []) {
                continue;
            }
            $result[] = [
                'keys'    => $keys,
                'type'    => $type,
                'date'    => $date,
                'summary' => trim((string) ($entry['summary'] ?? ''))
            ];
        }

        return $result;
    }

    /** @param list<array{keys: list<string>, type: string, date: string, summary: string}> $metadata */
    private function writeAnniversaryMetadata(array $metadata): void
    {
        $this->WriteAttributeString(
            'AnniversaryMetadata',
            json_encode(
                array_values($metadata),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
        if ($this->ReadAttributeString('BirthdayMetadata') !== '[]') {
            $this->WriteAttributeString('BirthdayMetadata', '[]');
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return list<string>
     */
    private function anniversaryEventKeys(array $event): array
    {
        $keys = [];
        $seriesId = trim((string) ($event['seriesId'] ?? ''));
        $eventReference = trim((string) ($event['eventReference'] ?? ''));
        if ($seriesId !== '') {
            $keys[] = 'id:' . $seriesId;
        } elseif ($eventReference !== '') {
            $keys[] = 'id:' . $eventReference;
        }

        $uid = trim((string) ($event['uid'] ?? ''));
        if ($uid !== '') {
            $keys[] = 'uid:' . $uid;
        }
        $resourceUrl = trim((string) ($event['resourceUrl'] ?? ''));
        if ($resourceUrl !== '') {
            $keys[] = 'resource:' . $resourceUrl;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, mixed> $event
     * @param list<array{keys: list<string>, type: string, date: string, summary: string}> $metadata
     */
    private function anniversaryMetadataIndex(array $event, array $metadata): ?int
    {
        $keys = $this->anniversaryEventKeys($event);
        if ($keys === []) {
            return null;
        }
        foreach ($metadata as $index => $entry) {
            if (array_intersect($keys, $entry['keys']) !== []) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $event
     * @return array{keys: list<string>, type: string, date: string, summary: string}|null
     */
    private function anniversaryMetadataForEvent(array $event): ?array
    {
        $metadata = $this->readAnniversaryMetadata();
        $index = $this->anniversaryMetadataIndex($event, $metadata);
        return $index === null ? null : $metadata[$index];
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $sourceEvent
     */
    private function upsertAnniversaryMetadata(
        array $event,
        string $type,
        string $date,
        string $summary,
        array $sourceEvent = []
    ): void {
        $type = $this->normalizeAnniversaryType($type);
        $date = $this->normalizeAnniversaryDate($date);
        if ($type === '' || $date === '') {
            throw new InvalidArgumentException('The annual-event metadata is invalid.');
        }
        $metadata = $this->readAnniversaryMetadata();
        $index = $sourceEvent !== [] ? $this->anniversaryMetadataIndex($sourceEvent, $metadata) : null;
        if ($index === null) {
            $index = $this->anniversaryMetadataIndex($event, $metadata);
        }
        $keys = $this->anniversaryEventKeys($event);
        if ($sourceEvent !== []) {
            $keys = array_merge($keys, $this->anniversaryEventKeys($sourceEvent));
        }
        if ($index === null) {
            if ($keys === []) {
                throw new InvalidArgumentException('The annual-event identity is incomplete.');
            }
            $metadata[] = [
                'keys'    => array_values(array_unique($keys)),
                'type'    => $type,
                'date'    => $date,
                'summary' => trim($summary)
            ];
        } else {
            $metadata[$index]['keys'] = array_values(array_unique(array_merge($metadata[$index]['keys'], $keys)));
            $metadata[$index]['type'] = $type;
            $metadata[$index]['date'] = $date;
            if (trim($summary) !== '') {
                $metadata[$index]['summary'] = trim($summary);
            }
        }
        $this->writeAnniversaryMetadata($metadata);
    }

    /**
     * Removes local annual-event metadata only after the provider has explicitly
     * confirmed that a recurring parent event no longer exists.
     *
     * Missing cached occurrences alone are never treated as a deletion. This is
     * important for excluded occurrences, short synchronization windows, and
     * leap-day annual events.
     *
     * @param list<array<string, mixed>> $events Current synchronized event cache.
     * @param list<array<string, mixed>> $previousEvents Event cache before synchronization.
     */
    private function reconcileAnniversaryMetadataAfterSynchronization(
        array $events,
        array $previousEvents
    ): void {
        $metadata = $this->readAnniversaryMetadata();
        if ($metadata === []) {
            return;
        }

        $dailyVerification = $this->shouldVerifyMissingAnniversaryMetadataToday();
        $retained = [];
        $removed = 0;
        foreach ($metadata as $entry) {
            if ($this->anniversaryMetadataMatchesEvents($entry, $events)) {
                $retained[] = $entry;
                continue;
            }

            $wasCached = $this->anniversaryMetadataMatchesEvents($entry, $previousEvents);
            if (!$wasCached && !$dailyVerification) {
                $retained[] = $entry;
                continue;
            }

            $candidates = $this->anniversaryVerificationCandidates($entry);
            if ($candidates === [] || $this->verifyAnniversarySeriesCandidates($candidates) !== false) {
                $retained[] = $entry;
                continue;
            }

            ++$removed;
        }

        if ($removed === 0) {
            return;
        }

        $this->writeAnniversaryMetadata($retained);
        $this->SendSafeDebug('AnniversaryMetadataCleanup', [
            'removed'   => $removed,
            'remaining' => count($retained)
        ]);
    }

    /**
     * @param array{keys: list<string>, type: string, date: string, summary: string} $metadata
     * @param list<array<string, mixed>> $events
     */
    private function anniversaryMetadataMatchesEvents(array $metadata, array $events): bool
    {
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (array_intersect($metadata['keys'], $this->anniversaryEventKeys($event)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{keys: list<string>, type: string, date: string, summary: string} $metadata
     * @return list<array{seriesId: string, resourceUrl: string}>
     */
    private function anniversaryVerificationCandidates(array $metadata): array
    {
        $seriesIds = [];
        $resourceUrls = [];
        foreach ($metadata['keys'] as $key) {
            if (str_starts_with($key, 'id:')) {
                $seriesId = trim(substr($key, 3));
                if ($seriesId !== '') {
                    $seriesIds[] = $seriesId;
                }
            } elseif (str_starts_with($key, 'resource:')) {
                $resourceUrl = trim(substr($key, 9));
                if ($resourceUrl !== '') {
                    $resourceUrls[] = $resourceUrl;
                }
            }
        }

        $seriesIds = array_values(array_unique($seriesIds));
        $resourceUrls = array_values(array_unique($resourceUrls));
        if ($seriesIds === []) {
            return [];
        }
        if ($resourceUrls === []) {
            $resourceUrls = [''];
        }

        $candidates = [];
        foreach ($seriesIds as $seriesId) {
            foreach ($resourceUrls as $resourceUrl) {
                $candidates[] = [
                    'seriesId'    => $seriesId,
                    'resourceUrl' => $resourceUrl
                ];
                if (count($candidates) >= 16) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param list<array{seriesId: string, resourceUrl: string}> $candidates
     * @return bool|null True when a parent exists, false when every candidate is confirmed missing, null when unknown.
     */
    private function verifyAnniversarySeriesCandidates(array $candidates): ?bool
    {
        $confirmedMissing = false;
        $unknown = false;
        foreach ($candidates as $candidate) {
            try {
                $verification = $this->sendRequest('CheckRecurringSeries', [
                    'SeriesID'    => $candidate['seriesId'],
                    'ResourceURL' => $candidate['resourceUrl']
                ]);
            } catch (Throwable $exception) {
                $this->SendSafeDebugException('AnniversaryMetadataVerificationError', $exception);
                $unknown = true;
                continue;
            }

            if (($verification['supported'] ?? false) !== true) {
                $unknown = true;
                continue;
            }
            if (($verification['exists'] ?? null) === true) {
                return true;
            }
            if (($verification['exists'] ?? null) !== false) {
                $unknown = true;
                continue;
            }
            $confirmedMissing = true;
        }

        return !$unknown && $confirmedMissing ? false : null;
    }

    private function shouldVerifyMissingAnniversaryMetadataToday(): bool
    {
        $lastSynchronization = $this->ReadAttributeInteger('LastSynchronization');
        if ($lastSynchronization <= 0) {
            return true;
        }

        $timezone = new DateTimeZone(date_default_timezone_get());
        $lastDate = (new DateTimeImmutable('@' . $lastSynchronization))
            ->setTimezone($timezone)
            ->format('Y-m-d');

        return $lastDate !== (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    }

    /** @param array<string, mixed> $event */
    private function removeAnniversaryMetadata(array $event): void
    {
        $metadata = $this->readAnniversaryMetadata();
        $index = $this->anniversaryMetadataIndex($event, $metadata);
        if ($index === null) {
            return;
        }
        unset($metadata[$index]);
        $this->writeAnniversaryMetadata(array_values($metadata));
    }

    /**
     * @param array<string, mixed> $event
     * @return array{enabled: bool, type: string, date: string}|null
     */
    private function anniversaryInput(array $event): ?array
    {
        if (array_key_exists('anniversaryType', $event) || array_key_exists('anniversaryDate', $event)) {
            $type = $this->normalizeAnniversaryType((string) ($event['anniversaryType'] ?? ''), true);
            if ($type === '') {
                return ['enabled' => false, 'type' => '', 'date' => ''];
            }
            $date = $this->normalizeAnniversaryDate((string) ($event['anniversaryDate'] ?? ''));
            if ($date === '' || $date > date('Y-m-d')) {
                throw new InvalidArgumentException('The annual-event date is invalid.');
            }

            return ['enabled' => true, 'type' => $type, 'date' => $date];
        }

        if (!array_key_exists('birthday', $event)) {
            return null;
        }
        if (!is_bool($event['birthday'])) {
            throw new InvalidArgumentException('The birthday flag must be boolean.');
        }
        if (!$event['birthday']) {
            return ['enabled' => false, 'type' => '', 'date' => ''];
        }
        $date = $this->normalizeAnniversaryDate((string) ($event['birthDate'] ?? ''));
        if ($date === '' || $date > date('Y-m-d')) {
            throw new InvalidArgumentException('The birth date is invalid.');
        }

        return ['enabled' => true, 'type' => self::ANNIVERSARY_TYPE_BIRTHDAY, 'date' => $date];
    }

    /** @param array<string, mixed> $event */
    private function assertAnniversaryRecurrence(array $event): void
    {
        $recurrence = $event['recurrence'] ?? null;
        if (!is_array($recurrence)
            || strtoupper(trim((string) ($recurrence['frequency'] ?? ''))) !== 'YEARLY'
            || max(1, (int) ($recurrence['interval'] ?? 1)) !== 1
            || !(bool) ($event['allDay'] ?? false)) {
            throw new InvalidArgumentException('Annual events must be all-day yearly recurring events.');
        }
    }

    /** @param array<string, mixed> $event */
    private function applyAnniversaryEventDefaults(array &$event, string $date): void
    {
        $date = $this->normalizeAnniversaryDate($date);
        if ($date === '') {
            throw new InvalidArgumentException('The annual-event date is invalid.');
        }
        $start = new DateTimeImmutable($date . ' 00:00:00');
        $event['allDay'] = true;
        $event['start'] = $date;
        $event['end'] = $start->modify('+1 day')->format('Y-m-d');
        $event['recurrence'] = [
            'frequency' => 'YEARLY',
            'interval'  => 1,
            'endMode'   => 'never'
        ];
    }

    private function normalizeAnniversaryType(string $type, bool $allowEmpty = false): string
    {
        $type = strtolower(trim($type));
        if ($type === '' && $allowEmpty) {
            return '';
        }
        if (!in_array($type, self::ANNIVERSARY_TYPES, true)) {
            if ($allowEmpty) {
                throw new InvalidArgumentException('The annual-event type is invalid.');
            }
            return '';
        }

        return $type;
    }

    private function normalizeAnniversaryDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return '';
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return '';
        }

        return $date;
    }

    private function nextAnniversaryDate(string $date, DateTimeImmutable $today): DateTimeImmutable
    {
        $month = (int) substr($date, 5, 2);
        $day = (int) substr($date, 8, 2);
        $year = (int) $today->format('Y');
        for ($offset = 0; $offset <= 8; ++$offset) {
            $candidateText = sprintf('%04d-%02d-%02d', $year + $offset, $month, $day);
            $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', $candidateText, $today->getTimezone());
            if ($candidate === false || $candidate->format('Y-m-d') !== $candidateText) {
                continue;
            }
            if ($candidate >= $today) {
                return $candidate;
            }
        }

        throw new RuntimeException('The next annual-event date could not be calculated.');
    }

    /** @param array<string, mixed> $event */
    private function enrichAnniversaryEvent(array $event): array
    {
        $metadata = $this->anniversaryMetadataForEvent($event);
        if ($metadata === null) {
            return $event;
        }

        return $this->applyAnniversaryPresentation($event, $metadata);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function enrichAnniversaryEvents(array $events): array
    {
        $metadata = $this->readAnniversaryMetadata();
        if ($metadata === []) {
            return $events;
        }
        $changed = false;
        foreach ($events as &$event) {
            if (!is_array($event)) {
                continue;
            }
            $index = $this->anniversaryMetadataIndex($event, $metadata);
            if ($index === null) {
                continue;
            }
            $event = $this->applyAnniversaryPresentation($event, $metadata[$index]);
            $summary = trim((string) ($event['summary'] ?? ''));
            if ($summary !== '' && $summary !== $metadata[$index]['summary']) {
                $metadata[$index]['summary'] = $summary;
                $changed = true;
            }
        }
        unset($event);
        if ($changed) {
            $this->writeAnniversaryMetadata($metadata);
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $event
     * @param array{keys: list<string>, type: string, date: string, summary: string} $metadata
     * @return array<string, mixed>
     */
    private function applyAnniversaryPresentation(array $event, array $metadata): array
    {
        $date = $metadata['date'];
        $startYear = (int) substr($date, 0, 4);
        $occurrenceDate = trim((string) ($event['originalStart'] ?? $event['start'] ?? ''));
        $occurrenceYear = preg_match('/^\d{4}/', $occurrenceDate, $matches) === 1
            ? (int) $matches[0]
            : (int) date('Y');
        $years = max(0, $occurrenceYear - $startYear);
        $summary = trim((string) ($event['summary'] ?? $metadata['summary']));

        $event['anniversaryType'] = $metadata['type'];
        $event['anniversaryDate'] = $date;
        $event['years'] = $years;
        $event['displaySummary'] = $summary !== '' ? sprintf('%s (%dJ)', $summary, $years) : sprintf('(%dJ)', $years);
        unset($event['birthday'], $event['birthDate'], $event['age']);
        if ($metadata['type'] === self::ANNIVERSARY_TYPE_BIRTHDAY) {
            $event['birthday'] = true;
            $event['birthDate'] = $date;
            $event['age'] = $years;
        }

        return $event;
    }

    /**
     * Resolves recurrence capabilities from the synchronized event cache whenever possible.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function resolveWriteRecurrence(array $event, bool $updating): array
    {
        $resourceUrl = trim((string) ($event['resourceUrl'] ?? ''));
        $identity = CalendarEventRecurrence::fromEvent($event);
        $writeScope = (string) ($identity['writeScope'] ?? '');

        // A synchronized CalDAV cache contains expanded occurrences, not the recurring
        // master. All occurrences of one series share the same resource URL. Therefore
        // a resource-URL cache match must never be used to validate a whole-series
        // write: it would turn the verified master back into an occurrence. Verify the
        // master directly with the provider before considering cached occurrence data.
        if ($writeScope === CalendarEventRecurrence::WRITE_SCOPE_SERIES) {
            $seriesId = trim((string) ($identity['seriesId'] ?? ''));
            $capabilityAvailable = $updating
                ? $this->ReadAttributeBoolean('DetectedCanUpdateSeries')
                : $this->ReadAttributeBoolean('DetectedCanDeleteSeries');
            if ($seriesId === '' || !$capabilityAvailable) {
                throw new InvalidArgumentException('The recurring series cannot be modified by this calendar.');
            }

            $verifiedSeries = $this->sendRequest(
                'GetRecurringSeries',
                [
                    'SeriesID'    => $seriesId,
                    'ResourceURL' => $resourceUrl
                ]
            );
            $verifiedIdentity = CalendarEventRecurrence::fromEvent($verifiedSeries);
            $capability = $updating ? 'canUpdateSeries' : 'canDeleteSeries';
            if (($verifiedIdentity['recurrenceType'] ?? '') !== CalendarEventRecurrence::MASTER
                || !hash_equals($seriesId, (string) ($verifiedIdentity['seriesId'] ?? ''))
                || !(bool) ($verifiedIdentity[$capability] ?? false)) {
                throw new InvalidArgumentException('The recurring series could not be verified for this calendar.');
            }

            $verifiedIdentity['writeScope'] = CalendarEventRecurrence::WRITE_SCOPE_SERIES;
            return CalendarEventRecurrence::fromEvent($verifiedIdentity);
        }

        $occurrenceId = trim((string) ($event['occurrenceId'] ?? ''));
        foreach ($this->readEvents() as $cachedEvent) {
            $cachedResourceUrl = trim((string) ($cachedEvent['resourceUrl'] ?? ''));
            $cachedOccurrenceId = trim((string) ($cachedEvent['occurrenceId'] ?? ''));
            $matchesOccurrence = $occurrenceId !== ''
                && $cachedOccurrenceId !== ''
                && hash_equals($cachedOccurrenceId, $occurrenceId);
            $matchesResource = $occurrenceId === ''
                && $resourceUrl !== ''
                && hash_equals($cachedResourceUrl, $resourceUrl);
            if ($matchesOccurrence || $matchesResource) {
                $cachedEvent['writeScope'] = (string) ($event['writeScope'] ?? '');
                if (trim((string) ($cachedEvent['originalStart'] ?? '')) === ''
                    && trim((string) ($event['originalStart'] ?? '')) !== ''
                    && CalendarEventRecurrence::isOccurrence($cachedEvent)) {
                    $cachedEvent['originalStart'] = trim((string) $event['originalStart']);
                }
                if ((bool) ($cachedEvent['recurring'] ?? false)
                    && trim((string) ($cachedEvent['occurrenceId'] ?? '')) !== ''
                    && trim((string) ($cachedEvent['seriesId'] ?? '')) !== '') {
                    if ($this->ReadAttributeBoolean('DetectedCanUpdateOccurrence')) {
                        $cachedEvent['canUpdateOccurrence'] = true;
                    }
                    if ($this->ReadAttributeBoolean('DetectedCanDeleteOccurrence')) {
                        $cachedEvent['canDeleteOccurrence'] = true;
                    }
                }
                if ($this->ReadAttributeBoolean('DetectedCanUpdateFollowing')
                    && (bool) ($cachedEvent['recurring'] ?? false)
                    && trim((string) ($cachedEvent['occurrenceId'] ?? '')) !== ''
                    && trim((string) ($cachedEvent['seriesId'] ?? '')) !== '') {
                    $cachedEvent['canUpdateFollowing'] = true;
                }
                if ($this->ReadAttributeBoolean('DetectedCanUpdateSeries')
                    && (bool) ($cachedEvent['recurring'] ?? false)
                    && trim((string) ($cachedEvent['seriesId'] ?? '')) !== '') {
                    $cachedEvent['canUpdateSeries'] = true;
                }
                if ($this->ReadAttributeBoolean('DetectedCanDeleteSeries')
                    && (bool) ($cachedEvent['recurring'] ?? false)
                    && trim((string) ($cachedEvent['seriesId'] ?? '')) !== '') {
                    $cachedEvent['canDeleteOccurrence'] = true;
                    $cachedEvent['canDeleteSeries'] = true;
                }
                return CalendarEventRecurrence::fromEvent($cachedEvent);
            }
        }

        if ($writeScope === CalendarEventRecurrence::WRITE_SCOPE_OCCURRENCE
            && CalendarEventRecurrence::isOccurrence($identity)) {
            if ($this->ReadAttributeBoolean('DetectedCanUpdateOccurrence')) {
                $identity['canUpdateOccurrence'] = true;
            }
            if ($this->ReadAttributeBoolean('DetectedCanDeleteOccurrence')) {
                $identity['canDeleteOccurrence'] = true;
            }
            return CalendarEventRecurrence::fromEvent($identity);
        }
        if ($writeScope === CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING) {
            if (!$this->ReadAttributeBoolean('DetectedCanUpdateFollowing')
                || (!$updating && !$this->ReadAttributeBoolean('DetectedCanDeleteSeries'))
                || !CalendarEventRecurrence::isOccurrence($identity)
                || trim((string) ($identity['seriesId'] ?? '')) === ''
                || trim((string) ($identity['occurrenceId'] ?? '')) === ''
                || trim((string) ($identity['originalStart'] ?? '')) === '') {
                throw new InvalidArgumentException('The recurring event cannot be split by this calendar.');
            }
            $identity['canUpdateFollowing'] = true;
            if (!$updating) {
                $identity['canDeleteSeries'] = true;
            }
            return CalendarEventRecurrence::fromEvent($identity);
        }
        return $identity;
    }

    private function removeLegacyEventsVariable(): void
    {
        if ($this->VariableExists('Events')) {
            $this->UnregisterVariable('Events');
        }
    }

    /**
     * Refreshes a written event through the provider-neutral direct lookup and updates the local cache when possible.
     *
     * @param array<string, mixed> $event Event identity and current time boundaries after the write.
     * @param array<string, mixed> $sourceEvent Previous event identity when an existing event was updated.
     */
    private function refreshEventAfterWrite(array $event, array $sourceEvent = []): bool
    {
        $startTimestamp = $this->eventBoundaryTimestamp($event, 'start');
        $endTimestamp = $this->eventBoundaryTimestamp($event, 'end');
        if ($startTimestamp > 0 && $endTimestamp <= $startTimestamp) {
            $endTimestamp = $startTimestamp + 1;
        }

        $currentEvent = null;
        $lookupIdentity = [
            'ResourceURL'    => trim((string) ($event['resourceUrl'] ?? '')),
            'EventReference' => trim((string) ($event['eventReference'] ?? '')),
            'UID'            => trim((string) ($event['uid'] ?? '')),
            'SeriesID'       => trim((string) ($event['seriesId'] ?? '')),
            'OccurrenceID'   => trim((string) ($event['occurrenceId'] ?? '')),
            'OriginalStart'  => trim((string) ($event['originalStart'] ?? '')),
            'RecurrenceID'   => trim((string) ($event['recurrenceId'] ?? '')),
            'Start'          => $startTimestamp,
            'End'            => $endTimestamp
        ];
        $hasLookupIdentity = $lookupIdentity['EventReference'] !== ''
            || $lookupIdentity['ResourceURL'] !== ''
            || $lookupIdentity['UID'] !== ''
            || $lookupIdentity['SeriesID'] !== ''
            || $lookupIdentity['OccurrenceID'] !== '';
        if (!$hasLookupIdentity && $startTimestamp <= 0) {
            return false;
        }

        if ($hasLookupIdentity) {
            try {
                $currentEvent = $this->sendRequest('GetEventAfterWrite', $lookupIdentity);
            } catch (Throwable $exception) {
                $this->SendSafeDebugException('EventDirectCacheRefreshFallback', $exception);
            }
        }

        if ($currentEvent === null) {
            try {
                $currentEvent = $this->sendRequest('GetEventForEdit', $lookupIdentity);
            } catch (Throwable $exception) {
                $this->SendSafeDebugException('EventCacheRefreshFallback', $exception);
                return false;
            }
        }

        $occurrenceReadback = CalendarEventLookup::resolveOccurrenceReadback(
            $sourceEvent,
            $currentEvent,
            $lookupIdentity['EventReference']
        );
        if ($occurrenceReadback === null && ((bool) ($currentEvent['recurring'] ?? false)
            || (string) ($currentEvent['recurrenceType'] ?? CalendarEventRecurrence::SINGLE)
                !== CalendarEventRecurrence::SINGLE)) {
            return false;
        }
        $currentEvent = $occurrenceReadback ?? $currentEvent;

        $previousIdentity = $sourceEvent !== [] ? $sourceEvent : $event;
        $events = array_values(array_filter(
            $this->readEvents(),
            fn (array $cachedEvent): bool => !$this->eventIdentityMatches($cachedEvent, $previousIdentity)
                && !$this->eventIdentityMatches($cachedEvent, $currentEvent)
                && !CalendarEventLookup::sameOccurrence($cachedEvent, $currentEvent)
        ));
        if ($this->eventOverlapsConfiguredRange($currentEvent)) {
            $events[] = $currentEvent;
        }
        $this->storeEventsAfterWrite($events);

        return true;
    }

    /** @param array<string, mixed> $identity */
    private function cachedEventForIdentity(array $identity): ?array
    {
        foreach ($this->readEvents() as $event) {
            if ($this->eventIdentityMatches($event, $identity)) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $identity
     */
    private function eventIdentityMatches(array $candidate, array $identity): bool
    {
        foreach (['resourceUrl', 'eventReference', 'uid'] as $key) {
            $expected = trim((string) ($identity[$key] ?? ''));
            $actual = trim((string) ($candidate[$key] ?? ''));
            if ($expected !== '' && $actual !== '' && hash_equals($expected, $actual)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $event */
    private function eventBoundaryTimestamp(array $event, string $key): int
    {
        $value = trim((string) ($event[$key] ?? ''));
        if ($value !== '') {
            try {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if ($date !== false && $date->format('Y-m-d') === $value) {
                        return $date->getTimestamp();
                    }
                }

                return (new DateTimeImmutable($value))->getTimestamp();
            } catch (Throwable) {
                return 0;
            }
        }

        return max(0, (int) ($event[$key . 'Timestamp'] ?? 0));
    }

    /** @param array<string, mixed> $event */
    private function eventOverlapsConfiguredRange(array $event): bool
    {
        $startTimestamp = $this->eventBoundaryTimestamp($event, 'start');
        if ($startTimestamp <= 0) {
            return false;
        }
        $endTimestamp = $this->eventBoundaryTimestamp($event, 'end');
        if ($endTimestamp <= $startTimestamp) {
            $endTimestamp = $startTimestamp + 1;
        }

        $pastDays = max(0, min(1095, $this->ReadPropertyInteger('PastDays')));
        $futureDays = max(1, min(1095, $this->ReadPropertyInteger('FutureDays')));
        $today = new DateTimeImmutable('today');
        $rangeStart = $today->modify('-' . $pastDays . ' days')->getTimestamp();
        $rangeEnd = $today->modify('+' . ($futureDays + 1) . ' days')->getTimestamp();

        return $endTimestamp >= $rangeStart && $startTimestamp < $rangeEnd;
    }

    /** @param list<array<string, mixed>> $events */
    private function storeEventsAfterWrite(array $events): void
    {
        $events = CalendarEventState::filterVisibleEvents($events);
        $events = $this->enrichAnniversaryEvents($this->enrichTaskEvents($events));
        usort(
            $events,
            static fn (array $left, array $right): int => ((int) ($left['startTimestamp'] ?? 0)
                <=> (int) ($right['startTimestamp'] ?? 0))
                ?: strcasecmp((string) ($left['summary'] ?? ''), (string) ($right['summary'] ?? ''))
        );
        $this->WritePersistentJsonCache('CachedEvents', $events);
        $this->updateEventCounters($events);
        $this->WriteAttributeString('LastError', '');
        $this->SetStatus($this->ReadPropertyBoolean('Active') ? IS_ACTIVE : IS_INACTIVE);

        // Notify Calendar View instances without changing the true synchronization timestamp.
        $this->SetValue('LastSynchronization', $this->ReadAttributeInteger('LastSynchronization'));
    }

    private function refreshAfterWrite(): void
    {
        // Preserve the existing incremental synchronization state after a successful write.
        // Creating a fresh delta baseline immediately after a provider write can miss an event
        // that is not yet visible in the provider's calendar view and permanently advance past it.
        $events = $this->requestEvents();
        $this->storeEvents($events);
        $this->WriteAttributeString('LastError', '');
        $this->SetStatus($this->ReadPropertyBoolean('Active') ? IS_ACTIVE : IS_INACTIVE);
    }

    /**
     * Rejects events that were cancelled after they were cached or selected.
     *
     * @param array<string, mixed> $event
     */
    private function assertEventAvailable(array $event): void
    {
        if (CalendarEventState::isCancelled($event['status'] ?? '')) {
            throw new RuntimeException('The selected event is no longer available.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json, string $description): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('The ' . $description . ' must be a JSON object.');
        }
        return $data;
    }

    /**
     * Builds a portable iCalendar stream from the original local resources.
     */
    private function localCalendarExportContent(): string
    {
        $lock = 'OpenCalendar.LocalCalendar.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 5000)) {
            throw new RuntimeException('The local calendar is busy. Please try again.');
        }

        try {
            $resources = $this->decodeObject(
                $this->ReadAttributeString('LocalCalendarResources'),
                'local calendar original data'
            );
            $provider = new LocalCalendarProvider($resources, $this->effectiveCalendarId());
            $calendars = array_values($provider->exportResources());
            if ($calendars === []) {
                return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//OpenCalendar//Symcon//EN\r\nCALSCALE:GREGORIAN\r\nEND:VCALENDAR\r\n";
            }

            return implode("\r\n", array_map(
                static fn (string $calendar): string => rtrim($calendar) . "\r\n",
                $calendars
            ));
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Accepts a single safe file name with the mandatory ICS extension.
     */
    private function isValidLocalCalendarExportFileName(string $fileName): bool
    {
        return $fileName !== ''
            && !str_contains($fileName, '/')
            && !str_contains($fileName, '\\')
            && !preg_match('/[[:cntrl:]]/', $fileName)
            && str_ends_with(strtolower($fileName), '.ics');
    }

    private function validateConfiguration(): string
    {
        if ($this->ReadPropertyBoolean('LocalCalendar')) {
            if (trim($this->ReadPropertyString('ProviderCalendarID')) !== ''
                || trim($this->ReadPropertyString('CalendarURL')) !== '') {
                return $this->Translate('A local calendar must not use an external calendar identity.');
            }
            return '';
        }
        if (!in_array(trim($this->ReadAttributeString('LocalCalendarResources')), ['', '{}', '[]'], true)) {
            return $this->Translate('This instance contains local original events. Keep local calendar mode enabled.');
        }
        if (!SynchronizationSchedule::isValid($this->ReadPropertyInteger('UpdateSchedule'))) {
            return $this->Translate('The synchronization schedule is invalid.');
        }
        if (trim($this->ReadPropertyString('CalendarID')) === ''
            && trim($this->ReadPropertyString('ProviderCalendarID')) === ''
            && !$this->HasActiveParent()) {
            return $this->Translate('The calendar ID is missing.');
        }
        return '';
    }

    /**
     * Moves overdue open task appointments to the current local day.
     *
     * @param list<array<string, mixed>> $events
     * @param int|null $moved Receives the number of successfully moved events.
     * @return list<array<string, mixed>>
     */
    private function rollForwardTaskEvents(array $events, ?int &$moved = null): array
    {
        $moved = 0;
        if (!$this->ReadPropertyBoolean('Active')
            || !$this->isRuntimeReady()
            || !$this->calendarCanWrite()) {
            return $events;
        }

        $today = new DateTimeImmutable('today');
        $events = CalendarTaskEvent::preservePendingSeries($events, $this->readEvents(), $today);
        $blockedSeries = $this->pendingTaskSeries($events);
        foreach ($events as $event) {
            $seriesKey = $this->taskSeriesKey($event);
            if ($seriesKey !== '' && $this->isRolledForwardOpenTask($event, $today)) {
                $blockedSeries[$seriesKey] = true;
            }
        }

        $candidates = [];
        foreach ($events as $index => $event) {
            if ((bool) ($event['recurring'] ?? false)
                && !CalendarEventRecurrence::isOccurrence($event)) {
                continue;
            }
            $changes = CalendarTaskEvent::rollForwardChanges($event, $today);
            if ($changes === null) {
                continue;
            }

            $seriesKey = $this->taskSeriesKey($event);
            if ($seriesKey !== '' && isset($blockedSeries[$seriesKey])) {
                continue;
            }
            $candidates[] = [
                'index'     => $index,
                'event'     => $event,
                'changes'   => $changes,
                'seriesKey' => $seriesKey,
                'start'     => $this->eventBoundaryTimestamp($event, 'start')
            ];
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => ($left['start'] <=> $right['start'])
                ?: ($left['index'] <=> $right['index'])
        );
        $movedSeries = [];
        try {
            foreach ($candidates as $candidate) {
                $seriesKey = $candidate['seriesKey'];
                if ($seriesKey !== '' && isset($movedSeries[$seriesKey])) {
                    continue;
                }

                $index = $candidate['index'];
                $event = $candidate['event'];
                $followingMoved = false;
                $detached = false;
                $updated = $this->rollForwardTaskEvent(
                    $event,
                    $candidate['changes'],
                    $events,
                    $followingMoved,
                    $detached
                );
                if ($followingMoved) {
                    $events = CalendarTaskEvent::shiftFollowingEvents(
                        $events,
                        $event,
                        (string) $candidate['changes']['start']
                    );
                }
                $events[$index] = array_merge($event, $candidate['changes'], $updated);
                if ($detached) {
                    $events[$index]['taskRolledForward'] = true;
                }
                if ($detached && $seriesKey !== '') {
                    $this->rememberPendingTaskSeries($seriesKey, $events[$index]);
                }
                if ($seriesKey !== '') {
                    $movedSeries[$seriesKey] = true;
                }
                ++$moved;
            }
        } catch (Throwable $exception) {
            // Keep confirmed earlier writes even when another task fails later.
            if ($moved > 0) {
                $this->storeEvents($events);
            }
            throw $exception;
        }

        if ($moved > 0) {
            $this->SendSafeDebug('TaskAppointmentsRolledForward', [
                'count' => $moved,
                'date'  => $today->format('Y-m-d')
            ]);
        }

        return $events;
    }

    /**
     * Updates an overdue task occurrence and optionally shifts its remaining series.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $changes
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function rollForwardTaskEvent(
        array $event,
        array $changes,
        array $events,
        ?bool &$followingMoved = null,
        ?bool &$detached = null
    ): array {
        $followingMoved = false;
        $detached = false;
        $recurrence = CalendarEventRecurrence::fromEvent($event);
        $followPlanned = (bool) ($event['taskFollowPlanned'] ?? false)
            && CalendarEventRecurrence::isOccurrence($event)
            && (bool) ($recurrence['canUpdateFollowing'] ?? false);
        if ($followPlanned) {
            $event = $this->prepareTaskFollowingMove($event, $changes);
            $recurrence = CalendarEventRecurrence::fromEvent($event);
            $followingMoved = true;
        }

        if (!$followPlanned
            && CalendarEventRecurrence::isOccurrence($event)
            && $this->mustDetachRolledForwardTaskOccurrence($events, $event, (string) ($changes['start'] ?? ''))) {
            $detached = true;
            return $this->detachOverdueTaskOccurrence($event, $changes, $recurrence);
        }

        return $this->sendRequest('UpdateEvent', [
            'UID'         => trim((string) ($event['uid'] ?? '')),
            'ResourceURL' => trim((string) ($event['resourceUrl'] ?? '')),
            'ETag'        => trim((string) ($event['etag'] ?? '')),
            'Event'       => $changes,
            'Recurrence'  => $recurrence
        ]);
    }

    /**
     * Decides whether moving one overdue task occurrence must create a standalone task.
     *
     * Local calendars always detach the moved occurrence so it cannot be merged back into
     * its source series during the next synchronization. External providers only need this
     * safeguard when the moved date would cross a later planned occurrence.
     *
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed> $event
     */
    private function mustDetachRolledForwardTaskOccurrence(array $events, array $event, string $newStart): bool
    {
        return $this->ReadPropertyBoolean('LocalCalendar')
            || CalendarTaskEvent::requiresOccurrenceDetachment($events, $event, $newStart);
    }

    /**
     * Prepares a verified series tail for manual and automatic task date changes.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $changes Receives the reanchored recurrence settings.
     * @return array<string, mixed>
     */
    private function prepareTaskFollowingMove(array $event, array &$changes): array
    {
        $following = $this->sendRequest('GetRecurringFollowing', [
            'SeriesID'      => trim((string) ($event['seriesId'] ?? '')),
            'OccurrenceID'  => trim((string) ($event['occurrenceId'] ?? '')),
            'OriginalStart' => trim((string) ($event['originalStart'] ?? '')),
            'ResourceURL'   => trim((string) ($event['resourceUrl'] ?? ''))
        ]);
        $settings = $following['recurrenceSettings'] ?? null;
        if (!is_array($settings) || $settings === []) {
            throw new InvalidArgumentException('The recurring task could not be prepared for moving following appointments.');
        }
        $changes['recurrence'] = CalendarTaskEvent::shiftPlannedRecurrence(
            $settings,
            (string) ($following['originalStart'] ?? $event['originalStart'] ?? ''),
            (string) ($changes['start'] ?? '')
        );
        if (trim((string) ($changes['timezone'] ?? '')) === '') {
            $changes['timezone'] = (string) ($following['timezone'] ?? '');
        }
        $following['writeScope'] = CalendarEventRecurrence::WRITE_SCOPE_FOLLOWING;
        return $following;
    }

    /**
     * Continues one overdue task occurrence as a single event without changing its series.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $recurrence
     * @return array<string, mixed>
     */
    private function detachOverdueTaskOccurrence(array $event, array $changes, array $recurrence): array
    {
        $detachedEvent = [];
        foreach (['summary', 'allDay', 'timezone', 'location', 'description', 'reminder', 'status', 'transparency'] as $key) {
            if (array_key_exists($key, $event)) {
                $detachedEvent[$key] = $event[$key];
            }
        }
        $detachedEvent = array_merge($detachedEvent, $changes);
        $detachedEvent = CalendarTaskEvent::prepareWrite([
            ...$detachedEvent,
            'task'                 => true,
            'taskCompleted'        => false,
            'taskRollForwardScope' => CalendarTaskEvent::ROLL_FORWARD_SCOPE_OCCURRENCE
        ], $event);
        $created = $this->sendRequest('CreateEvent', ['Event' => $detachedEvent]);

        try {
            $deleted = $this->sendRequest('DeleteEvent', [
                'ResourceURL'  => trim((string) ($event['resourceUrl'] ?? '')),
                'ETag'         => trim((string) ($event['etag'] ?? '')),
                'RecurrenceID' => trim((string) ($recurrence['recurrenceId'] ?? '')),
                'Recurrence'   => $recurrence
            ]);
            if (!(bool) ($deleted['success'] ?? false)) {
                throw new RuntimeException('The calendar account did not confirm the task occurrence deletion.');
            }
        } catch (Throwable $exception) {
            try {
                $this->sendRequest('DeleteEvent', [
                    'ResourceURL'  => trim((string) ($created['resourceUrl'] ?? '')),
                    'ETag'         => trim((string) ($created['etag'] ?? '')),
                    'RecurrenceID' => '',
                    'Recurrence'   => CalendarEventRecurrence::single()
                ]);
            } catch (Throwable $cleanupException) {
                $this->SendSafeDebugException('DetachedTaskCleanupError', $cleanupException);
            }
            throw $exception;
        }

        $detachedEvent['startTimestamp'] = $this->eventBoundaryTimestamp($detachedEvent, 'start');
        $detachedEvent['endTimestamp'] = $this->eventBoundaryTimestamp($detachedEvent, 'end');

        return array_merge($detachedEvent, $created, [
            'recurrenceType'      => CalendarEventRecurrence::SINGLE,
            'seriesId'            => '',
            'occurrenceId'        => '',
            'originalStart'       => '',
            'recurrenceId'        => '',
            'recurring'           => false,
            'canUpdateOccurrence' => false,
            'canDeleteOccurrence' => false,
            'canUpdateFollowing'  => false,
            'canUpdateSeries'     => false,
            'canDeleteSeries'     => false
        ]);
    }

    /** @param array<string, mixed> $event */
    private function taskSeriesKey(array $event): string
    {
        if (!(bool) ($event['recurring'] ?? false)
            || !CalendarEventRecurrence::isOccurrence($event)) {
            return '';
        }

        $seriesId = trim((string) ($event['seriesId'] ?? ''));
        if ($seriesId !== '') {
            return 'series:' . $seriesId;
        }

        $uid = trim((string) ($event['uid'] ?? ''));
        return $uid !== '' ? 'uid:' . $uid : '';
    }

    /**
     * Returns series keys which already have an open detached task.
     *
     * @param list<array<string, mixed>> $events
     * @return array<string, true>
     */
    private function pendingTaskSeries(array &$events): array
    {
        $pending = json_decode($this->ReadAttributeString('PendingTaskSeries'), true);
        if (!is_array($pending) || array_is_list($pending)) {
            $pending = [];
        }

        foreach ($events as &$event) {
            unset($event['taskRolledForward']);
        }
        unset($event);

        $openSeries = [];
        $remaining = [];
        foreach ($pending as $seriesKey => $identity) {
            if (!is_string($seriesKey) || !is_array($identity)) {
                continue;
            }
            // An absent item may simply lie outside PastDays/FutureDays. Keep
            // its association unless completion or deletion is confirmed.
            $openSeries[$seriesKey] = true;
            $remaining[$seriesKey] = $identity;
            $found = false;
            foreach ($events as &$event) {
                if (!$this->matchesPendingTaskIdentity($event, $identity)) {
                    continue;
                }
                $found = true;
                $event = CalendarTaskEvent::enrich($event);
                if ((bool) ($event['task'] ?? false) && !(bool) ($event['taskCompleted'] ?? false)) {
                    $event['taskRolledForward'] = true;
                    $openSeries[$seriesKey] = true;
                    $remaining[$seriesKey] = $identity;
                } else {
                    unset($openSeries[$seriesKey], $remaining[$seriesKey]);
                }
                break;
            }
            unset($event);
            if (!$found && $this->pendingTaskWasClosed($identity)) {
                unset($openSeries[$seriesKey], $remaining[$seriesKey]);
            }
        }

        if ($remaining !== $pending) {
            $this->WriteAttributeString(
                'PendingTaskSeries',
                json_encode($remaining, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
        }

        return $openSeries;
    }

    /** @param array<string, mixed> $identity */
    private function pendingTaskWasClosed(array $identity): bool
    {
        try {
            $start = (int) ($identity['startTimestamp'] ?? (new DateTimeImmutable('today'))->getTimestamp());
            $result = $this->sendRequest('CheckPendingTask', [
                'EventReference' => (string) ($identity['eventReference'] ?? ''),
                'ResourceURL'    => (string) ($identity['resourceUrl'] ?? ''),
                'UID'            => (string) ($identity['uid'] ?? ''),
                'Start'          => $start,
                'End'            => max($start + 1, (int) ($identity['endTimestamp'] ?? $start + 86400))
            ]);
            if (!(bool) ($result['known'] ?? false) || !array_key_exists('event', $result)) {
                return false;
            }
            if ($result['event'] === null) {
                return true;
            }
            if (!is_array($result['event']) || !$this->matchesPendingTaskIdentity($result['event'], $identity)) {
                return false;
            }
            $task = CalendarTaskEvent::enrich($result['event']);
            return !(bool) ($task['task'] ?? false) || (bool) ($task['taskCompleted'] ?? false);
        } catch (Throwable $exception) {
            $this->SendSafeDebugException('PendingTaskLookupDeferred', $exception);
            return false;
        }
    }

    /** @param array<string, mixed> $event Confirmed closed, unmarked or deleted task identity. */
    private function forgetPendingTask(array $event): void
    {
        $pending = json_decode($this->ReadAttributeString('PendingTaskSeries'), true);
        if (!is_array($pending) || array_is_list($pending)) {
            return;
        }
        foreach ($pending as $seriesKey => $identity) {
            if (is_array($identity) && $this->matchesPendingTaskIdentity($event, $identity)) {
                unset($pending[$seriesKey]);
            }
        }
        $this->WriteAttributeString(
            'PendingTaskSeries',
            json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    /** @param array<string, mixed> $event */
    private function rememberPendingTaskSeries(string $seriesKey, array $event): void
    {
        $eventReference = trim((string) ($event['eventReference'] ?? ''));
        $resourceUrl = trim((string) ($event['resourceUrl'] ?? ''));
        $uid = trim((string) ($event['uid'] ?? ''));
        if ($seriesKey === '' || ($eventReference === '' && $resourceUrl === '' && $uid === '')) {
            return;
        }

        $pending = json_decode($this->ReadAttributeString('PendingTaskSeries'), true);
        if (!is_array($pending) || array_is_list($pending)) {
            $pending = [];
        }
        $pending[$seriesKey] = [
            'eventReference' => $eventReference,
            'resourceUrl'    => $resourceUrl,
            'uid'            => $uid,
            'startTimestamp' => $this->eventBoundaryTimestamp($event, 'start'),
            'endTimestamp'   => $this->eventBoundaryTimestamp($event, 'end')
        ];
        $this->WriteAttributeString(
            'PendingTaskSeries',
            json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $identity
     */
    private function matchesPendingTaskIdentity(array $event, array $identity): bool
    {
        foreach (['eventReference', 'resourceUrl', 'uid'] as $key) {
            $expected = trim((string) ($identity[$key] ?? ''));
            $actual = trim((string) ($event[$key] ?? ''));
            if ($expected !== '' && $actual !== '' && hash_equals($expected, $actual)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $event */
    private function isRolledForwardOpenTask(array $event, DateTimeImmutable $today): bool
    {
        $event = CalendarTaskEvent::enrich($event);
        if (!(bool) ($event['task'] ?? false)
            || (bool) ($event['taskCompleted'] ?? false)
            || !(bool) ($event['allDay'] ?? false)) {
            return false;
        }

        $start = $this->taskEventDate($event, 'start');
        $originalStart = $this->taskEventDate($event, 'originalStart');
        return $start !== null
            && $originalStart !== null
            && $originalStart < $today
            && $start >= $today;
    }

    /** @param array<string, mixed> $event */
    private function taskEventDate(array $event, string $key): ?DateTimeImmutable
    {
        $value = substr(trim((string) ($event[$key] ?? '')), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function calendarCanWrite(): bool
    {
        if (!$this->ReadAttributeBoolean('CalendarMetadataAvailable')) {
            return $this->ReadPropertyBoolean('CanWrite');
        }
        if ($this->ReadAttributeBoolean('DetectedWriteAccessKnown')) {
            return $this->ReadAttributeBoolean('DetectedCanWrite');
        }

        return $this->ReadAttributeBoolean('DetectedCanWrite')
            || $this->ReadPropertyBoolean('CanWrite');
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function enrichTaskEvents(array $events): array
    {
        return array_map(
            static fn (array $event): array => CalendarTaskEvent::enrich($event),
            $events
        );
    }

    /**
     * Returns a cached task event when a fresh provider lookup is temporarily unavailable.
     *
     * Task appointments may have just been shifted to the current day. In that short
     * provider-consistency window their cached identity and ETag are still sufficient for
     * editing; normal appointments retain the stricter fresh-lookup requirement.
     *
     * @param array<string, mixed> $identity
     * @return array<string, mixed>|null
     */
    private function cachedTaskEventForEdit(array $identity, Throwable $exception): ?array
    {
        // Only transient provider failures may use the existing task identity.
        if (!$exception instanceof CalendarProviderErrorException
            || !in_array($exception->errorType, [
                CalendarProviderError::TYPE_UNAVAILABLE,
                CalendarProviderError::TYPE_TRANSPORT,
                CalendarProviderError::TYPE_RATE_LIMITED
            ], true)) {
            return null;
        }
        $event = $this->cachedEventForIdentity($identity);
        if ($event === null) {
            return null;
        }

        $event = $this->enrichAnniversaryEvent(CalendarTaskEvent::enrich($event));
        if (!(bool) ($event['task'] ?? false)) {
            return null;
        }

        $this->SendSafeDebug('TaskEventEditCacheFallback', [
            'reason'            => $exception->getMessage(),
            'hasEventReference' => trim((string) ($event['eventReference'] ?? '')) !== '',
            'hasResourceUrl'    => trim((string) ($event['resourceUrl'] ?? '')) !== ''
        ]);

        return $event;
    }
    private function handleError(Throwable $exception): string
    {
        $rawMessage = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '');
        if ($rawMessage === '') {
            $rawMessage = 'Unknown calendar error.';
        }

        $errorType = $exception instanceof CalendarProviderErrorException
            ? CalendarProviderError::normalizeType($exception->errorType)
            : CalendarProviderError::fromThrowable($exception)['type'];
        if ($errorType === '') {
            $errorType = CalendarProviderError::TYPE_PROVIDER;
        }

        if ($errorType === CalendarProviderError::TYPE_CONFLICT) {
            $this->SetStatus(self::STATUS_WRITE_CONFLICT);
        } elseif ($errorType === CalendarProviderError::TYPE_INVALID_RESPONSE) {
            $this->SetStatus(self::STATUS_INVALID_RESPONSE);
        } else {
            $this->SetStatus(self::STATUS_SYNCHRONIZATION_FAILED);
        }

        $normalizedMessage = CalendarProviderError::messageFor($errorType, $rawMessage);
        $message = $exception instanceof JsonException
            ? $this->Translate('Invalid JSON data.')
            : $this->translateErrorMessage($normalizedMessage);
        $this->WriteAttributeString('LastError', $message);
        $this->SendSafeDebug('CalendarError', [
            'type'      => $exception::class,
            'errorType' => $errorType,
            'message'   => $rawMessage,
            'code'      => $exception->getCode()
        ]);

        return $message;
    }

    private function translateErrorMessage(string $message): string
    {
        if (preg_match('/^The (.+) must be a JSON object\.$/', $message, $matches) === 1) {
            return sprintf($this->Translate('The %s must be a JSON object.'), $matches[1]);
        }

        return $this->Translate($message);
    }

    private function encodeResult(bool $success, mixed $event = null, string $error = ''): string
    {
        return json_encode(
            ['success' => $success, 'event' => $event, 'error' => $error],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
