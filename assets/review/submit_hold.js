/** The data-media-state values presigned-upload writes while the picture is not yet usable: uploading, then processing. */
const WAITING_MEDIA_STATES = ['uploading', 'pending'];

/** What a report form says while it holds its submit; it fits both states, as it stays up when the upload turns to processing. */
export const WAITING_MESSAGE = 'Waiting for your screenshot to be ready…';

/**
 * Whether a report form holds its submit for its picture: while it uploads or processes, unless the wait is over
 * (presigned-upload:still-processing), after which the server answers a still-pending picture itself.
 */
export function isSubmitHeldFor(mediaState, isWaitOver) {
  return !isWaitOver && WAITING_MEDIA_STATES.includes(mediaState);
}
