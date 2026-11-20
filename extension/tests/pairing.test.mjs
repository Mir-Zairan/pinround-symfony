import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { ApiError, PinroundApi } from '../src/shared/api.js';
import { CAPTURE_TARGET_KEY } from '../src/capture/capture-target.js';
import { NEW_REPORT_KEY, sendPendingCapture } from '../src/capture/new-report.js';
import { CONNECTED, SIGNED_OUT, SIGNED_OUT_LOCALLY, pair, signOut } from '../src/options/pairing.js';
import { fakeBrowser } from './fake-browser.mjs';

const TOKEN = 'pr_abcdefghijklmnopqrstuvwxyz012345';
const OTHER_TOKEN = 'pr_zyxwvutsrqponmlkjihgfedcba543210';
const ORIGIN = 'https://pinround.acme-foods.example';
const OTHER_ORIGIN = 'https://pinround.other-foods.example';
const NOTHING_TO_SEND = 'There is no screenshot to send again.';

function answering(status) {
    const calls = [];

    return { calls, fetchFn: async (url, init) => { calls.push({ url, method: init.method }); return { ok: status < 300, status, json: async () => ({ error: 'nope' }) }; } };
}

/** Lena's session as the popup leaves it: a chosen target and a first capture whose answer never came, with her pin note. */
async function rememberWaitingCapture(storage) {
    await storage.local.set({ pinround: { origin: ORIGIN, token: TOKEN } });
    await storage.session.set({
        [CAPTURE_TARGET_KEY]: { kind: 'new-report' },
        [NEW_REPORT_KEY]: {
            project: 'SHOP',
            reportTitle: 'Basket total is doubled',
            draftId: null,
            draftUrl: null,
            screenshotCount: 0,
            pendingCapture: { idempotencyKey: 'k1', path: '/api/v1/reports', bodyJson: '{"project":"SHOP","title":"Basket total is doubled","screenshots":[{"upload_id":"u1","pins":[{"x_pct":1,"y_pct":2,"note":"Lena\'s note"}]}]}', origin: ORIGIN },
        },
    });
}

test('pairing keeps the address and token only after pinround accepted the token', async () => {
    const { storage } = fakeBrowser();
    const refused = answering(401);
    await assert.rejects(pair(ORIGIN, TOKEN, storage, refused.fetchFn), ApiError);
    assert.deepEqual(await storage.local.get(null), {});

    const accepted = answering(200);
    assert.equal(await pair(`${ORIGIN}/`, TOKEN, storage, accepted.fetchFn), CONNECTED);
    assert.deepEqual(accepted.calls, [{ url: `${ORIGIN}/api/v1/me/tickets`, method: 'GET' }]);
    assert.deepEqual(await storage.local.get(null), { pinround: { origin: ORIGIN, token: TOKEN } });
});

test('sign out revokes the token in pinround, then forgets it', async () => {
    const { storage } = fakeBrowser();
    await storage.local.set({ pinround: { origin: ORIGIN, token: TOKEN } });
    const server = answering(200);

    assert.equal(await signOut(storage, server.fetchFn), SIGNED_OUT);
    assert.deepEqual(server.calls, [{ url: `${ORIGIN}/api/v1/me/token/revoke`, method: 'POST' }]);
    assert.deepEqual(await storage.local.get(null), {});
});

test('sign out forgets the token here too when pinround cannot be reached, and says so', async () => {
    const { storage } = fakeBrowser();
    await storage.local.set({ pinround: { origin: ORIGIN, token: TOKEN } });

    assert.equal(await signOut(storage, async () => { throw new TypeError('Failed to fetch'); }), SIGNED_OUT_LOCALLY);
    assert.deepEqual(await storage.local.get(null), {});
    await storage.local.set({ pinround: { origin: ORIGIN, token: TOKEN } });
    assert.equal(await signOut(storage, answering(401).fetchFn), SIGNED_OUT, 'A token pinround no longer accepts is revoked already.');
});

test('sign out forgets the chosen target and the capture waiting to be sent, so "Send it again" sends nothing after it', async () => {
    const { storage } = fakeBrowser();
    await rememberWaitingCapture(storage);
    await storage.session.set({ unrelated: 'kept' });

    assert.equal(await signOut(storage, answering(200).fetchFn), SIGNED_OUT);

    assert.deepEqual(await storage.session.get(null), { unrelated: 'kept' });
    const omarsPairing = answering(200);
    await assert.rejects(sendPendingCapture(storage.session, new PinroundApi(ORIGIN, OTHER_TOKEN, omarsPairing.fetchFn)), { message: NOTHING_TO_SEND });
    assert.deepEqual(omarsPairing.calls, []);
});

test('sign out with nothing paired still forgets what the popup kept for captures', async () => {
    const { storage } = fakeBrowser();
    await rememberWaitingCapture(storage);
    await storage.local.remove('pinround');

    assert.equal(await signOut(storage, answering(200).fetchFn), SIGNED_OUT);

    assert.deepEqual(await storage.session.get(null), {});
});

test('pairing again with another address forgets the earlier capture, so nothing of it reaches the new pinround', async () => {
    const { storage } = fakeBrowser();
    await rememberWaitingCapture(storage);

    assert.equal(await pair(OTHER_ORIGIN, OTHER_TOKEN, storage, answering(200).fetchFn), CONNECTED);

    assert.deepEqual(await storage.session.get(null), {});
    const otherPinround = answering(200);
    await assert.rejects(sendPendingCapture(storage.session, new PinroundApi(OTHER_ORIGIN, OTHER_TOKEN, otherPinround.fetchFn)), { message: NOTHING_TO_SEND });
    assert.deepEqual(otherPinround.calls, []);
});

test('a pairing pinround refuses keeps the earlier capture, since the earlier pairing still stands', async () => {
    const { storage } = fakeBrowser();
    await rememberWaitingCapture(storage);
    const before = await storage.session.get(null);

    await assert.rejects(pair(OTHER_ORIGIN, OTHER_TOKEN, storage, answering(401).fetchFn), ApiError);

    assert.deepEqual(await storage.session.get(null), before);
    assert.deepEqual(await storage.local.get(null), { pinround: { origin: ORIGIN, token: TOKEN } });
});

test('the manifest asks for exactly the permissions the guide lists, and for no host', () => {
    const manifest = JSON.parse(readFileSync(new URL('../manifest.json', import.meta.url), 'utf8'));
    const guide = readFileSync(new URL('../../docs/guides/browser-extension.md', import.meta.url), 'utf8');

    assert.deepEqual(manifest.permissions, ['activeTab', 'scripting', 'storage']);
    assert.equal(manifest.host_permissions, undefined);
    assert.equal(manifest.optional_host_permissions, undefined);
    assert.equal(manifest.content_scripts, undefined);
    for (const permission of manifest.permissions) {
        assert.ok(guide.includes(`| \`${permission}\` |`), `The guide's permission table names ${permission}.`);
    }
});
