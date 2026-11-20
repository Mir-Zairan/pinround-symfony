import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    ALLOWED_HOSTS_KEY,
    AllowedHostError,
    CONFIRMED_HOSTS_KEY,
    HOST_REFUSALS,
    MAX_ALLOWED_HOSTS,
    MAX_CONFIRMED_HOSTS,
    PLAY_REFUSALS,
    SUGGESTED_HOST,
    addAllowedHost,
    allowedHostOf,
    allowedHostText,
    allowsSite,
    confirmSite,
    hostTextOf,
    isConfirmedSite,
    loadAllowedHosts,
    playRefusalFor,
    removeAllowedHost,
    siteOf,
} from '../src/shared/allowed-hosts.js';
import { clearSettings, loadSettings, saveSettings } from '../src/shared/settings.js';
import { fakeBrowser } from './fake-browser.mjs';

const PINROUND_ORIGIN = 'https://pinround.acme-foods.example';
const TOKEN = 'pr_abcdefghijklmnopqrstuvwxyz012345';

const storedOf = async (storage, key) => (await storage.get(key))[key];
const rulesOf = (entries, pinroundOrigin = PINROUND_ORIGIN) => ({ allowedHosts: entries.map(allowedHostOf), pinroundOrigin });

test('each refused entry form gets its own plain message', () => {
    const refusals = [
        ['', HOST_REFUSALS.shape],
        ['   ', HOST_REFUSALS.shape],
        ['localhost:8000', HOST_REFUSALS.shape],
        ['http:/localhost', HOST_REFUSALS.shape],
        ['http://', HOST_REFUSALS.shape],
        ['http://.', HOST_REFUSALS.shape],
        ['http://localhost..', HOST_REFUSALS.shape],
        ['http://shop_acme.test', HOST_REFUSALS.shape],
        ['http://[::1', HOST_REFUSALS.shape],
        ['ftp://localhost', HOST_REFUSALS.scheme],
        ['javascript://localhost', HOST_REFUSALS.scheme],
        ['chrome-extension://abcdefghijklmnop', HOST_REFUSALS.scheme],
        ['http://lena:secret@localhost:8000', HOST_REFUSALS.userInfo],
        ['http://lena@localhost', HOST_REFUSALS.userInfo],
        ['http://localhost:8000/orders', HOST_REFUSALS.path],
        ['http://localhost//', HOST_REFUSALS.path],
        ['http://localhost:8000?next=orders', HOST_REFUSALS.path],
        ['http://localhost:8000#top', HOST_REFUSALS.path],
        ['http://localhost\\orders', HOST_REFUSALS.path],
        ['http://*.acme-foods.test', HOST_REFUSALS.wildcardHost],
        ['http://*:8000', HOST_REFUSALS.wildcardHost],
        ['http://shop.*.test:*', HOST_REFUSALS.wildcardHost],
        ['http://bäckerei.acme-foods.test', HOST_REFUSALS.notAscii],
        ['http://xn--bckerei-dxa.acme-foods.test', HOST_REFUSALS.notAscii],
        ['http://XN--bckerei-dxa.test', HOST_REFUSALS.notAscii],
        ['http://localhost:0', HOST_REFUSALS.port],
        ['http://localhost:65536', HOST_REFUSALS.port],
        ['http://localhost:123456', HOST_REFUSALS.port],
        ['http://localhost:80a', HOST_REFUSALS.port],
        ['http://localhost:-1', HOST_REFUSALS.port],
        ['http://localhost:', HOST_REFUSALS.port],
        ['http://localhost:**', HOST_REFUSALS.port],
        [`http://${'a'.repeat(294)}`, HOST_REFUSALS.tooLong],
    ];
    for (const [typed, message] of refusals) {
        assert.throws(() => allowedHostOf(typed), (error) => error instanceof AllowedHostError && message === error.message, JSON.stringify(typed));
    }
});

test('the length cap counts characters, not UTF-16 units, so 300 emoji are no "too long"', () => {
    assert.equal(allowedHostOf(`http://${'a'.repeat(293)}`).host, 'a'.repeat(293));
    assert.throws(() => allowedHostOf(`http://${'\u{1F353}'.repeat(293)}`), { message: HOST_REFUSALS.notAscii });
    assert.throws(() => allowedHostOf(`http://${'\u{1F353}'.repeat(294)}`), { message: HOST_REFUSALS.tooLong });
});

test('control and separator characters in an entry never reach the message: each refusal is a fixed text', () => {
    const typedEntries = [
        'http://local\u001b[2Jhost',
        'http://local\rhost',
        'http://local\nhost',
        'http://local\u{2028}host',
        'http://localhost:80\u001b',
        'http://localhost/\u001b[31m',
        '\u001bhttp://localhost',
    ];
    const fixedTexts = new Set(Object.values(HOST_REFUSALS));
    for (const typed of typedEntries) {
        assert.throws(() => allowedHostOf(typed), (error) => fixedTexts.has(error.message) && !/[\p{Cc}\p{Zl}]/u.test(error.message), JSON.stringify(typed));
    }
    assert.throws(() => allowedHostOf('http://local\u001b[2Jhost'), { message: HOST_REFUSALS.shape });
    assert.throws(() => allowedHostOf('http://local\u{2028}host'), { message: HOST_REFUSALS.notAscii });
});

test('accepted entries are lowered, get their default port, keep a * port, and lose one trailing dot and slash', () => {
    assert.deepEqual(allowedHostOf('http://localhost'), { scheme: 'http', host: 'localhost', port: 80 });
    assert.deepEqual(allowedHostOf('https://Shop.Acme-Foods.TEST/'), { scheme: 'https', host: 'shop.acme-foods.test', port: 443 });
    assert.deepEqual(allowedHostOf('HTTP://localhost:*'), { scheme: 'http', host: 'localhost', port: '*' });
    assert.deepEqual(allowedHostOf('http://localhost.:8000'), { scheme: 'http', host: 'localhost', port: 8000 });
    assert.deepEqual(allowedHostOf('http://127.0.0.1:65535'), { scheme: 'http', host: '127.0.0.1', port: 65535 });
    assert.deepEqual(allowedHostOf('http://[::1]:8000'), { scheme: 'http', host: '[::1]', port: 8000 });
    assert.deepEqual(allowedHostOf('http://localhost:1'), { scheme: 'http', host: 'localhost', port: 1 });
    // Pasted with a byte order mark and a CRLF line end.
    assert.deepEqual(allowedHostOf('\u{FEFF} http://localhost:8000\r\n'), { scheme: 'http', host: 'localhost', port: 8000 });
    assert.equal(allowedHostText(allowedHostOf('https://shop.acme-foods.test:443')), 'https://shop.acme-foods.test');
    assert.equal(allowedHostText(allowedHostOf('http://localhost:*')), SUGGESTED_HOST);
    assert.equal(allowedHostText(allowedHostOf('http://localhost:443')), 'http://localhost:443');
});

test('an entry matches a page by scheme, host and port only', () => {
    const shop = allowedHostOf('https://shop.acme-foods.test');
    const anyLocalPort = allowedHostOf('http://localhost:*');
    const localDev = allowedHostOf('http://localhost.:8000');
    const matches = (entry, url) => allowsSite(entry, siteOf(url));

    assert.equal(matches(shop, 'https://shop.acme-foods.test/basket?id=1#total'), true);
    assert.equal(matches(shop, 'https://shop.acme-foods.test:443/basket'), true);
    assert.equal(matches(shop, 'https://SHOP.acme-foods.test/'), true);
    assert.equal(matches(shop, 'https://shop.acme-foods.test./'), true);
    assert.equal(matches(shop, 'http://shop.acme-foods.test/'), false);
    assert.equal(matches(shop, 'https://shop.acme-foods.test:8443/'), false);
    assert.equal(matches(shop, 'https://www.shop.acme-foods.test/'), false);
    assert.equal(matches(shop, 'https://acme-foods.test/'), false);

    assert.equal(matches(anyLocalPort, 'http://localhost:3000/'), true);
    assert.equal(matches(anyLocalPort, 'http://localhost/'), true);
    assert.equal(matches(anyLocalPort, 'https://localhost:3000/'), false);
    assert.equal(matches(anyLocalPort, 'http://127.0.0.1:3000/'), false);

    assert.equal(matches(localDev, 'http://localhost:8000/orders'), true);
    assert.equal(matches(localDev, 'http://localhost.:8000/orders'), true);
    assert.equal(matches(localDev, 'http://localhost:8001/orders'), false);
});

test('a page that is no full http or https address has no site', () => {
    for (const url of ['chrome://settings/', 'about:blank', 'file:///home/lena/a.html', 'HTTP://localhost/', 'http://localhost:0/', 'http://localhost:65536/', 'http://xn--bckerei-dxa.test/', 'http://local\u001bhost/', 'http://localhost/a\u{2028}b', 'http://localhost/\n', undefined, null, 42]) {
        assert.equal(siteOf(url), null, JSON.stringify(url));
    }
    assert.deepEqual(siteOf('http://LOCALHOST.:8000/orders'), { scheme: 'http', host: 'localhost', port: 8000 });
    assert.equal(hostTextOf(siteOf('https://shop.acme-foods.test:443/')), 'shop.acme-foods.test');
    assert.equal(hostTextOf(siteOf('http://localhost:8000/')), 'localhost:8000');
});

test('play is refused on anything not allowed, with the host in the message; allowed pages are no refusal', () => {
    const rules = rulesOf(['http://localhost:*', 'https://shop.acme-foods.test']);

    assert.equal(playRefusalFor(rules, 'http://localhost:3000/orders'), null);
    assert.equal(playRefusalFor(rules, 'https://shop.acme-foods.test/basket'), null);
    assert.equal(playRefusalFor(rules, 'https://localhost:3000/orders'), PLAY_REFUSALS.notAllowed('localhost:3000'));
    assert.equal(playRefusalFor(rules, 'https://shop.acme-foods.test:8443/'), PLAY_REFUSALS.notAllowed('shop.acme-foods.test:8443'));
    assert.equal(playRefusalFor(rules, 'http://shop.acme-foods.test/'), PLAY_REFUSALS.notAllowed('shop.acme-foods.test'));
    assert.equal(PLAY_REFUSALS.notAllowed('shop.acme-foods.test'), 'shop.acme-foods.test is not on your allowed hosts. Add it in the extension options, then play again.');
    assert.equal(playRefusalFor(rules, 'chrome://settings/'), PLAY_REFUSALS.notAWebPage);
    assert.equal(playRefusalFor(rules, undefined), PLAY_REFUSALS.notAWebPage);
    assert.equal(playRefusalFor(rulesOf([]), 'http://localhost:3000/'), PLAY_REFUSALS.notAllowed('localhost:3000'));
});

test('pinround itself is refused even when an entry allows its host, by scheme, host and port', () => {
    const localPinround = rulesOf(['http://localhost:*', 'https://localhost'], 'https://localhost');

    assert.equal(playRefusalFor(localPinround, 'https://localhost/rounds'), PLAY_REFUSALS.pinroundHost);
    assert.equal(playRefusalFor(localPinround, 'https://LOCALHOST.:443/rounds'), PLAY_REFUSALS.pinroundHost);
    assert.equal(playRefusalFor(localPinround, 'http://localhost:8000/orders'), null);
    assert.equal(playRefusalFor(localPinround, 'http://localhost/'), null);

    const httpPinround = rulesOf(['http://localhost:*'], 'http://localhost:8080');
    assert.equal(playRefusalFor(httpPinround, 'http://localhost:8080/notebooks'), PLAY_REFUSALS.pinroundHost);
    assert.equal(playRefusalFor(httpPinround, 'http://localhost:8000/'), null);

    const sharedPinround = rulesOf(['https://pinround.acme-foods.example'], PINROUND_ORIGIN);
    assert.equal(playRefusalFor(sharedPinround, 'https://pinround.acme-foods.example/report-drafts'), PLAY_REFUSALS.pinroundHost);
});

test('a pinround origin that cannot be read refuses every page', () => {
    for (const pinroundOrigin of [undefined, '', 'not an address', 'https://xn--bckerei-dxa.example']) {
        const rules = { allowedHosts: [allowedHostOf('http://localhost:*')], pinroundOrigin };
        assert.equal(playRefusalFor(rules, 'http://localhost:3000/'), PLAY_REFUSALS.pinroundHost, String(pinroundOrigin));
    }
});

test('entries are added in order and kept as their text; a refused entry stores nothing', async () => {
    const storage = fakeBrowser().storage.local;

    assert.deepEqual(await loadAllowedHosts(storage), []);
    await addAllowedHost(storage, 'http://localhost:*');
    await addAllowedHost(storage, 'https://Shop.acme-foods.test:443/');
    await assert.rejects(addAllowedHost(storage, 'http://localhost/orders'), { message: HOST_REFUSALS.path });

    assert.deepEqual(await storedOf(storage, ALLOWED_HOSTS_KEY), ['http://localhost:*', 'https://shop.acme-foods.test']);
    assert.deepEqual(await loadAllowedHosts(storage), [
        { scheme: 'http', host: 'localhost', port: '*' },
        { scheme: 'https', host: 'shop.acme-foods.test', port: 443 },
    ]);
});

test('the same host twice, written another way, is refused and the list stays as it was', async () => {
    const storage = fakeBrowser().storage.local;
    await addAllowedHost(storage, 'http://localhost:8000');
    await addAllowedHost(storage, 'http://localhost');

    for (const typed of ['http://LOCALHOST:8000/', 'http://localhost.:8000', 'http://localhost:80']) {
        await assert.rejects(addAllowedHost(storage, typed), (error) => error instanceof AllowedHostError && HOST_REFUSALS.alreadyListed === error.message, typed);
    }
    assert.deepEqual(await storedOf(storage, ALLOWED_HOSTS_KEY), ['http://localhost:8000', 'http://localhost']);
});

test(`the list holds ${MAX_ALLOWED_HOSTS} hosts: the next one is refused and the list stays as it was`, async () => {
    const storage = fakeBrowser().storage.local;
    for (let port = 1; port <= MAX_ALLOWED_HOSTS; port += 1) {
        await addAllowedHost(storage, `http://localhost:${port}`);
    }
    const full = await storedOf(storage, ALLOWED_HOSTS_KEY);
    assert.equal(full.length, MAX_ALLOWED_HOSTS);

    await assert.rejects(addAllowedHost(storage, 'http://localhost:51'), { message: HOST_REFUSALS.listFull });
    assert.equal(HOST_REFUSALS.listFull, 'Your list holds 50 hosts, the most it can. Remove one first.');
    assert.deepEqual(await storedOf(storage, ALLOWED_HOSTS_KEY), full);

    await removeAllowedHost(storage, 'http://localhost:1');
    assert.equal((await addAllowedHost(storage, 'http://localhost:51')).length, MAX_ALLOWED_HOSTS);
});

test('two adds at once both stay: neither write drops the other', async () => {
    const storage = fakeBrowser().storage.local;

    await Promise.all([addAllowedHost(storage, 'http://localhost:3000'), addAllowedHost(storage, 'http://localhost:4000'), addAllowedHost(storage, 'http://localhost:3000').catch((error) => error.message)]);

    assert.deepEqual(await storedOf(storage, ALLOWED_HOSTS_KEY), ['http://localhost:3000', 'http://localhost:4000']);
});

test('two removes at once both take: both entries go and the answers of both hosts are forgotten', async () => {
    const storage = fakeBrowser().storage.local;
    for (const entry of ['http://localhost:3000', 'http://localhost:4000', 'https://shop.acme-foods.test']) {
        await addAllowedHost(storage, entry);
    }
    for (const url of ['http://localhost:3000/', 'http://localhost:4000/', 'https://shop.acme-foods.test/']) {
        await confirmSite(storage, siteOf(url));
    }

    await Promise.all([removeAllowedHost(storage, 'http://localhost:3000'), removeAllowedHost(storage, 'http://localhost:4000')]);

    assert.deepEqual(await storedOf(storage, ALLOWED_HOSTS_KEY), ['https://shop.acme-foods.test']);
    assert.deepEqual(await storedOf(storage, CONFIRMED_HOSTS_KEY), ['https://shop.acme-foods.test:443']);
});

test('two "Play anyway" answers at once are both kept', async () => {
    const storage = fakeBrowser().storage.local;

    await Promise.all([confirmSite(storage, siteOf('http://localhost:3000/')), confirmSite(storage, siteOf('http://localhost:4000/'))]);

    assert.deepEqual(await storedOf(storage, CONFIRMED_HOSTS_KEY), ['http://localhost:3000', 'http://localhost:4000']);
});

test('stored entries this version cannot read are left out, and a stored list past the cap is read only up to it', async () => {
    const storage = fakeBrowser().storage.local;
    await storage.set({ [ALLOWED_HOSTS_KEY]: ['http://localhost:*', 'ftp://localhost', 7, null, 'https://shop.acme-foods.test'] });
    assert.deepEqual((await loadAllowedHosts(storage)).map(allowedHostText), ['http://localhost:*', 'https://shop.acme-foods.test']);

    await storage.set({ [ALLOWED_HOSTS_KEY]: Array.from({ length: MAX_ALLOWED_HOSTS + 5 }, (_, index) => `http://localhost:${index + 1}`) });
    assert.equal((await loadAllowedHosts(storage)).length, MAX_ALLOWED_HOSTS);

    await storage.set({ [ALLOWED_HOSTS_KEY]: 'http://localhost:*' });
    assert.deepEqual(await loadAllowedHosts(storage), []);
});

test('"Play anyway" answers are kept per scheme, host and port', async () => {
    const storage = fakeBrowser().storage.local;
    const localDev = siteOf('http://localhost:8000/orders');

    assert.equal(await isConfirmedSite(storage, localDev), false);
    await confirmSite(storage, localDev);
    await confirmSite(storage, siteOf('http://localhost.:8000/basket'));

    assert.equal(await isConfirmedSite(storage, siteOf('http://LOCALHOST:8000/')), true);
    assert.equal(await isConfirmedSite(storage, siteOf('http://localhost:8001/')), false);
    assert.equal(await isConfirmedSite(storage, siteOf('https://localhost:8000/')), false);
    assert.deepEqual(await storedOf(storage, CONFIRMED_HOSTS_KEY), ['http://localhost:8000']);
});

test('removing an entry forgets the answers of the hosts no entry allows any more, and keeps the others', async () => {
    const storage = fakeBrowser().storage.local;
    await addAllowedHost(storage, 'http://localhost:*');
    await addAllowedHost(storage, 'http://localhost:8000');
    await addAllowedHost(storage, 'https://shop.acme-foods.test');
    for (const url of ['http://localhost:8000/', 'http://localhost:3000/', 'https://shop.acme-foods.test/']) {
        await confirmSite(storage, siteOf(url));
    }

    await removeAllowedHost(storage, 'http://localhost:*');
    assert.deepEqual(await storedOf(storage, CONFIRMED_HOSTS_KEY), ['http://localhost:8000', 'https://shop.acme-foods.test:443']);

    assert.deepEqual((await removeAllowedHost(storage, 'http://localhost:8000')).map(allowedHostText), ['https://shop.acme-foods.test']);
    assert.equal(await isConfirmedSite(storage, siteOf('http://localhost:8000/')), false);
    assert.equal(await isConfirmedSite(storage, siteOf('https://shop.acme-foods.test/')), true);

    await addAllowedHost(storage, 'http://localhost:8000');
    assert.equal(await isConfirmedSite(storage, siteOf('http://localhost:8000/')), false, 'Allowed again, the host is asked about again.');
});

test(`at most ${MAX_CONFIRMED_HOSTS} answers are kept; the oldest goes first, and answering again makes an answer the newest`, async () => {
    const storage = fakeBrowser().storage.local;
    const siteOfPort = (port) => siteOf(`http://localhost:${port}/`);
    for (let port = 1; port <= MAX_CONFIRMED_HOSTS; port += 1) {
        await confirmSite(storage, siteOfPort(port));
    }
    await confirmSite(storage, siteOfPort(1));

    await confirmSite(storage, siteOfPort(MAX_CONFIRMED_HOSTS + 1));

    const kept = await storedOf(storage, CONFIRMED_HOSTS_KEY);
    assert.equal(kept.length, MAX_CONFIRMED_HOSTS);
    assert.equal(await isConfirmedSite(storage, siteOfPort(1)), true);
    assert.equal(await isConfirmedSite(storage, siteOfPort(2)), false);
    assert.equal(await isConfirmedSite(storage, siteOfPort(3)), true);
    assert.equal(await isConfirmedSite(storage, siteOfPort(MAX_CONFIRMED_HOSTS + 1)), true);
});

test('signing out forgets the "Play anyway" answers and keeps the allowed hosts', async () => {
    const storage = fakeBrowser().storage.local;
    await saveSettings(PINROUND_ORIGIN, TOKEN, storage);
    await addAllowedHost(storage, 'http://localhost:*');
    await confirmSite(storage, siteOf('http://localhost:8000/'));

    await clearSettings(storage);

    assert.equal(await loadSettings(storage), null);
    assert.equal(await isConfirmedSite(storage, siteOf('http://localhost:8000/')), false);
    assert.equal(await storedOf(storage, CONFIRMED_HOSTS_KEY), undefined);
    assert.deepEqual(await storedOf(storage, ALLOWED_HOSTS_KEY), ['http://localhost:*']);
});
