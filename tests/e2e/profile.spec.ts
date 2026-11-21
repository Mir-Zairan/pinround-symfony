import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { OMAR_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Omar (auth.setup.ts), like avatar-menu.spec.ts. Nothing here saves: the functional ProfilePageTest covers the submits.
test.use({ storageState: OMAR_STORAGE_STATE });

async function openProfilePage(page: Page): Promise<void> {
  await page.goto('/me/profile');
  await expect(page.getByRole('heading', { name: 'Profile', level: 1 })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test('the profile page has the name, the time zone, the theme and a Save button', async ({ page }) => {
  await openProfilePage(page);

  await expect(page.getByLabel('Display name')).toHaveValue('Omar');
  await expect(page.getByLabel('Time zone')).toBeVisible();
  await expect(page.getByRole('group', { name: 'Theme' }).getByRole('radio')).toHaveCount(3);
  await expect(page.getByRole('button', { name: 'Save' })).toBeVisible();
});

test('the theme choice looks like the avatar menu\'s: the chosen theme is filled as the menu\'s pressed segment', async ({ page }) => {
  await openProfilePage(page);
  const chosenTheme = page.getByRole('group', { name: 'Theme' }).locator('label', { has: page.locator('input:checked') });
  const pressedMenuTheme = page.locator('.avatar-menu__theme .seg[aria-pressed="true"]');
  const looksOf = (element: Element) => {
    const style = getComputedStyle(element);
    return { background: style.backgroundColor, height: style.height, radius: style.borderRadius };
  };

  await expect(chosenTheme).toHaveCount(1);
  expect(await chosenTheme.evaluate(looksOf)).toEqual(await pressedMenuTheme.evaluate(looksOf));
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the profile page has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await openProfilePage(page);

    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the profile page does not scroll horizontally at 360px', async ({ page }) => {
    await openProfilePage(page);

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
});
