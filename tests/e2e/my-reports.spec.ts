import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { LENA_STORAGE_STATE } from './auth';
import { makeTicketForOmar } from './ticket';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Lena (auth.setup.ts), who reported every SHOP ticket of the demo seed, in steps of every group, Done among them,
// and every ticket other specs make: how many she has, and on which page the seeded Done ones fall, changes with each
// run. The specs read only the ticket they make and the counts the page states about itself.
test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });
// 50 reports a page (HomeQueries::MY_REPORTS_PER_PAGE): far more reports than any run of the suite leaves behind.
const MAX_PAGES_TO_DONE = 40;

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

/** Opens My reports and follows "Show more" to the first page with a Done fold. */
async function openFirstPageWithDoneReports(page: Page): Promise<void> {
  await openMyReports(page);
  for (let pageNumber = 1; pageNumber <= MAX_PAGES_TO_DONE; pageNumber++) {
    if ((await page.locator('details.my-reports__done').count()) > 0) {
      return;
    }
    const nextPage = page.locator('a.my-reports__next-page');
    expect(await nextPage.count(), 'Lena has no done report on any page: run make seed').toBe(1);
    await page.goto((await nextPage.getAttribute('href')) ?? '', { waitUntil: 'networkidle' });
  }
  throw new Error(`No Done fold on the first ${MAX_PAGES_TO_DONE} pages of Lena's reports: run make seed.`);
}

test('My reports opens from the menu and shows each report as a card with its progress in words', async ({ browser, page }) => {
  const title = `Basket badge counts a removed item ${randomUUID().slice(0, 8)}`;
  const key = await makeTicketForOmar(browser, title);
  await page.goto('/', { waitUntil: 'networkidle' });
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: /^My reports/ }).click();

  await expect(page).toHaveURL(/\/my-reports$/);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('My reports');
  // Regex expectations get the text as rendered, so the template's line break between the sentences is \s+ here.
  await expect(page.locator('.my-reports__intro')).toHaveText(/^[\d,]+ tickets? you reported\.\s+(None needs you right now\.|[\d,]+ needs? your answer\.)$/);
  // Made just now, so not Stuck: in Moving, on the first page.
  const card = page.locator('.my-reports__group--moving .my-reports__card').filter({ has: page.locator(`a.my-reports__link[href="/t/${key}"]`) });
  await expect(card).toBeVisible();
  await expect(card.locator('.my-reports__title')).toHaveText(title);
  await expect(card.getByRole('img', { name: 'Step 1 of 4: Reported' })).toBeVisible();
  await expect(card.locator('.my-reports__now')).not.toBeEmpty();
});

test('Ping on a card opens the ping sheet on My reports, sends from there and leaves the card pinged', async ({ browser, page }) => {
  const key = await makeTicketForOmar(browser, `Coupon field clears on reload ${randomUUID().slice(0, 8)}`);
  await openMyReports(page);
  const card = page.locator('.my-reports__card').filter({ has: page.locator(`a.my-reports__link[href="/t/${key}"]`) });
  const ping = card.getByRole('button', { name: `Ping Omar about ${key}` });

  await ping.click();

  const sheet = page.getByRole('dialog', { name: 'Ping Omar' });
  await expect(sheet).toBeVisible();
  await expect(page).toHaveURL(/\/my-reports$/);
  await expect(sheet.getByLabel('Message')).toHaveValue(`Hi Omar, could you look at ${key}?`);
  await sheet.getByRole('button', { name: 'Cancel' }).click();
  await expect(sheet).toBeHidden();
  await expect(ping, 'focus is back on the card\'s Ping').toBeFocused();

  await ping.click();
  await expect(sheet).toBeVisible();
  await sheet.getByRole('button', { name: 'Send ping' }).click();

  await expect(page.locator('#toast-region')).toContainText('Pinged Omar. One notification sent.');
  await expect(page).toHaveURL(/\/my-reports$/);
  await expect(card.locator('.ping-sheet__pinged')).toHaveText(/^Pinged · again in \d+ h$/);
  await expect(ping, 'the pinged state takes the button\'s place').toBeHidden();
});

test('Add more info opens the ticket on its comment box, focused, with the toast saying where to write', async ({ browser, page }) => {
  const key = await makeTicketForOmar(browser, `Gift card total rounds down ${randomUUID().slice(0, 8)}`);
  await openMyReports(page);
  const card = page.locator('.my-reports__card').filter({ has: page.locator(`a.my-reports__link[href="/t/${key}"]`) });

  await card.getByRole('button', { name: `More for ${key}` }).click();
  await card.getByRole('menuitem', { name: 'Add more info' }).click();

  const composer = page.locator('turbo-frame#ticket-thread #thread-composer-body');
  await expect(composer).toBeFocused();
  await expect(page.locator('#toast-region')).toHaveText('Write your info in the comment box.');
  await expect(page.getByRole('group', { name: 'What to write' }).getByRole('button', { name: 'Comment' })).toHaveAttribute('aria-pressed', 'true');
  expect(new URL(page.url()).search, 'the parameter leaves the address, so a reload does not repeat it').toBe('');
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
  await openFirstPageWithDoneReports(page);
  const done = page.locator('details.my-reports__done');
  const summary = done.locator('summary');
  // The summary holds both texts and my-reports.css hides one; toHaveText would read them both, the name only the shown one.
  await expect(summary).toHaveAccessibleName(/^Show [1-9]\d* done$/);
  const count = (await summary.locator('.my-reports__when-closed').textContent())?.match(/\d+/)?.[0] ?? '';
  await expect(summary.locator('.my-reports__when-closed')).toHaveText(`Show ${count} done`);
  await expect(summary.locator('.my-reports__when-open')).toBeHidden();
  await expect(done.locator('.my-reports__card')).toHaveCount(Number(count));
  await expect(done.locator('.my-reports__card').first()).toBeHidden();

  await summary.click();

  await expect(done).toHaveAttribute('open', '');
  await expect(summary).toHaveAccessibleName(`Hide ${count} done`);
  await expect(summary.locator('.my-reports__when-open')).toBeVisible();
  await expect(summary.locator('.my-reports__when-closed')).toBeHidden();
  await expect(done.locator('.my-reports__card').first()).toBeVisible();
  // Only done reports are folded here: every card's dots are at the last stage.
  await expect(done.getByRole('img', { name: 'Step 4 of 4: Done' })).toHaveCount(Number(count));

  await summary.click();

  await expect(summary).toHaveAccessibleName(`Show ${count} done`);
  await expect(done.locator('.my-reports__card').first()).toBeHidden();
});

for (const width of [1280, 360]) {
  test(`My reports has no serious accessibility violation and no sideways scroll at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await openMyReports(page);

    expect(await seriousViolations(page)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
  });
}
