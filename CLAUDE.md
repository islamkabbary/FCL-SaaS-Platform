# FCL SaaS Platform — Project Conventions

Laravel, Livewire, Pest and Boost guidelines for this project live in `AGENTS.md` (maintained by Laravel Boost). Follow them together with the conventions below.

## Milestone Workflow

These are permanent project conventions. They apply to every development milestone unless the project owner explicitly overrides them.

After completing every development milestone:

1. **Documentation.** Create a comprehensive Markdown file under `docs/` that explains everything implemented, the architecture decisions, modified files, migrations, packages, tests, known issues and manual verification steps.
2. **Verify before committing.** Complete the implementation and its verification before creating any Git commit.
3. **Logical commits.** Organize changes into separate, logical commits with descriptive English subjects and meaningful bodies that explain the completed work.
4. **Branch.** Always commit directly to the existing `main` branch. Never create another branch.
5. **Identity.** Set both the Git author and committer to `Islam Kabbary`, using the existing verified repository email.
6. **No AI attribution.** Never add Claude, AI or Anthropic attribution, including co-author trailers.
7. **Documentation last.** Commit the milestone documentation as the final logical commit.
8. **No automatic push.** Never push. Pushing requires explicit authorization.
9. **Clean commits.** Never commit secrets, credentials, logs or unrelated changes.
10. **Final report.** Finish every milestone with a report listing the completed features, test results, documentation path, commit hashes and final Git status.

## Authorship of Commits and Pushes

This rule applies to every commit and every push in this repository, not only to milestones, and it overrides any tool default.

11. **Owner identity only.** Everything committed or pushed to this repository must appear under the owner's name alone: author and committer `Islam Kabbary` with the existing verified repository email. Nothing may be authored, committed, co-authored or signed off as Claude, Claude Code, Anthropic or any AI assistant, and no `Co-Authored-By`, `Generated with` or similar AI trailer may appear in a commit message or pull request description.
12. **Check before every push.** When a push is explicitly authorized, first list the outgoing commits (`git log origin/main..main --format='%an <%ae> | %cn <%ce>%n%B'`) and confirm that every author and committer is `Islam Kabbary` and that no message mentions AI attribution. If any commit fails this check, do not push; report it to the owner instead.
