# Phase 05 — Screenshots, pins and rounds

Goal: the heart of pinround: safe screenshot uploads, numbered pins, Round 0 and the Round 1 checklist, review rounds with before/after and verdicts, full-screen review, and the private draft round that Claude Code will use.

## Index
- [Before you start](#before-you-start) — prototype boards, requirements, ADRs, docs
- [Outcome](#outcome) — what works after this phase
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — FR and NFR ids
- [Design notes](#design-notes) — tables, flows, decisions made here
- [Tasks](#tasks) — P05.T1 to P05.T10
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — notes for P06, P08, P09

## Before you start
1. **Prototype.** Open https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2 with the Artifact tool (`action: "read"`). Read three boards: `path: "project/Main.dc.html"` (ticket page Rounds section, draft round as the developer, the share dialog), `path: "project/Review.dc.html"` (review round), `path: "project/Focus.dc.html"` (full-screen review); `project/Shot.dc.html` is only the simulated screenshot. Fallback docs: [Ticket page: rounds](../04-prototype/screens/ticket-page.md#rounds), [Review round](../04-prototype/screens/review-round.md) (all sections), [Full-screen review](../04-prototype/screens/full-screen-review.md#elements-and-rules), [Share draft dialog](../04-prototype/screens/dialogs-and-popovers.md#share-draft-dialog), [Report a problem](../04-prototype/screens/report-a-problem.md) (screenshot and pins part).
2. **Requirements:** [FR-RND-01, FR-RND-02, FR-RND-03, FR-RND-04, FR-RND-05, FR-RND-06, FR-RND-07, FR-RND-08, FR-RND-09, FR-RND-10, FR-RND-11, FR-RND-12, FR-RND-13, FR-RND-14, FR-RND-16](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds) (FR-RND-06 and FR-RND-07 AI parts are filled in P08; FR-RND-15 plugin in P10), [NFR-SEC-06 upload rules](../01-requirements/non-functional-requirements.md#sec--security), [NFR-PERF-05, NFR-PERF-08](../01-requirements/non-functional-requirements.md#perf--performance), [NFR-A11Y-02](../01-requirements/non-functional-requirements.md#a11y--accessibility) (keyboard pins), [C-11](../01-requirements/constraints.md#c-11-screenshots-are-large-and-many).
3. **ADRs:** [0011 screenshot storage and processing](../03-adr/0011-screenshot-storage-and-processing.md), [0017 private draft rounds](../03-adr/0017-private-draft-rounds.md), [0009 outbox and media queue](../03-adr/0009-async-messaging-and-outbox.md), [0013 authorization](../03-adr/0013-authorization.md), [0027 secrets](../03-adr/0027-secrets-and-encryption.md).
4. **Architecture docs:** [media pipeline](../02-architecture/media-pipeline.md) — all sections, especially [upload flow](../02-architecture/media-pipeline.md#upload-flow), [validation rules](../02-architecture/media-pipeline.md#validation-rules), [processing worker](../02-architecture/media-pipeline.md#processing-worker), [storage key layout](../02-architecture/media-pipeline.md#storage-key-layout), [serving images](../02-architecture/media-pipeline.md#serving-images), [pin coordinate model](../02-architecture/media-pipeline.md#pin-coordinate-model); [upload security](../02-architecture/security.md#upload-security); [Review module](../02-architecture/modules.md#review-rvw_), [Media module](../02-architecture/modules.md#media-med_); tables [Review and Media](../02-architecture/data-model.md#review-and-media); [Stimulus controllers](../02-architecture/frontend.md#stimulus-controllers) (`pin-placer`, `presigned-upload`); [screenshot view hot path](../02-architecture/performance-and-scale.md#screenshot-view).
5. **Previous phase:** check the [P03 Definition of done](phase-03-tickets-core.md#definition-of-done) and the [P04 Definition of done](phase-04-conversation-and-pings.md#definition-of-done): P05.T6 clears pings (one action path) and P05.T7 opens the question composer, both built in P04. 🏁 Hackathon exception: P04 is cut from the slice, so the 🏁 tasks here (T1–T5, T8, T9) must not call P04 code; the ping clearing and question parts of T6 and T7 come after the hackathon.

## Outcome
Omar fixed SHOP-88. Before he sends it on, he starts a draft round that only he can see: he pastes two screenshots, drops a pin on one, writes "header is cut off" and marks it "Not right yet". Later he presses "Send to code review"; a dialog asks "Share your draft round?" and he picks "Share and move to Code review". Everyone now sees "Round 1 · self-check by Omar". Mia opens a review round: before and after side by side, the earlier pins listed, "Fixed" or "Still wrong" per pin, and one verdict per screen area. Every image went straight to the private bucket, was re-encoded by a worker, and is only shown through short-lived signed URLs.

## Scope
- Media: upload intents, presigned PUT, completion, processing worker (libvips), variants, signed GET URLs, quotas.
- `Screenshot` + `PinLayer` + `PinList` components, `pin-placer` and `presigned-upload` Stimulus controllers.
- Round model: report (Round 0), checklist (Round 1), review rounds, draft rounds; the Rounds section on the ticket page.
- Round 1 checklist; review round page with compare modes, area tabs, per-pin verdicts, area verdicts; full-screen review.
- Draft round: private editor, verdict per screenshot, share now, delete, hand-off dialog, "For Claude Code" box.

## Out of scope
- Creating Round 0 from the "Report a problem" page (P06 calls the Review Api built here).
- AI check lines and AI suggestion pins (P08 fills them; this phase renders them when data exists).
- MCP tools (P09) and the browser capture extension (P10).

## Requirements covered
FR-RND-01, FR-RND-02, FR-RND-03, FR-RND-04, FR-RND-05, FR-RND-06, FR-RND-07, FR-RND-08, FR-RND-09, FR-RND-10, FR-RND-11, FR-RND-12, FR-RND-13, FR-RND-14, FR-RND-16 (FR-RND-06 and FR-RND-07: display and Keep/Dismiss only), FR-WF-17 (verdicts clear pings), NFR-SEC-06, NFR-PERF-05, NFR-PERF-08, NFR-A11Y-02, C-11.

## Design notes
- **Tables:** `med_object`, `med_upload_intent`, `rvw_round`, `rvw_screenshot`, `rvw_pin`, `rvw_check_item`, plus a new `rvw_area` for review rounds (round id, name, position, verdict `pass`/`needs_fix`/`question`/NULL, note, decided_by) in P05.T7. All with RLS.
- **Storage keys:** `uploads/…` (temporary), `media/{org}/{yyyy}/{mm}/{id}/view.webp` and `thumb.webp`. View ≤ 1920 px, thumb ≤ 480 px; 40 megapixels max; 50 screenshots per round.
- **Visibility:** a round inherits the ticket's visibility; an unshared draft is visible to its owner only (not admins, not the lead). `RoundQueries::forTicket` filters drafts out for everyone else.
- **Draft round number:** set when shared: the next free round number on the ticket (Round 0 = report, Round 1 = checklist when it exists).
- **Decision taken here (review verdicts and moves):** saving area verdicts never moves the ticket by itself. When every area has a verdict, the side panel offers the matching verb to the tester: all areas Pass → "Pass it"; any Needs fix → "Needs changes"; Question → opens the question composer addressed to the lead (pauses the ticket). The move goes through the normal verb path and its rules. Record this in the phase hand-off notes and in [workflow-engine.md](../02-architecture/workflow-engine.md#transition-catalogue) as a note.
- **Performance:** images come from the object store through signed URLs with long cache on immutable keys; the app never streams image bytes.

## Tasks

### P05.T1 — Upload intents and presigned PUT 🏁
**Why:** large files must not pass through PHP memory, and every upload must be allowed first ([FR-RND-01](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds), [ADR-0011](../03-adr/0011-screenshot-storage-and-processing.md)).
**Steps:**
1. Media domain `UploadIntent`, `MediaObject`; migrations with RLS.
2. Flysystem/S3 client config for MinIO (path-style), private bucket, bucket CORS that allows PUT only from the app origin.
3. `POST /media/intents` {purpose, mime, bytes, target {kind, id}} (`UploadIntentController`, JSON): first, after the member and form-token checks, the per-person rate limit of 60 begins a minute is spent (429, "You are adding files too fast. Wait a minute and try again."), before the body is read, so every begin with a valid token counts, even one refused later. Then `UploadVoter` (`MediaPermission::UPLOAD` on an `UploadTarget`) asks the `MediaTargetAuthorizer` of the target's kind whether the caller may add to the target; a kind no authorizer decides is refused. Then `Uploads::begin` checks purpose, type and size, then, under a per-organization lock, the per-person cap of 20 open uploads ("You have too many uploads waiting…") and then the organization's storage quota ("Your company's storage is full. Ask your admin."; skipped when unlimited), then asks the authorizer again. This task ships the implementation for kind "ticket" (`TicketMediaTarget`: Ticketing `VIEW` and `EDIT`, from P03); Review registers its round/draft implementation in P05.T4, so this task does not need the round model. Then a presigned PUT (5 minutes) with fixed `Content-Type` and `Content-Length`. The intent stores the target kind and id.
4. `POST /media/intents/{id}/complete` re-checks the stored target, marks the intent used and writes `ProcessUpload` to the outbox in the same transaction; the relay sends it to the `async_media` queue.
5. `presigned-upload` Stimulus controller: browser-side type/size check, progress, paste from clipboard, local preview.
**Files:** `src/Media/**` (`UI/Controller/UploadIntentController`, `UI/Voter/UploadVoter`), `src/Ticketing/UI/Media/TicketMediaTarget.php`, `assets/controllers/presigned-upload_controller.js`, `config/packages/flysystem.yaml`.
**Acceptance criteria:**
- [x] A 16 MB file or a GIF is refused before any URL is issued, with a plain message. (`tests/Functional/Media/UploadIntentsTest.php`, `aFileOfTheWrongTypeOrSizeIsRefusedBeforeAnyUrlWithThePlainMessage`.)
- [x] A presigned URL cannot be used after 5 minutes or for another content type. (`tests/Integration/Media/Storage/PresignedUploadsMinioTest.php`: `aUrlSignedLongerAgoThanItsFiveMinutesIsRefused`, `aUrlIsSignedForFiveMinutesFromTheClocksNow`, `aPutWithAnotherContentTypeIsRefusedAndStoresNothing`, `aPutWithAnotherAllowedPictureTypeIsRefusedToo`.)
- [x] Anonymous GET on the uploaded object fails. (`PresignedUploadsMinioTest`, `anAnonymousGetOfAnUploadedObjectIsRefused`.)
**Tests:** unit (validation), integration with MinIO in CI, functional intent/complete, voter + isolation.
**Security notes:** the storage key never contains user input; intents are single use; size limits in the presigned URL are not trusted alone (the worker checks again in P05.T2).
**Prototype check:** none (backend task).
**Size:** M

### P05.T2 — Processing worker and signed URLs 🏁
**Why:** uploaded files are untrusted; only re-encoded images are ever shown ([NFR-SEC-06](../01-requirements/non-functional-requirements.md#sec--security)).
**Steps:**
1. Handler `ProcessUpload`: first `HEAD` the uploaded object; if its size differs from the intent's `bytes` or is above 15 MB, delete it and reject. Check magic bytes. Read width and height from the image header **before** decoding and refuse > 40 megapixels or animated images. Then decode with libvips, re-encode to WebP view (≤ 1920 px) and thumb (≤ 480 px), strip metadata, store, delete the upload object.
2. Status `pending` → `ready` / `rejected` with a plain reason; events `MediaReady`, `MediaRejected`.
3. Dedup by `sha256` per org (reuse the stored object, new reference).
4. `SignedUrls::forVariants(OrganizationId, list<string> mediaIds, variant, at): array<string, MediaImage>`: short-lived signed GET (valid 5 minutes from a signing time rounded down to the minute, so at least 4), for at most `MAX_MEDIA_IDS` = 200 pictures in one query. It takes no `UserId`: the caller must already have decided that the viewer may see the owner (ticket or round). Each `MediaImage` has a `MediaImageStatus` (pending, ready with the URL, rejected with the reason). The URL asks the storage for `Cache-Control: private, max-age=240, immutable`, and renders inside one minute give the same URL ([ADR-0041](../03-adr/0041-sign-page-images-at-render-time.md)).
5. "Processing…" placeholder until ready (updates via Turbo Stream; poll fallback when Mercure is not there yet).
**Files:** `src/Media/Infrastructure/Processing/*`, `docker/` (libvips in the worker image).
**Acceptance criteria:**
- [x] A PNG with appended script bytes is re-encoded and the extra bytes are gone. (`tests/Unit/Media/Application/Processing/CleanUploadTest.php`, `aPngWithAppendedScriptBytesLeavesNoByteOfThemInAnyStoredVariant`; end to end in `tests/Integration/Media/Processing/ProcessUploadHandlerTest.php`, `aValidPictureEndsReadyWithItsTwoWebpVariantsInTheBucketNoUploadObjectAndOneMediaReadyInTheOutbox`. The re-encoding is a `FakeImageEncoder` until the libvips encoder exists.)
- [x] A file whose magic bytes do not match its type is rejected. (`CleanUploadTest`, `aFileWhoseSignatureHeaderOrTypeIsWrongIsRejectedBeforeAnyDecoding`.)
- [x] A 50,000 × 50,000 px image is rejected from its header, before decoding, without exhausting memory. (`CleanUploadTest`, `aTooBigOrAnimatedPictureIsRejectedFromItsHeaderAndTheEncoderIsNeverCalled`; `PurePhpImageHeaderReaderTest`, `aHeaderClaimingAHugeSizeIsReadAsItStatesWithoutDecodingAnything`; `ProcessUploadHandlerTest`, `aPictureOf50000By50000PixelsIsRejectedFromItsHeaderAndNeverEncoded`.)
- [x] An object larger than the intent (uploaded by a client that ignored the presigned limits) is deleted and rejected. (`CleanUploadTest`, `anObjectNotTheSizeOfItsIntentOrAbove15MbIsDeletedAndRejectedWithoutReadingIt`; `ProcessUploadHandlerTest`, `anObjectLargerThanItsIntentIsDeletedAndRejectedAndNothingIsStored`.)
- [ ] Processing p95 ≤ 10 s for a 15 MB image (NFR-PERF-08).
**Tests:** worker tests with a fixture set (polyglot, wrong magic, huge, animated, oversized object, valid); signed URL expiry test.
**Prototype check:** none (backend task).
**Size:** M

### P05.T3 — Screenshot, pin layer and keyboard pins 🏁
**Why:** a pin says more than a paragraph ([FR-RND-02](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds), [NFR-A11Y-02](../01-requirements/non-functional-requirements.md#a11y--accessibility)).
**Steps:**
1. `Screenshot` component with `PinLayer` (absolutely positioned numbered markers at x/y percent) and `PinList` (the text equivalent: number + note, editable or read-only).
2. `pin-placer` controller: click adds a pin at `(clientX - left) / width × 100` (and y), rounded to 0.1, limited to 0–100; keyboard: "Add pin" puts one in the centre, arrows move 1 % (Shift 5 %), Enter confirms, Delete removes.
3. Pin note max 1,000 characters; numbering is per screenshot and stable.
4. Marker look ([FR-RND-02](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds), product owner's decision 2026-11-14; differs from the prototype's filled marker on purpose): a hollow ring with a dark outer and a white inner outline (visible on any background, WCAG 1.4.11), no fill, the number in a small tab at the ring's edge, and the note in a bubble beside the spot on hover, focus or select. AI pins stay dashed in AI colours; the selected ring stays `--primary`. Still x/y percent only, no width or height.
**Files:** `src/Review/UI/Component/{Screenshot,PinLayer,PinList}.php`, `assets/controllers/pin-placer_controller.js`.
**Acceptance criteria:**
- [ ] Pins stay on the same spot when the window is resized.
- [ ] A keyboard-only user can add, move, describe and remove a pin.
- [ ] Each marker has an accessible name "Pin 2: header is cut off".
- [ ] The field under a pin stays readable (the ring has no fill).
- [ ] The note bubble shows on hover, on keyboard focus and when the pin is selected.
**Tests:** unit (coordinate maths), Playwright (mouse and keyboard), axe.
**Prototype check:** the pin list in [Review round: screenshots and pins](../04-prototype/screens/review-round.md#screenshots-and-pins) and the report form pin list; the marker look is not checked against the prototype (ring pins differ on purpose).
**Size:** M

### P05.T4 — Round model and the Rounds section 🏁
**Why:** every round, from report to self-check, lives in one model ([FR-RND-03, FR-RND-14](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds)).
**Steps:**
1. Review domain: `Round` (kind report/checklist/review/draft, owner, number, shared_at, state, version), `Screenshot` (media id, position, before/after label, verdict, optional title ≤ 120 characters, optional summary ≤ 600 characters; [FR-RND-16](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds); the summary is the former per-screenshot note), `Pin` (number, x/y, note, verdict, source, ai_state, author).
2. Migrations `rvw_round`, `rvw_screenshot`, `rvw_pin` with RLS and the partial unique index "one open draft per owner per ticket".
3. `Review\Api\RoundCommands` and `RoundQueries::forTicket(TicketId, UserId $viewer)` (drafts filtered for non-owners; rounds of tickets the viewer may not see are filtered by the TicketVisibility predicate through `Ticketing\Api\TicketVisibility`). Register the Review `MediaTargetAuthorizer` (round and draft targets) for P05.T1.
4. `RoundCommands::createReport(TicketId, list<ScreenshotWithPins>)` for P06 (Round 0).
5. Rounds section on the ticket page: Round 0 card "Round 0 · Report · by Mia · 2 pins" with the screenshot and pins, its title and summary shown above the picture as text; review rounds with "Open round"; shared drafts; the empty state text when there are none.
**Files:** `src/Review/**`, ticket page partial.
**Acceptance criteria:**
- [x] Rounds list in order (0, 1, then by number). (`tests/Integration/Review/Queries/DbalRoundQueriesTest.php`, `roundsComeByNumberThenTheViewersOwnUnsharedDraftWithScreenshotsByPositionAndPinsByNumber`, `anotherPersonsUnsharedDraftIsLeftOutForEveryone`.)
- [ ] A title over 120 or a summary over 600 characters is refused with a plain message and nothing is saved.
- [x] A round of a team-only ticket is invisible to people outside the team (isolation + voter tests; case added to the visibility leak suite). (`tests/Integration/Visibility/RoundListLeakTest.php`: `theRoundsOfATeamOnlyTicketReachOnlyAViewerWhoMaySeeIt`, `aRoundOfATeamOnlyTicketIsInvisibleOutsideTheTeamAndToAnotherOrganization`; `DbalRoundQueriesTest`, `aTicketTheViewerMayNotSeeHasNoRoundsAndCostsNoRoundQuery`.)
**Tests:** domain unit tests; integration (queries, draft filter); functional render.
**Prototype check:** [Ticket page: rounds](../04-prototype/screens/ticket-page.md#rounds).
**Size:** M

### P05.T5 — Screenshot and pins on the simple New ticket form 🏁
**Why:** the hackathon demo starts with "Mia creates a ticket with a screenshot and two pins"; the full Report a problem page is P06, so the simple form from P03.T1 gets the screenshot part now ([FR-RND-01](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds), [FR-RND-02](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds), [FR-RND-03](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds)).
**Steps:**
1. Add a "Screenshot" section to `/tickets/new`: "Upload a file" and paste, using the `presigned-upload` controller (P05.T1), then the `Screenshot` + `PinLayer` + `PinList` components (P05.T3) with pin notes.
2. On create, call `Review\Api\RoundCommands::createReport(TicketId, list<ScreenshotWithPins>)` in the same transaction as the ticket (Round 0).
3. If processing is not finished at submit time, the form waits for `MediaReady` (poll every second, max 15 s) or shows "Still processing your screenshot. Try again in a moment."
4. P06.T1 later moves this section into the Report a problem page; keep it as a reusable Twig partial.
**Files:** `templates/ticket/new.html.twig`, `templates/review/_screenshot_with_pins.html.twig`, `src/Ticketing/Application/CreateTicket/*`.
**Acceptance criteria:**
- [ ] Creating a ticket with one screenshot and two pins shows "Round 0 · Report · by Mia · 2 pins" on the ticket page.
- [ ] Creating without a screenshot still works and creates no round.
- [ ] A rejected upload (wrong type) shows a plain message and the ticket form keeps its text.
**Tests:** functional create with and without screenshot; Playwright journey with a fixture PNG; isolation.
**Prototype check:** screenshot area and pin list in [Report a problem: fields and copy](../04-prototype/screens/report-a-problem.md#fields-and-copy) (labels "Upload a file", "Click on the screenshot to drop a pin.", "What is wrong here?").
**Size:** M

### P05.T6 — Round 1 checklist
**Why:** every reported pin must be fixed by the developer and checked by the tester ([FR-RND-04](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds)).
**Steps:**
1. `rvw_check_item` table; a checklist round is created from Round 0 pins (on the first "Start work", or when Round 0 exists and the ticket is in To do).
2. Item states `open`, `fixed`, `ok`, `not_fixed`; the assignee sees "Mark fixed" on open or not-fixed items; the tester sees "OK" / "Not fixed" on fixed items.
3. Summary "1 of 2 checked OK"; every change is a ticket event and clears the actor's pings.
**Files:** `src/Review/Application/Checklist/*`.
**Acceptance criteria:**
- [ ] Only the assignee can mark fixed; only the tester can mark OK / Not fixed (voter rows).
- [ ] State chip colours and texts match the prototype.
**Tests:** unit (state machine), functional per role.
**Prototype check:** checklist card in [Ticket page: rounds](../04-prototype/screens/ticket-page.md#rounds).
**Size:** S

### P05.T7 — Review round page
**Why:** the tester checks only the pinned spots, before and after ([FR-RND-05](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds), FR-RND-06 and FR-RND-07 display).
**Steps:**
1. Route `GET /t/{key}/rounds/{number}`; header "Round 2 · shot today, 09:12 · Ready for Mia"; compare control Side by side / Before / After; "Full screen" button.
2. `rvw_area` table (add to [data-model.md](../02-architecture/data-model.md#review-and-media)); area tabs with state chips (Passed, "3 pins", Not reviewed).
3. Before = the previous round's after screenshot (or Round 0), with its title and summary, read-only; after = this round's.
4. Side panel: pins from the earlier round with Fixed / Still wrong (+ Undo); AI check line and "AI found N more" with "Keep as my pin" / "Dismiss" when AI data exists (P08); area verdict chips Pass / Needs fix / Question, note "What should Lena know?", "Then: Lena, team lead", "Save verdict".
5. After every area has a verdict: offer the matching verb (see [design notes](#design-notes)); Question opens the question composer to the lead.
6. Toasts as in the prototype ("Verdict saved. Lena can see it.", "Pick Pass, Needs fix or Question first.").
**Files:** `src/Review/UI/Controller/RoundController.php`, `src/Review/UI/templates/round.html.twig`.
**Acceptance criteria:**
- [ ] Verdicts are saved per pin and per area and appear in What happened.
- [ ] A dismissed AI pin never counts; a kept AI pin becomes the reviewer's pin.
- [ ] No verdict moves the ticket by itself.
**Tests:** functional per action; Playwright review journey; voter rows (who may set verdicts: tester and lead).
**Prototype check:** [Review round](../04-prototype/screens/review-round.md): header, tabs, side panel texts and buttons.
**Size:** L

### P05.T8 — Draft round (private self-check) 🏁
**Why:** the developer checks their own work, privately, before anyone else sees it; Claude Code reads the notes ([FR-RND-09, FR-RND-10, FR-RND-12, FR-RND-13](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds), [ADR-0017](../03-adr/0017-private-draft-rounds.md)).
**Steps:**
1. "Start a draft round" for the assignee of an open ticket (one open draft per owner per ticket); tag PRIVATE; title "Draft round · only you can see this".
2. `DraftRoundEditor` Live Component: add screenshot (upload/paste), pins with notes, verdict per screenshot "Looks right" / "Not right yet" (toggle), a title and a note per screenshot (the note is the summary, [FR-RND-16](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds)), remove screenshot; status "1 of 2 look right".
3. Buttons "Add screenshot", "Share with the team now", "Delete draft" (files deleted after retention).
4. "For Claude Code" box: copyable line "Read my pinround notes for SHOP-88 and fix what is marked "Not right yet"." and the tool names `get_round_notes`, `add_screenshot`, `set_verdict` (served in P09).
5. `RoundQueries::roundNotes(TicketId, UserId $owner)` returning a stable JSON-ready DTO (screenshots with title and summary, verdicts, notes, pins) for P09.
**Files:** `src/Review/UI/Component/DraftRoundEditor.php`, `src/Review/Application/Draft/*`.
**Acceptance criteria:**
- [ ] The lead, the tester and an admin cannot see an unshared draft (HTML, queries and direct URLs; voter + isolation tests).
- [ ] "Share with the team now" turns it into "Round N · self-check by Omar", read-only.
- [ ] `roundNotes` returns exactly what the editor shows.
**Tests:** voter matrix rows (owner-only), functional, Playwright draft journey, unit test of the notes DTO.
**Prototype check:** draft round card in [Ticket page: rounds](../04-prototype/screens/ticket-page.md#rounds) (texts, buttons, status line, the Claude Code box).
**Size:** L

### P05.T9 — Hand-off dialog: share or keep private 🏁
**Why:** the decision to share must happen when the ticket leaves you, not be forgotten ([FR-RND-11](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds)).
**Steps:**
1. In the move path (P03.T7): when the actor owns an unshared draft with at least one screenshot, has not decided yet, and the verb changes the step, answer with the dialog instead of moving.
2. Dialog "Share your draft round?" with the text "Your draft round has N screenshots. Share it with the team as round N, or keep it private. Private notes stay readable for you and Claude Code." Buttons Cancel, "Keep private and move", "Share and move to <step>".
3. Both choices run the original verb through the normal path, then record the decision (asked once per draft).
4. Toasts "Moved to Code review. Your draft is now round 1 for the team." / "Moved to Code review. Your draft stays private."
**Files:** `src/Review/UI/Component/ShareDraftDialog.php`, move controller hook.
**Acceptance criteria:**
- [ ] Cancel moves nothing and shares nothing.
- [ ] The dialog never appears twice for the same draft or for a draft without screenshots.
- [ ] Rules and voters still apply to the move (no bypass).
**Tests:** functional (three choices), Playwright journey.
**Prototype check:** [Share draft dialog](../04-prototype/screens/dialogs-and-popovers.md#share-draft-dialog).
**Size:** S

### P05.T10 — Full-screen review
**Why:** big screens need a focused mode, one pin at a time ([FR-RND-08](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds)).
**Steps:**
1. Route `/t/{key}/rounds/{number}/focus`: one area, After/Before toggle, pins (AI pins on After only), selected ring.
2. Bottom bar: "PIN 2 OF 5 · MIA" or "AI SUGGESTION 4 OF 5", the note, buttons Fixed / Still wrong (or Keep / Dismiss), Previous / Next pin; a choice moves to the next pin.
3. "Exit full screen" back to the round page; keyboard: arrows for pins, Escape exits.
**Files:** `src/Review/UI/templates/focus.html.twig`, `assets/controllers/pin-walker_controller.js`.
**Acceptance criteria:**
- [ ] Choices made here show on the round page.
- [ ] Works with keyboard only.
**Tests:** Playwright; axe.
**Prototype check:** [Full-screen review: elements and rules](../04-prototype/screens/full-screen-review.md#elements-and-rules).
**Size:** M

## Definition of done
- [ ] All tasks ticked; upload → pins → draft → share-on-hand-off → review journey passes in Playwright.
- [ ] Upload fixture suite (polyglot, wrong magic, huge, animated, oversized object) passes; bucket is private; signed URLs expire.
- [ ] Draft privacy proven by voter, isolation and direct-URL tests.
- [ ] `rvw_area` and the review-verdict decision are written into the architecture docs.
- [ ] Global [Definition of done](README.md#definition-of-done-for-every-task) met for every task.

## Hand-off notes
- P06 creates Round 0 through `RoundCommands::createReport` and reuses the screenshot partial from P05.T5.
- P08 fills the AI check line per pin and AI suggestion pins (vision); this phase already renders them.
- P09 exposes `roundNotes`, `addScreenshot` and `setVerdict` as MCP tools for the owner's token.
- **2026-11-14 — product owner decisions (plan change only; nothing built or ticked):**
  - (a) Every screenshot in every round kind has an optional title (≤ 120 characters) and an optional summary (≤ 600 characters, one free text box, "what this picture shows"; on a before picture what is wrong, on an after picture what changed and what it shows now). Shown above the picture as text, never drawn into pixels. A review round's before is the earlier round's after row, so title and summary carry forward read-only. See [FR-RND-16](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds). `rvw_screenshot` gets `title` and `summary` (the old `note` is the summary) in [data-model.md](../02-architecture/data-model.md#review-and-media).
  - (b) Pins are see-through rings (dark outer, white inner outline, number tab, note bubble on hover, focus or select). This deliberately differs from the prototype's filled `#b4470a` marker in [review-round.md](../04-prototype/screens/review-round.md#screenshots-and-pins): the requirement wins.
  - (c) A person types title and summary. In P09 the person's own Claude Code may fill them through MCP, only into a draft its owner checks. In P08 pinround AI may suggest summary text ("Use this" / "Dismiss"); never saved alone ([ADR-0020](../03-adr/0020-ai-safety-and-prompt-injection.md)). Both are untrusted text, wrapped in delimiters for AI and MCP.
  - (d) New flow: a reporter's Claude Code files a report draft with the MCP tool `create_report` (scope `reports:write`); the reporter checks it and clicks Send, which calls `RoundCommands::createReport`; Send lives in Review's UI, not in Ticketing ([ADR-0040](../03-adr/0040-report-drafts-live-in-review.md)). See [ADR-0039](../03-adr/0039-agents-file-report-drafts-a-person-sends-them.md); built in P06 (UI) and P09.T9 (tool).
  - **PR split for this phase:** the first PR is T1–T4; the next PR is T5, T8, T9; T6, T7 and T10 come after the P04 Definition of done.
- **2026-11-14 — P05.T1 MinIO tests do not run in CI yet.** The integration job has no MinIO (only a stand-in TCP listener for `/health/ready`), so `composer test:integration` there excludes the `object-storage` group (`PresignedUploadsMinioTest`, `ObjectStorageWiringTest`, `MinioUploadCorsTest`). They run locally against the compose stack's MinIO. T1's "integration with MinIO in CI" is not met until CI gets a MinIO service.
- **2026-11-14 — dev MinIO CORS is by origin only.** MinIO has no bucket CORS (it answers `PutBucketCors` with NotImplemented), so `minio-init` sets the server-wide `cors_allow_origin` to the app origin (`MINIO_CORS_ALLOW_ORIGIN`). That limits origins but not methods. A production bucket's CORS must allow PUT only, and only from the app origin ([ADR-0011](../03-adr/0011-screenshot-storage-and-processing.md), P05.T1 step 2).
- **2026-11-15 — migration `Version20261115120000` (media tables) was changed after it ran, to add CHECKs.** A dev database that ran the old version must run it down and up: `bin/console doctrine:migrations:execute --down 'DoctrineMigrations\Version20261115120000'`, then the same command with `--up`. A fresh database needs nothing.
- **2026-11-15 — P05.T1 upload routes are built.** `POST /media/intents` refuses a bad type, size or purpose with 422 and a plain message, a target the person may not edit with 403, and an unknown intent on complete with 404. `POST /media/intents/{id}/complete` re-checks the stored target (a person who left the team is refused and the intent stays unused), refuses a second complete and an intent older than a day. A logged-out POST gets `302 /login`, not a JSON 401.
  - New migration `Version20261115141000` (target kind and id on `med_upload_intent`): run `doctrine:migrations:migrate` for dev and with `APP_ENV=test`.
  - It was `Version20261115140000` until the P04 merge, which brought a different migration with that number. A database that ran the old number has the columns but not P04's resolver function. Move the row, then migrate: `bin/console doctrine:migrations:version 'DoctrineMigrations\Version20261115140000' --delete`, then `... 'DoctrineMigrations\Version20261115141000' --add`, then `doctrine:migrations:migrate`. A fresh database needs nothing.
  - `ProcessUpload(organizationId, mediaId)` is written to the outbox and relayed to `async_media`, and handled by the P05.T2 worker (see its note below).
- **2026-11-15 — upload quota and per-person cap (P05.T1).** Migration `Version20261115160000` adds covering indexes for the storage sums: run `doctrine:migrations:migrate` for dev and with `APP_ENV=test`.
  - (a) The quota check sums stored objects and open intents in one statement. A running per-org usage row (for example `med_storage_usage`, locked `FOR UPDATE`, updated by begin, complete and reject) would make it O(1). Do that if the covering indexes are not enough at 2 M objects.
  - (b) Not built: the admin notice at 80% and 100% and the link to the Data settings (see [media-pipeline.md](../02-architecture/media-pipeline.md#quotas)).
  - (c) `Uploads::begin` and `complete` only work in a request whose tenant context is the given organization (`requireTenant`): a caller with no tenant, or another one, gets a `LogicException`. Only a logged-in actor who is not the given user, or an authorizer refusal, gives `UploadTargetDenied` (the authorizer reads the request's token). Callers outside a request (MCP in P09, console, message handlers) need a tenant set and an authorizer path that does not read token storage.
- **2026-11-15 — round tables (P05.T4 step 2).** Migration `Version20261115180000` adds `rvw_round`, `rvw_screenshot` and `rvw_pin`: run `doctrine:migrations:migrate` for dev and with `APP_ENV=test`.
  - (a) The app has no DELETE on the three tables yet. The task that first removes a round, screenshot or pin adds `GRANT DELETE` in its migration and updates `DatabaseRolesTest::theRoundTablesGrantTheAppNoDeleteAndSupportOnlyRead`.
  - (b) `rvw_round.decided_at` is not built; the task that first writes it adds the column.
  - (c) `title` and `summary` on `rvw_screenshot` (and `note` on `rvw_pin`) are `text` with `char_length` CHECKs (120, 600, 1,000), not `varchar(n)`, so a cap change is a CHECK swap, not a column rewrite.
  - (d) Two requests that take the same round slot at once rely on PostgreSQL's unique-index wait: the second `INSERT … ON CONFLICT DO NOTHING` waits for the first to commit and then writes nothing, so `DbalRounds` reports `RoundSlotTaken`. No test runs two connections at once to prove it.
  - (e) `DbalRounds::add` tells a repeated round id (a `LogicException`, a programming error) apart from a taken slot (`RoundSlotTaken`) by reading the id after an insert that wrote nothing. `Rounds::add`'s doc comment still says the `LogicException` is only for "no transaction"; update it with the next change to the interface.
  - (f) Done with the Rounds section: `RoundQueries::forTicket` returns `TicketRounds` (the rounds and `olderRoundsNotShown`) and loads Round 0, the viewer's own unshared draft and the newest `RoundQueries::MAX_NUMBERED_ROUNDS` (20) numbered rounds; the section says "N older rounds are not shown." (`DbalRoundQueriesTest`, cap tests; `RoundsSectionTest`). Each round may still hold 50 screenshots of 100 pins, and the pictures are signed in chunks of 200 media ids.
  - (g) The partial unique index `rvw_round_ticket_id_owner_id_open_draft_key` covers every unshared draft (`kind = 'draft' AND shared_at IS NULL`), closed ones too, not only open ones as step 2 says (`DbalRoundQueriesTest`, `aViewerHasAtMostOneUnsharedDraftOfATicketEvenWhenTheSecondIsClosed`). A person whose unshared draft was closed without sharing could start no new draft on that ticket. Decide whether a draft can be closed unshared; if so, add `AND state = 'open'` to the index.
  - (h) The ticket page is back within its query budget (ten; `TicketPageTest` pins 10 for the costliest page): the Rounds section loads in a lazy Turbo frame like `ThreadFrame`. `Review\UI\Component\RoundsSection` (a heading and the frame, embedded by name from `show.html.twig` with the ticket's key only) is filled by `Review\UI\RoundsController` at `/t/{key}/rounds`, which builds the viewer's own `TicketVisibility` from the request, answers 404 for an unknown, malformed, other-organization or hidden ticket (`TicketPermission::VIEW`), and reads the rounds through `Review\Application\RoundsVisibleTo`. `RoundsFrameTest` pins the frame's queries (15 with no round, 5 more with rounds, whatever their number). Only Round 0 and drafts load screenshots and pins; a card shows at most 10 pictures (`RoundCards::MAX_PICTURES_PER_CARD`, lazy-loaded) and says how many more are not shown. Pictures are signed in one query per 200 pictures (`SignedUrls::MAX_MEDIA_IDS`).
  - (i) "Open round" is a disabled button: there is no round page yet. Checklist and review rounds are rows only (no pin rows or states, and no screenshots loaded).
- **2026-11-15 — P05.T2 processing worker is built and tested, except the libvips encoder.** `CleanUpload` (handler `ProcessUploadHandler`, worker-media) checks size, signature, declared type, header size and animation, dedups by sha256 per organization and stores a view and a thumb; `MediaReady` and `MediaRejected` go to the outbox.
  - (a) **Still to do:** `VipsImageEncoder` and libvips in the worker image (Dockerfile). Until then the container binds `UnavailableImageEncoder`, which fails closed, so a picture that passes the header checks and is not a duplicate stays pending and the message is retried and then fails. The image rebuild is the user's call. Tests use `Tests\Fixtures\Media\FakeImageEncoder`; the "p95 ≤ 10 s for a 15 MB image" box stays open until the real encoder can be measured.
  - (b) The bucket is not part of the database transaction. A commit that fails after the upload object was deleted makes the retry reject the object as `Missing` (`CleanUploadTest`, `aCommitThatFailsAfterTheUploadWasDeletedLeavesTheObjectPendingAndTheRetryRejectsItAsMissing`), and the variants already written stay in the bucket unreferenced. A janitor for orphaned `media/` keys is not built.
  - (c) `ProcessUploadHandlerTest` is in the `object-storage` group like the other MinIO tests, so CI skips it until CI has a MinIO service.

- **2026-11-15 — signed page images (P05.T2 step 4, ADR-0041).** `SignedUrls::forVariants` signs a page of pictures in a minute window with a private `Cache-Control`. Not built: refreshing URLs on a page held open past 4 minutes, and the `/media/{id}/{variant}` redirect route for links outside a page. `PresignedDownloadsMinioTest` signs at the real clock (MinIO checks its own), so it stays out of CI with the other `object-storage` tests.
