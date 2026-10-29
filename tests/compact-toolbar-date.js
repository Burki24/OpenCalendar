'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, `Missing function: ${name}`);
    return source.slice(start, source.indexOf('\n}', start) + 2);
}

const context = vm.createContext({
    Date,
    Intl,
    document: {documentElement: {lang: 'de'}}
});
for (const name of ['formatCompactRange', 'formatCompactMonth']) {
    vm.runInContext(extract(name), context);
}
const date = (year, month, day) => new Date(year, month - 1, day);
assert.strictEqual(context.formatCompactRange(date(2026, 10, 1), date(2026, 10, 7)), '1.–7.10.26');
assert.strictEqual(context.formatCompactRange(date(2026, 10, 7), date(2026, 10, 7)), '7.10.26');
assert.strictEqual(context.formatCompactRange(date(2026, 9, 30), date(2026, 10, 7)), '30.9.–7.10.26');
assert.strictEqual(context.formatCompactRange(date(2026, 12, 30), date(2027, 1, 7)), '30.12.26–7.1.27');
assert.strictEqual(context.formatCompactMonth(date(2026, 10, 1)), '10.26');

console.log('Compact toolbar date tests passed.');

