import assert from 'node:assert/strict';
import { test } from 'node:test';
import { EXTENSION_PAGE_FETCH, answerExtensionPageFetch, extensionPageFetchOf } from '../src/background/extension-page-fetch.js';
import { upload } from '../src/capture/capture.js';
import { ApiError, NOT_REACHED, NOT_STORED, PinroundApi } from '../src/shared/api.js';
import { FAKE_EXTENSION_ID, NO_RECEIVER, ONE_OFFSCREEN_DOCUMENT, fakeBrowser } from './fake-browser.mjs';
import { PINROUND_ORIGIN, TOKEN, fakePinround } from './fake-pinround.mjs';

const OFFSCREEN_URL = `chrome-extension://${FAKE_EXTENSION_ID}/offscreen.html`;
// A PNG's signature and bytes past 0x7f, which a text round trip would change.
const PICTURE_BYTES = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0xff, 0x80, 0x7f]);

/** The service worker's browser, whose offscreen document makes each request with `fetchFn`, as offscreen-document.js does. */
function workerBrowser(fetchFn) {
    const made = [];
    const browser = fakeBrowser({
        offscreenListener: (message, sender, sendResponse) => {
            const answer = answerExtensionPageFetch(message, sender, {
                extensionId: FAKE_EXTENSION_ID,
                fetchFn: (url, init) => {
                    made.push({ url, init });

                    return fetchFn(url, init);
                },
            });
            if (null === answer) {
                return false;
            }
            answer.then(sendResponse);

            return true;
        },
    });

    return { browser, made };
}

test('the service worker\'s calls and the picture\'s PUT go from the offscreen document, opened once, and pinround gets the same bytes', async () => {
    const pinround = fakePinround();
    const { browser, made } = workerBrowser(pinround.fetchFn);
    const api = new PinroundApi(PINROUND_ORIGIN, TOKEN, extensionPageFetchOf(browser));
    const picture = new Blob([PICTURE_BYTES], { type: 'image/png' });

    const uploadId = await upload(api, { project: 'SHOP' }, picture);

    assert.deepEqual(browser.offscreenDocuments, [OFFSCREEN_URL]);
    assert.deepEqual(made.map(({ url, init }) => [init.method, new URL(url).origin]), [['POST', PINROUND_ORIGIN], ['PUT', new URL(made[1].url).origin]]);
    assert.notEqual(new URL(made[1].url).origin, PINROUND_ORIGIN, 'The PUT goes to the storage pinround named.');
    assert.deepEqual(made.map(({ init }) => [init.credentials, init.redirect]), [['omit', 'error'], ['omit', 'error']]);
    assert.equal(made[0].init.headers.Authorization, `Bearer ${TOKEN}`);
    assert.deepEqual([...pinround.state.storedFiles.get(uploadId)], [...PICTURE_BYTES]);
    assert.equal(made[1].init.body.type, 'image/png');
    assert.equal(browser.sentRuntimeMessages.length, 2);
});

test('an offscreen document already open is used as it is, and one another request opened meanwhile is no error', async () => {
    const answered = async () => new Response('{"ok":true}', { status: 200 });
    const { browser } = workerBrowser(answered);
    const fetchFn = extensionPageFetchOf(browser);
    await fetchFn(`${PINROUND_ORIGIN}/api/v1/me`);
    await fetchFn(`${PINROUND_ORIGIN}/api/v1/me`);
    assert.deepEqual(browser.offscreenDocuments, [OFFSCREEN_URL]);

    const racing = workerBrowser(answered).browser;
    const askedBefore = racing.runtime.getContexts;
    let isFirstAsk = true;
    // Another request opens the document between this one's look and its own createDocument.
    racing.runtime.getContexts = async (filter) => {
        if (isFirstAsk) {
            isFirstAsk = false;
            await racing.offscreen.createDocument({ url: 'offscreen.html', reasons: ['BLOBS'], justification: 'The other request.' });

            return [];
        }

        return askedBefore(filter);
    };
    const response = await extensionPageFetchOf(racing)(`${PINROUND_ORIGIN}/api/v1/me`);
    assert.deepEqual([response.status, await response.json()], [200, { ok: true }]);
    assert.deepEqual(racing.offscreenDocuments, [OFFSCREEN_URL]);
});

test('a request the offscreen document could not make rejects as fetch does: the PUT says the screenshot was not stored, a call that pinround was not reached', async () => {
    const pinround = fakePinround();
    const storageRefuses = async (url, init) => {
        if ('PUT' === init.method) {
            throw new TypeError('Failed to fetch');
        }

        return pinround.fetchFn(url, init);
    };
    const { browser } = workerBrowser(storageRefuses);
    const api = new PinroundApi(PINROUND_ORIGIN, TOKEN, extensionPageFetchOf(browser));

    await assert.rejects(upload(api, { project: 'SHOP' }, new Blob([PICTURE_BYTES])), (error) => error instanceof ApiError && NOT_STORED === error.message && 0 === error.status);

    const unreached = new PinroundApi(PINROUND_ORIGIN, TOKEN, extensionPageFetchOf(workerBrowser(async () => {
        throw new TypeError('Failed to fetch');
    }).browser));
    await assert.rejects(unreached.call('GET', '/api/v1/me/tickets'), (error) => error instanceof ApiError && NOT_REACHED === error.message && 0 === error.status);
});

test('a storage that answers a refusal is a refusal, not a lost request, and a 204 has no body', async () => {
    const pinround = fakePinround();
    const storageRefuses = async (url, init) => ('PUT' === init.method ? new Response('<Error>SignatureDoesNotMatch</Error>', { status: 403 }) : pinround.fetchFn(url, init));
    const api = new PinroundApi(PINROUND_ORIGIN, TOKEN, extensionPageFetchOf(workerBrowser(storageRefuses).browser));

    await assert.rejects(upload(api, { project: 'SHOP' }, new Blob([PICTURE_BYTES])), (error) => error instanceof ApiError && NOT_STORED === error.message && 403 === error.status);

    const noContent = await extensionPageFetchOf(workerBrowser(async () => new Response(null, { status: 204 })).browser)(`${PINROUND_ORIGIN}/api/v1/me`, { method: 'POST', body: '{}' });
    assert.deepEqual([noContent.status, noContent.ok, await noContent.text()], [204, true, '']);
});

test('an offscreen document that cannot be opened rejects, and nothing is sent', async () => {
    const { browser, made } = workerBrowser(async () => new Response('{}', { status: 200 }));
    browser.offscreen.createDocument = async () => {
        throw new Error('The offscreen API is turned off.');
    };

    await assert.rejects(extensionPageFetchOf(browser)(`${PINROUND_ORIGIN}/api/v1/me`), { message: 'The offscreen API is turned off.' });
    assert.deepEqual([browser.sentRuntimeMessages, made], [[], []]);
});

test('an answer that never came, or one of another shape, rejects as a request that got no answer', async () => {
    const noReceiver = fakeBrowser();
    await noReceiver.offscreen.createDocument({ url: 'offscreen.html', reasons: ['BLOBS'], justification: 'Opened, its script failed.' });
    await assert.rejects(noReceiver.runtime.sendMessage({}), { message: NO_RECEIVER });
    await assert.rejects(extensionPageFetchOf(noReceiver)(`${PINROUND_ORIGIN}/api/v1/me`), TypeError);

    for (const answer of [null, 'ok', { status: 200 }, { status: 0, bodyText: '' }, { status: 600, bodyText: '' }, { isUnanswered: true }]) {
        const shaped = fakeBrowser({ offscreenListener: (message, sender, sendResponse) => {
            sendResponse(answer);

            return true;
        } });
        await assert.rejects(extensionPageFetchOf(shaped)(`${PINROUND_ORIGIN}/api/v1/me`), TypeError, JSON.stringify(answer));
    }
});

test('the offscreen document makes requests for this extension\'s own contexts only, and never one from a tab or another extension', async () => {
    let fetches = 0;
    const fetchFn = async () => {
        fetches += 1;

        return new Response('{}', { status: 200 });
    };
    const request = { url: `${PINROUND_ORIGIN}/api/v1/me`, method: 'GET', headers: {} };
    const deps = { extensionId: FAKE_EXTENSION_ID, fetchFn };

    assert.equal(answerExtensionPageFetch({ [EXTENSION_PAGE_FETCH]: request }, { id: FAKE_EXTENSION_ID, tab: { id: 7 } }, deps), null, 'A content script runs in a tab.');
    assert.equal(answerExtensionPageFetch({ [EXTENSION_PAGE_FETCH]: request }, { id: 'another-extension' }, deps), null);
    assert.equal(answerExtensionPageFetch({ pinroundDock: 'state' }, { id: FAKE_EXTENSION_ID }, deps), null);
    assert.equal(answerExtensionPageFetch(null, { id: FAKE_EXTENSION_ID }, deps), null);
    assert.equal(fetches, 0);

    assert.deepEqual(await answerExtensionPageFetch({ [EXTENSION_PAGE_FETCH]: request }, { id: FAKE_EXTENSION_ID }, deps), { status: 200, bodyText: '{}' });
    assert.equal(fetches, 1);
});

test('a page fetch of a shape the offscreen document does not take is not made', async () => {
    let fetches = 0;
    const deps = { extensionId: FAKE_EXTENSION_ID, fetchFn: async () => { fetches += 1; return new Response('{}'); } };
    const good = { url: `${PINROUND_ORIGIN}/api/v1/me`, method: 'GET', headers: {} };

    for (const request of [null, { ...good, url: 7 }, { ...good, method: undefined }, { ...good, headers: { Accept: 1 } }, { ...good, redirect: 1 }, { ...good, bodyText: {} }, { ...good, bodyBase64: '***' }]) {
        assert.deepEqual(await answerExtensionPageFetch({ [EXTENSION_PAGE_FETCH]: request }, { id: FAKE_EXTENSION_ID }, deps), { isUnanswered: true }, JSON.stringify(request));
    }
    assert.equal(fetches, 0);
});

test('a body other than text or a Blob is refused before anything opens', async () => {
    const { browser } = workerBrowser(async () => new Response('{}'));

    await assert.rejects(extensionPageFetchOf(browser)(`${PINROUND_ORIGIN}/api/v1/me`, { method: 'POST', body: new URLSearchParams({ a: 'b' }) }), TypeError);
    assert.deepEqual([browser.offscreenDocuments, browser.sentRuntimeMessages], [[], []]);
});

test('opening the offscreen document with no reason Chrome knows is refused, as Chrome refuses it', async () => {
    await assert.rejects(fakeBrowser().offscreen.createDocument({ url: 'offscreen.html', reasons: ['NETWORK'], justification: 'x' }), TypeError);
    const browser = fakeBrowser();
    await browser.offscreen.createDocument({ url: 'offscreen.html', reasons: ['BLOBS'], justification: 'x' });
    await assert.rejects(browser.offscreen.createDocument({ url: 'offscreen.html', reasons: ['BLOBS'], justification: 'x' }), { message: ONE_OFFSCREEN_DOCUMENT });
});
