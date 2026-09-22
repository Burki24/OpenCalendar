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

const context = vm.createContext({localAttachmentMaximumUploadBytes: 2 * 1024 * 1024});
for (const name of ['providerAttachmentSelector', 'localAttachmentSelector', 'normalizedAttachmentMetadata', 'normalizedLocalAttachmentMetadata', 'safeAttachmentDownloadName']) {
    vm.runInContext(functionSource(name), context);
}

const endpointContext = vm.createContext({
    URL,
    window: {location: {protocol: 'data:', href: 'data:text/html,fixture', ancestorOrigins: []}},
    document: {referrer: ''}
});
vm.runInContext(functionSource('calendarRuntimeEndpoint'), endpointContext);
assert.strictEqual(
    endpointContext.calendarRuntimeEndpoint({endpoint: '/hook/opencalendar/view/12345'}),
    '/hook/opencalendar/view/12345',
    'Standalone IPSView must preserve the hook path routed by its configured Symcon connection.'
);
endpointContext.document.referrer = 'http://192.168.178.6:3777/tile/';
assert.strictEqual(
    endpointContext.calendarRuntimeEndpoint({endpoint: '/hook/opencalendar/view/12345'}),
    'http://192.168.178.6:3777/hook/opencalendar/view/12345',
    'Embedded IPSView must prefer its local Symcon origin.'
);

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

assert.deepStrictEqual(
    JSON.parse(JSON.stringify(context.localAttachmentSelector(
        {uid: 'local-event', startTimestamp: 1789988400, endTimestamp: 1789992000},
        {canReadLocalAttachments: true}
    ))),
    {uid: 'local-event', startTimestamp: 1789988400, endTimestamp: 1789992000},
    'Local attachments must use the exact bounded local-event identity.'
);
assert.deepStrictEqual(
    JSON.parse(JSON.stringify(context.localAttachmentSelector(
        {
            uid: 'local-series',
            startTimestamp: 1789988400,
            endTimestamp: 1789992000,
            recurrenceId: '20260921T090000',
            originalStart: '2026-09-21T09:00:00+02:00'
        },
        {canReadLocalAttachments: true}
    ))),
    {
        uid: 'local-series',
        startTimestamp: 1789988400,
        endTimestamp: 1789992000,
        recurrenceId: '20260921T090000',
        originalStart: '2026-09-21T09:00:00+02:00'
    },
    'Local recurring attachments must retain their exact occurrence identity.'
);
assert.strictEqual(
    context.localAttachmentSelector(
        {uid: 'local-event', startTimestamp: 1789988400, endTimestamp: 1790683201},
        {canReadLocalAttachments: true}
    ),
    null,
    'The client must not offer local attachment operations outside the server selector bounds.'
);
assert.strictEqual(
    context.localAttachmentSelector(
        {uid: 'local-event', startTimestamp: 1789988400, endTimestamp: 1789992000},
        {canReadLocalAttachments: false}
    ),
    null,
    'A disabled local attachment capability must hide local attachment access.'
);

assert.strictEqual(context.normalizedAttachmentMetadata({id: '1', name: 'x.pdf', kind: 'file', size: 12}).name, 'x.pdf');
assert.strictEqual(context.normalizedAttachmentMetadata({id: '', name: 'x.pdf', kind: 'file', size: 12}), null);
assert.strictEqual(context.normalizedAttachmentMetadata({id: '1', name: 'x.pdf', kind: 'unknown', size: 12}), null);
assert.strictEqual(
    context.normalizedLocalAttachmentMetadata({
        id: 'a'.repeat(64), name: 'local.pdf', revision: 'b'.repeat(64), size: 12
    }).destination,
    'local'
);
assert.strictEqual(
    context.normalizedLocalAttachmentMetadata({
        id: 'a'.repeat(64), name: 'local.pdf', revision: 'stale', size: 12
    }),
    null,
    'Local deletion must require an authoritative current revision.'
);
assert.strictEqual(context.safeAttachmentDownloadName('../private.txt'), '.._private.txt');

const openDetails = functionSource('openEventDetails');
assert(openDetails.includes('resetAttachmentDetails(event);'), 'Opening details must only prepare the lazy attachment area.');
assert(!openDetails.includes('loadSelectedEventAttachments('), 'Opening details must not contact the provider eagerly.');
assert(source.includes("eventAttachmentsLoadButton.addEventListener('click', () => void loadSelectedEventAttachments())"));
assert(source.includes("name.textContent = file.name;"), 'Untrusted filenames must be rendered as text.');
assert(!source.includes('name.innerHTML = file.name'), 'Untrusted filenames must never be rendered as HTML.');
assert(source.includes("body.set('action', 'TransferAttachment');"));
assert(source.includes("attachmentResponseName(response, file.name)"));
assert(source.includes('URL.revokeObjectURL(url)'));
assert(html.includes('id="details-attachments"') && html.includes('id="details-load-attachments"'));
assert(html.includes('id="details-add-attachment"') && html.includes('id="details-attachment-file"'));
assert(html.includes('id="details-local-attachment-note"'));
assert(html.includes('id="attachment-delete-confirm-dialog"'));
assert(source.includes("destination: 'local'"), 'Local transfers must declare their storage destination explicitly.');
assert(source.includes("crypto.getRandomValues"), 'Upload retry identities must use cryptographic randomness.');
assert(moduleSource.includes("'runtime'            => $runtime"));
assert(moduleSource.includes("'canReadLocalAttachments'"));
assert(moduleSource.includes("'canManageLocalAttachments'"));
assert(!moduleSource.includes('$runtime = $ipsView\n'));

console.log('Lazy attachment list and protected download UI tests passed.');
