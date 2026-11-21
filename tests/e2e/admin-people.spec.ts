import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { logInAndTurnOnTwoStepLogin, SARA_EMAIL, turnOffTwoStepLogin, twoStepCodesFile } from './auth';

// As Sara, the demo admin whom no other spec logs in as: beforeAll turns her two-step login on, which also counts as
// the code the admin pages ask for, and afterAll turns it off with a recovery code. Inviting the same address again
// replaces the open invite, so a rerun leaves one pending row. The functional AdminPeopleTest and AdminShellTest cover the refusals.
// Every other Acme Foods person has a session that other specs share or turn two-step login on and off in, so the live
// token the list shows is one Sara makes on her own tokens page (her login in beforeAll is the recent proof it asks
// for); each test that makes one revokes it from the people page.
const SARA_STORAGE_STATE = path.join(__dirname, 'test-results', '.auth', 'sara-people.json');
const INVITED_EMAIL = 'mia@acme.test';
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// toast_controller.js: the text goes in this long after the toast connects, and the toast goes this long after that.
const TOAST_ANNOUNCE_DELAY_MS = 150;
const TOAST_SHOWN_FOR_MS = 3_600;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await logInAndTurnOnTwoStepLogin(await context.newPage(), SARA_EMAIL, 'Sara');
  await context.storageState({ path: SARA_STORAGE_STATE });
  await context.close();
});

test.afterAll(async ({ browser }) => {
  if (!existsSync(twoStepCodesFile(SARA_EMAIL)) || !existsSync(SARA_STORAGE_STATE)) {
    return;
  }
  const context = await browser.newContext({ storageState: SARA_STORAGE_STATE });
  await turnOffTwoStepLogin(await context.newPage(), SARA_EMAIL);
  await context.close();
});

test.use({ storageState: SARA_STORAGE_STATE });

async function openPeoplePage(page: Page): Promise<void> {
  await page.goto('/admin/people');
  await expect(page.getByRole('heading', { name: 'People and roles', level: 1 })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

// A rerun after a crash finds the earlier run's token still listed; its own name tells the two apart.
function uniqueTokenName(): string {
  return `Sara laptop ${randomUUID().slice(0, 8)}`;
}

async function makeToken(page: Page, tokenName: string): Promise<void> {
  await page.goto('/me/tokens');
  await page.getByLabel('Name', { exact: true }).fill(tokenName);
  await page.getByRole('button', { name: 'Make token' }).click();
  await expect(page.getByRole('heading', { name: 'Copy your token now', level: 2 })).toBeVisible();
}

function tokenRow(page: Page, tokenName: string): Locator {
  return page.locator('li.people__token-row', { hasText: tokenName });
}

async function revokeFromPeoplePage(page: Page, tokenName: string): Promise<void> {
  await openPeoplePage(page);
  await tokenRow(page, tokenName).getByRole('button', { name: /^Revoke / }).click();
  await expect(tokenRow(page, tokenName)).toHaveCount(0);
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

function adminRail(page: Page): Locator {
  return page.getByRole('navigation', { name: 'Admin' });
}

test('Sara reaches every admin tab from the menu and from Home', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Admin', exact: true }).click();

  await expect(page).toHaveURL('/admin/people');
  await expect(page.getByRole('heading', { name: 'People and roles', level: 1 })).toBeVisible();
  await expect(adminRail(page).getByRole('link')).toHaveText(['People and roles', 'AI']);
  await expect(adminRail(page).getByRole('link', { name: 'People and roles' })).toHaveAttribute('aria-current', 'page');

  await adminRail(page).getByRole('link', { name: 'AI', exact: true }).click();

  await expect(page).toHaveURL('/admin/ai');
  await expect(page.getByRole('heading', { name: 'AI', level: 1 })).toBeVisible();
  await expect(adminRail(page).getByRole('link', { name: 'AI', exact: true })).toHaveAttribute('aria-current', 'page');

  await page.goto('/');
  await page.getByRole('link', { name: 'Open admin settings' }).click();

  await expect(page).toHaveURL('/admin/people');
  await expect(adminRail(page)).toBeVisible();
});

// The demo seed: Lena leads Shop and Mobile as a developer and is an admin; Raj is the manager and views Shop with the
// job "other", which the line leaves out; Sara, the first admin, is in no team.
test('Sara sees the people of Acme Foods in name order with their roles and teams', async ({ page }) => {
  await openPeoplePage(page);

  const people = page.locator('main .people__person');
  await expect(people.locator('.people__name')).toHaveText(['Lena', 'Mia', 'Omar', 'Raj', 'Sara']);
  const personNamed = (name: string): Locator => people.filter({ has: page.locator('.people__name', { hasText: new RegExp(`^${name}$`) }) });
  await expect(personNamed('Lena').locator('.tag')).toHaveText(['Admin']);
  await expect(personNamed('Lena').locator('.people__sub')).toHaveText(['Developer · Mobile · Team lead', 'Developer · Shop · Team lead']);
  await expect(personNamed('Raj').locator('.tag')).toHaveText(['Manager']);
  await expect(personNamed('Raj').locator('.people__sub')).toHaveText(['Shop · Team viewer']);
  await expect(personNamed('Sara').locator('.tag')).toHaveText(['Admin']);
  await expect(personNamed('Sara').locator('.people__sub')).toHaveText(['No team yet']);
  await expect(page.locator('.people__members').getByRole('link', { name: 'Show more' })).toHaveCount(0);
});

test('the invite form\'s address opened by hand shows the people page', async ({ page }) => {
  await page.goto('/admin/people/invite');

  await expect(page).toHaveURL('/admin/people');
  await expect(page.getByRole('heading', { name: 'People and roles', level: 1 })).toBeVisible();
});

test('the Invite button waits for something that looks like an email address', async ({ page }) => {
  await page.goto('/admin/people');
  const email = page.getByLabel('Email');
  const invite = page.getByRole('button', { name: 'Invite' });

  await expect(invite).toBeDisabled();
  await email.fill('mia@');
  await expect(invite).toBeDisabled();
  await email.fill(INVITED_EMAIL);
  await expect(invite).toBeEnabled();
});

// The clock is paused before the invite, and the page after the redirect replays it, so only runFor moves the toast.
test('an admin invites an email address and sees the toast and the pending row', async ({ page }) => {
  await page.clock.install();
  await page.goto('/admin/people');
  await page.getByLabel('Email').fill(INVITED_EMAIL);
  await page.clock.pauseAt(Date.now() + 1_000);

  await page.getByRole('button', { name: 'Invite' }).click();

  const toast = page.locator('.toast-region .toast');
  await expect(page.locator('.people__row', { hasText: INVITED_EMAIL })).toContainText('Invited · not joined yet');
  await expect(toast).toHaveText('');
  await page.clock.runFor(TOAST_ANNOUNCE_DELAY_MS);
  await expect(toast).toHaveText(`Invite sent to ${INVITED_EMAIL}`);
  await page.clock.runFor(TOAST_SHOWN_FOR_MS - 1);
  await expect(toast).toHaveText(`Invite sent to ${INVITED_EMAIL}`);
  await page.clock.runFor(1);
  await expect(toast).toHaveCount(0);
});

test('an admin sees a live token, named for its owner on the Revoke button, and revokes it', async ({ page }) => {
  const tokenName = uniqueTokenName();
  await makeToken(page, tokenName);
  await openPeoplePage(page);

  const row = tokenRow(page, tokenName);
  await expect(row).toBeVisible();
  const revoke = row.getByRole('button', { name: new RegExp(`^Revoke .+’s token ${tokenName}, pr_[A-Za-z0-9_-]{8}…$`) });
  await expect(revoke).toHaveText('Revoke');

  await revoke.click();

  await expect(tokenRow(page, tokenName)).toHaveCount(0);
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the people page has no serious or critical accessibility violations in ${scheme}, with a live token`, async ({ page }) => {
    const tokenName = uniqueTokenName();
    await makeToken(page, tokenName);
    await page.emulateMedia({ colorScheme: scheme });
    await openPeoplePage(page);
    await expect(tokenRow(page, tokenName)).toBeVisible();

    expect(await seriousViolations(page)).toEqual([]);

    await revokeFromPeoplePage(page, tokenName);
  });
}

async function openAiPage(page: Page): Promise<void> {
  await page.goto('/admin/ai');
  await expect(page.getByRole('heading', { name: 'AI', level: 1 })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

for (const scheme of ['light', 'dark'] as const) {
  test(`the AI page has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await openAiPage(page);

    expect(await seriousViolations(page)).toEqual([]);
  });
}

test.describe('phone viewport', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('on the AI page each switch shares a line with its label, and Save key lines up with the key field', async ({ page }) => {
    await openAiPage(page);
    const switchRows = page.locator('.admin-ai__row--switch');
    await expect(switchRows).toHaveCount(3);

    for (const row of await switchRows.all()) {
      const switchBox = await row.getByRole('switch').boundingBox();
      const labelBox = await row.locator('span').boundingBox();
      expect(switchBox, 'the switch is laid out').not.toBeNull();
      expect(labelBox, 'the label is laid out').not.toBeNull();
      // Side by side: the label starts right of the switch and the two overlap in height.
      expect(labelBox!.x).toBeGreaterThan(switchBox!.x + switchBox!.width);
      expect(labelBox!.y).toBeLessThan(switchBox!.y + switchBox!.height);
      expect(switchBox!.y).toBeLessThan(labelBox!.y + labelBox!.height);
    }

    const keyBox = await page.getByLabel('API key').boundingBox();
    const saveBox = await page.getByRole('button', { name: 'Save key' }).boundingBox();
    expect(keyBox, 'the key field is laid out').not.toBeNull();
    expect(saveBox, 'Save key is laid out').not.toBeNull();
    // At this width Save key goes under the field, on its left edge, before the notes.
    expect(saveBox!.x).toBeCloseTo(keyBox!.x, 0);
    expect(saveBox!.y).toBeGreaterThanOrEqual(keyBox!.y + keyBox!.height);
    const statusBox = await page.locator('#admin-ai-key-status').boundingBox();
    expect(statusBox!.y).toBeGreaterThanOrEqual(saveBox!.y + saveBox!.height);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
});

test('on a wide screen Save key sits beside the key field, centred on it', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openAiPage(page);

  const keyBox = await page.getByLabel('API key').boundingBox();
  const saveBox = await page.getByRole('button', { name: 'Save key' }).boundingBox();
  expect(keyBox, 'the key field is laid out').not.toBeNull();
  expect(saveBox, 'Save key is laid out').not.toBeNull();
  expect(saveBox!.x).toBeGreaterThan(keyBox!.x + keyBox!.width);
  expect(saveBox!.y + saveBox!.height / 2).toBeCloseTo(keyBox!.y + keyBox!.height / 2, 0);
});

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('the people page does not scroll horizontally at 360px, with a live token', async ({ page }) => {
    const tokenName = uniqueTokenName();
    await makeToken(page, tokenName);
    await openPeoplePage(page);
    await expect(tokenRow(page, tokenName)).toBeVisible();

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);

    await revokeFromPeoplePage(page, tokenName);
  });
});
