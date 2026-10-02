# yaycook Design

**Status:** accepted 2026-10-02
**Related:** split from one request on 2026-10-02 into this design and [the yaytidy design](2026-10-02-yaytidy-design.md). This work supersedes part of [the shared skills decision record](../decisions/2026-10-02-shared-design-plan-review-skills.md).

## Goal

A developer runs `/yaycook` on an active yayplan plan. Claude carries the plan out in the current session, task by task: it verifies every step, asks before every commit, and stops at each exit criterion so the developer can review the work.

## Context

- `yayplan` writes plans in two shapes, defined in `.claude/skills/yayplan/references/plan-template.md`:
  - a single file, for up to about 5 tasks;
  - a directory, for bigger work. Its `README.md` holds the Status, the constraints, a Phases table and the interface contract, and 2–4 phase files hold the tasks.
- Each task has a Files block, an Interfaces block and checkbox steps: write the test, run it and expect FAIL, implement, run it and expect PASS, commit. The plan's header tells agentic workers to "execute tasks in order, one at a time".
- A plan starts as `**Status:** active`, and `CLAUDE.md` acts only on an active plan.
- In a directory plan, later phases defer their steps ("Steps: expand after Phase N-1 lands."), because phase 1 changes the code those steps would point at. No skill does that expanding today.
- Nothing runs a plan. `yaybrainstorming` and `yayplan` both exclude execution, and `yayreview` only reviews afterwards.
- `CLAUDE.md`'s git rules:
  - branch as `<name>/<type>/<topic>`;
  - never commit to `dev/edd` or `main`;
  - Conventional Commits, ending with the Co-Authored-By trailer;
  - commit or push only when asked;
  - stage files by name.
- When there are no automated tests, the template allows manual steps (Open, Action, Expected). When `composer test` finds no tests, it prints "No tests executed!" and exits 0.
- The only active plan today, `docs/plans/2026-10-01-harness-renewal.md`, isn't in yayplan's format. Its checklist items, such as `- [ ] PR merged`, must never be run as steps.
- On a stacked branch, the PR base is the parent branch, not the base that `CLAUDE.md` names; see [the yaytidy design](2026-10-02-yaytidy-design.md).
- The yay skills are shared by the YayCommerce plugins and stay repo-neutral.

## Scope

**In:**
- **Choosing the plan:** it accepts only plans in yayplan's format, that is, with `### Task N:` headings and `**Files:**` blocks.
  - With an argument, it takes that plan file, directory or phase file. A phase file takes its Status from its directory's `README.md`.
  - With no argument, it uses the only active plan in yayplan's format under `docs/plans/`, and asks when there is more than one.
  - It refuses any Status other than `active`.
- **The branch:** if HEAD is on the base that `CLAUDE.md` names, or on `main`, it stops and offers to create a branch named per `CLAUDE.md`. For the PR base it follows the rule in [the yaytidy design](2026-10-02-yaytidy-design.md), worded the same, including its first step: a base the user names wins over detection.
- **Steps**, run in order:
  - A run or verify step passes when its output matches the step's Expected. If the check already fails on the base, passing means no new failures. A test command that ran no tests fails.
  - A manual step shows the user its Open, Action and Expected. The user answers pass or fail, and a pass is ticked with `(checked by user)`.
  - A code step is ticked once the symbol it names exists.
  - Only a passing step is ticked (`- [x]`).
- **Failures:**
  - a step that expects FAIL but passes stops the run;
  - a failing step may be fixed inside the task's Files, for at most 3 runs of that step (the first run plus 2 fixes). After that the run stops and shows the last output;
  - changing an assertion that the plan wrote always stops the run.
- **Deviations:**
  - a fix inside the task's own Files is allowed. It is recorded as `- Deviation (Step N): <what>; <why>` after the task's last step;
  - a change to an Interfaces entry, to an acceptance criterion, or to a file the task doesn't list stops the run and asks.
- **Commit steps:**
  - it stages, by name, the task's Files and the plan files the run edited: the plan or phase file, and the README in a directory plan;
  - it shows the commit message in `CLAUDE.md`'s format, trailer included;
  - it commits only on a yes. A no stops the run and leaves the step unticked.
- **Phases** (directory plans only):
  - A phase's row in the README's Phases table becomes `in progress since <short SHA>` when the phase starts, recording HEAD at that moment. For a deferred phase, the row changes only once the user has approved its expanded steps.
  - At the phase's exit criterion, it runs the check and shows the result in chat. It sets the row to `ready for review`, asks to commit the plan files, and prints the phase's commit range as `<recorded SHA>..HEAD`.
  - It then stops with: "Next: `/yaytidy` (optional) and commit its fixes, then `/yayreview <plan>` on the range. Run `/yaycook` again for the next phase."
- **Single-file plans:** at the final verification step it stops with the same message and changes no row.
- **The next phase:** when the previous row reads `ready for review`, it asks whether `/yayreview` passed. On a yes it sets that row to `done` and continues; on a no it stops.
- **Deferred steps:** when a phase reads "Steps: expand after Phase N-1 lands.", it runs yayplan's expand mode for that phase, shows the new steps and waits for the user's OK.
- **Resume:**
  - a rerun continues from the first unticked step;
  - if that step's work already looks done, it shows the evidence and asks whether to tick it;
  - a step that expects FAIL but passes while the task's files differ from HEAD makes it ask, rather than stop.
- **Finish:** after the user confirms that the last review passed, it sets the plan's Status to `done YYYY-MM-DD`, then asks before committing. yaycook is the only skill that sets `done`.

**Out:**
- Changing a plan's tasks, interfaces or acceptance criteria, which is yayplan's job.
- Reviewing (`/yayreview`) or tidying (`/yaytidy`), which it only suggests.
- Pushing, opening PRs or merging.
- Subagents, and running tasks in parallel.
- Skipping a verification step.
- Starting a phase whose predecessor the user hasn't confirmed as reviewed.

## Acceptance criteria

1. A plan whose Status isn't `active`, or that isn't in yayplan's format, is refused with the reason, and no file changes. With no argument in this repo today, it doesn't pick the harness renewal plan.
2. With HEAD on the base that `CLAUDE.md` names, or on `main`, it refuses and proposes `git switch -c <name>/<type>/<topic>`.
3. A step is ticked only when its Expected is met:
   - a manual step, only after the user answers "pass";
   - a test step, only when at least one test ran;
   - a step that expects FAIL but passes stops the run.
4. A commit stages exactly the task's Files and the plan files the run edited, each by name. Nothing is committed without a yes, and a no stops the run.
5. A deviation inside the task's Files adds a `- Deviation (Step N):` line. Any of the following stops the run with a proposal:
   - an edit to an assertion the plan wrote;
   - a needed change to an Interfaces entry;
   - a change to an unlisted file.
6. After the third run of one failing step, it stops and shows the last output.
7. In a directory plan, the phase's README row goes `in progress since <short SHA>` → `ready for review` → `done`, and `done` is set only after the user confirms the review. The stop message prints the range `<that SHA>..HEAD`.
8. A deferred phase runs only steps that yayplan's expand mode wrote and the user approved. A rerun before that approval runs nothing.
9. Run again in the middle of a plan, it resumes at the first unticked step and runs no step twice.
10. It never pushes, and never commits without a yes.
11. End-to-end verification: on a scratch branch in this repo, make a single-file plan with `/yayplan` for a toy change, with two tasks that have tests and one manual verify step. Run `/yaycook` and check that:
    - each test fails, then passes;
    - the manual step asks the user;
    - two commit prompts appear;
    - the final verification runs, and then it stops for review.

    `git log` shows only the commits the user approved, and every checkbox is ticked. Discard the branch afterwards.

## Constraints

- **Name and description:** the skill's `name` is `yaycook`, which equals its directory and matches `^[a-z0-9-]+$`. Its `description` is one line of at most 500 characters, with a "Do not use" clause.
- **Arguments:** the frontmatter sets `argument-hint: "[<plan>] [base <ref>]"`, so typing `/yaycook ` shows that it takes an optional plan and an optional base, the PR-base rule's `base <ref>`. The value is quoted, because YAML reads an unquoted value in brackets as a list, and the hint would lose its brackets.
- **Header:** Purpose and Update when go in YAML comments in the frontmatter. The Update when names yaytidy and yayreview, which share the PR-base rule.
- **Repo-neutral:** branch naming, the git rules, the commands and the failing checks all come from `CLAUDE.md` and the docs it links. No machine paths, tool versions or doc section numbers.
- **Links:** the skill links its reference files with markdown links, never as backticked paths (check C16).
- **Banned names:** the removed checker's names appear nowhere (check C2).
- **Plan edits:** the only edits it makes to a plan are ticks, Deviation lines, Phases rows, setting the Status to `done` once the user confirms, and the steps that yayplan's expand mode writes.
- **Evals:** in skill-creator's `evals/evals.json` format, as repo-neutral prompts with expected behaviour.

## Approach

**Chosen:** inline, task by task. The plan file is the progress record, the user gates every commit and every phase, and yayplan writes deferred steps.

**Architecture:** One loop over the plan's unticked steps, in the current session.
- The checkboxes and the Phases rows hold all the state, so a rerun resumes exactly where the last run stopped.
- The gates sit where `CLAUDE.md` and the user want them: before each commit, at each exit criterion, and before running steps that were just written.
- Planning stays in yayplan, through its new expand mode, and review stays in yayreview.

**Components:**
- `.claude/skills/yaycook/SKILL.md`: choosing the plan, the branch check, the step loop, commits, phases and the finish.
- `.claude/skills/yaycook/references/stop-rules.md`: when it stops, the failure and deviation rules, and resume.
- `.claude/skills/yaycook/evals/evals.json`: the skill's evals.
- Changes to the existing skills, in the same PR:
  - `yayplan` gains an "expand phase N" mode:
    - it is invoked as `expand <phase file>`, by yaycook through the Skill tool or by a user typing `/yayplan expand <phase file>`;
    - it writes the steps of one deferred phase against the current code, touching only that phase file;
    - it stops if the code contradicts the README's interface contract;
    - read-only ends once the steps are written, and it hands control back to the caller;
    - its hand-off names `/yaycook <plan>`. This goes in the body, because the description is already 498 characters.
  - `yayplan/references/plan-template.md`:
    - the Phases table's Status values become `pending | in progress since <short SHA> | ready for review | done`;
    - it gains the `- Deviation (Step N):` line format;
    - the lifecycle note says that yaycook sets `done`.
  - `yayreview`:
    - with no argument, it reviews the newest active plan in yayplan's format, and asks when there is none;
    - it uses the shared PR-base rule, so a base the user names wins over detection;
    - its prompt reads the plan's Deviation lines;
    - its last step hands `done`, and the next phase, to `/yaycook`.
  - Argument hints for the three existing skills, as one small task. Each value is quoted, like yaycook's:
    - `yaybrainstorming`: `argument-hint: "[<idea>]"`;
    - `yayplan`: `argument-hint: "[<spec> | expand <phase file>]"`;
    - `yayreview`: `argument-hint: "[<plan>] [base <ref>]"`.

**Data and storage:** the plan files: ticks, Deviation lines, Phases rows and the Status line.

**Integration points:**
- `CLAUDE.md`'s git rules and commands.
- The Skill tool, which invokes yayplan's expand mode.
- The stop messages, which name `/yaytidy` and `/yayreview`.
- The guard hook in `.claude/settings.json`.

**UI:** In chat. AskUserQuestion handles commit approvals, deviations, expanded steps, manual steps and review confirmations.

**Edge cases and failure modes:**
- **Every step already ticked:** it offers the finish.
- **A dirty working tree that the plan doesn't account for:** it shows the changes and asks.
- **A step whose command isn't in `CLAUDE.md`:** it runs the command as the plan wrote it, and the usual permission prompts apply.
- **A superseded spec behind the plan:** out of scope, because yaycook checks only the plan's Status.
- **An Expected written as prose:** it compares by judgment, and shows the evidence when it ticks the step.

**Decision record:** the same record as in the yaytidy design, `docs/decisions/YYYY-MM-DD-add-yaytidy-and-yaycook.md`. It supersedes [Q10]'s "these three, and there are no others".

## Alternatives considered

| Option | Why rejected |
|---|---|
| A subagent per task | The user chose inline. It also costs more, and yayreview already gives a fresh-context check at the end of each phase. |
| Inline, with subagents for parallel tasks | More moving parts, and few yayplan tasks are truly independent. |
| Committing each task without asking | `CLAUDE.md` says to commit only when asked, and the user wants to approve each commit. |
| No commits | yayreview's per-phase range relies on commits. |
| Running `/yayreview` automatically at the exit criterion | The user wants to review first. |
| yaycook writing deferred steps itself | The planning rules live in yayplan. |
| Writing every step up front | Later steps go stale as earlier phases change the code. |
| Stopping on every deviation | It would stop on trivial path fixes. |

## Rollout

- A new skill in `.claude/skills/yaycook/`, which teammates get when they pull.
- The yaybrainstorming, yayplan and yayreview changes ship in the same PR.
- The implementing PR also updates the harness renewal plan: §4's tree and owner map, §6, P11's agent tooling, and V8(c), which gains `/yaycook`.
- P9's dry run is unchanged.

## Open questions

None.
