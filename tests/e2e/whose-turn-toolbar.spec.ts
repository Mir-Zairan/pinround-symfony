import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

// As Lena, on the seeded stack (make seed: 41 SHOP tickets, several with "basket" in the title). WhoseTurnTest proves on
// the server that the project and the search narrow the cards; this spec proves what only a browser shows: the toolbar
// reloads only the cards' frame, and at phone width no ticket key breaks across lines. A full page load would reset
// window, so a test puts a marker there and checks it afterwards.
test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

const PHONE = { width: 390, height: 844 };
const NO_CARDS = 'The e2e database holds no waiting tickets (run make seed). WhoseTurnTest proves the toolbar on the server.';

async function openWhoseTurn(page: Page, path = '/tickets/turn?project=SHOP'): Promise<void> {
  await page.goto(path, { waitUntil: 'networkidle' });
  test.skip((await page.locator('.turn__card').count()) === 0, NO_CARDS);
  await page.evaluate(() => {
    (window as any).__beforeFilters = true;
  });
}

async function isSamePageLoad(page: Page): Promise<boolean> {
  return (await page.evaluate(() => (window as any).__beforeFilters)) === true;
}

function cardsFrameLoaded(page: Page) {
  return page.waitForResponse((response) => response.request().headers()['turbo-frame'] === 'ticket-list-frame' && response.ok());
}

async function shownTitles(page: Page): Promise<string[]> {
  return page.locator('.turn__title').allInnerTexts();
}

test('the toolbar has the search and Show done, and no people', async ({ page }) => {
  await openWhoseTurn(page);

  await expect(page.getByRole('searchbox', { name: 'Search by key or words in the title' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Show done', exact: true })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Assigned to' })).toHaveCount(0);
  await expect(page.getByRole('navigation', { name: 'Projects' })).toBeVisible();
});

test('typing a search narrows the cards without a page load', async ({ page }) => {
  await openWhoseTurn(page);
  const titlesBefore = await shownTitles(page);
  // The search must leave some rows out, or this test could not tell narrowed cards from the whole view.
  expect(titlesBefore.some((title) => !/basket/i.test(title))).toBe(true);
  const total = page.locator('.tickets__total');
  const totalBefore = await total.innerText();

  const loaded = cardsFrameLoaded(page);
  await page.getByRole('searchbox', { name: 'Search by key or words in the title' }).pressSequentially('basket');
  await loaded;

  await expect(page).toHaveURL(/\/tickets\/turn\?project=SHOP&q=basket$/);
  await expect(total).not.toHaveText(totalBefore);
  await expect(total).toHaveText(/ · grouped by who acts next$/);
  const titlesAfter = await shownTitles(page);
  expect(titlesAfter.length).toBeGreaterThan(0);
  for (const title of titlesAfter) {
    expect(title).toMatch(/basket/i);
  }
  expect(await isSamePageLoad(page)).toBe(true);
});

test.describe('at 390 px wide', () => {
  test.use({ viewport: PHONE });

  test('no ticket key wraps and the page does not scroll sideways', async ({ page }) => {
    await openWhoseTurn(page);

    // An inline box that breaks across lines has one client rect per line.
    const keyLineCounts = await page.locator('.turn__key').evaluateAll((keys) => keys.map((key) => ({ key: key.textContent, lines: key.getClientRects().length })));
    expect(keyLineCounts.length).toBeGreaterThan(0);
    expect(keyLineCounts.filter((key) => key.lines !== 1)).toEqual([]);
    const { scrollWidth, clientWidth } = await page.evaluate(() => {
      const scrollingElement = document.scrollingElement ?? document.documentElement;
      return { scrollWidth: scrollingElement.scrollWidth, clientWidth: scrollingElement.clientWidth };
    });
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
  });

  test('"Show N more" is a text button inside its card', async ({ page }) => {
    await openWhoseTurn(page);
    const more = page.getByRole('link', { name: /^Show [\d,]+ more$/ }).first();
    test.skip((await more.count()) === 0, 'No seeded card holds more than 5 tickets: run make seed.');

    await expect(more).toHaveClass(/\bbtn-text\b/);
    const moreBox = await more.boundingBox();
    const cardBox = await page.locator('.turn__card', { has: more }).boundingBox();
    expect(moreBox).not.toBeNull();
    expect(cardBox).not.toBeNull();
    expect(moreBox!.x + moreBox!.width).toBeLessThanOrEqual(cardBox!.x + cardBox!.width);
  });
});
