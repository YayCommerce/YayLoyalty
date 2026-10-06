# The shared skills' canonical copy lives in YayLoyalty

**Purpose:** where the one copy of the team's shared Claude Code skills is kept and changed, how other YayCommerce repos take them, and what a repo needs before it does.

**Update when:** never edit it. Supersede it with a new record when the canonical copy moves, for example to a plugin repo, or the rule for copying changes.

## Status

Accepted, 2026-10-03, by Trace Bui. It names the canonical copy that [the shared skills record](2026-10-02-shared-design-plan-review-skills.md) left open in its Consequences. It supersedes no decision, so that record stands as written. The ID in brackets is from [the harness renewal plan](../plans/2026-10-01-harness-renewal.md).

## Context

- The shared skills record decided that the YayCommerce plugins share these skills, and that their files stay repo-neutral [Q12]. It also said a fix in one repo has to be copied to the others, and that where the canonical copy lives wasn't named yet.
- The skills are `yaybrainstorming`, `yayplan`, `yayplancheck` and `yaytidy`. They exist only in this repo.
- Of the ten sibling plugin repos checked on 2026-10-03, none has these skills. Eight have no `CLAUDE.md`, and the skills read the PR base, the commands and the checks that already fail from it.
- Options turned down:
  - a separate plugin repo that each repo enables in its settings: there would be one copy and no drift, but plugin skills are namespaced, so `/yayplan` would become `/<plugin>:yayplan`, and every reference in the skills, the README and the plans would change. It also needs a repo and a marketplace before any sibling uses the skills;
  - leaving it open until a sibling wants the skills: the first copy would then have no rule for which side wins.

## Decision

- The canonical copy of each shared skill is `.claude/skills/<name>/` in YayCommerce/YayLoyalty.
- A change to a shared skill lands here first, by PR.
- Another repo copies a skill's whole directory unchanged, and replaces its copy whole when this one changes. It never edits its copy. A fix found while using a copy comes back here first.
- A repo takes the skills only once its `CLAUDE.md` names the PR base, the commands and the checks that already fail. A repo's own needs go in its `CLAUDE.md`, never in its copy of a skill.
- Each skill's header says where its canonical copy is.

## Consequences

- Each skill has one place to fix, and review happens there.
- A copy lags until someone replaces it. A copy that was edited in place loses the edit at its next replacement, which is why edits go to the canonical copy.
- Bringing the skills to a sibling repo starts with that repo's `CLAUDE.md`, so it is a project for each repo, not a copy-and-paste.
- Moving the skills to a plugin repo later needs a record that supersedes this one, and a rename of every reference to the namespaced names.
