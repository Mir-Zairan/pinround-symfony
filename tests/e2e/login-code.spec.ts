import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { demoPassword, logInAndTurnOnTwoStepLogin, MIA_EMAIL, turnOffTwoStepLogin, twoStepCodesFile } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Mia, whom no other spec logs in as: beforeAll turns her two-step login on, the last test turns it off on the
// security page with a recovery code, and afterAll turns it off when a test stopped before that. A run takes one of her
// 5 code tries in 15 minutes and never waits for a new 30-second step. The functional LoginCodeTest and
// TwoStepLoginOffTest cover the codes, the refusals and the throttle.
const MIA_LOGIN_CODE_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'mia-login-code.json');

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await logInAndTurnOnTwoStepLogin(await context.newPage(), MIA_EMAIL, 'Mia');
  await context.storageState({ path: MIA_LOGIN_CODE_STORAGE_STATE });
  await context.close();
});

test.afterAll(async ({ browser }) => {
  if (!existsSync(twoStepCodesFile(MIA_EMAIL)) || !existsSync(MIA_LOGIN_CODE_STORAGE_STATE)) {
    return;
  }
  const context = await browser.newContext({ storageState: MIA_LOGIN_CODE_STORAGE_STATE });
  await turnOffTwoStepLogin(await context.newPage(), MIA_EMAIL);
  await context.close();
});

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function horizontalOverflow(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

test.describe('the code page', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  async function openCodePage(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(MIA_EMAIL);
    await page.getByLabel('Password').fill(demoPassword());
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByRole('heading', { name: 'Type your code', level: 1 })).toBeVisible();
    await expect(page).toHaveURL(/\/login\/code$/);
    await page.evaluate(() => document.fonts.ready);
  }

  test('the code page shows the code field, the hint and the two buttons', async ({ page }) => {
    await openCodePage(page);

    const codeField = page.getByLabel('Code from your app');
    await expect(codeField).toHaveAttribute('autocomplete', 'one-time-code');
    await expect(codeField).toBeFocused();
    await expect(page.getByText('6 digits. No phone with you? Type one of your recovery codes instead.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Cancel' })).toBeVisible();
  });

  for (const scheme of ['light', 'dark'] as const) {
    test(`the code page has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await openCodePage(page);

      expect(await seriousViolations(page)).toEqual([]);
    });
  }

  test.describe('narrow viewport', () => {
    test.use({ viewport: { width: 360, height: 800 } });

    test('the code page does not scroll horizontally at 360px', async ({ page }) => {
      await openCodePage(page);

      expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    });
  });
});

test.describe('the turn-off form on the security page', () => {
  test.use({ storageState: MIA_LOGIN_CODE_STORAGE_STATE });

  async function openSecurityPage(page: Page): Promise<void> {
    await page.goto('/me/security');
    await expect(page.getByRole('button', { name: 'Turn off two-step login' })).toBeVisible();
    await page.evaluate(() => document.fonts.ready);
  }

  test('the form asks for the password and a code', async ({ page }) => {
    await openSecurityPage(page);

    await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('autocomplete', 'current-password');
    await expect(page.getByLabel('Code from your app')).toHaveAttribute('autocomplete', 'one-time-code');
    await expect(page.getByText('To turn it off, type your password and a code. Admin pages need two-step login.')).toBeVisible();
  });

  for (const scheme of ['light', 'dark'] as const) {
    test(`the security page with the form has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await openSecurityPage(page);

      expect(await seriousViolations(page)).toEqual([]);
    });
  }

  test.describe('narrow viewport', () => {
    test.use({ viewport: { width: 360, height: 800 } });

    test('the security page with the form does not scroll horizontally at 360px', async ({ page }) => {
      await openSecurityPage(page);

      expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    });
  });

  test('the password and a recovery code turn two-step login off', async ({ page }) => {
    await turnOffTwoStepLogin(page, MIA_EMAIL);

    await expect(page.getByRole('link', { name: 'Set up two-step login' })).toBeVisible();
  });
});
