---
name: yayplancheck
description: Reviews implemented code against its yayplan plan, in a fresh subagent, and reports only the gaps that affect correctness or stated requirements. Use after a task, phase, or whole plan has been implemented and before starting the next phase, merging, or calling the work done, when the user says "review against the plan", "check the implementation", "is this finished", or mentions YayPlanCheck. Do not use for style review, for code with no plan, or before any implementation exists.
# Purpose: checks an implementation against the yayplan plan it was built from, in a fresh subagent.
#   It owns the review prompt and the report format. This header covers the whole skill directory.
# Update when: the review procedure or the report format changes, or yayplan's plan format changes.
#   The YayCommerce plugins share this skill: keep its files repo-neutral, and put a repo's own facts
#   in that repo's CLAUDE.md.
#   Its canonical copy is YayCommerce/YayLoyalty, .claude/skills/yayplancheck/; a copy elsewhere is replaced
#   whole, never edited (docs/decisions/2026-10-03-shared-skills-canonical-copy.md).
# This header sits in the frontmatter because Claude Code strips the frontmatter when it loads a
#   skill, but keeps HTML comments.
---

# YayPlanCheck: check the work against the plan

The session that wrote the code is biased toward it. The reviewer must run in a fresh context that sees only the diff, the plan, and the spec, so dispatch a subagent rather than reviewing inline. The implementing session then receives the gaps and fixes them.

## Workflow

### 1. Fix the scope

Identify three things, asking the user only for what you cannot determine:

- The plan: the path given as the argument, or else the newest active plan under `docs/plans/` in yayplan's format, by the date in its name. A plan is in yayplan's format when it has lines that start with `### Task N:` and `**Files:**`; in a directory plan its phase files do, and its `README.md` holds the Status. If no plan qualifies, or two share the newest date, ask. For a directory plan, which phase file is in scope.
- The diff: the branch or commit range that implements that scope. Diff against the PR's base branch, `git diff <base>...HEAD`, where the base is the one CLAUDE.md names, or the previous branch when branches are stacked. Do not assume `main`. For a later phase, take the commits since the previous phase was approved.
- What "in scope" means: the whole plan, one phase, or one task. Review only that; later tasks are not gaps yet.

A review of the whole plan, or of its last phase, is the final review, and yaytidy runs before it: yaytidy's fixes change code, and only this review checks them against the plan. Unless the user has said yaytidy ran on this branch after the last code change, ask with AskUserQuestion: "Run /yaytidy first" (recommended), or "Skip yaytidy, review now".

### 2. Dispatch the reviewer

Start a general-purpose subagent with this prompt, filled in. It must stand on its own; the subagent has no conversation history.

```
Review an implementation against its plan. Report gaps, not style preferences.

Read, in order:
1. The plan: <plan path>, and for a directory plan also its README.md (constraints, review focus, interface contract).
2. The spec it links in its header. When the header says `none, planned from the request`, the plan's `## Requirements` section stands in for it.
3. The diff: run `<git diff command>`.

Scope under review: <whole plan | phase N | task N>.

Check and report:
- Requirements: every acceptance criterion and every task in scope is implemented. Name any that is missing or partial, with the plan line.
- Verification: run every test or verification step the in-scope tasks list. Paste the command and its result. A step that fails, or was never added, is a gap, unless the check already failed the same way on <base>, as the plan's expected output or the project's docs record. List those under Evidence. Do not switch branches to find out.
- Review focus: each edge case listed there has the verification step or accepted test the plan names for it. A test the plan does not list is never a gap.
- Interfaces: names, signatures, hooks, and routes in the code match the plan's Interfaces blocks and the README contract exactly.
- Scope: nothing outside the in-scope tasks changed. List any file in the diff the plan does not mention.
- Exit criterion (phase or plan reviews): run it and paste the result.

Output format:
## Verdict: PASS | FAIL
## Blocking gaps
- <file:line> — <what is wrong> — violates <plan task/step or spec criterion>
## Optional findings
- <one line each; things that do not affect correctness or stated requirements>
## Evidence
- <commands run and their output, trimmed>

A finding with no plan line, spec criterion, or failing check behind it is optional, not blocking. An empty Blocking gaps section with PASS is a valid and expected result.
```

### 3. Act on the report

- Fix every blocking gap in this session, re-run the affected verification steps, and dispatch the reviewer once more on the same scope. Stop after the second review: if gaps remain, report them to the user rather than looping.
- Do not act on optional findings unless the user asks. Chasing them produces defensive code and tests for cases that cannot happen.
- If the reviewer found a gap in the plan itself (a requirement the plan never covered), say so and point the user to yayplan rather than patching code around it.

### 4. Report

Reply with the verdict, the blocking gaps found and whether each is now fixed, the optional findings as a short list, and the evidence the reviewer pasted. Then state the next step: the next phase, a merge, or a return to yayplan. When a review of the whole plan, or of its last phase, passes, the next step also sets the plan's Status to `done YYYY-MM-DD`, in the PR that finishes it.
