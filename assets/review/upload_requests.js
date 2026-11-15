export const CONNECTION_MESSAGE = 'The upload could not reach the server. Check your connection and try again.';
export const STORAGE_MESSAGE = 'The file could not be stored. Try again.';

/** A refusal or failure with the plain text to show a person. */
export class UploadFailed extends Error {}

async function postJson(url, csrfToken, body, signal) {
  let response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(body ?? {}),
      signal,
    });
  } catch {
    throw new UploadFailed(CONNECTION_MESSAGE);
  }
  const answer = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new UploadFailed(typeof answer.message === 'string' ? answer.message : CONNECTION_MESSAGE);
  }
  return answer;
}

/** POST /media/intents; resolves with {intentId, url, headers}. */
export function beginUpload(beginUrl, csrfToken, { purpose, file, target }, signal) {
  return postJson(beginUrl, csrfToken, { purpose, mime: file.type, bytes: file.size, target }, signal);
}

/** POST {beginUrl}/{id}/complete; resolves with {mediaId, status}. */
export function completeUpload(beginUrl, csrfToken, intentId, signal) {
  return postJson(`${beginUrl}/${encodeURIComponent(intentId)}/complete`, csrfToken, undefined, signal);
}

/**
 * PUTs the file to the presigned URL with the headers the server signed. XMLHttpRequest, because fetch cannot report
 * upload progress. Content-Length is left to the browser, which sets it from the body. Aborting the signal aborts the
 * request, which rejects like a lost connection.
 */
export function putFile(url, headers, file, onProgress, signal) {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open('PUT', url);
    for (const [name, value] of Object.entries(headers)) {
      if ('content-length' !== name.toLowerCase()) {
        request.setRequestHeader(name, value);
      }
    }
    request.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable) {
        onProgress(event.loaded / event.total);
      }
    });
    request.addEventListener('load', () => (request.status >= 200 && request.status < 300 ? resolve() : reject(new UploadFailed(STORAGE_MESSAGE))));
    request.addEventListener('error', () => reject(new UploadFailed(CONNECTION_MESSAGE)));
    request.addEventListener('abort', () => reject(new UploadFailed(CONNECTION_MESSAGE)));
    signal?.addEventListener('abort', () => request.abort(), { once: true });
    request.send(file);
  });
}
