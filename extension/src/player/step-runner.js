// Runs one checked scenario step for ScenarioPlayer (player.js): the steps that act on the page go to page-player.js
// in the tab, the others to the extension. Every answer from the page is read again here, and its text made safe to
// show, as the page decides what its elements hold.
import './player-text.js';

const { MESSAGE_MAX_LENGTH, shownText } = globalThis.pinroundPlayerText;

/** player-text.js first: page-player.js reads the text rules it leaves on the global. Paths are from the extension's root. */
export const PAGE_PLAYER_FILES = ['src/player/player-text.js', 'src/player/page-player.js'];

export const RUNNER_REFUSALS = Object.freeze({
    unreadableAnswer: 'The page gave an answer the player cannot read. Play the step again.',
    outOfReach: 'The player cannot reach this page. Open the scenario\'s page and play again.',
    unknownStep: 'The player cannot run this step.',
});

const PAGE_STEP_TYPES = new Set(['click', 'type', 'select', 'wait_for', 'expect_text', 'expect_visible']);

/**
 * @param {object} browser src/shared/browser.js, or a fake in tests
 * @param {{tabId: number, openUrl: (url: string) => Promise<void>, captureToNotebook: () => Promise<void>}} tab the
 *   tab the scenario plays in; openUrl navigates it after the caller's host checks, and captureToNotebook adds a
 *   screenshot of it to the notebook
 * @returns {(step: object) => Promise<{ok: true} | {stopped: true, message: string, expected?: string, found?: string}>}
 *   never rejects: a failure is a stop with a plain message
 */
export function stepRunnerFor(browser, { tabId, openUrl, captureToNotebook }) {
    return async (step) => {
        try {
            if (PAGE_STEP_TYPES.has(step.type)) {
                return await runInPage(browser, tabId, step);
            }
            if ('go_to' === step.type) {
                await openUrl(step.url);

                return { ok: true };
            }
            if ('capture' === step.type) {
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

async function runInPage(browser, tabId, step) {
    // Injected before every step: after a navigation the page holds no player, and a second injection keeps the first.
    await browser.scripting.executeScript({ target: { tabId }, files: PAGE_PLAYER_FILES });
    const injections = await browser.scripting.executeScript({
        target: { tabId },
        args: [step],
        func: (pageStep) => globalThis.pinroundPagePlayer?.runStep(pageStep) ?? null,
    });

    return checkedPageAnswer(injections[0]?.result);
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
