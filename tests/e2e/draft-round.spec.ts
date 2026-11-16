import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import path from 'node:path';
import { demoPassword, LENA_STORAGE_STATE, MIA_STORAGE_STATE, OMAR_STORAGE_STATE, SARA_EMAIL } from './auth';
import { makeTicketForOmar, openAs, TOAST_REGION } from './ticket';

// The private draft round (P05.T8, ADR-0017) in a real browser. Omar is the assignee and the owner; Lena, Mia and Sara
// must not see it. What the server refuses for each role is proven in tests/Functional/Review; here it is proven again
// through the page and the direct URLs.
//
// Steps with a picture need the processing worker and a bucket (an upload stays pending without them), so they run only
// with E2E_MEDIA_PIPELINE=1 on a full stack, as in new-ticket-screenshot.spec.ts.

test.use({ viewport: { width: 1280, height: 900 } });

const DRAFT_HEADLINE = 'Draft round · only you can see this';
const SHARED_HEADLINE = /^Round \d+ · self-check by Omar$/;
const PICTURE = path.join(__dirname, 'fixtures', 'checkout-page.png');
const needsPipeline = () => test.skip(!process.env.E2E_MEDIA_PIPELINE, 'needs MinIO, a worker on async_media and vips: set E2E_MEDIA_PIPELINE on a full stack');

async function startDraftAsOmar(browser: Browser): Promise<{ context: BrowserContext; page: Page; key: string; roundId: string }> {
  const key = await makeTicketForOmar(browser, 'Draft round check on the checkout banner');
  const { context, page } = await openAs(browser, OMAR_STORAGE_STATE);
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  // The page and its lazy Rounds frame load at once and both write the session, so now and then the form's token is the
  // one the session lost and the start is refused as "open too long". Opening the page again gives a token that holds.
  await expect(async () => {
    await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Start a draft round' }).click({ timeout: 5_000 });
    await expect(page.getByRole('heading', { name: DRAFT_HEADLINE, level: 3 })).toBeVisible({ timeout: 5_000 });
  }).toPass({ timeout: 30_000 });
  await page.waitForLoadState('networkidle');
  const shareAction = await page.locator('form.round-card__form', { has: page.locator('.round-card__share') }).getAttribute('action');
  const roundId = /\/rounds\/([^/]+)\/share$/.exec(shareAction ?? '')?.[1];
  expect(roundId, 'the share form of the draft').toBeTruthy();
  return { context, page, key, roundId: roundId ?? '' };
}

async function addPicture(page: Page): Promise<void> {
  await page.locator('.draft-round-editor__upload input[type="file"]').setInputFiles(PICTURE);
  await expect(page.locator('section.draft-shot')).toHaveCount(1, { timeout: 30_000 });
}

function savedBy(page: Page, action: string) {
  return page.waitForResponse((response) => response.url().includes(`/_components/DraftRoundEditor/${action}`) && 200 === response.status());
}

test.describe('Draft round', () => {
  test('Omar starts a draft, sees the PRIVATE card and the Claude Code box, and sharing makes it read-only', async ({ browser }) => {
    const { context, page, key } = await startDraftAsOmar(browser);
    const card = page.locator('article.round-card--private');

    await expect(card.getByText('PRIVATE', { exact: true })).toBeVisible();
    await expect(card.getByText('Check your own work before you pass it on.', { exact: false })).toBeVisible();
    await expect(card.getByText('No screenshots yet.')).toBeVisible();
    await expect(card.getByRole('button', { name: 'Add screenshot' })).toBeVisible();
    await expect(card.getByRole('button', { name: 'Delete draft' })).toBeVisible();
    await expect(card.getByRole('heading', { name: 'For Claude Code', level: 4 })).toBeVisible();
    await expect(card.locator('.claude-code-box__prompt')).toHaveText(`Read my pinround notes for ${key} and fix what is marked “Not right yet”.`);
    await expect(card.locator('.claude-code-box__tools')).toContainText('get_round_notes, add_screenshot, set_verdict');
    await expect(page.getByRole('button', { name: 'Start a draft round' })).toHaveCount(0);

    await card.getByRole('button', { name: 'Share with the team now' }).click();

    const shared = page.locator('article.round-card').filter({ has: page.getByRole('heading', { name: SHARED_HEADLINE, level: 3 }) });
    await expect(shared).toBeVisible();
    await expect(shared.getByText('PRIVATE', { exact: true })).toHaveCount(0);
    await expect(shared.getByText('What Omar checked before passing the ticket on.')).toBeVisible();
    await expect(shared.getByRole('button', { name: 'Share with the team now' })).toHaveCount(0);
    await expect(shared.getByRole('button', { name: 'Delete draft' })).toHaveCount(0);
    await expect(shared.getByRole('button', { name: 'Add screenshot' })).toHaveCount(0);
    await expect(shared.locator('.claude-code-box')).toHaveCount(0);
    await expect(shared.locator('input, textarea')).toHaveCount(0);
    await context.close();
  });

  test('Lena, Mia and Sara see no unshared draft on the ticket page nor through its direct URLs', async ({ browser }) => {
    const { context: omarContext, page: omar, key, roundId } = await startDraftAsOmar(browser);
    const liveRoot = omar.locator('.draft-round-editor[data-controller~="live"]');
    const live = liveRoot;
    const liveUrl = await liveRoot.getAttribute('data-live-url-value');
    const liveProps = await liveRoot.getAttribute('data-live-props-value');
    await expect(live).toBeVisible();
    expect(liveUrl, 'the editor is a live component').toContain('/_components/DraftRoundEditor');
    const componentRequest = () => ({
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/vnd.live-component+html' },
      form: { data: JSON.stringify({ props: JSON.parse(liveProps ?? '{}'), updated: {} }) },
    });

    // The same replay as its owner shows the component answers, so a refusal below is the voter's and not a bad replay.
    const ownerAnswer = await omarContext.request.post(`${liveUrl}/get`, componentRequest());
    expect(ownerAnswer.status()).toBe(200);
    expect(await ownerAnswer.text()).toContain(DRAFT_HEADLINE);

    const others: [string, () => Promise<{ context: BrowserContext; page: Page }>][] = [
      ['Lena', () => openAs(browser, LENA_STORAGE_STATE)],
      ['Mia', () => openAs(browser, MIA_STORAGE_STATE)],
      ['Sara', async () => {
        const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } });
        const page = await context.newPage();
        await page.goto('/login');
        await page.getByLabel('Email').fill(SARA_EMAIL);
        await page.getByLabel('Password').fill(demoPassword());
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page.getByRole('button', { name: 'Sara', exact: true })).toBeVisible();
        return { context, page };
      }],
    ];
    for (const [name, open] of others) {
      const { context, page } = await open();
      await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
      await expect(page.locator('.ticket').first(), `${name} sees the ticket`).toHaveAttribute('data-ticket-key', key);
      await expect(page.locator('turbo-frame#ticket-rounds')).toBeVisible();
      await expect(page.getByRole('heading', { name: /Draft round|self-check/, level: 3 }), `${name}: no draft card`).toHaveCount(0);
      await expect(page.getByText('PRIVATE', { exact: true })).toHaveCount(0);
      await expect(page.getByRole('button', { name: 'Start a draft round' }), `${name} may not start a draft`).toHaveCount(0);

      const frame = await context.request.get(`/t/${key}/rounds`);
      expect(frame.status(), `${name}: rounds frame`).toBe(200);
      const frameHtml = await frame.text();
      expect(frameHtml).not.toContain('Draft round');
      expect(frameHtml).not.toContain(roundId);

      const editor = await context.request.post(`${liveUrl}/get`, componentRequest());
      expect(editor.status(), `${name}: editor component`).not.toBe(200);
      expect(await editor.text()).not.toContain(DRAFT_HEADLINE);

      for (const action of ['share', 'delete']) {
        const refused = await context.request.post(`/t/${key}/rounds/${roundId}/${action}`, { form: { _csrf_token: 'x' } });
        expect(refused.status(), `${name}: ${action}`).toBe(404);
        expect(await refused.text()).not.toContain(DRAFT_HEADLINE);
      }
      await context.close();
    }

    // Nothing the others tried changed the draft: it is still private and Omar's.
    await omar.reload({ waitUntil: 'networkidle' });
    await expect(omar.getByRole('heading', { name: DRAFT_HEADLINE, level: 3 })).toBeVisible();
    await expect(omar.getByRole('button', { name: 'Share with the team now' })).toBeVisible();
    await omarContext.close();
  });

  test('a title, a pin with its note and a verdict survive a reload and the status line follows', async ({ browser }) => {
    needsPipeline();
    test.setTimeout(90_000);
    const { context, page } = await startDraftAsOmar(browser);
    await addPicture(page);
    const shot = page.locator('section.draft-shot');
    const status = page.locator('[data-draft-status]');

    await shot.getByLabel('Picture title · optional').fill('Checkout banner');
    const textSaved = savedBy(page, 'editScreenshotText');
    await shot.getByLabel('Picture summary · optional').fill('The banner covers the total.');
    await shot.getByLabel('Picture summary · optional').blur();
    await textSaved;

    const frame = shot.locator('.screenshot__frame');
    const box = (await frame.boundingBox())!;
    const pinSaved = savedBy(page, 'setPins');
    await frame.click({ position: { x: box.width * 0.3, y: box.height * 0.4 } });
    await shot.getByLabel('Note for pin 1').fill('The total is hidden');
    await pinSaved;

    await expect(status).toHaveText('0 of 1 look right');
    const verdict = savedBy(page, 'setVerdict');
    await shot.getByRole('button', { name: 'Looks right', exact: true }).click();
    await verdict;
    await expect(status).toHaveText('1 of 1 look right');
    await expect(shot.getByRole('button', { name: 'Looks right', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const cleared = savedBy(page, 'setVerdict');
    await shot.getByRole('button', { name: 'Not right yet', exact: true }).click();
    await cleared;
    await expect(status).toHaveText('0 of 1 look right');

    await page.reload({ waitUntil: 'networkidle' });
    const again = page.locator('section.draft-shot');
    await expect(again.getByLabel('Picture title · optional')).toHaveValue('Checkout banner');
    await expect(again.getByLabel('Picture summary · optional')).toHaveValue('The banner covers the total.');
    await expect(again.getByLabel('Note for pin 1')).toHaveValue('The total is hidden');
    await expect(again.getByRole('button', { name: 'Not right yet', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-draft-status]')).toHaveText('0 of 1 look right');
    await context.close();
  });

  test('a double click on Share while a pin save waits sends one share request, with the pin saved first', async ({ browser }) => {
    needsPipeline();
    test.setTimeout(90_000);
    const { context, page, key, roundId } = await startDraftAsOmar(browser);
    await addPicture(page);
    const events: string[] = [];
    page.on('request', (request) => {
      const url = new URL(request.url());
      if ('POST' !== request.method()) {
        return;
      }
      if (url.pathname === `/t/${key}/rounds/${roundId}/share`) {
        events.push('share');
      } else if (url.pathname.endsWith('/setPins')) {
        events.push('setPins');
      }
    });
    const shot = page.locator('section.draft-shot');
    const frame = shot.locator('.screenshot__frame');
    const box = (await frame.boundingBox())!;
    await frame.click({ position: { x: box.width * 0.5, y: box.height * 0.5 } });
    await shot.getByLabel('Note for pin 1').fill('Shared with the pin');

    await page.getByRole('button', { name: 'Share with the team now' }).dblclick();

    await expect(page.getByRole('heading', { name: SHARED_HEADLINE, level: 3 })).toBeVisible();
    expect(events).toEqual(['setPins', 'share']);
    await expect(page.getByText('Shared with the pin')).toBeVisible();
    await context.close();
  });

  test('a refused delete keeps the draft and the pin still waiting to be saved', async ({ browser }) => {
    needsPipeline();
    test.setTimeout(90_000);
    const { context, page } = await startDraftAsOmar(browser);
    await addPicture(page);
    page.on('dialog', (dialog) => dialog.accept());
    const shot = page.locator('section.draft-shot');
    const frame = shot.locator('.screenshot__frame');
    const box = (await frame.boundingBox())!;
    await frame.click({ position: { x: box.width * 0.5, y: box.height * 0.5 } });
    await shot.getByLabel('Note for pin 1').fill('Kept after a refused delete');
    await page.locator('form.round-card__form', { has: page.locator('.round-card__delete') }).locator('input[name="_csrf_token"]').evaluate((field: HTMLInputElement) => {
      field.value = 'expired';
    });

    await page.getByRole('button', { name: 'Delete draft' }).click();

    await expect(page.locator(TOAST_REGION)).toHaveText('This page was open too long. Reload it and try again.');
    await expect.poll(async () => {
      await page.reload({ waitUntil: 'networkidle' });
      return page.getByLabel('Note for pin 1').inputValue().catch(() => '');
    }).toBe('Kept after a refused delete');
    await expect(page.getByRole('heading', { name: DRAFT_HEADLINE, level: 3 })).toBeVisible();
    await context.close();
  });
});
