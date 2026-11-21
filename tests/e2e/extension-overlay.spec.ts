import type { Page } from '@playwright/test';
import { inflateSync } from 'node:zlib';
import { ALL_TOKEN_SCOPES, CAPTURE_HOST_PERMISSIONS, PageInspector, TEST_PAGE_URL, answerPinroundApi, captureVisibleTabOf, chooseNewReport, expect, openPopupFor, pairedPopupFor, resizeWindowOf, tabIdOf, test, type ExtensionPageGlobals, type PinroundStub, type ShadowElement, type StubTicket } from './extension';

// The pin overlay "Pin this page" injects (extension/src/overlay/) and the capture that sends its pins (P13.T5 step 1).
// The overlay's shadow root is closed, which Playwright's locators do not enter: the specs read it through
// PageInspector (Chrome's accessibility tree and DOM, as DevTools reads them) and drive it with the trusted
// page.mouse and page.keyboard.

const PAGE_GREEN = [0, 128, 0];
// The ring's white inner outline lies 12 to 14 CSS px from the pin's centre.
const RING_OFFSET_CSS_PX = 13;
const OVERLAY = { role: 'application', name: 'Pin overlay' };
const OVERLAY_KEYS = 'Click, or move with the arrow keys and press Enter, to drop a pin. Tab goes through the pins, and Delete removes one. Escape closes and drops the pins.';
const FIRST_REPORT_CAPTURE_SAVED = 'Report draft made with screenshot 1. Capture more pages, or press Done to open it.';
const TICKETS: StubTicket[] = [
  { key: 'SHOP-7', title: 'Basket total is wrong', step: 'Building', now: 'Omar builds it', is_your_turn: true, is_assignee: false, updated_at: '2026-03-02T09:00:00+00:00' },
  { key: 'SHOP-12', title: 'Logo is cut off on the receipt', step: 'Checking', now: 'Mia checks it', is_your_turn: true, is_assignee: true, updated_at: '2026-03-01T09:00:00+00:00' },
];

interface StoredPin {
  x_pct: number;
  y_pct: number;
  note: string;
}

interface DecodedPng {
  width: number;
  height: number;
  rgbAt(x: number, y: number): number[];
}

function paeth(left: number, up: number, upLeft: number): number {
  const estimate = left + up - upLeft;
  const [toLeft, toUp, toUpLeft] = [Math.abs(estimate - left), Math.abs(estimate - up), Math.abs(estimate - upLeft)];
  if (toLeft <= toUp && toLeft <= toUpLeft) {
    return left;
  }
  return toUp <= toUpLeft ? up : upLeft;
}

/** Every pixel of an 8-bit, non-interlaced RGB or RGBA PNG, as Chrome's captureVisibleTab writes it. */
function decodedPng(png: Buffer): DecodedPng {
  const width = png.readUInt32BE(16);
  const height = png.readUInt32BE(20);
  const [bitDepth, colorType, interlace] = [png[24], png[25], png[28]];
  if (bitDepth !== 8 || (colorType !== 2 && colorType !== 6) || interlace !== 0) {
    throw new Error(`This decoder reads 8-bit RGB or RGBA PNGs without interlace, not depth ${bitDepth}, type ${colorType}, interlace ${interlace}.`);
  }
  const channels = colorType === 6 ? 4 : 3;
  const imageData: Buffer[] = [];
  for (let offset = 8; offset < png.length; offset += 12 + png.readUInt32BE(offset)) {
    if (png.toString('ascii', offset + 4, offset + 8) === 'IDAT') {
      imageData.push(png.subarray(offset + 8, offset + 8 + png.readUInt32BE(offset)));
    }
  }
  const filtered = inflateSync(Buffer.concat(imageData));
  const stride = width * channels;
  const pixels = Buffer.alloc(stride * height);
  for (let row = 0; row < height; row += 1) {
    const filter = filtered[row * (stride + 1)];
    for (let index = 0; index < stride; index += 1) {
      const left = index >= channels ? pixels[row * stride + index - channels] : 0;
      const up = row > 0 ? pixels[(row - 1) * stride + index] : 0;
      const upLeft = row > 0 && index >= channels ? pixels[(row - 1) * stride + index - channels] : 0;
      const predictions = [0, left, up, Math.floor((left + up) / 2), paeth(left, up, upLeft)];
      pixels[row * stride + index] = (filtered[row * (stride + 1) + 1 + index] + predictions[filter]) & 0xff;
    }
  }
  return {
    width,
    height,
    rgbAt: (x, y) => [...pixels.subarray((Math.round(y) * width + Math.round(x)) * channels, (Math.round(y) * width + Math.round(x)) * channels + 3)],
  };
}

const pngOfDataUrl = (dataUrl: string) => Buffer.from(dataUrl.slice('data:image/png;base64,'.length), 'base64');

/** Where the pin lies on the picture, then the ring's white outline left and right of it, in the picture's pixels. */
function overlaySpotsAround(picture: DecodedPng, pin: StoredPin, devicePixelRatio: number): Array<[number, number]> {
  const [x, y] = [(pin.x_pct / 100) * picture.width, (pin.y_pct / 100) * picture.height];
  const ringOffset = RING_OFFSET_CSS_PX * devicePixelRatio;
  return [[x, y], [x - ringOffset, y], [x + ringOffset, y]];
}

/**
 * With the overlay in `withOverlay`, each ring's white outline is where the stored percent puts it on the picture and
 * the tint covers the centre; `captured`, the picture "Capture" sent, has the plain green page at all three spots.
 */
function expectRingsWherePinsAre(withOverlay: DecodedPng, captured: DecodedPng, pins: StoredPin[], devicePixelRatio: number): void {
  expect(pins.length).toBeGreaterThan(0);
  for (const pin of pins) {
    const [centre, ringLeft, ringRight] = overlaySpotsAround(captured, pin, devicePixelRatio);
    expect(withOverlay.rgbAt(...ringLeft).every((channel) => channel >= 200)).toBe(true);
    expect(withOverlay.rgbAt(...ringRight).every((channel) => channel >= 200)).toBe(true);
    expect(withOverlay.rgbAt(...centre)).not.toEqual(PAGE_GREEN);
    expect([captured.rgbAt(...centre), captured.rgbAt(...ringLeft), captured.rgbAt(...ringRight)]).toEqual([PAGE_GREEN, PAGE_GREEN, PAGE_GREEN]);
  }
}

function expectPinsAt(pins: StoredPin[], droppedAt: number[][]): void {
  expect(pins.length).toBe(droppedAt.length);
  pins.forEach((pin, index) => {
    expect(Math.abs(pin.x_pct - droppedAt[index][0])).toBeLessThanOrEqual(0.5);
    expect(Math.abs(pin.y_pct - droppedAt[index][1])).toBeLessThanOrEqual(0.5);
  });
}

/** The pins of each picture in the person's report drafts, as the stub saved them, in order. */
function reportPinsOf(stub: PinroundStub): StoredPin[][] {
  return stub.myReportDrafts().flatMap((draft) => draft.screenshots.map((screenshot) => screenshot.pins));
}

async function pinNamesOf(inspector: PageInspector): Promise<string[]> {
  return (await inspector.accessibleNodes()).filter((node) => node.role === 'button' && /^Pin \d+(:|$)/.test(node.name)).map((node) => node.name);
}

const overlayHasClass = (element: ShadowElement, className: string) => element.className.split(' ').includes(className);

async function overlayElementsWithClass(inspector: PageInspector, className: string): Promise<ShadowElement[]> {
  return (await inspector.shadowElementsOf('pinround-overlay')).filter((element) => overlayHasClass(element, className));
}

async function overlayElementWithClass(inspector: PageInspector, className: string): Promise<ShadowElement> {
  const [element, ...others] = await overlayElementsWithClass(inspector, className);
  if (element === undefined || others.length > 0) {
    throw new Error(`Expected one .${className} in the overlay.`);
  }
  return element;
}

async function viewportOf(page: Page): Promise<{ width: number; height: number }> {
  return page.evaluate(() => ({ width: window.innerWidth, height: window.innerHeight }));
}

/** Resolves after the page has painted twice, so a scroll the input started has reached window.scrollY. */
async function afterTwoPaints(page: Page): Promise<void> {
  await page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
}

async function paintPageGreen(page: Page): Promise<void> {
  await page.evaluate(() => {
    document.body.style.background = 'rgb(0, 128, 0)';
    for (const child of document.body.children) {
      (child as HTMLElement).style.visibility = 'hidden';
    }
  });
}

// Every spec here captures, or pins as a capture would.
test.use({ extensionHostPermissions: CAPTURE_HOST_PERMISSIONS });

test.describe('in a window the test resizes', () => {
  test.use({ viewportFollowsWindow: true });

  test('a pin dropped by click and one by keyboard land at their percent of the captured picture after a resize, with no overlay in it', async ({ context, testPage }) => {
    const stub = await answerPinroundApi(context);
    await paintPageGreen(testPage);
    await resizeWindowOf(context, testPage, { width: 1280, height: 800 });
    const popup = await pairedPopupFor(context, testPage);
    await chooseNewReport(popup);
    const { width, height } = await viewportOf(testPage);
    const inspector = await PageInspector.of(context, testPage);

    await popup.getByRole('button', { name: 'Pin this page' }).click();
    await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
    await testPage.mouse.click(width / 4, height / 4);
    await testPage.keyboard.type('Logo is cut off');
    await testPage.keyboard.press('Enter');
    await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
    for (const key of ['Shift+ArrowRight', 'Shift+ArrowRight', 'ArrowDown', 'ArrowDown', 'ArrowDown', 'ArrowDown', 'ArrowDown', 'Enter']) {
      await testPage.keyboard.press(key);
    }
    await expect.poll(() => inspector.focused()).toEqual({ role: 'textbox', name: 'Note for pin 2' });
    await testPage.keyboard.type('Price is wrong');
    await testPage.keyboard.press('Enter');
    await testPage.keyboard.press('Tab');
    await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Pin 1: Logo is cut off' });
    await testPage.keyboard.press('Tab');
    await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Pin 2: Price is wrong' });

    await resizeWindowOf(context, testPage, { width: 1000, height: 640 });
    const resized = await viewportOf(testPage);
    const withOverlay = decodedPng(pngOfDataUrl(await captureVisibleTabOf(context, testPage)));
    await popup.getByRole('button', { name: 'Capture this page' }).click();
    await expect(popup.getByRole('status')).toHaveText(FIRST_REPORT_CAPTURE_SAVED);

    const [pins] = reportPinsOf(stub);
    expect(pins.map((pin) => pin.note)).toEqual(['Logo is cut off', 'Price is wrong']);
    expectPinsAt(pins, [[25, 25], [70, 55]]);
    expect(stub.storedPictures.length).toBe(1);
    const captured = decodedPng(stub.storedPictures[0]);
    expect(resized).not.toEqual({ width, height });
    expect({ width: captured.width, height: captured.height }).toEqual(resized);
    expect({ width: withOverlay.width, height: withOverlay.height }).toEqual(resized);
    expectRingsWherePinsAre(withOverlay, captured, pins, 1);
    await expect(testPage.locator('pinround-overlay')).toHaveCount(0);
  });
});

test.describe('on a screen with a devicePixelRatio of 2', () => {
  test.use({ viewportFollowsWindow: true, screenDeviceScaleFactor: 2 });

  test('a pin lands at its percent of a picture with twice as many pixels as the viewport has CSS pixels', async ({ context, testPage }) => {
    const stub = await answerPinroundApi(context);
    await paintPageGreen(testPage);
    const popup = await pairedPopupFor(context, testPage);
    await chooseNewReport(popup);
    const viewport = await viewportOf(testPage);
    expect(await testPage.evaluate(() => window.devicePixelRatio)).toBe(2);

    await popup.getByRole('button', { name: 'Pin this page' }).click();
    await testPage.mouse.click(viewport.width * 0.3, viewport.height * 0.4);
    await testPage.keyboard.type('Logo is cut off');
    await testPage.keyboard.press('Enter');
    const withOverlay = decodedPng(pngOfDataUrl(await captureVisibleTabOf(context, testPage)));
    await popup.getByRole('button', { name: 'Capture this page' }).click();
    await expect(popup.getByRole('status')).toHaveText(FIRST_REPORT_CAPTURE_SAVED);

    const [pins] = reportPinsOf(stub);
    expectPinsAt(pins, [[30, 40]]);
    expect(stub.storedPictures.length).toBe(1);
    const captured = decodedPng(stub.storedPictures[0]);
    expect({ width: captured.width, height: captured.height }).toEqual({ width: viewport.width * 2, height: viewport.height * 2 });
    expect({ width: withOverlay.width, height: withOverlay.height }).toEqual({ width: viewport.width * 2, height: viewport.height * 2 });
    expectRingsWherePinsAre(withOverlay, captured, pins, 2);
  });
});

test('a capture into my draft round on a ticket sends the pins with the picture and closes the overlay', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context, { tickets: TICKETS });
  const popup = await pairedPopupFor(context, testPage);
  const viewport = await viewportOf(testPage);
  await expect(popup.getByLabel('Put the screenshot in').locator('option')).toHaveText(['Choose where to put it', 'A new report', 'My draft round on SHOP-7', 'My draft round on SHOP-12', 'A new notebook']);
  await popup.getByLabel('Put the screenshot in').selectOption({ label: 'My draft round on SHOP-12' });

  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(viewport.width * 0.3, viewport.height * 0.6);
  await testPage.keyboard.type('Logo is cut off');
  await testPage.keyboard.press('Enter');
  await testPage.mouse.click(viewport.width * 0.8, viewport.height * 0.2);
  await testPage.keyboard.press('Enter');
  await popup.getByRole('button', { name: 'Capture this page' }).click();

  await expect(popup.getByRole('status')).toHaveText('Added to your draft round on SHOP-12. Only you see it.');
  const posts = stub.requests.filter((request) => request.method === 'POST');
  expect(posts.map((request) => [request.path, request.answerStatus])).toEqual([['/api/v1/uploads', 201], ['/api/v1/tickets/SHOP-12/draft/screenshots', 200]]);
  expect([posts[0].body?.ticket, posts[0].body?.project, posts[0].body?.mime]).toEqual(['SHOP-12', undefined, 'image/png']);
  const draftRoundBody = posts[1].body ?? {};
  // P14.T11: the popup starts the title and summary from the page, and a capture sends them with the pins.
  expect([Object.keys(draftRoundBody).sort(), draftRoundBody.upload_id]).toEqual([['pins', 'summary', 'title', 'upload_id'], 'upload-1']);
  expect(draftRoundBody.title).toBe('Acme Foods shop');
  expect(draftRoundBody.summary).toMatch(/^Page: http:\/\/shop\.acme-foods\.test\/ · Window \d+×\d+ · \S.* \d+(\.\d+)*$/);
  const sentPins = draftRoundBody.pins as StoredPin[];
  expect(sentPins.map((pin) => pin.note)).toEqual(['Logo is cut off', '']);
  expectPinsAt(sentPins, [[30, 60], [80, 20]]);
  expect(stub.storedPictures.length).toBe(1);
  expect(stub.myReportDrafts()).toEqual([]);
  await expect(testPage.locator('pinround-overlay')).toHaveCount(0);
});

test('a capture pinround refuses leaves the pins on the page, saves nothing, and puts focus back in the overlay', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context, { scopes: ALL_TOKEN_SCOPES.filter((scope) => scope !== 'reports:write') });
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await chooseNewReport(popup);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(640, 360);
  await testPage.keyboard.type('Total is wrong');
  await testPage.keyboard.press('Enter');

  await popup.getByRole('button', { name: 'Capture this page' }).click();

  await expect(popup.getByRole('status')).toHaveText('Your pinround token cannot make reports. Make a token that may prepare report drafts on the API tokens page, then pair the extension again.');
  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
  await expect.poll(() => pinNamesOf(inspector)).toEqual(['Pin 1: Total is wrong']);
  expect(stub.myReportDrafts()).toEqual([]);
  expect(stub.storedPictures).toEqual([]);
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  await testPage.keyboard.press('Tab');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Pin 1: Total is wrong' });
});

test('pressing "Pin this page" twice keeps one overlay with its pins', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(200, 200);
  await testPage.keyboard.press('Enter');

  await popup.getByRole('button', { name: 'Pin this page' }).click();

  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  expect((await inspector.accessibleNodes()).filter((node) => node.role === OVERLAY.role && node.name === OVERLAY.name).length).toBe(1);
  expect(await pinNamesOf(inspector)).toEqual(['Pin 1']);
});

test('without a mouse: Escape leaves a note, Delete removes the focused pin and the rest renumber, Escape closes and drops the pins', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  for (const [move, note] of [['ArrowLeft', 'First'], ['ArrowUp', 'Second'], ['ArrowRight', 'Third']]) {
    await testPage.keyboard.press(`Shift+${move}`);
    await testPage.keyboard.press('Enter');
    await testPage.keyboard.type(note);
    await testPage.keyboard.press('Escape');
    await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  }
  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
  expect(await pinNamesOf(inspector)).toEqual(['Pin 1: First', 'Pin 2: Second', 'Pin 3: Third']);

  await testPage.keyboard.press('Tab');
  await testPage.keyboard.press('Tab');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Pin 2: Second' });
  await testPage.keyboard.press('Delete');

  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Pin 1: First' });
  expect(await pinNamesOf(inspector)).toEqual(['Pin 1: First', 'Pin 2: Third']);

  await testPage.keyboard.press('Escape');
  await expect(testPage.locator('pinround-overlay')).toHaveCount(0);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  expect(await pinNamesOf(inspector)).toEqual([]);
});

test('a click on a pin opens its note to change, Backspace removes a pin and the rest renumber, and each note opens away from the nearer edges', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  const viewport = await viewportOf(testPage);
  const droppedAt = [[0.25, 0.25, 'First'], [0.75, 0.75, 'Second'], [0.25, 0.75, 'Third']] as const;
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  for (const [across, down, note] of droppedAt) {
    await testPage.mouse.click(viewport.width * across, viewport.height * down);
    await testPage.keyboard.type(note);
    await testPage.keyboard.press('Enter');
  }
  expect(await pinNamesOf(inspector)).toEqual(['Pin 1: First', 'Pin 2: Second', 'Pin 3: Third']);

  await testPage.mouse.click(viewport.width * 0.25, viewport.height * 0.25);
  await expect.poll(() => inspector.focused()).toEqual({ role: 'textbox', name: 'Note for pin 1' });
  expect((await inspector.node('textbox', 'Note for pin 1')).value).toBe('First');
  await testPage.keyboard.press('ControlOrMeta+A');
  await testPage.keyboard.type('Logo is cut off');
  await testPage.keyboard.press('Enter');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Pin 1: Logo is cut off' });
  expect(await pinNamesOf(inspector)).toEqual(['Pin 1: Logo is cut off', 'Pin 2: Second', 'Pin 3: Third']);

  // A focused pin shows its note: below and to the right in the top-left quarter, above and to the left in the
  // bottom-right one, as the note editor opens.
  const pins = await overlayElementsWithClass(inspector, 'pin');
  const bubbles = await overlayElementsWithClass(inspector, 'pin__note');
  expect([pins.length, bubbles.length]).toEqual([3, 3]);
  const placesOf = async (index: number) => [await inspector.boxesOf(pins[index].backendNodeId), await inspector.boxesOf(bubbles[index].backendNodeId)];
  const [pinOne, bubbleOne] = await placesOf(0);
  expect(bubbleOne?.border.top).toBeGreaterThanOrEqual(pinOne?.border.bottom ?? Infinity);
  expect(bubbleOne?.border.left).toBeGreaterThanOrEqual(((pinOne?.border.left ?? 0) + (pinOne?.border.right ?? 0)) / 2 - 1);
  await testPage.keyboard.press('Tab');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Pin 2: Second' });
  const [pinTwo, bubbleTwo] = await placesOf(1);
  expect(bubbleTwo?.border.bottom).toBeLessThanOrEqual(pinTwo?.border.top ?? -Infinity);
  expect(bubbleTwo?.border.right).toBeLessThanOrEqual(((pinTwo?.border.left ?? 0) + (pinTwo?.border.right ?? 0)) / 2 + 1);

  await testPage.keyboard.press('Shift+Tab');
  await testPage.keyboard.press('Backspace');

  await expect.poll(() => pinNamesOf(inspector)).toEqual(['Pin 1: Second', 'Pin 2: Third']);
  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Pin 1: Second' });
});

test('Tab and Shift+Tab wrap inside the overlay, so Enter never presses the page\'s own button under it', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  for (const [move, note] of [['ArrowLeft', 'First'], ['ArrowUp', 'Second']]) {
    await testPage.keyboard.press(`Shift+${move}`);
    await testPage.keyboard.press('Enter');
    await testPage.keyboard.type(note);
    await testPage.keyboard.press('Escape');
  }
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);

  await testPage.keyboard.press('Shift+Tab');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Pin 2: Second' });
  await testPage.keyboard.press('Enter');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'textbox', name: 'Note for pin 2' });
  await testPage.keyboard.press('Tab');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Save note' });
  await testPage.keyboard.press('Tab');
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  await testPage.keyboard.press('Shift+Tab');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Save note' });
  await testPage.keyboard.press('Enter');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Pin 2: Second' });
  await testPage.keyboard.press('Tab');
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  await testPage.keyboard.press('Shift+Tab');
  await testPage.keyboard.press('Shift+Tab');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'button', name: 'Pin 1: First' });
  await testPage.keyboard.press('Escape');
  await expect(testPage.locator('pinround-overlay')).toHaveCount(0);

  await expect(testPage.locator('#paid')).toBeHidden();
  await testPage.locator('#pay').press('Enter');
  await expect(testPage.locator('#paid')).toBeVisible();
});

test('the page does not scroll under the overlay by wheel or keys, a long note still scrolls, and the page scrolls again once the overlay is closed', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await testPage.evaluate(() => {
    document.body.style.minHeight = '4000px';
  });
  const scrollY = () => testPage.evaluate(() => window.scrollY);
  await popup.getByRole('button', { name: 'Pin this page' }).click();

  await testPage.mouse.move(400, 300);
  await testPage.mouse.wheel(0, 600);
  for (const key of ['PageDown', 'End', 'ArrowDown', 'Enter', 'Escape', 'Tab', 'PageDown', 'End', 'ArrowDown', 'Space']) {
    await testPage.keyboard.press(key);
  }
  await afterTwoPaints(testPage);

  expect(await scrollY()).toBe(0);
  await expect.poll(() => inspector.focused()).toEqual({ role: 'textbox', name: 'Note for pin 1' });
  await testPage.keyboard.insertText(Array.from({ length: 30 }, (unused, line) => `Line ${line + 1}`).join('\n'));
  const noteField = (await inspector.shadowElementsOf('pinround-overlay')).find((element) => element.tagName === 'textarea');
  const noteBoxes = noteField === undefined ? null : await inspector.boxesOf(noteField.backendNodeId);
  if (noteField === undefined || noteBoxes === null) {
    throw new Error('The note field is not shown.');
  }
  const noteScrollTop = () => inspector.runAsPageOn(noteField.backendNodeId, 'function () { return this.scrollTop; }');
  await inspector.runAsPageOn(noteField.backendNodeId, 'function () { this.scrollTop = 0; }');
  await testPage.mouse.move((noteBoxes.border.left + noteBoxes.border.right) / 2, (noteBoxes.border.top + noteBoxes.border.bottom) / 2);
  await testPage.mouse.wheel(0, 60);
  await expect.poll(noteScrollTop).toBe(60);
  await afterTwoPaints(testPage);
  expect(await scrollY()).toBe(0);
  await testPage.evaluate(() => document.querySelector('pinround-overlay')?.dispatchEvent(new WheelEvent('wheel', { deltaY: 60, bubbles: true, cancelable: true })));
  expect(await noteScrollTop()).toBe(60);

  await testPage.keyboard.press('Escape');
  await testPage.keyboard.press('Escape');
  await expect(testPage.locator('pinround-overlay')).toHaveCount(0);
  await testPage.mouse.wheel(0, 600);
  await expect.poll(scrollY).toBeGreaterThan(0);
});

test('Ctrl+wheel over the overlay is left to the browser to zoom, and a plain wheel is still cancelled', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await testPage.evaluate(() => {
    const wheels: Array<[boolean, boolean]> = [];
    (window as unknown as { pageWheels: typeof wheels }).pageWheels = wheels;
    window.addEventListener('wheel', (event) => wheels.push([event.ctrlKey, event.defaultPrevented]));
  });
  const pageWheels = () => testPage.evaluate(() => (window as unknown as { pageWheels: Array<[boolean, boolean]> }).pageWheels);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);

  await testPage.mouse.move(400, 300);
  await testPage.mouse.wheel(0, 100);
  await testPage.keyboard.down('Control');
  await testPage.mouse.wheel(0, 100);
  await testPage.keyboard.up('Control');
  await testPage.mouse.wheel(0, 100);

  await expect.poll(pageWheels).toEqual([[false, true], [true, false], [false, true]]);
});

test('the short hint shows until the first pin and again once the last is gone, and the keys stay in the overlay\'s description', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  const hint = await overlayElementWithClass(inspector, 'hint');
  const overlayDescription = async () => (await inspector.node(OVERLAY.role, OVERLAY.name)).description;
  expect(await inspector.boxesOf(hint.backendNodeId)).not.toBeNull();
  expect(await overlayDescription()).toBe(OVERLAY_KEYS);

  await testPage.keyboard.press('Enter');
  await testPage.keyboard.press('Escape');

  await expect.poll(() => inspector.boxesOf(hint.backendNodeId)).toBeNull();
  expect(await overlayDescription()).toBe(OVERLAY_KEYS);
  await testPage.keyboard.press('Tab');
  await testPage.keyboard.press('Delete');
  await expect.poll(() => pinNamesOf(inspector)).toEqual([]);
  await expect.poll(() => inspector.boxesOf(hint.backendNodeId)).not.toBeNull();
});

test('the page\'s own script can neither reach the overlay nor drop, change or remove a pin with events it makes', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  await chooseNewReport(popup);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await expect.poll(() => inspector.focused()).toEqual(OVERLAY);
  await testPage.mouse.click(320, 180);
  await testPage.keyboard.type('Price is wrong');
  await testPage.keyboard.press('Enter');
  await testPage.mouse.click(640, 360);
  await testPage.keyboard.type('Logo is cut off');
  await expect.poll(() => inspector.focused()).toEqual({ role: 'textbox', name: 'Note for pin 2' });
  const pinsBefore = ['Pin 1: Price is wrong', 'Pin 2: Logo is cut off'];
  expect(await pinNamesOf(inspector)).toEqual(pinsBefore);

  const reach = await testPage.evaluate(() => {
    const host = document.querySelector('pinround-overlay');
    const wroteIntoFocusedField = document.execCommand('insertText', false, ' Pay to another account');
    host?.dispatchEvent(new MouseEvent('click', { bubbles: true, composed: true, clientX: 600, clientY: 400 }));
    document.activeElement?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, composed: true }));
    return { hasHost: host !== null, shadowRoot: host?.shadowRoot ?? null, focusIsOnHost: document.activeElement === host, wroteIntoFocusedField, selectedText: document.getSelection()?.toString() };
  });
  expect(reach).toEqual({ hasHost: true, shadowRoot: null, focusIsOnHost: true, wroteIntoFocusedField: true, selectedText: '' });
  expect((await inspector.node('textbox', 'Note for pin 2')).value).toBe('Logo is cut off');

  // As if the page's script held the overlay's elements: it runs in the page's world and its events are not trusted.
  const elements = await inspector.shadowElementsOf('pinround-overlay');
  const [surface] = elements.filter((element) => overlayHasClass(element, 'surface'));
  const [firstPin] = elements.filter((element) => overlayHasClass(element, 'pin'));
  const noteField = elements.find((element) => element.tagName === 'textarea');
  const saveNote = elements.find((element) => element.tagName === 'button' && element.className === '');
  if (surface === undefined || firstPin === undefined || noteField === undefined || saveNote === undefined) {
    throw new Error('The overlay has no surface, pin, note field or Save note button.');
  }
  await inspector.runAsPageOn(noteField.backendNodeId, `function () {
    window.pageScriptRan = true;
    this.dispatchEvent(new InputEvent('beforeinput', { bubbles: true, composed: true, inputType: 'insertText', data: 'Pay to another account' }));
    this.value = 'Pay to another account';
    this.dispatchEvent(new InputEvent('input', { bubbles: true, composed: true }));
    this.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, composed: true }));
    this.form.requestSubmit();
  }`);
  await inspector.runAsPageOn(saveNote.backendNodeId, 'function () { this.click(); }');
  await inspector.runAsPageOn(surface.backendNodeId, `function () {
    this.dispatchEvent(new MouseEvent('click', { bubbles: true, composed: true, clientX: 600, clientY: 400 }));
    this.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, composed: true }));
    this.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, composed: true }));
  }`);
  await inspector.runAsPageOn(firstPin.backendNodeId, `function () {
    this.dispatchEvent(new KeyboardEvent('keydown', { key: 'Delete', bubbles: true, composed: true }));
    this.click();
  }`);

  expect(await testPage.evaluate(() => (window as unknown as { pageScriptRan?: boolean }).pageScriptRan)).toBe(true);
  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
  expect(await pinNamesOf(inspector)).toEqual(pinsBefore);
  expect((await inspector.node('textbox', 'Note for pin 2')).value).toBe('Logo is cut off');
  expect(await inspector.focused()).toEqual({ role: 'textbox', name: 'Note for pin 2' });

  await testPage.keyboard.press('Enter');
  await popup.getByRole('button', { name: 'Capture this page' }).click();
  await expect(popup.getByRole('status')).toHaveText(FIRST_REPORT_CAPTURE_SAVED);
  const [pins] = reportPinsOf(stub);
  expect(pins.map((stored) => stored.note)).toEqual(['Price is wrong', 'Logo is cut off']);
  expect(stub.storedPictures.length).toBe(1);
});

interface PageWrites {
  beforeinputs: number;
  wrote: boolean[];
}

const pageWritesOf = (page: Page) => page.evaluate(() => (window as unknown as { pageWrites: PageWrites }).pageWrites);

test('a key whose beforeinput the page cancelled does not let the page\'s execCommand into the note', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(400, 300);
  await testPage.keyboard.type('Logo is cut off');
  await expect.poll(() => pinNamesOf(inspector)).toEqual(['Pin 1: Logo is cut off']);

  // The page writes the cancelled key's own text over the whole note, so only the cancel tells it from the person's key.
  await testPage.evaluate(() => {
    const writes: PageWrites = { beforeinputs: 0, wrote: [] };
    (window as unknown as { pageWrites: PageWrites }).pageWrites = writes;
    window.addEventListener('beforeinput', (event) => {
      writes.beforeinputs += 1;
      event.preventDefault();
      // Queued before any timer the overlay starts on this beforeinput, so it runs first.
      setTimeout(() => writes.wrote.push(document.execCommand('selectAll') && document.execCommand('insertText', false, 'X')), 0);
    }, { capture: true });
  });
  await testPage.keyboard.press('X');
  await expect.poll(async () => (await pageWritesOf(testPage)).wrote).toEqual([true]);

  expect(await pageWritesOf(testPage)).toEqual({ beforeinputs: 1, wrote: [true] });
  expect((await inspector.node('textbox', 'Note for pin 1')).value).toBe('Logo is cut off');
  expect(await pinNamesOf(inspector)).toEqual(['Pin 1: Logo is cut off']);
  expect(await inspector.focused()).toEqual({ role: 'textbox', name: 'Note for pin 1' });
});

test('a note key\'s beforeinput stops at the overlay, so the page cannot cancel it after the overlay heard it and write instead', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(400, 300);
  await testPage.keyboard.type('Logo is cut off');
  await expect.poll(() => pinNamesOf(inspector)).toEqual(['Pin 1: Logo is cut off']);
  await testPage.evaluate(() => {
    const writes: PageWrites = { beforeinputs: 0, wrote: [] };
    (window as unknown as { pageWrites: PageWrites }).pageWrites = writes;
    window.addEventListener('beforeinput', (event) => {
      writes.beforeinputs += 1;
      event.preventDefault();
      writes.wrote.push(document.execCommand('insertText', false, ' Pay to another account'));
    });
  });

  await testPage.keyboard.press('X');

  await expect.poll(() => pinNamesOf(inspector)).toEqual(['Pin 1: Logo is cut offX']);
  expect(await pageWritesOf(testPage)).toEqual({ beforeinputs: 0, wrote: [] });
  expect((await inspector.node('textbox', 'Note for pin 1')).value).toBe('Logo is cut offX');
});

test('a key into a full note brings a beforeinput with no input, and that lets no execCommand of the page in, before or after the overlay\'s timer', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  const fullNote = 'Prix faux, 価格が違う. '.repeat(60).slice(0, 1000);
  expect(fullNote.length).toBe(1000);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(400, 300);
  await testPage.keyboard.insertText(fullNote);
  await expect.poll(async () => (await inspector.node('textbox', 'Note for pin 1')).value).toBe(fullNote);
  await testPage.evaluate(() => {
    const writes: PageWrites = { beforeinputs: 0, wrote: [] };
    (window as unknown as { pageWrites: PageWrites }).pageWrites = writes;
    window.addEventListener('beforeinput', () => {
      writes.beforeinputs += 1;
      if (writes.beforeinputs === 1) {
        // Queued before the overlay's own timer on this beforeinput, so it runs first.
        setTimeout(() => writes.wrote.push(document.execCommand('delete')), 0);
      }
    }, { capture: true });
  });

  await testPage.keyboard.press('X');
  await expect.poll(async () => (await pageWritesOf(testPage)).wrote).toEqual([true]);
  await testPage.keyboard.press('X');
  // After the overlay's timer on the second key, the page writes the key's own kind and text over the whole note.
  await testPage.evaluate(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
    (window as unknown as { pageWrites: PageWrites }).pageWrites.wrote.push(document.execCommand('selectAll') && document.execCommand('insertText', false, 'X'));
  });

  expect(await pageWritesOf(testPage)).toEqual({ beforeinputs: 2, wrote: [true, true] });
  const note = (await inspector.node('textbox', 'Note for pin 1')).value;
  expect({ length: note.length, isTheFullNote: note === fullNote }).toEqual({ length: 1000, isTheFullNote: true });
});

test('an execCommand the page runs while a typed key\'s input is under way leaves the note with that key and without the page\'s text', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(400, 300);
  await testPage.keyboard.type('Logo is cut off');
  await expect.poll(() => pinNamesOf(inspector)).toEqual(['Pin 1: Logo is cut off']);
  await testPage.evaluate(() => {
    const writes: PageWrites = { beforeinputs: 0, wrote: [] };
    (window as unknown as { pageWrites: PageWrites }).pageWrites = writes;
    let hasWritten = false;
    window.addEventListener('input', () => {
      if (!hasWritten) {
        hasWritten = true;
        writes.wrote.push(document.execCommand('insertText', false, ' Pay to another account'));
      }
    }, { capture: true });
  });

  await testPage.keyboard.press('X');

  await expect.poll(async () => (await pageWritesOf(testPage)).wrote).toEqual([true]);
  await expect.poll(async () => (await inspector.node('textbox', 'Note for pin 1')).value).toBe('Logo is cut offX');
  expect(await pinNamesOf(inspector)).toEqual(['Pin 1: Logo is cut offX']);
  await testPage.keyboard.type(', 価格');
  await expect.poll(() => pinNamesOf(inspector)).toEqual(['Pin 1: Logo is cut offX, 価格']);
});

test('the page replaying every note input over the whole note from its capture-phase input listener changes nothing the person writes', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  await chooseNewReport(popup);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(400, 300);
  await testPage.evaluate(() => {
    const writes: PageWrites = { beforeinputs: 0, wrote: [] };
    (window as unknown as { pageWrites: PageWrites }).pageWrites = writes;
    let isReplaying = false;
    window.addEventListener('input', (event) => {
      if (isReplaying || !(event instanceof InputEvent)) {
        return;
      }
      isReplaying = true;
      const isAllSelected = document.execCommand('selectAll');
      writes.wrote.push(isAllSelected && ('insertLineBreak' === event.inputType ? document.execCommand('insertLineBreak') : document.execCommand('insertText', false, event.data ?? '')));
      isReplaying = false;
    }, { capture: true });
  });

  await testPage.keyboard.type('Price is wrong');

  await expect.poll(() => pinNamesOf(inspector)).toEqual(['Pin 1: Price is wrong']);
  expect(await pageWritesOf(testPage)).toEqual({ beforeinputs: 0, wrote: Array.from({ length: 'Price is wrong'.length }, () => true) });

  await testPage.keyboard.press('Shift+Enter');
  await testPage.keyboard.type('Total 価格');
  await testPage.keyboard.insertText('\r\nTax 😀');

  const fullNote = 'Price is wrong\nTotal 価格\nTax 😀';
  await expect.poll(async () => (await inspector.node('textbox', 'Note for pin 1')).value).toBe(fullNote);
  const { wrote } = await pageWritesOf(testPage);
  expect(wrote.length).toBeGreaterThan('Price is wrong'.length + 1 + 'Total 価格'.length);
  expect(wrote.every((hasWritten) => hasWritten)).toBe(true);
  // The replay empties the note, which is no deletion before the caret, so the note stays as it was without the key.
  await testPage.keyboard.press('Home');
  await testPage.keyboard.press('Backspace');
  await expect.poll(async () => (await pageWritesOf(testPage)).wrote.length).toBe(wrote.length + 1);
  expect((await inspector.node('textbox', 'Note for pin 1')).value).toBe(fullNote);
  await testPage.keyboard.press('Enter');
  await popup.getByRole('button', { name: 'Capture this page' }).click();
  await expect(popup.getByRole('status')).toHaveText(FIRST_REPORT_CAPTURE_SAVED);
  const [pins] = reportPinsOf(stub);
  expect(pins.map((stored) => stored.note)).toEqual([fullNote]);
});

test('the person\'s own inserts, deletions, composition and a cut at the note\'s length all reach the note, and undo is refused', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(400, 300);
  const noteValue = async () => (await inspector.node('textbox', 'Note for pin 1')).value;

  await testPage.keyboard.insertText('Price 価格\r\nis wrong');
  await expect.poll(noteValue).toBe('Price 価格\nis wrong');
  await testPage.keyboard.press('Backspace');
  await expect.poll(noteValue).toBe('Price 価格\nis wron');
  await testPage.keyboard.press('Home');
  await testPage.keyboard.press('Delete');
  await expect.poll(noteValue).toBe('Price 価格\ns wron');
  await testPage.keyboard.press('End');
  await testPage.keyboard.press('Control+Backspace');
  await expect.poll(noteValue).toBe('Price 価格\ns ');

  const session = await context.newCDPSession(testPage);
  await session.send('Input.imeSetComposition', { text: 'に', selectionStart: 1, selectionEnd: 1 });
  await session.send('Input.imeSetComposition', { text: 'にほ', selectionStart: 2, selectionEnd: 2 });
  await session.send('Input.insertText', { text: '日本' });
  await session.detach();
  await expect.poll(noteValue).toBe('Price 価格\ns 日本');
  // The accessibility tree shows a line break in a name as a space.
  await expect.poll(() => pinNamesOf(inspector)).toEqual(['Pin 1: Price 価格 s 日本']);

  await testPage.keyboard.press('ControlOrMeta+Z');
  await afterTwoPaints(testPage);
  expect(await noteValue()).toBe('Price 価格\ns 日本');

  // The cut falls between the halves of the emoji, so Chrome keeps neither.
  const filler = 'Prix faux, 価格が違う. '.repeat(60).slice(0, 998);
  await testPage.keyboard.press('ControlOrMeta+A');
  await testPage.keyboard.insertText(`${filler}a😀b`);
  await expect.poll(noteValue).toBe(`${filler}a`);
});

test('the 101st pin is refused with a plain message and the 100 stay', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  for (let pin = 1; pin <= 100; pin += 1) {
    await testPage.keyboard.press('Enter');
    await testPage.keyboard.press('Enter');
  }
  await expect.poll(async () => (await pinNamesOf(inspector)).length).toBe(100);

  await testPage.keyboard.press('Enter');

  await expect.poll(async () => (await inspector.accessibleNodes()).filter((node) => node.role === 'status').map((node) => node.text)).toEqual(['A picture holds at most 100 pins.']);
  expect((await pinNamesOf(inspector)).length).toBe(100);
  expect(await inspector.focused()).toEqual(OVERLAY);
});

test('the page\'s styles do not reach the overlay, and the overlay\'s do not reach the page', async ({ context, testPage }) => {
  await answerPinroundApi(context);
  await testPage.addStyleTag({ content: 'pinround-overlay { display: none !important; } button { width: 3px !important; border: 9px solid red !important; }' });
  const payStyle = () => testPage.locator('#pay').evaluate((button) => { const style = getComputedStyle(button); return [style.width, style.borderTopWidth, style.font, style.color]; });
  const payBefore = await payStyle();
  const popup = await pairedPopupFor(context, testPage);
  const inspector = await PageInspector.of(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(400, 300);
  await testPage.keyboard.press('Enter');

  await expect(testPage.locator('pinround-overlay')).toBeVisible();
  const pin = await overlayElementWithClass(inspector, 'pin');
  const boxes = await inspector.boxesOf(pin.backendNodeId);
  expect(boxes === null ? null : [boxes.border.right - boxes.border.left, boxes.padding.left - boxes.border.left]).toEqual([28, 2]);
  expect(await payStyle()).toEqual(payBefore);
});

// The same paths and messages as extension/tests/fake-browser.test.mjs, which copies these rules.
test('Chrome finds injected files from the extension\'s root after one leading slash, and refuses a path or a folder that is no script', async ({ context, testPage }) => {
  const popup = await openPopupFor(context, testPage);
  const tabId = await tabIdOf(popup, TEST_PAGE_URL);
  const notFound = ['src/overlay/missing.js', '//src/overlay/pin-rules.js', 'src/popup/../overlay/pin-rules.js', '../pin-rules.js', 'src/overlay/', 'SRC/overlay/pin-rules.js', 'src/overlay/pin-rules.js/', 'src/overlay/missing.JS'];
  const noScript = ['src/overlay', 'styles.css'];

  const outcomes = await popup.evaluate(async ({ target, paths }) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    const outcomes: Record<string, string> = {};
    for (const file of paths) {
      outcomes[file] = await chrome.scripting.executeScript({ target: { tabId: target }, files: [file] }).then(() => 'loaded', (error: Error) => error.message);
    }
    return outcomes;
  }, { target: tabId, paths: ['/src/overlay/pin-rules.js', ...notFound, ...noScript] });

  expect(outcomes).toEqual({
    '/src/overlay/pin-rules.js': 'loaded',
    ...Object.fromEntries(notFound.map((file) => [file, `Could not load file: '${file}'.`])),
    ...Object.fromEntries(noScript.map((file) => [file, `Could not load file '${file}' for content script, content scripts can only be loaded from supported JavaScript files such as .js files.`])),
  });
});
