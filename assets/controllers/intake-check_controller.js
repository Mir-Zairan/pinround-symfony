import { Controller } from '@hotwired/stimulus';
import { FramePoll, frameOfAnswer, RUNNING, swapFrameChildren } from '../frame_poll.js';
import { clearMarks, markSuggestions, notePersonPick } from '../intake_marks.js';

const DONE = 'done';

// On .intake-check__state inside IntakeCheck's turbo-frame in the New ticket form; each state Turbo or this controller
// swaps in connects a new instance. "Check with AI" posts the form's fields to the request url with the Turbo-Frame
// header and puts the answer's frame in, a refusal's frame too; the button shows only while the title is not empty.
// While the state is "running" its FramePoll fetches the check's frame until it is done or failed. A done state marks
// its suggested Type and Priority with ✦ and selects each, unless the person picked that group themselves; any other
// state clears the marks.
export default class extends Controller {
  static targets = ['button'];
  static values = {
    state: String,
    requestUrl: String,
    url: String,
    type: String,
    priority: String,
    readyLabel: String,
    runningLabel: String,
    markLabel: String,
    unansweredLine: String,
  };

  #poll = null;
  #pressing = null;
  #form = null;
  #title = null;

  connect() {
    this.#form = this.element.closest('form');
    const title = this.#form?.elements.namedItem('title');
    this.#title = title instanceof HTMLInputElement ? title : null;
    this.#title?.addEventListener('input', this.#followTitle);
    this.#form?.addEventListener('change', this.#notePersonPick);
    this.#followTitle();

    if (this.#form !== null) {
      if (this.stateValue === DONE) {
        markSuggestions(this.#form, { type: this.typeValue, priority: this.priorityValue }, this.markLabelValue);
      } else {
        clearMarks(this.#form);
      }
    }

    this.#poll = new FramePoll(this.element, {
      stateAttribute: 'data-intake-check-state-value',
      url: this.urlValue,
      onAnswer: (freshFrame) => swapFrameChildren(this.element.closest('turbo-frame'), freshFrame),
      onLastPoll: () => this.#offerTheButtonAgain(),
    });
    this.#poll.start();
  }

  disconnect() {
    this.#poll?.stop();
    this.#pressing?.abort();
    this.#title?.removeEventListener('input', this.#followTitle);
    this.#form?.removeEventListener('change', this.#notePersonPick);
  }

  // On visibilitychange: a hidden tab sends no polls.
  followVisibility() {
    if (document.hidden) {
      this.#poll?.stop();
    } else {
      this.#poll?.start();
    }
  }

  // A button, not a submit, so Enter in a field still makes the ticket; the server answers an empty title 422 too.
  async check(event) {
    const button = event.currentTarget;
    const frame = this.element.closest('turbo-frame');
    if (this.#form === null || frame === null || this.#pressing !== null) {
      return;
    }
    // Read before the button goes off: a disabled button loses the focus to the page.
    const hadFocus = frame.contains(document.activeElement);
    const pressing = new AbortController();
    this.#pressing = pressing;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = this.runningLabelValue;

    let freshFrame = null;
    try {
      const response = await fetch(this.requestUrlValue, {
        method: 'POST',
        body: new FormData(this.#form),
        headers: { Accept: 'text/html', 'Turbo-Frame': frame.id },
        cache: 'no-store',
        signal: pressing.signal,
      });
      freshFrame = await frameOfAnswer(response, frame.id);
    } catch {
      freshFrame = null;
    }
    if (pressing.signal.aborted) {
      return;
    }
    this.#pressing = null;
    // Only focus the disabled button dropped, never one the person put elsewhere while the press ran.
    const isFocusDropped = hadFocus && (document.activeElement === null || document.activeElement === document.body || frame.contains(document.activeElement));
    if (freshFrame !== null) {
      swapFrameChildren(frame, freshFrame, { hadFocus: isFocusDropped });
      return;
    }
    // No frame came (offline, an error page): the person keeps the button and reads why nothing changed.
    clearMarks(this.#form);
    this.#showUnanswered();
    this.#offerTheButtonAgain();
    if (isFocusDropped) {
      button.focus();
    }
  }

  #followTitle = () => {
    const isTitleEmpty = (this.#title?.value ?? '').trim() === '';
    for (const button of this.buttonTargets) {
      if (!button.disabled) {
        button.hidden = isTitleEmpty;
      }
    }
  };

  #notePersonPick = (event) => notePersonPick(this.#form, event);

  #showUnanswered() {
    let line = this.element.querySelector('.intake-check__unanswered');
    if (line === null) {
      line = document.createElement('p');
      line.className = 'intake-check__notice intake-check__unanswered';
      if (this.hasButtonTarget) {
        this.buttonTarget.before(line);
      } else {
        this.element.append(line);
      }
    }
    line.textContent = this.unansweredLineValue;
  }

  #offerTheButtonAgain() {
    for (const button of this.buttonTargets) {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.textContent = this.readyLabelValue;
    }
    this.#followTitle();
  }
}
