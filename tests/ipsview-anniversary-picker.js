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
        'toggleIPSViewEventStatePicker', 'handleIPSViewEventStateOptionKeydown']) {
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
assert(source.includes('initializeIPSViewEventStatePicker(eventAnniversaryType);'), 'The annual selector must actually be initialized');
assert(source.includes('synchronizeIPSViewEventStatePicker(eventAnniversaryType);'), 'Programmatic annual changes must refresh the picker');
console.log('IPSView annual-event picker tests passed.');
