'use strict';

const calendarVisualization = window.SYMC_VISUALIZATION && typeof window.SYMC_VISUALIZATION === 'object'
    ? window.SYMC_VISUALIZATION
    : {};
const calendarTranslations = calendarVisualization.translations && typeof calendarVisualization.translations === 'object'
    ? calendarVisualization.translations
    : {};
const calendarOptions = calendarVisualization.options && typeof calendarVisualization.options === 'object'
    ? calendarVisualization.options
    : {};
const calendarRuntime = calendarVisualization.runtime && typeof calendarVisualization.runtime === 'object'
    ? calendarVisualization.runtime
    : null;
const calendarIPSViewConfig = calendarVisualization.mode === 'ipsview' ? calendarRuntime : null;
const calendarAgendaColorBarWidth = Math.max(2, Math.min(16, Number(calendarOptions.agendaColorBarWidth) || 5));
const calendarCompactColorBarWidth = Math.max(2, Math.min(16, Number(calendarOptions.compactColorBarWidth) || 3));
const calendarViewStateStorageKey = Number(calendarOptions.instanceId) > 0
    ? `OpenCalendar.ViewState.${Number(calendarOptions.instanceId)}`
    : '';
const calendarViews = new Set(['agenda', 'list', 'threeDays', 'week', 'month']);
const calendarViewLabels = { agenda: 'Agenda', list: 'List', threeDays: 'Days', week: 'Week', month: 'Month' };
const swipeNavigationViews = new Set(['threeDays', 'week', 'month']);
const swipeMinimumDistance = 60;
const swipeAxisRatio = 1.3;
document.documentElement.style.setProperty('--agenda-color-bar-width', `${calendarAgendaColorBarWidth}px`);
document.documentElement.style.setProperty('--compact-color-bar-width', `${calendarCompactColorBarWidth}px`);

let calendarState = { events: [], calendars: [], settings: {} };
let activeView = 'agenda';
let cursorDate = startOfDay(new Date());
let initialized = false;
let selectedEvent = null;
let editScopeSourceDialog = null;
let pendingEventEdit = null;
let pendingSeriesEdit = null;
let recurrencePatternContext = null;
let eventDialogEditable = false;
let reminderDefaultResolvedForMove = false;
let deleteSourceDialog = null;
let visibleCalendarIds = null;
let pendingCalendarFilterIds = new Set();
let toastTimer = null;
let monthLayoutFrame = null;
let selectedDayEventsDate = null;
let swipeGesture = null;
let suppressSwipeClickUntil = 0;
let deferredCalendarState = null;
let importedIcsTimezone = '';
const monthEventData = new WeakMap();

const content = document.getElementById('calendar-content');
const periodTitle = document.getElementById('period-title');
const eventDialog = document.getElementById('event-dialog');
const eventDetailsDialog = document.getElementById('event-details-dialog');
const editScopeDialog = document.getElementById('edit-scope-dialog');
const editScopeConfirmButton = document.getElementById('edit-scope-confirm');
const deleteConfirmDialog = document.getElementById('delete-confirm-dialog');
const deleteConfirmButton = document.getElementById('delete-confirm-button');
const eventForm = document.getElementById('event-form');
const icsImportButton = document.getElementById('ics-import-button');
const icsImportFile = document.getElementById('ics-import-file');
const eventCalendarInput = document.getElementById('event-calendar');
const eventCalendarPicker = document.getElementById('event-calendar-picker');
const eventCalendarTrigger = document.getElementById('event-calendar-trigger');
const eventCalendarValue = document.getElementById('event-calendar-value');
const eventCalendarOptions = document.getElementById('event-calendar-options');
const eventAnniversaryType = document.getElementById('event-anniversary-type');
const eventAnniversaryDateRow = document.getElementById('event-anniversary-date-row');
const eventAnniversaryDate = document.getElementById('event-anniversary-date');
const eventAnniversaryDateLabel = document.getElementById('event-anniversary-date-label');
const eventRecurrenceRow = document.getElementById('event-recurrence-row');
const eventRecurrenceFrequency = document.getElementById('event-recurrence-frequency');
const eventRecurrenceOptions = document.getElementById('event-recurrence-options');
const eventRecurrenceInterval = document.getElementById('event-recurrence-interval');
const eventRecurrenceIntervalUnit = document.getElementById('event-recurrence-interval-unit');
const eventRecurrenceWeekdays = document.getElementById('event-recurrence-weekdays');
const eventRecurrenceEndMode = document.getElementById('event-recurrence-end-mode');
const eventRecurrenceCountRow = document.getElementById('event-recurrence-count-row');
const eventRecurrenceCount = document.getElementById('event-recurrence-count');
const eventRecurrenceUntilRow = document.getElementById('event-recurrence-until-row');
const eventRecurrenceUntil = document.getElementById('event-recurrence-until');
const eventReminderMode = document.getElementById('event-reminder-mode');
const eventReminderCustomRow = document.getElementById('event-reminder-custom-row');
const eventReminderValue = document.getElementById('event-reminder-value');
const eventReminderUnit = document.getElementById('event-reminder-unit');
const eventReminderExtraList = document.getElementById('event-reminder-extra-list');
const eventReminderAddRow = document.getElementById('event-reminder-add-row');
const eventReminderAddButton = document.getElementById('event-reminder-add-button');
const dayEventsDialog = document.getElementById('day-events-dialog');
const viewSelectorDialog = document.getElementById('view-selector-dialog');
const viewSelectorButton = document.getElementById('view-selector-button');
const viewSelectorLabel = document.getElementById('view-selector-label');
const calendarFilterDialog = document.getElementById('calendar-filter-dialog');
const calendarFilterOptions = document.getElementById('calendar-filter-options');
const monthResizeObserver = typeof ResizeObserver === 'function'
    ? new ResizeObserver(() => {
        if (activeView === 'month') scheduleMonthEventLayout();
    })
    : null;
monthResizeObserver?.observe(content);

applySymconColorScheme();

function handleMessage(data) {
    let message = data;
    if (typeof message === 'string') {
        try { message = JSON.parse(message); } catch (error) { return; }
    }
    if (!message || typeof message !== 'object') return;
    if (message.toast && typeof message.toast === 'object') {
        if (message.toast.level === 'error') {
            pendingEventEdit = null;
            pendingSeriesEdit = null;
        }
        showToast(t(message.toast.message || ''), message.toast.level || 'success');
    }
    if (message.type === 'toast') {
        if (message.level === 'error') {
            pendingEventEdit = null;
            pendingSeriesEdit = null;
        }
        showToast(t(message.message || ''), message.level || 'success');
        return;
    }
    if (message.type !== 'state' || !message.payload) return;
    if (eventDialog.open) {
        deferredCalendarState = message.payload;
        return;
    }
    applyCalendarState(message.payload);
}

function applyCalendarState(state) {
    const agendaScrollPosition = initialized ? captureAgendaScrollPosition() : null;
    calendarState = state;
    const eventEdit = calendarState.eventEdit && typeof calendarState.eventEdit === 'object'
        ? calendarState.eventEdit
        : null;
    const seriesEdit = calendarState.seriesEdit && typeof calendarState.seriesEdit === 'object'
        ? calendarState.seriesEdit
        : null;
    delete calendarState.eventEdit;
    delete calendarState.seriesEdit;
    calendarState.events = Array.isArray(calendarState.events) ? calendarState.events : [];
    calendarState.calendars = Array.isArray(calendarState.calendars) ? calendarState.calendars : [];
    calendarState.settings = calendarState.settings || {};
    normalizeVisibleCalendarIds();
    applyTileFontScale();
    if (!initialized) {
        restoreClientViewState(calendarState.settings.defaultView || 'agenda');
        applyStaticTranslations();
        initialized = true;
    }
    render();
    restoreAgendaScrollPosition(agendaScrollPosition);
    if (eventEdit && pendingEventEdit
        && Number(eventEdit.calendarInstanceId) === pendingEventEdit.calendarInstanceId
        && (pendingEventEdit.eventReference === ''
            || String(eventEdit.eventReference || '') === pendingEventEdit.eventReference)
        && (pendingEventEdit.occurrenceId === ''
            || String(eventEdit.occurrenceId || '') === pendingEventEdit.occurrenceId)) {
        pendingEventEdit = null;
        openExistingEvent(eventEdit, 'occurrence');
    }
    if (seriesEdit && pendingSeriesEdit
        && Number(seriesEdit.calendarInstanceId) === pendingSeriesEdit.calendarInstanceId
        && String(seriesEdit.seriesId || '') === pendingSeriesEdit.seriesId) {
        const writeScope = pendingSeriesEdit.writeScope === 'following' ? 'following' : 'series';
        pendingSeriesEdit = null;
        openExistingEvent(seriesEdit, writeScope);
    }
}

function applyDeferredCalendarState() {
    if (!deferredCalendarState || eventDialog.open) return;
    const state = deferredCalendarState;
    deferredCalendarState = null;
    applyCalendarState(state);
}

function captureAgendaScrollPosition() {
    if (activeView !== 'agenda' || content.scrollTop <= 0) return null;

    const viewport = content.getBoundingClientRect();
    const eventAnchor = firstVisibleAgendaElement('.event-card[data-agenda-anchor]', viewport);
    const dayAnchor = firstVisibleAgendaElement('.agenda-day[data-agenda-date]', viewport);

    return {
        cursorDate: dayKey(cursorDate),
        scrollTop: content.scrollTop,
        eventKey: eventAnchor?.dataset.agendaAnchor || '',
        eventOffset: eventAnchor ? eventAnchor.getBoundingClientRect().top - viewport.top : 0,
        dayKey: dayAnchor?.dataset.agendaDate || '',
        dayOffset: dayAnchor ? dayAnchor.getBoundingClientRect().top - viewport.top : 0
    };
}

function firstVisibleAgendaElement(selector, viewport) {
    const elements = Array.from(content.querySelectorAll(selector));
    return elements.find(entry => {
        const rect = entry.getBoundingClientRect();
        return rect.bottom > viewport.top && rect.top < viewport.bottom;
    }) || elements.find(entry => entry.getBoundingClientRect().top >= viewport.top) || null;
}

function restoreAgendaScrollPosition(position) {
    if (!position || activeView !== 'agenda' || position.cursorDate !== dayKey(cursorDate)) return;

    const restore = () => {
        if (activeView !== 'agenda' || position.cursorDate !== dayKey(cursorDate)) return;

        let anchor = null;
        let expectedOffset = 0;
        if (position.eventKey) {
            anchor = Array.from(content.querySelectorAll('.event-card[data-agenda-anchor]'))
                .find(entry => entry.dataset.agendaAnchor === position.eventKey) || null;
            expectedOffset = position.eventOffset;
        }
        if (!anchor && position.dayKey) {
            anchor = Array.from(content.querySelectorAll('.agenda-day[data-agenda-date]'))
                .find(entry => entry.dataset.agendaDate === position.dayKey) || null;
            expectedOffset = position.dayOffset;
        }

        if (anchor) {
            const currentOffset = anchor.getBoundingClientRect().top - content.getBoundingClientRect().top;
            content.scrollTop += currentOffset - expectedOffset;
            return;
        }

        content.scrollTop = Math.min(
            position.scrollTop,
            Math.max(0, content.scrollHeight - content.clientHeight)
        );
    };

    if (typeof requestAnimationFrame === 'function') {
        requestAnimationFrame(restore);
    } else {
        restore();
    }
}

function applyTileFontScale() {
    if (calendarVisualization.mode === 'ipsview') return;
    const scale = Math.max(50, Math.min(200, Number(calendarState.settings.tileFontScale) || 100));
    document.documentElement.style.fontSize = `${scale}%`;
}

function render() {
    updateToolbar();
    content.style.touchAction = swipeNavigationViews.has(activeView) ? 'pan-y' : 'auto';
    content.replaceChildren();
    if (calendarState.calendars.length === 0) {
        renderEmpty('No calendars selected', 'Select at least one calendar in the instance configuration.');
    } else if (visibleCalendarCount() === 0) {
        renderEmpty('No calendars visible', 'Select one or more calendars in the calendar filter.');
    } else if (activeView === 'month') {
        renderMonth();
    } else if (activeView === 'week') {
        renderWeek();
    } else if (activeView === 'threeDays') {
        renderThreeDays();
    } else if (activeView === 'list') {
        renderList();
    } else {
        renderAgenda();
    }
    const actionBridgeAvailable = hasActionBridge();
    const hasWritableCalendar = actionBridgeAvailable
        && calendarState.calendars.some(calendar => calendar.canWrite);
    const addButton = document.getElementById('add-button');
    const showAddButton = actionBridgeAvailable && listControlsVisible() && activeView !== 'month';
    addButton.classList.toggle('visible', showAddButton);
    addButton.disabled = !hasWritableCalendar;
    addButton.setAttribute('aria-disabled', String(!hasWritableCalendar));
    const addButtonText = hasWritableCalendar ? 'Create event' : 'No writable calendar available';
    addButton.title = t(addButtonText);
    addButton.setAttribute('aria-label', t(addButtonText));
    updateCalendarFilterButton();
    if (activeView === 'month') scheduleMonthEventLayout();
}

function listControlsVisible() {
    return activeView !== 'list' || calendarState.settings.showListControls !== false;
}

function updateViewSelectorOptions() {
    document.querySelectorAll('.view-selector-option').forEach(button => {
        const view = button.dataset.view;
        const selected = view === activeView;
        const label = t(calendarViewLabels[view] || view);
        const period = formatViewPeriod(view);
        const labelElement = button.querySelector('.view-selector-option-label');
        const periodElement = button.querySelector('.view-selector-option-period');
        button.classList.toggle('active', selected);
        button.setAttribute('aria-checked', String(selected));
        button.setAttribute('aria-label', `${label}, ${period}`);
        if (labelElement) labelElement.textContent = label;
        if (periodElement) periodElement.textContent = period;
    });
}

function openViewSelector() {
    updateViewSelectorOptions();
    if (!viewSelectorDialog.open) {
        viewSelectorButton.setAttribute('aria-expanded', 'true');
        viewSelectorDialog.showModal();
    }
}

function updateToolbar() {
    const showControls = listControlsVisible();
    document.getElementById('previous-button').parentElement.classList.toggle('hidden', !showControls);
    document.getElementById('refresh-button').classList.toggle('hidden', !showControls);
    const activeViewLabel = t(calendarViewLabels[activeView] || activeView);
    viewSelectorLabel.textContent = activeViewLabel;
    viewSelectorButton.title = `${t('View')}: ${activeViewLabel}`;
    viewSelectorButton.setAttribute('aria-label', `${t('View')}: ${activeViewLabel}`);
    updateViewSelectorOptions();
    [
        ['previous-button', 'Previous'],
        ['today-button', 'Today'],
        ['next-button', 'Next'],
        ['refresh-button', 'Refresh'],
        ['calendar-filter-button', 'Filter calendars']
    ].forEach(([id, text]) => {
        const button = document.getElementById(id);
        button.title = t(text);
        button.setAttribute('aria-label', t(text));
    });

    if (activeView === 'month') {
        const monthCount = viewPeriod('month');
        const start = new Date(cursorDate.getFullYear(), cursorDate.getMonth(), 1);
        const end = new Date(start.getFullYear(), start.getMonth() + monthCount - 1, 1);
        periodTitle.textContent = monthCount === 1
            ? formatMonth(start)
            : `${formatMonth(start)} – ${formatMonth(end)}`;
    } else if (activeView === 'week') {
        const start = startOfWeek(cursorDate);
        const end = addDays(start, (viewPeriod('week') * 7) - 1);
        const calendarWeek = calendarState.settings.showWeekCalendarWeek !== false
            ? `${formatCalendarWeekLabel(daysBetween(start, end))} · `
            : '';
        periodTitle.textContent = calendarWeek + formatRange(start, end);
    } else if (activeView === 'threeDays') {
        const days = getVisibleDays(cursorDate, viewPeriod('threeDays'));
        const calendarWeek = calendarState.settings.showThreeDaysCalendarWeek === true
            ? `${formatCalendarWeekLabel(days)} · `
            : '';
        periodTitle.textContent = calendarWeek + formatRange(days[0], days[days.length - 1]);
    } else {
        const days = viewPeriod(activeView === 'list' ? 'list' : 'agenda');
        const end = addDays(cursorDate, days - 1);
        periodTitle.textContent = formatRange(cursorDate, end);
    }
}

function renderAgenda() {
    const rangeStart = startOfDay(cursorDate);
    const rangeEnd = addDays(rangeStart, viewPeriod('agenda'));
    const events = visibleCalendarEvents().filter(event => eventOverlaps(event, rangeStart, rangeEnd));
    const groups = new Map();
    dailyViewEntries(events, rangeStart, rangeEnd).forEach(entry => {
        const key = dayKey(entry.date);
        if (!groups.has(key)) groups.set(key, { date: startOfDay(entry.date), events: [] });
        groups.get(key).events.push(entry.event);
    });
    if (groups.size === 0) {
        renderEmpty('No events', 'There are no events in this period.');
        return;
    }
    let previousCalendarWeek = null;
    groups.forEach(group => {
        if (calendarState.settings.showAgendaCalendarWeek === true) {
            const calendarWeek = isoWeekKey(group.date);
            if (calendarWeek !== previousCalendarWeek) {
                const separator = element('div', 'agenda-week-separator');
                separator.textContent = formatCalendarWeekLabel([group.date]);
                content.appendChild(separator);
                previousCalendarWeek = calendarWeek;
            }
        }
        const section = element('section', 'agenda-day');
        section.dataset.agendaDate = dayKey(group.date);
        const heading = element('div', 'agenda-date');
        const strong = document.createElement('strong');
        strong.textContent = new Intl.DateTimeFormat(undefined, { weekday: 'long' }).format(group.date);
        const fullDate = document.createElement('span');
        fullDate.textContent = formatDayHeading(
            group.date,
            { day: '2-digit', month: 'long' },
            calendarState.settings.showAgendaDayOfYear !== false,
            group.events.length,
            calendarState.settings.showAgendaEventCount !== false
        );
        heading.append(strong, fullDate);
        section.appendChild(heading);
        group.events.forEach(event => section.appendChild(createAgendaEvent(event)));
        content.appendChild(section);
    });
}

function agendaEventAnchorKey(event) {
    const calendarInstanceId = String(Number(event?.calendarInstanceId || 0));
    const occurrenceId = String(event?.occurrenceId || '').trim();
    const eventReference = String(event?.eventReference || '').trim();
    if (occurrenceId) return `${calendarInstanceId}|occurrence:${occurrenceId}`;
    if (eventReference) return `${calendarInstanceId}|reference:${eventReference}`;

    const resourceUrl = String(event?.resourceUrl || '').trim();
    const uid = String(event?.uid || '').trim();
    const originalStart = String(event?.originalStart || '').trim();
    const start = originalStart || String(event?.startTimestamp || event?.start || '').trim();
    const summary = String(event?.summary || '').trim();
    const identity = resourceUrl
        ? `resource:${resourceUrl}`
        : (uid ? `uid:${uid}` : `fallback:${summary}`);

    return `${calendarInstanceId}|${identity}|${start}`;
}

function createAgendaEvent(event) {
    const card = element('button', 'event-card');
    card.type = 'button';
    card.dataset.agendaAnchor = agendaEventAnchorKey(event);
    card.addEventListener('click', () => openEventDetails(event));
    const color = element('span', 'event-color');
    color.style.background = safeColor(event.calendarColor);
    const time = element('span', 'event-time');
    time.textContent = event.allDay ? t('All day') : formatTime(eventStart(event)) + '\n' + formatTime(eventEnd(event));
    time.style.whiteSpace = 'pre-line';
    const main = element('span', 'event-main');
    const title = element('span', 'event-title');
    title.textContent = eventDisplaySummary(event) || t('Untitled event');
    main.appendChild(title);
    const metaParts = [];
    if (calendarState.settings.showCalendarName) metaParts.push(event.calendarName || '');
    if (calendarState.settings.showAnniversaryType !== false) metaParts.push(annualEventLabel(event));
    if (calendarState.settings.showLocation && event.location) metaParts.push('⌖ ' + event.location);
    if (metaParts.length) {
        const meta = element('span', 'event-meta');
        meta.textContent = metaParts.filter(Boolean).join(' · ');
        main.appendChild(meta);
    }
    if (calendarState.settings.showDescription && event.description) {
        const description = element('span', 'event-description');
        description.textContent = event.description;
        main.appendChild(description);
    }
    card.append(color, time, main);
    return card;
}

function renderList() {
    const rangeStart = startOfDay(cursorDate);
    const rangeEnd = addDays(rangeStart, viewPeriod('list'));
    const events = visibleCalendarEvents().filter(event => eventOverlaps(event, rangeStart, rangeEnd));
    const entries = dailyViewEntries(events, rangeStart, rangeEnd);
    if (entries.length === 0) {
        renderEmpty('No events', 'There are no events in this period.');
        return;
    }

    const columns = listColumns();
    const wrapper = element('div', 'list-view');
    const table = document.createElement('table');
    table.className = 'list-table';
    const head = document.createElement('thead');
    const headerRow = document.createElement('tr');
    const colorHeader = document.createElement('th');
    colorHeader.className = 'list-color-column';
    colorHeader.setAttribute('aria-hidden', 'true');
    headerRow.appendChild(colorHeader);
    columns.forEach(column => {
        const header = document.createElement('th');
        header.scope = 'col';
        header.textContent = t(column.label);
        headerRow.appendChild(header);
    });
    head.appendChild(headerRow);
    table.appendChild(head);

    const body = document.createElement('tbody');
    entries.forEach(entry => {
        const event = entry.event;
        const row = document.createElement('tr');
        row.className = 'list-row';
        row.tabIndex = 0;
        row.addEventListener('click', () => openEventDetails(event));
        row.addEventListener('keydown', key => { if (key.key === 'Enter') openEventDetails(event); });

        const color = document.createElement('td');
        color.className = 'list-color-column';
        color.style.setProperty('--event-color', safeColor(event.calendarColor));
        color.setAttribute('aria-hidden', 'true');
        row.appendChild(color);

        columns.forEach(column => {
            const cell = document.createElement('td');
            cell.className = `list-cell list-cell-${column.key}`;
            cell.textContent = column.value(event, entry.date);
            row.appendChild(cell);
        });
        body.appendChild(row);
    });
    table.appendChild(body);
    wrapper.appendChild(table);
    content.appendChild(wrapper);
}

function listColumns() {
    const columns = [];
    if (calendarState.settings.showListCalendarWeek === true) {
        columns.push({
            key: 'calendar-week',
            label: 'CW',
            value: (event, displayDate) => String(isoWeekNumber(displayDate || eventStart(event)))
        });
    }
    if (calendarState.settings.showListDate !== false) {
        columns.push({
            key: 'date',
            label: 'Date',
            value: (event, displayDate) => new Intl.DateTimeFormat(
                undefined,
                { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' }
            ).format(displayDate || eventStart(event))
        });
    }
    if (calendarState.settings.showListDayOfYear === true) {
        columns.push({
            key: 'day-of-year',
            label: 'Day',
            value: (event, displayDate) => {
                const date = displayDate || eventStart(event);
                return `${dayOfYear(date)}/${daysInYear(date)}`;
            }
        });
    }
    if (calendarState.settings.showListStart !== false) {
        columns.push({
            key: 'start',
            label: 'Start',
            value: event => event.allDay ? t('All day') : formatTime(eventStart(event))
        });
    }
    if (calendarState.settings.showListEnd !== false) {
        columns.push({
            key: 'end',
            label: 'End',
            value: event => event.allDay ? '' : formatTime(eventEnd(event))
        });
    }
    if (calendarState.settings.showListTitle !== false) {
        columns.push({
            key: 'title',
            label: 'Title',
            value: event => eventDisplaySummary(event) || t('Untitled event')
        });
    }
    if (calendarState.settings.showListAnniversaryType !== false) {
        columns.push({
            key: 'occasion',
            label: 'Occasion',
            value: event => annualEventLabel(event)
        });
    }
    if (calendarState.settings.showListCalendarName !== false) {
        columns.push({
            key: 'calendar',
            label: 'Calendar',
            value: event => event.calendarName || ''
        });
    }
    if (calendarState.settings.showListLocation === true) {
        columns.push({
            key: 'location',
            label: 'Location',
            value: event => event.location || ''
        });
    }
    if (calendarState.settings.showListDescription === true) {
        columns.push({
            key: 'description',
            label: 'Description',
            value: event => event.description || ''
        });
    }
    return columns;
}

function renderWeek() {
    const weekStart = startOfWeek(cursorDate);
    const periodDays = viewPeriod('week') * 7;
    const days = Array.from({ length: periodDays }, (_, index) => addDays(weekStart, index))
        .filter(day => calendarState.settings.showWeekends !== false || !isWeekend(day));
    const ipsView = document.documentElement.classList.contains('ipsview-mode');
    const vertical = (
        ipsView
            ? calendarState.settings.ipsViewWeekOrientation
            : calendarState.settings.tileWeekOrientation
    ) === 'vertical';
    renderDayColumns(
        days,
        'week-grid'
            + (calendarState.settings.showWeekends === false ? ' hide-weekends' : '')
            + (vertical ? ' vertical-week-grid' : ''),
        calendarState.settings.showWeekDayOfYear !== false,
        calendarState.settings.showWeekEventCount !== false
    );
}

function renderThreeDays() {
    const days = getVisibleDays(cursorDate, viewPeriod('threeDays'));
    const grid = renderDayColumns(
        days,
        'week-grid three-day-grid',
        calendarState.settings.showThreeDaysDayOfYear !== false,
        calendarState.settings.showThreeDaysEventCount !== false
    );
    grid.style.setProperty('--day-grid-columns', String(Math.min(days.length, 7)));
}

function renderDayColumns(days, className, showDayOfYear, showEventCount) {
    const grid = element('div', className);
    days.forEach(day => {
        const column = element('section', 'week-column' + (isToday(day) ? ' today' : ''));
        const dayEnd = addDays(day, 1);
        const events = visibleCalendarEvents().filter(event => eventOverlaps(event, day, dayEnd));
        const heading = element('div', 'week-heading');
        heading.textContent = formatDayHeading(
            day,
            { weekday: 'short', day: '2-digit', month: '2-digit' },
            showDayOfYear,
            events.length,
            showEventCount
        );
        column.appendChild(heading);
        const eventList = element('div', 'week-events');
        events.forEach(event => {
            const item = element('div', 'week-event');
            item.style.setProperty('--event-color', safeColor(event.calendarColor));
            item.tabIndex = 0;
            item.addEventListener('click', () => openEventDetails(event));
            item.addEventListener('keydown', key => { if (key.key === 'Enter') openEventDetails(event); });
            const title = document.createElement('strong');
            title.textContent = eventDisplaySummary(event) || t('Untitled event');
            const time = document.createElement('span');
            const timeParts = [event.allDay ? t('All day') : formatTime(eventStart(event))];
            if (calendarState.settings.showAnniversaryType !== false) timeParts.push(annualEventLabel(event));
            time.textContent = timeParts.filter(Boolean).join(' · ');
            item.append(title, time);
            eventList.appendChild(item);
        });
        column.appendChild(eventList);
        grid.appendChild(column);
    });
    content.appendChild(grid);
    return grid;
}

function getVisibleDays(start, count) {
    const days = [];
    let day = startOfDay(start);
    while (days.length < count) {
        if (calendarState.settings.showWeekends !== false || !isWeekend(day)) {
            days.push(day);
        }
        day = addDays(day, 1);
    }
    return days;
}

function renderMonth() {
    const monthCount = viewPeriod('month');
    for (let offset = 0; offset < monthCount; offset++) {
        const month = new Date(cursorDate.getFullYear(), cursorDate.getMonth() + offset, 1);
        const section = element('section', 'month-section');
        if (monthCount > 1) {
            const heading = element('div', 'month-section-title');
            heading.textContent = formatMonth(month);
            section.appendChild(heading);
        }
        section.appendChild(createMonthGrid(month, monthCount === 1));
        content.appendChild(section);
    }
}

function createMonthGrid(month, showOutsideDetails) {
    const first = new Date(month.getFullYear(), month.getMonth(), 1);
    const gridStart = startOfWeek(first);
    const days = Array.from({ length: 42 }, (_, index) => addDays(gridStart, index));
    const visibleDays = days.filter(day => calendarState.settings.showWeekends !== false || !isWeekend(day));
    const grid = element('div', 'calendar-grid' + (calendarState.settings.showWeekends === false ? ' hide-weekends' : ''));
    const weekdays = Array.from({ length: 7 }, (_, index) => addDays(startOfWeek(new Date()), index))
        .filter(day => calendarState.settings.showWeekends !== false || !isWeekend(day));
    weekdays.forEach(day => {
        const header = element('div', 'weekday');
        header.textContent = new Intl.DateTimeFormat(undefined, { weekday: 'short' }).format(day);
        grid.appendChild(header);
    });
    visibleDays.forEach(day => {
        const cell = element('div', 'month-day');
        const outside = day.getMonth() !== month.getMonth();
        if (outside) cell.classList.add('outside');
        if (isToday(day) && (!outside || showOutsideDetails)) cell.classList.add('today');
        const dayHeader = element('div', 'month-day-header');
        if (calendarState.settings.showMonthCalendarWeek === true && day.getDay() === 1) {
            const calendarWeek = element('span', 'month-week-number');
            calendarWeek.textContent = formatCalendarWeekLabel([day]);
            dayHeader.appendChild(calendarWeek);
        }
        if (!outside || showOutsideDetails) {
            const dateMeta = element('div', 'month-day-date');
            const number = element('div', 'day-number');
            number.textContent = String(day.getDate());
            dateMeta.appendChild(number);
            if (calendarState.settings.showMonthDayOfYear !== false) {
                const dayOfYearMeta = element('span', 'month-day-of-year');
                dayOfYearMeta.textContent = `${t('Day')} ${dayOfYear(day)}/${daysInYear(day)}`;
                dateMeta.appendChild(dayOfYearMeta);
            }
            dayHeader.appendChild(dateMeta);
        }
        cell.appendChild(dayHeader);
        if (!outside || showOutsideDetails) {
            const dayEnd = addDays(day, 1);
            const events = visibleCalendarEvents()
                .filter(event => eventOverlaps(event, day, dayEnd))
                .sort(compareEventsForDisplay);
            const eventList = element('div', 'month-events');
            cell.appendChild(eventList);
            monthEventData.set(eventList, { day, events });
            renderMonthEventPreview(eventList, day, events);
            bindMonthDayOverview(cell, day, events);
        }
        grid.appendChild(cell);
    });
    return grid;
}

function bindMonthDayOverview(cell, day, events) {
    cell.classList.add('month-day-overview-enabled');
    cell.addEventListener('click', clickEvent => {
        if (clickEvent.target.closest?.('button, a, input, select, textarea, label')) return;
        openDayEvents(day, events);
    });
}

function renderMonthEventPreview(container, day, events) {
    container.replaceChildren();
    const visibleCount = Math.min(3, events.length);
    events.slice(0, visibleCount).forEach(event => container.appendChild(createMonthEventChip(event)));
    if (events.length > visibleCount) {
        container.appendChild(createMoreEventsButton(day, events, events.length - visibleCount));
    }
}

function scheduleMonthEventLayout() {
    if (monthLayoutFrame !== null) cancelAnimationFrame(monthLayoutFrame);
    monthLayoutFrame = requestAnimationFrame(() => {
        monthLayoutFrame = null;
        document.querySelectorAll('.month-events').forEach(container => {
            const data = monthEventData.get(container);
            if (data) fitMonthEventContainer(container, data.day, data.events);
        });
    });
}

function fitMonthEventContainer(container, day, events) {
    if (container.clientHeight <= 0) return;

    container.replaceChildren();
    const visibleChips = [];

    for (const event of events) {
        const chip = createMonthEventChip(event);
        container.appendChild(chip);
        if (container.scrollHeight > container.clientHeight) {
            chip.remove();
            break;
        }
        visibleChips.push(chip);
    }

    if (visibleChips.length >= events.length) return;

    const more = createMoreEventsButton(day, events, events.length - visibleChips.length);
    container.appendChild(more);
    while (container.scrollHeight > container.clientHeight && visibleChips.length > 0) {
        visibleChips.pop().remove();
        more.textContent = '+' + (events.length - visibleChips.length) + ' ' + t('more');
    }
}

function createMonthEventChip(event) {
    const chip = element('button', 'event-chip');
    chip.type = 'button';
    chip.style.setProperty('--event-color', safeColor(event.calendarColor));
    const occasion = calendarState.settings.showAnniversaryType !== false ? annualEventLabel(event) : '';
    chip.textContent = (event.allDay ? '' : formatTime(eventStart(event)) + ' ')
        + (eventDisplaySummary(event) || t('Untitled event'))
        + (occasion ? ' · ' + occasion : '');
    chip.addEventListener('click', () => openEventDetails(event));
    return chip;
}

function createMoreEventsButton(day, events, hiddenCount) {
    const more = element('button', 'more-events');
    more.type = 'button';
    more.textContent = '+' + hiddenCount + ' ' + t('more');
    more.addEventListener('click', () => openDayEvents(day, events));
    return more;
}


function openDayEvents(day, events) {
    selectedDayEventsDate = startOfDay(day);
    document.getElementById('day-events-dialog-title').textContent = formatDayEventsTitle(day);
    const visibleEvents = [...events].sort(compareEventsForDisplay);
    const count = visibleEvents.length;
    document.getElementById('day-events-count').textContent = `${count} ${t(count === 1 ? 'Event' : 'Events')}`;

    const createButton = document.getElementById('day-events-create-button');
    const canCreate = hasActionBridge() && calendarState.calendars.some(calendar => calendar.canWrite);
    createButton.classList.toggle('hidden', !canCreate);

    const list = document.getElementById('day-events-list');
    list.replaceChildren();
    visibleEvents.forEach(event => {
        const item = element('button', 'day-event-item');
        item.type = 'button';
        item.style.setProperty('--event-color', safeColor(event.calendarColor));
        const time = element('span', 'day-event-time');
        time.textContent = event.allDay ? t('All day') : `${formatTime(eventStart(event))} – ${formatTime(eventEnd(event))}`;
        const summary = element('span', 'day-event-summary');
        summary.textContent = eventDisplaySummary(event) || t('Untitled event');
        item.append(time, summary);
        const metaParts = [];
        if (calendarState.settings.showCalendarName !== false && event.calendarName) metaParts.push(event.calendarName);
        if (calendarState.settings.showAnniversaryType !== false) metaParts.push(annualEventLabel(event));
        if (metaParts.filter(Boolean).length > 0) {
            const calendar = element('span', 'day-event-calendar');
            calendar.textContent = metaParts.filter(Boolean).join(' · ');
            item.appendChild(calendar);
        }
        item.addEventListener('click', () => {
            dayEventsDialog.close();
            openEventDetails(event);
        });
        list.appendChild(item);
    });
    dayEventsDialog.showModal();
}

function compareEventsForDisplay(left, right) {
    if (Boolean(left.allDay) !== Boolean(right.allDay)) return left.allDay ? -1 : 1;
    return eventStart(left).getTime() - eventStart(right).getTime();
}

function formatDayEventsTitle(day) {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'full' }).format(day);
}

function renderEmpty(title, description) {
    const box = element('div', 'empty-state');
    const inner = document.createElement('div');
    const symbol = element('span', 'empty-symbol');
    symbol.textContent = '◷';
    const heading = document.createElement('strong');
    heading.textContent = t(title);
    const text = document.createElement('div');
    text.style.marginTop = '5px';
    text.style.fontSize = '.78em';
    text.textContent = t(description);
    inner.append(symbol, heading, text);
    box.appendChild(inner);
    content.appendChild(box);
}

function allCalendarInstanceIds() {
    return calendarState.calendars
        .map(calendar => Number(calendar.instanceId))
        .filter(instanceId => instanceId > 0);
}

function normalizeVisibleCalendarIds() {
    if (!(visibleCalendarIds instanceof Set)) return;

    const availableIds = new Set(allCalendarInstanceIds());
    visibleCalendarIds = new Set(
        Array.from(visibleCalendarIds).filter(instanceId => availableIds.has(instanceId))
    );
    if (visibleCalendarIds.size === availableIds.size) visibleCalendarIds = null;
}

function effectiveVisibleCalendarIds() {
    return visibleCalendarIds instanceof Set
        ? new Set(visibleCalendarIds)
        : new Set(allCalendarInstanceIds());
}

function visibleCalendarCount() {
    return effectiveVisibleCalendarIds().size;
}

function calendarFilterActive() {
    return visibleCalendarIds instanceof Set;
}

function visibleCalendarEvents() {
    if (!calendarFilterActive()) return calendarState.events;
    return calendarState.events.filter(event => visibleCalendarIds.has(Number(event.calendarInstanceId)));
}

function dailyViewEntries(events, rangeStart, rangeEnd) {
    const entries = [];
    events.forEach(event => {
        if (!event.allDay) {
            const date = eventStart(event);
            entries.push({ event, date: date < rangeStart ? startOfDay(rangeStart) : date });
            return;
        }

        let day = eventStart(event);
        const end = eventEnd(event);
        if (day < rangeStart) day = startOfDay(rangeStart);
        while (day < end && day < rangeEnd) {
            entries.push({ event, date: startOfDay(day) });
            day = addDays(day, 1);
        }
    });
    entries.sort((left, right) => left.date.getTime() - right.date.getTime()
        || compareEventsForDisplay(left.event, right.event));
    return entries;
}

function updateCalendarFilterButton() {
    const button = document.getElementById('calendar-filter-button');
    const total = calendarState.calendars.length;
    const visible = visibleCalendarCount();
    const active = calendarFilterActive();
    button.disabled = total === 0;
    button.classList.toggle('filter-active', active);
    const label = active
        ? `${t('Filter calendars')} (${visible}/${total})`
        : t('Filter calendars');
    button.title = label;
    button.setAttribute('aria-label', label);
    button.setAttribute('aria-pressed', String(active));
}

function openCalendarFilter() {
    pendingCalendarFilterIds = effectiveVisibleCalendarIds();
    renderCalendarFilterOptions();
    calendarFilterDialog.showModal();
}

function renderCalendarFilterOptions() {
    calendarFilterOptions.replaceChildren();
    calendarState.calendars.forEach(calendar => {
        const instanceId = Number(calendar.instanceId);
        const option = element('label', 'calendar-filter-option');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = pendingCalendarFilterIds.has(instanceId);
        checkbox.addEventListener('change', () => {
            if (checkbox.checked) pendingCalendarFilterIds.add(instanceId);
            else pendingCalendarFilterIds.delete(instanceId);
        });
        const color = element('span', 'calendar-filter-color');
        color.style.setProperty('--event-color', safeColor(calendar.color));
        const name = element('span', 'calendar-filter-name');
        name.textContent = calendar.name || t('Calendar');
        option.append(checkbox, color, name);
        calendarFilterOptions.appendChild(option);
    });
}

function setPendingCalendarFilter(selectAll) {
    pendingCalendarFilterIds = selectAll
        ? new Set(allCalendarInstanceIds())
        : new Set();
    renderCalendarFilterOptions();
}

function applyCalendarFilter() {
    const allIds = allCalendarInstanceIds();
    visibleCalendarIds = pendingCalendarFilterIds.size === allIds.length
        ? null
        : new Set(pendingCalendarFilterIds);
    calendarFilterDialog.close();
    persistClientViewState();
    render();
}

function eventDisplaySummary(event) {
    const displaySummary = String(event?.displaySummary || '').trim();
    return displaySummary || String(event?.summary || '').trim();
}

function resetAnniversaryEditor() {
    eventAnniversaryType.value = '';
    eventAnniversaryDate.max = localDate(new Date());
    eventAnniversaryDate.value = '';
    eventAnniversaryDateRow.classList.add('hidden');
    eventAnniversaryDate.required = false;
    updateAnniversaryDateLabel();
}

function annualEventType(event) {
    const type = String(event?.anniversaryType || '').trim().toLowerCase();
    if (['birthday', 'anniversary', 'wedding', 'death'].includes(type)) return type;
    return event?.birthday ? 'birthday' : '';
}

function annualEventLabel(event) {
    return {
        birthday: t('Birthday'),
        anniversary: t('Anniversary'),
        wedding: t('Wedding anniversary'),
        death: t('Death anniversary')
    }[annualEventType(event)] || '';
}

function annualEventDate(event) {
    const date = String(event?.anniversaryDate || '').trim();
    if (date) return date;
    return event?.birthday ? String(event.birthDate || '') : '';
}

function loadAnniversaryEditor(event) {
    eventAnniversaryType.value = annualEventType(event);
    eventAnniversaryDate.max = localDate(new Date());
    eventAnniversaryDate.value = eventAnniversaryType.value ? annualEventDate(event) : '';
    eventAnniversaryDateRow.classList.toggle('hidden', !eventAnniversaryType.value);
    updateAnniversaryDateLabel();
}

function anniversaryEditorEditable() {
    const calendar = selectedCalendarEntry();
    if (!eventDialogEditable || !calendar?.canWrite) return false;
    if (selectedEvent === null) return Boolean(calendar.canCreateRecurrence);
    if (!selectedEvent.recurring) {
        const moving = Number(calendar.instanceId || 0) !== Number(selectedEvent.calendarInstanceId || 0);
        return Boolean(moving ? calendar.canCreateRecurrence : calendar.canUpdateRecurrence);
    }
    return selectedEvent.writeScope === 'series'
        && Boolean(selectedEvent.canUpdateSeries)
        && Boolean(calendar.canUpdateRecurrence);
}

function anniversaryRecurrence() {
    return { frequency: 'YEARLY', interval: 1, endMode: 'never' };
}

function updateAnniversaryDateLabel() {
    const labels = {
        birthday: 'Birth date',
        anniversary: 'Anniversary date',
        wedding: 'Wedding date',
        death: 'Date of death'
    };
    eventAnniversaryDateLabel.textContent = t(labels[eventAnniversaryType.value] || 'Anniversary date');
}

function updateAnniversaryControls() {
    let type = String(eventAnniversaryType.value || '');
    const editable = anniversaryEditorEditable();
    if (type && eventDialogEditable && !editable && !annualEventType(selectedEvent)) {
        eventAnniversaryType.value = '';
        eventAnniversaryDate.value = '';
        type = '';
    }
    eventAnniversaryType.disabled = !editable;
    eventAnniversaryDateRow.classList.toggle('hidden', !type);
    eventAnniversaryDate.disabled = !editable || !type;
    eventAnniversaryDate.required = editable && Boolean(type);
    updateAnniversaryDateLabel();

    const allDayInput = document.getElementById('event-all-day');
    if (type && editable) {
        allDayInput.checked = true;
        allDayInput.disabled = true;
        eventRecurrenceRow.classList.remove('hidden');
        eventRecurrenceFrequency.value = 'yearly';
        eventRecurrenceInterval.value = '1';
        eventRecurrenceEndMode.value = 'never';
        eventRecurrenceFrequency.disabled = true;
        eventRecurrenceOptions.classList.add('hidden');
    } else {
        allDayInput.disabled = !eventDialogEditable;
    }
}

function suggestedAnniversaryDate() {
    const eventStart = readInputDate(document.getElementById('event-start').value);
    if (!eventStart) return '';

    const today = startOfDay(new Date());
    const month = eventStart.getMonth();
    const day = eventStart.getDate();
    let year = Math.min(eventStart.getFullYear(), today.getFullYear());

    while (year >= 1) {
        const candidate = new Date(year, month, day);
        if (candidate.getFullYear() === year
            && candidate.getMonth() === month
            && candidate.getDate() === day
            && candidate <= today) {
            return localDate(candidate);
        }
        --year;
    }

    return '';
}

function syncAnniversarySchedule() {
    if (!eventAnniversaryType.value || !anniversaryEditorEditable()) return;
    const anniversaryDate = readInputDate(eventAnniversaryDate.value);
    if (!anniversaryDate) return;
    const end = addDays(anniversaryDate, 1);
    document.getElementById('event-all-day').checked = true;
    setDateInputs(anniversaryDate, end, true, true);
    eventRecurrenceFrequency.value = 'yearly';
    eventRecurrenceInterval.value = '1';
    eventRecurrenceEndMode.value = 'never';
}

function anniversaryEditorChange() {
    if (!anniversaryEditorEditable()) return null;
    const type = String(eventAnniversaryType.value || '');
    const date = type ? String(eventAnniversaryDate.value || '') : '';
    if (type && !date) return null;
    if (selectedEvent === null) {
        return type ? { enabled: true, type, date } : null;
    }
    const oldType = annualEventType(selectedEvent);
    const oldDate = oldType ? annualEventDate(selectedEvent) : '';
    if (type === oldType && (!type || date === oldDate)) return null;
    return { enabled: Boolean(type), type, date };
}

const icsImportMaximumBytes = 1024 * 1024;
const icsImportMessages = {
    de: {
        'Import ICS': 'ICS importieren',
        'ICS event imported.': 'ICS-Termin importiert.',
        'The ICS file is too large.': 'Die ICS-Datei ist zu groß.',
        'The selected file is not a valid single-event ICS file.': 'Die ausgewählte Datei ist keine gültige ICS-Datei mit einem einzelnen Termin.',
        'This ICS file contains multiple events.': 'Diese ICS-Datei enthält mehrere Termine.',
        'Recurring ICS invitations cannot be imported as a single event.': 'Wiederkehrende ICS-Einladungen können hier nicht als Einzeltermin importiert werden.'
    }
};

function icsImportText(value) {
    const translated = t(value);
    if (translated !== value) return translated;
    const language = String(document.documentElement.lang || '').toLowerCase().split('-')[0];
    return icsImportMessages[language]?.[value] || value;
}

function unfoldIcsLines(value) {
    const lines = [];
    String(value || '').replace(/\r\n?/g, '\n').split('\n').forEach(line => {
        if ((line.startsWith(' ') || line.startsWith('\t')) && lines.length > 0) {
            lines[lines.length - 1] += line.slice(1);
        } else {
            lines.push(line);
        }
    });
    return lines;
}

function parseIcsProperty(line) {
    const separator = line.indexOf(':');
    if (separator <= 0) return null;
    const definition = line.slice(0, separator).split(';');
    const propertyName = String(definition.shift() || '').split('.').pop().toUpperCase();
    if (!propertyName) return null;
    const parameters = {};
    definition.forEach(parameter => {
        const equals = parameter.indexOf('=');
        if (equals <= 0) return;
        const name = parameter.slice(0, equals).trim().toUpperCase();
        let value = parameter.slice(equals + 1).trim();
        if (value.startsWith('"') && value.endsWith('"') && value.length >= 2) {
            value = value.slice(1, -1);
        }
        parameters[name] = value;
    });
    return {
        name: propertyName,
        parameters,
        value: line.slice(separator + 1)
    };
}

function unescapeIcsText(value) {
    return String(value || '')
        .replace(/\\[nN]/g, '\n')
        .replace(/\\,/g, ',')
        .replace(/\\;/g, ';')
        .replace(/\\\\/g, '\\');
}

function collectIcsEventProperties(eventLines) {
    const properties = {};
    let nestedDepth = 0;
    for (let index = 1; index < eventLines.length - 1; index++) {
        const line = eventLines[index];
        const upperLine = line.trim().toUpperCase();
        if (upperLine.startsWith('BEGIN:')) {
            nestedDepth++;
            continue;
        }
        if (upperLine.startsWith('END:')) {
            nestedDepth = Math.max(0, nestedDepth - 1);
            continue;
        }
        if (nestedDepth > 0) continue;
        const property = parseIcsProperty(line);
        if (!property) continue;
        if (!properties[property.name]) properties[property.name] = [];
        properties[property.name].push(property);
    }
    return properties;
}

function normalizeIcsTimezone(value) {
    let timezone = String(value || '').trim();
    if (!timezone) return '';
    const mappings = {
        'GMT Standard Time': 'Europe/London',
        'W. Europe Standard Time': 'Europe/Berlin',
        'Central Europe Standard Time': 'Europe/Budapest',
        'Romance Standard Time': 'Europe/Paris',
        'Central European Standard Time': 'Europe/Warsaw',
        'GTB Standard Time': 'Europe/Bucharest',
        'FLE Standard Time': 'Europe/Kyiv',
        'Turkey Standard Time': 'Europe/Istanbul',
        'Russian Standard Time': 'Europe/Moscow',
        'Eastern Standard Time': 'America/New_York',
        'Central Standard Time': 'America/Chicago',
        'Mountain Standard Time': 'America/Denver',
        'Pacific Standard Time': 'America/Los_Angeles',
        'Tokyo Standard Time': 'Asia/Tokyo',
        'China Standard Time': 'Asia/Shanghai',
        'India Standard Time': 'Asia/Kolkata',
        'AUS Eastern Standard Time': 'Australia/Sydney',
        'New Zealand Standard Time': 'Pacific/Auckland'
    };
    timezone = mappings[timezone] || timezone;
    try {
        new Intl.DateTimeFormat('en-US', { timeZone: timezone }).format(new Date());
        return timezone;
    } catch (error) {
        const ianaMatch = timezone.match(/([A-Za-z_+-]+\/[A-Za-z0-9_+./-]+)$/);
        if (!ianaMatch) return '';
        try {
            new Intl.DateTimeFormat('en-US', { timeZone: ianaMatch[1] }).format(new Date());
            return ianaMatch[1];
        } catch (nestedError) {
            return '';
        }
    }
}

function datePartsInTimeZone(date, timezone) {
    const values = {};
    new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23'
    }).formatToParts(date).forEach(part => {
        if (part.type !== 'literal') values[part.type] = Number(part.value);
    });
    return values;
}

function dateFromIcsTimezone(parts, timezone) {
    const target = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second);
    let timestamp = target;
    for (let attempt = 0; attempt < 3; attempt++) {
        const observed = datePartsInTimeZone(new Date(timestamp), timezone);
        const observedTimestamp = Date.UTC(
            observed.year,
            observed.month - 1,
            observed.day,
            observed.hour,
            observed.minute,
            observed.second
        );
        const adjustment = target - observedTimestamp;
        timestamp += adjustment;
        if (adjustment === 0) break;
    }
    return new Date(timestamp);
}

function validIcsDateParts(parts) {
    const value = new Date(Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second));
    return value.getUTCFullYear() === parts.year
        && value.getUTCMonth() === parts.month - 1
        && value.getUTCDate() === parts.day
        && value.getUTCHours() === parts.hour
        && value.getUTCMinutes() === parts.minute
        && value.getUTCSeconds() === parts.second;
}

function parseIcsDateProperty(property) {
    if (!property) return null;
    const value = String(property.value || '').trim();
    const dateOnly = property.parameters.VALUE?.toUpperCase() === 'DATE' || /^\d{8}$/.test(value);
    if (dateOnly) {
        const match = /^(\d{4})(\d{2})(\d{2})$/.exec(value);
        if (!match) return null;
        const parts = {
            year: Number(match[1]),
            month: Number(match[2]),
            day: Number(match[3]),
            hour: 0,
            minute: 0,
            second: 0
        };
        if (!validIcsDateParts(parts)) return null;
        return {
            date: new Date(parts.year, parts.month - 1, parts.day),
            allDay: true,
            timezone: ''
        };
    }

    const match = /^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})?(Z)?$/i.exec(value);
    if (!match) return null;
    const parts = {
        year: Number(match[1]),
        month: Number(match[2]),
        day: Number(match[3]),
        hour: Number(match[4]),
        minute: Number(match[5]),
        second: Number(match[6] || 0)
    };
    if (!validIcsDateParts(parts)) return null;

    if (match[7]) {
        return {
            date: new Date(Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second)),
            allDay: false,
            timezone: 'UTC'
        };
    }

    const timezone = normalizeIcsTimezone(property.parameters.TZID || '');
    return {
        date: timezone
            ? dateFromIcsTimezone(parts, timezone)
            : new Date(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second),
        allDay: false,
        timezone
    };
}

function parseIcsDuration(value) {
    const match = /^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i.exec(String(value || '').trim());
    if (!match) return 0;
    const seconds = (Number(match[1] || 0) * 7 * 86400)
        + (Number(match[2] || 0) * 86400)
        + (Number(match[3] || 0) * 3600)
        + (Number(match[4] || 0) * 60)
        + Number(match[5] || 0);
    return seconds * 1000;
}

function parseIcsTriggerMinutes(property) {
    if (!property || String(property.parameters.RELATED || 'START').toUpperCase() !== 'START') return null;
    const value = String(property.value || '').trim().toUpperCase();
    if (!value.startsWith('-')) return null;
    const duration = parseIcsDuration(value.slice(1));
    if (duration < 0 || duration > 40320 * 60 * 1000 || duration % 60000 !== 0) return null;
    return duration / 60000;
}

function parseIcsReminder(eventLines) {
    const reminders = [];
    let inAlarm = false;
    for (const line of eventLines) {
        const upperLine = line.trim().toUpperCase();
        if (upperLine === 'BEGIN:VALARM') {
            inAlarm = true;
            continue;
        }
        if (upperLine === 'END:VALARM') {
            inAlarm = false;
            continue;
        }
        if (!inAlarm) continue;
        const property = parseIcsProperty(line);
        if (property?.name !== 'TRIGGER') continue;
        const minutes = parseIcsTriggerMinutes(property);
        if (minutes !== null && !reminders.includes(minutes)) reminders.push(minutes);
    }
    reminders.sort((left, right) => right - left);
    if (reminders.length === 0) {
        return { mode: 'none', minutesBeforeStart: null, reminders: [], editable: true };
    }
    if (reminders.length === 1) {
        return {
            mode: 'custom',
            minutesBeforeStart: reminders[0],
            reminders: [{ minutesBeforeStart: reminders[0] }],
            editable: true
        };
    }
    return {
        mode: 'multiple',
        minutesBeforeStart: null,
        reminders: reminders.slice(0, 5).map(minutesBeforeStart => ({ minutesBeforeStart })),
        editable: true
    };
}

function extractHttpUrl(value) {
    const match = String(value || '').match(/https?:\/\/[^\s<>"']+/i);
    return match ? match[0].replace(/[),.;]+$/, '') : '';
}

function conferenceUrlFromIcs(properties, description, location) {
    const propertyNames = Object.keys(properties);
    const preferredNames = propertyNames.filter(name => /(?:CONFERENCE|MEETING|JOIN)/i.test(name));
    const genericNames = propertyNames.filter(name => name === 'URL');
    for (const name of [...preferredNames, ...genericNames]) {
        for (const property of properties[name]) {
            const url = extractHttpUrl(unescapeIcsText(property.value));
            if (url) return url;
        }
    }
    return extractHttpUrl(description) || extractHttpUrl(location);
}

function appendConferenceUrl(description, conferenceUrl) {
    const value = String(description || '').trim();
    if (!conferenceUrl || value.includes(conferenceUrl)) return value.slice(0, 5000);
    const separator = value ? '\n\n' : '';
    const available = Math.max(0, 5000 - separator.length - conferenceUrl.length);
    return value.slice(0, available).trimEnd() + separator + conferenceUrl;
}

function parseSingleIcsEvent(value) {
    const lines = unfoldIcsLines(value);
    if (!lines.some(line => line.trim().toUpperCase() === 'BEGIN:VCALENDAR')) {
        throw new Error(icsImportText('The selected file is not a valid single-event ICS file.'));
    }
    if (lines.some(line => line.trim().toUpperCase() === 'METHOD:CANCEL')) {
        throw new Error(icsImportText('The selected file is not a valid single-event ICS file.'));
    }

    const eventBlocks = [];
    let eventStartIndex = -1;
    lines.forEach((line, index) => {
        const upperLine = line.trim().toUpperCase();
        if (upperLine === 'BEGIN:VEVENT') {
            if (eventStartIndex !== -1) {
                throw new Error(icsImportText('The selected file is not a valid single-event ICS file.'));
            }
            eventStartIndex = index;
        } else if (upperLine === 'END:VEVENT' && eventStartIndex !== -1) {
            eventBlocks.push(lines.slice(eventStartIndex, index + 1));
            eventStartIndex = -1;
        }
    });
    if (eventStartIndex !== -1 || eventBlocks.length === 0) {
        throw new Error(icsImportText('The selected file is not a valid single-event ICS file.'));
    }
    if (eventBlocks.length > 1) {
        throw new Error(icsImportText('This ICS file contains multiple events.'));
    }

    const eventLines = eventBlocks[0];
    const properties = collectIcsEventProperties(eventLines);
    if ((properties.RRULE?.length || 0) > 0 || (properties.RDATE?.length || 0) > 0) {
        throw new Error(icsImportText('Recurring ICS invitations cannot be imported as a single event.'));
    }
    if (String(properties.STATUS?.[0]?.value || '').trim().toUpperCase() === 'CANCELLED') {
        throw new Error(icsImportText('The selected file is not a valid single-event ICS file.'));
    }

    const start = parseIcsDateProperty(properties.DTSTART?.[0]);
    if (!start) {
        throw new Error(icsImportText('The selected file is not a valid single-event ICS file.'));
    }
    let end = parseIcsDateProperty(properties.DTEND?.[0]);
    if (end && end.allDay !== start.allDay) end = null;
    if (!end) {
        const duration = parseIcsDuration(properties.DURATION?.[0]?.value || '');
        end = {
            date: new Date(start.date.getTime() + (duration || (start.allDay ? 86400000 : 3600000))),
            allDay: start.allDay,
            timezone: start.timezone
        };
    }
    if (end.date <= start.date) {
        end.date = new Date(start.date.getTime() + (start.allDay ? 86400000 : 3600000));
    }

    const summary = unescapeIcsText(properties.SUMMARY?.[0]?.value || '').trim();
    const location = unescapeIcsText(properties.LOCATION?.[0]?.value || '').trim();
    const description = unescapeIcsText(properties.DESCRIPTION?.[0]?.value || '').trim();
    const conferenceUrl = conferenceUrlFromIcs(properties, description, location);
    return {
        summary: (summary || icsImportText('Untitled event')).slice(0, 250),
        location: location.slice(0, 500),
        description: appendConferenceUrl(description, conferenceUrl),
        allDay: start.allDay,
        start: start.date,
        end: end.date,
        timezone: start.timezone || end.timezone || '',
        reminder: parseIcsReminder(eventLines)
    };
}

function applyImportedIcsEvent(importedEvent) {
    if (selectedEvent !== null) return;
    importedIcsTimezone = importedEvent.timezone || '';
    document.getElementById('event-summary').value = importedEvent.summary;
    document.getElementById('event-location').value = importedEvent.location;
    document.getElementById('event-description').value = importedEvent.description;
    document.getElementById('event-all-day').checked = importedEvent.allDay;
    resetAnniversaryEditor();
    setDateInputs(importedEvent.start, importedEvent.end, importedEvent.allDay, importedEvent.allDay);
    resetRecurrenceEditor(importedEvent.start);
    loadReminderEditor({ reminder: importedEvent.reminder });
    updateRecurrenceAvailability();
    updateAnniversaryControls();
    updateReminderControls();
}

async function importIcsFile(file) {
    if (!file || selectedEvent !== null) return;
    if (file.size <= 0 || file.size > icsImportMaximumBytes) {
        throw new Error(icsImportText(file.size > icsImportMaximumBytes
            ? 'The ICS file is too large.'
            : 'The selected file is not a valid single-event ICS file.'));
    }
    const content = typeof file.text === 'function'
        ? await file.text()
        : await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(String(reader.result || ''));
            reader.onerror = () => reject(new Error(icsImportText('The selected file is not a valid single-event ICS file.')));
            reader.readAsText(file);
        });
    applyImportedIcsEvent(parseSingleIcsEvent(content));
}

function openNewEvent(preferredDay = null) {
    const writable = calendarState.calendars.filter(calendar => calendar.canWrite);
    if (!writable.length) return;
    selectedEvent = null;
    importedIcsTimezone = '';
    icsImportFile.value = '';
    icsImportButton.classList.remove('hidden');
    populateCalendarSelect(writable, writable[0].instanceId);
    document.getElementById('dialog-title').textContent = t('Create event');
    document.getElementById('event-summary').value = '';
    document.getElementById('event-location').value = '';
    document.getElementById('event-description').value = '';
    document.getElementById('event-all-day').checked = false;
    resetAnniversaryEditor();
    let start;
    if (preferredDay instanceof Date && !Number.isNaN(preferredDay.getTime())) {
        start = startOfDay(preferredDay);
        const today = startOfDay(new Date());
        if (dayKey(start) === dayKey(today)) {
            start = new Date();
            start.setMinutes(0, 0, 0);
            start.setHours(start.getHours() + 1);
            if (dayKey(start) !== dayKey(today)) {
                start = startOfDay(preferredDay);
                start.setHours(23, 0, 0, 0);
            }
        } else {
            start.setHours(9, 0, 0, 0);
        }
    } else {
        start = new Date(Math.max(Date.now(), cursorDate.getTime()));
        start.setMinutes(0, 0, 0);
        start.setHours(start.getHours() + 1);
    }
    const end = new Date(start.getTime() + 60 * 60 * 1000);
    setDateInputs(start, end, false);
    resetRecurrenceEditor(start);
    resetReminderEditor();
    setDialogEditable(true);
    document.getElementById('delete-button').classList.add('hidden');
    document.getElementById('dialog-note').classList.add('hidden');
    updateDialogColor();
    updateSaveButtonLabel();
    eventDialog.showModal();
}

function openEventDetails(event) {
    selectedEvent = event;
    const editable = eventCanUpdate(event);
    const deletable = eventCanDelete(event);
    document.getElementById('details-dialog-title').textContent = t('Event details');
    document.getElementById('details-summary').textContent = eventDisplaySummary(event) || t('Untitled event');
    document.getElementById('details-calendar').textContent = event.calendarName || '';
    document.getElementById('details-color').style.setProperty('--dialog-accent-color', safeColor(event.calendarColor));

    const start = eventStart(event);
    const end = eventEnd(event);
    if (event.allDay) {
        const displayEnd = end > start ? addDays(end, -1) : start;
        document.getElementById('details-start').textContent = `${formatDetailDate(start)} · ${t('All day')}`;
        document.getElementById('details-end').textContent = formatDetailDate(displayEnd);
    } else {
        document.getElementById('details-start').textContent = formatDetailDateTime(start);
        document.getElementById('details-end').textContent = formatDetailDateTime(end);
    }

    setOptionalDetail('occasion', annualEventLabel(event));
    setOptionalDetail('reminder', reminderDetailText(event));
    setOptionalDetail('location', event.location);
    setOptionalDetail('description', event.description);
    document.getElementById('details-edit-button').classList.toggle('hidden', !editable);
    document.getElementById('details-delete-button').classList.toggle('hidden', !deletable);

    const note = document.getElementById('details-note');
    const reason = eventReadOnlyReason(event);
    note.textContent = reason ? t(reason) : '';
    note.classList.toggle('hidden', reason === '');
    eventDetailsDialog.showModal();
}

function requestEdit(sourceDialog) {
    if (!selectedEvent || !eventCanUpdate(selectedEvent)) return;
    const event = selectedEvent;
    const occurrenceAllowed = eventCanUpdateOccurrence(event);
    const followingAllowed = eventCanUpdateFollowing(event);
    const seriesAllowed = eventCanUpdateSeries(event);
    if (!event.recurring || (!followingAllowed && !seriesAllowed)) {
        sourceDialog?.close();
        void prepareEventEdit(event);
        return;
    }

    editScopeSourceDialog = sourceDialog;
    document.getElementById('edit-scope-occurrence-option').classList.toggle('hidden', !occurrenceAllowed);
    document.getElementById('edit-scope-following-option').classList.toggle('hidden', !followingAllowed);
    document.getElementById('edit-scope-series-option').classList.toggle('hidden', !seriesAllowed);
    const defaultValue = occurrenceAllowed ? 'occurrence' : (followingAllowed ? 'following' : 'series');
    editScopeDialog.querySelectorAll('input[name="edit-scope"]').forEach(input => {
        input.checked = input.value === defaultValue;
    });
    editScopeDialog.showModal();
}

async function confirmEditScope() {
    if (!selectedEvent || !eventCanUpdate(selectedEvent)) {
        editScopeDialog.close();
        return;
    }

    const event = selectedEvent;
    const sourceDialog = editScopeSourceDialog;
    const selected = editScopeDialog.querySelector('input[name="edit-scope"]:checked');
    const scope = ['following', 'series'].includes(selected?.value) ? selected.value : 'occurrence';
    editScopeConfirmButton.disabled = true;
    try {
        editScopeDialog.close();
        sourceDialog?.close();
        if (scope === 'occurrence') {
            await prepareEventEdit(event);
            return;
        }

        pendingSeriesEdit = {
            calendarInstanceId: Number(event.calendarInstanceId),
            seriesId: String(event.seriesId || ''),
            resourceUrl: String(event.resourceUrl || ''),
            occurrenceId: String(event.occurrenceId || ''),
            originalStart: String(event.originalStart || ''),
            writeScope: scope
        };
        if (!pendingSeriesEdit.calendarInstanceId || !pendingSeriesEdit.seriesId
            || (scope === 'following' && (!pendingSeriesEdit.occurrenceId || !pendingSeriesEdit.originalStart))
            || !await sendAction('PrepareSeriesEdit', pendingSeriesEdit)) {
            pendingSeriesEdit = null;
        }
    } finally {
        editScopeConfirmButton.disabled = false;
    }
}

async function prepareEventEdit(event) {
    const calendarInstanceId = Number(event?.calendarInstanceId || 0);
    const eventReference = String(event?.eventReference || '');
    const occurrenceId = String(event?.occurrenceId || '');
    const startTimestamp = Number(event?.startTimestamp)
        || Math.floor(eventStart(event).getTime() / 1000);
    const endTimestamp = Number(event?.endTimestamp)
        || Math.floor(eventEnd(event).getTime() / 1000);

    pendingEventEdit = {
        calendarInstanceId,
        eventReference,
        occurrenceId
    };
    const request = {
        calendarInstanceId,
        event: {
            uid: String(event?.uid || ''),
            resourceUrl: String(event?.resourceUrl || ''),
            eventReference,
            occurrenceId,
            originalStart: String(event?.originalStart || ''),
            recurrenceId: String(event?.recurrenceId || ''),
            startTimestamp,
            endTimestamp
        }
    };

    if (!calendarInstanceId || startTimestamp <= 0
        || !(await sendAction('PrepareEventEdit', request))) {
        pendingEventEdit = null;
    }
}

function requestDelete(sourceDialog) {
    if (!selectedEvent || !eventCanDelete(selectedEvent)) return;
    deleteSourceDialog = sourceDialog;
    document.getElementById('delete-confirm-summary').textContent = eventDisplaySummary(selectedEvent) || t('Untitled event');
    document.getElementById('delete-confirm-period').textContent = formatDeleteEventPeriod(selectedEvent);
    updateDeleteScope(selectedEvent);
    deleteConfirmDialog.showModal();
}

function updateDeleteScope(event) {
    const scope = document.getElementById('delete-scope');
    const occurrenceOption = document.getElementById('delete-scope-occurrence-option');
    const followingOption = document.getElementById('delete-scope-following-option');
    const seriesOption = document.getElementById('delete-scope-series-option');
    const occurrenceAllowed = Boolean(event.recurring) && Boolean(event.canDeleteOccurrence);
    const followingAllowed = eventCanDeleteFollowing(event);
    const seriesAllowed = Boolean(event.recurring) && Boolean(event.canDeleteSeries);
    occurrenceOption.classList.toggle('hidden', !occurrenceAllowed);
    followingOption.classList.toggle('hidden', !followingAllowed);
    seriesOption.classList.toggle('hidden', !seriesAllowed);
    scope.classList.toggle(
        'hidden',
        !event.recurring || (!occurrenceAllowed && !followingAllowed && !seriesAllowed)
    );

    const defaultValue = occurrenceAllowed ? 'occurrence' : (followingAllowed ? 'following' : 'series');
    scope.querySelectorAll('input[name="delete-scope"]').forEach(input => {
        input.checked = input.value === defaultValue;
    });
}

function selectedDeleteScope(event) {
    if (!event?.recurring) return '';
    const selected = document.querySelector('input[name="delete-scope"]:checked');
    return ['following', 'series'].includes(selected?.value) ? selected.value : 'occurrence';
}

function formatDeleteEventPeriod(event) {
    const start = eventStart(event);
    const end = eventEnd(event);
    if (event.allDay) {
        const displayEnd = end > start ? addDays(end, -1) : start;
        const dateRange = dayKey(displayEnd) === dayKey(start)
            ? formatDetailDate(start)
            : `${formatDetailDate(start)} – ${formatDetailDate(displayEnd)}`;
        return `${dateRange} · ${t('All day')}`;
    }
    return `${formatDetailDateTime(start)} – ${formatDetailDateTime(end)}`;
}

async function confirmDeleteEvent() {
    if (!selectedEvent || !eventCanDelete(selectedEvent)) {
        deleteConfirmDialog.close();
        return;
    }

    const event = selectedEvent;
    const sourceDialog = deleteSourceDialog;
    deleteConfirmButton.disabled = true;
    try {
        const success = await sendAction('DeleteEvent', {
            calendarInstanceId: event.calendarInstanceId,
            event: {
                resourceUrl: event.resourceUrl,
                etag: event.etag,
                ...recurrencePayload(event, selectedDeleteScope(event))
            }
        });
        if (success) {
            deleteConfirmDialog.close();
            sourceDialog?.close();
        }
    } finally {
        deleteConfirmButton.disabled = false;
    }
}

function eventCanUpdateOccurrence(event) {
    return hasActionBridge()
        && Boolean(event.canWrite)
        && (!event.recurring || Boolean(event.canUpdateOccurrence));
}

function eventCanUpdateFollowing(event) {
    return hasActionBridge()
        && Boolean(event.canWrite)
        && Boolean(event.recurring)
        && Boolean(event.canUpdateFollowing)
        && Boolean(event.seriesId)
        && Boolean(event.occurrenceId)
        && Boolean(event.originalStart);
}

function eventCanUpdateSeries(event) {
    return hasActionBridge()
        && Boolean(event.canWrite)
        && Boolean(event.recurring)
        && Boolean(event.canUpdateSeries)
        && Boolean(event.seriesId);
}

function eventCanUpdate(event) {
    return eventCanUpdateOccurrence(event) || eventCanUpdateFollowing(event) || eventCanUpdateSeries(event);
}

function eventCanMove(event, writeScope = '') {
    if (!hasActionBridge() || !Boolean(event?.canWrite)) return false;
    const reminder = eventReminderState(event);
    if (reminder.mode === 'complex') return false;
    if (reminder.mode === 'default') {
        const sourceCalendar = calendarEntryByInstanceId(event?.calendarInstanceId);
        if (calendarDefaultReminderState(sourceCalendar).mode === 'complex') return false;
    }
    if (!Boolean(event?.recurring)) {
        return eventCanUpdateOccurrence(event) && eventCanDelete(event);
    }

    const scope = writeScope || event.writeScope || 'occurrence';
    if (scope === 'series') {
        return eventCanUpdateSeries(event)
            && Boolean(event.canDeleteSeries)
            && event.recurrenceEditable !== false;
    }
    if (scope === 'following') {
        return eventCanUpdateFollowing(event)
            && eventCanDeleteFollowing(event)
            && event.recurrenceEditable !== false;
    }

    return eventCanUpdateOccurrence(event) && Boolean(event.canDeleteOccurrence);
}

function eventCanDeleteFollowing(event) {
    return hasActionBridge()
        && Boolean(event.canWrite)
        && Boolean(event.recurring)
        && Boolean(event.canUpdateFollowing)
        && Boolean(event.canDeleteSeries)
        && Boolean(event.seriesId)
        && Boolean(event.occurrenceId)
        && Boolean(event.originalStart);
}

function eventCanDelete(event) {
    return hasActionBridge()
        && Boolean(event.canWrite)
        && (!event.recurring
            || Boolean(event.canDeleteOccurrence)
            || eventCanDeleteFollowing(event)
            || Boolean(event.canDeleteSeries));
}

function recurrencePayload(event, writeScope = '') {
    const scope = writeScope || event.writeScope || '';
    return {
        recurrenceType: event.recurrenceType || (event.recurring ? 'unknown' : 'single'),
        seriesId: event.seriesId || '',
        occurrenceId: event.occurrenceId || '',
        originalStart: event.originalStart || '',
        recurrenceId: event.recurrenceId || '',
        recurring: Boolean(event.recurring),
        canUpdateOccurrence: Boolean(event.canUpdateOccurrence),
        canDeleteOccurrence: Boolean(event.canDeleteOccurrence),
        canUpdateFollowing: Boolean(event.canUpdateFollowing),
        canUpdateSeries: Boolean(event.canUpdateSeries),
        canDeleteSeries: Boolean(event.canDeleteSeries),
        writeScope: scope
    };
}

function eventReadOnlyReason(event) {
    if (eventCanUpdate(event) || eventCanDelete(event)) return '';
    if (!hasActionBridge()) return 'Editing events is unavailable because no action bridge is configured.';
    if (event.recurring || event.recurrenceId) return 'Recurring occurrences are currently read-only.';
    return 'This calendar is read-only.';
}

function setOptionalDetail(name, value) {
    const row = document.getElementById(`details-${name}-row`);
    const target = document.getElementById(`details-${name}`);
    const text = String(value || '').trim();
    target.textContent = text;
    row.classList.toggle('hidden', text === '');
}

function formatDetailDate(date) {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date);
}

function formatDetailDateTime(date) {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

function openExistingEvent(event, writeScope = '') {
    const scope = event.recurring ? (writeScope || event.writeScope || 'occurrence') : '';
    selectedEvent = { ...event, writeScope: scope };
    importedIcsTimezone = '';
    icsImportFile.value = '';
    icsImportButton.classList.add('hidden');
    const editingSeries = Boolean(selectedEvent.recurring) && scope === 'series';
    const editingFollowing = Boolean(selectedEvent.recurring) && scope === 'following';
    const recurringOccurrence = Boolean(selectedEvent.recurring) && !editingSeries && !editingFollowing;
    const editable = editingSeries
        ? eventCanUpdateSeries(selectedEvent)
        : editingFollowing
            ? eventCanUpdateFollowing(selectedEvent)
            : eventCanUpdateOccurrence(selectedEvent);
    const canMove = editable && eventCanMove(selectedEvent, scope);
    const recurringTargetRequired = editingSeries || editingFollowing;
    const availableCalendars = editable
        ? calendarState.calendars.filter(calendar => calendar.instanceId === selectedEvent.calendarInstanceId
            || (canMove
                && calendar.canWrite
                && (!recurringTargetRequired || calendar.canCreateRecurrence)))
        : calendarState.calendars;
    populateCalendarSelect(availableCalendars, selectedEvent.calendarInstanceId);
    setCalendarSelectDisabled(!canMove);
    document.getElementById('dialog-title').textContent = t(editingSeries ? 'Edit recurring event' : 'Edit event');
    document.getElementById('event-summary').value = selectedEvent.summary || '';
    document.getElementById('event-location').value = selectedEvent.location || '';
    document.getElementById('event-description').value = selectedEvent.description || '';
    document.getElementById('event-all-day').checked = Boolean(selectedEvent.allDay);
    loadAnniversaryEditor(selectedEvent);
    setDateInputs(
        eventStart(selectedEvent),
        eventEnd(selectedEvent),
        Boolean(selectedEvent.allDay),
        Boolean(selectedEvent.allDay)
    );
    if (editingSeries || editingFollowing) {
        loadRecurrenceEditor(selectedEvent);
    } else if (!selectedEvent.recurring) {
        resetRecurrenceEditor(eventStart(selectedEvent));
    } else {
        updateRecurrenceAvailability();
    }
    loadReminderEditor(selectedEvent);
    const descriptionEditable = editable && !Boolean(selectedEvent.onlineMeeting);
    setDialogEditable(editable, descriptionEditable);
    document.getElementById('delete-button').classList.toggle('hidden', !eventCanDelete(selectedEvent));
    const note = document.getElementById('dialog-note');
    if (!editable) {
        note.textContent = t(eventReadOnlyReason(selectedEvent));
        note.classList.remove('hidden');
    } else if (editingSeries) {
        note.textContent = selectedEvent.recurrenceEditable === false
            ? `${t('Changes will apply to the entire recurring series.')} ${t('The recurrence pattern of this series cannot be edited here.')}`
            : t('Changes will apply to the entire recurring series.');
        note.classList.remove('hidden');
    } else if (editingFollowing) {
        note.textContent = `${t('Changes will apply to this and all following occurrences.')} ${t('Existing exceptions from this occurrence onward will be reset.')}`;
        note.classList.remove('hidden');
    } else if (recurringOccurrence) {
        note.textContent = t('Only this occurrence of the recurring event will be changed.');
        note.classList.remove('hidden');
    } else if (!descriptionEditable) {
        note.textContent = t('The description of Microsoft online meetings is protected and cannot be edited here.');
        note.classList.remove('hidden');
    } else {
        note.classList.add('hidden');
    }
    updateDialogColor();
    updateSaveButtonLabel();
    eventDialog.showModal();
}

function populateCalendarSelect(calendars, selectedId) {
    eventCalendarOptions.replaceChildren();
    calendars.forEach(calendar => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'calendar-picker-option';
        option.dataset.value = String(calendar.instanceId);
        option.textContent = calendar.name;
        option.setAttribute('role', 'option');
        option.addEventListener('click', () => {
            selectCalendarOption(calendar.instanceId, true);
            eventCalendarTrigger.focus();
        });
        option.addEventListener('keydown', handleCalendarOptionKeydown);
        eventCalendarOptions.appendChild(option);
    });

    const selectedCalendar = calendars.find(calendar => calendar.instanceId === Number(selectedId))
        || calendars[0]
        || null;
    selectCalendarOption(selectedCalendar?.instanceId || 0, false);
    setCalendarSelectDisabled(selectedCalendar === null);
}

function selectCalendarOption(instanceId, notify) {
    const value = String(Number(instanceId) || '');
    eventCalendarInput.value = value;
    let selectedOption = null;
    eventCalendarOptions.querySelectorAll('.calendar-picker-option').forEach(option => {
        const selected = option.dataset.value === value;
        option.setAttribute('aria-selected', String(selected));
        if (selected) selectedOption = option;
    });
    eventCalendarValue.textContent = selectedOption?.textContent || '';
    closeCalendarPicker();
    if (notify) {
        eventCalendarInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function setCalendarSelectDisabled(disabled) {
    eventCalendarInput.disabled = disabled;
    eventCalendarTrigger.disabled = disabled;
    eventCalendarTrigger.setAttribute('aria-disabled', String(disabled));
    if (disabled) closeCalendarPicker();
}

function openCalendarPicker(focusSelected = false) {
    if (eventCalendarTrigger.disabled) return;
    eventCalendarOptions.classList.remove('hidden');
    eventCalendarTrigger.setAttribute('aria-expanded', 'true');
    if (focusSelected) {
        const selected = eventCalendarOptions.querySelector('[aria-selected="true"]')
            || eventCalendarOptions.querySelector('.calendar-picker-option');
        selected?.focus();
    }
}

function closeCalendarPicker() {
    eventCalendarOptions.classList.add('hidden');
    eventCalendarTrigger.setAttribute('aria-expanded', 'false');
}

function toggleCalendarPicker() {
    if (eventCalendarOptions.classList.contains('hidden')) {
        openCalendarPicker();
    } else {
        closeCalendarPicker();
    }
}

function handleCalendarOptionKeydown(event) {
    const options = Array.from(eventCalendarOptions.querySelectorAll('.calendar-picker-option'));
    const currentIndex = options.indexOf(event.currentTarget);
    let nextIndex = currentIndex;
    if (event.key === 'ArrowDown') nextIndex = Math.min(options.length - 1, currentIndex + 1);
    else if (event.key === 'ArrowUp') nextIndex = Math.max(0, currentIndex - 1);
    else if (event.key === 'Home') nextIndex = 0;
    else if (event.key === 'End') nextIndex = options.length - 1;
    else if (event.key === 'Escape') {
        event.preventDefault();
        closeCalendarPicker();
        eventCalendarTrigger.focus();
        return;
    } else {
        return;
    }
    event.preventDefault();
    options[nextIndex]?.focus();
}

function calendarEntryByInstanceId(instanceId) {
    const normalizedId = Number(instanceId);
    return calendarState.calendars.find(calendar => Number(calendar.instanceId) === normalizedId) || null;
}

function selectedCalendarEntry() {
    return calendarEntryByInstanceId(eventCalendarInput.value);
}

function eventReminderState(event) {
    const reminder = event?.reminder;
    if (!reminder || typeof reminder !== 'object' || Array.isArray(reminder)) {
        return { mode: 'none', minutesBeforeStart: null, reminders: [], editable: true };
    }

    const mode = ['default', 'none', 'custom', 'multiple', 'complex'].includes(reminder.mode)
        ? reminder.mode
        : 'complex';
    const editable = reminder.editable !== false && mode !== 'complex';
    if (mode === 'custom') {
        const minutes = Number(reminder.minutesBeforeStart);
        if (!Number.isInteger(minutes) || minutes < 0 || minutes > 40320) {
            return { mode: 'complex', minutesBeforeStart: null, reminders: [], editable: false };
        }
        return { mode, minutesBeforeStart: minutes, reminders: [minutes], editable };
    }
    if (mode === 'multiple') {
        const items = Array.isArray(reminder.reminders) ? reminder.reminders : [];
        const minutes = items.map(item => Number(item?.minutesBeforeStart));
        if (minutes.length < 2
            || minutes.length > 5
            || minutes.some(value => !Number.isInteger(value) || value < 0 || value > 40320)
            || new Set(minutes).size !== minutes.length) {
            return { mode: 'complex', minutesBeforeStart: null, reminders: [], editable: false };
        }
        return { mode, minutesBeforeStart: null, reminders: minutes, editable };
    }

    return { mode, minutesBeforeStart: null, reminders: [], editable };
}

function calendarDefaultReminderState(calendar) {
    if (!calendar || !Boolean(calendar.canUseDefaultReminder)) {
        return { mode: 'complex', minutesBeforeStart: null, reminders: [], editable: false };
    }

    const reminder = calendar.defaultReminder;
    if (!reminder || typeof reminder !== 'object' || Array.isArray(reminder)) {
        return { mode: 'complex', minutesBeforeStart: null, reminders: [], editable: false };
    }

    const mode = ['none', 'custom', 'multiple', 'complex'].includes(reminder.mode)
        ? reminder.mode
        : 'complex';
    if (mode === 'custom') {
        const minutes = Number(reminder.minutesBeforeStart);
        if (!Number.isInteger(minutes) || minutes < 0 || minutes > 40320) {
            return { mode: 'complex', minutesBeforeStart: null, reminders: [], editable: false };
        }
        return {
            mode,
            minutesBeforeStart: minutes,
            reminders: [minutes],
            editable: reminder.editable !== false
        };
    }
    if (mode === 'multiple') {
        const items = Array.isArray(reminder.reminders) ? reminder.reminders : [];
        const minutes = items.map(item => Number(item?.minutesBeforeStart));
        if (minutes.length < 2
            || minutes.length > 5
            || minutes.some(value => !Number.isInteger(value) || value < 0 || value > 40320)
            || new Set(minutes).size !== minutes.length) {
            return { mode: 'complex', minutesBeforeStart: null, reminders: [], editable: false };
        }
        return {
            mode,
            minutesBeforeStart: null,
            reminders: minutes,
            editable: reminder.editable !== false
        };
    }

    return {
        mode,
        minutesBeforeStart: null,
        reminders: [],
        editable: reminder.editable !== false && mode !== 'complex'
    };
}

function maxReminderCount(calendar = selectedCalendarEntry()) {
    const value = Number(calendar?.maxReminders);
    return Math.max(1, Math.min(5, Number.isInteger(value) ? value : 1));
}

function reminderEditorEntries() {
    return [
        { valueInput: eventReminderValue, unitSelect: eventReminderUnit, removeButton: null },
        ...Array.from(eventReminderExtraList.querySelectorAll('.reminder-extra-entry')).map(entry => ({
            valueInput: entry.querySelector('.event-reminder-extra-value'),
            unitSelect: entry.querySelector('.event-reminder-extra-unit'),
            removeButton: entry.querySelector('[data-reminder-remove]')
        }))
    ].filter(entry => entry.valueInput && entry.unitSelect);
}

function createReminderUnitSelect() {
    const select = document.createElement('select');
    select.className = 'event-reminder-extra-unit';
    [
        ['minutes', 'Minutes'],
        ['hours', 'Hours'],
        ['days', 'Days'],
        ['weeks', 'Weeks']
    ].forEach(([value, label]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = t(label);
        select.append(option);
    });
    return select;
}

function appendReminderEditorEntry(minutesBeforeStart) {
    const entry = document.createElement('div');
    entry.className = 'reminder-extra-entry';

    const fields = document.createElement('div');
    fields.className = 'form-row two';

    const valueRow = document.createElement('div');
    valueRow.className = 'form-row';
    const valueLabel = document.createElement('label');
    valueLabel.textContent = t('Before start');
    const valueInput = document.createElement('input');
    valueInput.type = 'number';
    valueInput.min = '0';
    valueInput.max = '40320';
    valueInput.className = 'event-reminder-extra-value';
    valueRow.append(valueLabel, valueInput);

    const unitRow = document.createElement('div');
    unitRow.className = 'form-row';
    const unitLabel = document.createElement('label');
    unitLabel.textContent = t('Unit');
    const unitSelect = createReminderUnitSelect();
    unitRow.append(unitLabel, unitSelect);

    fields.append(valueRow, unitRow);

    const actions = document.createElement('div');
    actions.className = 'form-row';
    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'secondary-button';
    removeButton.dataset.reminderRemove = 'true';
    removeButton.textContent = t('Remove reminder');
    actions.append(removeButton);

    entry.append(fields, actions);
    eventReminderExtraList.append(entry);
    setReminderFieldsFromMinutes(valueInput, unitSelect, minutesBeforeStart);
}

function clearExtraReminderEntries() {
    eventReminderExtraList.replaceChildren();
}

function resetReminderEditor() {
    reminderDefaultResolvedForMove = false;
    const calendar = selectedCalendarEntry();
    const allowDefault = Boolean(calendar?.canUseDefaultReminder || calendar?.canCreateWithDefaultReminder);
    const defaultOption = eventReminderMode.querySelector('option[value="default"]');
    if (defaultOption) defaultOption.disabled = !allowDefault;
    eventReminderMode.value = allowDefault ? 'default' : 'none';
    clearExtraReminderEntries();
    setReminderFieldsFromMinutes(eventReminderValue, eventReminderUnit, 15);
    updateReminderControls();
}

function loadReminderEditor(event) {
    reminderDefaultResolvedForMove = false;
    const reminder = eventReminderState(event);
    eventReminderMode.value = ['custom', 'multiple'].includes(reminder.mode) ? 'custom' : reminder.mode;
    if (reminder.reminders.length > 0) {
        setReminderEditorValues(reminder.reminders);
    } else {
        clearExtraReminderEntries();
        setReminderFieldsFromMinutes(eventReminderValue, eventReminderUnit, 15);
    }
    updateReminderControls();
}

function setReminderEditorValues(minutesBeforeStart) {
    const values = Array.isArray(minutesBeforeStart) && minutesBeforeStart.length > 0
        ? minutesBeforeStart.slice(0, 5)
        : [15];
    clearExtraReminderEntries();
    setReminderFieldsFromMinutes(eventReminderValue, eventReminderUnit, values[0]);
    values.slice(1).forEach(value => appendReminderEditorEntry(value));
}

function setReminderFieldsFromMinutes(valueInput, unitSelect, minutes) {
    const value = Math.max(0, Math.min(40320, Number(minutes) || 0));
    const units = [
        ['weeks', 10080],
        ['days', 1440],
        ['hours', 60],
        ['minutes', 1]
    ];
    const selected = units.find(([, multiplier]) => value > 0 && value % multiplier === 0)
        || ['minutes', 1];
    unitSelect.value = selected[0];
    valueInput.value = String(value / selected[1]);
}

function reminderUnitMultiplier(unit = eventReminderUnit.value) {
    return {
        minutes: 1,
        hours: 60,
        days: 1440,
        weeks: 10080
    }[unit] || 1;
}

function reminderMinutesFromEntry(entry) {
    const value = Number(entry.valueInput.value);
    const minutes = value * reminderUnitMultiplier(entry.unitSelect.value);
    return Number.isInteger(value) && value >= 0 && Number.isInteger(minutes) && minutes <= 40320
        ? minutes
        : null;
}

function nextReminderDefaultMinutes() {
    const used = new Set(reminderEditorEntries().map(reminderMinutesFromEntry).filter(value => value !== null));
    return [60, 1440, 10080, 30, 10, 5, 0].find(value => !used.has(value)) ?? 15;
}

function resolveDefaultReminderForCalendarMove() {
    if (!selectedEvent || eventReminderState(selectedEvent).mode !== 'default') return;

    const sourceInstanceId = Number(selectedEvent.calendarInstanceId);
    const targetInstanceId = Number(eventCalendarInput.value);
    if (sourceInstanceId === targetInstanceId) {
        if (reminderDefaultResolvedForMove) {
            eventReminderMode.value = 'default';
            clearExtraReminderEntries();
            setReminderFieldsFromMinutes(eventReminderValue, eventReminderUnit, 15);
            reminderDefaultResolvedForMove = false;
        }
        return;
    }
    if (eventReminderMode.value !== 'default') return;

    const sourceDefault = calendarDefaultReminderState(calendarEntryByInstanceId(sourceInstanceId));
    if (sourceDefault.mode === 'none') {
        eventReminderMode.value = 'none';
        reminderDefaultResolvedForMove = true;
    } else if (['custom', 'multiple'].includes(sourceDefault.mode) && sourceDefault.reminders.length > 0) {
        eventReminderMode.value = 'custom';
        setReminderEditorValues(sourceDefault.reminders);
        reminderDefaultResolvedForMove = true;
    }
}

function reminderLimitMessage(maxReminders) {
    return t('This calendar supports up to %d reminders.').replace('%d', String(maxReminders));
}

function updateReminderControls() {
    const selectedReminder = selectedEvent ? eventReminderState(selectedEvent) : null;
    const reminderEditable = eventDialogEditable
        && (!selectedReminder || selectedReminder.editable);
    const selectedCalendar = selectedCalendarEntry();
    const defaultAllowed = selectedEvent
        ? Boolean(selectedCalendar?.canUseDefaultReminder)
        : Boolean(selectedCalendar?.canUseDefaultReminder || selectedCalendar?.canCreateWithDefaultReminder);
    const defaultOption = eventReminderMode.querySelector('option[value="default"]');
    if (defaultOption) {
        defaultOption.disabled = !defaultAllowed;
    }
    if (!selectedEvent && !defaultAllowed && eventReminderMode.value === 'default') {
        eventReminderMode.value = 'none';
    }

    eventReminderMode.disabled = !reminderEditable;
    const custom = reminderEditable && eventReminderMode.value === 'custom';
    eventReminderCustomRow.classList.toggle('hidden', !custom);
    eventReminderExtraList.classList.toggle('hidden', !custom);

    const entries = reminderEditorEntries();
    const limits = {
        minutes: 40320,
        hours: 672,
        days: 28,
        weeks: 4
    };
    entries.forEach(entry => {
        entry.valueInput.disabled = !custom;
        entry.unitSelect.disabled = !custom;
        entry.valueInput.required = custom;
        entry.removeButton && (entry.removeButton.disabled = !custom);
        entry.valueInput.max = String(limits[entry.unitSelect.value] || 40320);
        if (Number(entry.valueInput.value) > Number(entry.valueInput.max)) {
            entry.valueInput.value = entry.valueInput.max;
        }
    });

    const maxReminders = maxReminderCount(selectedCalendar);
    eventReminderAddRow.classList.toggle('hidden', !custom || entries.length >= maxReminders);
    eventReminderAddButton.disabled = !custom || entries.length >= maxReminders;

    eventReminderValue.setCustomValidity('');
    if (custom && entries.length > maxReminders) {
        eventReminderValue.setCustomValidity(reminderLimitMessage(maxReminders));
        return;
    }

    const minutes = entries.map(reminderMinutesFromEntry);
    const validMinutes = minutes.filter(value => value !== null);
    if (custom && validMinutes.length === minutes.length && new Set(validMinutes).size !== validMinutes.length) {
        eventReminderValue.setCustomValidity(t('Reminder times must be unique.'));
    }
}

function reminderEditorValue() {
    if (eventReminderMode.disabled || eventReminderMode.value === 'complex') {
        return null;
    }
    if (eventReminderMode.value === 'default') {
        return Boolean(selectedCalendarEntry()?.canUseDefaultReminder)
            ? { mode: 'default' }
            : null;
    }
    if (eventReminderMode.value === 'none') {
        return { mode: 'none' };
    }
    if (eventReminderMode.value !== 'custom') {
        return null;
    }

    const minutes = reminderEditorEntries().map(reminderMinutesFromEntry);
    if (minutes.some(value => value === null)
        || minutes.length < 1
        || minutes.length > maxReminderCount()
        || new Set(minutes).size !== minutes.length) {
        return null;
    }
    if (minutes.length === 1) {
        return {
            mode: 'custom',
            minutesBeforeStart: minutes[0]
        };
    }

    return {
        mode: 'multiple',
        reminders: minutes.map(minutesBeforeStart => ({ minutesBeforeStart }))
    };
}

function reminderOffsetText(minutes) {
    const normalizedMinutes = Math.max(0, Number(minutes) || 0);
    const units = [
        ['Week', 'Weeks', 10080],
        ['Day', 'Days', 1440],
        ['Hour', 'Hours', 60],
        ['Minute', 'Minutes', 1]
    ];
    const selected = units.find(([, , multiplier]) => normalizedMinutes > 0 && normalizedMinutes % multiplier === 0)
        || ['Minute', 'Minutes', 1];
    const value = normalizedMinutes / selected[2];
    const unit = t(value === 1 ? selected[0] : selected[1]);
    return `${value} ${unit} · ${t('Before start')}`;
}

function reminderOffsetsText(reminder) {
    return reminder.reminders.map(reminderOffsetText).join(', ');
}

function reminderDetailText(event) {
    const reminder = eventReminderState(event);
    if (reminder.mode === 'none') return '';
    if (reminder.mode === 'complex') return t('Existing reminder settings');
    if (reminder.mode === 'default') {
        const sourceDefault = calendarDefaultReminderState(calendarEntryByInstanceId(event?.calendarInstanceId));
        if (sourceDefault.mode === 'none') {
            return `${t('Calendar default')} · ${t('No reminder')}`;
        }
        if (['custom', 'multiple'].includes(sourceDefault.mode) && sourceDefault.reminders.length > 0) {
            return `${t('Calendar default')} · ${reminderOffsetsText(sourceDefault)}`;
        }
        return t('Calendar default');
    }

    return reminderOffsetsText(reminder);
}

function resetRecurrenceEditor(start) {
    recurrencePatternContext = null;
    setRecurrenceNoneOptionDisabled(false);
    eventRecurrenceFrequency.value = 'none';
    eventRecurrenceInterval.value = '1';
    eventRecurrenceEndMode.value = 'never';
    eventRecurrenceCount.value = '10';
    eventRecurrenceUntil.value = '';
    eventRecurrenceWeekdays.querySelectorAll('input[type="checkbox"]').forEach(input => {
        input.checked = false;
    });
    const patternControls = recurrencePatternControls();
    patternControls.mode.value = 'absolute';
    patternControls.index.value = 'first';
    selectDefaultRecurrenceWeekday(start);
    updateRecurrenceAvailability();
}

function setRecurrenceNoneOptionDisabled(disabled) {
    const option = eventRecurrenceFrequency.querySelector('option[value="none"]');
    if (option) option.disabled = disabled;
}

function loadRecurrenceEditor(event) {
    const recurrence = event.recurrenceSettings && typeof event.recurrenceSettings === 'object'
        ? event.recurrenceSettings
        : {};
    recurrencePatternContext = {
        frequency: String(recurrence.frequency || '').toUpperCase(),
        patternMode: recurrence.patternMode === 'relative' ? 'relative' : 'absolute',
        relativeIndex: ['first', 'second', 'third', 'fourth', 'last'].includes(recurrence.relativeIndex)
            ? recurrence.relativeIndex
            : 'first',
        weekStart: String(recurrence.weekStart || ''),
        dayOfMonth: Number(recurrence.dayOfMonth) || 0,
        month: Number(recurrence.month) || 0,
        recurrenceTimeZone: String(recurrence.recurrenceTimeZone || ''),
        startDate: localDate(eventStart(event))
    };
    const editable = event.recurrenceEditable !== false && Boolean(recurrence.frequency);
    setRecurrenceNoneOptionDisabled(editable);
    eventRecurrenceFrequency.value = editable ? String(recurrence.frequency).toLowerCase() : 'none';
    eventRecurrenceInterval.value = String(Math.max(1, Number(recurrence.interval) || 1));
    eventRecurrenceEndMode.value = ['count', 'until'].includes(recurrence.endMode)
        ? recurrence.endMode
        : 'never';
    eventRecurrenceCount.value = String(Math.max(1, Number(recurrence.count) || 10));
    eventRecurrenceUntil.value = String(recurrence.until || '');
    const selectedWeekdays = new Set(Array.isArray(recurrence.byDay) ? recurrence.byDay : []);
    eventRecurrenceWeekdays.querySelectorAll('input[type="checkbox"]').forEach(input => {
        input.checked = selectedWeekdays.has(input.value);
    });
    const patternControls = recurrencePatternControls();
    patternControls.mode.value = recurrencePatternContext.patternMode;
    patternControls.index.value = recurrencePatternContext.relativeIndex;
    if (editable
        && (eventRecurrenceFrequency.value === 'weekly' || recurrencePatternContext.patternMode === 'relative')
        && selectedWeekdays.size === 0) {
        selectDefaultRecurrenceWeekday(eventStart(event));
    }
    updateRecurrenceAvailability();
}

function recurrencePatternControls() {
    let row = document.getElementById('event-recurrence-pattern-row');
    if (row) {
        return {
            row,
            mode: document.getElementById('event-recurrence-pattern-mode'),
            index: document.getElementById('event-recurrence-relative-index')
        };
    }

    const german = document.documentElement.lang.toLowerCase().startsWith('de');
    row = document.createElement('div');
    row.id = 'event-recurrence-pattern-row';
    row.className = 'form-row two hidden';

    const modeRow = document.createElement('div');
    modeRow.className = 'form-row';
    const modeLabel = document.createElement('label');
    modeLabel.htmlFor = 'event-recurrence-pattern-mode';
    modeLabel.textContent = german ? 'Muster' : 'Pattern';
    const mode = document.createElement('select');
    mode.id = 'event-recurrence-pattern-mode';
    const absolute = document.createElement('option');
    absolute.value = 'absolute';
    const relative = document.createElement('option');
    relative.value = 'relative';
    relative.textContent = german ? 'Wochentagsposition' : 'Weekday position';
    mode.append(absolute, relative);
    modeRow.append(modeLabel, mode);

    const indexRow = document.createElement('div');
    indexRow.className = 'form-row';
    indexRow.id = 'event-recurrence-relative-index-row';
    const indexLabel = document.createElement('label');
    indexLabel.htmlFor = 'event-recurrence-relative-index';
    indexLabel.textContent = german ? 'Position' : 'Position';
    const index = document.createElement('select');
    index.id = 'event-recurrence-relative-index';
    const labels = german
        ? { first: 'Erste', second: 'Zweite', third: 'Dritte', fourth: 'Vierte', last: 'Letzte' }
        : { first: 'First', second: 'Second', third: 'Third', fourth: 'Fourth', last: 'Last' };
    Object.entries(labels).forEach(([value, label]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        index.appendChild(option);
    });
    indexRow.append(indexLabel, index);
    row.append(modeRow, indexRow);
    eventRecurrenceWeekdays.before(row);
    mode.addEventListener('change', updateRecurrenceControls);
    index.addEventListener('change', updateRecurrenceControls);

    return { row, mode, index };
}

function updateRecurrencePatternLabels(frequency, mode) {
    const controls = recurrencePatternControls();
    const absolute = controls.mode.querySelector('option[value="absolute"]');
    const german = document.documentElement.lang.toLowerCase().startsWith('de');
    if (absolute) {
        absolute.textContent = frequency === 'yearly'
            ? (german ? 'Festes Datum' : 'Fixed date')
            : (german ? 'Fester Monatstag' : 'Fixed day of month');
    }
    controls.index.parentElement.classList.toggle('hidden', mode !== 'relative');
}

function selectDefaultRecurrenceWeekday(start) {
    if (!(start instanceof Date) || Number.isNaN(start.getTime())) return;
    const weekday = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'][start.getDay()];
    const input = eventRecurrenceWeekdays.querySelector(`input[value="${weekday}"]`);
    if (input) input.checked = true;
}

function updateRecurrenceAvailability() {
    const calendar = selectedCalendarEntry();
    const editingSeries = Boolean(selectedEvent?.recurring) && selectedEvent?.writeScope === 'series';
    const editingFollowing = Boolean(selectedEvent?.recurring) && selectedEvent?.writeScope === 'following';
    const editingRecurringRange = editingSeries || editingFollowing;
    const editingSingle = selectedEvent !== null && !Boolean(selectedEvent?.recurring);
    const movingSingle = editingSingle
        && Number(calendar?.instanceId || 0) !== Number(selectedEvent?.calendarInstanceId || 0);
    const available = editingRecurringRange
        ? Boolean(editingSeries ? selectedEvent?.canUpdateSeries : selectedEvent?.canUpdateFollowing)
            && selectedEvent?.recurrenceEditable !== false
        : selectedEvent === null
            ? Boolean(calendar?.canWrite) && Boolean(calendar?.canCreateRecurrence)
            : editingSingle
                && eventCanUpdateOccurrence(selectedEvent)
                && Boolean(calendar?.canWrite)
                && Boolean(movingSingle ? calendar?.canCreateRecurrence : calendar?.canUpdateRecurrence);
    const canClearSeriesRecurrence = editingSeries
        && Boolean(calendar?.canUpdateRecurrence);
    setRecurrenceNoneOptionDisabled(
        editingRecurringRange && available && (!editingSeries || !canClearSeriesRecurrence)
    );
    eventRecurrenceRow.classList.toggle('hidden', !available);
    eventRecurrenceFrequency.disabled = !available;
    if (!available) eventRecurrenceFrequency.value = 'none';
    updateRecurrenceControls();
}

function updateRecurrenceControls() {
    const frequency = eventRecurrenceFrequency.value;
    const enabled = !eventRecurrenceFrequency.disabled && frequency !== 'none';
    eventRecurrenceOptions.classList.toggle('hidden', !enabled);

    const patternControls = recurrencePatternControls();
    const patternSupported = enabled
        && ['monthly', 'yearly'].includes(frequency)
        && Boolean(selectedCalendarEntry()?.canUpdateRecurrence);
    patternControls.row.classList.toggle('hidden', !patternSupported);
    if (!patternSupported) {
        patternControls.mode.value = 'absolute';
    }
    const patternMode = patternSupported ? patternControls.mode.value : 'absolute';
    updateRecurrencePatternLabels(frequency, patternMode);
    const relativePattern = patternSupported && patternMode === 'relative';
    eventRecurrenceWeekdays.classList.toggle('hidden', !enabled || (frequency !== 'weekly' && !relativePattern));

    const endMode = eventRecurrenceEndMode.value;
    const countVisible = enabled && endMode === 'count';
    const untilVisible = enabled && endMode === 'until';
    eventRecurrenceCountRow.classList.toggle('hidden', !countVisible);
    eventRecurrenceUntilRow.classList.toggle('hidden', !untilVisible);
    eventRecurrenceCount.disabled = !countVisible;
    eventRecurrenceUntil.disabled = !untilVisible;
    eventRecurrenceCount.required = countVisible;
    eventRecurrenceUntil.required = untilVisible;

    const interval = Math.max(1, Number(eventRecurrenceInterval.value) || 1);
    const units = {
        daily: interval === 1 ? 'Day' : 'Days',
        weekly: interval === 1 ? 'Week' : 'Weeks',
        monthly: interval === 1 ? 'Month' : 'Months',
        yearly: interval === 1 ? 'Year' : 'Years'
    };
    eventRecurrenceIntervalUnit.textContent = enabled ? t(units[frequency] || 'Days') : '';
    updateRecurrenceEndDateMinimum();
}

function updateRecurrenceEndDateMinimum() {
    const start = readInputDate(document.getElementById('event-start').value);
    if (!start) return;
    eventRecurrenceUntil.min = localDate(start);
    if (eventRecurrenceUntil.value && eventRecurrenceUntil.value < eventRecurrenceUntil.min) {
        eventRecurrenceUntil.value = eventRecurrenceUntil.min;
    }
}

function recurrenceEditorValue() {
    const editingSeries = Boolean(selectedEvent?.recurring) && selectedEvent?.writeScope === 'series';
    const editingFollowing = Boolean(selectedEvent?.recurring) && selectedEvent?.writeScope === 'following';
    const editingSingle = selectedEvent !== null && !Boolean(selectedEvent?.recurring);
    if ((selectedEvent !== null && !editingSeries && !editingFollowing && !editingSingle)
        || eventRecurrenceFrequency.disabled) return null;
    const frequency = eventRecurrenceFrequency.value;
    if (frequency === 'none') return null;

    const recurrence = {
        frequency: frequency.toUpperCase(),
        interval: Math.max(1, Math.min(999, Number(eventRecurrenceInterval.value) || 1)),
        endMode: eventRecurrenceEndMode.value
    };
    const patternControls = recurrencePatternControls();
    const relativePattern = ['monthly', 'yearly'].includes(frequency)
        && patternControls.mode.value === 'relative';
    if (relativePattern) {
        recurrence.patternMode = 'relative';
        recurrence.relativeIndex = patternControls.index.value;
    }
    if (frequency === 'weekly' || relativePattern) {
        let byDay = Array.from(eventRecurrenceWeekdays.querySelectorAll('input[type="checkbox"]:checked'))
            .map(input => input.value);
        if (!byDay.length) {
            const start = readInputDate(document.getElementById('event-start').value);
            if (start) byDay = [['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'][start.getDay()]];
        }
        recurrence.byDay = byDay;
    }

    const currentStartDate = String(document.getElementById('event-start').value || '').slice(0, 10);
    if (frequency === 'weekly'
        && recurrencePatternContext?.frequency === 'WEEKLY'
        && recurrencePatternContext.weekStart) {
        recurrence.weekStart = recurrencePatternContext.weekStart;
    }
    if (!relativePattern
        && recurrencePatternContext?.frequency === recurrence.frequency
        && recurrencePatternContext.startDate === currentStartDate) {
        if (frequency === 'monthly' && recurrencePatternContext.dayOfMonth > 0) {
            recurrence.dayOfMonth = recurrencePatternContext.dayOfMonth;
        } else if (frequency === 'yearly') {
            if (recurrencePatternContext.dayOfMonth > 0) {
                recurrence.dayOfMonth = recurrencePatternContext.dayOfMonth;
            }
            if (recurrencePatternContext.month > 0) {
                recurrence.month = recurrencePatternContext.month;
            }
        }
    } else if (relativePattern
        && frequency === 'yearly'
        && recurrencePatternContext?.frequency === 'YEARLY'
        && recurrencePatternContext.startDate === currentStartDate
        && recurrencePatternContext.month > 0) {
        recurrence.month = recurrencePatternContext.month;
    }
    if (recurrencePatternContext?.recurrenceTimeZone) {
        recurrence.recurrenceTimeZone = recurrencePatternContext.recurrenceTimeZone;
    }

    if (recurrence.endMode === 'count') {
        recurrence.count = Math.max(1, Math.min(9999, Number(eventRecurrenceCount.value) || 1));
    } else if (recurrence.endMode === 'until') {
        recurrence.until = eventRecurrenceUntil.value;
    }

    return recurrence;
}

function updateRecurrenceWeekdayLabels() {
    const monday = new Date(2026, 7, 17);
    eventRecurrenceWeekdays.querySelectorAll('[data-weekday]').forEach(label => {
        const offset = Math.max(0, Math.min(6, Number(label.dataset.weekday) - 1));
        label.textContent = new Intl.DateTimeFormat(undefined, { weekday: 'short' })
            .format(addDays(monday, offset));
    });
}

function setDialogEditable(editable, descriptionEditable = editable) {
    eventDialogEditable = editable;
    ['event-summary', 'event-all-day', 'event-start', 'event-end', 'event-location'].forEach(id => {
        document.getElementById(id).disabled = !editable;
    });
    document.getElementById('event-description').disabled = !descriptionEditable;
    document.getElementById('save-button').classList.toggle('hidden', !editable);
    updateReminderControls();
    updateAnniversaryControls();
}

function setDateInputs(start, end, allDay, allDayEndExclusive = false) {
    const startInput = document.getElementById('event-start');
    const endInput = document.getElementById('event-end');
    startInput.type = allDay ? 'date' : 'datetime-local';
    endInput.type = allDay ? 'date' : 'datetime-local';
    startInput.value = allDay ? localDate(start) : localDateTime(start);
    startInput.dataset.previousValue = startInput.value;
    const displayEnd = allDay && allDayEndExclusive && end > start ? addDays(end, -1) : end;
    endInput.value = allDay ? localDate(displayEnd) : localDateTime(displayEnd);
}

function updateEndFromStart() {
    const allDay = document.getElementById('event-all-day').checked;
    const startInput = document.getElementById('event-start');
    const endInput = document.getElementById('event-end');
    const start = readInputDate(startInput.value);
    if (!start) return;

    const previousStart = readInputDate(startInput.dataset.previousValue || '');
    let end;
    if (allDay) {
        end = start;
    } else {
        const dateChanged = previousStart && dayKey(previousStart) !== dayKey(start);
        const timeChanged = previousStart
            && (previousStart.getHours() !== start.getHours()
                || previousStart.getMinutes() !== start.getMinutes());
        const currentEnd = readInputDate(endInput.value);

        if (dateChanged && !timeChanged && currentEnd) {
            end = new Date(
                start.getFullYear(),
                start.getMonth(),
                start.getDate(),
                currentEnd.getHours(),
                currentEnd.getMinutes()
            );
            if (end <= start) end = new Date(start.getTime() + 60 * 60 * 1000);
        } else {
            end = new Date(start.getTime() + 60 * 60 * 1000);
        }
    }

    endInput.value = allDay ? localDate(end) : localDateTime(end);
    startInput.dataset.previousValue = startInput.value;
}

function updateDialogColor() {
    const instanceId = Number(eventCalendarInput.value);
    const calendar = calendarState.calendars.find(entry => entry.instanceId === instanceId);
    document.getElementById('dialog-color').style.background = safeColor(calendar?.color);
}

function updateSaveButtonLabel() {
    const targetCalendarInstanceId = Number(eventCalendarInput.value);
    const moving = selectedEvent
        && targetCalendarInstanceId > 0
        && targetCalendarInstanceId !== Number(selectedEvent.calendarInstanceId);
    document.getElementById('save-button').textContent = t(moving ? 'Move' : 'Save');
}

eventForm.addEventListener('submit', async event => {
    event.preventDefault();
    const allDay = document.getElementById('event-all-day').checked;
    const calendarInstanceId = Number(eventCalendarInput.value);
    const eventData = {
        summary: document.getElementById('event-summary').value.trim(),
        description: document.getElementById('event-description').value.trim(),
        location: document.getElementById('event-location').value.trim(),
        allDay,
        start: inputDateValue(document.getElementById('event-start').value, allDay),
        end: inputDateValue(document.getElementById('event-end').value, allDay, allDay)
    };
    const anniversaryChange = anniversaryEditorChange();
    if (anniversaryChange) {
        eventData.anniversaryType = anniversaryChange.enabled ? anniversaryChange.type : '';
        eventData.anniversaryDate = anniversaryChange.enabled ? anniversaryChange.date : '';
        if (anniversaryChange.enabled) {
            eventData.allDay = true;
            eventData.start = anniversaryChange.date;
            const anniversaryStart = readInputDate(anniversaryChange.date);
            eventData.end = anniversaryStart ? localDate(addDays(anniversaryStart, 1)) : '';
            eventData.recurrence = anniversaryRecurrence();
        }
    }
    const reminder = reminderEditorValue();
    if (reminder) {
        eventData.reminder = reminder;
    }
    const editingSeries = Boolean(selectedEvent?.recurring) && selectedEvent?.writeScope === 'series';
    const editingFollowing = Boolean(selectedEvent?.recurring) && selectedEvent?.writeScope === 'following';
    const recurrence = recurrenceEditorValue();
    if (recurrence && !(anniversaryChange?.enabled)) {
        eventData.recurrence = recurrence;
    } else if (!anniversaryChange?.enabled && editingSeries
        && !eventRecurrenceFrequency.disabled
        && eventRecurrenceFrequency.value === 'none'
        && Boolean(selectedCalendarEntry()?.canUpdateRecurrence)) {
        eventData.recurrence = null;
    }
    const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    const timezone = String(
        selectedEvent?.timezone || importedIcsTimezone || selectedCalendarEntry()?.timezone || browserTimezone
    ).trim();
    if (timezone && (!allDay || recurrence || editingSeries || editingFollowing)) {
        eventData.timezone = timezone;
    }
    if (!calendarInstanceId || !eventData.summary || !eventData.start || !eventData.end) return;
    const sourceCalendarInstanceId = Number(selectedEvent?.calendarInstanceId || 0);
    const moving = Boolean(selectedEvent) && calendarInstanceId !== sourceCalendarInstanceId;
    const selectedAnniversaryType = annualEventType(selectedEvent);
    if (moving && selectedAnniversaryType && ['series', 'following'].includes(selectedEvent?.writeScope)) {
        eventData.anniversaryType = selectedAnniversaryType;
        eventData.anniversaryDate = annualEventDate(selectedEvent);
        eventData.allDay = true;
        eventData.recurrence = anniversaryRecurrence();
    }
    if (selectedEvent?.onlineMeeting && !moving) delete eventData.description;

    const action = moving ? 'MoveEvent' : (selectedEvent ? 'UpdateEvent' : 'CreateEvent');
    const value = moving
        ? {
            sourceCalendarInstanceId,
            targetCalendarInstanceId: calendarInstanceId,
            sourceEvent: {
                uid: selectedEvent.uid,
                resourceUrl: selectedEvent.resourceUrl,
                etag: selectedEvent.etag,
                reminder: selectedEvent.reminder || null,
                anniversaryType: annualEventType(selectedEvent),
                anniversaryDate: annualEventDate(selectedEvent),
                ...recurrencePayload(selectedEvent)
            },
            event: eventData
        }
        : (selectedEvent
            ? {
                calendarInstanceId,
                event: {
                    uid: selectedEvent.uid,
                    resourceUrl: selectedEvent.resourceUrl,
                    etag: selectedEvent.etag,
                    ...recurrencePayload(selectedEvent),
                    changes: eventData
                }
            }
            : { calendarInstanceId, event: eventData });

    if (await sendAction(action, value)) {
        eventDialog.close();
    }
});

icsImportButton.addEventListener('click', () => {
    if (selectedEvent !== null) return;
    icsImportFile.value = '';
    icsImportFile.click();
});
icsImportFile.addEventListener('change', async () => {
    const file = icsImportFile.files?.[0] || null;
    if (!file) return;
    icsImportButton.disabled = true;
    try {
        await importIcsFile(file);
        showToast(icsImportText('ICS event imported.'), 'success');
    } catch (error) {
        showToast(error instanceof Error ? error.message : icsImportText('The selected file is not a valid single-event ICS file.'), 'error');
    } finally {
        icsImportButton.disabled = false;
        icsImportFile.value = '';
    }
});

document.getElementById('delete-button').addEventListener('click', () => requestDelete(eventDialog));

document.getElementById('event-start').addEventListener('change', () => {
    updateEndFromStart();
    updateRecurrenceEndDateMinimum();
});

document.getElementById('event-all-day').addEventListener('change', event => {
    const start = readInputDate(document.getElementById('event-start').value) || new Date();
    let end = readInputDate(document.getElementById('event-end').value) || addDays(start, 1);
    if (event.target.checked) {
        if (end < start) end = start;
    } else if (end <= start) {
        end = new Date(start.getTime() + 60 * 60 * 1000);
    }
    setDateInputs(start, end, event.target.checked);
});
eventCalendarInput.addEventListener('change', () => {
    updateDialogColor();
    updateSaveButtonLabel();
    updateRecurrenceAvailability();
    updateAnniversaryControls();
    resolveDefaultReminderForCalendarMove();
    updateReminderControls();
});
eventAnniversaryType.addEventListener('change', () => {
    updateRecurrenceAvailability();
    if (eventAnniversaryType.value) {
        if (!eventAnniversaryDate.value) eventAnniversaryDate.value = suggestedAnniversaryDate();
        syncAnniversarySchedule();
    }
    updateAnniversaryControls();
});
eventAnniversaryDate.addEventListener('change', () => {
    syncAnniversarySchedule();
    updateAnniversaryControls();
});
eventReminderMode.addEventListener('change', () => {
    reminderDefaultResolvedForMove = false;
    updateReminderControls();
});
eventReminderValue.addEventListener('input', () => {
    reminderDefaultResolvedForMove = false;
    updateReminderControls();
});
eventReminderUnit.addEventListener('change', () => {
    reminderDefaultResolvedForMove = false;
    updateReminderControls();
});
eventReminderAddButton.addEventListener('click', () => {
    if (reminderEditorEntries().length >= maxReminderCount()) return;
    appendReminderEditorEntry(nextReminderDefaultMinutes());
    reminderDefaultResolvedForMove = false;
    updateReminderControls();
});
eventReminderExtraList.addEventListener('click', event => {
    const button = event.target.closest('[data-reminder-remove]');
    if (!button) return;
    button.closest('.reminder-extra-entry')?.remove();
    reminderDefaultResolvedForMove = false;
    updateReminderControls();
});
eventReminderExtraList.addEventListener('input', () => {
    reminderDefaultResolvedForMove = false;
    updateReminderControls();
});
eventReminderExtraList.addEventListener('change', () => {
    reminderDefaultResolvedForMove = false;
    updateReminderControls();
});
eventRecurrenceFrequency.addEventListener('change', () => {
    const controls = recurrencePatternControls();
    const frequency = eventRecurrenceFrequency.value.toUpperCase();
    if (!recurrencePatternContext || recurrencePatternContext.frequency !== frequency) {
        controls.mode.value = 'absolute';
        controls.index.value = 'first';
    } else {
        controls.mode.value = recurrencePatternContext.patternMode;
        controls.index.value = recurrencePatternContext.relativeIndex;
    }
    updateRecurrenceControls();
});
eventRecurrenceInterval.addEventListener('input', updateRecurrenceControls);
eventRecurrenceEndMode.addEventListener('change', updateRecurrenceControls);
eventCalendarTrigger.addEventListener('click', event => {
    event.stopPropagation();
    toggleCalendarPicker();
});
eventCalendarTrigger.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        openCalendarPicker(true);
    } else if (event.key === 'Escape') {
        closeCalendarPicker();
    }
});
document.addEventListener('click', event => {
    if (!eventCalendarPicker.contains(event.target)) closeCalendarPicker();
});
eventDialog.addEventListener('cancel', event => {
    if (!eventCalendarOptions.classList.contains('hidden')) {
        event.preventDefault();
        closeCalendarPicker();
        eventCalendarTrigger.focus();
    }
});
eventDialog.addEventListener('close', () => {
    closeCalendarPicker();
    applyDeferredCalendarState();
});
document.getElementById('details-close').addEventListener('click', () => eventDetailsDialog.close());
document.getElementById('details-close-button').addEventListener('click', () => eventDetailsDialog.close());
document.getElementById('details-edit-button').addEventListener('click', () => requestEdit(eventDetailsDialog));
document.getElementById('edit-scope-close').addEventListener('click', () => editScopeDialog.close());
document.getElementById('edit-scope-cancel').addEventListener('click', () => editScopeDialog.close());
editScopeConfirmButton.addEventListener('click', confirmEditScope);
editScopeDialog.addEventListener('close', () => {
    editScopeSourceDialog = null;
});
document.getElementById('details-delete-button').addEventListener('click', () => requestDelete(eventDetailsDialog));
document.getElementById('delete-confirm-close').addEventListener('click', () => deleteConfirmDialog.close());
document.getElementById('delete-confirm-cancel').addEventListener('click', () => deleteConfirmDialog.close());
deleteConfirmButton.addEventListener('click', confirmDeleteEvent);
deleteConfirmDialog.addEventListener('close', () => {
    deleteSourceDialog = null;
});
document.getElementById('day-events-close').addEventListener('click', () => dayEventsDialog.close());
document.getElementById('day-events-close-button').addEventListener('click', () => dayEventsDialog.close());
document.getElementById('day-events-create-button').addEventListener('click', () => {
    const day = selectedDayEventsDate;
    dayEventsDialog.close();
    if (day) openNewEvent(day);
});
document.getElementById('dialog-close').addEventListener('click', () => eventDialog.close());
document.getElementById('cancel-button').addEventListener('click', () => eventDialog.close());
document.getElementById('add-button').addEventListener('click', openNewEvent);
viewSelectorButton.addEventListener('click', openViewSelector);
document.getElementById('view-selector-close').addEventListener('click', () => viewSelectorDialog.close());
document.getElementById('view-selector-close-button').addEventListener('click', () => viewSelectorDialog.close());
viewSelectorDialog.addEventListener('close', () => viewSelectorButton.setAttribute('aria-expanded', 'false'));
document.getElementById('calendar-filter-button').addEventListener('click', openCalendarFilter);
document.getElementById('calendar-filter-close').addEventListener('click', () => calendarFilterDialog.close());
document.getElementById('calendar-filter-cancel').addEventListener('click', () => calendarFilterDialog.close());
document.getElementById('calendar-filter-all').addEventListener('click', () => setPendingCalendarFilter(true));
document.getElementById('calendar-filter-none').addEventListener('click', () => setPendingCalendarFilter(false));
document.getElementById('calendar-filter-apply').addEventListener('click', applyCalendarFilter);
document.getElementById('refresh-button').addEventListener('click', () => sendAction('Refresh', true));
document.getElementById('today-button').addEventListener('click', () => {
    cursorDate = startOfDay(new Date());
    persistClientViewState();
    render();
});
document.getElementById('previous-button').addEventListener('click', () => navigate(-1));
document.getElementById('next-button').addEventListener('click', () => navigate(1));
document.querySelectorAll('.view-selector-option').forEach(button => button.addEventListener('click', () => {
    if (!calendarViews.has(button.dataset.view)) return;
    activeView = button.dataset.view;
    persistClientViewState();
    viewSelectorDialog.close();
    render();
}));
window.addEventListener('resize', () => {
    if (activeView === 'month') scheduleMonthEventLayout();
});
document.addEventListener('wheel', containWheelInsideTile, { capture: true, passive: false });
content.addEventListener('pointerdown', beginSwipeNavigation);
content.addEventListener('pointerup', finishSwipeNavigation);
content.addEventListener('pointercancel', cancelSwipeNavigation);
content.addEventListener('click', suppressClickAfterSwipe, true);

function beginSwipeNavigation(event) {
    if (!swipeNavigationViews.has(activeView)
        || !event.isPrimary
        || !['touch', 'pen'].includes(event.pointerType)
        || calendarDialogIsOpen()
        || swipeNavigationTargetIsInteractive(event.target)) {
        return;
    }

    swipeGesture = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY
    };
    content.setPointerCapture?.(event.pointerId);
}

function finishSwipeNavigation(event) {
    if (!swipeGesture || event.pointerId !== swipeGesture.pointerId) return;

    const deltaX = event.clientX - swipeGesture.startX;
    const deltaY = event.clientY - swipeGesture.startY;
    cancelSwipeNavigation(event);

    if (Math.abs(deltaX) < swipeMinimumDistance
        || Math.abs(deltaX) < Math.abs(deltaY) * swipeAxisRatio) {
        return;
    }

    suppressSwipeClickUntil = Date.now() + 500;
    event.preventDefault();
    navigate(deltaX < 0 ? 1 : -1);
}

function cancelSwipeNavigation(event = null) {
    if (!swipeGesture) return;
    const pointerId = swipeGesture.pointerId;
    swipeGesture = null;
    if (event && content.hasPointerCapture?.(pointerId)) {
        content.releasePointerCapture?.(pointerId);
    }
}

function suppressClickAfterSwipe(event) {
    if (Date.now() > suppressSwipeClickUntil) return;
    suppressSwipeClickUntil = 0;
    event.preventDefault();
    event.stopImmediatePropagation();
}

function swipeNavigationTargetIsInteractive(target) {
    return target instanceof Element
        && Boolean(target.closest('button, a, input, select, textarea, label, .week-event, [role="button"], [contenteditable="true"]'));
}

function calendarDialogIsOpen() {
    return [
        eventDialog,
        eventDetailsDialog,
        editScopeDialog,
        deleteConfirmDialog,
        dayEventsDialog,
        viewSelectorDialog,
        calendarFilterDialog
    ].some(dialog => dialog.open);
}

function containWheelInsideTile(event) {
    if (event.ctrlKey) return;

    const calendarOptionList = event.target instanceof Element
        ? event.target.closest('.calendar-picker-options')
        : null;
    const openDialog = [eventDialog, eventDetailsDialog, editScopeDialog, deleteConfirmDialog, dayEventsDialog, viewSelectorDialog, calendarFilterDialog]
        .find(dialog => dialog.open);
    const scrollTarget = calendarOptionList || openDialog?.querySelector('.dialog-body') || content;
    const factor = event.deltaMode === WheelEvent.DOM_DELTA_LINE
        ? 16
        : (event.deltaMode === WheelEvent.DOM_DELTA_PAGE ? scrollTarget.clientHeight : 1);
    const deltaX = event.deltaX * factor;
    const deltaY = event.deltaY * factor;

    if (event.shiftKey && deltaX === 0) {
        scrollTarget.scrollLeft += deltaY;
    } else {
        scrollTarget.scrollLeft += deltaX;
        scrollTarget.scrollTop += deltaY;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
}

function navigate(direction) {
    if (activeView === 'month') {
        cursorDate = new Date(
            cursorDate.getFullYear(),
            cursorDate.getMonth() + (direction * viewPeriod('month')),
            1
        );
    } else if (activeView === 'threeDays') {
        const firstVisibleDay = getVisibleDays(cursorDate, 1)[0];
        cursorDate = moveVisibleDays(firstVisibleDay, direction * viewPeriod('threeDays'));
    } else if (activeView === 'week') {
        cursorDate = addDays(startOfWeek(cursorDate), direction * viewPeriod('week') * 7);
    } else {
        cursorDate = addDays(cursorDate, direction * viewPeriod(activeView === 'list' ? 'list' : 'agenda'));
    }
    persistClientViewState();
    render();
}

function restoreClientViewState(defaultView) {
    activeView = calendarViews.has(defaultView) ? defaultView : 'agenda';
    cursorDate = startOfDay(new Date());

    const storedState = readClientViewState();
    if (!storedState || typeof storedState !== 'object') return;

    if (calendarViews.has(storedState.activeView)) {
        activeView = storedState.activeView;
    }
    const storedDate = parseStoredViewDate(storedState.cursorDate);
    if (storedDate) {
        cursorDate = storedDate;
    }
    if (Array.isArray(storedState.visibleCalendarIds)) {
        visibleCalendarIds = new Set(
            storedState.visibleCalendarIds
                .map(instanceId => Number(instanceId))
                .filter(instanceId => instanceId > 0)
        );
        normalizeVisibleCalendarIds();
    }
}

function persistClientViewState() {
    if (calendarViewStateStorageKey === '') return;

    const value = JSON.stringify({
        activeView,
        cursorDate: formatStoredViewDate(cursorDate),
        visibleCalendarIds: visibleCalendarIds instanceof Set
            ? Array.from(visibleCalendarIds).sort((left, right) => left - right)
            : null
    });
    try {
        window.localStorage.setItem(calendarViewStateStorageKey, value);
        return;
    } catch (error) {
        // Some embedded WebViews expose an opaque origin and reject Web Storage.
    }
    writeWindowNameViewState(value);
}

function readClientViewState() {
    if (calendarViewStateStorageKey === '') return null;

    let value = null;
    try {
        value = window.localStorage.getItem(calendarViewStateStorageKey);
    } catch (error) {
        // Fall back to window.name for embedded WebViews without Web Storage.
    }
    if (typeof value !== 'string' || value === '') {
        value = readWindowNameViewState();
    }
    if (typeof value !== 'string' || value === '') return null;

    try {
        return JSON.parse(value);
    } catch (error) {
        return null;
    }
}

function writeWindowNameViewState(value) {
    try {
        if (window.name && !window.name.startsWith('OpenCalendar:')) return;
        const state = window.name
            ? JSON.parse(window.name.slice('OpenCalendar:'.length))
            : {};
        state[calendarViewStateStorageKey] = value;
        window.name = `OpenCalendar:${JSON.stringify(state)}`;
    } catch (error) {
        // Client persistence is optional; rendering must continue without it.
    }
}

function readWindowNameViewState() {
    try {
        if (!window.name || !window.name.startsWith('OpenCalendar:')) return null;
        const state = JSON.parse(window.name.slice('OpenCalendar:'.length));
        return typeof state[calendarViewStateStorageKey] === 'string'
            ? state[calendarViewStateStorageKey]
            : null;
    } catch (error) {
        return null;
    }
}

function formatStoredViewDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function parseStoredViewDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
    if (!match) return null;

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    if (date.getFullYear() !== Number(match[1])
        || date.getMonth() !== Number(match[2]) - 1
        || date.getDate() !== Number(match[3])) {
        return null;
    }
    return startOfDay(date);
}

function moveVisibleDays(start, amount) {
    let result = startOfDay(start);
    const direction = amount < 0 ? -1 : 1;
    let remaining = Math.abs(amount);
    while (remaining > 0) {
        result = addDays(result, direction);
        if (calendarState.settings.showWeekends !== false || !isWeekend(result)) {
            remaining--;
        }
    }
    return result;
}

async function sendAction(ident, value) {
    if (typeof requestAction === 'function'
        || (isNativeVisualization() && await waitForNativeActionBridge())) {
        requestAction(ident, typeof value === 'string' ? value : JSON.stringify(value));
        return true;
    }
    if (!hasIPSViewActionBridge()) {
        showToast(t('Action failed.'), 'error');
        return false;
    }

    try {
        const payload = await calendarIPSViewRequest(ident, value);
        handleMessage(payload);
        return true;
    } catch (error) {
        console.error('OpenCalendar IPSView request failed:', error);
        showToast(t(error instanceof Error ? error.message : 'Action failed.'), 'error');
        return false;
    }
}

function hasActionBridge() {
    return isNativeVisualization() || hasIPSViewActionBridge();
}

function isNativeVisualization() {
    return calendarVisualization.mode === 'symcon';
}

async function waitForNativeActionBridge(timeoutMilliseconds = 1500) {
    const deadline = Date.now() + timeoutMilliseconds;
    while (Date.now() < deadline) {
        if (typeof requestAction === 'function') {
            return true;
        }
        await new Promise(resolve => window.setTimeout(resolve, 50));
    }
    return false;
}

function hasIPSViewActionBridge() {
    return Boolean(calendarIPSViewConfig?.endpoint && calendarIPSViewConfig?.token);
}

async function calendarIPSViewRequest(action, value) {
    if (!hasIPSViewActionBridge()) {
        throw new Error(t('Action failed.'));
    }

    const body = new URLSearchParams();
    body.set('token', String(calendarIPSViewConfig.token));
    body.set('action', action);
    body.set('value', JSON.stringify(value));

    const response = await fetch(String(calendarIPSViewConfig.endpoint), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: body.toString(),
        cache: 'no-store',
        credentials: 'same-origin'
    });
    const payload = await response.json();
    if (!response.ok || payload?.Error) {
        throw new Error(payload?.Error || `HTTP ${response.status}`);
    }

    return payload;
}

async function refreshIPSViewState() {
    if (!hasIPSViewActionBridge()) return;

    try {
        handleMessage(await calendarIPSViewRequest('GetState', null));
    } catch (error) {
        console.warn('OpenCalendar IPSView state refresh failed; using embedded state.', error);
    }
}

function applyStaticTranslations() {
    document.querySelectorAll('label[for]').forEach(label => {
        label.textContent = t(label.textContent.trim());
    });
    document.querySelectorAll('[data-i18n]').forEach(element => {
        element.textContent = t(element.dataset.i18n || element.textContent.trim());
    });
    updateRecurrenceWeekdayLabels();
    [
        ['delete-button', 'Delete'],
        ['cancel-button', 'Cancel'],
        ['save-button', 'Save'],
        ['details-delete-button', 'Delete'],
        ['details-close-button', 'Close'],
        ['details-edit-button', 'Edit'],
        ['edit-scope-cancel', 'Cancel'],
        ['edit-scope-confirm', 'Continue'],
        ['delete-confirm-cancel', 'Cancel'],
        ['delete-confirm-button', 'Delete'],
        ['day-events-create-button', 'Create event on this day'],
        ['day-events-close-button', 'Close'],
        ['view-selector-close-button', 'Close'],
        ['calendar-filter-all', 'Select all'],
        ['calendar-filter-none', 'Select none'],
        ['calendar-filter-cancel', 'Cancel'],
        ['calendar-filter-apply', 'Apply']
    ].forEach(([id, text]) => { document.getElementById(id).textContent = t(text); });
    icsImportButton.textContent = icsImportText('Import ICS');
    icsImportButton.title = icsImportText('Import ICS');
    icsImportButton.setAttribute('aria-label', icsImportText('Import ICS'));
    document.getElementById('all-day-label').textContent = t('All day');
    document.getElementById('dialog-title').textContent = t('Event');
    document.getElementById('details-dialog-title').textContent = t('Event details');
    document.getElementById('edit-scope-dialog-title').textContent = t('Edit recurring event');
    document.getElementById('edit-scope-question').textContent = t('Which events do you want to edit?');
    document.getElementById('delete-confirm-dialog-title').textContent = t('Delete event');
    document.getElementById('delete-confirm-question').textContent = t('Do you really want to delete this event?');
    document.getElementById('day-events-dialog-title').textContent = t('Day events');
    document.getElementById('view-selector-dialog-title').textContent = t('View');
    updateViewSelectorOptions();
    document.getElementById('calendar-filter-dialog-title').textContent = t('Filter calendars');
    document.getElementById('calendar-filter-note').textContent = t('This filter only changes the current view on this browser or monitor.');
    ['calendar', 'occasion', 'start', 'end', 'location', 'description'].forEach(name => {
        const label = document.getElementById(`details-${name}-label`);
        label.textContent = t(label.textContent.trim());
    });
    document.getElementById('add-button-label').textContent = t('New event');
    [
        ['dialog-close', 'Close'],
        ['details-close', 'Close'],
        ['edit-scope-close', 'Close'],
        ['delete-confirm-close', 'Close'],
        ['day-events-close', 'Close'],
        ['view-selector-close', 'Close'],
        ['calendar-filter-close', 'Close'],
        ['add-button', 'Create event']
    ].forEach(([id, text]) => {
        const button = document.getElementById(id);
        button.title = t(text);
        button.setAttribute('aria-label', t(text));
    });
}

function showToast(message, level) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'toast visible' + (level === 'error' ? ' error' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.className = 'toast'; }, 3200);
}

function applySymconColorScheme() {
    const suppliedColor = getComputedStyle(document.body).getPropertyValue('--content-color').trim();
    if (!suppliedColor) return;

    const probe = document.createElement('span');
    probe.style.color = 'var(--content-color)';
    probe.style.display = 'none';
    document.body.appendChild(probe);
    const components = getComputedStyle(probe).color.match(/[\d.]+/g);
    probe.remove();
    if (!components || components.length < 3) return;

    const luminance = (299 * Number(components[0]) + 587 * Number(components[1]) + 114 * Number(components[2])) / 1000;
    document.documentElement.style.colorScheme = luminance < 128 ? 'light' : 'dark';
}

function t(value) {
    try {
        if (calendarTranslations[value]) return calendarTranslations[value];
        return typeof translate === 'function' ? translate(value) : value;
    }
    catch (error) { return value; }
}
function element(tag, className) { const node = document.createElement(tag); node.className = className; return node; }
function startOfDay(date) { return new Date(date.getFullYear(), date.getMonth(), date.getDate()); }
function startOfWeek(date) { const result = startOfDay(date); const day = result.getDay() || 7; result.setDate(result.getDate() - day + 1); return result; }
function isoWeek(date) {
    const target = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const weekday = target.getUTCDay() || 7;
    target.setUTCDate(target.getUTCDate() + 4 - weekday);
    const year = target.getUTCFullYear();
    const yearStart = new Date(Date.UTC(year, 0, 1));
    return {
        year,
        week: Math.ceil((((target - yearStart) / 86400000) + 1) / 7)
    };
}
function isoWeekNumber(date) { return isoWeek(date).week; }
function isoWeekKey(date) {
    const value = isoWeek(date);
    return `${value.year}-${value.week}`;
}
function formatCalendarWeekLabel(days) {
    const calendarWeeks = [];
    const seen = new Set();
    days.forEach(day => {
        const key = isoWeekKey(day);
        if (seen.has(key)) return;
        seen.add(key);
        calendarWeeks.push(isoWeekNumber(day));
    });
    return `${t('CW')} ${calendarWeeks.join('/')}`;
}
function dayOfYear(date) {
    return Math.floor((
        Date.UTC(date.getFullYear(), date.getMonth(), date.getDate())
        - Date.UTC(date.getFullYear(), 0, 0)
    ) / 86400000);
}
function daysInYear(date) {
    const year = date.getFullYear();
    return new Date(year, 1, 29).getMonth() === 1 ? 366 : 365;
}
function formatDayHeading(date, options, showDayOfYear, eventCount, showEventCount) {
    const formattedDate = new Intl.DateTimeFormat(undefined, options).format(date);
    const parts = [formattedDate];
    if (showDayOfYear) {
        parts.push(`${t('Day')} ${dayOfYear(date)}/${daysInYear(date)}`);
    }
    if (showEventCount) {
        parts.push(`${eventCount} ${t(eventCount === 1 ? 'Event' : 'Events')}`);
    }
    return parts.join(' · ');
}
function addDays(date, days) { const result = new Date(date); result.setDate(result.getDate() + days); return result; }
function isWeekend(date) { return date.getDay() === 0 || date.getDay() === 6; }
function isToday(date) { return dayKey(date) === dayKey(new Date()); }
function dayKey(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`; }
function localDate(date) { return dayKey(date); }
function localDateTime(date) { return `${localDate(date)}T${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`; }
function readInputDate(value) {
    const dateOnly = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (dateOnly) return new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]));
    const date = value ? new Date(value) : null;
    return date && !Number.isNaN(date.getTime()) ? date : null;
}
function inputDateValue(value, allDay, exclusiveEnd = false) {
    if (!value) return '';
    if (!allDay) return new Date(value).toISOString();
    const date = readInputDate(value.slice(0, 10));
    if (!date) return '';
    return localDate(exclusiveEnd ? addDays(date, 1) : date);
}
function eventStart(event) {
    return event.allDay ? allDayDate(event.start, event.startTimestamp) : new Date(Number(event.startTimestamp || 0) * 1000);
}
function eventEnd(event) {
    return event.allDay
        ? allDayDate(event.end, event.endTimestamp || event.startTimestamp)
        : new Date(Number(event.endTimestamp || event.startTimestamp || 0) * 1000);
}
function allDayDate(value, fallbackTimestamp) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (match) {
        return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    }
    return new Date(Number(fallbackTimestamp || 0) * 1000);
}
function eventOverlaps(event, start, end) { const eventStartDate = eventStart(event); let eventEndDate = eventEnd(event); if (eventEndDate <= eventStartDate) eventEndDate = new Date(eventStartDate.getTime() + 1); return eventStartDate < end && eventEndDate > start; }
function formatTime(date) { return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(date); }
function viewPeriod(view) {
    const periods = {
        agenda: ['agendaPeriodDays', 14, 1, 366],
        list: ['listPeriodDays', 14, 1, 366],
        threeDays: ['threeDaysPeriodDays', 3, 1, 31],
        week: ['weekPeriodWeeks', 1, 1, 12],
        month: ['monthPeriodMonths', 1, 1, 12]
    };
    const [setting, fallback, minimum, maximum] = periods[view] || periods.agenda;
    const value = Number(calendarState.settings[setting]);
    return Number.isFinite(value) ? Math.max(minimum, Math.min(maximum, Math.round(value))) : fallback;
}
function formatViewPeriod(view) {
    const count = viewPeriod(view);
    const units = {
        agenda: ['Day', 'Days'],
        list: ['Day', 'Days'],
        threeDays: ['Day', 'Days'],
        week: ['Week', 'Weeks'],
        month: ['Month', 'Months']
    };
    const [singular, plural] = units[view] || units.agenda;
    return `${count} ${t(count === 1 ? singular : plural)}`;
}
function daysBetween(start, end) {
    const days = [];
    let current = startOfDay(start);
    const last = startOfDay(end);
    while (current <= last) {
        days.push(current);
        current = addDays(current, 1);
    }
    return days;
}
function formatMonth(date) {
    return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' }).format(date);
}
function formatRange(start, end) { return new Intl.DateTimeFormat(undefined, { day: '2-digit', month: 'short' }).format(start) + ' – ' + new Intl.DateTimeFormat(undefined, { day: '2-digit', month: 'short', year: 'numeric' }).format(end); }
function safeColor(value) {
    if (/^#[0-9a-f]{6}$/i.test(value || '')) return value;
    return getComputedStyle(document.documentElement).getPropertyValue('--cal-accent').trim() || 'currentColor';
}

if (calendarVisualization.state && typeof calendarVisualization.state === 'object') {
    handleMessage({ type: 'state', payload: calendarVisualization.state });
}

if (calendarVisualization.mode === 'ipsview') {
    void refreshIPSViewState();
}
