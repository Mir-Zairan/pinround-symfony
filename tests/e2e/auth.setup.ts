import { expect, test as setup, type Page } from '@playwright/test';
import { BEN_EMAIL, BEN_STORAGE_STATE, demoPassword, LENA_EMAIL, LENA_STORAGE_STATE, OMAR_EMAIL, OMAR_STORAGE_STATE, RAJ_EMAIL, RAJ_STORAGE_STATE } from './auth';

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

// One login per person for the whole run: the login limiter allows five tries per email and address in 15 minutes.
async function logIn(page: Page, email: string, firstName: string, storageState: string): Promise<void> {
  await submitPassword(page, email);
  await expect(avatarOf(page, firstName)).toBeVisible();
  await page.context().storageState({ path: storageState });
}

setup('log in as Lena', async ({ page }) => {
  await logIn(page, LENA_EMAIL, 'Lena', LENA_STORAGE_STATE);
});

setup('log in as Omar', async ({ page }) => {
  await logIn(page, OMAR_EMAIL, 'Omar', OMAR_STORAGE_STATE);
});

setup('log in as Ben', async ({ page }) => {
  await logIn(page, BEN_EMAIL, 'Ben', BEN_STORAGE_STATE);
});

// This project runs before any spec, so confirm-its-you.spec.ts has not turned Raj's two-step login on yet.
setup('log in as Raj', async ({ page }) => {
  await submitPassword(page, RAJ_EMAIL);
  const codePage = page.getByRole('heading', { name: 'Type your code', level: 1 });
  await expect(codePage.or(avatarOf(page, 'Raj'))).toBeVisible();
  // Typing a recovery code would spend one that confirm-its-you.spec.ts needs to turn it off again. CI starts from the
  // demo seed, where it is off, so there the code page fails this login.
  setup.skip(
    !process.env.CI && (await codePage.isVisible()),
    'Raj has two-step login on in this database (an earlier run of confirm-its-you.spec.ts stopped before turning it off), so ticket-move.spec.ts skips his viewer check.',
  );
  await expect(avatarOf(page, 'Raj')).toBeVisible();
  await page.context().storageState({ path: RAJ_STORAGE_STATE });
});
