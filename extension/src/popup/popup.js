// The popup: capture the visible tab into a new report draft (several captures, then "Make the report draft") or into
// the person's own draft round on a ticket (one capture at a time). Nothing becomes a ticket here: the person sends a
// report draft in pinround (ADR-0039).
import { ApiError, PinroundApi } from '../shared/api.js';
import { loadSettings } from '../shared/settings.js';
import { MAX_SCREENSHOTS, REPORT_TITLE_MAX_LENGTH, addToDraftRound, captureTexts, pngOf, upload } from '../capture/capture.js';
import { NEW_REPORT, fillReportInputs, showCaptures } from './report-captures.js';

const SESSION_KEY = 'pinroundReportCaptures';

const element = (id) => document.getElementById(id);

function showStatus(message, isError = false) {
    const status = element('status');
    status.textContent = message;
    status.classList.toggle('error', isError);
}

async function loadCaptures() {
    const stored = (await chrome.storage.session.get(SESSION_KEY))[SESSION_KEY];

    return stored ?? { project: '', reportTitle: '', items: [], idempotencyKey: crypto.randomUUID() };
}

async function saveCaptures(captures) {
    await chrome.storage.session.set({ [SESSION_KEY]: captures });
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

async function capture(api) {
    const texts = captureTexts(element('shot-title').value, element('shot-summary').value);
    const file = pngOf(await chrome.tabs.captureVisibleTab({ format: 'png' }));
    const targetValue = element('target').value;
    if (NEW_REPORT !== targetValue) {
        showStatus('Uploading…');
        const uploadId = await upload(api, { ticket: targetValue }, file);
        await addToDraftRound(api, targetValue, uploadId, texts);
        showStatus(`Added to your draft round on ${targetValue}. Only you see it.`);

        return;
    }
    const captures = await loadCaptures();
    const project = (captures.items.length > 0 ? captures.project : element('project').value).trim().toUpperCase();
    if ('' === project) {
        throw new Error('Type the project key, for example SHOP.');
    }
    if (captures.items.length >= MAX_SCREENSHOTS) {
        throw new Error('A report holds at most 10 screenshots. Make the report draft first.');
    }
    showStatus('Uploading…');
    const uploadId = await upload(api, { project }, file);
    const updated = { ...captures, project, reportTitle: element('report-title').value, items: [...captures.items, { upload_id: uploadId, ...texts }] };
    await saveCaptures(updated);
    showSavedCaptures(updated);
    showStatus(`Screenshot ${updated.items.length} saved for the report. Capture more pages, or make the report draft.`);
}

async function makeDraft(api, origin) {
    const captures = await loadCaptures();
    const title = element('report-title').value.trim();
    if ('' === title || [...title].length > REPORT_TITLE_MAX_LENGTH) {
        throw new Error('Write what is wrong in one line, at most 200 characters.');
    }
    const answer = await api.call('POST', '/api/v1/reports', { project: captures.project, title, screenshots: captures.items }, captures.idempotencyKey);
    await chrome.storage.session.remove(SESSION_KEY);
    showSavedCaptures(await loadCaptures());
    if ('string' === typeof answer.url && new URL(answer.url).origin === origin) {
        await chrome.tabs.create({ url: answer.url });
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
        element('open-options').addEventListener('click', () => chrome.runtime.openOptionsPage());

        return;
    }
    const api = new PinroundApi(settings.origin, settings.token);
    element('capture-form').hidden = false;
    await fillTargets(api);
    showSavedCaptures(await loadCaptures());

    element('target').addEventListener('change', async () => showCaptures(element, await loadCaptures()));
    element('report-title').addEventListener('change', async () => saveCaptures({ ...(await loadCaptures()), reportTitle: element('report-title').value }));
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
        await chrome.storage.session.remove(SESSION_KEY);
        showSavedCaptures(await loadCaptures());
        showStatus('Started over. The screenshots already uploaded are not in any report.');
    });
}

start();
