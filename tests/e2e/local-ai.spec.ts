import { expect, test, type Locator, type Page, type Response, type Route } from '@playwright/test';
import { BEN_STORAGE_STATE, LENA_STORAGE_STATE } from './auth';
import { runAsDatabaseOwner } from './database';

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

/** The ai key of org_settings.data before this spec changed it: null when the key was missing. */
interface StoredAiSettings {
  hasSettingsRow: boolean;
  aiJson: string | null;
}

const organizationIdSql = `(SELECT organization_id FROM org_organization WHERE slug = '${ORGANIZATION_SLUG}')`;

function readAiSettings(): StoredAiSettings {
  const [row] = runAsDatabaseOwner(`
    SELECT s.organization_id IS NOT NULL, coalesce(s.data ? 'ai', false), coalesce((s.data->'ai')::text, '')
    FROM org_organization o LEFT JOIN org_settings s ON s.organization_id = o.organization_id
    WHERE o.slug = '${ORGANIZATION_SLUG}'`);
  if (undefined === row) {
    throw new Error(`The demo organization ${ORGANIZATION_SLUG} is missing: run make seed.`);
  }
  const [hasSettingsRow, hasAiKey, ...aiJsonParts] = row.split('|');
  return { hasSettingsRow: hasSettingsRow === 't', aiJson: hasAiKey === 't' ? aiJsonParts.join('|') : null };
}

function sqlText(text: string): string {
  return `'${text.replaceAll("'", "''")}'`;
}

function storeAiSettings(ai: object): void {
  runAsDatabaseOwner(`
    INSERT INTO org_settings (organization_id, data, created_at)
    SELECT organization_id, jsonb_build_object('ai', ${sqlText(JSON.stringify(ai))}::jsonb), TIMESTAMPTZ '2026-11-01 08:00:00+00'
    FROM org_organization WHERE slug = '${ORGANIZATION_SLUG}'
    ON CONFLICT (organization_id) DO UPDATE SET data = org_settings.data || EXCLUDED.data, version = org_settings.version + 1`);
}

/** Puts back only the ai key, so a setting another spec changed meanwhile stays. */
function restoreAiSettings(previous: StoredAiSettings): void {
  if (null !== previous.aiJson) {
    runAsDatabaseOwner(`UPDATE org_settings SET data = jsonb_set(data, '{ai}', ${sqlText(previous.aiJson)}::jsonb), version = version + 1
      WHERE organization_id = ${organizationIdSql}`);
    return;
  }
  runAsDatabaseOwner(`UPDATE org_settings SET data = data - 'ai', version = version + 1 WHERE organization_id = ${organizationIdSql}`
    + (previous.hasSettingsRow ? '' : `; DELETE FROM org_settings WHERE organization_id = ${organizationIdSql} AND data = '{}'::jsonb`));
}

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

async function openHome(page: Page): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/', { waitUntil: 'networkidle' });
}

async function openSheet(page: Page): Promise<Locator> {
  await page.getByRole('banner').getByRole('button', { name: 'Local AI: not checked' }).click();
  const sheet = page.getByRole('dialog', { name: 'AI on your laptop' });
  await expect(sheet).toBeVisible();
  return sheet;
}

/** The chip's one shown state line; strict, so two shown lines fail too. */
function chipState(page: Page): Locator {
  return page.locator('.local-ai__chip [data-states]:not([hidden])');
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
    previousAiSettings = readAiSettings();
    storeAiSettings(LAPTOP_AI);
  });

  test.afterAll(() => {
    if (null !== previousAiSettings) {
      restoreAiSettings(previousAiSettings);
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
    const modelChoices = sheet.getByRole('group', { name: 'Model' }).getByRole('button');
    await expect(modelChoices).toHaveText(['phi4-mini:latest', 'qwen3:4b']);
    await expect(modelChoices.filter({ hasText: 'qwen3:4b' })).toHaveAttribute('aria-pressed', 'true');
    await expect(modelChoices.filter({ hasText: 'phi4-mini:latest' })).toHaveAttribute('aria-pressed', 'false');
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
