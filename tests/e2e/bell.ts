import { expect, type Page } from '@playwright/test';

export function bellButton(page: Page) {
  return page.getByRole('banner').getByRole('button', { name: 'Pings' });
}

/** The open popover, named by its frame's title, so it matches only once the frame has loaded. */
export function bellPopover(page: Page) {
  return page.getByRole('dialog', { name: /^Pings · \d+ open$/ });
}

/** On a loaded page: opens the bell's popover and waits for its frame from GET /bell. */
export async function openBellPopover(page: Page): Promise<void> {
  const frameLoaded = page.waitForResponse((response) => new URL(response.url()).pathname === '/bell' && response.request().method() === 'GET');
  // Stimulus connects the bell after the importmap modules load, which networkidle does not always wait for under load,
  // and a click before that does nothing; a click after it opens the popover at once, so only an unanswered click is repeated.
  await expect(async () => {
    await bellButton(page).click();
    await expect(bellButton(page)).toHaveAttribute('aria-expanded', 'true', { timeout: 1_000 });
  }).toPass({ intervals: [250, 500, 1_000], timeout: 10_000 });
  expect((await frameLoaded).status()).toBe(200);
  await expect(bellPopover(page)).toBeVisible();
  // The frame's own text, so a check that a card is absent never runs before the frame has rendered.
  await expect(bellPopover(page).getByText('Grouped by person and ticket.')).toBeVisible();
}
