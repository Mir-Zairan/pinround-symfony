import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { demoPassword, LENA_STORAGE_STATE, OMAR_STORAGE_STATE, RAJ_EMAIL } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

// The spec moves tickets, so it never touches the seeded ones the other specs read: Lena makes a SHOP ticket for Omar
// in To do for each journey, with a title no earlier run used, and the run leaves it where its last move put it.
// TicketMoveControllerTest proves every verb, refusal and the redirect without Turbo on the server.
const TOAST_REGION = '#toast-region';
const MOVE_FAILED = 'Could not move the ticket. Try again.';
const PENDING_TOAST_KEY = 'pinround.pendingToast';

test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

/** As Lena, who leads Shop and so may give a new ticket to someone: it starts in To do, given to Omar. */
async function makeTicketForOmar(browser: Browser, title: string): Promise<string> {
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

async function openAs(browser: Browser, storageState: string): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState, ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } });
  return { context, page: await context.newPage() };
}

async function openTicket(page: Page, key: string, title: string): Promise<void> {
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
}

/** Marks the document, so a check after a move proves the page was not loaded again. */
async function markThisLoad(page: Page): Promise<void> {
  await page.evaluate(() => document.documentElement.setAttribute('data-e2e-load', 'before-move'));
}

async function expectSameLoad(page: Page): Promise<void> {
  await expect(page.locator('html')).toHaveAttribute('data-e2e-load', 'before-move');
}

function nextStepBox(page: Page) {
  return page.locator('#ticket-next-step');
}

function verbButtons(page: Page) {
  return nextStepBox(page).locator('.next-step-box__verbs').getByRole('button');
}

function statusPill(page: Page) {
  return page.locator('#ticket-state .ticket__state').getByText(/^(Backlog|To do|In progress|Code review|Testing|Needs input|On hold|Done)$/);
}

/** Clicks a verb and waits for its answer, which a Turbo Stream gives with the status the move ended with. */
async function clickVerb(page: Page, label: string, expectedStatus: number): Promise<void> {
  const answer = page.waitForResponse((response) => /\/t\/SHOP-\d+\/move\//.test(response.url()) && response.request().method() === 'POST');
  await verbButtons(page).filter({ hasText: label }).click();
  const response = await answer;
  expect(response.status()).toBe(expectedStatus);
  expect(response.headers()['content-type']).toContain('text/vnd.turbo-stream.html');
}

test.describe('four people move one SHOP ticket', () => {
  test.describe.configure({ mode: 'serial' });

  const title = `Basket total ignores a removed coupon ${randomUUID().slice(0, 8)}`;
  let key = '';

  test.beforeAll(async ({ browser }) => {
    key = await makeTicketForOmar(browser, title);
  });

  test('Omar starts work and sends it to code review, and the page updates in place', async ({ browser }) => {
    const { context, page } = await openAs(browser, OMAR_STORAGE_STATE);
    await openTicket(page, key, title);
    await expect(statusPill(page)).toHaveText('To do');
    await expect(verbButtons(page)).toHaveText(['Start work']);
    expect(await seriousViolations(page)).toEqual([]);
    await markThisLoad(page);

    await clickVerb(page, 'Start work', 200);

    await expect(page.locator(TOAST_REGION)).toHaveText('Started. Status is In progress.');
    await expect(statusPill(page)).toHaveText('In progress');
    await expect(nextStepBox(page).getByRole('heading', { name: 'Your next step' })).toBeFocused();
    await expect(verbButtons(page).first()).toHaveText('Send to code review');
    await expectSameLoad(page);

    await clickVerb(page, 'Send to code review', 200);

    await expect(page.locator(TOAST_REGION)).toHaveText('Sent to code review. Lena’s turn now.');
    await expect(statusPill(page)).toHaveText('Code review');
    await expect(nextStepBox(page).locator('.next-step-box__sentence')).toHaveText('Nothing for you now. Lena’s turn.');
    await expect(verbButtons(page)).toHaveCount(0);
    await expect(nextStepBox(page).getByRole('heading', { name: 'Your next step' })).toBeFocused();
    await expectSameLoad(page);
    await context.close();
  });

  test('Lena approves the code, then moves it to Needs input on Omar from the move list', async ({ page }) => {
    await openTicket(page, key, title);
    await markThisLoad(page);

    await clickVerb(page, 'Approve code', 200);

    await expect(page.locator(TOAST_REGION)).toHaveText('Code approved. Mia’s turn to test.');
    await expect(statusPill(page)).toHaveText('Testing');
    expect(await seriousViolations(page)).toEqual([]);

    const moveList = nextStepBox(page).locator('details.next-step-box__move-to');
    await moveList.getByText('Move to another step…').click();
    await expect(moveList).toHaveAttribute('open', '');
    const groupLabels = await moveList.locator('.next-step-box__group-label').allInnerTexts();
    expect(groupLabels.length).toBeGreaterThan(1);
    expect(groupLabels).toContain('Checking');
    expect(groupLabels).toContain('Waiting');
    const current = moveList.locator('[aria-current="step"]');
    await expect(current).toHaveCount(1);
    await expect(current).toHaveText('Testing');
    await expect(current).toBeDisabled();
    await expect(moveList.getByRole('group', { name: 'Checking' })).toContainText('Testing');
    expect(await seriousViolations(page)).toEqual([]);

    await moveList.getByRole('button', { name: 'Needs input' }).click();
    const dialog = page.getByRole('dialog', { name: 'Move to Needs input' });
    await expect(dialog).toBeVisible();
    const people = dialog.getByRole('radio');
    await expect(people.first()).toBeChecked();
    await expect(dialog.getByRole('radio', { name: 'Someone else' })).toBeVisible();
    await expect(dialog.getByRole('textbox', { name: 'Who else it waits on' })).toBeDisabled();
    expect(await seriousViolations(page)).toEqual([]);
    const omar = dialog.getByRole('radio', { name: /^Omar · / });
    await expect(omar).not.toBeChecked();
    await omar.check();

    const answer = page.waitForResponse((response) => response.url().includes(`/t/${key}/move-to/`) && response.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'Move' }).click();
    expect((await answer).status()).toBe(200);

    await expect(dialog).toBeHidden();
    await expect(page.locator(TOAST_REGION)).toHaveText('Moved to Needs input.');
    await expect(statusPill(page)).toHaveText('Needs input');
    await expect(page.locator('#ticket-state .ticket__now')).toContainText('Omar');
    await expect(nextStepBox(page).locator('.next-step-box__sentence')).toHaveText('Nothing for you now. Omar’s turn.');
    await expectSameLoad(page);
  });

  // Raj is Shop's Viewer and a Manager of Acme Foods: he sees the ticket but may not move it.
  test('Raj sees the ticket with no verb and no move list', async ({ browser }) => {
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    await page.goto('/login');
    await page.getByLabel('Email').fill(RAJ_EMAIL);
    await page.getByLabel('Password').fill(demoPassword());
    await page.getByRole('button', { name: 'Log in' }).click();
    const codePage = page.getByRole('heading', { name: 'Type your code', level: 1 });
    const avatar = page.getByRole('button', { name: 'Raj', exact: true });
    await expect(codePage.or(avatar)).toBeVisible();
    expect(
      await codePage.isVisible(),
      'Raj has two-step login on: confirm-its-you.spec.ts turns it on while it runs, or an earlier run left it on (see auth.ts)',
    ).toBe(false);

    await openTicket(page, key, title);

    await expect(statusPill(page)).toHaveText('Needs input');
    await expect(nextStepBox(page).locator('.next-step-box__sentence')).toHaveText(/^Nothing for you\. /);
    await expect(nextStepBox(page).locator('form')).toHaveCount(0);
    await expect(page.getByText('Move to another step…')).toHaveCount(0);
    await context.close();
  });
});

test.describe('a stale page and a failed move', () => {
  test.use({ storageState: OMAR_STORAGE_STATE });

  test('a move from a page left open after another move answers 422 and shows the ticket as it is now', async ({ browser, page }) => {
    const title = `Coupon field keeps the old code ${randomUUID().slice(0, 8)}`;
    const key = await makeTicketForOmar(browser, title);
    const staleTab = await page.context().newPage();
    await openTicket(page, key, title);
    await openTicket(staleTab, key, title);

    await clickVerb(page, 'Start work', 200);
    await expect(statusPill(page)).toHaveText('In progress');
    await expect(statusPill(staleTab)).toHaveText('To do');
    await markThisLoad(staleTab);

    await clickVerb(staleTab, 'Start work', 422);

    await expect(staleTab.locator(TOAST_REGION)).toHaveText('Someone changed this ticket. The page is updated.');
    await expect(statusPill(staleTab)).toHaveText('In progress');
    await expect(verbButtons(staleTab).first()).toHaveText('Send to code review');
    await expectSameLoad(staleTab);
  });

  for (const status of [403, 404]) {
    test(`a ${status} answer to a move reloads the page and says the move failed, once`, async ({ browser, page }) => {
      const title = `Gift note loses line breaks ${randomUUID().slice(0, 8)}`;
      const key = await makeTicketForOmar(browser, title);
      await openTicket(page, key, title);
      await page.route('**/move/start_work', (route) => route.fulfill({ status, contentType: 'text/html', body: '<!doctype html><title>No</title>' }));
      await markThisLoad(page);

      const reloaded = page.waitForEvent('load');
      await verbButtons(page).filter({ hasText: 'Start work' }).click();
      await reloaded;

      await expect(page.locator('html')).not.toHaveAttribute('data-e2e-load', 'before-move');
      await expect(page.locator(`${TOAST_REGION} [data-toast-message-value]`)).toHaveAttribute('data-toast-message-value', MOVE_FAILED);
      await expect(page.locator(TOAST_REGION)).toHaveText(MOVE_FAILED);
      await expect(statusPill(page)).toHaveText('To do');
      await page.unroute('**/move/start_work');

      await page.reload({ waitUntil: 'networkidle' });

      await expect(page.locator(`${TOAST_REGION} .toast`)).toHaveCount(0);
      expect(await page.evaluate((storageKey) => window.sessionStorage.getItem(storageKey), PENDING_TOAST_KEY)).toBeNull();
    });
  }

  test('a move after the session ended goes to the login page with no toast', async ({ browser, page }) => {
    const title = `Order email misses the delivery slot ${randomUUID().slice(0, 8)}`;
    const key = await makeTicketForOmar(browser, title);
    await openTicket(page, key, title);
    await page.context().clearCookies();

    await verbButtons(page).filter({ hasText: 'Start work' }).click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
    await expect(page.locator(`${TOAST_REGION} .toast`)).toHaveCount(0);
    expect(await page.evaluate((storageKey) => window.sessionStorage.getItem(storageKey), PENDING_TOAST_KEY)).toBeNull();
  });
});
