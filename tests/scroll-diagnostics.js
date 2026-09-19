'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
const listeners = new Map();
const pending = [];
let panel;
const header = {appendChild(node) { panel = node; }};
const form = {scrollTop: 0, scrollHeight: 1000, clientHeight: 400};
const list = {scrollTop: 0, scrollHeight: 500, clientHeight: 150};
const context = vm.createContext({
    calendarVisualization: {mode: 'ipsview'}, scrollDiagnosticsCleanup: null,
    eventDialog: {open: true, querySelector: selector => selector === '.dialog-header' ? header : form},
    document: {createElement: () => ({textContent: '', remove() { this.removed = true; }})},
    window: {addEventListener: (type, callback) => listeners.set(type, callback), removeEventListener: type => listeners.delete(type)},
    requestAnimationFrame: callback => pending.push(callback),
    pickerScrollTarget: event => event.list || null
});
const start = source.indexOf('function toggleScrollDiagnostics(');
assert(start >= 0, 'Missing opt-in scroll diagnostics');
vm.runInContext(source.slice(start, source.indexOf('\n}', start) + 2), context);
context.toggleScrollDiagnostics();
assert(panel.textContent.includes('wheel=0'), 'No wheel events must remain visible as zero');
assert.strictEqual(listeners.size, 4);
const event = {target: {tagName: 'BUTTON', textContent: 'PRIVATE EVENT TITLE'}, list,
    clientX: 120, clientY: 240, deltaY: 40, deltaMode: 0, cancelable: true, defaultPrevented: false};
listeners.get('wheel')(event);
list.scrollTop = 40;
event.defaultPrevented = true;
pending.shift()();
assert(panel.textContent.includes('wheel=1'));
assert(panel.textContent.includes('prevented=true'));
assert(panel.textContent.includes('list=40/350'));
assert(!panel.textContent.includes('PRIVATE'), 'Diagnostics must never include calendar text');
form.scrollTop = 100;
listeners.get('scroll')({target: form});
assert(panel.textContent.includes('form=100/600'));
const firstPanel = panel;
context.toggleScrollDiagnostics();
assert(firstPanel.removed);
assert.strictEqual(listeners.size, 0, 'Disabling must remove all diagnostic listeners');
context.toggleScrollDiagnostics();
assert(panel.textContent.includes('wheel=0'), 'Reactivation must reset counters');
context.scrollDiagnosticsCleanup();
assert.strictEqual(listeners.size, 0);
console.log('Opt-in scroll diagnostics tests passed.');
