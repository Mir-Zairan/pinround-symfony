// A stand-in for src/shared/browser.js in node --test, with the rules of the real chrome API the extension relies on.
// Proved in real Chromium by tests/e2e/extension-harness.spec.ts: values cross storage and executeScript as Chrome
// converts them (copyAcrossChrome); executeScript runs `func` from its source text in a page scope of its own, where a
// throw or a rejection answers null and globals last until a navigation; a call with arguments of the wrong shape
// throws at once, any other refusal rejects, each with Chrome's message; a host permission reaches its origin and no
// other; a host permission alone does not let captureVisibleTab capture, <all_urls> does; a chrome:// page refuses the
// capture and the script and hides its address. Proved by tests/e2e/extension-overlay.spec.ts: `files` are found from
// the extension's root as refusalOfInjectedFile reads them, each refusal with Chrome's message. Only documented:
// storage answers {} for a missing key; and, as Playwright cannot click the toolbar icon that grants it, activeTab
// reaches only the tab whose icon was clicked and keeps it after a navigation within the origin but not to another. A
// `files` injection is recorded, not run: the page scope has no document. Only documented too: each window has one
// active tab; captureVisibleTab takes an optional window id before its options, captures that window's active tab
// (the last focused window's when none is given) and rejects a window id that names no window; windows.getLastFocused
// answers the window the person was last in, which an extension popup does not change. Proved by
// tests/e2e/extension-player.spec.ts: tabs.get and tabs.update answer the tab with Chrome's status, and update's with the
// old url and the new one as pendingUrl until the load ends, with the old document still in reach until the new page
// commits (its activeTab grant too, only documented); a missing tab rejects, an id that is no integer throws at
// once; a func still running when its document navigates away answers null with that document's id. Not modelled:
// Chrome's "Frame with ID 0 was removed." refusal of an injection into a page that is leaving, which comes and goes
// with timing; a test that needs it wraps executeScript.

import { statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

export const ALL_URLS = '<all_urls>';
export const FIRST_WINDOW_ID = 1;
export const CANNOT_ACCESS_PAGE = 'Cannot access contents of the page. Extension manifest must request permission to access the respective host.';
export const CANNOT_ACCESS_CHROME_URL = 'Cannot access a chrome:// URL';
export const NEEDS_ACTIVE_TAB = "Either the '<all_urls>' or 'activeTab' permission is required.";
export const ACTIVE_TAB_NOT_IN_EFFECT = "The 'activeTab' permission is not in effect because this extension has not been in invoked.";
const FUNC_OR_FILES = "Exactly one of 'func' and 'files' must be specified";
const EXECUTE_SCRIPT_ARGS = "Error in invocation of scripting.executeScript(scripting.ScriptInjection injection, optional function callback): Error at parameter 'injection': Error at property 'args'";
const BINARY_ARGUMENT = 'Unserializable argument passed.';
const BINARY_IN_STORAGE = 'Cannot serialize value to JSON';

const EXTENSION_ROOT = fileURLToPath(new URL('../', import.meta.url));
const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
const EMPTY_JPEG = '/9j/2Q==';

const LEFT_OUT = Symbol('left out');
const isLeftOut = (value) => ['undefined', 'function', 'symbol', 'bigint'].includes(typeof value) || ('number' === typeof value && !Number.isFinite(value));

// Chrome checks the shape of a call's arguments before the call starts and throws; what fails later rejects.
const answerLater = (work) => Promise.resolve().then(work);

/**
 * A value as it comes out of Chrome's extension boundary: undefined, functions, symbols, bigints and non-finite numbers
 * are left out of an object and become null in an array; any other object (Date, Map, Error, RegExp) becomes a plain
 * object of its own enumerable properties, with no toJSON call; keys come back sorted; -0 becomes 0.
 *
 * @param {{binaryRefusal?: string, cycle: null|symbol}} rules what a typed array or ArrayBuffer does (refuse with this
 *   message, or become {} when unset), and what a reference back to an enclosing object becomes
 * @returns {unknown} the copy, or LEFT_OUT
 */
function copyAcrossChrome(value, rules, enclosing = []) {
    if (isLeftOut(value)) {
        return LEFT_OUT;
    }
    if ('number' === typeof value) {
        return value + 0;
    }
    if (null === value || 'object' !== typeof value) {
        return value;
    }
    if (enclosing.includes(value)) {
        return rules.cycle;
    }
    if (ArrayBuffer.isView(value) || '[object ArrayBuffer]' === Object.prototype.toString.call(value)) {
        if (undefined !== rules.binaryRefusal) {
            throw new Error(rules.binaryRefusal);
        }

        return {};
    }
    const inside = [...enclosing, value];
    if (Array.isArray(value)) {
        return Array.from(value, (entry) => {
            const copy = copyAcrossChrome(entry, rules, inside);

            return LEFT_OUT === copy ? null : copy;
        });
    }

    return Object.fromEntries(Object.entries(value)
        .map(([key, entry]) => [key, copyAcrossChrome(entry, rules, inside)])
        .filter(([, copy]) => LEFT_OUT !== copy)
        .sort(byKey));
}

function byKey([oneKey], [otherKey]) {
    if (oneKey === otherKey) {
        return 0;
    }

    return oneKey < otherKey ? -1 : 1;
}

const STORED = { binaryRefusal: BINARY_IN_STORAGE, cycle: null };
const SCRIPT_ARGUMENT = { binaryRefusal: BINARY_ARGUMENT, cycle: LEFT_OUT };
const SCRIPT_RESULT = { cycle: null };

function fakeStorageArea() {
    const kept = new Map();
    const noMatchingSignature = (signature) => new TypeError(`Error in invocation of storage.${signature}: No matching signature.`);

    return {
        get(keys) {
            if (!['undefined', 'string', 'object'].includes(typeof keys)) {
                throw noMatchingSignature('get(optional [string|array|object] keys, optional function callback)');
            }

            const answerOf = (entries) => Object.fromEntries(entries.sort(byKey));

            return answerLater(() => {
                if (null === keys || undefined === keys) {
                    return answerOf([...kept].map(([key, value]) => [key, structuredClone(value)]));
                }
                if ('string' === typeof keys || Array.isArray(keys)) {
                    const wanted = [keys].flat().filter((key) => kept.has(key));

                    return answerOf(wanted.map((key) => [key, structuredClone(kept.get(key))]));
                }
                const fallbacks = copyAcrossChrome(keys, STORED);

                return answerOf(Object.entries(fallbacks).map(([key, fallback]) => [key, kept.has(key) ? structuredClone(kept.get(key)) : fallback]));
            });
        },
        set(items) {
            if (null === items || 'object' !== typeof items) {
                throw noMatchingSignature('set(object items, optional function callback)');
            }

            return answerLater(() => {
                for (const [key, value] of Object.entries(copyAcrossChrome(items, STORED))) {
                    kept.set(key, value);
                }
            });
        },
        remove(keys) {
            if ('string' !== typeof keys && !Array.isArray(keys)) {
                throw noMatchingSignature('remove([string|array] keys, optional function callback)');
            }

            return answerLater(() => {
                for (const key of [keys].flat()) {
                    kept.delete(key);
                }
            });
        },
    };
}

/** Chrome's own check of executeScript's args, which throws before the call starts. */
function checkScriptArguments(args) {
    if (!Array.isArray(args)) {
        throw new TypeError(`${EXECUTE_SCRIPT_ARGS}: Invalid type: expected array, found ${null === args ? 'null' : typeof args}.`);
    }
    const unserializable = args.findIndex(isLeftOut);
    if (-1 !== unserializable) {
        throw new TypeError(`${EXECUTE_SCRIPT_ARGS}: Error at index ${unserializable}: Value is unserializable.`);
    }
}

/**
 * Chrome's refusal of a file to inject, or null when it loads. The path is read from the extension's root after one
 * leading slash is dropped; a path that does not name something there, also through `..`, a second slash or a trailing
 * one, is not found; something found that is not a .js file, a folder too, is refused as no script.
 */
function refusalOfInjectedFile(file) {
    const relative = file.replace(/^\//, '');
    const notFound = `Could not load file: '${file}'.`;
    if (relative.startsWith('/') || relative.endsWith('/') || relative.split('/').includes('..')) {
        return notFound;
    }
    const found = statSync(path.join(EXTENSION_ROOT, relative), { throwIfNoEntry: false });
    if (undefined === found) {
        return notFound;
    }
    if (!relative.endsWith('.js')) {
        return `Could not load file '${file}' for content script, content scripts can only be loaded from supported JavaScript files such as .js files.`;
    }

    return found.isFile() ? null : notFound;
}

const isChromeUrl = (url) => url.startsWith('chrome://');
const isWebUrl = (url) => ['http:', 'https:'].includes(new URL(url).protocol);

/**
 * @param {{tabs?: Array<{id: number, url: string, title?: string, active?: boolean, status?: string, windowId?: number}>, hostOrigins?: string[]}} options
 *   the open tabs, at most one of them active in each window (FIRST_WINDOW_ID unless windowId says otherwise), each
 *   loaded unless its status says 'loading', and the manifest's host permissions: origins such as
 *   'https://shop.acme-foods.example', or ALL_URLS for every web page. The person is in the first tab's window.
 */
export function fakeBrowser({ tabs = [], hostOrigins = [] } = {}) {
    const openTabs = tabs.map((tab) => ({ active: false, status: 'complete', windowId: FIRST_WINDOW_ID, ...structuredClone(tab) }));
    let lastFocusedWindowId = openTabs[0]?.windowId ?? FIRST_WINDOW_ID;
    const hostOriginSet = new Set(hostOrigins);
    const hasAllUrls = hostOriginSet.has(ALL_URLS);
    const activeTabGrants = new Set();
    const pageDocuments = new Map();
    const injectedFiles = [];
    const messageListeners = [];
    let nextTabId = Math.max(0, ...openTabs.map((tab) => tab.id)) + 1;
    let documentsLoaded = 0;
    let optionsPageOpenings = 0;

    const tabOf = (tabId) => openTabs.find((tab) => tab.id === tabId);
    const mayReach = (tab) => !isChromeUrl(tab.url)
        && (activeTabGrants.has(tab.id) || hostOriginSet.has(new URL(tab.url).origin) || (hasAllUrls && isWebUrl(tab.url)));
    const activate = (tabId) => {
        const { windowId } = tabOf(tabId);
        openTabs.filter((tab) => tab.windowId === windowId).forEach((tab) => { tab.active = tab.id === tabId; });
        lastFocusedWindowId = windowId;
    };
    const isInWindowQueried = (tab, queryInfo) => !(true === queryInfo.currentWindow || true === queryInfo.lastFocusedWindow) || tab.windowId === lastFocusedWindowId;
    const asSeen = (tab) => {
        if (mayReach(tab)) {
            return structuredClone(tab);
        }
        const { url, pendingUrl, title, ...withoutAddress } = tab;

        return structuredClone(withoutAddress);
    };
    // The page's globals last as long as its document: one injection can leave a value for the next until the tab
    // navigates.
    const documentOf = (tabId) => {
        if (!pageDocuments.has(tabId)) {
            documentsLoaded += 1;
            const globals = vm.createContext({ console });
            vm.runInContext('globalThis.window = globalThis; globalThis.self = globalThis;', globals);
            let leave;
            const left = new Promise((resolve) => {
                leave = resolve;
            });
            pageDocuments.set(tabId, { documentId: `document-${documentsLoaded}`, globals, left, leave });
        }

        return pageDocuments.get(tabId);
    };
    const leaveDocument = (tabId) => {
        pageDocuments.get(tabId)?.leave(LEFT_OUT);
        pageDocuments.delete(tabId);
    };
    const runInPage = async (pageDocument, func, args) => {
        try {
            const pageFunction = vm.runInContext(`(${func.toString()})`, pageDocument.globals);
            const pageArgs = vm.runInContext('JSON.parse', pageDocument.globals)(JSON.stringify(args));
            const result = copyAcrossChrome(await Promise.race([pageFunction(...pageArgs), pageDocument.left]), SCRIPT_RESULT);

            return LEFT_OUT === result ? null : result;
        } catch {
            return null;
        }
    };
    const startLoading = (tab, url) => {
        if (new URL(url).origin !== new URL(tab.url).origin) {
            activeTabGrants.delete(tab.id);
        }
        tab.status = 'loading';
        leaveDocument(tab.id);
    };
    const existingTab = (tabId) => {
        const tab = tabOf(tabId);
        if (undefined === tab) {
            throw new Error(`No tab with id: ${tabId}.`);
        }

        return tab;
    };
    const checkTabId = (signature, tabId) => {
        if (!Number.isInteger(tabId)) {
            throw new TypeError(`Error in invocation of tabs.${signature}: No matching signature.`);
        }
    };

    return {
        storage: { local: fakeStorageArea(), session: fakeStorageArea() },
        tabs: {
            async query(queryInfo) {
                const unmodelled = Object.keys(queryInfo).filter((key) => !['active', 'currentWindow', 'lastFocusedWindow'].includes(key));
                if (unmodelled.length > 0) {
                    throw new Error(`The fake browser does not model the query key ${unmodelled.join(', ')}.`);
                }

                return openTabs.filter((tab) => (undefined === queryInfo.active || tab.active === queryInfo.active) && isInWindowQueried(tab, queryInfo)).map(asSeen);
            },
            async captureVisibleTab(windowIdOrOptions, maybeOptions) {
                const hasWindowId = Number.isInteger(windowIdOrOptions);
                const windowId = hasWindowId ? windowIdOrOptions : lastFocusedWindowId;
                const options = (hasWindowId ? maybeOptions : windowIdOrOptions) ?? {};
                if (hasWindowId && !openTabs.some((tab) => tab.windowId === windowId)) {
                    throw new Error(`No window with id: ${windowId}.`);
                }
                const active = openTabs.find((tab) => tab.active && tab.windowId === windowId);
                if (undefined === active || !(activeTabGrants.has(active.id) || hasAllUrls)) {
                    throw new Error(NEEDS_ACTIVE_TAB);
                }
                if (isChromeUrl(active.url)) {
                    throw new Error(ACTIVE_TAB_NOT_IN_EFFECT);
                }

                return 'png' === options.format ? `data:image/png;base64,${ONE_PIXEL_PNG}` : `data:image/jpeg;base64,${EMPTY_JPEG}`;
            },
            async create({ url, active = true }) {
                const tab = { id: nextTabId, url, active: false, windowId: lastFocusedWindowId };
                nextTabId += 1;
                openTabs.push(tab);
                if (active) {
                    activate(tab.id);
                }

                return asSeen(tab);
            },
            get(tabId) {
                checkTabId('get(integer tabId, optional function callback)', tabId);

                return answerLater(() => asSeen(existingTab(tabId)));
            },
            /** Navigates to properties.url, the only property modelled; the old document stays until finishLoading. */
            update(tabId, properties) {
                checkTabId('update(optional integer tabId, object updateProperties, optional function callback)', tabId);
                const unmodelled = Object.keys(properties ?? {}).filter((key) => 'url' !== key);
                if (unmodelled.length > 0 || 'string' !== typeof properties?.url) {
                    throw new Error(`The fake browser models tabs.update with a url only, not ${unmodelled.join(', ') || 'without one'}.`);
                }

                return answerLater(() => {
                    const tab = existingTab(tabId);
                    tab.status = 'loading';
                    tab.pendingUrl = properties.url;

                    return asSeen(tab);
                });
            },
        },
        windows: {
            async getLastFocused() {
                return { id: lastFocusedWindowId, focused: true };
            },
        },
        runtime: {
            async openOptionsPage() {
                optionsPageOpenings += 1;
            },
            onMessage: {
                addListener(listener) {
                    messageListeners.push(listener);
                },
            },
        },
        scripting: {
            executeScript({ target, func, args = [], files }) {
                checkScriptArguments(args);

                return answerLater(async () => {
                    if ((undefined === func) === (undefined === files)) {
                        throw new Error(FUNC_OR_FILES);
                    }
                    const pageArgs = args.map((argument) => copyAcrossChrome(argument, SCRIPT_ARGUMENT));
                    const tab = tabOf(target.tabId);
                    if (undefined === tab) {
                        throw new Error(`No tab with id: ${target.tabId}`);
                    }
                    if (isChromeUrl(tab.url)) {
                        throw new Error(CANNOT_ACCESS_CHROME_URL);
                    }
                    if (!mayReach(tab)) {
                        throw new Error(CANNOT_ACCESS_PAGE);
                    }
                    const pageDocument = documentOf(tab.id);
                    if (undefined !== files) {
                        const refusal = files.map(refusalOfInjectedFile).find((fileRefusal) => null !== fileRefusal);
                        if (undefined !== refusal) {
                            throw new Error(refusal);
                        }
                        injectedFiles.push({ tabId: tab.id, files: [...files] });

                        return [{ documentId: pageDocument.documentId, frameId: 0, result: null }];
                    }

                    return [{ documentId: pageDocument.documentId, frameId: 0, result: await runInPage(pageDocument, func, pageArgs) }];
                });
            },
        },

        /** What clicking the extension's icon on the tab does: the tab becomes the active one and activeTab reaches it. */
        clickIcon(tabId) {
            if (isChromeUrl(tabOf(tabId).url)) {
                throw new Error('The fake browser does not model activeTab on a chrome:// page.');
            }
            activate(tabId);
            activeTabGrants.add(tabId);
        },
        /** The person clicks the tab's title: it becomes the active one of its window, and that window the focused one. */
        showTab(tabId) {
            activate(tabId);
        },
        /** A link's or form's navigation: a new document, loading until finishLoading; leaving the origin ends activeTab. */
        navigate(tabId, url) {
            const tab = tabOf(tabId);
            startLoading(tab, url);
            tab.url = url;
        },
        /** The load ends and a tabs.update's page commits: its url is the new one, as startLoading leaves the old. */
        finishLoading(tabId) {
            const tab = tabOf(tabId);
            if (undefined !== tab.pendingUrl) {
                startLoading(tab, tab.pendingUrl);
                tab.url = tab.pendingUrl;
                delete tab.pendingUrl;
            }
            tab.status = 'complete';
        },
        tabUrl: (tabId) => tabOf(tabId)?.url,
        injectedFiles,
        /** The listeners added with runtime.onMessage.addListener, in order; a test calls them as Chrome would. */
        messageListeners,
        get optionsPageOpenings() {
            return optionsPageOpenings;
        },
    };
}
