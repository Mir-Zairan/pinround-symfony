import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

export const TOAST_REGION = '#toast-region';

/** As Lena, who leads Shop and so may give a new ticket to someone: it starts in To do, given to Omar. Returns its key. */
export async function makeTicketForOmar(browser: Browser, title: string): Promise<string> {
  const context = await browser.newContext({ storageState: LENA_STORAGE_STATE, ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await page.goto('/tickets/new');
  const shopValue = await page.locator('select[name="project_id"] option', { hasText: /^SHOP · / }).getAttribute('value');
  expect(shopValue, 'the seeded SHOP project is missing: run make seed').not.toBeNull();
  await page.getByLabel('Project').selectOption(shopValue ?? '');
  await page.getByLabel('Title').fill(title);
  await page.getByLabel('Give it to').selectOption({ label: 'Omar' });
  await page.getByRole('button', { name: 'Make ticket' }).click();
  await expect(page).toHaveURL(/\/t\/SHOP-\d+$/);
  const key = new URL(page.url()).pathname.replace('/t/', '');
  await expect(page.locator(TOAST_REGION)).toHaveText(`Ticket ${key} made.`);
  await context.close();
  return key;
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
