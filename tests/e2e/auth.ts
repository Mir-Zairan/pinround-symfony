import { createHmac } from 'node:crypto';
import path from 'node:path';

export const LENA_EMAIL = 'lena@acme-foods.example';

// Written by auth.setup.ts and read by the specs that open a logged-in page. It sits in test-results/, which is git-ignored.
export const LENA_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'lena.json');

// Omar's own session for the specs that save a preference, so Lena's pages in the other specs keep their look.
export const OMAR_EMAIL = 'omar@acme-foods.example';
export const OMAR_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'omar.json');

/** The password make seed gave the demo people (DEMO_PASSWORD); the tests never hold a value of their own. */
export function demoPassword(): string {
  const password = process.env.E2E_PASSWORD;
  if (!password) {
    throw new Error('E2E_PASSWORD is not set. Set it to the DEMO_PASSWORD the demo seed used (see docs/operations/config.md).');
  }
  return password;
}

// Mia is the one demo person with two-step login on: login-code.spec.ts turns it on at its first run. Her key sits in the
// git-ignored tests/e2e/.auth/, not test-results/, which Playwright empties at every run, so a person can still log in
// as her in dev.
export const MIA_EMAIL = 'mia@acme-foods.example';
export const MIA_TWO_STEP_KEY_FILE = path.join(__dirname, '.auth', 'mia-two-step-key.txt');

export const TWO_STEP_CODE_STEP_MS = 30_000;

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
