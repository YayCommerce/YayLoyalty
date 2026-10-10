# A fully refused award leaves no order note or activity row

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** code review of the points-naming and daily earning limit branch, 2026-10-07, branch `paul/feat/setting/change-point-naming`.

## Today

When `EarningService` refuses an award outright it returns `Outcome::skipped( EarningService::REASON_LIMIT_REACHED )`. `OrderListener::note()` now adds "No points earned: an earning limit held back all %d points." for that reason, and `EarningService::award()` announces the refusal through `EarningService::LIMITED_ACTION`, so `CustomerActivityService::record_earning_limited()` writes an `earning_limited` row with 0 awarded. Only the wp-admin check below is left.

## Why it matters

A customer at the daily cap completes an order and gets nothing. Without a record on the order or in their activity, support can't tell a cap refusal from an engine miss without reading WooCommerce logs.

## Suggested fix

Have `OrderListener::note()` add an order note for `REASON_LIMIT_REACHED` that names the earning limit, and consider recording an `earning_limited` activity row for a full refusal, as a partial cut already does. It wasn't done with the limit because refusals were meant to be silent.

## Done when

- [x] `OrderListener::note()` adds an order note when the outcome is skipped with `EarningService::REASON_LIMIT_REACHED`. — `OrderListener::note()`, `Outcome::SKIPPED` case
- [ ] A customer at the daily cap who completes an order has that note on the order, checked by completing such an order in wp-admin.
- [x] A decision is recorded on whether a full refusal also writes an `earning_limited` activity row, and the code matches it. — it does: the comment in `EarningService::award()` and the docblock of `CustomerActivityService::record_earning_limited()`
