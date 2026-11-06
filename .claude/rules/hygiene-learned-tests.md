---
paths:
  - "tests/**"
---
<!-- managed by /hygiene-learn — delete a bullet to retire that rule -->
# Learned rules (from review findings)

- Assert the exact status code the plan names (`assertResponseStatusCodeSame(200)`), not any success. <!-- class: test-quality, source: 20261106-6f62 -->
- A test that proves something is absent (not registered in prod) also proves the same lookup finds it where it should be, and never trusts a cached container. <!-- class: test-quality, source: 20261106-842b, 20261106-842c -->
- A check that matches a pinned value matches its exact shape (a 40-hex SHA), not just the key name. <!-- class: test-quality, source: 20261106-b4eb -->
