// The popup: choose once where captures go (a new report, my draft round on a ticket, a notebook), pin the page, then
// capture the visible tab with its pins into that target. Nothing becomes a ticket here: the person sends a report
// draft in pinround (ADR-0039). A notebook target also shows its scenarios (scenario-panel.js), played from here: the
// run lives as long as the popup. A draft round target also offers a retake of its screenshots (retake-picker.js).
import { pinroundApiOf } from '../shared/oauth-connection.js';
import { browser } from '../shared/browser.js';
import { loadSettings } from '../shared/settings.js';
import { captureTexts } from '../capture/capture.js';
import { CHOOSE_TARGET_FIRST, NEW_NOTEBOOK, TargetGoneError, choiceValueOf, forgetCaptureTarget, loadCaptureTarget, notebookChoice, saveCaptureTarget, targetChoices } from '../capture/capture-target.js';
import { NEW_SCREENSHOT, captureToTarget, newReportSavedMessage } from '../capture/capture-to-target.js';
import { OLD_CAPTURES_DROPPED, REPORT_KEPT_IN_PINROUND, REPORT_OPENED, clearNewReport, dropOldReportCaptures, finishNewReport, loadNewReport, saveTypedReport, sendPendingCapture } from '../capture/new-report.js';
import { captureToNotebook, makeNotebook } from '../capture/notebook-capture.js';
import { activePageContext } from '../capture/page-context.js';
import { activeTabId, openPageDock, openPinOverlay } from '../capture/page-pins.js';
import { StepRefusal } from '../player/step-runner.js';
import { fillReportInputs, showTargetFields } from './report-captures.js';
import { RetakePicker } from './retake-picker.js';
import { ScenarioPanel } from './scenario-panel.js';
import { NO_CHOICE, forgetGoneTarget } from './target-picker.js';
import { focusFirstVisible } from './visible-focus.js';

const element = (id) => document.getElementById(id);
const extensionSession = browser.storage.session;

/** The targets the picker offers, by option value; "A new notebook" and the empty choice are no target. */
const offeredTargets = new Map();
/** Where focus goes when the pressed control is hidden by what the press changed. */
const FALLBACK_FOCUS_IDS = ['capture', 'target'];

/** @type {ScenarioPanel|null} */
let scenarioPanel = null;
/** @type {RetakePicker|null} */
let retakePicker = null;

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
        throw new Error(NEW_NOTEBOOK === element('target').value ? 'Make the notebook first.' : CHOOSE_TARGET_FIRST);
    }

    return target;
}

/** The scenarios of the picker's choice; any choice but a notebook hides them. */
function showScenariosOfPicker() {
    scenarioPanel.showFor(offeredTargets.get(element('target').value));
}

/** "Add it as" for the picker's choice; a list that could not load is said on the status line. */
async function showRetakePickerOf(keptValue = NEW_SCREENSHOT) {
    const loadProblem = await retakePicker.showFor(offeredTargets.get(element('target').value), keptValue);
    if (null !== loadProblem) {
        showStatus(loadProblem, true);
    }
}

/** Takes a target pinround says is gone out of the picker, and the scenarios out of sight when the picker showed it. */
async function forgetGoneTargetOfPicker(goneTarget) {
    const shownValue = element('target').value;
    await forgetGoneTarget(extensionSession, element('target'), offeredTargets, goneTarget);
    if (shownValue !== element('target').value) {
        showScenariosOfPicker();
        await showRetakePickerOf();
    }
}

/**
 * A scenario's capture step into the notebook the panel shows. A notebook that is gone leaves the picker as a capture
 * of the page would, and the step stops with pinround's plain message.
 */
async function captureStepToNotebook(api, notebook, tabId, texts) {
    try {
        await captureToNotebook(api, browser, notebook, tabId, texts);
    } catch (error) {
        if (!(error instanceof TargetGoneError)) {
            throw error;
        }
        await forgetGoneTargetOfPicker(error.target);
        showStatus(error.message, true);
        throw new StepRefusal(error.message);
    }
}

/**
 * Starts the capture's title and summary from the page the popup was opened on, for the person to edit. A field the
 * person already typed in is kept, unless `isAfterCapture`: the last capture took its text.
 */
async function fillShotTexts(isAfterCapture) {
    // Both texts are optional, so a tab that cannot be read leaves them empty rather than failing a capture that worked.
    const { title, summary } = await activePageContext(browser, navigator).catch(() => ({ title: '', summary: '' }));
    for (const [id, text] of [['shot-title', title], ['shot-summary', summary]]) {
        if (isAfterCapture || '' === element(id).value) {
            element(id).value = text;
        }
    }
}

async function pinPage() {
    await openPinOverlay(browser, await activeTabId(browser));
    showStatus('Click the page to drop a pin. Then capture the page here.');
}

async function openDock() {
    await openPageDock(browser, await activeTabId(browser));
    showStatus('The dock is on the page. Pin, capture and save your draft from there.');
}

async function capture(api) {
    const target = chosenTarget();
    const texts = captureTexts(element('shot-title').value, element('shot-summary').value);
    const tabId = await activeTabId(browser);
    const retakeOf = retakePicker.retakeOfFor(target);
    showStatus('Uploading…');
    const typed = { project: element('project').value, reportTitle: element('report-title').value };
    let message;
    try {
        message = await captureToTarget({ api, browser, storage: extensionSession, tabId, target, typed, texts, retakeOf });
    } catch (error) {
        // A refused retake may name a screenshot removed in pinround since the list loaded; a gone target is run()'s.
        if (null !== retakeOf && !(error instanceof TargetGoneError)) {
            await showRetakePickerOf(retakeOf);
        }
        throw error;
    }
    // The draft now has another screenshot or try; the next capture is a new screenshot unless the person picks again.
    if ('ticket' === target.kind) {
        await showRetakePickerOf();
    }
    showStatus(message);
    await fillShotTexts(true);
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
    showScenariosOfPicker();
    await showRetakePickerOf();
    showStatus('Notebook made. Captures now go to it.');
}

async function finishReport(origin) {
    const isOpened = await finishNewReport(extensionSession, browser, origin);
    fillReportInputs(element, await loadNewReport(extensionSession));
    await showFields();
    showStatus(isOpened ? REPORT_OPENED : REPORT_KEPT_IN_PINROUND);
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
            await forgetGoneTargetOfPicker(error.target);
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
    } else if (controls.includes(focusedControl)) {
        // Done, Start over and Send it again hide themselves once they worked, and the notebook title's button too.
        focusFirstVisible([focusedControl, ...FALLBACK_FOCUS_IDS.map(element)]);
    }
}

async function start() {
    const settings = await loadSettings();
    if (null === settings) {
        element('not-paired').hidden = false;
        element('open-options').addEventListener('click', () => browser.runtime.openOptionsPage());

        return;
    }
    const api = pinroundApiOf(settings, browser.storage.local);
    retakePicker = new RetakePicker(element, api);
    element('capture-form').hidden = false;
    scenarioPanel = new ScenarioPanel(element, showStatus, {
        api,
        browser,
        storage: browser.storage.local,
        pinroundOrigin: settings.origin,
        activeTabId: () => activeTabId(browser),
        captureToNotebook: (notebook, tabId, texts) => captureStepToNotebook(api, notebook, tabId, texts),
    }, FALLBACK_FOCUS_IDS);
    const hadOldCaptures = await dropOldReportCaptures(extensionSession);
    fillReportInputs(element, await loadNewReport(extensionSession));
    const { choices, target, droppedMessage, loadProblems } = await targetChoices(api, await loadCaptureTarget(extensionSession));
    fillPicker(choices, null === target ? NO_CHOICE : choiceValueOf(target));
    if (null !== droppedMessage) {
        await forgetCaptureTarget(extensionSession);
    }
    await showFields();
    showScenariosOfPicker();
    await showRetakePickerOf();
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
        showScenariosOfPicker();
        await showRetakePickerOf();
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
    element('open-dock').addEventListener('click', () => run(openDock));
    element('capture-form').addEventListener('submit', (event) => {
        event.preventDefault();
        run(() => capture(api));
    });
    element('send-again').addEventListener('click', () => run(() => sendAgain(api)));
    element('finish-report').addEventListener('click', () => run(() => finishReport(settings.origin)));
    element('start-over').addEventListener('click', () => run(startOver));
    await fillShotTexts(false);
}

start();
