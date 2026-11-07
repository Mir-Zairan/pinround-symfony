import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { demoPassword, LENA_EMAIL } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

// The login page is for people who are not logged in, whatever the other specs share.
test.use({ storageState: { cookies: [], origins: [] } });

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function submitLogin(page: Page, password: string): Promise<void> {
  await page.getByLabel('Email').fill(LENA_EMAIL);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Log in' }).click();
}

test('the login card shows the fields and the button', async ({ page }) => {
  await page.goto('/login');

  await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible();
  await expect(page.getByLabel('Email')).toBeVisible();
  await expect(page.getByLabel('Password')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the login page has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await page.goto('/login');
    await page.evaluate(() => document.fonts.ready);

    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the login page does not scroll horizontally at 360px', async ({ page }) => {
    await page.goto('/login');

    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
  });
});

test('a wrong password shows one neutral message and stays on the login page', async ({ page }) => {
  await page.goto('/login');

  await submitLogin(page, 'not-the-demo-password');

  await expect(page.getByRole('alert')).toHaveText('Email or password is wrong');
  await expect(page).toHaveURL(/\/login$/);
});

test('a correct login lands on Home showing the initials of Lena', async ({ page }) => {
  await page.goto('/login');

  await submitLogin(page, demoPassword());

  await expect(page).toHaveURL(/\/$/);
  const avatar = page.getByRole('button', { name: 'Lena', exact: true });
  await expect(avatar).toHaveText('L');
  await page.waitForLoadState('networkidle');
  await avatar.click();
  await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();
});
