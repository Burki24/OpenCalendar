'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/style.css'), 'utf8');

function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, 'Missing client function: ' + name);
    const end = source.indexOf('\n}', start);
    assert(end > start, 'Missing client function end: ' + name);
    return source.slice(source.slice(start - 6, start) === 'async ' ? start - 6 : start, end + 2);
}

const requests = [];
const timers = [];
const context = vm.createContext({
    initialized: true, eventEditingActive: false, document: {visibilityState: 'visible'},
    native: true, bridge: false, hasActionBridge: () => context.bridge,
    isNativeVisualization: () => context.native,
    calendarState: {events: [], calendars: [], settings: {}, eventRange: null},
    visibleViewRange: () => ({start: 10, end: 20}),
    visualizationRangeSignature: range => range ? `${range.start}:${range.end}` : '',
    visualizationRangePageSignature: (range, offset) => range ? `${range.start}:${range.end}:${offset}` : '',
    loadedRangeCovers: () => false,
    requestVisibleRangePage: async (range, offset, force) => requests.push({range, offset, force}),
    visibleRangeRetryTimer: null, visibleRangeRetryAttempts: 0, visibleRangeRetryForce: false,
    visibleRangeRetryMaxAttempts: 40, visibleRangeRetryDelayMilliseconds: 300,
    window: {setTimeout: callback => {timers.push(callback); return timers.length;}, clearTimeout: () => {}},
    agendaScrollWorkflow: '', preservedAgendaScrollPosition: null, releaseAgendaScrollPositionAfterState: false,
    captureAgendaScrollPosition: () => null, pendingRangeRequestSignature: '',
    calendarStateContentSignature: () => 'state', calendarStateSignature: '',
    normalizeVisibleCalendarIds: () => {}, applyTileFontScale: () => {},
    restoreClientViewState: () => {}, applyStaticTranslations: () => {},
    clampCurrentCursorDate: () => false, render: () => {}, restoreAgendaScrollPosition: () => {},
    pendingEventEdit: null, pendingSeriesEdit: null
});
for (const name of ['ensureVisibleRangeLoaded', 'scheduleVisibleRangeRetry', 'cancelVisibleRangeRetry', 'applyCalendarState']) {
    vm.runInContext(extract(name), context);
}

(async () => {
    // A bootstrap state must start native loading, even while the bridge is arriving.
    context.applyCalendarState({events: [], settings: {defaultView: 'month'}});
    assert.strictEqual(timers.length, 1, 'Native bootstrap must schedule a missing-bridge retry');
    await context.ensureVisibleRangeLoaded(true);
    assert.strictEqual(timers.length, 1, 'Retries must coalesce while preserving force');
    context.bridge = true;
    context.requestAction = () => {};
    timers.shift()();
    await Promise.resolve();
    assert.strictEqual(requests.length, 1);
    assert.strictEqual(requests[0].force, true, 'Deferred retry must preserve forced reload');
    assert.strictEqual(requests[0].offset, 0);

    context.calendarState.eventRange = {start: 10, end: 20, hasMore: true, nextOffset: 200};
    await context.ensureVisibleRangeLoaded();
    assert.strictEqual(requests[1].offset, 200, 'Bootstrap paging must continue at the supplied offset');
    context.loadedRangeCovers = () => true;
    context.calendarState.eventRange.hasMore = false;
    await context.ensureVisibleRangeLoaded();
    assert.strictEqual(requests.length, 2, 'Loaded range must not be requested again');

    context.bridge = false;
    context.native = false;
    await context.ensureVisibleRangeLoaded();
    assert.strictEqual(timers.length, 0, 'IPSView must not use native bridge retries');
    context.native = true;
    context.document.visibilityState = 'hidden';
    await context.ensureVisibleRangeLoaded();
    assert.strictEqual(timers.length, 0, 'Hidden views must not start requests');
    context.document.visibilityState = 'visible';
    context.eventEditingActive = true;
    await context.ensureVisibleRangeLoaded();
    assert.strictEqual(timers.length, 0, 'Editing must defer range loading');
    context.eventEditingActive = false;
    context.visibleRangeRetryAttempts = 40;
    await context.ensureVisibleRangeLoaded();
    assert.strictEqual(timers.length, 0, 'Missing-bridge retry budget must be bounded');

    // CSS contracts supplement behavior tests; actual browser layout still needs visual verification.
    assert(css.includes('container-name: symcon-visualization;'));
    assert(css.includes('container-type: inline-size;'));
    for (const width of ['50rem', '35rem', '26rem']) {
        assert(css.includes(`@container symcon-visualization (max-width: ${width})`));
    }
    assert(css.includes('--symc-responsive-touch-target: 44px;'));
    assert(css.includes('#event-state-row { align-items: start; }'), 'Preserve 9.1 event-state controls');
    console.log('Startup client behavior and responsive CSS contracts passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
