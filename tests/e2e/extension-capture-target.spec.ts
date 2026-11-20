import type { BrowserContext, Locator, Page } from '@playwright/test';
import path from 'node:path';
import {
  ALL_TOKEN_SCOPES,
  CAPTURE_HOST_PERMISSIONS,
  PINROUND_TEST_ORIGIN,
  STUB_REPORT_FULL_TEXT,
  TEST_PAGE_ORIGIN,
  answerPinroundApi,
  chooseNewReport,
  expect,
  openPopupFor,
  pairedPopupFor,
  rememberInSession,
  test,
  type ExtensionPageGlobals,
  type PinroundStub,
  type StubPin,
  type StubRequest,
  type StubScreenshot,
  type StubTicket,
} from './extension';

// The popup's capture target picker (P13.T5 steps 2 and 3): each capture goes to the target the person chose, against
// a pinround answered by answerPinroundApi as the real API answers. Pins are dropped with the trusted page.mouse and
// page.keyboard into the overlay "Pin this page" opens.

const BASKET_PAGE_URL = `${TEST_PAGE_ORIGIN}/basket`;
const BASKET_PAGE_FILE = path.join(__dirname, 'fixtures', 'extension', 'capture-page-2.html');
const TARGET_PICKER = 'Put the screenshot in';
const FIRST_REPORT_CAPTURE_SAVED = 'Report draft made with screenshot 1. Capture more pages, or press Done to open it.';
const REPORTS_WRITE_MISSING = 'Your pinround token cannot make reports. Make a token that may prepare report drafts on the API tokens page, then pair the extension again.';
const NOT_SENT = 'The screenshot did not reach pinround. Check your connection and press Send it again.';
const REPORT_GONE = 'Your report draft is gone from pinround, so the screenshot was not added. Choose where to put it.';
const IDEMPOTENCY_KEY_PATTERN = /^[A-Za-z0-9_.:-]{1,100}$/;
const TICKETS: StubTicket[] = [
  { key: 'SHOP-7', title: 'Basket total is wrong', step: 'Building', now: 'Omar builds it', is_your_turn: true, is_assignee: false, updated_at: '2026-03-02T09:00:00+00:00' },
  { key: 'SHOP-12', title: 'Logo is cut off on the receipt', step: 'Checking', now: 'Mia checks it', is_your_turn: true, is_assignee: true, updated_at: '2026-03-01T09:00:00+00:00' },
];
const RECEIPT_NOTEBOOK_ID = 'nb000000-0000-4000-8000-000000000101';
const PRICES_NOTEBOOK_ID = 'nb000000-0000-4000-8000-000000000102';
const OMARS_NOTEBOOK_ID = 'nb000000-0000-4000-8000-000000000103';
const OMARS_DRAFT_ID = 'rd000000-0000-4000-8000-000000000201';
const MY_FULL_DRAFT_ID = 'rd000000-0000-4000-8000-000000000202';

test.use({ extensionHostPermissions: CAPTURE_HOST_PERMISSIONS });

/** Answers the basket page on the test page's origin, a second page beside the test page at `/`. */
async function answerBasketPage(context: BrowserContext): Promise<void> {
  await context.route(BASKET_PAGE_URL, (route) => route.fulfill({ path: BASKET_PAGE_FILE, contentType: 'text/html' }));
}

/** Pins the page once with `note` at the given fraction of its viewport, then presses "Capture this page". */
async function pinAndCapture(popup: Page, page: Page, note: string, [xFraction, yFraction]: [number, number]): Promise<void> {
  const viewport = await page.evaluate(() => ({ width: window.innerWidth, height: window.innerHeight }));
  await popup.getByRole('button', { name: 'Pin this page' }).click();
  await page.mouse.click(viewport.width * xFraction, viewport.height * yFraction);
  await page.keyboard.type(note);
  await page.keyboard.press('Enter');
  await popup.getByRole('button', { name: 'Capture this page' }).click();
}

const postsOf = (stub: PinroundStub): StubRequest[] => stub.requests.filter((request) => request.method === 'POST');
const routesOf = (requests: StubRequest[]) => requests.map((request) => [request.path, request.answerStatus]);
const notesOf = (screenshots: StubScreenshot[]) => screenshots.map((screenshot) => screenshot.pins.map((pin) => pin.note));

/** Presses the control with Enter after giving it the focus, as a keyboard user does. */
async function pressWithKeyboard(control: Locator): Promise<void> {
  await control.focus();
  await control.press('Enter');
}

/** A press that hid its own control leaves the focus on "Capture this page" or the picker, never on the page's body. */
async function expectFocusOnCaptureOrTarget(popup: Page): Promise<void> {
  await expect.poll(() => popup.evaluate(() => document.activeElement?.id ?? '')).toMatch(/^(capture|target)$/);
}

async function newReportInSession(popup: Page): Promise<unknown> {
  return popup.evaluate(async () => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    return (await chrome.storage.session.get('pinroundNewReport')).pinroundNewReport;
  });
}

test('two captures on two pages for "A new report" make one report draft with both pictures in order, each sent with its own Idempotency-Key', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context, { projects: ['SHOP', 'HR'] });
  await answerBasketPage(context);
  const popup = await pairedPopupFor(context, testPage);
  await chooseNewReport(popup, { project: 'shop', title: 'Basket total is wrong' });

  await pinAndCapture(popup, testPage, 'Total is wrong', [0.3, 0.4]);
  await expect(popup.getByRole('status')).toHaveText(FIRST_REPORT_CAPTURE_SAVED);
  await expect(testPage.locator('pinround-overlay')).toHaveCount(0);

  await testPage.goto(BASKET_PAGE_URL);
  const popupOnBasket = await openPopupFor(context, testPage);
  await expect(popupOnBasket.getByLabel(TARGET_PICKER)).toHaveValue('new-report');
  await expect(popupOnBasket.getByLabel('Project key')).toHaveValue('SHOP');
  await expect(popupOnBasket.getByLabel('Project key')).toBeDisabled();
  await expect(popupOnBasket.getByText('1 of 10 screenshots in the report draft.')).toBeVisible();
  await pinAndCapture(popupOnBasket, testPage, 'Jam price', [0.7, 0.2]);
  await expect(popupOnBasket.getByRole('status')).toHaveText('Screenshot 2 added to the report draft. Capture more pages, or press Done to open it.');
  await expect(testPage.locator('pinround-overlay')).toHaveCount(0);

  const [draft, ...otherDrafts] = stub.myReportDrafts();
  expect(otherDrafts).toEqual([]);
  expect([draft.project, draft.title]).toEqual(['SHOP', 'Basket total is wrong']);
  expect(draft.screenshots.map((screenshot) => screenshot.upload_id)).toEqual(['upload-1', 'upload-2']);
  expect(notesOf(draft.screenshots)).toEqual([['Total is wrong'], ['Jam price']]);
  const posts = postsOf(stub);
  expect(routesOf(posts)).toEqual([
    ['/api/v1/uploads', 201],
    ['/api/v1/reports', 201],
    ['/api/v1/uploads', 201],
    [`/api/v1/reports/${draft.id}/screenshots`, 200],
  ]);
  expect(posts.filter((request) => request.path === '/api/v1/uploads').map((request) => request.body?.project)).toEqual(['SHOP', 'SHOP']);
  expect(posts[1].body?.screenshots).toEqual([{ upload_id: 'upload-1', pins: draft.screenshots[0].pins }]);
  expect(posts[3].body).toEqual({ upload_id: 'upload-2', pins: draft.screenshots[1].pins });
  const [firstKey, secondKey] = [posts[1].idempotencyKey, posts[3].idempotencyKey];
  expect(firstKey).toMatch(IDEMPOTENCY_KEY_PATTERN);
  expect(secondKey).toMatch(IDEMPOTENCY_KEY_PATTERN);
  expect(secondKey).not.toBe(firstKey);
  expect(stub.storedPictures.length).toBe(2);
  expect(stub.storedPictures[0].equals(stub.storedPictures[1])).toBe(false);
});

test('"A new report" with a token that cannot make reports says so plainly and makes no upload, no report draft, and keeps the pins', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context, { scopes: ALL_TOKEN_SCOPES.filter((scope) => scope !== 'reports:write') });
  const popup = await pairedPopupFor(context, testPage);
  await chooseNewReport(popup);

  await pinAndCapture(popup, testPage, 'Total is wrong', [0.5, 0.5]);

  await expect(popup.getByRole('status')).toHaveText(REPORTS_WRITE_MISSING);
  // The scope is checked by the upload's begin, which pinround refuses before it makes an upload slot.
  expect(routesOf(postsOf(stub))).toEqual([['/api/v1/uploads', 403]]);
  expect(stub.uploads.size).toBe(0);
  expect(stub.storedPictures).toEqual([]);
  expect(stub.myReportDrafts()).toEqual([]);
  const remembered = (await newReportInSession(popup)) as { draftId?: unknown; pendingCapture?: unknown } | undefined;
  expect([remembered?.draftId ?? null, remembered?.pendingCapture ?? null]).toEqual([null, null]);
  await expect(popup.getByRole('button', { name: 'Send it again' })).toBeHidden();
  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
});

test('a capture for "My draft round on SHOP-7" goes to that draft round and to no other target', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context, { tickets: TICKETS });
  const popup = await pairedPopupFor(context, testPage);
  await popup.getByLabel(TARGET_PICKER).selectOption({ label: 'My draft round on SHOP-7' });

  await pinAndCapture(popup, testPage, 'Total is wrong', [0.5, 0.5]);

  await expect(popup.getByRole('status')).toHaveText('Added to your draft round on SHOP-7. Only you see it.');
  const posts = postsOf(stub);
  expect(routesOf(posts)).toEqual([['/api/v1/uploads', 201], ['/api/v1/tickets/SHOP-7/draft/screenshots', 200]]);
  expect(posts[0].body?.ticket).toBe('SHOP-7');
  expect((posts[1].body?.pins as Array<{ note: string }>).map((pin) => pin.note)).toEqual(['Total is wrong']);
  expect(stub.myReportDrafts()).toEqual([]);
});

test('a capture for a notebook of mine lands in it through the notebook routes; another person\'s notebook is never offered', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context, {
    notebooks: [
      { notebook_id: RECEIPT_NOTEBOOK_ID, title: 'Receipt layout', last_used_at: '2026-03-01T09:00:00+00:00' },
      { notebook_id: OMARS_NOTEBOOK_ID, title: 'Omar\'s checks', owner: 'omar', last_used_at: '2026-03-03T09:00:00+00:00' },
      { notebook_id: PRICES_NOTEBOOK_ID, title: 'Prices & <totals>', last_used_at: '2026-03-02T09:00:00+00:00' },
    ],
  });
  const popup = await pairedPopupFor(context, testPage);
  await expect(popup.getByLabel(TARGET_PICKER).locator('option')).toHaveText(['Choose where to put it', 'A new report', 'Notebook: Prices & <totals>', 'Notebook: Receipt layout', 'A new notebook']);
  await popup.getByLabel(TARGET_PICKER).selectOption({ label: 'Notebook: Receipt layout' });

  await pinAndCapture(popup, testPage, 'Logo is cut off', [0.2, 0.8]);

  await expect(popup.getByRole('status')).toHaveText('Screenshot 1 added to the notebook. Only you see it.');
  expect(routesOf(postsOf(stub))).toEqual([[`/api/v1/notebooks/${RECEIPT_NOTEBOOK_ID}/uploads`, 201], [`/api/v1/notebooks/${RECEIPT_NOTEBOOK_ID}/screenshots`, 200]]);
  expect(notesOf(stub.notebooks.get(RECEIPT_NOTEBOOK_ID)?.screenshots ?? [])).toEqual([['Logo is cut off']]);
  expect(stub.notebooks.get(PRICES_NOTEBOOK_ID)?.screenshots).toEqual([]);
  expect(stub.notebooks.get(OMARS_NOTEBOOK_ID)?.screenshots).toEqual([]);
  await expect(testPage.locator('pinround-overlay')).toHaveCount(0);
});

test('"A new notebook" makes the notebook with the typed title, and the capture lands in it', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  await popup.getByLabel(TARGET_PICKER).selectOption({ label: 'A new notebook' });
  await popup.getByLabel('Notebook title').fill('  Basket checks ');
  await popup.getByRole('button', { name: 'Make the notebook' }).click();
  await expect(popup.getByRole('status')).toHaveText('Notebook made. Captures now go to it.');
  await expect(popup.getByLabel(TARGET_PICKER).locator('option:checked')).toHaveText('Notebook: Basket checks');

  await pinAndCapture(popup, testPage, 'Total is wrong', [0.5, 0.5]);

  await expect(popup.getByRole('status')).toHaveText('Screenshot 1 added to the notebook. Only you see it.');
  const [notebook, ...otherNotebooks] = [...stub.notebooks.values()];
  expect(otherNotebooks).toEqual([]);
  expect(notebook.title).toBe('Basket checks');
  const posts = postsOf(stub);
  expect(routesOf(posts)).toEqual([
    ['/api/v1/notebooks', 201],
    [`/api/v1/notebooks/${notebook.notebook_id}/uploads`, 201],
    [`/api/v1/notebooks/${notebook.notebook_id}/screenshots`, 200],
  ]);
  expect(posts[0].body).toEqual({ title: 'Basket checks' });
  expect(notesOf(notebook.screenshots)).toEqual([['Total is wrong']]);
});

test('"Done" opens the report draft pinround answered in a new tab and starts the next report empty', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context);
  await context.route(`${PINROUND_TEST_ORIGIN}/report-drafts/**`, (route) => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Report draft</title><h1>Report draft</h1>' }));
  const popup = await pairedPopupFor(context, testPage);
  await chooseNewReport(popup);
  await pinAndCapture(popup, testPage, 'Total is wrong', [0.5, 0.5]);
  await expect(popup.getByRole('status')).toHaveText(FIRST_REPORT_CAPTURE_SAVED);
  const [draft] = stub.myReportDrafts();

  const draftTabOpened = context.waitForEvent('page');
  await pressWithKeyboard(popup.getByRole('button', { name: 'Done' }));
  const draftTab = await draftTabOpened;

  await expect(draftTab).toHaveURL(`${PINROUND_TEST_ORIGIN}/report-drafts/${draft.id}`);
  await expect(popup.getByRole('status')).toHaveText('Report draft opened in pinround. Check it there and press Send.');
  await expect(popup.getByRole('button', { name: 'Done' })).toBeHidden();
  await expectFocusOnCaptureOrTarget(popup);
  expect(await newReportInSession(popup)).toBeUndefined();
  await expect(popup.getByLabel('Project key')).toBeEnabled();
  await expect(popup.getByLabel('Project key')).toHaveValue('');
});

test('"Start over" pressed from the keyboard forgets the report in the popup, hides itself and leaves the focus on a control in sight', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  await chooseNewReport(popup);
  await pinAndCapture(popup, testPage, 'Total is wrong', [0.5, 0.5]);
  await expect(popup.getByRole('status')).toHaveText(FIRST_REPORT_CAPTURE_SAVED);

  await pressWithKeyboard(popup.getByRole('button', { name: 'Start over' }));

  await expect(popup.getByRole('status')).toHaveText('Started over. The report draft already made stays in pinround.');
  await expect(popup.getByRole('button', { name: 'Start over' })).toBeHidden();
  await expectFocusOnCaptureOrTarget(popup);
  expect(await newReportInSession(popup)).toBeUndefined();
  expect(stub.myReportDrafts()).toHaveLength(1);
});

test('"Send it again" after a lost answer re-sends the capture with its Idempotency-Key, makes one draft, and the next capture gets a new key', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  await chooseNewReport(popup);
  stub.loseAnswerTo('/api/v1/reports');

  await pinAndCapture(popup, testPage, 'Total is wrong', [0.5, 0.5]);
  await expect(popup.getByRole('status')).toHaveText(NOT_SENT);
  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
  await pressWithKeyboard(popup.getByRole('button', { name: 'Send it again' }));

  await expect(popup.getByRole('status')).toHaveText(`${FIRST_REPORT_CAPTURE_SAVED} Press Escape on the page to drop its pins.`);
  await expect(popup.getByRole('button', { name: 'Send it again' })).toBeHidden();
  await expectFocusOnCaptureOrTarget(popup);
  const reportPosts = postsOf(stub).filter((request) => request.path === '/api/v1/reports');
  expect(reportPosts.map((request) => request.answerStatus)).toEqual([null, 201]);
  expect(reportPosts[1].idempotencyKey).toMatch(IDEMPOTENCY_KEY_PATTERN);
  expect(reportPosts[1].idempotencyKey).toBe(reportPosts[0].idempotencyKey);
  expect(reportPosts[1].body).toEqual(reportPosts[0].body);
  const [draft, ...otherDrafts] = stub.myReportDrafts();
  expect(otherDrafts).toEqual([]);
  expect(notesOf(draft.screenshots)).toEqual([['Total is wrong']]);

  await testPage.keyboard.press('Escape');
  await expect(testPage.locator('pinround-overlay')).toHaveCount(0);
  await pinAndCapture(popup, testPage, 'Logo is cut off', [0.2, 0.2]);
  await expect(popup.getByRole('status')).toHaveText('Screenshot 2 added to the report draft. Capture more pages, or press Done to open it.');
  const addPost = postsOf(stub).find((request) => request.path === `/api/v1/reports/${draft.id}/screenshots`);
  expect(addPost?.idempotencyKey).toMatch(IDEMPOTENCY_KEY_PATTERN);
  expect(addPost?.idempotencyKey).not.toBe(reportPosts[0].idempotencyKey);
});

test('a remembered report draft pinround answers 404 for is dropped with a plain message, gets nothing added, and the pins stay', async ({ context, testPage }) => {
  const omarsScreenshot: StubScreenshot = { upload_id: 'upload-omar', title: null, summary: null, pins: [] };
  const stub = await answerPinroundApi(context, { reportDrafts: [{ id: OMARS_DRAFT_ID, owner: 'omar', project: 'SHOP', title: 'Receipt is blank', screenshots: [omarsScreenshot] }] });
  const popup = await pairedPopupFor(context, testPage);
  await rememberInSession(popup, {
    pinroundCaptureTarget: { kind: 'new-report' },
    pinroundNewReport: { project: 'SHOP', reportTitle: 'Basket total is wrong', draftId: OMARS_DRAFT_ID, draftUrl: `${PINROUND_TEST_ORIGIN}/report-drafts/${OMARS_DRAFT_ID}`, screenshotCount: 1, pendingCapture: null },
  });
  await popup.reload();
  await expect(popup.getByLabel(TARGET_PICKER)).toHaveValue('new-report');

  await pinAndCapture(popup, testPage, 'Total is wrong', [0.5, 0.5]);

  await expect(popup.getByRole('status')).toHaveText(REPORT_GONE);
  expect(routesOf(postsOf(stub)).at(-1)).toEqual([`/api/v1/reports/${OMARS_DRAFT_ID}/screenshots`, 404]);
  expect(stub.reportDrafts.get(OMARS_DRAFT_ID)?.screenshots).toEqual([omarsScreenshot]);
  expect(stub.myReportDrafts()).toEqual([]);
  await expect(popup.getByLabel(TARGET_PICKER)).toHaveValue('');
  expect(await newReportInSession(popup)).toBeUndefined();
  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
});

test('a report draft pinround holds 10 pictures of refuses the next with its message, and the pins stay', async ({ context, testPage }) => {
  const tenScreenshots = Array.from({ length: 10 }, (_, index): StubScreenshot => ({ upload_id: `upload-earlier-${index + 1}`, title: null, summary: null, pins: [] }));
  const stub = await answerPinroundApi(context, { reportDrafts: [{ id: MY_FULL_DRAFT_ID, owner: 'lena', project: 'SHOP', title: 'Basket total is wrong', screenshots: tenScreenshots }] });
  const popup = await pairedPopupFor(context, testPage);
  // The popup counts 9, as when a picture was added from another device: only pinround's own count can refuse.
  await rememberInSession(popup, {
    pinroundCaptureTarget: { kind: 'new-report' },
    pinroundNewReport: { project: 'SHOP', reportTitle: 'Basket total is wrong', draftId: MY_FULL_DRAFT_ID, draftUrl: `${PINROUND_TEST_ORIGIN}/report-drafts/${MY_FULL_DRAFT_ID}`, screenshotCount: 9, pendingCapture: null },
  });
  await popup.reload();

  await pinAndCapture(popup, testPage, 'Total is wrong', [0.5, 0.5]);

  await expect(popup.getByRole('status')).toHaveText(STUB_REPORT_FULL_TEXT);
  expect(routesOf(postsOf(stub)).at(-1)).toEqual([`/api/v1/reports/${MY_FULL_DRAFT_ID}/screenshots`, 422]);
  expect(stub.reportDrafts.get(MY_FULL_DRAFT_ID)?.screenshots).toEqual(tenScreenshots);
  await expect(popup.getByRole('button', { name: 'Send it again' })).toBeHidden();
  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
});

test('each target shows only its own fields: none for a ticket, the report fields for a new report, the title for a new notebook', async ({ context, testPage }) => {
  await answerPinroundApi(context, { tickets: TICKETS });
  const popup = await pairedPopupFor(context, testPage);
  const reportFields = popup.getByRole('group', { name: 'New report' });
  const notebookFields = popup.getByRole('group', { name: 'New notebook' });

  for (const [label, isReportShown, isNotebookShown] of [
    ['My draft round on SHOP-7', false, false],
    ['A new report', true, false],
    ['A new notebook', false, true],
    ['Choose where to put it', false, false],
  ] as const) {
    await popup.getByLabel(TARGET_PICKER).selectOption({ label });
    await expect(reportFields, label).toBeVisible({ visible: isReportShown });
    await expect(notebookFields, label).toBeVisible({ visible: isNotebookShown });
  }
});

test('while a capture is under way the whole form is disabled, so "Start over" and "Done" cannot act on it, and focus comes back after', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context);
  const popup = await pairedPopupFor(context, testPage);
  await chooseNewReport(popup);
  await pinAndCapture(popup, testPage, 'Total is wrong', [0.5, 0.5]);
  await expect(popup.getByRole('status')).toHaveText(FIRST_REPORT_CAPTURE_SAVED);
  let releaseUpload = () => {};
  const uploadReleased = new Promise<void>((resolve) => {
    releaseUpload = resolve;
  });
  await context.route(`${PINROUND_TEST_ORIGIN}/api/v1/uploads`, async (route) => {
    await uploadReleased;
    await route.fallback();
  });

  await pinAndCapture(popup, testPage, 'Jam price', [0.7, 0.2]);
  await expect(popup.getByRole('status')).toHaveText('Uploading…');
  for (const control of [popup.getByLabel(TARGET_PICKER), popup.getByRole('button', { name: 'Start over' }), popup.getByRole('button', { name: 'Done' }), popup.getByRole('button', { name: 'Capture this page' }), popup.getByRole('button', { name: 'Pin this page' })]) {
    await expect(control).toBeDisabled();
  }
  releaseUpload();

  await expect(popup.getByRole('status')).toHaveText('Screenshot 2 added to the report draft. Capture more pages, or press Done to open it.');
  await expect(popup.getByRole('button', { name: 'Start over' })).toBeEnabled();
  await expect(popup.getByRole('button', { name: 'Capture this page' })).toBeFocused();
  await expect(popup.getByLabel('Project key')).toBeDisabled();
  const [draft, ...otherDrafts] = stub.myReportDrafts();
  expect(otherDrafts).toEqual([]);
  expect(notesOf(draft.screenshots)).toEqual([['Total is wrong'], ['Jam price']]);
});

test('"Capture this page" with no target or an unmade notebook says what to do, sends nothing, and the pins stay for the next try', async ({ context, testPage }) => {
  const stub = await answerPinroundApi(context, { tickets: TICKETS });
  const popup = await pairedPopupFor(context, testPage);

  await pinAndCapture(popup, testPage, 'Total is wrong', [0.5, 0.5]);
  await expect(popup.getByRole('status')).toHaveText('Choose where to put the screenshot first.');
  await popup.getByLabel(TARGET_PICKER).selectOption({ label: 'A new notebook' });
  await popup.getByRole('button', { name: 'Capture this page' }).click();
  await expect(popup.getByRole('status')).toHaveText('Make the notebook first.');

  expect(postsOf(stub)).toEqual([]);
  expect(stub.uploads.size).toBe(0);
  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
  await popup.getByLabel(TARGET_PICKER).selectOption({ label: 'My draft round on SHOP-7' });
  await popup.getByRole('button', { name: 'Capture this page' }).click();
  await expect(popup.getByRole('status')).toHaveText('Added to your draft round on SHOP-7. Only you see it.');
  const [, addPost] = postsOf(stub);
  expect((addPost.body?.pins as StubPin[]).map((pin) => pin.note)).toEqual(['Total is wrong']);
});
