---
paths:
  - 'app/**'
  - 'tests/**'
  - 'database/**'
  - 'routes/**'
  - 'config/**'
---

# Code Style

## Clean code

- Keep code clean, readable and maintainable. Prefer clarity over cleverness.
- Follow PSR-12 through Laravel Pint: run `vendor/bin/pint --dirty --format agent` after changing PHP.
- Use descriptive names: `isRegisteredForDiscounts`, not `discount()`.
- Make the smallest change that solves the problem. Do not refactor unrelated code unless asked.
- Fix the root cause, not the symptom. No suppressed exceptions or workaround middleware.

## Comments

- Comment only when the intent is not obvious. Never comment obvious logic.
- Prefer PHPDoc blocks to inline comments for anything complex.
- Add PHPDoc to public methods when it adds information, especially `@throws` and array shapes.

## Modern PHP and Laravel

- Use PHP 8.4 features where they help: `readonly` properties, named arguments in complex calls, `match`, constructor property promotion.
- Explicit return types and parameter types on every method. Curly braces on every control structure. No empty zero-parameter constructors.
- Use backed enums for fixed value sets, cast on the model.
- Define casts in a `casts()` method. Use `withDefault()` when it makes relationship access safer.
- Prefer `Model::query()` to `DB::`. Do not bypass the ORM without a reason.
- Create files with `php artisan make:` and `--no-interaction`. Check options with `php artisan <command> --help` and discover commands with `php artisan list`.
- Use named routes and `route()` for internal URLs.
- Enable strict models (`Model::shouldBeStrict()`) only through a deliberate, separately tested change: it is not on today.

## Naming

| Item | Convention | Example |
|---|---|---|
| Classes and files | `PascalCase` | `CreateParty.php` |
| Methods and variables | descriptive `camelCase` | `isEligibleForRelease` |
| Tables | plural `snake_case` | `import_jobs` |
| Models | singular `PascalCase` | `ImportJob` |
| Migrations | descriptive `snake_case` | `create_parties_table` |
| Routes | `dot.notation` | `platform.tenants.show` |
| Config keys | `snake_case` | `supported_locales` |
| Blade views | `kebab-case` or `snake_case` in subfolders | `locale-switcher.blade.php` |
| Constants | `SCREAMING_SNAKE_CASE` | `MAX_ATTEMPTS` |
| Enum keys | `TitleCase` | `BillingView` |

## Imports

- Let Pint order `use` statements and remove unused ones.
- Use PSR-4 autoloading. Never `require` or `include` manually.
