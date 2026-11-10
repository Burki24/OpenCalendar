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

// Segment typing changes .value programmatically: browsers need not emit change.
function control() {
    return {value:'', listeners:{}, classList:{add(){}}, validity:{valid:true},
        setAttribute(){}, setCustomValidity(message){this.error=message;}, reportValidity(){return !this.error;},
        setSelectionRange(start,end){this.selectionStart=start;this.selectionEnd=end;},
        addEventListener(type,fn){(this.listeners[type]??=[]).push(fn);},
        dispatchEvent(event){for(const fn of this.listeners[event.type]||[]) fn(event);}};
}
const inputs = {};
const editors = {};
let nextEditor;
const dom = vm.createContext({Date, t:value=>value,
    document:{createElement:()=>nextEditor, querySelector:()=>null, addEventListener(){}, getElementById:id=>inputs[id]},
    Event:class {constructor(type){this.type=type;}}, MutationObserver:class {observe(){}}});
for(const name of ['ipsViewDateText','ipsViewDateValue','initializeIPSViewDateSegments']) vm.runInContext(extract(name),dom);
for(const [id,value] of [['event-start','2026-09-21T09:00'],['event-end','2026-09-21T13:00']]) {
    const input=Object.assign(control(),{id,type:'datetime-local',value,before(){}});
    inputs[id]=input; nextEditor=editors[id]=control(); dom.initializeIPSViewDateSegments(input);
}
const editor=editors['event-start']; editor.selectionStart=11;
for(const key of ['1','4']) editor.dispatchEvent({type:'keydown',key,preventDefault(){}});
assert.strictEqual(editor.value,'21.09.2026 14:00');
editor.dispatchEvent({type:'blur'});
assert.strictEqual(inputs['event-start'].value,'2026-09-21T14:00','Blur must commit keyboard segments without a native change event');
vm.runInContext(extract('commitIPSViewDateSegments'),dom);
editors['event-start'].value='21.09.2026 15:00';
editors['event-end'].value='21.09.2026 19:30';
inputs['event-start'].addEventListener('change',()=>{inputs['event-end'].value='2026-09-21T19:00';inputs['event-end'].ipsViewSync();});
assert.strictEqual(dom.commitIPSViewDateSegments(),true);
assert.strictEqual(inputs['event-start'].value,'2026-09-21T15:00');
assert.strictEqual(inputs['event-end'].value,'2026-09-21T19:30','Explicit end must survive start-driven duration update');
editors['event-end'].value='21.09.2026 25:00';
assert.strictEqual(dom.commitIPSViewDateSegments(),false,'Invalid visible time must block saving');
assert.strictEqual(inputs['event-end'].value,'2026-09-21T19:30');
assert(source.includes("event.preventDefault();\n    if (!commitIPSViewDateSegments()) return;"),'Submit must commit before reading payload');
console.log('IPSView segment blur, submit and invalid-input regression tests passed.');
