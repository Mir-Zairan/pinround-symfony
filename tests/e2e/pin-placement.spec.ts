import { expect, test, type Locator } from '@playwright/test';
import { OMAR_STORAGE_STATE } from './auth';
import { recordCspViolations, spotInLayer } from './pin-spots';
import { seedChecklistRound } from './review-round-seed';
import { makeTicketForOmar, openAs } from './ticket';

// Pins sit at their stored percent on every way a pin layer reaches the page (P14.T1): a full page load and the ticket
// page's lazy Rounds frame, whose markup comes from a separate request. The re-render of a draft round is proven in
// draft-round.spec.ts. The strict CSP allows no style attribute and no style element without the page's nonce, so a
// pin left at the layer's corner, or a violation in the console, is the bug this spec exists for.

test.use({ viewport: { width: 1280, height: 900 } });

// The seeded percents: SeedReviewRoundCommand::addReportWithChecklist places Round 0's pins at 25 + 40 n and 30 + 20 n.
const ROUND_ZERO_SPOTS = [
  { number: 1, x: 25, y: 30 },
  { number: 2, x: 65, y: 50 },
] as const;

// ScreenshotExample's read-only example, pins 1 to 3.
const GALLERY_SPOTS = [
  { number: 1, x: 28, y: 30 },
  { number: 2, x: 70, y: 45.5 },
  { number: 3, x: 45, y: 62 },
] as const;

const TOLERANCE_PERCENT = 0.5;

async function expectAtSpot(marker: Locator, x: number, y: number): Promise<void> {
  await expect(marker).toBeVisible();
  await expect.poll(async () => {
    const spot = await spotInLayer(marker);
    return Math.max(Math.abs(spot.x - x), Math.abs(spot.y - y));
  }, { message: `pin at ${x} % / ${y} %` }).toBeLessThanOrEqual(TOLERANCE_PERCENT);
}

test('on the ticket page Round 0 pins sit at their stored percent once the Rounds frame loads, with no CSP violation', async ({ browser }) => {
  test.setTimeout(90_000);
  const key = await makeTicketForOmar(browser, 'Pin spots check on the delivery date');
  seedChecklistRound(key);
  const { context, page } = await openAs(browser, OMAR_STORAGE_STATE);
  const cspViolations = await recordCspViolations(page);

  await page.goto(`/t/${key}`);
  const roundZero = page.locator('turbo-frame#ticket-rounds article.round-card').filter({
    has: page.getByRole('heading', { level: 3, name: /^Round 0 · Report · by .+ · 2 pins$/ }),
  });
  await roundZero.scrollIntoViewIfNeeded();
  const markers = roundZero.locator('.pin-layer .pin');
  await expect(markers).toHaveCount(ROUND_ZERO_SPOTS.length);

  for (const { number, x, y } of ROUND_ZERO_SPOTS) {
    await expectAtSpot(roundZero.locator(`.pin-layer .pin[data-pin-number="${number}"]`), x, y);
  }
  await page.waitForLoadState('networkidle');
  expect(await cspViolations()).toEqual([]);
  await context.close();
});

test('on a full page load the pins sit at their percent and a picture still processing keeps its shape, with no CSP violation', async ({ page }) => {
  const cspViolations = await recordCspViolations(page);

  await page.goto('/_gallery', { waitUntil: 'networkidle' });
  const example = page.locator('figure#gallery-screenshot');
  await example.scrollIntoViewIfNeeded();
  await expect(example.locator('.pin-layer .pin')).toHaveCount(GALLERY_SPOTS.length);

  for (const { number, x, y } of GALLERY_SPOTS) {
    await expectAtSpot(example.locator(`.pin-layer .pin[data-pin-number="${number}"]`), x, y);
  }
  const pendingFrame = page.locator('#gallery-pending-screenshot-frame');
  await expect(pendingFrame).toHaveCSS('aspect-ratio', '800 / 450');
  await expect(pendingFrame).toHaveCSS('max-width', '800px');
  expect(await cspViolations()).toEqual([]);
});
