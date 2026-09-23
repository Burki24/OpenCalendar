'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
const html = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/index.html'), 'utf8');
const moduleSource = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/module.php'), 'utf8');

function functionSource(name) {
    const asyncStart = source.indexOf(`async function ${name}(`);
    const start = asyncStart >= 0 ? asyncStart : source.indexOf(`function ${name}(`);
    assert(start >= 0, `Missing function: ${name}`);
    const following = /\n(?:async )?function /g;
    following.lastIndex = start + 1;
    const next = following.exec(source)?.index ?? -1;
    return source.slice(start, next < 0 ? source.length : next);
}

const context = vm.createContext({URL, localAttachmentMaximumUploadBytes: 2 * 1024 * 1024});
for (const name of ['providerAttachmentSelector', 'localAttachmentSelector', 'trustedMicrosoftReferenceUrl', 'trustedGoogleAttachmentUrl', 'normalizedAttachmentMetadata', 'normalizedLocalAttachmentMetadata', 'safeAttachmentDownloadName']) {
    vm.runInContext(functionSource(name), context);
}
context.calendarEntryByInstanceId = () => ({
    instanceId: 42,
    canReadProviderAttachments: true,
    canManageProviderAttachments: true,
    attachmentSelectorType: 'icalendar'
});
for (const name of ['attachmentTransferValue', 'providerAttachmentUploadValue', 'providerAttachmentDeleteValue']) {
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
assert.strictEqual(
    context.providerAttachmentUploadValue({calendarInstanceId: 42, uid: 'series', recurrenceType: 'occurrence'}),
    null,
    'A generated CalDAV occurrence must not accidentally attach a file to the series.'
);
assert.deepStrictEqual(
    JSON.parse(JSON.stringify(context.providerAttachmentUploadValue({calendarInstanceId: 42, uid: 'event'}, {name: 'Proof.pdf', content: 'JVBERg=='}))),
    {calendarId: 42, operation: 'upload', destination: 'provider', selector: {uid: 'event'}, data: {name: 'Proof.pdf', content: 'JVBERg=='}},
    'Provider upload must declare the selected owner and destination explicitly.'
);
assert.deepStrictEqual(
    JSON.parse(JSON.stringify(context.providerAttachmentDeleteValue(
        {calendarInstanceId: 42, uid: 'event'}, {id: 'attached-file', kind: 'embedded', destination: 'provider'}
    ))),
    {calendarId: 42, operation: 'delete', destination: 'provider', selector: {uid: 'event'}, data: {id: 'attached-file'}},
    'Provider deletion must target the selected event and exact file.'
);
assert.strictEqual(context.providerAttachmentDeleteValue(
    {calendarInstanceId: 42, uid: 'series', recurrenceType: 'occurrence'},
    {id: 'attached-file', kind: 'embedded', destination: 'provider'}
), null, 'A generated CalDAV occurrence must not delete from the master.');
assert.strictEqual(context.providerAttachmentDeleteValue(
    {calendarInstanceId: 42, uid: 'event'},
    {id: 'body-reference-' + 'a'.repeat(64), kind: 'reference', destination: 'provider'}
), null, 'A description-only Outlook link must not expose provider deletion.');
assert.strictEqual(context.providerAttachmentDeleteValue(
    {calendarInstanceId: 42, uid: 'event'},
    {id: 'graph-file', kind: 'file', destination: 'provider', isInline: true}
), null, 'Inline Graph attachments must not expose provider deletion.');

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
const googleFile = {id: 'file-1', name: 'x.pdf', kind: 'reference', size: null, url: 'https://drive.google.com/file/d/file-1/view'};
assert.strictEqual(context.normalizedAttachmentMetadata(googleFile, 'google').url, googleFile.url);
assert.strictEqual(context.normalizedAttachmentMetadata({...googleFile, url: 'https://docs.google.com/document/d/file-1/edit'}, 'google').url,
    'https://docs.google.com/document/d/file-1/edit');
for (const url of ['https://drive.google.com.evil.invalid/file', 'https://user@drive.google.com/file',
    'http://drive.google.com/file', 'https://drive.google.com:444/file', 'https://evil.invalid/file']) {
    assert.strictEqual(context.normalizedAttachmentMetadata({...googleFile, url}, 'google').url, '', `Untrusted Google URL opened: ${url}`);
}
assert.strictEqual(context.normalizedAttachmentMetadata(googleFile, 'microsoft').url, '',
    'A Google link must not bypass Microsoft URL validation.');
context.calendarEntryByInstanceId = () => ({
    instanceId: 42,
    canReadProviderAttachments: true,
    canManageProviderAttachments: false,
    attachmentSelectorType: 'event-reference',
    attachmentReferenceProvider: 'google'
});
assert.strictEqual(context.providerAttachmentUploadValue({calendarInstanceId: 42, eventReference: 'event-1'}), null,
    'Google provider upload must remain unavailable without a Drive scope.');
assert.deepStrictEqual(JSON.parse(JSON.stringify(context.localAttachmentSelector(
    {eventReference: 'event-1'}, {canReadLocalAttachments: true, attachmentLocalOwnerType: 'google'}
))), {eventReference: 'event-1'}, 'Google local files must use the exact provider event ID.');
assert.strictEqual(context.localAttachmentSelector(
    {uid: 'event-1'}, {canReadLocalAttachments: true, attachmentLocalOwnerType: 'google'}
), null, 'Google local files must not be bound by a caller-supplied iCalendar UID.');
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
assert.strictEqual(context.safeAttachmentDownloadName('report:2026?.pdf'), 'report_2026_.pdf');

const openDetails = functionSource('openEventDetails');
assert(openDetails.includes('resetAttachmentDetails(event);'), 'Opening details must only prepare the lazy attachment area.');
assert(!openDetails.includes('loadSelectedEventAttachments('), 'Opening details must not contact the provider eagerly.');
assert(source.includes("eventAttachmentsLoadButton.addEventListener('click', () => void loadSelectedEventAttachments())"));
assert(source.includes("name.textContent = file.name;"), 'Untrusted filenames must be rendered as text.');
assert(!source.includes('name.innerHTML = file.name'), 'Untrusted filenames must never be rendered as HTML.');
assert(source.includes('Promise.allSettled([localValue, providerValue]'),
    'Google events must load both local files and provider references independently.');
assert(source.includes("calendarVisualization.mode === 'ipsview'"),
    'IPSView must have a distinct external-link behavior.');
assert(source.includes("button.addEventListener('click', () => void copyExternalLink(file.url, event))"),
    'IPSView reference attachments must copy their validated link instead of opening a blocked new window.');
assert(source.includes("void copyExternalLink(url, selectedEvent);"),
    'IPSView provider-event links must use the same copy behavior.');
assert(source.includes("link.target = '_blank';"),
    'Native browser views must retain direct external attachment links.');
assert(html.includes('id="details-external-link-status"') && html.includes('id="details-external-link-input"'),
    'If the host blocks clipboard access, the link must remain selectable in the details dialog.');
assert(source.includes("body.set('action', 'TransferAttachment');"));
assert(source.includes("attachmentResponseName(response, file.name)"));
assert(source.includes('URL.revokeObjectURL(url)'));
assert(html.includes('id="details-attachments"') && html.includes('id="details-load-attachments"'));
assert(html.includes('id="details-add-attachment"') && html.includes('id="details-attachment-file"'));
assert(html.includes('id="details-local-attachment-note"'));
assert(html.includes('id="details-provider-attachment-note"'));
assert(html.includes('id="attachment-delete-confirm-dialog"'));
assert(source.includes("destination: 'local'"), 'Local transfers must declare their storage destination explicitly.');
assert(source.includes("crypto.getRandomValues"), 'Upload retry identities must use cryptographic randomness.');
assert(moduleSource.includes("'runtime'            => $runtime"));
assert(moduleSource.includes("'canReadLocalAttachments'"));
assert(moduleSource.includes("'canManageLocalAttachments'"));
assert(moduleSource.includes("'canManageProviderAttachments'"));
assert(!moduleSource.includes('$runtime = $ipsView\n'));

(async () => {
    const labels = vm.createContext({calendarVisualization: {mode: 'ipsview'}, t: value => value});
    vm.runInContext(functionSource('providerLinkText'), labels);
    vm.runInContext(functionSource('externalLinkActionLabel'), labels);
    assert.strictEqual(labels.externalLinkActionLabel('Open in provider'), 'Copy link');
    labels.calendarVisualization.mode = 'symcon';
    assert.strictEqual(labels.externalLinkActionLabel('Open in provider'), 'Open in provider',
        'The native view must retain the existing external-opening label.');

    const event = {url: 'https://calendar.google.com/calendar/u/0/r/eventedit/abc'};
    const opened = [];
    const requestedCopies = [];
    const openContext = vm.createContext({
        selectedEvent: event,
        providerEventUrl: value => value.url,
        calendarVisualization: {mode: 'ipsview'},
        copyExternalLink: url => requestedCopies.push(url),
        window: {open: url => opened.push(url)}
    });
    vm.runInContext(functionSource('openProviderEvent'), openContext);
    openContext.openProviderEvent();
    assert.deepStrictEqual(requestedCopies, [event.url]);
    assert.deepStrictEqual(opened, [], 'IPSView must not request a blocked new window.');
    openContext.calendarVisualization.mode = 'symcon';
    openContext.openProviderEvent();
    assert.deepStrictEqual(opened, [event.url], 'The native tile must retain direct provider opening.');

    const status = {classList: {remove(name) { this.removed = name; }}};
    const input = {
        classList: {toggle(name, hidden) { this.hidden = hidden; }},
        focus() { this.focused = true; },
        select() { this.selected = true; }
    };
    const feedbackContext = vm.createContext({
        document: {getElementById: id => id === 'details-external-link-status' ? status : input},
        t: value => value
    });
    vm.runInContext(functionSource('showExternalLinkFeedback'), feedbackContext);
    feedbackContext.showExternalLinkFeedback(event.url, false);
    assert.strictEqual(input.value, event.url, 'A denied clipboard must leave the URL visibly selectable.');
    assert.strictEqual(input.classList.hidden, false);
    assert(input.focused && input.selected);
    feedbackContext.showExternalLinkFeedback(event.url, true);
    assert.strictEqual(input.value, '', 'A successful copy must not leave the private URL visible.');
    assert.strictEqual(input.classList.hidden, true);

    const results = [];
    const copiedUrls = [];
    let legacyAllowed = true;
    let clipboardAllowed = true;
    const copyContext = vm.createContext({
        document: {
            createElement: () => ({style: {}, select() {}, remove() {}}),
            execCommand: () => legacyAllowed
        },
        navigator: {clipboard: {writeText: async url => {
            copiedUrls.push(url);
            if (!clipboardAllowed) throw new Error('Clipboard denied');
        }}},
        eventDetailsDialog: {open: true, append() {}},
        selectedEvent: event,
        showExternalLinkFeedback: (url, copied) => results.push({url, copied})
    });
    vm.runInContext(functionSource('copyExternalLink'), copyContext);
    await copyContext.copyExternalLink(event.url, event);
    assert.deepStrictEqual(results.pop(), {url: event.url, copied: true},
        'IPSView must confirm a successful direct clipboard copy.');
    assert.strictEqual(copiedUrls.length, 0, 'A working legacy clipboard command needs no second write.');

    legacyAllowed = false;
    await copyContext.copyExternalLink(event.url, event);
    assert.deepStrictEqual(results.pop(), {url: event.url, copied: true},
        'IPSView must try the modern Clipboard API when its legacy command is unavailable.');

    clipboardAllowed = false;
    await copyContext.copyExternalLink(event.url, event);
    assert.deepStrictEqual(results.pop(), {url: event.url, copied: false},
        'IPSView must display a selectable fallback when both clipboard methods are denied.');

    copyContext.eventDetailsDialog.open = false;
    await copyContext.copyExternalLink(event.url, event);
    assert.strictEqual(results.length, 0, 'A closed details dialog must not show stale copy feedback.');
    console.log('Lazy attachment list and protected download UI tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
