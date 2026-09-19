'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
process.env.TZ = 'Europe/Berlin';
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, 'Missing date picker function: ' + name);
    return source.slice(start, source.indexOf('\n}', start) + 2);
}
const context = vm.createContext({Date});
for (const name of ['datePickerMonthDays', 'datePickerInputValue', 'ipsViewDateText', 'ipsViewDateValue']) vm.runInContext(extract(name), context);
assert.strictEqual(context.ipsViewDateText('2026-09-21T09:05'), '21.09.2026 09:05');
assert.strictEqual(context.ipsViewDateValue('21.09.2026 09:05', false), '2026-09-21T09:05');
assert.strictEqual(context.ipsViewDateValue('29.02.2024', true), '2024-02-29');
for (const value of ['29.02.2026', '31.04.2026', '00.09.2026', '21.13.2026', '21.09.0000']) {
    assert.strictEqual(context.ipsViewDateValue(value, true), '', 'Reject impossible calendar dates');
}
for (const value of ['21.09.2026 24:00', '21.09.2026 09:60', '29.03.2026 02:30']) {
    assert.strictEqual(context.ipsViewDateValue(value, false), '', 'Reject invalid times including the DST gap');
}
const leap = context.datePickerMonthDays(2024, 1);
assert.strictEqual(leap.length, 42);
assert.strictEqual(leap[0].getDay(), 1, 'Calendar weeks start on Monday');
assert(leap.some(d => d.getMonth() === 1 && d.getDate() === 29), 'Leap day must be selectable');
const year = context.datePickerMonthDays(2026, 0);
assert(year.some(d => d.getFullYear() === 2025), 'Adjacent year dates must remain correct');
assert.strictEqual(context.datePickerInputValue(new Date(2026, 8, 21, 12), '09', '05', false), '2026-09-21T09:05');
assert.strictEqual(context.datePickerInputValue(new Date(2026, 8, 21, 12), '', '', true), '2026-09-21');
for (const [h, m] of [['24', '0'], ['-1', '0'], ['12', '60'], ['', '0'], ['1.5', '0']]) {
    assert.strictEqual(context.datePickerInputValue(new Date(2026, 8, 21), h, m, false), '', 'Invalid time must not be committed');
}
assert(source.includes("calendarVisualization.mode !== 'ipsview'"));
assert(source.includes('initializeIPSViewDatePickers();'));
console.log('Date/time picker calendar and value tests passed.');
