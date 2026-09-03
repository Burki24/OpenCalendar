<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\ChunkedJsonTransferHelper;
use Burki24\SymconModuleHelper\ConfigurationFormHelper;
use Burki24\SymconModuleHelper\DataFlowHelper;
use Burki24\SymconModuleHelper\DebugHelper;
use Burki24\SymconModuleHelper\PersistentJsonCacheHelper;
use Burki24\SymconModuleHelper\VariableHelper;
use IPSKalender\CalendarEventCounter;
use IPSKalender\CalendarEventDeletion;
use IPSKalender\CalendarEventRecurrence;
use IPSKalender\CalendarEventState;
use IPSKalender\CalendarProviderError;
use IPSKalender\CalendarProviderErrorException;
use IPSKalender\SynchronizationSchedule;

require_once __DIR__ . '/../libs/helper/ChunkedJsonTransferHelper.php';
require_once __DIR__ . '/../libs/helper/ConfigurationFormHelper.php';
require_once __DIR__ . '/../libs/helper/DataFlowHelper.php';
require_once __DIR__ . '/../libs/helper/DebugHelper.php';
require_once __DIR__ . '/../libs/helper/PersistentJsonCacheHelper.php';
require_once __DIR__ . '/../libs/helper/VariableHelper.php';
require_once __DIR__ . '/../libs/CalendarEventCounter.php';
require_once __DIR__ . '/../libs/CalendarEventDeletion.php';
require_once __DIR__ . '/../libs/CalendarEventRecurrence.php';
require_once __DIR__ . '/../libs/CalendarEventState.php';
require_once __DIR__ . '/../libs/CalendarProviderError.php';
require_once __DIR__ . '/../libs/SynchronizationSchedule.php';

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
    private const INITIALIZATION_DELAY_MS = 3_000;

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
        $this->RegisterPropertyString('CalendarID', '');
        $this->RegisterPropertyString('ProviderCalendarID', '');
        $this->RegisterPropertyString('CalendarURL', '');
        $this->RegisterPropertyString('CalendarColor', '');
        $this->RegisterPropertyBoolean('CanWrite', false);
        $this->RegisterPropertyInteger('UpdateSchedule', SynchronizationSchedule::CUSTOM);
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyInteger('PastDays', 30);
        $this->RegisterPropertyInteger('FutureDays', 365);

        $this->RegisterPersistentJsonCache('CachedEvents');
        $this->RegisterAttributeString('AnniversaryMetadata', '[]');
        $this->RegisterAttributeString('BirthdayMetadata', '[]');
        $this->RegisterAttributeInteger('LastSynchronization', 0);
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeString('IncrementalSyncToken', '');
        $this->RegisterAttributeInteger('IncrementalSyncWindowStart', 0);
        $this->RegisterAttributeInteger('IncrementalSyncWindowEnd', 0);
        $this->RegisterAttributeString('IncrementalSyncCalendarID', '');
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
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'UpdateInterval') {
                $element['visible'] = $customSchedule;
                break;
            }
        }
        unset($element);

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
            SynchronizationSchedule::timerInterval(
                $this->ReadPropertyInteger('UpdateSchedule'),
                $this->ReadPropertyInteger('UpdateInterval')
            )
        );
        $this->refreshCalendarMetadataSafely();
        $this->updateEventCounters($this->readEvents());
        $this->scheduleTodayEventCountRefresh();
        $this->SetStatus(IS_ACTIVE);

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
     * Recalculates the current-day event count after the local day changes.
     *
     * @return bool True when the counter was updated.
     */
    public function RefreshTodayEventCount(): bool
    {
        $this->SetTimerInterval('DayChangeTimer', 0);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return false;
        }

        $this->updateEventCounters($this->readEvents());
        $this->scheduleTodayEventCountRefresh();

        return true;
    }

    /**
     * Receives calendar metadata notifications from the parent account instance.
     *
     * @param string $JSONString JSON-encoded parent notification.
     * @return string Empty response required by the Symcon data flow.
     */
    public function ReceiveData(string $JSONString): string
    {
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
            $events = $this->requestEvents();
            $this->storeEvents($events);
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus(IS_ACTIVE);
            $this->SendSafeDebug('SynchronizationCompleted', [
                'eventCount' => count($events),
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
     * This read intentionally bypasses the local event cache so the editor receives
     * the provider's current ETag and other write-relevant identity fields.
     *
     * @param string $EventJSON JSON-encoded event identity and current time range.
     * @return string JSON-encoded normalized current event.
     */
    public function GetEventForEdit(string $EventJSON): string
    {
        try {
            $event = $this->decodeObject($EventJSON, 'event');
            $startTimestamp = (int) ($event['startTimestamp'] ?? 0);
            $endTimestamp = (int) ($event['endTimestamp'] ?? 0);
            if ($startTimestamp <= 0) {
                throw new InvalidArgumentException('The selected event start is invalid.');
            }

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
            $this->assertEventAvailable($currentEvent);
            $currentEvent = $this->enrichAnniversaryEvent($currentEvent);

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
            $series = $this->enrichAnniversaryEvent($series);
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
            $following = $this->enrichAnniversaryEvent($following);
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
            $this->readEvents(),
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
     *
     * @param string $EventJSON JSON-encoded event data.
     * @return string JSON-encoded operation result.
     */
    public function CreateEvent(string $EventJSON): string
    {
        try {
            $event = $this->decodeObject($EventJSON, 'event');
            $anniversary = $this->anniversaryInput($event);
            if ($anniversary !== null && $anniversary['enabled']) {
                $recurrenceInput = $event['recurrence'] ?? null;
                if (!is_array($recurrenceInput) || $recurrenceInput === []) {
                    $this->applyAnniversaryEventDefaults($event, $anniversary['date']);
                } else {
                    $this->assertAnniversaryRecurrence($event);
                }
            }
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
            return $this->encodeResult(false, null, $this->handleError($exception));
        }
    }

    /**
     * Updates an existing event in the configured calendar.
     *
     * @param string $EventJSON JSON-encoded event metadata and changes.
     * @return string JSON-encoded operation result.
     */
    public function UpdateEvent(string $EventJSON): string
    {
        try {
            $event = $this->decodeObject($EventJSON, 'event');
            $changes = $event['changes'] ?? $event;
            if (!is_array($changes)) {
                throw new InvalidArgumentException('The event changes are invalid.');
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
            return $this->encodeResult(false, null, $this->handleError($exception));
        }
    }

    /**
     * Deletes an event from the configured calendar.
     *
     * @param string $EventJSON JSON-encoded event metadata.
     * @return bool True when the event was deleted successfully.
     */
    public function DeleteEvent(string $EventJSON): bool
    {
        try {
            $event = $this->decodeObject($EventJSON, 'event');
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
            return false;
        }
    }

    /**
     * Clears cached events and synchronization metadata.
     */
    public function ClearCache(): void
    {
        $this->clearIncrementalSyncState();
        $this->storeEvents([]);
        $this->WriteAttributeInteger('LastSynchronization', 0);
        $this->SetValue('LastSynchronization', 0);
        $this->WriteAttributeString('LastError', '');
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
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $changes
     * @return list<array<string, mixed>>
     */
    private function mergeIncrementalEvents(array $events, array $changes): array
    {
        $replacedResources = [];
        foreach ($changes as $change) {
            if ((bool) ($change['_syncDeleted'] ?? false)
                || !(bool) ($change['_syncReplaceResource'] ?? false)) {
                continue;
            }
            $resourceUrl = trim((string) ($change['resourceUrl'] ?? ''));
            if ($resourceUrl !== '') {
                $replacedResources[$resourceUrl] = true;
            }
        }
        if ($replacedResources !== []) {
            $events = array_values(array_filter(
                $events,
                static function (array $event) use ($replacedResources): bool
                {
                    $resourceUrl = trim((string) ($event['resourceUrl'] ?? ''));
                    return $resourceUrl === '' || !isset($replacedResources[$resourceUrl]);
                }
            ));
        }

        foreach ($changes as $change) {
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
                            && hash_equals($resourceUrl, $candidateResource)) {
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
                static function (array $event) use (
                    $eventReference,
                    $resourceUrl,
                    $occurrenceId,
                    $replaceResource
                ): bool {
                    foreach ([
                        [$eventReference, trim((string) ($event['eventReference'] ?? ''))],
                        [$replaceResource ? '' : $resourceUrl, trim((string) ($event['resourceUrl'] ?? ''))],
                        [$occurrenceId, trim((string) ($event['occurrenceId'] ?? ''))]
                    ] as [$expected, $actual]) {
                        if ($expected !== '' && $actual !== '' && hash_equals($expected, $actual)) {
                            return false;
                        }
                    }

                    return true;
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
        $events = $this->enrichAnniversaryEvents($events);
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
            return CalendarEventState::filterVisibleEvents($events);
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
                    && ($cachedEvent['recurrenceType'] ?? '') === CalendarEventRecurrence::OCCURRENCE) {
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

        if ((bool) ($currentEvent['recurring'] ?? false)
            || (string) ($currentEvent['recurrenceType'] ?? CalendarEventRecurrence::SINGLE)
                !== CalendarEventRecurrence::SINGLE) {
            return false;
        }

        $previousIdentity = $sourceEvent !== [] ? $sourceEvent : $event;
        $events = array_values(array_filter(
            $this->readEvents(),
            fn (array $cachedEvent): bool => !$this->eventIdentityMatches($cachedEvent, $previousIdentity)
                && !$this->eventIdentityMatches($cachedEvent, $currentEvent)
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
        $events = $this->enrichAnniversaryEvents($events);
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

    private function validateConfiguration(): string
    {
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
