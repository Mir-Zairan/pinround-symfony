import type { BrowserContext, Locator, Page } from '@playwright/test';
import path from 'node:path';
import { PageInspector, TEST_PAGE_ORIGIN, expect, openPopupFor, tabIdOf, test, type ExtensionPageGlobals } from './extension';

// The scenario player's page side (extension/src/player/page-player.js) in real Chromium: each step runs as the
// extension runs it, through step-runner.js in an extension page, which injects the player into the tab's isolated
// world and checks its answer. The highlight's shadow root is closed, so PageInspector reads it.

const PLAYER_PAGE_FILE = path.join(__dirname, 'fixtures', 'extension', 'player-page.html');
const PLAYER_PAGE_URL = `${TEST_PAGE_ORIGIN}/player`;
const PAGE_PLAYER_FILES = ['src/player/player-text.js', 'src/player/page-player.js'];
const HIGHLIGHT_TAG = 'pinround-player-highlight';
// The ring sits this many CSS px outside the element (HIGHLIGHT_GAP_PX in page-player.js).
const HIGHLIGHT_GAP_PX = 4;

type Target = { role: string; name?: string } | { text: string } | { label: string };
type Step = { type: string; say: string; target?: Target; text?: string; option?: string };

interface StepAnswer {
  ok?: true;
  stopped?: true;
  message?: string;
  expected?: string;
  found?: string;
}

interface PlayerTab {
  page: Page;
  extensionPage: Page;
  tabId: number;
  inspector: PageInspector;
}

/** The player page in `testPage`, answered on the test page's origin, and an extension page to run steps from. */
async function openPlayerPage(context: BrowserContext, testPage: Page): Promise<PlayerTab> {
  await context.route(PLAYER_PAGE_URL, (route) => route.fulfill({ path: PLAYER_PAGE_FILE, contentType: 'text/html' }));
  await testPage.goto(PLAYER_PAGE_URL);
  const extensionPage = await openPopupFor(context, testPage);
  const tabId = await tabIdOf(extensionPage, PLAYER_PAGE_URL);
  return { page: testPage, extensionPage, tabId, inspector: await PageInspector.of(context, testPage) };
}

/**
 * Runs the step as the extension does, with stepRunnerFor from step-runner.js and the real browser adapter. Passed as
 * source text, so Playwright's transform leaves the dynamic imports for the extension page to resolve.
 */
async function playStep(tab: PlayerTab, step: Step): Promise<StepAnswer> {
  return tab.extensionPage.evaluate(`(async () => {
    const { browser } = await import('/src/shared/browser.js');
    const { stepRunnerFor } = await import('/src/player/step-runner.js');
    const refuse = async () => { throw new Error('This spec opens no page and captures nothing.'); };
    const runStep = stepRunnerFor(browser, { tabId: ${JSON.stringify(tab.tabId)}, openUrl: refuse, captureToNotebook: refuse });
    return runStep(${JSON.stringify(step)});
  })()`);
}

/** Injects the page player as the runner does and calls one of its functions in the isolated world. */
async function callPagePlayer(tab: PlayerTab, call: 'highlightTarget' | 'clearHighlight', target?: Target): Promise<StepAnswer | null> {
  return tab.extensionPage.evaluate(async ({ tabId, files, name, argument }) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    await chrome.scripting.executeScript({ target: { tabId }, files });
    const [injection] = await chrome.scripting.executeScript({
      target: { tabId },
      args: [name, argument ?? null],
      func: (functionName: string, given: unknown) => {
        const player = (globalThis as unknown as { pinroundPagePlayer: Record<string, (target: unknown) => StepAnswer | undefined> }).pinroundPagePlayer;
        return player[functionName](given) ?? null;
      },
    });
    return (injection?.result ?? null) as StepAnswer | null;
  }, { tabId: tab.tabId, files: PAGE_PLAYER_FILES, name: call, argument: target });
}

/**
 * Runs wait_for in the isolated world with a wait that, the first time the player waits, presses the page's
 * "Check stock" button, whose own script then shows the stock line: the element appears while the step waits, with no
 * real time passing. Answers the step's answer and how often it waited.
 */
async function waitForWithStockCheck(tab: PlayerTab, step: Step, timeoutMs: number): Promise<{ answer: StepAnswer; waits: number }> {
  return tab.extensionPage.evaluate(async ({ tabId, files, waitStep, timeout }) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    await chrome.scripting.executeScript({ target: { tabId }, files });
    const [injection] = await chrome.scripting.executeScript({
      target: { tabId },
      args: [waitStep, timeout],
      func: async (pageStep: Step, timeoutMs: number) => {
        let waits = 0;
        const player = (globalThis as unknown as { pinroundPagePlayer: { runStep(step: Step, options: object): Promise<StepAnswer> } }).pinroundPagePlayer;
        const answer = await player.runStep(pageStep, {
          timeoutMs,
          wait: async () => {
            waits += 1;
            if (1 === waits) {
              (document.getElementById('check-stock') as HTMLButtonElement).click();
            }
          },
        });
        return { answer, waits };
      },
    });
    return injection.result as { answer: StepAnswer; waits: number };
  }, { tabId: tab.tabId, files: PAGE_PLAYER_FILES, waitStep: step, timeout: timeoutMs });
}

/** The highlight ring's border box in viewport CSS px, or null when the page shows no highlight. */
async function highlightBox(tab: PlayerTab): Promise<{ left: number; top: number; right: number; bottom: number } | null> {
  const rings = (await tab.inspector.shadowElementsOf(HIGHLIGHT_TAG)).filter((element) => element.className === 'ring');
  if (rings.length === 0) {
    return null;
  }
  expect(rings).toHaveLength(1);
  return (await tab.inspector.boxesOf(rings[0].backendNodeId))?.border ?? null;
}

async function expectHighlightAround(tab: PlayerTab, element: Locator): Promise<void> {
  const box = await element.boundingBox();
  const ring = await highlightBox(tab);
  expect(box).not.toBeNull();
  expect(ring).not.toBeNull();
  if (box === null || ring === null) {
    return;
  }
  expect(ring.left).toBeCloseTo(box.x - HIGHLIGHT_GAP_PX, 0);
  expect(ring.top).toBeCloseTo(box.y - HIGHLIGHT_GAP_PX, 0);
  expect(ring.right).toBeCloseTo(box.x + box.width + HIGHLIGHT_GAP_PX, 0);
  expect(ring.bottom).toBeCloseTo(box.y + box.height + HIGHLIGHT_GAP_PX, 0);
}

const highlightHostCount = (page: Page) => page.evaluate((tag) => document.getElementsByTagName(tag).length, HIGHLIGHT_TAG);
const fieldEventsOf = (page: Page) => page.evaluate(() => (window as unknown as { fieldEvents: string[] }).fieldEvents);
const codePointsOf = (text: string) => [...text].length;

test('each target kind finds its one visible element: by role and name, by visible text, by label', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);
  const page = tab.page;
  const found: Array<[Target, Locator]> = [
    [{ role: 'button', name: 'Pay 12.40 EUR' }, page.locator('#pay')],
    [{ role: 'heading', name: 'Rye bread' }, page.locator('h2', { hasText: 'Rye bread' })],
    [{ role: 'img', name: 'Acme Foods logo' }, page.locator('img')],
    [{ role: 'link', name: 'Delivery terms' }, page.locator('#terms-link')],
    [{ role: 'combobox', name: 'Delivery day' }, page.locator('#day')],
    [{ role: 'textbox', name: 'Coupon code' }, page.locator('#coupon')],
    [{ role: 'textbox', name: 'Note for the driver' }, page.locator('#note')],
    [{ text: 'Delivery terms' }, page.locator('#terms-link')],
    [{ text: 'Basket: 0 items' }, page.locator('#basket')],
    [{ text: 'Rye' }, page.locator('h2', { hasText: 'Rye bread' })],
    [{ label: 'Email for the receipt' }, page.locator('#email')],
    [{ label: 'Note for the driver' }, page.locator('#note')],
    [{ label: 'Coupon code' }, page.locator('#coupon')],
  ];
  expect(found.length).toBeGreaterThan(0);

  for (const [target, element] of found) {
    expect(await callPagePlayer(tab, 'highlightTarget', target), JSON.stringify(target)).toEqual({ ok: true });
    await expectHighlightAround(tab, element);
  }
  expect(await highlightHostCount(page)).toBe(1);
});

test('two visible matches and none are refused with their counts, and nothing is clicked', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);

  expect(await playStep(tab, { type: 'click', say: 'Add it.', target: { role: 'button', name: 'Add to basket' } })).toEqual({
    stopped: true,
    message: 'Found 2 visible elements with role button named "Add to basket"; a step needs exactly one.',
  });
  expect(await playStep(tab, { type: 'click', say: 'Pay later.', target: { role: 'button', name: 'Pay later' } })).toEqual({
    stopped: true,
    message: 'Found no visible element with role button named "Pay later".',
  });
  expect(await playStep(tab, { type: 'click', say: 'Pay tomorrow.', target: { text: 'Pay tomorrow' } })).toEqual({
    stopped: true,
    message: 'Found no visible element with the text "Pay tomorrow".',
  });
  expect(await playStep(tab, { type: 'type', say: 'Type the phone.', target: { label: 'Phone' }, text: '0123' })).toEqual({
    stopped: true,
    message: 'Found no visible element labelled "Phone".',
  });

  await expect(tab.page.locator('#basket-count')).toHaveText('0');
  expect(await highlightHostCount(tab.page)).toBe(0);
  expect(await fieldEventsOf(tab.page)).toEqual([]);
});

test('click presses the element; a disabled one is refused and its handler does not run', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);
  await expect(tab.page.locator('#thanks')).toBeHidden();

  expect(await playStep(tab, { type: 'click', say: 'Pay by invoice.', target: { role: 'button', name: 'Pay by invoice' } })).toEqual({
    stopped: true,
    message: 'The element with role button named "Pay by invoice" is disabled, so it was not clicked.',
  });
  await expect(tab.page.locator('#thanks')).toBeHidden();

  expect(await playStep(tab, { type: 'click', say: 'Pay.', target: { role: 'button', name: 'Pay 12.40 EUR' } })).toEqual({ ok: true });
  await expect(tab.page.locator('#thanks')).toBeVisible();
  expect(await playStep(tab, { type: 'click', say: 'Read the terms.', target: { text: 'Delivery terms' } })).toEqual({ ok: true });
  await expect(tab.page).toHaveURL(`${PLAYER_PAGE_URL}#terms`);
});

test('type sets the text with input and change events: CRLF, multibyte text and emoji kept as the field keeps them, and empty text clears', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);
  const note = 'Leave the jars at the door.\r\nRing twice \u{1F353} — Grüße, ça va 日本';

  expect(await playStep(tab, { type: 'type', say: 'Type the note.', target: { label: 'Note for the driver' }, text: note })).toEqual({ ok: true });
  expect(await playStep(tab, { type: 'type', say: 'Type the coupon.', target: { label: 'Coupon code' }, text: '\u{FEFF}SHOP\r\n10 \u{1F35E}' })).toEqual({ ok: true });
  expect(await playStep(tab, { type: 'type', say: 'Type the email.', target: { label: 'Email for the receipt' }, text: 'mia@acme-foods.test' })).toEqual({ ok: true });

  await expect(tab.page.locator('#note')).toHaveValue('Leave the jars at the door.\nRing twice \u{1F353} — Grüße, ça va 日本');
  await expect(tab.page.locator('#coupon')).toHaveValue('\u{FEFF}SHOP10 \u{1F35E}');
  await expect(tab.page.locator('#email')).toHaveValue('mia@acme-foods.test');
  expect(await fieldEventsOf(tab.page)).toEqual(['input note', 'change note', 'input coupon', 'change coupon', 'input email', 'change email']);

  expect(await playStep(tab, { type: 'type', say: 'Clear the email.', target: { label: 'Email for the receipt' }, text: '' })).toEqual({ ok: true });
  await expect(tab.page.locator('#email')).toHaveValue('');
});

test('type into a password field is refused with a plain message, and the field stays empty with no event', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);
  const refusal = { stopped: true, message: 'The player never types into a password field. Type it yourself, then play on.' };

  expect(await playStep(tab, { type: 'type', say: 'Type the password.', target: { label: 'Password' }, text: 'jam-jar-2026' })).toEqual(refusal);
  expect(await playStep(tab, { type: 'type', say: 'Type the PIN.', target: { label: 'New PIN' }, text: '4711' })).toEqual(refusal);

  await expect(tab.page.locator('#password')).toHaveValue('');
  await expect(tab.page.locator('#pin')).toHaveValue('');
  expect(await fieldEventsOf(tab.page)).toEqual([]);
  // The twin: the same label lookup types into a field that is no password field.
  expect(await playStep(tab, { type: 'type', say: 'Type the coupon.', target: { label: 'Coupon code' }, text: 'SHOP10' })).toEqual({ ok: true });
  await expect(tab.page.locator('#coupon')).toHaveValue('SHOP10');
});

test('type refuses a field it cannot fill and changes nothing, and stops when the field does not keep the text', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);
  const typeInto = (label: string, text: string) => playStep(tab, { type: 'type', say: `Type into ${label}.`, target: { label }, text });

  expect(await typeInto('Order number', 'SHOP-8')).toEqual({ stopped: true, message: 'The field labelled "Order number" cannot be changed, so nothing was typed.' });
  expect(await typeInto('Coupon code', 'SHOP-2026-WINTER')).toEqual({ stopped: true, message: 'The field labelled "Coupon code" takes at most 12 characters, so nothing was typed.' });
  expect(await typeInto('Delivery day', 'Friday')).toEqual({ stopped: true, message: 'The element labelled "Delivery day" is no text field, so nothing was typed.' });
  await expect(tab.page.locator('#order-number')).toHaveValue('SHOP-7');
  await expect(tab.page.locator('#coupon')).toHaveValue('');
  await expect(tab.page.locator('#day')).toHaveValue('Monday');
  expect(await fieldEventsOf(tab.page)).toEqual([]);

  // The number field's label comes from aria-labelledby; it takes "3" and drops "three", which must not look typed.
  expect(await typeInto('Jars of jam', '3')).toEqual({ ok: true });
  await expect(tab.page.locator('#jars')).toHaveValue('3');
  expect(await typeInto('Jars of jam', 'three')).toEqual({ stopped: true, message: 'The field did not keep the text.', expected: 'three', found: '' });
  expect(await typeInto('Coupon code', 'SHOP-WINTER1')).toEqual({ ok: true });
  await expect(tab.page.locator('#coupon')).toHaveValue('SHOP-WINTER1');
});

test('select on an element that is no list is refused; a copy hidden from screen readers is not found by role', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);

  expect(await playStep(tab, { type: 'select', say: 'Choose Monday.', target: { role: 'button', name: 'Pay 12.40 EUR' }, option: 'Monday' })).toEqual({
    stopped: true,
    message: 'The element with role button named "Pay 12.40 EUR" is no list to choose from.',
  });
  expect(await playStep(tab, { type: 'click', say: 'Pay.', target: { text: 'Pay 12.40 EUR' } })).toEqual({
    stopped: true,
    message: 'Found 2 visible elements with the text "Pay 12.40 EUR"; a step needs exactly one.',
  });
  await expect(tab.page.locator('#thanks')).toBeHidden();
});

test('select chooses the option with the visible text, and an option the list does not hold is refused', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);

  expect(await playStep(tab, { type: 'select', say: 'Choose Sunday.', target: { label: 'Delivery day' }, option: 'Sunday' })).toEqual({
    stopped: true,
    message: 'Found no option "Sunday" in the list.',
  });
  await expect(tab.page.locator('#day')).toHaveValue('Monday');
  expect(await fieldEventsOf(tab.page)).toEqual([]);

  expect(await playStep(tab, { type: 'select', say: 'Choose Wednesday.', target: { label: 'Delivery day' }, option: 'Wednesday' })).toEqual({ ok: true });
  await expect(tab.page.locator('#day')).toHaveValue('Wednesday');
  expect(await fieldEventsOf(tab.page)).toEqual(['input day', 'change day']);
});

test('wait_for waits until the page shows the element, and refuses once the time is up', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);
  const waitForStock: Step = { type: 'wait_for', say: 'Wait for the stock.', target: { role: 'status' } };

  expect(await playStep(tab, { type: 'expect_visible', say: 'Stock shows.', target: { role: 'status' } })).toMatchObject({ stopped: true });
  const timedOut = await waitForWithStockCheck(tab, waitForStock, 0);
  expect(timedOut).toEqual({ answer: { stopped: true, message: 'Waited 0 seconds. Found no visible element with role status.' }, waits: 0 });
  await expect(tab.page.locator('#stock')).toBeHidden();

  const waited = await waitForWithStockCheck(tab, waitForStock, 10_000);

  expect(waited).toEqual({ answer: { ok: true }, waits: 1 });
  await expect(tab.page.locator('#stock')).toBeVisible();
  await expectHighlightAround(tab, tab.page.locator('#stock'));
});

test('a failed expect_text answers what was expected and what was found, capped and with control characters shown safely', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);

  expect(await playStep(tab, { type: 'expect_text', say: 'The basket is empty.', text: 'Basket:  0\r\nitems' })).toEqual({ ok: true });
  expect(await playStep(tab, { type: 'expect_text', say: 'The jam heading.', target: { role: 'heading', name: 'Strawberry jam' }, text: 'jam' })).toEqual({ ok: true });

  expect(await playStep(tab, { type: 'expect_text', say: 'The rye heading.', target: { role: 'heading', name: 'Strawberry jam' }, text: 'Rye bread' })).toEqual({
    stopped: true,
    message: 'The page does not show the expected text.',
    expected: 'Rye bread',
    found: 'Strawberry jam',
  });
  expect(await playStep(tab, { type: 'expect_text', say: 'The shelf code.', target: { text: 'Shelf code' }, text: 'Shelf code: A7' })).toEqual({
    stopped: true,
    message: 'The page does not show the expected text.',
    expected: 'Shelf code: A7',
    found: 'Shelf code: A\\u{1b}[31m7',
  });

  const onPage = await playStep(tab, { type: 'expect_text', say: 'Three in the basket.', text: 'Basket: 3 items' });
  expect(onPage).toMatchObject({ stopped: true, message: 'The page does not show the expected text.', expected: 'Basket: 3 items' });
  const found = onPage.found ?? '';
  expect(found.startsWith('Acme Foods order form Strawberry jam Add to basket Rye bread Add to basket Basket: 0 items Shelf code: A\\u{1b}[31m7')).toBe(true);
  expect(codePointsOf(found.replace('\\u{1b}', '\u001b'))).toBe(200);
  expect(found.endsWith('…')).toBe(true);
});

test('expect_visible passes on a visible element and answers expected and found for a hidden one', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);

  expect(await playStep(tab, { type: 'expect_visible', say: 'The thank-you shows.', target: { text: 'Thank you' } })).toEqual({
    stopped: true,
    message: 'Found no visible element with the text "Thank you".',
    expected: 'One visible element with the text "Thank you"',
    found: 'None visible',
  });
  expect(await playStep(tab, { type: 'expect_visible', say: 'Two add buttons.', target: { role: 'button', name: 'Add to basket' } })).toEqual({
    stopped: true,
    message: 'Found 2 visible elements with role button named "Add to basket"; a step needs exactly one.',
    expected: 'One visible element with role button named "Add to basket"',
    found: '2 visible',
  });

  await tab.page.locator('#pay').click();
  expect(await playStep(tab, { type: 'expect_visible', say: 'The thank-you shows.', target: { text: 'Thank you' } })).toEqual({ ok: true });
});

test('the highlight rings the current step\'s element, moves with the next step, is out of the page\'s reach and is removed after', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);

  expect(await playStep(tab, { type: 'click', say: 'Check the stock.', target: { role: 'button', name: 'Check stock' } })).toEqual({ ok: true });
  await expectHighlightAround(tab, tab.page.locator('#check-stock'));
  expect(await tab.page.evaluate((tag) => document.getElementsByTagName(tag)[0]?.shadowRoot, HIGHLIGHT_TAG)).toBeNull();

  expect(await playStep(tab, { type: 'type', say: 'Type the coupon.', target: { label: 'Coupon code' }, text: 'SHOP10' })).toEqual({ ok: true });
  await expectHighlightAround(tab, tab.page.locator('#coupon'));
  expect(await highlightHostCount(tab.page)).toBe(1);

  expect(await callPagePlayer(tab, 'clearHighlight')).toBeNull();
  expect(await highlightHostCount(tab.page)).toBe(0);
  expect(await highlightBox(tab)).toBeNull();

  expect(await playStep(tab, { type: 'click', say: 'Pay.', target: { role: 'button', name: 'Pay 12.40 EUR' } })).toEqual({ ok: true });
  expect(await highlightHostCount(tab.page)).toBe(1);
  expect(await playStep(tab, { type: 'click', say: 'Pay later.', target: { role: 'button', name: 'Pay later' } })).toMatchObject({ stopped: true });
  expect(await highlightHostCount(tab.page)).toBe(0);
});
