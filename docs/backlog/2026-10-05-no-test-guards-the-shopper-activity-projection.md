# No test guards the shopper-safe activity projection

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-05 `/simplify`, `/yaytidy` and `/code-review` of the storefront launcher, before it merged into `dev/edd` (#41).

## Today

`ShopperActivityRow::items()` is the only code that turns ledger rows into what `GET /me/activity` shows, with no admin note, actor id or campaign id. It calls `Text::store_name()`, `get_option()` and `wc_get_order()`, so a unit test without WordPress can't reach it. The public `from_entry()` seam was removed, and the user chose not to restore it.

## Why it matters

A change that leaks admin data into the public response ships unseen. The repo has no tests today (`docs/known-issues.md`), so this gap is part of that, but it is the one with a privacy cost.

## Suggested fix

When tests come back, add an integration test of `GET /me/activity` that writes an adjustment with an admin note and asserts the item keys are exactly `label`, `meta`, `points` and `delta` (without `points` once `2026-10-05-me-activity-returns-an-unread-field.md` is done), with no note text. Or bring back a WordPress-free seam for the label mapping and unit-test that.

## Done when

- [ ] A test writes an adjustment with an admin note, reads it back through `GET /me/activity` or `ShopperActivityRow::items()`, and asserts the exact item keys.
- [ ] The same test asserts that no item contains the note's text.
- [ ] `composer test:integration` or `composer test` runs that test and it passes (check the test count, not only the exit code).
