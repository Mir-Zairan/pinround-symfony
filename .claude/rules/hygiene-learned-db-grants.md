---
paths:
  - "migrations/**"
  - "docker/postgres/**"
  - "tests/Integration/Tenancy/**"
---
<!-- managed by /hygiene-learn — delete a bullet to retire that rule -->
# Learned rules (from review findings)

- A migration that creates a table or changes its grants comes with a `DatabaseRolesTest` case that pins the exact rights of each role with `assertEqualsCanonicalizing`. <!-- class: missing-test, source: 20261107-7b17, 20261107-010f, 20261106-dfe4, 20261106-7bba -->
- Grant on the partitioned parent table only, never on a child partition; code that creates a partition also runs `TenantTable::enableRls` on it (data-model.md). <!-- class: authz, source: 20261106-af7c, 20261106-8c77, 20261106-a7d7, 20261106-bef7, 20261106-b33c -->
