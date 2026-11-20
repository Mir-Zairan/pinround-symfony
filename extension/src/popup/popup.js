// The popup: choose once where captures go (a new report, my draft round on a ticket, a notebook), pin the page, then
// capture the visible tab with its pins into that target. Nothing becomes a ticket here: the person sends a report
// draft in pinround (ADR-0039).
import { PinroundApi } from '../shared/api.js';
import { browser } from '../shared/browser.js';
import { loadSettings } from '../shared/settings.js';
import { captureTexts } from '../capture/capture.js';
import { NEW_NOTEBOOK, NEW_REPORT, TargetGoneError, choiceValueOf, forgetCaptureTarget, loadCaptureTarget, notebookChoice, saveCaptureTarget, targetChoices } from '../capture/capture-target.js';
import { captureToTarget, newReportSavedMessage } from '../capture/capture-to-target.js';
import { OLD_CAPTURES_DROPPED, clearNewReport, dropOldReportCaptures, finishNewReport, loadNewReport, saveTypedReport, sendPendingCapture } from '../capture/new-report.js';
import { makeNotebook } from '../capture/notebook-capture.js';
import { activeTabId, closePinOverlay, openPinOverlay } from '../capture/page-pins.js';
import { fillReportInputs, showTargetFields } from './report-captures.js';

const element = (id) => document.getElementById(id);
const sessionStorage = browser.storage.session;
const NO_CHOICE = '';

/** The targets the picker offers, by option value; "A new notebook" and the empty choice are no target. */
const offeredTargets = new Map();

function showStatus(message, isError = false) {
    const status = element('status');
    status.textContent = message;
    status.classList.toggle('error', isError);
}

async function showFields() {
    showTargetFields(element, await loadNewReport(sessionStorage));
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

async function forgetGoneTarget(message) {
    await forgetCaptureTarget(sessionStorage);
    const picker = element('target');
    if (NEW_REPORT !== picker.value) {
        offeredTargets.delete(picker.value);
        picker.selectedOptions[0]?.remove();
    }
    picker.value = NO_CHOICE;
    await showFields();
    showStatus(message, true);
    element('target').focus();
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
    const message = await captureToTarget({ api, browser, storage: sessionStorage, tabId, target, typed, texts });
    element('shot-title').value = '';
    element('shot-summary').value = '';
    showStatus(message);
}

async function sendAgain(api) {
    showStatus('Sending…');
    const saved = await sendPendingCapture(sessionStorage, api);
    // The pins on the page may since be others than the ones sent, so they are left for the person to close.
    showStatus(`${newReportSavedMessage(saved.screenshotCount)} Press Escape on the page to drop its pins.`);
}

async function newNotebook(api) {
    const notebook = await makeNotebook(api, element('notebook-title').value);
    const choice = notebookChoice(notebook.notebookId, notebook.title);
    offeredTargets.set(choice.value, choice.target);
    element('target').add(new Option(choice.label, choice.value), element('target').querySelector(`option[value="${NEW_NOTEBOOK}"]`));
    element('target').value = choice.value;
    await saveCaptureTarget(sessionStorage, choice.target);
    element('notebook-title').value = '';
    await showFields();
    showStatus('Notebook made. Captures now go to it.');
}

async function finishReport(origin) {
    const isOpened = await finishNewReport(sessionStorage, browser, origin);
    fillReportInputs(element, await loadNewReport(sessionStorage));
    await showFields();
    showStatus(isOpened ? 'Report draft opened in pinround. Check it there and press Send.' : 'Your report draft is saved in pinround. Open it there, check it and press Send.');
}

async function startOver() {
    const hadDraft = null !== (await loadNewReport(sessionStorage)).draftId;
    await clearNewReport(sessionStorage);
    fillReportInputs(element, await loadNewReport(sessionStorage));
    await showFields();
    showStatus(hadDraft ? 'Started over. The report draft already made stays in pinround.' : 'Started over.');
}

async function run(button, work) {
    button.disabled = true;
    try {
        await work();
    } catch (error) {
        if (error instanceof TargetGoneError) {
            await forgetGoneTarget(error.message);
        } else {
            showStatus(error.message, true);
        }
    } finally {
        button.disabled = false;
        await showFields();
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
    const hadOldCaptures = await dropOldReportCaptures(sessionStorage);
    fillReportInputs(element, await loadNewReport(sessionStorage));
    const { choices, target, droppedMessage, loadProblems } = await targetChoices(api, await loadCaptureTarget(sessionStorage));
    fillPicker(choices, null === target ? NO_CHOICE : choiceValueOf(target));
    if (null !== droppedMessage) {
        await forgetCaptureTarget(sessionStorage);
    }
    await showFields();
    const problems = [droppedMessage, ...loadProblems, hadOldCaptures ? OLD_CAPTURES_DROPPED : null].filter((problem) => null !== problem);
    if (problems.length > 0) {
        showStatus(problems.join(' '), true);
    }

    element('target').addEventListener('change', async () => {
        const target = offeredTargets.get(element('target').value);
        if (undefined === target) {
            await forgetCaptureTarget(sessionStorage);
        } else {
            await saveCaptureTarget(sessionStorage, target);
        }
        await showFields();
    });
    for (const id of ['project', 'report-title']) {
        element(id).addEventListener('change', () => saveTypedReport(sessionStorage, { project: element('project').value, reportTitle: element('report-title').value }));
    }
    element('make-notebook').addEventListener('click', () => run(element('make-notebook'), () => newNotebook(api)));
    // Enter in the title makes the notebook, as the button beside it does, instead of submitting a capture.
    element('notebook-title').addEventListener('keydown', (event) => {
        if ('Enter' === event.key) {
            event.preventDefault();
            element('make-notebook').click();
        }
    });
    element('pin-page').addEventListener('click', () => run(element('pin-page'), pinPage));
    element('capture-form').addEventListener('submit', (event) => {
        event.preventDefault();
        run(element('capture'), () => capture(api));
    });
    element('send-again').addEventListener('click', () => run(element('send-again'), () => sendAgain(api)));
    element('finish-report').addEventListener('click', () => run(element('finish-report'), () => finishReport(settings.origin)));
    element('start-over').addEventListener('click', () => run(element('start-over'), startOver));
}

start();
