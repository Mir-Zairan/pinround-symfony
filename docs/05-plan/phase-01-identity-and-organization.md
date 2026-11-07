# Phase 01 — Identity and organization

Goal: people can log in safely, belong to organizations and teams with the right roles, and see a navigation and Home shell built from those roles.

## Index
- [Before you start](#before-you-start) — prototype screens, requirements, ADRs, docs
- [Outcome](#outcome) — what works after this phase
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — FR and NFR ids
- [Design notes](#design-notes) — modules, tables, routes, roles model
- [Tasks](#tasks) — P01.T1 to P01.T7
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — notes for P02

## Before you start
1. **Prototype.** Open https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2 (Artifact tool, `action: "read"`, `path: "project/Main.dc.html"`). Use the "view as" switch to compare Lead, Tester, Developer, Manager, Admin and "Lead + Admin" (this switch is prototype-only). Study the header, nav and Home top. Fallback docs: [Global layout](../04-prototype/prototype-guide.md#global-layout), [Home: header block and focus chips](../04-prototype/screens/home-work.md#header-block-and-focus-chips), [Admin home](../04-prototype/screens/home-admin.md), [Admin: people and roles](../04-prototype/screens/admin.md#people-and-roles), [Admin: teams](../04-prototype/screens/admin.md#teams).
2. **Requirements:** [FR-ID-01, FR-ID-02, FR-ID-03, FR-ID-04, FR-ID-05, FR-ID-06, FR-ID-07, FR-ID-08](../01-requirements/functional-requirements.md#id--identity-and-access) (FR-ID-05 tokens is built in P09), [FR-ORG-01, FR-ORG-02, FR-ORG-03, FR-ORG-04, FR-ORG-05, FR-ORG-09](../01-requirements/functional-requirements.md#org--organization-teams-and-roles), [permission matrix](../00-product/personas-and-roles.md#permission-matrix), [multi-role behaviour](../00-product/personas-and-roles.md#multi-role-behaviour), [NFR-SEC](../01-requirements/non-functional-requirements.md#sec--security) NFR-SEC-03, NFR-SEC-04, NFR-SEC-05, NFR-SEC-08, NFR-SEC-14.
3. **ADRs:** [0012 authentication](../03-adr/0012-authentication.md), [0013 authorization](../03-adr/0013-authorization.md), [0005 multi-tenancy](../03-adr/0005-multi-tenancy.md), [0010 sessions in Valkey](../03-adr/0010-cache-sessions-locks.md), [0027 secrets and encryption](../03-adr/0027-secrets-and-encryption.md).
4. **Architecture docs:** [Identity module](../02-architecture/modules.md#identity-idn_), [Organization module](../02-architecture/modules.md#organization-org_), [tables: Identity](../02-architecture/data-model.md#identity-global), [tables: Organization](../02-architecture/data-model.md#organization), [authorization model](../02-architecture/security.md#authorization-model), [tenant isolation](../02-architecture/security.md#tenant-isolation), [layout and navigation](../02-architecture/frontend.md#layout-and-navigation).
5. **Previous phase:** check the [P00 Definition of done](phase-00-foundation.md#definition-of-done).

## Outcome
Sara, the admin of Acme Foods, invites Omar. Omar sets a password and logs in. He sees Home, Tickets, My reports and Team in the menu. Lena is lead of the Shop team and also an admin: her Home has focus chips "All · My work · My team · Admin", and the Admin menu item. Sara must turn on two-step login before she can open admin pages. A wrong password shows one neutral message, and five fast wrong tries slow the attacker down.

## Scope
- Users, credentials (Argon2id), login, logout, sessions in Valkey, throttling, password reset, profile (name, time zone, theme).
- Organizations, org picker after login, memberships with org roles (Admin, Manager), teams with one lead, team members with team role and job.
- Invitations by email (single use, 72 h).
- TOTP two-step login, required for admins on admin pages.
- Role resolution, base voters, deny by default.
- App shell: menu from roles, header, avatar menu, Home shell with focus chips and empty sections.
- `app:demo:seed` with neutral data (Acme Foods, Shop team, Lena, Omar, Mia, Raj, Sara).

## Out of scope
- Personal access tokens (P09), SSO (P10), the full admin pages (P11), Home content (P07), Team page content (P02/P03).
- The Activity log UI, filters, export and retention (P11). This phase writes audit events through `Audit\Api\AuditLog` into the minimal `aud_event` table from P00.T7.

## Requirements covered
FR-ID-01, FR-ID-02, FR-ID-03, FR-ID-06, FR-ID-07, FR-ID-08, FR-ORG-01, FR-ORG-02, FR-ORG-03, FR-ORG-04, FR-ORG-05, FR-ORG-09 (default triager), FR-HOME-08 (chips shell only), NFR-SEC-03, NFR-SEC-04, NFR-SEC-05, NFR-SEC-08, NFR-SEC-14.

## Design notes
- **Identity tables** are global (no `organization_id`): `idn_user`, `idn_credential`, `idn_mfa_secret`, `idn_login_attempt`. A user can belong to many orgs.
- **Organization tables** are tenant tables with RLS: `org_organization`, `org_membership` (`org_roles text[]`), `org_team` (`lead_user_id`), `org_team_member` (`team_role`, `job`), `org_invitation`, `org_settings` (`triager_id` default = first admin).
- **Roles model:** org roles Admin and Manager are additive and company-wide; team roles Lead, Member, Viewer are per team; jobs (tester, developer, other) are not permissions ([role model](../00-product/personas-and-roles.md#role-model)).
- **Authorization:** `ActorContext` (user id, org id, org roles, team roles map) is built once per request from `MembershipQueries::rolesOf` and cached for the request only. Voters deny by default.
- **Routes:** `/login`, `/logout`, `/password/forgot`, `/password/reset/{token}`, `/invite/{token}`, `/org/choose`, `/me/profile`, `/me/security`, `/` (Home shell).
- **Sessions:** Valkey (`valkey-state`), cookie `Secure`, `HttpOnly`, `SameSite=Lax`; ID rotates at login and on role change; idle timeout and absolute lifetime per [ADR-0012](../03-adr/0012-authentication.md): idle 8 h, absolute 30 days, admin pages ask for MFA again after 1 h.
- **Security:** CSRF on every form; login throttling with RateLimiter keyed by account and by IP; neutral error text; TOTP secret encrypted with libsodium (key id) ([ADR-0027](../03-adr/0027-secrets-and-encryption.md)).

## Tasks

### P01.T1 — Users and password login 🏁
**Why:** nothing else works without a safe login ([FR-ID-01](../01-requirements/functional-requirements.md#id--identity-and-access)).
**Steps:**
1. Identity domain: `User`, `Email` (lower-case, citext), `PasswordHash`; repository; Doctrine XML mapping.
2. Migration for `idn_user`, `idn_credential`, `idn_login_attempt`.
3. Symfony Security: form login, Argon2id hasher, user provider, logout (POST with CSRF).
4. Login throttling ([ADR-0012](../03-adr/0012-authentication.md)): 5 failed tries per 15 min per email (lowercase) and IP together, plus 50 failed tries per hour per IP. Each try is counted before the password check; a correct password gives the per-IP try back, so only wrong tries fill the 50. The neutral error is "Email or password is wrong".
5. Sessions in `valkey-state`; ID rotation at login; logged-in users opening `/login` go to Home.
6. Login page with the prototype look (centered card, logo, two fields, filled button "Log in").
**Files:** `src/Identity/**`, `config/packages/security.yaml`, `config/packages/rate_limiter.yaml`, `config/packages/lock.yaml`, `migrations/`, `src/SharedKernel/Infrastructure/Console/*` (the demo seed step port), `src/Web/UI/templates/base.html.twig` (header user name and logout), `assets/styles/login.css`, `tests/e2e/`.
**Acceptance criteria:**
- [x] Correct login lands on `/`; wrong password and unknown email show the same message and take similar time (`LoginTest::theRightPasswordLandsOnHomeWhereTheHeaderShowsTheUsersInitials`, `::aWrongPasswordAndAnUnknownEmailShowTheSameMessageAndKeepTheEmail`, `UnknownEmailPasswordVerifierTest::anUnknownEmailVerifiesTheTypedPasswordAgainstAThrowawayHashThenStillFails`).
- [x] The 6th try within 15 minutes is blocked with "Too many tries. Wait 15 minutes." (`LoginTest::theSixthTryWithinFifteenMinutesIsRefusedEvenWithTheRightPassword`, `::fiftyFailedTriesFromOneIpRefuseAnyEmailForTheRestOfTheHour`, `LoginRateLimiterTest::aBurstOfTenTriesGetsAtMostFivePasswordChecks`).
- [x] Session cookie has `Secure`, `HttpOnly`, `SameSite=Lax`; the session id changes after login (`LoginTest::theSessionCookieIsAHostOnlySecureHttpOnlyLaxCookie`, `::theSessionIdChangesAtLogin`).
- [x] Passwords shorter than the ADR minimum are refused (`NewPasswordTest::itRefusesElevenCharacters`, `::itAcceptsTwelveCharactersWithoutCompositionRules`).
**Tests:** functional login/logout/throttle tests; unit tests for `Email`; a test that the hash algorithm is Argon2id.
**Prototype check:** the header after login matches the Main board header (logo, menu position, avatar initials).
**Size:** M

### P01.T2 — Password reset, profile and session control
**Why:** people forget passwords and need to set their time zone (quiet hours use it) ([FR-ID-06](../01-requirements/functional-requirements.md#id--identity-and-access), FR-ID-07, FR-ID-08).
**Steps:**
1. "Forgot password": email with a single-use link valid 1 hour; the response never says whether the email exists.
2. Reset form; all other sessions end after a reset.
3. `/me/profile`: display name, time zone (IANA list), theme (system / light / dark, stored and applied with `data-theme`).
4. `/me/security`: change password, "Log out of all devices".
5. Session limits per [ADR-0012](../03-adr/0012-authentication.md): idle timeout 8 hours, absolute lifetime 30 days; admin pages ask for MFA again after 1 hour (step-up, FR-ID-06).
**Files:** `src/Identity/UI/Controller/*`, templates, mailer templates (plain text and HTML, no secrets in URLs except the token).
**Acceptance criteria:**
- [ ] A reset link works once and expires after 1 hour.
- [ ] "Log out of all devices" ends sessions on another browser.
- [ ] The theme choice survives logout and login.
**Tests:** functional tests for reset (used, expired, unknown email), profile save, logout all.
**Prototype check:** avatar menu items (Security, Theme, Log out) as in [Global layout](../04-prototype/prototype-guide.md#global-layout).
**Size:** M

### P01.T3 — Organizations, teams, memberships and seed data 🏁
**Why:** every ticket lives inside one org and one team ([FR-ORG-01, FR-ORG-02, FR-ORG-03](../01-requirements/functional-requirements.md#org--organization-teams-and-roles)).
**Steps:**
1. Organization domain: `Organization`, `Membership` (org roles), `Team` (exactly one lead), `TeamMember` (team role, job), `OrgSettings` (jsonb with version; defaults from [data model](../02-architecture/data-model.md#organization)).
2. Migrations with RLS on every table (use the P00 helper; the catalog test must pass).
3. Org picker `/org/choose` after login when the user has more than one org; the list comes from the `SECURITY DEFINER` resolver function for "orgs of this user" (P00.T4), because no tenant is set yet; the chosen org goes into the session and `TenantContext`.
4. Commands (application layer): CreateOrganization, CreateTeam, SetTeamRole, SetOrgRoles (the last admin cannot remove their own Admin role).
5. Events: `MemberJoined`, `OrgRolesChanged`, `TeamCreated`, `TeamRoleChanged` (written to the outbox; the audit consumer comes in P11).
6. `app:demo:seed`: Acme Foods; team Shop (Lena lead + developer, Omar member developer, Mia member tester); Raj with Manager; Sara with Admin; Lena also Admin. A second org "Beta Bakery" with one user for isolation tests. Passwords from env, never in the repo.
**Files:** `src/Organization/**`, `src/Web/UI/Controller/OrgChooseController.php`, `src/SharedKernel/Infrastructure/Console/DemoSeedCommand.php`.
**Acceptance criteria:**
- [ ] A team cannot be saved without a lead; changing the lead records `TeamRoleChanged`.
- [ ] A user in two orgs picks one; switching orgs changes all data shown.
- [ ] Removing the last admin's Admin role fails with "An organization needs at least one admin."
- [ ] The default triager is the first admin.
**Tests:** domain unit tests (lead rule, last admin rule); tenant isolation tests for every `org_` table; functional test of the org picker.
**Prototype check:** role combinations in [Examples of role combinations](../00-product/personas-and-roles.md#examples-of-role-combinations) can all be seeded.
**Size:** L

### P01.T4 — Role resolution and base voters 🏁
**Why:** every later action asks a voter; this task builds the shared pieces ([ADR-0013](../03-adr/0013-authorization.md), NFR-SEC-04).
**Steps:**
1. `Organization\Api\MembershipQueries::rolesOf(UserId, OrganizationId): Roles` and `teamsOf`.
2. `ActorContext` value object built once per request (request-scoped service, reset in worker mode).
3. Base voter class: abstain only for unknown subjects; for known subjects return deny unless a rule grants.
4. `OrganizationVoter` (`ORG_VIEW`, `ORG_ADMIN`), `TeamVoter` (`TEAM_VIEW`, `TEAM_LEAD`), `AdminAreaVoter` (Admin role + TOTP confirmed from P01.T6; denies any actor that authenticated with a personal access token).
6. **Hackathon exception (until 22 Nov 2026):** P01.T6 (TOTP) is not in the hackathon slice, but P08.T3 (Admin › AI) is. With the env flag `APP_HACKATHON_SKIP_ADMIN_MFA=1`, `AdminAreaVoter` skips only the TOTP part. The kernel refuses to boot when this flag is set and `APP_ENV=prod`. The exception is recorded in [ADR-0012](../03-adr/0012-authentication.md) ("Hackathon exception (2026-11-06)"). Remove the flag when P01.T6 is done.
5. A table-driven voter test helper that reads rows from the [permission matrix](../00-product/personas-and-roles.md#permission-matrix) so later phases add rows, not new test code.
**Files:** `src/Organization/Api/*`, `src/Organization/UI/Voter/*`, `src/SharedKernel/Security/*`, `tests/Integration/Voter/*`.
**Acceptance criteria:**
- [ ] A Member of team A gets `TEAM_VIEW` for A and is denied `TEAM_LEAD` for A and everything for team B (unless they are a member there).
- [ ] An admin with no team role can view all teams and is denied `TEAM_LEAD`.
- [ ] Role changes take effect on the next request (no stale cache).
- [ ] Booting with `APP_HACKATHON_SKIP_ADMIN_MFA=1` and `APP_ENV=prod` fails with a clear error; in dev it lets an admin without TOTP open admin pages.
**Tests:** voter matrix tests (table-driven); a test that the ActorContext is rebuilt per request in worker mode.
**Prototype check:** none (backend task).
**Size:** M

### P01.T5 — App shell, menu by roles and Home shell 🏁
**Why:** one Home for all roles, no mode switch ([FR-ORG-05](../01-requirements/functional-requirements.md#org--organization-teams-and-roles), [ux principle 7](../00-product/ux-principles.md#7-one-home-for-all-roles)).
**Steps:**
1. Menu built from roles: Home (everyone); Tickets (team role or Manager); My reports (team role); Team (team role; "Teams ▾" when several); Admin (Admin role). See [layout and navigation](../02-architecture/frontend.md#layout-and-navigation).
2. Header: "Report a problem" tonal button for people with a team role (target route added in P03/P06), bell placeholder, avatar menu.
3. Home shell (`src/Web`): headline "Hi, Lena" placeholder, focus chips All / My work / My team / All teams (Manager) / Admin (Admin); sections render empty states. Chip choice saved per user and applied on next visit; chips only hide sections (Stimulus `focus-chips`).
4. Pure Manager and pure Admin homes: placeholders matching [Manager home](../04-prototype/screens/home-manager.md) and [Admin home](../04-prototype/screens/home-admin.md) layouts (content in P07).
**Files:** `src/Web/UI/Controller/HomeController.php`, `src/Web/UI/templates/home/*`, `assets/controllers/focus-chips_controller.js`, nav component.
**Acceptance criteria:**
- [ ] Each seeded person sees exactly the menu items of the table above.
- [ ] Lena (Lead + Admin) sees chips All, My work, My team, Admin and the Admin menu item.
- [ ] A pure admin sees Home and Admin only.
- [ ] The chosen chip is remembered across sessions.
**Tests:** functional tests per seeded person for the menu; Playwright: chips hide and show sections and keep the choice.
**Prototype check:** [Header block and focus chips](../04-prototype/screens/home-work.md#header-block-and-focus-chips): chip labels, order, the pressed style; nav item order.
**Size:** M

### P01.T6 — Two-step login (TOTP)
**Why:** admins hold the keys to everything ([FR-ID-03](../01-requirements/functional-requirements.md#id--identity-and-access), NFR-SEC-05).
**Steps:**
1. TOTP setup in `/me/security`: QR code (rendered server-side, no third-party service), confirm with a code, 10 recovery codes shown once (stored hashed).
2. Login step two when TOTP is on; throttle code tries.
3. Admin pages require a confirmed TOTP; an admin without it is sent to setup with a plain message.
4. Disable TOTP needs the password and a code.
5. `Identity\Application\RecentAuth`: a "Confirm it's you" page (password + TOTP code) whose success is remembered in the session for 10 minutes. Later phases use it for sensitive actions: admin credential changes (P08.T3), token creation (P09.T1), Jira and SSO setup (P10), role changes, retention and export (P11).
**Files:** `src/Identity/**` (`idn_mfa_secret` encrypted with sodium + key id).
**Acceptance criteria:**
- [ ] An admin without TOTP opening `/admin/people` lands on the setup page.
- [ ] A used recovery code cannot be used again.
- [ ] The secret is not readable in the database dump (encrypted).
- [ ] A route protected by `RecentAuth` asks again after 10 minutes.
**Tests:** functional tests for setup, login with code, recovery code, admin gate, `RecentAuth` expiry; unit test for encryption round trip with key rotation.
**Prototype check:** none (the prototype has no login screens; follow [ux-principles.md](../00-product/ux-principles.md)).
**Size:** M

### P01.T7 — Invitations
**Why:** admins add people without sharing passwords ([FR-ID-02](../01-requirements/functional-requirements.md#id--identity-and-access)).
**Steps:**
1. `InviteMember` command: email, org roles, optional team and team role; token stored as a hash; expires after 72 hours. The accept page finds the invite through the invite resolver function (P00.T4), not with `BYPASSRLS`.
2. Email (Mailpit locally) with the link `/invite/{token}`.
3. Accept page: new user sets name and password; an existing user (same email) logs in and gets the membership.
4. Expired or used link: "This invite is no longer valid. Ask your admin for a new one."
5. Pending invites query for the admin card and the People page (shown as "Invited · not joined yet" in P11).
**Files:** `src/Organization/**` (`org_invitation`), `src/Identity/UI/Controller/InviteController.php`.
**Acceptance criteria:**
- [ ] Accepting creates the membership with the chosen roles in one transaction.
- [ ] A second use of the same link fails with the message above.
- [ ] Only admins can invite (voter test).
**Tests:** functional tests (new user, existing user, expired, used), voter test.
**Prototype check:** invite field and button text in [Admin: people and roles](../04-prototype/screens/admin.md#people-and-roles) (the button needs a valid email).
**Size:** M

## Definition of done
- [ ] All tasks ticked; seeded people log in and see the right menus.
- [ ] Voter matrix tests and tenant isolation tests cover every new table and action.
- [ ] Admin pages are gated by role and TOTP.
- [ ] No passwords, codes or tokens in logs (marker test extended).
- [ ] Global [Definition of done](README.md#definition-of-done-for-every-task) met for every task.

## Hand-off notes
- Personal access tokens (FR-ID-05) are built in P09, together with the token page.
- SSO (FR-ID-04) is built in P10.
- Role and settings events are written to `aud_event` through `Audit\Api\AuditLog`; P11 adds the Activity log UI.
- Hackathon: `APP_HACKATHON_SKIP_ADMIN_MFA=1` is allowed only in dev and demo environments (ADR-0012 exception); after 22 Nov 2026 do P01.T6 and delete the flag and its code path.
- 2026-11-07 (P01.T1 step 2, login): Symfony Security with one `main` firewall (not lazy, because `RequestTransaction` must begin after it) and a `health` firewall without security for `/health/*`. `access_control` matches by route name: `identity_login`, the dev/test `web_component_gallery` and `test_*` routes are public, everything else needs a full login. Login throttling uses `ConsumeFirstLoginRateLimiter` from the limiters `login_per_email_and_ip` (5 per 15 min) and `login_per_ip` (50 per hour). It is not peekable, so every try, failed or not, takes a token before the password check, and a burst of parallel tries gets at most 5 checks; a successful login clears the email and IP counter and gives back the one token it took from the IP counter (`FixedWindowTryRefund`, under the limiter's own lock on the counter), so only wrong tries fill an IP's 50 per hour and an office behind one NAT can log in as often as it needs. Tries still being checked hold their IP token, so a burst across many emails also gets at most 50 password checks. Their counters sit on the state Valkey through `ValkeyLimiterStorage`, not a cache pool: a cache adapter reads an unreachable Valkey as an empty counter, so a pool would fail open. Both limiters lock each counter on the state Valkey (`framework.lock` on `state_valkey.connection`), so parallel tries all count. Sessions use `RedisSessionHandler` on `STATE_VALKEY_DSN` (prefix `session:`), cookie `__Host-pinround`; their TTL is still PHP's `session.gc_maxlifetime` (24 min) until P01.T2 sets the 8 h idle and 30 day limits. The session holds a crc32c of the password hash, so a password change (P01.T2) ends other sessions; a blocked user's session ends on the next request. Unknown emails get a throwaway Argon2id verify (`UnknownEmailPasswordVerifier`). The login page has its own stylesheet, `assets/styles/login.css`. A refused try names the real wait, never more than the refusing limiter's interval ("Too many tries. Wait 15 minutes." for one email and IP, up to 60 for the per-IP limit). `/health/ready` still checks the state Valkey through `MESSENGER_TRANSPORT_DSN`; it could read `STATE_VALKEY_DSN` now. The Playwright shell test (`tests/e2e/shell.spec.ts`) opens `/`, which redirects to `/login`: done in step 4 (login step in `tests/e2e/auth.setup.ts`, refreshed shell baselines).
- 2026-11-07 (P01.T1 step 2): the header reads the user's name through `app.user.name` (`SecurityUser::name()`) until an Identity Api view (`UserQueries`) exists. `PasswordHashing` and `Users::nextIdentity()` have no caller yet; the demo seed in the next step uses them.
- 2026-11-07 (P01.T1 step 3): `LoginAttemptRecorder` writes one `idn_login_attempt` row per try on the login form (success on `LoginSuccessEvent`, failure on `LoginFailureEvent`, throttled and CSRF-failed tries included) through `DbalLoginAttempts`. The hashes are HMAC-SHA256 under `kernel.secret` (`LoginKeyHasher`, renamed `ThrottleKeyHasher` on 2026-11-08, shared with the login and password reset limiters); the email is trimmed and lowercased first. The firewall runs before `RequestTransaction` begins, so the row commits on its own; a failed write is logged (class, SQLSTATE, code) and never changes the page. The 30-day purge of `idn_login_attempt` (data-model.md) is not built: P11 (retention) owns it, with the other purge jobs from the P00 hand-off lines; the app role has no DELETE until then.
- 2026-11-07 (P01.T1 step 4): `app:demo:seed` runs every `DemoSeedStep` (`SharedKernel\Infrastructure\Console`, tagged `app.demo_seed_step`), highest `#[AsTaggedItem(priority: ...)]` first, so SharedKernel never imports a module. Identity's step `SeedDemoPeople` (priority 100) creates the five people from `DEMO_PASSWORD` and fails with no writes when it is missing or under 12 characters; re-runs create no duplicates and keep existing passwords. P01.T3 adds the organization and team step the same way, with a priority below 100. Playwright logs in once as Lena in `auth.setup.ts` (password from `E2E_PASSWORD`) and `shell.spec.ts` reuses that session; the gallery and component specs stay logged out because their routes are public. The shell screenshot baselines were refreshed with `make e2e-update`.
- 2026-11-07 (P01.T2 foundation, session stamp and limits): `idn_user` has `session_stamp` and `theme` (`Version20261108100000`). `SecurityUser` carries the stamp and implements `EquatableInterface`: on every request the ContextListener compares the user from the session with the reloaded one (id, email, crc32c of the password hash, stamp), so `User::rotateSessionStamp()` plus a flush ends every session of that user on its next request. The reset, password change and "Log out of all devices" steps are to call it; it ends the current session too, unless that step puts a fresh `SecurityUser` into the token. A session saved before the stamp existed is logged out once. Limits (ADR-0012), parameters in `config/services.yaml`: `identity.session_idle_seconds` (28800) is also the `ttl` option of `RedisSessionHandler`, which every write or touch renews (it replaces PHP's 24 min `session.gc_maxlifetime`; `framework.session.gc_maxlifetime` would not reach the handler in the tests and is left alone); `identity.session_absolute_days` (30). `EndExpiredSession` (kernel.request, priority 9, just before the firewall at 8, because after it access control has already passed) invalidates a session whose metadata says last used over 8 h ago or created over 30 days ago, so the firewall sees no token and the request is redirected to `/login`; a request under a firewall without security (`/health/*`) is skipped, so probes never open the session. The login's `session_fixation_strategy: migrate` restamps the metadata's created time (`NativeSessionStorage::regenerate(true)` calls `MetadataBag::stampNew()`; the functional `EndExpiredSessionTest` checks it through the login form), so 30 days count from the login. The metadata times come from PHP's `time()`, not the Clock: the listener reads "now" from the Clock, so tests move the clock relative to the real time. `theme()` is on `User` and `SecurityUser` (`app.user.theme`); no template reads it yet.
- 2026-11-08 (P01.T2 steps 1–2, password reset): `/password/forgot` (`identity_password_forgot`), `/password/reset/{token}` (`identity_password_reset_token`) and `/password/reset` (`identity_password_reset`) are public in `access_control` by route name; a logged-in user opening `/password/forgot` goes Home. A POST to `/password/forgot` does the same work for every email: the throttle (`password_reset_per_email_and_ip` 5 per 15 min, `password_reset_per_ip` 50 per hour, on the state Valkey through `SymfonyPasswordResetRequestLimiter`, keys hashed with `ThrottleKeyHasher`, the renamed `LoginKeyHasher`; every request counts, no refund), then one `PasswordResetRequested` (the trimmed email, nothing else) sent straight to `async_notify`, then a redirect back to the page with the flash "If an account uses this email, we sent a link to reset the password. It works for 1 hour." (post/redirect/get, so a reload sends nothing). The request writes nothing to the database, so the message skips the outbox; it carries no TenantStamp. A refusal shows "Too many tries. Wait N minutes." with status 429 and queues nothing. In worker-notify, `PasswordResetRequestedHandler` runs `SendPasswordResetLink` inside `OnceOnlyHandling` (`identity.send_password_reset_link`): only an active user with a password gets a reset row (created then, valid 1 hour) and an email, sent straight to the mailer transport; a redelivered message adds no row and sends no second email, unless the send succeeded and the commit then failed. `idn_password_reset` (`Version20261108120000`, ORM-mapped, so the diff check covers it) keeps only the SHA-256 of the 32-byte token and is used up by one `UPDATE … RETURNING user_id`; a new link leaves older ones working until they expire. The link is built on `DEFAULT_URI`. Opening it moves the token into the session and redirects to the token-less `/password/reset`. `ResetPassword` checks the 12-character minimum before using the link, then sets the hash (`Credential::changePassword`) and rotates the session stamp in the request transaction, so every session of the user ends; the person is sent to `/login` with "Your password is changed. Log in with the new one." (the login page shows `notice` flashes and a "Forgot password?" link). `MAILER_FROM` (new env var) is the From header of all mail; the prod cache warm-up does not need it. `symfony/twig-bridge` is required directly (`TemplatedEmail`). The Identity UI layer cannot see Identity Domain or Infrastructure (Deptrac), so the controller passes the Clock's now to `ResetPassword` and gets `PasswordResetOutcome` back. `tests/e2e/password-reset.spec.ts` checks the forgot, link-sent and link-no-longer-works views (axe in both schemes, 360 px); the reset form itself needs a real emailed token and is covered by the functional tests only.
- 2026-11-08 (P01.T2, audit gap): ADR-0026 lists password resets as audited, but no reset request or reset writes an `aud_event` yet: `aud_event` rows need an organization, and a logged-out reset has none. P01.T3 (memberships) or the P11 audit consumer is to add it, for example one event per organization the user belongs to.
- (add dated notes here)
