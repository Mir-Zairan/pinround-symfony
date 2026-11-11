import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

// As Lena (auth.setup.ts). The journey makes no tickets of its own: the team-page specs unlink projects and cannot
// while one has an open ticket, so a ticket left behind here would break them. The collapse part runs when the demo has
// tickets and is skipped with a note when it has none; TicketListTest proves the collapse and "Show more" on the server.
test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

async function openTicketsFromMenu(page: Page): Promise<void> {
  await page.goto('/', { waitUntil: 'networkidle' });
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Tickets', exact: true }).click();
  await expect(page).toHaveURL(/\/tickets$/);
  await page.waitForLoadState('networkidle');
}

test('Lena opens Tickets from the menu and sees the heading, the count line and either steps or the empty state', async ({ page }) => {
  await openTicketsFromMenu(page);

  await expect(page.getByRole('heading', { level: 1, name: 'All tickets' })).toBeVisible();
  await expect(page.locator('.tickets__total')).toContainText('grouped by step');
  await expect(page.locator('.tickets__step, .empty-state').first()).toBeVisible();
});

test('Lena collapses a step with the keyboard and finds it collapsed after a reload', async ({ page }) => {
  await openTicketsFromMenu(page);
  const steps = page.locator('.tickets__step');
  test.skip((await steps.count()) === 0, 'The demo seed holds no tickets, so there is no step to collapse.');

  // The groups come in the order of a ticket's life: Needs input, Code review, In progress, To do, Done.
  const order = ['wait', 'check', 'doing', 'todo', 'done'];
  const shown = await steps.evaluateAll((sections) => sections.map((section) => (section as HTMLElement).dataset.group ?? ''));
  const ranks = shown.map((group) => order.indexOf(group));
  expect(ranks).toEqual([...ranks].sort((a, b) => a - b));

  const step = steps.first();
  const stepId = await step.getAttribute('data-step-id');
  const toggle = page.locator(`#step-${stepId} .tickets__step-toggle`);
  const rows = page.locator(`#step-${stepId}-rows`);
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');

  await toggle.focus();
  const saved = page.waitForResponse((response) => response.url().includes('/collapse') && response.request().method() === 'POST');
  await page.keyboard.press('Enter');
  expect((await saved).status()).toBe(204);
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(rows).toBeHidden();

  await page.reload({ waitUntil: 'networkidle' });
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(rows).toBeHidden();

  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(rows).toBeVisible();
});
