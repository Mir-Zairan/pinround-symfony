import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Locator, type Page, type Route } from '@playwright/test';
import { readAiSettings, restoreAiSettings, storeAiSettings, type StoredAiSettings } from './ai-settings';
import { LENA_STORAGE_STATE } from './auth';
import { runAsDatabaseOwner } from './database';
import { makeTicketForOmar, TOAST_REGION } from './ticket';

// "Summarize with AI" on the laptop (laptop_summary_controller.js): the package and result POSTs reach the app, Ollama
// is answered here. Lena made each ticket, so she is its reporter and may make the laptop summary the main one.

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

const ORGANIZATION_SLUG = 'acme-foods';
const LAPTOP_ONLY_AI = { on: true, who: 'everyone', where: 'laptop' };
const LAPTOP_FIRST_AI = { on: true, who: 'everyone', where: 'laptop_first' };

/** LocalAiChip::OLLAMA_ORIGIN. */
const OLLAMA_ORIGIN = 'http://localhost:11434';
const OLLAMA_TAGS_URL = `${OLLAMA_ORIGIN}/api/tags`;
const OLLAMA_GENERATE_URL = `${OLLAMA_ORIGIN}/api/generate`;
const MODEL = 'qwen3:4b';

const ASK_TEXT = 'Pressing pay on the last checkout step does nothing, and the cart stays full.';
// Markup in the answer must show as text: the proposal is user content.
const LAPTOP_SUMMARY = 'The <b>Pay</b> button does nothing on the last step & the cart stays full.';

/** PolicyRefusal::LaptopNotReady. */
const NOT_READY_LINE = 'Your company runs AI on each laptop, and it is not running on yours. Use “Local AI” at the top to set it up.';
/** laptop_run.js. */
const RUNNING_LINE = 'Running on this laptop. Ticket text stays on this laptop.';
const UNREACHABLE_LINE = 'pinround could not reach Ollama on this laptop. Use “Local AI” at the top to set it up.';
const NO_SUMMARY_LINE = 'Ollama on this laptop gave no summary. Use “Local AI” at the top to check it.';
/** LaptopController::TOO_MANY_REQUESTS. */
const TOO_MANY_LINE = 'You sent too many laptop AI requests. Wait a minute and try again.';
/** SummaryController::MADE_MAIN_SUMMARY. */
const MADE_MAIN_TOAST = 'Now the main summary. The old one is kept in history.';

// A day that is not today, so nothing here leans on the real clock.
const FROZEN_AT = new Date('2026-03-04T09:00:00Z');

const madeTicketKeys: string[] = [];

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

async function makeTicketWithAnAsk(browser: Browser, title: string): Promise<string> {
  const key = await makeTicketForOmar(browser, title);
  madeTicketKeys.push(key);
  runAsDatabaseOwner(`UPDATE tkt_ticket SET description = '${ASK_TEXT}'
    WHERE key = '${key}' AND organization_id = (SELECT organization_id FROM org_organization WHERE slug = '${ORGANIZATION_SLUG}')`);
  return key;
}

/** As the chip leaves it after a check that found Ollama with one model. */
async function seedReadyLaptop(page: Page): Promise<void> {
  await page.addInitScript((model) => {
    sessionStorage.setItem('pinround.localAiLastCheck', JSON.stringify({ state: 'ready', model, models: [model] }));
  }, MODEL);
}

/** Answers as Ollama does with OLLAMA_ORIGINS set to the app's address. */
async function answerAsOllama(route: Route, page: Page, body: object): Promise<void> {
  await route.fulfill({
    status: 200,
    contentType: 'application/json; charset=utf-8',
    headers: { 'access-control-allow-origin': new URL(page.url()).origin },
    body: JSON.stringify(body),
  });
}

function generateAnswer(response: string): object {
  return { model: MODEL, created_at: '2026-03-04T09:01:00.000000000Z', response, done: true, done_reason: 'stop' };
}

/** The bodies of the POST /api/generate requests the page sent to Ollama, in order. */
async function answerGenerateWith(page: Page, response: string, beforeAnswer: Promise<void> = Promise.resolve()): Promise<unknown[]> {
  const generateBodies: unknown[] = [];
  await page.route(OLLAMA_GENERATE_URL, async (route) => {
    generateBodies.push(route.request().postDataJSON());
    await beforeAnswer;
    await answerAsOllama(route, page, generateAnswer(response));
  });
  return generateBodies;
}

function recordPathsPosted(page: Page): string[] {
  const paths: string[] = [];
  page.on('request', (request) => {
    if (request.method() === 'POST') {
      paths.push(new URL(request.url()).pathname);
    }
  });
  return paths;
}

/** What the page wrote to the console; the ticket text and the AI answer must never be in it. */
function recordConsole(page: Page): string[] {
  const messages: string[] = [];
  page.on('console', (message) => messages.push(message.text()));
  return messages;
}

async function openTicket(page: Page, key: string): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
}

function summarizeButton(page: Page): Locator {
  return page.getByRole('button', { name: 'Summarize with AI' });
}

function summarizeForm(page: Page): Locator {
  return page.locator('#ticket-ask form.ticket__ask-actions:has(.ticket__summarize)');
}

function notReadyLine(page: Page): Locator {
  return page.locator('#ticket-ask .ticket__ask-ai-off');
}

function laptopStatusLine(page: Page): Locator {
  return page.locator('#ticket-ask .ticket__ask-laptop-status');
}

function proposal(page: Page): Locator {
  return page.locator('#ticket-ask .ticket__ask-proposal');
}

async function seriousViolationsIn(page: Page, selector: string): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).include(selector).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test.use({ storageState: LENA_STORAGE_STATE });

test.beforeEach(async ({ page }) => {
  await page.clock.install({ time: FROZEN_AT });
});

// The packages the laptop runs here would count against Lena's open packages and her packages an hour on a rerun.
test.afterEach(() => {
  const keys = madeTicketKeys.splice(0).map((key) => `'${key}'`).join(', ');
  if (keys === '') {
    return;
  }
  runAsDatabaseOwner(`DELETE FROM ast_local_package WHERE ticket_id IN (SELECT id FROM tkt_ticket WHERE key IN (${keys})
    AND organization_id = (SELECT organization_id FROM org_organization WHERE slug = '${ORGANIZATION_SLUG}'))`);
});

test.describe('an organization whose AI runs only on laptops', () => {
  useAiSettingsInEachTest(LAPTOP_ONLY_AI);

  test('a ready laptop summarizes the ask, the summary shows as a proposal, and Lena makes it the main summary', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Pay button does nothing at checkout');
    await seedReadyLaptop(page);
    const consoleMessages = recordConsole(page);
    let answerOllama: () => void = () => undefined;
    const generateBodies = await answerGenerateWith(page, LAPTOP_SUMMARY, new Promise((resolve) => {
      answerOllama = resolve;
    }));
    await openTicket(page, key);

    await expect(notReadyLine(page)).toBeHidden();
    await expect(summarizeButton(page)).toBeVisible();
    await summarizeButton(page).click();

    await expect(summarizeForm(page).locator('.ticket__summarize')).toBeDisabled();
    await expect(summarizeForm(page).locator('.ticket__summarize')).toHaveText('Summarizing…');
    await expect(laptopStatusLine(page)).toHaveText(RUNNING_LINE);
    await expect.poll(() => generateBodies.length).toBe(1);
    expect(generateBodies[0]).toMatchObject({ model: MODEL, stream: false, think: false });
    const generateBody = generateBodies[0] as { system: unknown; prompt: unknown };
    expect(typeof generateBody.system === 'string' && generateBody.system !== '').toBe(true);
    expect(String(generateBody.prompt)).toContain(ASK_TEXT);
    answerOllama();

    await expect(proposal(page)).toBeVisible();
    await expect(proposal(page).locator('.ticket__ask-source strong')).toHaveText('Laptop summary');
    await expect(proposal(page).locator('.ticket__ask-proposal-label')).toHaveText(new RegExp(`^posted by Lena\\b.* using local model ${MODEL} \\(unverified\\)$`));
    await expect(proposal(page).locator('.ticket__ask-proposal-text')).toHaveText(LAPTOP_SUMMARY);
    await expect(proposal(page).locator('.ticket__ask-proposal-text b')).toHaveCount(0);
    // The result is only a proposal: the ask still has no summary.
    await expect(page.locator('#ticket-ask .ticket__ask--summary')).toHaveCount(0);
    expect(await seriousViolationsIn(page, '#ticket-ask .ticket__ask-proposal')).toEqual([]);

    await proposal(page).getByRole('button', { name: 'Use as main summary' }).click();

    await expect(page.locator(TOAST_REGION)).toHaveText(MADE_MAIN_TOAST);
    const summary = page.locator('#ticket-ask .ticket__ask--summary');
    await expect(summary.locator('.ticket__ask-summary-text')).toHaveText(LAPTOP_SUMMARY);
    await expect(summary.locator('.ticket__ask-note')).toContainText(`using local model ${MODEL} (unverified), made the main summary by Lena`);
    await expect(proposal(page)).toHaveCount(0);
    expect(consoleMessages.filter((message) => message.includes(ASK_TEXT) || message.includes('Pay</b>'))).toEqual([]);
  });

  test('Ollama that cannot be reached gets the sentence that points to Local AI, and the button back', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Cart empties after login');
    await seedReadyLaptop(page);
    await page.route(OLLAMA_GENERATE_URL, (route) => route.abort('connectionrefused'));
    await openTicket(page, key);

    await summarizeButton(page).click();

    await expect(laptopStatusLine(page)).toHaveText(UNREACHABLE_LINE);
    await expect(summarizeButton(page)).toBeEnabled();
    await expect(summarizeButton(page)).not.toHaveAttribute('aria-busy');
    await expect(summarizeButton(page)).toBeFocused();
    await expect(proposal(page)).toHaveCount(0);
  });

  test('a refused package shows its sentence without asking Ollama, and an answer that is only thinking says Ollama gave no summary', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Saved address is not offered');
    await seedReadyLaptop(page);
    // A refusal the app gives only past a limit, answered here with the shape LaptopController answers.
    await page.route('**/ai/laptop/package', (route) => route.fulfill({ status: 429, contentType: 'application/json', body: JSON.stringify({ message: TOO_MANY_LINE }) }));
    const generateBodies = await answerGenerateWith(page, '<think>The ask is about addresses.</think>\n');
    await openTicket(page, key);

    await summarizeButton(page).click();

    await expect(laptopStatusLine(page)).toHaveText(TOO_MANY_LINE);
    await expect(summarizeButton(page)).toBeEnabled();
    expect(generateBodies).toEqual([]);

    await page.unroute('**/ai/laptop/package');
    await summarizeButton(page).click();

    await expect(laptopStatusLine(page)).toHaveText(NO_SUMMARY_LINE);
    await expect(summarizeButton(page)).toBeEnabled();
    expect(generateBodies).toHaveLength(1);
    await expect(proposal(page)).toHaveCount(0);
  });

  test('a laptop that is not ready keeps the line and shows no button, and a check that finds it ready shows the button', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Coupon field rejects valid codes');
    const postedPaths = recordPathsPosted(page);
    await openTicket(page, key);

    await expect(notReadyLine(page)).toHaveText(NOT_READY_LINE);
    // The [hidden] form must really be invisible, whatever .ticket__ask-actions sets its display to.
    await expect(summarizeForm(page)).toBeHidden();
    await expect(summarizeButton(page)).toHaveCount(0);

    await page.route(OLLAMA_TAGS_URL, (route) => answerAsOllama(route, page, { models: [{ name: MODEL, model: MODEL }] }));
    await page.getByRole('banner').getByRole('button', { name: /^Local AI: / }).click();
    const sheet = page.getByRole('dialog', { name: 'AI on your laptop' });
    await sheet.getByRole('button', { name: 'Check again' }).click();
    await expect(sheet.getByRole('status')).toContainText(MODEL);
    await page.keyboard.press('Escape');

    await expect(notReadyLine(page)).toBeHidden();
    await expect(summarizeButton(page)).toBeVisible();
    expect(postedPaths.filter((path) => path.startsWith('/ai/laptop/package'))).toEqual([]);
  });
});

test.describe('an organization whose AI runs on laptops first, then in the cloud', () => {
  useAiSettingsInEachTest(LAPTOP_FIRST_AI);

  test('a ready laptop runs the press, and an answer that thinks first shows without its thinking', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Checkout spinner never stops');
    await seedReadyLaptop(page);
    const postedPaths = recordPathsPosted(page);
    await answerGenerateWith(page, `<think>The ask is about the pay button.</think>\n\n${LAPTOP_SUMMARY}`);
    await openTicket(page, key);

    await summarizeButton(page).click();

    await expect(proposal(page).locator('.ticket__ask-proposal-text')).toHaveText(LAPTOP_SUMMARY);
    await expect(proposal(page)).not.toContainText('The ask is about the pay button.');
    expect(postedPaths.filter((path) => path === `/t/${key}/summary`)).toEqual([]);
    expect(postedPaths.filter((path) => path.startsWith('/ai/laptop/'))).toEqual(['/ai/laptop/package', '/ai/laptop/result']);
  });

  test('a laptop that is not ready leaves the press to the cloud', async ({ browser, page }) => {
    const key = await makeTicketWithAnAsk(browser, 'Order total shows the wrong currency');
    const postedPaths = recordPathsPosted(page);
    // The press is answered here, so no job reaches the shared async_ai queue.
    await page.route(`**/t/${key}/summary`, async (route) => {
      if (route.request().method() !== 'POST') {
        await route.continue();
        return;
      }
      await route.fulfill({ status: 200, contentType: 'text/html; charset=UTF-8', body: `<turbo-frame id="ticket-ask"><div class="ticket__ask-state"><p class="ticket__ask-failed">The AI answer failed. Try again.</p></div></turbo-frame>` });
    });
    await openTicket(page, key);

    await expect(notReadyLine(page)).toHaveCount(0);
    await expect(summarizeButton(page)).toBeVisible();
    await summarizeButton(page).click();

    await expect(page.locator('#ticket-ask .ticket__ask-failed')).toHaveText('The AI answer failed. Try again.');
    expect(postedPaths.filter((path) => path === `/t/${key}/summary`)).toEqual([`/t/${key}/summary`]);
    expect(postedPaths.filter((path) => path.startsWith('/ai/laptop/package'))).toEqual([]);
  });
});
