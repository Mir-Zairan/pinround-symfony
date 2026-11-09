import { expect, test } from '@playwright/test';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { logInAndTurnOnTwoStepLogin, SARA_EMAIL, turnOffTwoStepLogin, twoStepCodesFile } from './auth';

// As Sara, the demo admin whom no other spec logs in as: beforeAll turns her two-step login on, which also counts as
// the code the admin pages ask for, and afterAll turns it off with a recovery code. Inviting the same address again
// replaces the open invite, so a rerun leaves one pending row. The functional AdminPeopleTest covers the refusals.
const SARA_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'sara-people.json');
const INVITED_EMAIL = 'mia@acme.test';

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

test('an admin invites an email address and sees the toast and the pending row', async ({ page }) => {
  await page.goto('/admin/people');

  await page.getByLabel('Email').fill(INVITED_EMAIL);
  await page.getByRole('button', { name: 'Invite' }).click();

  await expect(page.locator('.toast-region .toast')).toHaveText(`Invite sent to ${INVITED_EMAIL}`);
  await expect(page.locator('.people__row', { hasText: INVITED_EMAIL })).toContainText('Invited · not joined yet');
  await expect(page.locator('.toast-region .toast')).toHaveCount(0, { timeout: 6_000 });
});
