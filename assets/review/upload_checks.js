// The same limits and plain messages as the server's UploadPolicy and DirectUploads, so a refusal reads the same
// whether the browser or the server makes it. The server checks again; these only save a request.
export const ALLOWED_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

/** 15 MB, as UploadPolicy::MAX_BYTES. */
export const MAX_BYTES = 15 * 1024 * 1024;

export const WRONG_TYPE_MESSAGE = 'This file is not a PNG, JPEG or WebP screenshot.';
export const WRONG_SIZE_MESSAGE = 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.';

/** The plain refusal for a file the server would refuse by type or size, in the server's order, else null. */
export function refusalFor(file) {
  if (!ALLOWED_TYPES.includes(file.type)) {
    return WRONG_TYPE_MESSAGE;
  }
  if (file.size < 1 || file.size > MAX_BYTES) {
    return WRONG_SIZE_MESSAGE;
  }
  return null;
}

/** The first file of a clipboard or drop that is a picture, else the first file, else null. */
export function pickedFile(files) {
  const list = Array.from(files ?? []);
  return list.find((file) => file.type.startsWith('image/')) ?? list[0] ?? null;
}
