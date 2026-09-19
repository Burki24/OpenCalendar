'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
process.env.TZ = 'Europe/Berlin';
const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, 'Missing function ' + name);
    const lineEnd = source.indexOf('\n', start);
    return source.slice(start, source.slice(start, lineEnd).trimEnd().endsWith('}') ? lineEnd : source.indexOf('\n}', start) + 2);
}
const context = vm.createContext({Date, Intl, compareEventsForDisplay: () => 0});
for (const name of ['startOfDay', 'addDays', 'eventStart', 'eventEnd', 'allDayDate', 'eventOverlaps', 'dailyViewEntries', 'formatTime']) {
    vm.runInContext(extract(name), context);
}
const date = value => new Date(value.length === 10 ? value + 'T00:00:00' : value);
const timed = (start, end) => Object.freeze({startTimestamp: date(start).getTime() / 1000, endTimestamp: date(end).getTime() / 1000});
const event = timed('2026-09-21T09:00:00', '2026-09-22T12:00:00');
const entries = (e, from, to) => context.dailyViewEntries([e], date(from), date(to));
const days = rows => Array.from(rows, row => row.date.getDate());
assert.deepStrictEqual(days(entries(event, '2026-09-21', '2026-09-24')), [21, 22], 'Timed overnight event must appear on both days');
assert.deepStrictEqual(days(entries(event, '2026-09-22', '2026-09-24')), [22], 'Clip ongoing event at visible range');
assert.deepStrictEqual(days(entries(timed('2026-09-21T09:00', '2026-09-22T00:00'), '2026-09-21', '2026-09-24')), [21], 'Exclusive midnight end must not add a day');
assert.deepStrictEqual(days(entries(timed('2026-09-21T09:00', '2026-09-21T09:00'), '2026-09-21', '2026-09-24')), [21], 'Zero-duration events remain visible');
assert.strictEqual(entries(event, '2026-09-23', '2026-09-24').length, 0, 'Out-of-range event excluded');
const allDay = Object.freeze({allDay:true, start:'2026-09-21', end:'2026-09-23'});
assert.deepStrictEqual(days(entries(allDay, '2026-09-21', '2026-09-24')), [21, 22], 'All-day end remains exclusive');
for (const [start, end, expected] of [
    ['2026-03-28T09:00', '2026-03-30T12:00', [28,29,30]],
    ['2026-10-24T09:00', '2026-10-26T12:00', [24,25,26]],
    ['2026-12-31T09:00', '2027-01-01T12:00', [31,1]]
]) {
    const e = timed(start, end);
    const rows = entries(e, start.slice(0,10), end.slice(0,10) + 'T23:59:59');
    assert.deepStrictEqual(days(rows), expected, 'Calendar days across DST/year boundary');
    assert(rows.every(row => row.event === e), 'Daily display must retain original event identity');
}
vm.runInContext(extract('eventDayTimes'), context);
const labels = (e, day) => Array.from(context.eventDayTimes(e, date(day)));
assert.deepStrictEqual(labels(event, '2026-09-21'), [context.formatTime(date('2026-09-21T09:00')), '24:00']);
assert.deepStrictEqual(labels(event, '2026-09-22'), [context.formatTime(date('2026-09-22T00:00')), context.formatTime(date('2026-09-22T12:00'))]);
const sameDay = timed('2026-09-21T09:00', '2026-09-21T12:00');
assert.deepStrictEqual(labels(sameDay, '2026-09-21'), [context.formatTime(date('2026-09-21T09:00')), context.formatTime(date('2026-09-21T12:00'))]);
// Exercise the real renderers with a minimal DOM boundary, not a reimplementation
// of the day/time logic. Clicking a daily representation must edit the original.
function element(tag, className = '') {
    return {tag, className, children: [], dataset: {}, style: {setProperty() {}},
        classList: {add() {}}, listeners: {}, textContent: '',
        append(...nodes) { this.children.push(...nodes); },
        appendChild(node) { this.children.push(node); },
        setAttribute() {}, addEventListener(type, fn) { this.listeners[type] = fn; }};
}
let opened;
Object.assign(context, {element, document:{createElement:element},
    calendarState:{settings:{showAnniversaryType:false}},
    t: text => text, safeColor: () => '#fff', eventDisplaySummary: () => 'Overnight',
    openEventDetails: e => {opened = e;}, annualEventLabel: () => '', agendaEventAnchorKey: () => 'original'});
for (const name of ['createAgendaEvent', 'createWeekEventElement', 'createMonthEventChip', 'createSingleDayTimelineEvent', 'listColumns']) {
    vm.runInContext(extract(name), context);
}
const nextDay = date('2026-09-22');
const midnight = context.formatTime(nextDay);
const agenda = context.createAgendaEvent(event, nextDay);
assert.strictEqual(agenda.children[1].textContent, midnight + '\n' + context.formatTime(date('2026-09-22T12:00')));
const week = context.createWeekEventElement(event, nextDay);
assert.strictEqual(week.children[1].textContent, midnight);
const month = context.createMonthEventChip(event, nextDay);
assert.strictEqual(month.textContent, midnight + ' Overnight');
const timeline = context.createSingleDayTimelineEvent(event, nextDay);
assert.strictEqual(timeline.children[1].textContent, midnight);
for (const node of [agenda, week, month, timeline]) {
    node.listeners.click();
    assert.strictEqual(opened, event, 'Daily cards open the unchanged original event');
}
const columns = context.listColumns();
assert.strictEqual(columns.find(c => c.key === 'start').value(event, nextDay), midnight);
assert.strictEqual(columns.find(c => c.key === 'end').value(event, nextDay), context.formatTime(date('2026-09-22T12:00')));
// Ensure each parent renderer passes its actual day, including both month paths.
for (const call of ['createAgendaEvent(event, group.date)', 'column.value(event, entry.date)',
    'createWeekEventElement(entry.event, dayStart)', 'createMonthEventChip(event, day)',
    'createSingleDayTimelineEvent(entry.event, dayStart)', "eventDayTimes(event, day).join(' – ')" ]) {
    assert(source.includes(call), 'Missing displayed-day wiring: ' + call);
}
console.log('Multi-day display, renderer labels, identity, midnight and DST tests passed.');
