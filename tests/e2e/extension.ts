import { test as base, chromium, expect, type BrowserContext, type CDPSession, type Page } from '@playwright/test';
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
 * A copy of extension/ whose manifest also grants `hostPermissions`. The shipped manifest asks only activeTab, which
 * Chrome grants when a person clicks the toolbar icon, and Playwright cannot click it; so the test copy grants the test
 * page's origin instead. extension/ itself is never written.
 */
async function testCopyOfExtension(hostPermissions: string[]): Promise<string> {
  const copy = await mkdtemp(path.join(os.tmpdir(), 'pinround-extension-'));
  await cp(SHIPPED_EXTENSION, copy, { recursive: true, filter: (source) => path.basename(source) !== 'tests' });
  const manifest = JSON.parse(await readFile(path.join(SHIPPED_EXTENSION, 'manifest.json'), 'utf8')) as Record<string, unknown>;
  await writeFile(path.join(copy, 'manifest.json'), JSON.stringify({ ...manifest, key: TEST_MANIFEST_KEY, host_permissions: hostPermissions }));
  return copy;
}

interface ExtensionFixtures {
  extensionHostPermissions: string[];
  viewportFollowsWindow: boolean;
  screenDeviceScaleFactor: number;
  extensionDirectory: string;
  context: BrowserContext;
  testPage: Page;
}

/**
 * Each test gets a fresh Chromium profile with a test copy of the extension loaded and the test page answered on
 * TEST_PAGE_ORIGIN; `testPage` is a tab already on it. `extensionHostPermissions` is the copy's host permissions: the
 * test page's origin by default; `test.use({ extensionHostPermissions: CAPTURE_HOST_PERMISSIONS })` for a capture.
 * `viewportFollowsWindow` drops Playwright's fixed viewport, for a spec that resizes the window (resizeWindowOf).
 * `screenDeviceScaleFactor` is the screen's devicePixelRatio, which captureVisibleTab's picture follows; Playwright's
 * fixed viewport emulates a ratio of 1 over it, so a spec that sets it also sets `viewportFollowsWindow`.
 */
export const test = base.extend<ExtensionFixtures>({
  extensionHostPermissions: [[`${TEST_PAGE_ORIGIN}/*`], { option: true }],
  viewportFollowsWindow: [false, { option: true }],
  screenDeviceScaleFactor: [1, { option: true }],
  extensionDirectory: async ({ extensionHostPermissions }, use) => {
    const directory = await testCopyOfExtension(extensionHostPermissions);
    await use(directory);
    await rm(directory, { recursive: true, force: true });
  },
  context: async ({ extensionDirectory, viewportFollowsWindow, screenDeviceScaleFactor }, use) => {
    const context = await chromium.launchPersistentContext('', {
      channel: 'chromium',
      args: [`--disable-extensions-except=${extensionDirectory}`, `--load-extension=${extensionDirectory}`, `--force-device-scale-factor=${screenDeviceScaleFactor}`],
      ...(viewportFollowsWindow ? { viewport: null } : {}),
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

export { expect };

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
 * Host permissions for a test copy that captures: chrome.tabs.captureVisibleTab needs an activeTab grant or <all_urls>,
 * and a single-site host permission is refused. Every web page is then in reach, so a spec that proves a page is out of
 * reach keeps the default.
 */
export const CAPTURE_HOST_PERMISSIONS = ['<all_urls>'];

/**
 * What `tab` shows, as the PNG data URL chrome.tabs.captureVisibleTab answers the popup page opened for it. Chrome
 * captures the active tab of the popup's window, which openPopupFor keeps on `tab`. The spec needs
 * `test.use({ extensionHostPermissions: CAPTURE_HOST_PERMISSIONS })`.
 */
export async function captureVisibleTabOf(context: BrowserContext, tab: Page): Promise<string> {
  const popup = await openPopupFor(context, tab);
  return popup.evaluate(async () => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    return chrome.tabs.captureVisibleTab({ format: 'png' });
  });
}

/**
 * The part of the chrome API the specs call inside an extension page's evaluate, as
 * `(globalThis as unknown as ExtensionPageGlobals).chrome`; the e2e package has no Chrome types.
 */
export interface ExtensionPageGlobals {
  chrome: {
    tabs: {
      query(queryInfo: { url?: string; active?: boolean; currentWindow?: boolean }): Promise<Array<{ id?: number; url?: string }>>;
      captureVisibleTab(options: { format: 'png' | 'jpeg' }): Promise<string>;
    };
    scripting: {
      executeScript<Result>(injection: { target: { tabId: number }; func: (...args: never[]) => Result; args?: unknown[] }): Promise<Array<{ documentId?: string; result?: Awaited<Result> }>>;
      executeScript(injection: { target: { tabId: number }; files: string[] }): Promise<Array<{ result?: unknown }>>;
    };
    storage: {
      local: { set(items: Record<string, unknown>): Promise<void>; get(keys: string | string[] | null): Promise<Record<string, unknown>> };
      session: { get(keys: string | string[] | null): Promise<Record<string, unknown>> };
    };
  };
}

/**
 * Resizes the window `tab` is in, as a person drags its edge, and waits until the page has the new size.
 * page.setViewportSize only emulates a size, and captureVisibleTab still captures the window's own, so a spec that
 * captures after a resize resizes the window instead, with `test.use({ viewportFollowsWindow: true })`.
 */
export async function resizeWindowOf(context: BrowserContext, tab: Page, size: { width: number; height: number }): Promise<void> {
  const viewportBefore = await tab.evaluate(() => [window.innerWidth, window.innerHeight]);
  const session = await context.newCDPSession(tab);
  const { windowId } = await session.send('Browser.getWindowForTarget');
  await session.send('Browser.setWindowBounds', { windowId, bounds: { ...size, windowState: 'normal' } });
  await session.detach();
  await expect.poll(() => tab.evaluate(() => [window.innerWidth, window.innerHeight])).not.toEqual(viewportBefore);
}

/** A pinround the specs answer with context.route; no app runs behind it. */
export const PINROUND_TEST_ORIGIN = 'https://pinround.acme-foods.test';

/**
 * The popup opened for `tab` (openPopupFor), paired with PINROUND_TEST_ORIGIN as the options page pairs it, so it
 * shows the capture form. The spec answers that origin's API with context.route before it calls this.
 */
export async function pairedPopupFor(context: BrowserContext, tab: Page): Promise<Page> {
  const popup = await openPopupFor(context, tab);
  await popup.evaluate(async (origin) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    await chrome.storage.local.set({ pinround: { origin, token: 'pr_test0000000000000000extension' } });
  }, PINROUND_TEST_ORIGIN);
  await popup.reload();
  return popup;
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

/** A node of the page's accessibility tree; `text` is the text of its descendants, for a role that takes no name from it. */
export interface AccessibleNode {
  role: string;
  name: string;
  description: string;
  value: string;
  text: string;
  isFocused: boolean;
  backendNodeId: number | undefined;
}

/** An element in a shadow root, as DevTools lists it. */
export interface ShadowElement {
  tagName: string;
  className: string;
  backendNodeId: number;
}

export interface Box {
  left: number;
  top: number;
  right: number;
  bottom: number;
}

const boxOfQuad = (quad: number[]): Box => ({ left: quad[0], top: quad[1], right: quad[4], bottom: quad[5] });

/**
 * The page as Chrome's DevTools read it, inside closed shadow roots too, which Playwright's locators do not enter: the
 * accessibility tree a screen reader gets, the elements of a shadow root and their boxes, and script run in the page's
 * own world on one of those elements, as the page's script would run it if it held that element.
 */
export class PageInspector {
  private constructor(private readonly session: CDPSession) {}

  static async of(context: BrowserContext, page: Page): Promise<PageInspector> {
    return new PageInspector(await context.newCDPSession(page));
  }

  /** Every node a screen reader gets, in tree order; ignored nodes are left out. */
  async accessibleNodes(): Promise<AccessibleNode[]> {
    const { nodes } = await this.session.send('Accessibility.getFullAXTree');
    const byId = new Map(nodes.map((node) => [node.nodeId, node]));
    const textOf = (nodeId: string): string => {
      const node = byId.get(nodeId);
      if (undefined === node) {
        return '';
      }
      if (node.role?.value === 'StaticText') {
        return String(node.name?.value ?? '');
      }
      return (node.childIds ?? []).map(textOf).join('');
    };
    return nodes.filter((node) => !node.ignored).map((node) => ({
      role: String(node.role?.value ?? ''),
      name: String(node.name?.value ?? ''),
      description: String(node.description?.value ?? ''),
      value: String(node.value?.value ?? ''),
      text: textOf(node.nodeId),
      isFocused: (node.properties ?? []).some((property) => property.name === 'focused' && property.value.value === true),
      backendNodeId: node.backendDOMNodeId,
    }));
  }

  /** The role and name of the node that has the keyboard focus, or null when the page itself has it. */
  async focused(): Promise<{ role: string; name: string } | null> {
    const node = (await this.accessibleNodes()).findLast((candidate) => candidate.isFocused && candidate.role !== 'RootWebArea');
    return undefined === node ? null : { role: node.role, name: node.name };
  }

  /** The one node with this role and exact name; throws when there is none or more than one. */
  async node(role: string, name: string): Promise<AccessibleNode> {
    const found = (await this.accessibleNodes()).filter((node) => node.role === role && node.name === name);
    if (found.length !== 1) {
      throw new Error(`Expected one ${role} named "${name}", found ${found.length}.`);
    }
    return found[0];
  }

  /** The elements in the shadow root of the first element named `hostTagName`, open or closed, in tree order. */
  async shadowElementsOf(hostTagName: string): Promise<ShadowElement[]> {
    const { root } = await this.session.send('DOM.getDocument', { depth: -1, pierce: true });
    type DomNode = typeof root;
    const descendants = (node: DomNode): DomNode[] => [...(node.shadowRoots ?? []), ...(node.children ?? [])].flatMap((child) => [child, ...descendants(child)]);
    const host = descendants(root).find((node) => node.localName === hostTagName);
    if (undefined === host?.shadowRoots?.[0]) {
      return [];
    }
    return descendants(host.shadowRoots[0]).filter((node) => node.nodeType === 1).map((node) => {
      const attributes = node.attributes ?? [];
      const classAt = attributes.indexOf('class');
      return { tagName: node.localName, className: classAt === -1 ? '' : attributes[classAt + 1], backendNodeId: node.backendNodeId };
    });
  }

  /** The element's border and padding boxes in CSS pixels of the viewport, or null when it is not rendered. */
  async boxesOf(backendNodeId: number): Promise<{ border: Box; padding: Box } | null> {
    try {
      const { model } = await this.session.send('DOM.getBoxModel', { backendNodeId });
      return { border: boxOfQuad(model.border), padding: boxOfQuad(model.padding) };
    } catch (error) {
      if (error instanceof Error && error.message.includes('Could not compute box model')) {
        return null;
      }
      throw error;
    }
  }

  /**
   * Runs `functionDeclaration` with `this` as the element, in the page's own world: what the page's script could do
   * with it. Answers what the function returns, as JSON carries it.
   */
  async runAsPageOn(backendNodeId: number, functionDeclaration: string): Promise<unknown> {
    const { object } = await this.session.send('DOM.resolveNode', { backendNodeId });
    if (undefined === object.objectId) {
      throw new Error(`Node ${backendNodeId} did not resolve to an object.`);
    }
    const { result, exceptionDetails } = await this.session.send('Runtime.callFunctionOn', { objectId: object.objectId, functionDeclaration, returnByValue: true });
    if (undefined !== exceptionDetails) {
      throw new Error(`The page's script threw: ${exceptionDetails.exception?.description ?? exceptionDetails.text}`);
    }
    return result.value;
  }
}
