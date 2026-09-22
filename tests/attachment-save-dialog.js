'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../Kalender Ansicht/visualization/app.js'), 'utf8');
const start = source.indexOf('async function downloadAttachment(');
const end = source.indexOf('\nfunction requestLocalAttachmentDelete(', start);
assert(start >= 0 && end > start, 'Attachment download workflow is missing.');
const downloadSource = source.slice(start, end);

async function checkSavePicker() {
    const calls = [];
    const bytes = Buffer.from('private-file');
    const writable = {
        write: async blob => {
            calls.push('write');
            assert.deepStrictEqual(Buffer.from(await blob.arrayBuffer()), bytes);
        },
        close: async () => calls.push('close'),
        abort: async () => calls.push('abort')
    };
    const context = vm.createContext({
        attachmentDetailsRevision: 1,
        providerAttachmentMaximumDownloadBytes: 3 * 1024 * 1024,
        localAttachmentTransferValue: () => null,
        attachmentTransferValue: () => ({destination: 'provider', operation: 'download'}),
        safeAttachmentDownloadName: name => name,
        attachmentResponseName: () => 'Proof.pdf',
        setAttachmentDetailsStatus: message => calls.push(`status:${message}`),
        t: message => message,
        attachmentTransferRequest: async () => {
            calls.push('fetch');
            return {
                headers: {get: () => String(bytes.length)},
                blob: async () => ({size: bytes.length, arrayBuffer: async () => bytes})
            };
        },
        window: {
            showSaveFilePicker: async options => {
                calls.push('picker');
                assert.strictEqual(options.suggestedName, 'Proof.pdf');
                return {createWritable: async () => writable};
            }
        },
        URL: {createObjectURL: () => { throw new Error('Picker download must not use a temporary URL.'); }},
        document: {createElement: () => { throw new Error('Picker download must not create an anchor.'); }}
    });
    vm.runInContext(downloadSource, context);
    const button = {disabled: false};
    await context.downloadAttachment({}, {id: 'file', name: 'Proof.pdf', destination: 'provider'}, button, 1);
    assert(calls.indexOf('picker') >= 0 && calls.indexOf('picker') < calls.indexOf('fetch'),
        'Save location must be chosen before the network request to retain user activation.');
    assert.deepStrictEqual(calls.filter(call => ['picker', 'fetch', 'write', 'close', 'abort'].includes(call)),
        ['picker', 'fetch', 'write', 'close'], JSON.stringify(calls));
    assert.strictEqual(button.disabled, false);
}

async function checkCancelledPicker() {
    const calls = [];
    const context = vm.createContext({
        attachmentDetailsRevision: 1,
        localAttachmentTransferValue: () => null,
        attachmentTransferValue: () => ({operation: 'download'}),
        safeAttachmentDownloadName: name => name,
        setAttachmentDetailsStatus: message => calls.push(`status:${message}`),
        t: message => message,
        attachmentTransferRequest: () => { throw new Error('Cancelling must not download the file.'); },
        window: {showSaveFilePicker: async () => {
            calls.push('picker');
            throw Object.assign(new Error('Cancelled'), {name: 'AbortError'});
        }}
    });
    vm.runInContext(downloadSource, context);
    const button = {disabled: false};
    await context.downloadAttachment({}, {id: 'file', name: 'Proof.pdf', destination: 'provider'}, button, 1);
    assert(calls.includes('picker') && calls.includes('status:') && !calls.includes('status:The attachment could not be downloaded.'));
    assert.strictEqual(button.disabled, false);
}

async function checkFallback(blockedPicker = false) {
    const calls = [];
    const bytes = Buffer.from('private-file');
    const link = {style: {}, click: () => calls.push('click'), remove: () => calls.push('remove')};
    const context = vm.createContext({
        attachmentDetailsRevision: 1,
        providerAttachmentMaximumDownloadBytes: 3 * 1024 * 1024,
        localAttachmentTransferValue: () => null,
        attachmentTransferValue: () => ({operation: 'download'}),
        safeAttachmentDownloadName: name => name,
        attachmentResponseName: () => 'Proof.pdf',
        setAttachmentDetailsStatus: message => calls.push(`status:${message}`),
        t: message => message,
        attachmentTransferRequest: async () => {
            calls.push('fetch');
            return {headers: {get: () => String(bytes.length)}, blob: async () => ({size: bytes.length})};
        },
        window: {
            setTimeout: () => {},
            ...(blockedPicker ? {showSaveFilePicker: async () => {
                throw Object.assign(new Error('Unavailable'), {name: 'SecurityError'});
            }} : {})
        },
        URL: {createObjectURL: () => 'blob:test', revokeObjectURL: () => {}},
        document: {createElement: () => link, body: {appendChild: () => calls.push('append')}}
    });
    vm.runInContext(downloadSource, context);
    const button = {disabled: false};
    await context.downloadAttachment({}, {id: 'file', name: 'Proof.pdf', destination: 'provider'}, button, 1);
    assert.strictEqual(link.download, 'Proof.pdf', JSON.stringify({blockedPicker, calls}));
    assert(calls.includes('click') && calls.some(call => call.includes('download folder')),
        'Fallback must keep downloads available and explain that the app/browser chooses the location.');
    assert.strictEqual(button.disabled, false);
}

Promise.resolve().then(checkSavePicker).then(checkCancelledPicker)
    .then(() => checkFallback(false)).then(() => checkFallback(true)).then(
    () => console.log('Attachment save dialog, cancellation and fallback passed.'), error => {
    console.error(error);
    process.exitCode = 1;
    }
);
