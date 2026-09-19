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
for (const name of ['datePickerMonthDays', 'datePickerInputValue']) vm.runInContext(extract(name), context);
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
