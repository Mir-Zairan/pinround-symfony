import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CAPTURE_TARGET_KEY, NEW_REPORT, loadCaptureTarget, notebookChoice, saveCaptureTarget, ticketChoice } from '../src/capture/capture-target.js';
import { NO_CHOICE, forgetGoneTarget } from '../src/popup/target-picker.js';
import { fakeBrowser } from './fake-browser.mjs';

const NEW_REPORT_CHOICE = { value: NEW_REPORT, label: 'A new report', target: { kind: NEW_REPORT } };
const SHOP_7 = ticketChoice('SHOP-7');
const SHOP_12 = ticketChoice('SHOP-12');
const CHECKOUT = notebookChoice('nb000000-0000-4000-8000-000000000001', 'Checkout');

/**
 * A <select> as the popup's picker uses it: removing the shown option shows the first one, and a value no option has
 * shows none (''), as HTMLSelectElement does.
 */
function pickerOf(choices, shownValue) {
    const options = [];
    let shown = null;
    const optionOf = (value) => ({ value, remove() { options.splice(options.indexOf(this), 1); if (shown === this) { shown = options[0] ?? null; } } });
    options.push(optionOf(NO_CHOICE), ...choices.map((choice) => optionOf(choice.value)));
    const picker = {
        get options() { return [...options]; },
        get value() { return shown?.value ?? ''; },
        set value(wanted) { shown = options.find((option) => option.value === wanted) ?? null; },
    };
    picker.value = shownValue;

    return picker;
}

const offeredOf = (choices) => new Map(choices.map((choice) => [choice.value, choice.target]));
const valuesOf = (picker) => picker.options.map((option) => option.value);

test('a gone ticket the picker still shows is taken out of it and out of storage, and the picker asks again', async () => {
    const storage = fakeBrowser().storage.session;
    const choices = [NEW_REPORT_CHOICE, SHOP_7, SHOP_12];
    const picker = pickerOf(choices, SHOP_7.value);
    const offeredTargets = offeredOf(choices);
    await saveCaptureTarget(storage, SHOP_7.target);

    await forgetGoneTarget(storage, picker, offeredTargets, SHOP_7.target);

    assert.equal(picker.value, NO_CHOICE);
    assert.deepEqual(valuesOf(picker), [NO_CHOICE, NEW_REPORT, SHOP_12.value]);
    assert.deepEqual([...offeredTargets.keys()], [NEW_REPORT, SHOP_12.value]);
    assert.deepEqual(await storage.get(CAPTURE_TARGET_KEY), {});
});

test('a gone notebook is taken out even when the person chose another target while the capture ran, and that choice stays', async () => {
    const storage = fakeBrowser().storage.session;
    const choices = [NEW_REPORT_CHOICE, SHOP_7, CHECKOUT];
    const picker = pickerOf(choices, SHOP_7.value);
    const offeredTargets = offeredOf(choices);
    await saveCaptureTarget(storage, SHOP_7.target);

    await forgetGoneTarget(storage, picker, offeredTargets, CHECKOUT.target);

    assert.equal(picker.value, SHOP_7.value);
    assert.deepEqual(valuesOf(picker), [NO_CHOICE, NEW_REPORT, SHOP_7.value]);
    assert.deepEqual([...offeredTargets.keys()], [NEW_REPORT, SHOP_7.value]);
    assert.deepEqual(await loadCaptureTarget(storage), SHOP_7.target);
});

test('a gone report draft keeps "A new report" offered, and resets the picker only while it shows it', async () => {
    for (const [shownValue, expectedValue] of [[NEW_REPORT, NO_CHOICE], [SHOP_12.value, SHOP_12.value]]) {
        const storage = fakeBrowser().storage.session;
        const choices = [NEW_REPORT_CHOICE, SHOP_12];
        const picker = pickerOf(choices, shownValue);
        await saveCaptureTarget(storage, choices.find((choice) => choice.value === shownValue).target);

        await forgetGoneTarget(storage, picker, offeredOf(choices), { kind: NEW_REPORT });

        assert.equal(picker.value, expectedValue, shownValue);
        assert.deepEqual(valuesOf(picker), [NO_CHOICE, NEW_REPORT, SHOP_12.value], shownValue);
        assert.equal(null === (await loadCaptureTarget(storage)), NEW_REPORT === shownValue, shownValue);
    }
});
