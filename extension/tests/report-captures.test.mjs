import assert from 'node:assert/strict';
import { test } from 'node:test';
import { NEW_REPORT, fillReportInputs, showCaptures } from '../src/popup/report-captures.js';

// The popup elements showCaptures and fillReportInputs touch, as plain objects with the DOM properties they use.
function popupOf(targetValue) {
    const ownerDocument = { createElement: (tag) => ({ tag, textContent: '' }) };
    const elements = {
        target: { value: targetValue },
        'report-fields': { hidden: false },
        project: { value: '', disabled: false },
        'report-title': { value: '' },
        'report-captures': { hidden: false },
        'captures-count': { textContent: '' },
        'captures-list': { ownerDocument, children: [], replaceChildren(...children) { this.children = children; } },
    };

    return (id) => elements[id] ?? assert.fail(`The popup has no element ${id}.`);
}

const saved = { project: 'SHOP', reportTitle: 'Total is doubled on the cart', items: [{ upload_id: 'u1', title: 'Cart' }, { upload_id: 'u2' }], idempotencyKey: 'k1' };

test('switching the target keeps the project and report title the person typed and has not saved', () => {
    const element = popupOf(NEW_REPORT);
    element('project').value = 'MOBILE';
    element('report-title').value = 'Typed, not saved yet';

    element('target').value = 'SHOP-12';
    showCaptures(element, saved);
    element('target').value = NEW_REPORT;
    showCaptures(element, saved);

    assert.equal(element('project').value, 'MOBILE');
    assert.equal(element('report-title').value, 'Typed, not saved yet');
});

test('the saved captures show as a list for a new report and hide for a draft round', () => {
    const element = popupOf(NEW_REPORT);
    showCaptures(element, saved);
    assert.deepEqual([element('report-fields').hidden, element('report-captures').hidden, element('project').disabled], [false, false, true]);
    assert.equal(element('captures-count').textContent, '2 of 10 screenshots for this report.');
    assert.deepEqual(element('captures-list').children.map((entry) => entry.textContent), ['Cart', 'Screenshot 2']);

    element('target').value = 'SHOP-12';
    showCaptures(element, saved);
    assert.deepEqual([element('report-fields').hidden, element('report-captures').hidden], [true, true]);
});

test('with nothing captured the project stays editable and the list is hidden', () => {
    const element = popupOf(NEW_REPORT);
    showCaptures(element, { project: '', reportTitle: '', items: [], idempotencyKey: 'k2' });
    assert.deepEqual([element('project').disabled, element('report-captures').hidden], [false, true]);
    assert.deepEqual(element('captures-list').children, []);
});

test('filling the inputs writes the saved project and report title over what is there', () => {
    const element = popupOf(NEW_REPORT);
    element('project').value = 'MOBILE';
    element('report-title').value = 'Typed, not saved yet';

    fillReportInputs(element, saved);

    assert.deepEqual([element('project').value, element('report-title').value], ['SHOP', 'Total is doubled on the cart']);
});
