// What the popup shows of the chosen target's own fields: the project, title and draft of a new report, and the title
// field of a new notebook. `element` finds a popup element by its id.
import { MAX_SCREENSHOTS } from '../capture/capture.js';
import { NEW_NOTEBOOK, NEW_REPORT } from '../capture/capture-target.js';

/**
 * Shows the fields of the picker's current choice. It never writes the project or report title inputs, so a switch
 * of target keeps what the person typed.
 *
 * @param {import('../capture/new-report.js').NewReport} newReport
 */
export function showTargetFields(element, newReport) {
    const choice = element('target').value;
    const isNewReport = NEW_REPORT === choice;
    const hasDraft = null !== newReport.draftId || null !== newReport.pendingCapture;
    element('new-notebook-fields').hidden = NEW_NOTEBOOK !== choice;
    element('report-fields').hidden = !isNewReport;
    element('project').disabled = hasDraft;
    element('report-title').disabled = hasDraft;
    element('report-draft').hidden = !isNewReport || !hasDraft;
    element('report-draft-count').textContent = `${newReport.screenshotCount} of ${MAX_SCREENSHOTS} screenshots in the report draft.`;
    element('send-again').hidden = null === newReport.pendingCapture;
}

/** Overwrites the project and report title inputs with the stored ones: when the popup opens or the report changed. */
export function fillReportInputs(element, newReport) {
    element('project').value = newReport.project;
    element('report-title').value = newReport.reportTitle;
}
