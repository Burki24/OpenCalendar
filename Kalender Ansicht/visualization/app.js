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
let deleteSourceDialog = null;
let visibleCalendarIds = null;
let pendingCalendarFilterIds = new Set();
let toastTimer = null;
let monthLayoutFrame = null;
let selectedDayEventsDate = null;
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
const eventCalendarInput = document.getElementById('event-calendar');
const eventCalendarPicker = document.getElementById('event-calendar-picker');
const eventCalendarTrigger = document.getElementById('event-calendar-trigger');
const eventCalendarValue = document.getElementById('event-calendar-value');
const eventCalendarOptions = document.getElementById('event-calendar-options');
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
    calendarState = message.payload;
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


function applyTileFontScale() {
    if (calendarVisualization.mode === 'ipsview') return;
    const scale = Math.max(50, Math.min(200, Number(calendarState.settings.tileFontScale) || 100));
    document.documentElement.style.fontSize = `${scale}%`;
}

function render() {
    updateToolbar();
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

function createAgendaEvent(event) {
    const card = element('button', 'event-card');
    card.type = 'button';
    card.addEventListener('click', () => openEventDetails(event));
    const color = element('span', 'event-color');
    color.style.background = safeColor(event.calendarColor);
    const time = element('span', 'event-time');
    time.textContent = event.allDay ? t('All day') : formatTime(eventStart(event)) + '\n' + formatTime(eventEnd(event));
    time.style.whiteSpace = 'pre-line';
    const main = element('span', 'event-main');
    const title = element('span', 'event-title');
    title.textContent = event.summary || t('Untitled event');
    main.appendChild(title);
    const metaParts = [];
    if (calendarState.settings.showCalendarName) metaParts.push(event.calendarName || '');
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
            value: event => event.summary || t('Untitled event')
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
            title.textContent = event.summary || t('Untitled event');
            const time = document.createElement('span');
            time.textContent = event.allDay ? t('All day') : formatTime(eventStart(event));
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
    chip.textContent = (event.allDay ? '' : formatTime(eventStart(event)) + ' ')
        + (event.summary || t('Untitled event'));
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
        summary.textContent = event.summary || t('Untitled event');
        item.append(time, summary);
        if (calendarState.settings.showCalendarName !== false && event.calendarName) {
            const calendar = element('span', 'day-event-calendar');
            calendar.textContent = event.calendarName;
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

function openNewEvent(preferredDay = null) {
    const writable = calendarState.calendars.filter(calendar => calendar.canWrite);
    if (!writable.length) return;
    selectedEvent = null;
    populateCalendarSelect(writable, writable[0].instanceId);
    document.getElementById('dialog-title').textContent = t('Create event');
    document.getElementById('event-summary').value = '';
    document.getElementById('event-location').value = '';
    document.getElementById('event-description').value = '';
    document.getElementById('event-all-day').checked = false;
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
    document.getElementById('details-summary').textContent = event.summary || t('Untitled event');
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
    document.getElementById('delete-confirm-summary').textContent = selectedEvent.summary || t('Untitled event');
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

function selectedCalendarEntry() {
    const instanceId = Number(eventCalendarInput.value);
    return calendarState.calendars.find(calendar => Number(calendar.instanceId) === instanceId) || null;
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
    ['event-summary', 'event-all-day', 'event-start', 'event-end', 'event-location'].forEach(id => {
        document.getElementById(id).disabled = !editable;
    });
    document.getElementById('event-description').disabled = !descriptionEditable;
    document.getElementById('save-button').classList.toggle('hidden', !editable);
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
    const editingSeries = Boolean(selectedEvent?.recurring) && selectedEvent?.writeScope === 'series';
    const editingFollowing = Boolean(selectedEvent?.recurring) && selectedEvent?.writeScope === 'following';
    const recurrence = recurrenceEditorValue();
    if (recurrence) {
        eventData.recurrence = recurrence;
    } else if (editingSeries
        && !eventRecurrenceFrequency.disabled
        && eventRecurrenceFrequency.value === 'none'
        && Boolean(selectedCalendarEntry()?.canUpdateRecurrence)) {
        eventData.recurrence = null;
    }
    const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    const timezone = String(
        selectedEvent?.timezone || selectedCalendarEntry()?.timezone || browserTimezone
    ).trim();
    if (timezone && (!allDay || recurrence || editingSeries || editingFollowing)) {
        eventData.timezone = timezone;
    }
    if (!calendarInstanceId || !eventData.summary || !eventData.start || !eventData.end) return;
    const sourceCalendarInstanceId = Number(selectedEvent?.calendarInstanceId || 0);
    const moving = Boolean(selectedEvent) && calendarInstanceId !== sourceCalendarInstanceId;
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
eventDialog.addEventListener('close', closeCalendarPicker);
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
    ['calendar', 'start', 'end', 'location', 'description'].forEach(name => {
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
