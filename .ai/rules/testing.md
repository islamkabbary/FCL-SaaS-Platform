---
paths:
  - 'tests/**'
---

# Testing

- **Every feature needs a Policy and a Pest feature test** covering create, update, validation and authorization, with both an allowed and a forbidden actor.
- Use Pest for all tests. Use unit tests only for pure logic tested in isolation.
- Use the factories and their states, and the helpers in `tests/Pest.php`.
- Do not remove tests or test files without approval. Do not break tests in the area you change.
- A feature with visible text is checked under both locales where the behavior can differ (see `localization.md`).
- A multi-tenant feature tests isolation between tenants. A write path tests the refusal during impersonation (BD-SEC01).
- Run the narrowest set of tests while working, then the whole suite as part of milestone verification.
