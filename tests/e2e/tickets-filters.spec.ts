import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

// As Lena, on the seeded stack: make seed gives SHOP 41 tickets, 5 of them in Done, half of the open ones Omar's, and
// several with "basket" in the title. The toolbar (TicketFilters) and the rail reload only their frames; a full page
// load would reset window, so each test puts a marker there and checks it afterwards.
test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

const NO_ROWS = 'The e2e database holds no tickets (run make seed). TicketListTest and TicketFiltersTest prove the filters on the server.';

async function openTickets(page: Page, path = '/tickets'): Promise<void> {
  await page.goto(path, { waitUntil: 'networkidle' });
  test.skip((await page.locator('.tickets__row').count()) === 0, NO_ROWS);
  await page.evaluate(() => {
    (window as any).__beforeFilters = true;
  });
}

async function isSamePageLoad(page: Page): Promise<boolean> {
  return (await page.evaluate(() => (window as any).__beforeFilters)) === true;
}

function listFrameLoaded(page: Page) {
  return page.waitForResponse((response) => response.request().headers()['turbo-frame'] === 'ticket-list-frame' && response.ok());
}

function railFrameLoaded(page: Page) {
  return page.waitForResponse((response) => response.request().headers()['turbo-frame'] === 'project-rail-frame' && response.ok());
}

async function shownTitles(page: Page): Promise<string[]> {
  return page.locator('.tickets__row-title').allInnerTexts();
}

test('typing a search narrows the list and replaces the address without a page load', async ({ page }) => {
  await openTickets(page);
  const total = page.locator('.tickets__total');
  await expect(total).toHaveAttribute('role', 'status');
  const totalBefore = await total.innerText();
  const titlesBefore = await shownTitles(page);
  // The search must leave some rows out, or this test could not tell a narrowed list from the whole one.
  expect(titlesBefore.some((title) => !/basket/i.test(title))).toBe(true);
  const historyLengthBefore = await page.evaluate(() => window.history.length);

  const loaded = listFrameLoaded(page);
  await page.getByRole('searchbox', { name: 'Search by key or words in the title' }).pressSequentially('basket');
  await loaded;

  await expect(page).toHaveURL(/[?&]q=basket(&|$)/);
  await expect(total).not.toHaveText(totalBefore);
  const titlesAfter = await shownTitles(page);
  expect(titlesAfter.length).toBeGreaterThan(0);
  expect(titlesAfter.length).toBeLessThan(titlesBefore.length);
  for (const title of titlesAfter) {
    expect(title).toMatch(/basket/i);
  }
  expect(await page.evaluate(() => window.history.length)).toBe(historyLengthBefore);
  expect(await isSamePageLoad(page)).toBe(true);
});

// give-it-to.spec.ts and ticket-move.spec.ts make tickets in parallel, so one made between the first load and Back
// grows the total. The journey is walked again until no ticket was made during it; a Back that keeps the filter still
// fails every walk. The first openTickets stays outside the retry, so its skip on an empty database is not retried.
test('a person chip filters the list, adds a history entry, and Back shows the whole list again', async ({ page }) => {
  await openTickets(page);
  await expect(async () => {
    await openTickets(page);
    const total = page.locator('.tickets__total');
    const totalBefore = await total.innerText();
    const rowCountBefore = await page.locator('.tickets__row').count();
    const omarChip = page.getByRole('group', { name: 'Assigned to' }).getByRole('link', { name: 'Omar', exact: true });
    const omarId = await omarChip.getAttribute('data-person-chip');
    const historyLengthBefore = await page.evaluate(() => window.history.length);

    const loaded = listFrameLoaded(page);
    await omarChip.click();
    await loaded;

    await expect(page).toHaveURL(new RegExp(`[?&]person=${omarId}(&|$)`));
    await expect(page.getByRole('group', { name: 'Assigned to' }).getByRole('link', { name: 'Omar', exact: true })).toHaveAttribute('aria-current', 'true');
    await expect(total).not.toHaveText(totalBefore);
    const assignees = await page.locator('.tickets__row .tickets__assignee').evaluateAll((avatars) => avatars.map((avatar) => avatar.getAttribute('title')));
    expect(assignees.length).toBeGreaterThan(0);
    expect(assignees.length).toBeLessThan(rowCountBefore);
    expect(new Set(assignees)).toEqual(new Set(['Assigned to Omar']));
    expect(await page.evaluate(() => window.history.length)).toBe(historyLengthBefore + 1);
    expect(await isSamePageLoad(page)).toBe(true);

    // Back to another list of this page reloads it, so the list and the address bar agree again.
    await page.goBack({ waitUntil: 'networkidle' });
    await expect(page).not.toHaveURL(/person=/);
    await expect(total).toHaveText(totalBefore);
    await expect(page.locator('.tickets__row')).toHaveCount(rowCountBefore);
    await expect(page.getByRole('group', { name: 'Assigned to' }).getByRole('link', { name: 'Everyone', exact: true })).toHaveAttribute('aria-current', 'true');
  }).toPass({ intervals: [500, 1000] });
});

test('"Show done" adds the done tickets and reloads the rail, whose count changes, without a page load', async ({ page }) => {
  await openTickets(page);
  const doneChip = page.locator('[data-done-chip]');
  const shopCount = page.locator('[data-rail-project="SHOP"] .project-rail__count');
  const shopCountBefore = await shopCount.innerText();
  const rowCountBefore = await page.locator('.tickets__row').count();
  const historyLengthBefore = await page.evaluate(() => window.history.length);

  const listLoaded = listFrameLoaded(page);
  const railLoaded = railFrameLoaded(page);
  await doneChip.click();
  await Promise.all([listLoaded, railLoaded]);

  await expect(page).toHaveURL(/[?&]done=1(&|$)/);
  await expect(page.locator('[data-done-chip]')).toHaveAttribute('aria-current', 'true');
  await expect(shopCount).not.toHaveText(shopCountBefore);
  await expect(page.locator('.tickets__step[data-group="done"]')).not.toHaveCount(0);
  expect(await page.locator('.tickets__row').count()).toBeGreaterThan(rowCountBefore);
  expect(await page.evaluate(() => window.history.length)).toBe(historyLengthBefore + 1);
  expect(await isSamePageLoad(page)).toBe(true);
});

test('a project on the rail is marked current and filters the list to its tickets', async ({ page }) => {
  await openTickets(page);
  const shopItem = page.getByRole('navigation', { name: 'Projects' }).locator('[data-rail-project="SHOP"]');
  await expect(shopItem).not.toHaveAttribute('aria-current', 'true');

  await shopItem.click();

  await expect(page).toHaveURL(/[?&]project=SHOP(&|$)/);
  await expect(page.locator('[data-rail-project="SHOP"]')).toHaveAttribute('aria-current', 'true');
  await expect(page.locator('[data-rail-project=""]')).not.toHaveAttribute('aria-current', 'true');
  const keys = await page.locator('.tickets__row').evaluateAll((rows) => rows.map((row) => (row as HTMLElement).dataset.ticketKey ?? ''));
  expect(keys.length).toBeGreaterThan(0);
  for (const key of keys) {
    expect(key).toMatch(/^SHOP-\d+$/);
  }

  // Only SHOP has seeded tickets, so the check above holds even if the filter were ignored. APP has none and no team,
  // so it is not on the rail: its list must be empty.
  const response = await page.goto('/tickets?project=APP', { waitUntil: 'networkidle' });
  expect(response?.status()).toBe(200);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('APP · Acme app');
  await expect(page.locator('.tickets__row')).toHaveCount(0);
  await expect(page.locator('.empty-state')).toHaveText('No tickets here yet.');
});

test('the skip link moves focus to the list without reloading the page', async ({ page }) => {
  await openTickets(page, '/tickets?done=1');

  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused();
  await page.keyboard.press('Enter');

  await expect(page).toHaveURL(/\/tickets\?done=1#main$/);
  expect(await isSamePageLoad(page)).toBe(true);
});

test('the filtered list with the toolbar and the rail has no serious accessibility violations', async ({ page }) => {
  await openTickets(page, '/tickets?project=SHOP&done=1&q=basket');

  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  const serious = violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
  expect(serious).toEqual([]);
});

test.describe('at 360 px', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the toolbar and the rail do not make the page scroll sideways', async ({ page }) => {
    await openTickets(page, '/tickets?project=SHOP&done=1');
    // Below 600px they wait behind the Filters button (phone-layout.spec.ts).
    await page.getByRole('main').getByRole('button', { name: 'Filters', exact: true }).click();
    await expect(page.getByRole('navigation', { name: 'Projects' })).toBeVisible();

    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
  });
});
