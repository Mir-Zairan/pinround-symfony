import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

// Reads the demo seed's SHOP tickets and changes nothing. tests/Functional/Ticketing/WhoseTurnTest.php proves the cards,
// their order, the paging by keyset and who sees what on the server; this spec proves what only a browser shows: the
// switch from the list, landing on a card after "Show N more", "Back to the start" from a later page, accessibility
// and the 360 px layout.
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const DESKTOP = { width: 1280, height: 800 };
// base.css's --header-height-max, the scroll-padding-top that keeps a landed card clear of the sticky header.
const SCROLL_PADDING_TOP_PX = 196;

test.use({ storageState: LENA_STORAGE_STATE, viewport: DESKTOP });

function cards(page: Page): Locator {
  return page.locator('.turn__card');
}

async function openWhoseTurnFromTheList(page: Page): Promise<void> {
  await page.goto('/tickets?project=SHOP');
  await page.getByRole('link', { name: 'Whose turn?', exact: true }).click();
  await expect(page).toHaveURL(/\/tickets\/turn\?project=SHOP$/);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(/^SHOP · /);
}

test('Lena switches from the list to Whose turn? and sees her own card first', async ({ page }) => {
  await openWhoseTurnFromTheList(page);

  expect(await cards(page).count(), 'the seeded SHOP tickets wait on nobody: run make seed').toBeGreaterThanOrEqual(2);
  const first = cards(page).first();
  await expect(first).toHaveClass(/\bturn__card--you\b/);
  await expect(first.getByRole('heading', { level: 2 })).toHaveText('You');
  await expect(first.locator('.turn__sentence')).toHaveText(/^[\d,]+ tickets? waits? on you · /);
  await expect(page.locator('.turn__card--you')).toHaveCount(1);
  await expect(page.locator('.turn__name', { hasText: /^You$/ })).toHaveCount(1);
  await expect(page.getByRole('link', { name: 'List', exact: true })).toHaveAttribute('href', '/tickets?project=SHOP');
  await expect(page.getByRole('link', { name: 'Back to the start', exact: true })).toHaveCount(0);
});

test('Back to the start leaves a later page for the first, with Lena\'s own card', async ({ page }) => {
  // A cursor after 1 Jan 2030 lies past every seeded wait, so this later page is empty whatever the seed holds.
  await page.goto('/tickets/turn?project=SHOP&after=1893456000.label.Customer');
  await expect(cards(page)).toHaveCount(0);
  await expect(page.getByRole('link', { name: /^Next \d+ people$/ })).toHaveCount(0);
  const backToTheStart = page.getByRole('navigation', { name: 'Pages of cards' }).getByRole('link', { name: 'Back to the start', exact: true });
  await expect(backToTheStart).toHaveAttribute('href', '/tickets/turn?project=SHOP');

  await backToTheStart.click();

  await expect(page).toHaveURL(/\/tickets\/turn\?project=SHOP$/);
  await expect(cards(page).first()).toHaveClass(/\bturn__card--you\b/);
  await expect(page.getByRole('link', { name: 'Back to the start', exact: true })).toHaveCount(0);
});

test('Show N more opens that card and lands on it below the header', async ({ page }) => {
  await openWhoseTurnFromTheList(page);
  const cardsWithMore = cards(page).filter({ has: page.getByRole('link', { name: /^Show \d+ more$/ }) });
  expect(await cardsWithMore.count(), 'no seeded card holds more than 5 tickets: run make seed').toBeGreaterThanOrEqual(1);
  const card = cardsWithMore.last();
  const cardId = await card.getAttribute('id');
  expect(cardId).toMatch(/^turn-[a-z0-9-]+$/);
  const cardIndex = await cards(page).evaluateAll((elements, id) => elements.findIndex((element) => element.id === id), cardId);
  expect(cardIndex, 'the card to open is the first one, which needs no scroll to land on').toBeGreaterThan(0);
  const moreText = (await card.getByRole('link', { name: /^Show \d+ more$/ }).innerText()).trim();
  const hiddenCount = Number(/^Show (\d+) more$/.exec(moreText)?.[1]);
  const shownCount = await card.locator('.turn__row').count();

  await card.getByRole('link', { name: moreText }).click();

  await expect(page).toHaveURL(new RegExp(`/tickets/turn\\?project=SHOP&open=[^#]+#${cardId}$`));
  const landed = page.locator(`[id="${cardId}"]`);
  await expect(landed.locator('.turn__row')).toHaveCount(Math.min(shownCount + hiddenCount, 100));
  await expect(landed.getByRole('link', { name: 'Show fewer' })).toHaveAttribute('href', new RegExp(`#${cardId}$`));
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
  const headerBox = await page.locator('.site-header').boundingBox();
  const cardBox = await landed.boundingBox();
  expect(headerBox).not.toBeNull();
  expect(cardBox).not.toBeNull();
  expect(cardBox!.y).toBeGreaterThanOrEqual(headerBox!.y + headerBox!.height - 1);
  expect(cardBox!.y).toBeLessThanOrEqual(SCROLL_PADDING_TOP_PX + 1);
});

test('Whose turn? has no serious or critical accessibility violations', async ({ page }) => {
  await openWhoseTurnFromTheList(page);
  await expect(cards(page).first()).toBeVisible();

  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();

  expect(
    violations
      .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
      .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`),
  ).toEqual([]);
});

test('at 360 px the cards fit and the page does not scroll sideways', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await openWhoseTurnFromTheList(page);

  const { scrollWidth, clientWidth } = await page.evaluate(() => {
    const scrollingElement = document.scrollingElement ?? document.documentElement;
    return { scrollWidth: scrollingElement.scrollWidth, clientWidth: scrollingElement.clientWidth };
  });
  expect(clientWidth).toBeLessThanOrEqual(360);
  expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
  const cardBoxes = await cards(page).evaluateAll((elements) => elements.map((element) => element.getBoundingClientRect().toJSON() as DOMRect));
  expect(cardBoxes.length).toBeGreaterThanOrEqual(1);
  for (const box of cardBoxes) {
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(clientWidth);
  }
});
