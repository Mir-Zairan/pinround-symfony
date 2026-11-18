import { Controller } from '@hotwired/stimulus';
import { frameOfAnswer, swapFrameChildren } from '../frame_poll.js';
import { FAILED_LINE, LaptopRunRefused, RUNNING_LINE, summarizeOnLaptop } from '../laptop_run.js';
import { readyModel } from '../local_ai_storage.js';

const LEFT_PAGE = Symbol('left the page');

// On TheAsk's laptop part (ai-architecture.md, Laptop mode protocol): "Summarize with AI" with what the browser needs to
// run it on the laptop (laptop_run.js). The laptop counts as ready when the header's Local AI chip last found it ready
// in this tab (local_ai_storage.js); nothing here calls Ollama before the press. A not-ready line a ready laptop cures
// (notReadyLine) is hidden and the form shown while it is ready; without one, a not-ready laptop leaves the form to its
// normal cloud submit. A press on a ready laptop runs the summary there, then reads the ask's frame again to show the
// proposal. The AI never writes on its own: the result is only a proposal until a person presses "Use as main summary".
export default class extends Controller {
  static targets = ['form', 'button', 'notReadyLine', 'statusLine'];
  static values = { packageUrl: String, resultUrl: String, frameUrl: String, generateUrl: String, csrfHeader: String, token: String, ticketId: String };

  #run = null;

  connect() {
    this.followCheck();
  }

  disconnect() {
    this.#run?.abort(LEFT_PAGE);
  }

  // On connect and on local-ai:checked.
  followCheck() {
    if (this.#run !== null || !this.hasNotReadyLineTarget) {
      return;
    }
    const isReady = readyModel() !== null;
    this.notReadyLineTarget.hidden = isReady;
    this.formTarget.hidden = !isReady;
  }

  // On the form's submit, before Turbo's: only a ready laptop takes the press from the cloud.
  async summarize(event) {
    const model = readyModel();
    if (model === null) {
      if (this.hasNotReadyLineTarget) {
        event.preventDefault();
        this.followCheck();
      }
      return;
    }
    event.preventDefault();
    if (this.#run !== null) {
      return;
    }
    const frame = this.element.closest('turbo-frame');
    const hadFocus = frame?.contains(document.activeElement) ?? false;
    const run = new AbortController();
    this.#run = run;
    const readyLabel = this.buttonTarget.textContent;
    this.#showRunning();
    let failureLine = null;
    try {
      await summarizeOnLaptop({
        packageUrl: this.packageUrlValue,
        resultUrl: this.resultUrlValue,
        generateUrl: this.generateUrlValue,
        csrfHeader: this.csrfHeaderValue,
        token: this.tokenValue,
        ticketId: this.ticketIdValue,
        model,
      }, run.signal);
    } catch (failure) {
      failureLine = failure instanceof LaptopRunRefused ? failure.line : FAILED_LINE;
    }
    if (run.signal.reason === LEFT_PAGE) {
      return;
    }
    this.#run = null;
    if (failureLine !== null) {
      this.#showFailure(failureLine, readyLabel, hadFocus);
      return;
    }
    await this.#showProposal(frame, hadFocus);
  }

  #showRunning() {
    const button = this.buttonTarget;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = button.dataset.turboSubmitsWith ?? button.textContent;
    this.#showLine(RUNNING_LINE);
  }

  #showFailure(line, readyLabel, hadFocus) {
    const button = this.buttonTarget;
    button.disabled = false;
    button.removeAttribute('aria-busy');
    button.textContent = readyLabel;
    this.#showLine(line);
    if (hadFocus) {
      button.focus();
    }
  }

  #showLine(line) {
    this.statusLineTarget.textContent = line;
    this.statusLineTarget.hidden = false;
  }

  // The frame answer carries the proposal; a page without the frame, or a failed read, reloads the page instead.
  async #showProposal(frame, hadFocus) {
    let freshFrame = null;
    try {
      const response = await fetch(this.frameUrlValue, { headers: { Accept: 'text/html', 'Turbo-Frame': frame?.id ?? '' }, cache: 'no-store' });
      freshFrame = response.ok && frame !== null ? await frameOfAnswer(response, frame.id) : null;
    } catch {
      freshFrame = null;
    }
    if (freshFrame === null) {
      window.location.reload();
      return;
    }
    swapFrameChildren(frame, freshFrame, { hadFocus });
  }
}
