// The offscreen document's script (offscreen.html): it makes the requests the service worker sends it
// (extension-page-fetch.js). Of the extension API, Chrome gives an offscreen document the runtime only.
import { browser } from '../shared/browser.js';
import { answerExtensionPageFetch } from './extension-page-fetch.js';

browser.runtime.onMessage.addListener((message, sender, sendResponse) => {
    const answer = answerExtensionPageFetch(message, sender, { extensionId: browser.runtime.id() });
    if (null === answer) {
        return false;
    }
    answer.then(sendResponse);

    // Keeps the reply channel open until the answer is sent.
    return true;
});
