import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/officer-spt-process.js', import.meta.url), 'utf8');

function element(classes = []) {
    const listeners = {};
    const values = new Set(classes);
    const attrs = new Map();
    return {
        listeners, attrs, disabled: true, textContent: '', dataset: {},
        classList: {
            remove: (name) => values.delete(name),
            replace: (a, b) => { values.delete(a); values.add(b); },
            contains: (name) => values.has(name),
        },
        addEventListener: (event, callback) => { listeners[event] = callback; },
        setAttribute: (key, value) => attrs.set(key, value),
        removeAttribute: (key) => attrs.delete(key),
        click() {}, remove() {},
    };
}

function setup(fetch) {
    const download = element(['btn-primary']);
    download.href = '/officer/spt/example/concept.docx';
    download.dataset.downloadName = 'Konsep.docx';
    const status = element();
    const formUploadSection = element(['d-none']);
    const revoked = [];
    const events = {};
    const processPanel = {
        querySelector: (key) => {
            if (key === '[data-spt-concept-download]') return download;
            if (key === '[data-spt-concept-status]') return status;
            if (key === '[data-spt-official-upload-form]') return formUploadSection;
            return null;
        },
    };
    vm.runInNewContext(source, {
        document: {
            querySelector: () => processPanel,
            createElement: () => element(),
            body: { append() {} },
        },
        window: { addEventListener: (key, handler) => { events[key] = handler; }, setTimeout() {} },
        URL: { createObjectURL: () => 'blob:word', revokeObjectURL: (url) => revoked.push(url) },
        AbortController, fetch,
    });
    return { download, status, formUploadSection, revoked, events };
}

const wordResponse = () => ({
    ok: true, status: 200,
    headers: { get: (key) => key === 'content-type' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : '2' },
    blob: async () => ({ size: 10 }),
});

test('Word download updates status and triggers download', async () => {
    let resolve;
    let calls = 0;
    const state = setup(() => { calls++; return new Promise((done) => { resolve = done; }); });
    const event = { preventDefault() {} };
    const first = state.download.listeners.click(event);
    await state.download.listeners.click(event);
    assert.equal(calls, 1);
    resolve(wordResponse());
    await first;
    assert.match(state.status.textContent, /versi 2/);
    assert.equal(state.formUploadSection.classList.contains('d-none'), false);
    state.events.pagehide();
    assert.deepEqual(state.revoked, ['blob:word']);
});

test('Wrong response keeps status updated with error', async () => {
    const state = setup(async () => ({ ...wordResponse(), headers: { get: () => 'text/html' } }));
    await state.download.listeners.click({ preventDefault() {} });
    assert.match(state.status.textContent, /File Word belum dapat diunduh/);
});

test('Other roles do not initialize Officer handlers', () => {
    vm.runInNewContext(source, { document: { querySelector: () => null } });
});
