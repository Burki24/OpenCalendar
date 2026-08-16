<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\IPSViewHTMLPageHelper;

require_once __DIR__ . '/../libs/helper/IPSViewHTMLPageHelper.php';

final class CalendarVisualizationRenderer
{
    use IPSViewHTMLPageHelper;

    public function render(bool $ipsView): string
    {
        return $this->RenderVisualizationHTMLPage($ipsView, [
            'language'           => 'de',
            'classes'            => $ipsView ? ['ipsview-mode'] : [],
            'rootFontSize'       => $ipsView ? '18px' : '100%',
            'title'              => 'Kalender',
            'visualizationTheme' => ':root { --symc-accent: #123456; }',
            'ipsViewStyle'       => ':root { --ipsview-accent: #654321; }',
            'state'              => [
                'events'    => [],
                'calendars' => [],
                'settings'  => [
                    'defaultView'               => 'list',
                    'agendaPeriodDays'          => 14,
                    'listPeriodDays'            => 21,
                    'threeDaysPeriodDays'       => 5,
                    'weekPeriodWeeks'           => 2,
                    'monthPeriodMonths'         => 2,
                    'showAgendaEventCount'      => false,
                    'showThreeDaysEventCount'   => true,
                    'showWeekEventCount'        => false,
                    'showAgendaCalendarWeek'    => true,
                    'showListCalendarWeek'      => true,
                    'showThreeDaysCalendarWeek' => false,
                    'showWeekCalendarWeek'      => true,
                    'showMonthCalendarWeek'     => true,
                    'showAgendaDayOfYear'       => false,
                    'showListDayOfYear'         => true,
                    'showThreeDaysDayOfYear'    => true,
                    'showWeekDayOfYear'         => false,
                    'showMonthDayOfYear'        => true,
                    'showListDate'              => true,
                    'showListStart'             => true,
                    'showListEnd'               => false,
                    'showListTitle'             => true,
                    'showListCalendarName'      => true,
                    'showListLocation'          => false,
                    'showListDescription'       => true,
                    'showListControls'          => false
                ]
            ],
            'runtime'            => $ipsView
                ? ['endpoint' => '/hook/opencalendar/view/12345', 'token' => '0123456789abcdef0123456789abcdef']
                : null,
            'translations'       => ['Today' => 'Heute'],
            'options'            => [
                'instanceId'           => 12345,
                'agendaColorBarWidth'  => 7,
                'compactColorBarWidth' => 7
            ]
        ]);
    }

    public function VisualizationAsset(string $name): string
    {
        $path = __DIR__ . '/../Kalender Ansicht/visualization/' . $name;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    public function Translate(string $text): string
    {
        return $text;
    }

    public function SendDebug(string $message, mixed $data, int $format): void
    {
    }
}

function assertVisualization(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$script = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/app.js');
$indexSource = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/index.html');
assertVisualization(
    str_contains($script, 'return event.allDay ? allDayDate(event.start, event.startTimestamp)')
        && str_contains($script, 'allDayDate(event.end, event.endTimestamp || event.startTimestamp)')
        && str_contains($script, 'function allDayDate(value, fallbackTimestamp)'),
    'All-day events must use their date-only boundaries so exclusive end dates cannot spill into the next local day.'
);
assertVisualization(
    str_contains($script, 'eventStart(selectedEvent),')
        && str_contains($script, 'Boolean(selectedEvent.allDay)')
        && str_contains($script, 'const displayEnd = allDay && allDayEndExclusive && end > start ? addDays(end, -1) : end;')
        && str_contains($script, "end: inputDateValue(document.getElementById('event-end').value, allDay, allDay)")
        && str_contains($script, 'return localDate(exclusiveEnd ? addDays(date, 1) : date);'),
    'The all-day event dialog must show an inclusive end date and convert it back to an exclusive provider end date.'
);
assertVisualization(
    str_contains($script, 'function updateEndFromStart()')
        && str_contains($script, 'startInput.dataset.previousValue = startInput.value;')
        && str_contains($script, 'const dateChanged = previousStart && dayKey(previousStart) !== dayKey(start);')
        && str_contains($script, 'if (dateChanged && !timeChanged && currentEnd) {')
        && str_contains($script, 'currentEnd.getHours(),')
        && str_contains($script, 'new Date(start.getTime() + 60 * 60 * 1000)')
        && str_contains($script, "document.getElementById('event-start').addEventListener('change', () => {")
        && str_contains($script, 'updateRecurrenceEndDateMinimum();'),
    'Changing only the start date must move the end to that date while preserving its time; changing the start time must use a one-hour duration.'
);

assertVisualization(
    str_contains($indexSource, 'id="event-recurrence-frequency"')
        && str_contains($indexSource, 'id="event-recurrence-weekdays"')
        && str_contains($indexSource, 'id="event-recurrence-end-mode"')
        && str_contains($script, 'Boolean(calendar?.canCreateRecurrence)')
        && str_contains($script, 'Boolean(movingSingle ? calendar?.canCreateRecurrence : calendar?.canUpdateRecurrence)')
        && str_contains($script, 'const editingSingle = selectedEvent !== null && !Boolean(selectedEvent?.recurring);')
        && str_contains($script, 'const canClearSeriesRecurrence = editingSeries')
        && str_contains($script, 'Boolean(calendar?.canUpdateRecurrence);')
        && str_contains($script, 'resetRecurrenceEditor(eventStart(selectedEvent));')
        && str_contains($script, 'function recurrencePatternControls()')
        && str_contains($script, "mode.id = 'event-recurrence-pattern-mode';")
        && str_contains($script, "index.id = 'event-recurrence-relative-index';")
        && str_contains($script, "Boolean(selectedCalendarEntry()?.canUpdateRecurrence);")
        && str_contains($script, "recurrencePatternContext.patternMode")
        && str_contains($script, "recurrence.patternMode = 'relative';")
        && str_contains($script, 'recurrence.relativeIndex = patternControls.index.value;')
        && str_contains($script, 'recurrence.weekStart = recurrencePatternContext.weekStart;')
        && str_contains($script, 'recurrence.recurrenceTimeZone = recurrencePatternContext.recurrenceTimeZone;')
        && str_contains($script, 'function recurrenceEditorValue()')
        && str_contains($script, 'eventData.recurrence = recurrence;')
        && str_contains($script, "eventRecurrenceFrequency.value === 'none'")
        && str_contains($script, 'eventData.recurrence = null;')
        && str_contains($script, 'Intl.DateTimeFormat().resolvedOptions().timeZone')
        && str_contains($script, 'if (timezone && (!allDay || recurrence || editingSeries || editingFollowing)) {')
        && str_contains($script, 'eventData.timezone = timezone;'),
    'The event dialog must support Microsoft recurrence conversion and preserve Outlook-specific weekly and relative recurrence metadata while submitting the correct timezone.'
);

assertVisualization(
    str_contains($script, "const action = moving ? 'MoveEvent' : (selectedEvent ? 'UpdateEvent' : 'CreateEvent');")
        && str_contains($script, 'targetCalendarInstanceId: calendarInstanceId')
        && str_contains($script, "document.getElementById('save-button').textContent = t(moving ? 'Move' : 'Save');")
        && str_contains($script, 'calendarState.calendars.filter(calendar => calendar.canWrite || calendar.instanceId === selectedEvent.calendarInstanceId)'),
    'Editable events must allow selecting another writable calendar and submit a dedicated move action.'
);

assertVisualization(
    str_contains($script, 'function openDayEvents(day, events)')
        && str_contains($script, "more.addEventListener('click', () => openDayEvents(day, events));")
        && str_contains($script, 'bindMonthDayOverview(cell, day, events);')
        && str_contains($script, "cell.classList.add('month-day-overview-enabled');")
        && str_contains($script, "clickEvent.target.closest?.('button, a, input, select, textarea, label')")
        && str_contains($script, 'openDayEvents(day, events);')
        && str_contains($script, 'dayEventsDialog.showModal();')
        && str_contains($script, 'openEventDetails(event);'),
    'The month view must open the day-events modal from the day cell or additional-events indicator without intercepting event buttons.'
);

assertVisualization(
    str_contains($indexSource, 'id="event-details-dialog" class="oc-dialog oc-dialog-extra-large event-details-dialog"')
        && str_contains($script, 'function openEventDetails(event)')
        && str_contains($script, 'card.addEventListener(\'click\', () => openEventDetails(event));')
        && str_contains($script, 'document.getElementById(\'details-edit-button\').addEventListener(\'click\'')
        && str_contains($script, 'requestEdit(eventDetailsDialog)')
        && str_contains($script, 'void prepareEventEdit(event);')
        && str_contains($script, "t(editingSeries ? 'Edit recurring event' : 'Edit event')")
        && str_contains($script, 'const displayEnd = end > start ? addDays(end, -1) : start;')
        && str_contains($script, 'function eventCanUpdate(event)')
        && str_contains($script, 'Boolean(event.canUpdateOccurrence)')
        && str_contains($script, 'function eventCanUpdateFollowing(event)')
        && str_contains($script, 'Boolean(event.canUpdateFollowing)')
        && str_contains($script, 'Boolean(event.canUpdateSeries)')
        && str_contains($script, 'function eventCanDeleteFollowing(event)')
        && str_contains($script, 'function eventCanDelete(event)')
        && str_contains($script, 'Boolean(event.canDeleteOccurrence)')
        && str_contains($script, 'eventCanDeleteFollowing(event)')
        && str_contains($script, '...recurrencePayload(selectedEvent)')
        && str_contains($script, "t('Only this occurrence of the recurring event will be changed.')"),
    'Event clicks must open a read-first details modal and route editable events to the existing editor only on request.'
);

assertVisualization(
    str_contains($script, "const eventList = element('div', 'month-events');")
        && str_contains($script, 'function fitMonthEventContainer(container, day, events)')
        && str_contains($script, 'container.scrollHeight > container.clientHeight')
        && str_contains($script, "more.textContent = '+' + (events.length - visibleChips.length) + ' ' + t('more');")
        && str_contains($script, "window.addEventListener('resize'")
        && str_contains($script, 'new ResizeObserver(() => {'),
    'The month view must dynamically fit event chips to the available row height and reserve space for the additional-events control.'
);

assertVisualization(
    str_contains($script, 'strong.textContent = new Intl.DateTimeFormat(undefined, { weekday: \'long\' }).format(group.date);')
        && str_contains($script, '{ day: \'2-digit\', month: \'long\' },')
        && !str_contains($script, 'relativeDay(group.date)')
        && !str_contains($script, 'function relativeDay(date)'),
    'Agenda day headings must show the weekday without Today, Tomorrow or Yesterday prefixes.'
);

assertVisualization(
    str_contains($script, 'function dailyViewEntries(events, rangeStart, rangeEnd)')
        && substr_count($script, 'dailyViewEntries(events, rangeStart, rangeEnd)') >= 3
        && str_contains($script, 'while (day < end && day < rangeEnd)')
        && str_contains($script, 'entries.push({ event, date: startOfDay(day) });')
        && str_contains($script, 'cell.textContent = column.value(event, entry.date);'),
    'Agenda and list views must expand multi-day all-day events to every visible day in their range.'
);

assertVisualization(
    str_contains($script, 'calendarState.settings.showAgendaEventCount !== false')
        && str_contains($script, 'calendarState.settings.showThreeDaysEventCount !== false')
        && str_contains($script, 'calendarState.settings.showWeekEventCount !== false')
        && str_contains($script, 'if (showEventCount) {'),
    'Agenda, three-day and week views must control their event counts independently.'
);
assertVisualization(
    str_contains($script, 'calendarState.settings.showAgendaCalendarWeek === true')
        && str_contains($script, 'calendarState.settings.showListCalendarWeek === true')
        && str_contains($script, 'calendarState.settings.showThreeDaysCalendarWeek === true')
        && str_contains($script, 'calendarState.settings.showWeekCalendarWeek !== false')
        && str_contains($script, 'calendarState.settings.showMonthCalendarWeek === true')
        && str_contains($script, "calendarWeeks.join('/')")
        && str_contains($script, 'day.getDay() === 1'),
    'Agenda, list, three-day, week and month views must control ISO calendar-week labels independently.'
);
assertVisualization(
    str_contains($script, 'calendarState.settings.showAgendaDayOfYear !== false')
        && str_contains($script, 'calendarState.settings.showListDayOfYear === true')
        && str_contains($script, 'calendarState.settings.showThreeDaysDayOfYear !== false')
        && str_contains($script, 'calendarState.settings.showWeekDayOfYear !== false')
        && str_contains($script, 'calendarState.settings.showMonthDayOfYear !== false')
        && str_contains($script, 'function formatDayHeading(date, options, showDayOfYear, eventCount, showEventCount)'),
    'Agenda, list, three-day, week and month views must control their day-of-year labels independently.'
);

$formSource = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/form.json');
$moduleSource = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/module.php');
$calendarModuleSource = (string) file_get_contents(__DIR__ . '/../Kalender/module.php');

assertVisualization(
    substr_count(
        $moduleSource,
        "if ((\$event['recurrenceType'] ?? '') === 'occurrence'\n"
            . "                    && trim((string) (\$event['originalStart'] ?? '')) === '') {\n"
            . "                    \$event['originalStart'] = trim((string) (\$event['start'] ?? ''));"
    ) === 2
        && substr_count(
            $moduleSource,
            "\$recurringOccurrence = (bool) (\$event['recurring'] ?? false)\n"
                . "                    && trim((string) (\$event['occurrenceId'] ?? '')) !== ''\n"
                . "                    && trim((string) (\$event['seriesId'] ?? '')) !== '';"
        ) === 2,
    'Cached Microsoft occurrences must recover their original start and recurrence capabilities without manual cache deletion.'
);

assertVisualization(
    str_contains($script, 'let pendingEventEdit = null;')
        && str_contains($script, 'async function prepareEventEdit(event)')
        && str_contains($script, "sendAction('PrepareEventEdit', request)")
        && str_contains($script, "openExistingEvent(eventEdit, 'occurrence')")
        && !str_contains($script, "openExistingEvent(event, 'occurrence')")
        && str_contains($moduleSource, "case 'PrepareEventEdit':")
        && str_contains($moduleSource, 'IPSKAL_GetEventForEdit(')
        && str_contains($moduleSource, '$state[\'eventEdit\'] = $eventEdit;')
        && str_contains($calendarModuleSource, 'public function GetEventForEdit(string $EventJSON): string')
        && str_contains($calendarModuleSource, '$this->sendRequest(\'GetEventForEdit\', ['),
    'Opening a normal or single-occurrence editor must refresh the event identity and ETag from the provider first.'
);

assertVisualization(
    str_contains($indexSource, 'id="edit-scope-dialog"')
        && str_contains($indexSource, 'name="edit-scope" value="occurrence"')
        && str_contains($indexSource, 'name="edit-scope" value="following"')
        && str_contains($indexSource, 'name="edit-scope" value="series"')
        && str_contains($script, 'function requestEdit(sourceDialog)')
        && str_contains($script, 'function confirmEditScope()')
        && str_contains($script, 'function eventCanUpdateFollowing(event)')
        && str_contains($script, "resourceUrl: String(event.resourceUrl || '')")
        && str_contains($script, "sendAction('PrepareSeriesEdit', pendingSeriesEdit)")
        && str_contains($script, "const writeScope = pendingSeriesEdit.writeScope === 'following' ? 'following' : 'series';")
        && str_contains($script, 'openExistingEvent(seriesEdit, writeScope)')
        && str_contains($script, 'function loadRecurrenceEditor(event)')
        && str_contains($script, "selectedEvent?.writeScope === 'following'")
        && str_contains($script, "selectedEvent?.writeScope === 'series'")
        && str_contains($script, 'Boolean(event.canUpdateFollowing)')
        && str_contains($script, 'Boolean(event.canUpdateSeries)')
        && str_contains($script, 'writeScope: scope')
        && str_contains($moduleSource, "case 'PrepareSeriesEdit':")
        && str_contains($moduleSource, 'IPSKAL_GetRecurringFollowing($instanceId, $seriesId, $occurrenceId, $originalStart, $resourceUrl)')
        && str_contains($moduleSource, 'IPSKAL_GetRecurringSeries($instanceId, $seriesId, $resourceUrl)')
        && str_contains($calendarModuleSource, "public function GetRecurringSeries(string \$SeriesID, string \$ResourceURL = ''): string")
        && str_contains($calendarModuleSource, 'public function GetRecurringFollowing(')
        && str_contains($calendarModuleSource, "string \$ResourceURL = ''")
        && str_contains($calendarModuleSource, "'ResourceURL' => trim(\$ResourceURL)")
        && str_contains($calendarModuleSource, "'ResourceURL'   => trim(\$ResourceURL)")
        && str_contains($moduleSource, '$seriesEdit[\'canWrite\'] = true;')
        && str_contains($moduleSource, '$seriesEdit[\'writeScope\'] = $writeScope;')
        && str_contains($moduleSource, "'Changes will apply to this and all following occurrences.'")
        && str_contains($moduleSource, "'Changes will apply to the entire recurring series.'"),
    'Recurring Google, Microsoft and CalDAV events must offer supported write scopes with verified provider data.'
);
$seriesWriteScopePosition = strpos(
    $calendarModuleSource,
    'if ($writeScope === CalendarEventRecurrence::WRITE_SCOPE_SERIES) {'
);
$cachedWriteLookupPosition = strpos(
    $calendarModuleSource,
    'foreach ($this->readEvents() as $cachedEvent) {'
);
assertVisualization(
    $seriesWriteScopePosition !== false
        && $cachedWriteLookupPosition !== false
        && $seriesWriteScopePosition < $cachedWriteLookupPosition
        && str_contains($calendarModuleSource, "'ResourceURL' => \$resourceUrl")
        && str_contains(
            $calendarModuleSource,
            'return CalendarEventRecurrence::fromEvent($verifiedIdentity);'
        )
        && !str_contains(
            $calendarModuleSource,
            "\$verifiedSeries = \$this->sendRequest('GetRecurringSeries', ['SeriesID' => \$seriesId]);"
        ),
    'Whole-series writes must verify the recurring master before cached occurrences and reuse the known provider resource.'
);

assertVisualization(
    str_contains($indexSource, 'id="delete-scope-following-option"')
        && str_contains($indexSource, 'name="delete-scope" value="following"')
        && str_contains($script, 'function eventCanDeleteFollowing(event)')
        && str_contains($script, 'Boolean(event.canUpdateFollowing)')
        && str_contains($script, 'Boolean(event.canDeleteSeries)')
        && str_contains($script, 'const followingAllowed = eventCanDeleteFollowing(event);')
        && str_contains($script, "return ['following', 'series'].includes(selected?.value) ? selected.value : 'occurrence';"),
    'Recurring Google, Microsoft and CalDAV events must offer deleting the selected occurrence and all following occurrences when splitting is supported.'
);
assertVisualization(
    str_contains($moduleSource, 'private function getFullUpdateMessage(?array $state = null, ?array $toast = null): string')
        && str_contains($moduleSource, '$message[\'toast\'] = $toast;')
        && str_contains($moduleSource, '$this->UpdateVisualizationValue($this->getFullUpdateMessage(')
        && str_contains($moduleSource, "'level'   => \$result['level']")
        && str_contains($moduleSource, "'message' => \$result['message']"),
    'Native visualization actions must send refreshed state and toast together so the tile cannot remain stale after an action.'
);
assertVisualization(
    str_contains($formSource, '"name": "TileFontScale"')
        && str_contains($formSource, '"caption": "Tile font size (%)"')
        && str_contains($moduleSource, "RegisterPropertyInteger('TileFontScale', 100)")
        && str_contains($moduleSource, "ReadPropertyInteger('TileFontScale')")
        && str_contains($moduleSource, "'tileFontScale'             => max(50, min(200, \$this->ReadPropertyInteger('TileFontScale')))")
        && str_contains($moduleSource, ": max(50, min(200, \$this->ReadPropertyInteger('TileFontScale'))) . '%'")
        && str_contains($moduleSource, '? $this->IPSViewStyleRootFontSize()')
        && str_contains($indexSource, 'style="font-size: {{ROOT_FONT_SIZE}};"')
        && str_contains($script, 'function applyTileFontScale()')
        && str_contains($script, 'document.documentElement.style.fontSize = `${scale}%`;'),
    'The native tile must apply its independent configurable font scale initially and on live state updates without changing IPSView scaling.'
);
foreach (['ShowAgendaEventCount', 'ShowThreeDaysEventCount', 'ShowWeekEventCount'] as $property) {
    assertVisualization(
        str_contains($formSource, '"name": "' . $property . '"')
            && str_contains($moduleSource, "RegisterPropertyBoolean('" . $property . "', true)")
            && str_contains($moduleSource, "ReadPropertyBoolean('" . $property . "')"),
        sprintf('The %s setting must be configurable, persisted and exposed to the visualization.', $property)
    );
}
foreach ([
    'ShowAgendaCalendarWeek'    => false,
    'ShowListCalendarWeek'      => false,
    'ShowThreeDaysCalendarWeek' => false,
    'ShowWeekCalendarWeek'      => true,
    'ShowMonthCalendarWeek'     => false
] as $property => $default) {
    assertVisualization(
        str_contains($formSource, '"name": "' . $property . '"')
            && str_contains(
                $moduleSource,
                "RegisterPropertyBoolean('" . $property . "', " . ($default ? 'true' : 'false') . ')'
            )
            && str_contains($moduleSource, "ReadPropertyBoolean('" . $property . "')"),
        sprintf('The %s setting must be configurable, persisted and exposed to the visualization.', $property)
    );
}
foreach (['ShowAgendaDayOfYear', 'ShowThreeDaysDayOfYear', 'ShowWeekDayOfYear', 'ShowMonthDayOfYear'] as $property) {
    assertVisualization(
        str_contains($formSource, '"name": "' . $property . '"')
            && str_contains($moduleSource, "RegisterPropertyBoolean('" . $property . "', true)")
            && str_contains($moduleSource, "ReadPropertyBoolean('" . $property . "')"),
        sprintf('The %s setting must be configurable, persisted and exposed to the visualization.', $property)
    );
}
assertVisualization(
    str_contains($formSource, '"name": "ShowListDayOfYear"')
        && str_contains($moduleSource, "RegisterPropertyBoolean('ShowListDayOfYear', false)")
        && str_contains($moduleSource, "ReadPropertyBoolean('ShowListDayOfYear')"),
    'The list day-of-year setting must be configurable, persisted and exposed to the visualization.'
);
assertVisualization(
    str_contains($formSource, '"caption": "Event count"')
        && str_contains($formSource, '"caption": "Calendar week"')
        && str_contains($formSource, '"caption": "Day of year"')
        && !str_contains($formSource, '"name": "ShowDayOfYear"'),
    'Display options must be grouped as an event-count, calendar-week and day-of-year matrix.'
);
assertVisualization(
    preg_match('/"type": "ExpansionPanel",\s*"caption": "Display options"/', $formSource) === 1
        && preg_match('/"type": "PopupButton",\s*"caption": "Display options"/', $formSource) === 0
        && str_contains($formSource, '"caption": "General options"')
        && str_contains($formSource, '"caption": "Per-view options"'),
    'Display options must use a collapsible configuration section instead of a popup.'
);
assertVisualization(
    str_contains($moduleSource, "array_key_exists('ShowDayOfYear', \$configuration)")
        && str_contains($moduleSource, "unset(\$configuration['ShowDayOfYear'])"),
    'The former global day-of-year setting must migrate to the per-view settings.'
);

assertVisualization(
    str_contains($formSource, '"caption": "List columns"')
        && str_contains($script, 'function renderList()')
        && str_contains($script, 'function listColumns()')
        && str_contains($script, "label: 'Date'")
        && str_contains($script, "label: 'Start'")
        && str_contains($script, "label: 'End'")
        && str_contains($script, "label: 'Title'")
        && str_contains($script, "label: 'Calendar'")
        && str_contains($script, "label: 'Location'")
        && str_contains($script, "label: 'Description'"),
    'The list view must expose configurable data columns.'
);
foreach ([
    'ShowListDate'         => true,
    'ShowListStart'        => true,
    'ShowListEnd'          => true,
    'ShowListTitle'        => true,
    'ShowListCalendarName' => true,
    'ShowListLocation'     => false,
    'ShowListDescription'  => false
] as $property => $default) {
    assertVisualization(
        str_contains($formSource, '"name": "' . $property . '"')
            && str_contains(
                $moduleSource,
                "RegisterPropertyBoolean('" . $property . "', " . ($default ? 'true' : 'false') . ')'
            )
            && str_contains($moduleSource, "ReadPropertyBoolean('" . $property . "')"),
        sprintf('The %s list-column setting must be configurable and persisted.', $property)
    );
}
assertVisualization(
    str_contains($formSource, '"name": "ShowListControls"')
        && str_contains($moduleSource, "RegisterPropertyBoolean('ShowListControls', true)")
        && str_contains($moduleSource, "ReadPropertyBoolean('ShowListControls')")
        && str_contains($script, 'function listControlsVisible()')
        && str_contains($script, "activeView !== 'list' || calendarState.settings.showListControls !== false")
        && str_contains($script, "document.getElementById('previous-button').parentElement.classList.toggle('hidden', !showControls)")
        && str_contains($script, "document.getElementById('refresh-button').classList.toggle('hidden', !showControls)")
        && str_contains($script, 'actionBridgeAvailable && listControlsVisible()'),
    'The list-view controls setting must hide navigation, event creation and refresh while preserving the period and view selector.'
);
foreach ([
    'AgendaPeriodDays'    => 14,
    'ListPeriodDays'      => 14,
    'ThreeDaysPeriodDays' => 3,
    'WeekPeriodWeeks'     => 1,
    'MonthPeriodMonths'   => 1
] as $property => $default) {
    assertVisualization(
        str_contains($formSource, '"name": "' . $property . '"')
            && str_contains($moduleSource, "RegisterPropertyInteger('" . $property . "', " . $default . ')')
            && str_contains($moduleSource, "ReadPropertyInteger('" . $property . "')"),
        sprintf('The %s view-period setting must be configurable, persisted and exposed.', $property)
    );
}
assertVisualization(
    preg_match('/"type": "ExpansionPanel",\s*"caption": "View periods"/', $formSource) === 1
        && preg_match('/"type": "PopupButton",\s*"caption": "View periods"/', $formSource) === 0
        && str_contains($script, "viewPeriod('agenda')")
        && str_contains($script, "viewPeriod('list')")
        && str_contains($script, "viewPeriod('threeDays')")
        && str_contains($script, "viewPeriod('week')")
        && str_contains($script, "viewPeriod('month')")
        && str_contains($script, 'function viewPeriod(view)'),
    'View periods must use a collapsible section and every visualization view must use its independently configured display period.'
);
assertVisualization(
    str_contains($moduleSource, "'instanceId'           => \$this->InstanceID")
        && str_contains($script, 'const calendarViewStateStorageKey = Number(calendarOptions.instanceId) > 0')
        && str_contains($script, 'restoreClientViewState(calendarState.settings.defaultView')
        && str_contains($script, 'window.localStorage.getItem(calendarViewStateStorageKey)')
        && str_contains($script, 'window.localStorage.setItem(calendarViewStateStorageKey, value)')
        && str_contains($script, 'function readWindowNameViewState()')
        && str_contains($script, 'function writeWindowNameViewState(value)')
        && str_contains($script, 'cursorDate: formatStoredViewDate(cursorDate)')
        && str_contains($script, 'visibleCalendarIds: visibleCalendarIds instanceof Set')
        && str_contains($script, 'Array.isArray(storedState.visibleCalendarIds)')
        && str_contains($script, 'persistClientViewState();'),
    'The selected view, cursor date and temporary calendar filter must persist per visualization instance on the client across page reloads.'
);

assertVisualization(
    str_contains($indexSource, 'id="calendar-filter-dialog"')
        && str_contains($indexSource, 'id="calendar-filter-button"')
        && str_contains($script, 'function openCalendarFilter()')
        && str_contains($script, 'function applyCalendarFilter()')
        && str_contains($script, 'function visibleCalendarEvents()')
        && str_contains($script, 'visibleCalendarEvents().filter(event => eventOverlaps(event, rangeStart, rangeEnd))')
        && str_contains($script, 'visibleCalendarEvents().filter(event => eventOverlaps(event, day, dayEnd))')
        && str_contains($script, 'visibleCalendarIds.has(Number(event.calendarInstanceId))'),
    'The shared visualization must provide a client-side calendar filter and apply it consistently to all rendered events.'
);

assertVisualization(
    str_contains($indexSource, 'id="event-dialog" class="oc-dialog oc-dialog-large"')
        && str_contains($indexSource, 'id="event-form" class="dialog-layout"')
        && str_contains($indexSource, 'id="event-details-dialog" class="oc-dialog oc-dialog-extra-large event-details-dialog"')
        && str_contains($indexSource, 'id="edit-scope-dialog" class="oc-dialog oc-dialog-small edit-scope-dialog"')
        && str_contains($indexSource, 'id="delete-confirm-dialog" class="oc-dialog oc-dialog-small delete-confirm-dialog"')
        && str_contains($indexSource, 'id="day-events-dialog" class="oc-dialog oc-dialog-large day-events-dialog"')
        && str_contains($indexSource, 'id="view-selector-dialog" class="oc-dialog oc-dialog-small view-selector-dialog"')
        && str_contains($indexSource, 'id="calendar-filter-dialog" class="oc-dialog oc-dialog-medium calendar-filter-dialog"')
        && substr_count($indexSource, 'class="icon-button dialog-close-button"') === 7
        && substr_count($indexSource, 'class="dialog-actions-end"') === 7,
    'All calendar dialogs must use the shared OpenCalendar modal structure and action layout.'
);

assertVisualization(
    str_contains($indexSource, 'id="view-selector-button"')
        && str_contains($indexSource, 'id="view-selector-options"')
        && substr_count($indexSource, 'class="view-selector-option"') === 5
        && substr_count($indexSource, 'class="view-selector-option-period"') === 5
        && str_contains($indexSource, 'data-view="threeDays"')
        && str_contains($indexSource, '<span class="view-selector-option-label">Days</span>')
        && str_contains($script, "threeDays: 'Days'")
        && str_contains($script, 'function formatViewPeriod(view)')
        && str_contains($script, "agenda: ['Day', 'Days']")
        && str_contains($script, "week: ['Week', 'Weeks']")
        && str_contains($script, "month: ['Month', 'Months']")
        && str_contains($script, "button.querySelector('.view-selector-option-period')")
        && !str_contains($formSource, '"caption": "3 Days"')
        && substr_count($formSource, '"caption": "Days"') >= 3
        && str_contains($moduleSource, "'Weeks'")
        && str_contains($moduleSource, "'Months'")
        && str_contains($script, 'function openViewSelector()')
        && str_contains($script, 'document.querySelectorAll(\'.view-selector-option\')')
        && str_contains($script, 'viewSelectorDialog.showModal()')
        && str_contains($script, 'viewSelectorDialog.close()'),
    'The calendar view switcher must show all five views with their configured periods and use a generic days label for the configurable multi-day view.'
);

assertVisualization(
    str_contains($indexSource, 'id="delete-confirm-summary"')
        && str_contains($indexSource, 'id="delete-confirm-period"')
        && str_contains($indexSource, 'id="delete-confirm-question"')
        && str_contains($script, 'function requestDelete(sourceDialog)')
        && str_contains($script, 'function confirmDeleteEvent()')
        && str_contains($script, 'requestDelete(eventDialog)')
        && str_contains($script, 'requestDelete(eventDetailsDialog)')
        && str_contains($script, 'deleteConfirmButton.addEventListener(\'click\', confirmDeleteEvent)')
        && str_contains($indexSource, 'id="delete-scope"')
        && str_contains($indexSource, 'value="occurrence" checked')
        && str_contains($indexSource, 'value="series"')
        && str_contains($script, 'function updateDeleteScope(event)')
        && str_contains($script, 'function selectedDeleteScope(event)')
        && str_contains($script, 'Boolean(event.canDeleteSeries)')
        && str_contains($indexSource, 'id="delete-scope-series-option"')
        && str_contains($script, 'recurrencePayload(event, selectedDeleteScope(event))')
        && !str_contains($script, 'confirm(')
        && str_contains($moduleSource, '\'Delete event\'')
        && str_contains($moduleSource, '\'Do you really want to delete this event?\'')
        && !str_contains($moduleSource, '\'Delete this event?\''),
    'Deleting an event must use the styled OpenCalendar confirmation modal from both details and edit dialogs instead of the native browser confirmation.'
);

assertVisualization(
    str_contains($indexSource, 'id="day-events-count"')
        && str_contains($indexSource, 'id="day-events-create-button"')
        && str_contains($script, 'const visibleEvents = [...events].sort(compareEventsForDisplay);')
        && str_contains($script, 'function openNewEvent(preferredDay = null)')
        && str_contains($script, 'if (day) openNewEvent(day);')
        && str_contains($script, 'calendarState.calendars.some(calendar => calendar.canWrite)')
        && str_contains($moduleSource, '\'Create event on this day\'')
        && str_contains($moduleSource, '\'New event\'')
        && str_contains($moduleSource, '\'Edit event\'')
        && str_contains($moduleSource, '\'Edit\'')
        && str_contains($moduleSource, '\'Filter calendars\'')
        && str_contains($moduleSource, '\'Select all\''),
    'The month day overview must sort its events, show the event count and allow creating an event directly for the selected day when a writable calendar is available.'
);

assertVisualization(
    str_contains($script, "activeView === 'list'")
        && str_contains($script, "list: 'List'")
        && str_contains($formSource, '"caption": "List"')
        && str_contains($formSource, '"value": 4'),
    'The shared client and default-view selector must provide the list view.'
);

$style = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/style.css');
assertVisualization(
    !str_contains($style, '.delete-confirm-dialog { font-size:')
        && !str_contains($style, 'html.ipsview-mode .delete-confirm-dialog { font-size:'),
    'The delete confirmation dialog must use the shared OpenCalendar dialog typography instead of a dialog-specific font size.'
);

assertVisualization(
    str_contains($style, '.form-row select option,')
        && str_contains($style, 'background: var(--cal-dialog);')
        && str_contains($style, '.form-row select option:checked')
        && str_contains($style, 'background: var(--cal-surface-hover);'),
    'Native select options in event dialogs must use the active OpenCalendar theme instead of the browser default popup colors.'
);
assertVisualization(
    str_contains($style, '--cal-view-background: var(--ipsview-role-view-background);')
        && str_contains($style, '--cal-page-background: var(--ipsview-role-page-background);')
        && str_contains($style, '--cal-text: var(--ipsview-role-text-primary);')
        && str_contains($style, '--cal-text-active: var(--ipsview-role-text-active);')
        && str_contains($style, '--cal-text-inactive: var(--ipsview-role-text-inactive);')
        && str_contains($style, '--cal-label-text: var(--ipsview-role-text-label);')
        && str_contains($style, '--cal-muted: var(--ipsview-role-text-inactive);')
        && str_contains($style, '--cal-faint: var(--ipsview-role-text-faint);')
        && str_contains($style, '--cal-icon: var(--ipsview-role-icon);'),
    'The calendar stylesheet must map every text, icon and page role to the configurable IPSView roles, including inactive text for muted content.'
);
assertVisualization(
    str_contains($style, '--cal-dialog-small-width: 360px;')
        && str_contains($style, '--cal-dialog-medium-width: 440px;')
        && str_contains($style, '--cal-dialog-large-width: 560px;')
        && str_contains($style, '--cal-dialog-extra-large-width: 640px;')
        && str_contains($style, '--cal-dialog-small-width: 420px;')
        && str_contains($style, '--cal-dialog-medium-width: 540px;')
        && str_contains($style, '--cal-dialog-large-width: 680px;')
        && str_contains($style, '--cal-dialog-extra-large-width: 760px;')
        && str_contains($style, '.oc-dialog-extra-large { --dialog-width: var(--cal-dialog-extra-large-width); }')
        && str_contains($style, '.oc-dialog[open] { display: flex; flex-direction: column; }')
        && str_contains($style, '.oc-dialog > .dialog-layout {')
        && str_contains($style, 'flex: 1 1 auto;')
        && str_contains($style, 'scrollbar-gutter: stable;')
        && str_contains($style, '.dialog-actions-start, .dialog-actions-end {')
        && str_contains($style, '.event-details-dialog .dialog-actions-start,')
        && str_contains($style, '.event-details-dialog .dialog-actions-end { flex-wrap: nowrap; }')
        && str_contains($style, '.event-details-dialog .dialog-actions button { flex: 0 0 auto; white-space: nowrap; }')
        && str_contains($style, '.dialog-close-button {')
        && str_contains($style, '.delete-scope,')
        && str_contains($style, '.edit-scope { display: grid;')
        && str_contains($style, '.delete-scope-option:hover,')
        && str_contains($style, '.edit-scope-option:hover { background: var(--cal-surface-hover); }')
        && str_contains($style, '@media (max-width: 420px) {'),
    'All OpenCalendar modals must share responsive size classes, fixed header/footer layout and a scrollable content area.'
);

assertVisualization(
    str_contains($style, '.agenda-week-separator {')
        && str_contains($style, '.month-week-number {')
        && str_contains($style, '.month-day-of-year {'),
    'Calendar-week separators and month day-of-year metadata must have dedicated visualization styles.'
);
assertVisualization(
    !str_contains($style, '--month-row-height:')
        && str_contains($style, 'grid-template-rows: auto repeat(6, minmax(82px, 1fr));')
        && str_contains($style, '.month-section:only-child {')
        && str_contains($style, 'height: 100%;')
        && str_contains($style, '.month-section:only-child .calendar-grid {')
        && str_contains($style, 'grid-template-rows: auto repeat(6, minmax(0, 1fr));')
        && str_contains($style, '.month-events { flex: 1 1 auto; min-height: 0; overflow: hidden; }'),
    'A single-month view must distribute all six calendar week rows evenly across the available height while multi-month views retain a usable minimum row height.'
);
assertVisualization(
    str_contains($style, '.list-table {')
        && str_contains($style, '.list-color-column {')
        && str_contains($style, '.list-row:hover,')
        && str_contains($style, '.view-selector-options { display: grid;')
        && str_contains($style, '--cal-dialog-font-size: clamp(0.98rem, 0.94rem + 0.12vw, 1.04rem);')
        && str_contains($style, '--cal-dialog-font-size: clamp(1rem, 0.96rem + 0.14vw, 1.06rem);')
        && str_contains($style, '--cal-dialog-label-font-size: .78em;')
        && str_contains($style, '--cal-dialog-meta-font-size: .82em;')
        && str_contains($style, 'font-size: var(--cal-dialog-font-size);')
        && str_contains($style, 'font-size: var(--cal-dialog-label-font-size);')
        && str_contains($style, 'font-size: var(--cal-dialog-meta-font-size);')
        && str_contains($style, 'min-width: 0;')
        && str_contains($style, 'max-width: 100%;')
        && !str_contains($style, '--cal-view-selector-font-size:')
        && !str_contains($style, '.view-selector-dialog { font-size:')
        && str_contains($style, 'html.ipsview-mode .view-selector-option { min-height: 48px; padding: 10px 13px; }'),
    'All dialogs must share responsive typography while the list view remains available through the readable view selector in native and IPSView modes.'
);

assertVisualization(
    preg_match('/html, body\s*\{[^}]*background:\s*var\(--cal-view-background\);/s', $style) === 1
        && preg_match('/#calendar-app\s*\{[^}]*background:\s*var\(--cal-view-background\);/s', $style) === 1
        && !preg_match('/#calendar-app\s*\{[^}]*background:\s*var\(--cal-page-background\);/s', $style)
        && str_contains($style, '--cal-card: var(--ipsview-role-page-background);')
        && str_contains($style, 'color: var(--cal-text-active, var(--cal-text));')
        && str_contains($style, 'color: var(--cal-text-inactive);')
        && str_contains($style, 'color: var(--cal-label-text);')
        && str_contains($style, 'color: var(--cal-icon);'),
    'The calendar viewport must use the view background so transparency works, while inner pages consume the page background role.'
);

assertVisualization(
    str_contains($script, 'const showAddButton = actionBridgeAvailable && listControlsVisible() && activeView !== \'month\';')
        && str_contains($script, 'addButton.classList.toggle(\'visible\', showAddButton);'),
    'The floating event creation button must stay available in non-month views and remain hidden in the month view.'
);

assertVisualization(
    !str_contains($style, 'html.ipsview-mode #add-button { display: none !important; }')
        && str_contains($style, 'html.ipsview-mode .floating-add {')
        && str_contains($style, 'height: 46px;')
        && str_contains($style, 'padding: 0 11px;')
        && str_contains($style, 'box-shadow: none;')
        && str_contains($style, 'width: 48px;')
        && str_contains($style, 'html.ipsview-mode .floating-add-label { display: inline; }'),
    'IPSView must expose a compact labelled creation button with a touch-sized control and a round narrow-screen fallback.'
);

$renderer = new CalendarVisualizationRenderer();
$native = $renderer->render(false);
$ipsView = $renderer->render(true);

assertVisualization(str_contains($native, 'style="font-size: 100%;"'), 'The native renderer must apply the configured font scale to the document root.');
assertVisualization(str_contains($ipsView, 'style="font-size: 18px;"'), 'IPSView must keep its independently resolved root font size.');

foreach ([$native, $ipsView] as $html) {
    assertVisualization(str_starts_with($html, '<!DOCTYPE html>'), 'The rendered page must be a complete HTML document.');
    assertVisualization(!preg_match('/\{\{[A-Z][A-Z0-9_]*\}\}/', $html), 'No visualization placeholder may remain unresolved.');
    assertVisualization(str_contains($html, 'window.SYMC_VISUALIZATION = '), 'The shared bootstrap object must be embedded.');
    assertVisualization(str_contains($html, 'contractVersion'), 'The bootstrap contract version must be embedded.');
    assertVisualization(str_contains($html, '"showAgendaEventCount":false'), 'The agenda event-count setting must be serialized.');
    assertVisualization(str_contains($html, '"showThreeDaysEventCount":true'), 'The three-day event-count setting must be serialized.');
    assertVisualization(str_contains($html, '"showWeekEventCount":false'), 'The week event-count setting must be serialized.');
    assertVisualization(str_contains($html, '"showAgendaCalendarWeek":true'), 'The agenda calendar-week setting must be serialized.');
    assertVisualization(str_contains($html, '"showThreeDaysCalendarWeek":false'), 'The three-day calendar-week setting must be serialized.');
    assertVisualization(str_contains($html, '"showWeekCalendarWeek":true'), 'The week calendar-week setting must be serialized.');
    assertVisualization(str_contains($html, '"showMonthCalendarWeek":true'), 'The month calendar-week setting must be serialized.');
    assertVisualization(str_contains($html, '"showAgendaDayOfYear":false'), 'The agenda day-of-year setting must be serialized.');
    assertVisualization(str_contains($html, '"showThreeDaysDayOfYear":true'), 'The three-day day-of-year setting must be serialized.');
    assertVisualization(str_contains($html, '"showWeekDayOfYear":false'), 'The week day-of-year setting must be serialized.');
    assertVisualization(str_contains($html, '"showMonthDayOfYear":true'), 'The month day-of-year setting must be serialized.');
    assertVisualization(str_contains($html, '"defaultView":"list"'), 'The list view must be serializable as the default view.');
    assertVisualization(str_contains($html, '"listPeriodDays":21'), 'The list period must be serialized.');
    assertVisualization(str_contains($html, '"threeDaysPeriodDays":5'), 'The multi-day period must be serialized.');
    assertVisualization(str_contains($html, '"weekPeriodWeeks":2'), 'The week period must be serialized.');
    assertVisualization(str_contains($html, '"monthPeriodMonths":2'), 'The month period must be serialized.');
    assertVisualization(str_contains($html, '"showListCalendarWeek":true'), 'The list calendar-week setting must be serialized.');
    assertVisualization(str_contains($html, '"showListDayOfYear":true'), 'The list day-of-year setting must be serialized.');
    assertVisualization(str_contains($html, '"showListDescription":true'), 'The list column settings must be serialized.');
    assertVisualization(str_contains($html, '"showListControls":false'), 'The list controls setting must be serialized.');
    assertVisualization(str_contains($html, 'calendarVisualization.state'), 'The calendar script must consume the shared state contract.');
    assertVisualization(
        str_contains($html, "calendarIPSViewRequest('GetState', null)"),
        'IPSView must refresh the embedded calendar state from its authenticated action bridge on page load.'
    );
    assertVisualization(str_contains($html, 'id="add-button-label"'), 'The event creation control must expose a visible text label for touch users.');

    assertVisualization(
        str_contains($html, 'id="calendar-filter-dialog"')
            && str_contains($html, 'id="calendar-filter-options"')
            && str_contains($html, 'id="calendar-filter-apply"'),
        'The rendered visualization must include the temporary calendar-filter modal.'
    );
    assertVisualization(
        str_contains($html, 'id="day-events-dialog"')
            && str_contains($html, 'id="day-events-list"'),
        'The rendered visualization must include the day-events modal markup.'
    );
    assertVisualization(
        str_contains($html, 'id="delete-confirm-dialog"')
            && str_contains($html, 'id="delete-confirm-button"'),
        'The rendered visualization must include the styled delete-confirmation modal.'
    );
    assertVisualization(
        str_contains($html, 'id="event-calendar-options" role="listbox"')
            && str_contains($html, 'class="calendar-picker-trigger"')
            && !str_contains($html, '<select id="event-calendar"'),
        'Calendar selection must use an in-document listbox instead of the unreliable native WebView2 select popup.'
    );
    assertVisualization(
        str_contains($html, 'function handleCalendarOptionKeydown(event)')
            && str_contains($html, "event.key === 'ArrowDown'")
            && str_contains($html, "event.key === 'Escape'"),
        'The custom calendar picker must provide keyboard navigation and escape handling.'
    );
    assertVisualization(str_contains($html, 't(\'New event\')'), 'The visible creation label must use the compact translation while title and aria-label remain descriptive.');
    assertVisualization(str_contains($html, 'addButton.disabled = !hasWritableCalendar'), 'The creation control must stay visible and communicate unavailable write access by disabling itself.');
    assertVisualization(str_contains($html, "calendarVisualization.mode === 'symcon'"), 'Native action availability must be derived from the explicit visualization mode.');
    assertVisualization(str_contains($html, 'waitForNativeActionBridge'), 'Native actions must tolerate delayed HTML-SDK bridge injection.');
    assertVisualization(str_contains($html, '--agenda-color-bar-width'), 'Calendar-specific options must remain available through the shared bootstrap.');
}

assertVisualization(str_contains($native, '--symc-accent: #123456'), 'The native page must include the Symcon visualization theme.');
assertVisualization(!str_contains($native, '--ipsview-accent: #654321'), 'The native page must not include the IPSView style.');
assertVisualization(!str_contains($native, 'class="ipsview-mode"'), 'The native page must not use the IPSView class.');
assertVisualization(str_contains($ipsView, '--ipsview-accent: #654321'), 'The IPSView page must include the selected IPSView style.');
assertVisualization(!str_contains($ipsView, '--symc-accent: #123456'), 'The IPSView page must not include the native visualization theme.');
assertVisualization(str_contains($ipsView, 'class="ipsview-mode"'), 'The IPSView page must expose its shared mode class.');
assertVisualization(str_contains($ipsView, '"mode":"ipsview"'), 'The IPSView bootstrap mode must be explicit.');
assertVisualization(
    str_contains($ipsView, '"endpoint":"/hook/opencalendar/view/12345"')
        && str_contains($ipsView, '"token":"0123456789abcdef0123456789abcdef"'),
    'The IPSView bootstrap must include its authenticated action bridge.'
);
assertVisualization(!str_contains($native, 'opencalendar/view/12345'), 'The native tile must not expose IPSView credentials.');
assertVisualization(str_contains($native, '"mode":"symcon"'), 'The native bootstrap mode must be explicit.');

fwrite(STDOUT, "Calendar visualization contract checks passed.\n");
