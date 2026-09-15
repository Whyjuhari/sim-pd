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
    const form = element(['d-none']);
    const submit = element();
    form.querySelector = () => submit;
    form.checkValidity = () => true;
    const controls = element();
    const status = element();
    const selectors = {
        '[data-spt-concept-download]': download, '[data-spt-send-form]': form,
        '[data-spt-send-controls]': controls, '[data-spt-concept-status]': status,
    };
    const revoked = [];
    const events = {};
    vm.runInNewContext(source, {
        document: {
            querySelector: () => ({ querySelector: (key) => selectors[key] || null }),
            createElement: () => element(),
            body: { append() {} },
        },
        window: { addEventListener: (key, handler) => { events[key] = handler; }, setTimeout() {} },
        URL: { createObjectURL: () => 'blob:word', revokeObjectURL: (url) => revoked.push(url) },
        AbortController, fetch,
    });
    return { download, form, submit, controls, status, revoked, events };
}

const wordResponse = () => ({
    ok: true, status: 200,
    headers: { get: (key) => key === 'content-type' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : '2' },
    blob: async () => ({ size: 10 }),
});

test('Word download unlocks one explicit send confirmation and prevents concurrent downloads', async () => {
    let resolve;
    let calls = 0;
    const state = setup(() => { calls++; return new Promise((done) => { resolve = done; }); });
    const event = { preventDefault() {} };
    const first = state.download.listeners.click(event);
    await state.download.listeners.click(event);
    assert.equal(calls, 1);
    assert.equal(state.controls.disabled, true);
    assert.equal(state.form.classList.contains('d-none'), true);
    resolve(wordResponse());
    await first;
    assert.equal(state.controls.disabled, false);
    assert.equal(state.form.classList.contains('d-none'), false);
    assert.match(state.status.textContent, /versi 2/);
    assert.equal(state.submit.attrs.has('disabled'), false);
    state.events.pagehide();
    assert.deepEqual(state.revoked, ['blob:word']);
});

test('Wrong response keeps the send form unavailable', async () => {
    const state = setup(async () => ({ ...wordResponse(), headers: { get: () => 'text/html' } }));
    await state.download.listeners.click({ preventDefault() {} });
    assert.equal(state.controls.disabled, true);
    assert.equal(state.form.classList.contains('d-none'), true);
    assert.match(state.status.textContent, /belum dapat diunduh/);
});

test('Send form submits only once', () => {
    const state = setup(async () => wordResponse());
    let prevented = 0;
    state.form.listeners.submit({ preventDefault: () => prevented++ });
    state.form.listeners.submit({ preventDefault: () => prevented++ });
    assert.equal(prevented, 1);
    assert.equal(state.submit.attrs.has('disabled'), true);
});

test('Other roles do not initialize Officer handlers', () => {
    vm.runInNewContext(source, { document: { querySelector: () => null } });
});
