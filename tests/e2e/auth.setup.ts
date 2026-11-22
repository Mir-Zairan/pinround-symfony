import { expect, test as setup, type Page } from '@playwright/test';
import {
  BEN_EMAIL,
  BEN_STORAGE_STATE,
  demoPassword,
  LENA_EMAIL,
  LENA_STORAGE_STATE,
  LENA_TEAM_PAGE_STORAGE_STATE,
  MIA_EMAIL,
  MIA_STORAGE_STATE,
  OMAR_EMAIL,
  OMAR_OWN_CHANGE_STORAGE_STATE,
  OMAR_STORAGE_STATE,
  RAJ_EMAIL,
  RAJ_STORAGE_STATE,
} from './auth';

async function submitPassword(page: Page, email: string): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(demoPassword());
  await page.getByRole('button', { name: 'Log in' }).click();
}

// The header's avatar button, named after the person, opens the menu with "Log out".
function avatarOf(page: Page, firstName: string) {
  return page.getByRole('button', { name: firstName, exact: true });
}

// One login per session for the whole run: the login limiter allows five tries per email and address in 15 minutes
// (a successful login clears that count, so a person's second login leaves it as one).
async function logIn(page: Page, email: string, firstName: string, storageState: string): Promise<void> {
  await submitPassword(page, email);
  await expect(avatarOf(page, firstName)).toBeVisible();
  await page.context().storageState({ path: storageState });
}

setup('log in as Lena', async ({ page }) => {
  await logIn(page, LENA_EMAIL, 'Lena', LENA_STORAGE_STATE);
});

setup('log in as Lena for the team page', async ({ page }) => {
  await logIn(page, LENA_EMAIL, 'Lena', LENA_TEAM_PAGE_STORAGE_STATE);
});

setup('log in as Omar', async ({ page }) => {
  await logIn(page, OMAR_EMAIL, 'Omar', OMAR_STORAGE_STATE);
});

setup('log in as Omar for his own change', async ({ page }) => {
  await logIn(page, OMAR_EMAIL, 'Omar', OMAR_OWN_CHANGE_STORAGE_STATE);
});

setup('log in as Ben', async ({ page }) => {
  await logIn(page, BEN_EMAIL, 'Ben', BEN_STORAGE_STATE);
});

/**
 * Logs in a person whose two-step login a spec turns on, which this project runs before. When an earlier local run
 * stopped with it on, it skips instead: typing a recovery code would spend one that spec needs to turn it off again.
 * CI starts from the demo seed, where it is off, so there the code page fails this login.
 */
async function logInBeforeTwoStepLogin(page: Page, email: string, firstName: string, storageState: string, skipReason: string): Promise<void> {
  await submitPassword(page, email);
  const codePage = page.getByRole('heading', { name: 'Type your code', level: 1 });
  await expect(codePage.or(avatarOf(page, firstName))).toBeVisible();
  setup.skip(!process.env.CI && (await codePage.isVisible()), skipReason);
  await expect(avatarOf(page, firstName)).toBeVisible();
  await page.context().storageState({ path: storageState });
}

setup('log in as Raj', async ({ page }) => {
  await logInBeforeTwoStepLogin(
    page,
    RAJ_EMAIL,
    'Raj',
    RAJ_STORAGE_STATE,
    'Raj has two-step login on in this database (an earlier run of confirm-its-you.spec.ts stopped before turning it off), so ticket-move.spec.ts skips his viewer check.',
  );
});

setup('log in as Mia', async ({ page }) => {
  await logInBeforeTwoStepLogin(
    page,
    MIA_EMAIL,
    'Mia',
    MIA_STORAGE_STATE,
    'Mia has two-step login on in this database (an earlier run of login-code.spec.ts stopped before turning it off), so live-updates.spec.ts skips her removed-access check.',
  );
});
