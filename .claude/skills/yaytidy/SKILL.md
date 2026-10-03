---
name: yaytidy
description: Before a PR, finds facts kept in more than one place, drifted or stale owner markers, and code the change duplicated or left redundant, in a branch, one commit or given paths. Runs the built-in /simplify on the same scope first, then fixes only the findings the developer picks. Use before opening a PR, or when the user says "tidy", "duplicates", "source of truth", "drifted mirror", or mentions YayTidy. Do not use for bug hunting (that is /code-review), lint, or naming and style requests.
argument-hint: "[base <ref> | commit <sha> | <path>…]"
# Purpose: before a PR, reports duplicated facts with their owners, and duplicated or redundant code,
#   after a guarded /simplify run on the same scope, and fixes only what the developer picks. It owns
#   the three modes, the run and the report format (references/finding-kinds.md). This header covers
#   the whole skill directory.
# Update when: a mode, the run, the kinds of finding or the report changes, or the built-in /simplify
#   changes how it takes a target. The YayCommerce plugins share this skill: keep its files
#   repo-neutral, and put a repo's own facts in that repo's CLAUDE.md.
#   Its canonical copy is YayCommerce/YayLoyalty, .claude/skills/yaytidy/; a copy elsewhere is replaced
#   whole, never edited (docs/decisions/2026-10-03-shared-skills-canonical-copy.md).
# This header sits in the frontmatter because Claude Code strips the frontmatter when it loads a
#   skill, but keeps HTML comments.
---

# YayTidy: one owner per fact, before the PR

A fact kept in two places drifts. Before a PR, this skill lets the built-in `/simplify` clean up the scope, then finds the facts, markers and code that the change duplicated or left behind, and shows each with its owner and a proposed fix. The developer picks what gets fixed.

This repo's facts come from `CLAUDE.md` and the docs it links: the PR base, the commands, the checks that already fail, and the rules that decide who owns a copied fact. Read them before step 1 of the run.

Read [finding-kinds.md](references/finding-kinds.md) before the own pass: it holds the seven kinds, how to read owner markers, the `contract` label and the report layout.

## Modes

The argument picks the mode. Never offer a menu of modes, and use one mode per run.

| Mode | Invocation | Scope | `/simplify` target | Each finding includes |
|---|---|---|---|---|
| Branch (default) | `/yaytidy`, or `/yaytidy base <ref>` | `git diff <base>...HEAD`, plus uncommitted and untracked files | the changed paths that still exist | a changed line, or a marker that names a symbol the diff removed or renamed |
| Commit | `/yaytidy commit <sha>` | the lines one commit changed that still exist now | the commit's files that still exist | a line the commit changed |
| Path | `/yaytidy <path>…` | one or more files or folders, checked against the whole codebase; `/yaytidy .` covers the whole repo | the paths, asking first when they hold more than 20 files | anything in the paths |

- `base <ref>` applies only to branch mode.
- Commit mode takes one commit. Refuse a range such as `a..b`, and suggest one run per commit, or `base <ref>`.
- In path mode, expand each argument with `git ls-files -- <path>`, so a folder or a quoted glob becomes the tracked files it holds. Refuse a path that matches no tracked file, with that reason, before anything else runs.
- A bare `/yaytidy` runs branch mode without asking which mode to use.

## PR base

Branch mode diffs against the PR's base. Pick it this way:

1. A base the user names wins. `base <ref>` takes a local branch, `origin/<branch>`, a tag or a SHA, checked with `git rev-parse --verify <ref>`. Refuse an invalid ref with git's reason, before anything else runs. A named base skips both the detection and the question.
2. Otherwise, start from the base that `CLAUDE.md` names, and use `origin/<base>` when that ref exists.
3. List the stack's parents with `git for-each-ref --merged=HEAD --no-merged=<base> --format='%(refname:short) %(upstream:track)' refs/heads/`. Leave out the current branch and any branch that points at HEAD (0 commits in `git rev-list --count <parent>..HEAD`). If any are left, ask with AskUserQuestion, offering in this order:
   - the nearest parent, meaning the one with the fewest commits to HEAD, as recommended;
   - the `CLAUDE.md` base;
   - the next parent;
   - Other.

   A parent whose upstream prints `[gone]` no longer exists on the remote: it was probably squash-merged and deleted. Rank it after every live parent, and when it fills a slot, its description says "probably squash-merged and deleted". A branch that was never pushed has no upstream and is a live parent. Don't fetch: use the remote-tracking refs as they are.
4. State the ref, its short SHA and the number of changed files.

If there is no base to start from, or HEAD is detached, ask for a base, or suggest `/yaytidy base <ref>`. If the diff is empty, say so, suggest `/yaytidy commit <sha>` or a path, and stop.

## Commit mode

1. Resolve the SHA with `git rev-parse --verify <sha>^{commit}`, and refuse it unless `git merge-base --is-ancestor <sha> HEAD` succeeds, giving that reason. Nothing else runs before this check.
2. Find the base the way rule 2 of the PR base section does. If `git merge-base --is-ancestor <sha> <base>` succeeds, the scope line says the commit is already in that base.
3. Diff against the commit's first parent, `<sha>^1`. For a root commit, use the empty tree, `git hash-object -t tree /dev/null`.
4. A changed line still exists when `git blame --line-porcelain HEAD -- <file>` attributes it to the commit. Check only those lines. The rewritten count is the lines the commit added, from `git diff --numstat <parent> <sha>`, minus the lines that still exist. Report it; don't check those lines. A file the commit touched that no longer exists counts all its lines as rewritten.
5. If no changed line still exists, for example because the commit only deleted lines or every line was rewritten later, say there is nothing left to check and stop, without invoking `simplify`.

## Scope line

The report's first line states the mode and the scope, even when the scope was stated earlier in the run. In branch mode, it gives the base ref, its short SHA and the number of changed files, and names each untracked file by its path, never only a count. For example:

```
Branch mode against feature/parent (a1b2c3d): 12 changed files, untracked: src/new-helper.ts
```

In commit mode, it gives the commit's short SHA, its subject, its file count and the number of rewritten lines, and says when the commit is already in the base:

```
Commit mode: 3f2c1ab "Add order export": 4 files, 6 changed lines rewritten later (not checked); already in origin/main
```

In path mode, it gives the paths as typed and their file count:

```
Path mode: src/Admin, src/Api/*.php: 14 files
```

## Run

Run these steps in order, once each.

1. **No code in scope.** When the scope holds no code files, for example only docs, the report reads "No code in scope" and the run stops here, without invoking `simplify`.
2. **Guard.** If in-scope files have uncommitted changes, list them and ask with AskUserQuestion:
   - "Commit them yourself, then continue" (recommended): wait for the user, then re-check;
   - "Continue without committing": the report warns that `/simplify`'s edits will mix with theirs;
   - "Stop".

   Never commit for them. Then hash every in-scope file with `git hash-object`, note which files are clean, and save the whole repo's `git status --porcelain` with a hash of each dirty file.
3. **`/simplify`.** Invoke the built-in `simplify` skill through the Skill tool, once, with the target the mode table gives, as space-separated paths. If the Skill tool can't run it, record the reason and go on to the own pass. In path mode, when the target holds more than 20 files, ask first with AskUserQuestion:
   - "Run /simplify on all <n> files";
   - "Skip /simplify, own pass only": the report's "Changed by /simplify" reads "skipped";
   - "Stop to narrow the paths".
4. **What `/simplify` changed.** Re-hash the in-scope files. List under "Changed by /simplify" each one whose hash changed, with one line on what changed, or "none". Compare `git status --porcelain` and the dirty-file hashes with step 2's, and list any file changed outside the target, offering `git restore <path>` for each one that was clean. Never restore without the user's yes. A `/simplify` edit that touches a contract (see [finding-kinds.md](references/finding-kinds.md)) becomes a `contract` finding that proposes reverting that edit.
5. **Own pass.** Check the scope as it now stands, without editing anything. Search tracked files with `git grep`, which skips the gitignored generated directories. In branch mode, also find each symbol the diff removed or renamed. That is a name declared on a `-` line of the diff whose declaration `git grep -nw <name>` no longer finds. Report every comment, in any tracked file, that still names it. Each finding includes what the mode table requires.
6. **Report.** Print the whole report as message text, before the ask, in the layout in [finding-kinds.md](references/finding-kinds.md):
   - the scope line first;
   - then "Changed by /simplify", even when it reads "none";
   - then every finding, with its ID, kind, `path:line` for each copy, owner, fix and any `contract` label.

   Don't summarise the findings in prose, and don't say which ones you will fix: the developer picks. When nothing is found, the report reads "No duplicates or source-of-truth problems in <scope>."

Never search `docs/`, `*.md` or `*.txt` files for copies: docs are read only for their rules. Literals in tests are never copies. Never edit a generated directory, and never commit.

## The ask

Before asking, re-hash the in-scope files. If any hash changed since step 4, say which. The own pass must not change files.

Ask only after the full report is on screen. Ask with AskUserQuestion, and refer to findings by ID:
- "Fix every finding not labelled contract";
- "Pick findings by ID": the user types the IDs under Other;
- "Fix none".

A `contract` finding is applied only when the user picked it by its ID, never through "fix every finding".

## Fix and check

1. Find the checks: the commands `CLAUDE.md` lists, narrowed to the touched files when a command takes paths, and whole otherwise.
2. Run each check once before applying the fixes, and keep its output.
3. Apply the picked fixes, and nothing else.
4. Run each check again. Report each command with its result before and after. A failure is new when the run before the fixes didn't show it. A check that already fails on the base is expected to show no new failures. A test command that ran no tests, such as one that prints "No tests executed!" or "No test files found", counts as failed.
5. If a fix caused a new failure, say so and offer to revert that fix. Don't commit: the developer does.
6. When the branch implements a yayplan plan, name the next step: `/yayplancheck` on the whole plan or its last phase, which checks these fixes against the plan.
