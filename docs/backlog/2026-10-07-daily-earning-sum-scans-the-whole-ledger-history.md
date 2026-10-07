# The daily earning sum scans a customer's whole ledger history

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** code review of the points-naming and daily earning limit branch, 2026-10-07, branch `paul/feat/setting/change-point-naming`.

## Today

`LedgerRepository::points_earned_today()` sums `points` for one customer's `earn` rows whose `created_at` falls in the site-timezone day. `DailyEarningLimit` calls it with no source on every award, and `EarningService` runs that award while holding the customer row lock. The ledger's per-user index is `idx_user_history_type` `(user_id,id,type)` in `LedgerTable`. It cannot narrow on `created_at`, so the query reads every `earn` row the customer has ever had to sum one day.

## Why it matters

The scan grows with the customer's ledger. It runs inside the award transaction under the customer lock, so every concurrent award for the same customer waits on it. A long-lived, active customer pays the most, and that is the customer the daily limit is aimed at.

## Suggested fix

Add an index that starts `(user_id, created_at)`, or bound the read to a range of ledger ids, so one day's sum reads only that day's rows. It wasn't done with the limit itself because the limit's behaviour and the label rename were the scope of that branch.

## Done when

- [ ] `LedgerTable` declares an index whose leading columns are `user_id, created_at`, and `Installer` or the schema upgrade adds it to existing sites.
- [ ] `EXPLAIN` of the `LedgerRepository::points_earned_today()` query for a customer with a large ledger names that index and examines only rows from the current day.
