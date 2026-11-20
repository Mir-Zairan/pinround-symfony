import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { MIA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { runAsDatabaseOwner } from './database';
import { runPhp } from './draft-picture';
import { ACCEPT, issueOmarsToken, McpSession, PROTOCOL_VERSION, revokeOnTokensPage, revokeTokensLeftOpen, UUID, type ToolAnswer } from './mcp';
import { makeTicketForOmar, openAs } from './ticket';

// P09.T5's journey with the real processing worker and bucket: Omar's token → create_upload → PUT to the presigned URL
// → add_screenshot → the picture is in Omar's draft round, and Mia, the tester, does not see it. The tool contracts and
// their refusals are proven in tests/Functional/AgentApi/Tool. mcp.ts says how the token is made and revoked.

test.use({ viewport: { width: 1280, height: 900 } });

/** read and rounds:write, as the token page makes it by default. */
const UPLOAD_SCOPES = ['read', 'rounds:write'] as const;
const DRAFT_HEADLINE = 'Draft round · only you can see this';
// P09.T5's acceptance: the screenshot is in the draft within 10 s of the upload.
const PROCESSING_LIMIT_MS = 10_000;
// AddScreenshot::STILL_PROCESSING's status and PICTURE_REJECTED.
const PROCESSING = 'processing';
const PICTURE_REJECTED = 'The picture was rejected: it is not a PNG, JPEG or WebP screenshot we can read, or it is too big. Upload another one with create_upload.';
const CHECKOUT_PNG = readFileSync(path.join(__dirname, 'fixtures', 'checkout-page.png'));

interface UploadSlot {
  upload_id: string;
  put_url: string;
  headers: Record<string, string>;
}

test.afterEach(revokeTokensLeftOpen);

const IMAGE_FIXTURES = 'App\\Tests\\Fixtures\\Media\\ImageFixtures';

/** A value of tests/Fixtures/Media/ImageFixtures, which the media security tests use, read in the app: `ImageFixtures::<member>`. */
function mediaFixture(member: string): Buffer {
  return Buffer.from(runPhp(`require "vendor/autoload.php"; echo base64_encode(${IMAGE_FIXTURES}::${member});`), 'base64');
}

/** create_upload for the file, then the PUT to put_url with the headers it gave; returns the upload_id. */
async function uploadThroughMcp(request: APIRequestContext, session: McpSession, key: string, picture: Buffer): Promise<string> {
  const begun = await session.callTool('create_upload', { key, mime: 'image/png', bytes: picture.length, sha256: createHash('sha256').update(picture).digest('hex') });
  expect(begun.isError, begun.text).toBe(false);
  const slot = begun.structured as unknown as UploadSlot;
  expect(slot.upload_id).toMatch(UUID);
  const stored = await request.put(slot.put_url, { headers: slot.headers, data: picture });
  expect(stored.status(), await stored.text()).toBe(200);
  return slot.upload_id;
}

/** Calls add_screenshot again while it answers "processing", for at most PROCESSING_LIMIT_MS; returns the last answer. */
async function addScreenshotOnceProcessed(session: McpSession, toolArguments: Record<string, unknown>): Promise<ToolAnswer> {
  const last: { answer?: ToolAnswer } = {};
  await expect
    .poll(
      async () => {
        last.answer = await session.callTool('add_screenshot', toolArguments);
        return last.answer.isError ? 'refused' : last.answer.structured.status;
      },
      { timeout: PROCESSING_LIMIT_MS, intervals: [250, 500, 1_000] },
    )
    .not.toBe(PROCESSING);
  if (undefined === last.answer) {
    throw new Error('add_screenshot was never called.');
  }
  return last.answer;
}

/** On Omar's ticket page: the id of his open draft round, from its share form. */
async function draftRoundIdOn(page: Page): Promise<string> {
  const shareAction = await page.locator('form.round-card__form', { has: page.locator('.round-card__share') }).getAttribute('action');
  const roundId = /\/rounds\/([^/]+)\/share$/.exec(shareAction ?? '')?.[1] ?? '';
  expect(roundId, 'the share form of the draft').toMatch(UUID);
  return roundId;
}

test.describe('Screenshots from Claude Code', () => {
  test('a screenshot added through MCP is in Omar\'s draft round within 10 s, and Mia, the tester, does not see it', async ({ browser, request }) => {
    test.setTimeout(90_000);
    const key = await makeTicketForOmar(browser, 'Claude Code adds a checkout screenshot');
    const token = issueOmarsToken(UPLOAD_SCOPES);
    const session = await McpSession.start(request, token.plainToken);

    const uploadId = await uploadThroughMcp(request, session, key, CHECKOUT_PNG);
    const added = await addScreenshotOnceProcessed(session, { key, upload_id: uploadId, title: 'Checkout page after the fix' });

    expect(added.isError, added.text).toBe(false);
    expect(added.structured).toMatchObject({ status: 'added', pin_count: 0 });
    const screenshotId = String(added.structured.screenshot_id);
    expect(screenshotId).toMatch(UUID);

    const { context: omarContext, page: omar } = await openAs(browser, OMAR_STORAGE_STATE);
    await omar.goto(`/t/${key}`, { waitUntil: 'networkidle' });
    await expect(omar.getByRole('heading', { name: DRAFT_HEADLINE, level: 3 })).toBeVisible();
    const shot = omar.locator(`section.draft-shot[data-screenshot-id="${screenshotId}"]`);
    await expect(omar.locator('section.draft-shot')).toHaveCount(1);
    await expect(shot.getByLabel('Picture title · optional')).toHaveValue('Checkout page after the fix');
    const picture = shot.locator('img').first();
    await picture.scrollIntoViewIfNeeded();
    await expect.poll(() => picture.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0), { message: 'the processed picture loads' }).toBe(true);
    const roundId = await draftRoundIdOn(omar);

    const { context: miaContext, page: mia } = await openAs(browser, MIA_STORAGE_STATE);
    await mia.goto(`/t/${key}`, { waitUntil: 'networkidle' });
    await expect(mia.locator('.ticket').first(), 'Mia sees the ticket').toHaveAttribute('data-ticket-key', key);
    // A lazy frame: the absence checks below prove something only once it has loaded.
    const miasRounds = mia.locator('turbo-frame#ticket-rounds');
    await miasRounds.scrollIntoViewIfNeeded();
    await expect(miasRounds).toHaveAttribute('complete', '');
    await expect(mia.getByRole('heading', { name: /Draft round|self-check/, level: 3 })).toHaveCount(0);
    await expect(mia.locator('[data-screenshot-id]')).toHaveCount(0);
    const roundsFrame = await miaContext.request.get(`/t/${key}/rounds`);
    expect(roundsFrame.status()).toBe(200);
    const roundsHtml = await roundsFrame.text();
    expect(roundsHtml).not.toContain(screenshotId);
    expect(roundsHtml).not.toContain(roundId);
    await miaContext.close();

    await revokeOnTokensPage(omar, token);
    const afterRevoke = await request.post('/mcp', {
      headers: { Authorization: `Bearer ${token.plainToken}`, Accept: ACCEPT },
      data: { jsonrpc: '2.0', id: 0, method: 'initialize', params: { protocolVersion: PROTOCOL_VERSION, capabilities: {}, clientInfo: { name: 'claude-code', version: '1.0.0' } } },
    });
    expect(afterRevoke.status()).toBe(401);
    await omarContext.close();
  });

  test('a picture far over the pixel limit is refused with the plain message and nothing is added', async ({ browser, request }) => {
    test.setTimeout(90_000);
    const key = await makeTicketForOmar(browser, 'Claude Code sends a huge picture');
    const token = issueOmarsToken(UPLOAD_SCOPES);
    const session = await McpSession.start(request, token.plainToken);
    // 50,000 × 50,000 px in its header, the first case of CleanUploadTest's pictures above the policy.
    const oversized = mediaFixture('pngClaiming(50000, 50000)');

    const uploadId = await uploadThroughMcp(request, session, key, oversized);
    const refused = await addScreenshotOnceProcessed(session, { key, upload_id: uploadId, title: 'Huge picture' });

    expect(refused.isError).toBe(true);
    expect(refused.text).toBe(PICTURE_REJECTED);
    const { context, page: omar } = await openAs(browser, OMAR_STORAGE_STATE);
    await omar.goto(`/t/${key}`, { waitUntil: 'networkidle' });
    await expect(omar.getByRole('heading', { name: DRAFT_HEADLINE, level: 3 })).toBeVisible();
    await expect(omar.locator('section.draft-shot')).toHaveCount(0);
    await expect(omar.getByText('No screenshots yet.')).toBeVisible();
    const roundId = await draftRoundIdOn(omar);
    expect(runAsDatabaseOwner(`SELECT count(*) FROM rvw_screenshot WHERE round_id = '${roundId}'`)).toEqual(['0']);

    await revokeOnTokensPage(omar, token);
    await context.close();
  });

  // The worker re-encodes every picture (P05.T2), so a polyglot is cleaned rather than refused: what Omar's draft shows
  // holds none of the bytes after the picture's end.
  test('a PNG with script bytes after its end is added re-encoded, and the picture Omar sees holds none of them', async ({ browser, request }) => {
    test.setTimeout(90_000);
    const key = await makeTicketForOmar(browser, 'Claude Code sends a polyglot');
    const token = issueOmarsToken(UPLOAD_SCOPES);
    const session = await McpSession.start(request, token.plainToken);
    const scriptBytes = mediaFixture('SCRIPT_BYTES');
    expect(scriptBytes.toString()).toContain('<?php');
    const polyglot = mediaFixture(`withAppended(${IMAGE_FIXTURES}::png(64, 64))`);
    expect(polyglot.includes(scriptBytes), 'the fixture carries the script bytes').toBe(true);

    const uploadId = await uploadThroughMcp(request, session, key, polyglot);
    const added = await addScreenshotOnceProcessed(session, { key, upload_id: uploadId });

    expect(added.isError, added.text).toBe(false);
    expect(added.structured).toMatchObject({ status: 'added' });
    const { context, page: omar } = await openAs(browser, OMAR_STORAGE_STATE);
    await omar.goto(`/t/${key}`, { waitUntil: 'networkidle' });
    const picture = omar.locator(`section.draft-shot[data-screenshot-id="${String(added.structured.screenshot_id)}"] img`).first();
    await picture.scrollIntoViewIfNeeded();
    await expect.poll(() => picture.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0), { message: 'the processed picture loads' }).toBe(true);
    const shown = await context.request.get(await picture.evaluate((image: HTMLImageElement) => image.currentSrc));
    expect(shown.status()).toBe(200);
    const shownBytes = await shown.body();
    expect(shownBytes.length).toBeGreaterThan(0);
    expect(shownBytes.includes(scriptBytes), 'the script bytes in the picture Omar sees').toBe(false);
    expect(shownBytes.includes(Buffer.from('<?php')), 'the start of the script in the picture Omar sees').toBe(false);

    await revokeOnTokensPage(omar, token);
    await context.close();
  });
});
