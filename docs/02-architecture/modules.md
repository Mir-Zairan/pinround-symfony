# Modules

The catalogue of pinround's modules: what each one owns, what it offers to others, which events it sends, and the rules that keep the boundaries clean.

## Index

- [Why modules](#why-modules) — the problem modules solve, as a short story.
- [Directory layout and namespaces](#directory-layout-and-namespaces) — folders inside a module, with an example.
- [How modules talk](#how-modules-talk) — calls through `Api`, events through the outbox, instant read models, organization setup steps, and nothing else.
- [Module catalogue](#module-catalogue) — every module: purpose, owned tables, Api, commands, events, dependencies.
- [Event catalogue](#event-catalogue) — every domain event: producer, consumers, payload, delivery.
- [Deptrac rules](#deptrac-rules) — the layers, the allowed edges and the tests behind `composer deptrac`.
- [Rules for adding a module](#rules-for-adding-a-module) — the checklist before you create a new one.

## Why modules

Six months from now, Omar adds a "mute" option to pings. In a classic monolith he would touch the ticket entity, the Home query, and the email code, and break the manager page by accident. In pinround he changes only the Notification module. Home learns about mutes through a small `NotificationQueries` interface. Tickets do not know mutes exist.

That is the goal: **a change in one area stays in one area.** We get it without the cost of microservices, which a team of three cannot run ([ADR-0002](../03-adr/0002-modular-monolith.md)).

## Directory layout and namespaces

Every module has the same five folders:

```text
src/
  SharedKernel/                 # ids, Clock, TenantContext, DomainEvent base, Result, ReadinessCheck, DemoSeedStep, FieldEncryption, RequiresRecentAuth
  Ticketing/
    Api/                        # PUBLIC: interfaces + DTOs other modules may use
      TicketQueries.php
      TicketCommands.php
      Dto/TicketSummary.php
      Event/TicketCreated.php   # public event classes
    Domain/                     # entities, value objects, domain services, repository interfaces
      Ticket.php
      TicketKey.php
      CheckReason.php
      TicketRepository.php
    Application/                # use cases (command handlers, query handlers), Api implementations
      CreateTicket/CreateTicketHandler.php
      ConfirmCheck/ConfirmCheckHandler.php
      TicketQueriesService.php
    Infrastructure/             # Doctrine mappings + repositories, Messenger handlers, external clients
      Doctrine/DoctrineTicketRepository.php
      Doctrine/Mapping/Ticket.orm.xml
    UI/                         # controllers, forms, Twig components, voters, templates for this module
      Controller/TicketController.php
      Voter/TicketVoter.php
      templates/
```

Namespaces follow the folders: `App\Ticketing\Domain\Ticket`, `App\Ticketing\Api\TicketQueries`, and so on.

- **Domain** depends on nothing except `SharedKernel`. Doctrine mapping is XML in Infrastructure, so entities stay plain PHP.
- **Application** depends on Domain, on SharedKernel, and on the `Api` of other modules.
- **Infrastructure** implements Domain interfaces.
- **UI** calls Application through command and query classes. It never calls repositories.
- **Api** holds only interfaces, read-only DTOs and event classes. There is no logic in it.

## How modules talk

1. **Synchronous query or command through `Api`.**
   - Example: Ticketing asks Workflow, "may Lena move this ticket from In progress to Code review?" through `Workflow\Api\TransitionPolicy`.
   - The call runs inside the caller's transaction.
2. **Domain events through the outbox.**
   - Example: Ticketing records `TicketMoved`. The event row is written to the outbox in the same transaction. Notification, Audit, Integration and Insight react later ([ADR-0009](../03-adr/0009-async-messaging-and-outbox.md)).
3. **Read model updates that must be instant** run synchronously, in the same transaction.
   - Example: the `ins_ticket_turn` row ([ADR-0015](../03-adr/0015-turn-read-model.md)).
   - The producing module calls `Insight\Api\TurnProjector` directly, so Home is correct right after the click.
4. **Setup steps in the transaction that creates an organization** ([ADR-0036](../03-adr/0036-organization-setup-steps-in-the-creating-transaction.md)).
   - Example: Workflow's `SetUpOrganizationWorkflow` gives every new organization the eight core steps and the default rule set.
   - A module implements `Organization\Api\OrganizationSetupStep`; the `_instanceof` rule in `config/services.yaml` tags it, and Organization's `CreateOrganization` runs every tagged step once the organization row exists, highest tag priority first.
   - The step runs inside the caller's open transaction, whose tenant is already the new organization. It never begins, commits or rolls back a transaction.
   - It records no events of its own, except through the outbox in that same transaction.
   - Anything it throws rolls back the whole organization.
   - Use it only for data an organization cannot exist without. Anything that may arrive a few seconds later reacts to an Organization event instead.
5. **Never:**
   - a join across another module's tables (one named exception: Insight's query service may join `ins_ticket_turn` with Ticketing's read-only view `tkt_ticket_list_v`, [ADR-0015](../03-adr/0015-turn-read-model.md));
   - a Doctrine relation to another module's entity (store the id as a value object instead);
   - using another module's repository or `Domain` class.

## Module catalogue

Table prefixes keep ownership visible in SQL. The tables and columns are in [data-model.md](data-model.md).

### SharedKernel

- **Purpose:** the small set of types every module shares.
- **Contains:**
  - id value objects (`OrganizationId`, `UserId`, `TicketId` …, all UUIDv7, [ADR-0006](../03-adr/0006-identifiers.md));
  - `Clock` (an interface, so tests can freeze time);
  - `TenantContext` (the current `OrganizationId`; callers only fill it). The DBAL driver middleware `TenantMiddleware` in `Infrastructure/Doctrine` reads it and runs `set_config('app.org_id', …, true)` at the start of each transaction. `TenantFilter` scopes ORM queries on `TenantOwned` entities and throws `TenantNotSet` without a tenant; `RequestTransaction` wraps each main web request in one transaction, except on a route whose `_request_transaction` default is `false` (today only the health routes); `TenantContext` refuses (`TenantSwitchRefused`) an org switch or `clear()` while a transaction is open or the EntityManager holds an entity ([ADR-0005](../03-adr/0005-multi-tenancy.md), [row-level security](data-model.md#row-level-security));
  - the `DomainEvent` base (event id, occurred at, organization id, actor id);
  - `Result`/`Problem` types for expected failures;
  - `DenyByDefaultVoter` (`UI\Voter`): the base that new voters on a subject extend. It abstains only for an attribute it does not own or a subject of another class, and denies its own unless the subclass's rule grants ([ADR-0013](../03-adr/0013-authorization.md)). The voters whose subject is null are the exceptions, because the base cannot own a null subject, so they keep Symfony's `Voter`: Organization's `OrganizationMemberVoter` (`ORGANIZATION_CHOOSE`) and Identity's `OwnAccountVoter` (`ACCOUNT_EDIT_OWN`); `AdminAreaVoter` extends the base;
  - `RequiresRecentAuth` (`UI`): the attribute that marks a sensitive controller class or action. Identity enforces it: without the password and a two-step code in this session within 10 minutes, the person goes to "Confirm it's you" ([ADR-0012](../03-adr/0012-authentication.md)). It is here because most modules that will mark sensitive actions (Organization, Integration, Audit and others, P08-P11) may not see `Identity_Api`, while every module may see SharedKernel;
  - `FieldEncryption` (Application, with `UndecryptableField` for a value that is altered, cut short or written with a key no longer in the ring) and its libsodium secretbox implementation `SodiumFieldEncryption` in `Infrastructure/Encryption`, with the key ring `FieldEncryptionKeys` ([ADR-0027](../03-adr/0027-secrets-and-encryption.md), [config.md](../operations/config.md#field-encryption)): encrypts secrets stored in the database. Identity's MFA seeds use it first; Integration's Jira and webhook secrets, OIDC client secrets and AI provider credentials follow;
  - `CitextType` and `TextArrayType` (`Infrastructure/Doctrine`): the Doctrine types of PostgreSQL `citext` and one-dimensional `text[]` columns (`text_array`: Organization's `org_roles`, Identity's `recovery_code_hashes`). Doctrine registers a type once for the whole app, so it cannot belong to one module;
  - `OnceOnlyHandling` (Application: run an async handler's effect once per message), with `MessageIdStamp`, `MessageIdStampMiddleware`, `MessageBeingHandled` and `ProcessedMessageLedger` in `Infrastructure/Messenger` ([ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md#idempotency-key));
  - `ReadinessCheck` (`Infrastructure/Health`): one thing `/health/ready` proves. The module that owns what is checked implements it (Audit: `NextAuditPartitionExists`; Media: `ObjectStorageIsReachable`), and autoconfiguration tags it. SharedKernel holds the platform checks `DatabaseAnswers`, `MigrationsAreCurrent` and `ValkeyAnswers` (one service per Valkey), and `ReadinessCheckFailed` for a dependency that answered in the wrong state ([operations.md](operations.md#observability));
  - `DemoSeedStep` (`Infrastructure/Console`, with `DemoSeedRefused`): one part of the demo data that `app:demo:seed` runs, dev and test only. The module that owns the tables implements it (Identity: `SeedDemoPeople`; Organization from P01.T3: `SeedDemoOrganizations`, priority 50; Ticketing from P02.T1: `SeedDemoProjects`, priority 40), autoconfiguration tags it, and `#[AsTaggedItem(priority: …)]` sets the order, highest first. It lives in SharedKernel because the seed command must not import modules, the same reason as `ReadinessCheck`;
- **Owns tables:** `processed_message` (global plumbing, no `organization_id`; see [data-model.md](data-model.md)).
- **Rule:** keep it tiny. A type joins SharedKernel only when three or more modules need it.

### Identity (`idn_`)

- **Story:** Sara invites Omar. Omar sets a password and turns on two-factor login. Later the company switches to Microsoft login, and Omar's account is linked, not duplicated.
- **Owns:** `idn_user`, `idn_credential`, `idn_password_reset`, `idn_mfa_secret`, `idn_sso_identity`, `idn_api_token`, `idn_login_attempt`. Sessions live in Valkey.
- **Api:**
  - `CurrentUser::id(): ?UserId` and `CurrentUser::idOf(mixed $tokenUser): ?UserId` (built in P01.T3: the logged-in user of the request, or of the token a voter is given; null when nobody is; `Infrastructure\Security\SymfonyCurrentUser`)
  - `LoginAssurance::isPasswordSession(mixed $token): bool`, `LoginAssurance::isTwoStepLoginOn(mixed $token): bool` and `LoginAssurance::hasConfirmedTwoStepLoginWithin(mixed $token, int $seconds): bool` (built in P01.T4 and P01.T6 for `AdminAreaVoter` and `SendAdminToTwoStepProof`; true only for the form login's `UsernamePasswordToken` of an Identity user, any other token kind is refused; the last needs two-step login on and a code typed in this session within `$seconds` and not before it was last turned on, the rule `RecentAuth` applies to `RequiresRecentAuth` too; `Infrastructure\Security\SymfonyLoginAssurance`)
  - `TwoStepProofPages::confirmItsYouPathFor(mixed $request): string` and `::sendToTwoStepSetup(mixed $request, string $notice): string` (built in P01.T6 for `SendAdminToTwoStepProof`: the paths of "Confirm it's you" and of the setup page, each with the way back; `UI\Controller\RecentAuthRedirects`)
  - `UserQueries::byId(UserId): ?UserView`
  - `UserQueries::byIds(list<UserId>): array<string, UserView>`
  - `UserQueries::byEmail(string): ?UserView`
  - `UserQueries::idByEmail(string): ?UserId` (built in P01.T3 for the demo seed: plain SQL on `idn_user`, null when no one has the address; `Infrastructure\Doctrine\DbalUserQueries`; the others above are not built yet)
  - `InvitedPersonRegistration::register(string $email, string $name, string $password, \DateTimeImmutable $now): UserId`, `::minimumPasswordLength(): int`, `::maximumNameLength(): int` and `::isValidEmail(string $email): bool` (the account email rule, which InviteMember asks) (built in P01.T7 for the invite accept page: an active user in UTC and English plus an Argon2id credential, written in the caller's transaction; every refused input comes before any write as `RegistrationRefused` listing `RegistrationRefusal` cases (InvalidEmail, EmailTaken, NameBlank, NameTooLong, PasswordTooShort); an email another transaction took at the same moment gives EmailTaken alone with the EntityManager closed, so the caller rolls back and reads nothing more through the ORM in that request; `Application\RegisterInvitedPerson`)
  - `TokenAuthenticator::authenticate(string $bearer): ?TokenPrincipal` (scopes included)
  - `ApiTokens::issue(UserId, OrganizationId, string $name, list<Scope>, \DateTimeImmutable $expires): IssuedToken` (the plain token is returned once)
- **Commands:** RegisterInvitedPerson (built in P01.T7, behind `InvitedPersonRegistration`), ChangePassword, StartTwoStepSetup and ConfirmTwoStepSetup (built in P01.T6 step 1: a new seed in `idn_mfa_secret` through `FieldEncryption`, then a code of it turns two-step login on and returns the 10 `RecoveryCodes` once; ports `OneTimeCodes` (`OtphpOneTimeCodes`), `QrCodeImages` (`BaconQrCodeImages`, SVG drawn on the server) and `RecoveryCodeHashing` (`SymfonyRecoveryCodeHashing`, Argon2id)), ConfirmItsYou (built in P01.T6 step 5: the password through `CurrentPasswordCheck`, which shares `ChangePassword`'s limiter, then a code through `AcceptTwoStepCode`; it records the session times `RecentAuth` reads for actions marked `RequiresRecentAuth`), DisableTwoStepLogin (built in P01.T6 step 4: the same password and code checks, then it deletes the `idn_mfa_secret` row and forgets both session times), LinkSsoIdentity, IssueApiToken, RevokeApiToken.
- **Events (planned, none built yet):** `UserRegistered`, `UserLoggedIn`, `LoginFailed`, `MfaEnabled`, `ApiTokenIssued`, `ApiTokenRevoked`. `ConfirmTwoStepSetup` raises no `MfaEnabled` yet: an `aud_event` row needs an organization and Identity is global, so it waits for the Audit consumer of P11 (the same gap as the P01.T2 password-reset audit note).
- **Depends on:** SharedKernel only.
- **ADRs:** [0012](../03-adr/0012-authentication.md), [0027](../03-adr/0027-secrets-and-encryption.md).

### Organization (`org_`)

- **Story:** Sara creates the team "Shop" with Lena as lead. She gives Raj the Manager role. Omar is a member of Shop and a viewer in "Mobile".
- **Owns:** `org_organization`, `org_membership` (org roles: admin, manager), `org_team`, `org_team_member` (team role: lead, member, viewer; job: tester, developer, other), `org_invitation`, `org_settings` (ping limits, quiet hours, lead-check switch, triager, step policy, AI policy, retention).
- **Api:**
  - `MembershipQueries::organizationsOf(UserId): list<OrganizationId>` (built in P01.T3, through `resolve_user_orgs`, so it needs no tenant)
  - `MembershipQueries::rolesOf(UserId, OrganizationId): Roles` (built in P01.T4; `Roles` offers `isAdmin()`, `isManager()`, `isLeadOf(TeamId)`, `isMemberOf(TeamId)` and `isViewerOf(TeamId)` for exactly that team role, `isInTeam(TeamId)` for any team role, `teamIds()` in id order, and `Roles::none()`, which a user with no membership gets)
  - `TeamQueries::teamsOf(OrganizationId): list<TeamId>` (moved from `MembershipQueries` in P01.T5; every team of the organization, in id order) and `TeamQueries::summariesOf(OrganizationId, list<TeamId>): list<TeamSummary>` (`TeamSummary` offers `id()` and `name()`; the asked teams of that organization ordered by name ignoring case, then id, in one query; unknown ids and ids of another organization are not returned)
  - `InvitationQueries::pendingOf(OrganizationId, int $limit, ?PendingInvitation $after): list<PendingInvitation>` (built in P01.T7: the invitations not accepted and not expired, one keyset page, newest first by `created_at` then id; `MAX_LIMIT` is 100 and a limit outside 1..100 throws `InvalidArgumentException`; `after` is read for its `id()` and `invitedAt()` only; `PendingInvitation` offers `id()` as a string, `email()`, `invitedAt()` and `expiresAt()`; `Infrastructure\Doctrine\DbalInvitationQueries`)
  - `DemoOrganizations::idBySlug(string): ?OrganizationId` (built in P02.T1; dev and test demo data only, for the demo seed steps of other modules: the demo organization the seed made with that slug, null when it does not exist; `Infrastructure\Demo\FirstAdminDemoOrganizations` looks among the organizations of its first admin, reading each through `OrganizationTransactions::runAs`, so it clears the EntityManager, puts the tenant back and throws `TenantSwitchRefused` when a transaction is open)
  - `OrganizationSetupStep::setUp(OrganizationId): void` (built in P02.T2; implemented by other modules, not by Organization: what every new organization needs from them, made by `CreateOrganization` in the creating transaction, see [How modules talk](#how-modules-talk) and [ADR-0036](../03-adr/0036-organization-setup-steps-in-the-creating-transaction.md); Workflow's `SetUpOrganizationWorkflow` is the first)
  - `TeamQueries::jobOf(TeamId, UserId): ?MemberJob` (built in P02.T3; `MemberJob` is `Developer`, `Tester` or `Other`, mapped from the stored job, never Domain's `Job`; one query; null when the user is not in that team or the team is another organization's)
  - `SettingsQueries::stepPolicyOf(OrganizationId): StepPolicy` (built in P02.T3; `StepPolicy` is `Locked`, `Extend` or `Team`, the values of `org_settings.data.step_policy`; one query in `Infrastructure\Doctrine\DbalSettingsQueries`; `Extend`, the default, when there is no settings row or no step policy, and a `LogicException` for a stored value it does not know)
  - `rolesOf`, `teamsOf`, `summariesOf`, `jobOf`, `stepPolicyOf` and `pendingOf` read under row-level security with no cache, so they need an open transaction whose tenant is that organization; under any other tenant, or none, they find nothing (`summariesOf` with no ids runs no query; `stepPolicyOf` then gives `Extend`).
  - `CurrentActor::actorFor(mixed $tokenUser): ?ActorContext` (built in P01.T4; for voters in any module, which pass `$token->getUser()`; `ActorContext` offers `userId()`, `organizationId()` and `roles(): Roles`; null when the token has no logged-in user or the request has no tenant, so the voter denies). Built once per request for each user and organization from one `rolesOf` call and reset between requests (`Infrastructure\Security\RequestScopedCurrentActor`), so role changes take effect at the next request; ask it inside the request transaction, outside one it reads no roles.
  - `OrganizationPermission` and `TeamPermission` (built in P01.T4): the voter attributes, so the UI of any module may ask them. `ORG_VIEW` and `ORG_ADMIN` take an `OrganizationId` (`UI\Voter\OrganizationVoter`: the organization the request works in; Admin for `ORG_ADMIN`); `TEAM_VIEW` and `TEAM_LEAD` take a `TeamId` (`UI\Voter\TeamVoter`: any team role, or Admin or Manager of the team's organization, for `TEAM_VIEW`; only the team's Lead for `TEAM_LEAD`). `ORG_INVITE` (built in P01.T7) takes an `OrganizationId` and is decided by `OrganizationVoter` for an Admin only; admin pages also ask `ADMIN_AREA`. `ORG_ACCEPT_INVITATION` (built in P01.T7) takes an `Application\OpenInvitationLink` (`UI\Voter\InvitationVoter`: the logged-in account with the invited email, or nobody logged in when no account has that email yet; anyone else is denied). `CHOOSE` and `ENTER` are the organization picker's (`UI\Voter\OrganizationMemberVoter`).
  - `AdminPermission` (built in P01.T4): `ADMIN_AREA` takes the request's `OrganizationId` (`UI\Voter\AdminAreaVoter`: an Admin there, with a password session, two-step login on and a code typed in this session within the last hour, all from Identity's `LoginAssurance`; `UI\SendAdminToTwoStepProof` sends a refused Admin to the setup page or to "Confirm it's you").
  - `TeamQueries::team(TeamId): TeamView`
  - `TeamQueries::leadOf(TeamId): UserId`
  - `TeamQueries::testersOf(TeamId): list<UserId>`
  - `TeamQueries::members(TeamId): list<MemberView>`
  - `OrgSettings::get(OrganizationId): SettingsView`
- **Commands:** CreateOrganization, AddMember (makes a user a member with org roles; used by the demo seed and by AcceptInvitation), InviteMember, AcceptInvitation, SetOrgRoles, CreateTeam, SetTeamRole, ChangeSettings. Built so far (P01.T3): CreateOrganization, AddMember, SetOrgRoles, CreateTeam, SetTeamRole, invokable services in `Application` that write in the caller's transaction and take the acting user (null for the system) and the time. Also built (P01.T7): InviteMember, which refuses an existing member and replaces an open invitation of the same email. Also built (P01.T7): `Application\InvitationLinks::find(string $token): ?OpenInvitationLink` (`Infrastructure\Doctrine\DbalInvitationLinks`: resolves the token's SHA-256 through `resolve_invitation` without a tenant, then reads the open invitation under its organization in a short transaction; call it with no transaction open) and AcceptInvitation with `acceptAs(OpenInvitationLink, string $linkToken, UserId, \DateTimeImmutable)` for the account with the invited email and `acceptAsNewPerson(OpenInvitationLink, string $linkToken, string $name, string $password, \DateTimeImmutable): UserId`, which registers the person through Identity's `InvitedPersonRegistration` first. Both lock the invitation row, check the link token against its stored hash (an `OpenInvitationLink` alone accepts nothing, as anyone can build one), then add the member with the invited org roles and, for a team invitation, the team role with job Other. Refusals the UI may catch are Application exceptions: `InvitationNoLongerValid` (accepted, expired, replaced or another token), `InvitationForAnotherPerson` and `AlreadyJoined`; `acceptAsNewPerson` also passes on `RegistrationRefused`. InviteMember's refusals are `InvalidEmailAddress` (asked of Identity's `InvitedPersonRegistration::isValidEmail`, the rule an account's email follows), `AlreadyInTheOrganization` (an existing member) and `InvitationSentMeanwhile` (a concurrent second invite of the email was saved first; nothing is recorded and the caller rolls back). `UnknownTeam` and `TeamHasOneLead` are still Domain exceptions: wrap them in Application exceptions when P11 adds the team choice. Also built (P02.T3): ChangeStepPolicy, the first part of ChangeSettings, takes the organization, a `StepPolicy`, the acting user and the time, and changes only `step_policy` through `OrgSettingsStore::save`, whose version check (Doctrine's optimistic lock) fails when another transaction changed the settings meanwhile; the caller asks `ORG_ADMIN` first. It records no `SettingsChanged` yet.
- **UI (built in P01.T7):** `UI\InvitationController` is the accept page: `GET /invite/{token}` moves the token into the session and redirects to `/invite`, which asks `ORG_ACCEPT_INVITATION` and runs AcceptInvitation through `Application\OrganizationTransactions::runAs` (a transaction of its own under the link's organization). `UI\Admin\PeopleController` is the admin People page: `GET /admin/people` shows the invite form and the invites not joined yet (through `InvitationQueries`), `PeopleController::PAGE_SIZE` (20) at a time with a "Show more" link to the next keyset page (query `after_invited_at`, as `Y-m-d\TH:i:s.uP`, and `after_id`; one without the other, or either not valid, answers 400 after the access checks), and `POST /admin/people/invite` invites an email with no roles and no team; both ask `ADMIN_AREA`, then `ORG_INVITE`.
- **Events:** `MemberJoined`, `MemberInvited` (built in P01.T7: organization id, invitation id, actor id and occurred at; no email), `OrgRolesChanged`, `TeamCreated`, `TeamRoleChanged`, `SettingsChanged`. They implement `Api\Event\OrganizationEvent` (organization id, actor id: the acting user's id or null for the system, occurred at; ids as UUID strings, roles as their values) and are published through `Application\OrganizationEvents` (`Infrastructure\Messenger\MessengerOrganizationEvents`, on `event.bus`), which routes the interface to the outbox; the relay sends it to `async_notify`. Organization consumes its own `MemberInvited` there: `Infrastructure\Messenger\QueueInvitationLinkEmailHandler` puts `Application\EmailInvitationLink` (ids only, never the token) on the command bus, routed to `async_notify`, so the email has its own transaction apart from the audit entry. `Infrastructure\Messenger\EmailInvitationLinkHandler` (command.bus) runs `Application\SendInvitationLink` once per message (`organization.send_invitation_link`). It locks the invitation row (`Invitations::lockById`), makes the link token in the worker, stores only its SHA-256 on the invitation and then emails the link, so only one email goes out even if `EmailInvitationLink` is queued twice, unless the email was sent and the transaction then failed to commit.
- **Depends on:** Identity Api.
- **ADRs:** [0013](../03-adr/0013-authorization.md).

### Workflow (`wf_`)

- **Story:** Lena adds a team step "Design check" under Checking. Raj still sees the team in the same five groups as every other team.
- **Owns:** `wf_step` (core, company or team steps, each with its group), `wf_rule_set` (the three rules per org or team). The verb catalogue is data in code (`Domain\TransitionCatalogue`, P02.T4), not a table, as it is the same for every organization.
- **Api:**
  - `StepQueries::stepsFor(TeamId): list<StepView>` (built in P02.T2: one query on `wf_step`, the organization's core and company steps and the team's own steps, without removed ones; in the groups' list order Waiting, Checking, Doing, To do, Done, then by origin (core, company, team; since P02.T3), then by position, then by id; `StepView` offers `id()` as a string, `name()`, `groupCode()`, `groupLabel()`, `position()`, `origin()` and `coreCode()`, the codes as strings because the Api may not expose Workflow's Domain enums; `Infrastructure\Doctrine\DbalStepQueries`)
  - `StepQueries::groupOf(string $stepId): ?string` (built in P02.T2: the step's group code, also for a removed step, as a ticket may still be in it; null when the organization has no such step; an id that is not a UUIDv7 throws `InvalidArgumentException` before any query)
  - `StepQueries::moveTargetsFor(TeamId $teamId): list<StepView>` (built in P02.T3: `stepsFor` without the team's own steps under the step policy `locked`; equal to `stepsFor` under `extend` and `team`; a ticket already in a team step keeps it; P02.T5's "Move to another step" list uses it)
  - `RuleQueries::rulesFor(TeamId $teamId): RuleSetView` (built in P02.T3: the three rules as bools; the team's own rule set under the step policy `team` when it has one, else the organization's; always the organization's under `extend` and `locked`; no rule set at all means all three on; `Infrastructure\Doctrine\DbalRuleQueries`)
  - `stepsFor`, `moveTargetsFor` and `rulesFor` are cached per team for a day (`Infrastructure\Cache\WorkflowCache`, pool `cache.workflow`; keys and tags in [performance-and-scale.md](performance-and-scale.md#caching)); a miss and `groupOf` (never cached) read under row-level security, so they need an open transaction whose tenant is the organization; under any other tenant, or none, they find nothing, and nothing is cached. A change clears its tags after commit (`WorkflowCacheTransactionMiddleware`) and again when `InvalidateWorkflowCacheHandler` handles the relayed event.
  - `StepOccupancy::openTicketsIn(string $stepId): int` (built in P02.T3; implemented by Ticketing, not by Workflow, as `Organization\Api\OrganizationSetupStep` is by other modules: Ticketing owns the tickets and already depends on Workflow, so a Ticketing Api count would make a cycle; `RemoveStep` asks it; reads under row-level security like `stepsFor`)
  - `WorkflowPermission` (built in P02.T3): the voter attributes `STEP_CHANGE_TEAM` and `RULES_CHANGE_TEAM`, both on a `TeamId` (`UI\Voter\WorkflowVoter`, which reads the actor's organization's step policy through Organization's `SettingsQueries` and asks `Application\StepChangeRights`): only the team's Lead, for its steps under the policy `extend` or `team`, for its rules under `team` only; no org role grants them. Company steps, the organization's rule set and the step policy itself are Admin only under every policy and ask Organization's `ORG_ADMIN`. `StepChangeRights` is in Application, not Domain, because Workflow's UI may not see its Domain.
  - `TransitionPolicy::available(TicketState, ActorInTeam, RuleSetView): Availability` (built in P02.T4: the `TransitionView`s in button order, each with its verb code, label and primary flag, and one `RuleNote` or null; pure, nothing read from the database; `Application\Transition\CatalogueTransitionPolicy`). `ActorInTeam` carries the person's team role and job in the ticket's team, as the Api may use SharedKernel types only: the caller builds it from Organization's `ActorContext` roles and `TeamQueries::jobOf`, and passes `RuleQueries::rulesFor`. Without the team role lead or member, or on a ticket no team owns, nobody gets a verb; an open question pauses every move.
  - `TransitionPolicy::check(TicketState, ActorInTeam, RuleSetView, string $verb): Decision` (built in P02.T4: allowed exactly when `available()` lists the verb, else refused with the rule note or none; an unknown verb is refused without a note)
  - `TurnRules::turnFor(TicketState, TeamFacts): Turn` (a pure function)
- **Commands:** AddStep, RemoveStep, SetRule (ChangeStepPolicy is Organization's, as the step policy is stored in `org_settings`). Built so far (P02.T3), invokable services in `Application` that write in the caller's transaction; the caller asks the voter first: Organization's `ORG_ADMIN` for company steps and the organization's rule set (as for the step policy), `WorkflowPermission` for a team's.
  - `AddStep(OrganizationId, ?TeamId, string $name, string $groupCode): string` (no team: a company step; returns the new step's id, as Workflow's UI may not see its Domain `StepId`); refuses with `StepGroupUnknown`, `StepTeamNotFound` (the team is not the organization's, asked through Organization's `TeamQueries`), `TeamStepsLocked` (a team step under the policy `locked`), `StepNameMissing` and `StepInDoneRefused`; the step comes last among the steps it is listed with.
  - `RemoveStep(OrganizationId, ?TeamId, string $stepId, \DateTimeImmutable)` (no team: a company step, voted with `ORG_ADMIN`; a team: voted with `STEP_CHANGE_TEAM` on it) archives the step; refuses with `UnknownStep` (also for an id that is not a step id, and unless the step is exactly that team's, or a company step when there is no team), `CoreStepNotRemovable` and `StepHasOpenTickets` (counted through `StepOccupancy`); removing a removed step changes nothing.
  - `SetRule(OrganizationId, ?TeamId, string $ruleCode, bool $isOn)` (no team: the organization's rule set, through `Domain\RuleSets::switchRule`, `Infrastructure\Doctrine\DbalRuleSets`, which writes the one rule's column only, so two people switching different rules at once keep both); refuses with `RuleUnknown`, `RuleTeamNotFound` (the team is not the organization's, asked through Organization's `TeamQueries`) and `TeamRulesNeedTeamPolicy` (a team rule under a policy other than `team`); a team's first own rule set starts as a copy of the organization's.
- **Events:** `StepAdded` (step id, group code), `StepRemoved` (step id, removed at; once per step), `RulesChanged` (rule code, on or off; on every set). Built in P02.T3. They implement `Api\Event\WorkflowEvent` (organization id, team id or null; ids as UUID strings, no names or free text) and are published through `Application\WorkflowEvents` (`Infrastructure\Messenger\MessengerWorkflowEvents`, on `event.bus`) in the command's transaction; the interface is routed to the outbox and the relay sends it to `async_notify`. The only consumer is Workflow's own `InvalidateWorkflowCacheHandler`, which clears the team's cache; Audit has no allow-list entry for them.
- **Depends on:** Organization Api.
- **Note:** Workflow does not store tickets. Ticketing owns the ticket's current step and asks Workflow before every move.
- **New organizations:** `Application\SetUpOrganizationWorkflow`, an `Organization\Api\OrganizationSetupStep`, adds the eight core steps and the default rule set in the transaction that creates the organization ([ADR-0036](../03-adr/0036-organization-setup-steps-in-the-creating-transaction.md)); organizations made before that got them from the backfill migration `Version20261110140000`.
- **More:** [workflow-engine.md](workflow-engine.md), [ADR-0014](../03-adr/0014-status-model-five-groups.md).

### Ticketing (`tkt_`)

- **Story:** Mia's report becomes `SHOP-412` at once, with the flag "maybe the same as an older ticket". Lena confirms it. Omar moves it through the steps.
- **Owns:** `tkt_project` (with the key counter), `tkt_ticket`, `tkt_ticket_event` (partitioned history), `tkt_duplicate_link`, `tkt_summary` (current + history).
- **Api:**
  - `TicketQueries::byKey(OrganizationId, string $key): ?TicketView`
  - `TicketQueries::list(ListFilter, ?Cursor): Page<TicketRow>`
  - `TicketQueries::reportedBy(UserId): list<TicketRow>`
  - `TicketQueries::duplicateCandidates(OrganizationId, string $text, ?TicketId $exclude): list<TicketRow>` (top 3)
  - `TicketCommands::create(CreateTicket): TicketId`
  - `TicketCommands::move(TicketId, TransitionName, UserId): void`
  - `TicketCommands::assign(TicketId, UserId, UserId $actor): void`
  - `TicketCommands::confirmCheck(TicketId, UserId): void`
  - `TicketCommands::closeAsDuplicate(TicketId, TicketId $of, UserId): void`
- **Commands (more):** SetVisibility, SetSummary, UseExplanationAsSummary, MoveToTeam, PutOnHold.
- **Events:** `TicketCreated`, `TicketMoved`, `TicketAssigned`, `TicketChecked`, `TicketClosedAsDuplicate`, `TicketVisibilityChanged`, `SummaryChanged`.
- **Depends on:** Workflow Api, Organization Api, Media Api (report screenshot ids), Insight Api (`TurnProjector`, synchronous).
- **Built so far (P02.T1):** `tkt_project` (`organization_id`, `id`, `team_id`, `key`, `name`, `next_number`; unique on `organization_id, key`; row-level security on). `Domain\Projects` (`Infrastructure\Doctrine\DoctrineProjects`): `nextIdentity`, `byId`, `byKey` and `add`, which saves at once and throws `ProjectKeyInUse` when the unique index refuses the key (the transaction is then failed and the EntityManager closed). `Application\NextTicketNumber::takeFor(ProjectId): int` (`Infrastructure\Doctrine\DbalNextTicketNumber`): call it in an open transaction; it locks the project row until that transaction ends, so numbers are gapless, and throws `UnknownProject` when the project is not found or is another organization's. `Application\CreateProject\CreateProject(OrganizationId, TeamId, string $key, string $name): ProjectId`, in the caller's transaction; it refuses with `ProjectKeyNotValid`, `ProjectNameMissing` (both wrap the Domain's `InvalidProjectKey` and `BlankProjectName` with the same message, as the UI may not use the Domain), `ProjectTeamNotFound` (the team is not the organization's, asked through Organization's `TeamQueries`) and `ProjectKeyTaken` (also when another transaction saved the key after the check). `ProjectPermission::CREATE` takes a `TeamId` and is decided by `UI\Voter\ProjectVoter`: the Lead of that team, or an Admin of its organization. The demo seed's `Infrastructure\Demo\SeedDemoProjects` creates SHOP for Acme Foods' team Shop, finding Acme Foods through Organization's `DemoOrganizations`. `ProjectCreated` is not built yet. Also built (P02.T3): `Infrastructure\Workflow\TicketStepOccupancy`, Ticketing's implementation of Workflow's `StepOccupancy`; it returns 0 until P03 adds tickets.
- **ADRs:** [0016](../03-adr/0016-direct-ticket-creation-with-check-flags.md), [0022](../03-adr/0022-search-and-duplicates.md).

### Conversation (`cnv_`)

- **Story:** Lena asks Mia, "Which contract was it?" The ticket pauses. Mia answers from "My reports", and the ticket goes back to its step.
- **Owns:** `cnv_message` (comment or question, with `to_user_id` and `resolved_at`), `cnv_reply` (one level deep).
- **Api:**
  - `ThreadQueries::forTicket(TicketId, UserId $viewer): list<MessageView>`
  - `ThreadQueries::openQuestion(TicketId): ?QuestionView`
  - `ThreadQueries::openQuestionsTo(UserId): list<QuestionView>`
- **Commands:** PostComment, AskQuestion, Reply, Answer (a reply that resolves the question), Resolve.
- **Events:** `CommentPosted`, `QuestionAsked`, `QuestionAnswered`, `CommentResolved`, `ReplyPosted`.
- **Depends on:** Ticketing Api (visibility check, ticket exists).
- **How pausing works:** a question does not change the ticket's step. Insight sees `QuestionAsked` and makes the asked person the turn owner. The page shows "Waiting · question to Mia" ([workflow-engine.md](workflow-engine.md#questions-pause-and-resume)).

### Review (`rvw_`)

- **Story:** Omar's private draft round has three screenshots. He shares it as round 2 when he sends the ticket to review. Mia marks each pin.
- **Owns:** `rvw_round` (kind: report, checklist, review, draft; owner; `shared_at`), `rvw_screenshot` (media id, position, verdict, note), `rvw_pin` (number, x/y in percent, note, verdict, source human/ai, state kept/dismissed), `rvw_check_item` (the Round 1 checklist, made from report pins).
- **Api:**
  - `RoundQueries::forTicket(TicketId, UserId $viewer): list<RoundView>` (private drafts filtered out)
  - `RoundQueries::roundNotes(TicketId, UserId $owner): RoundNotes` (for MCP `get_round_notes`)
  - `RoundCommands::addScreenshot(RoundId, MediaId, UserId): ScreenshotId`
  - `RoundCommands::setVerdict(ScreenshotId|PinId, Verdict, UserId): void`
  - `RoundCommands::shareDraft(RoundId, UserId): int $roundNumber`
- **Commands (more):** StartDraftRound, AddPin, EditPinNote, RemovePin, MarkChecklistItem, DeleteDraft, KeepOrDismissAiPin.
- **Events:** `RoundStarted`, `RoundShared`, `PinVerdictSet`, `ChecklistItemChanged`, `DraftDeleted`.
- **Depends on:** Ticketing Api, Media Api.
- **ADRs:** [0017](../03-adr/0017-private-draft-rounds.md), [0011](../03-adr/0011-screenshot-storage-and-processing.md).

### Media (`med_`)

- **Story:** Mia's 4 MB screenshot goes straight from her browser to the bucket. A worker checks it, strips hidden data, makes a small WebP, and marks it ready.
- **Owns:** `med_object` (storage key, sha256, mime, bytes, size, status, variants), `med_upload_intent` (presigned slot: max bytes, allowed types, expiry).
- **Api:**
  - `Uploads::begin(OrganizationId, UserId, UploadPurpose, int $bytes, string $mime): UploadIntent` (with a presigned PUT URL)
  - `Uploads::complete(UploadIntentId): MediaId`
  - `MediaQueries::status(MediaId): MediaStatus`
  - `SignedUrls::forVariant(MediaId, Variant, UserId): SignedUrl` (the caller must check access first)
- **Events:** `MediaReady`, `MediaRejected`.
- **Readiness:** `Infrastructure\Health\ObjectStorageIsReachable` (a `ReadinessCheck`): `OBJECT_STORAGE_ENDPOINT` accepts a TCP connection.
- **Depends on:** SharedKernel only. Media is generic and does not know about tickets.
- **More:** [media-pipeline.md](media-pipeline.md).

### Notification (`ntf_`)

- **Story:** Omar pings Lena twice in an hour. The second ping is blocked by the limit. When Lena approves the code, the ping clears by itself.
- **Owns:** `ntf_ping`, `ntf_notification` (partitioned), `ntf_mute`, `ntf_delivery` (email digest state).
- **Api:**
  - `PingCommands::ping(TicketId, UserId $from, UserId $to, string $body): PingResult` (sent, or rate limited until …)
  - `NotificationQueries::bell(UserId): BellView` (grouped by person and ticket)
  - `NotificationQueries::isMuted(UserId, TicketId): bool`
- **Commands (more):** MarkPingDone, Mute, Unmute.
- **Events:** `PingSent`, `PingCleared`.
- **Consumes:** `TicketMoved`, `TicketAssigned`, `QuestionAsked`, `QuestionAnswered`, `RoundShared`, `TicketChecked`, `TicketCreated`. Acting on a ticket auto-clears your pings on it.
- **Depends on:** Organization Api (settings), Ticketing Api (visibility).
- **More:** [realtime-and-notifications.md](realtime-and-notifications.md), [ADR-0024](../03-adr/0024-notifications-and-pings.md).

### Insight (`ins_`)

- **Story:** Lena opens Home. pinround does not scan 200k tickets. It reads her 30 rows from `ins_ticket_turn` and sorts them by score.
- **Owns:** `ins_ticket_turn` (turn user, turn label, kind, base score, waiting since, group, checker), `ins_team_counts` (open tickets per team and group, for managers), `ins_release_progress`.
- **Api:**
  - `TurnProjector::project(TicketState, TeamFacts, ?OpenQuestion): void` (called synchronously by Ticketing and Conversation)
  - `HomeQueries::needsYou(UserId, OrganizationId): NeedsList` (top 5 + grouped rest)
  - `HomeQueries::whoseTurn(ProjectFilter): list<TurnGroup>`
  - `HomeQueries::workload(TeamId): list<Load>`
  - `HomeQueries::myReports(UserId): ReportTracking`
  - `HomeQueries::teamsAtAGlance(OrganizationId): list<TeamBar>`
- **Consumes:** all ticket, conversation and round events (for counts and history). The turn row itself is updated synchronously.
- **Depends on:** Workflow Api (group of a step), Ticketing Api (row details for display).
- **ADRs:** [0015](../03-adr/0015-turn-read-model.md).

### Assist (`ast_`)

- **Story:** Mia asks "Explain it to me". The answer is private to her. She shares it, and Lena makes it the main summary. The old summary stays in history.
- **Owns:** `ast_explanation` (private or shared), `ast_suggestion` (intake suggestions as JSON), `ast_embedding` (pgvector, optional), `ast_usage` (tokens and model per call, no content), `ast_local_package` (prompt packages issued for laptop AI, short-lived).
- **Api:**
  - `AiPolicy::for(UserId, OrganizationId): PolicyView` (on, who, what is sent, where it runs)
  - `Summaries::request(TicketId, UserId): JobId`
  - `Explanations::ask(TicketId, UserId, string $prompt): JobId`
  - `IntakeAssist::suggest(DraftReport): JobId`
  - `LocalAi::package(TicketId, Purpose, UserId): PromptPackage`
  - `LocalAi::accept(PackageId, string $output, string $model, UserId): void`
- **Events:** `SummaryGenerated`, `ExplanationShared`, `SuggestionReady`.
- **Depends on:** Ticketing Api, Review Api, Organization Api (policy settings).
- **More:** [ai-architecture.md](ai-architecture.md), ADRs [0018](../03-adr/0018-ai-provider-abstraction.md), [0019](../03-adr/0019-browser-local-ai.md), [0020](../03-adr/0020-ai-safety-and-prompt-injection.md).

### Integration (`int_`)

- **Story:** The Shop team links its Jira project. Old issues come in once. After that, a move in pinround moves the Jira issue, and a Jira webhook updates pinround.
- **Owns:** `int_jira_connection` (encrypted credentials), `int_project_link`, `int_status_mapping` (Jira status → step), `int_external_ref` (entity, id, system, external id, version), `int_sync_log`.
- **Api:** `ExternalRefs::find(string $system, string $externalId): ?EntityRef`
- **Commands:** Connect, LinkProject, MapStatus, ImportProject, HandleWebhook.
- **Consumes:** `TicketMoved`, `TicketCreated`, `CommentPosted` (outbound sync, when enabled).
- **Depends on:** Ticketing Api, Workflow Api.
- **More:** [integrations.md](integrations.md), [ADR-0025](../03-adr/0025-jira-integration.md).

### Audit (`aud_`)

- **Story:** Sara wants to know who turned off the lead check last week. The activity log shows the person, the time and the old and new value.
- **Owns:** `aud_event` (append-only, partitioned by month; P00.T7), `aud_export` (P11).
- **Api:** `AuditLog::record(AuditEntry)` (for actions that have no domain event, for example an MCP call or an export download). `AuditEntry` is built with `byUser`, `byToken` or `bySystem` (`ActorKind`); `record` writes in the caller's transaction and throws `AuditEntryForAnotherTenantRefused` for an entry of another organization than `TenantContext`'s. An entry built with `occurredAt` (an event consumer passes the event's time) is stored at that time, else at the clock's now. Implemented by `Infrastructure\DbalAuditLog`.
- **Readiness:** `Infrastructure\Health\NextAuditPartitionExists` (a `ReadinessCheck`): `aud_event` has next month's partition (UTC).
- **Consumes:** admin, settings, role, token, login, AI-policy, export and ticket-move events. Built so far: `Application\RecordOrganizationAuditEntry` writes one row per Organization event, once per message (`OnceOnlyHandling`), with actions `member.joined`, `member.invited` (target: the invitation, no data), `org_roles.changed`, `team.created` and `team_role.changed`, at the event's `occurredAt`, and with exactly the data keys of its `DATA_KEYS` allow-list (`requireAllowListedData` refuses others); it is tagged as an `event.bus` handler in `config/services.yaml`, as Application code cannot use `#[AsMessageHandler]`.
- **Depends on:** SharedKernel only; its Application and Infrastructure may also listen to the events (`Api\Event`) of every module, as every module's may (deptrac.yaml).
- **ADRs:** [0026](../03-adr/0026-audit-log.md).

### AgentApi (`api_`)

- **Story:** Claude Code calls `get_round_notes` with Omar's token. It gets exactly what Omar may see, and nothing from another team.
- **Owns:** `api_idempotency_key` (safe retries for POST).
- **Offers:**
  - REST under `/api/v1` (browser capture plugin, CLI);
  - MCP tools at `/mcp`: `list_my_tickets`, `get_ticket`, `get_round_notes`, `add_screenshot`, `set_verdict`, `add_comment`, `move_ticket`.
- **Rule:** thin adapters only. Each tool calls another module's Api, after the same Voter check the web UI uses. Token scopes are checked first.
- **Depends on:** Identity, Ticketing, Review, Conversation, Media Api.
- **ADRs:** [0021](../03-adr/0021-mcp-server.md).

### Web (UI shell)

- **Purpose:** the layout, the navigation (built from the user's roles), Home composition with focus chips, and the design-system Twig components.
- **Rule:** no business logic. Web only puts the other modules' UI pieces together.
- **Owns:** `web_home_focus` (each user's Home focus chip per organization, saved by `POST /home/focus`; see [data-model.md](data-model.md#web)). Prefix `web_`, for UI preference tables only ([ADR-0035](../03-adr/0035-web-owns-its-ui-preferences.md)).
- **Health:** `UI\Controller\HealthController` serves `/health/live` and `/health/ready`, which runs every tagged `ReadinessCheck` ([operations.md](operations.md#observability)).
- **Depends on:** Api of Insight, Organization, Notification and Ticketing (read only).
- **More:** [frontend.md](frontend.md).

## Event catalogue

All events extend `SharedKernel\DomainEvent`. Every event carries `eventId`, `occurredAt`, `organizationId` and `actorId`. Until SharedKernel has that base, the Organization events implement `Organization\Api\Event\OrganizationEvent` (`organizationId`, `actorId`: the acting user's id or null for the system, `occurredAt`), and the message's `MessageIdStamp` stands in for `eventId`. "Outbox" means the event is stored in the same transaction and delivered at least once to async handlers.

| Event | Producer | Consumers | Payload (besides the base) | Delivery |
|---|---|---|---|---|
| TicketCreated | Ticketing | Insight, Notification, Audit, Assist, Integration | ticketId, projectId, teamId?, reporterId, assigneeId?, checkReasons[] | outbox |
| TicketMoved | Ticketing | Insight, Notification, Audit, Integration | ticketId, fromStep, toStep, transition | outbox (turn row synchronous) |
| TicketAssigned | Ticketing | Insight, Notification, Audit | ticketId, assigneeId, previousId? | outbox |
| TicketChecked | Ticketing | Insight, Notification, Audit | ticketId, checkerId | outbox |
| TicketClosedAsDuplicate | Ticketing | Insight, Notification, Audit | ticketId, duplicateOfId | outbox |
| TicketVisibilityChanged | Ticketing | Insight, Notification, Audit | ticketId, visibility | outbox |
| SummaryChanged | Ticketing | Audit | ticketId, source, authorId | outbox |
| CommentPosted | Conversation | Notification, Integration | ticketId, messageId | outbox |
| QuestionAsked | Conversation | Insight, Notification | ticketId, messageId, toUserId | outbox (turn row synchronous) |
| QuestionAnswered | Conversation | Insight, Notification | ticketId, messageId | outbox (turn row synchronous) |
| ReplyPosted | Conversation | Notification | ticketId, messageId, replyId | outbox |
| CommentResolved | Conversation | — (UI only) | ticketId, messageId | outbox |
| RoundStarted | Review | Audit | ticketId, roundId, kind | outbox |
| RoundShared | Review | Notification, Insight, Audit | ticketId, roundId, number | outbox |
| PinVerdictSet | Review | Insight, Notification | ticketId, roundId, pinId, verdict | outbox |
| ChecklistItemChanged | Review | Insight | ticketId, itemId, state | outbox |
| MediaReady / MediaRejected | Media | Review, Ticketing | mediaId, reason? | outbox |
| PingSent / PingCleared | Notification | Insight (bell counts), Audit | pingId, ticketId, from, to | outbox |
| SummaryGenerated | Assist | Ticketing (stores the summary), Notification | ticketId, model, source | outbox |
| ExplanationShared | Assist | Notification | ticketId, explanationId | outbox |
| SuggestionReady | Assist | Web (Mercure push to the requester) | requestId, userId | outbox |
| MemberJoined, MemberInvited, OrgRolesChanged, TeamCreated, TeamRoleChanged, SettingsChanged | Organization | Audit, Insight, Organization (MemberInvited only, through `QueueInvitationLinkEmailHandler`) | ids + old/new values | outbox |
| StepAdded, StepRemoved, RulesChanged | Workflow | Workflow (`InvalidateWorkflowCacheHandler`, clears the team's cache); Audit, Insight (none built yet) | `Workflow\Api\Event\WorkflowEvent`: organizationId, teamId?; StepAdded: stepId, groupCode; StepRemoved: stepId, removedAt; RulesChanged: ruleCode, isOn. No actor, no `occurredAt` except `removedAt` | outbox |
| UserLoggedIn, LoginFailed, MfaEnabled, ApiTokenIssued, ApiTokenRevoked | Identity | Audit | userId, ipHash, tokenId? | outbox |

Rules:

- **An event is a fact in the past tense.** It never asks someone to do something.
- **Consumers must be idempotent.** Wrap the effect in `OnceOnlyHandling::runOnce`, keyed by the `MessageIdStamp` id and a stable handler name, or upsert by natural key ([ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md#idempotency-key)).
- **Event classes are public** (they live in `Api/Event`). Changing a payload means adding fields only. To remove a field, create a new event version.

## Deptrac rules

`composer deptrac` runs in `composer qa` and in CI ([ADR-0028](../03-adr/0028-testing-and-quality-gates.md)). The one hand-written config is `deptrac.yaml`. It analyses `src` only and runs with `--fail-on-uncovered --report-uncovered`: a class that is outside every layer (and is not a PHP built-in) fails the run. The call edges between modules are the "Depends on" lines in the catalogue above, as decided in [ADR-0032](../03-adr/0032-module-call-edges-follow-modules-catalogue.md).

**Layers.** Layers are collected by namespace regex (for example `^App\\Ticketing\\Domain\\`), not by folder.

- Per module M, six layers: `M_Api` (`App\M\Api\`, except `Api\Event\`), `M_Event` (`App\M\Api\Event\`), `M_Domain`, `M_Application`, `M_Infrastructure`, `M_UI`. The modules are Identity, Organization, Workflow, Ticketing, Conversation, Review, Media, Notification, Insight, Assist, Integration, Audit, AgentApi and Web.
- `SharedKernel`: `App\SharedKernel\`.
- `Vendor`: every class that is not `App\` and not a PHP built-in.
- `Kernel`: `App\Kernel` only.

**Rules.** D(M) is the set of modules in M's "Depends on" line in the catalogue above. E is the `Event` layer of every module, including M's own. Anything not listed fails the build.

| From | May depend on |
|---|---|
| `M_Api` | `SharedKernel` |
| `M_Event` | `SharedKernel` |
| `M_Domain` | `SharedKernel` |
| `M_Application` | `M_Domain`, `M_Api`, `SharedKernel`, `D(M)_Api`, E |
| `M_Infrastructure` | `M_Domain`, `M_Application`, `M_Api`, `SharedKernel`, `Vendor`, `D(M)_Api`, E |
| `M_UI` | `M_Application`, `M_Api`, `SharedKernel`, `Vendor`, `D(M)_Api` |
| `SharedKernel` | `Vendor` |
| `Kernel` | `Vendor`, `SharedKernel` |

- Calls into another module go through `D(M)_Api` only, and only for modules in M's "Depends on" line.
- Any module's Application and Infrastructure may use any module's event classes (E), so a module can listen to events of modules it does not call. UI may not use events.
- Only Infrastructure and UI get `Vendor`. Api, Event, Domain and Application do not.
- No module may touch another module's Domain, Application, Infrastructure or UI.

**Tests.** `tests/Unit/Architecture/DeptracConfigTest.php` checks that every `src/` folder has its six layers, every module layer has a folder, every layer in the ruleset exists, only Infrastructure and UI get `Vendor`, and Application and Infrastructure get every Event layer. `tests/Unit/Architecture/ModuleSkeletonTest.php` checks that every module folder holds exactly `README.md` and the five layer folders. `tests/Integration/Architecture/ModuleBoundaryViolationTest.php` runs Deptrac with the arguments of the `deptrac` composer script on four fixtures in `tests/Fixtures/Deptrac`: `ReviewUsesTicketingDomain` must fail with `Review_Application on Ticketing_Domain`, `ReviewUsesUncoveredClass` must fail as uncovered, `AuditUsesTicketingApi` must fail with `Audit_Infrastructure on Ticketing_Api` (Ticketing is not on Audit's "Depends on" line), and `AllowedDependencies` (Review Application using Ticketing Api, Audit Infrastructure using a Ticketing event) must pass. Each fixture config imports the real `deptrac.yaml`; Deptrac merges the `paths` of imported files and resolves them against the importing file's folder, so `./src` points at the fixture.

## Rules for adding a module

Add a module only if **all** of these are true:

1. It owns data that no current module owns, and it has its own words (a new part of the glossary).
2. Its rules change for different reasons than its neighbours' rules.
3. You can name its Api in five methods or fewer at the start.

Then:

- write an ADR or update [ADR-0002](../03-adr/0002-modular-monolith.md);
- pick a table prefix;
- add both Deptrac layers;
- add it to this catalogue and to the event catalogue;
- add tenant-isolation tests for its tables ([ADR-0005](../03-adr/0005-multi-tenancy.md)).

If you are unsure, put the feature inside the closest module first. Moving code out later is cheaper than merging two modules back together.
