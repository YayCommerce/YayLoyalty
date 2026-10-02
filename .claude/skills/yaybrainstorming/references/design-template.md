# Design doc template

Every section exists for a reason yayplan depends on. Keep them all; a section that does not apply says "None" rather than disappearing. Scale the content, not the structure: a small change fills this in a few lines per section.

**Status** starts as `proposed`. It becomes `accepted YYYY-MM-DD` when the user approves the design, and `superseded by <link>` when a later design replaces it. A design is planned or built only once it is accepted.

```markdown
# [Topic] Design

**Status:** proposed
**Related:** [issue, ticket, or discussion links, or None]

## Goal

[One or two sentences: the user-visible outcome, and for whom.]

## Context

[What exists today: the files, patterns, and data the change builds on, with paths. Sibling plugins or shared code involved.]

## Scope

**In:**
- [behaviour included]

**Out:**
- [explicit non-goals and things deferred, so the planner does not add them]

## Acceptance criteria

1. [Testable statement with exact values: "Given X, when Y, then Z"]
2. ...
N. End-to-end verification: [the single command or UI walkthrough, with expected result, that proves the whole feature works together, not one unit of it. yayplan turns this into the plan's final exit criterion.]

## Constraints

- [Version floors, compatibility targets, naming rules, performance limits, tier boundaries, release rules. Exact values. These are copied into the plan's Global constraints verbatim.]

## Approach

**Chosen:** [name of the approach]

**Architecture:** [2–5 sentences: how the pieces fit and why this shape.]

**Components:**
- `path/or/class` — single responsibility

**Data and storage:** [where state lives: option key, meta key, table name, with exact names; migration for existing installs, uninstall behaviour; or None]

**Integration points:** [hooks, filters, REST routes, events, sibling-plugin touchpoints, shared code changed and who consumes it; exact names]

**UI:** [where it appears, which existing UI stack it uses; or None]

**Edge cases and failure modes:** [the inputs most likely to break it and the expected behaviour for each]

**Decision record:** [when the approach makes or reverses an architectural choice and the repo keeps decision records, the record the implementing PR adds; or None]

## Alternatives considered

| Option | Why rejected |
|---|---|
| [approach B] | [one line] |
| [approach C] | [one line] |

## Rollout

[Upgrade path for merchants already running the plugin, feature flag or setting default, anything that must happen in a release note; or None]

## Open questions

None.
```

## Fast-path doc

For a change the skill judged one-sentence-sized, the whole doc is five lines plus the header:

```markdown
# [Topic] Design

**Status:** proposed

- **Goal:** [one sentence]
- **Out of scope:** [what this does not touch]
- **Acceptance:** [one testable statement with exact values]
- **Approach:** [file and function or hook, one line]
- **Verification:** [command or action and expected result]
```

If writing it takes more than five lines, it was not a fast-path change: go back to step 2.

A design is accepted when its Status says `accepted`. yayplan reads this file as its spec and copies Constraints into the plan header; anything vague here becomes a vague task there.
