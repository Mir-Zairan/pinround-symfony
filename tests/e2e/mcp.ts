import { expect, type APIRequestContext, type Page } from '@playwright/test';
import { randomBytes, randomUUID } from 'node:crypto';
import { OMAR_EMAIL } from './auth';
import { runAsDatabaseOwner } from './database';
import { runPhp } from './draft-picture';

// What the MCP specs share: Omar's token (or another demo person's, issueTokenFor), a session on /mcp as Claude Code opens one, and the revoke on /me/tokens.
//
// The token is written straight into idn_api_token, hashed by the app's own pepper: making one on /me/tokens needs a
// fresh two-step proof, and every Acme Foods person who owns a draft has a session other specs share or a spec that
// turns two-step login on and off. Omar revokes it on /me/tokens at the end of each test, and
// revokeTokensLeftOpen, run in the spec's afterEach, revokes it in the database when a test stopped before that.

export const PROTOCOL_VERSION = '2025-06-18';
export const ACCEPT = 'application/json, text/event-stream';
export const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/** The scopes a token may hold (ApiTokenScope); the token page ticks read and rounds:write by default. */
export type TokenScope = 'read' | 'rounds:write' | 'comments:write' | 'tickets:move' | 'reports:write' | 'notebooks:write';

export interface IssuedToken {
  id: string;
  name: string;
  plainToken: string;
}

export interface ToolAnswer {
  isError: boolean;
  text: string;
  structured: Record<string, unknown>;
}

export interface PromptMessage {
  role: string;
  content: { type: string; text: string };
}

const issuedTokenIds: string[] = [];

/** Omar's live token in Acme Foods with exactly these scopes, for one day. */
export function issueOmarsToken(scopes: readonly TokenScope[]): IssuedToken {
  return issueTokenFor(OMAR_EMAIL, scopes);
}

/** The live token in Acme Foods of the demo person with this email, with exactly these scopes, for one day. */
export function issueTokenFor(email: string, scopes: readonly TokenScope[]): IssuedToken {
  expect(scopes.length, 'a token holds at least one scope').toBeGreaterThan(0);
  const plainToken = `pr_${randomBytes(32).toString('base64url')}`;
  // HmacApiTokenHashing, with API_TOKEN_PEPPER read from the app's env as the app reads it; the token goes in on stdin.
  const tokenHash = runPhp(
    'require "vendor/autoload.php"; (new Symfony\\Component\\Dotenv\\Dotenv())->bootEnv(".env"); echo hash_hmac("sha256", stream_get_contents(STDIN), $_SERVER["API_TOKEN_PEPPER"]);',
    plainToken,
  );
  expect(tokenHash).toMatch(/^[0-9a-f]{64}$/);
  const name = `Claude Code check ${randomUUID().slice(0, 8)}`;
  const scopeArray = `ARRAY[${scopes.map((scope) => `'${scope}'`).join(', ')}]`;
  const [id] = runAsDatabaseOwner(`
    INSERT INTO idn_api_token (organization_id, id, user_id, name, token_hash, prefix, scopes, created_at, expires_at)
    SELECT o.organization_id, gen_random_uuid(), u.id, '${name}', decode('${tokenHash}', 'hex'), '${plainToken.slice(0, 11)}',
      ${scopeArray}, now(), now() + interval '1 day'
    FROM org_organization o, idn_user u WHERE o.slug = 'acme-foods' AND u.email = '${email}'
    RETURNING id`);
  expect(id, `the seeded Acme Foods or ${email} is missing: run make seed`).toMatch(UUID);
  issuedTokenIds.push(id);
  return { id, name, plainToken };
}

/** For the spec's afterEach: revokes in the database every token issueOmarsToken made that no test revoked. */
export function revokeTokensLeftOpen(): void {
  for (const id of issuedTokenIds.splice(0)) {
    runAsDatabaseOwner(`UPDATE idn_api_token SET revoked_at = now() WHERE id = '${id}' AND revoked_at IS NULL`);
  }
}

export class McpSession {
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

  /** prompts/get without arguments; returns the prompt's messages, or fails on a JSON-RPC error. */
  async getPrompt(name: string): Promise<PromptMessage[]> {
    const answer = await this.request.post('/mcp', {
      headers: this.headers(),
      data: { jsonrpc: '2.0', id: this.nextId++, method: 'prompts/get', params: { name } },
    });
    expect(answer.status(), await answer.text()).toBe(200);
    const body = (await answer.json()) as { result?: { messages: PromptMessage[] }; error?: { message: string } };
    expect(body.error, 'the JSON-RPC error of prompts/get').toBeUndefined();
    return body.result?.messages ?? [];
  }

  private headers(): Record<string, string> {
    return { Authorization: `Bearer ${this.plainToken}`, Accept: ACCEPT, 'Mcp-Session-Id': this.sessionId, 'MCP-Protocol-Version': PROTOCOL_VERSION };
  }
}

export async function revokeOnTokensPage(page: Page, token: IssuedToken): Promise<void> {
  await page.goto('/me/tokens', { waitUntil: 'networkidle' });
  const row = page.locator('li.api-tokens__row', { hasText: token.name });
  await expect(row.locator('code')).toHaveText(`${token.plainToken.slice(0, 11)}…`);
  await row.getByRole('button', { name: 'Revoke' }).click();
  await expect(page.getByRole('main').getByRole('status').filter({ hasText: 'The token is revoked. It no longer works.' })).toBeVisible();
  await expect(page.locator('li.api-tokens__row', { hasText: token.name })).toHaveCount(0);
}
