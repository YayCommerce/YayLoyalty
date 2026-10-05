---
name: yaybacklog
description: Lists, checks and updates the backlog of follow-ups that reviews left open, one file per item with a Done when checklist. Checks open boxes against the code in a fresh subagent, ticks only the boxes the user picks, and removes an item once every box is ticked. Also adds an item from a review finding. Use when the user says "backlog", "what's still open", "is this follow-up done", "add this to the backlog", or mentions YayBacklog. Do not use to plan a feature or to review work against a plan.
# Purpose: lists the backlog, checks its items against the code in a fresh subagent, ticks and removes them,
#   and adds new ones. It owns the backlog item format (references/item-template.md). This header covers
#   the whole skill directory.
# Update when: a mode, the check prompt, the report format or the item format changes.
#   The YayCommerce plugins share this skill: keep its files repo-neutral, and put a repo's own facts
#   in that repo's CLAUDE.md.
#   Its canonical copy is YayCommerce/YayLoyalty, .claude/skills/yaybacklog/; a copy elsewhere is replaced
#   whole, never edited (docs/decisions/2026-10-03-shared-skills-canonical-copy.md).
# This header sits in the frontmatter because Claude Code strips the frontmatter when it loads a
#   skill, but keeps HTML comments.
---

# YayBacklog: list, check and close follow-ups

The backlog is the directory CLAUDE.md names for follow-ups (`docs/backlog/` by default). Each item is one file in the format of [references/item-template.md](references/item-template.md), with a `## Done when` checklist. Read that template before you write or change an item. An item is open while any of its boxes is unticked, and done once every box is ticked.

If CLAUDE.md names no backlog directory and `docs/backlog/` doesn't exist, say so and stop. Don't create one unless the user asks.

## Pick the mode

Take the mode from the argument. With no argument, list.

| Argument | Mode |
|---|---|
| none, or `list` | List |
| `check`, `check all`, or `check <item>…` | Check |
| `add`, or `add <finding>` | Add |

An `<item>` is a file name, with or without its date and `.md`, or a few words that match one title. If the words match more than one item, ask which one.

## List

For each item, read its `# ` title and count its `- [ ]` and `- [x]` lines under `## Done when`. Then reply with:

- a table of the open items: file name, title, ticked/total, and the first unticked condition;
- under "Done, ready to remove", every item whose boxes are all ticked.

Edit nothing. If the repo has a listing command (CLAUDE.md or `./scripts/run.sh help` names it), you may run it instead of reading the files, and show its output.

## Check

### 1. Fix the scope

The items named, or every open item for `check all` or a bare `check`. Skip items that are already done, and say so.

### 2. Dispatch the checker

The session that wrote the code, or the item, is biased toward it. Start one general-purpose subagent with the prompt below, filled in. It has no conversation history, so the prompt has to stand on its own.

```
Check backlog items against the code. Report facts, not opinions, and edit nothing.

Read each item file:
<item paths>

For every unticked box (`- [ ]`) under `## Done when`, decide whether its condition holds in the working tree now:
- met: the code or a check's result shows it; give the evidence as path:symbol, or as the command and its trimmed output;
- not met: give what you found instead, with path:symbol;
- unclear: the condition can't be checked as written, or the evidence conflicts; say why.

You may read any file and run read-only commands: git grep, git log, git diff and the checks listed in CLAUDE.md. Run a check only when a box names it. A check that already fails on the base branch, as CLAUDE.md or its known-issues doc records, can't show a box is met. Don't switch branches and don't change any file.

Also report any item whose `## Today` no longer matches the code, for example a symbol it cites that no longer exists.

Output format, one section per item:
## <file name>
- [met | not met | unclear] <the box's condition> — <evidence or reason>
Stale: <what in Today no longer matches, or "none">
```

### 3. Tick what the user picks

1. Show the subagent's report.
2. If any box came back `met`, ask with AskUserQuestion, `multiSelect: true`, which of those boxes to tick: one option per box. Put up to four in each question, and ask more questions if there are more boxes.
3. Tick exactly the boxes picked: `- [ ]` becomes `- [x]`, and the subagent's evidence goes after a dash, as the template's rules say. Leave every other box as it was.
4. For each item that now has every box ticked, ask: "Remove it now (recommended)" or "Keep it for now". Remove the file only on "Remove it now".
5. When the report flagged a stale Today, offer to rewrite that section from the evidence. Don't rewrite it unasked.

### 4. Report

Reply with what was ticked, what was removed, and the items still open, each with its ticked/total. Name the files changed. Don't commit: the user commits, in the PR that did the work, when CLAUDE.md's git rules allow it.

## Add

1. Get the finding: the text the user gave, or the finding they named from a review that ran in this session, such as a yaytidy or code-review report.
2. Read the code it cites, so Today and every box cite real paths and symbols.
3. Write `<backlog dir>/YYYY-MM-DD-<slug>.md` from the template, with today's date. Before you write it, check that no open item covers the same problem; if one does, offer to add to that item instead.
4. Every box must be a condition someone could check. If you can't state one for the fix, ask the user what "done" means, rather than writing a vague box.
5. If the repo has a harness check (CLAUDE.md or the README names it), run it, and fix the item until it passes.
6. Reply with the path and its Done when list. Don't commit.

## Never

- Commit, push, or open a PR.
- Tick a box the user didn't pick, or tick one the check didn't report as `met`.
- Delete an item with unticked boxes. One exception: when the user says the item is wrong or no longer wanted, delete it, and give the reason for the commit message.
- Put feature ideas or a schedule in the backlog. Point the user to a design or a plan instead.
