import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

// The unpacked extension loads nothing from outside extension/, so it carries its own copy of the scenario format.
const EXTENSION_COPY = new URL('../scenario.v1.json', import.meta.url);
const DOCS_ORIGINAL = new URL('../../docs/api/scenario.v1.json', import.meta.url);

test('the extension\'s scenario format is a byte for byte copy of docs/api/scenario.v1.json', async () => {
    const [copy, original] = await Promise.all([readFile(EXTENSION_COPY), readFile(DOCS_ORIGINAL)]);

    assert.ok(original.length > 0);
    assert.ok(copy.equals(original), 'Copy docs/api/scenario.v1.json to extension/scenario.v1.json again.');
});
