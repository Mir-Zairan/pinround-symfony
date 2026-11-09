# Allowed dependencies

Review Application uses Ticketing's Api, and Audit Infrastructure listens to a Ticketing event. Both are allowed,
so `ModuleBoundaryViolationTest` expects a clean run. Ticketing's demo seed step uses Identity's `UserQueries`, the
one skipped violation in `deptrac.yaml`; it is here because Deptrac fails on a skipped violation it never meets.
