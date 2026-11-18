import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { BEN_EMAIL, logInAndTurnOnTwoStepLogin, turnOffTwoStepLogin, twoStepCodesFile } from './auth';

// As Ben, the only demo person in Beta Bakery, because making a token needs two-step login on and every Acme Foods
// person with a spare session is taken: Mia, Raj and Sara have specs that turn it on and off, and Lena's and Omar's
// sessions are shared by many specs (two-step-setup.spec.ts replaces Omar's seed). None of Ben's other specs opens an
// admin page, the only page whose rules change with it on. beforeAll turns it on, which with the password login just
// before counts as the recent proof making a token asks for (10 minutes); afterAll turns it off with a recovery code.
// Turning it on keeps the session auth.setup.ts made for Ben's other specs. The functional TokenPageTest covers the
// refusals, the cap and the revoke of another person's token. Every test revokes the tokens it made.
const BEN_TOKENS_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'ben-tokens.json');
const PLAIN_TOKEN = /^pr_[A-Za-z0-9_-]{43}$/;
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// COPIED_SHOWN_FOR_MS in copy-text_controller.js.
const COPIED_SHOWN_FOR_MS = 2000;
const FROZEN_AT = new Date('2026-03-14T10:00:00Z');

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await logInAndTurnOnTwoStepLogin(await context.newPage(), BEN_EMAIL, 'Ben');
  await context.storageState({ path: BEN_TOKENS_STORAGE_STATE });
  await context.close();
});

test.afterAll(async ({ browser }) => {
  if (!existsSync(twoStepCodesFile(BEN_EMAIL)) || !existsSync(BEN_TOKENS_STORAGE_STATE)) {
    return;
  }
  const context = await browser.newContext({ storageState: BEN_TOKENS_STORAGE_STATE });
  await turnOffTwoStepLogin(await context.newPage(), BEN_EMAIL);
  await context.close();
});

test.use({ storageState: BEN_TOKENS_STORAGE_STATE, permissions: ['clipboard-read', 'clipboard-write'] });

// A rerun after a crash finds the earlier run's token still listed; its own name tells the two apart.
function uniqueTokenName(): string {
  return `Ben laptop ${randomUUID().slice(0, 8)}`;
}

async function openTokensPage(page: Page): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/me/tokens', { waitUntil: 'networkidle' });
  await expect(page.getByRole('heading', { name: 'API tokens', level: 1 })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

/** Makes a token with the form's defaults and stays on the response that shows it once. */
async function makeToken(page: Page, tokenName: string): Promise<void> {
  await page.getByLabel('Name', { exact: true }).fill(tokenName);
  await page.getByRole('button', { name: 'Make token' }).click();
  await expect(page.getByRole('heading', { name: 'Copy your token now', level: 2 })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

async function revokeToken(page: Page, tokenName: string): Promise<void> {
  await openTokensPage(page);
  await tokenRow(page, tokenName).getByRole('button', { name: 'Revoke' }).click();
  await expect(tokenRow(page, tokenName)).toHaveCount(0);
}

function tokenRow(page: Page, tokenName: string) {
  return page.locator('li.api-tokens__row', { hasText: tokenName });
}

function copyBlock(page: Page, buttonName: string) {
  return page.getByRole('main').locator('[data-controller="copy-text"]', { has: page.getByRole('button', { name: buttonName }) });
}

async function clipboardText(page: Page): Promise<string> {
  return page.evaluate(() => navigator.clipboard.readText());
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function horizontalOverflowPx(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

test('a person makes a token, copies it, sees it only once and revokes it', async ({ page }) => {
  const tokenName = uniqueTokenName();
  await page.clock.install({ time: FROZEN_AT });
  await page.goto('/', { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ben', exact: true }).click();
  await page.getByRole('link', { name: 'API tokens' }).click();
  await expect(page.getByRole('heading', { name: 'API tokens', level: 1 })).toBeVisible();
  await expect(page).toHaveTitle('API tokens · pinround');

  await makeToken(page, tokenName);
  const plainToken = await page.getByLabel('Token', { exact: true }).inputValue();
  expect(plainToken).toMatch(PLAIN_TOKEN);
  const command = await page.getByLabel('Add it to Claude Code').inputValue();
  expect(command).toContain(`"Authorization: Bearer ${plainToken}"`);

  const tokenCopy = copyBlock(page, 'Copy token');
  // The button shows once copy-text has connected, and click() waits for it to be visible.
  await tokenCopy.getByRole('button', { name: 'Copy token' }).click();
  await expect(tokenCopy.getByRole('status')).toHaveText('Copied');
  expect(await clipboardText(page)).toBe(plainToken);
  await page.clock.fastForward(COPIED_SHOWN_FOR_MS);
  await expect(tokenCopy.getByRole('status')).toHaveText('');

  const commandCopy = copyBlock(page, 'Copy command');
  await commandCopy.getByRole('button', { name: 'Copy command' }).click();
  await expect(commandCopy.getByRole('status')).toHaveText('Copied');
  expect(await clipboardText(page)).toBe(command);

  // Opened again with a GET: a browser reload would send the form a second time and make a second token.
  await openTokensPage(page);
  await expect(page.getByRole('heading', { name: 'Copy your token now' })).toHaveCount(0);
  expect(await page.content()).not.toContain(plainToken);
  const row = tokenRow(page, tokenName);
  await expect(row).toHaveCount(1);
  await expect(row.locator('code')).toHaveText(`${plainToken.slice(0, 11)}…`);
  await expect(row).toContainText('Read your tickets, rounds and notes.');
  await expect(row).not.toContainText('rounds:write');

  await row.getByRole('button', { name: 'Revoke' }).click();

  await expect(page.getByRole('main').getByRole('status').filter({ hasText: 'The token is revoked. It no longer works.' })).toBeVisible();
  await expect(tokenRow(page, tokenName)).toHaveCount(0);
});

test('when the browser refuses to copy, the token is selected for a manual copy', async ({ page }) => {
  const tokenName = uniqueTokenName();
  await openTokensPage(page);
  await makeToken(page, tokenName);
  const tokenCopy = copyBlock(page, 'Copy token');
  const tokenField = page.getByLabel('Token', { exact: true });
  const plainToken = await tokenField.inputValue();
  await expect(tokenCopy.getByRole('button', { name: 'Copy token' })).toBeVisible();
  // writeText stays a function, so copy-text keeps its button, and rejects as a browser does without permission.
  await page.evaluate(() => {
    Object.defineProperty(navigator.clipboard, 'writeText', {
      configurable: true,
      value: () => Promise.reject(new DOMException('Write permission denied.', 'NotAllowedError')),
    });
  });

  await tokenCopy.getByRole('button', { name: 'Copy token' }).click();

  await expect(tokenCopy.getByRole('status')).toHaveText('Could not copy. Select the text and copy it.');
  expect(await tokenField.evaluate((field: HTMLInputElement) => [field.selectionStart, field.selectionEnd])).toEqual([0, plainToken.length]);
  expect(await clipboardText(page)).not.toBe(plainToken);

  await revokeToken(page, tokenName);
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the tokens page has no serious or critical accessibility violations in ${scheme}, with a new token and without`, async ({ page }) => {
    const tokenName = uniqueTokenName();
    await page.emulateMedia({ colorScheme: scheme });
    await openTokensPage(page);
    await makeToken(page, tokenName);
    await expect(copyBlock(page, 'Copy token').getByRole('button', { name: 'Copy token' })).toBeVisible();

    expect(await seriousViolations(page)).toEqual([]);

    await openTokensPage(page);
    await expect(tokenRow(page, tokenName)).toHaveCount(1);

    expect(await seriousViolations(page)).toEqual([]);

    await revokeToken(page, tokenName);
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the tokens page does not scroll horizontally at 360px, with a new token and without', async ({ page }) => {
    const tokenName = uniqueTokenName();
    await openTokensPage(page);
    await makeToken(page, tokenName);

    expect(await horizontalOverflowPx(page)).toBeLessThanOrEqual(0);

    await openTokensPage(page);
    await expect(tokenRow(page, tokenName)).toHaveCount(1);

    expect(await horizontalOverflowPx(page)).toBeLessThanOrEqual(0);

    await revokeToken(page, tokenName);
  });
});
