import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

// Lena makes a SHOP ticket with nobody to give it to, so it starts in Backlog, with a title no earlier run used; the
// give test leaves it in To do with Omar, the 360 px test in Backlog. tests/Functional/Ticketing/GiveItToTest.php proves who sees the button, the
// picker's order and the give on the server.
const TOAST_REGION = '#toast-region';

test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function makeBacklogTicket(page: Page, title: string): Promise<string> {
  await page.goto('/tickets/new');
  const shopValue = await page.locator('select[name="project_id"] option', { hasText: /^SHOP · / }).getAttribute('value');
  expect(shopValue, 'the seeded SHOP project is missing: run make seed').not.toBeNull();
  await page.getByLabel('Project').selectOption(shopValue ?? '');
  await page.getByLabel('Title', { exact: true }).fill(title);
  await page.getByRole('button', { name: 'Make ticket' }).click();
  await expect(page).toHaveURL(/\/t\/SHOP-\d+$/);
  return new URL(page.url()).pathname.replace('/t/', '');
}

async function openTicket(page: Page, key: string, title: string): Promise<void> {
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
}

function nextStepBox(page: Page) {
  return page.locator('#ticket-next-step');
}

function statusPill(page: Page) {
  return page.locator('#ticket-state .ticket__state').getByText(/^(Backlog|To do|In progress|Code review|Testing|Needs input|On hold|Done)$/);
}

async function openAsOmar(browser: Browser, key: string, title: string): Promise<void> {
  const context = await browser.newContext({ storageState: OMAR_STORAGE_STATE, ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } });
  const page = await context.newPage();
  await openTicket(page, key, title);
  await expect(nextStepBox(page).getByRole('button', { name: /^Give it to/ })).toHaveCount(0);
  await context.close();
}

test('Lena gives a Backlog ticket to Omar through the person picker', async ({ browser, page }) => {
  const title = `Gift card balance rounds down ${randomUUID().slice(0, 8)}`;
  const key = await makeBacklogTicket(page, title);
  await openTicket(page, key, title);
  await expect(statusPill(page)).toHaveText('Backlog');
  await openAsOmar(browser, key, title);

  await nextStepBox(page).getByRole('button', { name: 'Give it to…' }).click();

  const dialog = page.getByRole('dialog', { name: `Give ${key} to…` });
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText('The ticket moves to their list. Sorted by how much is already waiting on them.');
  const omar = dialog.locator('.pick').filter({ has: page.locator('.pick__name', { hasText: /^Omar$/ }) });
  await expect(omar).toHaveCount(1);
  await expect(omar.locator('.pick__side')).toHaveText(/^\d+ waiting$/);
  expect(await seriousViolations(page)).toEqual([]);

  const answer = page.waitForResponse((response) => response.url().endsWith(`/t/${key}/assign`) && response.request().method() === 'POST');
  await omar.click();
  const response = await answer;
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('text/vnd.turbo-stream.html');

  await expect(dialog).toBeHidden();
  await expect(page.locator(TOAST_REGION)).toHaveText('Given to Omar.');
  await expect(statusPill(page)).toHaveText('To do');
  await expect(nextStepBox(page).getByRole('button', { name: 'Give it to someone else…' })).toBeVisible();
});

test('at 360 px the opened picker fits and the page does not scroll sideways', async ({ page }) => {
  const title = `Gift wrap option hides the total ${randomUUID().slice(0, 8)}`;
  const key = await makeBacklogTicket(page, title);
  await page.setViewportSize({ width: 360, height: 800 });
  await openTicket(page, key, title);

  await nextStepBox(page).getByRole('button', { name: 'Give it to…' }).click();

  const dialog = page.getByRole('dialog', { name: `Give ${key} to…` });
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('.pick').first()).toBeVisible();
  const { scrollWidth, clientWidth } = await page.evaluate(() => {
    const scrollingElement = document.scrollingElement ?? document.documentElement;
    return { scrollWidth: scrollingElement.scrollWidth, clientWidth: scrollingElement.clientWidth };
  });
  expect(clientWidth).toBeLessThanOrEqual(360);
  expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
  const box = await dialog.boundingBox();
  expect(box).not.toBeNull();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(clientWidth);
});
