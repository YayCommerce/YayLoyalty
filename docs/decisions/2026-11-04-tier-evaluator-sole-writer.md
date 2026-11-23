# TierEvaluator is the only writer of a customer's stored tier, and runs after the ledger commit

**Purpose:** why a customer's tier is stored rather than computed, why one Pro class writes it, and why that class runs after the ledger write instead of inside it.

**Update when:** never edit it. Supersede it with a new record if another writer is allowed, the tier stops being stored, or tier work moves inside the ledger write.

## Status

Accepted, 2026-11-04, by Jason, with the tier lifetime evaluation design.

## Context

- VIP tiers are Pro. A customer qualifies for the live tier with the highest threshold at or below their lifetime earned points; a staff-assigned floor can hold them higher.
- A tier change is an event, not just a value: it is logged as `TIER_CHANGED` activity, an upgrade pays the tier's entry bonus once per customer per tier, and later slices send emails and apply multipliers from it. Each needs a moment when the tier moved from one value to another.
- The Customers list sorts and filters by tier, and the Tiers page counts members per tier, so reads need the tier without recomputing it per row.
- Floors and, later, rolling-mode holds are state of their own, kept on the customer row next to the tier.
- `LedgerService` is Lite and the only writer of ledger rows and the cached totals ([its record](2026-10-01-ledger-service-sole-writer.md)). Its write holds the customer row lock for the length of one transaction.
- Catalogue changes (a new tier, a threshold edit, a delete) and turning tiers on can move every customer at once, which no single request can do on a large store.
- Options turned down:
  - computing the tier on read: there is no transition to log, pay a bonus for or email about, and floors and holds need stored state anyway;
  - writing the tier inside `LedgerService`: it widens the ledger's single-writer role and its lock time, and puts Pro logic in a Lite class;
  - letting the catalogue routes write customer columns directly: a second writer, and a request that times out on a large store.

## Decision

- The tier is stored on the customer row: `tier_id`, `tier_evaluated_at`, `tier_progress_value`, `tier_achieved_at`, `tier_manual_id` and `tier_manual_until`.
- `Pro\Services\TierEvaluator` is the only code that writes those columns, through `CustomerRepository::set_tier_state()`. `Models\Customer` maps them read-only, so no other customer save can overwrite them.
- The evaluator runs after the ledger commit, on `yayloyalty_ledger_recorded`, never inside `LedgerService`'s lock. It takes the customer row lock in its own transaction, and writes the change, its activity row and any entry bonus there together. The bonus itself is a ledger row, so it goes through `LedgerService::earn()` with an idempotency key, and the evaluator ignores the bonus row when it comes back.
- Routes that change the catalogue or the on/off switch only request a sweep: a cursor over customers, drained in batches by a Pro daily job with async continuation. They never write a customer's tier columns.
- While tiers are off the evaluator writes nothing, and Lite leaves the stored columns untouched.

## Consequences

- A tier change has one place that decides it, logs it and pays for it, so the history, the bonus and later emails agree.
- Lists and counts read a column.
- The ledger write stays as short as before, and Lite keeps no tier code.
- The tier lags the ledger by one after-commit step. A failed evaluation leaves the points written and the tier stale until the customer's next write or sweep.
- A catalogue change reaches customers only as fast as the sweep drains.
- Nothing in PHP stops new code from writing the tier columns. Review, the evaluator's docblock and `set_tier_state()` as the one write path are the guard.
