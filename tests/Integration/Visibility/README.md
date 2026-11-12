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
| Ticket page | planned | P03.T6 |
| Bell and email | planned | P04 |
| Rounds | planned | P05 |
| Duplicate suggestions | planned | P06 |
| Home, Whose turn, My reports | planned | P07 |
| AI inputs | planned | P08 |
| MCP tools | planned | P09 |
| Export | planned | P11 |
