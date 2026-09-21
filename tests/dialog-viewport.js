'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/style.css'), 'utf8');
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, 'Missing client function: ' + name);
    const end = source.indexOf('\n}', start);
    return source.slice(start, end + 2);
}
const properties = new Map();
const host = {innerWidth: 1000, innerHeight: 700};
const frame = {getBoundingClientRect: () => ({left: 100, top: -200, right: 900, bottom: 1000, width: 800, height: 1200})};
const context = vm.createContext({
    calendarVisualization: {mode: 'symcon'},
    window: {parent: host, frameElement: frame, innerWidth: 800, innerHeight: 1200},
    document: {documentElement: {}},
    getComputedStyle: () => ({getPropertyValue: () => '40'}),
    eventDialog: {style: {setProperty: (key, value) => properties.set(key, value), removeProperty: key => properties.delete(key)}}
});
for (const name of ['eventDialogHostViewport', 'updateEventDialogViewportBounds']) vm.runInContext(extract(name), context);
context.updateEventDialogViewportBounds();
assert.strictEqual(properties.get('--event-dialog-visible-top'), '212px', 'Editor must start inside the visible iframe intersection');
assert.strictEqual(properties.get('--event-dialog-visible-bottom'), '312px', 'Editor must end above the hidden iframe portion');
assert.strictEqual(properties.get('--event-dialog-visible-left'), '12px');
host.visualViewport = {offsetLeft: 0, offsetTop: 50, width: 1000, height: 350};
context.updateEventDialogViewportBounds();
assert.strictEqual(properties.get('--event-dialog-visible-top'), '262px');
assert.strictEqual(properties.get('--event-dialog-visible-bottom'), '612px', 'Visual viewport shrink must reserve keyboard space');
context.calendarVisualization.mode = 'ipsview';
context.updateEventDialogViewportBounds();
assert.strictEqual(properties.get('--event-dialog-visible-bottom'), '612px', 'Embedded IPSView must respect the same visible area');
context.window.parent = context.window;
context.updateEventDialogViewportBounds();
assert.strictEqual(properties.size, 0, 'Standalone IPSView must use CSS viewport bounds');
context.window.parent = host;
context.calendarVisualization.mode = 'symcon';
frame.getBoundingClientRect = () => { throw new Error('cross-origin'); };
assert.strictEqual(context.eventDialogHostViewport(), null, 'Inaccessible hosts must fall back safely');
assert(css.includes('#event-dialog > .dialog-layout {') && css.includes('#event-dialog .dialog-actions {'));
assert(css.includes('#event-state-row { align-items: start; }'), '9.1 state controls must retain their layout');
assert(source.includes("openDialog === eventDialog ? '.dialog-layout' : '.dialog-body'"), 'Other 9.1 modals must retain their existing scroll container');
function cssRule(selector) {
    const start = css.indexOf(selector + ' {');
    assert(start >= 0, 'Missing layout rule: ' + selector);
    return css.slice(start, css.indexOf('}', start));
}
assert(cssRule('.calendar-picker-options').includes('grid-auto-rows: max-content'), 'Scrollable picker rows must retain their full wrapped text height');
assert(cssRule('.calendar-picker-option').includes('overflow-wrap: anywhere'), 'Long option names must fit the available width');
assert(cssRule('#event-dialog .form-row.two').includes('repeat(auto-fit, minmax(min(100%, 14em), 1fr))'), 'Editor columns must respond to font size as well as viewport width');
assert(cssRule('#event-dialog .dialog-actions').includes('flex-wrap: wrap'), 'Editor footer groups must wrap instead of compressing each other');
console.log('Dialog viewport integration tests passed.');
