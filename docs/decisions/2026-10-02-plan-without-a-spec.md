# yayplan may plan without a spec

**Purpose:** why a change no longer needs a design doc before it is planned and built: yayplan plans any request without a spec and records its requirements in the plan.

**Update when:** never edit it. Supersede it with a new record when a change needs a design doc again, or the requirements leave the plan.

## Status

Accepted, 2026-10-02, by Trace Bui. It supersedes the decision of [the shared skills record](2026-10-02-shared-design-plan-review-skills.md) that "every behaviour change gets a design doc before any code, however small". That record's other decisions stand.

## Context

- yaybrainstorming gated every change behind an accepted design doc, and the shared skills record made that a consequence of the skills.
- yayplan already planned from an issue or a request without a spec, by asking up to three questions. Both texts landed in the same commit, and nothing said which one wins.
- Both skills' descriptions matched a request such as "I want a copy button, plan it", so the model chose between them.
- A plan with no spec had nothing to put in its `**Spec:**` line, and yayplancheck had no acceptance criteria to check against.
- Options turned down:
  - yayplan refuses without an accepted spec and points to yaybrainstorming: the team wants to plan straight from a request;
  - planning without a spec only for small requests, or only when no architectural choice or shared code is involved: the team chose not to limit it;
  - leaving the requirements out of the plan: the tasks alone give yayplancheck nothing to check acceptance against.

## Decision

- yayplan may plan any request without a spec. It asks up to three questions whose answers change the plan, and waits for them.
- Such a plan reads `**Spec:** none, planned from the request`, and its `## Requirements` section holds the goal, out of scope, the acceptance criteria with exact values, and each question with its answer. The last criterion is the end-to-end check that becomes the plan's final exit criterion.
- yayplancheck reads that section in place of a spec.
- yaybrainstorming runs when the user wants a design. Its gate still holds once a design is under way: no code until the design doc is accepted. A request for a plan goes to yayplan.

## Consequences

- A change can go from request to plan to code without a design doc, so a small change costs no fast-path doc.
- A plan without a spec compares no alternatives and names no decision record. A change that makes or reverses an architectural choice can land without either, unless someone asks for a design first.
- `CLAUDE.md` acts on an active plan whether or not a spec stands behind it.
- yaybrainstorming's and yayplan's descriptions now split on what the user asks for: a design or a plan. Their evals cover both sides.
