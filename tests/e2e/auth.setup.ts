import { expect, test as setup } from '@playwright/test';
import { demoPassword, LENA_EMAIL, LENA_STORAGE_STATE } from './auth';

// One login for the whole run: the login limiter allows five tries per email and address in 15 minutes.
setup('log in as Lena', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Email').fill(LENA_EMAIL);
  await page.getByLabel('Password').fill(demoPassword());
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();
  await page.context().storageState({ path: LENA_STORAGE_STATE });
});
