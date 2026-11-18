import { sleep } from './review/upload_status.js';

/** ticket-page.md and report-a-problem.md: while an AI request runs, its frame asks again every 3 s. */
export const POLL_INTERVAL_MS = 3000;
/** 3 minutes of polls per connect; after the last one the controller offers its button again. */
export const MAX_POLLS = 60;

export const RUNNING = 'running';

/**
 * Polls the turbo-frame around a state element while that element's state attribute says "running": every
 * POLL_INTERVAL_MS it fetches the url with the Turbo-Frame header, at most MAX_POLLS times, and hands the fresh frame to
 * onAnswer once its state differs. A failed or refused poll counts as still running. stop() ends it at once (on
 * disconnect, or while the page is hidden); start() goes on with the polls left. After each poll's answer is handled it
 * counts it in the frame's data-answered-polls, which outlives the swaps; for an answer that still says running, the
 * next poll's timer is already set by then. After the last poll it calls onLastPoll.
 */
export class FramePoll {
  #element;
  #stateAttribute;
  #url;
  #onAnswer;
  #onLastPoll;
  #polling = null;
  #pollsLeft = MAX_POLLS;

  /**
   * @param {Element} element the state element inside the frame, whose children it is not
   * @param {{stateAttribute: string, url: string, onAnswer: (freshFrame: Element, freshState: string) => void, onLastPoll: () => void}} options
   */
  constructor(element, { stateAttribute, url, onAnswer, onLastPoll }) {
    this.#element = element;
    this.#stateAttribute = stateAttribute;
    this.#url = url;
    this.#onAnswer = onAnswer;
    this.#onLastPoll = onLastPoll;
  }

  start() {
    if (this.#element.getAttribute(this.#stateAttribute) !== RUNNING || this.#polling !== null || document.hidden || this.#pollsLeft === 0) {
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

  stop() {
    this.#polling?.abort();
    this.#polling = null;
  }

  // Rejects when the signal aborts, which ends the loop at once.
  async #pollUntilTheStateChanges(signal) {
    while (this.#pollsLeft > 0) {
      await sleep(POLL_INTERVAL_MS, signal);
      this.#pollsLeft -= 1;
      const frame = this.#element.closest('turbo-frame');
      const freshFrame = await this.#fetchFrame(frame, signal);
      const freshState = freshFrame?.querySelector(`[${this.#stateAttribute}]`)?.getAttribute(this.#stateAttribute);
      if (freshState && freshState !== RUNNING) {
        this.#onAnswer(freshFrame, freshState);
        countAnsweredPoll(frame);
        return;
      }
      countAnsweredPoll(frame);
    }
    this.#onLastPoll();
  }

  // Null for a failed read (offline, a refused poll, an answer without the frame): the next poll tries again.
  async #fetchFrame(frame, signal) {
    if (frame === null) {
      return null;
    }
    try {
      const response = await fetch(this.#url, {
        headers: { Accept: 'text/html', 'Turbo-Frame': frame.id },
        cache: 'no-store',
        signal,
      });
      if (!response.ok) {
        return null;
      }
      return await frameOfAnswer(response, frame.id);
    } catch (failure) {
      if (signal.aborted) {
        throw failure;
      }
      return null;
    }
  }
}

/** The turbo-frame of that id in an HTML answer; null when the answer has none, such as an error page. */
export async function frameOfAnswer(response, frameId) {
  const answer = new DOMParser().parseFromString(await response.text(), 'text/html');
  return answer.getElementById(frameId);
}

/**
 * Puts the fresh frame's children in the frame, as Turbo would. The removed children may hold the focus, which would
 * fall to the top of the page: it moves to the new state's focus target, its [autofocus], but only when it was inside
 * the frame. That attribute comes off before the swap, so the browser never focuses it itself.
 */
export function swapFrameChildren(frame, freshFrame) {
  const hadFocus = frame.contains(document.activeElement);
  const focusTarget = freshFrame.querySelector('[autofocus]');
  focusTarget?.removeAttribute('autofocus');
  frame.replaceChildren(...freshFrame.childNodes);
  if (hadFocus) {
    focusTarget?.focus();
  }
}

function countAnsweredPoll(frame) {
  if (frame === null) {
    return;
  }
  frame.dataset.answeredPolls = String(Number(frame.dataset.answeredPolls ?? '0') + 1);
}
