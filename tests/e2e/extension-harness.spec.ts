import { inflateSync } from 'node:zlib';
import { CAPTURE_HOST_PERMISSIONS, EXTENSION_ID, TEST_PAGE_ORIGIN, TEST_PAGE_URL, captureVisibleTabOf, expect, openPopupFor, tabIdOf, test, type ExtensionPageGlobals } from './extension';

// These specs also prove in real Chromium the rules extension/tests/fake-browser.mjs copies.

const OTHER_ORIGIN = 'http://news.acme-foods.test';
const PNG_SIGNATURE = '89504e470d0a1a0a';
const ARGS_REFUSAL = "Error in invocation of scripting.executeScript(scripting.ScriptInjection injection, optional function callback): Error at parameter 'injection': Error at property 'args'";

/**
 * The red, green and blue of an 8-bit PNG's top-left pixel. Every PNG row filter leaves the first pixel of the first
 * row as it is, so no unfiltering is needed.
 */
function topLeftPixelOf(png: Buffer): number[] {
  const imageData: Buffer[] = [];
  for (let offset = 8; offset < png.length; offset += 12 + png.readUInt32BE(offset)) {
    if (png.toString('ascii', offset + 4, offset + 8) === 'IDAT') {
      imageData.push(png.subarray(offset + 8, offset + 8 + png.readUInt32BE(offset)));
    }
  }
  const rows = inflateSync(Buffer.concat(imageData));
  return [...rows.subarray(1, 4)];
}

test('the extension loads and its popup opens at the test id', async ({ context }) => {
  const popup = await context.newPage();
  await popup.goto(`chrome-extension://${EXTENSION_ID}/popup.html`);

  await expect(popup.getByRole('heading', { name: 'Capture with pinround', level: 1 })).toBeVisible();
  await expect(popup.getByText('Connect the extension to your pinround first.')).toBeVisible();
});

test('the test page is on its own origin, not on pinround', async ({ testPage, baseURL }) => {
  await expect(testPage.getByRole('heading', { name: 'Acme Foods shop', level: 1 })).toBeVisible();
  const origin = new URL(testPage.url()).origin;

  expect(origin).toBe(TEST_PAGE_ORIGIN);
  expect(origin).not.toBe('https://localhost');
  expect(origin).not.toBe(new URL(baseURL ?? 'https://localhost').origin);
});

test('from the popup, a script runs in the test page tab and reads what the person typed there', async ({ context, testPage }) => {
  await testPage.getByLabel('Email for the receipt').fill('mia@acme-foods.example');
  const popup = await openPopupFor(context, testPage);
  const tabId = await tabIdOf(popup, TEST_PAGE_URL);

  const activeTabUrls = await popup.evaluate(async () => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    return (await chrome.tabs.query({ active: true, currentWindow: true })).map((tab) => tab.url);
  });
  const typed = await popup.evaluate(async (target) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    const results = await chrome.scripting.executeScript({ target: { tabId: target }, func: () => (document.getElementById('email') as HTMLInputElement).value });
    return results.map((injection) => injection.result);
  }, tabId);

  expect(activeTabUrls).toEqual([TEST_PAGE_URL]);
  expect(typed).toEqual(['mia@acme-foods.example']);
});

test('a page on another origin stays out of reach, with the refusal the fake copies', async ({ context, testPage }) => {
  await context.route(`${OTHER_ORIGIN}/**`, (route) => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Acme Foods news</title><h1>Acme Foods news</h1>' }));
  const popup = await openPopupFor(context, testPage);
  const tabId = await tabIdOf(popup, TEST_PAGE_URL);
  await testPage.goto(`${OTHER_ORIGIN}/`);
  await expect(testPage.getByRole('heading', { name: 'Acme Foods news' })).toBeVisible();

  const refusal = await popup.evaluate(async (target) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    try {
      await chrome.scripting.executeScript({ target: { tabId: target }, func: () => document.title });
      return 'ran';
    } catch (error) {
      return (error as Error).message;
    }
  }, tabId);

  expect(refusal).toBe('Cannot access contents of the page. Extension manifest must request permission to access the respective host.');
});

test('a host permission for the page alone does not let the extension capture it', async ({ context, testPage }) => {
  const refusal = await captureVisibleTabOf(context, testPage).then(() => 'captured', (error: Error) => error.message);

  expect(refusal).toContain("Either the '<all_urls>' or 'activeTab' permission is required.");
});

test('a func runs from its source in the page: an outside variable, a throw or a rejection answer null, and page globals last until a navigation', async ({ context, testPage }) => {
  const popup = await openPopupFor(context, testPage);
  const tabId = await tabIdOf(popup, TEST_PAGE_URL);

  const answers = await popup.evaluate(async (target) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    const outside = 'only in the popup';
    const run = async (func: () => unknown) => (await chrome.scripting.executeScript({ target: { tabId: target }, func }))[0];
    return {
      outside: (await run(() => outside)).result,
      thrown: (await run(() => { throw new Error('broken page'); })).result,
      rejected: (await run(async () => { throw new Error('broken later'); })).result,
      nothing: (await run(() => undefined)).result,
      kept: (await run(() => { (globalThis as unknown as { kept: string }).kept = 'from the first injection'; })).documentId,
      keptRead: await run(() => (globalThis as unknown as { kept?: string }).kept ?? 'gone'),
    };
  }, tabId);
  await testPage.goto(`${TEST_PAGE_URL}?again`);
  const afterNavigation = await popup.evaluate(async (target) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    return (await chrome.scripting.executeScript({ target: { tabId: target }, func: () => (globalThis as unknown as { kept?: string }).kept ?? 'gone' }))[0];
  }, tabId);

  expect(answers).toMatchObject({ outside: null, thrown: null, rejected: null, nothing: null, keptRead: { result: 'from the first injection', documentId: answers.kept } });
  expect(afterNavigation.result).toBe('gone');
  expect(afterNavigation.documentId).not.toBe(answers.kept);
});

test('values cross storage and executeScript as Chrome converts them, and arguments it cannot send are refused', async ({ context, testPage }) => {
  const popup = await openPopupFor(context, testPage);
  const tabId = await tabIdOf(popup, TEST_PAGE_URL);

  const crossed = await popup.evaluate(async ({ target, argsRefusal }) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    const placedAt = new Date('2026-03-14T09:30:00Z');
    const looped: Record<string, unknown> = { name: 'looped' };
    looped.self = looped;
    const refusalOf = async (call: () => Promise<unknown>) => {
      try {
        return await call().then(() => 'sent', (error: Error) => `rejected: ${error.message}`);
      } catch (error) {
        return `thrown ${(error as Error).name}: ${(error as Error).message.replace(argsRefusal, '…')}`;
      }
    };

    await chrome.storage.local.set({ placedAt, gone: undefined, notANumber: NaN, lines: [undefined, 2, () => 'x'], totals: new Map([['jam', 2]]), order: { note: undefined, total: -0, label: { toJSON: () => 'converted' } }, looped });
    const stored = await chrome.storage.local.get(null);
    const pageSaw = (await chrome.scripting.executeScript({
      target: { tabId: target },
      func: (order: { placedAt: unknown }) => ({ json: JSON.stringify(order), placedAtIsDate: order.placedAt instanceof Date }),
      args: [{ placedAt, note: undefined, lines: [undefined, NaN, 3], label: { toJSON: () => 'converted' } }],
    }))[0].result;
    const returned = (await chrome.scripting.executeScript({
      target: { tabId: target },
      func: () => {
        const loop: Record<string, unknown> = { at: new Date(0), gone: undefined, bytes: new Uint8Array([1]), lines: [undefined, 2] };
        loop.self = loop;
        return loop;
      },
    }))[0].result;
    const refusals = {
      undefinedArgument: await refusalOf(() => chrome.scripting.executeScript({ target: { tabId: target }, func: () => 1, args: ['SHOP', undefined] })),
      functionArgument: await refusalOf(() => chrome.scripting.executeScript({ target: { tabId: target }, func: () => 1, args: [() => 'x'] })),
      notAnArray: await refusalOf(() => chrome.scripting.executeScript({ target: { tabId: target }, func: () => 1, args: 'SHOP' as unknown as unknown[] })),
      binaryArgument: await refusalOf(() => chrome.scripting.executeScript({ target: { tabId: target }, func: () => 1, args: [{ picture: new Uint8Array([1]) }] })),
      neitherFuncNorFiles: await refusalOf(() => chrome.scripting.executeScript({ target: { tabId: target } } as unknown as { target: { tabId: number }; files: string[] })),
      missingTab: await refusalOf(() => chrome.scripting.executeScript({ target: { tabId: 999999 }, func: () => 1 })),
      missingFile: await refusalOf(() => chrome.scripting.executeScript({ target: { tabId: target }, files: ['src/popup/report-captures.js', 'src/missing.js'] })),
      binaryStored: await refusalOf(() => chrome.storage.local.set({ picture: new Uint8Array([1]) })),
      storedNotAnObject: await refusalOf(() => chrome.storage.local.set('SHOP' as unknown as Record<string, unknown>)),
    };
    return { stored: JSON.stringify(stored), placedAtIsDate: stored.placedAt instanceof Date, pageSaw, returned, refusals };
  }, { target: tabId, argsRefusal: ARGS_REFUSAL });

  expect(crossed.stored).toBe('{"lines":[null,2,null],"looped":{"name":"looped","self":null},"order":{"label":{},"total":0},"placedAt":{},"totals":{}}');
  expect(crossed.placedAtIsDate).toBe(false);
  expect(crossed.pageSaw).toEqual({ json: '{"label":{},"lines":[null,null,3],"placedAt":{}}', placedAtIsDate: false });
  expect(crossed.returned).toEqual({ at: {}, bytes: {}, lines: [null, 2], self: null });
  expect(crossed.refusals).toEqual({
    undefinedArgument: 'thrown TypeError: …: Error at index 1: Value is unserializable.',
    functionArgument: 'thrown TypeError: …: Error at index 0: Value is unserializable.',
    notAnArray: 'thrown TypeError: …: Invalid type: expected array, found string.',
    binaryArgument: 'rejected: Unserializable argument passed.',
    neitherFuncNorFiles: "rejected: Exactly one of 'func' and 'files' must be specified",
    missingTab: 'rejected: No tab with id: 999999',
    missingFile: "rejected: Could not load file: 'src/missing.js'.",
    binaryStored: 'rejected: Cannot serialize value to JSON',
    storedNotAnObject: 'thrown TypeError: Error in invocation of storage.set(object items, optional function callback): No matching signature.',
  });
});

test.describe('with <all_urls> in the test manifest', () => {
  test.use({ extensionHostPermissions: CAPTURE_HOST_PERMISSIONS });

  test('the extension captures the test page, not its own popup, as a PNG the size of the viewport', async ({ context, testPage }) => {
    await testPage.evaluate(() => { document.body.style.background = 'rgb(0, 128, 0)'; });

    const dataUrl = await captureVisibleTabOf(context, testPage);

    expect(dataUrl).toMatch(/^data:image\/png;base64,/);
    const png = Buffer.from(dataUrl.slice('data:image/png;base64,'.length), 'base64');
    expect(png.subarray(0, 8).toString('hex')).toBe(PNG_SIGNATURE);
    expect({ width: png.readUInt32BE(16), height: png.readUInt32BE(20) }).toEqual(testPage.viewportSize());
    expect(topLeftPixelOf(png)).toEqual([0, 128, 0]);
  });

  test('a chrome:// page stays out of reach: no capture, no script, no address', async ({ context, testPage }) => {
    const popup = await openPopupFor(context, testPage);
    const settingsPage = await context.newPage();
    await settingsPage.goto('chrome://version/');
    await settingsPage.bringToFront();

    const refusals = await popup.evaluate(async () => {
      const { chrome } = globalThis as unknown as ExtensionPageGlobals;
      const [active] = await chrome.tabs.query({ active: true, currentWindow: true });
      const refusalOf = (promise: Promise<unknown>) => promise.then(() => 'allowed', (error: Error) => error.message);
      return {
        address: active?.url ?? 'hidden',
        capture: await refusalOf(chrome.tabs.captureVisibleTab({ format: 'png' })),
        script: await refusalOf(chrome.scripting.executeScript({ target: { tabId: active?.id ?? -1 }, func: () => document.title })),
      };
    });

    expect(refusals).toEqual({
      address: 'hidden',
      capture: "The 'activeTab' permission is not in effect because this extension has not been in invoked.",
      script: 'Cannot access a chrome:// URL',
    });
  });
});
