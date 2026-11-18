import { Controller } from '@hotwired/stimulus';
import { FramePoll, swapFrameChildren } from '../frame_poll.js';
import { showToast } from '../toast_region.js';

const DONE = 'done';

// On .ticket__ask-state inside TheAsk's turbo-frame, whose children Turbo and this controller replace, so each state
// connects a new instance. While the state is "running" its FramePoll fetches SummaryController's frame and swaps the
// frame's children in only when the answer's state differs; it stops when it disconnects and while the page is hidden,
// and goes on with the polls left when the page shows again. A summary that arrives shows the toast "Summary saved for
// everyone." through the page's toast region, as _toast.html.twig does. After the last poll the button is offered again,
// and a press while the request still runs gets its 409 frame, which polls anew.
export default class extends Controller {
  static targets = ['button'];
  static values = { url: String, state: String, savedToast: String, readyLabel: String };

  #poll = null;

  connect() {
    this.#poll = new FramePoll(this.element, {
      stateAttribute: 'data-summary-poll-state-value',
      url: this.urlValue,
      onAnswer: (freshFrame, freshState) => this.#show(freshFrame, freshState),
      onLastPoll: () => this.#offerTheButtonAgain(),
    });
    this.#poll.start();
  }

  disconnect() {
    this.#poll?.stop();
  }

  // On visibilitychange: a hidden tab sends no polls.
  followVisibility() {
    if (document.hidden) {
      this.#poll?.stop();
    } else {
      this.#poll?.start();
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

  // Replacing the frame's children disconnects this controller, so the toast goes first.
  #show(freshFrame, freshState) {
    if (freshState === DONE) {
      showToast(this.savedToastValue);
    }
    swapFrameChildren(this.element.closest('turbo-frame'), freshFrame);
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
