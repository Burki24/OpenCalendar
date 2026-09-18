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
for (const id of ['event-task', 'event-task-completed', 'event-task-roll-forward', 'event-microsoft-todo-recurrence-note', 'details-task-toggle-button',
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
    eventTaskRollForwardScope: {value: 'following'},
    eventCalendarInput: {value: '1'},
    t: text => text
});
for (const name of ['taskPlainSummary', 'taskRollForwardScope', 'microsoftTodoIdentity', 'editableEventSummary', 'requestEdit', 'updateTaskMoveNote']) {
    vm.runInContext(extract(name), context);
}
for (const marker of ['[OC:TODO]', '[OC:DONE]', '[OC:TODO:FOLLOW]', '[OC:DONE:FOLLOW]', '[OC:TODO:KEEP]', '[OC:DONE:KEEP]', '☐', '☑', '☐↻', '☑↻']) {
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
context.eventTaskRollForwardScope.value = 'occurrence';
context.updateTaskMoveNote();
assert(note.textContent.startsWith('Only this occurrence'), 'Unchecked option must explain occurrence-only write');
context.eventTaskRollForwardScope.value = 'disabled';
context.updateTaskMoveNote();
assert(note.textContent.startsWith('Overdue tasks will remain'), 'Disabled scheduling must retain the original date');
assert.strictEqual(context.taskRollForwardScope({taskFollowPlanned: true}), 'following');
assert.strictEqual(context.taskRollForwardScope({taskFollowPlanned: true, taskRollForwardScope: 'disabled'}), 'disabled');

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
context.selectedEvent = null;
const nativeTaskPayload = {task: true};
context.appendEventStateChanges(nativeTaskPayload, {...targetCalendar, microsoftTodoEnabled: true}, false, true);
assert.deepStrictEqual(nativeTaskPayload, {task: true}, 'Native To Do must not receive calendar availability/status fields');

// Native To Do recurrence belongs to Microsoft; ordinary task series retain all policies.
const followingOption = {disabled: false};
function row() {
    return {hidden: false, classList: {toggle(name, value) { this.hidden = value; }, add() {}}};
}
context.eventTaskCompleted = {checked: false};
context.eventTaskCompletedRow = row();
context.eventTaskRollForwardScopeRow = row();
context.eventMicrosoftTodoRecurrenceNote = row();
context.eventTaskRollForwardScope.querySelector = () => followingOption;
context.eventRecurrenceFrequency = {value: 'DAILY'};
context.eventAnniversaryType = {value: ''};
context.eventAnniversaryDate = {value: ''};
context.eventAnniversaryDateRow = row();
context.selectedCalendarEntry = () => ({canUpdateFollowing: true, microsoftTodoEnabled: context.nativeTodo});
context.nativeTodo = true;
context.selectedEvent = null;
context.readInputDate = () => new Date('2026-09-18T00:00:00Z');
context.dayKey = () => '2026-09-18';
const nativeFields = Object.fromEntries(['event-all-day', 'event-end', 'event-location', 'event-start']
    .map(id => [id, {checked: true, value: '2026-09-18', disabled: false}]));
context.document = {getElementById: id => id === 'dialog-note' ? note : nativeFields[id]};
for (const name of ['selectedCalendarUsesMicrosoftTodo', 'updateTaskControls']) vm.runInContext(extract(name), context);
context.updateTaskControls();
assert.strictEqual(context.eventTaskRollForwardScopeRow.classList.hidden, true);
assert.strictEqual(context.eventMicrosoftTodoRecurrenceNote.classList.hidden, false);
assert.strictEqual(context.eventTaskRollForwardScope.value, 'disabled');
assert.strictEqual(nativeFields['event-location'].disabled, true, 'New native tasks must not offer an unsupported location');
assert.strictEqual(nativeFields['event-end'].disabled, true, 'Native task due dates must not offer a separate calendar end date');
context.nativeTodo = false;
context.eventTaskRollForwardScope.value = 'following';
context.updateTaskControls();
assert.strictEqual(context.eventTaskRollForwardScopeRow.classList.hidden, false);
assert.strictEqual(context.eventMicrosoftTodoRecurrenceNote.classList.hidden, true);
assert.strictEqual(context.eventTaskRollForwardScope.value, 'following');
assert.strictEqual(nativeFields['event-location'].disabled, false, 'Calendar tasks retain their existing location control');
context.nativeTodo = true;
context.selectedEvent = {task: true, recurring: true, calendarInstanceId: 2};
context.updateTaskControls();
assert.strictEqual(context.eventTaskRollForwardScopeRow.classList.hidden, false, 'Existing calendar tasks must keep their scheduling after a To Do list is enabled');
assert.strictEqual(context.eventTaskRollForwardScope.value, 'following');
const legacyPayload = {task: true};
context.appendEventStateChanges(legacyPayload, {...targetCalendar, microsoftTodoEnabled: true}, false, true);
assert.strictEqual(legacyPayload.status, 'TENTATIVE', 'Existing calendar tasks must retain independent 9.1 state controls');
context.selectedEvent = {sourceType: 'microsoft-todo', task: true, recurring: false, calendarInstanceId: 2,
    taskNativeRecurrence: {pattern: {type: 'daily'}}};
context.eventRecurrenceFrequency.value = 'none';
context.updateTaskControls();
assert.strictEqual(context.eventMicrosoftTodoRecurrenceNote.classList.hidden, false, 'Native task recurrence must be explained without pretending it is a calendar series');

context.eventReminderMode = {value: 'custom', querySelector: () => ({disabled: false})};
context.eventReminderCustomRow = row();
context.eventReminderExtraList = row();
context.eventReminderAddRow = row();
context.eventReminderAddButton = {};
context.eventReminderValue = {setCustomValidity() {}};
context.reminderEditorEntries = () => [];
context.reminderMinutesFromEntry = () => 15;
context.maxReminderCount = () => 1;
context.synchronizeIPSViewEventStatePickers = () => {};
vm.runInContext(extract('updateReminderControls'), context);
context.selectedEvent = null;
context.updateReminderControls();
assert.strictEqual(context.eventReminderMode.disabled, true, 'Creation must not offer native task reminders that are not persisted');
context.nativeTodo = false;
context.updateReminderControls();
assert.strictEqual(context.eventReminderMode.disabled, false, 'Calendar task reminders must remain editable');

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
    context.selectedEvent = {...context.selectedEvent, sourceType: 'microsoft-todo', taskId: 'task-1', taskListId: 'list-1', taskRollForwardScope: 'disabled'};
    await context.toggleSelectedTaskCompletion();
    assert.strictEqual(updates[2].value.event.sourceType, 'microsoft-todo');
    assert.strictEqual(updates[2].value.event.taskId, 'task-1');
    assert.strictEqual(updates[2].value.event.taskListId, 'list-1');
    assert.strictEqual(updates[2].value.event.taskRollForwardScope, 'disabled');
    assert.strictEqual(toggleButton.disabled, false);
    console.log('Task UI behavior tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
