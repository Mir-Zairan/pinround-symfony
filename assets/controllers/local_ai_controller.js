import { Controller } from '@hotwired/stimulus';

// Ollama's answer comes from whatever listens on the laptop's port, so it is untrusted: only names of this shape are
// shown or sent, and only as text. LaptopController::MODEL_NAME_PATTERN checks the same on the server.
const MODEL_NAME = /^[a-z0-9:._-]{1,64}$/;
const CHECK_TIMEOUT_MS = 3000;
// The choice of model outlives the page; a browser that refuses storage just picks again on the next check.
const CHOSEN_MODEL_KEY = 'pinround.localAiModel';
// Every page renders a new header, so the last check's result is kept for the tab and shown again without calling
// Ollama; a browser that refuses storage shows "not checked" instead.
const LAST_CHECK_KEY = 'pinround.localAiLastCheck';
const NOT_CHECKED = { state: 'not-checked', model: null, models: [] };
// The abort reason when the person leaves the page: the check then ends with nothing shown, kept or reported.
const LEFT_PAGE = Symbol('left the page');

// The header's Local AI chip and setup sheet (ADR-0019). It looks for Ollama only when "Check again" is pressed, never
// on page load: a public page calling localhost may make the browser ask for local network access, and that prompt must
// follow the person's own click. It tells the server what it found with POST /ai/laptop/status.
export default class extends Controller {
  static targets = ['stateLine', 'modelName', 'modelChoices', 'checkButton'];
  static values = { tagsUrl: String, statusUrl: String, csrfHeader: String, csrfToken: String };

  pendingCheck = null;

  // Also undoes a check that a page snapshot kept half done, so Back never shows "checking…" with Check again off.
  connect() {
    this.#showLastCheck();
  }

  // The element is off the page, so nothing is written into it.
  disconnect() {
    this.pendingCheck?.abort(LEFT_PAGE);
  }

  // On beforeunload and pagehide. Leaving the page cancels the request, which would read as Ollama not found; and a
  // page the browser keeps for Back must not come back mid-check.
  leavePage() {
    if (!this.pendingCheck) {
      return;
    }
    this.pendingCheck.abort(LEFT_PAGE);
    this.#showLastCheck();
  }

  async check() {
    if (this.pendingCheck) {
      return;
    }
    const pendingCheck = new AbortController();
    this.pendingCheck = pendingCheck;
    const timeout = setTimeout(() => pendingCheck.abort(), CHECK_TIMEOUT_MS);
    this.#showState('checking');
    this.modelChoicesTarget.hidden = true;
    this.checkButtonTarget.disabled = true;
    const models = await this.#installedModels(pendingCheck.signal);
    clearTimeout(timeout);
    this.pendingCheck = null;
    if (pendingCheck.signal.reason === LEFT_PAGE) {
      return;
    }
    this.checkButtonTarget.disabled = false;
    const outcome = this.#outcomeOf(models);
    this.#showOutcome(outcome);
    this.#rememberOutcome(outcome);
    this.#reportStatus(outcome.state === 'ready', outcome.model);
  }

  choose(event) {
    const model = event.currentTarget.textContent;
    const models = this.#shownChoices();
    if (!MODEL_NAME.test(model) || !models.includes(model)) {
      return;
    }
    try {
      localStorage.setItem(CHOSEN_MODEL_KEY, model);
    } catch {
      // Storage off or full: the choice holds for this page only.
    }
    this.#showModel(model);
    this.#rememberOutcome({ state: 'ready', model, models });
    this.#reportStatus(true, model);
  }

  // Null when Ollama could not be reached or answered with something that is not its list of models. Never throws.
  async #installedModels(signal) {
    try {
      const response = await fetch(this.tagsUrlValue, { signal, credentials: 'omit', cache: 'no-store', referrerPolicy: 'no-referrer' });
      if (!response.ok) {
        return null;
      }
      const tags = await response.json();
      if (!Array.isArray(tags?.models)) {
        return null;
      }
      const names = tags.models.map((model) => model?.name).filter((name) => typeof name === 'string' && MODEL_NAME.test(name));
      return [...new Set(names)];
    } catch {
      return null;
    }
  }

  #outcomeOf(models) {
    if (models === null) {
      return { state: 'not-found', model: null, models: [] };
    }
    if (models.length === 0) {
      return { state: 'no-model', model: null, models: [] };
    }
    return { state: 'ready', model: this.#preferredModel(models), models };
  }

  #showLastCheck() {
    this.checkButtonTarget.disabled = false;
    this.#showOutcome(this.#lastCheck() ?? NOT_CHECKED);
  }

  #showOutcome({ state, model, models }) {
    // One model needs no choice.
    const choices = models.length > 1 ? models : [];
    this.modelChoicesTarget.replaceChildren(...choices.map((name) => this.#modelChip(name)));
    this.modelChoicesTarget.hidden = choices.length === 0;
    this.#showModel(model);
    this.#showState(state);
  }

  #modelChip(model) {
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'chip';
    chip.textContent = model;
    chip.dataset.action = 'local-ai#choose';
    return chip;
  }

  #showModel(model) {
    this.modelNameTarget.textContent = model ?? '';
    for (const chip of this.modelChoicesTarget.children) {
      chip.setAttribute('aria-pressed', String(chip.textContent === model));
    }
  }

  #shownChoices() {
    return Array.from(this.modelChoicesTarget.children, (chip) => chip.textContent);
  }

  // The model chosen before when Ollama still has it, else the first qwen3, else the first phi4 or gemma, else the first.
  #preferredModel(models) {
    const chosenModel = this.#chosenModel();
    if (models.includes(chosenModel)) {
      return chosenModel;
    }
    return models.find((name) => name.startsWith('qwen3'))
      ?? models.find((name) => name.startsWith('phi4') || name.startsWith('gemma'))
      ?? models[0];
  }

  #chosenModel() {
    try {
      return localStorage.getItem(CHOSEN_MODEL_KEY);
    } catch {
      return null;
    }
  }

  #rememberOutcome(outcome) {
    try {
      sessionStorage.setItem(LAST_CHECK_KEY, JSON.stringify(outcome));
    } catch {
      // Storage off or full: the next page shows "not checked".
    }
  }

  // Null when there is none, storage is off, or what is stored is not a result a check gives.
  #lastCheck() {
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

  #showState(state) {
    this.element.dataset.state = state;
    for (const line of this.stateLineTargets) {
      line.hidden = !line.dataset.states.split(' ').includes(state);
    }
  }

  // The server keeps nothing from it and the sheet already shows what was found, so a failed report changes nothing here.
  async #reportStatus(ready, model) {
    try {
      await fetch(this.statusUrlValue, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', [this.csrfHeaderValue]: this.csrfTokenValue },
        body: JSON.stringify({ ready, model }),
      });
    } catch {
      // Offline or the session ended; the next check reports again.
    }
  }
}
