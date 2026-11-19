# ADR-0045 — Evidence is captured where the person works and filed in one step at the end

Claude Code or the browser extension takes the screenshots and pins while the person checks a problem. When the
person says "file it", everything goes to pinround in one step, as a report draft or into their draft round.

## Index

- [Status](#status) — Proposed, date, deciders, what it adds to.
- [Context and problem statement](#context-and-problem-statement) — Raj asks Claude Code whether something is a bug.
- [Decision drivers](#decision-drivers) — no second screenshot loop, a person decides, one upload path.
- [Considered options](#considered-options) — client-side evidence log, server-side capture session, server screenshots.
- [Decision outcome](#decision-outcome) — the evidence log, the target choice, new tools, the extension overlay.
- [Confirmation](#confirmation) — contract, e2e and extension tests.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [More information](#more-information) — related ADRs, requirements and revisit triggers.

## Status

Proposed

- Date: 2026-11-19
- Deciders: Zairan Mir (lead, product owner)

It adds to [ADR-0039](0039-agents-file-report-drafts-a-person-sends-them.md) and
[ADR-0040](0040-report-drafts-live-in-review.md). Their rule stands: no token creates a ticket, and a person clicks
Send. When accepted, it supersedes part of [ADR-0021](0021-mcp-server.md)'s tool list (adds `find_tickets` and the
MCP prompt `report_while_investigating`). It also changes the plan of P10.T7: the extension's token may hold
`reports:write`.

## Context and problem statement

Raj, a manager at Acme Foods, hears from a customer that a delivery date change leaves the invoice total wrong. He
asks his Claude Code: "Check the order app on my laptop and tell me if this is a bug." Claude Code reads the code,
opens the app in a browser, tries three cases and finds that case B is wrong. Today Raj must then take the
screenshots again, upload them and add the pins by hand, although Claude Code saw every screen a minute ago.

Mia tests the same app by hand. She sees a problem on one screen and a second one two screens later. She wants to
pin both on the live page and send them as one report, or add them to the ticket she is testing.

How do screenshots, pins and their context, collected while someone checks a problem, reach pinround without a second
round of capturing?

## Decision drivers

- No second loop: what was captured during the check is what gets filed.
- A person decides what becomes a ticket ([ADR-0020](0020-ai-safety-and-prompt-injection.md),
  [ADR-0039](0039-agents-file-report-drafts-a-person-sends-them.md)).
- The pinround server never connects to a person's laptop and never browses their app
  ([ADR-0019](0019-browser-local-ai.md)). Their local app is not reachable from the server anyway.
- One upload path: presigned PUT and the processing worker ([ADR-0011](0011-screenshot-storage-and-processing.md)).
- Nothing reaches pinround when the check finds no bug.
- It works with any MCP client, not only Claude Code.

## Considered options

1. **A client-side evidence log, filed at the end.** The client keeps the screenshots, pins and notes locally and
   uploads them only when the person says "file it".
2. **A server-side capture session.** The client opens a session in pinround at the start and uploads every
   screenshot as it goes.
3. **The server takes the screenshots.** Pinround runs a headless browser against the person's app.

## Decision outcome

Chosen option: **1, a client-side evidence log, filed at the end**, because nothing reaches pinround unless it is a
bug the person wants to report, and filing needs only the tools we already have plus one read tool.

- **Who captures.** The client captures: Claude Code with its own browser tool (for example Playwright), or the
  browser extension. Pinround only receives files and data.
- **The evidence log.** While it checks, the client keeps an ordered list. Each entry is a local image file, a title,
  a summary ("what this picture shows") and pins (x/y percent and a note). It uses the same caps as the server
  (title ≤ 120, summary ≤ 600, pin note ≤ 1,000 characters, FR-RND-16). A client that knows the element's box
  places the pin at its centre, so the client does not guess the spot from the picture.
- **The MCP prompt `report_while_investigating`.** Pinround's MCP server exposes this prompt, so every MCP client gets
  the same working method without installing anything. It tells the client to:
  - keep the evidence log;
  - capture the "before" state of each case it tries;
  - give each picture a title and summary;
  - never upload while it is still checking;
  - ask the person at the end.
  Prompt text is a versioned file under `src/AgentApi/Infrastructure/Prompt/`.
- **At the end, the person chooses the target.** The client calls the new read tool `find_tickets {project?, text}`.
  It uses the ADR-0022 search and the ticket visibility predicate, returns at most 10 open tickets, and needs scope
  `read`. The client then offers the choices:
  - "New report": `create_report` creates a report draft (at most 10 screenshots). The person opens it in pinround
    and clicks Send.
  - "Add to SHOP-42": `add_screenshot` adds each picture to the person's own draft round on that ticket. This is
    allowed when it is their turn ([ADR-0044](0044-whose-turn-it-is-may-keep-a-draft-round.md)). The draft is shared
    at hand-off.
  - "Wait, check more": the client goes on and keeps the log.
- **Order of upload.** `create_upload` → PUT → (`create_report` or `add_screenshot`) for each picture. Uploads run only
  after the person picks a target, so the 5-minute presigned URLs never expire during a long check.
- **The browser extension.** It extends P10.T7 and P10.T8 and uses the same two targets:
  - **Pin overlay:** "Pin this page" draws a layer over the live page. The person clicks to drop numbered pins and
    types a note for each. On "Capture", the extension takes the visible tab and stores each pin as x/y percent of
    that image. This is the FR-RND-02 model, so nothing new is stored.
  - **Session target:** the popup asks once: "New report" (`POST /api/v1/reports`, scope `reports:write`) or a
    ticket from "My tickets" (`POST /api/v1/tickets/{key}/draft/screenshots`, scope `rounds:write`). Each capture
    goes to that target until the person changes it.
  - **Report draft first:** for "New report", the first capture creates the report draft and later captures add to
    it, through `POST /api/v1/reports/{id}/screenshots` (new, same caps). At the end the extension opens the draft
    page, where the person clicks Send.
  - **Hosts:** the extension works on `http://localhost:*` and on the addresses the person adds in its options. The
    `activeTab` permission is enough for the capture and the overlay.
- **Local LLMs.** Any MCP client that speaks Streamable HTTP and can drive a browser can use the same tools and the
  same prompt. Pinround makes no promise about how well a given model follows it. The laptop AI of
  [ADR-0019](0019-browser-local-ai.md) is not part of this flow.
- **Safety.** Titles, summaries and pin notes are untrusted user text (FR-RND-16). The same rate limits and the same
  audit rows apply, without content.

### Consequences

- Good: the person who checked the problem files it in one step, with the pictures already explained.
- Good: no new storage, no new upload path, and no ticket until a person clicks Send.
- Good: a check that finds no bug leaves nothing in pinround.
- Bad: if the client crashes before filing, the evidence log lives only on that laptop. The prompt asks the client
  to keep it in a file, so a new session can pick it up.
- Bad: the quality of the screenshots and summaries depends on the client and its model.
- Neutral: the extension gains `reports:write` as an optional scope. It is still off by default on the token page.

## Confirmation

- MCP contract tests for `find_tickets`: it shows only visible tickets, puts another organization's tickets in no
  result, returns at most 10, and needs scope `read`.
- MCP test: `prompts/list` shows `report_while_investigating`, and `prompts/get` returns the versioned file's text.
- e2e (Playwright as the client): log three captures → `find_tickets` → `create_report` → the owner opens the draft
  and sees three titled pictures with their pins → Send → Round 0 has three pictures.
- e2e: the same with "Add to SHOP-42" as Mia in a test step. Her draft holds the pictures, and nobody else sees them.
- Extension tests: a pin dropped on the overlay lands at the same percent on the captured image. A capture on a host
  that is not allowed is refused. "New report" with a token without `reports:write` shows a plain message and creates
  nothing.

## Pros and cons of the options

### Option 1, client-side evidence log

- Good, because pinround stores nothing until the person decides.
- Good, because it needs only one new read tool and one prompt.
- Bad, because a crash can lose the log if the client did not keep it in a file.

### Option 2, server-side capture session

- Good, because nothing is lost on a crash.
- Bad, because every check leaves drafts and files behind, even when there is no bug, and they need cleanup.
- Bad, because uploads happen while the person has not decided anything.

### Option 3, the server takes the screenshots

- Good, because clients need no browser tool.
- Bad, because the server cannot reach a person's local app, and it must never connect to their laptop.

## More information

- Related: [ADR-0011](0011-screenshot-storage-and-processing.md), [ADR-0019](0019-browser-local-ai.md),
  [ADR-0020](0020-ai-safety-and-prompt-injection.md), [ADR-0021](0021-mcp-server.md),
  [ADR-0022](0022-search-and-duplicates.md), [ADR-0039](0039-agents-file-report-drafts-a-person-sends-them.md),
  [ADR-0040](0040-report-drafts-live-in-review.md), [ADR-0044](0044-whose-turn-it-is-may-keep-a-draft-round.md).
- Requirements: FR-MCP-12, FR-MCP-13, FR-MCP-14, FR-RND-15, FR-RND-16, FR-RND-18.
- Plan: [phase 13](../05-plan/phase-13-capture-explore-and-scenarios.md), P13.T3 to P13.T5.
- Verify at implementation: prompt support in `symfony/mcp-bundle` and `mcp/sdk`, and the Chrome MV3 APIs for the
  overlay (`activeTab`, `chrome.scripting`, `captureVisibleTab`).
- Revisit when: people report a lost evidence log more than twice in one month, or ask to continue a check on a
  second device.
