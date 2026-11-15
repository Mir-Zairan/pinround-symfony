import { Controller } from '@hotwired/stimulus';
import { pickedFile, refusalFor } from '../review/upload_checks.js';
import { beginUpload, completeUpload, putFile, UploadFailed } from '../review/upload_requests.js';

// Uploads one screenshot straight to storage (ADR-0011): the file is picked, dropped or pasted, checked for type and
// size, then POST /media/intents, a PUT to the presigned URL with the progress shown, and POST .../complete. The
// returned media id goes into the hidden `mediaId` field and into presigned-upload:uploaded ({ mediaId }), for the form
// that later posts it. Refusals show the server's own message in the `status` live region. The markup and the
// values come from the UploadField component.
export default class extends Controller {
  static targets = ['input', 'status', 'progress', 'preview', 'mediaId'];
  static values = {
    url: String,
    csrfToken: String,
    purpose: { type: String, default: 'screenshot' },
    targetKind: String,
    targetId: String,
  };

  connect() {
    this.isUploading = false;
    this.previewUrl = null;
    this.abortController = null;
  }

  disconnect() {
    this.abortController?.abort();
    cancelAnimationFrame(this.sayFrame);
    this.clearPreview();
  }

  choose() {
    this.upload(pickedFile(this.inputTarget.files));
    this.inputTarget.value = '';
  }

  paste(event) {
    const file = pickedFile(event.clipboardData?.files);
    if (file) {
      event.preventDefault();
      this.upload(file);
    }
  }

  dragOver(event) {
    event.preventDefault();
  }

  drop(event) {
    event.preventDefault();
    this.upload(pickedFile(event.dataTransfer?.files));
  }

  async upload(file) {
    if (!file || this.isUploading) {
      return;
    }
    const refusal = refusalFor(file);
    if (refusal) {
      this.say(refusal);
      return;
    }
    this.isUploading = true;
    const { signal } = (this.abortController = new AbortController());
    this.mediaIdTarget.value = '';
    this.showPreview(file);
    this.showProgress(0);
    this.say(`Uploading ${file.name}…`);
    try {
      const slot = await beginUpload(this.urlValue, this.csrfTokenValue, {
        purpose: this.purposeValue,
        file,
        target: { kind: this.targetKindValue, id: this.targetIdValue },
      }, signal);
      await putFile(slot.url, slot.headers, file, (fraction) => this.showProgress(fraction), signal);
      const { mediaId } = await completeUpload(this.urlValue, this.csrfTokenValue, slot.intentId, signal);
      this.mediaIdTarget.value = mediaId;
      this.say(`${file.name} uploaded.`);
      this.dispatch('uploaded', { detail: { mediaId } });
    } catch (failure) {
      if (signal.aborted) {
        return;
      }
      this.clearPreview();
      this.say(failure instanceof UploadFailed ? failure.message : 'The upload failed. Try again.');
    } finally {
      this.isUploading = false;
      if (!signal.aborted) {
        this.progressTarget.hidden = true;
      }
    }
  }

  // The text is cleared first and set on the next frame: a live region does not announce a message equal to the last.
  say(message) {
    this.statusTarget.textContent = '';
    cancelAnimationFrame(this.sayFrame);
    this.sayFrame = requestAnimationFrame(() => {
      this.statusTarget.textContent = message;
    });
  }

  showProgress(fraction) {
    this.progressTarget.hidden = false;
    this.progressTarget.value = Math.round(fraction * 100);
  }

  showPreview(file) {
    this.clearPreview();
    this.previewUrl = URL.createObjectURL(file);
    this.previewTarget.src = this.previewUrl;
    this.previewTarget.hidden = false;
  }

  clearPreview() {
    if (this.previewUrl) {
      URL.revokeObjectURL(this.previewUrl);
      this.previewUrl = null;
    }
    if (this.hasPreviewTarget) {
      this.previewTarget.removeAttribute('src');
      this.previewTarget.hidden = true;
    }
  }
}
