import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Locator, type Page, type Request, type Route } from '@playwright/test';
import { readAiSettings, restoreAiSettings, storeAiSettings, type StoredAiSettings } from './ai-settings';
import { OMAR_STORAGE_STATE } from './auth';
import { runAsDatabaseOwner } from './database';
import { makeTicketForOmar, TOAST_REGION } from './ticket';

// The summary frame's poll (summary-poll_controller.js). The press and every poll are answered here, so no job reaches
// the shared async_ai queue and the spec needs no AI provider; the answers carry the markup TheAsk renders.

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

/** summary-poll_controller.js POLL_INTERVAL_MS and MAX_POLLS. */
const POLL_INTERVAL_MS = 3000;
const MAX_POLLS = 60;
/** toast_controller.js ANNOUNCE_DELAY_MS. */
const TOAST_ANNOUNCE_DELAY_MS = 150;
/** Playwright's fake clock runs requestAnimationFrame callbacks on a 16 ms timer. */
const ANIMATION_FRAME_MS = 16;
/** The most pressSummarize moves the clock while it waits for Turbo's render; far below POLL_INTERVAL_MS. */
const MAX_RENDER_STEPPING_MS = 500;

const ORGANIZATION_SLUG = 'acme-foods';
const CLOUD_AI = { on: true, who: 'everyone', where: 'cloud' };
const AI_OFF = { on: false, who: 'everyone', where: 'cloud' };
const SUMMARY_TEXT = 'The pay button does nothing on the last checkout step.';
const FAILED_LINE = 'The AI answer failed. Try again.';

const ASK_FRAME = '#ticket-ask';
const ASK_STATE = '#ticket-ask .ticket__ask-state';

// A day that is not today, so nothing here leans on the real clock.
const FROZEN_AT = new Date('2026-03-04T09:00:00Z');

type FrameState = 'running' | 'done' | 'failed';

function frameAnswer(key: string, state: FrameState): string {
  const summaryUrl = `/t/${key}/summary`;
  const body = {
    running: `<form method="post" action="${summaryUrl}" class="ticket__ask-actions">
        <button type="submit" class="btn btn-tonal ticket__summarize" disabled aria-busy="true" data-summary-poll-target="button" data-turbo-submits-with="Summarizing…">Summarizing…</button>
      </form>`,
    done: `<div class="card ticket__ask ticket__ask--summary"><p class="ticket__ask-source"><strong>In short</strong></p>
        <div class="ticket__ask-summary-text">${SUMMARY_TEXT}</div></div>`,
    failed: `<p class="ticket__ask-failed">${FAILED_LINE}</p>
      <form method="post" action="${summaryUrl}" class="ticket__ask-actions">
        <button type="submit" class="btn btn-tonal ticket__summarize" data-turbo-submits-with="Summarizing…">Summarize with AI</button>
      </form>`,
  }[state];
  return `<turbo-frame id="ticket-ask" aria-live="polite"><div class="ticket__ask-state" tabindex="-1" autofocus
      data-controller="summary-poll"
      data-action="visibilitychange@document->summary-poll#followVisibility turbo:submit-start->summary-poll#markBusy turbo:submit-end->summary-poll#clearBusy"
      data-summary-poll-url-value="${summaryUrl}" data-summary-poll-state-value="${state}"
      data-summary-poll-saved-toast-value="Summary saved for everyone." data-summary-poll-ready-label-value="Summarize with AI">${body}</div></turbo-frame>`;
}

async function answerWith(route: Route, key: string, state: FrameState): Promise<void> {
  await route.fulfill({ status: 200, contentType: 'text/html; charset=UTF-8', body: frameAnswer(key, state) });
}

function isPoll(request: Request, key: string): boolean {
  return request.method() === 'GET' && new URL(request.url()).pathname === `/t/${key}/summary`;
}

/** Answers the press with the running frame and each poll with the next state of pollStates, its last one after that. */
async function answerPressAndPolls(page: Page, key: string, pollStates: FrameState[]): Promise<Request[]> {
  const polls: Request[] = [];
  await page.route(`**/t/${key}/summary`, async (route) => {
    if (route.request().method() === 'POST') {
      await answerWith(route, key, 'running');
      return;
    }
    if (!isPoll(route.request(), key)) {
      await route.continue();
      return;
    }
    polls.push(route.request());
    await answerWith(route, key, pollStates[Math.min(polls.length, pollStates.length) - 1]);
  });
  return polls;
}

async function makeTicketWithAnAsk(browser: Browser, title: string): Promise<string> {
  const key = await makeTicketForOmar(browser, title);
  runAsDatabaseOwner(`UPDATE tkt_ticket SET description = 'Pressing pay on the last step does nothing.'
    WHERE key = '${key}' AND organization_id = (SELECT organization_id FROM org_organization WHERE slug = '${ORGANIZATION_SLUG}')`);
  return key;
}

/** Stores ai before each test of the calling describe and puts the organization's own AI settings back after it. */
function useAiSettingsInEachTest(ai: object): void {
  let previousAiSettings: StoredAiSettings | null = null;

  test.beforeEach(() => {
    previousAiSettings = readAiSettings(ORGANIZATION_SLUG);
    storeAiSettings(ORGANIZATION_SLUG, ai);
  });

  test.afterEach(() => {
    if (null !== previousAiSettings) {
      restoreAiSettings(ORGANIZATION_SLUG, previousAiSettings);
      previousAiSettings = null;
    }
  });
}

/** Opens the ticket and stops the page's clock; from here only runFor moves the polls. */
async function openTicketWithPausedClock(page: Page, key: string): Promise<void> {
  await page.clock.install({ time: FROZEN_AT });
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await page.clock.pauseAt(new Date(FROZEN_AT.getTime() + 60_000));
}

async function clickButton(button: Locator): Promise<void> {
  await button.click();
}

async function pressButtonWithEnter(button: Locator): Promise<void> {
  await button.press('Enter');
}

/**
 * Presses "Summarize with AI" on the open ticket and waits until Turbo has rendered the running frame and focused its
 * [autofocus]. Turbo takes those steps only after animation frames, which the paused clock fires only when it moves, so
 * the clock moves one frame at a time until the frame's turbo:frame-load, at most MAX_RENDER_STEPPING_MS in all.
 * Resolves to the clock's time, in ms, when the running content went in: the first poll timer started then, when the new
 * summary-poll controller connected, before turbo:frame-load.
 */
async function pressSummarize(page: Page, key: string, press: (button: Locator) => Promise<void> = clickButton): Promise<number> {
  const frameRender = await page.locator(ASK_FRAME).evaluateHandle((frame) => {
    const sign: { swappedAtMs: number | null; hasLoaded: boolean } = { swappedAtMs: null, hasLoaded: false };
    const swap = new MutationObserver(() => {
      sign.swappedAtMs = Date.now();
      swap.disconnect();
    });
    swap.observe(frame, { childList: true });
    frame.addEventListener(
      'turbo:frame-load',
      () => {
        sign.hasLoaded = true;
      },
      { once: true },
    );
    return sign;
  });
  const pressed = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === `/t/${key}/summary`);
  await press(page.locator(ASK_FRAME).getByRole('button', { name: 'Summarize with AI' }));
  await pressed;

  let steppedMs = 0;
  await expect
    .poll(async () => {
      if (!(await frameRender.evaluate((sign) => sign.hasLoaded)) && steppedMs + ANIMATION_FRAME_MS <= MAX_RENDER_STEPPING_MS) {
        await page.clock.runFor(ANIMATION_FRAME_MS);
        steppedMs += ANIMATION_FRAME_MS;
      }
      return frameRender.evaluate((sign) => sign.hasLoaded);
    })
    .toBe(true);

  const runningButton = page.locator(`${ASK_FRAME} .ticket__summarize`);
  await expect(runningButton).toHaveAttribute('data-summary-poll-target', 'button');
  await expect(runningButton).toHaveText('Summarizing…');
  await expect(runningButton).toBeDisabled();

  const swappedAtMs = await frameRender.evaluate((sign) => sign.swappedAtMs);
  if (swappedAtMs === null) {
    throw new Error('Turbo loaded #ticket-ask without swapping its content.');
  }
  return swappedAtMs;
}

/**
 * Records the clock's time of each summary GET the page starts with fetch. The route handler only sees a poll some real
 * time after the page sent it, so its count can neither show that a runFor sent no poll nor which runFor sent one.
 */
async function recordPollTimes(page: Page): Promise<() => Promise<number[]>> {
  const pollTimes = await page.evaluateHandle(() => {
    const startedAtMs: number[] = [];
    const pageFetch = window.fetch.bind(window);
    window.fetch = (input, init) => {
      const url = new URL(input instanceof Request ? input.url : String(input), window.location.href);
      if ((init?.method ?? 'GET').toUpperCase() === 'GET' && url.pathname.endsWith('/summary')) {
        startedAtMs.push(Date.now());
      }
      return pageFetch(input, init);
    };
    return startedAtMs;
  });
  return () => pollTimes.evaluate((startedAtMs) => [...startedAtMs]);
}

/**
 * Moves the paused clock to the next poll, then waits for the frame's count of handled answers: the route handler sees
 * the poll before the page has read its answer, and only after that is the next poll's timer set.
 */
async function nextPoll(page: Page, polls: Request[], expectedCount: number): Promise<void> {
  await page.clock.runFor(POLL_INTERVAL_MS);
  await expect.poll(() => polls.length).toBe(expectedCount);
  await expect(page.locator(ASK_FRAME)).toHaveAttribute('data-answered-polls', String(expectedCount));
}

async function isFocusInsideTheAsk(page: Page): Promise<boolean> {
  return page.evaluate((frameSelector) => document.querySelector(frameSelector)?.contains(document.activeElement) ?? false, ASK_FRAME);
}

/** axe-core settles each rule on a setTimeout, which a paused clock never fires, so the clock runs again first. */
async function seriousViolations(page: Page): Promise<string[]> {
  await page.clock.resume();
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test.describe('"Summarize with AI" while the summary is on its way', () => {
  test.use({ storageState: OMAR_STORAGE_STATE });
  useAiSettingsInEachTest(CLOUD_AI);

  test('polls every 3 seconds, shows the summary with its toast when it arrives, then polls no more', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Summary poll shows the summary');
    const polls = await answerPressAndPolls(page, key, ['running', 'done']);
    await openTicketWithPausedClock(page, key);
    const pollTimes = await recordPollTimes(page);
    const swappedAtMs = await pressSummarize(page, key);

    const renderedAtMs = await page.evaluate(() => Date.now());
    await page.clock.runFor(swappedAtMs + POLL_INTERVAL_MS - 1 - renderedAtMs);
    expect(await pollTimes()).toEqual([]);
    await page.clock.runFor(1);
    await expect.poll(() => polls.length).toBe(1);
    await expect(page.locator(ASK_FRAME)).toHaveAttribute('data-answered-polls', '1');
    expect(await pollTimes()).toEqual([swappedAtMs + POLL_INTERVAL_MS]);
    expect(polls[0].headers()['turbo-frame']).toBe('ticket-ask');
    await expect(page.locator(`${ASK_FRAME} .ticket__summarize`)).toHaveText('Summarizing…');

    await nextPoll(page, polls, 2);
    expect(await pollTimes()).toEqual([swappedAtMs + POLL_INTERVAL_MS, swappedAtMs + 2 * POLL_INTERVAL_MS]);
    await expect(page.locator(`${ASK_FRAME} .ticket__ask-summary-text`)).toHaveText(SUMMARY_TEXT);
    await page.clock.runFor(TOAST_ANNOUNCE_DELAY_MS);
    await expect(page.locator(TOAST_REGION)).toHaveText('Summary saved for everyone.');

    await page.clock.runFor(3 * POLL_INTERVAL_MS);
    expect(await pollTimes()).toHaveLength(2);
    expect(await seriousViolations(page)).toEqual([]);
  });

  test('a failed request shows its line and the button again, with no toast', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Summary poll shows a failure');
    const polls = await answerPressAndPolls(page, key, ['failed']);
    await openTicketWithPausedClock(page, key);
    const pollTimes = await recordPollTimes(page);
    await pressSummarize(page, key);

    await nextPoll(page, polls, 1);

    await expect(page.locator(`${ASK_FRAME} .ticket__ask-failed`)).toHaveText(FAILED_LINE);
    await expect(page.locator(ASK_FRAME).getByRole('button', { name: 'Summarize with AI' })).toBeEnabled();
    await page.clock.runFor(TOAST_ANNOUNCE_DELAY_MS + POLL_INTERVAL_MS);
    await expect(page.locator(TOAST_REGION)).toHaveText('');
    expect(await pollTimes()).toHaveLength(1);
    expect(await seriousViolations(page)).toEqual([]);
  });

  test('a keyboard press keeps the focus in The ask through the running frame and the summary', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Summary poll keeps the focus');
    const appFrameAnswer = await page.request.get(`/t/${key}/summary`, { headers: { 'Turbo-Frame': 'ticket-ask' } });
    expect(appFrameAnswer.status()).toBe(200);
    expect(await appFrameAnswer.text()).toMatch(/<div class="ticket__ask-state" tabindex="-1" autofocus\s/);
    const polls = await answerPressAndPolls(page, key, ['done']);
    await openTicketWithPausedClock(page, key);
    const askState = page.locator(ASK_STATE);
    await expect(askState).not.toHaveAttribute('autofocus');
    expect(await isFocusInsideTheAsk(page)).toBe(false);

    await pressSummarize(page, key, pressButtonWithEnter);
    await expect(askState).toHaveAttribute('data-summary-poll-state-value', 'running');
    await expect(askState).toBeFocused();

    await nextPoll(page, polls, 1);
    await expect(askState).toHaveAttribute('data-summary-poll-state-value', 'done');
    await expect(askState).toBeFocused();
    await expect(askState).not.toHaveAttribute('autofocus');
  });

  test('a summary that arrives while the focus is elsewhere on the page leaves the focus there', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Summary poll leaves the focus alone');
    const polls = await answerPressAndPolls(page, key, ['done']);
    await openTicketWithPausedClock(page, key);
    await pressSummarize(page, key, pressButtonWithEnter);
    await expect(page.locator(ASK_STATE)).toBeFocused();

    await page.keyboard.press('Shift+Tab');
    const focusedOutside = await page.evaluateHandle(() => document.activeElement);
    expect(await isFocusInsideTheAsk(page)).toBe(false);
    expect(await page.evaluate((element) => element !== null && element !== document.body, focusedOutside)).toBe(true);

    await nextPoll(page, polls, 1);
    await expect(page.locator(`${ASK_FRAME} .ticket__ask-summary-text`)).toHaveText(SUMMARY_TEXT);
    expect(await page.evaluate((element) => element === document.activeElement, focusedOutside)).toBe(true);
  });

  test('a hidden page sends no polls and goes on polling when it shows again', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Summary poll waits while hidden');
    const polls = await answerPressAndPolls(page, key, ['running']);
    await openTicketWithPausedClock(page, key);
    const pollTimes = await recordPollTimes(page);
    await pressSummarize(page, key);

    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.clock.runFor(3 * POLL_INTERVAL_MS);
    expect(await pollTimes()).toEqual([]);

    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await nextPoll(page, polls, 1);
  });

  test('after 60 polls that still say running the button is offered again, and polling stops', async ({ browser, page }) => {
    test.slow();
    const key = await makeTicketWithAnAsk(browser, 'Summary poll gives up');
    const polls = await answerPressAndPolls(page, key, ['running']);
    await openTicketWithPausedClock(page, key);
    const pollTimes = await recordPollTimes(page);
    await pressSummarize(page, key);

    for (let poll = 1; poll <= MAX_POLLS; poll += 1) {
      await nextPoll(page, polls, poll);
    }

    const button = page.locator(ASK_FRAME).getByRole('button', { name: 'Summarize with AI' });
    await expect(button).toBeEnabled();
    await expect(button).not.toHaveAttribute('aria-busy', 'true');
    await page.clock.runFor(3 * POLL_INTERVAL_MS);
    expect(await pollTimes()).toHaveLength(MAX_POLLS);
  });
});

test.describe('"Summarize with AI" when the admin turned AI help off', () => {
  test.use({ storageState: OMAR_STORAGE_STATE });
  useAiSettingsInEachTest(AI_OFF);

  test('the person sees why, and no button to press', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Summary with AI turned off');
    await page.clock.install({ time: FROZEN_AT });
    await page.goto(`/t/${key}`);

    const ask = page.locator(ASK_FRAME);
    await expect(ask.locator('.ticket__ask-text')).toHaveText('Pressing pay on the last step does nothing.');
    await expect(ask.locator('.ticket__ask-ai-off')).toHaveText('Your admin turned AI help off.');
    await expect(ask.getByRole('button', { name: 'Summarize with AI' })).toHaveCount(0);
  });
});
