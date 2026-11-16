import { expect, test, type Browser, type Page } from '@playwright/test';
import path from 'node:path';
import { OMAR_STORAGE_STATE } from './auth';
import { makeTicketForOmar, openAs, TOAST_REGION } from './ticket';

// "Share your draft round?" (P05.T9, ADR-0017): the dialog a move shows to the owner of an undecided draft round with a
// screenshot. What the server answers for every choice and refusal is proven in
// tests/Functional/Ticketing/TicketMoveDraftQuestionTest.php; here it is proven through the page.
//
// A picture needs the processing worker and a bucket (an upload stays pending without them), so the cases with one run
// only with E2E_MEDIA_PIPELINE=1 on a full stack, as in draft-round.spec.ts; the case with an empty draft runs anywhere.

test.use({ viewport: { width: 1280, height: 900 } });

const PICTURE = path.join(__dirname, 'fixtures', 'checkout-page.png');
const needsPipeline = () => test.skip(!process.env.E2E_MEDIA_PIPELINE, 'needs MinIO, a worker on async_media and vips: set E2E_MEDIA_PIPELINE on a full stack');
const DIALOG_TEXT = 'Your draft round has 1 screenshot. Share it with the team as round 2, or keep it private. Private notes stay readable for you and Claude Code.';

async function startDraft(browser: Browser, title: string, withPicture: boolean) {
  const key = await makeTicketForOmar(browser, title);
  const { context, page } = await openAs(browser, OMAR_STORAGE_STATE);
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Start a draft round' }).click();
  await expect(page.getByRole('heading', { name: 'Draft round · only you can see this', level: 3 })).toBeVisible();
  await page.waitForLoadState('networkidle');
  if (withPicture) {
    await page.locator('.draft-round-editor__upload input[type="file"]').setInputFiles(PICTURE);
    await expect(page.locator('section.draft-shot')).toHaveCount(1, { timeout: 30_000 });
  }
  return { context, page, key };
}

function startWork(page: Page) {
  return page.locator('#ticket-next-step .next-step-box__verbs').getByRole('button', { name: 'Start work' });
}

function moveAnswer(page: Page) {
  return page.waitForResponse((response) => /\/t\/SHOP-\d+\/move\//.test(response.url()) && 'POST' === response.request().method());
}

test.describe('the Share draft dialog', () => {
  test('Cancel moves nothing, Keep private and move moves and keeps the draft private, and the second move asks nothing', async ({ browser }) => {
    needsPipeline();
    test.setTimeout(120_000);
    const { context, page } = await startDraft(browser, 'Share draft dialog check on the cart badge', true);
    const dialog = page.getByRole('dialog', { name: 'Share your draft round?' });

    let answer = moveAnswer(page);
    await startWork(page).click();
    expect((await answer).status()).toBe(409);
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText(DIALOG_TEXT);
    await expect(dialog.getByRole('button', { name: 'Share and move to In progress' })).toBeVisible();

    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('#ticket-state')).not.toContainText('In progress');
    await expect(startWork(page)).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Draft round · only you can see this', level: 3 })).toBeVisible();

    answer = moveAnswer(page);
    await startWork(page).click();
    expect((await answer).status()).toBe(409);
    answer = moveAnswer(page);
    await dialog.getByRole('button', { name: 'Keep private and move' }).click();
    expect((await answer).status()).toBe(200);
    await expect(dialog).toHaveCount(0);
    await expect(page.locator(TOAST_REGION)).toContainText('Moved to In progress. Your draft stays private.');
    await expect(page.locator('#ticket-state')).toContainText('In progress');
    await expect(page.getByRole('heading', { name: 'Draft round · only you can see this', level: 3 })).toBeVisible();

    answer = moveAnswer(page);
    await page.locator('#ticket-next-step .next-step-box__verbs').getByRole('button', { name: 'Send to code review' }).click();
    expect((await answer).status()).toBe(200);
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await context.close();
  });

  test('Share and move shares the draft as the next round and says so in the toast', async ({ browser }) => {
    needsPipeline();
    test.setTimeout(120_000);
    const { context, page } = await startDraft(browser, 'Share draft dialog check on the order total', true);
    const dialog = page.getByRole('dialog', { name: 'Share your draft round?' });

    await startWork(page).click();
    await expect(dialog).toBeVisible();
    const answer = moveAnswer(page);
    await dialog.getByRole('button', { name: 'Share and move to In progress' }).click();
    expect((await answer).status()).toBe(200);
    await expect(page.locator(TOAST_REGION)).toContainText(/^Moved to In progress\. Your draft is now round \d+ for the team\.$/);
    await context.close();
  });

  test('a draft with no screenshot moves with no question', async ({ browser }) => {
    test.setTimeout(60_000);
    const { context, page } = await startDraft(browser, 'Share draft dialog check on an empty draft', false);

    const answer = moveAnswer(page);
    await startWork(page).click();
    expect((await answer).status()).toBe(200);
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.locator(TOAST_REGION)).toContainText('Started. Status is In progress.');
    await context.close();
  });
});
