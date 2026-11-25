'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
const markup = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/index.html'), 'utf8');
const moduleSource = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/module.php'), 'utf8');
assert(markup.includes('id="fullscreen-button"'), 'The calendar toolbar must contain the fullscreen button');
assert(moduleSource.includes("'enableHtmlFullscreen' => $this->ReadPropertyBoolean('EnableHTMLFullscreen')"),
    'Both calendar HTML outputs must receive the active fullscreen option');
const start = source.indexOf('function configureFullscreenButton(');
assert(start >= 0, 'The calendar needs a fullscreen control in its own toolbar');
const end = source.indexOf('\n}', start);
assert(end > start);
const configure = source.slice(start, end + 2);

function check(mode, enabled, instanceId, hasOpenObject, hasFullscreen = false) {
    const calls = [];
    const attributes = {};
    const classes = new Set(['hidden']);
    const button = {
        classList: {toggle: (name, value) => value ? classes.add(name) : classes.delete(name)},
        setAttribute: (name, value) => { attributes[name] = value; },
        addEventListener: (name, callback) => { button[name] = callback; }
    };
    const document = {
        fullscreenEnabled: hasFullscreen,
        fullscreenElement: null,
        documentElement: hasFullscreen ? {requestFullscreen: () => {
            calls.push('requestFullscreen');
            document.fullscreenElement = document.documentElement;
            return Promise.resolve();
        }} : {},
        exitFullscreen: () => {
            calls.push('exitFullscreen');
            document.fullscreenElement = null;
            return Promise.resolve();
        },
        getElementById: id => id === 'fullscreen-button' ? button : null,
        addEventListener: (name, callback) => { document[name] = callback; }
    };
    const context = vm.createContext({
        calendarVisualization: {mode},
        calendarOptions: {enableHtmlFullscreen: enabled, instanceId},
        document,
        window: hasOpenObject ? {openObject: id => calls.push(id)} : {},
        t: text => text,
        showToast: (message, level) => calls.push([message, level]),
        isNativeVisualization: () => mode === 'symcon'
    });
    vm.runInContext(configure, context);
    context.configureFullscreenButton();
    return {button, calls, attributes, classes, document};
}

async function main() {
    const native = check('symcon', true, 12345, true);
    assert(!native.classes.has('hidden'), 'The enabled native tile needs a visible fullscreen button');
    assert.strictEqual(native.attributes['aria-label'], 'Open fullscreen');
    await native.button.click();
    assert.deepStrictEqual(native.calls, [12345], 'The button must open this calendar instance');

    const ipsview = check('ipsview', true, 12345, false, true);
    assert(!ipsview.classes.has('hidden'), 'IPSView HTML needs a visible fullscreen button when the host allows fullscreen');
    await ipsview.button.click();
    assert.deepStrictEqual(ipsview.calls, ['requestFullscreen']);
    ipsview.document.fullscreenchange();
    assert.strictEqual(ipsview.attributes['aria-label'], 'Exit fullscreen');
    await ipsview.button.click();
    assert.deepStrictEqual(ipsview.calls, ['requestFullscreen', 'exitFullscreen']);

    for (const [mode, enabled, id, bridge, fullscreen] of [
        ['ipsview', true, 12345, false, false],
        ['ipsview', false, 12345, false, true],
        ['symcon', false, 12345, true, false],
        ['symcon', true, 0, true, false],
        ['symcon', true, 12345, false, false]
    ]) {
        const result = check(mode, enabled, id, bridge, fullscreen);
        assert(result.classes.has('hidden'), `${mode}/${enabled}/${id}/${bridge}: button must stay hidden`);
        assert.strictEqual(result.button.click, undefined);
    }

    console.log('Fullscreen button tests passed.');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
