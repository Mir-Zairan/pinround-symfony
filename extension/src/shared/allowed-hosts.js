// The hosts the person allowed scenarios to play on (ADR-0046 "Where it plays"), kept in the extension's local storage,
// and the hosts they said "Play anyway" for. Nothing is allowed by default. A page is compared by scheme, host and port
// only, with the default port filled in; the pinround the extension is paired with is never a place to play, also when
// an entry would allow it.
import { URL_PATTERN } from '../player/scenario-rules.js';

export const ALLOWED_HOSTS_KEY = 'pinroundAllowedHosts';
export const CONFIRMED_HOSTS_KEY = 'pinroundConfirmedHosts';
export const MAX_ALLOWED_HOSTS = 50;
/** The most hosts kept as answered; the oldest answer is dropped first, so that host is asked about again. */
export const MAX_CONFIRMED_HOSTS = 200;
/** The entry ADR-0046 suggests, which the options page adds with one press. */
export const SUGGESTED_HOST = 'http://localhost:*';
const TYPED_MAX_LENGTH = 300;
const ANY_PORT = '*';
const MAX_PORT = 65535;
const DEFAULT_PORTS = Object.freeze({ http: 80, https: 443 });

export const HOST_REFUSALS = Object.freeze({
    tooLong: 'That is too long for a host. Type the scheme, host and port only.',
    shape: 'Type a host like http://localhost:8000 or https://shop.acme-foods.test.',
    scheme: 'Only http:// and https:// hosts can be allowed.',
    userInfo: 'Leave out the user name and password. Type the scheme, host and port only.',
    path: 'Leave out the path. Type the scheme, host and port only.',
    wildcardHost: 'A host cannot hold a *. Only the port can be *, as in http://localhost:*.',
    notAscii: 'Type the host in plain letters, digits, dots and dashes. International names cannot be allowed.',
    port: 'The port must be a number from 1 to 65535, or *.',
    listFull: `Your list holds ${MAX_ALLOWED_HOSTS} hosts, the most it can. Remove one first.`,
    alreadyListed: 'That host is on your list already.',
});

export const PLAY_REFUSALS = Object.freeze({
    notAWebPage: 'Scenarios play only on http and https pages you allowed.',
    pinroundHost: 'Scenarios never play on pinround itself.',
    notAllowed: (host) => `${host} is not on your allowed hosts. Add it in the extension options, then play again.`,
});

export class AllowedHostError extends Error {}

// Without the m flag, $ is the true end, so a trailing newline is refused.
const URL_SHAPE = new RegExp(URL_PATTERN, 'u');
const SCHEME_AND_REST = /^([A-Za-z][A-Za-z0-9+.-]*):\/\/(.*)$/su;
const BRACKETED_HOST = /^(\[[0-9A-Fa-f:.]+\])(?::(.*))?$/su;
const NAMED_HOST = /^([^:]*)(?::(.*))?$/su;
const HOST_LABEL = /^[a-z0-9-]+$/u;
const PORT_DIGITS = /^[0-9]{1,5}$/u;
// Punycode is how a browser writes an international name, which can look like another host.
const PUNYCODE_LABEL = /(?:^|\.)xn--/u;

/**
 * @typedef {{scheme: 'http'|'https', host: string, port: number|'*'}} AllowedHost a host in lowercase without a
 *   trailing dot; IPv6 in brackets
 * @typedef {{scheme: 'http'|'https', host: string, port: number}} Site where a page is: scheme, host and port
 */

/**
 * The entry as typed, checked: scheme, host and an optional port or `*`, nothing else. A trailing slash is allowed.
 *
 * @param {string} typed
 * @returns {AllowedHost}
 * @throws {AllowedHostError} with the plain message to show
 */
export function allowedHostOf(typed) {
    const text = String(typed).trim();
    if ([...text].length > TYPED_MAX_LENGTH) {
        throw new AllowedHostError(HOST_REFUSALS.tooLong);
    }
    const parts = SCHEME_AND_REST.exec(text);
    if (null === parts) {
        throw new AllowedHostError(HOST_REFUSALS.shape);
    }
    const scheme = parts[1].toLowerCase();
    if (!Object.hasOwn(DEFAULT_PORTS, scheme)) {
        throw new AllowedHostError(HOST_REFUSALS.scheme);
    }
    const authority = parts[2].endsWith('/') ? parts[2].slice(0, -1) : parts[2];
    if (authority.includes('@')) {
        throw new AllowedHostError(HOST_REFUSALS.userInfo);
    }
    if (/[/?#\\]/u.test(authority)) {
        throw new AllowedHostError(HOST_REFUSALS.path);
    }
    const [, givenHost, givenPort] = BRACKETED_HOST.exec(authority) ?? NAMED_HOST.exec(authority);
    if (givenHost.includes('*')) {
        throw new AllowedHostError(HOST_REFUSALS.wildcardHost);
    }
    const host = hostOf(givenHost);
    if (null === host) {
        throw new AllowedHostError(/^[\x00-\x7f]*$/u.test(givenHost) && !PUNYCODE_LABEL.test(givenHost.toLowerCase()) ? HOST_REFUSALS.shape : HOST_REFUSALS.notAscii);
    }

    return Object.freeze({ scheme, host, port: portOf(givenPort, scheme) });
}

/** The host in lowercase without one trailing dot, or null when it is no plain ASCII name, IPv4 or bracketed IPv6. */
function hostOf(givenHost) {
    const host = givenHost.toLowerCase().replace(/\.$/u, '');
    if (host.startsWith('[')) {
        return BRACKETED_HOST.test(host) ? host : null;
    }
    if ('' === host || PUNYCODE_LABEL.test(host) || host.split('.').some((label) => !HOST_LABEL.test(label))) {
        return null;
    }

    return host;
}

function portOf(givenPort, scheme) {
    if (undefined === givenPort) {
        return DEFAULT_PORTS[scheme];
    }
    if (ANY_PORT === givenPort) {
        return ANY_PORT;
    }
    const port = PORT_DIGITS.test(givenPort) ? Number(givenPort) : 0;
    if (port < 1 || port > MAX_PORT) {
        throw new AllowedHostError(HOST_REFUSALS.port);
    }

    return port;
}

/** @returns {string} the entry as the options page shows and storage keeps it, the default port left out */
export function allowedHostText({ scheme, host, port }) {
    return `${scheme}://${host}${DEFAULT_PORTS[scheme] === port ? '' : `:${port}`}`;
}

/**
 * Where a page is, read with the scenario format's own URL rule, or null for anything that is no full http or https
 * address (a chrome:// page, an address the tab hides) or whose host is an international name.
 *
 * @param {unknown} url
 * @returns {Site|null}
 */
export function siteOf(url) {
    const parts = 'string' === typeof url ? URL_SHAPE.exec(url) : null;
    if (null === parts) {
        return null;
    }
    const [, scheme, givenHost, givenPort] = parts;
    const host = hostOf(givenHost);
    const port = undefined === givenPort ? DEFAULT_PORTS[scheme] : Number(givenPort);
    if (null === host || port < 1 || port > MAX_PORT) {
        return null;
    }

    return Object.freeze({ scheme, host, port });
}

const siteKeyOf = ({ scheme, host, port }) => `${scheme}://${host}:${port}`;
const isSameSite = (one, other) => siteKeyOf(one) === siteKeyOf(other);

/** @returns {boolean} whether the entry allows the site */
export function allowsSite(entry, site) {
    return entry.scheme === site.scheme && entry.host === site.host && (ANY_PORT === entry.port || entry.port === site.port);
}

/** @returns {string} the host as the person reads it in a question or a refusal: host, and the port when not the default */
export function hostTextOf(site) {
    return `${site.host}${DEFAULT_PORTS[site.scheme] === site.port ? '' : `:${site.port}`}`;
}

/**
 * Why a scenario may not play at the address, or null when it may: it must be a web page on an allowed host and never
 * on the paired pinround. A pinround origin that cannot be read refuses every address.
 *
 * @param {{allowedHosts: AllowedHost[], pinroundOrigin: string}} rules
 * @param {unknown} url
 * @returns {string|null}
 */
export function playRefusalFor({ allowedHosts, pinroundOrigin }, url) {
    const site = siteOf(url);
    if (null === site) {
        return PLAY_REFUSALS.notAWebPage;
    }
    const pinround = siteOf(pinroundOrigin);
    if (null === pinround || isSameSite(pinround, site)) {
        return PLAY_REFUSALS.pinroundHost;
    }
    if (!allowedHosts.some((entry) => allowsSite(entry, site))) {
        return PLAY_REFUSALS.notAllowed(hostTextOf(site));
    }

    return null;
}

/** @returns {Promise<AllowedHost[]>} the stored entries in their order; one this version cannot read again is left out */
export async function loadAllowedHosts(storage) {
    const stored = (await storage.get(ALLOWED_HOSTS_KEY))[ALLOWED_HOSTS_KEY];
    if (!Array.isArray(stored)) {
        return [];
    }
    const entries = [];
    for (const text of stored.slice(0, MAX_ALLOWED_HOSTS)) {
        try {
            entries.push(allowedHostOf(text));
        } catch (error) {
            if (!(error instanceof AllowedHostError)) {
                throw error;
            }
        }
    }

    return entries;
}

// Each change reads a list, changes it and writes it back. Two changes at once from one page, such as two quick presses,
// would both read the same list and the later write would drop the earlier change, so they take turns per storage area.
const lastChangeOf = new WeakMap();

function inTurn(storage, change) {
    const turn = (lastChangeOf.get(storage) ?? Promise.resolve()).then(change);
    lastChangeOf.set(storage, turn.catch(() => undefined));

    return turn;
}

async function saveAllowedHosts(storage, entries) {
    await storage.set({ [ALLOWED_HOSTS_KEY]: entries.map(allowedHostText) });
}

/**
 * @param {string} typed
 * @returns {Promise<AllowedHost[]>} the list with the new entry at its end
 * @throws {AllowedHostError} a refused entry, one already listed, or a full list; nothing is stored then
 */
export async function addAllowedHost(storage, typed) {
    const entry = allowedHostOf(typed);

    return inTurn(storage, async () => {
        const entries = await loadAllowedHosts(storage);
        if (entries.some((listed) => allowedHostText(listed) === allowedHostText(entry))) {
            throw new AllowedHostError(HOST_REFUSALS.alreadyListed);
        }
        if (entries.length >= MAX_ALLOWED_HOSTS) {
            throw new AllowedHostError(HOST_REFUSALS.listFull);
        }
        const added = [...entries, entry];
        await saveAllowedHosts(storage, added);

        return added;
    });
}

/**
 * Removes the entry and forgets the "Play anyway" answers of every host the list no longer allows, so playing there
 * again, once allowed again, asks again.
 *
 * @param {string} text the entry as allowedHostText wrote it
 * @returns {Promise<AllowedHost[]>} the list left
 */
export async function removeAllowedHost(storage, text) {
    return inTurn(storage, async () => {
        const left = (await loadAllowedHosts(storage)).filter((entry) => allowedHostText(entry) !== text);
        await saveAllowedHosts(storage, left);
        const confirmed = await loadConfirmedSiteKeys(storage);
        const stillAllowed = confirmed.filter((key) => {
            const site = siteOf(`${key}/`);

            return null !== site && left.some((entry) => allowsSite(entry, site));
        });
        await storage.set({ [CONFIRMED_HOSTS_KEY]: stillAllowed });

        return left;
    });
}

async function loadConfirmedSiteKeys(storage) {
    const stored = (await storage.get(CONFIRMED_HOSTS_KEY))[CONFIRMED_HOSTS_KEY];

    return Array.isArray(stored) ? stored.filter((key) => 'string' === typeof key).slice(-MAX_CONFIRMED_HOSTS) : [];
}

/** @returns {Promise<boolean>} whether the person said "Play anyway" for the site's host before */
export async function isConfirmedSite(storage, site) {
    return (await loadConfirmedSiteKeys(storage)).includes(siteKeyOf(site));
}

export async function confirmSite(storage, site) {
    return inTurn(storage, async () => {
        const confirmed = (await loadConfirmedSiteKeys(storage)).filter((key) => key !== siteKeyOf(site));
        await storage.set({ [CONFIRMED_HOSTS_KEY]: [...confirmed, siteKeyOf(site)].slice(-MAX_CONFIRMED_HOSTS) });
    });
}
