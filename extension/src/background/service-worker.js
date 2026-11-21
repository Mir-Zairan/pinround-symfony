// The extension's service worker: it answers the dock on a page (dock-requests.js). Chrome sends it only messages from
// this extension's own pages and scripts; a web page cannot reach it without externally_connectable, which is not set.
import { browser } from '../shared/browser.js';
import { answerDockRequest, isDockRequest } from './dock-requests.js';

browser.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (!isDockRequest(message) || undefined === sender.tab) {
        return false;
    }
    answerDockRequest(message, sender.tab, { browser, navigator }).then(sendResponse);

    // Keeps the reply channel open until the answer is sent.
    return true;
});
