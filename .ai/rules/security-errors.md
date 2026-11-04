---
paths:
  - 'app/**'
  - 'config/**'
  - 'routes/**'
  - 'bootstrap/**'
---

# Security and Error Handling

## Security

- Never hardcode secrets, tokens or credentials. Use `.env` and `config()`.
- Never call `env()` outside config files. Never commit `.env`.
- Do not log passwords, tokens or personal data.
- Use Eloquent or the Query Builder with bindings. Never put user input in raw SQL.
- Keep CSRF protection on web forms. An exemption needs a written reason and a replacement credential (see `support/handoff`).
- Hash passwords with `Hash::make()` or the model's `hashed` cast. Never store plain text.
- Validate all input at the boundary and again in the Action's validation class.
- Use Policies for all authorization. No inline role checks. Authorize in the Action, not only in the UI.
- Rate limit authentication and sensitive endpoints with `throttle`.
- Protect models against mass assignment.
- If an API is added, use Sanctum or Passport (Q-X04). Never build custom API auth.

## Multi-tenancy

- Tenant data stays in the tenant database, central data in the central database (BD-T10, BD-T11).
- No write is allowed during impersonation unless reviewed (BD-SEC01). A session-only preference is not a write to tenant data; a change to a user record is.
- Never read the authenticated user on a tenant route before `ScopeSessions` has verified the session.

## Errors

- Handle null, empty, missing and invalid states. No silent failures: log or show the error.
- Use custom exceptions for domain errors (`app/Exceptions`, for example `PossibleDuplicateParty`).
- Map exceptions in `bootstrap/app.php` to the right HTTP or JSON response.
- API errors use one consistent JSON structure.
- Validate external API responses before using them.
- Use `try/catch` only where the exception can be handled meaningfully. Never swallow one silently.
