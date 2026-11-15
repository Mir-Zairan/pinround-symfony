import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { bellButton, bellPopover, expectOmarUnderBellCaps, openBellPopover } from './bell';

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
  await expect(bellButton(page)).toHaveAttribute('aria-expanded', 'false');

  await openBellPopover(page);

  await expect(bellButton(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(bellPopover(page)).toBeFocused();
  await expect(bellPopover(page).getByText('Grouped by person and ticket.')).toBeVisible();
  await expect(bellPopover(page).getByRole('button', { name: 'Close' })).toBeVisible();
});

test('Escape closes the popover and puts focus back on the bell', async ({ page }) => {
  await openHome(page);
  await openBellPopover(page);

  await page.keyboard.press('Escape');

  await expect(bellPopover(page)).toBeHidden();
  await expect(bellButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(bellButton(page)).toBeFocused();
});

test('Close closes the popover and puts focus back on the bell', async ({ page }) => {
  await openHome(page);
  await openBellPopover(page);

  await bellPopover(page).getByRole('button', { name: 'Close' }).click();

  await expect(bellPopover(page)).toBeHidden();
  await expect(bellButton(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(bellButton(page)).toBeFocused();
});

/**
 * The badge's count as the server renders it now, read from a fresh Home page in Omar's session, so it is taken a moment
 * before the next click rather than when the open page loaded.
 */
async function serverBadgeCountOf(page: Page): Promise<number> {
  const home = await page.request.get('/');
  expect(home.status()).toBe(200);
  const spokenCount = await page.evaluate(
    (html) => new DOMParser().parseFromString(html, 'text/html').querySelector('#bell-badge .visually-hidden')?.textContent ?? null,
    await home.text(),
  );
  if (null === spokenCount) {
    return 0;
  }
  const match = /^(\d+) open$/.exec(spokenCount.trim());
  expect(match, 'the badge names its count as "N open"').not.toBeNull();
  return Number(match![1]);
}

function lenasCardOn(page: Page, key: string) {
  return bellPopover(page).getByRole('article', { name: `Lena pinged you on ${key}` });
}

// The badge counts every open notification of Omar, and the specs running beside this one notify him and clear his
// pings at any moment, so no read taken seconds before Done can be compared with the count after it. Each try reads
// the count just before the click and compares it with the count Done's own answer draws. A try whose drop is not one,
// as when another spec's change landed in between, is spent, and the next one pings Omar on a new ticket; a Done that
// drops the badge by anything but one fails every try.
const DONE_TRIES = 3;

test('Done takes the card out of the popover and drops the badge by one', async ({ browser, page }) => {
  expectOmarUnderBellCaps();
  test.setTimeout(DONE_TRIES * 30_000);
  const drops: number[] = [];
  let key = '';
  for (let attempt = 1; attempt <= DONE_TRIES && drops.at(-1) !== 1; attempt++) {
    const message = `Can you look at the basket total? ${randomUUID().slice(0, 8)}`;
    key = await pingOmarOnNewTicket(browser, message);
    // The ping reaches the bell through the outbox and its worker, a moment after Lena sent it.
    await expect(async () => {
      await openHome(page);
      await openBellPopover(page);
      await expect(lenasCardOn(page, key)).toBeVisible({ timeout: 1_000 });
    }).toPass({ intervals: [500, 1_000, 2_000], timeout: 20_000 });
    await expect(lenasCardOn(page, key).getByText(`“${message}”`)).toBeVisible();

    const countBefore = await serverBadgeCountOf(page);
    expect(countBefore, 'the badge counts the ping on screen').toBeGreaterThan(0);
    const answer = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/done') && response.request().method() === 'POST');
    await lenasCardOn(page, key).getByRole('button', { name: 'Done' }).click();
    expect((await answer).status()).toBe(200);

    // The toast comes last in Done's stream, so the badge and the popover are already redrawn from the same answer.
    await expect(page.locator(TOAST_REGION)).not.toBeEmpty();
    const countAfter = await badgeCountOf(page);
    await expect(bellPopover(page)).toHaveAccessibleName(`Pings · ${countAfter} open`);
    await expect(lenasCardOn(page, key)).toHaveCount(0);
    drops.push(countBefore - countAfter);
  }

  expect(drops.at(-1), `the badge dropped by ${drops.join(', then ')} over ${drops.length} tries`).toBe(1);
  // After a reload too: Done ended the group on the server, not only on the open page.
  await openHome(page);
  await openBellPopover(page);
  await expect(lenasCardOn(page, key)).toHaveCount(0);
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the open popover has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await openHome(page);
    await openBellPopover(page);

    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the open popover stays inside the viewport at 360px', async ({ page }) => {
    await openHome(page);
    await openBellPopover(page);

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    const box = (await bellPopover(page).boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(360);
  });
});
