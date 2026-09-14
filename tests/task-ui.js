'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
const markup = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/index.html'), 'utf8');

function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, 'Missing UI function: ' + name);
    const end = source.indexOf('\n}', start);
    assert(end > start, 'Missing UI function end: ' + name);
    return source.slice(source.slice(start - 6, start) === 'async ' ? start - 6 : start, end + 2);
}
for (const id of ['event-task', 'event-task-completed', 'event-task-follow-planned', 'details-task-toggle-button',
    'event-status', 'event-availability', 'details-status', 'details-availability']) {
    assert.strictEqual(markup.split('id="' + id + '"').length - 1, 1, 'Expected one accessible control: ' + id);
}
const calls = [];
const note = { textContent: '', classList: { remove() {} } };
const scopeInputs = ['occurrence', 'following', 'series'].map(value => ({value, checked: false}));
const context = vm.createContext({
    selectedEvent: null,
    eventCanUpdate: () => true,
    eventCanUpdateOccurrence: () => true,
    eventCanUpdateFollowing: () => true,
    eventCanUpdateSeries: () => true,
    eventIsRecurring: event => Boolean(event?.recurring),
    beginAgendaScrollWorkflow: () => calls.push('workflow'),
    prepareEventEdit: () => calls.push('edit'),
    editScopeSourceDialog: null,
    editScopeDialog: { querySelectorAll: () => scopeInputs, showModal: () => calls.push('scope') },
    document: { getElementById: id => id === 'dialog-note' ? note : {classList: {toggle() {}}} },
    eventDialogEditable: true,
    eventTask: {checked: true},
    eventTaskFollowPlanned: {checked: true},
    eventCalendarInput: {value: '1'},
    t: text => text
});
for (const name of ['taskPlainSummary', 'editableEventSummary', 'requestEdit', 'updateTaskMoveNote']) {
    vm.runInContext(extract(name), context);
}
for (const marker of ['[OC:TODO]', '[OC:DONE]', '[OC:TODO:FOLLOW]', '[OC:DONE:FOLLOW]', '☐', '☑', '☐↻', '☑↻']) {
    assert.strictEqual(context.taskPlainSummary(marker + ' Clean fridge'), 'Clean fridge');
    assert.strictEqual(context.editableEventSummary({task: true, summary: marker + ' Clean fridge'}), 'Clean fridge');
}
assert.strictEqual(context.editableEventSummary({summary: 'Meeting'}), 'Meeting');
context.selectedEvent = {task: true, recurring: true, calendarInstanceId: 1, writeScope: 'occurrence'};
context.requestEdit({close: () => calls.push('close')});
assert(calls.includes('edit') && !calls.includes('scope'), 'Task editing must bypass scope dialog');
calls.length = 0;
context.selectedEvent.task = false;
context.requestEdit({});
assert(calls.includes('scope') && !calls.includes('edit'), 'Normal series must retain scope choice');
context.selectedEvent.task = true;
context.updateTaskMoveNote();
assert(note.textContent.startsWith('Changing the date'), 'Local task shift must explain following occurrences');
context.eventCalendarInput.value = '2';
context.updateTaskMoveNote();
assert(note.textContent.includes('selected calendar'), 'Calendar transfer must explain following tasks');
context.eventTaskFollowPlanned.checked = false;
context.updateTaskMoveNote();
assert(note.textContent.startsWith('Only this occurrence'), 'Unchecked option must explain occurrence-only write');

// The migration must retain independent 9.1 state controls and optimized rendering.
for (const symbol of ['loadEventStateEditor', 'appendEventStateChanges', 'initializeIPSViewEventStatePickers',
    'visibleCalendarEventsByDay', 'eventsForIndexedDay', 'calendarClientContractVersion']) {
    assert(source.includes(symbol), 'Missing preserved 9.1 feature: ' + symbol);
}
for (const name of ['normalizedEventStatus', 'normalizedEventTransparency', 'calendarSupportsEventStatus',
    'calendarSupportsEventAvailability', 'defaultEventStatus', 'defaultEventTransparency', 'appendEventStateChanges']) {
    vm.runInContext(extract(name), context);
}
context.eventStatusInput = {value: 'TENTATIVE'};
context.eventAvailabilityInput = {value: 'TRANSPARENT'};
context.eventStatusEdited = false;
context.eventAvailabilityEdited = false;
const targetCalendar = {canWriteStatus: true, canWriteTransparency: true,
    defaultStatus: 'CONFIRMED', defaultAllDayTransparency: 'OPAQUE'};
const taskPayload = {task: true, taskCompleted: false, taskFollowPlanned: true};
context.appendEventStateChanges(taskPayload, targetCalendar, true, true);
assert.deepStrictEqual(taskPayload, {task: true, taskCompleted: false, taskFollowPlanned: true,
    status: 'TENTATIVE', transparency: 'TRANSPARENT'}, 'Task transfer must preserve independent 9.1 event state');
vm.runInContext(extract('recurrencePayload'), context);
vm.runInContext(extract('toggleSelectedTaskCompletion'), context);
const toggleButton = {disabled: false};
context.document = {getElementById: () => toggleButton};
context.releaseAgendaScrollWorkflowAfterState = () => {};
context.cancelAgendaScrollWorkflowRelease = () => {};
context.eventDetailsDialog = {close: () => {}};
context.selectedEvent = {task: true, taskCompleted: false, taskFollowPlanned: true, recurring: true,
    writeScope: 'occurrence', calendarInstanceId: 1, uid: 'first', etag: 'fresh', status: 'TENTATIVE'};
const updates = [];
context.sendAction = async (action, value) => { updates.push({action, value}); return true; };
(async () => {
    await context.toggleSelectedTaskCompletion();
    assert.strictEqual(updates[0].action, 'UpdateEvent');
    assert.strictEqual(updates[0].value.event.changes.taskCompleted, true);
    assert.strictEqual(updates[0].value.event.writeScope, 'occurrence');
    assert(!('status' in updates[0].value.event.changes), 'Task completion must not change appointment status');
    context.selectedEvent.taskCompleted = true;
    await context.toggleSelectedTaskCompletion();
    assert.strictEqual(updates[1].value.event.changes.taskCompleted, false);
    assert.strictEqual(toggleButton.disabled, false);
    console.log('Task UI behavior tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
