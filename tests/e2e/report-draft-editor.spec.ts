import { randomUUID } from 'node:crypto';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { MIA_EMAIL, MIA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { runAsDatabaseOwner } from './database';
import { drawablePng, MEDIA_ID, routeCompletedUpload, routeStatus } from './media-mocks';

// The ScreenshotEditor on the report draft page and on Report a problem (P14.T10): one screenshot at a time, chosen by
// its thumbnail, and forms that post the same field names and values the stacked pictures did. ReportControllerTest and
// ReportDraftScreenshotEditorTest prove what the server renders and stores; this spec proves what the browser shows and
// posts.
//
// The draft's pictures are seeded ready in the database with no file behind them, and the browser is answered with a
// drawn PNG for them, so no worker or bucket is needed. Report a problem's upload is answered in the browser
// (media-mocks.ts).

test.use({ viewport: { width: 1280, height: 900 } });

type DraftPin = { x_pct: number; y_pct: number; note: string };
type DraftScreenshot = { title: string | null; summary: string | null; pins: DraftPin[] };
type SeededDraft = { draftId: string; mediaIds: string[] };

const SCREENSHOT_COUNT = 4;

/** Two pins, none, one, and two of which one has no note; the second has no title or summary. */
const FOUR_SCREENSHOTS: DraftScreenshot[] = [
  { title: 'Cart page', summary: 'The cart of Acme Foods.', pins: [{ x_pct: 20, y_pct: 30, note: 'Total' }, { x_pct: 60, y_pct: 70, note: 'Count' }] },
  { title: null, summary: null, pins: [] },
  { title: 'Sum line', summary: null, pins: [{ x_pct: 50, y_pct: 50, note: 'Sum is doubled' }] },
  { title: 'Coupon', summary: 'The coupon field.', pins: [{ x_pct: 10, y_pct: 10, note: 'Empty field' }, { x_pct: 80, y_pct: 80, note: '' }] },
];

/** What the stacked form posted for FOUR_SCREENSHOTS untouched: a title, a summary and each pin's note, no remove. */
const UNTOUCHED_FIELDS: [string, string][] = [
  ['screenshots[0][title]', 'Cart page'],
  ['screenshots[0][summary]', 'The cart of Acme Foods.'],
  ['screenshots[0][pins][0]', 'Total'],
  ['screenshots[0][pins][1]', 'Count'],
  ['screenshots[1][title]', ''],
  ['screenshots[1][summary]', ''],
  ['screenshots[2][title]', 'Sum line'],
  ['screenshots[2][summary]', ''],
  ['screenshots[2][pins][0]', 'Sum is doubled'],
  ['screenshots[3][title]', 'Coupon'],
  ['screenshots[3][summary]', 'The coupon field.'],
  ['screenshots[3][pins][0]', 'Empty field'],
  ['screenshots[3][pins][1]', ''],
];

const seededDrafts: SeededDraft[] = [];

/** A random id in UUIDv7's shape (version nibble 7, variant 10xx), which MediaId accepts; its time bits mean nothing. */
function randomUuidV7Shaped(): string {
  const id = randomUUID();
  return `${id.slice(0, 14)}7${id.slice(15)}`;
}

function sqlText(text: string): string {
  return `'${text.replaceAll("'", "''")}'`;
}

/** Mia's report draft for SHOP with the screenshots given, each on a ready picture of its own. */
function seedMiasReportDraft(title: string, screenshots: DraftScreenshot[]): SeededDraft {
  const draftId = randomUuidV7Shaped();
  const mediaIds = screenshots.map(() => randomUuidV7Shaped());
  const stored = screenshots.map((screenshot, index) => ({ media_id: mediaIds[index], ...screenshot }));
  runAsDatabaseOwner(`
    INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
    SELECT o.organization_id, m.id::uuid, 'uploads/' || m.id, 'image/png', 2048, u.id, now(), 'ready', NULL, md5(m.id) || md5('sha' || m.id), 640, 480,
      jsonb_build_object('view', 'media/' || m.id || '/view.webp', 'thumb', 'media/' || m.id || '/thumb.webp')
    FROM org_organization o, idn_user u, (VALUES ${mediaIds.map((id) => `(${sqlText(id)})`).join(', ')}) AS m(id)
    WHERE o.slug = 'acme-foods' AND u.email = ${sqlText(MIA_EMAIL)};
    INSERT INTO rvw_report_draft (organization_id, id, owner_id, project_key, title, detail, screenshots, version, created_at, updated_at)
    SELECT o.organization_id, ${sqlText(draftId)}::uuid, u.id, 'SHOP', ${sqlText(title)}, '', CAST(${sqlText(JSON.stringify(stored))} AS jsonb), 1, now(), now()
    FROM org_organization o, idn_user u
    WHERE o.slug = 'acme-foods' AND u.email = ${sqlText(MIA_EMAIL)}`);
  const seeded = { draftId, mediaIds };
  seededDrafts.push(seeded);
  return seeded;
}

test.afterEach(() => {
  for (const { draftId, mediaIds } of seededDrafts.splice(0)) {
    runAsDatabaseOwner(`
      DELETE FROM rvw_report_draft WHERE id = ${sqlText(draftId)}::uuid;
      DELETE FROM med_object WHERE id IN (${mediaIds.map((id) => `${sqlText(id)}::uuid`).join(', ')})`);
  }
});

/** Answers the seeded pictures' signed addresses with a drawn PNG, as the bucket has no file for them. */
async function routeSeededPictures(page: Page, mediaIds: string[]): Promise<void> {
  const picture = drawablePng(640, 480);
  await page.route(
    (url) => mediaIds.some((id) => url.pathname.includes(id)),
    (route) => route.fulfill({ status: 200, contentType: 'image/png', body: picture }),
  );
}

async function openReportDraft(page: Page, { draftId, mediaIds }: SeededDraft) {
  await routeSeededPictures(page, mediaIds);
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto(`/report-drafts/${draftId}`, { waitUntil: 'networkidle' });
  const form = page.locator('form.login__form');
  const editor = form.locator('#draft-shots');
  const panel = (number: number) => editor.locator(`#draft-shots-panel-${number}`);
  return {
    form,
    editor,
    panel,
    tab: (number: number) => editor.getByRole('tab', { name: `Screenshot ${number} of ${SCREENSHOT_COUNT}` }),
    pinNote: (screenshot: number, pin: number) => panel(screenshot).getByLabel(`Note for pin ${pin}`, { exact: true }),
    pins: (screenshot: number) => panel(screenshot).locator('.pin-layer .pin'),
    save: form.getByRole('button', { name: 'Save changes' }),
  };
}

type ReportDraftPage = Awaited<ReturnType<typeof openReportDraft>>;

async function expectShown({ tab, panel }: ReportDraftPage, shown: number): Promise<void> {
  for (let number = 1; number <= SCREENSHOT_COUNT; number++) {
    const isShown = number === shown;
    await expect(tab(number)).toHaveAttribute('aria-selected', isShown ? 'true' : 'false');
    if (isShown) {
      await expect(panel(number)).toBeVisible();
    } else {
      await expect(panel(number)).toBeHidden();
    }
  }
}

/** What the form would post, in document order. */
async function postedFields(form: Locator): Promise<[string, string][]> {
  return form.evaluate((element: HTMLFormElement) => [...new FormData(element).entries()].map(([name, value]): [string, string] => [name, String(value)]));
}

/** Sorted by name in code-unit order, which no locale changes. */
function sortedByName(fields: [string, string][]): [string, string][] {
  return [...fields].sort(([first], [second]) => {
    if (first === second) {
      return 0;
    }
    return first < second ? -1 : 1;
  });
}

/** The posted fields whose names match, sorted by name: the editor's order is not the stacked form's. */
async function postedFieldsMatching(form: Locator, name: RegExp): Promise<[string, string][]> {
  return sortedByName((await postedFields(form)).filter(([postedName]) => name.test(postedName)));
}

test.describe('the report draft page', () => {
  test.use({ storageState: MIA_STORAGE_STATE });

  test('a draft with four screenshots shows one at a time, and a thumbnail shows its own', async ({ page }) => {
    const draft = await openReportDraft(page, seedMiasReportDraft(`Cart total is doubled ${randomUUID().slice(0, 8)}`, FOUR_SCREENSHOTS));
    await expect(draft.editor.getByRole('tab')).toHaveCount(SCREENSHOT_COUNT);
    await expectShown(draft, 1);
    await expect(draft.panel(1).getByLabel('Picture title · optional')).toHaveValue('Cart page');
    await expect(draft.editor.getByRole('button', { name: 'Add screenshot' })).toHaveCount(0);

    await draft.tab(3).click();

    await expectShown(draft, 3);
    await expect(draft.panel(3).getByLabel('Picture title · optional')).toHaveValue('Sum line');
    await expect(draft.pinNote(3, 1)).toHaveValue('Sum is doubled');
    const picture = draft.panel(3).locator('img.screenshot__image');
    await expect.poll(() => picture.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0), { message: 'the shown picture loads' }).toBe(true);

    await draft.tab(2).click();

    await expectShown(draft, 2);
    await expect(draft.pins(2)).toHaveCount(0);
    await expect(draft.panel(2).getByLabel('Picture title · optional')).toHaveValue('');
  });

  test('switching by thumbnail keeps each screenshot\'s pins and typed notes', async ({ page }) => {
    const draft = await openReportDraft(page, seedMiasReportDraft(`Coupon field clears ${randomUUID().slice(0, 8)}`, FOUR_SCREENSHOTS));
    await expect(draft.pins(1)).toHaveCount(2);
    await draft.pinNote(1, 2).fill('Count is off by one');
    await draft.panel(1).getByLabel('Picture title · optional').fill('Cart page of Acme Foods');

    await draft.tab(4).click();
    await expectShown(draft, 4);
    await expect(draft.pins(4)).toHaveCount(2);
    await draft.pinNote(4, 2).fill('No code shown');
    await draft.tab(1).click();

    await expectShown(draft, 1);
    await expect(draft.pins(1)).toHaveCount(2);
    await expect(draft.pinNote(1, 1)).toHaveValue('Total');
    await expect(draft.pinNote(1, 2)).toHaveValue('Count is off by one');
    await expect(draft.panel(1).getByRole('button', { name: /^Pin 2\b/ })).toHaveAttribute('aria-label', 'Pin 2: Count is off by one');
    await expect(draft.panel(1).getByLabel('Picture title · optional')).toHaveValue('Cart page of Acme Foods');

    await draft.tab(4).click();

    await expect(draft.pins(4)).toHaveCount(2);
    await expect(draft.pinNote(4, 1)).toHaveValue('Empty field');
    await expect(draft.pinNote(4, 2)).toHaveValue('No code shown');
  });

  test('the form posts the field names and values the stacked form did, and Save changes keeps them', async ({ page }) => {
    const seeded = seedMiasReportDraft(`Sum line is doubled ${randomUUID().slice(0, 8)}`, FOUR_SCREENSHOTS);
    const draft = await openReportDraft(page, seeded);

    expect(await postedFieldsMatching(draft.form, /^screenshots\[/)).toEqual(sortedByName(UNTOUCHED_FIELDS));

    await draft.pinNote(1, 2).fill('Count is off by one');
    await draft.tab(2).click();
    await draft.panel(2).getByRole('button', { name: 'Remove screenshot' }).click();
    await draft.tab(4).click();
    await draft.panel(4).getByLabel('What this picture shows · optional').fill('The coupon field of Acme Foods.');
    await draft.pinNote(4, 2).fill('No code shown');

    const edited = new Map(UNTOUCHED_FIELDS);
    edited.set('screenshots[0][pins][1]', 'Count is off by one');
    edited.set('screenshots[1][remove]', '1');
    edited.set('screenshots[3][summary]', 'The coupon field of Acme Foods.');
    edited.set('screenshots[3][pins][1]', 'No code shown');
    expect(await postedFieldsMatching(draft.form, /^screenshots\[/)).toEqual(sortedByName([...edited.entries()]));

    // The URL is the draft's before and after the save, so the test waits for the save's own answer. Mia's saved
    // session is shared with parallel specs, any of which may take the one-time "Report draft saved." toast;
    // ReportDraftScreenshotEditorTest checks it.
    const saveAnswer = page.waitForResponse((response) => 'POST' === response.request().method() && new URL(response.url()).pathname === `/report-drafts/${seeded.draftId}`);
    await draft.save.click();

    expect((await saveAnswer).status()).toBe(303);
    await expect(page).toHaveURL(new RegExp(`/report-drafts/${seeded.draftId}$`));
    const saved = await openReportDraft(page, seeded);
    await expect(saved.editor.getByRole('tab')).toHaveCount(SCREENSHOT_COUNT - 1);
    expect(await postedFieldsMatching(saved.form, /^screenshots\[/)).toEqual(sortedByName([
      ['screenshots[0][title]', 'Cart page'],
      ['screenshots[0][summary]', 'The cart of Acme Foods.'],
      ['screenshots[0][pins][0]', 'Total'],
      ['screenshots[0][pins][1]', 'Count is off by one'],
      ['screenshots[1][title]', 'Sum line'],
      ['screenshots[1][summary]', ''],
      ['screenshots[1][pins][0]', 'Sum is doubled'],
      ['screenshots[2][title]', 'Coupon'],
      ['screenshots[2][summary]', 'The coupon field of Acme Foods.'],
      ['screenshots[2][pins][0]', 'Empty field'],
      ['screenshots[2][pins][1]', 'No code shown'],
    ]));
  });
});

test.describe('Report a problem', () => {
  test.use({ storageState: OMAR_STORAGE_STATE });

  async function openReportForm(page: Page) {
    await routeCompletedUpload(page, 'intent-report-editor');
    await routeStatus(page);
    // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
    await page.goto('/report', { waitUntil: 'networkidle' });
    const form = page.locator('form.report__form');
    const editor = form.locator('#screenshot-editor');
    return {
      form,
      editor,
      pictureArea: form.locator('.screenshot-report__picture'),
      fileInput: form.getByLabel('Upload a file', { exact: true }),
      mediaId: form.locator('input[name="screenshot_media_id"]'),
      frame: editor.locator('.screenshot__frame'),
      pins: editor.locator('.screenshot__frame .pin'),
    };
  }

  const PICTURE_FIELDS = /^(screenshot_|pins\[)/;

  test('the picture sits in the editor and the form posts the picture fields it posted before', async ({ page }) => {
    const report = await openReportForm(page);
    await expect(report.pictureArea).toBeHidden();
    expect(await postedFieldsMatching(report.form, PICTURE_FIELDS)).toEqual([
      ['screenshot_media_id', ''],
      ['screenshot_summary', ''],
      ['screenshot_title', ''],
    ]);

    await report.fileInput.setInputFiles({ name: 'cart.png', mimeType: 'image/png', buffer: drawablePng(400, 240) });

    await expect(report.mediaId).toHaveValue(MEDIA_ID);
    await expect(report.pictureArea).toBeVisible();
    await expect(report.editor.getByRole('tab')).toHaveCount(1);
    await expect(report.editor.getByRole('tab').locator('img')).toHaveAttribute('src', /^blob:/);
    const box = (await report.frame.boundingBox())!;
    expect(box.width).toBeGreaterThan(100);
    await report.frame.click({ position: { x: box.width * 0.25, y: box.height * 0.4 } });
    await expect(report.pins).toHaveCount(1);
    await report.frame.click({ position: { x: box.width * 0.7, y: box.height * 0.75 } });
    await expect(report.pins).toHaveCount(2);
    await report.editor.getByLabel('Note for pin 1', { exact: true }).fill('Total is doubled');
    await report.editor.getByLabel('Note for pin 2', { exact: true }).fill('Coupon is lost');
    await report.editor.getByLabel('Picture title · optional').fill('Cart of Acme Foods');
    await report.editor.getByLabel('What this picture shows · optional').fill('The cart after one coupon.');

    const posted = await postedFieldsMatching(report.form, PICTURE_FIELDS);
    expect(posted.map(([name]) => name)).toEqual([
      'pins[1][note]',
      'pins[1][x]',
      'pins[1][y]',
      'pins[2][note]',
      'pins[2][x]',
      'pins[2][y]',
      'screenshot_media_id',
      'screenshot_summary',
      'screenshot_title',
    ]);
    const postedValue = new Map(posted);
    expect([postedValue.get('pins[1][note]'), postedValue.get('pins[2][note]')]).toEqual(['Total is doubled', 'Coupon is lost']);
    expect([postedValue.get('screenshot_media_id'), postedValue.get('screenshot_title'), postedValue.get('screenshot_summary')]).toEqual([MEDIA_ID, 'Cart of Acme Foods', 'The cart after one coupon.']);
    for (const spot of ['pins[1][x]', 'pins[1][y]', 'pins[2][x]', 'pins[2][y]']) {
      expect(Number(postedValue.get(spot)), `${spot} is a percentage`).toBeGreaterThan(0);
      expect(Number(postedValue.get(spot)), `${spot} is a percentage`).toBeLessThan(100);
    }
  });

  test('the editor\'s Remove screenshot drops the picture, its pins, title and summary, and hands focus to the upload', async ({ page }) => {
    const report = await openReportForm(page);
    await report.fileInput.setInputFiles({ name: 'cart.png', mimeType: 'image/png', buffer: drawablePng(400, 240) });
    await expect(report.mediaId).toHaveValue(MEDIA_ID);
    const box = (await report.frame.boundingBox())!;
    await report.frame.click({ position: { x: box.width * 0.5, y: box.height * 0.5 } });
    await expect(report.pins).toHaveCount(1);
    await report.editor.getByLabel('Picture title · optional').fill('Cart of Acme Foods');
    await report.editor.getByLabel('What this picture shows · optional').fill('The cart after one coupon.');

    await report.editor.getByRole('button', { name: 'Remove screenshot' }).click();

    await expect(report.pictureArea).toBeHidden();
    await expect(report.mediaId).toHaveValue('');
    await expect(report.fileInput).toBeFocused();
    await expect(report.pins).toHaveCount(0);
    expect(await postedFieldsMatching(report.form, PICTURE_FIELDS)).toEqual([
      ['screenshot_media_id', ''],
      ['screenshot_summary', ''],
      ['screenshot_title', ''],
    ]);

    // A blank ticket title is refused on its own field, so the form sends and no ticket is made.
    await report.form.locator('input[name="title"]').fill('   ');
    await report.form.getByRole('button', { name: 'Create ticket' }).click();

    await expect(page.locator('#report-title-error')).toBeVisible();
    await expect(page.locator('#screenshot-error')).toHaveCount(0);
  });
});
