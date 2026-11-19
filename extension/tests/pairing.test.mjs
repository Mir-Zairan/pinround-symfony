import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { ApiError } from '../src/shared/api.js';
import { CONNECTED, SIGNED_OUT, SIGNED_OUT_LOCALLY, pair, signOut } from '../src/options/pairing.js';

const TOKEN = 'pr_abcdefghijklmnopqrstuvwxyz012345';

function memoryStorage() {
    const stored = {};

    return {
        stored,
        get: async (key) => ({ [key]: stored[key] }),
        set: async (values) => Object.assign(stored, values),
        remove: async (key) => delete stored[key],
    };
}

function answering(status) {
    const calls = [];

    return { calls, fetchFn: async (url, init) => { calls.push({ url, method: init.method }); return { ok: status < 300, status, json: async () => ({ error: 'nope' }) }; } };
}

test('pairing keeps the address and token only after pinround accepted the token', async () => {
    const storage = memoryStorage();
    const refused = answering(401);
    await assert.rejects(pair('https://pinround.acme-foods.example', TOKEN, storage, refused.fetchFn), ApiError);
    assert.deepEqual(storage.stored, {});

    const accepted = answering(200);
    assert.equal(await pair('https://pinround.acme-foods.example/', TOKEN, storage, accepted.fetchFn), CONNECTED);
    assert.deepEqual(accepted.calls, [{ url: 'https://pinround.acme-foods.example/api/v1/me/tickets', method: 'GET' }]);
    assert.deepEqual(storage.stored.pinround, { origin: 'https://pinround.acme-foods.example', token: TOKEN });
});

test('sign out revokes the token in pinround, then forgets it', async () => {
    const storage = memoryStorage();
    await storage.set({ pinround: { origin: 'https://pinround.acme-foods.example', token: TOKEN } });
    const server = answering(200);

    assert.equal(await signOut(storage, server.fetchFn), SIGNED_OUT);
    assert.deepEqual(server.calls, [{ url: 'https://pinround.acme-foods.example/api/v1/me/token/revoke', method: 'POST' }]);
    assert.deepEqual(storage.stored, {});
});

test('sign out forgets the token here too when pinround cannot be reached, and says so', async () => {
    const storage = memoryStorage();
    await storage.set({ pinround: { origin: 'https://pinround.acme-foods.example', token: TOKEN } });

    assert.equal(await signOut(storage, async () => { throw new TypeError('Failed to fetch'); }), SIGNED_OUT_LOCALLY);
    assert.deepEqual(storage.stored, {});
    await storage.set({ pinround: { origin: 'https://pinround.acme-foods.example', token: TOKEN } });
    assert.equal(await signOut(storage, answering(401).fetchFn), SIGNED_OUT, 'A token pinround no longer accepts is revoked already.');
});

test('the manifest asks for exactly the permissions the guide lists, and for no host', () => {
    const manifest = JSON.parse(readFileSync(new URL('../manifest.json', import.meta.url), 'utf8'));
    const guide = readFileSync(new URL('../../docs/guides/browser-extension.md', import.meta.url), 'utf8');

    assert.deepEqual(manifest.permissions, ['activeTab', 'storage']);
    assert.equal(manifest.host_permissions, undefined);
    assert.equal(manifest.optional_host_permissions, undefined);
    assert.equal(manifest.content_scripts, undefined);
    for (const permission of manifest.permissions) {
        assert.ok(guide.includes(`| \`${permission}\` |`), `The guide's permission table names ${permission}.`);
    }
});
