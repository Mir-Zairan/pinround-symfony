import { Controller } from '@hotwired/stimulus';
import { frameOfAnswer, swapFrameChildren } from '../frame_poll.js';
import { CLOUD_NEXT_LINE, FAILED_LINE, LaptopRunRefused, RUNNING_LINE, summarizeOnLaptop } from '../laptop_run.js';
import { NOT_FOUND_CHECK, readyModel, rememberLastCheck } from '../local_ai_storage.js';

const LEFT_PAGE = Symbol('left the page');

// On TheAsk's laptop part (ai-architecture.md, Laptop mode protocol): "Summarize with AI" with what the browser needs to
// run it on the laptop (laptop_run.js). The laptop counts as ready when the header's Local AI chip last found it ready
// in this tab (local_ai_storage.js); nothing here calls Ollama before the press. A not-ready line a ready laptop cures
// (notReadyLine) is hidden and the form shown while it is ready; without one, a not-ready laptop leaves the form to its
// normal cloud submit, and when Ollama does not answer a press there the next press goes to the cloud. A press on a
// ready laptop runs the summary there, then reads the ask's frame again to show the proposal.
// The AI never writes on its own: the result is only a proposal until a person presses "Use as main summary".
export default class extends Controller {
  static targets = ['form', 'button', 'notReadyLine', 'statusLine'];
  static values = { packageUrl: String, resultUrl: String, frameUrl: String, generateUrl: String, csrfHeader: String, token: String, ticketId: String };

  #run = null;
  #isReady = null;
  // Set when Ollama did not answer a press that the cloud may take: presses on this page go to the cloud, as the status
  // line says, until readiness changes or a new check finds the laptop ready, and clears that line.
  #isCloudNext = false;

  connect() {
    this.followCheck();
  }

  disconnect() {
    this.#run?.abort(LEFT_PAGE);
  }

  // On connect and on local-ai:checked. A line about the last press is old news once the readiness changes, and so is
  // one sending presses to the cloud once a new check (or a model picked in the sheet) finds the laptop ready again.
  followCheck(checkedEvent) {
    if (this.#run !== null) {
      return;
    }
    const isReady = readyModel() !== null;
    const isNewReadyCheck = isReady && checkedEvent !== undefined;
    if (isReady === this.#isReady && !isNewReadyCheck) {
      return;
    }
    this.#isReady = isReady;
    this.#isCloudNext = false;
    this.statusLineTarget.hidden = true;
    this.statusLineTarget.textContent = '';
    if (this.hasNotReadyLineTarget) {
      this.notReadyLineTarget.hidden = isReady;
      this.formTarget.hidden = !isReady;
    }
  }

  // On the form's submit, before Turbo's: only a ready laptop takes the press from the cloud.
  async summarize(event) {
    const model = this.#isCloudNext ? null : readyModel();
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
    let refusal = null;
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
      refusal = failure instanceof LaptopRunRefused ? failure : new LaptopRunRefused(FAILED_LINE);
    }
    if (run.signal.reason === LEFT_PAGE) {
      return;
    }
    this.#run = null;
    if (refusal === null) {
      await this.#showProposal(frame, hadFocus);
      return;
    }
    this.#showRefusal(refusal, readyLabel, frame, hadFocus);
  }

  // A check made during the press may have found the laptop not ready and hidden the form: the not-ready line then
  // says what to do, alone.
  #showRefusal(refusal, readyLabel, frame, hadFocus) {
    const line = this.#lineAfter(refusal);
    // Disabling the button dropped a focus that was in the ask to the page; one the person moved elsewhere stays there.
    const isFocusOurs = hadFocus && (document.activeElement === document.body || (frame?.contains(document.activeElement) ?? false));
    const isFormShown = !this.formTarget.hidden;
    this.#giveButtonBack(readyLabel, isFocusOurs && isFormShown);
    if (isFormShown) {
      this.#showLine(line);
    } else if (isFocusOurs) {
      this.notReadyLineTarget.tabIndex = -1;
      this.notReadyLineTarget.focus();
    }
  }

  // Where the cloud may take the press (no not-ready line), an Ollama that did not answer sends the next press there; one
  // that could not be reached also makes the laptop not found, while a slow one is still there and keeps its check.
  // Also catches up with a check made while the press ran.
  #lineAfter(refusal) {
    const mayFallBackToCloud = (refusal.isOllamaUnreachable || refusal.isOllamaTooSlow) && !this.hasNotReadyLineTarget;
    if (mayFallBackToCloud && refusal.isOllamaUnreachable) {
      rememberLastCheck(NOT_FOUND_CHECK);
    }
    this.followCheck();
    if (!mayFallBackToCloud) {
      return refusal.line;
    }
    this.#isCloudNext = true;
    return CLOUD_NEXT_LINE;
  }

  #showRunning() {
    const button = this.buttonTarget;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = button.dataset.turboSubmitsWith ?? button.textContent;
    this.#showLine(RUNNING_LINE);
  }

  #giveButtonBack(readyLabel, hadFocus) {
    const button = this.buttonTarget;
    button.disabled = false;
    button.removeAttribute('aria-busy');
    button.textContent = readyLabel;
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
