import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

// As Lena (auth.setup.ts), on the seeded stack (make seed gives SHOP 40 tickets, 35 open and 5 in Done, fewer than one page per step). The
// specs make no tickets. The parts that need rows skip with a note only on an empty database; TicketListTest proves
// "Show more" and its focus on the server, and the frame-missing case below injects a link because no seeded step
// holds more than 50 rows.
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
  test.skip((await page.locator('.tickets__row').count()) === 0, 'The e2e database holds no tickets (run make seed). TicketListTest proves the list on the server.');
  await expect(page.locator('.tickets__row').first()).toBeVisible();
}

async function openTickets(page: Page): Promise<void> {
  await page.goto('/tickets', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
}

async function openTicketsWithRows(page: Page): Promise<void> {
  await openTickets(page);
  test.skip((await page.locator('.tickets__row').count()) === 0, 'The e2e database holds no tickets (run make seed). TicketListTest proves the list on the server.');
  await expect(page.locator('.tickets__row').first()).toBeVisible();
}

test('Lena opens Tickets from the menu and sees the heading, the count line and the steps', async ({ page }) => {
  await openTicketsFromMenu(page);

  await expect(page.getByRole('heading', { level: 1, name: 'All tickets' })).toBeVisible();
  await expect(page.locator('.tickets__total')).toContainText('grouped by step');
  await expect(page.locator('.tickets__step').first()).toBeVisible();
});

test('the tickets list has no serious accessibility violations', async ({ page }) => {
  await openTicketsWithRows(page);

  expect(await seriousViolations(page)).toEqual([]);
});

test('the tickets list raises no Content Security Policy violation in the console', async ({ page }) => {
  const violations: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error' && message.text().includes('Content Security Policy')) {
      violations.push(message.text());
    }
  });

  await openTicketsWithRows(page);

  expect(violations).toEqual([]);
});

test('Lena collapses a step with the keyboard and finds it collapsed after a reload', async ({ page }) => {
  await openTicketsFromMenu(page);
  const steps = page.locator('.tickets__step');

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

  test('the page does not scroll sideways while rows are shown', async ({ page }) => {
    await openTicketsWithRows(page);

    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
  });

  test('a row keeps a readable title and shows its time inside the list', async ({ page }) => {
    await openTicketsWithRows(page);
    const firstRow = page.locator('.tickets__row').first();

    const title = await firstRow.locator('.tickets__row-title').boundingBox();
    const updated = await firstRow.locator('.tickets__updated').boundingBox();
    const list = await page.locator('.tickets__rows').first().boundingBox();
    expect(title?.width ?? 0).toBeGreaterThan(200);
    expect((updated?.x ?? 0) + (updated?.width ?? 0)).toBeLessThanOrEqual((list?.x ?? 0) + (list?.width ?? 0));
    expect(await seriousViolations(page)).toEqual([]);
  });
});

test('a Show more link whose response has no matching frame loads that page in the browser', async ({ page }) => {
  await openTickets(page);
  await page.route('**/tickets/more-probe', (route) =>
    route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Landed</title><h1>Landed page</h1>' }),
  );
  // No seeded step holds more than 50 rows, so there is no real link: inject one in a frame, as the list renders it.
  await page.evaluate(() => {
    document.body.insertAdjacentHTML('beforeend', '<turbo-frame id="more-probe"><a id="more-probe-link" href="/tickets/more-probe">Show more</a></turbo-frame>');
  });

  await page.evaluate(() => {
    (window as any).__beforeMoreProbe = true;
  });

  await page.locator('#more-probe-link').click();

  await expect(page).toHaveURL(/\/tickets\/more-probe$/);
  // Only a real page load resets window; a Turbo visit would keep the marker.
  expect(await page.evaluate(() => (window as any).__beforeMoreProbe)).toBeUndefined();
  await expect(page.getByRole('heading', { name: 'Landed page' })).toBeVisible();
  await expect(page.getByText('Content missing')).toHaveCount(0);
});
