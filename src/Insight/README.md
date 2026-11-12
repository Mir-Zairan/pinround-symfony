# Insight

Read models for Home and manager views: whose turn it is, team counts and release progress. Home reads ready rows and never scans tickets. It owns the tables `ins_ticket_turn`, `ins_team_counts` and `ins_release_progress`. Its Api is `TurnProjector::refresh(TicketId)`, which rewrites one ticket's turn row in the caller's transaction from the facts of the `TicketTurnSource` port and returns the turn's override note, `TurnUserQueries::turnUsersOf(list<TicketId>)`, the turn users of the tickets list's shown rows in one query on `ins_ticket_turn` (no visibility filter: the caller passes only ids the viewer may see), and `HomeQueries`. It may use the Api of Workflow and Ticketing.

See the [module catalogue](../../docs/02-architecture/modules.md#insight-ins_).
