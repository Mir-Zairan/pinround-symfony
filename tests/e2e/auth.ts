import path from 'node:path';

export const LENA_EMAIL = 'lena@acme-foods.example';

// Written by auth.setup.ts and read by the specs that open a logged-in page. It sits in test-results/, which is git-ignored.
export const LENA_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'lena.json');

/** The password make seed gave the demo people (DEMO_PASSWORD); the tests never hold a value of their own. */
export function demoPassword(): string {
  const password = process.env.E2E_PASSWORD;
  if (!password) {
    throw new Error('E2E_PASSWORD is not set. Set it to the DEMO_PASSWORD the demo seed used (see docs/operations/config.md).');
  }
  return password;
}
