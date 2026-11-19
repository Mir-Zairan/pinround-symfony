// What the popup shows of the captures saved for a new report (chrome.storage.session). `element` finds a popup element
// by its id.
import { MAX_SCREENSHOTS } from '../capture/capture.js';

export const NEW_REPORT = 'new-report';

// The list and what is visible for the chosen target. It never writes the project or report title inputs, so a
// switch of target keeps what the person typed and has not saved yet.
export function showCaptures(element, captures) {
    const isNewReport = NEW_REPORT === element('target').value;
    element('report-fields').hidden = !isNewReport;
    element('project').disabled = captures.items.length > 0;
    element('report-captures').hidden = !isNewReport || 0 === captures.items.length;
    element('captures-count').textContent = `${captures.items.length} of ${MAX_SCREENSHOTS} screenshots for this report.`;
    const list = element('captures-list');
    list.replaceChildren(...captures.items.map((item, index) => {
        const entry = list.ownerDocument.createElement('li');
        entry.textContent = item.title ?? `Screenshot ${index + 1}`;

        return entry;
    }));
}

// Overwrites the project and report title inputs with the saved ones: only when the popup opens, or once the saved
// captures were just written or cleared.
export function fillReportInputs(element, captures) {
    element('project').value = captures.project;
    element('report-title').value = captures.reportTitle;
}
