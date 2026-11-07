import { expect, test } from '@playwright/test';

// Fails on purpose to prove CI uploads the Playwright trace; reverted in the next commit.
test('trace proof: CI uploads the trace of a failing test', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('body')).toHaveText('this text is not on the page', { timeout: 2000 });
});
