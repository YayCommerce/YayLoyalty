# yaytidy Design

**Status:** accepted 2026-10-02; AC 17.2 amended 2026-10-02 to accept (b) as duplicated logic or redundant code, since kind 7 covers a second implementation of something that already exists
**Related:** this work supersedes part of [the shared skills decision record](../decisions/2026-10-02-shared-design-plan-review-skills.md).

## Goal

Before opening a PR, a developer runs `/yaytidy`. It finds facts kept in more than one place, and code that the change duplicated or left redundant. It reports each with its owner and a proposed fix, and fixes only what the developer picks. The built-in `/simplify` cleans up the same scope in the same run. By default the scope is the branch's changes. An argument can name another base, a single commit, or paths.

## Context

- The team's own skills take work from design to plan to review: `yaybrainstorming`, `yayplan` and `yayplancheck`. None of them checks that a fact has one source of truth.
- A duplicate-rule checker did this until P2 of the harness renewal removed it. The tag `harness-v1-final` keeps it.
  - It worked from a 38-row owner table in a standards doc. The table had grown from 15 rows in a single week, and it went stale.
  - It cited doc section numbers and hard-coded a machine path and `dev/edd`. Claude Code didn't register its name.
  - Its evals applied patches to the repo, and they broke as the code changed.
  - Its report-then-ask flow worked, and yaytidy keeps it.
- This repo's rule for copies is in `docs/conventions.md`, under "TypeScript copies of PHP name their owner". A TypeScript copy says in its header which PHP code it mirrors, and that PHP is the source of truth.
- Owner markers in the code are free text, and some of them misfire:
  - a negation: "nothing to keep in sync" in `apps/admin/src/pages/customers/drawer/customer-drawer.activity-rows.ts`;
  - no owner named: "snake_case to mirror PHP" in `apps/admin/src/lib/api/api.type.ts`, and "mirrored server-side" in `apps/admin/src/pages/customers/customers-page.filter-suggestions.ts`;
  - a pointer to a file that holds no keys: `includes/Controllers/CustomerRowSort.php` says it mirrors `apps/admin/src/pages/customers/customers-page.sort-rows.ts`, which defers back to PHP.
- Problems this skill should catch exist today:
  - **drift:** `CustomerRowSort::KEYS` lacks `tier` and `referrals`, which the sort keys in `apps/admin/src/lib/schema/customer.schema.ts` offer, so PHP silently sorts those two by balance;
  - **no owner:** the REST error codes written in `includes/Controllers/AbstractRestController.php` are copied into `apps/admin/src/lib/access.tsx`, and neither side names an owner;
  - **duplicated logic:** `includes/Models/CampaignConditionSet.php` and `includes/Models/RewardConditionSet.php` are near-copies of each other.
- No duplication tooling is installed: no phpcpd, jscpd, knip or similar.
- The built-in `/simplify [target]` runs four review agents in parallel (reuse of existing helpers, simplification, efficiency, and level of abstraction) and applies their fixes. Its target is a path or a PR reference. Its default scope isn't documented.
- A stacked branch can't use the base that `CLAUDE.md` names. On the P9 branch on 2026-10-02, `dev/edd...HEAD` covered 100 files, while the diff against its parent branch covered 12. `git for-each-ref --merged=HEAD --no-merged=<base> refs/heads/` lists a stack's parents.
- PRs are squash-merged into `dev/edd` (`CLAUDE.md`). A squash merge doesn't make the branch's commits part of `dev/edd`, so `--no-merged` keeps listing a parent after its PR has merged, until its local branch is deleted.
- Claude Code passes whatever the user types after a skill's name to the skill as text. In version 2.1.287, the skill's `argument-hint` shows as dim text in the prompt once the user types the name and a space, and disappears at the first character of an argument; the `/` menu shows only names and descriptions. An unquoted value in brackets is read as a YAML list, and the hint then shows without its brackets.
- When `composer test` finds no tests, it prints "No tests executed!" and exits 0.
- The yay skills are shared by the YayCommerce plugins and stay repo-neutral. Each repo's `CLAUDE.md`, and the docs it links, supply that repo's facts.

## Scope

**In:**
- **Modes.** The argument picks the mode, and each run uses one mode:

  | Mode | Invocation | Scope | `/simplify` target | Each finding includes |
  |---|---|---|---|---|
  | Branch (default) | `/yaytidy`, or `/yaytidy base <ref>` | `git diff <base>...HEAD`, plus uncommitted and untracked files | the changed paths that still exist | a changed line, or a marker that names a symbol the diff removed or renamed |
  | Commit | `/yaytidy commit <sha>` | the lines one commit changed that still exist now | the commit's files that still exist | a line the commit changed |
  | Path | `/yaytidy <path>…` | one or more files or folders (quoted globs allowed), checked against the whole codebase; `/yaytidy .` covers the whole repo | the paths, asking first when they hold more than 20 files | anything in the paths |

  - `base` applies only to branch mode.
  - The frontmatter's `argument-hint` lists the three forms.
- **The scope line.** The report's first line states the mode and the scope:
  - in branch mode: the base ref, its short SHA and the number of changed files, with each untracked file named;
  - in commit mode: the commit's short SHA, its subject and its file count;
  - in path mode: the paths and their file count.
- **The PR-base rule** for branch mode:
  1. A base the user names wins. `base <ref>` takes a local branch, `origin/<branch>`, a tag or a SHA, checked with `git rev-parse --verify`. An invalid ref is refused. A named base skips both the detection and the question.
  2. Otherwise, start from the base that `CLAUDE.md` names, and use `origin/<base>` when that ref exists.
  3. List the stack's parents with `git for-each-ref --merged=HEAD --no-merged=<base> refs/heads/`, leaving out the current branch. If there are any, ask, offering:
     - the nearest parent, meaning the one with the fewest commits to HEAD, as recommended;
     - the `CLAUDE.md` base;
     - the next parent;
     - Other.

     A parent whose `origin/<branch>` no longer exists was probably squash-merged and deleted. It goes last, with a note.
  4. State the ref, its short SHA and the number of changed files.
- **Commit mode:**
  - the commit must be in HEAD's history (`git merge-base --is-ancestor <sha> HEAD`), or it is refused;
  - when the commit is already in the `CLAUDE.md` base, the scope line says so;
  - the diff is against the commit's first parent, and a root commit is compared with the empty tree;
  - a changed line that a later commit rewrote isn't checked, and the report counts such lines;
  - one commit per run.
- **The run**, in order:
  1. **Guard:** if in-scope files have uncommitted changes, ask the user to commit them first, so that `/simplify`'s edits stand apart. Then hash every in-scope file with `git hash-object`, and note which files are clean.
  2. **`/simplify`:** invoke the built-in `simplify` skill through the Skill tool, with the target the mode table gives.
  3. **What `/simplify` changed:** list under "Changed by /simplify" the in-scope files whose hash changed. Also list any file it changed outside the target, with an offer to `git restore` each one that was clean. A `/simplify` edit that touches a contract becomes a `contract` finding that proposes reverting it.
  4. **Own pass:** check the scope as it now stands, and report.
  5. **The ask:** fix every finding not labelled `contract`, pick findings by ID, or fix none. A `contract` finding is applied only when it is picked by its ID.
  6. **Fix and check:** apply the picked fixes, then run the checks.
- **No code in scope:** when the scope holds no code files, for example only docs, the report says "No code in scope" and stops before invoking `simplify`.
- **Search:** the own pass searches tracked files with `git grep`, which skips the gitignored generated directories. Each finding includes what the mode table requires.
- **Kinds of finding:**
  1. **copy:** a fact repeated where an owner exists. A fact is a key, an enum value, a constant, a bound or default, a message, a shape or a rule.
  2. **drifted mirror:** a marked copy that no longer matches its owner.
  3. **unowned copy:** two or more copies, none of which names an owner.
  4. **owner dispute:** markers that point at each other, or a marker that contradicts a repo rule.
  5. **stale marker:** a marker that doesn't name a symbol holding the same fact.
  6. **duplicated logic:** the same function or class written twice.
  7. **redundant code:** code the change left unused, or a second implementation of something that already exists.
- **Owners:**
  - A marker counts only if it names a symbol that holds the same fact. The wording doesn't matter.
  - Without such a marker, the repo's rules decide, from `CLAUDE.md` and the docs it links.
  - When nothing decides, the finding says so and asks.
  - A copy that a language boundary forces, such as PHP and TypeScript, is fine when it is marked and matches its owner.
- **The `contract` label:** a finding gets it when its fix would change something other code depends on: a hook name or its arguments, a REST route or field, an option or meta key, a table column, a shape shared by PHP and TypeScript, or a string that another file reads.
- **Report size:** with more than 20 findings, the report shows the 20 that matter most, gives the count of the rest, and offers to narrow the scope.
- **After fixing:**
  - run the commands that `CLAUDE.md` lists, narrowed to the touched files;
  - a check that already fails on the base is expected to show no new failures;
  - a test command that ran no tests counts as failed.

**Out:**
- Bug hunting (`/code-review` does that), lint, and naming or style.
- Searching docs for copies: docs are read only for their rules. Literals in tests, and `*.md` and `*.txt` files, are never treated as copies.
- Editing generated directories.
- Any owner table or list, whether in a doc or in the skill.
- Line-count or nesting limits. Simplicity is judged case by case.
- Applying its own findings without the developer's pick.
- Committing.
- A whole-repo mode or an `all` argument: `/yaytidy .` covers the whole repo, and asks before `/simplify` touches more than 20 files.
- Commit ranges: commit mode takes one commit.
- A menu of modes: the argument picks the mode, and a bare `/yaytidy` runs branch mode.

## Acceptance criteria

1. A bare `/yaytidy` runs branch mode without asking which mode to use. Its first line shows the base ref, its short SHA and the number of changed files, and names each untracked file. On a stacked branch it asks for the base, recommending the nearest parent. For example, on the P9 branch on 2026-10-02 it recommends `trace/chore/harness-p8-settings` (12 files), not `dev/edd` (100 files).
2. `/yaytidy base origin/dev/edd` on the P9 branch uses that ref without asking, and its first line shows 100 changed files. `/yaytidy base` with a ref that doesn't exist is refused with the reason, and no file changes.
3. When it asks for the base, a parent whose `origin/<branch>` no longer exists comes last in the list, with a note that it was probably squash-merged.
4. `/yaytidy commit <sha>` reports only findings on lines that the commit changed and that still exist. A problem elsewhere in the same file isn't reported. Lines that later commits rewrote are counted, not checked.
5. `/yaytidy commit <sha>` for a commit outside HEAD's history is refused with the reason. For a commit already in the `CLAUDE.md` base, the first line says so.
6. In branch mode, when the diff renames or removes a symbol that a marker elsewhere names, that marker is reported as a stale marker, even though its file isn't in the diff. For example, renaming `CampaignTrigger::lite_triggers()` flags the marker in `apps/admin/src/lib/schema/campaign.schema.ts`.
7. `/yaytidy <path> <path>` covers both paths, and `/yaytidy .` covers the whole repo. Before invoking `simplify` on more than 20 files, it asks.
8. When the scope holds no code files, the report reads "No code in scope", and `simplify` isn't invoked.
9. `simplify` is invoked at most once per run, before the own pass, with the target the mode table gives. If the skill isn't available, the report says so and the own pass still runs.
10. From the moment `/simplify` finishes until the user picks, no in-scope file's hash changes.
11. Every finding shows:
    - an ID and its kind;
    - `path:line` for each copy;
    - the owner as a path plus a symbol, or "no owner:" followed by the rule or the question;
    - the proposed fix;
    - the `contract` label where it applies.
12. A marked mirror whose values differ from its owner's is reported as a drifted mirror, with the differing values from each side. A marker that names no symbol holding the fact is reported as a stale marker.
13. Only picked findings are applied, and a `contract` finding only when it was picked by its ID. A `/simplify` edit that touches a contract appears as a `contract` finding.
14. After fixing, the report shows each check's command and result. No check fails in a new way, and a test run that executed no tests counts as failed.
15. When nothing is found, the report reads "No duplicates or source-of-truth problems in <scope>."
16. It never edits a generated directory, never searches `docs/` for copies, and never commits.
17. End-to-end verification. On a scratch branch in this repo, plant three things, each in its own commit:
    - (a) a TypeScript constant that copies the values of `includes/Enums/CustomerFilters.php`, with no marker;
    - (b) a PHP function in a new file under `includes/Utils/` that re-implements `Sql::in_placeholders()` from `includes/Utils/Sql.php`;
    - (c) `'review'` removed from `LITE_CAMPAIGN_TRIGGERS` in `apps/admin/src/lib/schema/campaign.schema.ts`, which is marked "Mirrors CampaignTrigger::lite_triggers()".

    Then:
    1. Run `/yaytidy commit` with the SHA of the commit that planted (c).
       - `simplify`'s target is `campaign.schema.ts` alone.
       - (c) appears either under "Changed by /simplify" or as a drifted mirror, and neither (a) nor (b) is reported.
       - Pick none, and discard any edits `/simplify` made with `git restore`.
    2. Run `/yaytidy`. Each plant appears either under "Changed by /simplify" or as its own finding:
       - (a) as a copy or an unowned copy, with owner `includes/Enums/CustomerFilters.php`;
       - (b) as duplicated logic or redundant code, pointing at `Sql::in_placeholders()`;
       - (c) as a drifted mirror whose owner is `CampaignTrigger::lite_triggers()`.
    3. Pick only (c). The own pass then changes only `campaign.schema.ts`, and the checks show no new failures.

    Discard the branch afterwards.

## Constraints

- **Name:** the skill's `name` is `yaytidy`, which equals its directory and matches `^[a-z0-9-]+$` (harness check C12).
- **Description:** one line of at most 500 characters, because Claude Code's skill listing cuts it off after 511. It keeps its "Do not use" clause, and rules out naming and style requests.
- **Arguments:** the frontmatter sets `argument-hint: "[base <ref> | commit <sha> | <path>…]"`, so typing `/yaytidy ` shows the three forms. The value is quoted, because YAML reads an unquoted value in brackets as a list, and the hint would lose its brackets.
- **Header:** Purpose and Update when go in YAML comments in the frontmatter, because Claude Code keeps a loaded skill's HTML comments but drops its frontmatter.
- **Repo-neutral:** the base, commands, failing checks and rules come from `CLAUDE.md` and the docs it links. No machine paths, tool versions, doc section numbers or line-number citations.
- **Links:** the skill links its reference files with markdown links, never as backticked paths, because check C16 resolves backticked paths from the repo root.
- **Banned names:** the removed checker's names, and any hyphenated form of "source of truth" followed by "check" (heading anchors included), appear nowhere. Check C2 rejects them everywhere outside `docs/plans/`.
- **Evals:** in skill-creator's `evals/evals.json` format, as repo-neutral prompts with expected behaviour. They never apply patches to the repo.
- **Dependency:** the built-in `simplify` skill. The team runs Claude Code 2.1.283 or later.

## Approach

**Chosen:** owners come from code markers and repo rules. The argument picks branch, commit or path mode. A guarded `/simplify` run goes first, then the human-gated own pass.

**Architecture:** One skill runs two passes over one scope.
- The argument picks the scope. A bare run stays a single command with the branch behaviour, and the other modes need no menu.
- `/simplify` goes first because it fixes without asking. The guard makes its edits visible, and lets the developer revert any of them.
- The own pass comes second, so the developer's decisions are the last word, and no automatic pass rewrites code the developer just approved.
- Owners come from the code itself and the repo's rules, so there is no table to go stale.

**Components:**
- `.claude/skills/yaytidy/SKILL.md`: the modes, the PR-base rule, the workflow, the ask, and the checks.
- `.claude/skills/yaytidy/references/finding-kinds.md`: the seven kinds with neutral examples, how to read owner markers, what makes a finding `contract`, and the report layout.
- `.claude/skills/yaytidy/evals/evals.json`: the skill's evals, covering each mode.

**Data and storage:** None. The report lives in the conversation.

**Integration points:**
- The Skill tool, which invokes the built-in `simplify`.
- `CLAUDE.md` and the docs it links, for the base, the commands and the rules.
- The PreToolUse guard hook in `.claude/settings.json`, which blocks edits to generated directories.

**UI:** In chat. AskUserQuestion offers the fix choice, and the base choice on a stacked branch.

**Edge cases and failure modes:**
- **An empty diff in branch mode:** it says so, and suggests `commit <sha>` or a path.
- **No base found, or a detached HEAD:** it asks for a base, or suggests `base <ref>`.
- **A commit whose changed lines were all rewritten later:** it says there is nothing left to check.
- **`/simplify` changes nothing:** its section reads "none".
- **`/simplify` edits outside its target:** those files are listed, with `git restore` offered for any that were clean.
- **A marker in negated or vague wording:** it doesn't count as an owner. When it claims a mirror, it is reported as a stale marker.
- **Shared test fixtures that are marked as mirrors and match:** these are not findings.

**Decision record:** the implementing PR adds `docs/decisions/YYYY-MM-DD-add-yaytidy.md`. It supersedes the shared skills record's decision "these three, and there are no others" ([Q10]), and that record's Status line says so.

## Alternatives considered

| Option | Why rejected |
|---|---|
| An owner table in each repo's docs | P1 removed exactly that. The old table grew from 15 to 38 rows in a week and went stale. |
| Inferring owners on every run | It can guess wrong, and two runs can disagree. |
| yaytidy doing the simplification itself | The user chose to reuse the built-in `/simplify`. |
| Duplication only, with `/simplify` run separately | Two commands for one cleanup. |
| The old readability rules (nesting ≤ 2, functions of about 40 lines, and so on) | Thresholds flag code that is long but clear. P1 dropped them. |
| The whole repo on every run | Long runs, and one huge diff of fixes. |
| A whole-repo mode (`all`) | `/yaytidy .` already covers it, and asks before `/simplify` edits more than 20 files. |
| Commit ranges (`commit <a>..<b>`) | The user chose single commits. For several commits, run once per commit, or use branch mode with `base <ref>`. |
| A menu of modes on a bare `/yaytidy` | The user chose arguments only. A bare run keeps the branch behaviour, and asks only for a stacked branch's base. |
| Fixing its own findings without asking | The user wants the report first, then to pick. |

## Rollout

- A new skill in `.claude/skills/yaytidy/`, which teammates get when they pull.
- The implementing PR also updates the harness renewal plan: §4's tree and owner map, §6, P11's agent tooling, and V8.

## Open questions

None.
