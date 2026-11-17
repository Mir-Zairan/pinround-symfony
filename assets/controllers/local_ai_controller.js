import { Controller } from '@hotwired/stimulus';

// Ollama's answer comes from whatever listens on the laptop's port, so it is untrusted: only names of this shape are
// shown or sent, and only as text. LaptopController::MODEL_NAME_PATTERN checks the same on the server.
const MODEL_NAME = /^[a-z0-9:._-]{1,64}$/;
const CHECK_TIMEOUT_MS = 3000;
// The choice of model outlives the page; a browser that refuses storage just picks again on the next check.
const CHOSEN_MODEL_KEY = 'pinround.localAiModel';

// The header's Local AI chip and setup sheet (ADR-0019). It looks for Ollama only when "Check again" is pressed, never
// on page load: a public page calling localhost may make the browser ask for local network access, and that prompt must
// follow the person's own click. It tells the server what it found with POST /ai/laptop/status.
export default class extends Controller {
  static targets = ['stateLine', 'modelName', 'modelChoices', 'checkButton'];
  static values = { tagsUrl: String, statusUrl: String, csrfHeader: String, csrfToken: String };

  pendingCheck = null;

  disconnect() {
    this.pendingCheck?.abort();
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
    try {
      const models = await this.#installedModels(pendingCheck.signal);
      this.#showModels(models);
    } finally {
      clearTimeout(timeout);
      this.pendingCheck = null;
      this.checkButtonTarget.disabled = false;
    }
  }

  choose(event) {
    const model = event.currentTarget.textContent;
    if (!MODEL_NAME.test(model)) {
      return;
    }
    try {
      localStorage.setItem(CHOSEN_MODEL_KEY, model);
    } catch {
      // Storage off or full: the choice holds for this page only.
    }
    this.#showReady(model);
  }

  // Null when Ollama could not be reached or answered with something that is not its list of models.
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

  #showModels(models) {
    this.modelChoicesTarget.replaceChildren();
    this.modelChoicesTarget.hidden = true;
    if (models === null) {
      this.#showState('not-found');
      this.#reportStatus(false, null);
      return;
    }
    if (models.length === 0) {
      this.#showState('no-model');
      this.#reportStatus(false, null);
      return;
    }
    if (models.length > 1) {
      for (const model of models) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'chip';
        chip.textContent = model;
        chip.dataset.action = 'local-ai#choose';
        this.modelChoicesTarget.append(chip);
      }
      this.modelChoicesTarget.hidden = false;
    }
    this.#showReady(this.#preferredModel(models));
  }

  #showReady(model) {
    this.modelNameTarget.textContent = model;
    for (const chip of this.modelChoicesTarget.children) {
      chip.setAttribute('aria-pressed', String(chip.textContent === model));
    }
    this.#showState('ready');
    this.#reportStatus(true, model);
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
