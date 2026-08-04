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
document.documentElement.style.setProperty('--agenda-color-bar-width', `${calendarAgendaColorBarWidth}px`);
document.documentElement.style.setProperty('--compact-color-bar-width', `${calendarCompactColorBarWidth}px`);

let calendarState = { events: [], calendars: [], settings: {} };
let activeView = 'agenda';
let cursorDate = startOfDay(new Date());
let initialized = false;
let selectedEvent = null;
let toastTimer = null;

const content = document.getElementById('calendar-content');
const periodTitle = document.getElementById('period-title');
const eventDialog = document.getElementById('event-dialog');
const eventForm = document.getElementById('event-form');
const eventCalendarInput = document.getElementById('event-calendar');
const eventCalendarPicker = document.getElementById('event-calendar-picker');
const eventCalendarTrigger = document.getElementById('event-calendar-trigger');
const eventCalendarValue = document.getElementById('event-calendar-value');
const eventCalendarOptions = document.getElementById('event-calendar-options');

applySymconColorScheme();

function handleMessage(data) {
    let message = data;
    if (typeof message === 'string') {
        try { message = JSON.parse(message); } catch (error) { return; }
    }
    if (!message || typeof message !== 'object') return;
    if (message.toast && typeof message.toast === 'object') {
        showToast(t(message.toast.message || ''), message.toast.level || 'success');
    }
    if (message.type === 'toast') {
        showToast(t(message.message || ''), message.level || 'success');
        return;
    }
    if (message.type !== 'state' || !message.payload) return;
    calendarState = message.payload;
    calendarState.events = Array.isArray(calendarState.events) ? calendarState.events : [];
    calendarState.calendars = Array.isArray(calendarState.calendars) ? calendarState.calendars : [];
    calendarState.settings = calendarState.settings || {};
    if (!initialized) {
        activeView = calendarState.settings.defaultView || 'agenda';
        applyStaticTranslations();
        initialized = true;
    }
    render();
}

function render() {
    updateToolbar();
    content.replaceChildren();
    if (calendarState.calendars.length === 0) {
        renderEmpty('No calendars selected', 'Select at least one calendar in the instance configuration.');
    } else if (activeView === 'month') {
        renderMonth();
    } else if (activeView === 'week') {
        renderWeek();
    } else if (activeView === 'threeDays') {
        renderThreeDays();
    } else {
        renderAgenda();
    }
    const actionBridgeAvailable = hasActionBridge();
    const hasWritableCalendar = actionBridgeAvailable
        && calendarState.calendars.some(calendar => calendar.canWrite);
    const addButton = document.getElementById('add-button');
    addButton.classList.toggle('visible', actionBridgeAvailable);
    addButton.disabled = !hasWritableCalendar;
    addButton.setAttribute('aria-disabled', String(!hasWritableCalendar));
    const addButtonText = hasWritableCalendar ? 'Create event' : 'No writable calendar available';
    addButton.title = t(addButtonText);
    addButton.setAttribute('aria-label', t(addButtonText));
}

function updateToolbar() {
    const viewLabels = { agenda: 'Agenda', threeDays: '3 Days', week: 'Week', month: 'Month' };
    document.querySelectorAll('.view-button').forEach(button => {
        button.classList.toggle('active', button.dataset.view === activeView);
        button.textContent = t(viewLabels[button.dataset.view] || button.dataset.view);
    });
    [
        ['previous-button', 'Previous'],
        ['today-button', 'Today'],
        ['next-button', 'Next'],
        ['refresh-button', 'Refresh']
    ].forEach(([id, text]) => {
        const button = document.getElementById(id);
        button.title = t(text);
        button.setAttribute('aria-label', t(text));
    });

    if (activeView === 'month') {
        periodTitle.textContent = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' }).format(cursorDate);
    } else if (activeView === 'week') {
        const start = startOfWeek(cursorDate);
        const end = addDays(start, 6);
        periodTitle.textContent = `${t('CW')} ${isoWeekNumber(start)} · ${formatRange(start, end)}`;
    } else if (activeView === 'threeDays') {
        const days = getThreeVisibleDays(cursorDate);
        periodTitle.textContent = formatRange(days[0], days[days.length - 1]);
    } else {
        const end = addDays(cursorDate, 13);
        periodTitle.textContent = formatRange(cursorDate, end);
    }
}

function renderAgenda() {
    const rangeStart = startOfDay(cursorDate);
    const rangeEnd = addDays(rangeStart, 14);
    const events = calendarState.events.filter(event => eventOverlaps(event, rangeStart, rangeEnd));
    const groups = new Map();
    events.forEach(event => {
        const date = eventStart(event);
        const key = dayKey(date);
        if (!groups.has(key)) groups.set(key, { date: startOfDay(date), events: [] });
        groups.get(key).events.push(event);
    });
    if (groups.size === 0) {
        renderEmpty('No events', 'There are no events in this period.');
        return;
    }
    groups.forEach(group => {
        const section = element('section', 'agenda-day');
        const heading = element('div', 'agenda-date');
        const strong = document.createElement('strong');
        strong.textContent = relativeDay(group.date);
        const fullDate = document.createElement('span');
        fullDate.textContent = formatDayHeading(
            group.date,
            { weekday: 'long', day: '2-digit', month: 'long' }
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
    card.addEventListener('click', () => openExistingEvent(event));
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

function renderWeek() {
    const weekStart = startOfWeek(cursorDate);
    const days = Array.from({ length: 7 }, (_, index) => addDays(weekStart, index))
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
            + (vertical ? ' vertical-week-grid' : '')
    );
}

function renderThreeDays() {
    renderDayColumns(getThreeVisibleDays(cursorDate), 'week-grid three-day-grid');
}

function renderDayColumns(days, className) {
    const grid = element('div', className);
    days.forEach(day => {
        const column = element('section', 'week-column' + (isToday(day) ? ' today' : ''));
        const heading = element('div', 'week-heading');
        heading.textContent = formatDayHeading(
            day,
            { weekday: 'short', day: '2-digit', month: '2-digit' }
        );
        column.appendChild(heading);
        const eventList = element('div', 'week-events');
        const dayEnd = addDays(day, 1);
        const events = calendarState.events.filter(event => eventOverlaps(event, day, dayEnd));
        events.forEach(event => {
            const item = element('div', 'week-event');
            item.style.setProperty('--event-color', safeColor(event.calendarColor));
            item.tabIndex = 0;
            item.addEventListener('click', () => openExistingEvent(event));
            item.addEventListener('keydown', key => { if (key.key === 'Enter') openExistingEvent(event); });
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
}

function getThreeVisibleDays(start) {
    const days = [];
    let day = startOfDay(start);
    while (days.length < 3) {
        if (calendarState.settings.showWeekends !== false || !isWeekend(day)) {
            days.push(day);
        }
        day = addDays(day, 1);
    }
    return days;
}

function renderMonth() {
    const first = new Date(cursorDate.getFullYear(), cursorDate.getMonth(), 1);
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
        if (day.getMonth() !== cursorDate.getMonth()) cell.classList.add('outside');
        if (isToday(day)) cell.classList.add('today');
        const number = element('div', 'day-number');
        number.textContent = String(day.getDate());
        cell.appendChild(number);
        const dayEnd = addDays(day, 1);
        const events = calendarState.events.filter(event => eventOverlaps(event, day, dayEnd));
        events.slice(0, 3).forEach(event => {
            const chip = element('button', 'event-chip');
            chip.type = 'button';
            chip.style.setProperty('--event-color', safeColor(event.calendarColor));
            chip.textContent = (event.allDay ? '' : formatTime(eventStart(event)) + ' ') + (event.summary || t('Untitled event'));
            chip.addEventListener('click', () => openExistingEvent(event));
            cell.appendChild(chip);
        });
        if (events.length > 3) {
            const more = element('div', 'more-events');
            more.textContent = '+' + (events.length - 3) + ' ' + t('more');
            cell.appendChild(more);
        }
        grid.appendChild(cell);
    });
    content.appendChild(grid);
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

function openNewEvent() {
    const writable = calendarState.calendars.filter(calendar => calendar.canWrite);
    if (!writable.length) return;
    selectedEvent = null;
    populateCalendarSelect(writable, writable[0].instanceId);
    document.getElementById('dialog-title').textContent = t('Create event');
    document.getElementById('event-summary').value = '';
    document.getElementById('event-location').value = '';
    document.getElementById('event-description').value = '';
    document.getElementById('event-all-day').checked = false;
    const start = new Date(Math.max(Date.now(), cursorDate.getTime()));
    start.setMinutes(0, 0, 0);
    start.setHours(start.getHours() + 1);
    const end = new Date(start.getTime() + 60 * 60 * 1000);
    setDateInputs(start, end, false);
    setDialogEditable(true);
    document.getElementById('delete-button').classList.add('hidden');
    document.getElementById('dialog-note').classList.add('hidden');
    updateDialogColor();
    eventDialog.showModal();
}

function openExistingEvent(event) {
    selectedEvent = event;
    populateCalendarSelect(calendarState.calendars, event.calendarInstanceId);
    setCalendarSelectDisabled(true);
    document.getElementById('dialog-title').textContent = t('Event details');
    document.getElementById('event-summary').value = event.summary || '';
    document.getElementById('event-location').value = event.location || '';
    document.getElementById('event-description').value = event.description || '';
    document.getElementById('event-all-day').checked = Boolean(event.allDay);
    setDateInputs(eventStart(event), eventEnd(event), Boolean(event.allDay));
    const editable = hasActionBridge()
        && Boolean(event.canWrite) && !event.recurring && !event.recurrenceId;
    const descriptionEditable = editable && !Boolean(event.onlineMeeting);
    setDialogEditable(editable, descriptionEditable);
    document.getElementById('delete-button').classList.toggle('hidden', !editable);
    const note = document.getElementById('dialog-note');
    if (!editable) {
        note.textContent = !hasActionBridge()
            ? t('Editing events is unavailable because no action bridge is configured.')
            : (event.recurring || event.recurrenceId
                ? t('Recurring occurrences are currently read-only.')
                : t('This calendar is read-only.'));
        note.classList.remove('hidden');
    } else if (!descriptionEditable) {
        note.textContent = t('The description of Microsoft online meetings is protected and cannot be edited here.');
        note.classList.remove('hidden');
    } else {
        note.classList.add('hidden');
    }
    updateDialogColor();
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

function setDialogEditable(editable, descriptionEditable = editable) {
    ['event-summary', 'event-all-day', 'event-start', 'event-end', 'event-location'].forEach(id => {
        document.getElementById(id).disabled = !editable;
    });
    document.getElementById('event-description').disabled = !descriptionEditable;
    document.getElementById('save-button').classList.toggle('hidden', !editable);
}

function setDateInputs(start, end, allDay) {
    const startInput = document.getElementById('event-start');
    const endInput = document.getElementById('event-end');
    startInput.type = allDay ? 'date' : 'datetime-local';
    endInput.type = allDay ? 'date' : 'datetime-local';
    startInput.value = allDay ? localDate(start) : localDateTime(start);
    endInput.value = allDay ? localDate(end) : localDateTime(end);
}

function updateDialogColor() {
    const instanceId = Number(eventCalendarInput.value);
    const calendar = calendarState.calendars.find(entry => entry.instanceId === instanceId);
    document.getElementById('dialog-color').style.background = safeColor(calendar?.color);
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
        end: inputDateValue(document.getElementById('event-end').value, allDay)
    };
    if (!calendarInstanceId || !eventData.summary || !eventData.start || !eventData.end) return;
    if (selectedEvent?.onlineMeeting) delete eventData.description;

    const action = selectedEvent ? 'UpdateEvent' : 'CreateEvent';
    const value = selectedEvent
        ? {
            calendarInstanceId,
            event: {
                uid: selectedEvent.uid,
                resourceUrl: selectedEvent.resourceUrl,
                etag: selectedEvent.etag,
                recurrenceId: selectedEvent.recurrenceId || '',
                changes: eventData
            }
        }
        : { calendarInstanceId, event: eventData };

    if (await sendAction(action, value)) {
        eventDialog.close();
    }
});

document.getElementById('delete-button').addEventListener('click', async () => {
    if (!selectedEvent || !confirm(t('Delete this event?'))) return;
    const success = await sendAction('DeleteEvent', {
        calendarInstanceId: selectedEvent.calendarInstanceId,
        event: {
            resourceUrl: selectedEvent.resourceUrl,
            etag: selectedEvent.etag,
            recurrenceId: selectedEvent.recurrenceId || ''
        }
    });
    if (success) {
        eventDialog.close();
    }
});

document.getElementById('event-all-day').addEventListener('change', event => {
    const start = readInputDate(document.getElementById('event-start').value) || new Date();
    let end = readInputDate(document.getElementById('event-end').value) || addDays(start, 1);
    if (event.target.checked && end <= start) end = addDays(start, 1);
    setDateInputs(start, end, event.target.checked);
});
eventCalendarInput.addEventListener('change', updateDialogColor);
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
document.getElementById('dialog-close').addEventListener('click', () => eventDialog.close());
document.getElementById('cancel-button').addEventListener('click', () => eventDialog.close());
document.getElementById('add-button').addEventListener('click', openNewEvent);
document.getElementById('refresh-button').addEventListener('click', () => sendAction('Refresh', true));
document.getElementById('today-button').addEventListener('click', () => { cursorDate = startOfDay(new Date()); render(); });
document.getElementById('previous-button').addEventListener('click', () => navigate(-1));
document.getElementById('next-button').addEventListener('click', () => navigate(1));
document.querySelectorAll('.view-button').forEach(button => button.addEventListener('click', () => {
    activeView = button.dataset.view;
    render();
}));
document.addEventListener('wheel', containWheelInsideTile, { capture: true, passive: false });

function containWheelInsideTile(event) {
    if (event.ctrlKey) return;

    const calendarOptionList = event.target instanceof Element
        ? event.target.closest('.calendar-picker-options')
        : null;
    const scrollTarget = calendarOptionList || (eventDialog.open
        ? document.querySelector('.dialog-body')
        : content);
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
    if (activeView === 'month') cursorDate = new Date(cursorDate.getFullYear(), cursorDate.getMonth() + direction, 1);
    else if (activeView === 'threeDays') cursorDate = moveVisibleDays(getThreeVisibleDays(cursorDate)[0], direction * 3);
    else cursorDate = addDays(cursorDate, direction * (activeView === 'week' ? 7 : 14));
    render();
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
    [
        ['delete-button', 'Delete'],
        ['cancel-button', 'Cancel'],
        ['save-button', 'Save']
    ].forEach(([id, text]) => { document.getElementById(id).textContent = t(text); });
    document.getElementById('all-day-label').textContent = t('All day');
    document.getElementById('dialog-title').textContent = t('Event');
    document.getElementById('add-button-label').textContent = t('New event');
    [
        ['dialog-close', 'Close'],
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
function isoWeekNumber(date) {
    const target = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const weekday = target.getUTCDay() || 7;
    target.setUTCDate(target.getUTCDate() + 4 - weekday);
    const yearStart = new Date(Date.UTC(target.getUTCFullYear(), 0, 1));
    return Math.ceil((((target - yearStart) / 86400000) + 1) / 7);
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
function formatDayHeading(date, options) {
    const formattedDate = new Intl.DateTimeFormat(undefined, options).format(date);
    if (calendarState.settings.showDayOfYear === false) return formattedDate;
    return `${formattedDate} · ${t('Day')} ${dayOfYear(date)}/${daysInYear(date)}`;
}
function addDays(date, days) { const result = new Date(date); result.setDate(result.getDate() + days); return result; }
function isWeekend(date) { return date.getDay() === 0 || date.getDay() === 6; }
function isToday(date) { return dayKey(date) === dayKey(new Date()); }
function dayKey(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`; }
function localDate(date) { return dayKey(date); }
function localDateTime(date) { return `${localDate(date)}T${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`; }
function readInputDate(value) { const date = value ? new Date(value) : null; return date && !Number.isNaN(date.getTime()) ? date : null; }
function inputDateValue(value, allDay) { if (!value) return ''; return allDay ? value.slice(0, 10) : new Date(value).toISOString(); }
function eventStart(event) { return new Date(Number(event.startTimestamp || 0) * 1000); }
function eventEnd(event) { return new Date(Number(event.endTimestamp || event.startTimestamp || 0) * 1000); }
function eventOverlaps(event, start, end) { const eventStartDate = eventStart(event); let eventEndDate = eventEnd(event); if (eventEndDate <= eventStartDate) eventEndDate = new Date(eventStartDate.getTime() + 1); return eventStartDate < end && eventEndDate > start; }
function formatTime(date) { return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(date); }
function formatRange(start, end) { return new Intl.DateTimeFormat(undefined, { day: '2-digit', month: 'short' }).format(start) + ' – ' + new Intl.DateTimeFormat(undefined, { day: '2-digit', month: 'short', year: 'numeric' }).format(end); }
function relativeDay(date) { if (isToday(date)) return t('Today'); if (dayKey(date) === dayKey(addDays(new Date(), 1))) return t('Tomorrow'); if (dayKey(date) === dayKey(addDays(new Date(), -1))) return t('Yesterday'); return new Intl.DateTimeFormat(undefined, { weekday: 'long' }).format(date); }
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
