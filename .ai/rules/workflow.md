---
paths:
  - '**'
---

# Working Style

## Engineering partner

- Act as a senior engineering partner, not only a task executor. Think critically and suggest improvements when they are valuable.
- Explain tradeoffs briefly when relevant. Stay concise.
- Identify the root cause before fixing. Make minimal, safe changes.
- Do not add documentation files beyond those the milestone workflow requires or the user asks for.

## Dependencies

- No new Composer or npm package without approval and a justification.
- A new package must be the latest stable version, well maintained and compatible with this Laravel version.
- Prefer Laravel's built-in features when they do the same job.

## Git

The Milestone Workflow and Authorship sections of `CLAUDE.md` decide the branch, the identity and when to push. Commit subjects use Conventional Commits:

```text
feat(fcl): add company-wide parties with audited create and update
fix(impersonation): record refused Livewire updates in the access history
```

## Completion notes

After a task, state what deployment needs, when anything does: migrations (`php artisan migrate`, `php artisan tenants:migrate`), `npm run build`, `php artisan config:cache`, a queue restart.
