import type { BrowserContext, Page } from '@playwright/test';
import path from 'node:path';
import {
  CAPTURE_HOST_PERMISSIONS,
  PINROUND_TEST_ORIGIN,
  TEST_PAGE_ORIGIN,
  answerPinroundApi,
  expect,
  pairedPopupFor,
  test,
  type ExtensionPageGlobals,
  type PinroundStub,
  type StubScenario,
  type StubScenarioStep,
} from './extension';

// The scenario player (P13.T8) through the real popup: a notebook chosen as the target shows its scenarios, which
// GET /api/v1/notebooks/{id} of the stub answers wrapped as pinround wraps them, and Play, Step, Next and Back run them
// on the test page with extension/src/player/. The allowed hosts are put in storage as the options page stores them.

const PLAYER_PAGE_FILE = path.join(__dirname, 'fixtures', 'extension', 'player-page.html');
const PLAYER_PAGE_URL = `${TEST_PAGE_ORIGIN}/player`;
/** pinround's host on another port: no pinround, as a site is its scheme, host and port. */
const OTHER_PORT_ORIGIN = 'https://pinround.acme-foods.test:8443';
const NOTEBOOK_ID = 'nb000000-0000-4000-8000-000000000301';
const TARGET_PICKER = 'Put the screenshot in';
const TEST_PAGE_HOST = 'http://shop.acme-foods.test';
const SHOP_HOST_QUESTION = 'This scenario clicks and types on shop.acme-foods.test. It can change data there.';
const RAN_TO_ITS_END = 'The scenario ran to its end.';

test.use({ extensionHostPermissions: CAPTURE_HOST_PERMISSIONS });

const scenarioOn = (startUrl: string, title: string, steps: StubScenarioStep[]): StubScenario => ({ title, why: 'The basket must keep what Lena put in it.', setup: '', start_url: startUrl, steps });
const scenario = (title: string, steps: StubScenarioStep[]) => scenarioOn(PLAYER_PAGE_URL, title, steps);
const pressCheckStock: StubScenarioStep = { type: 'click', say: 'Press Check stock.', target: { role: 'button', name: 'Check stock' } };
const pressPay: StubScenarioStep = { type: 'click', say: 'Press Pay.', target: { role: 'button', name: 'Pay 12.40 EUR' } };

interface OpenedPlayer {
  stub: PinroundStub;
  popup: Page;
}

/**
 * Pairs the popup opened on the player page at `pageUrl`, allows `allowedHosts` as the options page stores them, and
 * chooses the notebook holding `scenarios`, whose list is then shown.
 */
async function openNotebookPlayer(context: BrowserContext, testPage: Page, scenarios: StubScenario[], { allowedHosts = [TEST_PAGE_HOST], pageUrl = PLAYER_PAGE_URL } = {}): Promise<OpenedPlayer> {
  const stub = await answerPinroundApi(context, { notebooks: [{ notebook_id: NOTEBOOK_ID, title: 'Basket checks', last_used_at: '2026-03-02T09:00:00+00:00', scenarios }] });
  await context.route(
    (url) => url.pathname === '/player' && [TEST_PAGE_ORIGIN, OTHER_PORT_ORIGIN].includes(url.origin),
    (route) => route.fulfill({ path: PLAYER_PAGE_FILE, contentType: 'text/html' }),
  );
  await testPage.goto(pageUrl);
  const popup = await pairedPopupFor(context, testPage);
  await popup.evaluate(async (hosts) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    await chrome.storage.local.set({ pinroundAllowedHosts: hosts });
  }, allowedHosts);
  await popup.reload();
  await popup.getByLabel(TARGET_PICKER).selectOption({ label: 'Notebook: Basket checks' });
  await expect(popup.locator('#scenario-position')).toHaveText(`Scenario 1 of ${scenarios.length}`);
  return { stub, popup };
}

const playButton = (popup: Page) => popup.getByRole('button', { name: 'Play', exact: true });
const hostQuestion = (popup: Page) => popup.getByRole('group', { name: /^This scenario clicks and types on / });

/** Presses Play and answers the host question it asks with "Play anyway". */
async function playAnyway(popup: Page, question = SHOP_HOST_QUESTION): Promise<void> {
  await playButton(popup).click();
  await expect(hostQuestion(popup)).toHaveAccessibleName(question);
  await popup.getByRole('button', { name: 'Play anyway' }).click();
}

interface PlayerPageState {
  fieldEvents: string[];
  isStockShown: boolean;
  isThanksShown: boolean;
  coupon: string;
  password: string;
}

const UNTOUCHED_PAGE: PlayerPageState = { fieldEvents: [], isStockShown: false, isThanksShown: false, coupon: '', password: '' };

async function pageStateOf(page: Page): Promise<PlayerPageState> {
  return page.evaluate(() => ({
    fieldEvents: (window as unknown as { fieldEvents: string[] }).fieldEvents,
    isStockShown: !(document.getElementById('stock') as HTMLElement).hidden,
    isThanksShown: !(document.getElementById('thanks') as HTMLElement).hidden,
    coupon: (document.getElementById('coupon') as HTMLInputElement).value,
    password: (document.getElementById('password') as HTMLInputElement).value,
  }));
}

/** No step ran: the page is as it loaded, and pinround got no capture, only the reads of the notebook. */
async function expectNothingRan(page: Page, stub: PinroundStub): Promise<void> {
  expect(await pageStateOf(page)).toEqual(UNTOUCHED_PAGE);
  expect(stub.requests.filter((request) => request.method !== 'GET')).toEqual([]);
  expect(stub.storedPictures).toEqual([]);
  expect(stub.notebooks.get(NOTEBOOK_ID)?.screenshots).toEqual([]);
}

/**
 * Records in the popup each text the element `textId` shows while `shownId` (it or a parent) is not hidden, in order,
 * each time it comes; read them with recordedTexts.
 */
async function recordTextsOf(popup: Page, shownId: string, textId = shownId): Promise<void> {
  await popup.evaluate(({ shown, text }) => {
    const shownElement = document.getElementById(shown) as HTMLElement;
    const textElement = document.getElementById(text) as HTMLElement;
    const texts: string[] = [];
    let lastSeen = '';
    (window as unknown as { recordedTexts: string[] }).recordedTexts = texts;
    new MutationObserver(() => {
      const seen = shownElement.hidden ? '' : textElement.textContent ?? '';
      if (seen !== '' && seen !== lastSeen) {
        texts.push(seen);
      }
      lastSeen = seen;
    }).observe(shownElement, { childList: true, characterData: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
  }, { shown: shownId, text: textId });
}

const recordedTexts = (popup: Page) => popup.evaluate(() => (window as unknown as { recordedTexts: string[] }).recordedTexts);

/** The control that has the keyboard focus, by its text (a button) or its id, and whether a person can see it. */
async function focusedControlOf(popup: Page): Promise<{ name: string; isVisible: boolean }> {
  return popup.evaluate(() => {
    const focused = document.activeElement;
    if (focused === null || focused === document.body) {
      return { name: '(nothing)', isVisible: false };
    }
    return { name: focused instanceof HTMLButtonElement ? (focused.textContent ?? '').trim() : `#${focused.id}`, isVisible: focused.checkVisibility() };
  });
}

async function expectFocusOn(popup: Page, name: string): Promise<void> {
  await expect.poll(() => focusedControlOf(popup)).toEqual({ name, isVisible: true });
}

/** Presses `key` (Tab or Shift+Tab) until `name` has the focus, checking that each control it lands on is seen. */
async function keyboardTo(popup: Page, name: string, key: 'Tab' | 'Shift+Tab' = 'Tab'): Promise<void> {
  for (let presses = 0; presses < 30; presses += 1) {
    await popup.keyboard.press(key);
    const focused = await focusedControlOf(popup);
    expect(focused.isVisible, `focus on ${focused.name}`).toBe(true);
    if (focused.name === name) {
      return;
    }
  }
  throw new Error(`${key} never reached ${name}.`);
}

test('three scenarios play on the test page, and Next and Back move between them with each one\'s steps from the start', async ({ context, testPage }) => {
  const { stub, popup } = await openNotebookPlayer(context, testPage, [
    scenario('Stock check', [pressCheckStock, { type: 'expect_text', say: 'The stock line shows 12 jars.', target: { role: 'status' }, text: 'In stock: 12 jars' }]),
    scenario('Coupon and pay', [{ type: 'type', say: 'Type the coupon.', target: { label: 'Coupon code' }, text: 'SHOP10' }, pressPay, { type: 'expect_visible', say: 'The thanks show.', target: { text: 'Thank you' } }]),
    scenario('Delivery day', [{ type: 'select', say: 'Choose Wednesday.', target: { label: 'Delivery day' }, option: 'Wednesday' }]),
  ]);
  await expect(popup.locator('#scenario-list li')).toHaveText(['Stock check', 'Coupon and pay', 'Delivery day']);
  await expect(popup.locator('#scenario-title')).toHaveText('Stock check');

  await playAnyway(popup);
  await expect(popup.getByRole('status')).toHaveText(RAN_TO_ITS_END);
  expect(await pageStateOf(testPage)).toEqual({ ...UNTOUCHED_PAGE, isStockShown: true });

  await popup.getByRole('button', { name: 'Next' }).click();
  await expect(popup.locator('#scenario-position')).toHaveText('Scenario 2 of 3');
  await expect(popup.locator('#scenario-title')).toHaveText('Coupon and pay');
  await expect(popup.locator('#scenario-list li[aria-current="step"]')).toHaveText('Coupon and pay');
  await expect(popup.locator('#player-say')).toHaveText('');
  await playButton(popup).click();
  await expect(popup.getByRole('status')).toHaveText(RAN_TO_ITS_END);
  expect(await pageStateOf(testPage)).toEqual({ ...UNTOUCHED_PAGE, isStockShown: true, isThanksShown: true, coupon: 'SHOP10', fieldEvents: ['input coupon', 'change coupon'] });
  await expect(popup.getByRole('button', { name: 'Play again' })).toBeVisible();

  await popup.getByRole('button', { name: 'Next' }).click();
  await expect(popup.locator('#scenario-title')).toHaveText('Delivery day');
  await popup.getByRole('button', { name: 'Next' }).click();
  await expect(popup.getByRole('status')).toHaveText('This is the last scenario.');
  await expect(popup.locator('#scenario-position')).toHaveText('Scenario 3 of 3');

  await popup.getByRole('button', { name: 'Back' }).click();
  await expect(popup.locator('#scenario-position')).toHaveText('Scenario 2 of 3');
  await expect(popup.locator('#scenario-title')).toHaveText('Coupon and pay');
  await expect(playButton(popup)).toBeVisible();
  await expect(popup.locator('#player-say')).toHaveText('');
  await expect(testPage.locator('#day')).toHaveValue('Monday');
  expect(stub.requests.filter((request) => request.method !== 'GET')).toEqual([]);
  expect(stub.requests.filter((request) => request.path === `/api/v1/notebooks/${NOTEBOOK_ID}`)).toHaveLength(1);
});

test('a failed expect_text stops the run, runs no later step, and the popup shows the expected and the found text', async ({ context, testPage }) => {
  const { popup } = await openNotebookPlayer(context, testPage, [
    scenario('Bread heading', [{ type: 'expect_text', say: 'The heading names the bread.', target: { role: 'heading', name: 'Strawberry jam' }, text: 'Rye bread' }, pressPay]),
  ]);

  await playAnyway(popup);

  await expect(popup.getByRole('status')).toHaveText('The page does not show the expected text.');
  await expect(popup.locator('#player-stop-message')).toHaveText('The page does not show the expected text.');
  await expect(popup.locator('#player-expected')).toHaveText('Rye bread');
  await expect(popup.locator('#player-found')).toHaveText('Strawberry jam');
  await expect(popup.locator('#player-stop-texts')).toBeVisible();
  expect(await pageStateOf(testPage)).toEqual(UNTOUCHED_PAGE);
});

test('a scenario on a host not in the allowed list is refused before anything is asked, and no step runs', async ({ context, testPage }) => {
  const { stub, popup } = await openNotebookPlayer(context, testPage, [scenarioOn('http://bakery.acme-foods.test/player', 'Bakery stock', [pressCheckStock])]);

  await playButton(popup).click();
  await expect(popup.getByRole('status')).toHaveText('bakery.acme-foods.test is not on your allowed hosts. Add it in the extension options, then play again.');
  await popup.getByRole('button', { name: 'Step' }).click();
  await expect(popup.getByRole('status')).toHaveText('bakery.acme-foods.test is not on your allowed hosts. Add it in the extension options, then play again.');

  await expect(hostQuestion(popup)).toBeHidden();
  await expectNothingRan(testPage, stub);
});

test('pinround\'s own origin is refused though the list allows it, while the same host on another port plays after the question', async ({ context, testPage }) => {
  const { stub, popup } = await openNotebookPlayer(context, testPage, [
    scenarioOn(`${PINROUND_TEST_ORIGIN}/player`, 'On pinround', [pressCheckStock]),
    scenarioOn(`${OTHER_PORT_ORIGIN}/player`, 'Other port', [pressCheckStock]),
  ], { allowedHosts: [PINROUND_TEST_ORIGIN, OTHER_PORT_ORIGIN], pageUrl: `${OTHER_PORT_ORIGIN}/player` });

  await playButton(popup).click();
  await expect(popup.getByRole('status')).toHaveText('Scenarios never play on pinround itself.');
  await expect(hostQuestion(popup)).toBeHidden();
  await expectNothingRan(testPage, stub);

  await popup.getByRole('button', { name: 'Next' }).click();
  await playAnyway(popup, 'This scenario clicks and types on pinround.acme-foods.test:8443. It can change data there.');
  await expect(popup.getByRole('status')).toHaveText(RAN_TO_ITS_END);
  expect(await pageStateOf(testPage)).toEqual({ ...UNTOUCHED_PAGE, isStockShown: true });
});

test('a type step into a password field is refused with the plain message, the field stays empty and no step after it runs', async ({ context, testPage }) => {
  const { stub, popup } = await openNotebookPlayer(context, testPage, [
    scenario('Sign in', [{ type: 'type', say: 'Type the password.', target: { label: 'Password' }, text: 'jam-jar-2026' }, pressPay]),
  ]);

  await playAnyway(popup);

  await expect(popup.getByRole('status')).toHaveText('The player never types into a password field. Type it yourself, then play on.');
  await expect(popup.locator('#player-say')).toHaveText('Type the password.');
  await expectNothingRan(testPage, stub);
});

test('without a mouse: Tab, Enter and Space reach Play, the question\'s Cancel and Play anyway, Stop, Step, Next and Back, and focus stays on a control in sight', async ({ context, testPage }) => {
  const { stub, popup } = await openNotebookPlayer(context, testPage, [
    scenario('Stock picture', [{ type: 'capture', say: 'Keep the basket.' }, pressCheckStock]),
    scenario('Pay', [pressPay]),
  ]);
  let releaseUpload = () => {};
  const uploadReleased = new Promise<void>((resolve) => {
    releaseUpload = resolve;
  });
  let isUploadHeld = false;
  await context.route(`${PINROUND_TEST_ORIGIN}/api/v1/notebooks/${NOTEBOOK_ID}/uploads`, async (route) => {
    isUploadHeld = true;
    await uploadReleased;
    await route.fallback();
  });

  await keyboardTo(popup, 'Play');
  await popup.keyboard.press('Enter');
  await expect(hostQuestion(popup)).toHaveAccessibleName(SHOP_HOST_QUESTION);
  await expectFocusOn(popup, 'Cancel');
  await popup.keyboard.press('Enter');
  await expect(popup.getByRole('status')).toHaveText('Nothing ran.');
  await expect(hostQuestion(popup)).toBeHidden();
  await expectFocusOn(popup, 'Play');
  await expectNothingRan(testPage, stub);

  await popup.keyboard.press('Space');
  await expectFocusOn(popup, 'Cancel');
  await keyboardTo(popup, 'Play anyway', 'Shift+Tab');
  await popup.keyboard.press('Space');
  await expectFocusOn(popup, 'Stop');
  await expect.poll(() => isUploadHeld, 'the capture step is under way').toBe(true);
  await popup.keyboard.press('Enter');
  releaseUpload();
  await expect(popup.getByRole('status')).toHaveText('Stopped. Play to go on from this step.');
  await expectFocusOn(popup, 'Play');
  expect(stub.notebooks.get(NOTEBOOK_ID)?.screenshots).toHaveLength(1);
  expect(await pageStateOf(testPage)).toEqual(UNTOUCHED_PAGE);

  await keyboardTo(popup, 'Step');
  await popup.keyboard.press('Enter');
  await expect(popup.getByRole('status')).toHaveText(RAN_TO_ITS_END);
  expect(await pageStateOf(testPage)).toEqual({ ...UNTOUCHED_PAGE, isStockShown: true });
  await expectFocusOn(popup, 'Step');

  await keyboardTo(popup, 'Next');
  await popup.keyboard.press('Enter');
  await expect(popup.locator('#scenario-position')).toHaveText('Scenario 2 of 2');
  await expectFocusOn(popup, 'Next');
  await keyboardTo(popup, 'Back', 'Shift+Tab');
  await popup.keyboard.press('Space');
  await expect(popup.locator('#scenario-position')).toHaveText('Scenario 1 of 2');
  await expectFocusOn(popup, 'Back');
});

test('the say line of each step is announced: the polite live region holds each say text in order', async ({ context, testPage }) => {
  const says = ['Press Check stock.', 'The stock line shows 12 jars.', 'Type the coupon SHOP10.'];
  const { popup } = await openNotebookPlayer(context, testPage, [
    scenario('Stock and coupon', [
      { ...pressCheckStock, say: says[0] },
      { type: 'expect_text', say: says[1], target: { role: 'status' }, text: 'In stock: 12 jars' },
      { type: 'type', say: says[2], target: { label: 'Coupon code' }, text: 'SHOP10' },
    ]),
  ]);
  await expect(popup.locator('#player-say')).toHaveAttribute('aria-live', 'polite');
  await recordTextsOf(popup, 'player-say');

  await playAnyway(popup);

  await expect(popup.getByRole('status')).toHaveText(RAN_TO_ITS_END);
  expect(await recordedTexts(popup)).toEqual(says);
  await expect(popup.locator('#player-say')).toHaveText(says[2]);
});

test('the host question is asked once per host, and each run\'s capture step adds one screenshot to the notebook', async ({ context, testPage }) => {
  const { stub, popup } = await openNotebookPlayer(context, testPage, [
    scenario('Stock picture', [pressCheckStock, { type: 'capture', say: 'Keep the stock line.' }]),
  ]);
  await recordTextsOf(popup, 'host-question', 'host-question-text');

  await playAnyway(popup);
  await expect(popup.getByRole('status')).toHaveText(RAN_TO_ITS_END);
  expect(stub.notebooks.get(NOTEBOOK_ID)?.screenshots.map((screenshot) => [screenshot.title, screenshot.summary])).toEqual([['Stock picture', 'Keep the stock line.']]);

  await popup.getByRole('button', { name: 'Play again' }).click();
  await expect(popup.getByRole('status')).toHaveText(RAN_TO_ITS_END);

  expect(await recordedTexts(popup)).toEqual([SHOP_HOST_QUESTION]);
  expect(stub.notebooks.get(NOTEBOOK_ID)?.screenshots).toHaveLength(2);
  expect(stub.requests.filter((request) => request.method === 'POST').map((request) => [request.path, request.answerStatus])).toEqual([
    [`/api/v1/notebooks/${NOTEBOOK_ID}/uploads`, 201],
    [`/api/v1/notebooks/${NOTEBOOK_ID}/screenshots`, 200],
    [`/api/v1/notebooks/${NOTEBOOK_ID}/uploads`, 201],
    [`/api/v1/notebooks/${NOTEBOOK_ID}/screenshots`, 200],
  ]);
});
