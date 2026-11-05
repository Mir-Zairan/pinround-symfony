# Trade-offs and risks

The main tensions in pinround's design, what we chose, what it costs, and when we would change our mind.

## Index

- [How to read this](#how-to-read-this) — the scene and the format.
- [Trade-off table](#trade-off-table) — each tension with choice, reason, cost and revisit trigger.
- [Notes on the hardest calls](#notes-on-the-hardest-calls) — longer reasoning for four of them.
- [Risks register](#risks-register) — likelihood, impact, mitigation, owner.

## How to read this

Lena asks: "Why don't we encrypt the ticket text like the Jira tokens? Wouldn't that be safer?" It would be safer against one kind of attack. It would also make search, duplicate suggestions and AI summaries impossible without decrypting everything in PHP. Every row below is a question like Lena's: two good things that pull against each other, the side we picked, and what would make us switch.

Each row links the ADR that holds the full decision.

## Trade-off table

| Tension | Choice | Why | Cost we accept | Revisit when |
|---|---|---|---|---|
| Security vs speed of delivery | Security controls in phase 0–1 (RLS, voters, CSP, gates), even for the hackathon | Retro-fitting isolation is risky and slow; tickets hold security bugs | Slower first two weeks; hackathon demo has fewer features | Never for isolation; MFA/SSO may slip after the hackathon |
| RLS overhead vs isolation | PostgreSQL RLS **plus** Doctrine filter ([ADR-0005](../03-adr/0005-multi-tenancy.md)) | Two independent layers; a forgotten `WHERE` cannot leak | Small CPU cost per query; every transaction needs a transaction-local tenant setting; more complex migrations | Measured RLS cost > 10% of query time on hot paths |
| Hotwire vs SPA | Server-rendered Twig + Turbo + Stimulus + Live Components ([ADR-0007](../03-adr/0007-server-rendered-ui.md)) | One language, one codebase, no public JSON API for every screen, smaller attack surface, fast enough | Rich canvas interactions (pins, review) need careful Stimulus code; fewer ready-made UI libraries | A screen needs offline use or heavy client state that Live Components cannot handle well |
| Modular monolith vs microservices | Modular monolith with Deptrac boundaries ([ADR-0002](../03-adr/0002-modular-monolith.md)) | Team of three; one deploy; real transactions; boundaries still enforced | One scaling unit; one bad deploy affects everything | One module needs a different scale or release pace (likely AI or media workers) |
| Postgres FTS vs search engine | PostgreSQL FTS + `pg_trgm` ([ADR-0022](../03-adr/0022-search-and-duplicates.md)) | No extra system to run and secure; RLS applies to search too | Weaker ranking and typo handling than Meilisearch/OpenSearch | Search p95 > 300 ms on the largest org, or people complain about relevance |
| Laptop AI vs cloud AI quality | Org chooses; laptop first then cloud is offered ([ADR-0018](../03-adr/0018-ai-provider-abstraction.md), [ADR-0019](../03-adr/0019-browser-local-ai.md)) | Small companies get free AI and keep data on laptops | Small models make more mistakes; laptop setup is extra work; no embeddings server-side in laptop-only mode | Small models reach acceptable golden-set scores, or browser local-network rules block the approach |
| Outbox complexity vs reliability | Transactional outbox for domain events ([ADR-0009](../03-adr/0009-async-messaging-and-outbox.md)) | Events are never lost or sent for rolled-back changes | One more table, a relay process, idempotent handlers everywhere | Never; we may swap the relay technology |
| Presigned uploads vs proxy uploads | Presigned PUT straight to storage, then worker processing ([ADR-0011](../03-adr/0011-screenshot-storage-and-processing.md)) | PHP workers never hold big files; scales with storage | Two-step flow; images are "processing" for a moment; more states to handle | Storage provider cannot enforce size/type on presigned URLs |
| Field encryption vs searchability | Encrypt credentials only; ticket text protected by disk encryption, RLS and access control ([ADR-0027](../03-adr/0027-secrets-and-encryption.md)) | Search, duplicates and AI need plain text in the DB | A DB dump with disk access would expose ticket text | A customer contract requires per-tenant keys; then consider per-org encrypted databases |
| Read model vs computing on the fly | Store "whose turn" per ticket ([ADR-0015](../03-adr/0015-turn-read-model.md)) | Home and Whose turn are the most-used screens | Must keep it in sync on every change; nightly check job | Turn rules become so dynamic they cannot be stored |
| Custom transition engine vs Symfony Workflow | Small custom engine on data ([ADR-0014](../03-adr/0014-status-model-five-groups.md)) | Steps differ per team and change at runtime; Symfony Workflow config is static | We own more code and its tests | Symfony Workflow gains good runtime-defined workflows |
| Direct ticket creation vs triage gate | Create directly, flag for checks ([ADR-0016](../03-adr/0016-direct-ticket-creation-with-check-flags.md)) | No bottleneck; reporter sees progress at once | Some noise (duplicates) enters the list before a lead looks | Orgs report too much noise; then they turn on "every new ticket needs a lead check" |

## Notes on the hardest calls

**RLS with connection pooling.** RLS needs the org id in the session. With PgBouncer in transaction mode, a session-level `SET` would leak to the next transaction. We use `SELECT set_config('app.org_id', $1, true)` (transaction-local, bound parameter) inside every transaction, write the policy with `NULLIF(current_setting('app.org_id', true), '')::uuid` so an old setting read back as `''` fails closed, and forbid plain `SET` with a static check. That keeps pooling and isolation together. See [performance-and-scale.md](performance-and-scale.md#connection-pooling).

**Hotwire for a visual tool.** The review canvas (pins on screenshots, before/after, full screen) is the most interactive part. We keep it server-rendered and put the pin placing, zoom and keyboard navigation in one Stimulus controller. Pins are HTML elements over an image, positioned in percent, so the server can render them and Turbo can update them. If this controller grows past what one person can maintain, we may build that one widget as a small isolated component, without moving the whole app to an SPA.

**Laptop AI depends on the browser.** Browsers are adding permission prompts for public sites that call `localhost`. Today that is a one-time "allow" for the user. If a browser blocks it fully, laptop mode needs a tiny local helper or the capture extension as a bridge. We keep laptop mode behind a feature flag and keep "laptop first, then cloud" as the safer default for orgs that allow cloud.

**Security before features in a hackathon.** The hackathon gives two weeks. We still build RLS, voters and CSP first, because a demo with real tickets on a shared screen must not leak. We cut MFA, SSO and Jira from the hackathon slice instead.

## Risks register

Likelihood and impact: L (low), M (medium), H (high). Owner: the role responsible on the team (lead = Zairan's role; dev; tester).

| Risk | Likelihood | Impact | Mitigation | Owner |
|---|---|---|---|---|
| Symfony AI is in beta (1.0.0-BETA1); APIs change | H | M | Wrap in `AiGateway`; pin versions; one adapter to update; golden tests catch behaviour changes | dev |
| Hackathon deadline (2026-11-22 18:00 CET) too tight | H | M | Hackathon slice marked 🏁 in the plan; cut scope, not security; demo script prepared early | lead |
| Browser local-network permission rules change and block laptop AI | M | M | Feature flag; "laptop first, then cloud" fallback; plan B: extension or local helper as bridge | dev |
| Small team (3 people); bus factor | H | H | ADRs and phase files make context explicit; each Claude Code session follows CLAUDE.md; tests as safety net | lead |
| One org sees another org's data through a missed filter | L | H | RLS + Doctrine filter; isolation suite; catalog check for RLS on new tables | dev |
| Prompt injection leads to wrong suggestions accepted blindly | M | M | Suggestions only, ✦ marks, schema validation, injection golden set | dev |
| Jira API changes or rate limits break sync | M | M | Isolated integration queue; reconcile job; clear admin notices; pinround keeps working without Jira | dev |
| Image processing cost or memory spikes | M | M | libvips (low memory), size and pixel limits, isolated media queue, worker restarts | dev |
| Small laptop models too weak for intake JSON | M | L | Golden set before recommending; cloud fallback; FTS duplicates always work without AI | tester |
| Real company data ends up in the repo or fixtures | M | H | CLAUDE.md rule; neutral fixtures; secret scanning; review checklist | lead |
| FrankenPHP worker mode leaks state between requests | M | H | `ResetInterface` discipline; worker state leak test; restart workers after N requests | dev |
| Read model drifts from ticket state | L | M | Same-transaction updates; nightly check and rebuild | dev |
