'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
class Element {
    constructor(list = null) { this.list = list; this.scrollTop = 0; this.scrollLeft = 0; this.clientHeight = 200; }
    closest() { return this.list; }
}
const list = new Element();
list.list = list;
const option = new Element(list);
const body = new Element();
const content = new Element();
const eventDialog = {open: true, querySelector: () => body};
const context = vm.createContext({Element, eventDialog, content,
    eventDetailsDialog: {}, editScopeDialog: {}, deleteConfirmDialog: {}, dayEventsDialog: {}, viewSelectorDialog: {}, calendarFilterDialog: {},
    WheelEvent: {DOM_DELTA_LINE: 1, DOM_DELTA_PAGE: 2}, pickerTouchScroll: null});
function load(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, 'Missing handler: ' + name);
    vm.runInContext(source.slice(start, source.indexOf('\n}', start) + 2), context);
}
// Allow the baseline wheel regression to fail before the new helper exists.
if (source.includes('function pickerScrollTarget(')) load('pickerScrollTarget');
load('containWheelInsideTile');
function event(extra = {}) {
    return Object.assign({target: option, deltaMode: 0, deltaX: 0, deltaY: 40,
        prevented: false, stopped: false,
        preventDefault() { this.prevented = true; },
        stopImmediatePropagation() { this.stopped = true; }}, extra);
}
let wheel = event({target: body, composedPath: () => [option, list, body]});
context.containWheelInsideTile(wheel);
assert.strictEqual(list.scrollTop, 40, 'Retargeted wheel events must scroll the inner list');
assert.strictEqual(body.scrollTop, 0, 'Retargeted wheel events must not move the form');
assert(wheel.prevented && wheel.stopped);
context.containWheelInsideTile(event({target: {parentElement: option}, deltaMode: 1, deltaY: 2}));
assert.strictEqual(list.scrollTop, 72, 'Text targets and line deltas must work');
context.containWheelInsideTile(event({deltaMode: 2, deltaY: 1}));
assert.strictEqual(list.scrollTop, 272, 'Page deltas use list height');
wheel = event({ctrlKey: true});
context.containWheelInsideTile(wheel);
assert(!wheel.prevented, 'Browser zoom must remain available');
context.containWheelInsideTile(event({target: body}));
assert.strictEqual(body.scrollTop, 40, 'Scrolling outside the list still moves the form');
for (const name of ['beginPickerTouchScroll', 'movePickerTouchScroll', 'endPickerTouchScroll']) load(name);
const touch = y => ({identifier: 1, clientY: y});
const start = event({touches: [touch(100)]});
context.beginPickerTouchScroll(start);
assert(!start.prevented, 'A tap must still select an option');
const move = event({target: body, touches: [touch(60)], cancelable: true});
context.movePickerTouchScroll(move);
assert.strictEqual(list.scrollTop, 312, 'Touch gesture remains bound to its initial list');
assert.strictEqual(body.scrollTop, 40);
assert(move.prevented && move.stopped);
context.endPickerTouchScroll();
const ended = event({touches: [touch(20)], cancelable: true});
context.movePickerTouchScroll(ended);
assert(!ended.prevented, 'Ended gestures must not trap future scrolling');
context.beginPickerTouchScroll(start);
const pinch = event({touches: [touch(60), {identifier: 2, clientY: 30}], cancelable: true});
context.movePickerTouchScroll(pinch);
assert(!pinch.prevented, 'Multitouch gestures must not be hijacked');
// Model a browser scroll container clamping at its boundaries.
let boundedTop = 0;
Object.defineProperty(list, 'scrollTop', {
    get: () => boundedTop,
    set: value => { boundedTop = Math.max(0, Math.min(400, value)); }
});
for (const [initial, delta, expected] of [[0, -40, 0], [400, 40, 400]]) {
    list.scrollTop = initial;
    wheel = event({deltaY: delta});
    context.containWheelInsideTile(wheel);
    assert.strictEqual(list.scrollTop, expected);
    assert.strictEqual(body.scrollTop, 40, 'Wheel must not chain at list boundaries');
    assert(wheel.prevented && wheel.stopped);
    context.beginPickerTouchScroll(start);
    const edge = event({touches: [touch(100 - delta)], cancelable: true});
    context.movePickerTouchScroll(edge);
    assert.strictEqual(list.scrollTop, expected);
    assert.strictEqual(body.scrollTop, 40, 'Touch must not chain at list boundaries');
    assert(edge.prevented && edge.stopped);
    context.endPickerTouchScroll();
}
context.beginPickerTouchScroll(event({target: body, touches: [touch(100)]}));
const outside = event({target: body, touches: [touch(60)], cancelable: true});
context.movePickerTouchScroll(outside);
assert(!outside.prevented, 'Touch scrolling outside a picker must stay native');
assert(source.includes("document.addEventListener('touchmove', movePickerTouchScroll, { capture: true, passive: false })"));
console.log('Picker wheel and touch scroll tests passed.');
