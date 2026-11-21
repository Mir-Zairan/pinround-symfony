import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { seedDraftWithPicture } from './draft-picture';
import { seedChecklistRound } from './review-round-seed';
import { makeTicketForOmar, openAs } from './ticket';

// Rounds on the ticket page (P14.T7): later rounds are one-line rows, so a ticket with five rounds stays short, and on a
// phone the Your next step box sits above the Rounds. Which rounds each role sees is proven in
// tests/Functional/Review/RoundsFrameTest.php, and the markup the phone order relies on in
// tests/Functional/Ticketing/TicketPageTest.php; here the browser lays the page out.
//
// The rounds are seeded through the dev-only console commands (review-round-seed.ts, draft-picture.ts): Round 0, the
// Round 1 checklist, Omar's shared round 2 and the review round 3, both closed, then a draft of Omar's he shares as
// round 4: under ADR-0048 a share waits while another shared round is open.

const MAX_PAGE_HEIGHT_PX = 1400;
/** review.css's --round-thumbnail-width. */
const ROUND_ZERO_THUMBNAIL_PX = 480;
const ROUNDS = 5;
const ROWS = 3;
const ROW_HEADLINE = /^Round \d+ · (review|self-check|findings) by Omar · (open|closed) · (no screenshots|1 screenshot|\d+ screenshots)$/;

// Omar shares a draft below, and the draft specs read and decide his drafts; playwright.config.ts runs them one test
// at a time.
test.describe.configure({ mode: 'serial' });

let key = '';
let checklistNumber = 0;

async function openTicketWithRoundsLoaded(page: Page): Promise<void> {
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  // The Rounds frame loads lazily once it is in view.
  await page.getByRole('heading', { level: 2, name: 'Rounds' }).scrollIntoViewIfNeeded();
  await expect(page.locator('turbo-frame#ticket-rounds article.round-card')).toHaveCount(ROUNDS);
  await expect(page.locator('turbo-frame#ticket-round-bar .round-bar__text')).toHaveText(/^Round \d+ is open/);
  // The thread's frame is lazy too: measured before it loads, the page would be shorter than it is.
  await expect(page.locator('turbo-frame#ticket-thread form.thread__composer')).toBeVisible();
  await page.waitForLoadState('networkidle');
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(90_000);
  key = await makeTicketForOmar(browser, 'Rounds on the ticket page stay short');
  checklistNumber = seedChecklistRound(key, { closed: true });
  seedDraftWithPicture(key);

  const { context, page } = await openAs(browser, OMAR_STORAGE_STATE);
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await page.getByRole('heading', { level: 2, name: 'Rounds' }).scrollIntoViewIfNeeded();
  const draft = page.locator('article.round-card--private');
  await draft.getByRole('button', { name: 'Share with the team now' }).click();
  await page.getByRole('heading', { level: 2, name: 'Rounds' }).scrollIntoViewIfNeeded();
  await expect(page.locator('turbo-frame#ticket-rounds article.round-card--row')).toHaveCount(ROWS);
  await expect(draft).toHaveCount(0);
  await context.close();
});

test('a SHOP ticket with five rounds is under 1,400 px tall at 1440x900, with the later rounds as rows', async ({ browser }) => {
  const { context, page } = await openAs(browser, LENA_STORAGE_STATE);
  await page.setViewportSize({ width: 1440, height: 900 });
  await openTicketWithRoundsLoaded(page);

  const frame = page.locator('turbo-frame#ticket-rounds');
  await expect(frame.getByRole('heading', { level: 3, name: /^Round 0 · Report · by .+ · 2 pins$/ })).toHaveCount(1);
  await expect(frame.getByRole('heading', { level: 3, name: `Round ${checklistNumber} · Checklist from the report`, exact: true })).toHaveCount(1);
  const rows = frame.locator('article.round-card--row');
  await expect(rows).toHaveCount(ROWS);
  for (const headline of await rows.locator('h3').allTextContents()) {
    expect(headline).toMatch(ROW_HEADLINE);
  }
  await expect(rows.getByRole('link', { name: 'Open round' })).toHaveCount(ROWS);
  await expect(rows.locator('img')).toHaveCount(0);

  // Round 0's picture is a thumbnail (review.css), and the empty thread one line, not the dashed box (P14.T7).
  const roundZeroPicture = (await frame.locator('.round-card__screenshot--thumbnail img.screenshot__image').boundingBox())!;
  expect(roundZeroPicture.width, "Round 0's picture width").toBeLessThanOrEqual(ROUND_ZERO_THUMBNAIL_PX);
  await expect(page.locator('turbo-frame#ticket-thread .thread__empty')).toBeVisible();
  await expect(page.locator('turbo-frame#ticket-thread .empty-state')).toHaveCount(0);

  const pageHeight = await page.evaluate(() => document.scrollingElement?.scrollHeight ?? Number.POSITIVE_INFINITY);
  expect(pageHeight, 'the ticket page height at 1440x900').toBeLessThan(MAX_PAGE_HEIGHT_PX);

  // On a wide screen the box keeps its place in the aside, right of the Rounds.
  const box = (await page.locator('.next-step-box').boundingBox())!;
  const rounds = (await page.locator('section.ticket__rounds').boundingBox())!;
  expect(box.x, 'the Your next step box sits right of the Rounds').toBeGreaterThanOrEqual(rounds.x + rounds.width);
  await context.close();
});

test('at 390 px wide the Your next step box sits above the Rounds, and the rest of the aside below them', async ({ browser }) => {
  const { context, page } = await openAs(browser, LENA_STORAGE_STATE);
  await page.setViewportSize({ width: 390, height: 844 });
  await openTicketWithRoundsLoaded(page);

  const box = (await page.locator('.next-step-box').boundingBox())!;
  const rounds = (await page.locator('section.ticket__rounds').boundingBox())!;
  const people = (await page.locator('section.people').boundingBox())!;
  expect(box.y, 'the Your next step box top is above the Rounds top').toBeLessThan(rounds.y);
  expect(box.y + box.height, 'the box ends before the Rounds start').toBeLessThanOrEqual(rounds.y);
  expect(people.y, 'People follows the Rounds').toBeGreaterThan(rounds.y + rounds.height);
  expect(box.width, 'the box fills the column').toBeCloseTo(rounds.width, 0);
  const overflowPx = await page.evaluate(() => (document.scrollingElement ? document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth : Number.POSITIVE_INFINITY));
  expect(overflowPx, 'no sideways scroll at 390 px').toBeLessThanOrEqual(0);
  await context.close();
});
