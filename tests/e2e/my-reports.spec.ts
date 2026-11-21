import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Lena (auth.setup.ts), who reported every SHOP ticket of the demo seed, in steps of every group, Done among them.
// Nothing here changes data.
test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function openMyReports(page: Page): Promise<void> {
  await page.goto('/my-reports', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
}

test('My reports opens from the menu and shows each report as a card with its progress in words', async ({ page }) => {
  await page.goto('/', { waitUntil: 'networkidle' });
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: /^My reports/ }).click();

  await expect(page).toHaveURL(/\/my-reports$/);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('My reports');
  await expect(page.locator('.my-reports__intro')).toHaveText(/^\d+ tickets? you reported\. (None needs you right now\.|\d+ needs? your answer\.)$/);
  const cards = page.locator('.my-reports__group .my-reports__card');
  await expect(cards.first()).toBeVisible();
  const firstCard = cards.first();
  await expect(firstCard.getByRole('img', { name: /^Step [1-4] of 4: (Reported|Building|Checking|Done)(, waiting)?$/ })).toBeVisible();
  await expect(firstCard.locator('.my-reports__link')).toHaveAttribute('href', /^\/t\/SHOP-\d+$/);
  await expect(firstCard.locator('.my-reports__now')).not.toBeEmpty();
});

test('no card shows more than two buttons, and Answer names its ticket', async ({ page }) => {
  await openMyReports(page);

  const buttonCounts = await page.locator('.my-reports__card').evaluateAll((cards) => cards.map((card) => card.querySelectorAll('.btn, button').length));
  expect(buttonCounts.length).toBeGreaterThan(0);
  expect(Math.max(...buttonCounts)).toBeLessThanOrEqual(2);
  for (const answer of await page.locator('a.my-reports__answer').all()) {
    await expect(answer).toHaveAccessibleName(/^Answer on SHOP-\d+$/);
  }
});

test('Show N done opens the done reports and then reads Hide N done', async ({ page }) => {
  await openMyReports(page);
  const done = page.locator('details.my-reports__done');
  const summary = done.locator('summary');
  await expect(summary).toHaveText(/^Show (\d+) done$/);
  const count = (await summary.textContent())?.match(/\d+/)?.[0] ?? '';
  await expect(done.locator('.my-reports__card')).toHaveCount(Number(count));
  await expect(done.locator('.my-reports__card').first()).toBeHidden();

  await summary.click();

  await expect(done).toHaveAttribute('open', '');
  await expect(summary).toHaveText(`Hide ${count} done`);
  await expect(summary).toHaveAccessibleName(`Hide ${count} done`);
  await expect(done.locator('.my-reports__card').first()).toBeVisible();

  await summary.click();

  await expect(summary).toHaveText(`Show ${count} done`);
});

for (const width of [1280, 360]) {
  test(`My reports has no serious accessibility violation and no sideways scroll at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await openMyReports(page);

    expect(await seriousViolations(page)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
  });
}
