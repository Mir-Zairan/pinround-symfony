# Frontend

How pinround's pages are built: Twig, Symfony UX (Turbo, Stimulus, Live Components), AssetMapper, design tokens, accessibility, and which prototype screen becomes which route.

## Index

- [Why server-rendered](#why-server-rendered) — the reason we do not build a single-page app.
- [Building blocks](#building-blocks) — Twig, Turbo, Stimulus, Live Components, AssetMapper: who does what.
- [Layout and navigation](#layout-and-navigation) — the app shell, menus per role and their route names, header items.
- [Design system: Twig components](#design-system-twig-components) — the reusable components and their prototype source.
- [Live Components](#live-components) — the few places where the server re-renders as you type or click.
- [Stimulus controllers](#stimulus-controllers) — the small JavaScript pieces, including pins, uploads and local AI.
- [Turbo and real-time updates](#turbo-and-real-time-updates) — frames, streams, and Mercure.
- [Design tokens and theming](#design-tokens-and-theming) — the M3 colours from seed #2A6F65, fonts, dark mode.
- [Accessibility](#accessibility) — WCAG 2.2 AA rules we build to.
- [Performance budgets](#performance-budgets) — limits for JS, CSS, images, and layout shift.
- [Frontend security](#frontend-security) — CSP, CSRF, safe HTML.
- [Route map: prototype screen → route → template](#route-map-prototype-screen--route--template) — the full table.

## Why server-rendered

Omar clicks "Start work". The server checks the rules, saves the move, and sends back the new action box. Nothing else on the page moves. There is no JSON API to keep in step with a second app, no client-side copy of the permission rules, and no 400 KB bundle to download.

A team of three can keep one codebase in one language secure and fast. That is why pinround uses Twig with Symfony UX instead of React or Vue ([ADR-0007](../03-adr/0007-server-rendered-ui.md)). The few rich interactions (pins on screenshots, uploads, local AI) are small Stimulus controllers.

## Building blocks

| Tool | Job in pinround |
|---|---|
| Twig | All HTML. Page templates live per module under `src/<Module>/UI/templates/`. The shell lives in `src/Web`. |
| Twig Components (`symfony/ux-twig-component`) | The design system: `<twig:Button>`, `<twig:Chip>`, `<twig:Tracker>` … |
| Live Components (`symfony/ux-live-component`) | Server-driven widgets that re-render on input: filters, the report form, the draft round editor |
| Turbo (`symfony/ux-turbo`) | Page navigation without full reloads; Frames for parts of a page; Streams for updates after a POST and from Mercure |
| Stimulus (`symfony/stimulus-bundle`) | Small controllers for behaviour HTML cannot do alone |
| AssetMapper + importmap | Serves JS/CSS without Node or a build step; files are versioned and cached forever |
| Mercure | Pushes Turbo Streams to open pages ([realtime-and-notifications.md](realtime-and-notifications.md)) |

Verify the exact bundle names and Twig function names against the Symfony UX docs at implementation.

## Layout and navigation

`base.html.twig` contains:

- **Header:**
  - logo "pinround";
  - main menu (built from roles, see below);
  - "Report a problem" (tonal button, for everyone with a team role: lead, member or viewer; FR-ORG-05 keeps this label where the prototype says "New report");
  - the "Local AI" status chip (only when the org's AI runs on laptops);
  - the bell with a count (for the same people as "Report a problem");
  - the user's avatar menu (for a user in two or more organizations, the current one's name and "Switch organization" first; then Profile, Security, Personal access tokens, Theme, Log out). Profile is not in the prototype; FR-ID-07 needs the page reachable. Personal access tokens come with P09. Theme is a System | Light | Dark switch that applies at once and is saved on the user.
- **Main:** one `<main>` per page.
- **Overlays:** the toast area (an `aria-live="polite"` region) and the dialog root.

Menu items come from the user's roles. There is no mode switch: a person with several roles sees the union ([ADR-0013](../03-adr/0013-authorization.md)).

| Item | Shown to | Route (name) |
|---|---|---|
| Home | everyone | `web_home` |
| Tickets | everyone with a team role, and managers | `ticketing_tickets` (`/tickets`) |
| My reports (with a count of questions waiting on me) | everyone with a team role | `my_reports` (`/my-reports`) |
| Team | everyone with a team role: "Team" for one team; a "Teams ▾" disclosure listing the teams by name when there are several | `team_show` (`/teams/{slug}`, the slug from `TeamSummary::slug` or, for a person's only team, `Roles::teamSlugOf`) |
| Admin | org admins | `web_admin` with `tab: people` (`/admin/people`; the admin home is its People tab; current on every tab) |

A team role is lead, member or viewer. Without an organization (the picker, a page without a login) the menu is Home alone, and there is no "Report a problem" or bell. `App\Web\UI\Navigation\MainMenu` builds the items (Twig `main_menu()`) and decides "Report a problem" and the bell (`can_report_problems()`); the route names above live there. Showing an item grants nothing: each page asks its own voter.

An item whose route does not exist yet is inert: muted text with no link and no focus, which tells screen readers "(not built yet)". When a phase adds the route under that name, the item becomes a link with no menu change.

**Home** is one page with three layouts, chosen by `App\Web\UI\Home\HomeLayout` from the user's roles. The work Home is for anyone who leads or joins a team, and the fallback for everyone else (no role, or a viewer only). A Manager with no lead or member role gets "Teams at a glance", the five-group legend and one card per team; an Admin with no team role and not a Manager gets "Your organization" (stat cards, a note and an "Open admin settings" button that is a link once the `web_admin` route exists, inert before). Neither of those two has chips.

The work Home has a date line, the headline, a role line for leads, the focus chips and the sections: Needs you now and Also waiting on you (left), Admin (admins, with an "Open settings" button), Pings for you, Release (leads) and Your team (right), then "All teams · you are also a manager" (Managers). Every Home sets `turbo-cache-control: no-cache`. The chips (`HomeFocusChoices`) only narrow the page: All shows every section, My work shows Needs you now, Also waiting on you and Pings for you, My team shows Release and Your team, All teams and Admin show their own section. Each section carries `data-focus-group` naming its chip, and the server prints `hidden` on the others, so the page opens on the saved chip (`ShowHomeFocus`) with no flash. The `focus-chips` controller presses a chip, shows and hides at once, and saves with a fetch POST to `web_home_focus`, putting the old chip back if the save is not answered 204; without the script each chip is a submit button of a form that posts the same way and is redirected back. The chosen chip is saved per user and organization so the page opens the same way next time. A chip's red count (when the section it hides needs the user) comes with the content in P07; the sections hold empty states until then.

The prototype's "view as" switch and "Big team" toggle are demo devices. They are not built.

## Design system: Twig components

Each component lives in `src/Web/UI/Component/` with a template in `src/Web/UI/templates/components/` (Twig namespace `@Web`). The names follow the prototype's CSS classes, so a developer can compare them one to one. The PHP class for `Switch` is `SwitchControl`, because PHP reserves `switch`; it is registered as `<twig:Switch>`.

The gallery at `/_gallery` shows every built component. It exists in dev and test only (route file `config/routes/component_gallery.yaml`) and returns 404 in prod. Add `?theme=light` or `?theme=dark` to show one theme.

| Component | Prototype source | Notes |
|---|---|---|
| `Button` (filled, tonal, outline, text; sizes md/sm) | `.btn-*` | Always a `<button>` or `<a>`; never a `div`. `:disabled`, `[aria-disabled="true"]` and `[aria-busy="true"]` look disabled and skip hover (busy shows the progress cursor) |
| `Chip` (toggle, with `aria-pressed`) | `.chip` | Filters, focus chips, verdict chips |
| `SegmentedControl` | `.seg` | List / Whose turn?; After / Before |
| `Tag` | `.tag` | Short UPPERCASE labels: PRIVATE, EXAMPLE, ONLY THE TEAM AND ADMINS SEE THIS |
| `AiChip` | `.aichip` | AI SUMMARY, AI ORDER, AI SUGGESTED |
| `StatusPill` | status pill | Step name in group colours; "Waiting · question to X" |
| `Avatar`, `AvatarStack` (+N) | `.av`, `.stack` | Initials; shows at most 4 faces, then +N |
| `Card` | `.card` | |
| `NeedItem` | Home "Needs you now" item | Rank, key + title, task verb, "Why" line, button, wait time |
| `GroupedList` | "Also waiting on you", ticket list groups | Collapsible header with `aria-expanded`, count, hint, Start button |
| `QueueBar` | the queue bar on the ticket page | "Code reviews · 2 of 4", Previous, Next / Finish, Stop |
| `NextStepBox` | "YOUR NEXT STEP" | Sentence + verb buttons + rule note + "Move to another step…" |
| `PingCard`, `BellPopover` | pings tray | Grouped by person + ticket |
| `PersonPicker` | person picker dialog | Search, sorted by load, side text "N waiting" |
| `Tracker` | My reports dots | Four dots: Reported → Building → Checking → Done; amber "Waiting" |
| `GroupBar` | manager bars, release bar | Five group colours, text labels inside or in a legend |
| `WorkloadBar` | Team page | Red at 20 or more, amber at 8 or more |
| `Screenshot` with `PinLayer` | Round 0, rounds, draft round, report form | Pins positioned by percent |
| `PinList` | pin notes under a screenshot | The text equivalent of the pins (accessibility) |
| `ScreenshotReportField` | the Screenshot section of the new ticket form (later Report a problem) | One picture uploaded, pasted or dropped (`presigned-upload`), pins with notes (`pin-placer`), a picture title and summary; posts `screenshot_media_id`, `screenshot_title`, `screenshot_summary`, `pins[n][x|y|note]`. Re-rendered after a refusal it keeps the title and summary, and the picture and pins only when the picture is the person's own and not rejected (pending shows "Processing…"); a malformed posted value counts as empty. The error is `#screenshot-error`, tied to the file input and text fields with `aria-describedby`. |
| `Thread`, `Message`, `Reply`, `Composer` | ticket conversation | Comment / Question switch; answering a question resolves it |
| `Dialog` | dialogs (ping, picker, share draft, local AI) | Native `<dialog>` with focus trap; a dialog with no trigger of its own, like the share draft dialog that arrives in a Turbo Stream, is mounted with `openOnConnect` and opens itself; the `share-draft` controller removes it once closed |
| `Toast` | toast | One at a time, 3.6 s, `role="status"`. A flashed toast arrives empty with its text in `data-toast-message-value` and `data-turbo-temporary` (no re-show on Back); the `toast` controller writes the text in 150 ms after connect, so the region announces it, and removes the toast 3.6 s later. Without JavaScript a `<noscript>` copy shows and stays. The gallery's toast has no controller and stays |
| `Switch` | admin switches | `role="switch"`, `aria-checked` |
| `EmptyState` | dashed boxes | One plain sentence + one action |

## Live Components

Live Components re-render on the server as the user types or clicks. Use them only where the server must recompute something:

| Live component | Why live | Debounce |
|---|---|---|
| `TicketFilters` (search, people chips, project, Show done, "New · to check") | Updates the list frame and the counts | 300 ms on search |
| `ReportForm` (Report a problem) | Live duplicate suggestions, the "When you send:" preview sentence, check reasons, AI ✦ suggestions | 400 ms on title/text |
| `DraftRoundEditor` (`src/Review/UI/Component`) | The owner's unshared draft round: add and remove screenshots, verdicts, title and summary, pins with notes; each change is saved at once. Share and delete are the plain forms of `DraftRoundController`, not live actions | title and summary: on change (blur); pins: 600 ms after the last pin change |
| `PersonPickerSearch` | Searches big teams (2,000 people) on the server | 200 ms |
| `ExplainBox` | Sends the private "Explain it to me" request and shows progress | — |

Everything else is plain Twig + Turbo: links, forms that POST and answer with a Turbo Stream.

`TicketFilters` (`src/Ticketing/UI/Component`, `symfony/ux-live-component` 3.7) is built for the search, the "Assigned to" chips and "Show done"; project and "New · to check" come with the rail and the check filter. Its live requests are POSTs, so the search words never sit in a component URL, and each first asks `OrganizationPermission::VIEW` on the request's organization. After each change it sends the browser event `ticket-filters:changed` with the list's URL (`?project=&person=&done=&q=`, `q` trimmed); the `ticket-list` controller puts that URL in the address bar (a chip adds a history entry, typing replaces it; Back reloads the page, as Drive is off) and loads it into the `ticket-list-frame` Turbo frame, which holds the title, the count line and the steps with their own "Show more" frames. Without script every chip is a link to that URL and the search is a GET form. The people are the lead and members of the page's team, the same one the project rail shows (the picked project's team when the viewer may see it, `TeamPermission::VIEW`; else `?team=`, one of the viewer's own, else the viewer's first team by id; viewers are no assignees): `Me` for a lead or member of it, each other one's first name while the team has at most 4, else a `Person ▾` chip (`data-person-picker-trigger`, the picked person's first name once one is) that opens the person picker. A person set by a chip or the picker must be a lead or member of the team, else the action is a 404. The chips cost the page two queries beside the list's five. The person picker is `PersonPicker` (`src/Web/UI/Component`, a plain Twig component with a required `mode`; `filter` now, assign comes in P03.T8): the chip, a `Dialog` titled by the mode ("Show tickets of…", sub line "Sorted by open tickets assigned to them."), `PersonPickerSearch` and Cancel. `PersonPickerSearch` reads nothing until the `person-picker` controller calls its `open` action as the dialog opens, with the person the page filters by (a controller value the parent's re-renders keep current), so the page's query count does not change. None of its props takes updates from the parent (team, project id and event are fixed per page load), so a search keystroke, "Show done" or a chip in `TicketFilters` sends the closed picker no request. It lists the team's lead and members whose first name holds the search (from the cached `TeamPeopleQueries`), most open tickets assigned to them first, then by name, at most 20 with "Showing 20 of N. Type a name to narrow the list." when more match. "N open" comes from Ticketing's `AssigneeTicketCounts` (steps outside Done, under `TicketVisibilitySql`, one grouped query): the rows the list without "Show done" shows filtered by that person in the same project (every project on "All projects", as the list), ignoring the list's search; warm, opening it runs 12 queries, 4 beyond the request's own. Each row is a `<button type="button" aria-pressed>` as the prototype's `.pick` buttons that emits `personPicked` up to `TicketFilters`; no row is a link, as the chip does not open the picker without script, so there is no page a link could serve. The rows are the anonymous component `PersonPickerRows`; the gallery shows the chip and a `Dialog` with the mode's title and sub line, a search box, three fixed `PersonPickerRows` rows and Cancel, which sends no request (a row there only closes the dialog). The project rail (`ProjectRail`, `_project_rail.html.twig`) sits outside the list frame in its own Turbo frame `project-rail-frame`: `ticket-list` loads the same URL into it after each change, and that request renders the rail alone, reading only the project and the rail's three, so its counts follow "Show done". "All projects" shows the sum of the team's project counts; each count carries hidden text ("open tickets", or "tickets" with "Show done") for screen readers. Its items and the team switch (a `<details>` of links) are links without script.

`DraftRoundEditor` has five live actions, all POSTs: `addScreenshot`, `removeScreenshot`, `editScreenshotText`, `setVerdict` (an empty verdict clears it) and `setPins` (at most 100 pins; a pin that is not `{x, y, note}` is a 400). Each saves through `RoundCommands` in the request's transaction. Every request, each action included, first finds the ticket for the person and asks `RoundPermission` VIEW and EDIT on the round; anyone else, an unknown id, another ticket's round and a draft shared or deleted by now for a person who may not edit it get the one 404. A refusal of the command (a picture that is not theirs or not ready, a text or note over its limit, too many pictures or pins, an unknown verdict) is shown in the card as `.draft-round-editor__refusal` and saves nothing. A command takes no version, so a second tab cannot make one request stale; `DRAFT_CHANGED` comes only from two requests at once. `DraftRoundEditorTest` proves each of these.

## Stimulus controllers

Every controller is small, stays under about 150 lines, and has a test (Playwright for behaviour). Names are kebab-case.

| Controller | What it does |
|---|---|
| `pin-placer` | On the editable screenshot (`EditableScreenshot`, a form, or the `div.pin-placer` inside `ScreenshotReportField`; events are wired by `data-action` on it). Pins live as percent positions. A click on the picture adds a pin at `x = (clientX - left) / width × 100` (same for y), rounded to 0.1 and limited to 0–100, and moves focus to its note field. "Add pin" puts a pin in the centre and focuses its marker. Keys on a focused marker: arrow keys move it by 1 % (Shift: 5 %), Enter moves to its note field, Delete or Backspace removes it (focus goes to the next pin, else to "Add pin"). A marker can also be dragged with the mouse or a finger (after 3 px); the click that ends a drag adds no pin. Focusing a marker or a note field selects that pin (`aria-pressed`, selected ring, note bubble). The page's own pins are read from the markers (`data-x-percent`, `data-y-percent`) and keep their two decimals until moved; the next new number is the highest one plus 1, numbers are never reused. Every change rewrites the hidden fields `pins[n][x]` and `pins[n][y]` (two decimals) in the `fields` target; the note is the textarea `pins[n][note]` (at most 1,000 code points like the server's `mb_strlen`, so the field has no `maxlength`, which counts UTF-16 units; the controller cuts the rest and says so in the status). Nothing is sent: each change fires `pin-placer:change` with `detail: { pins: [{ number, x, y, note }] }` for the form that posts the fields. The `clear()` action (called through an outlet by `screenshot-report`) removes every pin without a message and fires `pin-placer:change`; the next pin is number 1 again. Targets: `layer` (PinLayer), `list` (PinList), `fields`, `status` (`role="status"`). Values: `noteMax`, `notePlaceholder` (the hint of a new pin's note field, empty for none). The pure maths is in `assets/review/pin_position.js` (specs: `tests/e2e/pin-position.spec.ts`, run in Node), the marker and list markup in `assets/review/pin_markup.js`, the pins and their elements in `pin_board.js`, the key commands in `pin_keyboard.js` and the drag threshold in `pin_drag.js`. |
| `presigned-upload` | On the screenshot upload field (`UploadField`, in `src/Media/UI`; the gallery shows one). A person picks a file, drops one on the field or pastes one from the clipboard while the field has focus; only a drag whose `dataTransfer.types` holds `Files` is taken over (`carriesFiles` in `assets/review/upload_checks.js`), so text dragged onto a field of the form drops as usual. Once the file passes the browser checks, the field's `data-media-state` becomes `uploading` and `presigned-upload:started` fires (no detail). The browser refuses a type other than PNG, JPEG or WebP and a size of 0 or over 15 MB with the server's own plain texts, before any request (`assets/review/upload_checks.js`; `tests/Unit/Media/Application/UploadMessagesMatchBrowserTest.php` pins the texts and limits to the server's; specs in `tests/e2e/upload-checks.spec.ts`, run in Node). Then `POST /media/intents` ({ purpose, mime, bytes, target: { kind, id } }, header `X-CSRF-Token` of token id `media_upload`), a `PUT` of the file to the returned URL with the returned headers (XMLHttpRequest, for the `progress` bar), and `POST /media/intents/{id}/complete` (`assets/review/upload_requests.js`). The returned media id goes into the hidden input (`mediaId` target) and `presigned-upload:uploaded` fires with `detail: { mediaId }`, for the form that later posts it. Then it polls `GET /media/{id}/status` (URL template from the `statusUrl` value, `assets/review/upload_status.js`) once a second, at most 15 times: the field's `data-media-state` is `pending`, then `ready` with `presigned-upload:ready` (`detail: { mediaId }`); on `rejected` it shows "We could not process this image. Try again.", empties the hidden input (state `rejected`) and fires `presigned-upload:rejected` (`detail: { mediaId }`); after 15 s still pending `presigned-upload:still-processing` fires (`detail: { mediaId }`), the field shows no text of its own and the id stays: the form that listens says "Still processing your screenshot. Try again in a moment." when the server refuses a submit with a pending picture. A status poll that fails or is refused (offline, 404, 429) shows nothing and counts as pending. A new upload or leaving the page stops the polling. A local preview shows while it uploads and after. Any refusal of the upload itself (403, 404, 413, 422, 429) shows the server's `message` in the `status` live region; a failed PUT or lost connection has its own text, and the hidden input stays empty; either way `data-media-state` is removed and `presigned-upload:failed` fires (no detail). The `clear()` action drops the picture: it stops the upload or the polling, empties the hidden input, `data-media-state`, the preview and the status, and fires nothing. One upload at a time. Leaving the page mid-upload aborts the requests. The pipeline behind it: [media-pipeline.md](media-pipeline.md). Targets: `input`, `status` (`role="status"`), `progress`, `preview`, `mediaId`. Values: `url`, `csrfToken`, `purpose`, `targetKind`, `targetId`, `statusUrl`. |
| `screenshot-report` | On the Screenshot section of a report form (`ScreenshotReportField` in `src/Review/UI`, inside the new ticket form; P06.T1 reuses it), on the same element as `presigned-upload`, with a `pin-placer` outlet (the section's `div.pin-placer`). On `presigned-upload:started` it shows the picture area and the Remove screenshot button, hides the empty box and removes every pin (a new picture starts with none); on `presigned-upload:failed`, `presigned-upload:rejected` and Remove screenshot (which also runs `presigned-upload#clear`) it removes every pin and hides the area again, and Remove moves focus to the file input. The title and summary fields keep their text. On `pin-placer:change` it rewrites the line under the pins (`assets/review/pin_count.js`, the same texts as the component; `tests/Unit/Review/UI/Component/PinCountTextMatchesBrowserTest.php`, specs in `tests/e2e/pin-count.spec.ts`, run in Node). While `data-media-state` is `uploading` or `pending` it holds the surrounding form's submit and says "Waiting for your screenshot to be ready…" (`assets/review/submit_hold.js`, specs in `tests/e2e/submit-hold.spec.ts`, run in Node), so a submit pressed mid-upload never posts an empty `screenshot_media_id`; `presigned-upload:ready` sends the held submit, `presigned-upload:still-processing` sends it too and lets later submits through, so the server refuses a still-pending picture with its own text; a removed, failed or rejected picture drops the held submit. When a failed or rejected upload hides the picture area while focus is in it or on Remove screenshot, focus moves to the file input. A page re-rendered after a refusal with a pending picture shows "Processing…" and holds nothing, as nothing polls it. Targets: `picture`, `empty`, `remove`, `count`, `frame`, `image`, `placeholder`, `file`, `status`. Outlet: `pin-placer`. |
| `pin-walker` | On the full-screen review (`@Review/focus.html.twig`, `GET /t/{key}/rounds/{number}/focus`): one pin at a time. The server renders every pin's bar and shows the one `?pin=` names; Previous and Next are links and the verdicts plain forms (a 303 back to the page with its toast), so the walk works without JavaScript. The controller switches bars in place, moves the selected ring (`pin--selected`, `aria-pressed`), picks a pin on a click of its ring, walks with ← / → (wrapping at both ends, not in a text field or with a modifier key) and sends Escape to the Exit link. A control that had focus hands it to its twin in the new bar (`data-pin-walker-control`); the address keeps the shown pin with `history.replaceState`. Targets: `stop` (one bar, `data-pin-number`, `data-pin-position`), `exit`. Value: `parameter` (the query name of the pin). |
| `person-picker` | Inside a `dialog` controller (which opens, traps focus, closes on Escape and returns focus to the chip): asks `PersonPickerSearch` for its rows when the dialog opens, focuses the search field, and moves between the rows with the arrow keys, Home and End |
| `ticket-list` | The tickets list page: loads the URL `TicketFilters` sends into the list frame, the rail's frame and the address bar |
| `focus-chips` | Shows and hides Home sections without a reload and saves the choice |
| `local-ai` | Laptop AI ([ADR-0019](../03-adr/0019-browser-local-ai.md)): on the user's click it checks `GET http://localhost:11434/api/tags`; it fetches a prompt package from the server; it calls `POST /api/generate` (non-streaming, `format: "json"` when JSON is expected); it posts the result back. It never runs on page load (browsers may show a local-network permission prompt). |
| `summary-poll` | `assets/controllers/summary-poll_controller.js`, on `.ticket__ask-state` inside TheAsk's `ticket-ask` frame: while its `state` value is `running` its `FramePoll` fetches the `url` value (`GET /t/{key}/summary`, with the `Turbo-Frame` header) every 3 s, at most 60 times per connect, and replaces the frame's children when the answer's state is no longer running; a `done` answer first puts the `savedToast` value ("Summary saved for everyone.") in the toast region. A failed or refused poll counts as running. It stops on disconnect and while the page is hidden. After the last poll it turns the `button` target back on with the `readyLabel` value. During a press it sets `aria-busy` on the submitter (Turbo writes "Summarizing…"). Specs: `tests/e2e/summary-poll.spec.ts` |
| `intake-check` | `assets/controllers/intake-check_controller.js`, on `.intake-check__state` inside IntakeCheck's `intake-check` frame in the New ticket form. "Check with AI" is a `type="button"` (Enter in a field still makes the ticket) whose `check` action posts the whole form as `FormData` to the `requestUrl` value (`POST /tickets/new/check`, header `Turbo-Frame: intake-check`), shows the `runningLabel` value ("Thinking…") meanwhile, and puts the answer's frame in, a 4xx refusal's frame with its line too; an answer with no frame (offline, an error page) shows the `unansweredLine` value and gives the button back with the `readyLabel` value. The `button` targets show only while the title is not empty, followed as the person types. While the `state` value is `running` its `FramePoll` fetches the `url` value (`GET /tickets/new/check/{checkId}`) as `summary-poll` does and swaps in the done or failed frame; after the last poll it gives the button back. A done state puts "✦" (with the `markLabel` value, "AI suggestion", for screen readers) after the Type and Priority labels of its `type` and `priority` values and selects each, unless the person changed that radio group themselves (a trusted `change`, kept on the form in `data-intake-check-person-picked`); a running state removes the marks. It touches no other field. Specs: `tests/e2e/check-with-ai.spec.ts` |
| `menu` | The "⋯" menus, the avatar menu and the bell popover: open/close, Escape, click outside, roving focus (the avatar menu is a disclosure and keeps its items in the Tab order) |
| `theme-switch` | The avatar menu's System / Light / Dark switch: sets or removes `data-theme` on `<html>` at once and posts the choice to `/me/theme` |
| `dialog` | Opens a `<dialog>`, keeps focus inside, gives focus back on close |
| `project-picker` | The Team page's "Add a project" dialog: filters the unlinked projects by key or name, and keeps the submit button's label ("Add N projects") and disabled state in step with the checked boxes |
| `waiting-on` | `assets/controllers/waiting-on_controller.js`: in the Needs input dialog of the "Your next step" box, writes the chosen person into the hidden `waiting_on_user_id` (or the "Someone else" text into `waiting_on_label`) of the chip's form (`[data-waiting-on-target="moveForm"]`) and calls `requestSubmit`, so the move gets the usual Turbo Stream answer; its `chooseWhoItWaitsOn` action, on each change of choice, enables the label field only while "Someone else" is chosen. Without it the chip's form posts with empty fields and the ticket waits on its reporter |
| `clipboard` | "Copy link" |
| `queue` | Keyboard shortcuts in a queue: J/K for next/previous (phase 12 adds more shortcuts) |
| `autosize` | Grows textareas with their content |

The AI frame poll is shared, not copied: `assets/frame_poll.js` holds `FramePoll` (every 3 s while the state element's state attribute says `running`, at most 60 polls per connect, none while the page is hidden, a failed or refused poll counted as running, each handled answer counted in the frame's `data-answered-polls`), `swapFrameChildren` (puts a fresh frame's children in and moves the focus to its `[autofocus]` only when the focus was inside the frame) and `frameOfAnswer`. `summary-poll` and `intake-check` use it.

## Turbo and real-time updates

- **Navigation:** Turbo is installed (`symfony/ux-turbo`) but Drive is off app-wide (`Turbo.session.drive = false` in `assets/app.js`), so links are full page loads and only frames (the tickets list's "Show more" and its list frame, which `TicketFilters` reloads) use Turbo; ADR-0007 stays the target, and a change turns Drive on together with an e2e sweep. Pages set `<meta name="turbo-cache-control" content="no-cache">` where data must be fresh (Home, ticket page).
- **Frames:**
  - the ticket list results;
  - the ticket page's action box, thread and rounds;
  - the Home sections;
  - the admin tab bodies.
- **POST answers:** a successful action answers with a `text/vnd.turbo-stream.html` response that replaces the changed frames and shows a toast. A non-Turbo form gets a normal redirect, so everything works without JavaScript except pins, uploads and local AI. With Drive off, a form opts in with `data-turbo="true"`. The ticket moves are the first: their stream replaces `#ticket-state` (the step pill, Now and Next, the group line) and `#ticket-next-step` (the Your next step box) from `TicketPageParts`, and puts the toast into `#toast-region` (`@Web/_toast_stream.html.twig`, the same empty toast the `toast` controller fills, so it is announced); a refused or stale move answers 422 with the same stream, showing the ticket as it is now. Any other answer to a move is not swapped in (`assets/app.js`): a redirect (the login page after the session expired) is followed with a real page load, and anything else (a plain 403, 404 or 500) reloads the page. Before that reload, a form that carries `data-failed-toast` leaves its text in `sessionStorage`, which the next page load takes once and shows as that same empty toast in `#toast-region`; a form without it reloads with no toast. Each move form in `NextStepBox` (the verbs, the move-list chips and the Needs input dialog's form) carries `MoveToasts::MOVE_FAILED`, "Could not move the ticket. Try again."
- **Push:** pages subscribe to Mercure topics through Symfony UX Turbo's Mercure support (verify the Twig helper name at implementation). The server publishes Turbo Streams per user, so a page never receives data the user may not see ([ADR-0023](../03-adr/0023-realtime-mercure.md)).

## Design tokens and theming

The colours are Material 3 tones generated from **one seed, #2A6F65**. They are defined once as CSS custom properties in `assets/styles/tokens.css`, the same names the prototype uses.

| Token | Light | Dark |
|---|---|---|
| `--primary` / `--on-primary` | #006b5f / #ffffff | #82d5c7 / #003731 |
| `--primary-container` / `--on-primary-container` | #9ef2e3 / #005047 | #005047 / #9ef2e3 |
| `--secondary-container` / `--on-secondary-container` | #cce8e2 / #334b47 | #334b47 / #cce8e2 |
| `--surface` | #f4fbf8 | #0e1513 |
| `--s-lowest` `--s-low` `--s-c` `--s-high` `--s-highest` | #ffffff #eff5f2 #e9efec #e3eae7 #dde4e1 | #090f0e #161d1b #1a211f #252b2a #303634 |
| `--on-surface` / `--on-surface-var` | #161d1b / #3f4946 | #dde4e1 / #bec9c5 |
| `--outline` / `--outline-var` | #6f7976 / #bec9c5 | #899390 / #3f4946 |
| `--error` / `--error-container` / `--on-error-container` | #ba1a1a / #ffdad6 / #93000a | #ffb4ab / #93000a / #ffdad6 |
| `--on-error` | #ffffff | #690005 |
| `--passed` / `--passed-container` / `--on-passed-container` | #006c51 / #83f8cd / #002116 | #66dbb2 / #00513c / #83f8cd |
| `--waiting` / `--waiting-container` / `--on-waiting-container` | #735c00 / #ffe088 / #241a00 | #ebc23e / #574500 / #ffe088 |
| `--ping` / `--ping-container` / `--on-ping-container` | #0062a0 / #d0e4ff / #001d34 | #9bcbff / #004a7a / #d0e4ff |
| `--ai` / `--ai-container` / `--on-ai-container` | #4f51be / #e1e0ff / #06006c | #c0c1ff / #3637a4 / #e1e0ff |

- **`--on-error` goes beyond the prototype.** It gives text enough contrast on the dark-mode `--error` colour. The badge uses it.
- **Group colours:** fixed tokens `--group-todo` … `--group-done` (see [workflow-engine.md](workflow-engine.md#five-groups-and-core-steps)).
- **Fonts:** Atkinson Hyperlegible Next (text) and Atkinson Hyperlegible Mono (keys, code). Both are self-hosted as WOFF2 with `font-display: swap`; there are no font CDNs. Base size 15 px, line height 1.5.
- **Shape and space:**
  - radius tokens: 6 (tag), 10 (chip), 12, 16 (card), 20 (button), 24 (dialog);
  - spacing scale: 4, 6, 8, 10, 12, 16, 20, 24, 28, 32.
- **Dark mode:** follows `prefers-color-scheme`, with a user override (Light / Dark / System) stored on the user. The `data-theme` attribute on `<html>` wins over the media query.
- **Not Jira:** no dense card boards, no status jargon, no colour-only signals. Each screen has one primary (filled) button.

## Accessibility

We build to WCAG 2.2 AA ([non-functional-requirements.md](../01-requirements/non-functional-requirements.md)).

- **Real elements:** buttons are `<button>`, links are `<a>`, headings are in order, and every page has one `<h1>`.
- **Pins:**
  - a pin on a screenshot is a `<button>` with `aria-label="Pin 2: Total looks doubled"`;
  - every screenshot has a `PinList` below it with the same content as text;
  - placing a pin works with the keyboard (`pin-placer`).
- **Colour is never the only signal:** groups and verdicts always carry text ("Waiting", "Not right yet").
- **Contrast:** text ≥ 4.5:1 and UI parts ≥ 3:1 in both themes. The token table above is checked in CI with a small contrast script.
- **Target size:** at least 24 × 24 px (2.5.8). Chips and buttons are 30–40 px high.
- **Focus:** always visible (`:focus-visible` outline in `--primary`). Never hidden behind the sticky header (2.4.11).
- **Motion:** `prefers-reduced-motion` turns off transitions.
- **Live regions:** toasts use `role="status"`; new pushed items announce politely.
- **Layout:** works at 360 px wide and at 200 % zoom.
- **Automated checks:** axe runs in the Playwright tests on every main page ([testing-strategy.md](testing-strategy.md)).

## Performance budgets

| Budget | Limit |
|---|---|
| JS per page (gzip) | ≤ 120 KB, including Turbo, Stimulus and Live Components |
| CSS (gzip) | ≤ 40 KB |
| Fonts | 2 WOFF2 files preloaded; subsets ≤ 60 KB each |
| LCP (mid laptop, warm cache) | ≤ 2.0 s |
| INP | ≤ 200 ms |
| CLS | ≤ 0.05; screenshots always render with width/height or an aspect ratio |
| Screenshot in lists | thumbnail WebP only (≤ 40 KB); full image only in a round view |

Rules:

- No N+1 queries in templates. Controllers pass ready DTOs, and Twig never triggers lazy loading. A test fails on lazy loads.
- Images use `loading="lazy"` below the fold and `decoding="async"`.
- A Lighthouse CI run on the main pages fails the build when a budget is broken (phase 12).

## Frontend security

- **CSP with nonces:**
  - `default-src 'self'`;
  - `script-src 'self' 'nonce-…'`;
  - `connect-src 'self'` plus the Mercure hub, the storage host and `http://localhost:11434` (only when the org's AI runs on laptops);
  - `img-src 'self'` plus the storage host and `data:` (only for tiny placeholders);
  - `frame-ancestors 'none'`.
  - There are no inline event handlers. Every script tag gets the request's nonce from `csp_nonce()`: `<script nonce="{{ csp_nonce() }}">`, and for the import map `{{ importmap('app', {nonce: csp_nonce()}) }}`, which puts it on the importmap JSON and the entry module script.
- **CSRF:** a token on every POST form and Live Component action (Symfony's CSRF protection, SameSite=Lax cookies).
- **Escaping:** Twig auto-escaping is always on. User text is never rendered with `|raw`. Comments and descriptions support a small Markdown subset that is rendered and then sanitized with the HTML Sanitizer component on the server.
- **External links** open with `rel="noopener noreferrer"`.
- More in [security.md](security.md).

## Route map: prototype screen → route → template

The prototype's single page becomes these routes. The `Module` column says who owns the controller. Exact template paths follow the module layout in [modules.md](modules.md).

| Prototype screen | Route (name) | Module | Template / main components |
|---|---|---|---|
| Home (lead, tester, developer, manager, admin, mixed roles) | `GET /` (`web_home`) | Web | `home/index.html.twig`: FocusChips, NeedItem ×5, GroupedList, PingCard, release GroupBar, AvatarStack, Admin card, All teams |
| Tickets · List | `GET /tickets?project=&team=&person=&q=&done=&check=` (`ticketing_tickets`) | Ticketing | `@Ticketing/list.html.twig` + `TicketFilters`; the List / Whose turn? SegmentedControl as two links that keep only `project` and `team` |
| Tickets · Whose turn? | `GET /tickets/turn?project=&team=&open=&after=` (`ticketing_whose_turn`) | Ticketing (reads Insight's `HomeQueries::whoseTurn`) | `@Ticketing/whose_turn.html.twig`: the same List / Whose turn? links, Avatar, `<progress>` meter, EmptyState; the viewer's card and 20 others, "Next page" on to `after=` and, on a later page, "Back to the start" without it, each card's "Show N more" / "Show fewer" landing on its id |
| Ticket page | `GET /t/{key}` (`ticket_show`) | Ticketing | `@Ticketing/show.html.twig`: QueueBar, StatusPill, NextStepBox, Thread, rounds, people, activity |
| Verb buttons | `POST /t/{key}/move/{verb}` (`ticket_move`) | Ticketing | Turbo Stream |
| Check box actions | `POST /t/{key}/check/{action}` (`ticket_check`) | Ticketing | Turbo Stream |
| Comment / question / reply / resolve | `POST /t/{key}/thread…` | Conversation | Turbo Stream |
| Ping dialog | `POST /t/{key}/ping/{userId}` | Notification | Dialog |
| Explain it to me / summarize | `POST /t/{key}/ai/explain`; the summary: `GET /t/{key}/summary` (`assist_summary_frame`), `GET /t/{key}/summary/earlier` (`assist_summary_earlier`), `POST /t/{key}/summary` (`assist_summary_request`) | Assist | `ExplainBox`; `TheAsk` with the `summary-poll` controller |
| Report a problem | `GET/POST /report` (`report_new`) | Ticketing | `ReportForm` |
| My reports | `GET /my-reports` (`my_reports`) | Insight | Tracker cards grouped Needs you / Stuck / Moving / Done |
| Round view (prototype "Review round" board) | `GET /t/{key}/rounds/{number}` | Review | Screenshot + PinLayer, before/after, verdicts |
| Full-screen review (prototype "Review in full screen" board) | `GET /t/{key}/rounds/{number}/focus` | Review | Full-screen pin walker |
| Draft round | part of the ticket page's Rounds frame (`GET /t/{key}/rounds`, `review_rounds`); `POST /t/{key}/rounds/draft` (`review_draft_start`), `POST /t/{key}/rounds/{id}/share` (`review_draft_share`), `POST /t/{key}/rounds/{id}/delete` (`review_draft_delete`), `DraftRoundController` | Review | the owner's draft card in `@Review/rounds_frame.html.twig` (PRIVATE tag, buttons, "For Claude Code" box); forms leave the frame (`_top`), a change redirects to `ticket_show#ticket-rounds` with its toast, a refusal is a 422 toast stream; the owner's card holds `DraftRoundEditor` (see [Live Components](#live-components)) |
| Team page | `GET /teams/{slug}` (`team_show`) | Web (reads Organization, Ticketing and Workflow) | Projects, "How work moves here", people with WorkloadBar |
| Admin tabs | `GET /admin/{tab}` (`web_admin`), tab ∈ people, teams, statuses, ai, pings, new-tickets, connections, activity, data; the menu's Admin item opens people | Web (+ owning module per tab) | Admin layout with tab list |
| Manager "Teams at a glance" | part of `/` for managers | Insight | GroupBar per team |
| Local AI sheet | dialog from the header chip; `POST /ai/laptop/status`, `POST /ai/laptop/package`, `POST /ai/laptop/result` | Assist | `local-ai` controller |
| Profile (name, time zone, theme) | `GET/POST /me/profile` (`identity_profile`); the avatar menu's theme switch `POST /me/theme` (`identity_theme`) | Identity | `profile.html.twig` |
| Personal access tokens | `GET/POST /me/tokens` | Identity | for MCP and the plugin |
| Security (password, two-factor) | `GET /me/security` (`identity_security`), `POST /me/security/password`, `POST /me/security/log-out-everywhere` | Identity | |
| Login, SSO callback | `/login`, `/login/sso/{provider}/callback` | Identity | |
| Invite accept | `GET /invite/{token}` (`organization_invitation_token`) keeps the token in the session and redirects to `GET/POST /invite` (`organization_invitation`), the page itself | Organization | `invitation.html.twig` (`@Organization`), the login card |
| Organization picker | `GET/POST /org/choose` (`organization_choose`); a logged-in user without a chosen organization is sent here, and the avatar menu links it as "Switch organization" for users in two or more (not on the picker itself) | Organization | `choose.html.twig` (`@Organization`), the login card |
| MCP endpoint | `POST /mcp` | AgentApi | no HTML |
| REST for the plugin | `/api/v1/…` | AgentApi | no HTML |
