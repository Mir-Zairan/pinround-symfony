import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { OMAR_STORAGE_STATE } from './auth';
import { issueOmarsToken, McpSession, revokeOnTokensPage, revokeTokensLeftOpen, UUID } from './mcp';
import { openAs, TOAST_REGION } from './ticket';

// P13.T4's journey with Playwright as the MCP client and the real processing worker and bucket: it reads the
// report_while_investigating prompt, checks a page in three cases as the prompt says (one capture each, the pin at the
// element's centre), files them at the end with create_report_upload → PUT → create_report, and Omar opens the report
// draft, sees the three pictures and presses Send: Round 0 of the new ticket holds the three pictures and their pins.
// The prompt's contract and its refusals are proven in tests/Functional/AgentApi/Prompt; mcp.ts says how the token is
// made and revoked.

test.use({ viewport: { width: 1280, height: 900 } });

const PROMPT_NAME = 'report_while_investigating';
const PROMPT_FILE = path.join(__dirname, '..', '..', 'src', 'AgentApi', 'Infrastructure', 'Prompt', 'report_while_investigating.v1.md');
/** The token page leaves reports:write off; the person ticks it for this. No rounds:write: a report needs none. */
const REPORT_SCOPES = ['read', 'reports:write'] as const;
const PROJECT = 'SHOP';
const REPORT_TITLE = 'Checkout at Acme Foods hides the total and drops the coupon';
const REPORT_DETAIL = 'Steps: put two items in the cart and open the checkout. Expected: the total, a working coupon field and a delivery date from today on. Happened: see the three pictures.';
const PROCESSING_TIMEOUT_MS = 60_000;

/** One case the check tries: the page as it is when the problem shows, and the element that shows it. */
interface CheckedCase {
  title: string;
  summary: string;
  pinNote: string;
  html: string;
  pinnedSelector: string;
}

/** What the evidence log holds for one case after its capture. */
interface Evidence {
  checkedCase: CheckedCase;
  picture: Buffer;
  pin: { x_pct: number; y_pct: number; note: string };
}

interface UploadSlot {
  upload_id: string;
  put_url: string;
  headers: Record<string, string>;
}

function checkoutPage(body: string): string {
  return `<!doctype html><html><head><style>
    body { margin: 0; font: 16px/1.4 sans-serif; background: #fafafa; }
    header { padding: 16px 32px; background: #1f5130; color: #fff; font-size: 22px; }
    main { padding: 32px; max-width: 720px; }
    .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #ddd; }
    .banner { position: absolute; left: 32px; top: 230px; width: 720px; padding: 24px; background: #f4c542; }
    button { padding: 8px 16px; }
    .error { color: #b00020; }
  </style></head><body><header>Acme Foods · Checkout</header><main>
    <div class="row"><span>Oat milk, 2 ×</span><span>3.80</span></div>
    <div class="row"><span>Rye bread</span><span>2.90</span></div>
    ${body}
  </main></body></html>`;
}

const CASES: CheckedCase[] = [
  {
    title: 'Checkout total under the promo banner',
    summary: 'The promo banner covers the total, so the buyer cannot see what they pay.',
    pinNote: 'The total is under the banner',
    html: checkoutPage('<div class="row" id="total"><strong>Total</strong><strong>6.70</strong></div><div class="banner" id="banner">Free delivery this week!</div>'),
    pinnedSelector: '#banner',
  },
  {
    title: 'Coupon field after Apply',
    summary: 'After Apply the coupon field is empty and the total stays the same; no message says why.',
    pinNote: 'Apply clears the code and says nothing',
    html: checkoutPage('<p><label>Coupon <input id="coupon" value=""></label> <button id="apply">Apply</button></p><div class="row"><strong>Total</strong><strong>6.70</strong></div>'),
    pinnedSelector: '#apply',
  },
  {
    title: 'Delivery date in the past',
    summary: 'The first delivery date offered is yesterday.',
    pinNote: 'Yesterday is offered as the first date',
    html: checkoutPage('<p>Delivery: <select id="delivery"><option>Yesterday</option><option>Today</option></select></p><p class="error">Pick a delivery date.</p>'),
    pinnedSelector: '#delivery',
  },
];

/** A PNG's own size, from its IHDR chunk. */
function pngSizeOf(picture: Buffer): { width: number; height: number } {
  expect(picture.subarray(1, 4).toString('latin1'), 'a PNG').toBe('PNG');
  return { width: picture.readUInt32BE(16), height: picture.readUInt32BE(20) };
}

/** The prompt's step 3: the "before" picture, and one pin at the centre of the element's box, in the picture's pixels. */
async function captureCase(page: Page, checkedCase: CheckedCase): Promise<Evidence> {
  await page.setContent(checkedCase.html);
  const box = await page.locator(checkedCase.pinnedSelector).boundingBox();
  expect(box, `the box of ${checkedCase.pinnedSelector}`).not.toBeNull();
  const picture = await page.screenshot({ type: 'png' });
  const { width, height } = pngSizeOf(picture);
  // The viewport's CSS pixels are the picture's pixels only at a device scale factor of 1.
  const scale = width / (page.viewportSize()?.width ?? width);
  const centreX = ((box?.x ?? 0) + (box?.width ?? 0) / 2) * scale;
  const centreY = ((box?.y ?? 0) + (box?.height ?? 0) / 2) * scale;
  return { checkedCase, picture, pin: { x_pct: (centreX / width) * 100, y_pct: (centreY / height) * 100, note: checkedCase.pinNote } };
}

/** create_report_upload for the file, then the PUT to put_url with the headers it gave; returns the upload_id. */
async function uploadForReport(request: APIRequestContext, session: McpSession, picture: Buffer): Promise<string> {
  const begun = await session.callTool('create_report_upload', { project: PROJECT, mime: 'image/png', bytes: picture.length, sha256: createHash('sha256').update(picture).digest('hex') });
  expect(begun.isError, begun.text).toBe(false);
  const slot = begun.structured as unknown as UploadSlot;
  expect(slot.upload_id).toMatch(UUID);
  const stored = await request.put(slot.put_url, { headers: slot.headers, data: picture });
  expect(stored.status(), await stored.text()).toBe(200);
  return slot.upload_id;
}

test.afterEach(revokeTokensLeftOpen);

test.describe('Reporting from Claude Code', () => {
  test('three captures filed with create_report become Round 0 of the ticket Omar sends, with their pins', async ({ browser, page, request }) => {
    test.setTimeout(180_000);
    const token = issueOmarsToken(REPORT_SCOPES);
    const session = await McpSession.start(request, token.plainToken);

    const messages = await session.getPrompt(PROMPT_NAME);
    expect(messages).toEqual([{ role: 'user', content: { type: 'text', text: readFileSync(PROMPT_FILE, 'utf8') } }]);

    const evidenceLog: Evidence[] = [];
    for (const checkedCase of CASES) {
      evidenceLog.push(await captureCase(page, checkedCase));
    }
    expect(evidenceLog).toHaveLength(3);

    const uploadIds: string[] = [];
    for (const evidence of evidenceLog) {
      uploadIds.push(await uploadForReport(request, session, evidence.picture));
    }
    const filed = await session.callTool('create_report', {
      project: PROJECT,
      title: REPORT_TITLE,
      detail: REPORT_DETAIL,
      screenshots: evidenceLog.map((evidence, index) => ({
        upload_id: uploadIds[index],
        title: evidence.checkedCase.title,
        summary: evidence.checkedCase.summary,
        pins: [evidence.pin],
      })),
    });
    expect(filed.isError, filed.text).toBe(false);
    expect(filed.structured).toMatchObject({ screenshot_count: 3, status: 'draft' });
    const draftPath = new URL(String(filed.structured.url)).pathname;
    expect(draftPath).toBe(`/report-drafts/${String(filed.structured.draft_id)}`);

    const { context, page: omar } = await openAs(browser, OMAR_STORAGE_STATE);
    // The worker processes the pictures after the PUT; the page shows "Processing…" until each is ready.
    await expect(async () => {
      await omar.goto(draftPath, { waitUntil: 'networkidle' });
      await expect(omar.locator('#draft-shots [role="tabpanel"] img.screenshot__image')).toHaveCount(3, { timeout: 2_000 });
    }).toPass({ timeout: PROCESSING_TIMEOUT_MS });
    await expect(omar.getByRole('heading', { name: `Report draft for ${PROJECT}`, level: 1 })).toBeVisible();
    await expect(omar.getByLabel('What is wrong, in one line')).toHaveValue(REPORT_TITLE);
    const editor = omar.locator('#draft-shots');
    await expect(editor.getByRole('tab')).toHaveCount(3);
    for (const [index, evidence] of evidenceLog.entries()) {
      // The editor shows one screenshot at a time; its thumbnail shows it, and its lazy picture loads only then.
      await editor.getByRole('tab', { name: `Screenshot ${index + 1} of 3` }).click();
      const section = editor.locator(`#draft-shots-panel-${index + 1}`);
      await expect(section).toBeVisible();
      await expect(section.getByLabel('Picture title · optional')).toHaveValue(evidence.checkedCase.title);
      await expect(section.getByLabel('Note for pin 1', { exact: true })).toHaveValue(evidence.pin.note);
      const picture = section.locator('img.screenshot__image');
      await picture.scrollIntoViewIfNeeded();
      await expect.poll(() => picture.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0), { message: 'the processed picture loads' }).toBe(true);
    }

    await omar.getByRole('main').getByRole('button', { name: 'Send', exact: true }).click();
    await expect(omar).toHaveURL(/\/t\/SHOP-\d+$/);
    const key = new URL(omar.url()).pathname.replace('/t/', '');
    await expect(omar.locator(TOAST_REGION)).toHaveText(`Sent. It is ticket ${key} now.`);
    const rounds = omar.locator('turbo-frame#ticket-rounds');
    await rounds.scrollIntoViewIfNeeded();
    await expect(rounds).toHaveAttribute('complete', '');
    await expect(rounds.getByText('Round 0 · Report · by Omar · 3 pins', { exact: true })).toBeVisible();
    const card = rounds.locator('article.round-card', { hasText: 'Round 0 · Report · by Omar' });
    await expect(card.locator('img.screenshot__image')).toHaveCount(3);
    await expect(card.locator('.screenshot__title')).toHaveText(CASES.map((checkedCase) => checkedCase.title));
    const shownPins = card.locator('button.pin');
    await expect(shownPins).toHaveCount(3);
    for (const [index, evidence] of evidenceLog.entries()) {
      const shownPin = shownPins.nth(index);
      expect(Number(await shownPin.getAttribute('data-x-percent'))).toBeCloseTo(evidence.pin.x_pct, 1);
      expect(Number(await shownPin.getAttribute('data-y-percent'))).toBeCloseTo(evidence.pin.y_pct, 1);
    }

    await revokeOnTokensPage(omar, token);
    await context.close();
  });
});
