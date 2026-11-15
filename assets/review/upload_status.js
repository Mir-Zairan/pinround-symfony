/** The text of the Screenshot component's rejected picture; UploadStatusMessageTest keeps the two alike. */
export const REJECTED_MESSAGE = 'We could not process this image. Try again.';
export const STILL_PROCESSING_MESSAGE = 'Still processing your screenshot. Try again in a moment.';

export const POLL_INTERVAL_MS = 1000;
export const MAX_POLLS = 15;

/** The URL of GET /media/{id}/status, from the template the UploadField component sets. */
export function statusUrlFor(template, mediaId) {
  return template.replace('MEDIA_ID', encodeURIComponent(mediaId));
}

/**
 * GET the status of an uploaded picture; resolves with "pending", "ready" or "rejected". A failed read (offline, a
 * refused poll, an answer that is none of these) resolves "pending": the next poll tries again, and after the last one
 * the server decides on submit.
 */
export async function readUploadStatus(url, signal) {
  try {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, signal });
    if (!response.ok) {
      return 'pending';
    }
    const { status } = await response.json();
    return 'ready' === status || 'rejected' === status ? status : 'pending';
  } catch (failure) {
    if (signal?.aborted) {
      throw failure;
    }
    return 'pending';
  }
}

/**
 * Reads the status once per POLL_INTERVAL_MS, at most MAX_POLLS times, until it is "ready" or "rejected".
 * Resolves with that, or with "pending" after the last poll. `sleep(ms, signal)` rejects when the signal aborts,
 * which ends the wait with that rejection.
 */
export async function waitForProcessing(readStatus, sleep, signal) {
  for (let poll = 0; poll < MAX_POLLS; poll += 1) {
    await sleep(POLL_INTERVAL_MS, signal);
    const status = await readStatus(signal);
    if ('pending' !== status) {
      return status;
    }
  }
  return 'pending';
}

/** setTimeout that rejects as soon as the signal aborts, so a left page stops polling at once. */
export function sleep(milliseconds, signal) {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(new DOMException('Aborted', 'AbortError'));
      return;
    }
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort);
      resolve();
    }, milliseconds);
    const onAbort = () => {
      clearTimeout(timer);
      reject(new DOMException('Aborted', 'AbortError'));
    };
    signal?.addEventListener('abort', onAbort, { once: true });
  });
}
