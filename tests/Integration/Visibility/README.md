# Visibility leak suite

Every read path that can show a ticket, or a number that counts one, proves here that it keeps the ticket visibility predicate ([ADR-0013](../../../docs/03-adr/0013-authorization.md)).

## The cast

`VisibilityLeakCast` commits, once per test class, a team-only SHOP ticket titled `LEAK-MARKER team-only` (reported by Mia, outside the Shop team), a team-only ticket with no team in the unlinked project OPS, and seven viewers: Lena (Shop lead), Mia (outside reporter), Sara (admin, no team role), Omar (triager, no team), Raj (manager, no team role), Noor (member, no link) and Ben (admin of Beta Bakery).

Its data provider `viewers()` yields `[viewer, seesTeamOnly, seesNoTeamTicket]`. `asViewer()` runs a closure with the viewer's token in a rolled-back transaction under their organization, as a request would.

## Adding a path

1. Add one class per read path, `<Path>LeakTest extends VisibilityLeakCast`.
2. Iterate `viewers()`: read the path as each viewer and assert the marker ticket appears exactly when `seesTeamOnly` says so (and the no-team ticket when `seesNoTeamTicket` does).
3. For a count, assert it never includes a ticket the viewer who reads it may not see.
4. Add the path to the list below.

## Paths

| Path | Class | Phase |
| --- | --- | --- |
| TicketVoter VIEW | `TicketVoterViewLeakTest` | P03.T2 |
| Team page project counts, step count | `TicketCountsLeakTest` | P03.T2 |
| Ticket list | `TicketListLeakTest` | P03.T3 |
| Search function `search_ticket_ids` (visibility written out in its migration): each viewer finds what `TicketVisibilitySql` lets them see, an outsider in any person column finds that ticket, no other organization's ticket | `TicketSearchFunctionLeakTest` | P03.T4 |
| Ticket list with a search (`/tickets?q=`): the function's matches joined back under `TicketVisibilitySql`, by title words and by key, no other organization's ticket | `TicketSearchLeakTest` | P03.T4 |
| Project rail counts (`ProjectCountQueries::countsOfTeam`, from `ins_project_counts`): the ticket's own team and admins count the team-only ticket, anyone else (its outside reporter, a new team of its project) does not | `ProjectCountsLeakTest` | P03.T5 |
| Person picker "N open" (`AssigneeTicketCounts::openTicketsAssignedTo`): both cast tickets assigned to one person, each viewer counts exactly those they may see | `PersonPickerCountsLeakTest` | P03.T5 |
| Ticket page (`/t/{key}`): `TicketQueries::pageOf` under the viewer's organization, then `VIEW` on the page's own visibility facts; each viewer gets the title exactly when they may see the ticket, else the 404 an unknown key gets; its parent's key only when they may see the parent; its "N of M" (`TicketQueries::positionOf`) never counts a ticket they may not see, and Previous and Next skip it and always open | `TicketPageLeakTest` | P03.T6 |
| Notification delivery (`NotifyRecipients` writing `ntf_notification` as worker-notify handles an event): each viewer, asked a question about each cast ticket, gets a notification exactly when they may see that ticket | `NotificationDeliveryLeakTest` | P04.T5 |
| Batch visibility check (`TicketQueries::visibleTicketIdsOf`, for modules that keep ticket ids): each viewer gets back exactly the cast tickets they may see, once each, never an unknown id or another organization's ticket; an empty list costs no query, more than 200 ids, a viewer of another organization than the tenant and no open transaction are refused before any query | `VisibleTicketIdsLeakTest` | P04.T5 |
| Bell (`NotificationQueries::bell`, `BellInbox` count, Done and Open): each viewer, with an open question about each cast ticket, sees, counts and changes exactly those of tickets they may see, and Done and Open on a hidden one leave its row as it was; a person who leaves the ticket's team loses it from the bell, a cold count and Done | `BellLeakTest` | P04.T5 |
| Rounds section (`RoundQueries::forTicket`): each viewer gets a cast ticket's Round 0, with its screenshot and pin, exactly when they may see that ticket; a member outside the team, a manager with no team role and another organization's admin get none of the team-only ticket's | `RoundListLeakTest` | P05.T4 |
| Intake candidates (`IntakeCandidateQueries::openCandidatesIn`, the ticket keys and titles the intake check sends to an AI, and `openCandidatesByKeys`, the ones it shows the reporter): with team-only tickets left out, no viewer gets one, not even the team's lead, its reporter, an admin or the triager who may see it; with them included, each viewer gets exactly the team-only tickets they may see; every Acme Foods viewer gets the project's open ticket for everyone, and another organization's admin only their own organization's ticket in a project with the same id; asked by key, each viewer gets exactly the cast tickets they may see | `IntakeCandidateLeakTest` | P08.T7 |
| Duplicate suggestions | planned | P06 |
| Home, Whose turn, My reports | planned | P07 |
| AI inputs | planned | P08 |
| MCP tools | planned | P09 |
| Export | planned | P11 |
