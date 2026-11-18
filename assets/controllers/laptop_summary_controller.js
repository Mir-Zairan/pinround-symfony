import { Controller } from '@hotwired/stimulus';
import { frameOfAnswer, swapFrameChildren } from '../frame_poll.js';
import { readyModel } from '../local_ai_storage.js';

// A CPU-only laptop may take a minute or two for a summary; this stays under the package's 5 minutes.
const GENERATE_TIMEOUT_MS = 180_000;
// Thinking models may put their reasoning before the answer; an unclosed tag means the answer was cut inside it.
const THINKING = /<think>[\s\S]*?(?:<\/think>|$)/g;
const LEFT_PAGE = Symbol('left the page');

const RUNNING_LINE = 'Running on this laptop. Ticket text stays on this laptop.';
const UNREACHABLE_LINE = 'pinround could not reach Ollama on this laptop. Use “Local AI” at the top to set it up.';
const TOO_SLOW_LINE = 'Ollama on this laptop took too long to answer. Try again, or use “Local AI” at the top to pick another model.';
const NO_SUMMARY_LINE = 'Ollama on this laptop gave no summary. Use “Local AI” at the top to check it.';
const FAILED_LINE = 'The AI answer failed. Try again.';

class LaptopRunRefused extends Error {
  constructor(line) {
    super(line);
    this.line = line;
  }
}

// On TheAsk's laptop part (ai-architecture.md, Laptop mode protocol): "Summarize with AI" with what the browser needs to
// run it on the laptop. The laptop counts as ready when the header's Local AI chip last found it ready in this tab
// (local_ai_storage.js); nothing here calls Ollama before the press. A not-ready line a ready laptop cures
// (notReadyLine) is hidden and the form shown while it is ready; without one, a not-ready laptop leaves the form to its
// normal cloud submit. A press on a ready laptop asks pinround for the prompt package, Ollama for the text, and pinround
// to keep that text as a proposal, then reads the ask's frame again to show it. The AI never writes on its own: the
// result is only a proposal until a person presses "Use as main summary". Neither the ticket text nor the answer is
// logged.
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
      await this.#summarizeOnLaptop(model, run.signal);
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

  async #summarizeOnLaptop(model, signal) {
    const promptPackage = await this.#postToPinround(this.packageUrlValue, { feature: 'summary', ticketId: this.ticketIdValue }, 200, signal);
    if (typeof promptPackage?.packageId !== 'string' || typeof promptPackage.instructions !== 'string' || typeof promptPackage.prompt !== 'string') {
      throw new LaptopRunRefused(FAILED_LINE);
    }
    const text = await this.#generate(model, promptPackage, signal);
    await this.#postToPinround(this.resultUrlValue, { packageId: promptPackage.packageId, model, text }, 201, signal);
  }

  // The answer's JSON; a refusal throws with the sentence pinround answered, any other failure with FAILED_LINE.
  async #postToPinround(url, body, expectedStatus, signal) {
    let response;
    try {
      response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', [this.csrfHeaderValue]: this.tokenValue },
        body: JSON.stringify(body),
        signal,
      });
    } catch (failure) {
      throw signal.aborted ? failure : new LaptopRunRefused(FAILED_LINE);
    }
    const answer = await response.json().catch(() => null);
    if (response.status !== expectedStatus) {
      throw new LaptopRunRefused(typeof answer?.message === 'string' && answer.message !== '' ? answer.message : FAILED_LINE);
    }
    return answer;
  }

  // Ollama's text without its thinking. The request carries no cookie and no referrer: Ollama needs neither.
  async #generate(model, promptPackage, signal) {
    const timeout = AbortSignal.timeout(GENERATE_TIMEOUT_MS);
    const request = { model, system: promptPackage.instructions, prompt: promptPackage.prompt, stream: false, think: false };
    if (typeof promptPackage.schema === 'string') {
      request.format = JSON.parse(promptPackage.schema);
    }
    const unansweredLine = () => (timeout.aborted ? TOO_SLOW_LINE : UNREACHABLE_LINE);
    let response;
    try {
      response = await fetch(this.generateUrlValue, {
        method: 'POST',
        body: JSON.stringify(request),
        signal: AbortSignal.any([signal, timeout]),
        credentials: 'omit',
        cache: 'no-store',
        referrerPolicy: 'no-referrer',
      });
    } catch (failure) {
      throw signal.aborted ? failure : new LaptopRunRefused(unansweredLine());
    }
    if (!response.ok) {
      throw new LaptopRunRefused(NO_SUMMARY_LINE);
    }
    let answer;
    try {
      answer = await response.json();
    } catch (failure) {
      if (signal.aborted) {
        throw failure;
      }
      throw new LaptopRunRefused(timeout.aborted ? TOO_SLOW_LINE : NO_SUMMARY_LINE);
    }
    const text = typeof answer?.response === 'string' ? answer.response.replace(THINKING, '').trim() : '';
    if (text === '') {
      throw new LaptopRunRefused(NO_SUMMARY_LINE);
    }
    return text;
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
