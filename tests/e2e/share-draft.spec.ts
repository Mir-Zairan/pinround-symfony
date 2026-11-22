import { expect, test, type Browser, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { seedDraftWithPicture } from './draft-picture';
import { expectOnDraftPage, makeTicketForOmar, openAs, TOAST_REGION } from './ticket';

// "Share your draft round?" (P05.T9, ADR-0017): the dialog a move shows to the owner of an undecided draft round with a
// screenshot. What the server answers for every choice and refusal is proven in
// tests/Functional/Ticketing/TicketMoveDraftQuestionTest.php; here it is proven through the page.
//
// A picture upload needs the processing worker and a bucket, so the draft's one picture is seeded already ready through
// the dev-only console command (draft-picture.ts) and every case runs on any stack.

test.use({ viewport: { width: 1280, height: 900 } });
// Both draft specs read and decide the same people's drafts; playwright.config.ts runs them one test at a time.

const DIALOG_TEXT = 'Your draft round has 1 screenshot. Share it with the team as round 1, or keep it private. Private notes stay readable for you and Claude Code.';

async function startDraft(browser: Browser, title: string, withPicture: boolean) {
  const key = await makeTicketForOmar(browser, title);
  if (withPicture) {
    seedDraftWithPicture(key);
  }
  const { context, page } = await openAs(browser, OMAR_STORAGE_STATE);
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  if (!withPicture) {
    // The start opens the new draft's page; the moves below are the ticket page's.
    await page.getByRole('button', { name: 'Start a draft round' }).click();
    await expectOnDraftPage(page);
    await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  }
  const draftRow = page.locator('article.round-card--private');
  await expect(draftRow.getByRole('heading', { name: 'Draft round · only you can see this', level: 3 })).toBeVisible();
  await page.waitForLoadState('networkidle');
  await expect(draftRow.locator('.round-card__status')).toHaveText(withPicture ? '0 of 1 look right · 1 screenshot' : 'no screenshots');
  return { context, page, key };
}

/** How many shared rounds the ticket page shows to Lena, who is not the draft's owner. */
async function sharedRoundsSeenByLena(browser: Browser, key: string): Promise<number> {
  const { context, page } = await openAs(browser, LENA_STORAGE_STATE);
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  const rounds = page.locator('turbo-frame#ticket-rounds');
  await rounds.scrollIntoViewIfNeeded();
  await expect(rounds).toHaveAttribute('complete', '');
  const count = await page.getByRole('heading', { name: /^Round \d+ · /, level: 3 }).count();
  await context.close();
  return count;
}

function startWork(page: Page) {
  return page.locator('#ticket-next-step .next-step-box__verbs').getByRole('button', { name: 'Start work' });
}

function moveAnswer(page: Page) {
  return page.waitForResponse((response) => /\/t\/SHOP-\d+\/move\//.test(response.url()) && 'POST' === response.request().method());
}

test.describe('the Share draft dialog', () => {
  test('Cancel moves nothing, Keep private and move moves and keeps the draft private, and the second move asks nothing', async ({ browser }) => {
    test.setTimeout(120_000);
    const { context, page, key } = await startDraft(browser, 'Share draft dialog check on the cart badge', true);
    const dialog = page.getByRole('dialog', { name: 'Share your draft round?' });

    let answer = moveAnswer(page);
    await startWork(page).click();
    expect((await answer).status()).toBe(409);
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText(DIALOG_TEXT);
    await expect(dialog.getByRole('button', { name: 'Share and move to In progress' })).toBeVisible();

    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(startWork(page)).toBeFocused();
    await expect(page.locator('#ticket-state')).not.toContainText('In progress');
    await expect(startWork(page)).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Draft round · only you can see this', level: 3 })).toBeVisible();
    expect(await sharedRoundsSeenByLena(browser, key)).toBe(0);

    answer = moveAnswer(page);
    await startWork(page).click();
    expect((await answer).status()).toBe(409);
    await expect(dialog).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(startWork(page)).toBeFocused();

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
    expect(await sharedRoundsSeenByLena(browser, key)).toBe(0);

    answer = moveAnswer(page);
    await page.locator('#ticket-next-step .next-step-box__verbs').getByRole('button', { name: 'Send to code review' }).click();
    expect((await answer).status()).toBe(200);
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await context.close();
  });

  test('Share and move shares the draft as the next round and says so in the toast', async ({ browser }) => {
    test.setTimeout(120_000);
    const { context, page, key } = await startDraft(browser, 'Share draft dialog check on the order total', true);
    const dialog = page.getByRole('dialog', { name: 'Share your draft round?' });

    await startWork(page).click();
    await expect(dialog).toBeVisible();
    const answer = moveAnswer(page);
    await dialog.getByRole('button', { name: 'Share and move to In progress' }).click();
    expect((await answer).status()).toBe(200);
    await expect(page.locator(TOAST_REGION)).toContainText('Moved to In progress. Your draft is now round 1 for the team.');
    expect(await sharedRoundsSeenByLena(browser, key)).toBe(1);
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
