'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
class Node {
    constructor() {
        this.children = []; this.dataset = {}; this.attrs = {}; this.listeners = {};
        this.textContent = ''; this.disabled = false; this.isConnected = true;
        const classes = new Set();
        this.classList = {add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c)};
    }
    set className(value) { value.split(' ').forEach(c => this.classList.add(c)); }
    setAttribute(key, value) { this.attrs[key] = value; }
    append(...nodes) { this.children.push(...nodes); }
    appendChild(node) { this.append(node); }
    replaceChildren() { this.children = []; }
    addEventListener(type, callback) { this.listeners[type] = callback; }
    dispatchEvent(event) { this.listeners[event.type]?.(event); }
    closest() { return this.row; }
    focus() { this.focused = true; }
    querySelectorAll(selector) {
        return this.children.filter(c => selector === 'label' ? c instanceof Label
            : c.classList.contains('calendar-picker-option') && (!selector.includes(':not') || !c.disabled));
    }
    querySelector(selector) {
        return selector === '[aria-selected="true"]' ? this.children.find(c => c.attrs['aria-selected'] === 'true')
            : this.querySelectorAll(selector)[0];
    }
}
class Select extends Node {}
class Label extends Node {}
function makeContext(mode) {
    const map = new Map();
    const context = vm.createContext({calendarVisualization: {mode}, ipsViewEventStatePickers: map,
        ipsViewEventSelectSequence: 0, HTMLSelectElement: Select, HTMLElement: Node, HTMLLabelElement: Label,
        document: {createElement: () => new Node()}, t: text => text,
        element: (tag, cls) => { const node = new Node(); node.className = cls; return node; },
        Event: class { constructor(type) { this.type = type; } }});
    for (const name of ['initializeIPSViewEventStatePicker', 'rebuildIPSViewEventStatePickerOptions', 'synchronizeIPSViewEventStatePicker',
        'openIPSViewEventStatePicker', 'closeIPSViewEventStatePicker', 'closeIPSViewEventStatePickers',
        'toggleIPSViewEventStatePicker', 'handleIPSViewEventStateOptionKeydown',
        'initializeIPSViewEventStatePickers', 'synchronizeIPSViewEventStatePickers']) {
        const start = source.indexOf('function ' + name + '(');
        assert(start >= 0, 'Missing IPSView picker: ' + name);
        vm.runInContext(source.slice(start, source.indexOf('\n}', start) + 2), context);
    }
    return {context, map};
}
const select = new Select();
select.id = 'event-anniversary-type'; select.value = '';
select.row = new Node();
const label = new Label(); label.htmlFor = select.id; select.row.append(label);
select.options = ['', 'birthday', 'anniversary'].map(value => ({value, textContent: value || 'None', dataset: {}, disabled: false}));
let changes = 0;
select.addEventListener('change', () => changes++);
const {context, map} = makeContext('ipsview');
context.initializeIPSViewEventStatePicker(select);
const picker = map.get(select);
assert(picker && select.classList.contains('hidden'));
context.initializeIPSViewEventStatePicker(select);
assert.strictEqual(select.row.children.length, 2, 'Do not install a picker twice');
const click = {stopPropagation() {}};
picker.trigger.listeners.click(click);
assert(!picker.options.classList.contains('hidden'), 'Click must open the in-page list');
picker.options.children[1].listeners.click(click);
assert.strictEqual(select.value, 'birthday');
assert.strictEqual(changes, 1, 'Selection must invoke the existing annual-event logic once');
assert(picker.options.classList.contains('hidden'));
assert.strictEqual(picker.value.textContent, 'birthday');
context.openIPSViewEventStatePicker(select, true);
assert(picker.options.children[1].focused);
context.handleIPSViewEventStateOptionKeydown({key: 'Escape', preventDefault() {}}, select);
assert(picker.options.classList.contains('hidden'));
select.value = 'anniversary'; select.disabled = true;
context.synchronizeIPSViewEventStatePicker(select);
picker.trigger.listeners.click(click);
assert(picker.options.classList.contains('hidden') && picker.trigger.disabled);
assert.strictEqual(picker.value.textContent, 'anniversary');
const native = new Select();
makeContext('symcon').context.initializeIPSViewEventStatePicker(native);
assert(!native.classList.contains('hidden'), 'Native tile controls must stay unchanged');
assert(source.includes("eventDialog.querySelectorAll('select').forEach(select => initializeIPSViewEventStatePicker(select));"), 'All editor selects must be initialized');
for (const name of ['unitSelect', 'mode', 'index']) {
    assert(source.includes(`initializeIPSViewEventStatePicker(${name});`), 'Dynamic selector must be initialized: ' + name);
}
assert(source.includes('synchronizeIPSViewEventStatePicker(eventAnniversaryType);'), 'Programmatic annual changes must refresh the picker');
const html = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/index.html'), 'utf8');
const ids = [...html.matchAll(/<select id="([^"]+)"/g)].map(match => match[1]);
const selects = ids.map(id => {
    const item = new Select(); item.id = id; item.value = 'a'; item.row = new Node();
    const itemLabel = new Label(); itemLabel.htmlFor = id; item.row.append(itemLabel);
    item.options = ['a', 'b', 'c'].map(value => ({value, textContent:value, dataset:{}, disabled:value === 'c'}));
    return item;
});
const all = makeContext('ipsview');
all.context.eventDialog = {querySelectorAll: () => selects};
all.context.initializeIPSViewEventStatePickers();
assert.strictEqual(all.map.size, ids.length, 'Every static select is replaced');
for (const item of selects) {
    const p = all.map.get(item);
    all.context.openIPSViewEventStatePicker(item, true);
    assert(p.options.children[0].focused && !p.options.classList.contains('hidden'));
    p.options.children[1].listeners.click(click);
    assert.strictEqual(item.value, 'b');
    assert(p.options.classList.contains('hidden'));
    p.options.children[2].listeners.click(click);
    assert.strictEqual(item.value, 'b', 'Disabled option cannot change the selection');
    item.disabled = true;
    all.context.synchronizeIPSViewEventStatePickers();
    assert(p.trigger.disabled, 'Provider restrictions propagate to every trigger');
}
selects[0].isConnected = false;
all.context.synchronizeIPSViewEventStatePickers();
assert(!all.map.has(selects[0]), 'Removed fields must not remain registered');
const dynamic = new Select(); dynamic.value = 'hours'; dynamic.row = new Node();
dynamic.row.append(new Label());
dynamic.options = [{value:'hours', textContent:'Hours', dataset:{}, disabled:false}];
all.context.initializeIPSViewEventStatePicker(dynamic);
assert(dynamic.id && all.map.get(dynamic).value.textContent === 'Hours', 'Generated reminder select receives an ID and current label');
console.log('IPSView static/dynamic selector, selection and disabled-state tests passed.');
