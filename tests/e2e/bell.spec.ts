import { expect, test, type Page } from '@playwright/test';
import { OMAR_STORAGE_STATE } from './auth';

// As Omar (auth.setup.ts), whose pings the bell shows.
test.use({ storageState: OMAR_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

async function openHome(page: Page): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/', { waitUntil: 'networkidle' });
}

function bell(page: Page) {
  return page.getByRole('banner').getByRole('button', { name: 'Pings' });
}

function popover(page: Page) {
  return page.getByRole('dialog', { name: /^Pings · \d+ open$/ });
}

async function openPopover(page: Page): Promise<void> {
  const frameLoaded = page.waitForResponse((response) => new URL(response.url()).pathname === '/bell' && response.request().method() === 'GET');
  await bell(page).click();
  expect((await frameLoaded).status()).toBe(200);
  await expect(popover(page)).toBeVisible();
}

test('the bell opens the Pings popover, loads its frame and focuses it', async ({ page }) => {
  await openHome(page);
  await expect(bell(page)).toHaveAttribute('aria-expanded', 'false');

  await openPopover(page);

  await expect(bell(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(popover(page)).toBeFocused();
  await expect(popover(page).getByText('Grouped by person and ticket.')).toBeVisible();
  await expect(popover(page).getByRole('button', { name: 'Close' })).toBeVisible();
});

test('Escape closes the popover and puts focus back on the bell', async ({ page }) => {
  await openHome(page);
  await openPopover(page);

  await page.keyboard.press('Escape');

  await expect(popover(page)).toBeHidden();
  await expect(bell(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(bell(page)).toBeFocused();
});

test('Close closes the popover and puts focus back on the bell', async ({ page }) => {
  await openHome(page);
  await openPopover(page);

  await popover(page).getByRole('button', { name: 'Close' }).click();

  await expect(popover(page)).toBeHidden();
  await expect(bell(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(bell(page)).toBeFocused();
});

// TODO(P04.T4): the demo seed has no pings for Omar yet; once Lena can ping him through the ping form, send one here,
// press Done on its card and check that the badge drops by one and the card leaves the popover.
test.fixme('Done drops the badge', async () => {});
