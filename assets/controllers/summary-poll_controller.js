import { Controller } from '@hotwired/stimulus';
import { sleep } from '../review/upload_status.js';
import { showToast } from '../toast_region.js';

/** ticket-page.md: while a summary request runs, The ask asks again every 3 s. */
export const POLL_INTERVAL_MS = 3000;
/** 3 minutes of polls; after the last one the button is offered again, and a press while the request still runs gets its 409 frame, which polls anew. */
export const MAX_POLLS = 60;

const RUNNING = 'running';
const DONE = 'done';

// On .ticket__ask-state inside TheAsk's turbo-frame, whose children Turbo and this controller replace, so each state
// connects a new instance. While the state is "running" it fetches SummaryController's frame every POLL_INTERVAL_MS, at
// most MAX_POLLS times per connect, and swaps the frame's children in only when the answer's state differs; it stops
// when it disconnects and while the page is hidden, and goes on with the polls left when the page shows again. A summary
// that arrives shows the toast "Summary saved for everyone." through the page's toast region, as _toast.html.twig does.
// After each poll's answer is handled it counts it in the frame's data-answered-polls, which outlives the swaps; for an
// answer that still says running, the next poll's timer is already set by then.
export default class extends Controller {
  static targets = ['button'];
  static values = { url: String, state: String, savedToast: String, readyLabel: String };

  #polling = null;
  #pollsLeft = MAX_POLLS;

  connect() {
    this.#pollsLeft = MAX_POLLS;
    this.#startPolling();
  }

  disconnect() {
    this.#stopPolling();
  }

  // On visibilitychange: a hidden tab sends no polls.
  followVisibility() {
    if (document.hidden) {
      this.#stopPolling();
    } else {
      this.#startPolling();
    }
  }

  // On turbo:submit-start: Turbo disables the button and writes its data-turbo-submits-with; this says it is busy.
  markBusy(event) {
    event.detail?.formSubmission?.submitter?.setAttribute('aria-busy', 'true');
  }

  // On turbo:submit-end: a press whose answer never came keeps the button, which is no longer busy.
  clearBusy(event) {
    event.detail?.formSubmission?.submitter?.removeAttribute('aria-busy');
  }

  #startPolling() {
    if (this.stateValue !== RUNNING || this.#polling !== null || document.hidden || this.#pollsLeft === 0) {
      return;
    }
    const polling = new AbortController();
    this.#polling = polling;
    this.#pollUntilTheStateChanges(polling.signal)
      .catch(() => undefined)
      .finally(() => {
        if (this.#polling === polling) {
          this.#polling = null;
        }
      });
  }

  #stopPolling() {
    this.#polling?.abort();
    this.#polling = null;
  }

  // Rejects when the signal aborts, which ends the loop at once.
  async #pollUntilTheStateChanges(signal) {
    while (this.#pollsLeft > 0) {
      await sleep(POLL_INTERVAL_MS, signal);
      this.#pollsLeft -= 1;
      const freshFrame = await this.#fetchFrame(signal);
      const freshState = freshFrame?.querySelector('[data-summary-poll-state-value]')?.getAttribute('data-summary-poll-state-value');
      if (freshState && freshState !== RUNNING) {
        this.#show(freshFrame, freshState);
        return;
      }
      this.#countAnsweredPoll(this.element.closest('turbo-frame'));
    }
    this.#offerTheButtonAgain();
  }

  // Null for a failed read (offline, a refused poll, an answer without the frame): the next poll tries again.
  async #fetchFrame(signal) {
    const frame = this.element.closest('turbo-frame');
    if (frame === null) {
      return null;
    }
    try {
      const response = await fetch(this.urlValue, {
        headers: { Accept: 'text/html', 'Turbo-Frame': frame.id },
        cache: 'no-store',
        signal,
      });
      if (!response.ok) {
        return null;
      }
      const answer = new DOMParser().parseFromString(await response.text(), 'text/html');
      return answer.getElementById(frame.id);
    } catch (failure) {
      if (signal.aborted) {
        throw failure;
      }
      return null;
    }
  }

  // Replacing the frame's children disconnects this controller, so the toast goes first. The removed children may hold
  // the focus, which would fall to the top of the page: it moves to the new state's focus target, its [autofocus], but
  // only when it was inside the frame. That attribute comes off before the swap, so the browser never focuses it itself.
  #show(freshFrame, freshState) {
    const frame = this.element.closest('turbo-frame');
    if (freshState === DONE) {
      showToast(this.savedToastValue);
    }
    const hadFocus = frame.contains(document.activeElement);
    const focusTarget = freshFrame.querySelector('[autofocus]');
    focusTarget?.removeAttribute('autofocus');
    frame.replaceChildren(...freshFrame.childNodes);
    if (hadFocus) {
      focusTarget?.focus();
    }
    this.#countAnsweredPoll(frame);
  }

  #countAnsweredPoll(frame) {
    if (frame === null) {
      return;
    }
    frame.dataset.answeredPolls = String(Number(frame.dataset.answeredPolls ?? '0') + 1);
  }

  #offerTheButtonAgain() {
    if (!this.hasButtonTarget) {
      return;
    }
    const button = this.buttonTarget;
    button.disabled = false;
    button.removeAttribute('aria-busy');
    button.textContent = this.readyLabelValue;
  }
}
