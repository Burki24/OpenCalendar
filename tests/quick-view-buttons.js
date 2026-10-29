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

function element() {
    const attributes = {};
    const classes = new Set();
    return {
        attributes,
        classes,
        children: [],
        dataset: {},
        classList: {toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name)},
        setAttribute(name, value) { attributes[name] = value; },
        addEventListener(name, callback) { this[name] = callback; },
        appendChild(child) { this.children.push(child); },
        set textContent(value) { this.children = []; this.text = value; },
        get textContent() { return this.text; }
    };
}

const toolbar = element();
const quickViewButtons = element();
quickViewButtons.parentElement = toolbar;
const calls = {persist: 0, render: 0, close: 0};
const context = vm.createContext({
    calendarState: {settings: {quickViewButtons: []}},
    calendarViews: new Set(['agenda', 'list', 'threeDays', 'week', 'workWeek', 'month']),
    calendarViewLabels: {agenda: 'Agenda', list: 'List', threeDays: 'Days', week: 'Full week', workWeek: 'Work week', month: 'Month'},
    quickViewButtons,
    quickViewButtonsSignature: '',
    activeView: 'agenda',
    document: {createElement: element},
    t: value => value,
    clampCurrentCursorDate: () => {},
    persistClientViewState: () => { calls.persist++; },
    render: () => { calls.render++; },
    viewSelectorDialog: {close: () => { calls.close++; }}
});
for (const name of ['configuredQuickViewButtons', 'updateQuickViewButtons', 'selectCalendarView']) {
    vm.runInContext(extract(name), context);
}

context.updateQuickViewButtons();
assert.strictEqual(quickViewButtons.children.length, 0);
assert(quickViewButtons.classes.has('hidden'));
assert(!toolbar.classes.has('has-quick-views'));

context.calendarState.settings.quickViewButtons = [
    {view: 'threeDays', label: 'T'},
    {view: 'workWeek', label: 'A'},
    {view: 'week', label: 'W'},
    {view: 'month', label: 'M'},
    {view: 'agenda', label: 'G'},
    {view: 'week', label: 'X'},
    {view: 'list', label: '<'}
];
context.updateQuickViewButtons();
assert.deepStrictEqual(quickViewButtons.children.map(button => button.textContent), ['T', 'A', 'W', 'M', 'G']);
assert(toolbar.classes.has('has-quick-views'));
assert.strictEqual(quickViewButtons.children[1].attributes['aria-label'], 'Work week');
assert.strictEqual(quickViewButtons.children[1].attributes['aria-pressed'], 'false');

quickViewButtons.children[1].click();
assert.strictEqual(context.activeView, 'workWeek');
assert.deepStrictEqual(calls, {persist: 1, render: 1, close: 0});
context.updateQuickViewButtons();
assert.strictEqual(quickViewButtons.children[1].attributes['aria-pressed'], 'true');
assert(quickViewButtons.children[1].classes.has('active'));

context.selectCalendarView('week', true);
assert.strictEqual(context.activeView, 'week');
assert.deepStrictEqual(calls, {persist: 2, render: 2, close: 1});
context.selectCalendarView('invalid');
assert.deepStrictEqual(calls, {persist: 2, render: 2, close: 1});

console.log('Quick-view button tests passed.');
