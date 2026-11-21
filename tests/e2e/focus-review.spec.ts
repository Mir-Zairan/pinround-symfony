import AxeBuilder from '@axe-core/playwright';
import { expect, test, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { MIA_STORAGE_STATE } from './auth';
import { drawablePng } from './media-mocks';
import { EARLIER_PIN_NOTES, LONG_PIN_NOTE, REPORT_PIN_NOTES, REVIEW_AREAS, seedChecklistRound, seedReviewRound } from './review-round-seed';
import { makeTicketForOmar, openAs, TOAST_REGION } from './ticket';

// The full-screen review (P05.T10) as Mia, the tester of Shop, with the keyboard only, and its layout on small screens.
// What the server answers for each choice and refusal is proven in tests/Functional/Review/FocusPageTest.php; here it is
// proven through the page: the walk, the focus, the exits and that the pin bar stays on screen.
//
// The round comes from the dev-only seed command (review-round-seed.ts): round 1's first pin has the long note, and the
// second area's "after" picture is still processing. Its ready pictures have no file behind them, so the browser is
// answered with a drawn 640×480 PNG, the size the seed stores.

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const APP_ORIGIN = new URL(process.env.E2E_BASE_URL ?? 'https://localhost').origin;
const PIN_NOTES = [LONG_PIN_NOTE, EARLIER_PIN_NOTES[1]];

// The cases follow one ticket's round: the verdicts of one case are the state of the next.
test.describe.configure({ mode: 'serial' });

let key = '';
let roundNumber = 0;

test.beforeAll(async ({ browser }) => {
  key = await makeTicketForOmar(browser, 'Full-screen review check on the basket page');
  roundNumber = seedReviewRound(key, { longNote: true, pendingAfter: 1 });
});

function roundPath(): string {
  return `/t/${key}/rounds/${roundNumber}`;
}

function focusPath(areaPosition: number): string {
  return `${roundPath()}/focus?area=${areaPosition}`;
}

async function drawThePictures(context: BrowserContext): Promise<void> {
  const picture = drawablePng(640, 480);
  await context.route('**/*', (route) => {
    const request = route.request();
    if ('image' === request.resourceType() && new URL(request.url()).origin !== APP_ORIGIN) {
      return route.fulfill({ status: 200, contentType: 'image/png', body: picture });
    }
    return route.fallback();
  });
}

function shownStop(page: Page): Locator {
  return page.locator('.focus-bar__stop:not([hidden])');
}

async function expectShownPin(page: Page, number: number): Promise<void> {
  const label = shownStop(page).locator('.focus-bar__label');
  await expect(label).toHaveText(`Pin ${number} of 2 · Omar`);
  expect(await label.innerText(), 'the label is shown in capitals').toBe(`PIN ${number} OF 2 · OMAR`);
  await expect(shownStop(page).locator('.focus-bar__note')).toHaveText(PIN_NOTES[number - 1]);
  await expect(page.locator('.focus-review__picture .pin[aria-pressed="true"]')).toHaveAttribute('data-pin-number', String(number));
  await expect(page.locator('.focus-bar__stop')).toHaveCount(2);
  await expect(page.locator('.focus-bar__stop:not([hidden])')).toHaveCount(1);
}

async function pressUntilFocused(page: Page, target: Locator, keyName: 'Tab' | 'Shift+Tab'): Promise<void> {
  for (let presses = 0; presses < 80; presses += 1) {
    if (await target.evaluate((element) => element === document.activeElement)) {
      return;
    }
    await page.keyboard.press(keyName);
  }
  await expect(target, `${keyName} reaches it`).toBeFocused();
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test('Mia walks the pins with the keyboard only: Full screen, the arrows, Enter on Fixed, Exit and Escape', async ({ browser }) => {
  const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
  await drawThePictures(context);
  await page.goto(roundPath(), { waitUntil: 'networkidle' });

  await pressUntilFocused(page, page.getByRole('link', { name: 'Full screen', exact: true }), 'Tab');
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(new RegExp(`${focusPath(0).replace('?', '\\?')}$`));
  // The arrows work once pin-walker has connected, after the page's scripts have loaded.
  await page.waitForLoadState('networkidle');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(`${key} · Round ${roundNumber} · ${REVIEW_AREAS[0]} · section 1 of 2`);
  await expectShownPin(page, 1);
  await expect(shownStop(page).getByRole('button', { name: 'Fixed' }), 'focus starts on the shown pin\'s first choice').toBeFocused();

  await page.keyboard.press('ArrowRight');
  await expectShownPin(page, 2);
  await expect(page).toHaveURL(/[?&]pin=2$/);
  await expect(shownStop(page).getByRole('button', { name: 'Fixed' }), 'the choice focused moves to the shown bar').toBeFocused();

  await page.keyboard.press('ArrowRight');
  await expectShownPin(page, 1);
  await page.keyboard.press('ArrowLeft');
  await expectShownPin(page, 2);
  await page.keyboard.press('ArrowLeft');
  await expectShownPin(page, 1);
  await page.keyboard.press('ArrowLeft');
  await expectShownPin(page, 2);
  await expect(shownStop(page).getByRole('button', { name: 'Fixed' })).toBeFocused();

  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/[?&]side=after&pin=1$/);
  await page.waitForLoadState('networkidle');
  await expect(page.locator(TOAST_REGION)).toContainText('Pin 2 marked fixed.');
  await expectShownPin(page, 1);
  await expect(shownStop(page).getByRole('button', { name: 'Fixed' }), 'a choice moves to the next pin and focuses its first choice').toBeFocused();

  await pressUntilFocused(page, page.getByRole('link', { name: 'Exit full screen' }), 'Shift+Tab');
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(new RegExp(`${roundPath()}\\?area=0$`));
  await expect(page.getByRole('link', { name: 'Full screen', exact: true }), 'focus is back on the link that opened it').toBeFocused();
  const pinRow = (note: string) => page.locator('aside#round-panel .round-panel__pin').filter({ hasText: note });
  await expect(pinRow(EARLIER_PIN_NOTES[1]).locator('.verdict-tag'), 'the choice made in full screen').toHaveText('Fixed');
  await expect(pinRow(LONG_PIN_NOTE).locator('.verdict-tag')).toHaveText('Not checked yet');

  await page.waitForLoadState('networkidle');
  await pressUntilFocused(page, page.getByRole('link', { name: 'Full screen', exact: true }), 'Tab');
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(new RegExp(`${focusPath(0).replace('?', '\\?')}$`));
  await page.waitForLoadState('networkidle');
  await expect(shownStop(page).getByRole('button', { name: 'Fixed' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page).toHaveURL(new RegExp(`${roundPath()}\\?area=0$`));
  await expect(page.getByRole('heading', { level: 1, name: new RegExp(`^Round ${roundNumber}`) })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Full screen', exact: true })).toBeFocused();
  await context.close();
});

// P14.T8 step 2: every round has a full screen, Round 0 also from its card on the ticket page. The seed gives a new
// ticket Mia's Round 0 with one picture and REPORT_PIN_NOTES, the checklist round, then Omar's shared draft of two
// pictures, the first with EARLIER_PIN_NOTES.
test('Full screen opens from a shared draft, from Round 0 and from Round 0 on the ticket page; Escape goes back to each, focus on its link', async ({ browser }) => {
  const reportKey = await makeTicketForOmar(browser, 'Full-screen check of the report and draft pictures');
  const draftNumber = seedChecklistRound(reportKey) + 1;
  const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
  await drawThePictures(context);
  const fullScreenLink = page.getByRole('link', { name: 'Full screen', exact: true });

  await page.goto(`/t/${reportKey}/rounds/${draftNumber}`, { waitUntil: 'networkidle' });
  await pressUntilFocused(page, fullScreenLink, 'Tab');
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(new RegExp(`/t/${reportKey}/rounds/${draftNumber}/focus\\?screenshot=1$`));
  await page.waitForLoadState('networkidle');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(`${reportKey} · Round ${draftNumber} · Screenshot 1 of 2`);
  await expect(shownStop(page).locator('.focus-bar__note')).toHaveText(EARLIER_PIN_NOTES[0]);
  await page.keyboard.press('ArrowRight');
  await expect(shownStop(page).locator('.focus-bar__note')).toHaveText(EARLIER_PIN_NOTES[1]);
  await page.getByRole('link', { name: 'Next', exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`/focus\\?screenshot=2$`));
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(`${reportKey} · Round ${draftNumber} · Screenshot 2 of 2`);
  await page.waitForLoadState('networkidle');
  await page.keyboard.press('Escape');
  await expect(page).toHaveURL(new RegExp(`/t/${reportKey}/rounds/${draftNumber}\\?screenshot=2$`));
  await expect(page.locator('.screenshot-pager__position')).toHaveText('2 of 2');
  await expect(fullScreenLink).toBeFocused();

  await page.goto(`/t/${reportKey}/rounds/0`, { waitUntil: 'networkidle' });
  await pressUntilFocused(page, fullScreenLink, 'Tab');
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(new RegExp(`/t/${reportKey}/rounds/0/focus\\?screenshot=1$`));
  await page.waitForLoadState('networkidle');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(`${reportKey} · Round 0 · Screenshot 1 of 1`);
  await expect(shownStop(page).locator('.focus-bar__note')).toHaveText(REPORT_PIN_NOTES[0]);
  await page.keyboard.press('Escape');
  await expect(page).toHaveURL(new RegExp(`/t/${reportKey}/rounds/0\\?screenshot=1$`));
  await expect(fullScreenLink).toBeFocused();

  await page.goto(`/t/${reportKey}`, { waitUntil: 'networkidle' });
  const cardLink = page.locator('.round-card').getByRole('link', { name: 'Full screen', exact: true });
  await expect(cardLink, 'only Round 0\'s card has one').toHaveCount(1);
  await cardLink.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(new RegExp(`/t/${reportKey}/rounds/0/focus\\?from=ticket$`));
  await page.waitForLoadState('networkidle');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(`${reportKey} · Round 0 · Screenshot 1 of 1`);
  await page.keyboard.press('Escape');
  await expect(page).toHaveURL(new RegExp(`/t/${reportKey}$`));
  await expect(cardLink, 'focus is back on Round 0\'s link once the Rounds frame has loaded').toBeFocused();
  await context.close();
});

test('the full-screen review has no serious accessibility violations, wide or at 360px', async ({ browser }) => {
  const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
  await drawThePictures(context);
  await page.goto(focusPath(0), { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  expect(await seriousViolations(page)).toEqual([]);

  await page.setViewportSize({ width: 360, height: 800 });
  expect(await seriousViolations(page), 'the long note scrolls in the bar here').toEqual([]);
  await context.close();
});

const SMALL_SCREENS = [
  { name: 'at 360px wide', viewport: { width: 360, height: 800 } },
  { name: 'at 200% zoom of a 1280×720 screen', viewport: { width: 640, height: 360 } },
];

for (const { name, viewport } of SMALL_SCREENS) {
  test(`${name} the pin bar with a long note keeps Fixed and Still wrong on screen and the pin layer matches the picture`, async ({ browser }) => {
    const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
    await drawThePictures(context);
    await page.setViewportSize(viewport);
    await page.goto(focusPath(0), { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);

    await expect(shownStop(page).locator('.focus-bar__note')).toHaveText(LONG_PIN_NOTE);
    await expect(shownStop(page).getByRole('button', { name: 'Fixed' })).toBeInViewport({ ratio: 1 });
    await expect(shownStop(page).getByRole('button', { name: 'Still wrong' })).toBeInViewport({ ratio: 1 });
    await expect(page.getByRole('link', { name: 'Exit full screen' })).toBeInViewport({ ratio: 1 });
    const scroll = await page.evaluate(() => ({
      width: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      height: document.documentElement.scrollHeight,
      clientHeight: document.documentElement.clientHeight,
    }));
    expect(scroll.width, 'no sideways scroll').toBeLessThanOrEqual(scroll.clientWidth);
    expect(scroll.height, 'no scroll down to reach the bar').toBeLessThanOrEqual(scroll.clientHeight);

    const image = page.locator('.focus-review__picture img.screenshot__image');
    expect(await image.evaluate((picture: HTMLImageElement) => picture.naturalWidth), 'the drawn picture loaded').toBe(640);
    const imageBox = await image.boundingBox();
    const layerBox = await page.locator('.focus-review__picture .pin-layer').boundingBox();
    expect(imageBox).not.toBeNull();
    expect(layerBox).not.toBeNull();
    expect(imageBox?.height ?? 0, 'the picture keeps some of the screen').toBeGreaterThan(0);
    for (const side of ['x', 'y', 'width', 'height'] as const) {
      expect(Math.abs((layerBox?.[side] ?? -100) - (imageBox?.[side] ?? 100)), `the pin layer's ${side} is the picture's`).toBeLessThanOrEqual(1);
    }
    await context.close();
  });
}

test('at 390px the pin bar keeps Previous and Next on one row, at its two ends, with the note and choices between', async ({ browser }) => {
  const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
  await drawThePictures(context);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(focusPath(0), { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);

  const stop = shownStop(page);
  await expect(stop.getByRole('button', { name: 'Still wrong' }), 'the choices are in the bar').toBeVisible();
  const stopBox = await stop.boundingBox();
  const previousBox = await stop.getByRole('link', { name: 'Previous pin' }).boundingBox();
  const nextBox = await stop.getByRole('link', { name: 'Next pin' }).boundingBox();
  const textBox = await stop.locator('.focus-bar__text').boundingBox();
  expect(stopBox).not.toBeNull();
  expect(previousBox).not.toBeNull();
  expect(nextBox).not.toBeNull();
  expect(textBox).not.toBeNull();
  if (stopBox === null || previousBox === null || nextBox === null || textBox === null) {
    return;
  }

  expect(Math.abs(nextBox.y - previousBox.y), 'Next is on Previous\'s row, not wrapped under it').toBeLessThanOrEqual(1);
  expect(previousBox.x + previousBox.width, 'Previous is left of the note').toBeLessThanOrEqual(textBox.x);
  expect(nextBox.x, 'Next is right of the note').toBeGreaterThanOrEqual(textBox.x + textBox.width);
  expect(stopBox.x + stopBox.width - (nextBox.x + nextBox.width), 'Next sits at the bar\'s right end').toBeLessThanOrEqual(17);
  await context.close();
});

for (const viewport of [{ width: 1280, height: 800 }, { width: 360, height: 800 }]) {
  test(`at ${viewport.width}px a picture still processing takes the column's full width`, async ({ browser }) => {
    const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
    await page.setViewportSize(viewport);
    await page.goto(focusPath(1), { waitUntil: 'networkidle' });

    await expect(page.getByRole('heading', { level: 1 })).toHaveText(`${key} · Round ${roundNumber} · ${REVIEW_AREAS[1]} · section 2 of 2`);
    await expect(page.locator('.focus-review__picture').getByRole('status')).toHaveText('Processing…');
    const columnBox = await page.locator('.focus-review__picture').boundingBox();
    const frameBox = await page.locator('.focus-review__picture .screenshot__frame--placeholder').boundingBox();
    expect(columnBox?.width ?? 0).toBeGreaterThan(0);
    expect(Math.abs((frameBox?.width ?? 0) - (columnBox?.width ?? 0)), 'the placeholder is as wide as the column').toBeLessThanOrEqual(1);
    await expect(page.locator('.focus-review__none')).toBeInViewport({ ratio: 1 });
    await context.close();
  });
}
