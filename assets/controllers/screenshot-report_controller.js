import { Controller } from '@hotwired/stimulus';
import { pinCountText } from '../review/pin_count.js';

const WAITING_MESSAGE = 'Waiting for your screenshot to finish processing…';

// The Screenshot section of a report form (ScreenshotReportField), on the same element as presigned-upload. It shows
// the picture area when an upload starts and hides it again, with every pin removed through the pin-placer outlet, when
// the upload fails, processing rejects it or Remove screenshot is clicked; a new upload also starts with no pins. It
// keeps the line under the pins in step with pin-placer:change. While the picture is pending it holds the form's submit
// and sends it once presigned-upload:ready fires; after presigned-upload:still-processing it lets the submit through,
// so the server refuses it with its own text.
export default class extends Controller {
  static targets = ['picture', 'empty', 'remove', 'count', 'frame', 'image', 'placeholder', 'file', 'status'];
  static outlets = ['pin-placer'];

  connect() {
    this.form = this.element.closest('form');
    this.isSubmitHeld = false;
    this.heldSubmitter = null;
    this.isWaitOver = false;
    this.form?.addEventListener('submit', this.holdWhilePending);
  }

  disconnect() {
    this.form?.removeEventListener('submit', this.holdWhilePending);
  }

  holdWhilePending = (event) => {
    if ('pending' !== this.element.dataset.mediaState || this.isWaitOver) {
      return;
    }
    event.preventDefault();
    this.isSubmitHeld = true;
    this.heldSubmitter = event.submitter ?? null;
    this.statusTarget.textContent = WAITING_MESSAGE;
  };

  showNewPicture() {
    this.releaseSubmit();
    this.isWaitOver = false;
    this.clearPins();
    this.forgetPagePicture();
    this.showPictureArea(true);
  }

  dropPicture() {
    this.releaseSubmit();
    this.clearPins();
    this.forgetPagePicture();
    this.showPictureArea(false);
  }

  removePicture() {
    this.dropPicture();
    this.fileTarget.focus();
  }

  sendHeldSubmit() {
    if (this.isSubmitHeld) {
      const submitter = this.heldSubmitter;
      this.releaseSubmit();
      this.form.requestSubmit(submitter);
    }
  }

  endWait() {
    this.isWaitOver = true;
    this.sendHeldSubmit();
  }

  countPins(event) {
    this.countTarget.textContent = pinCountText(event.detail.pins.length);
  }

  releaseSubmit() {
    if (this.isSubmitHeld) {
      this.statusTarget.textContent = '';
    }
    this.isSubmitHeld = false;
    this.heldSubmitter = null;
  }

  // The page may have come with a pending picture's placeholder, or a ready one's size, which would stretch a new one.
  forgetPagePicture() {
    this.placeholderTargets.forEach((placeholder) => placeholder.remove());
    this.frameTarget.classList.remove('screenshot__frame--placeholder');
    this.imageTarget.removeAttribute('width');
    this.imageTarget.removeAttribute('height');
  }

  clearPins() {
    if (this.hasPinPlacerOutlet) {
      this.pinPlacerOutlet.clear();
    }
  }

  showPictureArea(isShown) {
    this.pictureTarget.hidden = !isShown;
    this.removeTarget.hidden = !isShown;
    this.emptyTarget.hidden = isShown;
  }
}
