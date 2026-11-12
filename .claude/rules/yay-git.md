<!-- Kit-managed by the yay plugin 0.8.0. Run /yay:harness-update instead of editing. -->
# Git rules

- **Branches:** `<type>/<slug>`, with type one of: feat, fix, chore, hotfix, docs, refactor, test. Get the name from `yayh git branch-name <type> "<title>" --id=<P-n|B-n>`, so work with an artifact leads with its id (`feat/p-4-refund-window`). Use the plan for planned work, the bug for a fix, and `R-<n>` only when there's no plan. One branch per plan or bug, in its own worktree under `.claude/worktrees/<slug>/` (`yayh git worktree <branch> [from]`).
- **Never commit to `dev` directly.** Release tags are cut only by `/yay:release`.
- **Commits:** `type(scope): summary`
  - type is one of `feat fix perf refactor docs test build ci chore revert`
  - add `!` for a breaking change
  - on a branch that leads with an id, the summary leads with it: `feat(refunds): P-4 add refund window setting`
  - the header is at most 72 characters, imperative, with no trailing period
  - check it with `yayh git commit-check "<msg>"`
- **Trailers** (each on its own line at the end of the message):
  - `Plan: P-<n>` on every commit made for a plan
  - `Fixes: B-<n>` on the commit that fixes a bug
  - `Roadmap: R-<n>` when a commit completes a roadmap item
- **No debug leftovers.** Temporary instrumentation is tagged `yay:debug`, and a hook blocks commits that still contain it.
- **PRs:**
  - the title is the conventional header, id included
  - the body follows `.github/pull_request_template.md`
  - target `dev`, except hotfixes, which come from the release tag
  - assigned to their owner, the developer who asked for the work: `gh pr create --assignee @me`. Ownership lives on the PR, not in the branch name.
  - merged with `gh pr merge <n> --merge`. The default is a merge commit, which keeps each task's commit and its trailers on `dev`. Squash or rebase only when `git.mergeMethod` says so.
- **Small commits:** one logical change each. Never use `--no-verify`, never force-push a shared branch, and never rewrite `dev`.
