# Record architecture decisions, and supersede a record only whole

**Purpose:** how this repo records an architectural decision: when to write a record, what stays out of one, its file name, its format, its status values and how one record replaces another.

**Update when:** never edit it. To change the practice, write a record that supersedes it.

## Status

Accepted, 2026-10-04, by Trace Bui. It supersedes [the first format record](2026-10-01-record-architecture-decisions.md) whole, and restates every rule of it that still holds.

## Context

- The code shows what YayLoyalty does, not why it was built that way or what was turned down. [architecture.md](../architecture.md) describes how the parts fit, and [conventions.md](../conventions.md) the rules the code follows. Neither keeps the reasoning behind a choice, or the options it beat. The first format record set up `docs/decisions/` for that, in Michael Nygard's format.
- Three days later, the harness and its skills had five records. Each one after the first superseded single decisions inside an earlier one. To learn which skills exist, or whether a change needs a design doc first, a reader had to walk four records and work out which of their lines still held. One superseded rule, that every behaviour change gets a design doc, still read as current in a record's Consequences.
- Those records also held what a decision record isn't for: a skill's modes, its PR-base rule and its description limit, which the skill's own files and `scripts/check-harness.sh` already own; and the renewal project's reviews, docs freeze and tool version, which belong in its plan and in `README.md`. Several could be read only with the plan open, because they cited its question IDs.
- Options turned down:
  - letting a record supersede single decisions, as before: it produced that chain;
  - a "current state" doc beside the records: it would restate the records, and drift from them.

## Decision

We record each architectural decision in its own file in `docs/decisions/`, in Michael Nygard's format.

- **When:** write a record when a change makes or reverses a choice that is costly to undo and that the code can't justify by itself. Examples: how Pro and Lite are split, who writes a table, how money or dates are stored, a dependency that ships in the zip, the PHP floor, how the SPA reaches WordPress, and the shape of this harness. The record lands in the PR that makes the change.
- **What stays out:**
  - A choice internal to one skill, such as its modes, its rules, its formats or the length of its description, lives in that skill's files. A record is written when the harness's shape changes, not when a skill does.
  - Plan and process content, such as reviews, freezes, assignments, deadlines and tool versions, lives in a plan or in `README.md`. A Status names only who accepted the record.
  - A record cites no plan or question IDs. It states each decision in its own words.
- **File name:** `YYYY-MM-DD-<slug>.md`. The date is the day the record is written. The slug is lowercase words, joined by hyphens, that name the decision.
- **Format:** a title that states the decision, the **Purpose** and **Update when** lines that every harness doc opens with, then four headings:
  - `## Status`: the status, the date it was set and who accepted it;
  - `## Context`: the facts and forces that applied when the decision was made, including the options turned down;
  - `## Decision`: what we do, stated as rules;
  - `## Consequences`: what gets easier and what gets harder, costs included.
- **Statuses:**
  - `proposed`: open for discussion. A PR may merge it, but nothing acts on it.
  - `accepted`: in force. The code follows it.
  - `rejected`: turned down. It stays, so the question isn't reopened without new facts.
  - `superseded by <link>`: replaced by a later record. It stays as history, and nothing in it is in force.
- **An accepted record doesn't change.** Only two edits are allowed: its Status line, when a later record supersedes it, and a link that a moved file broke.
- **A record is superseded whole.** To change any decision in a record, write a new record that restates every decision of the old one still in force, along with the change, and in the same PR set the old one's Status to `superseded by` the new one. A record is never partly superseded.
- **Backfills:** a decision made before this practice gets a record dated the day it is written. Its Status names the commit that made the decision, and a teammate confirms the reasons before it is accepted.
- **What goes where:** a record holds the why. The mechanism stays in `architecture.md`, and the rules that follow from a decision stay in `conventions.md` or `CLAUDE.md`; they link to the record for the reasoning. Like those docs, a record cites paths and symbols, never line numbers.

## Consequences

- A reason outlives the session and the person that had it: a teammate, or Claude, finds why a choice was made and what it cost in one short file.
- Every accepted record is wholly in force, so a reader never has to check which of its lines a later record replaced.
- A change that contradicts an accepted record has to supersede it, so the change of mind shows up in review. Changing one decision costs restating the rest of its record.
- A record keeps its context as it was when it was written, so it ages. A reader checks today's mechanism in `architecture.md`.
- Each record costs a short file. Choices that are cheap to reverse, skill changes included, don't get one.
