import assert from 'node:assert/strict';
import { test } from 'node:test';
import { NEW_NOTEBOOK, NEW_REPORT } from '../src/capture/capture-target.js';
import { emptyNewReport } from '../src/capture/new-report.js';
import { fillReportInputs, showTargetFields } from '../src/popup/report-captures.js';

// The popup elements showTargetFields and fillReportInputs touch, as plain objects with the DOM properties they use.
function popupOf(targetValue) {
    const elements = {
        target: { value: targetValue },
        'new-notebook-fields': { hidden: true },
        'report-fields': { hidden: false },
        project: { value: '', disabled: false },
        'report-title': { value: '', disabled: false },
        'report-draft': { hidden: false },
        'report-draft-count': { textContent: '' },
        'send-again': { hidden: false },
    };

    return (id) => elements[id] ?? assert.fail(`The popup has no element ${id}.`);
}

const withDraft = { ...emptyNewReport(), project: 'SHOP', reportTitle: 'Total is doubled on the cart', draftId: 'd1', draftUrl: 'https://pinround.acme-foods.example/report-drafts/d1', screenshotCount: 2 };

test('switching the target keeps the project and report title the person typed and has not saved', () => {
    const element = popupOf(NEW_REPORT);
    element('project').value = 'MOBILE';
    element('report-title').value = 'Typed, not saved yet';

    element('target').value = 'ticket:SHOP-12';
    showTargetFields(element, emptyNewReport());
    element('target').value = NEW_REPORT;
    showTargetFields(element, emptyNewReport());

    assert.equal(element('project').value, 'MOBILE');
    assert.equal(element('report-title').value, 'Typed, not saved yet');
});

test('a made draft shows its count with Done and locks its project and title; other targets hide it', () => {
    const element = popupOf(NEW_REPORT);
    showTargetFields(element, withDraft);
    assert.deepEqual([element('report-fields').hidden, element('report-draft').hidden, element('project').disabled, element('report-title').disabled, element('send-again').hidden], [false, false, true, true, true]);
    assert.equal(element('report-draft-count').textContent, '2 of 10 screenshots in the report draft.');

    element('target').value = 'notebook:nb1';
    showTargetFields(element, withDraft);
    assert.deepEqual([element('report-fields').hidden, element('report-draft').hidden, element('new-notebook-fields').hidden], [true, true, true]);
});

test('with no draft the project stays editable and Done is hidden; a capture waiting to be sent shows Send it again', () => {
    const element = popupOf(NEW_REPORT);
    showTargetFields(element, emptyNewReport());
    assert.deepEqual([element('project').disabled, element('report-draft').hidden], [false, true]);

    showTargetFields(element, { ...emptyNewReport(), pendingCapture: { idempotencyKey: 'k1', path: '/api/v1/reports', bodyJson: '{}' } });
    assert.deepEqual([element('project').disabled, element('report-draft').hidden, element('send-again').hidden], [true, false, false]);
});

test('"A new notebook" shows its title field only', () => {
    const element = popupOf(NEW_NOTEBOOK);
    showTargetFields(element, emptyNewReport());
    assert.deepEqual([element('new-notebook-fields').hidden, element('report-fields').hidden], [false, true]);
});

test('filling the inputs writes the stored project and report title over what is there', () => {
    const element = popupOf(NEW_REPORT);
    element('project').value = 'MOBILE';
    element('report-title').value = 'Typed, not saved yet';

    fillReportInputs(element, withDraft);

    assert.deepEqual([element('project').value, element('report-title').value], ['SHOP', 'Total is doubled on the cart']);
});
