import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE, MIA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { runAsDatabaseOwner } from './database';
import { seedDraftWithPicture } from './draft-picture';
import { makeTicketForOmar, openAs, openOwnDraft, TOAST_REGION } from './ticket';

// The round page of a shared draft (P14.T8 step 1) in a real browser: one screenshot at a time, the right arrow showing
// the next, a click on a tab jumping to another. What the page shows for each round kind and query, and who is
// answered 404, is proven in tests/Functional/Review/RoundPageScreenshotsTest.php.
//
// A picture upload needs the processing worker and a bucket, so the draft's first picture is seeded ready through the
// dev-only console command (draft-picture.ts), and screenshots 2 and 3 are copies of it added as the database owner. The
// ticket is this test's own, so no other draft spec shares its draft. Ids are uuidv7(): the domain refuses any other
// UUID version when it loads the round.

test.use({ viewport: { width: 1280, height: 900 } });

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
/** RoundBar::FRAME_ID: the ticket page's frame that holds the round bar while a shared round is open. */
const ROUND_BAR_FRAME = 'ticket-round-bar';

function nextStepVerb(page: Page, name: string) {
  return page.locator('#ticket-next-step .next-step-box__verbs').getByRole('button', { name });
}

/**
 * Adds screenshots 2 and 3 to the draft, copies of its first picture, as the database owner.
 */
function seedTwoMoreScreenshots(roundId: string): void {
  // Checked before it goes into the SQL: it comes from the page.
  expect(roundId).toMatch(UUID);
  runAsDatabaseOwner(
    `INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, title, created_at)
     SELECT organization_id, uuidv7(), round_id, media_id, position + added.step, 'Screenshot copy ' || added.step, now()
     FROM rvw_screenshot CROSS JOIN (VALUES (1), (2)) AS added(step) WHERE round_id = '${roundId}' AND position = 0`,
  );
}

test.describe('The round page of a shared draft', () => {
  test('Mia opens Omar\'s shared draft on its first tab, the right arrow shows the second, and a tab jumps to the third', async ({ browser }) => {
    test.setTimeout(120_000);
    const key = await makeTicketForOmar(browser, 'Round page check on the cart screenshots');
    seedDraftWithPicture(key);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    await openOwnDraft(omar.page, key);
    const shareAction = await omar.page.locator('form.round-card__form', { has: omar.page.locator('.round-card__share') }).getAttribute('action');
    seedTwoMoreScreenshots(/\/rounds\/([^/]+)\/share$/.exec(shareAction ?? '')?.[1] ?? '');
    await omar.page.reload({ waitUntil: 'networkidle' });
    await expect(omar.page.locator('section.draft-shot')).toHaveCount(3);

    await omar.page.locator('.round-card__share').click();

    const shared = omar.page.locator('article.round-card--row').filter({ has: omar.page.getByRole('heading', { name: /^Round 1 · /, level: 3 }) });
    await expect(shared.getByRole('link', { name: 'Open round' })).toBeVisible();
    await omar.context.close();

    const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
    // networkidle: the arrow keys need the screenshot-pager controller, which connects after the importmap modules load.
    await page.goto(`/t/${key}/rounds/1`, { waitUntil: 'networkidle' });
    const tabs = page.getByRole('navigation', { name: 'Screenshots', exact: true });
    const selectedTab = tabs.locator('a[aria-current="page"]');
    const afterTitle = page.locator('.round-compare__side--after .screenshot__title');
    await expect(tabs.getByRole('link')).toHaveCount(3);
    await expect(selectedTab).toHaveId('round-tab-1');
    const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
      .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);

    await page.keyboard.press('ArrowRight');

    await expect(page).toHaveURL(new RegExp(`/t/${key}/rounds/1\\?screenshot=2$`));
    await expect(selectedTab).toHaveId('round-tab-2');
    await expect(selectedTab, 'the arrow key\'s visit gives the shown tab the focus').toBeFocused();
    await expect(afterTitle).toHaveText('Screenshot copy 1');

    await tabs.getByRole('link', { name: /^Screenshot copy 2/ }).click();

    await expect(selectedTab).toHaveId('round-tab-3');
    await expect(afterTitle).toHaveText('Screenshot copy 2');
    await expect(tabs.locator('[data-screenshot-pager-target="next"]'), 'no screenshot after the last').toHaveCount(0);
    await context.close();
  });

  // Who may give it, and what a refused post changes, is proven in
  // tests/Functional/Review/VerdictControllerScreenshotsTest.php.
  test('Mia gives the shared screenshot Needs fix with a note, and Omar reads it on the same page', async ({ browser }) => {
    test.setTimeout(120_000);
    const key = await makeTicketForOmar(browser, 'Round page verdict on the cart screenshot');
    seedDraftWithPicture(key);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    await openOwnDraft(omar.page, key);
    await omar.page.locator('.round-card__share').click();
    await expect(omar.page.locator('article.round-card--row').getByRole('heading', { name: /^Round 1 · /, level: 3 })).toBeVisible();

    const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
    await page.goto(`/t/${key}/rounds/1`, { waitUntil: 'networkidle' });
    const verdict = page.locator('#round-screenshot-verdict');
    await expect(verdict.getByRole('heading', { level: 2 })).toHaveText(/^Your verdict on /);
    await expect(verdict).toContainText('No verdict yet.');
    await expect(verdict).toContainText('Then: Lena, team lead');

    await verdict.getByText('Needs fix', { exact: true }).click();
    await verdict.getByLabel('Note (optional)').fill('The total still shows the old price.');
    await verdict.getByRole('button', { name: 'Save verdict' }).click();

    await expect(page.locator(TOAST_REGION)).toContainText('Verdict saved. Lena can see it.');
    // fragment-focus focuses the section the redirect names, then drops the fragment from the address.
    await expect(page).toHaveURL(new RegExp(`/t/${key}/rounds/1\\?screenshot=1$`));
    await expect(verdict, 'the redirect brings Mia back to the verdict').toBeFocused();
    await expect(verdict.locator('.round-panel__verdict')).toHaveText('Needs fix');
    await expect(verdict.getByRole('radio', { name: 'Needs fix' })).toBeChecked();
    await context.close();

    await omar.page.goto(`/t/${key}/rounds/1`, { waitUntil: 'networkidle' });
    const omarsView = omar.page.locator('#round-screenshot-verdict');
    await expect(omarsView.getByRole('heading', { level: 2 })).toHaveText(/^Verdict on /);
    await expect(omarsView.locator('.round-panel__verdict')).toHaveText('Needs fix');
    await expect(omarsView.locator('.round-panel__note')).toHaveText('The total still shows the old price.');
    await expect(omarsView.getByRole('button')).toHaveCount(0);
    await omar.context.close();
  });
});

// The move-on buttons (P14.T8, ADR-0048): the person whose turn it is gives the open round's verdict from its page, a
// plain form post that lands on the ticket with the round closed. Who sees the buttons and what a refused post changes
// is proven in tests/Functional/Review/RoundPageMoveOnButtonsTest.php.
//
// SHOP reviews code before testing, so In progress offers "Send to code review", not "Send to testing". A round shared
// on the way to code review is ready for Lena, and her "Approve code" closes it (ADR-0048), so Omar shares his draft
// only once the ticket is in Testing: the open round is then ready for Mia, whose turn it is.
test.describe('The move-on buttons of an open round', () => {
  test('Mia presses Needs changes on Omar\'s shared round and lands on the ticket with the round closed', async ({ browser }) => {
    test.setTimeout(120_000);
    const key = await makeTicketForOmar(browser, 'Round page move on for the cart total');
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

    // Seeded once the ticket is in Testing, so no hand-off above asked to share it.
    seedDraftWithPicture(key);
    await openOwnDraft(omar.page, key);
    await omar.page.getByRole('button', { name: 'Share with the team now' }).click();
    const sharedRow = omar.page.locator('turbo-frame#ticket-rounds article.round-card--row');
    await expect(sharedRow.getByRole('heading', { name: /^Round 1 · self-check by Omar · open · 1 screenshot$/, level: 3 })).toBeVisible();
    await omar.context.close();

    const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
    await page.goto(`/t/${key}/rounds/1`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(/^Round 1 · shot .+ · Ready for you$/);
    const moveOn = page.locator('section.round-move-on');
    await expect(moveOn.getByRole('heading', { name: 'Your verdict', exact: true })).toBeVisible();

    await moveOn.getByRole('button', { name: 'Needs changes' }).click();

    await expect(page).toHaveURL(new RegExp(`/t/${key}$`));
    await expect(page.locator(TOAST_REGION)).toContainText('Sent back to Omar with your notes.');
    await expect(page.locator('#ticket-state')).toContainText('In progress');
    const roundBar = page.locator(`turbo-frame#${ROUND_BAR_FRAME}`);
    await expect(roundBar).toHaveAttribute('complete', '');
    await expect(roundBar.locator('.round-bar')).toHaveCount(0);
    const rounds = page.locator('turbo-frame#ticket-rounds');
    await rounds.scrollIntoViewIfNeeded();
    await expect(rounds.getByRole('heading', { name: /^Round 1 · self-check by Omar · closed · 1 screenshot$/, level: 3 })).toBeVisible();
    await context.close();
  });
});
