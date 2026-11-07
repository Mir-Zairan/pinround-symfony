import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const LINK_SENT = 'If an account uses this email, we sent a link to reset the password. It works for 1 hour.';
const LINK_NO_LONGER_WORKS = 'This link no longer works. Ask for a new one.';

// The reset pages are for people who are not logged in, whatever the other specs share.
test.use({ storageState: { cookies: [], origins: [] } });

type View = { name: string; open: (page: Page) => Promise<void> };

// Each "link sent" visit takes one try of the per-IP limit (50 per hour), so the address is new on every run.
const VIEWS: View[] = [
  { name: 'forgot password', open: async (page) => { await page.goto('/password/forgot'); } },
  {
    name: 'link sent',
    open: async (page) => {
      await page.goto('/password/forgot');
      await page.getByLabel('Email').fill(`nobody.${Date.now()}.${Math.floor(Math.random() * 1e6)}@acme-foods.example`);
      await page.getByRole('button', { name: 'Send link' }).click();
      await expect(page.getByRole('main').getByRole('status')).toHaveText(LINK_SENT);
    },
  },
  {
    name: 'link no longer works',
    open: async (page) => {
      await page.goto('/password/reset/not-a-token-anyone-was-sent');
      await expect(page.getByRole('alert')).toHaveText(LINK_NO_LONGER_WORKS);
    },
  },
];

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test('the login page links to forgot password, which asks for one email', async ({ page }) => {
  await page.goto('/login');

  await page.getByRole('link', { name: 'Forgot password?' }).click();

  await expect(page).toHaveURL(/\/password\/forgot$/);
  await expect(page.getByRole('heading', { name: 'Forgot password' })).toBeVisible();
  await expect(page.getByLabel('Email')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Send link' })).toBeVisible();
});

test('the link moves to an address without the token', async ({ page }) => {
  await page.goto('/password/reset/not-a-token-anyone-was-sent');

  await expect(page).toHaveURL(/\/password\/reset$/);
  await expect(page.getByRole('link', { name: 'Ask for a new link' })).toHaveAttribute('href', '/password/forgot');
});

for (const view of VIEWS) {
  for (const scheme of ['light', 'dark'] as const) {
    test(`the ${view.name} view has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await view.open(page);
      await page.evaluate(() => document.fonts.ready);

      expect(await seriousViolations(page)).toEqual([]);
    });
  }
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  for (const view of VIEWS) {
    test(`the ${view.name} view does not scroll horizontally at 360px`, async ({ page }) => {
      await view.open(page);

      const { scrollWidth, clientWidth } = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }));
      expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
    });
  }
});
