# AgentApi

The REST API under `/api/v1` and the MCP tools at `/mcp` for the browser plugin, the CLI and AI agents. These are thin adapters: each call goes to another module's Api after the same Voter check as the web UI. It owns the table `api_idempotency_key`. It has no Api of its own. It may use the Api of Identity, Ticketing, Review, Conversation and Media.

## Depends on

- Identity Api: `CurrentApiToken::access()`, the request's `ApiTokenAccess` (token id, organization, scope names), which the stateless `api_token` firewall (`TokenAuthenticator`, security.yaml) sets for every request under `/mcp` and `/api/v1`.

## What it has now

- The MCP server (ADR-0021, P09.T2): `symfony/mcp-bundle` pinned to exactly 0.15.0, on `mcp/sdk` 0.9 (config/packages/mcp.yaml). The server key is `pinround` (its `serverInfo.name`), and its route `_mcp_endpoint_pinround` answers GET, POST, DELETE and OPTIONS at `/mcp` (config/routes/mcp.yaml). No tool exists yet, so `tools/list` is empty.
  - The registry is `'*'`: the bundle refuses a namespace pattern that matches no service. The first tool (P09.T3) narrows it to `App\AgentApi\Infrastructure\Mcp\Tool\`, so no vendor service can add a tool.
  - Sessions (`Mcp-Session-Id`) live in the pool `cache.mcp_sessions` on the state Valkey, which never evicts (ADR-0010) and which every app container shares; the test env keeps them there too. The session lock is off, so two requests of one session at once can overwrite each other's session write; turn on `session.lock` with a `LockFactory` before a tool keeps state in the session. A session carries protocol state only: every request still authenticates by its own Bearer token.
  - The SDK has its own checks behind ours: a 4 MB body cap, and a DNS rebinding check whose allowed host is DEFAULT_URI's (`allowed_hosts`). It also lets an `Origin` through only when it equals the request's own scheme and host, so a request must reach PHP with the public scheme (behind a TLS proxy, through trusted proxies).
- `Infrastructure\Mcp\McpRequestGuard`, on every main request under `/mcp`, in this order:
  1. Before the firewall, so no token is looked up for a refused request: a Host other than DEFAULT_URI's gets 400 (`UNKNOWN_HOST`), an `Origin` other than DEFAULT_URI's origin 403 (`FOREIGN_ORIGIN`), and a body over 1 MB 413 (`BODY_TOO_LARGE`; a Content-Length over the cap is refused unread, a body without one is read to one byte past the cap).
  2. The firewall: a missing, revoked, expired or unknown token, and a browser session cookie alone, get 401.
  3. After `EnterChosenOrganization` and the request transaction's BEGIN: `ToolCallLimiter` spends one call, and a spent limit gets 429 with `Retry-After` in seconds to the end of the clock's minute or hour.
- `Infrastructure\Mcp\ToolCallLimiter` (rate_limiter.yaml): per token 60 calls a minute and 600 an hour, per organization 600 a minute (`TOKEN_TOO_FAST`, `TOKEN_HOUR_SPENT`, `ORGANIZATION_TOO_FAST`). Each counter is keyed by the clock's minute or hour, on the state Valkey; a counter that cannot be read or written fails the call. A refused call spends nothing of the limits after the one that refused it.
- Proof: `McpEndpointTest` (functional: the handshake, each refusal with its reason and the token and counters unchanged, both limits through the real endpoint), `McpRequestGuardTest` and `ToolCallLimiterTest` (unit).

Not built yet: the tools, the scope check, the audit row per call (P09.T2 steps 3 to 5), and any guard or limit on `/api/v1` (P09.T7).

See the [module catalogue](../../docs/02-architecture/modules.md#agentapi-api_).
