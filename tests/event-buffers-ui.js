'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
process.env.TZ = 'Europe/Berlin';

const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, `Missing ${name}`);
    const lineEnd = source.indexOf('\n', start);
    const firstLine = source.slice(start, lineEnd).trimEnd();
    return firstLine.endsWith('}') ? firstLine : source.slice(start, source.indexOf('\n}', start) + 2);
}
const classes = () => ({hidden: false, toggle(name, on) { if (name === 'hidden') this.hidden = on; }});
const fields = {
    'event-buffers': {classList: classes()},
    'event-buffers-options': {classList: classes()},
    'event-buffers-enabled': {checked: true},
    'event-buffer-mode': {value: 'linked'},
    'event-buffer-before': {checked: true},
    'event-buffer-after': {checked: true},
    'event-buffer-before-minutes': {value: '60', reportValidity: () => true},
    'event-buffer-after-minutes': {value: '30', reportValidity: () => true},
    'event-buffer-before-title': {value: 'Arrival'},
    'event-buffer-after-title': {value: 'Departure'},
    'event-buffer-before-preview': {textContent: ''},
    'event-buffer-after-preview': {textContent: ''},
    'event-all-day': {checked: false},
    'event-start': {value: '2026-03-29T00:30'},
    'event-end': {value: '2026-03-29T03:30'}
};
const context = vm.createContext({
    Date,
    document: {getElementById: id => fields[id]},
    selectedEvent: null,
    eventTask: {checked: false},
    eventAnniversaryType: {value: ''},
    eventRecurrenceFrequency: {value: 'none'},
    showToast() { throw new Error('Unexpected validation error'); },
    t: value => value
});
for (const name of ['dayKey', 'localDate', 'localDateTime', 'readInputDate', 'updateEventBufferControls', 'selectedEventBuffers']) {
    vm.runInContext(extract(name), context);
}
context.updateEventBufferControls();
assert.strictEqual(fields['event-buffer-before-preview'].textContent, '2026-03-28 23:30 – 2026-03-29 00:30');
assert.strictEqual(fields['event-buffer-after-preview'].textContent, '2026-03-29 03:30 – 2026-03-29 04:00');
const chosen = context.selectedEventBuffers();
assert.strictEqual(chosen.before.minutes, 60);
assert.strictEqual(chosen.after.summary, 'Departure');
fields['event-all-day'].checked = true;
context.updateEventBufferControls();
assert.strictEqual(fields['event-buffers'].classList.hidden, true);
assert.strictEqual(fields['event-buffers-enabled'].checked, false);
assert.strictEqual(fields['event-buffer-before-minutes'].disabled, true);
fields['event-all-day'].checked = false;
context.eventRecurrenceFrequency.value = 'weekly';
context.updateEventBufferControls();
assert.strictEqual(fields['event-buffers'].classList.hidden, true);

console.log('Adjacent appointment UI tests passed.');
