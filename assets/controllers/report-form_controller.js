import { Controller } from '@hotwired/stimulus';

// On Report a problem's form (report.html.twig).
// Picking a team shows that team's "Give it to" and project chips and hides the others; the radios of a hidden group
// are disabled, so they are not posted. "Give it to" goes back to "The team decides" and the team's first project is
// picked, as report-a-problem.md asks.
// While the person types or picks, the form is posted to the preview url 400 ms after the last change, and the
// answer's duplicates box and "When you send:" words replace the shown ones. An answer that comes back after a newer
// change was made is dropped. The duplicates HTML is the server's own rendering of escaped titles.
const DEBOUNCE_MS = 400;

export default class extends Controller {
  static targets = ['team', 'giveTo', 'projects', 'duplicates', 'preview', 'previewLine'];
  static values = { previewUrl: String };

  #timer = null;
  #request = null;

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
    this.#request?.abort();
    const request = new AbortController();
    this.#request = request;
    try {
      const response = await fetch(this.previewUrlValue, {
        method: 'POST',
        body: new FormData(this.element),
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
    } catch {
      // An aborted or failed preview keeps the last words shown; the create works everything out again on the server.
    }
  }
}
