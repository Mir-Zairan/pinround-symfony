// A stand-in for src/shared/browser.js in node --test, with the rules of the real chrome API the extension relies on:
// storage answers {} for a missing key and copies values both ways; activeTab reaches only the tab whose icon was
// clicked, until it navigates; a host permission reaches its origin; captureVisibleTab and executeScript refuse with
// Chrome's own messages. One window only.

export const CANNOT_ACCESS_PAGE = 'Cannot access contents of the page. Extension manifest must request permission to access the respective host.';
const NEEDS_ACTIVE_TAB = "Either the '<all_urls>' or 'activeTab' permission is required.";
const FUNC_OR_FILES = "Exactly one of 'func' and 'files' must be specified.";

const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
const EMPTY_JPEG = '/9j/2Q==';

function fakeStorageArea() {
    const kept = new Map();

    return {
        async get(keys) {
            if (null === keys || undefined === keys) {
                return Object.fromEntries([...kept].map(([key, value]) => [key, structuredClone(value)]));
            }
            if ('string' === typeof keys || Array.isArray(keys)) {
                const wanted = [keys].flat().filter((key) => kept.has(key));

                return Object.fromEntries(wanted.map((key) => [key, structuredClone(kept.get(key))]));
            }
            if ('object' === typeof keys) {
                return Object.fromEntries(Object.entries(keys).map(([key, fallback]) => [key, structuredClone(kept.has(key) ? kept.get(key) : fallback)]));
            }
            throw new TypeError('Error in invocation of storage.get(optional [string|array|object] keys): No matching signature.');
        },
        async set(items) {
            for (const [key, value] of Object.entries(structuredClone(items))) {
                kept.set(key, value);
            }
        },
        async remove(keys) {
            for (const key of [keys].flat()) {
                kept.delete(key);
            }
        },
    };
}

/**
 * @param {{tabs?: Array<{id: number, url: string, title?: string, active?: boolean}>, hostOrigins?: string[]}} options
 *   the open tabs, at most one of them active, and the origins the manifest grants as host permissions
 */
export function fakeBrowser({ tabs = [], hostOrigins = [] } = {}) {
    const openTabs = tabs.map((tab) => ({ active: false, ...structuredClone(tab) }));
    const hostOriginSet = new Set(hostOrigins);
    const activeTabGrants = new Set();
    const injectedFiles = [];
    let nextTabId = Math.max(0, ...openTabs.map((tab) => tab.id)) + 1;
    let optionsPageOpenings = 0;

    const tabOf = (tabId) => openTabs.find((tab) => tab.id === tabId);
    const mayReach = (tab) => activeTabGrants.has(tab.id) || hostOriginSet.has(new URL(tab.url).origin);
    const activate = (tabId) => openTabs.forEach((tab) => { tab.active = tab.id === tabId; });
    const asSeen = (tab) => {
        if (mayReach(tab)) {
            return structuredClone(tab);
        }
        const { url, title, ...withoutAddress } = tab;

        return structuredClone(withoutAddress);
    };

    return {
        storage: { local: fakeStorageArea(), session: fakeStorageArea() },
        tabs: {
            async query(queryInfo) {
                const unmodelled = Object.keys(queryInfo).filter((key) => !['active', 'currentWindow', 'lastFocusedWindow'].includes(key));
                if (unmodelled.length > 0) {
                    throw new Error(`The fake browser does not model the query key ${unmodelled.join(', ')}.`);
                }

                return openTabs.filter((tab) => undefined === queryInfo.active || tab.active === queryInfo.active).map(asSeen);
            },
            async captureVisibleTab(options = {}) {
                const active = openTabs.find((tab) => tab.active);
                if (undefined === active || !mayReach(active)) {
                    throw new Error(NEEDS_ACTIVE_TAB);
                }

                return 'png' === options.format ? `data:image/png;base64,${ONE_PIXEL_PNG}` : `data:image/jpeg;base64,${EMPTY_JPEG}`;
            },
            async create({ url, active = true }) {
                const tab = { id: nextTabId, url, active: false };
                nextTabId += 1;
                openTabs.push(tab);
                if (active) {
                    activate(tab.id);
                }

                return asSeen(tab);
            },
        },
        runtime: {
            async openOptionsPage() {
                optionsPageOpenings += 1;
            },
        },
        scripting: {
            async executeScript({ target, func, args = [], files }) {
                if ((undefined === func) === (undefined === files)) {
                    throw new Error(FUNC_OR_FILES);
                }
                const tab = tabOf(target.tabId);
                if (undefined === tab) {
                    throw new Error(`No tab with id: ${target.tabId}.`);
                }
                if (!mayReach(tab)) {
                    throw new Error(CANNOT_ACCESS_PAGE);
                }
                if (undefined !== files) {
                    injectedFiles.push({ tabId: tab.id, files: [...files] });

                    return [{ documentId: `document-${tab.id}`, frameId: 0, result: null }];
                }
                const result = await func(...structuredClone(args));

                return [{ documentId: `document-${tab.id}`, frameId: 0, result: structuredClone(result) }];
            },
        },

        /** What clicking the extension's icon on the tab does: the tab becomes the active one and activeTab reaches it. */
        clickIcon(tabId) {
            activate(tabId);
            activeTabGrants.add(tabId);
        },
        /** A navigation in the tab; it ends the activeTab grant, a host permission still counts for the new address. */
        navigate(tabId, url) {
            tabOf(tabId).url = url;
            activeTabGrants.delete(tabId);
        },
        tabUrl: (tabId) => tabOf(tabId)?.url,
        injectedFiles,
        get optionsPageOpenings() {
            return optionsPageOpenings;
        },
    };
}
