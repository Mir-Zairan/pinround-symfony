import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { logInAndTurnOnTwoStepLogin, RAJ_EMAIL, turnOffTwoStepLogin, twoStepCodesFile } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Raj, whom no other spec logs in as: beforeAll turns his two-step login on and afterAll turns it off with a
// recovery code, so a run takes one of his 5 code tries in 15 minutes. This spec only opens the page and never
// confirms. The functional ConfirmItsYouTest covers the confirmation, the refusals and the return path.
const RAJ_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'raj-confirm.json');

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await logInAndTurnOnTwoStepLogin(await context.newPage(), RAJ_EMAIL, 'Raj');
  await context.storageState({ path: RAJ_STORAGE_STATE });
  await context.close();
});

test.afterAll(async ({ browser }) => {
  if (!existsSync(twoStepCodesFile(RAJ_EMAIL)) || !existsSync(RAJ_STORAGE_STATE)) {
    return;
  }
  const context = await browser.newContext({ storageState: RAJ_STORAGE_STATE });
  await turnOffTwoStepLogin(await context.newPage(), RAJ_EMAIL);
  await context.close();
});

test.use({ storageState: RAJ_STORAGE_STATE });

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
