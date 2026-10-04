# Record architecture decisions

**Purpose:** how this repo records an architectural decision: when to write a record, its file name, its format and its status values. This is the first record.

**Update when:** never edit it. To change the practice, write a record that supersedes it.

## Status

Superseded by [the decision-record practice](2026-10-04-decision-record-practice.md), 2026-10-04. Before that: Accepted, 2026-10-01, by Trace Bui, with the harness renewal.

## Context

The code shows what YayLoyalty does, not why it was built that way or what was turned down. Those reasons lived in commit messages, and in long docs that restated the code, drifted from it and were removed with the old harness. [architecture.md](../architecture.md) now describes how the parts fit, and [conventions.md](../conventions.md) the rules the code follows. Neither keeps the reasoning behind a choice, or the options it beat.

## Decision

We record each architectural decision in its own file in `docs/decisions/`, in Michael Nygard's format.

- **When:** write a record when a change makes or reverses a choice that is costly to undo and that the code can't justify by itself. Examples: how Pro and Lite are split, who writes a table, how money or dates are stored, a dependency that ships in the zip, the PHP floor, how the SPA reaches WordPress, and the shape of this harness. The record lands in the PR that makes the change.
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
  - `superseded by <link>`: replaced by a later record. It stays as history.
- **An accepted record doesn't change.** Only two edits are allowed: its Status line, when a later record supersedes it, and a link that a moved file broke. To change a decision, write a new record that names the old one, and in the same PR set the old one's Status to `superseded by` the new one.
- **Backfills:** a decision made before this practice gets a record dated the day it is written. Its Status names the commit that made the decision, and a teammate confirms the reasons before it is accepted.
- **What goes where:** a record holds the why. The mechanism stays in `architecture.md`, and the rules that follow from a decision stay in `conventions.md` or `CLAUDE.md`; they link to the record for the reasoning. Like those docs, a record cites paths and symbols, never line numbers.

## Consequences

- A reason outlives the session and the person that had it: a teammate, or Claude, finds why a choice was made and what it cost in one short file.
- A change that contradicts an accepted record has to supersede it, so the change of mind shows up in review.
- A record keeps its context as it was when it was written, so it ages. A reader checks today's mechanism in `architecture.md`.
- Each record costs a short file. Choices that are cheap to reverse don't get one.
