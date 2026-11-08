import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { codeOf, demoPassword, MIA_EMAIL, MIA_TWO_STEP_KEY_FILE, TWO_STEP_CODE_STEP_MS } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// As Mia, whom only confirm-its-you.spec.ts also logs in as: the first test turns her two-step login on and writes her
// key to MIA_TWO_STEP_KEY_FILE (auth.ts), and the last one turns it off on the security page and deletes the file, so
// the next run starts as the demo seed left her. The functional LoginCodeTest and TwoStepLoginOffTest cover the codes,
// the refusals and the throttle.

test.use({ storageState: { cookies: [], origins: [] } });
// One at a time, so two first runs never both turn two-step login on.
test.describe.configure({ mode: 'serial' });

async function typePassword(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(MIA_EMAIL);
  await page.getByLabel('Password').fill(demoPassword());
  await page.getByRole('button', { name: 'Log in' }).click();
}

async function turnOnTwoStepLogin(page: Page): Promise<void> {
  await page.goto('/me/security/two-step');
  const setUp = page.getByRole('button', { name: 'Set up two-step login' });
  const getNewQrCode = page.getByRole('button', { name: 'Get a new QR code' });
  await expect(setUp.or(getNewQrCode)).toBeVisible();
  await ((await setUp.isVisible()) ? setUp : getNewQrCode).click();
  const key = (await page.locator('main .two-step__key').innerText()).replace(/\s/g, '');
  await page.getByLabel('Code from the app').fill(codeOf(key, Date.now()));
  await page.getByRole('button', { name: 'Turn on' }).click();
  await expect(page.getByRole('heading', { name: 'Two-step login is on', level: 1 })).toBeVisible();
  await mkdir(path.dirname(MIA_TWO_STEP_KEY_FILE), { recursive: true });
  await writeFile(MIA_TWO_STEP_KEY_FILE, `${key}\n`);
}

async function openCodePage(page: Page): Promise<void> {
  await typePassword(page);
  const codePageHeading = page.getByRole('heading', { name: 'Type your code', level: 1 });
  const avatar = page.getByRole('button', { name: 'Mia', exact: true });
  await expect(codePageHeading.or(avatar)).toBeVisible();
  if (await avatar.isVisible()) {
    await turnOnTwoStepLogin(page);
    await page.context().clearCookies();
    await typePassword(page);
  } else if (!existsSync(MIA_TWO_STEP_KEY_FILE)) {
    // The code page needs no key, so the spec still runs; only a person logging in as Mia does.
    test.info().annotations.push({ type: 'warning', description: `Mia's two-step login is on, but ${MIA_TWO_STEP_KEY_FILE} is missing: a person cannot log in as her in dev until the database is rebuilt.` });
  }
  await expect(codePageHeading).toBeVisible();
  await expect(page).toHaveURL(/\/login\/code$/);
  await page.evaluate(() => document.fonts.ready);
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test('the code page shows the code field, the hint and the two buttons', async ({ page }) => {
  await openCodePage(page);

  const codeField = page.getByLabel('Code from your app');
  await expect(codeField).toHaveAttribute('autocomplete', 'one-time-code');
  await expect(codeField).toBeFocused();
  await expect(page.getByText('6 digits. No phone with you? Type one of your recovery codes instead.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Cancel' })).toBeVisible();
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the code page has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await openCodePage(page);

    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the code page does not scroll horizontally at 360px', async ({ page }) => {
    await openCodePage(page);

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
});

async function waitForTheNextStep(page: Page): Promise<void> {
  await page.waitForTimeout(TWO_STEP_CODE_STEP_MS - (Date.now() % TWO_STEP_CODE_STEP_MS) + 1_000);
}

/**
 * Types the code of the step at(), and the password when the form asks for one. A code counts once, so when another
 * spec just used it, this waits for the next step and tries once more. Every try counts against Mia's 5 code tries in
 * 15 minutes, so a run takes two when nothing else logs her in.
 */
async function typeCode(page: Page, key: string, at: () => number, submit: string, done: Locator, withPassword = false): Promise<void> {
  const send = async (): Promise<void> => {
    if (withPassword) {
      await page.getByLabel('Password', { exact: true }).fill(demoPassword());
    }
    await page.getByLabel('Code from your app').fill(codeOf(key, at()));
    await page.getByRole('button', { name: submit }).click();
  };
  await send();
  const refusal = page.getByRole('alert');
  await expect(done.or(refusal)).toBeVisible();
  if (await refusal.isVisible()) {
    await expect(refusal, 'Mia used up her code tries: wait 15 minutes before the next run.').not.toContainText('Too many tries');
    await waitForTheNextStep(page);
    await send();
    await expect(done).toBeVisible();
  }
}

test('the security page turns two-step login off with the password and a code', async ({ page }) => {
  test.skip(!existsSync(MIA_TWO_STEP_KEY_FILE), `${MIA_TWO_STEP_KEY_FILE} is missing, so Mia's two-step login cannot be turned off here.`);
  // Up to two waits for a new 30-second step.
  test.setTimeout(100_000);
  const key = readFileSync(MIA_TWO_STEP_KEY_FILE, 'utf8').trim();
  await openCodePage(page);
  // The key file is written when the setup spends its code, so a login in that same step would be refused.
  if (Math.floor(statSync(MIA_TWO_STEP_KEY_FILE).mtimeMs / TWO_STEP_CODE_STEP_MS) === Math.floor(Date.now() / TWO_STEP_CODE_STEP_MS)) {
    await waitForTheNextStep(page);
  }
  await typeCode(page, key, () => Date.now(), 'Log in', page.getByRole('button', { name: 'Mia', exact: true }));

  await page.goto('/me/security');
  await expect(page.getByText('Admin pages need two-step login.')).toBeVisible();
  // The login spent the code of this step; the server also takes the next step's code, which is not spent yet.
  await typeCode(page, key, () => Date.now() + TWO_STEP_CODE_STEP_MS, 'Turn off two-step login', page.getByText('Two-step login is off.'), true);

  await expect(page.getByRole('link', { name: 'Set up two-step login' })).toBeVisible();
  await rm(MIA_TWO_STEP_KEY_FILE, { force: true });
});
