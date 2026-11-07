import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Lena (auth.setup.ts). Nothing here submits a form: a change or "Log out of all devices" would end the session the
// other specs share, and a wrong try would take one of Lena's 5 password checks per 15 minutes. The functional
// SecurityPageTest covers the submits.
test.use({ storageState: LENA_STORAGE_STATE });

async function openSecurityPage(page: Page): Promise<void> {
  await page.goto('/me/security');
  await expect(page.getByRole('heading', { name: 'Security', level: 1 })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test('the security page has the change password form and the log out of all devices button', async ({ page }) => {
  await openSecurityPage(page);

  await expect(page.getByRole('region', { name: 'Change password' })).toBeVisible();
  await expect(page.getByLabel('Current password')).toHaveAttribute('autocomplete', 'current-password');
  await expect(page.getByLabel('New password', { exact: true })).toHaveAttribute('minlength', '12');
  await expect(page.getByLabel('Repeat the new password')).toHaveAttribute('minlength', '12');
  await expect(page.getByRole('button', { name: 'Change password' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Log out of all devices' }).getByRole('button', { name: 'Log out of all devices' })).toBeVisible();
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the security page has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await openSecurityPage(page);

    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the security page does not scroll horizontally at 360px', async ({ page }) => {
    await openSecurityPage(page);

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
});
