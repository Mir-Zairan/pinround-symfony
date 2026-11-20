# Phase 14 — Tester feedback: rounds, reporting, pages and the extension

Goal: fix what the first hands-on test of the whole product found, so that rounds, reports, Claude Code and the extension work the way the prototype and the product owner expect.

## Index
- [Before you start](#before-you-start) — where this phase comes from, ADRs to accept, docs and prototype screens to read
- [Outcome](#outcome) — what people can do when this phase is done
- [Scope](#scope) — what this phase builds or fixes
- [Out of scope](#out-of-scope) — what stays in later phases
- [Decisions already made](#decisions-already-made) — the product owner's answers of 2026-11-20
- [Requirements covered](#requirements-covered) — FR IDs changed or added
- [Design notes](#design-notes) — modules, security, performance
- [Tasks](#tasks) — P14.T1 to P14.T20, one session each, in build order
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — what later sessions must know

## Before you start
1. **Where this phase comes from.** The product owner tested pinround by hand on 2026-11-20 with the demo shop, the extension and Claude Code, and compared it with the prototype. A UI check of every page at desktop and phone width followed. Each task below names the problem it fixes.
2. **ADRs.** P14.T4 writes three ADRs: rounds keep tries and one open round per ticket (0048), the demo two-step login switch (0049), and one-click connect with OAuth (0050). They must be **accepted** before the tasks that depend on them start (T5, T6, T18, T20). T1–T3 and T7–T17 do not wait for them, but T7 and T8 read better after T5.
3. **Earlier work this phase relies on:** P05.T4, T7, T8, T9 and T10 (rounds, review round page, draft rounds, hand-off dialog, full screen); P06.T1 (report drafts); P09 (MCP tools and prompts); P10.T7 and T8 (extension); P13.T5 and T8 (overlay and scenario player). Check their Definition of done. If something is missing, tell the user before you build on it.
4. **Prototype.** Open https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2 with the Artifact tool. Each task names its screens: `project/Main.dc.html` (ticket page › Rounds, Tickets, Whose turn?, Home, My reports, Admin, Report a problem), `project/Review.dc.html` (round page), `project/Focus.dc.html` (full screen). The floating dock has its own prototype: https://claude.ai/artifact/283f5waQuikU5QejVccamZ (version 2, accepted by the product owner on 2026-11-20). Fallback: [prototype-guide.md](../04-prototype/prototype-guide.md). Never copy names, keys or text from the prototypes.
5. **Read:** [ADR-0017](../03-adr/0017-private-draft-rounds.md), [ADR-0021](../03-adr/0021-mcp-server.md), [ADR-0012](../03-adr/0012-authentication.md), [ADR-0044](../03-adr/0044-whose-turn-it-is-may-keep-a-draft-round.md), [ADR-0045](../03-adr/0045-evidence-is-captured-where-the-person-works.md), [ADR-0046](../03-adr/0046-notebooks-and-scenarios-for-understanding-an-app.md); [RND](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds), [MCP](../01-requirements/functional-requirements.md#mcp--mcp-server-and-agent-api), [MINE](../01-requirements/functional-requirements.md#mine--my-reports), [HOME](../01-requirements/functional-requirements.md#home--home-page); the guides [claude-code.md](../guides/claude-code.md), [reporting-with-claude-code.md](../guides/reporting-with-claude-code.md) and [browser-extension.md](../guides/browser-extension.md).

## Outcome
Mia reports a cart problem from the extension. She drags the pinround dock to the side of the shop page, presses P, pins the wrong total, removes a pin she put in the wrong place, presses C, and goes on testing. Each screenshot already has a title and a line about the page. At the end she saves one draft, opens it in pinround, sees one screenshot at a time, picks "The team (Lena decides)" and presses Send. The ticket shows Round 0 with her pins exactly where she put them.

Omar asks his Claude Code to fix the pins. Claude Code looks at each pinned screenshot with its note, fixes the code, and saves a retake of each screen as a file with its context. When Omar asks, it uploads them: each becomes "try 2" of the same screenshot in his private draft. He flips between try 1 and try 2, then sends the ticket to code review and shares the draft as Round 2. Round 2 stays open until Lena gives her verdict; until then nobody can share another round.

Lena opens SHOP-42. A bar at the top says "Round 2 is open · Ready for Lena · Open round". The round page shows one screenshot at a time, Before and After, and a Full screen button.

## Scope
- Bugs: pins at the top-left, broken upload preview, images over plain HTTP, dead menu items and links, a Home that says "All clear" while work waits, an error page on `/admin/people/invite`.
- Rounds: tries inside a draft, one open shared round per ticket, no empty shares, short round rows on the ticket, a round bar, a round page for every round kind, full screen everywhere.
- Reporting: recipient choice on Send, one screenshot at a time while reporting, screenshot context filled in by the extension and required from Claude Code.
- Claude Code: capture-as-you-go guidance for every session, and a prompt for fixing round notes.
- Pages: "Whose turn?" toolbar, My reports page, a simple honest Home, phone layout, a polish pass, an admin area with tabs.
- Demo: a switch that skips two-step login on a local demo.
- Extension: the floating dock, and one-click connect with OAuth for the extension, Claude Code and a Claude connector.

## Out of scope
- The needs-you score and grouped Home of P07.T1–T5 (T14 shows a plain list until then).
- My reports actions (P07.T7) and the admin pages P11.T3–T9.
- AI suggestion pins and the AI check per pin (FR-RND-06, FR-RND-07).
- Other browsers than Chrome for the extension.

## Decisions already made
The product owner answered on 2026-11-20:
- **Tries.** All back-and-forth with Claude Code happens inside one private draft. A retake becomes the next try of the same screenshot; it neither overwrites it nor starts a round. A round number counts hand-offs, not tries. On share, the team sees the latest try of each screenshot, and "before" is the earlier round's "after".
- **One open round.** A shared round stays open until the person it is ready for gives a verdict. Until then nobody can share another round on that ticket. Anyone may keep a private draft meanwhile. This applies to every role whose turn it is (developer, reviewer, tester), not only developers.
- **Opening a ticket.** The ticket page opens as before, with a bar at the top for the open round and an "Open round" button. It does not jump to the round.
- **My reports.** Build the page now (P07.T6 moves here).
- **Dock.** Build it as in the dock prototype, version 2: pinround logo, visible Remove on a pin, Delete key, "Clear N pins".
- **Screenshot context.** Context is captured at the moment of capture, never pieced together later. Claude Code saves the screenshot as a file with title, summary and pins while it works, and uploads only when the person asks. When it reads round notes it looks at each pinned screenshot together with its note.
- **Demo two-step login.** Off by default, so people see that two-step login exists. The setup command offers to turn the switch on, and `.env` explains it.

## Requirements covered
Changes FR-RND-05 (a round page for every round kind), FR-RND-08 (full screen everywhere), FR-RND-12 (share rules), FR-RND-14 (round rows and round bar), FR-RND-16 (context filled in), FR-MCP-05 and FR-MCP-12 (title and summary required), FR-INT-01 (recipient on Send of a report draft), FR-HOME-01 (plain list until P07), FR-MINE-01.
Adds FR-RND-19 (tries), FR-RND-20 (one open round per ticket), FR-MCP-15 (server instructions), FR-MCP-16 (prompt `fix_my_round_notes`) and the dock and connect requirements in the extension section; each task that builds one writes its text.
NFR-SEC-01, NFR-SEC-03, NFR-SEC-04, NFR-SEC-09, NFR-SEC-10, NFR-PERF-06.

## Design notes
- **Modules.** Review owns rounds, tries, report drafts and the round page. AgentApi owns MCP instructions, prompts and tool contracts. Identity owns OAuth and the demo switch. Web owns the menu, shell and phone header. Organization owns the admin area. No new module.
- **CSP stays strict.** No `unsafe-inline`. Anything rendered by a separate request (Turbo frame, live component) places pins by script from `data-x-percent`/`data-y-percent`, never by a nonce'd `<style>`. `img-src` gains `blob:` only.
- **Races.** "One open round" and "share" lock the ticket's round rows (`SELECT … FOR UPDATE`) or use a conditional write, so two shares at once cannot both pass.
- **Caps.** A screenshot keeps at most 5 tries; refuse past it with a plain message. Existing caps (50 screenshots per round, 10 per report draft, title 120, summary 600, note 1,000) stay.
- **Untrusted text.** Page titles and addresses the extension reads are untrusted: trimmed to the caps, query string and fragment dropped from addresses, escaped on every page, delimited when returned by MCP.
- **Performance.** The round page reads one round and its screenshots in a fixed number of queries; tries load the latest per screenshot, older tries on demand. Keyset paging on My reports.

## Tasks

### P14.T1 — Pins land where they were put
- **Why:** on the ticket page every pin sits at the top-left corner. The Rounds section loads through a lazy Turbo frame; its `<style nonce>` carries a new nonce that does not match the page's CSP header, so the browser drops the rule (`PinLayer.html.twig:4-6`). Checked live on SHOP-42: computed `left/top` = `0px/0px` and a `style-src` violation in the console.
- **Steps:**
  1. A Stimulus controller places every pin of a layer from its `data-x-percent`/`data-y-percent` on connect, with `placeMarker()` from `assets/review/pin_markup.js`.
  2. Keep the server-printed `<style>` for full page loads, or drop it if the controller covers every case; decide by test.
  3. Cover `Screenshot.html.twig:19` and `DraftRoundEditor` re-renders with the same controller.
- **Files:** `src/Review/UI/templates/components/PinLayer.html.twig`, `Screenshot.html.twig`, `assets/review/pin_markup.js`, a new controller under `assets/controllers/`.
- **Acceptance:**
  - [ ] On the ticket page, Round 0's pins sit at their stored percent (± 0.5) after the frame loads; the console has no CSP violation (Playwright).
  - [ ] The same holds after a live component re-render of a draft round.
- **Tests:** Playwright on a seeded ticket; a JS unit test for the controller.
- **Prototype check:** `Main › Ticket › Rounds`, Round 0 card.
- **Size:** S

### P14.T2 — Upload preview and images over HTTPS
- **Why:** on Report a problem the chosen screenshot shows as a broken image: the preview is a `blob:` URL and `img-src` does not allow `blob:` (checked live). Screenshots on the ticket page also load from `http://localhost:9000` on an HTTPS page (mixed content).
- **Steps:**
  1. Add `blob:` to `img-src` in `SecurityHeadersSubscriber` and to its test.
  2. Serve object storage to the browser over HTTPS on the dev stack (Caddy route or `OBJECT_STORAGE_PUBLIC_URL` on https); document it in [config.md](../operations/config.md).
- **Acceptance:**
  - [ ] Choosing a PNG on Report a problem shows its preview; no CSP error (Playwright).
  - [ ] No mixed-content warning on the ticket page.
- **Tests:** `SecurityHeadersSubscriberTest`; Playwright upload preview.
- **Size:** S

### P14.T3 — Menu, admin area and links that work
- **Why:** "Admin" and "My reports" are inert because `MainMenu` points at routes that do not exist (`web_admin`, `my_reports`); Home's "Open settings" and Sara's "Open admin settings" are disabled; the admin pages exist only by typed URL; `GET /admin/people/invite` shows a 405 error page; the People page lists no people.
- **Steps:**
  1. Build the admin shell of P11.T1 in its smallest form: `/admin` redirects to the first tab; a tab rail with People and AI, the tabs that exist today. Name the route `web_admin` or change `MainMenu` to the real name.
  2. Point Home's admin links at it.
  3. `GET /admin/people/invite` redirects to `/admin/people`.
  4. The People tab lists the organization's people with their teams and roles (read only; editing stays in P11.T2).
- **Acceptance:**
  - [ ] Sara reaches every admin tab from the menu and from Home; a person without the Admin role gets 404 at `/admin` and sees no menu item.
  - [ ] `GET /admin/people/invite` answers 302 to `/admin/people`.
  - [ ] People lists everyone in the organization, ordered by name, with keyset paging; another organization's people never show (isolation test).
- **Tests:** functional tests for menu, redirects and voter; isolation test; Playwright for Sara's path.
- **Prototype check:** `Main › Admin` (tab rail, People and roles).
- **Size:** M

### P14.T4 — Write ADRs 0048, 0049 and 0050
- **Why:** three changes reverse or extend accepted decisions, and CLAUDE.md asks for a new ADR each time.
- **Steps:**
  1. **ADR-0048 — Tries in a draft and one open round per ticket.** Extends ADR-0017: a retake is a try of the same screenshot (`retake_of`), at most 5 tries; share copies the latest try; a shared review round is open until its verdict, and a findings round (ADR-0044) until the assignee shares the next round; one open shared round per ticket; no share of a round without screenshots.
  2. **ADR-0049 — Demo switch for two-step login.** Supersedes the "no exception" part of ADR-0012: `PINROUND_DEMO_SKIP_2FA`, off by default, read only in `dev`; the kernel refuses to boot with it set in any other environment.
  3. **ADR-0050 — One-click connect with OAuth.** Supersedes "no OAuth flow for MCP in v1" in ADR-0021: OAuth 2.1 with PKCE, a consent page, short-lived access tokens with refresh, the same scopes as personal tokens; used by the extension (`chrome.identity.launchWebAuthFlow`), Claude Code and a Claude custom connector. Pasted tokens keep working.
  4. Add them to the [ADR index](../03-adr/README.md) as Proposed and ask the product owner to accept them.
- **Acceptance:**
  - [ ] Three ADRs follow [template.md](../03-adr/template.md), each with options and consequences; the index lists them.
  - [ ] The product owner has accepted each one (date in the ADR).
- **Size:** S

### P14.T5 — Round rules: one open round, no empty share
- **Why:** on SHOP-42 Omar shared Rounds 2, 3 and 4 back to back, all empty, while Round 1 was still being checked. `Round::share` checks neither.
- **Steps:**
  1. A shared review round is open until the person it is ready for sets a verdict; a findings round is open until the assignee shares the next round. Then it closes (`RoundState::Closed`).
  2. A tester's "Needs changes" with a draft sets her verdict on the open round and shares her draft as the findings round in one move: close first, then number, in one transaction.
  3. `share` refuses while another shared round on the ticket is open ("Round 2 is still open. Lena gives a verdict first.") and refuses a round with no screenshots ("Add a screenshot before you share."). Lock the ticket's round rows or make the write conditional and check the row count.
  4. The hand-off dialog offers only "Keep private and move" when sharing is refused, with the reason.
  5. MCP and REST answer the same refusal as a 4xx with the reason.
- **Acceptance:**
  - [ ] Sharing while a round is open is refused with the reason, and the draft stays unshared and unnumbered (assert state, not only status).
  - [ ] Sharing an empty draft is refused the same way.
  - [ ] Two shares at the same moment: exactly one wins (concurrency test).
  - [ ] A verdict closes the round, and the next share gets the next number.
  - [ ] Mia's "Needs changes" with findings closes Round 2 with her verdict and shares Round 3 · findings by Mia in the same move; Omar's next share closes Round 3.
- **Tests:** domain unit tests; repository integration test with two connections; functional and MCP tests for the refusal.
- **Size:** M

### P14.T6 — Tries of a screenshot
- **Why:** today a retake is a new screenshot added to the draft, so old and new pile up. The product owner wants a retake to be the next try of the same screenshot (ADR-0048).
- **Steps:**
  1. `rvw_screenshot.retake_of` (nullable, same round, same organization); index leads with `organization_id`; RLS stays on.
  2. A draft round shows the latest try of each screenshot with "Try 2 of 2" and a switch to earlier tries; pins stay on the try they were put on; a pin on an earlier try can be marked "Fixed on try N" or "Still wrong".
  3. Share copies only the latest try of each screenshot into the shared round; earlier tries stay with the owner and are deleted with the draft.
  4. `add_screenshot` and `POST /api/v1/…/screenshots` take an optional `retake_of`; the extension's "My draft round" target offers "Retake of screenshot N".
  5. Cap: 5 tries per screenshot.
- **Acceptance:**
  - [ ] A retake with `retake_of` shows as try 2 of the same screenshot; the try-1 pins stay on try 1.
  - [ ] After share, the shared round has one screenshot per chain, the latest try; its "before" is the earlier round's "after".
  - [ ] A sixth try is refused with the cap message, and nothing is stored.
  - [ ] `retake_of` pointing at another round, another person's draft or another organization answers 404.
- **Tests:** domain, migration, repository and isolation tests; MCP contract test; Playwright for the switch.
- **Prototype check:** `Main › Ticket › draft round`; `Review` (Before / After).
- **Size:** L

### P14.T7 — Rounds on the ticket page: short rows and the round bar
- **Why:** every round is a full card on the ticket page, so the page gets long. In the prototype only Round 0 shows its screenshot; later rounds are one line with "Open round".
- **Steps:**
  1. Round 0 keeps its card with the screenshot and pins; Round 1 keeps its checklist card.
  2. Every later round is one row: "Round N · <kind> by <name> · <state> · M screenshots" and an "Open round" button. Private drafts show only to their owner.
  3. A round bar sits at the top of the ticket page while a shared round is open: "Round N is open · Ready for <name> · Open round".
  4. On phone, "Your next step" sits above the Rounds section.
- **Acceptance:**
  - [ ] SHOP-42 with five rounds is under 1,400 px tall at 1440×900 (Playwright).
  - [ ] The round bar shows only while a round is open, and links to it.
  - [ ] Omar's private draft row is not visible to Lena, Mia or Sara (leak suite).
- **Prototype check:** `Main › Ticket › Rounds`.
- **Size:** M

### P14.T8 — One round page for every round, with full screen
- **Why:** the round page with tabs, Before/After and Full screen exists, but it shows only for seeded review rounds with "screen areas"; a shared draft opens to "This round has no screen areas." Round 0 has no full screen at all.
- **Steps:**
  1. The round page works for every round kind: one screenshot at a time, back and next (arrow keys too), a strip of small thumbnails with pin counts, the pin list for the shown screenshot, Before / After / Side by side when there is an earlier round, and the verdict box for the person it is ready for.
  2. Full screen (`Focus`) for every round and for Round 0 on the ticket page.
  3. The header's "Ready for" agrees with the ticket's turn.
  4. The breadcrumb link uses the app's link style.
- **Acceptance:**
  - [ ] A shared draft with 3 screenshots shows screenshot 1 of 3; next and the arrow key show 2; the thumbnail strip jumps (Playwright).
  - [ ] Full screen opens from the round page and from Round 0, and Escape closes it.
  - [ ] The page reads the round in a fixed number of queries, whatever the screenshot count (query-count test).
- **Prototype check:** `Review` and `Focus`.
- **Size:** L

### P14.T9 — Choose who gets a report on Send
- **Why:** Send on a report draft puts the ticket in Backlog with no assignee; the reporter cannot choose. The Report a problem form and the prototype offer "The team (lead decides)" or a named person.
- **Steps:**
  1. The report draft page gets the same "Give it to" choice as the report form (`ReportController::218-243`, `ReportTeamPeople`), default "The team (<lead> decides)".
  2. The chosen person goes through the same check rules as the form.
  3. Update phase-06's note "no assignee" and FR-INT-01.
- **Acceptance:**
  - [ ] Send with a named developer makes the ticket with that assignee; with "The team" it has none and the lead plans it.
  - [ ] Picking a person outside the project's team is refused with the reason, and no ticket is made.
- **Size:** S

### P14.T10 — One screenshot at a time while reporting
- **Why:** Report a problem and the report draft page stack every screenshot with its own form, one below the other; the draft page borrows login and security styles. "Picture title" and "Picture summary" show before any picture exists.
- **Steps:**
  1. A screenshot editor shared by both pages: the shown screenshot large with its pins and notes, title and summary beside it, a thumbnail strip to switch, "Add screenshot", and remove per screenshot.
  2. Title and summary fields appear only once a picture is there.
  3. "More detail" starts at 5 lines.
  4. Use the design-system components, not login or security classes.
- **Acceptance:**
  - [ ] With 4 screenshots, one shows at a time; switching keeps every pin and note (Playwright).
  - [ ] The form posts the same data as before (functional test unchanged or extended).
- **Prototype check:** `Main › Report a problem`.
- **Size:** M

### P14.T11 — Screenshot context filled in
- **Why:** captures from the extension arrive with no title or summary. Both fields start empty, and the extension does not read the page title or address. Claude Code skips them unless it runs the `report_while_investigating` prompt.
- **Steps:**
  1. The extension fills the title with the page title and the summary with "Page: <address without query or fragment> · Window <w>×<h> · <browser and version>", trimmed to the caps; the person may edit both.
  2. In the MCP tools `create_report` and `add_screenshot`, and in `add_notebook_screenshot`, `title` and `summary` become required; a missing one is refused with a plain reason. The web form and the extension stay optional.
  3. Update [claude-code.md](../guides/claude-code.md) and [reporting-with-claude-code.md](../guides/reporting-with-claude-code.md).
- **Acceptance:**
  - [ ] An extension capture of a page whose address has `?token=…` stores the address without the query (extension test).
  - [ ] An MCP call without a summary is refused with the reason, and nothing is stored.
  - [ ] Test with a page title that has CRLF, a BOM and multibyte text.
- **Size:** M

### P14.T12 — Claude Code captures as it goes, and fixes round notes
- **Why:** the capture-as-you-go rule lives only in the `report_while_investigating` prompt, so a session that did not start with it took its screenshots at the end. Fixing pins has no prompt at all.
- **Steps:**
  1. Send MCP server `instructions` at connect: keep an evidence log of files with title, summary and pins at the moment of capture; upload only when the person asks. Check that `symfony/mcp-bundle` supports `instructions`; if not, put the rule in every tool description that uploads.
  2. New prompt `fix_my_round_notes` (versioned file under `src/AgentApi/Infrastructure/Prompt/`): read `get_round_notes`; open each pinned screenshot with its note; fix; after each fix save the retake as a file with its context and the try it answers; when the person asks, upload each as a try (`retake_of`) and mark each pin fixed or still wrong.
  3. Document both in the guides with example prompts.
- **Acceptance:**
  - [ ] `instructions` (or the tool descriptions) contain the capture rule (contract test).
  - [ ] The prompt is listed by `prompts/list` and renders with a ticket key (MCP test).
- **Depends on:** T6.
- **Size:** M

### P14.T13 — "Whose turn?" gets its toolbar
- **Why:** the List view has the project rail, search, people chips and Show done; "Whose turn?" has none (`whose_turn.html.twig:10-15`, `WhoseTurnController.php:32`). In the prototype both views share the rail, search and Show done; only the people chips hide.
- **Steps:**
  1. Reuse the List view's rail, search and Show done; apply project and search to the turn query.
  2. "Show N more" uses the text-button style; the cards line up with the heading; keys never break across lines.
- **Acceptance:**
  - [ ] Search and project narrow "Whose turn?" (functional test with tickets that must be left out).
  - [ ] At 390 px wide, no key wraps (Playwright).
- **Prototype check:** `Main › Tickets` › Whose turn?
- **Size:** M

### P14.T14 — Home that tells the truth
- **Why:** Lena's Home says "All clear" while 22 tickets wait on her; "Your team" shows a placeholder; the admin tiles show "—".
- **Steps:**
  1. Until P07's score exists, "Needs you now" lists the open tickets where it is the person's turn, oldest wait first, at most 10, with "See all" to "Whose turn?" filtered to them.
  2. The headline follows the count ("3 tickets wait on you").
  3. "Your team" shows the avatar stack and "N people", linking to the team page.
  4. The admin tiles show their real values.
- **Acceptance:**
  - [ ] Home and "Whose turn?" agree on the count for Lena, Omar and Mia (functional test).
  - [ ] Home reads in a fixed number of queries (query-count test).
- **Prototype check:** `Main › Home`.
- **Size:** M

### P14.T15 — My reports page
- **Why:** the menu item exists and the product owner wants the page now (P07.T6 moves here).
- **Steps:** build P07.T6 as its phase file describes; actions stay in P07.T7.
- **Acceptance:** P07.T6's acceptance criteria, ticked there with a note that P14.T15 built them.
- **Prototype check:** `Main › My reports`.
- **Size:** M

### P14.T16 — Phone layout
- **Why:** on a phone the header wraps to three rows (about 185 px); on Tickets the rail and filters come before the heading, so the first ticket is below the fold.
- **Steps:**
  1. Below 600 px, the header is one row: logo, Report a problem, bell, avatar and a menu button that opens the nav.
  2. Tickets: heading first, then a "Filters" button that opens the rail and chips.
  3. Ticket page: covered by T7 step 4.
- **Acceptance:**
  - [ ] At 390×844 the header is at most 64 px tall, and the first ticket shows without scrolling (Playwright).
  - [ ] The menu button is reachable and works with the keyboard, and focus returns on close.
- **Size:** M

### P14.T17 — Polish pass across pages
- **Why:** the UI check found controls that do not match the design system.
- **Steps (one commit each):**
  1. Radios and checkboxes use `--primary` (`accent-color`); selects match text inputs; the theme choice is the same control everywhere.
  2. Links that act as buttons use the button styles; a destructive "Remove" on the team page looks destructive.
  3. Empty states on Report drafts and My notebooks say how to make the first one; "Reports your Claude Code … prepared" is reworded.
  4. Admin › AI: switches and labels on one line at phone width; "Save key" lines up; plain words instead of "Symfony AI", "bridge" and "JSON".
  5. New ticket uses the same chips, button names and Cancel as Report a problem.
  6. Profile, Security and API tokens get a small tab rail between them.
  7. Team page: "People 2 waiting on them" says what it counts, and matches the numbers.
  8. The logo lines up with the page content.
- **Acceptance:**
  - [ ] Each step has a before and after screenshot in the task's hand-off note.
  - [ ] The accessibility check (axe) passes on every changed page.
- **Size:** M

### P14.T18 — Demo switch for two-step login
- **Why:** a person trying a local demo must turn on two-step login before they can make a token or open admin pages.
- **Steps:**
  1. `PINROUND_DEMO_SKIP_2FA` (bool, default false), read into `RecentAuth` (covers `AskToConfirmItsYou` and `SymfonyLoginAssurance`) and `AdminAreaVoter`.
  2. The kernel refuses to boot when it is true and `APP_ENV` is not `dev`, with a plain message (`KernelTest`).
  3. A banner on every page while it is on: "Demo mode: two-step login is skipped."
  4. `.env` explains it; the `pinround-setup` skill asks whether to turn it on.
- **Acceptance:**
  - [ ] With the switch on in dev, Sara opens admin pages and makes a token without two-step login.
  - [ ] With the switch on and `APP_ENV=prod`, boot fails with the message.
  - [ ] With it off, every existing two-step test still passes.
- **Depends on:** ADR-0049 accepted.
- **Size:** S

### P14.T19 — Extension: the floating dock
- **Why:** today every pin, capture and next screenshot needs another click on the toolbar icon; scenario runs stop when the page is clicked, because they live in the popup.
- **Steps:**
  1. A dock in a closed shadow root, injected into the chosen tab: pinround logo and grip, target picker, Pin (P), Capture (C), Play, Save draft with a count, shrink (H). Drag anywhere; it snaps to the nearest side; the side and height are kept per host.
  2. Pins show a visible Remove and accept Delete; "Clear N pins" removes them all.
  3. The scenario player runs from the dock: step card with Do / Expected, "Looks right", "Found a problem" (turns on pin mode and tags the next capture with the step) and Skip; Play opens `start_url` when the tab is elsewhere.
  4. The popup stays for setup and for opening the dock.
  5. Keys are ignored while typing in the page's own fields.
- **Acceptance:**
  - [ ] A tester pins, removes a pin, captures twice and saves one draft without opening the popup again (Playwright, `chromium-extension`).
  - [ ] A scenario keeps running after a click on the page.
  - [ ] The page cannot read pins or notes through the DOM (existing overlay tests extended).
- **Prototype check:** dock prototype, version 2.
- **Size:** L

### P14.T20 — One-click connect with OAuth
- **Why:** everyone pastes a token into the extension and into Claude Code, and making a token needs two-step login first. A Claude custom connector needs OAuth anyway.
- **Steps:**
  1. OAuth 2.1 authorization server in Identity: authorization code with PKCE, consent page listing the scopes, access tokens of 1 hour, refresh tokens with rotation, revocation; discovery and protected-resource metadata for `/mcp`.
  2. Extension: "Connect" opens the consent page with `chrome.identity.launchWebAuthFlow`; "Sign out" revokes.
  3. Claude Code and the Claude connector: document the steps; note that a Claude connector reaches pinround from Anthropic's servers, so it needs a public HTTPS address (not `https://localhost`).
  4. Personal tokens keep working.
- **Acceptance:**
  - [ ] The extension connects without a pasted token, and its calls use the issued token (Playwright).
  - [ ] A code is single use; a wrong PKCE verifier and a reused code are refused, and no token is issued.
  - [ ] Tokens never appear in logs (log test).
- **Depends on:** ADR-0050 accepted.
- **Size:** L

## Definition of done
- [ ] All P14 tasks ticked; the three journeys of the [outcome](#outcome) pass in Playwright.
- [ ] The UI check of every page at 1440×900 and 390×844 finds no high or medium problem.
- [ ] ADR-0048, 0049 and 0050 accepted; FR, guides, config and ADR index updated with their Index.
- [ ] Quality gates green: `composer test`, `composer phpstan`, `composer deptrac`, `composer cs`, `composer audit`.

## Hand-off notes
- 2026-11-20 (plan change): phase added from the product owner's hands-on test and a UI check of every page. P07.T6 (My reports page) moves here as T15, and the smallest part of P11.T1 (admin shell with the tabs that exist) as T3; their phase files say so. Evidence for T1 and T2 (live checks on SHOP-42 and Report a problem) is in this session's notes, not in the repo.
</content>
</invoke>
