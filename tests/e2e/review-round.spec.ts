import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE, MIA_STORAGE_STATE } from './auth';
import { EARLIER_PIN_NOTES, REVIEW_AREAS, seedReviewRound } from './review-round-seed';
import { makeTicketForOmar, openAs, TOAST_REGION } from './ticket';

// The review round page (P05.T7) as Mia, the tester of Shop, and Lena, its lead. What the server answers for every
// verdict and refusal is proven in tests/Functional/Review/VerdictControllerTest.php and RoundPageTest.php; here it is
// proven through the page: the compare control, the focus after a pin verdict, the toasts and the question to the lead.
//
// No command makes a review round yet, so its rounds are seeded through the dev-only console command
// (review-round-seed.ts), with ready pictures that have no file behind them, and every case runs on any stack.

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

// The cases follow one ticket's round: the verdicts of one case are the state of the next.
test.describe.configure({ mode: 'serial' });

let key = '';
let roundNumber = 0;

test.beforeAll(async ({ browser }) => {
  key = await makeTicketForOmar(browser, 'Review round check on the basket page');
  roundNumber = seedReviewRound(key);
});

function roundPath(areaPosition?: number): string {
  return `/t/${key}/rounds/${roundNumber}${undefined === areaPosition ? '' : `?area=${areaPosition}`}`;
}

async function openRound(browser: Browser, storageState: string, areaPosition?: number): Promise<{ page: Page; close: () => Promise<void> }> {
  const { context, page } = await openAs(browser, storageState);
  await page.goto(roundPath(areaPosition), { waitUntil: 'networkidle' });
  await expect(page.getByRole('heading', { level: 1, name: new RegExp(`^Round ${roundNumber}`) })).toBeVisible();
  return { page, close: () => context.close() };
}

function panel(page: Page) {
  return page.locator('aside#round-panel');
}

function areaTab(page: Page, name: string) {
  return page.getByRole('navigation', { name: 'Screen areas' }).getByRole('link', { name: new RegExp(`^${name}`) });
}

async function saveVerdict(page: Page) {
  const answer = page.waitForResponse((response) => /\/areas\/[^/]+\/verdict$/.test(new URL(response.url()).pathname) && 'POST' === response.request().method());
  await panel(page).getByRole('button', { name: 'Save verdict' }).click();
  return (await answer).status();
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test('Mia opens the round from the Rounds section, switches area tabs and compares Before and After', async ({ browser }) => {
  const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await page.getByRole('heading', { level: 2, name: 'Rounds' }).scrollIntoViewIfNeeded();
  const card = page.locator('article.round-card').filter({ has: page.getByRole('heading', { level: 3, name: `Round ${roundNumber} · Review · by Omar` }) });
  await card.getByRole('link', { name: 'Open round' }).click();
  await expect(page).toHaveURL(new RegExp(`/t/${key}/rounds/${roundNumber}$`));
  await page.waitForLoadState('networkidle');

  await expect(areaTab(page, REVIEW_AREAS[0])).toHaveAttribute('aria-current', 'page');
  await expect(areaTab(page, REVIEW_AREAS[0])).toContainText('2 pins');
  await expect(areaTab(page, REVIEW_AREAS[1])).toContainText('Not reviewed');
  await expect(panel(page).getByRole('heading', { name: 'Pins from round 1' })).toBeVisible();
  await expect(panel(page).locator('.round-panel__pin')).toHaveText([new RegExp(EARLIER_PIN_NOTES[0]), new RegExp(EARLIER_PIN_NOTES[1])]);

  await areaTab(page, REVIEW_AREAS[1]).click();
  await expect(page).toHaveURL(new RegExp(`\\?area=1$`));
  await expect(areaTab(page, REVIEW_AREAS[1])).toHaveAttribute('aria-current', 'page');
  await expect(areaTab(page, REVIEW_AREAS[0])).not.toHaveAttribute('aria-current', 'page');
  await expect(panel(page).getByRole('heading', { name: `Your verdict on ${REVIEW_AREAS[1]}` })).toBeVisible();
  await expect(panel(page)).toContainText('That round left no pins here.');

  await areaTab(page, REVIEW_AREAS[0]).click();
  await expect(panel(page).getByRole('heading', { name: `Your verdict on ${REVIEW_AREAS[0]}` })).toBeVisible();
  await page.waitForLoadState('networkidle');
  const compare = page.getByRole('group', { name: 'Compare' });
  const before = page.locator('figure.round-compare__side--before');
  const after = page.locator('figure.round-compare__side--after');
  const option = (name: string) => compare.getByRole('button', { name, exact: true });
  await expect(option('Side by side')).toHaveAttribute('aria-pressed', 'true');
  await expect(before).toBeVisible();
  await expect(after).toBeVisible();

  await option('Before').click();
  await expect(option('Before')).toHaveAttribute('aria-pressed', 'true');
  await expect(option('Side by side')).toHaveAttribute('aria-pressed', 'false');
  await expect(before).toBeVisible();
  await expect(after).toBeHidden();

  await option('After').click();
  await expect(option('After')).toHaveAttribute('aria-pressed', 'true');
  await expect(option('Before')).toHaveAttribute('aria-pressed', 'false');
  await expect(after).toBeVisible();
  await expect(before).toBeHidden();

  await option('Side by side').click();
  await expect(option('Side by side')).toHaveAttribute('aria-pressed', 'true');
  await expect(before).toBeVisible();
  await expect(after).toBeVisible();
  await context.close();
});

test('Mia marks a pin Fixed, focus lands on its Undo, and Undo takes the verdict back', async ({ browser }) => {
  const { page, close } = await openRound(browser, MIA_STORAGE_STATE);
  const firstPin = panel(page).locator('.round-panel__pin').filter({ hasText: EARLIER_PIN_NOTES[0] });
  const secondPin = panel(page).locator('.round-panel__pin').filter({ hasText: EARLIER_PIN_NOTES[1] });
  await expect(firstPin).toContainText('Not checked yet');

  await firstPin.getByRole('button', { name: 'Fixed' }).click();
  await expect(page.locator(TOAST_REGION)).toContainText('Pin 1 marked fixed.');
  await expect(firstPin.getByRole('button', { name: 'Undo' })).toBeFocused();
  await expect(firstPin).toContainText('Fixed');
  await expect(secondPin).toContainText('Not checked yet');
  await expect(secondPin.getByRole('button', { name: 'Fixed' })).toBeVisible();

  await firstPin.getByRole('button', { name: 'Undo' }).click();
  await expect(page.locator(TOAST_REGION)).toContainText('Verdict on pin 1 undone.');
  await expect(firstPin.getByRole('button', { name: 'Fixed' })).toBeFocused();
  await expect(firstPin).toContainText('Not checked yet');
  await expect(firstPin.getByRole('button', { name: 'Undo' })).toHaveCount(0);
  await close();
});

test('a save with no choice is refused, Pass and Question save, and then Ask Lena opens a question to her', async ({ browser }) => {
  const { page, close } = await openRound(browser, MIA_STORAGE_STATE);
  const nextStep = panel(page).getByRole('heading', { name: 'Next step' });

  expect(await saveVerdict(page)).toBeGreaterThanOrEqual(400);
  await expect(page.locator(TOAST_REGION)).toContainText('Pick Pass, Needs fix or Question first.');
  await expect(panel(page)).toContainText('No verdict yet.');
  await expect(areaTab(page, REVIEW_AREAS[0])).toContainText('2 pins');

  await panel(page).getByRole('radio', { name: 'Pass' }).check();
  expect(await saveVerdict(page)).toBe(200);
  await expect(page.locator(TOAST_REGION)).toContainText('Verdict saved. Lena can see it.');
  await expect(areaTab(page, REVIEW_AREAS[0])).toContainText('Passed');
  await expect(panel(page)).toContainText('Saved by Mia');
  await expect(nextStep, 'one area still has no verdict').toHaveCount(0);

  await areaTab(page, REVIEW_AREAS[1]).click();
  await expect(panel(page).getByRole('heading', { name: `Your verdict on ${REVIEW_AREAS[1]}` })).toBeVisible();
  await page.waitForLoadState('networkidle');
  await panel(page).getByRole('radio', { name: 'Question' }).check();
  expect(await saveVerdict(page)).toBe(200);
  await expect(page.locator(TOAST_REGION)).toContainText('Verdict saved. Lena can see it.');
  await expect(areaTab(page, REVIEW_AREAS[1])).toContainText('Question');
  await expect(nextStep).toBeVisible();
  await expect(panel(page)).toContainText('An area has a question for Lena.');

  const askLena = panel(page).getByRole('link', { name: 'Ask Lena' });
  const askedLeadId = new URL(await askLena.getAttribute('href') ?? '', page.url()).searchParams.get('ask');
  expect(askedLeadId).toMatch(/^[0-9a-f-]{36}$/);
  await askLena.click();
  await expect(page).toHaveURL(new RegExp(`/t/${key}/thread\\?ask=${askedLeadId}#thread-composer-${key}$`));
  const composer = page.locator(`form#thread-composer-${key}`);
  await expect(composer.getByRole('group', { name: 'What to write' }).getByRole('button', { name: 'Ask a question' })).toHaveAttribute('aria-pressed', 'true');
  await expect(composer.getByRole('group', { name: 'What to write' }).getByRole('button', { name: 'Comment' })).toHaveAttribute('aria-pressed', 'false');
  const people = composer.getByRole('group', { name: 'Whom to ask' });
  await expect(people).toBeVisible();
  await expect(people.getByRole('button', { name: 'Lena', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect(people.locator('[aria-pressed="true"]')).toHaveCount(1);
  await expect(composer.locator('[data-thread-composer-target="askedUser"]')).toHaveValue(askedLeadId ?? '');
  await expect(composer.locator('[data-thread-composer-target="askedUser"]')).toBeEnabled();
  await expect(composer.getByRole('button', { name: 'Ask Lena' })).toBeVisible();
  await expect(composer.locator('#thread-composer-body')).toBeFocused();
  await close();
});

test('Lena, the lead, sees the verdicts but is offered no question to herself', async ({ browser }) => {
  const { page, close } = await openRound(browser, LENA_STORAGE_STATE, 1);

  await expect(areaTab(page, REVIEW_AREAS[0])).toContainText('Passed');
  await expect(panel(page).getByRole('heading', { name: `Your verdict on ${REVIEW_AREAS[1]}` })).toBeVisible();
  await expect(panel(page)).toContainText('Saved by Mia');
  await expect(panel(page).getByRole('heading', { name: 'Next step' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Ask Lena' })).toHaveCount(0);
  await close();
});

test('the round page has no serious accessibility violations', async ({ browser }) => {
  const { page, close } = await openRound(browser, MIA_STORAGE_STATE);
  await page.evaluate(() => document.fonts.ready);

  expect(await seriousViolations(page)).toEqual([]);
  await close();
});

test('at 360px wide the round page does not scroll sideways', async ({ browser }) => {
  const { page, close } = await openRound(browser, MIA_STORAGE_STATE);
  await page.setViewportSize({ width: 360, height: 800 });
  await page.evaluate(() => document.fonts.ready);

  await expect(panel(page).getByRole('button', { name: 'Save verdict' })).toBeVisible();
  const widths = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
  expect(widths.scroll).toBeLessThanOrEqual(widths.client);
  await close();
});
