# Allowed dependencies

Review Application uses Ticketing's Api, and Audit Infrastructure listens to a Ticketing event. Both are allowed,
so `ModuleBoundaryViolationTest` expects a clean run.
