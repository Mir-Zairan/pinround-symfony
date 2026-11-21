// The only module that calls the chrome API: the rest of the extension takes `browser`, so node --test can hand it
// tests/fake-browser.mjs instead. Each call reads the chrome global when it runs, never at import, so the modules load
// in node too.

function storageArea(name) {
    return {
        get: (keys) => chrome.storage[name].get(keys),
        set: (items) => chrome.storage[name].set(items),
        remove: (keys) => chrome.storage[name].remove(keys),
    };
}

export const browser = {
    storage: { local: storageArea('local'), session: storageArea('session') },
    tabs: {
        query: (queryInfo) => chrome.tabs.query(queryInfo),
        captureVisibleTab: (windowId, options) => chrome.tabs.captureVisibleTab(windowId, options),
        create: (properties) => chrome.tabs.create(properties),
        get: (tabId) => chrome.tabs.get(tabId),
        update: (tabId, properties) => chrome.tabs.update(tabId, properties),
    },
    windows: { getLastFocused: () => chrome.windows.getLastFocused() },
    runtime: {
        openOptionsPage: () => chrome.runtime.openOptionsPage(),
        onMessage: { addListener: (listener) => chrome.runtime.onMessage.addListener(listener) },
    },
    scripting: { executeScript: (injection) => chrome.scripting.executeScript(injection) },
};
