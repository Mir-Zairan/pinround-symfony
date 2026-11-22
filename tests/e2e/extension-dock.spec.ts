import type { BrowserContext, Page } from '@playwright/test';
import {
  CAPTURE_HOST_PERMISSIONS,
  PageInspector,
  TEST_PAGE_ORIGIN,
  TEST_PAGE_URL,
  answerPinroundApi,
  buttonNamesOf,
  clickOverlayButton,
  expect,
  pairedPopupFor,
  test,
  type ExtensionPageGlobals,
  type PinroundStub,
  type StubScenario,
} from './extension';

// The floating dock (P14.T19): the popup only opens it, and the tester pins, removes a pin, captures, plays a scenario
// and saves the report draft from the dock on the page. The dock lives in the overlay's closed shadow root, which
// Playwright's locators do not enter, so the specs read it through PageInspector and press it with the trusted mouse
// and keyboard, as extension-overlay.spec.ts does.

const OVERLAY = { role: 'application', name: 'Pin overlay' };
const DOCK_OPENED = 'The dock is on the page. Pin, capture and save your draft from there.';
const NOTEBOOK_ID = 'b0000000-0000-7000-8000-000000000351';
const BASKET_SCENARIO: StubScenario = {
  title: 'Pay for the basket',
  why: 'Mia checks that paying shows the thank-you line.',
  setup: '',
  start_url: TEST_PAGE_URL,
  steps: [
    { type: 'click', say: 'Press Pay.', target: { role: 'button', name: 'Pay 12.40 EUR' } },
    { type: 'expect_text', say: 'Read the line under the form.', text: 'Thank you. Your order is on its way.' },
  ],
};

// Every spec here captures, or opens the dock on the test page as a capture would.
test.use({ extensionHostPermissions: CAPTURE_HOST_PERMISSIONS });

async function pinNamesOf(inspector: PageInspector): Promise<string[]> {
  return (await buttonNamesOf(inspector)).filter((name) => /^Pin \d+(:|$)/.test(name));
}

/** The texts of the statuses the overlay shows; an empty status is not rendered, so it is not listed. */
async function shownStatusesOf(inspector: PageInspector): Promise<string[]> {
  return (await inspector.accessibleNodes()).filter((node) => node.role === 'status' && node.text !== '').map((node) => node.text);
}

/** The text of the dock's step card, or null while no card shows. */
async function stepCardTextOf(inspector: PageInspector): Promise<string | null> {
  const cards = (await inspector.accessibleNodes()).filter((node) => node.role === 'region' && node.name === 'Scenario step');
  return cards.length === 1 ? cards[0].text : null;
}

/** A person's click on the pinning surface, the note typed into the editor it opens, then Enter to keep it. */
async function dropPin(page: Page, inspector: PageInspector, [x, y]: [number, number], note: string, pinNumber: number): Promise<void> {
  await page.mouse.click(x, y);
  await expect.poll(() => inspector.focused()).toEqual({ role: 'textbox', name: `Note for pin ${pinNumber}` });
  await page.keyboard.type(note);
  await page.keyboard.press('Enter');
}

/** Opens the dock from the paired popup, then closes the popup, as Chrome does once the person clicks on the page. */
async function openDockAndClosePopup(context: BrowserContext, testPage: Page, popup: Page): Promise<PageInspector> {
  await popup.getByRole('button', { name: 'Open the dock on this page' }).click();
  await expect(popup.getByRole('status')).toHaveText(DOCK_OPENED);
  await popup.close();
  await testPage.bringToFront();
  const inspector = await PageInspector.of(context, testPage);
  await expect.poll(async () => (await inspector.accessibleNodes()).filter((node) => node.role === 'toolbar' && node.name === 'pinround dock').length).toBe(1);
  return inspector;
}

const extensionPagesOf = (context: BrowserContext) => context.pages().filter((page) => page.url().startsWith('chrome-extension://'));

/** The paired popup with the notebook holding BASKET_SCENARIO chosen, and the test page's host allowed to play. */
async function popupWithBasketNotebook(context: BrowserContext, testPage: Page): Promise<{ stub: PinroundStub; popup: Page }> {
  const stub = await answerPinroundApi(context, { answersDock: true, notebooks: [{ notebook_id: NOTEBOOK_ID, title: 'Basket checks', last_used_at: '2026-03-02T09:00:00+00:00', scenarios: [BASKET_SCENARIO] }] });
  const popup = await pairedPopupFor(context, testPage);
  await popup.evaluate(async (hosts) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    await chrome.storage.local.set({ pinroundAllowedHosts: hosts });
  }, [TEST_PAGE_ORIGIN]);
  await popup.reload();
  await popup.getByLabel('Put the screenshot in').selectOption({ label: 'Notebook: Basket checks' });
  await expect(popup.locator('#scenario-position')).toHaveText('Scenario 1 of 1');
  return { stub, popup };
}

test('a tester pins, removes a pin, captures twice and saves one report draft with the title typed in the dock, without opening the popup again', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context, { answersDock: true });
  const popup = await pairedPopupFor(context, testPage);
  await popup.getByLabel('Put the screenshot in').selectOption({ label: 'A new report' });
  await popup.getByLabel('Project key').fill('SHOP');
  await popup.getByLabel('Project key').press('Tab');
  await expect.poll(() => popup.evaluate(async () => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    const kept = (await chrome.storage.session.get('pinroundNewReport')).pinroundNewReport as { project?: string } | undefined;
    return kept?.project;
  })).toBe('SHOP');
  const inspector = await openDockAndClosePopup(context, testPage, popup);
  const pagesBeforeSave = context.pages().length;
  expect((await buttonNamesOf(inspector)).filter((name) => name.startsWith('Clear'))).toEqual([]);

  await testPage.keyboard.press('p');
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  await dropPin(testPage, inspector, [320, 180], 'Price is wrong', 1);
  // Above the dock's bar, which crosses the middle of the page at its right half.
  await dropPin(testPage, inspector, [640, 180], 'Logo is cut off', 2);
  expect(await pinNamesOf(inspector)).toEqual(['Pin 1: Price is wrong', 'Pin 2: Logo is cut off']);
  expect(await buttonNamesOf(inspector)).toContain('Clear 2 pins');
  // A pin's Remove is in its note form, which a click on the pin opens.
  expect((await buttonNamesOf(inspector)).filter((name) => name.startsWith('Remove pin'))).toEqual([]);

  await clickOverlayButton(inspector, testPage, 'Pin 1: Price is wrong');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'textbox', name: 'Note for pin 1' });
  await clickOverlayButton(inspector, testPage, 'Remove pin 1');

  await expect.poll(() => pinNamesOf(inspector)).toEqual(['Pin 1: Logo is cut off']);
  expect(await buttonNamesOf(inspector)).toContain('Clear 1 pin');
  expect((await buttonNamesOf(inspector)).filter((name) => name.startsWith('Remove pin'))).toEqual([]);

  await clickOverlayButton(inspector, testPage, 'Capture');
  await expect.poll(() => shownStatusesOf(inspector)).toEqual(['Captured. 1 waiting.']);
  expect(await pinNamesOf(inspector)).toEqual([]);
  expect((await buttonNamesOf(inspector)).filter((name) => name.startsWith('Clear'))).toEqual([]);

  // C is the dock's key wherever focus is but in the page's own fields.
  await testPage.keyboard.press('c');
  await expect.poll(() => shownStatusesOf(inspector)).toEqual(['Captured. 2 waiting.']);
  // The captures wait in the extension until Save draft: pinround has no report draft yet.
  expect(stub.myReportDrafts()).toEqual([]);

  await clickOverlayButton(inspector, testPage, 'Save draft, 2 in it');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'textbox', name: 'What is wrong, in one line' });
  await testPage.keyboard.type('Basket total is wrong');
  await clickOverlayButton(inspector, testPage, 'Save draft');

  await expect.poll(() => shownStatusesOf(inspector)).toEqual(['Draft saved with 2 captures. Open it in pinround to send it.']);
  const [draft, ...otherDrafts] = stub.myReportDrafts();
  expect(otherDrafts).toEqual([]);
  expect([draft.project, draft.title]).toEqual(['SHOP', 'Basket total is wrong']);
  expect(draft.screenshots.map((screenshot) => screenshot.pins.map((pin) => pin.note))).toEqual([['Logo is cut off'], []]);
  const [keptPin] = draft.screenshots[0].pins;
  expect([keptPin.x_pct, keptPin.y_pct]).toEqual([expect.closeTo(50, 0), expect.closeTo(25, 0)]);
  expect(stub.storedPictures.length).toBe(2);
  expect(stub.requests.filter((request) => request.method === 'POST' && request.path === '/api/v1/reports').length).toBe(1);
  expect(context.pages().length).toBe(pagesBeforeSave);
  expect(extensionPagesOf(context)).toEqual([]);
});

test('the note form of a pin dropped at the left or right edge of the page stays fully on screen', async ({ context, testPage }) => {
  await answerPinroundApi(context, { answersDock: true });
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await openDockAndClosePopup(context, testPage, popup);
  const viewport = testPage.viewportSize();
  if (viewport === null) {
    throw new Error('The test page has no viewport size.');
  }
  const expectOnScreen = async (role: string, name: string) => {
    const { backendNodeId } = await inspector.node(role, name);
    const boxes = backendNodeId === undefined ? null : await inspector.boxesOf(backendNodeId);
    expect(boxes, name).not.toBeNull();
    expect(boxes?.border.left, name).toBeGreaterThanOrEqual(0);
    expect(boxes?.border.right, name).toBeLessThanOrEqual(viewport.width);
  };

  await testPage.keyboard.press('p');
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  // Above the dock, which sits halfway down the right edge.
  const pinSpots: Array<[number, number, number]> = [[1, 0, 300], [2, viewport.width - 1, 100]];
  for (const [pinNumber, x, y] of pinSpots) {
    await testPage.mouse.click(x, y);
    await expect.poll(() => inspector.focused()).toEqual({ role: 'textbox', name: `Note for pin ${pinNumber}` });
    await expectOnScreen('textbox', `Note for pin ${pinNumber}`);
    await expectOnScreen('button', 'Save');
    await expectOnScreen('button', `Remove pin ${pinNumber}`);
    await testPage.keyboard.type(1 === pinNumber ? 'Menu is cut off' : 'Basket is cut off');
    await testPage.keyboard.press('Enter');
  }

  await clickOverlayButton(inspector, testPage, 'Pin 1: Menu is cut off');
  await clickOverlayButton(inspector, testPage, 'Remove pin 1');
  await expect.poll(() => pinNamesOf(inspector)).toEqual(['Pin 1: Basket is cut off']);
});

test('on a 360px wide phone the dock wraps inside the screen, and its buttons keep their names where their words are hidden', async ({ context, testPage }) => {
  await answerPinroundApi(context, { answersDock: true });
  await testPage.setViewportSize({ width: 360, height: 640 });
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await openDockAndClosePopup(context, testPage, popup);
  const controls: Array<[string, string]> = [['button', 'Move the dock'], ['combobox', 'Save to'], ['button', 'Pin'], ['button', 'Capture'], ['button', 'Play'], ['button', 'Save draft'], ['button', 'Letter keys'], ['button', 'Shrink the dock']];

  for (const [role, name] of controls) {
    const { backendNodeId } = await inspector.node(role, name);
    const boxes = backendNodeId === undefined ? null : await inspector.boxesOf(backendNodeId);
    expect(boxes, name).not.toBeNull();
    expect(boxes?.border.left, name).toBeGreaterThanOrEqual(0);
    expect(boxes?.border.right, name).toBeLessThanOrEqual(360);
  }
});

test('with Letter keys off, P on the page does nothing while P in the dock still pins, and turned on again P on the page pins', async ({ context, testPage }) => {
  await answerPinroundApi(context, { answersDock: true });
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await openDockAndClosePopup(context, testPage, popup);
  const overlaysShown = async () => (await inspector.accessibleNodes()).filter((node) => node.role === OVERLAY.role && node.name === OVERLAY.name).length;

  await clickOverlayButton(inspector, testPage, 'Letter keys');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Letter keys' });
  await testPage.keyboard.press('p');
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  await testPage.keyboard.press('Escape');
  await expect.poll(overlaysShown).toBe(0);

  await testPage.mouse.click(40, 300);
  await testPage.keyboard.press('p');
  expect(await overlaysShown()).toBe(0);

  await clickOverlayButton(inspector, testPage, 'Letter keys');
  await testPage.mouse.click(40, 300);
  await testPage.keyboard.press('p');
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
});

test('a scenario started with Play keeps its step card after a click on the page, and goes on from the dock', async ({ context, testPage }) => {
  const { stub, popup } = await popupWithBasketNotebook(context, testPage);
  const inspector = await openDockAndClosePopup(context, testPage, popup);
  const notebookReads = () => stub.requests.filter((request) => request.method === 'GET' && request.path === `/api/v1/notebooks/${NOTEBOOK_ID}`).length;
  // Choosing the notebook in the popup already read it once; Play reads it once more.
  const readsBeforePlay = notebookReads();

  await clickOverlayButton(inspector, testPage, 'Play');
  await expect.poll(() => stepCardTextOf(inspector)).toContain('Step 1 of 2');
  expect(await stepCardTextOf(inspector)).toContain('Press Pay.');

  await testPage.getByRole('button', { name: 'Pay 12.40 EUR' }).click();

  await expect(testPage.locator('#paid')).toBeVisible();
  expect(await stepCardTextOf(inspector)).toContain('Step 1 of 2');
  expect(await stepCardTextOf(inspector)).toContain('Press Pay.');
  expect(await buttonNamesOf(inspector)).toEqual(expect.arrayContaining(['Looks right', 'Found a problem', 'Skip', 'Stop']));

  await clickOverlayButton(inspector, testPage, 'Looks right');
  await expect.poll(() => stepCardTextOf(inspector)).toContain('Step 2 of 2');
  expect(await stepCardTextOf(inspector)).toContain('Read the line under the form.');
  expect(await stepCardTextOf(inspector)).toContain('The page shows "Thank you. Your order is on its way."');

  await testPage.mouse.click(40, 300);
  await clickOverlayButton(inspector, testPage, 'Looks right');
  await expect.poll(() => stepCardTextOf(inspector)).toContain('Scenario done: 2 looked right, 0 with a problem, 0 skipped.');
  expect(notebookReads()).toBe(readsBeforePlay + 1);
  expect(extensionPagesOf(context)).toEqual([]);
});

test('the page finds no dock, step card, pin or note through its DOM, and a click on them shows it only the overlay\'s host', async ({ context, testPage }) => {
  const { popup } = await popupWithBasketNotebook(context, testPage);
  const inspector = await openDockAndClosePopup(context, testPage, popup);
  await clickOverlayButton(inspector, testPage, 'Play');
  await expect.poll(() => stepCardTextOf(inspector)).toContain('Press Pay.');
  await testPage.keyboard.press('p');
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  await dropPin(testPage, inspector, [320, 180], 'Logo is cut off', 1);
  await testPage.evaluate(() => {
    const heard: Array<[string, string[]]> = [];
    (window as unknown as { pageHeard: typeof heard }).pageHeard = heard;
    const nameOf = (node: EventTarget) => (node instanceof Element ? node.localName : node === document ? '#document' : node === window ? 'window' : 'other');
    window.addEventListener('click', (event) => heard.push([nameOf(event.target as EventTarget), event.composedPath().map(nameOf)]), { capture: true });
  });

  const pageSees = await testPage.evaluate(() => {
    const classNames = ['dock', 'dock__bar', 'dock-handle', 'dock__clear', 'dock__status', 'dock-save', 'dock-step', 'dock-step__do', 'surface', 'pin', 'pin__note', 'note-editor', 'note-field', 'note-editor__remove'];
    const host = document.querySelector('pinround-overlay');
    return {
      bySelector: classNames.filter((className) => document.querySelector(`.${className}`) !== null),
      byClassName: classNames.filter((className) => document.getElementsByClassName(className).length > 0),
      textareas: document.getElementsByTagName('textarea').length,
      shadowRoot: host?.shadowRoot ?? null,
      hostChildren: host?.children.length,
      hasNote: document.documentElement.textContent?.includes('Logo is cut off'),
      hasStep: document.documentElement.textContent?.includes('Press Pay.'),
    };
  });
  expect(pageSees).toEqual({ bySelector: [], byClassName: [], textareas: 0, shadowRoot: null, hostChildren: 0, hasNote: false, hasStep: false });

  await clickOverlayButton(inspector, testPage, 'Pin 1: Logo is cut off');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'textbox', name: 'Note for pin 1' });
  await testPage.keyboard.press('Enter');
  await clickOverlayButton(inspector, testPage, 'Skip');
  await expect.poll(() => stepCardTextOf(inspector)).toContain('Step 2 of 2');

  const seenByPage = ['pinround-overlay', ['pinround-overlay', 'html', '#document', 'window']];
  await expect.poll(() => testPage.evaluate(() => (window as unknown as { pageHeard: Array<[string, string[]]> }).pageHeard)).toEqual([seenByPage, seenByPage]);
});
