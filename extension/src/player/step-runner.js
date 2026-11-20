// Runs one checked scenario step for ScenarioPlayer (player.js): the steps that act on the page go to page-player.js
// in the tab, the others to the extension. Every answer from the page is read again here, and its text made safe to
// show, as the page decides what its elements hold.
import './player-text.js';

const { MESSAGE_MAX_LENGTH, shownText } = globalThis.pinroundPlayerText;

/** player-text.js first: page-player.js reads the text rules it leaves on the global. Paths are from the extension's root. */
export const PAGE_PLAYER_FILES = ['src/player/player-text.js', 'src/player/page-player.js'];

/** How long a page step may wait for the tab to load, wait_for's own wait included. */
export const PAGE_STEP_TIMEOUT_MS = 10_000;
/** The pause between two looks at whether the tab has loaded. */
export const LOAD_POLL_MS = 100;

export const RUNNER_REFUSALS = Object.freeze({
    unreadableAnswer: 'The page gave an answer the player cannot read. Play the step again.',
    outOfReach: 'The player cannot reach this page. Open the scenario\'s page and play again.',
    unknownStep: 'The player cannot run this step.',
    notLoaded: `The page did not finish loading in ${PAGE_STEP_TIMEOUT_MS / 1000} seconds. Play the step again once it shows.`,
});

const PAGE_STEP_TYPES = new Set(['click', 'type', 'select', 'wait_for', 'expect_text', 'expect_visible']);

const SYSTEM_CLOCK = Object.freeze({
    now: () => Date.now(),
    wait: (ms) => new Promise((resolve) => {
        setTimeout(resolve, ms);
    }),
});

/**
 * @param {object} browser src/shared/browser.js, or a fake in tests
 * @param {{tabId: number, openUrl: (url: string) => Promise<void>, captureToNotebook: () => Promise<void>,
 *   clock?: {now: () => number, wait: (ms: number) => Promise<void>}}} tab the tab the scenario plays in; openUrl
 *   navigates it after the caller's host checks, and captureToNotebook adds a screenshot of it to the notebook. The
 *   clock, in ms, times the waits for the tab to load; the system's by default.
 * @returns {(step: object) => Promise<{ok: true} | {stopped: true, message: string, expected?: string, found?: string}>}
 *   never rejects: a failure is a stop with a plain message
 */
export function stepRunnerFor(browser, { tabId, openUrl, captureToNotebook, clock = SYSTEM_CLOCK }) {
    return async (step) => {
        try {
            if (PAGE_STEP_TYPES.has(step.type)) {
                return await runInPage(browser, tabId, step, clock);
            }
            if ('go_to' === step.type) {
                await openUrl(step.url);

                return { ok: true };
            }
            if ('capture' === step.type) {
                if (!await hasLoaded(browser, tabId, clock, clock.now() + PAGE_STEP_TIMEOUT_MS)) {
                    return { stopped: true, message: RUNNER_REFUSALS.notLoaded };
                }
                // The ring of the step before would be in the screenshot.
                await clearPageHighlight(browser, tabId);
                await captureToNotebook();

                return { ok: true };
            }
            // A pause waits for the person, which ScenarioPlayer does; there is nothing to do in the tab.
            if ('pause' === step.type) {
                return { ok: true };
            }

            return { stopped: true, message: RUNNER_REFUSALS.unknownStep };
        } catch (error) {
            if (isOutOfReach(error)) {
                return { stopped: true, message: RUNNER_REFUSALS.outOfReach };
            }

            return { stopped: true, message: shownText(`The step could not run: ${String(error?.message ?? error)}`, MESSAGE_MAX_LENGTH) };
        }
    };
}

/**
 * Removes the page player's highlight from the tab, if the page holds one, and resolves once a frame without it has
 * been painted in a visible tab, so a screenshot taken next does not show it. For the end, stop or close of a run too.
 * Rejects as executeScript does, for a tab that is gone or out of reach.
 */
export async function clearPageHighlight(browser, tabId) {
    await browser.scripting.executeScript({
        target: { tabId },
        func: () => {
            const player = globalThis.pinroundPagePlayer;
            if (undefined === player) {
                return true;
            }
            player.clearHighlight();

            // captureVisibleTab copies the last painted frame; the second frame callback runs after one without the ring.
            return 'visible' === globalThis.document?.visibilityState
                ? new Promise((resolve) => {
                    requestAnimationFrame(() => requestAnimationFrame(() => resolve(true)));
                })
                : true;
        },
    });
}

/**
 * A click can start a navigation, so the step waits for the tab to load before it is injected. A wait_for whose
 * document goes away while it looks, which answers null or rejects, looks again in the new document until its time is
 * up; a failed look and a next injection in the same document is a broken player, not a navigation. A refused
 * injection of the player itself names no document, so the next look waits LOAD_POLL_MS first: the time runs out.
 */
async function runInPage(browser, tabId, step, clock) {
    const deadline = clock.now() + PAGE_STEP_TIMEOUT_MS;
    let leftDocumentId = null;
    for (;;) {
        if (!await hasLoaded(browser, tabId, clock, deadline)) {
            return { stopped: true, message: RUNNER_REFUSALS.notLoaded };
        }
        let playerInjection;
        let stepInjection;
        try {
            // Injected before every step: after a navigation the page holds no player, and a second injection keeps the first.
            [playerInjection] = await browser.scripting.executeScript({ target: { tabId }, files: PAGE_PLAYER_FILES });
            if (null !== leftDocumentId && playerInjection?.documentId === leftDocumentId) {
                return { stopped: true, message: RUNNER_REFUSALS.unreadableAnswer };
            }
            [stepInjection] = await browser.scripting.executeScript({
                target: { tabId },
                args: [step, { timeoutMs: Math.max(0, deadline - clock.now()) }],
                func: (pageStep, options) => globalThis.pinroundPagePlayer?.runStep(pageStep, options) ?? null,
            });
        } catch (error) {
            if ('wait_for' !== step.type || isOutOfReach(error)) {
                throw error;
            }
            stepInjection = { documentId: playerInjection?.documentId, result: null };
        }
        const answer = stepInjection?.result ?? null;
        if (null !== answer || 'wait_for' !== step.type || clock.now() >= deadline) {
            return checkedPageAnswer(answer);
        }
        leftDocumentId = stepInjection?.documentId ?? null;
        if (undefined === playerInjection) {
            await clock.wait(LOAD_POLL_MS);
        }
    }
}

/** Whether the tab's status is complete by the deadline, looking every LOAD_POLL_MS. */
async function hasLoaded(browser, tabId, clock, deadline) {
    for (;;) {
        const tab = await browser.tabs.get(tabId);
        if ('complete' === tab.status) {
            return true;
        }
        if (clock.now() >= deadline) {
            return false;
        }
        await clock.wait(LOAD_POLL_MS);
    }
}

/**
 * The page player's answer, with only the fields the player reads and each text capped and safe to show. Anything
 * else, such as no answer from a page that went away, is a stop: never a step that looks done.
 */
export function checkedPageAnswer(answer) {
    if (null === answer || 'object' !== typeof answer) {
        return { stopped: true, message: RUNNER_REFUSALS.unreadableAnswer };
    }
    if (true === answer.ok && undefined === answer.stopped) {
        return { ok: true };
    }
    if (true !== answer.stopped || 'string' !== typeof answer.message) {
        return { stopped: true, message: RUNNER_REFUSALS.unreadableAnswer };
    }
    const checked = { stopped: true, message: shownText(answer.message, MESSAGE_MAX_LENGTH) };
    for (const field of ['expected', 'found']) {
        if (Object.hasOwn(answer, field)) {
            if ('string' !== typeof answer[field]) {
                return { stopped: true, message: RUNNER_REFUSALS.unreadableAnswer };
            }
            checked[field] = shownText(answer[field]);
        }
    }

    return checked;
}

const isOutOfReach = (error) => error instanceof Error && error.message.startsWith('Cannot access ');
