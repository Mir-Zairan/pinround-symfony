# AgentApi

The REST API under `/api/v1` and the MCP tools at `/mcp` for the browser plugin, the CLI and AI agents. These are thin adapters: each call goes to another module's Api after the same Voter check as the web UI. It owns the table `api_idempotency_key`. It has no Api of its own. It may use the Api of Identity, Ticketing, Review, Conversation, Media, Audit, Insight and Workflow.

## Depends on

- Identity Api: `CurrentApiToken::access()`, the request's `ApiTokenAccess` (token id, organization, scope names), which the stateless `api_token` firewall (`TokenAuthenticator`, security.yaml) sets for every request under `/mcp` and `/api/v1`; `CurrentUser::id()`, the token's user; `UserQueries::byIds()`, for whoami's display name.
- Audit Api: `AuditLog::record()` and `AuditDataAllowList`, for the row of each tool call.

## What it has now

- The MCP server (ADR-0021, P09.T2): `symfony/mcp-bundle` pinned to exactly 0.15.0, on `mcp/sdk` 0.9 (config/packages/mcp.yaml). The server key is `pinround` (its `serverInfo.name`), and its route `_mcp_endpoint_pinround` answers GET, POST, DELETE and OPTIONS at `/mcp` (config/routes/mcp.yaml).
  - The registry is `'*'`: the tools are `AgentTool`s, not `#[McpTool]` services, and the bundle refuses a namespace pattern that matches no `#[McpTool]` service. A vendor `#[McpTool]` could show in `tools/list` but never run, as `GatedToolCallHandler` knows only the `AgentTool`s.
  - Sessions (`Mcp-Session-Id`) live in the pool `cache.mcp_sessions` on the state Valkey, which never evicts (ADR-0010) and which every app container shares; the test env keeps them there too. The session lock is off, so two requests of one session at once can overwrite each other's session write; turn on `session.lock` with a `LockFactory` before a tool keeps state in the session. A session carries protocol state only: every request still authenticates by its own Bearer token.
  - The SDK has its own checks behind ours: a 4 MB body cap, and a DNS rebinding check whose allowed host is DEFAULT_URI's (`allowed_hosts`). It also lets an `Origin` through only when it equals the request's own scheme and host, so a request must reach PHP with the public scheme (behind a TLS proxy, through trusted proxies).
- `Infrastructure\Mcp\McpRequestGuard`, on every main request under `/mcp`, in this order:
  1. Before the firewall, so no token is looked up for a refused request: a Host other than DEFAULT_URI's gets 400 (`UNKNOWN_HOST`), an `Origin` other than DEFAULT_URI's origin 403 (`FOREIGN_ORIGIN`), and a body over 1 MB 413 (`BODY_TOO_LARGE`; a Content-Length over the cap is refused unread, a body without one is read to one byte past the cap).
  2. The firewall: a missing, revoked, expired or unknown token, and a browser session cookie alone, get 401.
  3. After `EnterChosenOrganization` and the request transaction's BEGIN: `ToolCallLimiter` spends one call, and a spent limit gets 429 with `Retry-After` in seconds to the end of the clock's minute or hour.
- `Infrastructure\Mcp\ToolCallLimiter` (rate_limiter.yaml): per token 60 calls a minute and 600 an hour, per organization 600 a minute (`TOKEN_TOO_FAST`, `TOKEN_HOUR_SPENT`, `ORGANIZATION_TOO_FAST`). Each counter is keyed by the clock's minute or hour, on the state Valkey; a counter that cannot be read or written fails the call. A refused call spends nothing of the limits after the one that refused it.
- The tools (P09.T2 steps 3 to 5), all in `Infrastructure\Mcp`:
  - `AgentTool`, the contract of a tool (tagged `agent_api.tool`): its definition (name, description, input schema), the scope it needs, `targetOf()` (a `ToolTarget`: the voter attribute and subject the web UI checks, and the audit target) and `call()`. A tool may throw a `ToolRefusal` (not found, read-only) before it writes anything.
  - `AgentToolLoader` (an SDK loader) lists each tool in `tools/list`; the registry's own handler for a tool only throws.
  - `GatedToolCallHandler` (an SDK request handler, asked before the SDK's `CallToolHandler`) answers every `tools/call`. An unknown tool name gets a JSON-RPC invalid-params error and no audit row.
  - `ToolCallGate` runs each call: the token's scope, the input schema (a new SDK `SchemaValidator` per call, which logs nothing), `targetOf()`, the voter (a denial is "not found", ADR-0013), `call()`. A missing scope, a not-found or a read-only refusal is a tool error result with the `ToolRefusal` message; invalid input is a JSON-RPC invalid-params error; any other failure is the generic `TOOL_FAILED` result, logged with the tool name and the exception class only.
  - `ToolCallAuditor` writes one `aud_event` per call that reached the gate, refused or not: actor kind `token` with the token id and its user, action `mcp.tool_called`, the target (or type `mcp_tool` and no id), and data exactly `tool` and `outcome` (`ok`, `not_found`, `read_only`, `missing_scope`, `invalid_input`, `error`). Never the input, the output or the token.
  - `Tool\WhoAmI` (`whoami`, scope `read`, no target): the token's user id and display name, its organization id and its scopes.
- Proof: `McpEndpointTest` (functional: the handshake, each refusal with its reason and the token and counters unchanged, both limits, `tools/list` and `whoami` through the real endpoint), `McpRequestGuardTest`, `ToolCallLimiterTest`, `ToolCallGateTest`, `GatedToolCallHandlerTest`, `ToolCallAuditorTest` and `AgentToolLoaderTest` (unit).

Not built yet: the organization name in `whoami` (Organization has no Api for it), the session binding to its token and whoami's token expiry (P09.T2, task 5b), the other tools (P09.T3 on), and any guard or limit on `/api/v1` (P09.T7).

See the [module catalogue](../../docs/02-architecture/modules.md#agentapi-api_).
