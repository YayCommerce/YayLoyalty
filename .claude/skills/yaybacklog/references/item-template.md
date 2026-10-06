# Backlog item template

A backlog item is one follow-up that a review or a change left open. Each one is its own file in the backlog directory that CLAUDE.md names, called `YYYY-MM-DD-<slug>.md`. The date is the day the item was written, and the slug is lowercase words, joined by hyphens, that name the problem.

Copy the block below, fill in every placeholder, and keep the four `##` headings exactly as they are: the repo's harness check may look for them.

```markdown
# <The problem, as a sentence>

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** <the review or change that found it, its date, and the branch, PR or commit>

## Today

<How the code behaves now, citing each path and symbol involved.>

## Why it matters

<What goes wrong, for whom, and when.>

## Suggested fix

<The change that would close it, and any reason it wasn't made at the time.>

## Done when

- [ ] <One condition a reader can check in the code, or by running something.>
```

## Rules

- **One condition per box.** It states a fact about the code or a check's result, not a task step. Write "`ShopperActivityRow::items()` returns no `points` key", not "Remove `points`".
- **Checkable.** Each box cites a path and a symbol, or a command and the result it should give. A box nobody could check is a sign the fix isn't understood yet.
- **Ticking.** A ticked box adds its evidence after a dash: `- [x] <condition> — <path:symbol, or the command and its result>`.
- **Done.** Once every box is ticked, the item is done. Remove the file in the same PR that ticks the last box; git history keeps it.
- **Wrong items.** An item that turns out to be wrong, or no longer worth doing, is deleted with its boxes unticked, and the commit message says why.
- **Citations.** Cite code by path and symbol, never by line number. Code never cites a backlog item: a `TODO(topic)` names the topic instead.
- **What it isn't.** The backlog holds no feature ideas and no schedule. A new feature starts from a design or a plan.
