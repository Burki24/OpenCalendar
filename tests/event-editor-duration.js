'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
process.env.TZ = 'Europe/Berlin';
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, 'Missing function: ' + name);
    const lineEnd = source.indexOf('\n', start);
    const firstLine = source.slice(start, lineEnd).trimEnd();
    return firstLine.endsWith('}') ? firstLine : source.slice(start, source.indexOf('\n}', start) + 2);
}
const fields = {
    'event-start': {value: '', dataset: {}},
    'event-end': {value: ''},
    'event-all-day': {checked: false}
};
const context = vm.createContext({Date, document: {getElementById: id => fields[id]}});
for (const name of ['dayKey', 'localDate', 'localDateTime', 'readInputDate', 'setDateInputs', 'updateEndFromStart']) {
    vm.runInContext(extract(name), context);
}
function move(before, end, after, expected) {
    fields['event-all-day'].checked = false;
    context.setDateInputs(new Date(before), new Date(end), false);
    fields['event-start'].value = after;
    context.updateEndFromStart();
    assert.strictEqual(fields['event-end'].value, expected, before + ' -> ' + after);
    assert.strictEqual(fields['event-start'].dataset.previousValue, after);
}
move('2026-09-19T16:00', '2026-09-19T20:00', '2026-09-19T15:00', '2026-09-19T19:00');
move('2026-09-19T16:00', '2026-09-19T20:00', '2026-09-19T17:00', '2026-09-19T21:00');
move('2026-09-19T16:00', '2026-09-20T20:00', '2026-09-19T15:00', '2026-09-20T19:00');
move('2026-09-19T16:00', '2026-09-20T20:00', '2026-09-21T16:00', '2026-09-22T20:00');
move('2026-09-19T23:00', '2026-09-20T03:00', '2026-09-20T01:00', '2026-09-20T05:00');
move('2026-09-19T16:00', '2026-09-19T16:30', '2026-09-19T17:15', '2026-09-19T17:45');
// Preserve elapsed duration when crossing a daylight-saving transition.
move('2026-03-28T00:00', '2026-03-28T04:00', '2026-03-29T00:00', '2026-03-29T05:00');
move('2026-10-24T00:00', '2026-10-24T04:00', '2026-10-25T00:00', '2026-10-25T03:00');
move('2026-09-19T16:00', '2026-09-19T20:00', '2026-09-19T16:00', '2026-09-19T20:00');
// Multiple edits use the last start, and manual end changes define the new duration.
fields['event-start'].value = '2026-09-19T17:00';
context.updateEndFromStart();
assert.strictEqual(fields['event-end'].value, '2026-09-19T21:00');
fields['event-end'].value = '2026-09-19T23:00';
fields['event-start'].value = '2026-09-19T18:00';
context.updateEndFromStart();
assert.strictEqual(fields['event-end'].value, '2026-09-20T00:00');
for (const invalidEnd of ['', 'invalid', '2026-09-19T17:00']) {
    fields['event-start'].dataset.previousValue = '2026-09-19T18:00';
    fields['event-start'].value = '2026-09-19T19:00';
    fields['event-end'].value = invalidEnd;
    context.updateEndFromStart();
    assert.strictEqual(fields['event-end'].value, '2026-09-19T20:00');
}
fields['event-start'].dataset.previousValue = '';
fields['event-start'].value = '2026-09-19T19:00';
context.updateEndFromStart();
assert.strictEqual(fields['event-end'].value, '2026-09-19T20:00');
fields['event-start'].value = '';
context.updateEndFromStart();
assert.strictEqual(fields['event-end'].value, '2026-09-19T20:00');
assert.strictEqual(fields['event-start'].dataset.previousValue, '2026-09-19T19:00');
// Existing date-only task behavior remains unchanged.
fields['event-all-day'].checked = true;
fields['event-start'].value = '2026-09-20';
context.updateEndFromStart();
assert.strictEqual(fields['event-end'].value, '2026-09-20');
console.log('Event editor duration tests passed.');
