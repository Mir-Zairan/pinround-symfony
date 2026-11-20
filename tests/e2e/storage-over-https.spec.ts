import { expect, test, type ConsoleMessage, type Page } from '@playwright/test';
import path from 'node:path';
import { LENA_STORAGE_STATE, MIA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { drawablePng, routeCompletedUpload, routeStatus } from './media-mocks';
import { fillTicket, openNewTicketForm } from './new-ticket-form';
import { recordCspViolations } from './pin-spots';
import { seedChecklistRound } from './review-round-seed';
import { makeTicketForOmar, openAs } from './ticket';

// Pictures on an https page (P14.T2): the preview of a chosen file is a blob: URL, which the CSP's img-src must allow,
// and screenshots come from the bucket through the dev stack's HTTPS proxy (docker/frankenphp/dev.d), never over
// plain HTTP. The last test uploads for real, so it needs MinIO, a worker on async_media and vips, like the whole
// journey in new-ticket-screenshot.spec.ts: E2E_MEDIA_PIPELINE=1.

const PREVIEW_WIDTH = 400;

/** Every console message Chrome writes about mixed content (an http picture on the https page), from now on. */
function recordMixedContent(page: Page): string[] {
  const messages: string[] = [];
  page.on('console', (message: ConsoleMessage) => {
    if (/Mixed Content/i.test(message.text())) {
      messages.push(message.text());
    }
  });
  return messages;
}

/** The address each picture of the page loads from, and whether the browser drew it. */
async function picturesOf(page: Page): Promise<{ src: string; drawn: boolean }[]> {
  return page.locator('img').evaluateAll((images) => (images as HTMLImageElement[])
    .filter((image) => '' !== image.currentSrc)
    .map((image) => ({ src: image.currentSrc, drawn: image.complete && image.naturalWidth > 0 })));
}

test('choosing a PNG on Report a problem shows its preview, with no CSP violation', async ({ browser }) => {
  const { context, page } = await openAs(browser, LENA_STORAGE_STATE);
  const cspViolations = await recordCspViolations(page);
  await routeCompletedUpload(page, 'intent-report-preview');
  await routeStatus(page);
  await page.goto('/report', { waitUntil: 'networkidle' });
  const form = page.locator('form.report__form');
  const preview = form.locator('[data-presigned-upload-target="preview"]');

  await form.getByLabel('Upload a file', { exact: true }).setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: drawablePng(PREVIEW_WIDTH, 240) });

  await expect(preview).toHaveAttribute('src', /^blob:/);
  await expect.poll(() => preview.evaluate((image: HTMLImageElement) => image.naturalWidth)).toBe(PREVIEW_WIDTH);
  expect(await cspViolations()).toEqual([]);
  await context.close();
});

test('on a ticket page with screenshots every picture address is https, with no mixed content', async ({ browser }) => {
  test.setTimeout(90_000);
  const key = await makeTicketForOmar(browser, 'Storage over https check on the coupon field');
  seedChecklistRound(key);
  const { context, page } = await openAs(browser, OMAR_STORAGE_STATE);
  const mixedContent = recordMixedContent(page);

  await page.goto(`/t/${key}`);
  const roundPictures = page.locator('turbo-frame#ticket-rounds article.round-card img');
  await roundPictures.first().scrollIntoViewIfNeeded();
  await page.waitForLoadState('networkidle');

  const pictures = await picturesOf(page);
  expect(pictures.length).toBeGreaterThan(0);
  expect(await roundPictures.count()).toBeGreaterThan(0);
  expect(pictures.filter(({ src }) => src.startsWith('http:')).map(({ src }) => src)).toEqual([]);
  expect(await roundPictures.evaluateAll((images) => (images as HTMLImageElement[]).map((image) => new URL(image.currentSrc).protocol))).toContain('https:');
  expect(mixedContent).toEqual([]);
  await context.close();
});

test.describe('a real upload and picture through the HTTPS proxy', () => {
  test.use({ storageState: MIA_STORAGE_STATE, viewport: { width: 1280, height: 900 } });

  test('the presigned PUT goes over https and the ticket page draws the screenshot from https', async ({ page }) => {
    test.skip(!process.env.E2E_MEDIA_PIPELINE, 'needs MinIO, a worker on async_media and vips: set E2E_MEDIA_PIPELINE on a full stack');
    test.setTimeout(60_000);
    const mixedContent = recordMixedContent(page);
    const bucketPuts: { url: string; status: number }[] = [];
    page.on('requestfinished', async (request) => {
      if ('PUT' === request.method()) {
        bucketPuts.push({ url: request.url(), status: (await request.response())?.status() ?? 0 });
      }
    });
    const title = 'Checkout total is hidden by the banner (over https)';
    const form = await openNewTicketForm(page);
    await fillTicket(form, title);

    await form.fileInput.setInputFiles(path.join(__dirname, 'fixtures', 'checkout-page.png'));
    await expect(form.uploadStatus).toHaveText('checkout-page.png uploaded.');
    await form.submit.click();

    await expect(page).not.toHaveURL(/\/tickets\/new/, { timeout: 30_000 });
    await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();
    expect(bucketPuts).toHaveLength(1);
    expect(bucketPuts[0].url).toMatch(/^https:\/\//);
    expect(bucketPuts[0].status).toBe(200);
    const roundPictures = page.locator('turbo-frame#ticket-rounds article.round-card img');
    await roundPictures.first().scrollIntoViewIfNeeded();
    await expect.poll(() => roundPictures.first().evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
    await page.waitForLoadState('networkidle');
    const pictures = await picturesOf(page);
    expect(pictures.length).toBeGreaterThan(0);
    expect(pictures.filter(({ src }) => src.startsWith('http:'))).toEqual([]);
    expect(await roundPictures.evaluateAll((images) => (images as HTMLImageElement[]).filter((image) => !image.currentSrc.startsWith('https://') || 0 === image.naturalWidth).map((image) => image.currentSrc))).toEqual([]);
    expect(mixedContent).toEqual([]);
  });
});
