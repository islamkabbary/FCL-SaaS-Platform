---
paths:
  - 'resources/**'
---

# UI Design

## Components and styling

- Use Flux UI components first (free edition). Then reuse the existing Blade components in `resources/views/components` and partials. Extend through props and slots; never fork or duplicate them.
- Use Tailwind utilities as the primary styling approach. Do not add custom CSS classes or move styling into `app.css` unless Tailwind cannot reasonably express it (keyframes, custom scrollbars and similar).
- When a reference design exists for a page, match it exactly: same structure and classes, no improvised styling. When none exists, follow the existing pages.
- Colors, spacing, breakpoints and radii come from the theme tokens in `resources/css/app.css` (`--color-accent` and friends). No one-off values.

## Consistency

Every page must feel like one product.

- The same pattern (tables, forms, filters, modals, page headers, empty, loading and error states) looks and behaves the same everywhere. Reuse shared components instead of re-styling per page.
- One shell per area, defined once: the tenant sidebar and header, the platform layout, the auth layouts. Only the page content changes inside it.

## Quality bar

- No rough or placeholder UI.
- Interactive elements have clear hover, focus and disabled states.
- Forms show validation errors inline with `<flux:error>`.
- Asynchronous actions show loading feedback.
- Destructive actions (delete, reset) require a confirmation step.
- Pages handle empty, loading and error states.

## Responsive

- Mobile-first. Every page works on mobile, tablet and desktop. Never break mobile to fix desktop.
- Wide content (tables, code) scrolls inside its own container. The page body never scrolls horizontally.

## Definition of done

Check the page at the standard breakpoints in **both Arabic (RTL) and English (LTR)** before calling it complete (see `localization.md`). If a change does not show, run `npm run build` or `npm run dev`.
