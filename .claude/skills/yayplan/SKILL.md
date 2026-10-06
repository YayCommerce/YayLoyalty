---
name: yayplan
description: Turns an accepted spec, issue, or feature request into a step-by-step implementation plan that another engineer or agent can execute without the planner's context. Use when the user asks to plan, break down, scope, sequence, or estimate a feature, refactor, or migration, says "before we touch code" or "write a plan", wants tasks from a spec, or mentions YayPlan, even without the word "plan". Do not use for product planning (PRDs, roadmaps, MVP scoping) or to execute a plan that already exists.
# Purpose: turns an accepted spec, or a request with no spec, into an implementation plan in docs/plans/ that yayplancheck can check
#   work against. It owns the plan format, both plan shapes and the plan Status words
#   (references/plan-template.md). This header covers the whole skill directory.
# Update when: the plan format, its Status words or the sizing rules change, or yayplancheck needs
#   something new from a plan. The YayCommerce plugins share this skill: keep its files repo-neutral,
#   and put a repo's own facts in that repo's CLAUDE.md.
#   Its canonical copy is YayCommerce/YayLoyalty, .claude/skills/yayplan/; a copy elsewhere is replaced
#   whole, never edited (docs/decisions/2026-10-03-shared-skills-canonical-copy.md).
# This header sits in the frontmatter because Claude Code strips the frontmatter when it loads a
#   skill, but keeps HTML comments.
---

# YayPlan: implementation planning

Write the plan for an engineer or agent who has never seen this codebase or this spec. They write good code once they know the exact files, names, signatures, and verification steps. What they cannot know is what you decided. The plan records those decisions and nothing else.

Standing rules for the whole task:

- Planning is read-only. Do not create or edit source files; the only file you write is the plan.
- One plan per independently shippable unit. If the spec spans unrelated subsystems, say so and propose a split before writing.
- The plan is done only after the self-review below, and implementation starts only when the user says so.

## Workflow

### 1. Collect inputs

Read the spec, issue, design doc, or request the user pointed to. A design doc whose Status is not `accepted` is not ready to plan: say so and stop. Any request can be planned without a spec. Then ask up to three questions whose answers change the plan (scope boundary, acceptance criteria, hard constraints) and wait for them, and write the plan's `## Requirements` section from the request and the answers. Do not plan from guesses.

Collect project-wide constraints from the spec and CLAUDE.md (version floors, naming rules, compatibility requirements). They go into the plan verbatim.

### 2. Explore the codebase

Before deciding anything, find:

- The code the change touches: entry points, the existing pattern for this kind of thing, and how it is currently tested.
- Shared code: anything consumed by other packages, plugins, or modules. A change there becomes its own task with every consumer listed.
- The verification commands CLAUDE.md lists (test runner, linter, build step), and which of them already fail on the base branch: CLAUDE.md or the docs it links say so. If there are none, note how the feature is exercised by hand (command, URL, UI action).

Search tracked files with `git grep`, which skips gitignored generated directories.

### 3. Decide the file structure

List every file to create or modify and its single responsibility. Follow the codebase's existing conventions even where you would structure things differently. Propose splitting a file only when you are already modifying it and it has become unwieldy. This list drives the task breakdown: each task produces a self-contained change that makes sense on its own.

Then decide tests with the user; this question does not count toward step 1's three. Propose only the tests CLAUDE.md's test rule warrants, or, when it has none, those where a bug would be costly and a test catches it cheaply. Give each its file, suite and what it guards, ask with AskUserQuestion which to include, and wait. When none is warranted, say so instead of asking. The plan's `**Tests:**` line records the answer, and only those tests get test steps; every other task gets an exact verification step.

### 4. Write the plan

First choose the plan shape from the task list in step 3:

| Work | Shape |
|---|---|
| Up to about 5 tasks, done in one sitting | Single file: `docs/plans/YYYY-MM-DD-<feature-slug>.md` |
| 6 to 15 tasks, spanning several sessions or PRs | Directory `docs/plans/YYYY-MM-DD-<feature-slug>/` with a `README.md` and 2 to 4 phase files |
| Parallel workers or a subagent per task | Phases, with one file per task inside a phase only when those tasks are truly independent |

The user or CLAUDE.md may name another location; that wins. Follow the file structures and the Status lifecycle in [references/plan-template.md](references/plan-template.md). A new plan is `active`.

Phase rules:

- A phase is a vertical slice that ends in working, verifiable software with a stated exit criterion. Do not phase by technical layer (database, then API, then UI): nothing works until the last phase and the review gates test nothing a user sees.
- The README holds the interface contract shared across phases, once. Phase files consume it; they do not restate it.
- Only the first phase needs step-level detail now. Later phases list their tasks and the interfaces each produces, and get their steps expanded when the previous phase lands. Steps may be deferred; tasks and interfaces may not.
- Each phase file opens by pointing back to the README, which holds the plan's Status, then states what it depends on from earlier phases and the exact check that proves it done.
- The spec's end-to-end verification line, or the last acceptance criterion in `## Requirements` when there is no spec, becomes the exit criterion of the last phase (or the final verification step of a single-file plan).

Sizing:

- A task is the smallest change a reviewer could accept or reject on its own, and it ends in a working, verifiable state.
- A step is one action with one checkable result: "run Y, expect Z", "commit", or "write test X" for an accepted test.
- Fold setup, configuration, scaffolding, and docs into the task whose deliverable needs them.

What a step contains, and only that:

- Code step: file path, exact signature (or hook, filter, route, or event name), and the spec values that pin behaviour. The implementer writes the body. Include a body only for an algorithm the signature and tests do not determine.
- Test step, only for a test the user accepted in step 3: the test name and its assertions as code, with the spec's exact values.
- Verification step: the command (or URL and action) and the output that means it passed. Commands are the ones CLAUDE.md lists, or a narrower form of one, such as a single test or the changed files. When a check already fails on the base branch, the expected output is no new failures, with the base's result stated.
- Commit step: the files to stage, by name, and the message. CLAUDE.md's git rules set the message format and any trailer, so the step does not spell out a `git commit` command.
- Reference to another task: point at that task's Interfaces block. Do not repeat its code.

Lines that decide nothing ("handle edge cases", "add appropriate validation", "TBD") are gaps. Function bodies the signature and tests already determine are transcription. Both fail review.

### 5. Self-review

Re-read the spec with fresh eyes, then check the plan against it:

1. Coverage: every spec requirement maps to a task. List gaps and add tasks.
2. Steps: each step lets the implementer do exactly one reasonable thing, and no step carries more than that.
3. Consistency: names, signatures, and types match across tasks and across phase files. A method called `clear_cache()` in Task 2 and `clearCache()` in Task 5 is a bug.
4. Risk: the inputs and failure modes the spec implies but no step exercises. Name the ones most likely to bite a real user in Review Focus, and give each a verification step, or its accepted test, in the task that owns the code. Do not add a test the user did not accept.
5. Proportion: a plan several times longer than the spec has become the code. Replace bodies with signatures and assertions.
6. Phases (directory plans only): every phase ends in something runnable with an exit criterion, no phase is a bare technical layer, and every interface a later phase consumes appears in the README contract.

Fix issues inline. No second pass needed.

### 6. Hand off

Reply with the plan path (or directory), a short summary (goal, number of tasks or phases, the biggest risk), and anything you had to assume. Ask the user to review. Suggest how to execute (continue in this session after approval, a fresh session per plan or per phase that reads the README plus that phase, or a subagent per task with review between tasks) and recommend one with a one-line reason.

Stop there. Implementation starts only when the user says so. After each phase is implemented (or the whole plan, for a single file), the next step is `/yayplancheck <plan path>`: a fresh reviewer checks the diff against the plan before the next phase starts or the work is merged. Before the final review, of the last phase or of a single-file plan, yayplancheck asks whether to run `/yaytidy` first.
