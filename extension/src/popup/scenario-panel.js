// The popup's "Scenarios" section: the chosen notebook's scenarios, played by NotebookPlayer (src/player/), with the
// running step's say line in a polite live region, a failed step's expected and found texts, and the one-time
// question per host. Every text from pinround is set with textContent, its control and direction characters written
// out. `element` finds a popup element by its id.
import { shownText } from '../capture/untrusted-text.js';
import { NOTEBOOK_PLAYER_TEXTS, NotebookPlayer, loadNotebookScenarios } from '../player/notebook-player.js';
import { SAY_MAX_LENGTH, SETUP_MAX_LENGTH, TITLE_MAX_LENGTH, WHY_MAX_LENGTH } from '../player/scenario-rules.js';

export const PANEL_TEXTS = Object.freeze({
    loading: 'Loading the scenarios…',
    done: 'The scenario ran to its end.',
    paused: 'Paused. Press Play to go on.',
    stepped: 'Step done. Press Step for the next one.',
});

const PLAYER_BUTTON_IDS = ['player-play', 'player-step', 'player-stop-run', 'player-back', 'player-next'];

export class ScenarioPanel {
    #element;
    #showStatus;
    #playerDeps;
    #player = null;
    #loadsStarted = 0;
    /** Where focus goes back to when a run ends under the keyboard. */
    #lastPressedId = 'player-play';

    /**
     * @param {(id: string) => HTMLElement} element
     * @param {(message: string, isError?: boolean) => void} showStatus the popup's status line
     * @param {{api: object, browser: object, storage: object, pinroundOrigin: string, activeTabId: () => Promise<number>,
     *   captureToNotebook: (notebook: object, tabId: number, texts: object) => Promise<unknown>}} playerDeps
     */
    constructor(element, showStatus, playerDeps) {
        this.#element = element;
        this.#showStatus = showStatus;
        this.#playerDeps = playerDeps;
        element('player-play').addEventListener('click', () => this.#press('player-play', (player) => player.play()));
        element('player-step').addEventListener('click', () => this.#press('player-step', (player) => player.step()));
        element('player-back').addEventListener('click', () => this.#press('player-back', (player) => player.back()));
        element('player-next').addEventListener('click', () => this.#press('player-next', (player) => player.next()));
        element('player-stop-run').addEventListener('click', () => this.#player?.stop());
        globalThis.addEventListener('pagehide', () => {
            this.#player?.close();
        });
    }

    /**
     * Shows the scenarios of the target when it is a notebook, and hides the section otherwise. The player of the
     * notebook shown before is closed, so its highlight leaves the page.
     *
     * @param {import('../capture/capture-target.js').CaptureTarget|undefined} target
     */
    async showFor(target) {
        const load = ++this.#loadsStarted;
        await this.#player?.close();
        this.#player = null;
        this.#element('scenario-player').hidden = true;
        if ('notebook' !== target?.kind) {
            this.#element('scenarios').hidden = true;

            return;
        }
        this.#element('scenarios').hidden = false;
        this.#showMessage(PANEL_TEXTS.loading);
        let made;
        try {
            const scenarios = await loadNotebookScenarios(this.#playerDeps.api, target.notebookId);
            made = NotebookPlayer.of(scenarios, this.#notebookPlayerDeps(target));
        } catch (error) {
            made = { refusal: String(error?.message ?? error) };
        }
        if (load !== this.#loadsStarted) {
            return;
        }
        if (undefined !== made.refusal) {
            this.#showMessage(made.refusal);

            return;
        }
        this.#player = made.player;
        this.#showMessage('');
        this.#element('scenario-player').hidden = false;
        this.#element('player-say').textContent = '';
        this.#render();
    }

    #notebookPlayerDeps(notebook) {
        const { browser, storage, pinroundOrigin, activeTabId, captureToNotebook } = this.#playerDeps;

        return {
            browser,
            storage,
            pinroundOrigin,
            activeTabId,
            askToPlay: (host) => this.#askToPlay(host),
            captureToNotebook: (tabId, texts) => captureToNotebook(notebook, tabId, texts),
            onStepStart: (step) => {
                this.#element('player-say').textContent = shownText(step.say, SAY_MAX_LENGTH);
            },
        };
    }

    #showMessage(message) {
        this.#element('scenarios-message').textContent = message;
        this.#element('scenarios-message').hidden = '' === message;
    }

    async #press(buttonId, move) {
        const player = this.#player;
        if (null === player) {
            return;
        }
        this.#showStatus('');
        this.#lastPressedId = buttonId;
        const moving = move(player);
        this.#render();
        let answer;
        try {
            answer = await moving;
        } catch (error) {
            answer = { refused: String(error?.message ?? error) };
        }
        if (player !== this.#player) {
            return;
        }
        this.#render();
        this.#showAnswer(answer);
        if (this.#isFocusLost()) {
            this.#element(buttonId).focus();
        }
    }

    #showAnswer(answer) {
        if (undefined !== answer.refused) {
            this.#showStatus(answer.refused, true);

            return;
        }
        if (undefined !== answer.moved) {
            this.#element('player-say').textContent = '';

            return;
        }
        if ('stopped' === answer.ended) {
            const stop = this.#player.lastStop;
            this.#showStatus(stop.message, NOTEBOOK_PLAYER_TEXTS.stopped !== stop.message);

            return;
        }
        if ('paused' === answer.ended) {
            this.#element('player-say').textContent = shownText(this.#player.say ?? '', SAY_MAX_LENGTH);
        }
        this.#showStatus({ done: PANEL_TEXTS.done, paused: PANEL_TEXTS.paused, stepped: PANEL_TEXTS.stepped }[answer.ended]);
    }

    #render() {
        const player = this.#player;
        const element = this.#element;
        const { scenario } = player;
        element('scenario-list').replaceChildren(...player.scenarioTitles.map((title, index) => {
            const item = document.createElement('li');
            item.textContent = shownText(title, TITLE_MAX_LENGTH);
            if (index === player.scenarioIndex) {
                item.setAttribute('aria-current', 'step');
            }

            return item;
        }));
        element('scenario-position').textContent = `Scenario ${player.scenarioIndex + 1} of ${player.scenarioCount}`;
        element('scenario-title').textContent = shownText(scenario.title, TITLE_MAX_LENGTH);
        element('scenario-why').textContent = shownText(scenario.why, WHY_MAX_LENGTH);
        element('scenario-setup').textContent = shownText(scenario.setup, SETUP_MAX_LENGTH);
        element('scenario-setup-line').hidden = '' === scenario.setup.trim();
        element('player-play').textContent = player.isDone ? 'Play again' : 'Play';

        const stop = player.lastStop;
        const hasTexts = null !== stop && (undefined !== stop.expected || undefined !== stop.found);
        element('player-stop').hidden = !hasTexts;
        element('player-stop-texts').hidden = !hasTexts;
        element('player-stop-message').textContent = hasTexts ? stop.message : '';
        element('player-expected').textContent = stop?.expected ?? '';
        element('player-found').textContent = stop?.found ?? '';

        const wasFocused = document.activeElement;
        for (const id of PLAYER_BUTTON_IDS) {
            element(id).disabled = 'player-stop-run' === id ? !player.isRunning : player.isRunning;
        }
        // A control disabled under the keyboard drops its focus to the body; Stop is the one a running player offers.
        if (PLAYER_BUTTON_IDS.includes(wasFocused?.id) && wasFocused.disabled) {
            element(player.isRunning ? 'player-stop-run' : this.#lastPressedId).focus();
        }
    }

    #isFocusLost() {
        return null === document.activeElement || document.body === document.activeElement;
    }

    #askToPlay(host) {
        const element = this.#element;
        element('host-question-text').textContent = NOTEBOOK_PLAYER_TEXTS.hostQuestion(host);
        element('host-question').hidden = false;
        element('host-question-cancel').focus();

        return new Promise((resolve) => {
            const listening = new AbortController();
            const answer = (isPlayAnyway) => {
                listening.abort();
                element('host-question').hidden = true;
                element('player-stop-run').focus();
                resolve(isPlayAnyway);
            };
            element('host-question-play').addEventListener('click', () => answer(true), { signal: listening.signal });
            element('host-question-cancel').addEventListener('click', () => answer(false), { signal: listening.signal });
        });
    }
}
