---
name: yaybrainstorming
description: Turns a feature idea or rough requirement into an accepted design doc that yayplan can plan from without asking questions. Use when the user wants to design a new feature, behaviour change, refactor, or integration before planning it, says "I want to add", "how should we build", "let's design", "think through", or mentions YayBrainstorming. Do not use when the user asks for an implementation plan, even without a spec (that is yayplan), to execute a plan, or for PRDs and roadmaps.
# Purpose: turns an idea into a design doc in docs/specs/ that yayplan can plan from. It owns the
#   spec format and its Status words (references/design-template.md) and the interview
#   (references/question-bank.md). This header covers the whole skill directory.
# Update when: the spec format, its Status words or the interview changes, or yayplan needs something
#   new from a spec. The YayCommerce plugins share this skill: keep its files repo-neutral, and put a
#   repo's own facts in that repo's CLAUDE.md.
#   Its canonical copy is YayCommerce/YayLoyalty, .claude/skills/yaybrainstorming/; a copy elsewhere is replaced
#   whole, never edited (docs/decisions/2026-10-03-shared-skills-canonical-copy.md).
# This header sits in the frontmatter because Claude Code strips the frontmatter when it loads a
#   skill, but keeps HTML comments.
---

# YayBrainstorming: idea to accepted design

Produce `docs/specs/YYYY-MM-DD-<topic>-design.md` that answers everything yayplan needs: goal, scope boundary, acceptance criteria, constraints, and the chosen approach. If a planner would still have to ask a question, the design is not done.

Standing rules for the whole task:

- Hard gate: once a design is under way, no code, scaffolding, source edits, or implementation skills until the user approves the design doc and its Status is `accepted`. A small change gets the fast-path doc. A request for a plan without a design is yayplan's, not this skill's.
- The only skill invoked after approval is `yayplan`. Do not invoke it before.
- Decide with the user, not for them. Present options, recommend one, and record what was rejected and why.

## Workflow

### 1. Explore context

Before asking anything, read the code around the idea: the files it touches, the existing pattern for this kind of feature, the docs CLAUDE.md links for the areas it touches (architecture, conventions, decision records), and recent commits. Note sibling plugins and shared code that would be affected. Then read [references/question-bank.md](references/question-bank.md) and pick only the questions the codebase and the request leave open.

**Fast path.** If the change could be described as a one-sentence diff, touches no shared code, and the codebase answers every question in the bank, skip steps 2 to 4. Write the five-line fast-path doc from the template in a single message, ask for approval, and continue at step 7. The doc is still mandatory; the interview and the alternatives are not.

### 2. Clarify

Ask in rounds of two or three related questions per message, one topic per round: purpose and success criteria first, then scope, then constraints and compatibility. Prefer multiple choice with a recommended default; in Claude Code use the AskUserQuestion tool. Wait for answers before the next round.

Stop when you can state purpose, scope in and out, acceptance criteria, and constraints without guessing. Never ask something the codebase, CLAUDE.md, or the request already answers. Dig into the hard parts the user may not have considered rather than the obvious ones.

### 3. Propose approaches

Present two or three viable approaches, one short paragraph each, with trade-offs on complexity, compatibility, performance, and upgrade path for existing installs. Recommend one with a one-line reason and ask the user to choose. Record the rejected ones; they go in the doc so nobody relitigates them later.

If the recommended approach makes or reverses an architectural choice (costly to undo, and the code alone can't justify it), say so. When the repo keeps decision records (CLAUDE.md says where), the design names the record that the implementing PR adds.

### 4. Present the design

Present the design in sections scaled to complexity: a tiny change is one section; a larger one covers architecture, data and storage, integration points, UI, and edge cases. Keep each section short enough for the user to review in one read, and end it with "does this look right so far?". Wait, revise if needed, then continue. Do not present the whole design in one message.

### 5. Write the design doc

Save to `docs/specs/YYYY-MM-DD-<topic>-design.md` unless the user or CLAUDE.md names another location. Follow [references/design-template.md](references/design-template.md). Use exact values throughout: option keys, hook and filter names, limits, version floors. "TBD" and "to be decided" do not appear in a finished doc. Set `**Status:** proposed`. If this design replaces an earlier spec, set that spec's Status to `superseded by <link to this doc>`. Offer to commit the doc.

### 6. Self-review

Re-read the conversation, then check the doc:

1. Every answer the user gave is reflected, and nothing in the doc contradicts one.
2. Scope lists what is out, not only what is in.
3. Every acceptance criterion is testable and carries its exact value.
4. The chosen approach and the rejected alternatives are both recorded.
5. Open questions is empty, or each item names who decides and is marked as blocking planning.
6. Planner test: could someone write implementation tasks from this doc without asking the user anything? If not, add what is missing.

Fix issues inline. No second pass needed.

### 7. Review gate and hand off

Reply with the doc path and a three-line summary (goal, chosen approach, biggest risk). Ask the user to review and approve. Make changes they request and update the doc.

Once the user approves, set the doc's Status to `accepted YYYY-MM-DD` and say: "Design accepted at `<path>`. Next step is `/yayplan <path>`." Invoke `yayplan` with the doc path only when the user says to proceed.
