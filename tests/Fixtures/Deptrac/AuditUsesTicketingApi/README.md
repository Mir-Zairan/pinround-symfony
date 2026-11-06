# Deliberate Api call outside "Depends on"

Audit depends on SharedKernel only, so `Audit\Infrastructure\ReadTicketTitle` using Ticketing's `TicketQueries` is a violation
on purpose. `ModuleBoundaryViolationTest` needs it. Do not "fix" the import.
