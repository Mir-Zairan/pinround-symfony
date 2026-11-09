import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { logInAndTurnOnTwoStepLogin, SARA_EMAIL, turnOffTwoStepLogin, twoStepCodesFile } from './auth';

// As Sara, the demo admin whom no other spec logs in as: beforeAll turns her two-step login on, which also counts as
// the code the admin pages ask for, and afterAll turns it off with a recovery code. Inviting the same address again
// replaces the open invite, so a rerun leaves one pending row. The functional AdminPeopleTest covers the refusals.
const SARA_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'sara-people.json');
const INVITED_EMAIL = 'mia@acme.test';
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// toast_controller.js: the text goes in this long after the toast connects, and the toast goes this long after that.
const TOAST_ANNOUNCE_DELAY_MS = 150;
const TOAST_SHOWN_FOR_MS = 3_600;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await logInAndTurnOnTwoStepLogin(await context.newPage(), SARA_EMAIL, 'Sara');
  await context.storageState({ path: SARA_STORAGE_STATE });
  await context.close();
});

test.afterAll(async ({ browser }) => {
  if (!existsSync(twoStepCodesFile(SARA_EMAIL)) || !existsSync(SARA_STORAGE_STATE)) {
    return;
  }
  const context = await browser.newContext({ storageState: SARA_STORAGE_STATE });
  await turnOffTwoStepLogin(await context.newPage(), SARA_EMAIL);
  await context.close();
});

test.use({ storageState: SARA_STORAGE_STATE });

async function openPeoplePage(page: Page): Promise<void> {
  await page.goto('/admin/people');
  await expect(page.getByRole('heading', { name: 'People and roles', level: 1 })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test('the Invite button waits for something that looks like an email address', async ({ page }) => {
  await page.goto('/admin/people');
  const email = page.getByLabel('Email');
  const invite = page.getByRole('button', { name: 'Invite' });

  await expect(invite).toBeDisabled();
  await email.fill('mia@');
  await expect(invite).toBeDisabled();
  await email.fill(INVITED_EMAIL);
  await expect(invite).toBeEnabled();
});

// The clock is paused before the invite, and the page after the redirect replays it, so only runFor moves the toast.
test('an admin invites an email address and sees the toast and the pending row', async ({ page }) => {
  await page.clock.install();
  await page.goto('/admin/people');
  await page.getByLabel('Email').fill(INVITED_EMAIL);
  await page.clock.pauseAt(Date.now() + 1_000);

  await page.getByRole('button', { name: 'Invite' }).click();

  const toast = page.locator('.toast-region .toast');
  await expect(page.locator('.people__row', { hasText: INVITED_EMAIL })).toContainText('Invited · not joined yet');
  await expect(toast).toHaveText('');
  await page.clock.runFor(TOAST_ANNOUNCE_DELAY_MS);
  await expect(toast).toHaveText(`Invite sent to ${INVITED_EMAIL}`);
  await page.clock.runFor(TOAST_SHOWN_FOR_MS - 1);
  await expect(toast).toHaveText(`Invite sent to ${INVITED_EMAIL}`);
  await page.clock.runFor(1);
  await expect(toast).toHaveCount(0);
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the people page has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await openPeoplePage(page);

    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the people page does not scroll horizontally at 360px', async ({ page }) => {
    await openPeoplePage(page);

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
});
