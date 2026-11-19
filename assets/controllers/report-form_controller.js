import { Controller } from '@hotwired/stimulus';

// On Report a problem's form (report.html.twig).
// Picking a team shows that team's "Give it to" and project chips and hides the others; the radios of a hidden group
// are disabled, so they are not posted. "Give it to" goes back to "The team decides" and the team's first project is
// picked, as report-a-problem.md asks.
// While the person types or picks, the form is posted to the preview url 400 ms after the last change, and the
// answer's duplicates box and "When you send:" words replace the shown ones. An answer that comes back after a newer
// change was made is dropped. The duplicates HTML is the server's own rendering of escaped titles.
// No call is made while the fields the answer depends on are as they were for the shown answer (the page's own at
// first) or for the call still running, so picking a type or a priority, or typing a picture's title, asks nothing.
const DEBOUNCE_MS = 400;
// What ReportController::liveParts reads, with every pins[n][note]: the notes and their count change the check reasons.
const PREVIEW_FIELDS = new Set(['title', 'description', 'team_id', 'project_id', 'assignee_id', 'screenshot_media_id']);
const PIN_NOTE_FIELD = /^pins\[\d+\]\[note\]$/;

export default class extends Controller {
  static targets = ['team', 'giveTo', 'projects', 'duplicates', 'preview', 'previewLine'];
  static values = { previewUrl: String };

  #timer = null;
  #request = null;
  #requestedFields = null;
  #answeredFields = null;

  connect() {
    // The server rendered the duplicates box and the "When you send:" line for the fields the page loaded with.
    this.#answeredFields = previewFieldsOf(new FormData(this.element));
  }

  disconnect() {
    clearTimeout(this.#timer);
    this.#request?.abort();
  }

  chooseTeam() {
    const chosen = this.teamTargets.find((radio) => radio.checked)?.value ?? '';
    for (const group of [...this.giveToTargets, ...this.projectsTargets]) {
      const isShown = group.dataset.team === chosen;
      group.hidden = !isShown;
      for (const radio of group.querySelectorAll('input[type="radio"]')) {
        radio.disabled = !isShown;
        radio.checked = false;
      }
      if (isShown) {
        const first = group.querySelector('input[type="radio"]');
        if (first !== null) {
          first.checked = true;
        }
      }
    }
  }

  schedulePreview() {
    clearTimeout(this.#timer);
    this.#timer = setTimeout(() => this.#preview(), DEBOUNCE_MS);
  }

  async #preview() {
    if (!this.hasPreviewUrlValue) {
      return;
    }
    const body = new FormData(this.element);
    const askedFields = previewFieldsOf(body);
    if (this.#request !== null && askedFields === this.#requestedFields) {
      return;
    }
    // A call still running was asked for other values; its answer would replace the one that holds again.
    this.#request?.abort();
    if (askedFields === this.#answeredFields) {
      this.#request = null;
      return;
    }
    const request = new AbortController();
    this.#request = request;
    this.#requestedFields = askedFields;
    try {
      const response = await fetch(this.previewUrlValue, {
        method: 'POST',
        body,
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: request.signal,
      });
      if (!response.ok || this.#request !== request) {
        return;
      }
      const answer = await response.json();
      if (this.hasDuplicatesTarget) {
        this.duplicatesTarget.innerHTML = typeof answer.duplicates === 'string' ? answer.duplicates : '';
      }
      if (this.hasPreviewTarget) {
        this.previewTarget.textContent = typeof answer.preview === 'string' ? answer.preview : '';
        this.previewLineTarget.hidden = this.previewTarget.textContent === '';
      }
      this.#answeredFields = askedFields;
    } catch {
      // An aborted or failed preview keeps the last words shown; the create works everything out again on the server.
    } finally {
      if (this.#request === request) {
        this.#request = null;
      }
    }
  }
}

function previewFieldsOf(formData) {
  const fields = [];
  for (const [name, value] of formData) {
    if (PREVIEW_FIELDS.has(name) || PIN_NOTE_FIELD.test(name)) {
      fields.push([name, typeof value === 'string' ? value : '']);
    }
  }
  return JSON.stringify(fields);
}
