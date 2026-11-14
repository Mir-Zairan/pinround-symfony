import { expect, test, type Browser, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { makeTicketForOmar, openAs, TOAST_REGION } from './ticket';

// Auto-clear (FR-PNG-05) is on in the demo seed. Each run makes its own SHOP ticket, with a title no earlier run used,
// so the ping limit never refuses a rerun. tests/Functional/Ticketing/MoveClearsPingsTest.php proves on the server
// which pings a move clears and which it leaves alone, for every move path.
test.use({ storageState: OMAR_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

async function pingOmarAbout(browser: Browser, key: string, message: string): Promise<void> {
  const { context, page } = await openAs(browser, LENA_STORAGE_STATE);
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ping Omar' }).click();
  const sheet = page.getByRole('dialog', { name: 'Ping Omar' });
  await sheet.getByLabel('Message').fill(message);
  const answer = page.waitForResponse((response) => response.url().includes(`/t/${key}/ping/`) && response.request().method() === 'POST');
  await sheet.getByRole('button', { name: 'Send ping' }).click();
  expect((await answer).status()).toBe(200);
  await expect(page.locator(TOAST_REGION)).toHaveText('Pinged Omar. One notification sent.');
  await context.close();
}

function bell(page: Page) {
  return page.getByRole('banner').getByRole('button', { name: 'Pings' });
}

function popover(page: Page) {
  return page.getByRole('dialog', { name: /^Pings · \d+ open$/ });
}

async function openPopoverOn(page: Page, path: string): Promise<void> {
  await page.goto(path, { waitUntil: 'networkidle' });
  const frameLoaded = page.waitForResponse((response) => new URL(response.url()).pathname === '/bell' && response.request().method() === 'GET');
  await bell(page).click();
  expect((await frameLoaded).status()).toBe(200);
  await expect(popover(page)).toBeVisible();
  // The frame's own text, so a check that a card is absent never runs before the frame has rendered.
  await expect(popover(page).getByText('Grouped by person and ticket.')).toBeVisible();
}

function lenasCardOn(page: Page, key: string) {
  return popover(page).getByRole('article', { name: `Lena pinged you on ${key}` });
}

test('Omar starting work on a ticket clears Lena’s ping from his bell and the toast says so', async ({ browser, page }) => {
  const title = `Voucher code accepts trailing spaces ${randomUUID().slice(0, 8)}`;
  const message = `Can you start on the voucher fix? ${randomUUID().slice(0, 8)}`;
  const key = await makeTicketForOmar(browser, title);
  await pingOmarAbout(browser, key, message);

  // The ping reaches the bell through the outbox and its worker, a moment after Lena sent it.
  await expect(async () => {
    await openPopoverOn(page, `/t/${key}`);
    await expect(lenasCardOn(page, key)).toBeVisible({ timeout: 1_000 });
  }).toPass({ intervals: [500, 1_000, 2_000], timeout: 20_000 });
  await expect(lenasCardOn(page, key).getByText(`“${message}”`)).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(popover(page)).toBeHidden();

  const answer = page.waitForResponse((response) => response.url().endsWith(`/t/${key}/move/start_work`) && response.request().method() === 'POST');
  await page.locator('#ticket-next-step .next-step-box__verbs').getByRole('button', { name: 'Start work' }).click();
  expect((await answer).status()).toBe(200);

  await expect(page.locator(TOAST_REGION)).toHaveText('Started. Status is In progress. 1 ping on this ticket cleared.');
  await openPopoverOn(page, '/');
  await expect(lenasCardOn(page, key)).toHaveCount(0);
});
