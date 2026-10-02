# Three shared skills take work from design to plan to review

**Purpose:** why the team's own Claude Code skills are `yaybrainstorming`, `yayplan` and `yayreview`, why the YayCommerce plugins share them, and why they own the Status words of specs and plans.

**Update when:** never edit it. Supersede it with a new record when a skill is added or removed, the skills stop being shared, or the Status words change.

## Status

Accepted, 2026-10-02, by Trace Bui, who drafted the skills. It supersedes the skills decision ([A4, A8, Q1]) of the [harness structure record](2026-10-01-harness-structure.md), whose other decisions stand. The IDs in brackets are from [the harness renewal plan](../plans/2026-10-01-harness-renewal.md), which also holds the evidence. Its decision that the own skills are "these three, and there are no others" ([Q10]) is superseded by [the yaytidy record](2026-10-02-add-yaytidy.md); its other decisions stand.

## Context

- The harness record planned two skills of the team's own, `brainstorming` and `writing-plans`, and no others. Personal and plugin skills can carry the same plain names, so the plan meant to tell them apart with descriptions specific to this repo.
- Trace drafted three skills instead. `yaybrainstorming` turns an idea into a design doc, and `yayplan` turns the design into a plan. `yayreview` is new. The session that wrote the code is biased toward it, so a fresh subagent checks the work against the plan before the next phase starts or the work merges.
- The drafts were written for any YayCommerce plugin, not only this one. Tailoring them to YayLoyalty was turned down: it would fork the plugin family's skills, and `CLAUDE.md`'s "read when…" links already lead to this repo's docs on Pro/Lite, new writers, the SPA, money and dates.
- The drafts gave a spec the words `draft | approved` and gave a plan no Status line at all, but `CLAUDE.md` acts only on an `accepted` spec or an `active` plan. Changing `CLAUDE.md` to `approved` was turned down: the decision records already say `proposed` and `accepted`, and the drafts had no word for a spec or plan that a later one replaces.
- Only one of the harness record's decisions changed. Restating that whole record in order to supersede it was turned down.
- Claude Code 2.1.286 shows the model only the first 511 characters of a skill's description. When it loads a skill, it drops the frontmatter but keeps HTML comments.

## Decision

- [Q10] The team's own skills are these three, and there are no others:
  - `yaybrainstorming` turns an idea into a design doc in `docs/specs/`. No code is written before the doc is accepted;
  - `yayplan` turns an accepted design into a plan in `docs/plans/`, either one file or a directory of phases;
  - `yayreview` has a fresh subagent review an implementation against its plan and report the gaps that block it.
- [Q10] The `yay` prefix keeps them apart from personal or plugin skills with plain names.
- [Q12] The YayCommerce plugins share these skills, so their files stay repo-neutral. Each repo's `CLAUDE.md`, and the docs it links, supply the commands, the PR base, the checks that already fail and where decisions are recorded.
- [Q11] The skills own the spec and plan formats and their Status words:
  - a spec is `proposed`, then `accepted YYYY-MM-DD`, or `superseded by <link>`;
  - a plan is `active`, then `done YYYY-MM-DD`, or `superseded by <link>`;
  - `CLAUDE.md` acts only on an accepted spec or an active plan.
- A skill's description stays within 500 characters, so the model sees all of it. Its Purpose and Update when go in YAML comments in its frontmatter, so they never reach the model.
- [Q13] This record supersedes only the harness record's skills decision. The harness record's Status line says so.

## Consequences

- Design, plan and review form one loop, and a Status line says what an agent may act on. A plan isn't done until its Status says so.
- A fix to a skill in one repo has to be copied to the others, or the copies drift. Where the canonical copy lives isn't named yet.
- Repo-neutral skills lean on `CLAUDE.md`. If it doesn't name the PR base, the commands or the failing checks, the skills have to guess.
- Each review costs a subagent run and repeats the plan's checks. After a second review the skill reports any remaining gaps instead of looping.
- Every behaviour change gets a design doc before any code, however small, though a one-sentence change needs only five lines.
- Teammates' personal skills keep their names and don't collide with these.
