import { expect, type Page } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { existsSync } from 'node:fs';
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';

export const LENA_EMAIL = 'lena@acme-foods.example';

// Written by auth.setup.ts and read by the specs that open a logged-in page. It sits in test-results/, which is git-ignored.
export const LENA_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'lena.json');

// Lena's second session, for team-page.spec.ts alone, which reads the toast each of its redirects flashed. The specs on
// other workers that use LENA_STORAGE_STATE share that one session, and a page any of them loads between a redirect and
// its page takes the flash out of it, or writes back a copy read before the flash. A second spec here would do the same.
export const LENA_TEAM_PAGE_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'lena-team-page.json');

// Omar's own session for the specs that save a preference, so Lena's pages in the other specs keep their look.
export const OMAR_EMAIL = 'omar@acme-foods.example';
export const OMAR_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'omar.json');

// Omar's second session, for the own-change toast test of live-updates.spec.ts alone, which reads the toast its
// comment's redirect flashed; OMAR_STORAGE_STATE's session is shared and loses flashes as LENA_TEAM_PAGE_STORAGE_STATE says.
export const OMAR_OWN_CHANGE_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'omar-own-change.json');

// Ben leads Ovens, the team of Beta Bakery, the one demo organization under the step policy team.
export const BEN_EMAIL = 'ben@beta-bakery.example';
export const BEN_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'ben.json');

/** The password make seed gave the demo people (DEMO_PASSWORD); the tests never hold a value of their own. */
export function demoPassword(): string {
  const password = process.env.E2E_PASSWORD;
  if (!password) {
    throw new Error('E2E_PASSWORD is not set. Set it to the DEMO_PASSWORD the demo seed used (see docs/operations/config.md).');
  }
  return password;
}

// Each spec that needs two-step login on has a demo person of its own, so specs running at the same time never change
// each other's state: login-code.spec.ts has Mia, confirm-its-you.spec.ts has Raj. Each turns it on in beforeAll and
// off in afterAll, so every run starts from the demo seed's state, with it off.
export const MIA_EMAIL = 'mia@acme-foods.example';
export const RAJ_EMAIL = 'raj@acme-foods.example';

// Raj's session from auth.setup.ts, which logs him in before any spec turns his two-step login on. Turning it on or off
// keeps his session stamp, so this session stays logged in while confirm-its-you.spec.ts runs; ticket-move.spec.ts
// uses it. Missing when a local run found his two-step login already on (auth.setup.ts says why).
export const RAJ_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'raj.json');

// Mia's session from auth.setup.ts, logged in before login-code.spec.ts turns her two-step login on; live-updates.spec.ts
// uses it. Missing when a local run found her two-step login already on, as for Raj.
export const MIA_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'mia.json');
export const SARA_EMAIL = 'sara@acme-foods.example';

export const TWO_STEP_CODE_STEP_MS = 30_000;

/** The key and the recovery codes not typed yet of a person whose two-step login a spec turned on. */
interface TwoStepLoginCodes {
  key: string;
  recoveryCodes: string[];
}

/**
 * Where a spec keeps the codes while it has two-step login on: the git-ignored tests/e2e/.auth/, not test-results/,
 * which Playwright empties at every run, so a later run can still log in and turn it off after a crash.
 */
export function twoStepCodesFile(email: string): string {
  return path.join(__dirname, '.auth', `${email.split('@')[0]}-two-step.json`);
}

/**
 * Takes one recovery code and saves the others, so no code is typed twice. A recovery code needs no wait for a new
 * 30-second step, and it takes one of the person's 5 code tries in 15 minutes like any code.
 */
async function takeRecoveryCode(email: string): Promise<string> {
  const file = twoStepCodesFile(email);
  if (!existsSync(file)) {
    throw new Error(`${email} has two-step login on, but ${file} is missing: turn it off by hand or rebuild the dev database.`);
  }
  const codes = JSON.parse(await readFile(file, 'utf8')) as TwoStepLoginCodes;
  const recoveryCode = codes.recoveryCodes.shift();
  if (undefined === recoveryCode) {
    throw new Error(`${file} has no recovery code left.`);
  }
  await writeFile(file, JSON.stringify(codes));
  return recoveryCode;
}

/** On a logged-in page: turns two-step login off on /me/security with the password and a recovery code. */
export async function turnOffTwoStepLogin(page: Page, email: string): Promise<void> {
  await page.goto('/me/security');
  await page.getByLabel('Password', { exact: true }).fill(demoPassword());
  await page.getByLabel('Code from your app').fill(await takeRecoveryCode(email));
  await page.getByRole('button', { name: 'Turn off two-step login' }).click();
  await expect(page.getByText('Two-step login is off.')).toBeVisible();
  await rm(twoStepCodesFile(email), { force: true });
}

/**
 * Logs the person in with the password and turns two-step login on, keeping the key and the recovery codes in
 * twoStepCodesFile. The page stays logged in, in a session that never typed a code. When an earlier run stopped with it
 * on, this logs in with a saved recovery code and turns it off first.
 */
export async function logInAndTurnOnTwoStepLogin(page: Page, email: string, firstName: string): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(demoPassword());
  await page.getByRole('button', { name: 'Log in' }).click();
  const codePageHeading = page.getByRole('heading', { name: 'Type your code', level: 1 });
  const avatar = page.getByRole('button', { name: firstName, exact: true });
  await expect(codePageHeading.or(avatar)).toBeVisible();
  if (await codePageHeading.isVisible()) {
    await page.getByLabel('Code from your app').fill(await takeRecoveryCode(email));
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(avatar).toBeVisible();
    await turnOffTwoStepLogin(page, email);
  }

  await page.goto('/me/security/two-step');
  const setUp = page.getByRole('button', { name: 'Set up two-step login' });
  const getNewQrCode = page.getByRole('button', { name: 'Get a new QR code' });
  await expect(setUp.or(getNewQrCode)).toBeVisible();
  await ((await setUp.isVisible()) ? setUp : getNewQrCode).click();
  const key = (await page.locator('main .two-step__key').innerText()).replace(/\s/g, '');
  await page.getByLabel('Code from the app').fill(codeOf(key, Date.now()));
  await page.getByRole('button', { name: 'Turn on' }).click();
  await expect(page.getByRole('heading', { name: 'Two-step login is on', level: 1 })).toBeVisible();
  const recoveryCodes = await page.locator('main .two-step__codes li').allInnerTexts();
  expect(recoveryCodes).toHaveLength(10);
  await mkdir(path.dirname(twoStepCodesFile(email)), { recursive: true });
  await writeFile(twoStepCodesFile(email), JSON.stringify({ key, recoveryCodes } satisfies TwoStepLoginCodes));
}

/** The RFC 6238 code of a base32 key at a time in milliseconds: SHA-1, 6 digits, 30-second steps, as the server checks it. */
export function codeOf(key: string, at: number): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bytes: number[] = [];
  let bits = 0;
  let buffer = 0;
  for (const character of key.toUpperCase()) {
    buffer = ((buffer << 5) | alphabet.indexOf(character)) & 0xffff;
    bits += 5;
    if (bits >= 8) {
      bits -= 8;
      bytes.push((buffer >>> bits) & 0xff);
    }
  }
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(at / TWO_STEP_CODE_STEP_MS)));
  const hmac = createHmac('sha1', Buffer.from(bytes)).update(counter).digest();
  const offset = hmac[hmac.length - 1] & 0x0f;
  return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}
