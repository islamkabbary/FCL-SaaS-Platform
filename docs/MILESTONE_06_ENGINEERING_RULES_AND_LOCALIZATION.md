# Milestone 06: Engineering Rules and Arabic Localization

Date: 2026-11-04

This milestone did two things:

1. It imported the engineering rules of the Deli Callcenter project and split them into files that `CLAUDE.md` points to.
2. It made the whole interface bilingual: Arabic (RTL) and English (LTR), on the tenant application and the platform administration.

## 1. Engineering rules

### Structure

Rules live in `.ai/rules/`, the location Boost already reads. `.claude/` and `AGENTS.md` are ignored by Git, so rules placed there would not reach the team. `CLAUDE.md` and the local `AGENTS.md` list the files and require reading the matching ones before any edit.

Each file starts with `paths:` front matter, and `index.md` uses the format of Boost's `RuleRepository`. `php artisan boost:update` was run and changed none of these files.

### Where each Deli section went

| Deli section | Destination | Adaptation |
|---|---|---|
| Stack | `architecture.md` | Actual versions: Laravel 13, PHP 8.4, Flux UI |
| 0 Project-specific rules | `architecture.md`, `ui-design.md`, `database-performance.md`, `localization.md`, `icons.md`, `testing.md` | See decisions below |
| 1 Clean code | `code-style.md` | None |
| 2 Small files | `architecture.md` | None |
| 3 Comments | `code-style.md` | None |
| 4 Livewire and Services | `architecture.md` | Actions replace Services |
| 5 SOLID, DRY | `architecture.md` | None |
| 6 Root cause | `code-style.md`, `workflow.md` | None |
| 7 Minimal changes | `code-style.md`, `database-performance.md` | `php artisan list` replaces `list-artisan-commands` |
| 8 No breaking changes | `database-performance.md` | Shipped migrations are frozen; new ones are allowed in an approved milestone |
| 9 Conventions | `architecture.md` | None |
| 10 Shared code | `architecture.md` | `app/Concerns` replaces `app/Core` and `app/Shared` |
| 11 Performance | `database-performance.md` | Cache keys must be tenant-aware |
| 12 Business logic | `architecture.md`, `security-errors.md` | Actions replace Services; validation may live in the Action |
| 13 Errors | `security-errors.md` | None |
| 14 Dependencies | `workflow.md` | None |
| 15 Security | `security-errors.md` | Tenancy and impersonation rules added |
| 16 Modern PHP | `code-style.md` | PHP 8.4 and Laravel 13; strict models noted as not enabled |
| 17 UI and UX | `ui-design.md` | Flux first |
| 18 Team mindset | `workflow.md` | Documentation rule follows the milestone workflow |
| 19 Duplication | `architecture.md` | None |
| 20 Naming | `code-style.md` | Enum keys `TitleCase`; the `_` prefix for private members dropped (unused here, against PSR-12) |
| 21 Use statements | `code-style.md` | Pint decides the order |
| 22 Testing | `testing.md` | Tenant isolation and impersonation added |
| 23 Separation of concerns | `architecture.md` | None |
| 24 Laravel structure | `architecture.md` | The ban on `app/Http/Middleware` dropped (the project has 12 middleware classes) |
| 25 Git and PR output | `workflow.md` | Conventional Commits kept; feature branches and PRs not imported |
| 26 Localization | `localization.md` | Keys are English sentences in `lang/ar.json`, matching the existing `__()` calls |
| 27 Responsive | `ui-design.md` | None |
| 28 Icons | `icons.md` | Flux icons replace Font Awesome; every other part kept |

Not imported: Deli's `.claude/settings.json` (permissions specific to that project and machine).

### Decisions

| Topic | Decision | Reason |
|---|---|---|
| Architecture | Keep FCL's Actions and single-file Livewire pages | Validation, authorization and audit sit inside each Action, so every caller gets them. Moving to Services means rewriting 32 Actions with no benefit |
| Controllers | New UI uses Livewire; existing controllers stay | The Platform area already uses them |
| Migrations | Shipped ones are frozen; new ones are allowed in an approved milestone | M1 still needs tables |
| Icons | Flux icons | Inline SVG with no extra download, already used in 117 places, matches Flux |
| Language | Arabic and English are enforced now | Project decision, recorded as BD-S07 |
| Git | `main`, no automatic push, and the identity of the current Git account with no trailer | Rules 5, 6, 11 and 12 of `CLAUDE.md` were changed to say so |

## 2. Localization

### How the language is chosen

`app/Http/Middleware/SetLocale.php` applies the first supported value of:

1. The signed-in account's `locale` column.
2. The session.
3. `config('app.locale')`, which stays `en`.

While impersonating, the session comes before the account, so the person acting can choose a language without changing the account's preference.

`SetLocale` is in the `web` group and in the priority list right after `EnsureUserIsActive`. **It must stay after `ScopeSessions`**: it reads the authenticated user, and reading it earlier could resolve a user from another tenant. A test protects this order.

### The switcher

- `resources/views/partials/locale-switcher.blade.php` is in the sidebar, the header, the platform layout and the auth layouts. The welcome page has its own copy, because it loads no Flux CSS.
- `SwitchLocaleController` handles `POST /locale` (`locale.switch` on tenants, `central.locale.switch` on the central domain), with validation, CSRF and `throttle:20,1`.
- `locale.switch` is on the `RestrictionPolicy` route allowlist. During impersonation it writes only to the session, so it never changes tenant data (BD-SEC01). `UpdateLocalePreference` runs only outside impersonation.

### Translations

- `lang/ar.json`: all application strings, Flux strings, framework mail strings, error titles and audit field names.
- `lang/en/*.php` (published) and `lang/ar/{auth,pagination,passwords,validation}.php`, including Arabic validation attribute names.
- Permission badges on the Roles page now show the translated label instead of the raw code, in both languages.
- `TenantRole::displayName()` translates built-in role names. Custom role names stay as entered.

### Direction

- `dir` is on every `<html>` (8 files).
- Physical utilities were replaced with logical ones.
- Directional icons use `rtl:[&_svg]:rotate-180`, because Flux's `icon:class` does not reach trailing icons.
- Technical values stay left to right with `<bdi dir="ltr">` or `dir="ltr"`: tax numbers, tenant IDs, commands, recovery codes, setup keys, temporary passwords and e-mail inputs.
- `html:lang(ar)` switches the font to Cairo, loaded through the existing Bunny fonts setup in `vite.config.js`.

### Dates

`toFormattedDateString()` and `toDayDateTimeString()` always print English, so views now use `translatedFormat(__('M j, Y'))` and `translatedFormat(__('D, M j, Y g:i A'))`. The English output is unchanged. Arabic has its own order through `lang/ar.json`, for example `25 أكتوبر 2027`. Digits stay Latin.

### Platform header

The visual check showed that the platform navigation already overflowed the page on tablets and phones. Below `lg` it now moves to its own scrollable row. The e-mail link appears only from `2xl`, and the brand no longer repeats "Platform".

## 3. Files

| Area | Files |
|---|---|
| Rules | `.ai/rules/*`, `CLAUDE.md`, local `AGENTS.md` |
| Code | `app/Http/Middleware/SetLocale.php`, `app/Http/Controllers/SwitchLocaleController.php`, `app/Actions/Localization/UpdateLocalePreference.php`, `app/Enums/TenantRole.php`, `app/Models/User.php`, `app/Models/PlatformAdmin.php`, `app/Impersonation/RestrictionPolicy.php`, `app/Http/Middleware/EnsureUserIsActive.php` |
| Wiring | `bootstrap/app.php`, `config/app.php`, `routes/web.php`, `routes/tenant.php`, `vite.config.js`, `resources/css/app.css` |
| Language | `lang/ar.json`, `lang/ar/*`, `lang/en/*` |
| Views | Layouts, the switcher partial and the views with dates, icons or left-to-right values |
| Tests | `tests/Feature/LocaleTest.php`, `tests/Feature/TranslationCompletenessTest.php` |
| Decisions | `docs/fcl/BUSINESS_DECISIONS.md`, `docs/fcl/OPEN_QUESTIONS.md`, `docs/fcl/M1_IMPLEMENTATION_PLAN.md`, `docs/fcl/PROJECT_CONTEXT.md` |

**Migrations:** `add_locale_to_users_table` (tenant) and `add_locale_to_platform_admins_table` (central). Both add one nullable column.

**Packages:** none added.

## 4. Tests

`LocaleTest` covers:

- switching as a guest, a tenant user and a Platform Admin, and refusing an unsupported language;
- the order of preference and session, and the fallback for a stored language that is no longer supported;
- `lang` and `dir` on the page, Arabic validation messages, and the language after a Livewire update;
- built-in and custom role names;
- impersonation leaving the user's preference unchanged;
- the middleware order.

`TranslationCompletenessTest` fails when:

- a `__()` key in `app/` or `resources/views` has no Arabic translation;
- a translation loses a placeholder;
- the `en` and `ar` framework files differ in keys;
- a view uses a physical direction utility;
- a document has no `dir`.

### Suite result

706 tests: 704 passed, 1 skipped, 1 failed. Packages installed during the run (`byrcsc/laravel-cartographer`, `g4t/schema-designer`, `albertoarena/laravel-truss`) were left out of the run, because they are not part of this milestone.

The failure is `records live only in the company database`, in `tests/Feature/Tenancy/TenantAuditLogTest.php:231`. It is not caused by this milestone, which does not change that test or the audit tables. The assertion calls `getTableListing()` without a schema, and on MySQL that lists every database on the server, including the tenant database the test itself creates. Passing the central database name to `getTableListing()` should fix it. Running it on the previous commit was not possible, so this comes from reading the code and from confirming the listing behaviour on the local server.

### Visual check

Chrome was driven through 19 pages at 390, 820 and 1440 pixels in Arabic, and 6 of them in English (75 renders), using a temporary database that was dropped afterwards. No page had the wrong `dir` or horizontal overflow in the final run.

## 5. Known issues

- **Stored text keeps the language it was created in.** Some text is saved at creation time, for example charge line descriptions from `ActivateSubscription` and audit reasons. A charge created by an admin using Arabic stays in Arabic for everyone. Translating at display time needs a separate design.
- **An unknown URL shows the error page in English.** A route that does not exist fails before `web` middleware, so no language is resolved. Laravel's default error pages also have no `dir` attribute. Custom error views would address this.
- **The Arabic text needs a human review.** It has not been reviewed by a native operations user. Check these terms first: "Tenant" as `الشركة`, "Charge" as `مطالبة`, "Party" as `طرف`, "Term" as `فترة`, "Provisioning" as `تجهيز`, "Impersonation" as `العرض بهوية المستخدم`, "Import job" as `ملف استيراد`. ACID and FCL stay in Latin letters, as in the research.
- **Stored data stays as entered.** The default branch `Main Branch` is data created in English during provisioning, and audit values appear as stored.
- **The welcome page is still Laravel's stock page,** now translated. Replacing it is a product decision.
- **Language after logout.** Logging out clears the session, so the login page returns to the default until the user signs in.

## 6. Deployment

```bash
php artisan migrate --force
php artisan tenants:migrate --force
npm run build
php artisan config:cache
```

## 7. Manual verification

1. Open a tenant login page and select `العربية`. The page should mirror, use Cairo, and show Arabic text.
2. Submit an empty login form. The error should be in Arabic.
3. Sign in, switch to Arabic, sign out and sign in again. Arabic should return.
4. Open Employees, Roles, Parties and Subscription on a phone width and a desktop width in both languages.
5. Open a support session, switch language, and confirm the user's saved language has not changed.
6. Open the platform dashboard in Arabic and confirm the arrows point left.
