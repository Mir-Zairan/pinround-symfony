import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { OMAR_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Omar (auth.setup.ts), because picking a theme saves it on the user: Lena's shell baselines in shell.spec.ts must
// keep following the system scheme. Nothing here logs out, so the shared session survives.
test.use({ storageState: OMAR_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

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

function avatar(page: Page) {
  return page.getByRole('button', { name: 'Omar', exact: true });
}

function themeOption(page: Page, label: 'System' | 'Light' | 'Dark') {
  return page.getByRole('banner').getByRole('group', { name: 'Theme' }).getByRole('button', { name: label, exact: true });
}

async function pickTheme(page: Page, label: 'System' | 'Light' | 'Dark'): Promise<void> {
  const saved = page.waitForResponse((response) => response.url().endsWith('/me/theme') && response.request().method() === 'POST');
  await themeOption(page, label).click();
  await saved;
}

test.afterEach(async ({ page }) => {
  // Back to System, so the next test and the next run start from the device's scheme.
  await openHome(page);
  await avatar(page).click();
  if ((await themeOption(page, 'System').getAttribute('aria-pressed')) !== 'true') {
    await pickTheme(page, 'System');
  }
});

test('the avatar menu lists Profile, Security, Theme and Log out', async ({ page }) => {
  await openHome(page);
  await expect(avatar(page)).toHaveAttribute('aria-expanded', 'false');

  await avatar(page).click();

  await expect(avatar(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByRole('link', { name: 'Profile' })).toHaveAttribute('href', '/me/profile');
  await expect(page.getByRole('link', { name: 'Security' })).toHaveAttribute('href', '/me/security');
  await expect(page.getByRole('banner').getByRole('group', { name: 'Theme' }).getByRole('button')).toHaveText(['System', 'Light', 'Dark']);
  await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();
});

test('picking Dark applies it without a reload and a reload keeps it', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'light' });
  await openHome(page);
  await page.evaluate(() => {
    (window as unknown as { notReloaded: boolean }).notReloaded = true;
  });
  await avatar(page).click();

  await pickTheme(page, 'Dark');

  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await expect(themeOption(page, 'Dark')).toHaveAttribute('aria-pressed', 'true');
  await expect(themeOption(page, 'System')).toHaveAttribute('aria-pressed', 'false');
  expect(await page.evaluate(() => (window as unknown as { notReloaded?: boolean }).notReloaded)).toBe(true);
  await expect(page.getByRole('banner').getByRole('group', { name: 'Theme' })).toBeVisible();

  await page.reload({ waitUntil: 'networkidle' });

  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});

test('picking System removes data-theme so the device scheme decides', async ({ page }) => {
  await openHome(page);
  await avatar(page).click();
  await pickTheme(page, 'Light');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');

  await pickTheme(page, 'System');

  await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
  await page.reload({ waitUntil: 'networkidle' });
  await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
});

test('a failed save puts the previous theme back', async ({ page }) => {
  await openHome(page);
  await page.route('**/me/theme', (route) => route.fulfill({ status: 500, body: '' }));
  await avatar(page).click();

  await pickTheme(page, 'Dark');

  await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
  await expect(themeOption(page, 'System')).toHaveAttribute('aria-pressed', 'true');
  await expect(themeOption(page, 'Dark')).toHaveAttribute('aria-pressed', 'false');
  await page.unroute('**/me/theme');
});

test('a redirect to the login page counts as a failed save and puts the previous theme back', async ({ page }) => {
  await openHome(page);
  // What the firewall answers once the session has ended.
  await page.route('**/me/theme', (route) => route.fulfill({ status: 302, headers: { location: '/login' }, body: '' }));
  await avatar(page).click();

  await pickTheme(page, 'Dark');

  await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
  await expect(themeOption(page, 'System')).toHaveAttribute('aria-pressed', 'true');
  await page.unroute('**/me/theme');
});

test('a late failure of an older save keeps the newer pick', async ({ page }) => {
  await openHome(page);
  let releaseDarkSave: () => void = () => {};
  let saves = 0;
  await page.route('**/me/theme', async (route) => {
    saves += 1;
    if (saves === 1) {
      await new Promise<void>((resolve) => {
        releaseDarkSave = resolve;
      });
      await route.fulfill({ status: 500, body: '' });
      return;
    }
    await route.continue();
  });
  await avatar(page).click();
  await themeOption(page, 'Dark').click();
  await expect.poll(() => saves).toBe(1);
  await pickTheme(page, 'Light');

  // The controller warns at the end of its catch block, after any roll-back; the 500 response alone comes earlier.
  const darkSaveHandled = page.waitForEvent('console', (message) => message.type() === 'warning');
  releaseDarkSave();
  await darkSaveHandled;

  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await expect(themeOption(page, 'Light')).toHaveAttribute('aria-pressed', 'true');
  await expect(themeOption(page, 'Dark')).toHaveAttribute('aria-pressed', 'false');
  await page.unroute('**/me/theme');
});

test('Tab from the avatar after a mouse open moves into the menu', async ({ page }) => {
  await openHome(page);
  await avatar(page).click();
  await expect(avatar(page)).toBeFocused();

  await page.keyboard.press('Tab');

  await expect(avatar(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByRole('link', { name: 'Profile' })).toBeFocused();
});

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the open menu does not scroll the page horizontally at 360px', async ({ page }) => {
    await openHome(page);
    await avatar(page).click();
    await expect(page.getByRole('link', { name: 'Profile' })).toBeVisible();

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    const list = (await page.locator('#avatar-menu-list').boundingBox())!;
    expect(list.x).toBeGreaterThanOrEqual(0);
    expect(list.x + list.width).toBeLessThanOrEqual(360);
  });
});

test('on the profile page the theme radios follow the menu, so Save keeps the pick', async ({ page }) => {
  await page.goto('/me/profile', { waitUntil: 'networkidle' });
  await avatar(page).click();

  await pickTheme(page, 'Dark');

  await expect(page.getByRole('main').getByRole('radio', { name: 'Dark' })).toBeChecked();
});

test('the menu opens with Enter, closes with Escape and gives focus back to the avatar', async ({ page }) => {
  await openHome(page);
  await avatar(page).focus();

  await page.keyboard.press('Enter');

  await expect(avatar(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByRole('link', { name: 'Profile' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Security' })).toBeFocused();

  await page.keyboard.press('Escape');

  await expect(avatar(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByRole('link', { name: 'Profile' })).toBeHidden();
  await expect(avatar(page)).toBeFocused();
});

test('Tab past the last item closes the menu and moves on to the page', async ({ page }) => {
  // The profile page, because Home has nothing focusable after the header yet.
  await page.goto('/me/profile', { waitUntil: 'networkidle' });
  await avatar(page).focus();
  await page.keyboard.press('Enter');
  await page.getByRole('button', { name: 'Log out' }).focus();

  await page.keyboard.press('Tab');

  await expect(avatar(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByLabel('Display name')).toBeFocused();
});

for (const theme of ['Light', 'Dark'] as const) {
  test(`the open menu has no serious or critical accessibility violations in ${theme}`, async ({ page }) => {
    await openHome(page);
    await avatar(page).click();
    await pickTheme(page, theme);

    expect(await seriousViolations(page)).toEqual([]);
  });
}
