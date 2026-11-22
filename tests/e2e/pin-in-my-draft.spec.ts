import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE, MIA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { seedDraftWithPicture } from './draft-picture';
import { expectOnDraftPage, makeTicketForOmar, openAs, openOwnDraft, TOAST_REGION } from './ticket';

// "Pin this in my draft" (ADR-0044 note of 2026-11-22) in a real browser: Mia, whose turn it is to test, copies the
// screenshot of Omar's shared round into her own draft, pins it with a note, and shares it with "Needs changes" as the
// findings round. Who sees the button and every refusal are proven in tests/Functional/Review/DraftRoundControllerTest.php,
// the copy itself in tests/Integration/Review/Application/UseCaseRoundCommandsCopyToDraftTest.php.
//
// A picture upload needs the processing worker and a bucket, so Omar's picture is seeded ready through the dev-only
// console command (draft-picture.ts). SHOP reviews code before testing, so the ticket goes through code review to
// Testing before Omar shares, as round-page.spec.ts does: the open round is then ready for Mia.

test.use({ viewport: { width: 1280, height: 900 } });

function nextStepVerb(page: Page, name: string) {
  return page.locator('#ticket-next-step .next-step-box__verbs').getByRole('button', { name });
}

function savedBy(page: Page, action: string) {
  return page.waitForResponse((response) => response.url().includes(`/_components/DraftRoundEditor/${action}`) && 200 === response.status());
}

test('Mia pins Omar\'s shared screenshot in her draft, adds a pin with a note and shares it as the findings round', async ({ browser }) => {
  test.setTimeout(150_000);
  const key = await makeTicketForOmar(browser, 'Pin in my draft check on the discount line');
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  await omar.page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await nextStepVerb(omar.page, 'Start work').click();
  await expect(omar.page.locator('#ticket-state')).toContainText('In progress');
  await nextStepVerb(omar.page, 'Send to code review').click();
  await expect(omar.page.locator(TOAST_REGION)).toContainText(/Sent to code review\. Lena.s turn now\./);
  const lena = await openAs(browser, LENA_STORAGE_STATE);
  await lena.page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await nextStepVerb(lena.page, 'Approve code').click();
  await expect(lena.page.locator(TOAST_REGION)).toContainText(/Code approved\. Mia.s turn to test\./);
  await lena.context.close();
  seedDraftWithPicture(key);
  await openOwnDraft(omar.page, key);
  await omar.page.getByRole('button', { name: 'Share with the team now' }).click();
  await expect(omar.page.locator('turbo-frame#ticket-rounds').getByRole('heading', { name: /^Round 1 · self-check by Omar · open · 1 screenshot$/, level: 3 })).toBeVisible();
  await omar.page.goto(`/t/${key}/rounds/1`, { waitUntil: 'networkidle' });
  await expect(omar.page.getByRole('button', { name: 'Pin this in my draft' })).toHaveCount(0);
  await omar.context.close();

  const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
  await page.goto(`/t/${key}/rounds/1`, { waitUntil: 'networkidle' });
  await page.locator('.round-page__side').getByRole('button', { name: 'Pin this in my draft' }).click();

  await expect(page).toHaveURL(new RegExp(`/t/${key}/rounds/[0-9a-f-]{36}/draft\\?screenshot=1$`));
  await expectOnDraftPage(page);
  await expect(page.locator(TOAST_REGION)).toContainText('Draft round started with this screenshot. Only you see it. Add your pins.');
  const shot = page.locator('section.draft-shot:not([hidden])');
  await expect(shot).toHaveCount(1);
  await expect(shot.getByLabel('Picture title · optional')).toHaveValue('From round 1 · Screenshot 1');

  // The Before beside it is Omar's same picture, as round 1 is the hand-off round before Mia's draft.
  await expect(shot.locator('.draft-board__picture--before .round-compare__caption')).toHaveText('Before · round 1');
  const frame = shot.locator('.draft-board__picture--after .screenshot__frame');
  const box = (await frame.boundingBox())!;
  const pinSaved = savedBy(page, 'setPins');
  await frame.click({ position: { x: box.width * 0.4, y: box.height * 0.5 } });
  await shot.getByLabel('Note for pin 1').fill('The discount line is cut off');
  await pinSaved;
  await page.reload({ waitUntil: 'networkidle' });
  await expect(page.locator('section.draft-shot:not([hidden])').getByLabel('Note for pin 1')).toHaveValue('The discount line is cut off');

  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await nextStepVerb(page, 'Needs changes').click();
  const dialog = page.getByRole('dialog', { name: 'Share your draft round?' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: /^Share and move to / }).click();

  await expect(page.locator('#ticket-state')).toContainText('In progress');
  const rounds = page.locator('turbo-frame#ticket-rounds');
  await rounds.scrollIntoViewIfNeeded();
  await expect(rounds.getByRole('heading', { name: /^Round 2 · findings by Mia · open · 1 screenshot$/, level: 3 })).toBeVisible();
  await expect(rounds.locator('article.round-card--private')).toHaveCount(0);
  await context.close();
});
