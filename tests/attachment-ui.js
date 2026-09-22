'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
const html = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/index.html'), 'utf8');
const moduleSource = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/module.php'), 'utf8');

function functionSource(name) {
    const start = source.indexOf(`function ${name}(`);
    assert(start >= 0, `Missing function: ${name}`);
    const next = source.indexOf('\nfunction ', start + 1);
    return source.slice(start, next < 0 ? source.length : next);
}

const context = vm.createContext({});
for (const name of ['providerAttachmentSelector', 'normalizedAttachmentMetadata', 'safeAttachmentDownloadName']) {
    vm.runInContext(functionSource(name), context);
}

assert.deepStrictEqual(
    JSON.parse(JSON.stringify(context.providerAttachmentSelector(
        {sourceType: 'microsoft-todo', taskId: 'task', taskListId: 'list'},
        {canReadProviderAttachments: true, attachmentSelectorType: 'event-reference'}
    ))),
    {sourceType: 'microsoft-todo', taskId: 'task', taskListId: 'list'},
    'Microsoft To Do must use the concrete task and list identity.'
);
assert.deepStrictEqual(
    JSON.parse(JSON.stringify(context.providerAttachmentSelector(
        {eventReference: 'event'},
        {canReadProviderAttachments: true, attachmentSelectorType: 'event-reference'}
    ))),
    {eventReference: 'event'},
    'Microsoft events must use their immutable provider reference.'
);
assert.deepStrictEqual(
    JSON.parse(JSON.stringify(context.providerAttachmentSelector(
        {uid: 'series', recurrenceId: '20260922T100000Z'},
        {canReadProviderAttachments: true, attachmentSelectorType: 'icalendar'}
    ))),
    {uid: 'series', recurrenceId: '20260922T100000Z'},
    'CalDAV occurrences must retain their exact recurrence slot.'
);
assert.strictEqual(
    context.providerAttachmentSelector({uid: 'event'}, {canReadProviderAttachments: false, attachmentSelectorType: 'icalendar'}),
    null,
    'A disabled calendar capability must hide attachment access.'
);

assert.strictEqual(context.normalizedAttachmentMetadata({id: '1', name: 'x.pdf', kind: 'file', size: 12}).name, 'x.pdf');
assert.strictEqual(context.normalizedAttachmentMetadata({id: '', name: 'x.pdf', kind: 'file', size: 12}), null);
assert.strictEqual(context.normalizedAttachmentMetadata({id: '1', name: 'x.pdf', kind: 'unknown', size: 12}), null);
assert.strictEqual(context.safeAttachmentDownloadName('../private.txt'), '.._private.txt');

const openDetails = functionSource('openEventDetails');
assert(openDetails.includes('resetAttachmentDetails(event);'), 'Opening details must only prepare the lazy attachment area.');
assert(!openDetails.includes('loadSelectedEventAttachments('), 'Opening details must not contact the provider eagerly.');
assert(source.includes("eventAttachmentsLoadButton.addEventListener('click', () => void loadSelectedEventAttachments())"));
assert(source.includes("name.textContent = file.name;"), 'Untrusted filenames must be rendered as text.');
assert(!source.includes('name.innerHTML = file.name'), 'Untrusted filenames must never be rendered as HTML.');
assert(source.includes("body.set('action', 'TransferAttachment');"));
assert(source.includes("link.download = attachmentResponseName(response, file.name);"));
assert(source.includes('URL.revokeObjectURL(url)'));
assert(html.includes('id="details-attachments"') && html.includes('id="details-load-attachments"'));
assert(moduleSource.includes("'runtime'            => $runtime"));
assert(!moduleSource.includes('$runtime = $ipsView\n'));

console.log('Lazy attachment list and protected download UI tests passed.');
