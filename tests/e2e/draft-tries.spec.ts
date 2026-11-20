import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { OMAR_STORAGE_STATE } from './auth';
import { runAsDatabaseOwner } from './database';
import { seedDraftWithPicture } from './draft-picture';
import { makeTicketForOmar, openAs } from './ticket';

// The tries of a screenshot in the owner's draft round (P14.T6 step 2, ADR-0048) in a real browser: the latest try with
// "Try 2 of 2", the switch to try 1 and back, and a pin of try 1 marked fixed. What each action stores and refuses, and
// who is answered 404, is proven in tests/Functional/Review/DraftRoundEditorTriesTest.php.
//
// No app path adds a retake from the page yet, so try 2 is seeded as the database owner: a copy of the seeded picture
// that points at try 1, and a pin on try 1. The ticket is this test's own, so no other draft spec shares its draft.

test.use({ viewport: { width: 1280, height: 900 } });

const DRAFT_HEADLINE = 'Draft round · only you can see this';
const FIRST_TRY_NOTE = 'The banner covers the total';
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

function savedBy(page: Page, action: string) {
  return page.waitForResponse((response) => response.url().includes(`/_components/DraftRoundEditor/${action}`) && 200 === response.status());
}

/**
 * Adds try 2 of the draft's first screenshot and a pin on try 1, as the database owner.
 */
function seedRetakeWithPinOnTheFirstTry(roundId: string): void {
  // Checked before it goes into the SQL: it comes from the page.
  expect(roundId).toMatch(UUID);
  runAsDatabaseOwner(
    `INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, retake_of, created_at)
     SELECT organization_id, gen_random_uuid(), round_id, media_id, position + 1, id, now() FROM rvw_screenshot WHERE round_id = '${roundId}' AND position = 0;
     INSERT INTO rvw_pin (organization_id, id, screenshot_id, number, x_pct, y_pct, note, source, author_id, created_at)
     SELECT screenshot.organization_id, gen_random_uuid(), screenshot.id, 1, 20, 30, '${FIRST_TRY_NOTE}', 'human', round.owner_id, now()
     FROM rvw_screenshot AS screenshot JOIN rvw_round AS round ON round.organization_id = screenshot.organization_id AND round.id = screenshot.round_id
     WHERE screenshot.round_id = '${roundId}' AND screenshot.position = 0`,
  );
}

function storedMarkOfTheFirstTryPin(roundId: string): string[] {
  expect(roundId).toMatch(UUID);
  return runAsDatabaseOwner(
    `SELECT COALESCE(pin.verdict, 'none') FROM rvw_pin AS pin JOIN rvw_screenshot AS screenshot ON screenshot.organization_id = pin.organization_id AND screenshot.id = pin.screenshot_id
     WHERE screenshot.round_id = '${roundId}' AND screenshot.position = 0`,
  );
}

test.describe('Tries of a screenshot in the draft round', () => {
  test('Omar switches from try 2 to try 1, marks its pin fixed, and switches back', async ({ browser }) => {
    const key = await makeTicketForOmar(browser, 'Draft round tries on the checkout banner');
    seedDraftWithPicture(key);
    const { context, page } = await openAs(browser, OMAR_STORAGE_STATE);
    await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('heading', { name: DRAFT_HEADLINE, level: 3 })).toBeVisible();
    const shareAction = await page.locator('form.round-card__form', { has: page.locator('.round-card__share') }).getAttribute('action');
    const roundId = /\/rounds\/([^/]+)\/share$/.exec(shareAction ?? '')?.[1] ?? '';
    seedRetakeWithPinOnTheFirstTry(roundId);

    // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
    await page.reload({ waitUntil: 'networkidle' });
    const shot = page.locator('section.draft-shot');
    await expect(shot).toHaveCount(1);
    await expect(shot.locator('[data-try-label]')).toHaveText('Try 2 of 2');
    const tries = shot.getByRole('group', { name: 'Tries of Screenshot 1' });
    await expect(tries.getByRole('button', { name: 'Try 1' })).toHaveAttribute('aria-pressed', 'false');
    await expect(tries.getByRole('button', { name: 'Try 2' })).toHaveAttribute('aria-pressed', 'true');
    await expect(shot.getByRole('button', { name: 'Add pin' })).toBeVisible();
    await expect(shot.getByText(FIRST_TRY_NOTE)).toHaveCount(0);

    const shown = savedBy(page, 'showTry');
    await tries.getByRole('button', { name: 'Try 1' }).click();
    await shown;

    await expect(shot.locator('[data-try-label]')).toHaveText('Try 1 of 2');
    await expect(tries.getByRole('button', { name: 'Try 1' })).toHaveAttribute('aria-pressed', 'true');
    await expect(shot.getByRole('button', { name: 'Add pin' })).toHaveCount(0);
    await expect(shot.getByRole('group', { name: 'Verdict for Screenshot 1' })).toHaveCount(0);
    const pin = shot.locator('.round-panel__pin');
    await expect(pin).toHaveCount(1);
    await expect(pin.locator('.round-panel__note')).toHaveText(FIRST_TRY_NOTE);
    await expect(pin.locator('.verdict-tag')).toHaveText('Not checked yet');
    await expect(shot.getByRole('button', { name: 'Remove this try' })).toBeVisible();
    const axe = await new AxeBuilder({ page }).include('section.draft-shot').withTags(AXE_TAGS).analyze();
    expect(axe.violations).toEqual([]);

    const marked = savedBy(page, 'setPinVerdict');
    await pin.getByRole('button', { name: 'Fixed on try 2' }).click();
    await marked;

    await expect(pin.locator('.verdict-tag')).toHaveText('Fixed on try 2');
    await expect(pin.getByRole('button', { name: 'Fixed on try 2' })).toHaveAttribute('aria-pressed', 'true');
    expect(storedMarkOfTheFirstTryPin(roundId)).toEqual(['fixed']);

    const back = savedBy(page, 'showTry');
    await tries.getByRole('button', { name: 'Try 2' }).click();
    await back;

    await expect(shot.locator('[data-try-label]')).toHaveText('Try 2 of 2');
    await expect(shot.getByRole('button', { name: 'Add pin' })).toBeVisible();
    await expect(shot.getByText(FIRST_TRY_NOTE)).toHaveCount(0);
    await context.close();
  });
});
