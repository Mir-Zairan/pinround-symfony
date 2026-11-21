import type { BrowserContext, Page, Response } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import path from 'node:path';
import { demoPassword, OMAR_EMAIL, SARA_EMAIL } from './auth';
import { runAsDatabaseOwner } from './database';
import {
  CAPTURE_HOST_PERMISSIONS,
  EXTENSION_ID,
  TEST_PAGE_ORIGIN,
  chooseNewReport,
  expect,
  openPopupFor,
  test,
  type ExtensionPageGlobals,
  type StubScenario,
  type StubScenarioStep,
} from './extension';
import { issueTokenFor, McpSession, revokeTokensLeftOpen, UUID, type IssuedToken } from './mcp';

// The extension's journeys against the real dev stack (P13.T5 criteria 2 and 3, the P13 notebook journey): the popup
// is paired on the options page with a real token, captures go through the app's REST API and the presigned PUT to the
// object storage, and the person sees the result on the app's own pages, logged in in the same browser. Only the test
// pages, on their own origin, are answered by context.route. Needs the dev stack up (app, worker, postgres, minio at
// OBJECT_STORAGE_PUBLIC_URL) and E2E_PASSWORD; mcp.ts writes the tokens.

const BASKET_PAGE_URL = `${TEST_PAGE_ORIGIN}/basket`;
const BASKET_PAGE_FILE = path.join(__dirname, 'fixtures', 'extension', 'capture-page-2.html');
const PLAYER_PAGE_URL = `${TEST_PAGE_ORIGIN}/player`;
const PLAYER_PAGE_FILE = path.join(__dirname, 'fixtures', 'extension', 'player-page.html');
const TARGET_PICKER = 'Put the screenshot in';
const FIRST_REPORT_CAPTURE_SAVED = 'Report draft made with screenshot 1. Capture more pages, or press Done to open it.';
const REPORTS_WRITE_MISSING = 'Your pinround token cannot make reports. Make a token that may prepare report drafts on the API tokens page, then pair the extension again.';
const SHOP_HOST_QUESTION = 'This scenario clicks and types on shop.acme-foods.test. It can change data there.';
const RAN_TO_ITS_END = 'The scenario ran to its end.';

test.use({ extensionHostPermissions: CAPTURE_HOST_PERMISSIONS });

/** A report draft or notebook a test is about to make, found again by its owner and its run-unique title. */
interface MadeByTitle {
  ownerEmail: string;
  title: string;
}

// Recorded before the popup makes them, so a run that fails before the id is read back still deletes them.
const madeReportDrafts: MadeByTitle[] = [];
const madeNotebooks: MadeByTitle[] = [];

// A .env.dev.local that sets EXTENSION_ORIGINS to only a person's own extension drops the test copy's origin, and
// every popup POST then fails on RestRequestGuard's 403 deep in a journey. Say so before the journey starts.
test.beforeEach(async ({ request, baseURL }) => {
  const preflight = await request.fetch(`${String(baseURL)}/api/v1/me`, { method: 'OPTIONS', headers: { Origin: `chrome-extension://${EXTENSION_ID}` } });
  expect(preflight.status(), `the app's EXTENSION_ORIGINS must hold chrome-extension://${EXTENSION_ID} (set in .env.dev; a .env.dev.local that sets its own must list it too, comma-separated); restart the app after changing it`).toBe(204);
});

test.afterEach(() => {
  revokeTokensLeftOpen();
  for (const { ownerEmail, title } of madeReportDrafts.splice(0)) {
    runAsDatabaseOwner(`DELETE FROM rvw_report_draft d USING idn_user u WHERE u.id = d.owner_id AND u.email = '${ownerEmail}' AND d.title = '${title}'`);
  }
  for (const { ownerEmail, title } of madeNotebooks.splice(0)) {
    runAsDatabaseOwner(`DELETE FROM rvw_notebook n USING idn_user u WHERE u.id = n.owner_id AND u.email = '${ownerEmail}' AND n.title = '${title}'`);
  }
});

/** Answers the basket and the player pages on the test page's origin, beside the test page at `/`. */
async function answerTestPages(context: BrowserContext): Promise<void> {
  await context.route(BASKET_PAGE_URL, (route) => route.fulfill({ path: BASKET_PAGE_FILE, contentType: 'text/html' }));
  await context.route(PLAYER_PAGE_URL, (route) => route.fulfill({ path: PLAYER_PAGE_FILE, contentType: 'text/html' }));
}

/** Pairs the extension with the app on its options page, as a person pastes a token there; the page stays open. */
async function pairOnOptionsPage(context: BrowserContext, appOrigin: string, token: IssuedToken): Promise<Page> {
  const options = await context.newPage();
  await options.goto(`chrome-extension://${EXTENSION_ID}/options.html`);
  await options.getByLabel('pinround address').fill(appOrigin);
  await options.getByText('Use a personal access token instead').click();
  await options.getByLabel('Personal access token').fill(token.plainToken);
  await options.getByRole('button', { name: 'Save and check' }).click();
  await expect(options.locator('#status')).toHaveText('Connected. You can capture pages now.');
  await expect(options.locator('#paired-to')).toHaveText(`Connected to ${appOrigin}.`);
  return options;
}

/** Logs the person in to the app in the extension's browser, so a page the popup opens shows what they see. */
async function logInToApp(context: BrowserContext, appOrigin: string, email: string, firstName: string): Promise<Page> {
  const app = await context.newPage();
  await app.goto(`${appOrigin}/login`);
  await app.getByLabel('Email').fill(email);
  await app.getByLabel('Password').fill(demoPassword());
  await app.getByRole('button', { name: 'Log in' }).click();
  await expect(app.getByRole('button', { name: firstName, exact: true })).toBeVisible();
  return app;
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

async function inSession(extensionPage: Page, key: string): Promise<Record<string, unknown> | undefined> {
  return extensionPage.evaluate(async (wanted) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    return (await chrome.storage.session.get(wanted))[wanted] as Record<string, unknown> | undefined;
  }, key);
}

/** The person's report drafts and upload intents, counted in the database, which the app shows nowhere in full. */
function reportDraftsAndUploadIntentsOf(email: string): string[] {
  return runAsDatabaseOwner(`
    SELECT (SELECT count(*) FROM rvw_report_draft d WHERE d.owner_id = u.id), (SELECT count(*) FROM med_upload_intent i WHERE i.user_id = u.id)
    FROM idn_user u WHERE u.email = '${email}'`);
}

/** Every answer the app gave the popup, as `METHOD path status`, in the order they came. */
function recordAppAnswers(popup: Page, appOrigin: string): string[] {
  const answers: string[] = [];
  popup.on('response', (response: Response) => {
    const url = new URL(response.url());
    if (url.origin === appOrigin) {
      answers.push(`${response.request().method()} ${url.pathname} ${response.status()}`);
    }
  });
  return answers;
}

test('two captures on two test pages for "A new report" make one report draft in the app, the pictures in capture order with their pin notes', async ({ context, testPage, baseURL }) => {
  test.setTimeout(120_000);
  const appOrigin = String(baseURL);
  const reportTitle = `Basket total is wrong ${randomUUID().slice(0, 8)}`;
  const token = issueTokenFor(OMAR_EMAIL, ['read', 'reports:write']);
  await answerTestPages(context);
  const app = await logInToApp(context, appOrigin, OMAR_EMAIL, 'Omar');
  await pairOnOptionsPage(context, appOrigin, token);

  const popup = await openPopupFor(context, testPage);
  await chooseNewReport(popup, { project: 'SHOP', title: reportTitle });
  madeReportDrafts.push({ ownerEmail: OMAR_EMAIL, title: reportTitle });
  await pinAndCapture(popup, testPage, 'Total is wrong', [0.3, 0.4]);
  await expect(popup.getByRole('status')).toHaveText(FIRST_REPORT_CAPTURE_SAVED);
  const draftId = String((await inSession(popup, 'pinroundNewReport'))?.draftId);
  expect(draftId).toMatch(UUID);

  await testPage.goto(BASKET_PAGE_URL);
  const popupOnBasket = await openPopupFor(context, testPage);
  await expect(popupOnBasket.getByLabel(TARGET_PICKER)).toHaveValue('new-report');
  await expect(popupOnBasket.getByText('1 of 10 screenshots in the report draft.')).toBeVisible();
  await pinAndCapture(popupOnBasket, testPage, 'Jam price', [0.7, 0.2]);
  await expect(popupOnBasket.getByRole('status')).toHaveText('Screenshot 2 added to the report draft. Capture more pages, or press Done to open it.');

  const draftTabOpened = context.waitForEvent('page');
  await popupOnBasket.getByRole('button', { name: 'Done' }).click();
  const draftTab = await draftTabOpened;
  await expect(draftTab).toHaveURL(`${appOrigin}/report-drafts/${draftId}`);
  await expect(draftTab.getByRole('heading', { name: 'Report draft for SHOP', level: 1 })).toBeVisible();
  await expect(draftTab.getByLabel('What is wrong, in one line')).toHaveValue(reportTitle);
  const editor = draftTab.locator('#draft-shots');
  await expect(editor.getByRole('tab')).toHaveCount(2);
  const picture = (number: number) => editor.locator(`#draft-shots-panel-${number}`);
  await expect(picture(1)).toBeVisible();
  await expect(picture(1).getByLabel('Note for pin 1', { exact: true })).toHaveValue('Total is wrong');
  await editor.getByRole('tab', { name: 'Screenshot 2 of 2' }).click();
  await expect(picture(2)).toBeVisible();
  await expect(picture(2).getByLabel('Note for pin 1', { exact: true })).toHaveValue('Jam price');
  await expect(picture(2).getByLabel('Note for pin 2', { exact: true })).toHaveCount(0);
  expect(runAsDatabaseOwner(`
    SELECT d.id, jsonb_array_length(d.screenshots) FROM rvw_report_draft d JOIN idn_user u ON u.id = d.owner_id
    WHERE u.email = '${OMAR_EMAIL}' AND d.title = '${reportTitle}'`)).toEqual([`${draftId}|2`]);
  await app.close();
});

test('"A new report" with a real token without reports:write shows the plain message, and the app makes no report draft and no upload intent', async ({ context, testPage, baseURL }) => {
  const appOrigin = String(baseURL);
  // Sara, whom no other spec gives an upload or a report draft, so the counts can only move by this test.
  const token = issueTokenFor(SARA_EMAIL, ['read', 'rounds:write', 'notebooks:write']);
  const countsBefore = reportDraftsAndUploadIntentsOf(SARA_EMAIL);
  expect(countsBefore).toHaveLength(1);
  await pairOnOptionsPage(context, appOrigin, token);
  const popup = await openPopupFor(context, testPage);
  const appAnswers = recordAppAnswers(popup, appOrigin);
  await chooseNewReport(popup);

  await pinAndCapture(popup, testPage, 'Total is wrong', [0.5, 0.5]);

  await expect(popup.getByRole('status')).toHaveText(REPORTS_WRITE_MISSING);
  expect(appAnswers.filter((answer) => answer.startsWith('POST '))).toEqual(['POST /api/v1/uploads 403']);
  expect(reportDraftsAndUploadIntentsOf(SARA_EMAIL)).toEqual(countsBefore);
  const remembered = await inSession(popup, 'pinroundNewReport');
  expect([remembered?.draftId ?? null, remembered?.pendingCapture ?? null]).toEqual([null, null]);
  await expect(testPage.locator('pinround-overlay')).toHaveCount(1);
});

const pressCheckStock: StubScenarioStep = { type: 'click', say: 'Press Check stock.', target: { role: 'button', name: 'Check stock' } };
const JOURNEY_SCENARIOS: StubScenario[] = [
  {
    title: 'Stock check',
    why: 'The stock line must show what is on the shelf.',
    setup: '',
    start_url: PLAYER_PAGE_URL,
    steps: [
      pressCheckStock,
      { type: 'expect_text', say: 'The stock line shows 12 jars.', target: { role: 'status' }, text: 'In stock: 12 jars' },
      { type: 'capture', say: 'Keep the stock line.' },
    ],
  },
  {
    title: 'Coupon and pay',
    why: 'A coupon must not stop the payment.',
    setup: 'One SHOP coupon, SHOP10.',
    start_url: PLAYER_PAGE_URL,
    steps: [
      { type: 'type', say: 'Type the coupon.', target: { label: 'Coupon code' }, text: 'SHOP10' },
      { type: 'click', say: 'Press Pay.', target: { role: 'button', name: 'Pay 12.40 EUR' } },
      { type: 'expect_visible', say: 'The thanks show.', target: { text: 'Thank you' } },
    ],
  },
  {
    title: 'Delivery day',
    why: 'Wednesday is the last delivery day.',
    setup: '',
    start_url: PLAYER_PAGE_URL,
    steps: [{ type: 'select', say: 'Choose Wednesday.', target: { label: 'Delivery day' }, option: 'Wednesday' }],
  },
];

test('a capture makes "A new notebook" in the app, Claude Code saves three scenarios to it, and they play from the popup, their capture landing in the notebook', async ({ context, testPage, baseURL, request }) => {
  test.setTimeout(150_000);
  const appOrigin = String(baseURL);
  const notebookTitle = `Basket checks ${randomUUID().slice(0, 8)}`;
  const token = issueTokenFor(OMAR_EMAIL, ['read', 'notebooks:write']);
  await answerTestPages(context);
  const app = await logInToApp(context, appOrigin, OMAR_EMAIL, 'Omar');
  const options = await pairOnOptionsPage(context, appOrigin, token);

  await testPage.goto(PLAYER_PAGE_URL);
  const popup = await openPopupFor(context, testPage);
  await popup.getByLabel(TARGET_PICKER).selectOption({ label: 'A new notebook' });
  await popup.getByLabel('Notebook title').fill(notebookTitle);
  madeNotebooks.push({ ownerEmail: OMAR_EMAIL, title: notebookTitle });
  await popup.getByRole('button', { name: 'Make the notebook' }).click();
  await expect(popup.getByRole('status')).toHaveText('Notebook made. Captures now go to it.');
  const notebookId = String((await inSession(popup, 'pinroundCaptureTarget'))?.notebookId);
  expect(notebookId).toMatch(UUID);
  await pinAndCapture(popup, testPage, 'Coupon field is too short', [0.4, 0.6]);
  await expect(popup.getByRole('status')).toHaveText('Screenshot 1 added to the notebook. Only you see it.');
  await popup.close();

  const session = await McpSession.start(request, token.plainToken);
  const saved = await session.callTool('save_scenarios', { notebook: notebookId, scenarios: JOURNEY_SCENARIOS });
  expect(saved.isError, saved.text).toBe(false);
  await options.getByLabel('Host to add').fill(TEST_PAGE_ORIGIN);
  await options.getByRole('button', { name: 'Add', exact: true }).click();
  await expect(options.locator('#allowed-host-status')).toHaveText(`Added ${TEST_PAGE_ORIGIN}.`);

  await app.goto(`${appOrigin}/notebooks/${notebookId}`);
  await expect(app.getByRole('heading', { name: /^Screenshot \d+$/, level: 3 })).toHaveText(['Screenshot 1']);

  await testPage.reload();
  const player = await openPopupFor(context, testPage);
  await expect(player.getByLabel(TARGET_PICKER).locator('option:checked')).toHaveText(`Notebook: ${notebookTitle}`);
  await expect(player.locator('#scenario-list li')).toHaveText(JOURNEY_SCENARIOS.map((scenario) => scenario.title));
  await expect(player.locator('#scenario-position')).toHaveText('Scenario 1 of 3');

  await player.getByRole('button', { name: 'Play', exact: true }).click();
  await expect(player.getByRole('group', { name: SHOP_HOST_QUESTION })).toBeVisible();
  await player.getByRole('button', { name: 'Play anyway' }).click();
  await expect(player.getByRole('status')).toHaveText(RAN_TO_ITS_END);
  await expect(testPage.locator('#stock')).toBeVisible();

  await player.getByRole('button', { name: 'Next' }).click();
  await expect(player.locator('#scenario-title')).toHaveText('Coupon and pay');
  // Asked once per host: this run reaches its end without anyone answering a question.
  await player.getByRole('button', { name: 'Play', exact: true }).click();
  await expect(player.getByRole('status')).toHaveText(RAN_TO_ITS_END);
  await expect(testPage.locator('#coupon')).toHaveValue('SHOP10');
  await expect(testPage.locator('#thanks')).toBeVisible();
  await player.getByRole('button', { name: 'Next' }).click();
  await expect(player.locator('#scenario-title')).toHaveText('Delivery day');
  await expect(player.locator('#scenario-position')).toHaveText('Scenario 3 of 3');
  await expect(testPage.locator('#day')).toHaveValue('Monday');
  await player.getByRole('button', { name: 'Play', exact: true }).click();
  await expect(player.getByRole('status')).toHaveText(RAN_TO_ITS_END);
  await expect(testPage.locator('#day')).toHaveValue('Wednesday');

  await app.bringToFront();
  await app.goto(`${appOrigin}/notebooks`);
  const card = app.locator('article.report-drafts__card').filter({ hasText: notebookTitle });
  await expect(card).toContainText('2 screenshots · 3 scenarios');
  await card.getByRole('link', { name: notebookTitle }).click();
  await expect(app).toHaveURL(`${appOrigin}/notebooks/${notebookId}`);
  const shots = app.locator('section.report-draft__picture[aria-labelledby^="notebook-shot-"]');
  await expect(shots.getByRole('heading', { name: /^Screenshot \d+$/, level: 3 })).toHaveText(['Screenshot 1', 'Screenshot 2']);
  await expect(shots.nth(0).locator('.pin-list__note')).toHaveText(['Coupon field is too short']);
  await expect(shots.nth(1).locator('.screenshot__title')).toHaveText('Stock check');
  await expect(shots.nth(1).locator('.screenshot__summary')).toHaveText('Keep the stock line.');
  await expect(app.getByRole('region', { name: 'Scenarios' }).getByRole('listitem')).toHaveText(JOURNEY_SCENARIOS.map((scenario) => scenario.title));
});
