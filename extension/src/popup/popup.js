// The popup: pin the page, then capture the visible tab with its pins into a new report draft (several captures, then
// "Make the report draft") or into the person's own draft round on a ticket (one capture at a time). Nothing becomes a
// ticket here: the person sends a report draft in pinround (ADR-0039).
import { ApiError, PinroundApi } from '../shared/api.js';
import { browser } from '../shared/browser.js';
import { loadSettings } from '../shared/settings.js';
import { REPORT_TITLE_MAX_LENGTH, addToDraftRound, captureTexts, upload } from '../capture/capture.js';
import { addCaptureToNewReport, clearReportCaptures, loadReportCaptures, saveReportCaptures } from '../capture/new-report.js';
import { activeTabId, capturePinnedTab, closePinOverlay, openPinOverlay } from '../capture/page-pins.js';
import { NEW_REPORT, fillReportInputs, showCaptures } from './report-captures.js';

const element = (id) => document.getElementById(id);
const reportStorage = browser.storage.session;

function showStatus(message, isError = false) {
    const status = element('status');
    status.textContent = message;
    status.classList.toggle('error', isError);
}

function showSavedCaptures(captures) {
    fillReportInputs(element, captures);
    showCaptures(element, captures);
}

async function fillTargets(api) {
    const target = element('target');
    const newReport = new Option('A new report', NEW_REPORT);
    target.replaceChildren(newReport);
    try {
        const answer = await api.call('GET', '/api/v1/me/tickets');
        for (const ticket of answer.tickets ?? []) {
            target.add(new Option(`My draft round on ${ticket.key}`, ticket.key));
        }
    } catch (error) {
        showStatus(error instanceof ApiError ? error.message : 'Your tickets could not be loaded.', true);
    }
}

async function pinPage() {
    await openPinOverlay(browser, await activeTabId(browser));
    showStatus('Click the page to drop a pin. Then capture the page here.');
}

// The pins leave the page only once the screenshot is saved with them; a refused capture keeps them there.
async function capture(api) {
    const texts = captureTexts(element('shot-title').value, element('shot-summary').value);
    const tabId = await activeTabId(browser);
    const { file, pins } = await capturePinnedTab(browser, tabId);
    const targetValue = element('target').value;
    showStatus('Uploading…');
    if (NEW_REPORT !== targetValue) {
        const uploadId = await upload(api, { ticket: targetValue }, file);
        await addToDraftRound(api, targetValue, uploadId, { ...texts, pins });
        await closePinOverlay(browser, tabId);
        showStatus(`Added to your draft round on ${targetValue}. Only you see it.`);

        return;
    }
    const updated = await addCaptureToNewReport(reportStorage, api, { project: element('project').value, reportTitle: element('report-title').value }, { file, texts, pins });
    await closePinOverlay(browser, tabId);
    showSavedCaptures(updated);
    showStatus(`Screenshot ${updated.items.length} saved for the report. Capture more pages, or make the report draft.`);
}

async function makeDraft(api, origin) {
    const captures = await loadReportCaptures(reportStorage);
    const title = element('report-title').value.trim();
    if ('' === title || [...title].length > REPORT_TITLE_MAX_LENGTH) {
        throw new Error('Write what is wrong in one line, at most 200 characters.');
    }
    const answer = await api.call('POST', '/api/v1/reports', { project: captures.project, title, screenshots: captures.items }, captures.idempotencyKey);
    await clearReportCaptures(reportStorage);
    showSavedCaptures(await loadReportCaptures(reportStorage));
    if ('string' === typeof answer.url && new URL(answer.url).origin === origin) {
        await browser.tabs.create({ url: answer.url });
    }
    showStatus('Report draft made. Check it in pinround and press Send.');
}

async function run(button, work) {
    button.disabled = true;
    try {
        await work();
    } catch (error) {
        showStatus(error.message, true);
    } finally {
        button.disabled = false;
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
    await fillTargets(api);
    showSavedCaptures(await loadReportCaptures(reportStorage));

    element('target').addEventListener('change', async () => showCaptures(element, await loadReportCaptures(reportStorage)));
    element('report-title').addEventListener('change', async () => saveReportCaptures(reportStorage, { ...(await loadReportCaptures(reportStorage)), reportTitle: element('report-title').value }));
    element('pin-page').addEventListener('click', () => run(element('pin-page'), pinPage));
    element('capture-form').addEventListener('submit', (event) => {
        event.preventDefault();
        run(element('capture'), async () => {
            await capture(api);
            element('shot-title').value = '';
            element('shot-summary').value = '';
        });
    });
    element('make-draft').addEventListener('click', () => run(element('make-draft'), () => makeDraft(api, settings.origin)));
    element('clear-captures').addEventListener('click', async () => {
        await clearReportCaptures(reportStorage);
        showSavedCaptures(await loadReportCaptures(reportStorage));
        showStatus('Started over. The screenshots already uploaded are not in any report.');
    });
}

start();
