// What the header's Local AI chip keeps in the browser, shared with the controllers that run AI on the laptop.

// Ollama's answer comes from whatever listens on the laptop's port, so it is untrusted: only names of this shape are
// shown or sent, and only as text. LaptopController::MODEL_NAME_PATTERN checks the same on the server.
export const MODEL_NAME = /^[a-z0-9:._-]{1,64}$/;
// The choice of model outlives the page; a browser that refuses storage just picks again on the next check.
export const CHOSEN_MODEL_KEY = 'pinround.localAiModel';
// Every page renders a new header, so the last check's result is kept for the tab and shown again without calling
// Ollama; a browser that refuses storage shows "not checked" instead.
export const LAST_CHECK_KEY = 'pinround.localAiLastCheck';
// The local-ai controller's own event name, so TheAsk's local-ai:checked@window action hears both writers.
const CHECKED_EVENT = 'local-ai:checked';

/**
 * The chip's last check in this tab, as {state, model, models}. Null when there is none, storage is off, or what is
 * stored is not a result a check gives.
 */
export function readLastCheck() {
  let stored;
  try {
    stored = JSON.parse(sessionStorage.getItem(LAST_CHECK_KEY) ?? 'null');
  } catch {
    return null;
  }
  const models = stored?.models;
  if (!Array.isArray(models) || !models.every((name) => typeof name === 'string' && MODEL_NAME.test(name))) {
    return null;
  }
  if (stored.state === 'ready') {
    const isKnownModel = typeof stored.model === 'string' && MODEL_NAME.test(stored.model);
    return isKnownModel && (models.length === 0 || models.includes(stored.model)) ? { state: 'ready', model: stored.model, models } : null;
  }
  if (stored.state === 'not-found' || stored.state === 'no-model') {
    return { state: stored.state, model: null, models: [] };
  }
  return null;
}

/** The model the chip's last check in this tab found ready, or null when the laptop is not known to be ready. */
export function readyModel() {
  const lastCheck = readLastCheck();
  return lastCheck?.state === 'ready' ? lastCheck.model : null;
}

/** What a check that could not reach Ollama finds. */
export const NOT_FOUND_CHECK = Object.freeze({ state: 'not-found', model: null, models: [] });

/**
 * Keeps a check's result, {state, model, models}, as the last check in this tab, then dispatches local-ai:checked on
 * the window with it as detail, so the chip and every feature on the page that runs on the laptop follow it at once. A
 * browser that refuses storage keeps nothing: its next page shows "not checked".
 */
export function rememberLastCheck(outcome) {
  try {
    sessionStorage.setItem(LAST_CHECK_KEY, JSON.stringify(outcome));
  } catch {
    // Storage off or full.
  }
  window.dispatchEvent(new CustomEvent(CHECKED_EVENT, { detail: outcome }));
}
