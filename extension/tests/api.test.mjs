import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ApiError, NOT_REACHED, OTHER_HOST, PinroundApi } from '../src/shared/api.js';

const ORIGIN = 'https://pinround.acme-foods.example';
const TOKEN = 'pr_abcdefghijklmnopqrstuvwxyz012345';

function recordingFetch(answers) {
    const calls = [];
    const fetchFn = async (url, init) => {
        calls.push({ url, init });
        const [status, body] = answers.shift() ?? [200, {}];

        return { ok: status >= 200 && status < 300, status, json: async () => body };
    };

    return { calls, fetchFn };
}

test('a call goes to the configured origin with the token and no cookies', async () => {
    const { calls, fetchFn } = recordingFetch([[201, { draft_id: 'd1' }]]);
    const answer = await new PinroundApi(ORIGIN, TOKEN, fetchFn).call('POST', '/api/v1/reports', { project: 'SHOP' }, 'key-1');

    assert.deepEqual(answer, { draft_id: 'd1' });
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, `${ORIGIN}/api/v1/reports`);
    assert.equal(calls[0].init.headers.Authorization, `Bearer ${TOKEN}`);
    assert.equal(calls[0].init.headers['Idempotency-Key'], 'key-1');
    assert.equal(calls[0].init.credentials, 'omit');
    assert.equal(calls[0].init.redirect, 'error');
});

test('a path that leaves the origin or the API is refused before any request', async () => {
    const { calls, fetchFn } = recordingFetch([]);
    const api = new PinroundApi(ORIGIN, TOKEN, fetchFn);

    for (const path of ['https://evil.example/api/v1/reports', '//evil.example/api/v1/reports', '/report-drafts', '/api/v2/reports']) {
        await assert.rejects(api.call('GET', path), (error) => error instanceof ApiError && OTHER_HOST === error.message, path);
    }
    assert.equal(calls.length, 0);
});

test('a PUT goes only to a put_url pinround answered, once', async () => {
    const putUrl = 'https://storage.acme-foods.example/pinround/uploads/x?signature=1';
    const { calls, fetchFn } = recordingFetch([[201, { upload_id: 'u1', put_url: putUrl, headers: { 'Content-Type': 'image/png', 'Content-Length': '3' } }], [200, {}]]);
    const api = new PinroundApi(ORIGIN, TOKEN, fetchFn);
    const file = new Blob([new Uint8Array([1, 2, 3])], { type: 'image/png' });

    await assert.rejects(api.putFile({ put_url: putUrl, headers: {} }, file), (error) => OTHER_HOST === error.message);
    const slot = await api.call('POST', '/api/v1/uploads', { project: 'SHOP' });
    await api.putFile(slot, file);
    await assert.rejects(api.putFile(slot, file), (error) => OTHER_HOST === error.message, 'A put_url is used once.');

    assert.equal(calls.length, 2);
    assert.equal(calls[1].init.method, 'PUT');
    assert.deepEqual(calls[1].init.headers, { 'Content-Type': 'image/png' });
});

test('a refusal carries pinround\'s message and status, and an unreachable server says so', async () => {
    const { fetchFn } = recordingFetch([[403, { error: 'This token does not have the "reports:write" scope.', outcome: 'missing_scope' }], [401, {}]]);
    const api = new PinroundApi(ORIGIN, TOKEN, fetchFn);

    await assert.rejects(api.call('POST', '/api/v1/reports', {}), (error) => 403 === error.status && error.message.includes('reports:write'));
    await assert.rejects(api.call('GET', '/api/v1/me/tickets'), (error) => 401 === error.status && error.message.includes('Pair the extension again'));
    const offline = new PinroundApi(ORIGIN, TOKEN, async () => { throw new TypeError('Failed to fetch'); });
    await assert.rejects(offline.call('GET', '/api/v1/me/tickets'), (error) => 0 === error.status && NOT_REACHED === error.message);
});
