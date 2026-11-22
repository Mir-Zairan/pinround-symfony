import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { MIA_STORAGE_STATE } from './auth';
import { seedDraftWithPicture } from './draft-picture';
import { makeTicketGivenTo, openAs, openOwnDraft, TOAST_REGION } from './ticket';

// "Make it a new ticket" (ADR-0044, P13.T2): the owner of a private draft round moves its pictures into a new report
// draft and lands on its page. Who else may press it, and what stays unchanged when they try, is proven in
// tests/Functional/Review/DraftRoundControllerTest; here it is the journey in a real browser.
//
// The picture is seeded ready through the dev-only console command (draft-picture.ts), so no worker or bucket is needed.

test.use({ viewport: { width: 1280, height: 900 } });

const DRAFT_HEADLINE = 'Draft round · only you can see this';
const MOVED_TOAST = 'Your draft is a report draft now. Press Send to make the ticket.';

test('Mia makes a new ticket of her draft round and lands on the report draft with its picture', async ({ browser }) => {
  test.setTimeout(90_000);
  const key = await makeTicketGivenTo(browser, `Coupon field clears on reload ${randomUUID().slice(0, 8)}`, 'Mia');
  seedDraftWithPicture(key);
  const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
  await openOwnDraft(page, key);
  const card = page.locator('.draft-round-editor');
  await expect(card.locator('section.draft-shot')).toHaveCount(1);
  const shot = card.locator('section.draft-shot');
  const titleSaved = page.waitForResponse((response) => response.url().includes('/_components/DraftRoundEditor/editScreenshotText') && 200 === response.status());
  await shot.getByLabel('Picture title · optional').fill('Coupon field empty');
  await shot.getByLabel('Picture title · optional').blur();
  await titleSaved;

  await card.getByRole('button', { name: 'Make it a new ticket' }).click();

  await expect(page).toHaveURL(/\/report-drafts\/[0-9a-f-]{36}$/);
  await expect(page.locator(TOAST_REGION)).toHaveText(MOVED_TOAST);
  await expect(page.getByRole('heading', { name: 'Report draft for SHOP', level: 1 })).toBeVisible();
  await expect(page.getByLabel('What is wrong, in one line')).toHaveValue('Coupon field empty');
  await expect(page.getByLabel('More detail · optional')).toHaveValue(`Found while testing ${key}`);
  const editor = page.locator('#draft-shots');
  await expect(editor.getByRole('tab')).toHaveCount(1);
  await expect(editor.getByRole('tab', { name: 'Screenshot 1 of 1' })).toHaveAttribute('aria-selected', 'true');
  const picture = editor.locator('#draft-shots-panel-1');
  await expect(picture).toBeVisible();
  await expect(picture.locator('img.screenshot__image')).toHaveAttribute('src', /\S/);
  await expect(picture.getByLabel('Picture title · optional')).toHaveValue('Coupon field empty');

  // The draft round is gone from the ticket.
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await expect(page.getByRole('heading', { name: DRAFT_HEADLINE, level: 3 })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Make it a new ticket' })).toHaveCount(0);
  await context.close();
});
