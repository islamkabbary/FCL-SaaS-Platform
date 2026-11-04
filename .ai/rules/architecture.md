---
paths:
  - 'app/**'
  - 'routes/**'
  - 'bootstrap/**'
---

# Architecture

## Stack

PHP 8.4, Laravel 13, Livewire 4 (single-file page components), Flux UI (free), Tailwind CSS 4, Alpine.js, Pest, Pint, stancl/tenancy (one database per tenant). Confirm a package's installed version before relying on its API.

## Layers

- **Actions own the business logic.** `app/Actions/<Feature>/` holds one class per operation with a `handle()` method (see `app/Actions/Fcl/CreateParty.php`). They are the single place where decisions and operations happen. Do not introduce an `app/Services` layer.
- **An Action enforces its own rules:** authorization through `Gate::forUser($actor)`, validation through a dedicated class such as `ValidatePartyAttributes`, `DB::transaction()` for multi-step writes, and the audit record. Every caller then gets the same protection.
- **Actions never receive a `Request`.** Pass the actor and validated or plain data. They are stateless and resolved from the container.
- **Livewire pages are thin:** handle the interaction, call an Action, render. No business logic in components, routes or Blade.
- **Blade only displays.** No queries or heavy logic, and no model calls beyond simple attribute access or a computed value from the component.
- **API Resources format API responses** and use `whenLoaded()` so they trigger no extra queries. Do not format API data ad hoc.

## New UI and controllers

- New user-facing pages are Livewire single-file components under `resources/views/pages/`, routed with `Route::livewire()`.
- A new controller is allowed only for an API endpoint, a webhook, or an HTTP endpoint with no page of its own (for example `EndImpersonationController`). Prefer an invokable controller for these.
- The existing Platform controllers stay. Do not rewrite them without a request to do so. A change to them follows their current pattern.
- Controllers contain no query logic or business rules; they delegate to Actions.

## Validation placement

- Domain rules: the Action's validation class.
- UI-only rules: the Livewire component's `rules()`.
- Controllers: a Form Request. Check sibling controllers first and follow them.
- Do not scatter or duplicate rules. Extract shared ones to `app/Rules` or a validation class.

## Files and reuse

- Keep files, classes and methods small and focused. Avoid large models with mixed responsibilities.
- Apply SOLID and DRY where they help. Do not force patterns. Prefer composition over inheritance.
- Follow the existing folder structure, naming and patterns (BD-T13). No new base folder without approval.
- Check for an existing Action, trait, scope or component before writing a new one.
- Code shared by several features goes in `app/Concerns` or the feature's existing folder. Extract repeated queries to an Eloquent scope.
- Global helpers, if ever needed, go in one dedicated file autoloaded through `composer.json`.

## Laravel structure

- Middleware classes live in `app/Http/Middleware` and are registered in `bootstrap/app.php`, together with exceptions and routing files.
- Providers are listed in `bootstrap/providers.php`. There is no `app/Console/Kernel.php`; console configuration is in `routes/console.php` or `bootstrap/app.php`. Commands in `app/Console/Commands` register themselves.
- **Middleware order is security-sensitive.** Anything that reads the authenticated user on a tenant route must run after `ScopeSessions` (see the priority list in `bootstrap/app.php`).

## Shared files

Ask before changing a shared file when the current task does not clearly require it: layouts, `resources/css/app.css`, base components, config files, `bootstrap/app.php`, `AppServiceProvider`.
