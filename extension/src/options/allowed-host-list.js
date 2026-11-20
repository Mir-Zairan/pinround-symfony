// The options page's allowed hosts (ADR-0046 "Where it plays"): the list with a Remove per entry, Add, and the one-press
// suggestion. allowed-hosts.js owns the rules, the cap and the refusals; this draws them. Every entry is set with
// textContent.
import { AllowedHostError, HOST_REFUSALS, MAX_ALLOWED_HOSTS, SUGGESTED_HOST, addAllowedHost, allowedHostOf, allowedHostText, loadAllowedHosts, removeAllowedHost } from '../shared/allowed-hosts.js';

export const ALLOWED_HOST_TEXTS = Object.freeze({
    empty: 'No hosts yet. Scenarios play only on hosts you add here.',
    full: HOST_REFUSALS.listFull,
    notRead: 'Could not read your allowed hosts. Reload this page to try again.',
    notSaved: 'Could not save your allowed hosts. Try again.',
    added: (entry) => `Added ${entry}.`,
    removed: (entry) => `Removed ${entry}.`,
    removeName: (entry) => `Remove ${entry}`,
});

const SUGGESTED_ENTRY = allowedHostText(allowedHostOf(SUGGESTED_HOST));

/**
 * One press at a time: each press draws the list and moves focus from the list the one before left, so a Remove
 * pressed before the last one ended would pick its next focus from a list that is gone.
 *
 * @param {Document} document the options page's, or a fake in tests
 * @param {object} storage the extension's local storage area
 */
export class AllowedHostList {
    #document;
    #storage;
    /** @type {string[]|null} null until the stored list was read */
    #entries = null;
    #pressing = Promise.resolve();
    /** @type {Map<string, HTMLButtonElement>} each shown entry's Remove button */
    #removeButtons = new Map();

    constructor(document, storage) {
        this.#document = document;
        this.#storage = storage;
        this.#element('allowed-host-form').addEventListener('submit', (event) => {
            event.preventDefault();
            this.#press(() => this.#add(this.#element('allowed-host-input').value, true));
        });
        this.#element('add-suggested-host').addEventListener('click', () => this.#press(() => this.#add(SUGGESTED_HOST, false)));
    }

    /** Reads the stored list and draws it; a list that cannot be read shows a refusal, never the empty state. */
    async show() {
        try {
            this.#entries = (await loadAllowedHosts(this.#storage)).map(allowedHostText);
        } catch {
            this.#showStatus(ALLOWED_HOST_TEXTS.notRead, true);

            return;
        }
        this.#render();
    }

    #element(id) {
        return this.#document.getElementById(id);
    }

    #press(work) {
        const pressed = this.#pressing.then(work);
        // A press that broke stays reported as unhandled, and the presses after it still run.
        this.#pressing = pressed.catch(() => undefined);
    }

    /** Only text Add refused marks the input invalid; any other answer clears the mark. */
    #showStatus(message, isError = false, isTypedTextRefused = false) {
        const status = this.#element('allowed-host-status');
        status.textContent = message;
        status.classList.toggle('error', isError);
        if (isTypedTextRefused) {
            this.#element('allowed-host-input').setAttribute('aria-invalid', 'true');
        } else {
            this.#element('allowed-host-input').removeAttribute('aria-invalid');
        }
    }

    async #add(entryText, isTyped) {
        let added;
        try {
            added = await addAllowedHost(this.#storage, entryText);
        } catch (error) {
            const isRefused = error instanceof AllowedHostError;
            this.#showStatus(isRefused ? error.message : ALLOWED_HOST_TEXTS.notSaved, true, isRefused && isTyped);

            return;
        }
        this.#entries = added.map(allowedHostText);
        this.#element('allowed-host-input').value = '';
        this.#render();
        this.#showStatus(ALLOWED_HOST_TEXTS.added(this.#entries.at(-1)));
        // The suggestion just pressed is hidden now, and a hidden button drops its focus to the page.
        if (!this.#element('add-suggested-host').checkVisibility()) {
            this.#element('allowed-host-input').focus();
        }
    }

    async #remove(entry) {
        const index = this.#entries.indexOf(entry);
        let left;
        try {
            left = await removeAllowedHost(this.#storage, entry);
        } catch {
            this.#showStatus(ALLOWED_HOST_TEXTS.notSaved, true);

            return;
        }
        this.#entries = left.map(allowedHostText);
        this.#render();
        this.#showStatus(ALLOWED_HOST_TEXTS.removed(entry));
        const nextRemove = this.#removeButtons.get(this.#entries[index]);
        (nextRemove ?? this.#element('allowed-host-input')).focus();
    }

    #render() {
        const entries = this.#entries;
        this.#removeButtons.clear();
        this.#element('allowed-host-list').replaceChildren(...entries.map((entry) => this.#entryItem(entry)));
        this.#element('allowed-host-list').hidden = 0 === entries.length;
        this.#element('allowed-hosts-empty').hidden = 0 !== entries.length;
        const isFull = entries.length >= MAX_ALLOWED_HOSTS;
        this.#element('allowed-hosts-full').textContent = isFull ? ALLOWED_HOST_TEXTS.full : '';
        this.#element('allowed-hosts-full').hidden = !isFull;
        this.#element('add-suggested-host').hidden = entries.includes(SUGGESTED_ENTRY);
    }

    #entryItem(entry) {
        const item = this.#document.createElement('li');
        const text = this.#document.createElement('span');
        text.textContent = entry;
        const remove = this.#document.createElement('button');
        remove.type = 'button';
        remove.className = 'secondary';
        remove.textContent = 'Remove';
        remove.setAttribute('aria-label', ALLOWED_HOST_TEXTS.removeName(entry));
        remove.addEventListener('click', () => this.#press(() => this.#remove(entry)));
        this.#removeButtons.set(entry, remove);
        item.append(text, remove);

        return item;
    }
}
