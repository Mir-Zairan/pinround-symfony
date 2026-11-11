import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

// As Lena (auth.setup.ts). The specs make no tickets: the team-page specs unlink SHOP and cannot while it has an open
// ticket, and no command removes one yet (P03.T7). The parts that need rows skip with a note on a database with none;
// TicketListTest proves "Show more" and its focus on the server.
test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function openTicketsFromMenu(page: Page): Promise<void> {
  await page.goto('/', { waitUntil: 'networkidle' });
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Tickets', exact: true }).click();
  await expect(page).toHaveURL(/\/tickets$/);
  await page.waitForLoadState('networkidle');
}

async function openTickets(page: Page): Promise<void> {
  await page.goto('/tickets', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
}

test('Lena opens Tickets from the menu and sees the heading, the count line and the steps', async ({ page }) => {
  await openTicketsFromMenu(page);

  await expect(page.getByRole('heading', { level: 1, name: 'All tickets' })).toBeVisible();
  await expect(page.locator('.tickets__total')).toContainText('grouped by step');
  await expect(page.locator('.tickets__step').first()).toBeVisible();
});

test('the tickets list has no serious accessibility violations', async ({ page }) => {
  await openTickets(page);
  await expect(page.locator('.tickets__step').first()).toBeVisible();

  expect(await seriousViolations(page)).toEqual([]);
});

test('Lena collapses a step with the keyboard and finds it collapsed after a reload', async ({ page }) => {
  await openTicketsFromMenu(page);
  const steps = page.locator('.tickets__step');
  test.skip((await steps.count()) === 0, 'The e2e database holds no tickets, so there is no step to collapse. TicketListTest proves the collapse on the server.');

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

test.describe('at 360 px', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the page does not scroll sideways', async ({ page }) => {
    await openTickets(page);

    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
  });

  test('a row keeps a readable title and shows its time inside the list', async ({ page }) => {
    await openTickets(page);
    const firstRow = page.locator('.tickets__row').first();
    test.skip((await firstRow.count()) === 0, 'The e2e database holds no tickets, so there is no row to measure.');
    await expect(firstRow).toBeVisible();

    const title = await firstRow.locator('.tickets__row-title').boundingBox();
    const updated = await firstRow.locator('.tickets__updated').boundingBox();
    const list = await page.locator('.tickets__rows').first().boundingBox();
    expect(title?.width ?? 0).toBeGreaterThan(200);
    expect((updated?.x ?? 0) + (updated?.width ?? 0)).toBeLessThanOrEqual((list?.x ?? 0) + (list?.width ?? 0));
    expect(await seriousViolations(page)).toEqual([]);
  });
});
