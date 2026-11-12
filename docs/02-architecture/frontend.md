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
| `Button` (filled, tonal, outline, text; sizes md/sm) | `.btn-*` | Always a `<button>` or `<a>`; never a `div` |
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
| `Thread`, `Message`, `Reply`, `Composer` | ticket conversation | Comment / Question switch; answering a question resolves it |
| `Dialog` | dialogs (ping, picker, share draft, local AI) | Native `<dialog>` with focus trap |
| `Toast` | toast | One at a time, 3.6 s, `role="status"`. A flashed toast arrives empty with its text in `data-toast-message-value` and `data-turbo-temporary` (no re-show on Back); the `toast` controller writes the text in 150 ms after connect, so the region announces it, and removes the toast 3.6 s later. Without JavaScript a `<noscript>` copy shows and stays. The gallery's toast has no controller and stays |
| `Switch` | admin switches | `role="switch"`, `aria-checked` |
| `EmptyState` | dashed boxes | One plain sentence + one action |

## Live Components

Live Components re-render on the server as the user types or clicks. Use them only where the server must recompute something:

| Live component | Why live | Debounce |
|---|---|---|
| `TicketFilters` (search, people chips, project, Show done, "New · to check") | Updates the list frame and the counts | 300 ms on search |
| `ReportForm` (Report a problem) | Live duplicate suggestions, the "When you send:" preview sentence, check reasons, AI ✦ suggestions | 400 ms on title/text |
| `DraftRoundEditor` | Verdicts, notes, pins, share/delete; each change is saved at once | notes: on blur |
| `PersonPickerSearch` | Searches big teams (2,000 people) on the server | 200 ms |
| `ExplainBox` | Sends the private "Explain it to me" request and shows progress | — |

Everything else is plain Twig + Turbo: links, forms that POST and answer with a Turbo Stream.

`TicketFilters` (`src/Ticketing/UI/Component`, `symfony/ux-live-component` 3.7) is built for the search, the "Assigned to" chips and "Show done"; project and "New · to check" come with the rail and the check filter. Its live requests are POSTs, so the search words never sit in a component URL, and each first asks `OrganizationPermission::VIEW` on the request's organization. After each change it sends the browser event `ticket-filters:changed` with the list's URL (`?project=&person=&done=&q=`, `q` trimmed); the `ticket-list` controller puts that URL in the address bar (a chip adds a history entry, typing replaces it; Back reloads the page, as Drive is off) and loads it into the `ticket-list-frame` Turbo frame, which holds the title, the count line and the steps with their own "Show more" frames. Without script every chip is a link to that URL and the search is a GET form. The people are the lead and members of the page's team, the same one the project rail shows (the picked project's team when the viewer may see it, `TeamPermission::VIEW`; else `?team=`, one of the viewer's own, else the viewer's first team by id; viewers are no assignees): `Me` for a lead or member of it, each other one's first name while the team has at most 4, else a disabled `Person ▾` chip (`data-person-picker-trigger`) that P03.T5's person picker opens. The chips cost the page two queries beside the list's five. The project rail (`ProjectRail`, `_project_rail.html.twig`) sits outside the list frame in its own Turbo frame `project-rail-frame`: `ticket-list` loads the same URL into it after each change, and that request renders the rail alone, reading only the project and the rail's three, so its counts follow "Show done". "All projects" shows the sum of the team's project counts; each count carries hidden text ("open tickets", or "tickets" with "Show done") for screen readers. Its items and the team switch (a `<details>` of links) are links without script.

## Stimulus controllers

Every controller is small, stays under about 150 lines, and has a test (Playwright for behaviour). Names are kebab-case.

| Controller | What it does |
|---|---|
| `pin-placer` | On a screenshot: a click adds a pin at `x_pct = (clientX - left) / width × 100` (same for y), rounded to 0.1 and limited to 0–100. Keyboard: "Add pin" puts a pin in the centre; arrow keys move it by 1 % (Shift: 5 %); Enter confirms. It sends the pin to the Live Component or posts it. |
| `presigned-upload` | Asks the server for an upload slot, PUTs the file straight to storage with progress, then calls "complete". It checks type and size in the browser first (server checks again). Paste from clipboard (Ctrl+V) is supported ([media-pipeline.md](media-pipeline.md)). |
| `person-picker` | Opens the dialog, keyboard navigation in the list, returns the chosen id |
| `ticket-list` | The tickets list page: loads the URL `TicketFilters` sends into the list frame, the rail's frame and the address bar |
| `focus-chips` | Shows and hides Home sections without a reload and saves the choice |
| `local-ai` | Laptop AI ([ADR-0019](../03-adr/0019-browser-local-ai.md)): on the user's click it checks `GET http://localhost:11434/api/tags`; it fetches a prompt package from the server; it calls `POST /api/generate` (non-streaming, `format: "json"` when JSON is expected); it posts the result back. It never runs on page load (browsers may show a local-network permission prompt). |
| `menu` | The "⋯" menus, the avatar menu and the bell popover: open/close, Escape, click outside, roving focus (the avatar menu is a disclosure and keeps its items in the Tab order) |
| `theme-switch` | The avatar menu's System / Light / Dark switch: sets or removes `data-theme` on `<html>` at once and posts the choice to `/me/theme` |
| `dialog` | Opens a `<dialog>`, keeps focus inside, gives focus back on close |
| `project-picker` | The Team page's "Add a project" dialog: filters the unlinked projects by key or name, and keeps the submit button's label ("Add N projects") and disabled state in step with the checked boxes |
| `clipboard` | "Copy link" |
| `queue` | Keyboard shortcuts in a queue: J/K for next/previous (phase 12 adds more shortcuts) |
| `autosize` | Grows textareas with their content |

## Turbo and real-time updates

- **Navigation:** Turbo is installed (`symfony/ux-turbo`) but Drive is off app-wide (`Turbo.session.drive = false` in `assets/app.js`), so links are full page loads and only frames (the tickets list's "Show more" and its list frame, which `TicketFilters` reloads) use Turbo; ADR-0007 stays the target, and a change turns Drive on together with an e2e sweep. Pages set `<meta name="turbo-cache-control" content="no-cache">` where data must be fresh (Home, ticket page).
- **Frames:**
  - the ticket list results;
  - the ticket page's action box, thread and rounds;
  - the Home sections;
  - the admin tab bodies.
- **POST answers:** a successful action answers with a `text/vnd.turbo-stream.html` response that replaces the changed frames and shows a toast. A non-Turbo form gets a normal redirect, so everything works without JavaScript except pins, uploads and local AI.
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
| Tickets · List | `GET /tickets?project=&team=&person=&q=&done=&check=` (`ticketing_tickets`) | Ticketing | `ticket/list.html.twig` + `TicketFilters` |
| Tickets · Whose turn? | `GET /tickets/turn?project=` (`ticket_turn`) | Insight | `insight/whose_turn.html.twig` |
| Ticket page | `GET /t/{key}` (`ticket_show`) | Ticketing | `ticket/show.html.twig`: QueueBar, StatusPill, NextStepBox, Thread, rounds, people, activity |
| Verb buttons | `POST /t/{key}/move/{verb}` (`ticket_move`) | Ticketing | Turbo Stream |
| Check box actions | `POST /t/{key}/check/{action}` (`ticket_check`) | Ticketing | Turbo Stream |
| Comment / question / reply / resolve | `POST /t/{key}/thread…` | Conversation | Turbo Stream |
| Ping dialog | `POST /t/{key}/ping/{userId}` | Notification | Dialog |
| Explain it to me / summarize | `POST /t/{key}/ai/explain`, `POST /t/{key}/ai/summary` | Assist | `ExplainBox` |
| Report a problem | `GET/POST /report` (`report_new`) | Ticketing | `ReportForm` |
| My reports | `GET /my-reports` (`my_reports`) | Insight | Tracker cards grouped Needs you / Stuck / Moving / Done |
| Round view (prototype "Review round" board) | `GET /t/{key}/rounds/{number}` | Review | Screenshot + PinLayer, before/after, verdicts |
| Full-screen review (prototype "Review in full screen" board) | `GET /t/{key}/rounds/{number}/focus` | Review | Full-screen pin walker |
| Draft round | part of the ticket page; `POST /t/{key}/draft…` | Review | `DraftRoundEditor` |
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
