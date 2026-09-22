'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
const start = source.indexOf('async function uploadSelectedAttachment(');
const end = source.indexOf('\nfunction openEventDetails(', start);
assert(start >= 0 && end > start, 'Attachment upload workflow is missing.');
const uploadSource = source.slice(start, end);

async function checkUpload(pending) {
    const calls = [];
    const event = {id: 'event'};
    let releaseWait = null;
    const context = vm.createContext({
        selectedEvent: event,
        attachmentDetailsRevision: 1,
        eventAttachmentsFileInput: {files: [{name: 'Proof.pdf', size: 16}], value: 'Proof.pdf'},
        eventAttachmentsAddButton: {disabled: false},
        eventDetailsDialog: {open: true},
        localAttachmentMaximumUploadBytes: 2 * 1024 * 1024,
        localAttachmentFileContent: async () => 'cGRm',
        localAttachmentRequestId: () => 'request',
        localAttachmentTransferValue: () => null,
        providerAttachmentUploadValue: () => ({operation: 'upload'}),
        attachmentTransferRequest: async () => {
            calls.push('upload');
            return {result: {uploaded: true, pendingVerification: pending}};
        },
        loadSelectedEventAttachments: async () => {
            calls.push('list');
            return true;
        },
        setAttachmentDetailsStatus: message => calls.push(`status:${message}`),
        t: message => message,
        window: {setTimeout: (callback, delay) => {
            assert.strictEqual(delay, 4000);
            calls.push('wait');
            releaseWait = callback;
        }}
    });
    vm.runInContext(uploadSource, context);
    const operation = context.uploadSelectedAttachment();
    await new Promise(resolve => setImmediate(resolve));
    if (pending) {
        assert.deepStrictEqual(calls.filter(call => ['upload', 'wait', 'list'].includes(call)), ['upload', 'wait']);
        assert.strictEqual(context.eventAttachmentsAddButton.disabled, true);
        releaseWait();
    }
    await operation;
    assert.deepStrictEqual(calls.filter(call => ['upload', 'wait', 'list'].includes(call)),
        pending ? ['upload', 'wait', 'list'] : ['upload', 'list']);
    assert.strictEqual(context.eventAttachmentsAddButton.disabled, false);
    if (pending) {
        assert(calls.some(call => call.includes('If the file is not visible yet')));
    }
}

Promise.resolve().then(() => checkUpload(true)).then(() => checkUpload(false)).then(
    () => console.log('CalDAV attachment upload waits asynchronously for the provider list.'),
    error => { console.error(error); process.exitCode = 1; }
);
