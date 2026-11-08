import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { codeOf, demoPassword, MIA_EMAIL, MIA_TWO_STEP_KEY_FILE, TWO_STEP_CODE_STEP_MS } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Mia, the one demo person with two-step login on: login-code.spec.ts turns it on at its first run and keeps her
// key in the git-ignored tests/e2e/.auth/. This spec only opens the page and never confirms, so it spends no code
// but the one of its single login. The functional ConfirmItsYouTest covers the confirmation, the refusals and the
// return path.
const MIA_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'mia-confirm.json');

test.describe.configure({ mode: 'serial' });

/**
 * One login for the whole spec; false when Mia's login asks for no code. A code counts once, so when another spec
 * just logged Mia in with the code of this step, the login waits for the next step and tries once more.
 */
async function logInAsMia(browser: Browser, key: string): Promise<boolean> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const page = await context.newPage();
  await page.goto('/login');
  await page.getByLabel('Email').fill(MIA_EMAIL);
  await page.getByLabel('Password').fill(demoPassword());
  await page.getByRole('button', { name: 'Log in' }).click();
  const codePageHeading = page.getByRole('heading', { name: 'Type your code', level: 1 });
  const avatar = page.getByRole('button', { name: 'Mia', exact: true });
  await expect(codePageHeading.or(avatar)).toBeVisible();
  if (await avatar.isVisible()) {
    await context.close();
    return false;
  }
  await page.getByLabel('Code from your app').fill(codeOf(key, Date.now()));
  await page.getByRole('button', { name: 'Log in' }).click();
  const refusal = page.getByRole('alert');
  await expect(avatar.or(refusal)).toBeVisible();
  if (await refusal.isVisible()) {
    await page.waitForTimeout(TWO_STEP_CODE_STEP_MS - (Date.now() % TWO_STEP_CODE_STEP_MS) + 1_000);
    await page.getByLabel('Code from your app').fill(codeOf(key, Date.now()));
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(avatar).toBeVisible();
  }
  await context.storageState({ path: MIA_STORAGE_STATE });
  await context.close();
  return true;
}

test.beforeAll(async ({ browser }) => {
  test.skip(!existsSync(MIA_TWO_STEP_KEY_FILE), `${MIA_TWO_STEP_KEY_FILE} is missing: run login-code.spec.ts first, which turns on Mia's two-step login.`);
  const isLoggedIn = await logInAsMia(browser, readFileSync(MIA_TWO_STEP_KEY_FILE, 'utf8').trim());
  test.skip(!isLoggedIn, "Mia's two-step login is off, so her key file is from an older database: run login-code.spec.ts first.");
});

test.use({ storageState: MIA_STORAGE_STATE });

async function openConfirmPage(page: Page): Promise<void> {
  await page.goto('/me/confirm');
  await expect(page.getByRole('heading', { name: "Confirm it's you", level: 1 })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test('the page asks for the password and a code', async ({ page }) => {
  await openConfirmPage(page);

  const password = page.getByLabel('Password');
  await expect(password).toHaveAttribute('autocomplete', 'current-password');
  await expect(password).toBeFocused();
  await expect(page.getByLabel('Code from your app')).toHaveAttribute('autocomplete', 'one-time-code');
  await expect(page.getByText('6 digits. No phone with you? Type one of your recovery codes instead.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Confirm' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Cancel' })).toBeVisible();
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the page has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await openConfirmPage(page);

    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the page does not scroll horizontally at 360px', async ({ page }) => {
    await openConfirmPage(page);

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
});
