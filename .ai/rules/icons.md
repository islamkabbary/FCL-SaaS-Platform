---
paths:
  - 'resources/views/**'
---

# Icons

- **Use Flux icons** (`<flux:icon>` and the `icon="..."` prop): Heroicons by default. Do not add Font Awesome or another icon library.
- For a glyph Heroicons lacks, import it from Lucide with `php artisan flux:icon <name>`. Check the exact name; do not guess.
- **Never render a literal emoji in the UI.** An emoji in a design is a placeholder for an icon, for example: bell → `bell`, calendar → `calendar`, user → `user`, edit → `pencil`, delete → `trash`, success → `check`, warning → `exclamation-triangle`. Map any emoji that comes from data to an icon at render time.
- **Same concept, same icon** on every page.
- **Directional icons mirror in RTL.** Add `rtl:rotate-180` to icons that point along the reading direction (`chevron-right`, `arrow-left`, `arrow-right-start-on-rectangle`).
- **Accessibility:** decorative icons get `aria-hidden="true"` (Flux icons do by default). A control whose only content is an icon gets an accessible label through `aria-label` or visually hidden text, written with `__()`.
