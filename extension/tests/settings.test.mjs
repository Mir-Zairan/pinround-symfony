import assert from 'node:assert/strict';
import { test } from 'node:test';
import { SettingsError, checkedToken, clearSettings, loadSettings, pinroundOriginOf, saveSettings } from '../src/shared/settings.js';
import { fakeBrowser } from './fake-browser.mjs';

test('an https address or a local http address becomes its origin', () => {
    assert.equal(pinroundOriginOf(' https://pinround.acme-foods.example/ '), 'https://pinround.acme-foods.example');
    assert.equal(pinroundOriginOf('https://pinround.acme-foods.example:8443'), 'https://pinround.acme-foods.example:8443');
    assert.equal(pinroundOriginOf('http://localhost:8080'), 'http://localhost:8080');
});

test('any other address is refused', () => {
    for (const typed of ['http://pinround.acme-foods.example', 'https://pinround.acme-foods.example/report', 'https://user:secret@pinround.acme-foods.example', 'https://pinround.acme-foods.example/?next=x', 'javascript:alert(1)', 'ftp://localhost', 'pinround', '']) {
        assert.throws(() => pinroundOriginOf(typed), SettingsError, typed);
    }
});

test('a token must look like a pinround token', () => {
    assert.equal(checkedToken('  pr_abcdefghijklmnopqrstuvwxyz012345\n'), 'pr_abcdefghijklmnopqrstuvwxyz012345');
    for (const typed of ['abcdefghijklmnopqrstuvwxyz', 'pr_short', 'pr_with space inside aaaaaaaa', 'Bearer pr_abcdefghijklmnopqrstuvwxyz']) {
        assert.throws(() => checkedToken(typed), SettingsError, typed);
    }
});

test('settings are kept, read back and cleared in the given storage only', async () => {
    const storage = fakeBrowser().storage.local;

    assert.equal(await loadSettings(storage), null);
    await saveSettings('https://pinround.acme-foods.example/', 'pr_abcdefghijklmnopqrstuvwxyz012345', storage);
    assert.deepEqual(await loadSettings(storage), { origin: 'https://pinround.acme-foods.example', token: 'pr_abcdefghijklmnopqrstuvwxyz012345' });
    await clearSettings(storage);
    assert.equal(await loadSettings(storage), null);
    await assert.rejects(saveSettings('http://evil.example', 'pr_abcdefghijklmnopqrstuvwxyz012345', storage), SettingsError);
    assert.equal(await loadSettings(storage), null, 'A refused address saves nothing.');
});
