# yaytidy joins the shared skills

**Purpose:** why the team's own Claude Code skills grow from three to four: `yaytidy` checks a change for duplicated facts before a PR. It also records how yaytidy finds a PR's base on a stacked branch.

**Update when:** never edit it. Supersede it with a new record when a skill is added or removed, the PR-base rule changes, or yaytidy stops taking owners from the code.

## Status

Superseded by [the harness and shared skills record](2026-10-04-harness-and-shared-skills.md), 2026-10-04. Before that: Accepted, 2026-10-02, by Trace Bui, who accepted [the yaytidy design](../specs/2026-10-02-yaytidy-design.md). It supersedes the decision of [the shared skills record](2026-10-02-shared-design-plan-review-skills.md) that the own skills are "these three, and there are no others" ([Q10]). That record's other decisions stand. The IDs in brackets are from [the harness renewal plan](../plans/2026-10-01-harness-renewal.md).

## Context

- The own skills take work from design to plan to review. None of them checks that a fact has one owner.
- A duplicate-rule checker did that check until the harness renewal removed it. It worked from an owner table in a standards doc, which grew from 15 to 38 rows in one week and went stale. Its report-then-ask flow worked.
- Problems that such a check should catch exist in the code today: a PHP sort-key list that lacks two keys its TypeScript copy offers, REST error codes copied into the SPA with no owner named, and two near-identical condition-set classes.
- Owner markers in the code are free text. Some negate themselves, some name no owner, and some point at a file that points back.
- Claude Code has a built-in `/simplify` that reviews a change for reuse, simplification, efficiency and level of abstraction, and applies its fixes without asking.
- On a stacked branch, the base that `CLAUDE.md` names takes in every parent's commits. One branch's diff covered 100 files against that base and 12 against its parent.
- Options turned down:
  - an owner table, in the repo's docs or in the skill: it went stale before;
  - inferring owners afresh on every run: two runs could disagree;
  - yaytidy doing its own simplification, or `/simplify` run as a separate command;
  - a menu of modes, a whole-repo mode, and commit ranges: the argument picks the mode, `/yaytidy .` covers the repo, and branch mode with a named base covers several commits;
  - fixing findings, or committing, without the developer's pick.

## Decision

- [Q14] The team's own skills are four: `yaybrainstorming`, `yayplan`, `yayplancheck` and `yaytidy`.
- `yaytidy` runs before a PR, on a branch (the default), one commit or given paths:
  - it runs the built-in `/simplify` first, behind a guard that makes its edits visible and lets the developer revert them;
  - it then reports duplicated facts, drifted or stale markers, and duplicated or redundant code, each with its owner and a proposed fix, and fixes only what the developer picks;
  - an owner is a symbol that a marker in the code names, or else whatever the repo's rules decide. There is no owner table;
  - a fix that would change something other code depends on is labelled `contract` and is applied only when picked by its ID.
- yaytidy finds a PR's base with one rule: a base the user names wins; otherwise the stack's nearest parent is offered before the base that `CLAUDE.md` names.
- The shared skills record's other decisions still apply to all four: the skills stay repo-neutral, descriptions fit in 500 characters, and headers sit in YAML comments.

## Consequences

- A change can be checked for duplicated facts before review, and the check costs no table to keep up.
- An owner marker is only as good as its wording. A marker that names nothing is reported, so vague markers get fixed one change at a time.
- `/simplify` edits files without asking. The guard lists its edits, but on uncommitted files they mix with the developer's own, which is why yaytidy asks for a commit first.
- A yaytidy run over many files is slow and costly. `/simplify` asks before touching more than 20 files in path mode.
