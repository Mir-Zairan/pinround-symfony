import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

// A changed key changes the id on every machine, and with it the origin pinround must allow: change both together.
const EXTENSION_ID = 'ecijiihpbchkekoddiblnidjpeaajkfo';
const EXTENSION_ORIGIN = `chrome-extension://${EXTENSION_ID}`;

function readRepoFile(relativePath) {
    return readFileSync(new URL(`../../${relativePath}`, import.meta.url), 'utf8');
}

/** Chrome's id for a manifest key: the first 128 bits of the SHA-256 of its DER bytes, each hex digit as a to p. */
function extensionIdOf(manifestKey) {
    const hex = createHash('sha256').update(Buffer.from(manifestKey, 'base64')).digest('hex').slice(0, 32);

    return [...hex].map((digit) => String.fromCharCode('a'.charCodeAt(0) + parseInt(digit, 16))).join('');
}

test('the manifest key gives the extension the id the dev stack allows', () => {
    const manifest = JSON.parse(readRepoFile('extension/manifest.json'));

    assert.equal(typeof manifest.key, 'string');
    assert.equal(extensionIdOf(manifest.key), EXTENSION_ID);
});

test('the dev app allows exactly the extension origin', () => {
    const lines = readRepoFile('.env.dev').split(/\r?\n/).filter((line) => line.startsWith('EXTENSION_ORIGINS='));

    assert.deepEqual(lines, [`EXTENSION_ORIGINS=${EXTENSION_ORIGIN}`]);
});

test('the dev storage lets the app and the extension send presigned PUTs', () => {
    const compose = readRepoFile('compose.yaml');

    assert.ok(
        compose.includes(`MINIO_CORS_ALLOW_ORIGIN: \${MINIO_CORS_ALLOW_ORIGIN:-https://localhost,${EXTENSION_ORIGIN}}`),
        'compose.yaml defaults MINIO_CORS_ALLOW_ORIGIN to the app origin and the extension origin.',
    );
});
