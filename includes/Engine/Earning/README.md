# Earning orchestration

Earning has two moments and every class belongs to exactly one:

| Moment | Where | Purity |
|---|---|---|
| **Evaluation** — which campaign, how many points | `Engine/Points/*` (unchanged), reached through `EarningService::preview()` | pure: no `$wpdb`, no WooCommerce globals, no current user, no wall clock |
| **Award** — ledger row, customer cache, pending release, order meta, one transaction | `LedgerService::earn()` + `PendingPoints`, reached through `EarningService::award()` | impure by definition |

Between them: `Gate` (+ `ProgramGate`) and `PendingPoints`. Around them: `Listeners/*`, one per event family, which only build a `Context` and call the service — and, for orders, decide what a status transition means. Nothing below the listener knows an order status.

```
woocommerce_order_status_changed( $id, $from, $to, $order )
     → OrderListener::on_status_changed   routes on $to alone
       └─ ContextFactory::from_order( $order, points_earned_today )   occurred_at = order creation date
                → EarningService::preview( $trigger, $ctx )   gates → PointsEngine::evaluate( $trigger, $ctx )   → Result   (stage as pending)
                → EarningService::award( $trigger, $ctx )     preview again, same occurred_at
                                                             → Transaction::run
                                                                 LedgerService::earn( draft )   lock customer row → INSERT (UNIQUE idempotency_key) → aggregates
                                                                 PendingPoints::release / mark_awarded
                                                             → after commit: yayloyalty_ledger_recorded, yayloyalty_points_awarded
                                                             → Outcome: awarded | skipped( reason ) | duplicate
```

## Invariants (the test names under `tests/unit/Earning/` are the specification)

1. Evaluation runs twice per order at the **same** timestamp — the order's creation date. The engine has no timestamp argument; it reads `Context::occurred_at`, which `ContextFactory::from_order()` sets from the order and throws without.
2. Pending is staged idempotently on `_yayloyalty_points_pending` being absent and released exactly once on `_yayloyalty_points_released`. Award never requires that staging ran.
3. The idempotency key is one string, `IdempotencyKey::compose( type, user_id, reference_type, reference_id, qualifier )` → `earn:7:order:1234:purchase`, in one column under one UNIQUE index. The qualifier is the trigger when earning, the reward type when redeeming.
4. The index is the guard. A pre-check may skip a known duplicate; the insert is attempted and error 1062 becomes `Outcome::duplicate`, a normal return. Implemented: `QueryException::is_duplicate_key()` reads the driver's number, `LedgerRepository::insert_unless_duplicate()` suppresses wpdb's reporting for the attempt so the guard firing leaves no line that reads like a failure, and `LedgerService::earn()` returns the row that won.
5. **Paid is a set of statuses, not a status.** A fully downloadable-and-virtual order never enters `processing`; plenty of physical stores never leave it. The award set is `general.award_statuses`, default `processing` + `completed`, and the first arrival wins — a later transition into the set composes the same key and costs one rejected insert.
6. **One order hook.** `woocommerce_order_status_changed` only, routed on `$to`. A per-status hook or `woocommerce_payment_complete` alongside it would describe the same transition twice and process it twice.
7. **Statuses that do nothing are a decision, not an omission.** `failed` releases nothing and restores nothing — the order is still payable and a retry lands on an award status. `refunded` is inert too: refunds are driven by refund objects, except when `get_total_refunded()` is 0, which means an admin reversed the order outside WooCommerce and is a full reversal referenced to the order. `checkout-draft` never reaches staging.
8. `CustomerRepository::lock_and_get()` runs before the insert so `balance_after` stays an unbroken chain per customer.
9. Nothing slow or externally fallible runs inside the transaction; hooks fire after commit.
10. `Engine/Points/*` stays pure. `ProgramGate` is the one place on the path that reads settings and user meta; campaign conditions are meant to compare against `CustomerFacts` read once by `ContextFactory::customer_facts()`; until `ConditionsEvaluator::evaluate()` is rewritten it still queries (interim fold over the user id).

## Status

Scaffold, with the guard real. `LedgerService::earn()`, `LedgerRepository::find_by_idempotency_key()` / `insert_unless_duplicate()` and `QueryException::is_duplicate_key()` are implemented; the rest are `TODO(F01-4)` stubs except `IdempotencyKey`, `Outcome`, `CustomerFacts` and `EarningService`'s private gate-chain iteration. The guard needs the table to carry `uniq_idempotency_key`, which only a `dbDelta` run installs — see `Database\Installer`. Refund / clawback attaches at `OrderListener::on_refunded()` and `ReviewListener::on_unapproved()` and is not designed here. Listeners are not registered at boot until their bodies exist; `EarningService::build()` is the composition root to call from `YayLoyalty::__construct()`.
