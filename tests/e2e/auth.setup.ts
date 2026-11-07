import { expect, test as setup, type Page } from '@playwright/test';
import { demoPassword, LENA_EMAIL, LENA_STORAGE_STATE, OMAR_EMAIL, OMAR_STORAGE_STATE } from './auth';

// One login per person for the whole run: the login limiter allows five tries per email and address in 15 minutes.
async function logIn(page: Page, email: string, firstName: string, storageState: string): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(demoPassword());
  await page.getByRole('button', { name: 'Log in' }).click();
  // The header's avatar button, named after the person, opens the menu with "Log out".
  await expect(page.getByRole('button', { name: firstName, exact: true })).toBeVisible();
  await page.context().storageState({ path: storageState });
}

setup('log in as Lena', async ({ page }) => {
  await logIn(page, LENA_EMAIL, 'Lena', LENA_STORAGE_STATE);
});

setup('log in as Omar', async ({ page }) => {
  await logIn(page, OMAR_EMAIL, 'Omar', OMAR_STORAGE_STATE);
});
