---
paths:
  - "tests/**"
---
<!-- managed by /hygiene-learn — delete a bullet to retire that rule -->
# Learned rules (from review findings)

- After every request in a functional test, assert the exact status code first (`assertResponseStatusCodeSame(200)`), not any success: an error page passes most other checks. <!-- class: test-quality, source: 20261106-6f62, 20261107-cfa2, 20261107-793a -->
- A test that proves something is absent (not registered in prod) also proves the same lookup finds it where it should be, and never trusts a cached container. <!-- class: test-quality, source: 20261106-842b, 20261106-842c -->
- A check that matches a pinned value matches its exact shape (a 40-hex SHA), not just the key name. <!-- class: test-quality, source: 20261106-b4eb -->
- For every rule a config enforces (Deptrac layers, compose services, scheduler transports), write a test that fails when the rule is removed: one forbidden case, one allowed case, and exact-list asserts (`assertEqualsCanonicalizing`), never `assertContains` alone. <!-- class: missing-test, source: 20261106-515b, 20261106-515d, 20261106-5d8b, 20261106-5d8c, 20261106-5d8d, 20261106-7c65, 20261106-7c66 -->
- A test that needs no kernel, container or database goes in `tests/Unit` at the same path as its class and extends `TestCase`, not in `tests/Integration`. <!-- class: test-quality, source: 20261107-6acc, 20261107-010b, 20261107-e27e -->
- Name a test class after its subject (`AvatarComponentTest`), never after when it was written (`NewComponentsTest`); name each test method after the behaviour it proves. <!-- class: naming, source: 20261107-793b, 20261107-b113, 20261107-58c6, 20261106-515e, 20261107-a73a, 20261106-7427, 20261107-76bc -->
- A test whose result depends on the date (audit partitions, months) freezes time with a `MockClock` through `ClockInterface`, also for services the request reaches; never compute the expected value from the wall clock (`gmdate()`, `new DateTimeImmutable()`). Freeze it at a fixed time whose expected result differs from what the wall clock would give (another day, another hour), so code that ignores the clock fails. <!-- class: time-handling, source: 20261107-6acd, 20261107-6e07, 20261108-a890, 20261108-03c4 -->
