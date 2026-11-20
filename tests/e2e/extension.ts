import { test as base, chromium, type BrowserContext, type Page } from '@playwright/test';
import { createHash } from 'node:crypto';
import { cp, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

const SHIPPED_EXTENSION = path.join(__dirname, '..', '..', 'extension');
const TEST_PAGE_FILE = path.join(__dirname, 'fixtures', 'extension', 'test-page.html');

// A page of the shop under test, on an origin that is not pinround's. Nothing serves it: context.route answers it.
export const TEST_PAGE_ORIGIN = 'http://shop.acme-foods.test';
export const TEST_PAGE_URL = `${TEST_PAGE_ORIGIN}/`;

// The public half of a key pair made for these tests only; Chrome derives the extension id from it, so the id is known
// before the browser starts. The private half was never kept: an unpacked extension does not need it.
const TEST_MANIFEST_KEY =
  'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0i1N24OpeG7XLZnGEzVsQi0PWZuLvKnsDnDoV31AgBv2+5Dl9j9fgwfcWSxfAAPjRXeC6EfdCQ3uvNRuBtSCdmm42voFPXfA2OHe/x3AmqLl/jjVJDRkU5zBOCmpRJ65eQWN+7GV6tgz6bBp0l2J58D3sPFCptURi1liJuEvYuKquIPl8puYMIJXJAnDjHMeGbk5css2B5DHmy+3Tyl/MDd7xDY+g8XhapR+bh5fLMcIJdG6OnyRJf6iX1LJC75/q6efoVMytmSCzniQXVtN5CN0RR2B9uDGhHSCN6XF4Nqsd59SWxiQd7EgUA5pOqDDYOi2guDA7VblPDSKqomI7wIDAQAB';

/** Chrome's id for an extension with this manifest key: the first 128 bits of its SHA-256, each hex digit as a to p. */
function extensionIdOf(manifestKey: string): string {
  const hex = createHash('sha256').update(Buffer.from(manifestKey, 'base64')).digest('hex').slice(0, 32);
  return [...hex].map((digit) => String.fromCharCode('a'.charCodeAt(0) + parseInt(digit, 16))).join('');
}

export const EXTENSION_ID = extensionIdOf(TEST_MANIFEST_KEY);

/**
 * A copy of extension/ whose manifest also grants the test page's origin as a host permission. The shipped manifest asks
 * only activeTab, which Chrome grants when a person clicks the toolbar icon, and Playwright cannot click it; so the e2e
 * permission set differs from the shipped one on purpose (the user chose this). extension/ itself is never written.
 */
async function testCopyOfExtension(): Promise<string> {
  const copy = await mkdtemp(path.join(os.tmpdir(), 'pinround-extension-'));
  await cp(SHIPPED_EXTENSION, copy, { recursive: true, filter: (source) => path.basename(source) !== 'tests' });
  const manifest = JSON.parse(await readFile(path.join(SHIPPED_EXTENSION, 'manifest.json'), 'utf8')) as Record<string, unknown>;
  await writeFile(path.join(copy, 'manifest.json'), JSON.stringify({ ...manifest, key: TEST_MANIFEST_KEY, host_permissions: [`${TEST_PAGE_ORIGIN}/*`] }));
  return copy;
}

interface ExtensionFixtures {
  context: BrowserContext;
  testPage: Page;
}

interface ExtensionWorkerFixtures {
  extensionDirectory: string;
}

/**
 * Each test gets a fresh Chromium profile with the test copy of the extension loaded and the test page answered on
 * TEST_PAGE_ORIGIN; `testPage` is a tab already on it.
 */
export const test = base.extend<ExtensionFixtures, ExtensionWorkerFixtures>({
  extensionDirectory: [
    async ({}, use) => {
      const directory = await testCopyOfExtension();
      await use(directory);
      await rm(directory, { recursive: true, force: true });
    },
    { scope: 'worker' },
  ],
  context: async ({ extensionDirectory }, use) => {
    const context = await chromium.launchPersistentContext('', {
      channel: 'chromium',
      args: [`--disable-extensions-except=${extensionDirectory}`, `--load-extension=${extensionDirectory}`],
    });
    await context.route(`${TEST_PAGE_ORIGIN}/**`, (route) =>
      new URL(route.request().url()).pathname === '/' ? route.fulfill({ path: TEST_PAGE_FILE, contentType: 'text/html' }) : route.fulfill({ status: 404 }),
    );
    await use(context);
    await context.close();
  },
  testPage: async ({ context }, use) => {
    const page = await context.newPage();
    await page.goto(TEST_PAGE_URL);
    await use(page);
  },
});

export { expect } from '@playwright/test';

/**
 * Opens the extension's popup page in a tab of its own while `tab` stays the active tab, so the popup's
 * chrome.tabs.query({ active: true, currentWindow: true }) finds `tab` as it does when a person clicks the icon there.
 */
export async function openPopupFor(context: BrowserContext, tab: Page): Promise<Page> {
  const popup = await context.newPage();
  await tab.bringToFront();
  await popup.goto(`chrome-extension://${EXTENSION_ID}/popup.html`);
  return popup;
}

/**
 * The part of the chrome API the specs call inside an extension page's evaluate, as
 * `(globalThis as unknown as ExtensionPageGlobals).chrome`; the e2e package has no Chrome types.
 */
export interface ExtensionPageGlobals {
  chrome: {
    tabs: { query(queryInfo: { url?: string; active?: boolean; currentWindow?: boolean }): Promise<Array<{ id?: number; url?: string }>> };
    scripting: { executeScript<Result>(injection: { target: { tabId: number }; func: () => Result }): Promise<Array<{ result?: Awaited<Result> }>> };
  };
}

/** The chrome tab id of the tab at `url`, asked from an extension page (the test manifest lets it see the test page's address). */
export async function tabIdOf(extensionPage: Page, url: string): Promise<number> {
  const tabIds = await extensionPage.evaluate(async (wanted) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    const tabs = await chrome.tabs.query({ url: wanted });
    return tabs.map((tab) => tab.id);
  }, url);
  if (tabIds.length !== 1 || undefined === tabIds[0]) {
    throw new Error(`Expected one tab at ${url}, found ${tabIds.length}.`);
  }
  return tabIds[0];
}
