import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Locator, type Page } from '@playwright/test';
import { MIA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { REPORT_PIN_NOTES, seedChecklistRound } from './review-round-seed';
import { makeTicketForOmar, openAs, TOAST_REGION } from './ticket';

// The checklist card in the ticket's Rounds section (P05.T6) as Omar, the assignee, and Mia, the tester of Shop. What
// the server answers for every change and refusal is proven in tests/Functional/Review/ChecklistControllerTest.php;
// here it is proven through the page: the chip colours, the focus after each change, the failed-save reload, axe and
// the narrow screen.
//
// No command makes a report with ready pictures in a browser test, so Round 0 and its checklist are seeded through the
// dev-only console command (review-round-seed.ts), and every case runs on any stack.

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

// The chip colours (ticket-page.md, Rounds): Open has no fill, so it cannot pass for a button, and --on-surface-var
// text; then the ping, passed and error containers, as tokens.css sets them for the light theme and review.css's
// check-state rules use them.
const CHIP_COLOURS = {
  open: { background: 'rgba(0, 0, 0, 0)', text: 'rgb(63, 73, 70)' },
  fixed: { background: 'rgb(208, 228, 255)', text: 'rgb(0, 29, 52)' },
  ok: { background: 'rgb(131, 248, 205)', text: 'rgb(0, 33, 22)' },
  notFixed: { background: 'rgb(255, 218, 214)', text: 'rgb(147, 0, 10)' },
} as const;

const SAVE_FAILED = 'Could not save the checklist change. Try again.';

// The cases follow one ticket's checklist: the changes of one case are the state of the next.
test.describe.configure({ mode: 'serial' });

let key = '';

test.beforeAll(async ({ browser }) => {
  key = await makeTicketForOmar(browser, 'Checklist check on the coupon field');
  seedChecklistRound(key);
});

async function openTicket(browser: Browser, storageState: string): Promise<{ page: Page; close: () => Promise<void> }> {
  const { context, page } = await openAs(browser, storageState);
  await page.emulateMedia({ colorScheme: 'light' });
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await page.getByRole('heading', { level: 2, name: 'Rounds' }).scrollIntoViewIfNeeded();
  await expect(checklistHeading(page)).toBeVisible();
  return { page, close: () => context.close() };
}

function checklistHeading(page: Page): Locator {
  return page.getByRole('heading', { level: 3, name: 'Checklist from the report', exact: true });
}

function card(page: Page): Locator {
  return page.locator('article.round-card').filter({ has: checklistHeading(page) });
}

function row(page: Page, pinNumber: 1 | 2): Locator {
  return card(page).locator('li.checklist__row').filter({ hasText: REPORT_PIN_NOTES[pinNumber - 1] });
}

function button(scope: Locator, name: 'Mark fixed' | 'OK' | 'Not fixed'): Locator {
  return scope.getByRole('button', { name, exact: true });
}

async function expectChip(pinRow: Locator, label: string, colours: { background: string; text: string }): Promise<void> {
  const chip = pinRow.locator('.check-state');
  await expect(chip).toHaveText(label);
  await expect(chip).toHaveCSS('background-color', colours.background);
  await expect(chip).toHaveCSS('color', colours.text);
}

async function expectButtons(page: Page, counts: { markFixed: number; ok: number; notFixed: number }): Promise<void> {
  await expect(button(card(page), 'Mark fixed')).toHaveCount(counts.markFixed);
  await expect(button(card(page), 'OK')).toHaveCount(counts.ok);
  await expect(button(card(page), 'Not fixed')).toHaveCount(counts.notFixed);
}

test('every item starts Open, Omar sees only Mark fixed and Mia no button at all', async ({ browser }) => {
  const omar = await openTicket(browser, OMAR_STORAGE_STATE);
  await expect(card(omar.page)).toContainText('0 of 2 checked OK');
  await expectChip(row(omar.page, 1), 'Open', CHIP_COLOURS.open);
  await expectChip(row(omar.page, 2), 'Open', CHIP_COLOURS.open);
  await expectButtons(omar.page, { markFixed: 2, ok: 0, notFixed: 0 });
  await omar.close();

  const mia = await openTicket(browser, MIA_STORAGE_STATE);
  await expectChip(row(mia.page, 1), 'Open', CHIP_COLOURS.open);
  await expect(card(mia.page).getByRole('button')).toHaveCount(0);
  await mia.close();
});

test('Omar marks both pins fixed: the chips turn Fixed · to check and focus goes to the next Mark fixed, then the heading', async ({ browser }) => {
  const { page, close } = await openTicket(browser, OMAR_STORAGE_STATE);

  await button(row(page, 1), 'Mark fixed').click();
  await expect(page.locator(TOAST_REGION)).toContainText('Pin 1 marked fixed.');
  await expectChip(row(page, 1), 'Fixed · to check', CHIP_COLOURS.fixed);
  await expect(button(row(page, 2), 'Mark fixed')).toBeFocused();
  await expect(row(page, 1).getByRole('button')).toHaveCount(0);

  await button(row(page, 2), 'Mark fixed').click();
  await expect(page.locator(TOAST_REGION)).toContainText('Pin 2 marked fixed.');
  await expectChip(row(page, 2), 'Fixed · to check', CHIP_COLOURS.fixed);
  await expect(checklistHeading(page), 'Omar has no button left on the card').toBeFocused();
  await expectButtons(page, { markFixed: 0, ok: 0, notFixed: 0 });
  await expect(card(page)).toContainText('0 of 2 checked OK');
  await close();
});

test('Mia checks pin 1 OK and sends pin 2 back: the summary counts it, the chips change and focus moves on', async ({ browser }) => {
  const { page, close } = await openTicket(browser, MIA_STORAGE_STATE);
  await expectButtons(page, { markFixed: 0, ok: 2, notFixed: 2 });

  await button(row(page, 1), 'OK').click();
  await expect(page.locator(TOAST_REGION)).toContainText('Pin 1 checked OK.');
  await expect(card(page)).toContainText('1 of 2 checked OK');
  await expectChip(row(page, 1), 'Checked OK', CHIP_COLOURS.ok);
  await expect(button(row(page, 2), 'OK'), 'focus goes to the next OK').toBeFocused();
  await expect(row(page, 1).getByRole('button'), 'only Undo is left on the checked pin').toHaveText(['Undo']);

  await button(row(page, 2), 'Not fixed').click();
  await expect(page.locator(TOAST_REGION)).toContainText('Pin 2 sent back.');
  await expectChip(row(page, 2), 'Not fixed', CHIP_COLOURS.notFixed);
  await expect(checklistHeading(page), 'Mia has no button left on the card').toBeFocused();
  await expectButtons(page, { markFixed: 0, ok: 0, notFixed: 0 });
  await expect(card(page)).toContainText('1 of 2 checked OK');
  await close();
});

test('on the round page Mia undoes her Not fixed on pin 2 by its Undo, and sends it back again', async ({ browser }) => {
  const { context, page } = await openAs(browser, MIA_STORAGE_STATE);
  await page.emulateMedia({ colorScheme: 'light' });
  await page.goto(`/t/${key}/rounds/0`, { waitUntil: 'networkidle' });
  const pinRow = page.locator('.round-panel li.round-pin').filter({ hasText: REPORT_PIN_NOTES[1] });
  await expect(pinRow.locator('.check-state')).toHaveText('Not fixed');

  await pinRow.getByRole('button', { name: 'Undo', exact: true }).click();
  await expect(page.locator(TOAST_REGION)).toContainText('Check on pin 2 undone.');
  await expect(pinRow.locator('.check-state')).toHaveText('Fixed · to check');
  await expect(pinRow.getByRole('button'), 'the pin waits for its check again').toHaveText(['OK', 'Not fixed']);
  await expect(pinRow, 'the redirect brings the person back to the pin').toBeFocused();

  // In the full-screen bar the state is a small status centred on the buttons beside it, never stretched to their height.
  await page.goto(`/t/${key}/rounds/0/focus?pin=2`, { waitUntil: 'networkidle' });
  const bar = page.locator('.focus-bar__stop:not([hidden])');
  const chip = bar.locator('.check-state');
  const notFixed = bar.getByRole('button', { name: 'Not fixed', exact: true });
  await expect(chip).toHaveText('Fixed · to check');
  const chipBox = await chip.boundingBox();
  const buttonBox = await notFixed.boundingBox();
  if (chipBox === null || buttonBox === null) {
    throw new Error('The chip or the Not fixed button has no box.');
  }
  expect(chipBox.height, 'the chip is shorter than the buttons').toBeLessThan(buttonBox.height * 0.7);
  expect(Math.abs(chipBox.y + chipBox.height / 2 - (buttonBox.y + buttonBox.height / 2)), 'the chip is centred on the buttons').toBeLessThanOrEqual(1);

  await notFixed.click();
  await expect(page.locator(TOAST_REGION)).toContainText('Pin 2 sent back.');
  await expect(page.locator('.focus-bar__stop:not([hidden]) .check-state')).toHaveText('Not fixed');
  await expect(page.locator('.focus-bar__stop:not([hidden])').getByRole('button', { name: 'Undo', exact: true })).toBeVisible();
  await context.close();
});

test('Omar marks the pin sent back fixed again and is offered nothing on the pin checked OK', async ({ browser }) => {
  const { page, close } = await openTicket(browser, OMAR_STORAGE_STATE);
  await expectChip(row(page, 1), 'Checked OK', CHIP_COLOURS.ok);
  await expect(row(page, 1).getByRole('button')).toHaveCount(0);
  await expectButtons(page, { markFixed: 1, ok: 0, notFixed: 0 });

  await button(row(page, 2), 'Mark fixed').click();
  await expect(page.locator(TOAST_REGION)).toContainText('Pin 2 marked fixed.');
  await expectChip(row(page, 2), 'Fixed · to check', CHIP_COLOURS.fixed);
  await expect(checklistHeading(page)).toBeFocused();
  await expectButtons(page, { markFixed: 0, ok: 0, notFixed: 0 });
  await close();
});

test('a change answered 404 reloads the page once with the failed-save toast and no error page', async ({ browser }) => {
  const { page, close } = await openTicket(browser, MIA_STORAGE_STATE);
  await page.route(`**/t/${key}/checklist/**`, async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback();
      return;
    }
    await route.fulfill({ status: 404, contentType: 'text/html', body: '<!doctype html><title>Gone</title><h1>Nothing was found here</h1>' });
  });
  // Full document loads only: Turbo's same-document history changes also fire framenavigated.
  let pageLoads = 0;
  page.on('load', () => {
    pageLoads += 1;
  });

  await button(row(page, 2), 'OK').click();
  await expect(page.locator(TOAST_REGION)).toContainText(SAVE_FAILED);
  await page.waitForLoadState('networkidle');
  expect(pageLoads, 'the page reloads once').toBe(1);
  await expect(page).toHaveURL(new RegExp(`/t/${key}$`));
  await expect(page.getByText('Nothing was found here')).toHaveCount(0);
  await expect(checklistHeading(page)).toBeVisible();
  await expectChip(row(page, 2), 'Fixed · to check', CHIP_COLOURS.fixed);
  await expect(card(page)).toContainText('1 of 2 checked OK');
  await expectButtons(page, { markFixed: 0, ok: 1, notFixed: 1 });
  await close();
});

test('the checklist card has no accessibility violations', async ({ browser }) => {
  const { page, close } = await openTicket(browser, MIA_STORAGE_STATE);
  await page.evaluate(() => document.fonts.ready);
  const checklistId = await card(page).locator('.checklist').getAttribute('id');
  expect(checklistId).not.toBeNull();

  const { violations } = await new AxeBuilder({ page }).include(`#${checklistId}`).withTags(AXE_TAGS).analyze();
  expect(violations.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
  await close();
});

test('at 360px wide the ticket page with the checklist does not scroll sideways', async ({ browser }) => {
  const { page, close } = await openTicket(browser, MIA_STORAGE_STATE);
  await page.setViewportSize({ width: 360, height: 800 });
  await page.evaluate(() => document.fonts.ready);

  await card(page).scrollIntoViewIfNeeded();
  await expect(button(row(page, 2), 'Not fixed')).toBeVisible();
  const widths = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
  expect(widths.scroll).toBeLessThanOrEqual(widths.client);
  await close();
});
