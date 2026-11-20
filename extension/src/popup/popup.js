// The popup: choose once where captures go (a new report, my draft round on a ticket, a notebook), pin the page, then
// capture the visible tab with its pins into that target. Nothing becomes a ticket here: the person sends a report
// draft in pinround (ADR-0039).
import { PinroundApi } from '../shared/api.js';
import { browser } from '../shared/browser.js';
import { loadSettings } from '../shared/settings.js';
import { captureTexts } from '../capture/capture.js';
import { NEW_NOTEBOOK, TargetGoneError, choiceValueOf, forgetCaptureTarget, loadCaptureTarget, notebookChoice, saveCaptureTarget, targetChoices } from '../capture/capture-target.js';
import { captureToTarget, newReportSavedMessage } from '../capture/capture-to-target.js';
import { OLD_CAPTURES_DROPPED, clearNewReport, dropOldReportCaptures, finishNewReport, loadNewReport, saveTypedReport, sendPendingCapture } from '../capture/new-report.js';
import { makeNotebook } from '../capture/notebook-capture.js';
import { activeTabId, closePinOverlay, openPinOverlay } from '../capture/page-pins.js';
import { fillReportInputs, showTargetFields } from './report-captures.js';
import { NO_CHOICE, forgetGoneTarget } from './target-picker.js';

const element = (id) => document.getElementById(id);
const extensionSession = browser.storage.session;

/** The targets the picker offers, by option value; "A new notebook" and the empty choice are no target. */
const offeredTargets = new Map();

function showStatus(message, isError = false) {
    const status = element('status');
    status.textContent = message;
    status.classList.toggle('error', isError);
}

async function showFields() {
    showTargetFields(element, await loadNewReport(extensionSession));
}

function fillPicker(choices, chosenValue) {
    offeredTargets.clear();
    const picker = element('target');
    const options = [new Option('Choose where to put it', NO_CHOICE)];
    for (const choice of choices) {
        offeredTargets.set(choice.value, choice.target);
        options.push(new Option(choice.label, choice.value));
    }
    options.push(new Option('A new notebook', NEW_NOTEBOOK));
    picker.replaceChildren(...options);
    picker.value = offeredTargets.has(chosenValue) ? chosenValue : NO_CHOICE;
}

/** The target to capture into, as the picker shows it; the picker is the person's choice, storage only remembers it. */
function chosenTarget() {
    const target = offeredTargets.get(element('target').value);
    if (undefined === target) {
        throw new Error(NEW_NOTEBOOK === element('target').value ? 'Make the notebook first.' : 'Choose where to put the screenshot first.');
    }

    return target;
}

async function pinPage() {
    await openPinOverlay(browser, await activeTabId(browser));
    showStatus('Click the page to drop a pin. Then capture the page here.');
}

async function capture(api) {
    const target = chosenTarget();
    const texts = captureTexts(element('shot-title').value, element('shot-summary').value);
    const tabId = await activeTabId(browser);
    showStatus('Uploading…');
    const typed = { project: element('project').value, reportTitle: element('report-title').value };
    const message = await captureToTarget({ api, browser, storage: extensionSession, tabId, target, typed, texts });
    element('shot-title').value = '';
    element('shot-summary').value = '';
    showStatus(message);
}

async function sendAgain(api) {
    showStatus('Sending…');
    const saved = await sendPendingCapture(extensionSession, api);
    // The pins on the page may since be others than the ones sent, so they are left for the person to close.
    showStatus(`${newReportSavedMessage(saved.screenshotCount)} Press Escape on the page to drop its pins.`);
}

async function newNotebook(api) {
    const notebook = await makeNotebook(api, element('notebook-title').value);
    const choice = notebookChoice(notebook.notebookId, notebook.title);
    offeredTargets.set(choice.value, choice.target);
    element('target').add(new Option(choice.label, choice.value), element('target').querySelector(`option[value="${NEW_NOTEBOOK}"]`));
    element('target').value = choice.value;
    await saveCaptureTarget(extensionSession, choice.target);
    element('notebook-title').value = '';
    await showFields();
    showStatus('Notebook made. Captures now go to it.');
}

async function finishReport(origin) {
    const isOpened = await finishNewReport(extensionSession, browser, origin);
    fillReportInputs(element, await loadNewReport(extensionSession));
    await showFields();
    showStatus(isOpened ? 'Report draft opened in pinround. Check it there and press Send.' : 'Your report draft is saved in pinround. Open it there, check it and press Send.');
}

async function startOver() {
    const hadDraft = null !== (await loadNewReport(extensionSession)).draftId;
    await clearNewReport(extensionSession);
    fillReportInputs(element, await loadNewReport(extensionSession));
    await showFields();
    showStatus(hadDraft ? 'Started over. The report draft already made stays in pinround.' : 'Started over.');
}

/**
 * Runs one press with the whole form disabled, so no other press (Start over, Done, another target) can act on what
 * this one is still changing.
 */
async function run(work) {
    const controls = [...element('capture-form').elements];
    // Disabling the pressed control drops its focus; it gets it back after, so a keyboard user stays in place.
    const focusedControl = document.activeElement;
    for (const control of controls) {
        control.disabled = true;
    }
    let isTargetGone = false;
    try {
        await work();
    } catch (error) {
        isTargetGone = error instanceof TargetGoneError;
        if (isTargetGone) {
            await forgetGoneTarget(extensionSession, element('target'), offeredTargets, error.target);
        }
        showStatus(error.message, true);
    } finally {
        for (const control of controls) {
            control.disabled = false;
        }
        await showFields();
    }
    if (isTargetGone) {
        element('target').focus();
    } else if (controls.includes(focusedControl) && !focusedControl.hidden) {
        focusedControl.focus();
    }
}

async function start() {
    const settings = await loadSettings();
    if (null === settings) {
        element('not-paired').hidden = false;
        element('open-options').addEventListener('click', () => browser.runtime.openOptionsPage());

        return;
    }
    const api = new PinroundApi(settings.origin, settings.token);
    element('capture-form').hidden = false;
    const hadOldCaptures = await dropOldReportCaptures(extensionSession);
    fillReportInputs(element, await loadNewReport(extensionSession));
    const { choices, target, droppedMessage, loadProblems } = await targetChoices(api, await loadCaptureTarget(extensionSession));
    fillPicker(choices, null === target ? NO_CHOICE : choiceValueOf(target));
    if (null !== droppedMessage) {
        await forgetCaptureTarget(extensionSession);
    }
    await showFields();
    const problems = [droppedMessage, ...loadProblems, hadOldCaptures ? OLD_CAPTURES_DROPPED : null].filter((problem) => null !== problem);
    if (problems.length > 0) {
        showStatus(problems.join(' '), true);
    }

    element('target').addEventListener('change', async () => {
        const target = offeredTargets.get(element('target').value);
        if (undefined === target) {
            await forgetCaptureTarget(extensionSession);
        } else {
            await saveCaptureTarget(extensionSession, target);
        }
        await showFields();
    });
    for (const id of ['project', 'report-title']) {
        element(id).addEventListener('change', () => saveTypedReport(extensionSession, { project: element('project').value, reportTitle: element('report-title').value }));
    }
    element('make-notebook').addEventListener('click', () => run(() => newNotebook(api)));
    // Enter in the title makes the notebook, as the button beside it does, instead of submitting a capture.
    element('notebook-title').addEventListener('keydown', (event) => {
        if ('Enter' === event.key) {
            event.preventDefault();
            element('make-notebook').click();
        }
    });
    element('pin-page').addEventListener('click', () => run(pinPage));
    element('capture-form').addEventListener('submit', (event) => {
        event.preventDefault();
        run(() => capture(api));
    });
    element('send-again').addEventListener('click', () => run(() => sendAgain(api)));
    element('finish-report').addEventListener('click', () => run(() => finishReport(settings.origin)));
    element('start-over').addEventListener('click', () => run(startOver));
}

start();
