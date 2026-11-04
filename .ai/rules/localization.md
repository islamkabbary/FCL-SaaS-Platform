---
paths:
  - 'resources/**'
  - 'lang/**'
  - 'app/**'
  - 'tests/**'
---

# Localization (Arabic and English)

The application must be fully usable in **Arabic (RTL)** and **English (LTR)** on every page.

## Strings

- **No hardcoded user-facing text** in Blade, Livewire, Actions, enums, notifications or exception messages. Use `__()`, `@lang` and `trans_choice()`.
- The English sentence is the key: `__('Add party')`. Its Arabic goes in `lang/ar.json`. Framework strings live in `lang/{en,ar}/*.php`.
- **Keep the locales in sync.** A key added to one must exist in the other, in the same change. `tests/Feature/TranslationCompletenessTest.php` enforces this.
- Enum `label()` methods and validation messages and attributes translate too.
- Do not build sentences by concatenating translated fragments. Use placeholders: `__('Added by :name', ['name' => $name])`.
- Business terms follow `docs/fcl/research/02-egypt-operations-research.md`. Keep the term consistent across pages.

## Direction

- `<html>` carries `lang` and `dir` from the active locale, on every layout and standalone page.
- Use **logical Tailwind utilities**: `ms-*` `me-*` `ps-*` `pe-*` `start-*` `end-*` `text-start` `text-end` `border-s` `border-e` `rounded-s-*` `rounded-e-*`. Do not use physical `left`/`right` equivalents unless the layout must not mirror.
- Mirror directional icons (see `icons.md`).
- **Technical values stay left to right** inside `<bdi dir="ltr">` or `dir="ltr"`: container numbers, ACID, tax numbers, e-mail addresses, phone numbers, URLs, amounts and codes.

## Locale resolution

- Order: the authenticated user's preference, then the session, then `config('app.locale')`. Fallback is `config('app.fallback_locale')`. Supported locales are in `config('app.supported_locales')`.
- `SetLocale` applies it. It must keep running after `ScopeSessions`.
- The switcher is `resources/views/partials/locale-switcher.blade.php`. It posts to `SwitchLocaleController`.
- During impersonation the choice stays in the session and never changes the user's saved preference.

## Formatting

- Dates that contain month or day names use `translatedFormat()`. Numeric date formats stay as they are.
- Digits stay Latin (0-9) in both languages, because shipping and customs references use them.
- Keep each amount's own currency and the tenant's timezone rules.

## User content

User-entered data (party names, notes) is stored and shown as entered in its single column. Bilingual content columns (`_ar`, `_en`) need a separate approved decision.
