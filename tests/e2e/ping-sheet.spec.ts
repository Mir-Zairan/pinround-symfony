import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const TOAST_REGION = '#toast-region';
// PingController::FORM_EXPIRED
const FORM_EXPIRED = 'The page was open too long. Try again.';

// Omar pings Lena from the People of a SHOP ticket Lena made for him. Each run makes its own ticket, with a title no
// earlier run used, so the ping limit (one ping per sender, person and ticket every N hours) never refuses a rerun.
// tests/Functional/Notification/PingTest.php proves every refusal, who may not ping and the redirect without Turbo on
// the server.
test.use({ storageState: OMAR_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

/** As Lena, who leads Shop: a new SHOP ticket given to Omar, so People has Lena (reporter) and Omar (assignee). */
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

async function openTicket(page: Page, key: string, title: string): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
  await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
}

function peopleRowOf(page: Page, firstName: string) {
  return page.locator('.people__row').filter({ has: page.locator('.people__name', { hasText: new RegExp(`^${firstName}( \\(you\\))?$`) }) });
}

function pingButton(page: Page) {
  return page.getByRole('button', { name: 'Ping Lena' });
}

function sheet(page: Page) {
  return page.getByRole('dialog', { name: 'Ping Lena' });
}

async function openSheet(page: Page): Promise<void> {
  await pingButton(page).click();
  await expect(sheet(page)).toBeVisible();
  // The browser focuses the first control of a modal <dialog>; the page behind it is inert.
  await expect.poll(() => sheet(page).evaluate((dialog) => dialog.contains(document.activeElement))).toBe(true);
}

/** Clicks Send ping and waits for the answer, a Turbo Stream with the status the ping ended with. */
async function sendPing(page: Page, key: string, expectedStatus: number): Promise<void> {
  const answer = page.waitForResponse((response) => response.url().includes(`/t/${key}/ping/`) && response.request().method() === 'POST');
  await sheet(page).getByRole('button', { name: 'Send ping' }).click();
  const response = await answer;
  expect(response.status()).toBe(expectedStatus);
  expect(response.headers()['content-type']).toContain('text/vnd.turbo-stream.html');
}

test.describe('Omar pings Lena about a SHOP ticket', () => {
  test.describe.configure({ mode: 'serial' });

  const title = `Delivery slot picker skips Sunday ${randomUUID().slice(0, 8)}`;
  let key = '';

  test.beforeAll(async ({ browser }) => {
    key = await makeTicketForOmar(browser, title);
  });

  test('the sheet opens with the draft, focus inside it and no serious accessibility violations', async ({ page }) => {
    await openTicket(page, key, title);
    await expect(peopleRowOf(page, 'Omar').getByRole('button')).toHaveCount(0);
    await expect(peopleRowOf(page, 'Lena').locator('.ping-sheet__pinged')).toHaveCount(0);

    await openSheet(page);

    await expect(sheet(page).locator('.ping-sheet__ticket')).toHaveText(`${key} · ${title}`);
    await expect(sheet(page).getByLabel('Message')).toHaveValue(new RegExp(`^Hi Lena, could you look at ${key}\\?`));
    await expect(sheet(page).getByText(/^Lena gets one notification\. You can ping again after \d+ hours? \(set by your admin\)\.$/)).toBeVisible();
    await expect(sheet(page).getByRole('alert')).toBeEmpty();
    for (const scheme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: scheme });
      expect(await seriousViolations(page), scheme).toEqual([]);
    }
  });

  test('a refusal that keeps the Ping button shows inside the open sheet and leaves with it', async ({ page }) => {
    await openTicket(page, key, title);
    await openSheet(page);
    // An expired form is the one refusal a browser can cause; PingTest covers the others, which answer the same way.
    await sheet(page).locator('input[name="_csrf_token"]').evaluate((input) => {
      (input as HTMLInputElement).value = 'expired';
    });

    await sendPing(page, key, 422);

    await expect(sheet(page)).toBeVisible();
    await expect(sheet(page).getByRole('alert')).toHaveText(FORM_EXPIRED);
    await expect(sheet(page).getByLabel('Message')).toBeFocused();
    await expect(page.locator(TOAST_REGION)).toHaveText(FORM_EXPIRED);
    expect(await seriousViolations(page)).toEqual([]);

    await sheet(page).getByRole('button', { name: 'Cancel' }).click();

    await expect(sheet(page)).toBeHidden();
    await expect(pingButton(page)).toBeFocused();
    await openSheet(page);
    await expect(sheet(page).getByRole('alert')).toBeEmpty();
  });

  test('sending the draft turns Lena’s Ping button into the pinged state and focuses it', async ({ page }) => {
    await openTicket(page, key, title);
    await openSheet(page);

    await sendPing(page, key, 200);

    await expect(sheet(page)).toHaveCount(0);
    const pinged = peopleRowOf(page, 'Lena').locator('.ping-sheet__pinged');
    await expect(pinged).toHaveText(/^Pinged · again in \d+ h$/);
    await expect(pinged).toBeFocused();
    await expect(pingButton(page)).toHaveCount(0);
    await expect(page.locator(TOAST_REGION)).toHaveText('Pinged Lena. One notification sent.');
    expect(await seriousViolations(page)).toEqual([]);

    await openTicket(page, key, title);

    await expect(peopleRowOf(page, 'Lena').locator('.ping-sheet__pinged')).toHaveText(/^Pinged · again in \d+ h$/);
    await expect(peopleRowOf(page, 'Lena').locator('.ping-sheet__pinged')).not.toBeFocused();
    await expect(pingButton(page)).toHaveCount(0);
  });
});
