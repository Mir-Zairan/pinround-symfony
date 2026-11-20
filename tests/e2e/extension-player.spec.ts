import type { BrowserContext, Locator, Page } from '@playwright/test';
import path from 'node:path';
import { PageInspector, TEST_PAGE_ORIGIN, expect, openPopupFor, tabIdOf, test, type ExtensionPageGlobals } from './extension';

// The scenario player's page side (extension/src/player/page-player.js) in real Chromium: each step runs as the
// extension runs it, through step-runner.js in an extension page, which injects the player into the tab's isolated
// world and checks its answer. The highlight's shadow root is closed, so PageInspector reads it.

const PLAYER_PAGE_FILES_BY_PATH: Record<string, string> = {
  '/player': path.join(__dirname, 'fixtures', 'extension', 'player-page.html'),
  '/player-2': path.join(__dirname, 'fixtures', 'extension', 'player-page-2.html'),
};
const PLAYER_PAGE_URL = `${TEST_PAGE_ORIGIN}/player`;
const RECEIPT_PAGE_URL = `${TEST_PAGE_ORIGIN}/player-2`;
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

/** The player page in `testPage`, answered with its receipt page on the test page's origin, and an extension page to run steps from. */
async function openPlayerPage(context: BrowserContext, testPage: Page): Promise<PlayerTab> {
  await context.route(
    (url) => Object.hasOwn(PLAYER_PAGE_FILES_BY_PATH, url.pathname),
    (route) => route.fulfill({ path: PLAYER_PAGE_FILES_BY_PATH[new URL(route.request().url()).pathname], contentType: 'text/html' }),
  );
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
    const runStep = stepRunnerFor(browser, { pageRefusalOf: () => null, tabId: ${JSON.stringify(tab.tabId)}, openUrl: refuse, captureToNotebook: refuse });
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
 * Runs wait_for in the isolated world with a wait that resolves at once and logs each pause, so no real time passes.
 * With `checkStockAtWait`, that wait presses the page's "Check stock" button, whose own script then shows the stock
 * line: the element appears while the step waits. Answers the step's answer and the pauses in ms.
 */
async function waitForWithCountedWaits(
  tab: PlayerTab,
  step: Step,
  options: { timeoutMs: number; pollMs?: number; checkStockAtWait: number | null },
): Promise<{ answer: StepAnswer; waits: number[] }> {
  return tab.extensionPage.evaluate(async ({ tabId, files, waitStep, timeoutMs, pollMs, checkStockAtWait }) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    await chrome.scripting.executeScript({ target: { tabId }, files });
    const [injection] = await chrome.scripting.executeScript({
      target: { tabId },
      args: [waitStep, timeoutMs, pollMs, checkStockAtWait],
      func: async (pageStep: Step, timeout: number, poll: number | null, pressAt: number | null) => {
        const waits: number[] = [];
        const player = (globalThis as unknown as { pinroundPagePlayer: { runStep(step: Step, options: object): Promise<StepAnswer> } }).pinroundPagePlayer;
        const answer = await player.runStep(pageStep, {
          timeoutMs: timeout,
          ...(null === poll ? {} : { pollMs: poll }),
          wait: async (ms: number) => {
            waits.push(ms);
            if (waits.length === pressAt) {
              (document.getElementById('check-stock') as HTMLButtonElement).click();
            }
          },
        });
        return { answer, waits };
      },
    });
    return injection.result as { answer: StepAnswer; waits: number[] };
  }, { tabId: tab.tabId, files: PAGE_PLAYER_FILES, waitStep: step, timeoutMs: options.timeoutMs, pollMs: options.pollMs ?? null, checkStockAtWait: options.checkStockAtWait });
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

/**
 * Runs a capture step as the extension does, with a captureToNotebook that answers how many highlight hosts the page
 * held when it was called.
 */
async function highlightHostsAtCapture(tab: PlayerTab): Promise<{ answer: StepAnswer; hostsAtCapture: number[] }> {
  return tab.extensionPage.evaluate(`(async () => {
    const { browser } = await import('/src/shared/browser.js');
    const { stepRunnerFor } = await import('/src/player/step-runner.js');
    const tabId = ${JSON.stringify(tab.tabId)};
    const hostsAtCapture = [];
    const captureToNotebook = async () => {
      const [injection] = await browser.scripting.executeScript({ target: { tabId }, args: [${JSON.stringify(HIGHLIGHT_TAG)}], func: (tag) => document.getElementsByTagName(tag).length });
      hostsAtCapture.push(injection.result);
    };
    const refuse = async () => { throw new Error('This spec opens no page.'); };
    const answer = await stepRunnerFor(browser, { pageRefusalOf: () => null, tabId, openUrl: refuse, captureToNotebook })({ type: 'capture', say: 'Keep a picture.' });
    return { answer, hostsAtCapture };
  })()`);
}

interface SeenTab {
  id?: number;
  status?: string;
  url?: string;
  pendingUrl?: string;
}

/** The tab as browser.tabs.get answers it in the extension page. */
async function seenTab(tab: PlayerTab): Promise<SeenTab> {
  return tab.extensionPage.evaluate(`(async () => {
    const { browser } = await import('/src/shared/browser.js');
    const { id, status, url, pendingUrl } = await browser.tabs.get(${JSON.stringify(tab.tabId)});
    return { id, status, url, pendingUrl };
  })()`);
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
  const timedOut = await waitForWithCountedWaits(tab, waitForStock, { timeoutMs: 0, checkStockAtWait: 1 });
  expect(timedOut).toEqual({ answer: { stopped: true, message: 'Waited 0 seconds. Found no visible element with role status.' }, waits: [] });
  await expect(tab.page.locator('#stock')).toBeHidden();

  const neverShown = await waitForWithCountedWaits(tab, waitForStock, { timeoutMs: 300, pollMs: 100, checkStockAtWait: null });
  expect(neverShown).toEqual({ answer: { stopped: true, message: 'Waited 0.3 seconds. Found no visible element with role status.' }, waits: [100, 100, 100] });
  await expect(tab.page.locator('#stock')).toBeHidden();

  const waited = await waitForWithCountedWaits(tab, waitForStock, { timeoutMs: 10_000, checkStockAtWait: 1 });

  expect(waited).toEqual({ answer: { ok: true }, waits: [100] });
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

test('a capture step clears the highlight of the step before, so the ring is not in the screenshot', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);
  expect(await playStep(tab, { type: 'click', say: 'Check the stock.', target: { role: 'button', name: 'Check stock' } })).toEqual({ ok: true });
  expect(await highlightHostCount(tab.page)).toBe(1);

  expect(await highlightHostsAtCapture(tab)).toEqual({ answer: { ok: true }, hostsAtCapture: [0] });
});

test('expect_text never reads a password field: a right and a wrong guess get the same refusal, and the value is in neither', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);
  const refusal = { stopped: true, message: 'The player never reads a password field. Look at it yourself, then play on.' };
  await tab.page.locator('#password').fill('jam-jar-2026');
  await tab.page.locator('#pin').fill('4711');

  const answers = [
    await playStep(tab, { type: 'expect_text', say: 'The password is right.', target: { label: 'Password' }, text: 'jam-jar-2026' }),
    await playStep(tab, { type: 'expect_text', say: 'The password is a guess.', target: { label: 'Password' }, text: 'jam-jar-2025' }),
    await playStep(tab, { type: 'expect_text', say: 'The PIN is right.', target: { label: 'New PIN' }, text: '4711' }),
    await playStep(tab, { type: 'expect_text', say: 'The PIN is a guess.', target: { label: 'New PIN' }, text: '1234' }),
  ];

  expect(answers).toEqual([refusal, refusal, refusal, refusal]);
  for (const value of ['jam-jar-2026', '4711']) {
    expect(JSON.stringify(answers)).not.toContain(value);
  }
  await expect(tab.page.locator('#password')).toHaveValue('jam-jar-2026');
  // The twin: the same label lookup reads a field that is no password field.
  expect(await playStep(tab, { type: 'expect_text', say: 'The order number.', target: { label: 'Order number' }, text: 'SHOP-7' })).toEqual({ ok: true });
});

test('select refuses a list that takes more than one choice, and changes nothing', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);

  expect(await playStep(tab, { type: 'select', say: 'Add an ice pack.', target: { label: 'Extras' }, option: 'Ice pack' })).toEqual({
    stopped: true,
    message: 'The list labelled "Extras" takes more than one choice, and a step chooses one, so nothing was chosen.',
  });

  expect(await tab.page.locator('#extras option:checked').allTextContents()).toEqual(['Napkins']);
  expect(await fieldEventsOf(tab.page)).toEqual([]);
});

test('select refuses two options with the same text and a disabled option; click refuses an aria-disabled button; nothing changes', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);

  expect(await playStep(tab, { type: 'select', say: 'Choose large.', target: { label: 'Box size' }, option: 'Large' })).toEqual({
    stopped: true,
    message: 'Found 2 options "Large" in the list; a step needs exactly one.',
  });
  expect(await playStep(tab, { type: 'select', say: 'Choose medium.', target: { label: 'Box size' }, option: 'Medium' })).toEqual({
    stopped: true,
    message: 'The option "Medium" cannot be chosen.',
  });
  expect(await playStep(tab, { type: 'click', say: 'Pay by card.', target: { role: 'button', name: 'Pay by card' } })).toEqual({
    stopped: true,
    message: 'The element with role button named "Pay by card" is disabled, so it was not clicked.',
  });

  await expect(tab.page.locator('#box-size')).toHaveValue('Small');
  expect(await fieldEventsOf(tab.page)).toEqual([]);
  await expect(tab.page.locator('#thanks')).toBeHidden();
  // The twin: the same list chooses an option it holds once.
  expect(await playStep(tab, { type: 'select', say: 'Choose small.', target: { label: 'Box size' }, option: 'Small' })).toEqual({ ok: true });
  expect(await fieldEventsOf(tab.page)).toEqual(['input box-size', 'change box-size']);
});

test('after a click follows a link, the next wait_for and expect_text run on the new page once it has loaded', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);

  expect(await playStep(tab, { type: 'click', say: 'See the receipt.', target: { role: 'link', name: 'See the receipt' } })).toEqual({ ok: true });
  expect(await playStep(tab, { type: 'wait_for', say: 'Wait for the receipt.', target: { text: 'Order sent' } })).toEqual({ ok: true });
  expect(await playStep(tab, { type: 'expect_text', say: 'Omar packs it.', target: { role: 'status' }, text: 'Omar packs it today.' })).toEqual({ ok: true });

  await expect(tab.page).toHaveURL(RECEIPT_PAGE_URL);
  await expectHighlightAround(tab, tab.page.locator('#sent'));
});

test('after a click submits a form, the next expect_text and wait_for run on the new page once it has loaded', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);

  expect(await playStep(tab, { type: 'click', say: 'Send the order.', target: { role: 'button', name: 'Send the order' } })).toEqual({ ok: true });
  expect(await playStep(tab, { type: 'expect_text', say: 'The order is sent.', text: 'Order sent.' })).toEqual({ ok: true });
  expect(await playStep(tab, { type: 'wait_for', say: 'Wait for the receipt.', target: { role: 'heading', name: 'Your receipt' } })).toEqual({ ok: true });

  await expect(tab.page).toHaveURL(`${RECEIPT_PAGE_URL}?reference=SHOP-8`);
});

test('tabs.get, tabs.update and a func cut off by a navigation behave in Chrome as extension/tests/fake-browser.mjs models them', async ({ context, testPage }) => {
  const tab = await openPlayerPage(context, testPage);
  expect(await seenTab(tab)).toEqual({ id: tab.tabId, status: 'complete', url: PLAYER_PAGE_URL });

  const seen = await tab.extensionPage.evaluate(`(async () => {
    const { browser } = await import('/src/shared/browser.js');
    const tabId = ${JSON.stringify(tab.tabId)};
    const refusalOf = async (call) => { try { await call(); return 'no refusal'; } catch (error) { return error.message; } };
    const thrownBy = (call) => { try { call(); return 'did not throw'; } catch (error) { return [error.name, error.message]; } };
    const [before] = await browser.scripting.executeScript({ target: { tabId }, func: () => true });
    const pending = browser.scripting.executeScript({ target: { tabId }, func: () => new Promise(() => undefined) });
    await browser.scripting.executeScript({ target: { tabId }, func: () => true });
    const { id, status, url, pendingUrl } = await browser.tabs.update(tabId, { url: ${JSON.stringify(RECEIPT_PAGE_URL)} });
    const [left] = await pending;
    return {
      updated: { id, status, url, pendingUrl },
      left: { sameDocument: left.documentId === before.documentId, frameId: left.frameId, result: left.result },
      missing: await refusalOf(() => browser.tabs.get(tabId + 100000)),
      getWithText: thrownBy(() => browser.tabs.get(String(tabId))),
      updateWithText: thrownBy(() => browser.tabs.update(String(tabId), { url: ${JSON.stringify(RECEIPT_PAGE_URL)} })),
    };
  })()`);

  expect(seen).toEqual({
    updated: { id: tab.tabId, status: 'loading', url: PLAYER_PAGE_URL, pendingUrl: RECEIPT_PAGE_URL },
    left: { sameDocument: true, frameId: 0, result: null },
    missing: `No tab with id: ${tab.tabId + 100000}.`,
    getWithText: ['TypeError', 'Error in invocation of tabs.get(integer tabId, optional function callback): No matching signature.'],
    updateWithText: ['TypeError', 'Error in invocation of tabs.update(optional integer tabId, object updateProperties, optional function callback): No matching signature.'],
  });
  await expect.poll(() => seenTab(tab)).toEqual({ id: tab.tabId, status: 'complete', url: RECEIPT_PAGE_URL });
});
