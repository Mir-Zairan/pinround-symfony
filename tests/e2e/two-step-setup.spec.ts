import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { OMAR_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Omar, like the other specs that save something: "Set up two-step login" stores a new seed that waits for a code.
// It stays off until a code is typed, which this spec never does, so Omar's logins are unchanged; each run replaces
// the seed. The functional TwoStepLoginSetupTest covers the code, the recovery codes and the refusals.
test.use({ storageState: OMAR_STORAGE_STATE });

async function openSetupWithAQrCode(page: Page): Promise<void> {
  await page.goto('/me/security/two-step');
  await expect(page.getByRole('heading', { name: 'Two-step login', level: 1 })).toBeVisible();
  const getNewQrCode = page.getByRole('button', { name: 'Get a new QR code' });
  const setUp = page.getByRole('button', { name: 'Set up two-step login' });
  await expect(getNewQrCode.or(setUp)).toBeVisible();
  await ((await setUp.isVisible()) ? setUp : getNewQrCode).click();
  await expect(page.getByRole('img', { name: 'QR code for your authenticator app' })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test('the setup page shows the QR code, the key and the code field', async ({ page }) => {
  await openSetupWithAQrCode(page);

  await expect(page.locator('main .two-step__key')).toHaveText(/^[A-Z2-7]{4}( [A-Z2-7]{4}){7}$/);
  await expect(page.getByLabel('Code from the app')).toHaveAttribute('autocomplete', 'one-time-code');
  await expect(page.getByRole('button', { name: 'Turn on' })).toBeVisible();
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the setup page has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await openSetupWithAQrCode(page);

    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the setup page does not scroll horizontally at 360px', async ({ page }) => {
    await openSetupWithAQrCode(page);

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
});
