import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

export const TOAST_REGION = '#toast-region';

/** As Lena, who leads Shop and so may give a new ticket to someone: it starts in To do, given to Omar. Returns its key. */
export async function makeTicketForOmar(browser: Browser, title: string): Promise<string> {
  return makeTicketGivenTo(browser, title, 'Omar');
}

/** As Lena: a new SHOP ticket in To do, given to the Shop member with that first name. Returns its key. */
export async function makeTicketGivenTo(browser: Browser, title: string, assigneeFirstName: string): Promise<string> {
  const context = await browser.newContext({ storageState: LENA_STORAGE_STATE, ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await page.goto('/tickets/new');
  const shopValue = await page.locator('select[name="project_id"] option', { hasText: /^SHOP · / }).getAttribute('value');
  expect(shopValue, 'the seeded SHOP project is missing: run make seed').not.toBeNull();
  await page.getByLabel('Project').selectOption(shopValue ?? '');
  await page.getByLabel('Title', { exact: true }).fill(title);
  await page.getByLabel('Give it to').selectOption({ label: assigneeFirstName });
  await page.getByRole('button', { name: 'Create ticket' }).click();
  await expect(page).toHaveURL(/\/t\/SHOP-\d+$/);
  const key = new URL(page.url()).pathname.replace('/t/', '');
  await expectTicketPageOf(page, title);
  await context.close();
  return key;
}

/**
 * The page a Create ticket redirects to shows the new ticket. Not its "Ticket … made." toast: every Lena spec shares one
 * session, and a page another worker opens in it at that moment takes the flash. NewTicketTest checks the toast.
 */
export async function expectTicketPageOf(page: Page, title: string): Promise<void> {
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(title);
}

/** As someone who may give the ticket, on its page: gives it to the person with that first name through the picker. */
export async function giveTicketTo(page: Page, key: string, firstName: string): Promise<void> {
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await page.locator('#ticket-next-step').getByRole('button', { name: /^Give it to/ }).click();
  const dialog = page.getByRole('dialog', { name: `Give ${key} to…` });
  const person = dialog.locator('.pick').filter({ has: page.locator('.pick__name', { hasText: new RegExp(`^${firstName}$`) }) });
  const given = page.waitForResponse((response) => new URL(response.url()).pathname === `/t/${key}/assign` && response.request().method() === 'POST');
  await person.click();
  expect((await given).status()).toBe(200);
  await expect(page.locator(TOAST_REGION)).toHaveText(`Given to ${firstName}.`);
}

export async function openAs(browser: Browser, storageState: string): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState, ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } });
  return { context, page: await context.newPage() };
}

export function threadFrame(page: Page) {
  return page.locator('turbo-frame#ticket-thread');
}

export function composerBody(page: Page) {
  return threadFrame(page).locator('#thread-composer-body');
}

/** Opens the ticket and scrolls to its thread, whose lazy frame loads from GET /t/{key}/thread once it is in view. */
export async function openThread(page: Page, key: string): Promise<void> {
  const frameLoaded = page.waitForResponse((response) => new URL(response.url()).pathname === `/t/${key}/thread` && response.request().method() === 'GET');
  await page.goto(`/t/${key}`);
  await page.getByRole('heading', { level: 2, name: 'Questions and comments' }).scrollIntoViewIfNeeded();
  expect((await frameLoaded).status()).toBe(200);
  await expect(composerBody(page)).toBeVisible();
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.waitForLoadState('networkidle');
}

/** The headline of the logged-in person's own unshared draft round in the Rounds section. */
export const DRAFT_ROW_HEADLINE = 'Draft round · only you can see this';

/**
 * From the ticket page, opens the logged-in person's own unshared draft round with its row's "Open draft" and waits until
 * its editor is ready.
 */
export async function openOwnDraft(page: Page, key: string): Promise<void> {
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  const row = page.locator('article.round-card--private');
  await expect(row.getByRole('heading', { name: DRAFT_ROW_HEADLINE, level: 3 })).toBeVisible();
  await row.getByRole('link', { name: 'Open draft' }).click();
  await expectOnDraftPage(page);
}

/** Waits until the draft page's editor is ready: its heading is shown and its Stimulus controllers are connected. */
export async function expectOnDraftPage(page: Page): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Draft round', level: 1, exact: true })).toBeVisible();
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.waitForLoadState('networkidle');
}
