import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';
import { LENA_STORAGE_STATE, MIA_STORAGE_STATE } from './auth';
import { drawablePng, MEDIA_ID, routeCompletedUpload, routeStatus } from './media-mocks';

// The picture section of /tickets/new (P05.T5), as a member sees it in a real browser.
//
// Most tests here need no worker: the bucket, the begin, complete and status calls are answered in the browser (see
// media-mocks.ts), so they run against any stack, also this one in CI.
//
// The last test is the whole journey with nothing mocked: a real upload to MinIO, the processing worker turning it into
// a ready picture, and Round 0 on the ticket page. Run it from a full dev stack in the main checkout (MinIO up, a worker
// on async_media, the app image rebuilt with vips), with E2E_MEDIA_PIPELINE=1 and the usual E2E_BASE_URL and E2E_PASSWORD:
//   E2E_MEDIA_PIPELINE=1 npx playwright test new-ticket-screenshot.spec.ts --project=chromium
// Without E2E_MEDIA_PIPELINE it is skipped.

test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 900 } });

const WAITING_MESSAGE = 'Waiting for your screenshot to be ready…';
const NOT_A_SCREENSHOT_MESSAGE = 'This file is not a PNG, JPEG or WebP screenshot.';
const TITLE = 'Checkout total is hidden by the banner';
const DESCRIPTION = 'The banner covers the total.\nSecond line, with café ☕ and 画面.';

async function openNewTicketForm(page: Page) {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/tickets/new', { waitUntil: 'networkidle' });
  const form = page.locator('form.login__form');
  return {
    form,
    title: form.locator('input[name="title"]'),
    description: form.locator('textarea[name="description"]'),
    section: form.locator('section.screenshot-report'),
    fileInput: form.locator('input[type="file"]'),
    uploadStatus: form.locator('.upload-field__status'),
    holdStatus: form.locator('.screenshot-report__status'),
    mediaId: form.locator('input[name="screenshot_media_id"]'),
    frame: form.locator('.screenshot__frame'),
    pins: form.locator('.screenshot__frame .pin'),
    pinCount: form.locator('.screenshot-report__count'),
    submit: form.getByRole('button', { name: 'Make ticket' }),
  };
}

type TicketForm = Awaited<ReturnType<typeof openNewTicketForm>>;

async function fillTicket(form: TicketForm, title: string) {
  await form.title.fill(title);
  await form.description.fill(DESCRIPTION);
  await form.form.locator('input[name="type"]').first().check();
  await form.form.locator('input[name="priority"]').first().check();
}

// Clicks the picture where it is 25% and 70% across, and 40% and 75% down: two pins in different places.
async function placeTwoPins(form: TicketForm) {
  const box = (await form.frame.boundingBox())!;
  expect(box.width).toBeGreaterThan(100);
  await form.frame.click({ position: { x: box.width * 0.25, y: box.height * 0.4 } });
  await expect(form.pins).toHaveCount(1);
  await form.frame.click({ position: { x: box.width * 0.7, y: box.height * 0.75 } });
  await expect(form.pins).toHaveCount(2);
}

test('a ticket made without a picture is created and its page shows no round', async ({ page }) => {
  const form = await openNewTicketForm(page);
  await expect(form.section.getByRole('heading', { name: 'Screenshot' })).toBeVisible();
  await expect(form.section.getByText('Upload a file', { exact: true })).toBeVisible();
  await expect(form.frame).toBeHidden();
  await fillTicket(form, `${TITLE} (no picture)`);

  await form.submit.click();

  await expect(page).not.toHaveURL(/\/tickets\/new/);
  await expect(page.getByRole('heading', { name: `${TITLE} (no picture)`, level: 1 })).toBeVisible();
  await expect(page.getByText(/Round 0/)).toHaveCount(0);
});

for (const [name, mimeType, buffer] of [
  ['animation.gif', 'image/gif', Buffer.from('GIF89a')],
  ['notes.txt', 'text/plain', Buffer.from('not a picture')],
] as const) {
  test(`a ${name} is refused in plain words and the form keeps its text`, async ({ page }) => {
    const intentRequests: string[] = [];
    page.on('request', (request) => {
      if (new URL(request.url()).pathname.startsWith('/media/intents')) {
        intentRequests.push(request.url());
      }
    });
    const form = await openNewTicketForm(page);
    await fillTicket(form, TITLE);

    await form.fileInput.setInputFiles({ name, mimeType, buffer });

    await expect(form.uploadStatus).toHaveText(NOT_A_SCREENSHOT_MESSAGE);
    await expect(form.title).toHaveValue(TITLE);
    await expect(form.description).toHaveValue(DESCRIPTION);
    await expect(form.mediaId).toHaveValue('');
    await expect(form.frame).toBeHidden();
    expect(intentRequests).toEqual([]);
  });
}

test('an uploaded picture takes two pins with notes, and the count follows', async ({ page }) => {
  await routeCompletedUpload(page, 'intent-new-1');
  await routeStatus(page);
  const form = await openNewTicketForm(page);
  await expect(form.pinCount).toHaveText('Pins are optional, but they help the developer find the problem fast.');

  await form.fileInput.setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: drawablePng(400, 240) });

  await expect(form.uploadStatus).toHaveText('checkout.png uploaded.');
  await expect(form.mediaId).toHaveValue(MEDIA_ID);
  await expect(form.section.getByText('Click on the screenshot to drop a pin. Then say what is wrong at that pin.')).toBeVisible();
  await expect(form.section.getByRole('button', { name: 'Remove screenshot' })).toBeVisible();
  await placeTwoPins(form);
  await form.form.getByLabel('Note for pin 1').fill('The total is hidden');
  await form.form.getByLabel('Note for pin 2').fill('The banner has no close button');
  await expect(form.form.getByLabel('Note for pin 1')).toHaveAttribute('placeholder', 'What is wrong here?');
  await expect(form.pinCount).toHaveText('2 pins. Each pin becomes a checklist item for the developer and the tester.');
  await expect(form.form.locator('[name="pins[1][note]"]')).toHaveValue('The total is hidden');
  await expect(form.form.locator('[name="pins[2][note]"]')).toHaveValue('The banner has no close button');
});

test('a submit while the picture is pending is held with the waiting text and sent when it is ready', async ({ page }) => {
  await routeCompletedUpload(page, 'intent-new-2');
  let isReady = false;
  await routeStatus(page, () => ({ status: 200, json: { status: isReady ? 'ready' : 'pending' } }));
  const submissions: string[] = [];
  page.on('request', (request) => {
    if ('POST' === request.method() && new URL(request.url()).pathname === '/tickets/new') {
      submissions.push(request.postData() ?? '');
    }
  });
  const form = await openNewTicketForm(page);
  await fillTicket(form, TITLE);
  await form.fileInput.setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: drawablePng(400, 240) });
  await expect(form.uploadStatus).toHaveText('checkout.png uploaded.');
  await placeTwoPins(form);
  await form.form.getByLabel('Note for pin 1').fill('The total is hidden');

  await form.submit.click();

  await expect(form.holdStatus).toHaveText(WAITING_MESSAGE);
  await expect(form.submit).toBeFocused();
  await expect(page).toHaveURL(/\/tickets\/new$/);
  expect(submissions).toEqual([]);
  await expect(form.title).toHaveValue(TITLE);
  await expect(form.pins).toHaveCount(2);

  isReady = true;
  await expect.poll(() => submissions.length, { timeout: 10_000 }).toBe(1);
  expect(submissions[0]).toContain(`screenshot_media_id=${MEDIA_ID}`);
  expect(submissions[0]).toContain('pins%5B1%5D%5Bnote%5D=The+total+is+hidden');
});

test('removing the screenshot drops the pins and the held wait, and focus lands on the upload control', async ({ page }) => {
  await routeCompletedUpload(page, 'intent-new-3');
  await routeStatus(page, () => ({ status: 200, json: { status: 'pending' } }));
  const form = await openNewTicketForm(page);
  await fillTicket(form, TITLE);
  await form.fileInput.setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: drawablePng(400, 240) });
  await expect(form.uploadStatus).toHaveText('checkout.png uploaded.');
  await placeTwoPins(form);
  await form.submit.click();
  await expect(form.holdStatus).toHaveText(WAITING_MESSAGE);

  await form.section.getByRole('button', { name: 'Remove screenshot' }).click();

  await expect(form.fileInput).toBeFocused();
  await expect(form.frame).toBeHidden();
  await expect(form.pins).toHaveCount(0);
  await expect(form.mediaId).toHaveValue('');
  await expect(form.holdStatus).toHaveText('');
  await expect(form.pinCount).toHaveText('Pins are optional, but they help the developer find the problem fast.');
  await expect(form.title).toHaveValue(TITLE);
});

test('text dragged into a pin note is not swallowed, while a dragged file is taken over', async ({ page }) => {
  await routeCompletedUpload(page, 'intent-new-4');
  await routeStatus(page);
  const intentRequests: string[] = [];
  page.on('request', (request) => {
    if ('POST' === request.method() && new URL(request.url()).pathname === '/media/intents') {
      intentRequests.push(request.url());
    }
  });
  const form = await openNewTicketForm(page);
  await form.fileInput.setInputFiles({ name: 'checkout.png', mimeType: 'image/png', buffer: drawablePng(400, 240) });
  await expect(form.uploadStatus).toHaveText('checkout.png uploaded.');
  await placeTwoPins(form);
  const beginsBefore = intentRequests.length;
  const note = form.form.getByLabel('Note for pin 1');

  const answers = await note.evaluate((element) => {
    const dispatch = (type: string, transfer: DataTransfer) => {
      const event = new DragEvent(type, { dataTransfer: transfer, bubbles: true, cancelable: true });
      element.dispatchEvent(event);
      return event.defaultPrevented;
    };
    const text = new DataTransfer();
    text.setData('text/plain', 'dragged words');
    const files = new DataTransfer();
    files.items.add(new File([new Uint8Array([1, 2, 3])], 'notes.txt', { type: 'text/plain' }));
    return {
      textOver: dispatch('dragover', text),
      textDrop: dispatch('drop', text),
      fileOver: dispatch('dragover', files),
    };
  });

  expect(answers).toEqual({ textOver: false, textDrop: false, fileOver: true });
  expect(intentRequests.length).toBe(beginsBefore);
  await expect(form.pins).toHaveCount(2);
});

test.describe('the whole journey, with a worker and a bucket', () => {
  test.use({ storageState: MIA_STORAGE_STATE });

  test('Mia makes a ticket with a screenshot and two pins and its page shows Round 0', async ({ page }) => {
    test.skip(!process.env.E2E_MEDIA_PIPELINE, 'needs MinIO, a worker on async_media and vips: set E2E_MEDIA_PIPELINE on a full stack');
    test.setTimeout(60_000);
    const form = await openNewTicketForm(page);
    await fillTicket(form, `${TITLE} (with picture)`);

    await form.fileInput.setInputFiles(path.join(__dirname, 'fixtures', 'checkout-page.png'));
    await expect(form.uploadStatus).toHaveText('checkout-page.png uploaded.');
    await placeTwoPins(form);
    await form.form.getByLabel('Note for pin 1').fill('The total is hidden');
    await form.form.getByLabel('Note for pin 2').fill('The banner has no close button');
    await form.submit.click();

    await expect(page).not.toHaveURL(/\/tickets\/new/, { timeout: 30_000 });
    await expect(page.getByRole('heading', { name: `${TITLE} (with picture)`, level: 1 })).toBeVisible();
    await expect(page.getByText('Round 0 · Report · by Mia · 2 pins')).toBeVisible();
  });
});

