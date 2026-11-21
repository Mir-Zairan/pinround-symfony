import type { BrowserContext, Page, Response } from '@playwright/test';
import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { logInAndTurnOnTwoStepLogin, MIA_EMAIL, turnOffTwoStepLogin, twoStepCodesFile } from './auth';
import { runAsDatabaseOwner } from './database';
import { EXTENSION_ID, expect, openPopupFor, test, type ExtensionPageGlobals } from './extension';

// P14.T20: the extension connects with pinround's consent page instead of a pasted token, and its calls use the issued
// token. Against the real dev stack, and the extension reads the OAuth endpoints' answers through CORS
// (EXTENSION_ORIGINS): the test copy keeps the default host permissions, which do not reach pinround. One part is
// stood in for: chrome.identity.launchWebAuthFlow (stubSignInWindow), whose window Playwright cannot reach.
//
// As Mia: the consent page asks for the recent password and two-step proof of making a token, so beforeAll turns her
// two-step login on (that login is the proof, for 10 minutes) and afterAll turns it off. login-code.spec.ts does the
// same for her in the chromium project: run the two apart, as each expects her two-step login to be off when it starts.
const MIA_CONNECT_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'mia-connect.json');
const CONSENT_HEADING = 'Connect pinround browser extension to pinround?';
const CONNECTED = 'Connected. You can capture pages now.';
const SIGNED_OUT = 'Signed out. The token no longer works.';

/** What the options page keeps after a Connect (settings.js saveConnection); the tokens never leave this process. */
interface StoredConnection {
  origin: string;
  token: string;
  refreshToken: string;
  clientId: string;
}

test.describe.configure({ mode: 'serial' });

// Recorded as soon as the extension registered, so a test that stops before Sign out still revokes what it was issued.
const registeredClientIds: string[] = [];

test.beforeAll(async ({ browser }) => {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await logInAndTurnOnTwoStepLogin(await context.newPage(), MIA_EMAIL, 'Mia');
  await context.storageState({ path: MIA_CONNECT_STORAGE_STATE });
  await context.close();
});

test.afterAll(async ({ browser }) => {
  if (!existsSync(twoStepCodesFile(MIA_EMAIL)) || !existsSync(MIA_CONNECT_STORAGE_STATE)) {
    return;
  }
  const context = await browser.newContext({ storageState: MIA_CONNECT_STORAGE_STATE });
  await turnOffTwoStepLogin(await context.newPage(), MIA_EMAIL);
  await context.close();
});

test.afterEach(() => {
  for (const clientId of registeredClientIds.splice(0)) {
    runAsDatabaseOwner(`
      UPDATE idn_api_token SET revoked_at = now() WHERE revoked_at IS NULL AND id IN (SELECT access_token_id FROM idn_oauth_refresh_token WHERE client_id = '${clientId}');
      UPDATE idn_oauth_refresh_token SET revoked_at = now() WHERE revoked_at IS NULL AND client_id = '${clientId}'`);
  }
});

/** Gives the extension's browser Mia's logged-in session from beforeAll, as if she had logged in to pinround there. */
async function logInAsMia(context: BrowserContext): Promise<void> {
  const { cookies } = JSON.parse(await readFile(MIA_CONNECT_STORAGE_STATE, 'utf8')) as { cookies: Parameters<BrowserContext['addCookies']>[0] };
  await context.addCookies(cookies);
}

async function storedConnectionOf(extensionPage: Page): Promise<StoredConnection | undefined> {
  return extensionPage.evaluate(async () => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    return (await chrome.storage.local.get('pinround')).pinround as StoredConnection | undefined;
  });
}

interface SignInWindowStub {
  chrome: { identity: { launchWebAuthFlow: (details: { url: string; interactive: boolean }) => Promise<string> } };
  signInWindow?: { url: string; finish: (reachedUrl: string) => void };
}

/**
 * Replaces chrome.identity.launchWebAuthFlow on the options page, where Connect calls it. Chrome opens the real sign-in
 * window as no page Playwright attaches to (it is not among the browser's DevTools targets), so the stub keeps the
 * consent page's address for signInWindowAddressOf and answers with the address finishSignInWindow passes, as Chrome
 * answers with the redirect address the window reached. Everything else in Connect runs as shipped.
 */
async function stubSignInWindow(options: Page): Promise<void> {
  await options.evaluate(() => {
    const scope = globalThis as unknown as SignInWindowStub;
    scope.chrome.identity.launchWebAuthFlow = ({ url }) => new Promise((resolve) => {
      scope.signInWindow = { url, finish: resolve };
    });
  });
}

async function signInWindowAddressOf(options: Page): Promise<string> {
  const address = () => options.evaluate(() => (globalThis as unknown as SignInWindowStub).signInWindow?.url ?? null);
  await expect.poll(address, { message: 'Connect opened the sign-in window' }).not.toBeNull();
  return String(await address());
}

async function finishSignInWindow(options: Page, reachedUrl: string): Promise<void> {
  await options.evaluate((reached) => (globalThis as unknown as SignInWindowStub).signInWindow?.finish(reached), reachedUrl);
}

/** The issued access token's row: its OAuth client and whether pinround saw it used and still takes it. */
function accessTokenRowsOf(clientId: string, tokenPrefix: string): string[] {
  return runAsDatabaseOwner(`
    SELECT r.client_id, t.last_used_at IS NOT NULL, t.revoked_at IS NULL, r.revoked_at IS NULL
    FROM idn_api_token t JOIN idn_oauth_refresh_token r ON r.access_token_id = t.id JOIN idn_user u ON u.id = t.user_id
    WHERE u.email = '${MIA_EMAIL}' AND r.client_id = '${clientId}' AND t.prefix = '${tokenPrefix}'`);
}

test('Connect on the options page allows the extension on the consent page, its calls carry the issued token, and Sign out revokes it', async ({ context, testPage, baseURL, request }) => {
  test.setTimeout(90_000);
  const appOrigin = String(baseURL);
  const redirectOrigin = `https://${EXTENSION_ID}.chromiumapp.org`;
  // Chrome's sign-in window stops at this address and never loads it; the tab standing in for it gets an empty page.
  await context.route(`${redirectOrigin}/**`, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '' }));
  await logInAsMia(context);
  const options = await context.newPage();
  await options.goto(`chrome-extension://${EXTENSION_ID}/options.html`);
  await stubSignInWindow(options);
  await options.getByLabel('pinround address').fill(appOrigin);

  await options.getByRole('button', { name: 'Connect to pinround' }).click();
  const consentAddress = new URL(await signInWindowAddressOf(options));
  expect(`${consentAddress.origin}${consentAddress.pathname}`).toBe(`${appOrigin}/oauth/authorize`);
  expect(consentAddress.searchParams.get('redirect_uri')).toBe(`${redirectOrigin}/`);
  expect(consentAddress.searchParams.get('code_challenge_method')).toBe('S256');
  const consent = await context.newPage();
  await consent.goto(consentAddress.href);
  await expect(consent.getByRole('heading', { name: CONSENT_HEADING, level: 1 })).toBeVisible();
  const kept = (await options.evaluate(async () => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    return (await chrome.storage.local.get('pinroundOAuthClient')).pinroundOAuthClient;
  })) as { clientId?: unknown } | undefined;
  expect(typeof kept?.clientId).toBe('string');
  const clientId = String(kept?.clientId);
  registeredClientIds.push(clientId);
  expect(await storedConnectionOf(options)).toBeUndefined();
  await consent.getByRole('radio', { name: 'Acme Foods' }).check();
  await consent.getByRole('button', { name: 'Allow' }).click();
  await consent.waitForURL(`${redirectOrigin}/**`);
  await finishSignInWindow(options, consent.url());
  await consent.close();

  await expect(options.locator('#status')).toHaveText(CONNECTED);
  await expect(options.locator('#paired-to')).toHaveText(`Connected to ${appOrigin}.`);
  const connection = await storedConnectionOf(options);
  expect(connection?.origin).toBe(appOrigin);
  expect(connection?.clientId).toBe(clientId);
  const issuedToken = String(connection?.token);
  const issuedRefreshToken = String(connection?.refreshToken);
  // Shapes only, so a failure never prints a token (ADR-0027).
  expect(/^pr_[A-Za-z0-9_-]{43}$/.test(issuedToken), 'the access token has the shape pinround issues').toBe(true);
  expect(/^prr_[A-Za-z0-9_-]{43}$/.test(issuedRefreshToken), 'the refresh token has the shape pinround issues').toBe(true);

  const ticketsAnswered = context.waitForEvent('response', (response: Response) => response.url().startsWith(`${appOrigin}/api/v1/me/tickets`));
  const popup = await openPopupFor(context, testPage);
  const ticketsAnswer = await ticketsAnswered;
  expect(ticketsAnswer.status()).toBe(200);
  expect((await ticketsAnswer.request().headerValue('authorization')) === `Bearer ${issuedToken}`, 'the popup called with the issued access token').toBe(true);
  await expect(popup.getByLabel('Put the screenshot in')).toBeVisible();
  expect(accessTokenRowsOf(clientId, issuedToken.slice(0, 11))).toEqual([`${clientId}|t|t|t`]);

  await options.bringToFront();
  await options.getByRole('button', { name: 'Sign out' }).click();
  await expect(options.locator('#status')).toHaveText(SIGNED_OUT);
  expect(await storedConnectionOf(options)).toBeUndefined();
  const afterSignOut = await request.get(`${appOrigin}/api/v1/me/tickets`, { headers: { Authorization: `Bearer ${issuedToken}` } });
  expect(afterSignOut.status()).toBe(401);
  const refreshAfterSignOut = await request.post(`${appOrigin}/oauth/token`, { form: { grant_type: 'refresh_token', refresh_token: issuedRefreshToken, client_id: clientId } });
  expect(refreshAfterSignOut.status()).toBe(400);
  expect(((await refreshAfterSignOut.json()) as { error?: unknown }).error).toBe('invalid_grant');
  expect(accessTokenRowsOf(clientId, issuedToken.slice(0, 11))).toEqual([`${clientId}|t|f|f`]);
});
