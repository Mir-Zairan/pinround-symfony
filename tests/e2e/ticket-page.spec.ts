import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

// The demo seed (SeedDemoTickets) holds one SHOP ticket whose ask is past the 700-character cut. The spec finds it by its
// title in the SHOP list, as Lena (auth.setup.ts), and makes no tickets.
const LONG_ASK_TITLE = 'Pay button does nothing on a long basket';
const SENTENCE = 'Pay now does nothing once the basket holds many items. ';
const LONG_ASK = `${SENTENCE.repeat(14)}The checkout total is wrong.`;

test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

let ticketPath = '';

test.beforeAll(async ({ browser }) => {
  const context = await browser.newContext({ storageState: LENA_STORAGE_STATE, ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await page.goto('/tickets?project=SHOP');
  const link = page.locator('.tickets__row-link', { hasText: LONG_ASK_TITLE });
  await expect(link, 'the seeded long-ask ticket is missing: run make seed').toHaveCount(1);
  const href = await link.getAttribute('href');
  ticketPath = new URL(href ?? '', page.url()).pathname;
  await context.close();
});

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function openTicket(page: Page): Promise<void> {
  await page.goto(ticketPath, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  await expect(page.getByRole('heading', { level: 1, name: LONG_ASK_TITLE })).toBeVisible();
}

test('the ticket page has no serious accessibility violations', async ({ page }) => {
  await openTicket(page);

  expect(await seriousViolations(page)).toEqual([]);
});

test('Show the full ask swaps the cut text for the whole ask and Show less swaps it back', async ({ page }) => {
  await openTicket(page);
  const shortAsk = page.locator('.ticket__ask-text--short');
  const fullAsk = page.locator('.ticket__ask-text--full');
  const toggle = page.locator('.ticket__ask-more > summary');

  await expect(shortAsk).toHaveText(/ …$/);
  const shortText = ((await shortAsk.textContent()) ?? '').replace(/ …$/, '');
  expect(shortText.length).toBeLessThanOrEqual(700);
  expect(shortText.length).toBeGreaterThan(600);
  expect(LONG_ASK.startsWith(shortText)).toBe(true);
  expect(LONG_ASK.charAt(shortText.length)).toBe(' ');
  await expect(fullAsk).toBeHidden();
  await expect(toggle).toHaveText('Show the full ask', { useInnerText: true });

  await toggle.click();
  await expect(fullAsk).toBeVisible();
  await expect(fullAsk).toHaveText(LONG_ASK.trimEnd());
  await expect(shortAsk).toBeHidden();
  await expect(toggle).toHaveText('Show less', { useInnerText: true });
  expect(await seriousViolations(page)).toEqual([]);

  await toggle.click();
  await expect(shortAsk).toBeVisible();
  await expect(fullAsk).toBeHidden();
  await expect(toggle).toHaveText('Show the full ask', { useInnerText: true });
});

test('a row of the SHOP list opens its ticket in that list, and Next opens the next row', async ({ page }) => {
  await page.goto('/tickets?project=SHOP', { waitUntil: 'networkidle' });
  const rowKeys = await page.locator('.tickets__row').evaluateAll((rows) => rows.map((row) => row.getAttribute('data-ticket-key') ?? ''));
  expect(rowKeys.length).toBeGreaterThanOrEqual(2);

  await page.locator(`.tickets__row[data-ticket-key="${rowKeys[0]}"] .tickets__row-link`).click();

  await expect(page).toHaveURL(new RegExp(`/t/${rowKeys[0]}\\?project=SHOP$`));
  const positionLine = page.locator('.ticket__position-line');
  await expect(positionLine).toHaveText(/^1 of [\d,]+\+?$/);
  const total = ((await positionLine.textContent()) ?? '').replace(/^1 of /, '');
  await expect(page.locator('.ticket__previous')).toHaveAttribute('aria-disabled', 'true');
  expect(await seriousViolations(page)).toEqual([]);

  await page.locator('.ticket__next-ticket').click();

  await expect(page).toHaveURL(new RegExp(`/t/${rowKeys[1]}\\?project=SHOP$`));
  await expect(page.locator('.ticket').first()).toHaveAttribute('data-ticket-key', rowKeys[1]);
  await expect(positionLine).toHaveText(`2 of ${total}`);
  await expect(page.locator('.ticket__back')).toHaveAttribute('href', '/tickets?project=SHOP');
});

test.describe('at 360 px', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the page does not scroll sideways and the aside comes under the main column', async ({ page }) => {
    await openTicket(page);

    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
    const main = await page.locator('.ticket__main').boundingBox();
    const aside = await page.locator('.ticket__aside').boundingBox();
    expect(aside?.y ?? 0).toBeGreaterThanOrEqual((main?.y ?? 0) + (main?.height ?? 0));
  });
});
