import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 900 } });

// The gallery's upload field targets a ticket id that does not exist, so the real server refuses a begin. The happy
// path is run with the three calls answered in the browser: a browser PUT to the bucket needs its CORS to allow this
// origin, which the dev stack does for the app's own origin only.
const FIELD = '#gallery-upload';
const STORAGE_URL = 'https://storage.example.test/uploads/slot';
const PNG = Buffer.from('89504e470d0a1a0a0000000d49484452', 'hex');
const MULTIBYTE_NAME = 'Café ☕ 画面.png';

async function openUploadField(page: Page) {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/_gallery', { waitUntil: 'networkidle' });
  const field = page.locator(FIELD);
  await field.scrollIntoViewIfNeeded();
  return {
    field,
    input: field.locator('input[type="file"]'),
    status: field.getByRole('status'),
    preview: field.getByAltText('Preview of the screenshot you picked'),
    mediaId: field.locator('input[type="hidden"]'),
  };
}

test('a member uploads a PNG: it is put to the presigned URL and the hidden input gets the media id', async ({ page }) => {
  const calls: string[] = [];
  let beginBody: unknown = null;
  let beginToken: string | undefined;
  let putHeaders: Record<string, string> = {};
  let putBytes = 0;
  await page.route('**/media/intents', async (route) => {
    calls.push('begin');
    beginBody = route.request().postDataJSON();
    beginToken = route.request().headers()['x-csrf-token'];
    await route.fulfill({
      status: 201,
      json: { intentId: 'intent-1', url: STORAGE_URL, method: 'PUT', headers: { 'Content-Type': 'image/png', 'Content-Length': String(PNG.length) }, expiresAt: '2026-11-06T12:05:00+00:00' },
    });
  });
  await page.route(STORAGE_URL, async (route) => {
    calls.push('put');
    putHeaders = route.request().headers();
    putBytes = route.request().postDataBuffer()?.length ?? 0;
    await route.fulfill({ status: 200, headers: { 'Access-Control-Allow-Origin': '*' }, body: '' });
  });
  await page.route('**/media/intents/intent-1/complete', async (route) => {
    calls.push('complete');
    await route.fulfill({ status: 202, json: { mediaId: '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f02', status: 'pending' } });
  });
  const { field, input, status, preview, mediaId } = await openUploadField(page);
  const uploaded = field.evaluate(
    (element) => new Promise<string>((resolve) => element.addEventListener('presigned-upload:uploaded', (event) => resolve((event as CustomEvent).detail.mediaId), { once: true })),
  );

  await input.setInputFiles({ name: MULTIBYTE_NAME, mimeType: 'image/png', buffer: PNG });

  await expect(status).toHaveText(`${MULTIBYTE_NAME} uploaded.`);
  await expect(mediaId).toHaveValue('0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f02');
  expect(await uploaded).toBe('0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f02');
  await expect(preview).toBeVisible();
  await expect(preview).toHaveAttribute('src', /^blob:/);
  expect(calls).toEqual(['begin', 'put', 'complete']);
  expect(beginBody).toEqual({ purpose: 'screenshot', mime: 'image/png', bytes: PNG.length, target: { kind: 'ticket', id: '00000000-0000-7000-8000-000000000000' } });
  expect(beginToken).toBeTruthy();
  expect(putHeaders['content-type']).toBe('image/png');
  expect(putBytes).toBe(PNG.length);
});

test('a GIF shows the plain refusal and no request goes to /media/intents', async ({ page }) => {
  const intentRequests: string[] = [];
  page.on('request', (request) => {
    if (new URL(request.url()).pathname.startsWith('/media/intents')) {
      intentRequests.push(request.url());
    }
  });
  const { input, status, preview, mediaId } = await openUploadField(page);

  await input.setInputFiles({ name: 'animation.gif', mimeType: 'image/gif', buffer: Buffer.from('GIF89a') });

  await expect(status).toHaveText('This file is not a PNG, JPEG or WebP screenshot.');
  await expect(mediaId).toHaveValue('');
  await expect(preview).toBeHidden();
  expect(intentRequests).toEqual([]);
});

test('an empty PNG shows the size refusal and no request goes to /media/intents', async ({ page }) => {
  const intentRequests: string[] = [];
  page.on('request', (request) => {
    if (new URL(request.url()).pathname.startsWith('/media/intents')) {
      intentRequests.push(request.url());
    }
  });
  const { input, status } = await openUploadField(page);

  await input.setInputFiles({ name: 'empty.png', mimeType: 'image/png', buffer: Buffer.alloc(0) });

  await expect(status).toHaveText('This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.');
  expect(intentRequests).toEqual([]);
});

test('a picture pasted from the clipboard is uploaded like a picked one', async ({ page }) => {
  await page.route('**/media/intents', async (route) => {
    await route.fulfill({ status: 201, json: { intentId: 'intent-2', url: STORAGE_URL, method: 'PUT', headers: { 'Content-Type': 'image/png' }, expiresAt: '2026-11-06T12:05:00+00:00' } });
  });
  await page.route(STORAGE_URL, (route) => route.fulfill({ status: 200, headers: { 'Access-Control-Allow-Origin': '*' }, body: '' }));
  await page.route('**/media/intents/intent-2/complete', (route) => route.fulfill({ status: 202, json: { mediaId: 'pasted-media-id', status: 'pending' } }));
  const { input, mediaId } = await openUploadField(page);

  await input.focus();
  await input.evaluate((element, base64) => {
    const bytes = Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
    const transfer = new DataTransfer();
    transfer.items.add(new File([bytes], 'image.png', { type: 'image/png' }));
    element.dispatchEvent(new ClipboardEvent('paste', { clipboardData: transfer, bubbles: true, cancelable: true }));
  }, PNG.toString('base64'));

  await expect(mediaId).toHaveValue('pasted-media-id');
});

test('the server refusal text is shown, and the hidden input stays empty', async ({ page }) => {
  const { input, status, mediaId, preview } = await openUploadField(page);
  const refused = page.waitForResponse((response) => new URL(response.url()).pathname === '/media/intents');

  await input.setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: PNG });

  expect((await refused).status()).toBe(403);
  await expect(status).toHaveText('You may not add a file here.');
  await expect(mediaId).toHaveValue('');
  await expect(preview).toBeHidden();
});

test('a refused storage PUT says the file could not be stored and sends no complete call', async ({ page }) => {
  let isCompleteCalled = false;
  await page.route('**/media/intents', (route) =>
    route.fulfill({ status: 201, json: { intentId: 'intent-3', url: STORAGE_URL, method: 'PUT', headers: { 'Content-Type': 'image/png' }, expiresAt: '2026-11-06T12:05:00+00:00' } }),
  );
  await page.route(STORAGE_URL, (route) => route.fulfill({ status: 403, headers: { 'Access-Control-Allow-Origin': '*' }, body: '<Error/>' }));
  await page.route('**/media/intents/intent-3/complete', (route) => {
    isCompleteCalled = true;
    return route.fulfill({ status: 202, json: { mediaId: 'never', status: 'pending' } });
  });
  const { input, status, mediaId } = await openUploadField(page);

  await input.setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: PNG });

  await expect(status).toHaveText('The file could not be stored. Try again.');
  await expect(mediaId).toHaveValue('');
  expect(isCompleteCalled).toBe(false);
});

for (const [code, message] of [
  [413, 'This upload request is larger than 4 KB. Reload the page and try again.'],
  [422, 'The page was open too long. Try again.'],
  [429, 'You are adding files too fast. Wait a minute and try again.'],
  [404, 'Nothing you may add a file to has that id.'],
] as const) {
  test(`a ${code} answer shows the server's own message`, async ({ page }) => {
    await page.route('**/media/intents', (route) => route.fulfill({ status: code, json: { message } }));
    const { input, status, mediaId } = await openUploadField(page);

    await input.setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: PNG });

    await expect(status).toHaveText(message);
    await expect(mediaId).toHaveValue('');
  });
}
