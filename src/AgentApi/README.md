# AgentApi

The REST API under `/api/v1` and the MCP tools at `/mcp` for the browser plugin, the CLI and AI agents. These are thin adapters: each call goes to another module's Api after the same Voter check as the web UI. It owns the table `api_idempotency_key`. It has no Api of its own. It may use the Api of Identity, Ticketing, Review, Conversation and Media.

See the [module catalogue](../../docs/02-architecture/modules.md#agentapi-api_).
