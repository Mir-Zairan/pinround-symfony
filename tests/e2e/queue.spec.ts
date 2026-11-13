import AxeBuilder from '@axe-core/playwright';
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { demoPassword, LENA_EMAIL } from './auth';

// The queue lives in the session, so this spec logs Lena in once in a context of its own: a queue started in the
// shared LENA_STORAGE_STATE session would put the bar on the ticket pages other specs check at the same time. It walks
// the demo seed's SHOP Code review step and makes or moves no ticket.
const STEP = 'Code review';
const TOAST_REGION = '#toast-region';
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const DESKTOP = { width: 1280, height: 800 };

test.describe.configure({ mode: 'serial' });

let context: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }) => {
  context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: DESKTOP });
  page = await context.newPage();
  await page.goto('/login');
  await page.getByLabel('Email').fill(LENA_EMAIL);
  await page.getByLabel('Password').fill(demoPassword());
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page.getByRole('button', { name: 'Lena', exact: true })).toBeVisible();
});

test.afterAll(async () => {
  await context.close();
});

function queueBar(): ReturnType<Page['locator']> {
  return page.getByRole('navigation', { name: 'Queue' });
}

async function startQueue(): Promise<number> {
  await page.goto('/tickets?project=SHOP');
  await page.getByRole('button', { name: `Go through them: ${STEP}` }).click();
  await expect(page).toHaveURL(/\/t\/SHOP-\d+\?project=SHOP$/);
  const line = queueBar().locator('.queue-bar__line');
  await expect(line).toHaveText(new RegExp(`^${STEP} · 1 of \\d+$`));
  const count = Number(/of (\d+)$/.exec((await line.innerText()).trim())?.[1]);
  expect(count, 'the seeded SHOP Code review step holds fewer than 3 tickets: run make seed').toBeGreaterThanOrEqual(3);
  return count;
}

function keyOf(url: string): string {
  return new URL(url).pathname.replace(/^\/t\//, '');
}

test('a walk through the queue goes ticket by ticket and Finish says All done on Home', async () => {
  const count = await startQueue();
  await expect(queueBar().getByRole('link', { name: 'Previous' })).toHaveAttribute('aria-disabled', 'true');
  await expect(page.locator('.ticket__position')).toHaveCount(0);

  const walked = [keyOf(page.url())];
  for (let place = 2; place <= count; place++) {
    await queueBar().getByRole('link', { name: 'Next ticket' }).click();
    await expect(queueBar().locator('.queue-bar__line')).toHaveText(`${STEP} · ${place} of ${count}`);
    walked.push(keyOf(page.url()));
  }
  expect(walked.length).toBeGreaterThanOrEqual(3);
  expect(new Set(walked).size).toBe(walked.length);
  await expect(queueBar().getByRole('link', { name: 'Next ticket' })).toHaveCount(0);
  await expect(queueBar().getByRole('link', { name: 'Previous' })).not.toHaveAttribute('aria-disabled', 'true');

  await queueBar().getByRole('button', { name: 'Finish' }).click();

  await expect(page).toHaveURL(/\/$/);
  await expect(page.locator(`${TOAST_REGION} [data-toast-message-value]`)).toHaveAttribute('data-toast-message-value', `All done in ${STEP}.`);
});

test('Stop removes the bar and keeps the ticket open', async () => {
  await startQueue();
  await queueBar().getByRole('link', { name: 'Next ticket' }).click();
  await expect(queueBar().locator('.queue-bar__line')).toHaveText(new RegExp(`^${STEP} · 2 of \\d+$`));
  const secondUrl = page.url();

  await queueBar().getByRole('button', { name: 'Stop' }).click();

  await expect(queueBar()).toHaveCount(0);
  expect(page.url()).toBe(secondUrl);
  await expect(page.locator('.ticket__position')).toHaveCount(1);
});

test('Back while in a queue ends it and opens the list', async () => {
  await startQueue();

  await page.locator('.ticket__back').click();

  await expect(page).toHaveURL(/\/tickets\?project=SHOP$/);
  await page.goBack();
  await page.reload();
  await expect(queueBar()).toHaveCount(0);
});

test('a ticket page with the queue bar has no serious or critical accessibility violations', async () => {
  await startQueue();
  await expect(queueBar()).toBeVisible();

  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();

  expect(
    violations
      .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
      .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`),
  ).toEqual([]);
});

test('at 360 px the queue bar fits and the page does not scroll sideways', async () => {
  await page.setViewportSize({ width: 360, height: 800 });
  try {
    await startQueue();
    await queueBar().getByRole('link', { name: 'Next ticket' }).click();
    await expect(queueBar().locator('.queue-bar__line')).toHaveText(new RegExp(`^${STEP} · 2 of \\d+$`));

    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
    const controls = await queueBar().locator('.btn').all();
    expect(controls).toHaveLength(3);
    for (const control of controls) {
      const box = await control.boundingBox();
      expect(box).not.toBeNull();
      expect(box!.x).toBeGreaterThanOrEqual(0);
      expect(box!.x + box!.width).toBeLessThanOrEqual(clientWidth);
    }
  } finally {
    await page.setViewportSize(DESKTOP);
  }
});
