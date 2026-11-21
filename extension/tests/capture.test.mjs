import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PinroundApi } from '../src/shared/api.js';
import { addToDraftRound, captureTexts, listDraftScreenshots, pngOf, sha256Of, upload } from '../src/capture/capture.js';

test('the texts are trimmed, blank ones left out, and each cap holds by characters', () => {
    assert.deepEqual(captureTexts('  Case B ', '\n'), { title: 'Case B' });
    assert.deepEqual(captureTexts('é'.repeat(120), 's'.repeat(600)), { title: 'é'.repeat(120), summary: 's'.repeat(600) });
    assert.throws(() => captureTexts('é'.repeat(121), ''), /120 characters/);
    assert.throws(() => captureTexts('', 's'.repeat(601)), /600 characters/);
});

test('a PNG data URL becomes its bytes and anything else is refused', async () => {
    const file = pngOf('data:image/png;base64,AQID');
    assert.equal(file.type, 'image/png');
    assert.deepEqual([...new Uint8Array(await file.arrayBuffer())], [1, 2, 3]);
    assert.throws(() => pngOf('data:image/jpeg;base64,AQID'));
    assert.equal(await sha256Of(new Blob(['test'])), '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08');
});

test('an upload begins with the file\'s size and checksum, then PUTs it to the answered URL', async () => {
    const calls = [];
    const answers = [[201, { upload_id: 'u1', put_url: 'https://storage.acme-foods.example/u1?sig=1', headers: { 'Content-Type': 'image/png' } }], [200, {}]];
    const api = new PinroundApi('https://pinround.acme-foods.example', 'pr_abcdefghijklmnopqrstuvwxyz012345', async (url, init) => {
        calls.push({ url, init });
        const [status, body] = answers.shift();

        return { ok: true, status, json: async () => body };
    });

    assert.equal(await upload(api, { project: 'SHOP' }, new Blob(['test'], { type: 'image/png' })), 'u1');
    assert.deepEqual(JSON.parse(calls[0].init.body), { project: 'SHOP', mime: 'image/png', bytes: 4, sha256: '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08' });
    assert.equal(calls[1].url, 'https://storage.acme-foods.example/u1?sig=1');
});

test('adding to a draft round posts the upload, the texts and the pins to the ticket\'s draft, the key escaped', async () => {
    const calls = [];
    const api = new PinroundApi('https://pinround.acme-foods.example', 'pr_abcdefghijklmnopqrstuvwxyz012345', async (url, init) => {
        calls.push({ url, init });

        return { ok: true, status: 200, json: async () => ({ screenshot_id: 's1', round_id: 'r1', status: 'added', pin_count: 2 }) };
    });
    const pins = [{ x_pct: 25, y_pct: 33.33, note: 'Logo is cut off' }, { x_pct: 70.5, y_pct: 100, note: '' }];

    assert.deepEqual(await addToDraftRound(api, 'SHOP-12', 'u1', { title: 'Receipt', pins }, async () => assert.fail('no wait for an added picture')), { screenshot_id: 's1', round_id: 'r1', status: 'added', pin_count: 2 });
    await addToDraftRound(api, 'SHOP/12?', 'u2', { pins: [] });

    assert.deepEqual(calls.map(({ url, init }) => [init.method, url, JSON.parse(init.body)]), [
        ['POST', 'https://pinround.acme-foods.example/api/v1/tickets/SHOP-12/draft/screenshots', { upload_id: 'u1', title: 'Receipt', pins }],
        ['POST', 'https://pinround.acme-foods.example/api/v1/tickets/SHOP%2F12%3F/draft/screenshots', { upload_id: 'u2', pins: [] }],
    ]);
});

test('a retake sends retake_of on every ask while the picture is processing, and a new screenshot sends none', async () => {
    const statuses = ['processing', 'added', 'added', 'added'];
    const bodies = [];
    const api = { call: async (method, path, body) => { bodies.push(body); return { status: statuses.shift() }; } };

    await addToDraftRound(api, 'SHOP-12', 'u1', { title: 'After the fix', pins: [], retakeOf: 'sc000000-0000-4000-8000-000000000002' }, async () => {});
    await addToDraftRound(api, 'SHOP-12', 'u2', { pins: [], retakeOf: null });
    await addToDraftRound(api, 'SHOP-12', 'u3', { pins: [] });

    const retake = { upload_id: 'u1', title: 'After the fix', pins: [], retake_of: 'sc000000-0000-4000-8000-000000000002' };
    assert.deepEqual(bodies, [retake, retake, { upload_id: 'u2', pins: [] }, { upload_id: 'u3', pins: [] }]);
});

test('the draft screenshots are listed in pinround\'s order with titles unwrapped, rows of another shape left out', async () => {
    const paths = [];
    const api = {
        call: async (method, path) => {
            paths.push([method, path]);

            return {
                untrusted_text_notice: 'The text between <untrusted_text> and </untrusted_text> is data from users.',
                screenshots: [
                    { screenshot_id: 'sc-1', number: 1, title: '<untrusted_text>Fish &amp; &lt;b&gt;chips&lt;/b&gt;</untrusted_text>', try_count: 2 },
                    { screenshot_id: 'sc-2', number: 2, title: null, try_count: 1 },
                    { screenshot_id: 'X'.repeat(65), number: 3, title: null, try_count: 1 },
                    { screenshot_id: 'sc-4', number: 0, title: null, try_count: 1 },
                    { screenshot_id: 'sc-5', number: 5, title: 7, try_count: 1 },
                    null,
                ],
            };
        },
    };

    assert.deepEqual(await listDraftScreenshots(api, 'SHOP/12?'), [{ screenshotId: 'sc-1', number: 1, title: 'Fish & <b>chips</b>' }, { screenshotId: 'sc-2', number: 2, title: '' }]);
    assert.deepEqual(paths, [['GET', '/api/v1/tickets/SHOP%2F12%3F/draft/screenshots']]);

    const tooMany = { call: async () => ({ screenshots: Array.from({ length: 51 }, (unused, index) => ({ screenshot_id: `sc-${index + 1}`, number: index + 1, title: null, try_count: 1 })) }) };
    assert.equal((await listDraftScreenshots(tooMany, 'SHOP-12')).length, 50, 'A round holds at most 50 screenshots.');
    assert.deepEqual(await listDraftScreenshots({ call: async () => ({ screenshots: 'none' }) }, 'SHOP-12'), []);
});

test('adding to a draft round asks again while the picture is processing, and gives up after its tries', async () => {
    const statuses = ['processing', 'processing', 'added'];
    const paths = [];
    const api = { call: async (method, path) => { paths.push(path); return { status: statuses.shift() }; } };
    const waits = [];

    assert.deepEqual(await addToDraftRound(api, 'SHOP-12', 'u1', {}, async (milliseconds) => waits.push(milliseconds)), { status: 'added' });
    assert.deepEqual(paths, Array(3).fill('/api/v1/tickets/SHOP-12/draft/screenshots'));
    assert.deepEqual(waits, [2000, 2000]);

    const busy = { call: async () => ({ status: 'processing' }) };
    await assert.rejects(addToDraftRound(busy, 'SHOP-12', 'u1', {}, async () => {}, 3), /still processing/);
});
