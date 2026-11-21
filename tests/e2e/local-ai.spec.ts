import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page, type Response, type Route } from '@playwright/test';
import { BEN_STORAGE_STATE, LENA_STORAGE_STATE } from './auth';
import { readAiSettings, restoreAiSettings, storeAiSettings, type StoredAiSettings } from './ai-settings';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

/** LocalAiChip::OLLAMA_ORIGIN. */
const OLLAMA_ORIGIN = 'http://localhost:11434';
const OLLAMA_TAGS_URL = `${OLLAMA_ORIGIN}/api/tags`;

/** local_ai_controller.js CHECK_TIMEOUT_MS. */
const CHECK_TIMEOUT_MS = 3000;

// Ben's own organization, so the chip this spec turns on never shows in the Acme Foods pages other specs compare with a
// screenshot. He leads Ovens, so he is not an admin with no team, whom the chip leaves out.
const ORGANIZATION_SLUG = 'beta-bakery';
const LAPTOP_AI = { on: true, where: 'laptop' };

// A day that is not today, so nothing here leans on the real clock.
const FROZEN_AT = new Date('2026-03-04T09:00:00Z');

/** One entry of Ollama's GET /api/tags, as Ollama answers it. */
function installedModel(name: string, family: string, parameterSize: string) {
  return {
    name,
    model: name,
    modified_at: '2026-02-20T10:15:00.000000000+01:00',
    size: 2_497_293_918,
    digest: 'a3de86cd1c132c822487ededd47a324c50491393e6565cd14bafa40d0b8e686f',
    details: { parent_model: '', format: 'gguf', family, families: [family], parameter_size: parameterSize, quantization_level: 'Q4_K_M' },
  };
}

// phi4 comes first so a controller that takes the first model, not the first qwen3, fails.
const PHI4_MINI = installedModel('phi4-mini:latest', 'phi3', '3.8B');
const QWEN3_4B = installedModel('qwen3:4b', 'qwen3', '4.0B');
const GEMMA3_4B = installedModel('gemma3:4b', 'gemma3', '4.3B');
const LLAMA3_2_3B = installedModel('llama3.2:3b', 'llama', '3.2B');

function recordOllamaRequests(page: Page): string[] {
  const ollamaRequests: string[] = [];
  page.on('request', (request) => {
    if (request.url().startsWith(OLLAMA_ORIGIN)) {
      ollamaRequests.push(request.url());
    }
  });
  return ollamaRequests;
}

/** Answers as Ollama does with OLLAMA_ORIGINS set to the app's address. */
async function answerWithModels(route: Route, page: Page, models: object[]): Promise<void> {
  await route.fulfill({
    status: 200,
    contentType: 'application/json; charset=utf-8',
    headers: { 'access-control-allow-origin': new URL(page.url()).origin },
    body: JSON.stringify({ models }),
  });
}

/** The bodies of the POST /ai/laptop/status requests the page sends, in order. */
function recordStatusReports(page: Page): unknown[] {
  const statusReports: unknown[] = [];
  page.on('request', (request) => {
    if (request.method() === 'POST' && new URL(request.url()).pathname === '/ai/laptop/status') {
      statusReports.push(request.postDataJSON());
    }
  });
  return statusReports;
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

/** How far the page is wider than the window; above 0 means it scrolls sideways. */
async function sidewaysOverflowPx(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

async function openHome(page: Page): Promise<void> {
  await openPage(page, '/');
}

async function openPage(page: Page, path: string): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto(path, { waitUntil: 'networkidle' });
}

function mainMenuLink(page: Page, name: string): Locator {
  return page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name, exact: true });
}

async function openSheet(page: Page): Promise<Locator> {
  await page.getByRole('banner').getByRole('button', { name: /^Local AI: / }).click();
  const sheet = page.getByRole('dialog', { name: 'AI on your laptop' });
  await expect(sheet).toBeVisible();
  return sheet;
}

async function checkAgain(sheet: Locator): Promise<void> {
  await sheet.getByRole('button', { name: 'Check again' }).click();
}

function modelChoices(sheet: Locator): Locator {
  return sheet.getByRole('group', { name: 'Model' }).getByRole('button');
}
/** The chip's one shown state line; strict, so two shown lines fail too. */
function chipState(page: Page): Locator {
  return page.locator('.local-ai__chip [data-states]:not([hidden])');
}

/** The "AI" a phone shows on the chip in place of the dot and the state line. */
function chipMark(page: Page): Locator {
  return page.locator('.local-ai__chip .local-ai__mark');
}

/** The sign after the mark's "AI", as CSS gives it, quotes included. */
async function chipSign(page: Page): Promise<string> {
  return chipMark(page).evaluate((mark) => getComputedStyle(mark, '::after').content);
}

function statusLine(sheet: Locator): Locator {
  return sheet.getByRole('status');
}

function isStatusReport(response: Response): boolean {
  return response.request().method() === 'POST' && new URL(response.url()).pathname === '/ai/laptop/status';
}

test.describe('a person whose organization runs AI on laptops', () => {
  let previousAiSettings: StoredAiSettings | null = null;

  test.use({ storageState: BEN_STORAGE_STATE });

  test.beforeAll(() => {
    previousAiSettings = readAiSettings(ORGANIZATION_SLUG);
    storeAiSettings(ORGANIZATION_SLUG, LAPTOP_AI);
  });

  test.afterAll(() => {
    if (null !== previousAiSettings) {
      restoreAiSettings(ORGANIZATION_SLUG, previousAiSettings);
    }
  });

  test.beforeEach(async ({ page }) => {
    await page.clock.install({ time: FROZEN_AT });
  });

  test('the page and the sheet call no Ollama until Check again, then a laptop with two models reads ready with qwen3', async ({ page }) => {
    const ollamaRequests = recordOllamaRequests(page);
    await page.route(OLLAMA_TAGS_URL, (route) => answerWithModels(route, page, [PHI4_MINI, QWEN3_4B]));
    await openHome(page);
    await expect(chipState(page)).toHaveText('Local AI: not checked');
    expect(ollamaRequests).toEqual([]);

    const sheet = await openSheet(page);
    await expect(statusLine(sheet)).toHaveText('Not checked yet.', { useInnerText: true });
    expect(ollamaRequests).toEqual([]);

    const statusReport = page.waitForResponse(isStatusReport);
    await sheet.getByRole('button', { name: 'Check again' }).click();

    await expect(statusLine(sheet)).toHaveText('Ollama runs on this laptop. pinround uses qwen3:4b. Ticket text stays on this laptop.', { useInnerText: true });
    await expect(chipState(page)).toHaveText('Local AI: ready');
    await expect(modelChoices(sheet)).toHaveText(['phi4-mini:latest', 'qwen3:4b']);
    await expect(modelChoices(sheet).filter({ hasText: 'qwen3:4b' })).toHaveAttribute('aria-pressed', 'true');
    await expect(modelChoices(sheet).filter({ hasText: 'phi4-mini:latest' })).toHaveAttribute('aria-pressed', 'false');
    const report = await statusReport;
    expect(report.status()).toBe(204);
    expect(report.request().postDataJSON()).toEqual({ ready: true, model: 'qwen3:4b' });
    expect(ollamaRequests).toEqual([OLLAMA_TAGS_URL]);
  });

  test('Ollama with no model reads not found on the chip and asks for step 2 in the sheet', async ({ page }) => {
    await page.route(OLLAMA_TAGS_URL, (route) => answerWithModels(route, page, []));
    await openHome(page);
    const sheet = await openSheet(page);

    const statusReport = page.waitForResponse(isStatusReport);
    await sheet.getByRole('button', { name: 'Check again' }).click();

    await expect(statusLine(sheet)).toHaveText('Ollama runs, but it has no model yet. Do step 2.', { useInnerText: true });
    await expect(chipState(page)).toHaveText('Local AI: not found');
    await expect(sheet.getByRole('group', { name: 'Model' })).toBeHidden();
    const report = await statusReport;
    expect(report.status()).toBe(204);
    expect(report.request().postDataJSON()).toEqual({ ready: false, model: null });
  });

  test('a laptop with no Ollama reads not found and throws no error on the page', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    const consoleErrors: string[] = [];
    page.on('console', (message) => {
      // Chromium logs every refused request as "Failed to load resource" at the request's URL; that one is expected.
      if (message.type() === 'error' && message.location().url !== OLLAMA_TAGS_URL) {
        consoleErrors.push(message.text());
      }
    });
    await page.route(OLLAMA_TAGS_URL, (route) => route.abort('connectionrefused'));
    await openHome(page);
    const sheet = await openSheet(page);

    const statusReport = page.waitForResponse(isStatusReport);
    await sheet.getByRole('button', { name: 'Check again' }).click();

    await expect(statusLine(sheet)).toHaveText('pinround could not reach Ollama on this laptop.', { useInnerText: true });
    await expect(chipState(page)).toHaveText('Local AI: not found');
    const report = await statusReport;
    expect(report.status()).toBe(204);
    expect(report.request().postDataJSON()).toEqual({ ready: false, model: null });
    expect(pageErrors).toEqual([]);
    expect(consoleErrors).toEqual([]);
  });

  test('Ollama that does not answer within 3 seconds reads not found, and not a moment before', async ({ page }) => {
    const heldRequests: Route[] = [];
    await page.route(OLLAMA_TAGS_URL, (route) => {
      heldRequests.push(route);
    });
    await openHome(page);
    const sheet = await openSheet(page);
    // From here only runFor moves the page's timers, so the check's timeout fires exactly when the test says.
    await page.clock.pauseAt(new Date(FROZEN_AT.getTime() + 60_000));

    try {
      const statusReport = page.waitForResponse(isStatusReport);
      await sheet.getByRole('button', { name: 'Check again' }).click();
      await expect.poll(() => heldRequests.length).toBe(1);
      await expect(statusLine(sheet)).toHaveText('Looking for Ollama on this laptop…', { useInnerText: true });
      await expect(chipState(page)).toHaveText('Local AI: checking…');
      await expect(sheet.getByRole('button', { name: 'Check again' })).toBeDisabled();

      await page.clock.runFor(CHECK_TIMEOUT_MS - 1);
      await expect(chipState(page)).toHaveText('Local AI: checking…');

      await page.clock.runFor(1);
      await expect(statusLine(sheet)).toHaveText('pinround could not reach Ollama on this laptop.', { useInnerText: true });
      await expect(chipState(page)).toHaveText('Local AI: not found');
      await expect(sheet.getByRole('button', { name: 'Check again' })).toBeEnabled();
      const report = await statusReport;
      expect(report.status()).toBe(204);
      expect(report.request().postDataJSON()).toEqual({ ready: false, model: null });
    } finally {
      for (const route of heldRequests) {
        // The page gave up on it already, so Playwright may refuse to answer it.
        await route.abort().catch(() => undefined);
      }
    }
  });

  test('going to another page keeps the chip ready with the same model and calls no Ollama', async ({ page }) => {
    const ollamaRequests = recordOllamaRequests(page);
    await page.route(OLLAMA_TAGS_URL, (route) => answerWithModels(route, page, [PHI4_MINI, QWEN3_4B]));
    await openHome(page);
    let sheet = await openSheet(page);
    await checkAgain(sheet);
    await expect(chipState(page)).toHaveText('Local AI: ready');
    await sheet.locator('.dialog__actions').getByRole('button', { name: 'Close' }).click();
    await expect(sheet).toBeHidden();
    expect(ollamaRequests).toEqual([OLLAMA_TAGS_URL]);

    await mainMenuLink(page, 'Tickets').click();

    await expect(page).toHaveURL(/\/tickets$/);
    await expect(mainMenuLink(page, 'Tickets')).toHaveAttribute('aria-current', 'page');
    await expect(chipState(page)).toHaveText('Local AI: ready');
    sheet = await openSheet(page);
    await expect(statusLine(sheet)).toHaveText('Ollama runs on this laptop. pinround uses qwen3:4b. Ticket text stays on this laptop.', { useInnerText: true });
    await expect(modelChoices(sheet)).toHaveText(['phi4-mini:latest', 'qwen3:4b']);
    await expect(modelChoices(sheet).filter({ hasText: 'qwen3:4b' })).toHaveAttribute('aria-pressed', 'true');
    expect(ollamaRequests).toEqual([OLLAMA_TAGS_URL]);
  });

  test('a browser that refuses storage still checks and picks, and the next page reads not checked', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    await page.addInitScript(() => {
      // As a browser with site data blocked does: every call throws.
      for (const method of ['getItem', 'setItem', 'removeItem', 'clear', 'key'] as const) {
        Storage.prototype[method] = () => {
          throw new DOMException('The operation is insecure.', 'SecurityError');
        };
      }
    });
    await page.route(OLLAMA_TAGS_URL, (route) => answerWithModels(route, page, [PHI4_MINI, QWEN3_4B]));
    await openHome(page);
    await expect(chipState(page)).toHaveText('Local AI: not checked');
    let sheet = await openSheet(page);

    const checkReport = page.waitForResponse(isStatusReport);
    await checkAgain(sheet);
    expect((await checkReport).request().postDataJSON()).toEqual({ ready: true, model: 'qwen3:4b' });
    const choiceReport = page.waitForResponse(isStatusReport);
    await modelChoices(sheet).filter({ hasText: 'phi4-mini:latest' }).click();

    expect((await choiceReport).request().postDataJSON()).toEqual({ ready: true, model: 'phi4-mini:latest' });
    await expect(chipState(page)).toHaveText('Local AI: ready');
    await expect(modelChoices(sheet).filter({ hasText: 'phi4-mini:latest' })).toHaveAttribute('aria-pressed', 'true');

    await page.reload({ waitUntil: 'networkidle' });
    await expect(chipState(page)).toHaveText('Local AI: not checked');
    sheet = await openSheet(page);
    await expect(statusLine(sheet)).toHaveText('Not checked yet.', { useInnerText: true });
    expect(pageErrors).toEqual([]);
  });

  test('a stored last check that is not one a check gives reads not checked', async ({ page }) => {
    await page.addInitScript(() => {
      sessionStorage.setItem('pinround.localAiLastCheck', JSON.stringify({ state: 'ready', model: '<img src=x>', models: [] }));
    });
    await openHome(page);

    await expect(chipState(page)).toHaveText('Local AI: not checked');
    const sheet = await openSheet(page);
    await expect(statusLine(sheet)).toHaveText('Not checked yet.', { useInnerText: true });
  });

  test('a check cut short by leaving the page sends no status, and Back shows it not checked with Check again on', async ({ page }) => {
    const statusReports = recordStatusReports(page);
    const heldRequests: Route[] = [];
    await page.route(OLLAMA_TAGS_URL, (route) => {
      heldRequests.push(route);
    });
    // Not the home page, which tells the browser never to keep it, so Back may show the list from the browser's cache.
    await openPage(page, '/tickets');

    try {
      const sheet = await openSheet(page);
      await checkAgain(sheet);
      await expect.poll(() => heldRequests.length).toBe(1);
      await expect(chipState(page)).toHaveText('Local AI: checking…');
      await sheet.locator('.dialog__actions').getByRole('button', { name: 'Close' }).click();
      await expect(sheet).toBeHidden();

      await mainMenuLink(page, 'Home').click();
      await expect(page).toHaveURL(/\/$/);
      await expect(mainMenuLink(page, 'Home')).toHaveAttribute('aria-current', 'page');
      // A Back sent while Home still loads makes goBack return before the list has loaded again.
      await page.waitForLoadState('networkidle');
      // Drive is off, so Back loads the list anew: its chip reads "not checked" from the server before the dialog
      // controller is connected to open the sheet on a click.
      await page.goBack({ waitUntil: 'networkidle' });

      await expect(page).toHaveURL(/\/tickets$/);
      await expect(mainMenuLink(page, 'Tickets')).toHaveAttribute('aria-current', 'page');
      await expect(chipState(page)).toHaveText('Local AI: not checked');
      const restoredSheet = await openSheet(page);
      await expect(statusLine(restoredSheet)).toHaveText('Not checked yet.', { useInnerText: true });
      await expect(restoredSheet.getByRole('button', { name: 'Check again' })).toBeEnabled();
      expect(statusReports).toEqual([]);
    } finally {
      for (const route of heldRequests) {
        // The page gave up on it already, so Playwright may refuse to answer it.
        await route.abort().catch(() => undefined);
      }
    }
  });

  test('a picked model moves the choice, is reported and is picked again after a reload over qwen3', async ({ page }) => {
    const ollamaRequests = recordOllamaRequests(page);
    await page.route(OLLAMA_TAGS_URL, (route) => answerWithModels(route, page, [PHI4_MINI, QWEN3_4B]));
    await openHome(page);
    let sheet = await openSheet(page);
    const firstCheckReport = page.waitForResponse(isStatusReport);
    await checkAgain(sheet);
    expect((await firstCheckReport).request().postDataJSON()).toEqual({ ready: true, model: 'qwen3:4b' });
    await expect(modelChoices(sheet).filter({ hasText: 'qwen3:4b' })).toHaveAttribute('aria-pressed', 'true');

    const choiceReport = page.waitForResponse(isStatusReport);
    await modelChoices(sheet).filter({ hasText: 'phi4-mini:latest' }).click();

    await expect(modelChoices(sheet).filter({ hasText: 'phi4-mini:latest' })).toHaveAttribute('aria-pressed', 'true');
    await expect(modelChoices(sheet).filter({ hasText: 'qwen3:4b' })).toHaveAttribute('aria-pressed', 'false');
    await expect(statusLine(sheet)).toHaveText('Ollama runs on this laptop. pinround uses phi4-mini:latest. Ticket text stays on this laptop.', { useInnerText: true });
    const report = await choiceReport;
    expect(report.status()).toBe(204);
    expect(report.request().postDataJSON()).toEqual({ ready: true, model: 'phi4-mini:latest' });

    await page.reload({ waitUntil: 'networkidle' });
    sheet = await openSheet(page);
    const checkReport = page.waitForResponse(isStatusReport);
    await checkAgain(sheet);

    expect((await checkReport).request().postDataJSON()).toEqual({ ready: true, model: 'phi4-mini:latest' });
    await expect(modelChoices(sheet).filter({ hasText: 'phi4-mini:latest' })).toHaveAttribute('aria-pressed', 'true');
    await expect(modelChoices(sheet).filter({ hasText: 'qwen3:4b' })).toHaveAttribute('aria-pressed', 'false');
    expect(ollamaRequests).toEqual([OLLAMA_TAGS_URL, OLLAMA_TAGS_URL]);
  });

  test('with no qwen3 the check picks the first phi4 or gemma model, not the first model', async ({ page }) => {
    // gemma comes before phi4, so a controller that prefers phi4 over gemma fails too.
    await page.route(OLLAMA_TAGS_URL, (route) => answerWithModels(route, page, [LLAMA3_2_3B, GEMMA3_4B, PHI4_MINI]));
    await openHome(page);
    const sheet = await openSheet(page);

    const statusReport = page.waitForResponse(isStatusReport);
    await checkAgain(sheet);

    expect((await statusReport).request().postDataJSON()).toEqual({ ready: true, model: 'gemma3:4b' });
    await expect(modelChoices(sheet)).toHaveText(['llama3.2:3b', 'gemma3:4b', 'phi4-mini:latest']);
    await expect(modelChoices(sheet).filter({ hasText: 'gemma3:4b' })).toHaveAttribute('aria-pressed', 'true');
    await expect(statusLine(sheet)).toHaveText('Ollama runs on this laptop. pinround uses gemma3:4b. Ticket text stays on this laptop.', { useInnerText: true });
  });

  test('the open sheet in the ready state has no serious or critical accessibility violations', async ({ page }) => {
    await page.route(OLLAMA_TAGS_URL, (route) => answerWithModels(route, page, [PHI4_MINI, QWEN3_4B]));
    await openHome(page);
    const sheet = await openSheet(page);
    await checkAgain(sheet);
    await expect(modelChoices(sheet)).toHaveCount(2);

    expect(await seriousViolations(page)).toEqual([]);
  });

  test.describe('at 360 px', () => {
    test.use({ viewport: { width: 360, height: 800 } });

    test('neither the chip in the header nor the open ready sheet makes the page scroll sideways', async ({ page }) => {
      await page.route(OLLAMA_TAGS_URL, (route) => answerWithModels(route, page, [PHI4_MINI, QWEN3_4B]));
      await openHome(page);
      await expect(chipState(page)).toBeVisible();

      expect(await sidewaysOverflowPx(page), 'with the chip in the header').toBeLessThanOrEqual(0);
      // The chip wraps the header to a second row here; a focused item must still clear it (base.css, WCAG 2.4.11).
      const headerHeightPx = (await page.locator('.site-header').boundingBox())!.height;
      const scrollPaddingTopPx = await page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop));
      expect(scrollPaddingTopPx).toBeGreaterThan(0);
      expect(headerHeightPx, 'the header with the chip').toBeLessThanOrEqual(scrollPaddingTopPx);

      const sheet = await openSheet(page);
      await checkAgain(sheet);
      await expect(modelChoices(sheet)).toHaveCount(2);
      expect(await sidewaysOverflowPx(page), 'with the ready sheet open').toBeLessThanOrEqual(0);
    });

    test('the chip shows "AI" and a sign of each state, so its colour is not the only signal', async ({ page }) => {
      const heldRequests: Route[] = [];
      await page.route(OLLAMA_TAGS_URL, (route) => {
        heldRequests.push(route);
      });
      await openHome(page);

      await expect(chipMark(page)).toBeVisible();
      await expect(chipMark(page)).toHaveText('AI');
      await expect(page.locator('.local-ai__chip .local-ai__dot')).toBeHidden();
      // The mark is aria-hidden: the button's name stays the full state line.
      await expect(page.getByRole('banner').getByRole('button', { name: 'Local AI: not checked', exact: true })).toBeVisible();
      expect(await chipSign(page), 'not checked').toBe('"?"');

      const sheet = await openSheet(page);
      // From here no timer runs, so the held check cannot time out into "not found".
      await page.clock.pauseAt(new Date(FROZEN_AT.getTime() + 60_000));
      await checkAgain(sheet);
      await expect.poll(() => heldRequests.length).toBe(1);
      await expect(chipState(page)).toHaveText('Local AI: checking…');
      expect(await chipSign(page), 'checking').toBe('"…"');

      await answerWithModels(heldRequests[0], page, [PHI4_MINI, QWEN3_4B]);
      await expect(chipState(page)).toHaveText('Local AI: ready');
      expect(await chipSign(page), 'ready').toBe('"✓"');

      await checkAgain(sheet);
      await expect.poll(() => heldRequests.length).toBe(2);
      await heldRequests[1].abort('connectionrefused');
      await expect(statusLine(sheet)).toHaveText('pinround could not reach Ollama on this laptop.', { useInnerText: true });
      await expect(chipState(page)).toHaveText('Local AI: not found');
      expect(await chipSign(page), 'not found').toBe('"!"');

      // Ollama answers, but with no model whose name pinround can use: the no-model state, which shows the same sign.
      await checkAgain(sheet);
      await expect.poll(() => heldRequests.length).toBe(3);
      await answerWithModels(heldRequests[2], page, [installedModel('My Model:latest', 'llama', '3.2B')]);
      await expect(statusLine(sheet)).toHaveText('Ollama runs, but it has no model yet. Do step 2.', { useInnerText: true });
      await expect(chipState(page)).toHaveText('Local AI: not found');
      expect(await chipSign(page), 'no model').toBe('"!"');
    });
  });

  test('from 600 px the chip shows its dot and its state line, not the phone\'s "AI" mark', async ({ page }) => {
    await openHome(page);

    await expect(page.locator('.local-ai__chip .local-ai__dot')).toBeVisible();
    await expect(chipMark(page)).toBeHidden();
    await expect(chipState(page)).toHaveText('Local AI: not checked');
  });
});

test.describe('a person whose organization does not run AI on laptops', () => {
  // The demo seed gives Acme Foods no AI settings, so its AI help is off.
  test.use({ storageState: LENA_STORAGE_STATE });

  test('sees no Local AI chip and the page calls no Ollama', async ({ page }) => {
    const ollamaRequests = recordOllamaRequests(page);
    await openHome(page);

    await expect(page.getByRole('banner').getByRole('button', { name: 'Lena', exact: true })).toBeVisible();
    await expect(page.locator('.local-ai')).toHaveCount(0);
    expect(ollamaRequests).toEqual([]);
  });
});
