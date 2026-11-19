# Phase 13 — Capture while checking, notebooks and scenarios

Goal: whoever checks a problem (by hand, with the browser extension or with Claude Code) files it in one step with the screenshots and pins already made, testers add findings to the ticket they test, and anyone can pin a screen to ask about it and watch Claude Code's scenarios play live.

## Index
- [Before you start](#before-you-start) — ADRs to accept, earlier tasks this phase needs, docs to read
- [Outcome](#outcome) — what people can do when this phase is done
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — FR and NFR IDs
- [Design notes](#design-notes) — modules, scopes, caps, security
- [Tasks](#tasks) — P13.T1 to P13.T10, one session each
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — what later sessions must know

## Before you start
1. **The ADRs.** [ADR-0044](../03-adr/0044-whose-turn-it-is-may-keep-a-draft-round.md), [ADR-0045](../03-adr/0045-evidence-is-captured-where-the-person-works.md) and [ADR-0046](../03-adr/0046-notebooks-and-scenarios-for-understanding-an-app.md) were accepted on 2026-11-19.
2. **These earlier tasks must be done:** P05.T6 (Round 1 checklist), P05.T8 and P05.T9 (draft round and hand-off dialog) for T1 and T2; P03.T4 (search) for T3; P06.T1 (report drafts list and Send) and P09.T9 (`create_report`) for T2's "Make it a new ticket", T4 and T5; P09.T7 (REST `/api/v1`), P10.T7 and P10.T8 (browser extension) for T5 to T9; the P11 retention job for T10. Check their Definition of done. If something is missing, tell the user before you build on it.
3. **Prototype.** No prototype screen shows this phase (product owner's decision, 2026-11-19). Reuse the screens it builds on: open the prototype (https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2, Artifact tool, `path: "project/Main.dc.html"`) and look at the draft round, the "Share your draft round?" dialog, Report a problem and its "Capture with pinround" button. Fallback: [ticket-page.md › Rounds](../04-prototype/screens/ticket-page.md#rounds) and [dialogs-and-popovers.md › Share draft dialog](../04-prototype/screens/dialogs-and-popovers.md#share-draft-dialog). New copy follows [ux-principles.md](../00-product/ux-principles.md).
4. **Read the requirements:** FR-RND-02, FR-RND-04, FR-RND-09 to FR-RND-12, FR-RND-15 to FR-RND-18 in [RND](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds); FR-MCP-04, FR-MCP-05, FR-MCP-10, FR-MCP-12 to FR-MCP-14 in [MCP](../01-requirements/functional-requirements.md#mcp--mcp-server-and-agent-api); all of [EXP](../01-requirements/functional-requirements.md#exp--explore-notebooks-and-scenarios).
5. **Read the decisions:** the three ADRs above, plus [ADR-0017](../03-adr/0017-private-draft-rounds.md), [ADR-0020](../03-adr/0020-ai-safety-and-prompt-injection.md), [ADR-0021](../03-adr/0021-mcp-server.md), [ADR-0039](../03-adr/0039-agents-file-report-drafts-a-person-sends-them.md), [ADR-0040](../03-adr/0040-report-drafts-live-in-review.md); [modules.md › Review](../02-architecture/modules.md) and [› AgentApi](../02-architecture/modules.md#agentapi-api_), [security.md › MCP and personal access token threats](../02-architecture/security.md#mcp-and-personal-access-token-threats), [workflow-engine.md › Transition catalogue](../02-architecture/workflow-engine.md#transition-catalogue).

## Outcome
Omar sends SHOP-42 to testing. Mia tests it, finds the discount line cut off, and pins it on the live page with the extension, into her own draft round. She presses "Needs changes" and picks "Share and move". Omar sees "Round 3 · findings by Mia" with one checklist item per pin.

Raj asks his Claude Code whether a customer's complaint is a bug. Claude Code checks the app, keeps a screenshot and pins for each case it tries, and at the end asks: "New report, add to SHOP-51, or check more?" Raj says "New report". He opens the report draft in pinround, sees three pictures with titles and pins, and clicks Send.

Lena pins three spots of the order page into a notebook and asks Claude Code to explain them. Then she asks for every warning on the page. Claude Code saves six scenarios. In the extension she plays them one by one on her local app and watches each warning appear.

## Scope
- Draft rounds for the person whose turn it is; "findings by" rounds and their checklist items; "Make it a new ticket" ([ADR-0044](../03-adr/0044-whose-turn-it-is-may-keep-a-draft-round.md)).
- MCP tool `find_tickets` and MCP prompt `report_while_investigating`; a guide for reporting from Claude Code ([ADR-0045](../03-adr/0045-evidence-is-captured-where-the-person-works.md)).
- Extension: pin overlay on the live page, a session target (new report, my draft on a ticket, notebook), adding to an open report draft.
- Notebooks: `rvw_notebook`, the UI list, REST and MCP read tools ([ADR-0046](../03-adr/0046-notebooks-and-scenarios-for-understanding-an-app.md)).
- Scenarios: the format, `save_scenarios`, and the extension player.

## Out of scope
- Any token action that creates a ticket or sends a report draft (ADR-0039 stands).
- Sharing notebooks with other people.
- Step types beyond the ADR-0046 list (hover menus, drag and drop, file pickers).
- Preparing test data in the person's app: Claude Code does that, pinround does not.
- Other browsers than Chrome (P10.T7 builds Chrome MV3 only).

## Requirements covered
FR-RND-17, FR-RND-18, FR-MCP-13, FR-MCP-14, FR-EXP-01, FR-EXP-02, FR-EXP-03, FR-EXP-04, FR-EXP-05, FR-EXP-06; changes FR-RND-09 (who may start a draft) and FR-RND-11 (the dialog runs on the owner's move with any verb).
NFR-SEC-01, NFR-SEC-03, NFR-SEC-04, NFR-SEC-09, NFR-SEC-10, NFR-PERF-06.

## Design notes
- **Modules.** Review owns draft rules, findings rounds, report drafts and notebooks (`rvw_notebook`). AgentApi owns the MCP tools, the MCP prompt and the REST adapters. Ticketing gives `find_tickets` its search through its Api, with the ticket visibility predicate. No new module.
- **Scopes.** `find_tickets`, `get_notebook` and `list_my_notebooks` need `read`. `save_scenarios` and the extension's notebook capture need `notebooks:write` (new, off by default, plain text on the token page: "Let Claude Code and the extension write to your notebooks."). The extension's "New report" needs `reports:write` (off by default).
- **Caps (refuse past them with a plain message):** report draft 10 screenshots; notebook 10 screenshots and 20 scenarios; scenario 30 steps; title 120, summary 600, pin note 1,000, scenario `why` and `setup` 600, step `say` 200, step text value 1,000 characters; `find_tickets` at most 10 results and `text` at most 200 characters.
- **Security.** Everything people or AI typed is untrusted text: delimiters when returned by MCP, escaped on every page. The scenario player interprets data only (no `eval`, no `new Function`, no script from AI text). It runs only on allowed hosts, never on the pinround host, asks before the first run on each host, and refuses `type` into a password field. Audit every tool and REST call without content.
- **Performance.** Notebook and report-draft pages read one row. `find_tickets` ≤ 300 ms p95 like every tool.

## Tasks

### P13.T1 — Draft round for the person whose turn it is
- **Why:** a tester or reviewer collects findings privately on the ticket they check ([ADR-0044](../03-adr/0044-whose-turn-it-is-may-keep-a-draft-round.md), FR-RND-17).
- **Steps:**
  1. `DraftRoundCreationVoter` lets a person start a draft when they are the assignee or when the turn read model says it is their turn; `RoundVoter` keeps an existing draft editable by its owner after the turn passes on.
  2. The hand-off dialog runs on the owner's move with any verb, including "Needs changes" and "Ask for changes"; add the side effect to those rows of the transition catalogue in [workflow-engine.md](../02-architecture/workflow-engine.md#transition-catalogue).
  3. Update FR-RND-09's wording, the [permission matrix](../00-product/personas-and-roles.md#permission-matrix) and the voter matrix test rows.
- **Files:** `src/Review/UI/Voter/DraftRoundCreationVoter.php`, `src/Review/UI/Voter/RoundVoter.php`, the move path that asks the draft question, their tests under `tests/Unit/Review/UI/Voter/`, the voter matrix test.
- **Acceptance:**
  - [ ] Mia may start a draft in Testing on a ticket she can see; she is refused when it is not her turn, with the reason, and nothing is created.
  - [ ] Lena may start one in Code review; Sara (admin, not on the team) is refused.
  - [ ] Omar, Lena and Sara cannot see Mia's unshared draft (HTML, queries, direct URLs; added to the visibility leak suite).
  - [ ] "Needs changes" with Mia's draft holding screenshots asks the dialog once; Cancel moves and shares nothing.
- **Tests:** voter unit tests; functional tests for the dialog on the new verbs; isolation test.
- **Prototype check:** the existing draft round card and dialog texts; the card for a tester uses the same layout.
- **Size:** M

### P13.T2 — Findings rounds: label, checklist items and "Make it a new ticket"
- **Why:** the developer ticks off each new finding, and the tester can split off an unrelated problem.
- **Steps:**
  1. A shared draft of someone other than the assignee reads "Round N · findings by Mia".
  2. On share, each pin of a findings round becomes an `open` `rvw_check_item` in the same transaction; a self-check round makes none.
  3. "Make it a new ticket" on a draft moves its screenshots, pins, titles and summaries into a new report draft of the same owner, with detail "Found while testing SHOP-42", and closes the round draft; over 10 screenshots it is refused.
- **Files:** `src/Review/Application/*` (share, split), `src/Review/UI/RoundCards.php`, the draft card template.
- **Acceptance:**
  - [ ] Sharing Mia's draft with two pins shows "Round N · findings by Mia" and two `open` checklist items; Omar may mark them fixed and only Mia may mark them OK or Not fixed.
  - [ ] "Make it a new ticket" creates a report draft with the same pictures and pins in the same order, closes the round draft, and creates no ticket until Send (count rows before and after).
  - [ ] A draft with 11 screenshots is refused with "A report holds at most 10 screenshots." and nothing changes.
- **Tests:** functional per action; integration test for the checklist creation; e2e for the split.
- **Prototype check:** none for the new label; follow the "self-check by" card.
- **Size:** M

### P13.T3 — `find_tickets` tool
- **Why:** at the end of a check, the client offers "add to SHOP-42" only for tickets the person may see ([FR-MCP-13](../01-requirements/functional-requirements.md#mcp--mcp-server-and-agent-api)).
- **Steps:**
  1. `find_tickets {project?, text}`, scope `read`; uses the ADR-0022 search through Ticketing's Api with the ticket visibility predicate; open tickets only; at most 10; each result has key, title, step, whose turn, and whether the caller may start a draft on it now.
  2. Text fields come back inside delimiters.
- **Files:** `src/AgentApi/Infrastructure/Mcp/Tool/FindTickets.php`, tests beside the other tool tests.
- **Acceptance:**
  - [ ] Only tickets the caller may see come back; another organization's ticket and a team-only ticket of another team never do (visibility leak suite case).
  - [ ] `text` over 200 characters is refused; a result list never holds more than 10.
  - [ ] `may_start_draft` is true only where P13.T1's voter grants it.
- **Tests:** MCP contract tests; leak suite case; query count.
- **Prototype check:** none.
- **Size:** S

### P13.T4 — MCP prompt `report_while_investigating` and the reporting guide
- **Why:** every MCP client keeps an evidence log while it checks and files it in one step at the end ([FR-MCP-14](../01-requirements/functional-requirements.md#mcp--mcp-server-and-agent-api)).
- **Steps:**
  1. Verify prompt support in `symfony/mcp-bundle` and `mcp/sdk`; write the finding in the hand-off notes.
  2. Add the prompt as a versioned file under `src/AgentApi/Infrastructure/Prompt/`. It says: keep an ordered evidence log in a local file; per case capture the before picture, a title, a summary and pins at the element's centre; do not upload while checking; at the end call `find_tickets` and offer "New report", "Add to <key>" (only where `may_start_draft`) and "Check more"; then `create_upload` → PUT → `create_report` or `add_screenshot`.
  3. Write `docs/guides/reporting-with-claude-code.md`: token scopes, example prompts, what happens at the end, how to resume a log, using a local model client.
- **Files:** `src/AgentApi/Infrastructure/Prompt/report_while_investigating.v1.md`, the prompt registration, `docs/guides/reporting-with-claude-code.md`.
- **Acceptance:**
  - [ ] `prompts/list` shows the prompt; `prompts/get` returns the file's text.
  - [ ] Following only the guide, a person who did not build it files a report draft with three pictures and their pins from one Claude Code check, with no second capture; recorded in the guide.
- **Tests:** MCP test for the prompt; e2e with Playwright as the client: three captures → `create_report` → the owner sees them → Send → Round 0 holds three pictures.
- **Prototype check:** none.
- **Size:** M

### P13.T5 — Extension: pin overlay and session target
- **Why:** a person pins problems on the live page and files them to one target ([ADR-0045](../03-adr/0045-evidence-is-captured-where-the-person-works.md), FR-RND-18).
- **Steps:**
  1. "Pin this page" draws an overlay; click or keyboard drops numbered pins with notes; "Capture" takes the visible tab and stores pins as x/y percent of the image.
  2. The popup asks once for the target: "New report", a ticket from `GET /api/v1/me/tickets` where the person may start a draft, or a notebook (after P13.T6). Each capture goes there until changed.
  3. "New report": the first capture calls `POST /api/v1/reports`; later ones call the new `POST /api/v1/reports/{id}/screenshots` (owner only, 10-screenshot cap, idempotency key). "Done" opens the draft page in pinround.
  4. Works on `http://localhost:*` and the hosts in options; `activeTab` and `chrome.scripting` only.
- **Files:** `extension/src/overlay/*`, `extension/src/capture/*`, `src/AgentApi/UI/Rest/*`, `src/Review/Api/ReportDrafts.php` (add a screenshot), `docs/api/v1.yaml`, `docs/guides/browser-extension.md`.
- **Acceptance:**
  - [ ] A pin dropped on the overlay lands at the same percent (± 0.5) on the captured image, also after a window resize.
  - [ ] Two captures on two pages end in one report draft with both pictures in order.
  - [ ] "New report" with a token without `reports:write` shows a plain message and creates nothing.
  - [ ] `POST /api/v1/reports/{id}/screenshots` on another person's draft answers 404 and changes nothing; the 11th picture is refused.
  - [ ] The overlay is keyboard usable and each pin has the accessible name "Pin 2: <note>".
- **Tests:** extension unit tests; functional tests for the new endpoint; extension e2e against a test page.
- **Prototype check:** pin look follows FR-RND-02 (see-through ring).
- **Size:** L

### P13.T6 — Notebooks: table, page and read access
- **Why:** pins for a question need a private home that is not a ticket ([ADR-0046](../03-adr/0046-notebooks-and-scenarios-for-understanding-an-app.md), FR-EXP-01 to FR-EXP-03).
- **Steps:**
  1. Migration for `rvw_notebook` (tenant table, RLS, `organization_id` first in the key and every index, grants per role); add it to [data-model.md](../02-architecture/data-model.md#review-and-media) and the catalogue test.
  2. `Review\Api\Notebooks`: create, add screenshot (upload target kind `notebook`), rename, delete, `forOwner`, `get`.
  3. "My notebooks" page (list, open, rename, delete) in Review's UI; owner only.
  4. REST `POST /api/v1/notebooks`, `POST /api/v1/notebooks/{id}/screenshots` (scope `notebooks:write`); MCP `list_my_notebooks` and `get_notebook` (scope `read`); add the scope to the token page.
- **Files:** `migrations/*`, `src/Review/{Domain,Application,Infrastructure,UI,Api}/*Notebook*`, `src/AgentApi/Infrastructure/Mcp/Tool/{ListMyNotebooks,GetNotebook}.php`, `src/AgentApi/UI/Rest/*`.
- **Acceptance:**
  - [ ] Lena's notebook is not found for Omar, Sara and another organization (HTML, queries, direct URL, REST, MCP).
  - [ ] An 11th screenshot is refused with a plain message and nothing changes.
  - [ ] `get_notebook` returns titles, summaries and pin notes inside delimiters, unchanged.
- **Tests:** isolation and catalogue tests; voter tests; MCP and REST contract tests; functional tests for the page.
- **Prototype check:** none; list and card follow the report drafts list.
- **Size:** L

### P13.T7 — Scenario format and `save_scenarios`
- **Why:** Claude Code saves checked, data-only scenarios that the extension can play (FR-EXP-04, FR-EXP-05).
- **Steps:**
  1. A JSON Schema for a scenario, kept in `docs/api/scenario.v1.json` and used by both the server and the extension.
  2. `save_scenarios {notebook, scenarios}` (scope `notebooks:write`) validates and replaces the notebook's scenarios; `get_notebook` returns them.
  3. Add the scenario format and an example to the reporting guide.
- **Files:** `docs/api/scenario.v1.json`, `src/Review/Domain/Scenario*`, `src/AgentApi/Infrastructure/Mcp/Tool/SaveScenarios.php`.
- **Acceptance:**
  - [ ] An unknown step type, a CSS or XPath target, 31 steps, 21 scenarios or an over-cap text are refused with a plain error and nothing is saved.
  - [ ] A `go_to` URL whose host differs from `start_url`'s host is refused.
  - [ ] Without `notebooks:write` the tool errors and nothing changes; another person's notebook is not found.
- **Tests:** unit tests per rule (each with a valid twin that passes); MCP contract tests.
- **Prototype check:** none.
- **Size:** M

### P13.T8 — Extension: scenario player
- **Why:** a person watches each case happen live and steps through them (FR-EXP-06).
- **Steps:**
  1. "Scenarios" lists the chosen notebook's scenarios; "Play", "Step", "Next", "Back"; each step is highlighted with its `say` line.
  2. One handler per step type; locate by role and name, text or label; a failed `expect_*` stops and shows expected and found; `capture` adds a screenshot to the notebook.
  3. Allowed hosts only, never the pinround host; a one-time question per host; `type` into a password field refused.
  4. CI step: a search of `extension/` for `eval(` and `new Function` fails the build if found.
- **Files:** `extension/src/player/*`, `.github/workflows/*` (the search step).
- **Acceptance:**
  - [ ] A three-scenario fixture plays on a test page; "Next" and "Back" move between scenarios.
  - [ ] A failed `expect_text` stops the run and shows both texts.
  - [ ] A host that is not allowed, the pinround host and a password field are each refused, and no step runs.
  - [ ] The player is keyboard usable and announces each `say` line to screen readers.
- **Tests:** extension unit tests per handler and per refusal; extension e2e; the CI search.
- **Prototype check:** none.
- **Size:** L

### P13.T9 — Explain and scenarios: guide and prompt
- **Why:** people need to know how to ask Claude Code about a notebook and get playable scenarios.
- **Steps:**
  1. Add an MCP prompt `explain_my_notebook` (versioned file): read the notebook, answer per pin, and when asked for cases, write scenarios with `setup` notes and prepare their data in the person's app first.
  2. Add a section to `docs/guides/browser-extension.md` and the reporting guide: notebooks, asking, scenarios, safety.
- **Files:** `src/AgentApi/Infrastructure/Prompt/explain_my_notebook.v1.md`, the two guides.
- **Acceptance:**
  - [ ] Following only the guide, a person who did not build it pins a page, gets an answer, and plays at least two scenarios Claude Code saved; recorded in the guide.
- **Tests:** MCP test for the prompt.
- **Prototype check:** none.
- **Size:** S

### P13.T10 — Retention for notebooks and old report drafts
- **Why:** private objects must not pile up (ADR-0046 consequences, ADR-0039 retention).
- **Steps:**
  1. Notebooks unused for 90 days and report drafts unsent for 30 days are deleted with their files, by the P11 retention job; the owner is told 7 days before.
  2. The admin data page shows both rules (read only until P11 adds editing).
- **Files:** the retention job of P11, `src/Review/Application/*Retention*`.
- **Acceptance:**
  - [ ] With the clock frozen at 2026-03-02, a notebook last used on 2025-12-01 is deleted with its files; one used on 2025-12-03 stays.
- **Tests:** integration test with a frozen clock and rows on both sides of the limit.
- **Prototype check:** Admin › Data rules texts, when present.
- **Size:** S

## Definition of done
- [ ] All P13 tasks ticked; the three journeys of the [outcome](#outcome) pass in Playwright (tester findings, report from a Claude Code check, notebook with scenarios).
- [ ] Privacy proven for tester drafts, report drafts and notebooks by voter, isolation and direct-URL tests.
- [ ] No token path creates a ticket (count test per new tool and endpoint).
- [ ] The extension source has no `eval(` or `new Function`, enforced in CI.
- [ ] Quality gates green; FR, data-model, modules, security and guide docs and their Index updated.

## Hand-off notes
- 2026-11-19 (plan change): phase added by the product owner's decision, from [ADR-0044](../03-adr/0044-whose-turn-it-is-may-keep-a-draft-round.md), [ADR-0045](../03-adr/0045-evidence-is-captured-where-the-person-works.md) and [ADR-0046](../03-adr/0046-notebooks-and-scenarios-for-understanding-an-app.md), accepted on that date. P10.T7's rule "the extension's token has scope `rounds:write` only" changes when ADR-0045 is accepted: `reports:write` and `notebooks:write` become optional scopes for the extension.
