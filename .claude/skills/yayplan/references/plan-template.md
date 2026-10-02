# Plan template

Use this structure for every plan. Keep the header; adapt task count and step count to the work.

**Status** starts as `active`. The PR that finishes the plan sets it to `done YYYY-MM-DD`, and a plan that another replaces becomes `superseded by <link>`. Only an active plan is executed. In a directory plan, the README's Status covers every phase.

```markdown
# [Feature Name] Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** active
**Goal:** [one sentence: what this builds]
**Spec:** [path or link to the spec/design doc this plan implements]
**Architecture:** [2–3 sentences: the approach and why it was chosen over the obvious alternative]
**Touches:** [files or packages modified; mark shared code with (shared) and list its consumers]

## Global constraints

[Project-wide requirements from the spec and CLAUDE.md, one per line, exact values copied verbatim: version floors, naming rules, compatibility targets, dependency limits. Every task implicitly includes this section.]

## Review focus

[The inputs or failure modes the spec implies but no task's tests cover, that are most likely to bite a real user. One line each: the input or condition, and the behaviour a reasonable person expects. Each line has a matching test in the task that owns the code. An empty section means you checked and found none, not that you skipped the check.]

---

### Task 1: [Component name]

**Files:**
- Create: `exact/path/to/new-file.php`
- Modify: `exact/path/to/existing.php:120-145`
- Test: `tests/exact/path/to/test-file.php`

**Interfaces:**
- Consumes: [what this task uses from earlier tasks: exact names and signatures]
- Produces: [what later tasks rely on: exact function, class, hook, or route names with parameter and return types]

- [ ] **Step 1: Write the failing test**

```php
public function test_specific_behaviour(): void {
    $result = function_under_test( $input );
    $this->assertSame( $expected, $result );
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test -- --filter test_specific_behaviour`
Expected: FAIL, function not defined

- [ ] **Step 3: Implement `function_under_test( array $input ): string` in `exact/path/to/new-file.php`**

[One line on the approach only where the signature and test leave a choice. No body unless the algorithm is undetermined by them.]

- [ ] **Step 4: Run the test to confirm it passes**

Run: `composer test -- --filter test_specific_behaviour`
Expected: PASS

- [ ] **Step 5: Commit**

Stage: `tests/exact/path/to/test-file.php`, `exact/path/to/new-file.php`
Message: `feat(scope): short description`

### Task 2: [Next component]
...
```

## When the project has no automated tests

Replace the test steps with a verification step that is still exact:

```markdown
- [ ] **Step 3: Verify in the admin**

Open: `/wp-admin/admin.php?page=example-settings`
Action: enable the toggle, save, reload
Expected: toggle stays enabled; `get_option( 'example_enabled' )` returns `true`
```

A verification step without an expected result is a gap.

## Phased plans (directory shape)

Use when SKILL.md's shape table points to phases.

```
docs/plans/YYYY-MM-DD-<feature-slug>/
├── README.md          # header, constraints, review focus, phase list, interface contract
├── phase-1-<slug>.md  # full tasks and steps
├── phase-2-<slug>.md  # tasks and interfaces now; steps expanded when phase 1 lands
└── phase-3-<slug>.md
```

### README.md

```markdown
# [Feature Name] Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayreview` has passed on it.

**Status:** active
**Goal:** [one sentence]
**Spec:** [path or link]
**Architecture:** [2–3 sentences]
**Touches:** [files or packages; mark shared code with (shared) and list its consumers]

## Global constraints

[Same as the single-file plan: one per line, exact values, applies to every phase.]

## Review focus

[Same as the single-file plan. Each line names the phase and task that owns its test.]

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | [working slice] | Run `...`; expected `...` | pending |
| 2 | [next slice] | ... | pending |

## Interface contract

[Every name, signature, hook, route, option key, or table that one phase produces and another consumes. Exact types. Phase files reference this section instead of restating it.]

- `function_name( array $input ): string` — produced by Phase 1 Task 2, consumed by Phase 2 Task 1
- Filter `example_plugin_settings` ( array $settings ): array — produced by Phase 1 Task 3
```

### phase-N-<slug>.md

```markdown
# Phase N: [Name]

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** [what earlier phases must have landed, by phase and task, or "nothing"]
**Delivers:** [the user-visible result of this phase]
**Exit criterion:** [the exact command or action and expected output that proves the phase done]

### Task 1: [Component name]
[Same task format as the single-file plan: Files, Interfaces, checkbox steps. The Interfaces block may say "see README contract" for anything already listed there.]

### Task 2: ...
```

A later phase that is not yet expanded keeps its tasks with Files and Interfaces filled in and a single line in place of steps: `Steps: expand after Phase N-1 lands.` Tasks and interfaces are never deferred, only steps.

## Separate plans

When the spec spans subsystems that do not share interfaces at all, they are separate features: write one plan (single file or directory) per subsystem rather than forcing them into phases of one plan.
