# Earning orchestration

Earning has two moments and every class belongs to exactly one:

| Moment | Where | Purity |
|---|---|---|
| **Evaluation** — which campaign, how many points | `Engine/Points/*` (unchanged), reached through `EarningService::preview()` | pure: no `$wpdb`, no WooCommerce globals, no current user, no wall clock |
| **Award** — ledger row, customer cache, pending release, order meta, one transaction | `LedgerService::earn()` + `PendingPoints`, reached through `EarningService::award()` | impure by definition |

Between them: `Gate` (+ `ProgramGate`) and `PendingPoints`. Around them: `Listeners/*`, one per event family, which only build a `Context` and call the service.

```
hook → Listener → ContextFactory::from_order( $order, points_earned_today )   occurred_at = order creation date
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
3. The idempotency key is one string, `IdempotencyKey::compose( type, user_id, reference_type, reference_id, trigger )` → `earn:7:order:1234:purchase`, in one column under one UNIQUE index.
4. The index is the guard. A pre-check may skip a known duplicate; the insert is attempted and error 1062 becomes `Outcome::duplicate`, a normal return.
5. `CustomerRepository::lock_and_get()` runs before the insert so `balance_after` stays an unbroken chain per customer.
6. Nothing slow or externally fallible runs inside the transaction; hooks fire after commit.
7. `Engine/Points/*` stays pure. `ProgramGate` is the one place on the path that reads settings and user meta; campaign conditions are meant to compare against `CustomerFacts` read once by `ContextFactory::customer_facts()`; until `ConditionsEvaluator::evaluate()` is rewritten it still queries (interim fold over the user id).

## Status

Scaffold. Bodies are `TODO(F01-4)` stubs except `IdempotencyKey`, `Outcome`, `CustomerFacts` and `EarningService`'s private gate-chain iteration. Refund / clawback attaches at `OrderListener::on_refunded()` and `ReviewListener::on_unapproved()` and is not designed here. Listeners are not registered at boot until their bodies exist; `EarningService::build()` is the composition root to call from `YayLoyalty::__construct()`.
