---
paths:
  - 'database/**'
  - 'app/Models/**'
  - 'app/Actions/**'
---

# Database and Performance

## Migrations

- **Never edit a migration that has shipped.** Changes go in a new migration.
- New migrations are allowed only inside an approved milestone. Ask before adding one outside that scope.
- Put tenant tables in `database/migrations/tenant` and central tables in `database/migrations` (BD-T10, BD-T11). A tenant migration reaches existing tenants through `php artisan tenants:migrate`: state that in the deployment notes.
- When modifying a column, restate all its existing attributes or they are dropped.
- Do not break existing routes, schema, API contracts or UX flows unless told to. Version an API or stay backward compatible when changing its responses.
- Never run `migrate:fresh`, `db:seed` or other destructive commands against production without explicit confirmation. Tests use the test database only.

## Queries

- Avoid N+1: eager load with `with()` when reading relations in loops. Limiting eager loaded records with `$query->latest()->limit(10)` is supported natively.
- Use `select()` to fetch only the needed columns on performance-sensitive paths.
- Index columns used in `WHERE`, `ORDER BY` and `JOIN` on tables expected to grow.
- Use `pluck()` or `value()` for scalar reads instead of hydrating models.
- Use `chunk()` or `cursor()` for large datasets. Never load every record into memory.
- Use `Cache::remember()` for expensive repeated queries. Cache keys must be tenant-aware.
- Use `DB::transaction()` for atomic multi-step writes.
- Keep heavy logic out of Blade.

## Models

- Protect against mass assignment. Follow the model's existing approach; audited Actions use `forceFill()` with validated values only.
- Keep query logic in scopes or custom builders (`app/Models/Builders`), not duplicated across Actions.
