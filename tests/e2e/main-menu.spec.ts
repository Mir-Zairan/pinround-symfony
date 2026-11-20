import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Lena (auth.setup.ts), who leads Shop and Mobile in the demo seed, so her menu has the "Teams" disclosure. Nothing
// here saves a preference, so her shell baselines in shell.spec.ts are untouched.
test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function openHome(page: Page): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
}

function mainMenu(page: Page) {
  return page.getByRole('navigation', { name: 'Main' });
}

function teamsButton(page: Page) {
  return mainMenu(page).getByRole('button', { name: 'Teams', exact: true });
}

function teamList(page: Page) {
  return mainMenu(page).locator('.nav-menu__list');
}

// My reports stays out of the menu until P14.T15 builds its page.
test('the menu lists Lena\'s items in order and links Home, her team pages and Admin', async ({ page }) => {
  await openHome(page);

  await expect(mainMenu(page).locator(':scope > a, :scope > span, :scope > div > button')).toHaveText([
    'Home',
    'Tickets',
    'Teams',
    'Admin',
  ]);
  // The team links sit in the closed list, so they count only with hidden elements included.
  await expect(mainMenu(page).getByRole('link', { includeHidden: true })).toHaveText(['Home', 'Tickets', 'Mobile', 'Shop', 'Admin']);
  await expect(mainMenu(page).getByRole('link', { name: 'Admin', exact: true })).toHaveAttribute('href', '/admin');
  await expect(teamList(page).locator('a[href="/teams/mobile"], a[href="/teams/shop"]')).toHaveCount(2);
});

test('a click opens the team list and a second click closes it', async ({ page }) => {
  await openHome(page);
  await expect(teamsButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(teamList(page)).toBeHidden();

  await teamsButton(page).click();

  await expect(teamsButton(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(teamList(page).locator(':scope > *')).toHaveText(['Mobile', 'Shop']);

  await teamsButton(page).click();

  await expect(teamsButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(teamList(page)).toBeHidden();
});

test('a click outside closes the team list', async ({ page }) => {
  await openHome(page);
  await teamsButton(page).click();
  await expect(teamList(page)).toBeVisible();

  await page.getByRole('main').click();

  await expect(teamsButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(teamList(page)).toBeHidden();
});

test('Enter opens the team list and Escape closes it with focus kept on the button', async ({ page }) => {
  await openHome(page);
  await teamsButton(page).focus();

  await page.keyboard.press('Enter');

  await expect(teamsButton(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(teamList(page)).toBeVisible();

  await page.keyboard.press('Escape');

  await expect(teamsButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(teamList(page)).toBeHidden();
  await expect(teamsButton(page)).toBeFocused();
});

test('ArrowDown opens the team list', async ({ page }) => {
  await openHome(page);
  await teamsButton(page).focus();

  await page.keyboard.press('ArrowDown');

  await expect(teamsButton(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(teamList(page)).toBeVisible();
});

test('Tab from the open team list moves into it, onto the first team link, and keeps it open', async ({ page }) => {
  await openHome(page);
  await teamsButton(page).click();
  await expect(teamsButton(page)).toBeFocused();

  await page.keyboard.press('Tab');

  await expect(teamsButton(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(teamList(page)).toBeVisible();
  const firstTeam = teamList(page).getByRole('link').first();
  await expect(firstTeam).toBeFocused();
  await expect(firstTeam).toHaveText('Mobile');
  await expect(firstTeam).toHaveAttribute('href', '/teams/mobile');
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the open team list has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await openHome(page);
    await teamsButton(page).click();
    await expect(teamList(page)).toBeVisible();

    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('as Omar, who holds no Admin role', () => {
  test.use({ storageState: OMAR_STORAGE_STATE });

  test('the menu shows no Admin item and /admin answers 404', async ({ page }) => {
    await openHome(page);

    await expect(mainMenu(page).getByRole('link', { name: 'Home', exact: true })).toBeVisible();
    await expect(mainMenu(page).getByText('Admin', { exact: true })).toHaveCount(0);

    const response = await page.goto('/admin');

    expect(response?.status()).toBe(404);
    await expect(page).toHaveURL('/admin');
  });
});

// 360px is the narrowest screen we support; 641px is the narrowest that keeps the list under its button (shell.css).
for (const width of [360, 641]) {
  test.describe(`${width}px viewport`, () => {
    test.use({ viewport: { width, height: 800 } });

    test(`the open team list stays inside the viewport at ${width}px`, async ({ page }) => {
      await openHome(page);
      await teamsButton(page).click();
      await expect(teamList(page)).toBeVisible();

      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
      const list = (await teamList(page).boundingBox())!;
      expect(list.x).toBeGreaterThanOrEqual(0);
      expect(list.x + list.width).toBeLessThanOrEqual(width);
    });
  });
}
