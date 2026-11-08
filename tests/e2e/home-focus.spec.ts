import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { demoPassword, OMAR_EMAIL, OMAR_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Omar (auth.setup.ts), whose choice this spec saves, so Lena's shell baselines keep Home on All.
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

function chip(page: Page, name: string) {
  return page.getByRole('group', { name: 'Show on Home' }).getByRole('button', { name, exact: true });
}

function section(page: Page, title: string) {
  return page.getByRole('heading', { level: 2, name: title, exact: true });
}

async function pressChip(page: Page, name: string): Promise<void> {
  const saved = page.waitForResponse((response) => response.url().endsWith('/home/focus') && response.request().method() === 'POST');
  await chip(page, name).click();
  expect((await saved).status()).toBe(204);
}

test.afterEach(async ({ page }) => {
  // Omar's choice is kept on the server; leave it on All for the next run and the other specs.
  await openHome(page);
  if ((await chip(page, 'All').getAttribute('aria-pressed')) !== 'true') {
    await pressChip(page, 'All');
  }
});

test('Omar gets the chips All, My work and My team with All pressed', async ({ page }) => {
  await openHome(page);

  await expect(page.getByRole('group', { name: 'Show on Home' }).getByRole('button')).toHaveText(['All', 'My work', 'My team']);
  await expect(chip(page, 'All')).toHaveAttribute('aria-pressed', 'true');
  for (const title of ['Needs you now', 'Also waiting on you', 'Pings for you', 'Your team']) {
    await expect(section(page, title)).toBeVisible();
  }
});

test('a chip hides and shows the sections at once', async ({ page }) => {
  await openHome(page);

  await pressChip(page, 'My team');

  await expect(chip(page, 'My team')).toHaveAttribute('aria-pressed', 'true');
  await expect(chip(page, 'All')).toHaveAttribute('aria-pressed', 'false');
  await expect(section(page, 'Your team')).toBeVisible();
  await expect(section(page, 'Needs you now')).toBeHidden();
  await expect(section(page, 'Pings for you')).toBeHidden();

  await pressChip(page, 'My work');

  await expect(section(page, 'Needs you now')).toBeVisible();
  await expect(section(page, 'Your team')).toBeHidden();

  await pressChip(page, 'All');

  await expect(section(page, 'Your team')).toBeVisible();
  await expect(section(page, 'Needs you now')).toBeVisible();
});

test('a failed save puts the previous chip and its sections back', async ({ page }) => {
  await openHome(page);
  await page.route('**/home/focus', (route) => route.fulfill({ status: 500 }));

  await chip(page, 'My team').click();

  await expect(chip(page, 'All')).toHaveAttribute('aria-pressed', 'true');
  await expect(chip(page, 'My team')).toHaveAttribute('aria-pressed', 'false');
  await expect(section(page, 'Needs you now')).toBeVisible();
  await page.unroute('**/home/focus');
});

test('the choice survives a reload and a fresh login', async ({ page, browser }) => {
  await openHome(page);
  await pressChip(page, 'My team');

  await page.reload({ waitUntil: 'networkidle' });

  await expect(chip(page, 'My team')).toHaveAttribute('aria-pressed', 'true');
  await expect(section(page, 'Needs you now')).toBeHidden();

  const freshContext = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  try {
    const freshPage = await freshContext.newPage();
    await freshPage.goto('/login');
    await freshPage.getByLabel('Email').fill(OMAR_EMAIL);
    await freshPage.getByLabel('Password').fill(demoPassword());
    await freshPage.getByRole('button', { name: 'Log in' }).click();

    await expect(chip(freshPage, 'My team')).toHaveAttribute('aria-pressed', 'true');
    await expect(section(freshPage, 'Needs you now')).toBeHidden();
    await expect(section(freshPage, 'Your team')).toBeVisible();
  } finally {
    await freshContext.close();
  }
});

for (const scheme of ['light', 'dark'] as const) {
  test(`Home has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await openHome(page);
    expect(await seriousViolations(page)).toEqual([]);

    await pressChip(page, 'My work');
    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('Home does not scroll horizontally at 360px', async ({ page }) => {
    await openHome(page);

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
});
