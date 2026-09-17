import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/spt-searchable-selects.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '');

function run(searchable, layout) {
    let captured = null;
    class HTMLSelectElement {}
    const select = Object.create(HTMLSelectElement.prototype);
    select.dataset = { sptSearchable: searchable, placeholder: 'Cari' };
    if (layout) select.dataset.sptLayout = layout;
    select.options = [];
    select.append = () => {};
    select.addEventListener = () => {};
    select.form = null;

    vm.runInNewContext(source, {
        document: { readyState: 'complete', querySelectorAll: () => [select] },
        Choices: function (element, options) { captured = options; },
        HTMLSelectElement,
    });

    return captured;
}

test('employee picker closes its dropdown once an employee is selected', () => {
    const options = run('employees');

    assert.equal(options.closeDropdownOnSelect, true);
});

test('destination picker keeps the shared dropdown behaviour', () => {
    const options = run('destination');

    assert.notEqual(options.closeDropdownOnSelect, true);
});
