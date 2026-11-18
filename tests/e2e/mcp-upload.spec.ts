import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { createHash, randomBytes, randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { MIA_STORAGE_STATE, OMAR_EMAIL, OMAR_STORAGE_STATE } from './auth';
import { runAsDatabaseOwner } from './database';
import { runPhp } from './draft-picture';
import { makeTicketForOmar, openAs } from './ticket';

// P09.T5's journey with the real processing worker and bucket: Omar's token → create_upload → PUT to the presigned URL
// → add_screenshot → the picture is in Omar's draft round, and Mia, the tester, does not see it. The tool contracts and
// their refusals are proven in tests/Functional/AgentApi/Tool.
//
// The token is written straight into idn_api_token, hashed by the app's own pepper: making one on /me/tokens needs a
// fresh two-step proof, and every Acme Foods person who owns a draft has a session other specs share or a spec that
// turns two-step login on and off. Omar revokes it on /me/tokens at the end, and afterEach revokes it in the database
// when a test stopped before that.

test.use({ viewport: { width: 1280, height: 900 } });

const DRAFT_HEADLINE = 'Draft round · only you can see this';
const PROTOCOL_VERSION = '2025-06-18';
const ACCEPT = 'application/json, text/event-stream';
// P09.T5's acceptance: the screenshot is in the draft within 10 s of the upload.
const PROCESSING_LIMIT_MS = 10_000;
// AddScreenshot::STILL_PROCESSING's status and PICTURE_REJECTED.
const PROCESSING = 'processing';
const PICTURE_REJECTED = 'The picture was rejected: it is not a PNG, JPEG or WebP screenshot we can read, or it is too big. Upload another one with create_upload.';
const CHECKOUT_PNG = readFileSync(path.join(__dirname, 'fixtures', 'checkout-page.png'));
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

interface IssuedToken {
  id: string;
  name: string;
  plainToken: string;
}

interface ToolAnswer {
  isError: boolean;
  text: string;
  structured: Record<string, unknown>;
}

interface UploadSlot {
  upload_id: string;
  put_url: string;
  headers: Record<string, string>;
}

const issuedTokenIds: string[] = [];

/** Omar's live token in Acme Foods with read and rounds:write, as the token page makes it by default, for one day. */
function issueOmarsToken(): IssuedToken {
  const plainToken = `pr_${randomBytes(32).toString('base64url')}`;
  // HmacApiTokenHashing, with API_TOKEN_PEPPER read from the app's env as the app reads it; the token goes in on stdin.
  const tokenHash = runPhp(
    'require "vendor/autoload.php"; (new Symfony\\Component\\Dotenv\\Dotenv())->bootEnv(".env"); echo hash_hmac("sha256", stream_get_contents(STDIN), $_SERVER["API_TOKEN_PEPPER"]);',
    plainToken,
  );
  expect(tokenHash).toMatch(/^[0-9a-f]{64}$/);
  const name = `Claude Code upload check ${randomUUID().slice(0, 8)}`;
  const [id] = runAsDatabaseOwner(`
    INSERT INTO idn_api_token (organization_id, id, user_id, name, token_hash, prefix, scopes, created_at, expires_at)
    SELECT o.organization_id, gen_random_uuid(), u.id, '${name}', decode('${tokenHash}', 'hex'), '${plainToken.slice(0, 11)}',
      ARRAY['read', 'rounds:write'], now(), now() + interval '1 day'
    FROM org_organization o, idn_user u WHERE o.slug = 'acme-foods' AND u.email = '${OMAR_EMAIL}'
    RETURNING id`);
  expect(id, 'the seeded Acme Foods or Omar is missing: run make seed').toMatch(UUID);
  issuedTokenIds.push(id);
  return { id, name, plainToken };
}

test.afterEach(() => {
  for (const id of issuedTokenIds.splice(0)) {
    runAsDatabaseOwner(`UPDATE idn_api_token SET revoked_at = now() WHERE id = '${id}' AND revoked_at IS NULL`);
  }
});

/** The PNG fixture the media security tests use, built by tests/Fixtures/Media/ImageFixtures in the app. */
function mediaFixture(call: string): Buffer {
  return Buffer.from(runPhp(`require "vendor/autoload.php"; echo base64_encode(App\\Tests\\Fixtures\\Media\\ImageFixtures::${call});`), 'base64');
}

class McpSession {
  private nextId = 1;

  private constructor(private readonly request: APIRequestContext, private readonly plainToken: string, private readonly sessionId: string) {}

  /** initialize and notifications/initialized, as Claude Code starts a session. */
  static async start(request: APIRequestContext, plainToken: string): Promise<McpSession> {
    const initialized = await request.post('/mcp', {
      headers: { Authorization: `Bearer ${plainToken}`, Accept: ACCEPT },
      data: { jsonrpc: '2.0', id: 0, method: 'initialize', params: { protocolVersion: PROTOCOL_VERSION, capabilities: {}, clientInfo: { name: 'claude-code', version: '1.0.0' } } },
    });
    expect(initialized.status(), await initialized.text()).toBe(200);
    const sessionId = initialized.headers()['mcp-session-id'];
    expect(sessionId, 'the Mcp-Session-Id').toMatch(UUID);
    const session = new McpSession(request, plainToken, sessionId);
    const confirmed = await request.post('/mcp', { headers: session.headers(), data: { jsonrpc: '2.0', method: 'notifications/initialized' } });
    expect(confirmed.status()).toBe(202);
    return session;
  }

  async callTool(name: string, toolArguments: Record<string, unknown>): Promise<ToolAnswer> {
    const answer = await this.request.post('/mcp', {
      headers: this.headers(),
      data: { jsonrpc: '2.0', id: this.nextId++, method: 'tools/call', params: { name, arguments: toolArguments } },
    });
    expect(answer.status(), await answer.text()).toBe(200);
    const { result } = (await answer.json()) as { result: { isError?: boolean; content: { text: string }[]; structuredContent?: Record<string, unknown> } };
    return { isError: true === result.isError, text: result.content.map((content) => content.text).join('\n'), structured: result.structuredContent ?? {} };
  }

  private headers(): Record<string, string> {
    return { Authorization: `Bearer ${this.plainToken}`, Accept: ACCEPT, 'Mcp-Session-Id': this.sessionId, 'MCP-Protocol-Version': PROTOCOL_VERSION };
  }
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

async function revokeOnTokensPage(page: Page, token: IssuedToken): Promise<void> {
  await page.goto('/me/tokens', { waitUntil: 'networkidle' });
  const row = page.locator('li.api-tokens__row', { hasText: token.name });
  await expect(row.locator('code')).toHaveText(`${token.plainToken.slice(0, 11)}…`);
  await row.getByRole('button', { name: 'Revoke' }).click();
  await expect(page.getByRole('main').getByRole('status').filter({ hasText: 'The token is revoked. It no longer works.' })).toBeVisible();
  await expect(page.locator('li.api-tokens__row', { hasText: token.name })).toHaveCount(0);
}

test.describe('Screenshots from Claude Code', () => {
  test('a screenshot added through MCP is in Omar\'s draft round within 10 s, and Mia, the tester, does not see it', async ({ browser, request }) => {
    test.setTimeout(90_000);
    const key = await makeTicketForOmar(browser, 'Claude Code adds a checkout screenshot');
    const token = issueOmarsToken();
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
    const token = issueOmarsToken();
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
});
