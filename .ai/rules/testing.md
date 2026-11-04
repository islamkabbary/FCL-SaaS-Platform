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

## Running tests

- Run only the tests of the part being changed: the feature's own test files, and the existing test files that the change directly affects. This is enough for every feature and edit, including milestone verification.
- **Do not run the whole suite on your own**, because it takes a long time. When a change is important enough to need it (for example authentication, tenancy, or shared middleware), propose it with a one-line reason and wait for the owner's approval. If the owner declines, continue without it.
- Report exactly what was run. Never describe a partial run as the whole suite.
