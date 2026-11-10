'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
const start = source.indexOf('function handleIPSViewPickerTab(');
assert(start >= 0, 'IPSView picker TAB exit handler is missing');
let focused = false, hidden = false, expanded = 'true';
const trigger = {focus() { focused = true; }, setAttribute(k, v) { expanded = v; }};
const options = {classList:{add() { hidden = true; }}};
const picker = {querySelector: selector => selector.includes('trigger') ? trigger : options};
const target = {closest: () => picker};
const context = vm.createContext({calendarVisualization:{mode:'ipsview'}, eventDialog:{contains: () => true}});
vm.runInContext(source.slice(start, source.indexOf('\n}', start) + 2), context);
for (const shiftKey of [false, true]) {
    focused = hidden = false; expanded = 'true';
    context.handleIPSViewPickerTab({key:'Tab', shiftKey, target});
    assert(focused && hidden && expanded === 'false', 'TAB must close list and restore its trigger before native focus traversal');
}
focused = false;
context.handleIPSViewPickerTab({key:'ArrowDown', target});
assert(!focused, 'Arrow navigation must remain with the picker');
context.calendarVisualization.mode = 'symcon';
context.handleIPSViewPickerTab({key:'Tab', target});
assert(!focused, 'Native tile behavior must remain unchanged');
assert(source.includes("eventDialog.addEventListener('keydown', handleIPSViewPickerTab);"));
assert(source.includes("if (calendarVisualization.mode === 'ipsview') option.tabIndex = -1;"));
console.log('IPSView picker TAB exit tests passed.');
