import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

// P14.T16: below 600px the header is one row with a menu button, and Tickets shows its heading, then a Filters button,
// then the list. As Lena (auth.setup.ts), who leads Shop and Mobile and holds Admin, so her menu has every item and the
// "Teams" group. Nothing here saves a preference. The seeded stack gives SHOP its tickets (tickets-list.spec.ts); the
// checks that need a row skip with a note on an empty database.
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const PHONE = { width: 390, height: 844 };
const DESKTOP = { width: 1440, height: 900 };
const HEADER_HEIGHT_MAX_PX = 64;
const MENU_ITEMS = ['Home', 'Tickets', /^My reports/, 'Teams', 'Admin'];

test.use({ storageState: LENA_STORAGE_STATE, viewport: PHONE });

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

// Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
async function open(page: Page, path: string): Promise<void> {
  const response = await page.goto(path, { waitUntil: 'networkidle' });
  expect(response?.status()).toBe(200);
  await page.evaluate(() => document.fonts.ready);
}

async function openTicketsWithRows(page: Page): Promise<void> {
  await open(page, '/tickets');
  test.skip((await page.locator('.tickets__row').count()) === 0, 'The e2e database holds no tickets (run make seed). TicketListTest proves the list on the server.');
}

function menuButton(page: Page) {
  return page.locator('.site-header').getByRole('button', { name: 'Menu', exact: true });
}

function mainMenu(page: Page) {
  return page.getByRole('navigation', { name: 'Main' });
}

function filtersButton(page: Page) {
  return page.getByRole('main').getByRole('button', { name: 'Filters', exact: true });
}

function searchField(page: Page) {
  return page.getByRole('searchbox');
}

function projectRail(page: Page) {
  return page.getByRole('navigation', { name: 'Projects' });
}

async function tabTo(page: Page, target: ReturnType<Page['locator']>, maxPresses: number): Promise<void> {
  for (let press = 0; press < maxPresses; press++) {
    await page.keyboard.press('Tab');
    if (await target.evaluate((element) => element === document.activeElement)) {
      return;
    }
  }
  throw new Error(`Tab did not reach the element in ${maxPresses} presses.`);
}

test('at 390x844 the header is one row of at most 64px and the first ticket shows without scrolling', async ({ page }) => {
  await openTicketsWithRows(page);

  const header = (await page.locator('.site-header').boundingBox())!;
  expect(header.height).toBeLessThanOrEqual(HEADER_HEIGHT_MAX_PX);
  await expect(menuButton(page)).toBeVisible();
  await expect(page.locator('.site-header').getByRole('link', { name: 'Report a problem' })).toBeVisible();
  await expect(page.locator('.site-header').getByRole('button', { name: 'Pings' })).toBeVisible();
  // The nav waits behind the menu button, so its links take no room in the row.
  await expect(mainMenu(page)).toBeHidden();

  expect(await page.evaluate(() => window.scrollY)).toBe(0);
  // A step another spec collapsed hides its rows, so the first ticket is the first one in an open step.
  const firstRow = (await page.locator('.tickets__rows:not([hidden]) .tickets__row').first().boundingBox())!;
  expect(firstRow.y).toBeGreaterThanOrEqual(header.y + header.height);
  expect(firstRow.y + firstRow.height).toBeLessThanOrEqual(PHONE.height);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
});

test('Tab reaches the menu button, Enter opens the menu with focus inside, Escape closes it back onto the button', async ({ page }) => {
  await open(page, '/');
  await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(menuButton(page)).toHaveAttribute('aria-controls', 'main-nav');

  // The skip link, the logo, then the menu button.
  await tabTo(page, menuButton(page), 3);
  await page.keyboard.press('Enter');

  await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(mainMenu(page)).toBeVisible();
  await expect(mainMenu(page).locator(':scope > a, :scope > span, :scope > div > button')).toHaveText(MENU_ITEMS);
  await expect(mainMenu(page).getByRole('link', { name: 'Home', exact: true })).toBeFocused();

  await page.keyboard.press('Escape');

  await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(mainMenu(page)).toBeHidden();
  await expect(menuButton(page)).toBeFocused();
});

test('Space opens the menu and, back on the button, Space closes it', async ({ page }) => {
  await open(page, '/');
  await menuButton(page).focus();

  await page.keyboard.press('Space');

  await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(mainMenu(page).getByRole('link', { name: 'Home', exact: true })).toBeFocused();

  // Back onto the button stays inside the menu's holder, so the menu stays open.
  await page.keyboard.press('Shift+Tab');
  await expect(menuButton(page)).toBeFocused();
  await expect(mainMenu(page)).toBeVisible();

  await page.keyboard.press('Space');

  await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(mainMenu(page)).toBeHidden();
  await expect(menuButton(page)).toBeFocused();
});

test('Escape in the Teams list closes that list only; a second Escape closes the menu', async ({ page }) => {
  await open(page, '/');
  await menuButton(page).click();
  const teamsButton = mainMenu(page).getByRole('button', { name: 'Teams', exact: true });
  await teamsButton.focus();
  await page.keyboard.press('Enter');
  const firstTeam = mainMenu(page).locator('.nav-menu__list').getByRole('link').first();
  await expect(firstTeam).toBeFocused();
  await expect(firstTeam).toHaveText('Mobile');

  await page.keyboard.press('Escape');

  await expect(teamsButton).toHaveAttribute('aria-expanded', 'false');
  await expect(teamsButton).toBeFocused();
  await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(mainMenu(page)).toBeVisible();

  await page.keyboard.press('Escape');

  await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(mainMenu(page)).toBeHidden();
  await expect(menuButton(page)).toBeFocused();
});

test('a click outside closes the menu, and a menu link opens its page', async ({ page }) => {
  await open(page, '/');
  await menuButton(page).click();
  await expect(mainMenu(page)).toBeVisible();

  await page.getByRole('main').click({ position: { x: 5, y: 300 } });

  await expect(menuButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(mainMenu(page)).toBeHidden();

  await menuButton(page).click();
  await mainMenu(page).getByRole('link', { name: 'Tickets', exact: true }).click();

  await expect(page).toHaveURL(/\/tickets$/);
  // The new page opens with the menu closed, so the nav itself is hidden from the role query too.
  const closedMainMenu = page.getByRole('navigation', { name: 'Main', includeHidden: true });
  await expect(closedMainMenu).toBeHidden();
  await expect(closedMainMenu.getByRole('link', { name: 'Tickets', exact: true, includeHidden: true })).toHaveAttribute('aria-current', 'page');
});

test('on Tickets the heading comes first, then a Filters button that shows and hides the rail and the toolbar', async ({ page }) => {
  await open(page, '/tickets');
  const heading = page.getByRole('heading', { level: 1 });
  await expect(heading).toHaveText('All tickets');
  await expect(filtersButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(filtersButton(page)).toHaveAttribute('aria-controls', 'tickets-filters-panel');
  await expect(searchField(page)).toBeHidden();
  await expect(projectRail(page)).toBeHidden();
  expect((await heading.boundingBox())!.y).toBeLessThan((await filtersButton(page).boundingBox())!.y);

  await filtersButton(page).click();

  await expect(filtersButton(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(searchField(page)).toBeVisible();
  await expect(projectRail(page)).toBeVisible();
  await expect(page.getByRole('link', { name: 'Show done' })).toBeVisible();
  expect((await filtersButton(page).boundingBox())!.y).toBeLessThan((await projectRail(page).boundingBox())!.y);

  await filtersButton(page).focus();
  await page.keyboard.press('Enter');

  await expect(filtersButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(searchField(page)).toBeHidden();
  await expect(projectRail(page)).toBeHidden();
  await expect(filtersButton(page)).toBeFocused();
});

test('Whose turn? also shows its heading before the Filters button', async ({ page }) => {
  await open(page, '/tickets/turn');
  const heading = page.getByRole('heading', { level: 1 });
  await expect(filtersButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(searchField(page)).toBeHidden();
  expect((await heading.boundingBox())!.y).toBeLessThan((await filtersButton(page).boundingBox())!.y);

  await filtersButton(page).click();

  await expect(searchField(page)).toBeVisible();
  await expect(projectRail(page)).toBeVisible();
});

test('the phone tickets list with its filters open has no serious accessibility violations', async ({ page }) => {
  await openTicketsWithRows(page);
  await filtersButton(page).click();
  await expect(searchField(page)).toBeVisible();

  expect(await seriousViolations(page)).toEqual([]);
});

test('the phone header with its menu open has no serious accessibility violations', async ({ page }) => {
  await open(page, '/');
  await menuButton(page).click();
  await expect(mainMenu(page)).toBeVisible();

  expect(await seriousViolations(page)).toEqual([]);
});

test.describe('logged out', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('a visit to Tickets goes to the login page, which has no menu button', async ({ page }) => {
    await page.goto('/tickets');

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('button', { name: 'Menu', exact: true })).toHaveCount(0);
  });
});

test.describe('at 1440x900', () => {
  test.use({ viewport: DESKTOP });

  test('the header shows the full menu in its row and no menu button, also after a click outside', async ({ page }) => {
    await open(page, '/tickets');

    await expect(menuButton(page)).toBeHidden();
    await expect(mainMenu(page)).toBeVisible();
    await expect(mainMenu(page).locator(':scope > a, :scope > span, :scope > div > button')).toHaveText(MENU_ITEMS);
    await expect(page.locator('.logo')).toHaveText('pinround');
    await expect(page.locator('.logo .logo__word')).toBeVisible();

    // The menu controller still runs from 600px; its closing must not hide the nav there.
    await page.getByRole('main').click({ position: { x: 5, y: 5 } });
    await expect(mainMenu(page)).toBeVisible();
    await mainMenu(page).getByRole('link', { name: 'Home', exact: true }).focus();
    await page.keyboard.press('Escape');
    await expect(mainMenu(page)).toBeVisible();
    await expect(mainMenu(page).getByRole('link', { name: 'Home', exact: true })).toBeFocused();
  });

  test('Tickets keeps the rail on the left and the toolbar above the heading, with no Filters button', async ({ page }) => {
    await open(page, '/tickets');

    await expect(filtersButton(page)).toBeHidden();
    await expect(projectRail(page)).toBeVisible();
    await expect(searchField(page)).toBeVisible();
    const rail = (await projectRail(page).boundingBox())!;
    const search = (await searchField(page).boundingBox())!;
    const heading = (await page.getByRole('heading', { level: 1 }).boundingBox())!;
    expect(rail.x + rail.width).toBeLessThanOrEqual(search.x);
    expect(rail.x + rail.width).toBeLessThanOrEqual(heading.x);
    expect(search.y + search.height).toBeLessThanOrEqual(heading.y);
  });
});
