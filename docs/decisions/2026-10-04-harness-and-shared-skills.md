# Harness and shared skills: a short index, scoped rules, docs reached by link, and shared yay skills

**Purpose:** why this repo's agent and developer harness (`CLAUDE.md`, `.claude/`, `docs/` and `README.md`) and the team's own Claude Code skills have the shape they have. It states every harness decision in force, in one place.

**Update when:** never edit it. Supersede it whole with a new record when the harness's shape changes: what makes up the harness, how Claude reaches a doc, who owns a topic, the committed tooling, the base branch, or how the skills are shared, prefixed or allowed to gate work. Adding, removing or changing a skill is not such a change.

## Status

Superseded by [the harness with review backlog record](2026-10-05-harness-with-review-backlog.md), 2026-10-05. Before that: Accepted, 2026-10-04, by Trace Bui. It superseded, whole, [the harness structure record](2026-10-01-harness-structure.md), [the shared skills record](2026-10-02-shared-design-plan-review-skills.md), [the yaytidy record](2026-10-02-add-yaytidy.md) and [the plan-without-a-spec record](2026-10-02-plan-without-a-spec.md), and restates every decision of theirs still in force. [The canonical-copy record](2026-10-03-shared-skills-canonical-copy.md) stays in force beside it.

## Context

- The previous harness was a large doc set that every session read up front. It restated the code (trees, class names, counts, status), so it drifted, and many code commits had to edit it too. Code comments cited doc paths and section numbers, which broke silently whenever a doc changed. It is preserved at the tag `harness-v1-final`.
- Claude Code loads `CLAUDE.md`, unscoped rules and `@` imports at launch, so whatever they hold costs context in every session. A path-scoped rule loads only when Claude reads, writes or edits a file it matches. A doc reached by a plain link loads only when Claude chooses to read it.
- The YayCommerce plugins share one set of skills. Personal and plugin skills can carry plain names such as `brainstorming`, so the team's own need a name that can't collide.
- The four records this one replaces superseded each other one decision at a time, and mixed in skill internals and the renewal project's process. [The decision-record practice](2026-10-04-decision-record-practice.md) now keeps those out, and allows only whole supersession.
- Options turned down, and still turned down:
  - an `AGENTS.md`: Claude Code is the only agent supported;
  - `@` imports in `CLAUDE.md`: they load at launch, and splitting into imports saves no context;
  - an owner table in the docs: one grew from 15 to 38 rows in a week and went stale;
  - tailoring the shared skills to this repo: it would fork the plugin family's skills, and `CLAUDE.md`'s links already lead to this repo's docs;
  - requiring a design doc before every plan: the team wants to plan straight from a request.

## Decision

**What the harness is**

- `CLAUDE.md`, `.claude/`, `docs/` and `README.md` make up the harness. Claude Code is the only agent supported, so there is no `AGENTS.md`.
- Designs for unbuilt features don't stay in the tree. Code says "designed, not built yet", and a new spec is written when the feature is built.
- There are no feature IDs, roadmap or development changelog. A to-do names its topic, as in `TODO(redemption)`, and release notes stay in `readme.txt`.

**Its shape**

- `CLAUDE.md` is an index under 100 lines: commands, hard rules, git rules and where to look. It reaches docs through plain links with "read when…" triggers, never `@` imports. A doc that Claude keeps missing may be promoted to an import, by a record that supersedes this one.
- Path-scoped rules in `.claude/rules/`, one each for PHP, the admin SPA and the release tooling, with globs that match this repo's real paths.
- Each topic has one owner, and the others link to it. `README.md`'s owner map lists the owners.
- `README.md` is the front door for people: onboarding, the daily workflow, the agent tooling and how to keep the harness current.
- Every doc, rule, record and skill opens with Purpose and Update when. Docs cite code by path and symbol. Code never cites a doc, a section number or a feature ID.
- Status, progress and counts appear only in plans, and in the Status lines of specs and records. Plans and specs are kept as history, and an agent checks their Status before acting.

**Tooling**

- A committed `.claude/settings.json` holds an allowlist for the routine checks, a hook that blocks edits to generated directories, and `includeGitInstructions: false`. That setting removes Claude Code's built-in git guidance, so `CLAUDE.md` states the git rules itself.
- `.gitignore` ignores personal Claude files, and nothing else of the harness.
- `.nvmrc` pins Node 22, and the root `package.json` declares it.
- The lockfiles stay gitignored, and `docs/known-issues.md` records the cost.
- `scripts/check-harness.sh` holds the mechanical checks on the harness.

**Workflow**

- `dev/edd` is the long-lived base. Every change, docs included, reaches it through a PR.

**Shared skills**

- The team's own skills carry the `yay` prefix, so they never collide with personal or plugin skills. `README.md` lists them, and says when to use each.
- The YayCommerce plugins share them, so their files stay repo-neutral. Each repo's `CLAUDE.md`, and the docs it links, supply the commands, the PR base, the checks that already fail and where decisions are recorded. Where the canonical copy lives, and how other repos take it, is [the canonical-copy record](2026-10-03-shared-skills-canonical-copy.md)'s.
- The skills own the spec and plan formats and their Status words. `CLAUDE.md` acts only on an `accepted` spec or an `active` plan.
- A change needs no design doc before it is planned. `yayplan` plans from a request alone, and writes the requirements into the plan for `yayplancheck` to check against. `yaybrainstorming` runs when the user wants a design, and once a design is under way, no code is written until it is accepted.

## Consequences

- A session starts from a short index. A scoped rule loads when Claude touches a file it matches, and a doc when its "read when…" trigger fires. If Claude misses a doc it needs, the promotion rule applies.
- Docs cite code and code never cites docs, so a code change doesn't break a doc reference, and a doc edit never touches code. Facts the code shows aren't written down: read the code for them.
- With the built-in git guidance off, the git rules in `CLAUDE.md` are the only ones, so they have to stay complete.
- The generated-directory hook sees edits made with Claude's edit tools, not writes made through the shell.
- With untracked lockfiles, a documented command can still behave differently on another machine.
- Repo-neutral skills lean on `CLAUDE.md`. If it doesn't name the PR base, the commands or the failing checks, the skills have to guess.
- A plan made without a spec compares no alternatives and names no decision record, so a change that makes an architectural choice can land without either unless someone asks for a design first.
- A skill can be added, removed or changed without a record: `README.md`, the skill's own files and `scripts/check-harness.sh` own those details.
- Old designs and the old docs' history are read at the tag, not in the tree.
