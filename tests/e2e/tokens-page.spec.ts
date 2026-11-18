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
// refusals, the cap and the revoke of another person's token.
const BEN_TOKENS_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'ben-tokens.json');
const PLAIN_TOKEN = /^pr_[A-Za-z0-9_-]{43}$/;

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

function copyBlock(page: Page, buttonName: string) {
  return page.getByRole('main').locator('[data-controller="copy-text"]', { has: page.getByRole('button', { name: buttonName }) });
}

async function clipboardText(page: Page): Promise<string> {
  return page.evaluate(() => navigator.clipboard.readText());
}

test('a person makes a token, copies it, sees it only once and revokes it', async ({ page }) => {
  // A rerun after a crash finds the earlier run's token still listed; its own name tells the two apart.
  const tokenName = `Ben laptop ${randomUUID().slice(0, 8)}`;
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/', { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ben', exact: true }).click();
  await page.getByRole('link', { name: 'API tokens' }).click();
  await expect(page.getByRole('heading', { name: 'Personal access tokens', level: 1 })).toBeVisible();

  await page.getByLabel('Name', { exact: true }).fill(tokenName);
  await page.getByRole('button', { name: 'Make token' }).click();

  await expect(page.getByRole('heading', { name: 'Copy your token now', level: 2 })).toBeVisible();
  const plainToken = await page.getByLabel('Token', { exact: true }).inputValue();
  expect(plainToken).toMatch(PLAIN_TOKEN);
  const command = await page.getByLabel('Add it to Claude Code').inputValue();
  expect(command).toContain(`"Authorization: Bearer ${plainToken}"`);

  const tokenCopy = copyBlock(page, 'Copy token');
  // The button shows once copy-text has connected, and click() waits for it to be visible.
  await tokenCopy.getByRole('button', { name: 'Copy token' }).click();
  await expect(tokenCopy.getByRole('status')).toHaveText('Copied');
  expect(await clipboardText(page)).toBe(plainToken);
  // "Copied" shows for a moment only (2 s in copy-text_controller.js).
  await expect(tokenCopy.getByRole('status')).toHaveText('');

  const commandCopy = copyBlock(page, 'Copy command');
  await commandCopy.getByRole('button', { name: 'Copy command' }).click();
  await expect(commandCopy.getByRole('status')).toHaveText('Copied');
  expect(await clipboardText(page)).toBe(command);

  // Opened again with a GET: a browser reload would send the form a second time and make a second token.
  await page.goto('/me/tokens');
  await expect(page.getByRole('heading', { name: 'Personal access tokens', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Copy your token now' })).toHaveCount(0);
  expect(await page.content()).not.toContain(plainToken);
  const row = page.locator('li.api-tokens__row', { hasText: tokenName });
  await expect(row).toHaveCount(1);
  await expect(row.locator('code')).toHaveText(`${plainToken.slice(0, 11)}…`);

  await row.getByRole('button', { name: 'Revoke' }).click();

  await expect(page.getByRole('main').getByRole('status').filter({ hasText: 'The token is revoked. It no longer works.' })).toBeVisible();
  await expect(page.locator('li.api-tokens__row', { hasText: tokenName })).toHaveCount(0);
});
