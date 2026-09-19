'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, 'Missing function: ' + name);
    return source.slice(start, source.indexOf('\n}', start) + 2);
}
for (const mode of ['ipsview', 'symcon']) {
    const fields = new Map();
    const handlers = new Map();
    const field = id => {
        if (!fields.has(id)) fields.set(id, {
            value: '', checked: false, disabled: false,
            classList: {add() {}, remove() {}, toggle() {}},
            addEventListener(type, callback) { handlers.set(id + ':' + type, callback); }
        });
        return fields.get(id);
    };
    const context = vm.createContext({
        Date, calendarVisualization: {mode},
        calendarState: {calendars: [{instanceId: 1, canWrite: true}]},
        cursorDate: new Date(2026, 8, 19), selectedEvent: null,
        selectedDayEventsDate: new Date(2026, 8, 22),
        document: {getElementById: field},
        eventTask: field('task'), eventTaskCompleted: field('completed'),
        eventTaskRollForwardScope: field('scope'),
        eventStatusInput: field('status'), eventAvailabilityInput: field('availability'),
        eventRecurrenceFrequency: field('frequency'), eventRecurrenceOptions: field('options'),
        eventReminderMode: field('reminder'), icsImportFile: field('file'),
        icsImportButton: field('import'), calendarCanImportIcsFile: true,
        dayEventsDialog: {close() { context.dayClosed = true; }},
        t: value => value, startOfDay: date => new Date(date.getFullYear(), date.getMonth(), date.getDate()),
        dayKey: date => date.toDateString(),
        setDateInputs: (start, end) => { context.dates = [start, end]; },
        showEventDialog: () => { context.opened = true; },
        deferEventDialogSetup: callback => callback(),
        normalizedEventStatus: value => value || 'CONFIRMED',
        normalizedEventTransparency: value => value || 'OPAQUE'
    });
    for (const name of ['beginAgendaScrollWorkflow', 'populateCalendarSelect', 'resetEventStateEditor',
        'resetAnniversaryEditor', 'clearExtraReminderEntries', 'setDialogEditable', 'updateDialogColor',
        'updateSaveButtonLabel', 'setEventDialogLoading', 'resetRecurrenceEditor', 'resetReminderEditor',
        'loadReminderEditor', 'updateRecurrenceAvailability', 'updateTaskControls', 'updateAnniversaryControls',
        'updateReminderControls', 'updateEventStateControls']) {
        context[name] = () => {};
    }
    for (const name of ['openNewEvent', 'applyImportedIcsEvent']) vm.runInContext(extract(name), context);
    const first = source.indexOf("document.getElementById('day-events-create-button').addEventListener");
    const last = source.indexOf("document.getElementById('add-button').addEventListener", first);
    vm.runInContext(source.slice(first, source.indexOf('\n', last)), context);
    context.eventTaskRollForwardScope.value = 'following';
    handlers.get('add-button:click')({type: 'click'});
    assert(context.opened, mode + ': toolbar must open the editor without a ReferenceError');
    assert.strictEqual(context.eventTaskRollForwardScope.value, 'occurrence');
    context.opened = false;
    handlers.get('day-events-create-button:click')();
    assert(context.dayClosed && context.opened, mode + ': day details must transition to the editor');
    assert.strictEqual(context.dates[0].getDate(), 22, 'Selected day must reach the editor');
    context.eventTaskRollForwardScope.value = 'following';
    context.applyImportedIcsEvent({summary: 'Imported', start: new Date(2026, 8, 23), end: new Date(2026, 8, 24), allDay: true});
    assert.strictEqual(context.eventTaskRollForwardScope.value, 'occurrence', 'Import must reset the current task control');
    assert.strictEqual(field('event-summary').value, 'Imported');
}
console.log('Event editor opening tests passed.');
