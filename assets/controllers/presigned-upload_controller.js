import { Controller } from '@hotwired/stimulus';
import { carriesFiles, pickedFile, refusalFor } from '../review/upload_checks.js';
import { beginUpload, completeUpload, putFile, UploadFailed } from '../review/upload_requests.js';
import { readUploadStatus, REJECTED_MESSAGE, sleep, statusUrlFor, waitForProcessing } from '../review/upload_status.js';

// Uploads one screenshot straight to storage (ADR-0011): the file is picked, dropped or pasted, checked for type and
// size, then the field's data-media-state becomes "uploading", presigned-upload:started fires (no detail) and POST
// /media/intents, a PUT to the presigned URL with the progress shown, and POST .../complete. The
// returned media id goes into the hidden `mediaId` field and into presigned-upload:uploaded ({ mediaId }), for the form
// that later posts it. Then GET the status URL once a second, at most 15 times: data-media-state is
// "pending" meanwhile, then "ready" with presigned-upload:ready ({ mediaId }), or on "rejected" the hidden field is
// emptied, the message shows and presigned-upload:rejected ({ mediaId }) fires (the state is then "rejected"). After
// 15 s still pending, presigned-upload:still-processing ({ mediaId }) fires and nothing is shown: the media id stays
// and the form that listens says so when the server refuses a submit. A status poll that fails or is refused (offline,
// 404, 429) shows nothing and counts as pending. Refusals and failures of the upload itself show the server's own
// message in the `status` live region, remove data-media-state and fire presigned-upload:failed (no detail). The clear()
// action drops the picture: it stops the upload or the polling, empties the field, data-media-state and the preview, and
// fires nothing. The markup and the values
// come from the UploadField component, or from ScreenshotReportField.
export default class extends Controller {
  static targets = ['input', 'status', 'progress', 'preview', 'mediaId'];
  static values = {
    url: String,
    csrfToken: String,
    purpose: { type: String, default: 'screenshot' },
    targetKind: String,
    targetId: String,
    statusUrl: String,
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
    if (carriesFiles(event.dataTransfer?.types)) {
      event.preventDefault();
    }
  }

  drop(event) {
    if (!carriesFiles(event.dataTransfer?.types)) {
      return;
    }
    event.preventDefault();
    this.upload(pickedFile(event.dataTransfer.files));
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
    this.abortController?.abort();
    const { signal } = (this.abortController = new AbortController());
    this.mediaIdTarget.value = '';
    this.element.dataset.mediaState = 'uploading';
    this.showPreview(file);
    this.showProgress(0);
    this.say(`Uploading ${file.name}…`);
    this.dispatch('started');
    try {
      const slot = await beginUpload(this.urlValue, this.csrfTokenValue, {
        purpose: this.purposeValue,
        file,
        target: { kind: this.targetKindValue, id: this.targetIdValue },
      }, signal);
      await putFile(slot.url, slot.headers, file, (fraction) => this.showProgress(fraction), signal);
      const { mediaId } = await completeUpload(this.urlValue, this.csrfTokenValue, slot.intentId, signal);
      this.mediaIdTarget.value = mediaId;
      this.element.dataset.mediaState = 'pending';
      this.say(`${file.name} uploaded.`);
      this.dispatch('uploaded', { detail: { mediaId } });
      this.isUploading = false;
      this.progressTarget.hidden = true;
      await this.awaitProcessing(mediaId, signal);
    } catch (failure) {
      if (signal.aborted) {
        return;
      }
      delete this.element.dataset.mediaState;
      this.clearPreview();
      this.say(failure instanceof UploadFailed ? failure.message : 'The upload failed. Try again.');
      this.dispatch('failed');
    } finally {
      // An aborted upload was replaced by a newer one, or the page is gone: the newer one owns these.
      if (!signal.aborted) {
        this.isUploading = false;
        this.progressTarget.hidden = true;
      }
    }
  }

  clear() {
    this.abortController?.abort();
    this.abortController = null;
    this.isUploading = false;
    this.mediaIdTarget.value = '';
    delete this.element.dataset.mediaState;
    this.progressTarget.hidden = true;
    this.clearPreview();
    this.say('');
  }

  async awaitProcessing(mediaId, signal) {
    const statusUrl = statusUrlFor(this.statusUrlValue, mediaId);
    const status = await waitForProcessing((pollSignal) => readUploadStatus(statusUrl, pollSignal), sleep, signal);
    if ('ready' === status) {
      this.element.dataset.mediaState = 'ready';
      this.dispatch('ready', { detail: { mediaId } });
    } else if ('rejected' === status) {
      this.element.dataset.mediaState = 'rejected';
      this.mediaIdTarget.value = '';
      this.clearPreview();
      this.say(REJECTED_MESSAGE);
      this.dispatch('rejected', { detail: { mediaId } });
    } else {
      this.dispatch('still-processing', { detail: { mediaId } });
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
