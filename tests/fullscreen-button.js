'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
const markup = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/index.html'), 'utf8');
const moduleSource = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/module.php'), 'utf8');
assert(markup.includes('id="fullscreen-button"'), 'The calendar toolbar must contain the fullscreen button');
assert(moduleSource.includes("'enableHtmlFullscreen' => !$ipsView && $this->ReadPropertyBoolean('EnableHTMLFullscreen')"),
    'The native tile must expose the active fullscreen option to its client');
const start = source.indexOf('function configureFullscreenButton(');
assert(start >= 0, 'The calendar needs a fullscreen control in its own toolbar');
const end = source.indexOf('\n}', start);
assert(end > start);
const configure = source.slice(start, end + 2);

function check(mode, enabled, instanceId, hasOpenObject) {
    const calls = [];
    const attributes = {};
    const classes = new Set(['hidden']);
    const button = {
        classList: {toggle: (name, value) => value ? classes.add(name) : classes.delete(name)},
        setAttribute: (name, value) => { attributes[name] = value; },
        addEventListener: (name, callback) => { button[name] = callback; }
    };
    const context = vm.createContext({
        calendarVisualization: {mode},
        calendarOptions: {enableHtmlFullscreen: enabled, instanceId},
        document: {getElementById: id => id === 'fullscreen-button' ? button : null},
        window: hasOpenObject ? {openObject: id => calls.push(id)} : {},
        t: text => text,
        isNativeVisualization: () => mode === 'symcon'
    });
    vm.runInContext(configure, context);
    context.configureFullscreenButton();
    return {button, calls, attributes, classes};
}

const native = check('symcon', true, 12345, true);
assert(!native.classes.has('hidden'), 'The enabled native tile needs a visible fullscreen button');
assert.strictEqual(native.attributes['aria-label'], 'Open fullscreen');
native.button.click();
assert.deepStrictEqual(native.calls, [12345], 'The button must open this calendar instance');

for (const [mode, enabled, id, bridge] of [
    ['ipsview', true, 12345, true],
    ['symcon', false, 12345, true],
    ['symcon', true, 0, true],
    ['symcon', true, 12345, false]
]) {
    const result = check(mode, enabled, id, bridge);
    assert(result.classes.has('hidden'), `${mode}/${enabled}/${id}/${bridge}: button must stay hidden`);
    assert.strictEqual(result.button.click, undefined);
}

console.log('Fullscreen button tests passed.');
