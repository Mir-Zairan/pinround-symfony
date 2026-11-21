import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

const PIN_SAVE_DELAY_MS = 600;
// Each extra wait is one more 15 s round of status polls by presigned-upload.
const MAX_EXTRA_PROCESSING_WAITS = 3;
const STILL_PROCESSING_MESSAGE = 'This picture is taking too long to process. Add it again in a moment.';

// The glue of the owner's draft round (DraftRoundEditor, a live component), on the same element as presigned-upload.
// It turns the upload's media id into the addScreenshot action, a change of a picture's title or summary into
// editScreenshotText, and the pin-placer's changes into setPins, sent once the person stops for a moment. Verdicts and
// Remove call their actions straight from the markup (live#action).
//
// When a Turbo page change takes the editor away, every waiting setPins is sent at once; a reload or a closed tab still
// loses the last PIN_SAVE_DELAY_MS of pin changes, as there is no beforeunload prompt. "Share with the team now" waits
// until the waiting and running saves are done, then goes on. "Delete draft" holds the waiting pins while it runs and
// drops them only once the draft is deleted; a refused delete sends them. A picture still processing keeps its media id
// and is added once it is ready (it is polled for up to MAX_EXTRA_PROCESSING_WAITS more rounds, then the person is told
// to add it again).
export default class extends Controller {
  static targets = ['file', 'refusal'];
  static outlets = ['presigned-upload'];

  connect() {
    this.waitingPins = new Map();
    this.runningSaves = new Set();
    this.extraWaits = 0;
    this.isHoldingShare = false;
    this.isLeavingForShare = false;
    this.liveComponent = null;
    this.card = this.element.closest('.round-card');
    this.onSubmit = (event) => this.holdShareUntilSaved(event);
    this.onSubmitStart = (event) => this.holdPinsWhileDeleting(event);
    this.onSubmitEnd = (event) => this.settlePinsAfterDelete(event);
    this.card?.addEventListener('submit', this.onSubmit);
    this.card?.addEventListener('turbo:submit-start', this.onSubmitStart);
    this.card?.addEventListener('turbo:submit-end', this.onSubmitEnd);
  }

  disconnect() {
    this.card?.removeEventListener('submit', this.onSubmit);
    this.card?.removeEventListener('turbo:submit-start', this.onSubmitStart);
    this.card?.removeEventListener('turbo:submit-end', this.onSubmitEnd);
    this.flushPins();
  }

  pickFile() {
    this.fileTarget.click();
  }

  resetWaiting() {
    this.extraWaits = 0;
  }

  // The picture is ready: add it, then empty the upload. preventDefault keeps the upload busy, so a live refresh of the
  // Rounds section waits until the picture is added; a refused add, whose reason the component shows, keeps it busy
  // until the person moves on, so the refresh does not wipe that reason.
  async addUploaded(event) {
    const upload = this.hasPresignedUploadOutlet ? this.presignedUploadOutlet : null;
    if (upload !== null) {
      event.preventDefault();
    }
    this.extraWaits = 0;
    try {
      const component = await this.component();
      await this.send(component, 'addScreenshot', { mediaId: event.detail.mediaId });
    } finally {
      if (this.hasRefusalTarget) {
        upload?.clearAfterFailure();
      } else {
        upload?.clear();
      }
    }
  }

  // Still processing after 15 s: keep the media id and poll again, a bounded number of times. The upload stays busy
  // meanwhile (preventDefault), as for addUploaded.
  keepWaiting(event) {
    if (!this.hasPresignedUploadOutlet) {
      return;
    }
    event.preventDefault();
    if (this.extraWaits >= MAX_EXTRA_PROCESSING_WAITS) {
      this.extraWaits = 0;
      this.presignedUploadOutlet.clearAfterFailure(STILL_PROCESSING_MESSAGE);
      return;
    }
    this.extraWaits += 1;
    this.presignedUploadOutlet.resumeProcessing();
  }

  async saveText(event) {
    const section = event.target.closest('[data-screenshot-id]');
    if (!section || !event.target.matches('[data-field]')) {
      return;
    }
    const component = await this.component();
    this.send(component, 'editScreenshotText', {
      screenshotId: section.dataset.screenshotId,
      title: section.querySelector('[data-field="title"]').value,
      summary: section.querySelector('[data-field="summary"]').value,
    });
  }

  schedulePinSave(event) {
    const section = event.target.closest('[data-screenshot-id]');
    if (!section) {
      return;
    }
    const screenshotId = section.dataset.screenshotId;
    const pins = event.detail.pins.map(({ x, y, note }) => ({ x, y, note }));
    clearTimeout(this.waitingPins.get(screenshotId)?.timer);
    this.waitingPins.set(screenshotId, {
      pins,
      timer: setTimeout(() => this.sendPins(screenshotId), PIN_SAVE_DELAY_MS),
    });
    // Taken now, because after a disconnect the element may no longer be inside the live component.
    this.component();
  }

  sendPins(screenshotId) {
    const waiting = this.waitingPins.get(screenshotId);
    if (!waiting) {
      return Promise.resolve();
    }
    clearTimeout(waiting.timer);
    this.waitingPins.delete(screenshotId);
    return this.component().then((component) => this.send(component, 'setPins', { screenshotId, pins: waiting.pins }));
  }

  flushPins() {
    return Promise.all([...this.waitingPins.keys()].map((screenshotId) => this.sendPins(screenshotId)));
  }

  // Share must not go before the last pin or text is saved, or the team would see an older draft.
  // A click while one Share is already held is swallowed, or each held click would post its own share.
  holdShareUntilSaved(event) {
    if (!event.submitter?.matches('.round-card__share') || this.isLeavingForShare) {
      return;
    }
    if (this.isHoldingShare) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    if (0 === this.waitingPins.size && 0 === this.runningSaves.size) {
      return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    const form = event.target;
    const submitter = event.submitter;
    this.isHoldingShare = true;
    submitter.setAttribute('aria-busy', 'true');
    this.flushPins()
      .then(() => Promise.allSettled([...this.runningSaves]))
      .then(() => {
        this.isLeavingForShare = true;
        form.requestSubmit(submitter);
        this.isLeavingForShare = false;
      })
      .finally(() => {
        this.isHoldingShare = false;
        submitter.removeAttribute('aria-busy');
      });
  }

  // turbo:submit-start comes after the browser's confirm, so a cancelled delete never gets here. The pins stay waiting,
  // unsent, until the delete's answer.
  holdPinsWhileDeleting(event) {
    if (!this.isDeleteForm(event.target)) {
      return;
    }
    this.waitingPins.forEach(({ timer }) => clearTimeout(timer));
  }

  // A refused delete (an expired form, a draft changed elsewhere) changed nothing, so its waiting pins are still saved.
  settlePinsAfterDelete(event) {
    if (!this.isDeleteForm(event.target)) {
      return;
    }
    if (!event.detail.success) {
      this.flushPins();
      return;
    }
    this.waitingPins.forEach(({ timer }) => clearTimeout(timer));
    this.waitingPins.clear();
  }

  isDeleteForm(target) {
    return target.matches('form') && null !== target.querySelector('.round-card__delete');
  }

  send(component, action, args) {
    const running = Promise.resolve(component.action(action, args)).catch(() => {});
    this.runningSaves.add(running);
    return running.finally(() => this.runningSaves.delete(running));
  }

  component() {
    this.liveComponent ??= Promise.resolve(getComponent(this.element.closest('[data-controller~="live"]')));
    return this.liveComponent;
  }
}
