import { expect, test, type Browser, type Page, type Request, type Route } from '@playwright/test';
import { readAiSettings, restoreAiSettings, storeAiSettings, type StoredAiSettings } from './ai-settings';
import { OMAR_STORAGE_STATE } from './auth';
import { runAsDatabaseOwner } from './database';
import { makeTicketForOmar, TOAST_REGION } from './ticket';

// The summary frame's poll (summary-poll_controller.js). The press and every poll are answered here, so no job reaches
// the shared async_ai queue and the spec needs no AI provider; the answers carry the markup TheAsk renders.

/** summary-poll_controller.js POLL_INTERVAL_MS and MAX_POLLS. */
const POLL_INTERVAL_MS = 3000;
const MAX_POLLS = 60;
/** toast_controller.js ANNOUNCE_DELAY_MS. */
const TOAST_ANNOUNCE_DELAY_MS = 150;

const ORGANIZATION_SLUG = 'acme-foods';
const CLOUD_AI = { on: true, who: 'everyone', where: 'cloud' };
const SUMMARY_TEXT = 'The pay button does nothing on the last checkout step.';
const FAILED_LINE = 'The AI answer failed. Try again.';

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
  return `<turbo-frame id="ticket-ask" aria-live="polite"><div class="ticket__ask-state"
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

/** Opens the ticket, stops the page's clock and presses "Summarize with AI"; from here only runFor moves the polls. */
async function pressSummarize(page: Page, key: string): Promise<void> {
  await page.clock.install({ time: FROZEN_AT });
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await page.clock.pauseAt(new Date(FROZEN_AT.getTime() + 60_000));
  const pressed = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === `/t/${key}/summary`);
  await page.locator('#ticket-ask').getByRole('button', { name: 'Summarize with AI' }).click();
  await pressed;
  await expect(page.locator('#ticket-ask .ticket__summarize')).toHaveText('Summarizing…');
  await expect(page.locator('#ticket-ask .ticket__summarize')).toBeDisabled();
}

async function nextPoll(page: Page, polls: Request[], expectedCount: number): Promise<void> {
  await page.clock.runFor(POLL_INTERVAL_MS);
  await expect.poll(() => polls.length).toBe(expectedCount);
}

test.describe('"Summarize with AI" while the summary is on its way', () => {
  let previousAiSettings: StoredAiSettings | null = null;

  test.use({ storageState: OMAR_STORAGE_STATE });

  test.beforeAll(() => {
    previousAiSettings = readAiSettings(ORGANIZATION_SLUG);
    storeAiSettings(ORGANIZATION_SLUG, CLOUD_AI);
  });

  test.afterAll(() => {
    if (null !== previousAiSettings) {
      restoreAiSettings(ORGANIZATION_SLUG, previousAiSettings);
    }
  });

  test('polls every 3 seconds, shows the summary with its toast when it arrives, then polls no more', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Summary poll shows the summary');
    const polls = await answerPressAndPolls(page, key, ['running', 'done']);
    await pressSummarize(page, key);

    await page.clock.runFor(POLL_INTERVAL_MS - 1);
    expect(polls).toHaveLength(0);
    await nextPoll(page, polls, 1);
    expect(polls[0].headers()['turbo-frame']).toBe('ticket-ask');
    await expect(page.locator('#ticket-ask .ticket__summarize')).toHaveText('Summarizing…');

    await nextPoll(page, polls, 2);
    await expect(page.locator('#ticket-ask .ticket__ask-summary-text')).toHaveText(SUMMARY_TEXT);
    await page.clock.runFor(TOAST_ANNOUNCE_DELAY_MS);
    await expect(page.locator(TOAST_REGION)).toHaveText('Summary saved for everyone.');

    await page.clock.runFor(3 * POLL_INTERVAL_MS);
    expect(polls).toHaveLength(2);
  });

  test('a failed request shows its line and the button again, with no toast', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Summary poll shows a failure');
    const polls = await answerPressAndPolls(page, key, ['failed']);
    await pressSummarize(page, key);

    await nextPoll(page, polls, 1);

    await expect(page.locator('#ticket-ask .ticket__ask-failed')).toHaveText(FAILED_LINE);
    await expect(page.locator('#ticket-ask').getByRole('button', { name: 'Summarize with AI' })).toBeEnabled();
    await page.clock.runFor(TOAST_ANNOUNCE_DELAY_MS + POLL_INTERVAL_MS);
    await expect(page.locator(TOAST_REGION)).toHaveText('');
    expect(polls).toHaveLength(1);
  });

  test('a hidden page sends no polls and goes on polling when it shows again', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Summary poll waits while hidden');
    const polls = await answerPressAndPolls(page, key, ['running']);
    await pressSummarize(page, key);

    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.clock.runFor(3 * POLL_INTERVAL_MS);
    expect(polls).toHaveLength(0);

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
    await pressSummarize(page, key);

    for (let poll = 1; poll <= MAX_POLLS; poll += 1) {
      await nextPoll(page, polls, poll);
    }

    const button = page.locator('#ticket-ask').getByRole('button', { name: 'Summarize with AI' });
    await expect(button).toBeEnabled();
    await expect(button).not.toHaveAttribute('aria-busy', 'true');
    await page.clock.runFor(3 * POLL_INTERVAL_MS);
    expect(polls).toHaveLength(MAX_POLLS);
  });
});
