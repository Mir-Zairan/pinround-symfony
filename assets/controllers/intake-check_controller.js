import { Controller } from '@hotwired/stimulus';
import { FramePoll, frameOfAnswer, RUNNING, swapFrameChildren } from '../frame_poll.js';

const DONE = 'done';

/** The New ticket form's radio groups the AI suggests a value for; it never touches any other field (ADR-0020). */
const SUGGESTED_FIELDS = ['type', 'priority'];

/** On the form, the radio groups the person changed themselves, space-separated; it outlives the frame's swaps. */
const PERSON_PICKED_ATTRIBUTE = 'data-intake-check-person-picked';

const MARK_CLASS = 'intake-check__mark';

// On .intake-check__state inside IntakeCheck's turbo-frame in the New ticket form; each state Turbo or this controller
// swaps in connects a new instance. "Check with AI" posts the form's fields to the request url with the Turbo-Frame
// header and puts the answer's frame in, a refusal's frame too; the button shows only while the title is not empty.
// While the state is "running" its FramePoll fetches the check's frame until it is done or failed. A done state marks
// its suggested Type and Priority with ✦ and selects each, unless the person picked that group themselves.
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

    if (this.stateValue === DONE) {
      this.#markSuggestions();
    } else if (this.stateValue === RUNNING) {
      this.#clearMarks();
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
    if (freshFrame !== null) {
      swapFrameChildren(frame, freshFrame);
      return;
    }
    // No frame came (offline, an error page): the person keeps the button and reads why nothing changed.
    this.#showUnanswered();
    this.#offerTheButtonAgain();
  }

  #followTitle = () => {
    const isTitleEmpty = (this.#title?.value ?? '').trim() === '';
    for (const button of this.buttonTargets) {
      if (!button.disabled) {
        button.hidden = isTitleEmpty;
      }
    }
  };

  // Only the person's own choice counts: selecting a radio from script fires no change event.
  #notePersonPick = (event) => {
    const field = event.target;
    if (!event.isTrusted || !(field instanceof HTMLInputElement) || field.type !== 'radio' || !SUGGESTED_FIELDS.includes(field.name)) {
      return;
    }
    const picked = new Set(this.#personPickedFields());
    picked.add(field.name);
    this.#form.setAttribute(PERSON_PICKED_ATTRIBUTE, [...picked].join(' '));
  };

  #personPickedFields() {
    return (this.#form?.getAttribute(PERSON_PICKED_ATTRIBUTE) ?? '').split(' ').filter((name) => name !== '');
  }

  #markSuggestions() {
    this.#clearMarks();
    const suggestedValues = { type: this.typeValue, priority: this.priorityValue };
    const personPicked = this.#personPickedFields();
    for (const fieldName of SUGGESTED_FIELDS) {
      const suggestedRadio = this.#radiosOf(fieldName).find((radio) => radio.value === suggestedValues[fieldName]);
      if (suggestedRadio === undefined) {
        continue;
      }
      suggestedRadio.closest('label')?.append(this.#mark());
      if (!personPicked.includes(fieldName)) {
        suggestedRadio.checked = true;
      }
    }
  }

  #clearMarks() {
    for (const fieldName of SUGGESTED_FIELDS) {
      for (const radio of this.#radiosOf(fieldName)) {
        radio.closest('label')?.querySelector(`.${MARK_CLASS}`)?.remove();
      }
    }
  }

  #radiosOf(fieldName) {
    if (this.#form === null) {
      return [];
    }
    return [...this.#form.querySelectorAll('input[type="radio"]')].filter((radio) => radio.name === fieldName);
  }

  // "✦" after the label; screen readers hear the mark's label instead.
  #mark() {
    const mark = document.createElement('span');
    mark.className = MARK_CLASS;
    const sign = document.createElement('span');
    sign.setAttribute('aria-hidden', 'true');
    sign.textContent = '✦';
    const label = document.createElement('span');
    label.className = 'visually-hidden';
    label.textContent = this.markLabelValue;
    mark.append(' ', sign, label);
    return mark;
  }

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
