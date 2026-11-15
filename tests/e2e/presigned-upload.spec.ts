import { expect, test, type Locator, type Page, type Route } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 900 } });

// The gallery's upload field targets a ticket id that does not exist, so the real server refuses a begin. The happy
// path is run with the three calls answered in the browser: a browser PUT to the bucket needs its CORS to allow this
// origin, which the dev stack does for the app's own origin only.
const FIELD = '#gallery-upload';
// The dev stack's OBJECT_STORAGE_PUBLIC_URL (.env.dev), the only storage origin the page's CSP lets it connect to.
const STORAGE_URL = 'http://localhost:9000/pinround-dev/uploads/slot';
const APP_ORIGIN = new URL(process.env.E2E_BASE_URL ?? 'https://localhost').origin;
// What the bucket's CORS rule answers for the app's origin; a PUT with a Content-Type is preflighted.
const STORAGE_CORS = {
  'Access-Control-Allow-Origin': APP_ORIGIN,
  'Access-Control-Allow-Methods': 'PUT, GET, HEAD',
  'Access-Control-Allow-Headers': 'content-type',
  Vary: 'Origin',
};

// Answers the browser's preflight like the bucket, and each PUT with what onPut returns.
async function routeStorage(page: Page, onPut: (route: Route) => Promise<{ status: number; body?: string }> | { status: number; body?: string } = () => ({ status: 200 })) {
  await page.route(STORAGE_URL, async (route) => {
    if ('OPTIONS' === route.request().method()) {
      await route.fulfill({ status: 200, headers: STORAGE_CORS, body: '' });
      return;
    }
    const { status, body = '' } = await onPut(route);
    await route.fulfill({ status, headers: STORAGE_CORS, body }).catch(() => {});
  });
}
const PNG = Buffer.from('89504e470d0a1a0a0000000d49484452', 'hex');
const MULTIBYTE_NAME = 'Café ☕ 画面.png';
const MEDIA_ID = '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f02';
const USED_ALREADY_MESSAGE = 'This upload has ended. Upload the file again.';
const NOT_A_BEGIN_REQUEST_MESSAGE = 'This upload request needs a purpose, a file type, a size and a target. Reload the page and try again.';

// What PresignedUploads::forPut answers: the signed Content-Type and Content-Length headers.
function beginAnswer(intentId: string) {
  return {
    status: 201,
    json: {
      intentId,
      url: STORAGE_URL,
      method: 'PUT',
      headers: { 'Content-Type': 'image/png', 'Content-Length': String(PNG.length) },
      expiresAt: '2026-11-06T12:05:00+00:00',
    },
  };
}

// The status the page polls after a complete call; most tests do not care, so by default the picture is ready.
async function routeStatus(page: Page, answer: (poll: number) => { status: number; json: unknown } = () => ({ status: 200, json: { status: 'ready' } })) {
  let polls = 0;
  await page.route('**/media/*/status', (route) => {
    polls += 1;
    return route.fulfill(answer(polls));
  });
  return () => polls;
}

async function openUploadField(page: Page, { withStatusRoute = true }: { withStatusRoute?: boolean } = {}) {
  if (withStatusRoute) {
    await routeStatus(page);
  }
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
    await route.fulfill(beginAnswer('intent-1'));
  });
  await routeStorage(page, (route) => {
    calls.push('put');
    putHeaders = route.request().headers();
    putBytes = route.request().postDataBuffer()?.length ?? 0;
    return { status: 200 };
  });
  await page.route('**/media/intents/intent-1/complete', async (route) => {
    calls.push('complete');
    await route.fulfill({ status: 202, json: { mediaId: MEDIA_ID, status: 'pending' } });
  });
  const { field, input, status, preview, mediaId } = await openUploadField(page);
  const uploaded = field.evaluate(
    (element) => new Promise<string>((resolve) => element.addEventListener('presigned-upload:uploaded', (event) => resolve((event as CustomEvent).detail.mediaId), { once: true })),
  );

  await input.setInputFiles({ name: MULTIBYTE_NAME, mimeType: 'image/png', buffer: PNG });

  await expect(status).toHaveText(`${MULTIBYTE_NAME} uploaded.`);
  await expect(mediaId).toHaveValue(MEDIA_ID);
  expect(await uploaded).toBe(MEDIA_ID);
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
    await route.fulfill(beginAnswer('intent-2'));
  });
  await routeStorage(page);
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
  await page.route('**/media/intents', (route) => route.fulfill(beginAnswer('intent-3')));
  await routeStorage(page, () => ({ status: 403, body: '<Error/>' }));
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
  [400, NOT_A_BEGIN_REQUEST_MESSAGE],
  [413, 'This upload request is larger than 4 KB. Reload the page and try again.'],
  [422, 'The page was open too long. Try again.'],
  [429, 'You are adding files too fast. Wait a minute and try again.'],
] as const) {
  test(`a ${code} answer to the begin call shows the server's own message`, async ({ page }) => {
    await page.route('**/media/intents', (route) => route.fulfill({ status: code, json: { message } }));
    const { input, status, mediaId } = await openUploadField(page);

    await input.setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: PNG });

    await expect(status).toHaveText(message);
    await expect(mediaId).toHaveValue('');
  });
}

for (const [code, message] of [
  [404, 'Nothing you may add a file to has that id.'],
  [422, USED_ALREADY_MESSAGE],
] as const) {
  test(`a ${code} answer to the complete call shows the server's own message and leaves the hidden input empty`, async ({ page }) => {
    await page.route('**/media/intents', (route) => route.fulfill(beginAnswer('intent-4')));
    await routeStorage(page);
    await page.route('**/media/intents/intent-4/complete', (route) => route.fulfill({ status: code, json: { message } }));
    const { input, status, mediaId, preview } = await openUploadField(page);

    await input.setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: PNG });

    await expect(status).toHaveText(message);
    await expect(mediaId).toHaveValue('');
    await expect(preview).toBeHidden();
  });
}

test('a new upload clears the media id of the previous one, and a refused begin leaves it empty', async ({ page }) => {
  let beginCount = 0;
  await page.route('**/media/intents', (route) => {
    beginCount += 1;
    return 1 === beginCount ? route.fulfill(beginAnswer('intent-5')) : route.fulfill({ status: 422, json: { message: 'The page was open too long. Try again.' } });
  });
  await routeStorage(page);
  await page.route('**/media/intents/intent-5/complete', (route) => route.fulfill({ status: 202, json: { mediaId: MEDIA_ID, status: 'pending' } }));
  const { input, status, mediaId } = await openUploadField(page);
  await input.setInputFiles({ name: 'first.png', mimeType: 'image/png', buffer: PNG });
  await expect(mediaId).toHaveValue(MEDIA_ID);

  await input.setInputFiles({ name: 'second.png', mimeType: 'image/png', buffer: PNG });

  await expect(status).toHaveText('The page was open too long. Try again.');
  await expect(mediaId).toHaveValue('');
  expect(beginCount).toBe(2);
});

test('a second file picked while one uploads is ignored: one begin call, and the status still names the first file', async ({ page }) => {
  let beginCount = 0;
  let releasePut: () => void = () => {};
  const putHeld = new Promise<void>((resolve) => (releasePut = resolve));
  let putStarted: () => void = () => {};
  const hasPutStarted = new Promise<void>((resolve) => (putStarted = resolve));
  await page.route('**/media/intents', (route) => {
    beginCount += 1;
    return route.fulfill(beginAnswer('intent-6'));
  });
  await routeStorage(page, async () => {
    putStarted();
    await putHeld;
    return { status: 200 };
  });
  await page.route('**/media/intents/intent-6/complete', (route) => route.fulfill({ status: 202, json: { mediaId: MEDIA_ID, status: 'pending' } }));
  const { input, status, mediaId } = await openUploadField(page);

  await input.setInputFiles({ name: 'first.png', mimeType: 'image/png', buffer: PNG });
  await hasPutStarted;
  await expect(status).toHaveText('Uploading first.png…');
  await input.setInputFiles({ name: 'second.png', mimeType: 'image/png', buffer: PNG });

  await expect(status).toHaveText('Uploading first.png…');
  expect(beginCount).toBe(1);
  releasePut();
  await expect(status).toHaveText('first.png uploaded.');
  await expect(mediaId).toHaveValue(MEDIA_ID);
  expect(beginCount).toBe(1);
});

test('a repeated refusal is announced again: the live region is emptied before the text is set', async ({ page }) => {
  const { input, status } = await openUploadField(page);
  await status.evaluate((element) => {
    (window as unknown as { texts: string[] }).texts = [];
    new MutationObserver(() => (window as unknown as { texts: string[] }).texts.push(element.textContent ?? '')).observe(element, { childList: true, characterData: true, subtree: true });
  });

  await input.setInputFiles({ name: 'animation.gif', mimeType: 'image/gif', buffer: Buffer.from('GIF89a') });
  await expect(status).toHaveText('This file is not a PNG, JPEG or WebP screenshot.');
  await input.setInputFiles({ name: 'other.gif', mimeType: 'image/gif', buffer: Buffer.from('GIF89a') });
  await expect(status).toHaveText('This file is not a PNG, JPEG or WebP screenshot.');

  const texts = await page.evaluate(() => (window as unknown as { texts: string[] }).texts);
  expect(texts.filter((text) => '' === text).length).toBeGreaterThanOrEqual(1);
  expect(texts.filter((text) => text.startsWith('This file is not')).length).toBe(2);
});

test('leaving the page mid-upload aborts it: no complete call is made', async ({ page }) => {
  let isCompleteCalled = false;
  let releasePut: () => void = () => {};
  const putHeld = new Promise<void>((resolve) => (releasePut = resolve));
  let putStarted: () => void = () => {};
  const hasPutStarted = new Promise<void>((resolve) => (putStarted = resolve));
  await page.route('**/media/intents', (route) => route.fulfill(beginAnswer('intent-7')));
  await routeStorage(page, async () => {
    putStarted();
    await putHeld;
    return { status: 200 };
  });
  await page.route('**/media/intents/intent-7/complete', (route) => {
    isCompleteCalled = true;
    return route.fulfill({ status: 202, json: { mediaId: MEDIA_ID, status: 'pending' } });
  });
  const { field, input } = await openUploadField(page);

  await input.setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: PNG });
  await hasPutStarted;
  await field.evaluate((element) => element.remove());
  releasePut();

  await page.waitForLoadState('networkidle');
  expect(isCompleteCalled).toBe(false);
});

// Playwright's clock fires the timers at once, so no test waits a real second. The status request between two timers is
// real, hence a run of short steps until the polls are done.
async function runClockUntil(page: Page, isDone: () => boolean) {
  await expect
    .poll(
      async () => {
        await page.clock.runFor(1000);
        return isDone();
      },
      { timeout: 30_000, intervals: [20] },
    )
    .toBe(true);
}

async function recordEvents(field: Locator, names: string[]) {
  await field.evaluate((element, eventNames) => {
    const events: { name: string; mediaId: string }[] = [];
    (window as unknown as { uploadEvents: typeof events }).uploadEvents = events;
    for (const name of eventNames) {
      element.addEventListener(`presigned-upload:${name}`, (event) => events.push({ name, mediaId: (event as CustomEvent).detail.mediaId }));
    }
  }, names);
  return () => field.evaluate(() => (window as unknown as { uploadEvents: { name: string; mediaId: string }[] }).uploadEvents);
}

async function uploadPng(page: Page, status: Locator) {
  await page.locator(`${FIELD} input[type="file"]`).setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: PNG });
  await expect(status).toHaveText('checkout.png uploaded.');
}

async function routeCompletedUpload(page: Page, intentId: string) {
  await page.route('**/media/intents', (route) => route.fulfill(beginAnswer(intentId)));
  await routeStorage(page);
  await page.route(`**/media/intents/${intentId}/complete`, (route) => route.fulfill({ status: 202, json: { mediaId: MEDIA_ID, status: 'pending' } }));
}

test('the field polls the status URL and, once ready after two polls, marks itself ready and says so in an event', async ({ page }) => {
  await routeCompletedUpload(page, 'intent-8');
  const polledUrls: string[] = [];
  page.on('request', (request) => {
    if (request.url().includes('/status')) {
      polledUrls.push(new URL(request.url()).pathname);
    }
  });
  const pollCount = await routeStatus(page, (poll) => ({ status: 200, json: { status: poll < 2 ? 'pending' : 'ready' } }));
  await page.clock.install();
  const { field, status, mediaId } = await openUploadField(page, { withStatusRoute: false });
  const events = await recordEvents(field, ['uploaded', 'ready', 'still-processing']);

  await uploadPng(page, status);
  await expect(field).toHaveAttribute('data-media-state', 'pending');
  await runClockUntil(page, () => pollCount() >= 2);

  await expect(field).toHaveAttribute('data-media-state', 'ready');
  expect(await events()).toEqual([
    { name: 'uploaded', mediaId: MEDIA_ID },
    { name: 'ready', mediaId: MEDIA_ID },
  ]);
  await expect(mediaId).toHaveValue(MEDIA_ID);
  expect(pollCount()).toBe(2);
  expect([...new Set(polledUrls)]).toEqual([`/media/${MEDIA_ID}/status`]);
});

test('a rejected picture shows the plain message, empties the media id and never says ready', async ({ page }) => {
  await routeCompletedUpload(page, 'intent-9');
  const pollCount = await routeStatus(page, () => ({ status: 200, json: { status: 'rejected' } }));
  await page.clock.install();
  const { field, status, mediaId, preview } = await openUploadField(page, { withStatusRoute: false });
  const events = await recordEvents(field, ['ready', 'still-processing']);

  await uploadPng(page, status);
  await runClockUntil(page, () => pollCount() >= 1);

  await expect(mediaId).toHaveValue('');
  await expect(field).toHaveAttribute('data-media-state', 'rejected');
  await expect(preview).toBeHidden();
  await page.clock.runFor(100);
  await expect(status).toHaveText('We could not process this image. Try again.');
  expect(await events()).toEqual([]);
  expect(pollCount()).toBe(1);
});

test('after 15 polls still pending it says so, keeps the media id and polls no more', async ({ page }) => {
  await routeCompletedUpload(page, 'intent-10');
  const pollCount = await routeStatus(page, () => ({ status: 200, json: { status: 'pending' } }));
  await page.clock.install();
  const { field, status, mediaId } = await openUploadField(page, { withStatusRoute: false });
  const events = await recordEvents(field, ['ready', 'still-processing']);
  await uploadPng(page, status);

  await runClockUntil(page, () => pollCount() >= 15);
  await expect.poll(async () => (await events()).length).toBe(1);
  await page.clock.runFor(5000);
  await page.clock.runFor(100);

  expect(await events()).toEqual([{ name: 'still-processing', mediaId: MEDIA_ID }]);
  await expect(mediaId).toHaveValue(MEDIA_ID);
  await expect(field).toHaveAttribute('data-media-state', 'pending');
  await expect(status).toHaveText('Still processing your screenshot. Try again in a moment.');
  expect(pollCount()).toBe(15);
});

test('a failed poll is not the end: the next poll asks again', async ({ page }) => {
  await routeCompletedUpload(page, 'intent-11');
  const pollCount = await routeStatus(page, (poll) => (1 === poll ? { status: 429, json: { message: 'Slow down.' } } : { status: 200, json: { status: 'ready' } }));
  await page.clock.install();
  const { field, status } = await openUploadField(page, { withStatusRoute: false });
  await uploadPng(page, status);

  await runClockUntil(page, () => pollCount() >= 2);

  await expect(field).toHaveAttribute('data-media-state', 'ready');
});

test('a new upload stops the polling of the earlier one', async ({ page }) => {
  let beginCount = 0;
  await page.route('**/media/intents', (route) => route.fulfill(beginAnswer(`intent-12-${(beginCount += 1)}`)));
  await routeStorage(page);
  await page.route('**/media/intents/intent-12-*/complete', (route) => route.fulfill({ status: 202, json: { mediaId: 1 === beginCount ? MEDIA_ID : 'second-media-id', status: 'pending' } }));
  const polledIds: string[] = [];
  await page.route('**/media/*/status', (route) => {
    polledIds.push(new URL(route.request().url()).pathname.split('/')[2]);
    return route.fulfill({ status: 200, json: { status: 'pending' } });
  });
  await page.clock.install();
  const { field, input, status, mediaId } = await openUploadField(page, { withStatusRoute: false });
  await uploadPng(page, status);
  await runClockUntil(page, () => polledIds.length >= 2);
  const pollsOfFirst = polledIds.length;

  await input.setInputFiles({ name: 'second.png', mimeType: 'image/png', buffer: PNG });
  await expect(mediaId).toHaveValue('second-media-id');
  await expect(field).toHaveAttribute('data-media-state', 'pending');
  await runClockUntil(page, () => polledIds.includes('second-media-id'));

  expect(polledIds.slice(pollsOfFirst).filter((id) => MEDIA_ID === id).length).toBeLessThanOrEqual(1);
});

test('leaving the page stops the polling', async ({ page }) => {
  await routeCompletedUpload(page, 'intent-13');
  const pollCount = await routeStatus(page, () => ({ status: 200, json: { status: 'pending' } }));
  await page.clock.install();
  const { field, status } = await openUploadField(page, { withStatusRoute: false });
  await uploadPng(page, status);
  await runClockUntil(page, () => pollCount() >= 1);

  await field.evaluate((element) => element.remove());
  const pollsWhenLeft = pollCount();
  await page.clock.runFor(20_000);
  await page.waitForLoadState('networkidle');

  expect(pollCount() - pollsWhenLeft).toBeLessThanOrEqual(1);
});
