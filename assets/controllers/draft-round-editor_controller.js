import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

const PIN_SAVE_DELAY_MS = 600;

// The glue of the owner's draft round (DraftRoundEditor, a live component), on the same element as presigned-upload.
// It turns the upload's media id into the addScreenshot action, a change of a picture's title or summary into
// editScreenshotText, and the pin-placer's changes into setPins, sent once the person stops for a moment. Verdicts and
// Remove call their actions straight from the markup (live#action). It keeps no state beyond the pending timers.
export default class extends Controller {
  static targets = ['file'];
  static outlets = ['presigned-upload'];

  connect() {
    this.pinTimers = new Map();
  }

  disconnect() {
    this.pinTimers.forEach((timer) => clearTimeout(timer));
    this.pinTimers.clear();
  }

  pickFile() {
    this.fileTarget.click();
  }

  // Also runs for a picture still processing after 15 s: the server then says so, in the card.
  async addUploaded(event) {
    const component = await this.component();
    await component.action('addScreenshot', { mediaId: event.detail.mediaId });
    if (this.hasPresignedUploadOutlet) {
      this.presignedUploadOutlet.clear();
    }
  }

  async saveText(event) {
    const section = event.target.closest('[data-screenshot-id]');
    if (!section || !event.target.matches('[data-field]')) {
      return;
    }
    const component = await this.component();
    component.action('editScreenshotText', {
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
    clearTimeout(this.pinTimers.get(screenshotId));
    this.pinTimers.set(
      screenshotId,
      setTimeout(async () => {
        this.pinTimers.delete(screenshotId);
        const component = await this.component();
        component.action('setPins', { screenshotId, pins });
      }, PIN_SAVE_DELAY_MS),
    );
  }

  component() {
    return getComponent(this.element.closest('[data-controller~="live"]'));
  }
}
