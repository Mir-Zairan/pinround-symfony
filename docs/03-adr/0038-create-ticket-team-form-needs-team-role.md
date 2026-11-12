# ADR-0038 — Create ticket: team form needs a team role, Report a problem is open to every role

The simple team ticket form needs a role on the team; the intake's "Report a problem" stays open to every role.

## Index

- [Status](#status) — accepted, date, deciders, the ADR-0013 row it supersedes.
- [Context and problem statement](#context-and-problem-statement) — one matrix row, two create paths.
- [Decision drivers](#decision-drivers) — clean team boards, open reporting, least privilege.
- [Considered options](#considered-options) — one rule for both paths, or one rule per path.
- [Decision outcome](#decision-outcome) — `TicketCreationVoter` needs a team role; intake stays open.
- [Confirmation](#confirmation) — the voter matrix rows and unit tests.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [More information](#more-information) — related ADRs and docs.

## Status

Accepted · 2026-11-11 · Deciders: Zairan Mir (lead).

Supersedes in part [ADR-0013](0013-authorization.md): its permission matrix row "Report a problem (create ticket)".
The rest of ADR-0013 stands.

## Context and problem statement

Raj is a Manager at Acme Foods and a viewer of the Shop team. Sara is an Admin with no team role. Phase 3 builds a
simple "New ticket" form at `/tickets/new` that files a ticket straight onto a team's board, before the full
"Report a problem" intake arrives in phase 6. ADR-0013's matrix says every role may "Report a problem (create
ticket)", yet the phase-3 acceptance criteria say "A pure manager cannot create tickets through the form (voter)
unless they also have a team role" and "An admin without a team role cannot create or edit tickets (voter rows)"
([phase-03](../05-plan/phase-03-tickets-core.md#p03t1--ticket-aggregate-and-simple-create-)).

Which roles may create a ticket, and does the answer differ between the team form and the intake?

## Decision drivers

- A team's board holds the work its own people file; someone outside the team should not file onto it directly.
- Anyone in the organization, whatever their role, must be able to report a problem
  ([personas-and-roles.md](../00-product/personas-and-roles.md#permission-matrix)).
- Every action goes through a voter, deny by default (ADR-0013).

## Considered options

1. One rule for both paths: every role may create a ticket anywhere (ADR-0013's row as written).
2. One rule for both paths: only a team role may create a ticket.
3. One rule per path: the team form needs a Lead, Member or Viewer role on the team; the intake's "Report a problem"
   is open to every role.

## Decision outcome

Chosen option: **3, one rule per path**, because it keeps team boards for the team's own people while no one loses
the right to report a problem.

- The team ticket form (`/tickets/new`) checks `TicketPermission::CREATE` on the team's `TeamId` through
  `Ticketing\UI\Voter\TicketCreationVoter`. It grants a Lead, Member or Viewer of that team, in the actor's own
  organization, and denies everyone else: a Manager or Admin with no role on the team, a user outside the team, and
  anyone of another organization.
- The intake's "Report a problem" path (phase 6) stays open to every role. The intake, not the reporter, decides the
  team; it gets its own permission and voter when it is built, not `TICKET_CREATE`.

### Consequences

- Good: a team's board only gets tickets filed directly by people of that team.
- Good: reporting stays open to every role, as the product promises.
- Bad: a pure Manager or an Admin without a team role must use "Report a problem" (phase 6); until then they cannot
  create a ticket at all.
- Neutral: two create paths, two permissions.

## Confirmation

- `tests/Integration/Voter/VoterMatrixTest` decides the `TICKET_CREATE` rows of `voter_matrix_rows.php` through the
  app's access decision manager: granted for a Lead, a Member and a Viewer of the team, and for an Admin who also has
  a team role; denied for a Manager or Admin without a role on the team, a lead of another team, and a user of
  another organization.
- `tests/Unit/Ticketing/UI/Voter/TicketCreationVoterTest` holds the full unit matrix.

## Pros and cons of the options

### Option 1, every role creates anywhere

- Good, because it matches ADR-0013's row and the product promise word for word.
- Bad, because anyone could file straight onto any team's board, skipping the intake's checks.

### Option 2, only a team role creates

- Good, because it is one simple rule.
- Bad, because a pure Manager or Admin could never report a problem, which breaks the product promise.

### Option 3, one rule per path

- Good, because each path gets the rule that fits it.
- Bad, because two permissions must be kept apart and documented.

## More information

- Related: [ADR-0013](0013-authorization.md) (authorization), [ADR-0016](0016-direct-ticket-creation-with-check-flags.md)
  (direct ticket creation), [personas-and-roles.md › Permission matrix](../00-product/personas-and-roles.md#permission-matrix).
- Revisit when: phase 6 decides whether the simple form stays for leads as "New ticket" or goes.
