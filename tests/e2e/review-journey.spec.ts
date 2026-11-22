import { expect, test, type Locator, type Page } from '@playwright/test';
import path from 'node:path';
import { LENA_STORAGE_STATE, MIA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { fillTicket, openNewTicketForm, placeTwoPins } from './new-ticket-form';
import { EARLIER_PIN_NOTES, REVIEW_AREAS, seedReviewRound } from './review-round-seed';
import { areaTab, roundPanel, saveVerdict } from './round-page';
import { expectOnDraftPage, giveTicketTo, makeTicketForOmar, openAs, TOAST_REGION } from './ticket';

// Phase 5's journey with nothing mocked (P05 Definition of done): Mia reports a ticket with an uploaded screenshot and
// two pins; Omar, given the ticket, starts work, uploads a picture to his draft round, pins it and shares the draft when
// he sends the ticket to code review; Mia sees the shared round, then marks pins and gives each area a verdict on a
// review round and is offered the next step.
//
// The uploads go to MinIO and wait for the media worker (worker-heavy, with vips), so the spec runs only with
// E2E_MEDIA_PIPELINE=1, which the CI e2e job sets. It decides Omar's drafts, so playwright.config.ts runs it with the
// draft specs, one test at a time.
//
// No product code makes a review round with screen areas yet: the review step runs on a second ticket whose rounds come
// from the dev-only seed command (review-round-seed.ts), which refuses a ticket that has rounds already.

test.use({ viewport: { width: 1280, height: 900 } });

const FIXTURE = path.join(__dirname, 'fixtures', 'checkout-page.png');
const TITLE = 'Checkout total is hidden behind the promo banner';
const DRAFT_PIN_NOTE = 'The total shows again above the banner';
const PROCESSING_TIMEOUT_MS = 90_000;

function moveAnswer(page: Page, key: string) {
  return page.waitForResponse((response) => new URL(response.url()).pathname.startsWith(`/t/${key}/move/`) && 'POST' === response.request().method());
}

function nextStepVerb(page: Page, name: string) {
  return page.locator('#ticket-next-step .next-step-box__verbs').getByRole('button', { name });
}

/** Opens the ticket page and waits for its lazy Rounds frame to load. */
async function openRounds(page: Page, key: string) {
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  const rounds = page.locator('turbo-frame#ticket-rounds');
  await rounds.scrollIntoViewIfNeeded();
  await expect(rounds).toHaveAttribute('complete', '');
  return rounds;
}

/** The picture is not only in the page but loaded: its signed URL answered from the bucket with a real image. */
async function expectPictureLoaded(picture: Locator) {
  await picture.scrollIntoViewIfNeeded();
  await expect.poll(() => picture.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0), { timeout: 15_000 }).toBe(true);
}

/** A live component POST carries its arguments as JSON, in multipart form data or URL-encoded. */
function carriesText(body: string, text: string): boolean {
  return body.includes(text) || body.includes(new URLSearchParams({ text }).toString().slice('text='.length));
}

test('Mia reports with pins, Omar shares a pinned draft on hand-off, and Mia reviews the round', async ({ browser }) => {
  test.skip(!process.env.E2E_MEDIA_PIPELINE, 'needs MinIO, a worker on async_media and vips: set E2E_MEDIA_PIPELINE on a full stack');
  test.setTimeout(300_000);
  let key = '';

  await test.step('Mia reports the ticket with a screenshot and two pins, and the picture is processed', async () => {
    const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
    const form = await openNewTicketForm(page);
    await fillTicket(form, TITLE);
    await form.fileInput.setInputFiles(FIXTURE);
    await expect(form.uploadStatus).toHaveText('checkout-page.png uploaded.', { timeout: 30_000 });
    await placeTwoPins(form);
    await form.form.getByLabel('Note for pin 1').fill('The total is hidden');
    await form.form.getByLabel('Note for pin 2').fill('The banner has no close button');
    await form.submit.click();

    await expect(page).toHaveURL(/\/t\/[A-Z][A-Z0-9]*-\d+$/, { timeout: PROCESSING_TIMEOUT_MS });
    key = new URL(page.url()).pathname.replace('/t/', '');
    // The form holds the submit while the picture is processed, but gives up after 15 polls: the page is read again
    // until the report's picture is ready.
    await expect(async () => {
      const rounds = await openRounds(page, key);
      await expect(rounds.locator('article.round-card', { hasText: 'Round 0 · Report · by Mia' }).locator('img.screenshot__image')).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: PROCESSING_TIMEOUT_MS });
    await expect(page.getByText('Round 0 · Report · by Mia · 2 pins')).toBeVisible();
    await expectPictureLoaded(page.locator('article.round-card', { hasText: 'Round 0 · Report · by Mia' }).locator('img.screenshot__image'));
    await context.close();
  });

  await test.step('Lena gives the ticket to Omar', async () => {
    const { context, page } = await openAs(browser, LENA_STORAGE_STATE);
    await giveTicketTo(page, key, 'Omar');
    await context.close();
  });

  await test.step('Omar starts work, uploads a picture to his draft round and pins it, and shares it on the way to code review', async () => {
    const { context, page } = await openAs(browser, OMAR_STORAGE_STATE);
    await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
    await nextStepVerb(page, 'Start work').click();
    await expect(page.locator('#ticket-state')).toContainText('In progress');

    await openRounds(page, key);
    await page.getByRole('button', { name: 'Start a draft round' }).click();
    await expectOnDraftPage(page);
    const fileChooser = page.waitForEvent('filechooser');
    await page.locator('.draft-round-editor').getByRole('button', { name: 'Add screenshot' }).click();
    await (await fileChooser).setFiles(FIXTURE);

    // The editor adds the picture by itself once the worker has made it ready.
    const shot = page.locator('section.draft-shot');
    await expect(shot).toHaveCount(1, { timeout: PROCESSING_TIMEOUT_MS });
    await expectPictureLoaded(shot.locator('img.screenshot__image'));
    const frame = shot.locator('.screenshot__frame');
    const box = (await frame.boundingBox())!;
    const pinSaved = page.waitForResponse((response) => response.url().includes('/_components/DraftRoundEditor/setPins')
      && 200 === response.status() && carriesText(response.request().postData() ?? '', DRAFT_PIN_NOTE));
    await frame.click({ position: { x: box.width * 0.4, y: box.height * 0.5 } });
    await shot.getByLabel('Note for pin 1').fill(DRAFT_PIN_NOTE);
    await pinSaved;

    await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
    const dialog = page.getByRole('dialog', { name: 'Share your draft round?' });
    let answer = moveAnswer(page, key);
    await nextStepVerb(page, 'Send to code review').click();
    expect((await answer).status()).toBe(409);
    await expect(dialog).toContainText('Your draft round has 1 screenshot.');
    answer = moveAnswer(page, key);
    await dialog.getByRole('button', { name: 'Share and move to Code review' }).click();
    expect((await answer).status()).toBe(200);
    await expect(page.locator(TOAST_REGION)).toContainText(/Moved to Code review\. Your draft is now round \d+ for the team\./);
    await context.close();
  });

  // The shared round is one row on the ticket page (P14.T7); its picture and pin show on the round page (P14.T8).
  await test.step('Mia sees the shared round as a row that opens its page', async () => {
    const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
    const rounds = await openRounds(page, key);
    const shared = rounds.locator('article.round-card--row').filter({ has: page.getByRole('heading', { name: /^Round \d+ · self-check by Omar · (open|closed) · 1 screenshot$/, level: 3 }) });
    await expect(shared).toBeVisible();
    await expect(rounds.getByText('PRIVATE', { exact: true })).toHaveCount(0);
    await expect(shared.getByRole('link', { name: 'Open round' })).toHaveAttribute('href', /\/rounds\/\d+$/);
    await expect(shared.locator('img.screenshot__image')).toHaveCount(0);
    await context.close();
  });

  await test.step('Mia marks the pins and gives each area of a review round a verdict, and is offered the next step', async () => {
    // The seeded review round, on a ticket of its own: see the note at the top.
    const reviewKey = await makeTicketForOmar(browser, `${TITLE} (review round)`);
    const roundNumber = seedReviewRound(reviewKey);
    const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
    await page.goto(`/t/${reviewKey}/rounds/${roundNumber}`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('heading', { level: 1, name: new RegExp(`^Round ${roundNumber}`) })).toBeVisible();
    const panel = roundPanel(page);
    const nextStep = panel.getByRole('heading', { name: 'Next step' });

    const firstPin = panel.locator('.round-pin[data-pin-list="before"]').filter({ hasText: EARLIER_PIN_NOTES[0] });
    const secondPin = panel.locator('.round-pin[data-pin-list="before"]').filter({ hasText: EARLIER_PIN_NOTES[1] });
    await firstPin.getByRole('button', { name: 'Fixed' }).click();
    await expect(page.locator(TOAST_REGION)).toContainText('Pin 1 marked fixed.');
    await expect(firstPin.locator('.verdict-tag')).toHaveText('Fixed');
    await secondPin.getByRole('button', { name: 'Still wrong' }).click();
    await expect(page.locator(TOAST_REGION)).toContainText('Pin 2 marked still wrong.');
    await expect(secondPin.locator('.verdict-tag')).toHaveText('Still wrong');

    await panel.getByRole('radio', { name: 'Needs fix' }).check();
    expect(await saveVerdict(page)).toBe(200);
    await expect(page.locator(TOAST_REGION)).toContainText('Verdict saved. Lena can see it.');
    await expect(panel).toContainText('Saved by Mia');
    await expect(nextStep, 'one area still has no verdict').toHaveCount(0);

    await areaTab(page, REVIEW_AREAS[1]).click();
    await expect(panel.getByRole('heading', { name: `Your verdict on ${REVIEW_AREAS[1]}` })).toBeVisible();
    await page.waitForLoadState('networkidle');
    await panel.getByRole('radio', { name: 'Pass' }).check();
    expect(await saveVerdict(page)).toBe(200);
    await expect(areaTab(page, REVIEW_AREAS[1])).toContainText('Passed');

    await expect(nextStep).toBeVisible();
    await expect(panel).toContainText('At least one area needs a fix.');
    await expect(panel.getByRole('link', { name: `Needs changes on ${reviewKey}` })).toBeVisible();
    await context.close();
  });
});
