import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const TOAST_REGION = '#toast-region';

// As Omar (auth.setup.ts), whose pings the bell shows.
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
}

function bell(page: Page) {
  return page.getByRole('banner').getByRole('button', { name: 'Pings' });
}

function popover(page: Page) {
  return page.getByRole('dialog', { name: /^Pings · \d+ open$/ });
}

async function openPopover(page: Page): Promise<void> {
  const frameLoaded = page.waitForResponse((response) => new URL(response.url()).pathname === '/bell' && response.request().method() === 'GET');
  await bell(page).click();
  expect((await frameLoaded).status()).toBe(200);
  await expect(popover(page)).toBeVisible();
}

/** The badge's count of open notifications, 0 when the badge shows none. */
async function badgeCountOf(page: Page): Promise<number> {
  const spokenCount = page.locator('#bell-badge .visually-hidden');
  if ((await spokenCount.count()) === 0) {
    return 0;
  }
  const match = /^(\d+) open$/.exec((await spokenCount.innerText()).trim());
  expect(match, 'the badge names its count as "N open"').not.toBeNull();
  return Number(match![1]);
}

/**
 * As Lena, who leads Shop: makes a SHOP ticket given to Omar, with a title no earlier run used, and pings him about it.
 * A fresh ticket keeps the ping limit (4 h per sender, person and ticket) from refusing a rerun, and leaves the seeded
 * tickets the other specs read alone. Returns the ticket's key.
 */
async function pingOmarOnNewTicket(browser: Browser, message: string): Promise<string> {
  const context = await browser.newContext({ storageState: LENA_STORAGE_STATE, ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } });
  const page = await context.newPage();
  await page.goto('/tickets/new');
  const shopValue = await page.locator('select[name="project_id"] option', { hasText: /^SHOP · / }).getAttribute('value');
  expect(shopValue, 'the seeded SHOP project is missing: run make seed').not.toBeNull();
  await page.getByLabel('Project').selectOption(shopValue ?? '');
  await page.getByLabel('Title').fill(`Check the bell ${randomUUID().slice(0, 8)}`);
  await page.getByLabel('Give it to').selectOption({ label: 'Omar' });
  await page.getByRole('button', { name: 'Make ticket' }).click();
  await expect(page).toHaveURL(/\/t\/SHOP-\d+$/);
  const key = new URL(page.url()).pathname.replace('/t/', '');
  await expect(page.locator(TOAST_REGION)).toHaveText(`Ticket ${key} made.`);

  await page.reload({ waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ping Omar' }).click();
  const sheet = page.getByRole('dialog', { name: 'Ping Omar' });
  await expect(sheet.locator('.ping-sheet__ticket')).toHaveText(new RegExp(`^${key} · `));
  await sheet.getByLabel('Message').fill(message);
  await sheet.getByRole('button', { name: 'Send ping' }).click();
  await expect(page.getByText(/^Pinged · /)).toBeVisible();
  await context.close();
  return key;
}

test('the bell opens the Pings popover, loads its frame and focuses it', async ({ page }) => {
  await openHome(page);
  await expect(bell(page)).toHaveAttribute('aria-expanded', 'false');

  await openPopover(page);

  await expect(bell(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(popover(page)).toBeFocused();
  await expect(popover(page).getByText('Grouped by person and ticket.')).toBeVisible();
  await expect(popover(page).getByRole('button', { name: 'Close' })).toBeVisible();
});

test('Escape closes the popover and puts focus back on the bell', async ({ page }) => {
  await openHome(page);
  await openPopover(page);

  await page.keyboard.press('Escape');

  await expect(popover(page)).toBeHidden();
  await expect(bell(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(bell(page)).toBeFocused();
});

test('Close closes the popover and puts focus back on the bell', async ({ page }) => {
  await openHome(page);
  await openPopover(page);

  await popover(page).getByRole('button', { name: 'Close' }).click();

  await expect(popover(page)).toBeHidden();
  await expect(bell(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(bell(page)).toBeFocused();
});

test.describe('a ping from Lena', () => {
  const message = `Can you look at the basket total? ${randomUUID().slice(0, 8)}`;
  let key = '';

  test.beforeAll(async ({ browser }) => {
    key = await pingOmarOnNewTicket(browser, message);
  });

  function lenasCard(page: Page) {
    // A rerun's earlier ping on the same ticket would make the group "2 pings", so only the sender and key are fixed.
    return popover(page).getByRole('article', { name: new RegExp(`^Lena .+ on ${key}$`) });
  }

  test('Done takes the card out of the popover and drops the badge by one', async ({ page }) => {
    // The ping reaches the bell through the outbox and its worker, a moment after Lena sent it.
    await expect(async () => {
      await openHome(page);
      await openPopover(page);
      await expect(lenasCard(page)).toBeVisible({ timeout: 1_000 });
    }).toPass({ intervals: [500, 1_000, 2_000], timeout: 20_000 });
    await expect(lenasCard(page)).toHaveAccessibleName(`Lena pinged you on ${key}`);
    await expect(lenasCard(page).getByText(`“${message}”`)).toBeVisible();
    const countBefore = await badgeCountOf(page);
    expect(countBefore).toBeGreaterThan(0);

    await lenasCard(page).getByRole('button', { name: 'Done' }).click();

    await expect(page.locator(TOAST_REGION)).not.toBeEmpty();
    await expect.poll(() => badgeCountOf(page)).toBe(countBefore - 1);
    await openHome(page);
    await openPopover(page);
    await expect(popover(page)).toHaveAccessibleName(`Pings · ${countBefore - 1} open`);
    await expect(lenasCard(page)).toHaveCount(0);
  });
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the open popover has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await openHome(page);
    await openPopover(page);

    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the open popover stays inside the viewport at 360px', async ({ page }) => {
    await openHome(page);
    await openPopover(page);

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    const box = (await popover(page).boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(360);
  });
});
