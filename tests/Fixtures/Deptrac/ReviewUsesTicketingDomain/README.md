# Deliberate boundary violation

`Review\Application\ShowTicket` imports `Ticketing\Domain\Ticket` on purpose. It is the documented proof that Deptrac
fails the build when a module uses another module's Domain. Do not "fix" the import; `ModuleBoundaryViolationTest` needs it.
