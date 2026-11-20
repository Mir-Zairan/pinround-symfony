import type { BrowserContext, Page } from '@playwright/test';
import { inflateSync } from 'node:zlib';
import { CAPTURE_HOST_PERMISSIONS, PINROUND_TEST_ORIGIN, TEST_PAGE_URL, captureVisibleTabOf, expect, openPopupFor, pairedPopupFor, resizeWindowOf, tabIdOf, test, type ExtensionPageGlobals } from './extension';

// The pin overlay "Pin this page" injects (extension/src/overlay/) and the capture that sends its pins (P13.T5 step 1).

const STORAGE_ORIGIN = 'https://storage.acme-foods.test';
const PAGE_GREEN = [0, 128, 0];
// The ring's white inner outline lies 12 to 14 CSS px from the pin's centre; devicePixelRatio is 1 here.
const RING_OFFSET_PX = 13;

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

/** Where the pin lies on the picture, then the ring's white outline left and right of it. */
function overlaySpotsAround(picture: DecodedPng, pin: StoredPin): Array<[number, number]> {
  const [x, y] = [(pin.x_pct / 100) * picture.width, (pin.y_pct / 100) * picture.height];
  return [[x, y], [x - RING_OFFSET_PX, y], [x + RING_OFFSET_PX, y]];
}

/** Answers pinround's API for the paired popup and keeps each picture PUT to storage, in order. */
async function answerPinround(context: BrowserContext, { uploadRefusal }: { uploadRefusal?: string } = {}): Promise<Buffer[]> {
  const storedPictures: Buffer[] = [];
  await context.route(`${PINROUND_TEST_ORIGIN}/**`, (route) => {
    const { pathname } = new URL(route.request().url());
    if (pathname === '/api/v1/me/tickets') {
      return route.fulfill({ json: { tickets: [] } });
    }
    if (pathname === '/api/v1/uploads' && uploadRefusal !== undefined) {
      return route.fulfill({ status: 403, json: { error: uploadRefusal, outcome: 'refused' } });
    }
    if (pathname === '/api/v1/uploads') {
      return route.fulfill({ status: 201, json: { upload_id: `upload-${storedPictures.length + 1}`, put_url: `${STORAGE_ORIGIN}/upload-${storedPictures.length + 1}`, headers: { 'Content-Type': 'image/png' } } });
    }
    return route.fulfill({ status: 404, json: { error: 'Not found.' } });
  });
  await context.route(`${STORAGE_ORIGIN}/**`, (route) => {
    storedPictures.push(route.request().postDataBuffer() ?? Buffer.alloc(0));
    return route.fulfill({ status: 200, body: '' });
  });
  return storedPictures;
}

async function storedReportPins(popup: Page): Promise<StoredPin[][]> {
  return popup.evaluate(async () => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    const stored = (await chrome.storage.session.get('pinroundReportCaptures')).pinroundReportCaptures as { items: Array<{ pins: StoredPin[] }> } | undefined;
    return (stored?.items ?? []).map((item) => item.pins);
  });
}

const pinButtons = (page: Page) => page.getByRole('button', { name: /^Pin \d+/ });

// Every spec here captures, or pins as a capture would.
test.use({ extensionHostPermissions: CAPTURE_HOST_PERMISSIONS });

test.describe('in a window the test resizes', () => {
  test.use({ viewportFollowsWindow: true });

  test('a pin dropped by click and one by keyboard land at their percent of the captured picture after a resize, with no overlay in it', async ({ context, testPage }) => {
    const storedPictures = await answerPinround(context);
    await testPage.evaluate(() => {
      document.body.style.background = 'rgb(0, 128, 0)';
      for (const child of document.body.children) {
        (child as HTMLElement).style.visibility = 'hidden';
      }
    });
    await resizeWindowOf(context, testPage, { width: 1280, height: 800 });
    const popup = await pairedPopupFor(context, testPage);
    await popup.getByLabel('Project key').fill('SHOP');
    const [width, height] = await testPage.evaluate(() => [window.innerWidth, window.innerHeight]);

    await popup.getByRole('button', { name: 'Pin this page' }).click();
    const surface = testPage.getByRole('application', { name: 'Pin overlay' });
    await expect(surface).toBeFocused();
    await testPage.mouse.click(width / 4, height / 4);
    await testPage.keyboard.type('Logo is cut off');
    await testPage.keyboard.press('Enter');
    await expect(surface).toBeFocused();
    for (const key of ['Shift+ArrowRight', 'Shift+ArrowRight', 'ArrowDown', 'ArrowDown', 'ArrowDown', 'ArrowDown', 'ArrowDown', 'Enter']) {
      await testPage.keyboard.press(key);
    }
    await expect(testPage.getByLabel('Note for pin 2')).toBeFocused();
    await testPage.keyboard.type('Price is wrong');
    await testPage.keyboard.press('Enter');
    await testPage.keyboard.press('Tab');
    await expect(testPage.getByRole('button', { name: 'Pin 1: Logo is cut off', exact: true })).toBeFocused();
    await testPage.keyboard.press('Tab');
    await expect(testPage.getByRole('button', { name: 'Pin 2: Price is wrong', exact: true })).toBeFocused();

    await resizeWindowOf(context, testPage, { width: 1000, height: 640 });
    const resized = await testPage.evaluate(() => ({ width: window.innerWidth, height: window.innerHeight }));
    const withOverlay = decodedPng(pngOfDataUrl(await captureVisibleTabOf(context, testPage)));
    await popup.getByRole('button', { name: 'Capture this page' }).click();
    await expect(popup.getByRole('status')).toHaveText('Screenshot 1 saved for the report. Capture more pages, or make the report draft.');

    const droppedAt = [[25, 25], [70, 55]];
    const [pins] = await storedReportPins(popup);
    expect(pins.map((pin) => pin.note)).toEqual(['Logo is cut off', 'Price is wrong']);
    pins.forEach((pin, index) => {
      expect(Math.abs(pin.x_pct - droppedAt[index][0])).toBeLessThanOrEqual(0.5);
      expect(Math.abs(pin.y_pct - droppedAt[index][1])).toBeLessThanOrEqual(0.5);
    });
    expect(storedPictures.length).toBe(1);
    const captured = decodedPng(storedPictures[0]);
    expect(resized).not.toEqual({ width, height });
    expect({ width: captured.width, height: captured.height }).toEqual(resized);
    expect({ width: withOverlay.width, height: withOverlay.height }).toEqual(resized);
    // With the overlay in the picture, each ring's white outline is where the stored percent puts it on the resized
    // picture and the tint covers the centre; the picture taken by "Capture" has the plain page at all three spots.
    for (const pin of pins) {
      const [centre, ringLeft, ringRight] = overlaySpotsAround(captured, pin);
      expect(withOverlay.rgbAt(...ringLeft).every((channel) => channel >= 200)).toBe(true);
      expect(withOverlay.rgbAt(...ringRight).every((channel) => channel >= 200)).toBe(true);
      expect(withOverlay.rgbAt(...centre)).not.toEqual(PAGE_GREEN);
      expect([captured.rgbAt(...centre), captured.rgbAt(...ringLeft), captured.rgbAt(...ringRight)]).toEqual([PAGE_GREEN, PAGE_GREEN, PAGE_GREEN]);
    }
    await expect(testPage.locator('pinround-overlay')).toHaveCount(0);
  });
});

test('a capture pinround refuses leaves the pins on the page and saves nothing', async ({ context, testPage }) => {
  await answerPinround(context, { uploadRefusal: 'This token may not make reports.' });
  const popup = await pairedPopupFor(context, testPage);
  await popup.getByLabel('Project key').fill('SHOP');
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(640, 360);
  await testPage.keyboard.type('Total is wrong');
  await testPage.keyboard.press('Enter');

  await popup.getByRole('button', { name: 'Capture this page' }).click();

  await expect(popup.getByRole('status')).toHaveText('This token may not make reports.');
  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
  await expect(pinButtons(testPage)).toHaveCount(1);
  await expect(testPage.getByRole('button', { name: 'Pin 1: Total is wrong', exact: true })).toBeVisible();
  expect(await storedReportPins(popup)).toEqual([]);
});

test('pressing "Pin this page" twice keeps one overlay with its pins', async ({ context, testPage }) => {
  await answerPinround(context);
  const popup = await pairedPopupFor(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(200, 200);
  await testPage.keyboard.press('Enter');

  await popup.getByRole('button', { name: 'Pin this page' }).click();

  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
  await expect(testPage.getByRole('application', { name: 'Pin overlay' })).toHaveCount(1);
  await expect(testPage.getByRole('button', { name: 'Pin 1', exact: true })).toBeVisible();
  await expect(testPage.getByRole('application', { name: 'Pin overlay' })).toBeFocused();
});

test('without a mouse: Escape leaves a note, Delete removes the focused pin and the rest renumber, Escape closes and drops the pins', async ({ context, testPage }) => {
  await answerPinround(context);
  const popup = await pairedPopupFor(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  const surface = testPage.getByRole('application', { name: 'Pin overlay' });
  for (const [move, note] of [['ArrowLeft', 'First'], ['ArrowUp', 'Second'], ['ArrowRight', 'Third']]) {
    await testPage.keyboard.press(`Shift+${move}`);
    await testPage.keyboard.press('Enter');
    await testPage.keyboard.type(note);
    await testPage.keyboard.press('Escape');
    await expect(surface).toBeFocused();
  }
  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
  await expect(pinButtons(testPage)).toHaveText(['1', '2', '3'].map((number) => new RegExp(`^${number}`)));

  await testPage.keyboard.press('Tab');
  await testPage.keyboard.press('Tab');
  await expect(testPage.getByRole('button', { name: 'Pin 2: Second', exact: true })).toBeFocused();
  await testPage.keyboard.press('Delete');

  await expect(testPage.getByRole('button', { name: 'Pin 1: First', exact: true })).toBeFocused();
  expect(await pinButtons(testPage).evaluateAll((buttons) => buttons.map((button) => button.getAttribute('aria-label')))).toEqual(['Pin 1: First', 'Pin 2: Third']);

  await testPage.keyboard.press('Escape');
  await expect(testPage.locator('pinround-overlay')).toHaveCount(0);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await expect(surface).toBeFocused();
  await expect(pinButtons(testPage)).toHaveCount(0);
});

test('the 101st pin is refused with a plain message and the 100 stay', async ({ context, testPage }) => {
  await answerPinround(context);
  const popup = await pairedPopupFor(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  for (let pin = 1; pin <= 100; pin += 1) {
    await testPage.keyboard.press('Enter');
    await testPage.keyboard.press('Enter');
  }
  await expect(pinButtons(testPage)).toHaveCount(100);

  await testPage.keyboard.press('Enter');

  await expect(testPage.locator('pinround-overlay').getByRole('status')).toHaveText('A picture holds at most 100 pins.');
  await expect(pinButtons(testPage)).toHaveCount(100);
  await expect(testPage.getByRole('application', { name: 'Pin overlay' })).toBeFocused();
});

test('the page\'s styles do not reach the overlay, and the overlay\'s do not reach the page', async ({ context, testPage }) => {
  await answerPinround(context);
  await testPage.addStyleTag({ content: 'pinround-overlay { display: none !important; } button { width: 3px !important; border: 9px solid red !important; }' });
  const payStyle = () => testPage.locator('#pay').evaluate((button) => { const style = getComputedStyle(button); return [style.width, style.borderTopWidth, style.font, style.color]; });
  const payBefore = await payStyle();
  const popup = await pairedPopupFor(context, testPage);
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await testPage.mouse.click(400, 300);
  await testPage.keyboard.press('Enter');

  const pin = testPage.getByRole('button', { name: 'Pin 1', exact: true });
  await expect(testPage.locator('pinround-overlay')).toBeVisible();
  expect(await pin.evaluate((button) => [getComputedStyle(button).width, getComputedStyle(button).borderTopWidth])).toEqual(['28px', '2px']);
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
