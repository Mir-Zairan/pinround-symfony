import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

// As Lena (auth.setup.ts). The demo seed holds no tickets, so the journey first makes one on /tickets/new; it needs a
// project of one of Lena's teams and skips with a note when the demo has none. The ticket stays: tickets are not deleted.
test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

const TITLE = `Journey ticket ${Date.now()}`;

async function makeTicket(page: Page): Promise<boolean> {
  await page.goto('/tickets/new', { waitUntil: 'networkidle' });
  const project = page.locator('select[name="project_id"]');
  if ((await project.count()) === 0 || (await project.locator('option:not([value=""])').count()) === 0) {
    return false;
  }
  if ((await project.inputValue()) === '') {
    await project.selectOption({ index: 1 });
  }
  await page.locator('input[name="title"]').fill(TITLE);
  await page.getByRole('main').locator('button[type="submit"]').click();
  await page.waitForURL((url) => !url.pathname.endsWith('/tickets/new'));
  return true;
}

async function openTicketsFromMenu(page: Page): Promise<void> {
  await page.goto('/', { waitUntil: 'networkidle' });
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Tickets', exact: true }).click();
  await expect(page).toHaveURL(/\/tickets$/);
  await page.waitForLoadState('networkidle');
}

test('Lena opens Tickets, collapses a step with the keyboard and finds it collapsed after a reload', async ({ page }) => {
  test.skip(!(await makeTicket(page)), 'The demo seed gives Lena no project to make a ticket in.');
  await openTicketsFromMenu(page);

  await expect(page.getByRole('heading', { level: 1, name: 'All tickets' })).toBeVisible();
  const row = page.locator('.tickets__row', { hasText: TITLE });
  await expect(row).toBeVisible();
  const step = page.locator('.tickets__step', { has: row });
  const toggle = step.locator('.tickets__step-toggle');
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');

  // The groups come in the order of a ticket's life: Needs input, Code review, In progress, To do, Done.
  const order = ['wait', 'check', 'doing', 'todo', 'done'];
  const shown = await page.locator('.tickets__step').evaluateAll((sections) => sections.map((section) => (section as HTMLElement).dataset.group ?? ''));
  expect(shown.map((group) => order.indexOf(group))).toEqual([...shown.map((group) => order.indexOf(group))].sort((a, b) => a - b));

  await toggle.focus();
  const saved = page.waitForResponse((response) => response.url().includes('/collapse') && response.request().method() === 'POST');
  await page.keyboard.press('Enter');
  expect((await saved).status()).toBe(204);
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(row).toBeHidden();

  await page.reload({ waitUntil: 'networkidle' });
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(row).toBeHidden();

  // Expand again so the next run starts from the open state.
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(row).toBeVisible();
});

// Not exercised here: "Show more". The demo seed has no step with more than 50 tickets, and the journey makes one.
// The Turbo frame swap is covered by the functional test of the link and the frame ids.
