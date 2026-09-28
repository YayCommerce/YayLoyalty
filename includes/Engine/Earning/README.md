# Earning orchestration

Earning has two moments and every class belongs to exactly one:

| Moment | Where | Purity |
|---|---|---|
| **Evaluation** — which campaign, how many points | `Engine/Points/*` (unchanged), reached through `EarningService::preview()` | pure: no `$wpdb`, no WooCommerce globals, no current user, no wall clock |
| **Award** — ledger row, customer cache, pending release, order meta, one transaction | `LedgerService::earn()` + `PendingPoints`, reached through `EarningService::award()` | impure by definition |

Between them: `Gate` (+ `ProgramGate`) and `PendingPoints`. Around them: `Listeners/*`, one per event family, which only build a `Context` and call the service — and, for orders, decide what a status transition means. Nothing below the listener knows an order status.

```
woocommerce_order_status_changed( $id, $from, $to, $order )
     → OrderListener::on_status_changed   routes on $to alone         ─┐
woocommerce_new_order( $id, $order )                                    │  same routing table
     → OrderListener::on_order_created   routes on $order->get_status()┘  ($from is empty here by definition)
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

1. Evaluation runs twice per order at the **same** timestamp — the order's creation date. `CustomerFacts` are as-of that creation date by construction, but "paid" status is read at evaluation time, so stage and award may pick different campaigns when an older order is paid in between. Award is authoritative and re-evaluates; stage and award logs may therefore differ when the customer row changed (invariant 6 describes the hook overlap). The engine reads `Context::occurred_at`, which `ContextFactory::from_order()` sets from the order and throws without.
2. Pending is staged idempotently on `_yayloyalty_points_pending` being absent and released exactly once on `_yayloyalty_points_released`. Award never requires that staging ran.
3. The idempotency key is one string, `IdempotencyKey::compose( type, user_id, reference_type, reference_id, qualifier )` → `earn:7:order:1234:purchase`, in one column under one UNIQUE index. The qualifier is the trigger when earning, the reward type when redeeming.
4. The index is the guard. A pre-check may skip a known duplicate; the insert is attempted and error 1062 becomes `Outcome::duplicate`, a normal return. Implemented: `QueryException::is_duplicate_key()` reads the driver's number, `LedgerRepository::insert_unless_duplicate()` suppresses wpdb's reporting for the attempt so the guard firing leaves no line that reads like a failure, and `LedgerService::earn()` returns the row that won.
5. **Paid is a set of statuses, not a status,** owned by one place: `SettingsHelper::award_statuses()` reads `general.award_statuses`, default `processing` + `completed`. A fully downloadable-and-virtual order never enters `processing`; plenty of physical stores never leave it. The first arrival into the award set wins — a later transition into the set composes the same key and costs one rejected insert. Both `OrderListener` and `CustomerFactsReader` call `award_statuses()` to read the set; no hard-coded paid list lives anywhere else.
6. **Two order hooks, one routing table.** `woocommerce_order_status_changed` only fires when the old status is non-empty (`WC_Abstract_Order::set_status()`), so an order's very first status — set on a brand-new object that has no prior status — never fires it; a checkout or admin order that lands on `pending`/`on-hold` and never transitions again would otherwise never stage. `woocommerce_new_order` covers exactly that gap: WooCommerce guarantees it fires exactly once per order, either at first insert (non-draft status) or at the auto-draft → real-status save (admin). Both hooks route through the same `$to`-based switch — a per-status hook or `woocommerce_payment_complete` alongside either would still describe the same transition twice and is still out; these two don't, since each owns a disjoint moment. Where they do land in the same request (an admin order's single save moving `auto-draft` → `pending` fires both), the overlap is free: staging (invariant 2) and the award's UNIQUE key (invariants 3-4) absorb the repeat as a no-op or a rejected insert, same as any other duplicate arrival into the award set.
7. **Statuses that do nothing are a decision, not an omission.** `failed` releases nothing and restores nothing — the order is still payable and a retry lands on an award status. `refunded` is inert too: refunds are driven by refund objects, except when `get_total_refunded()` is 0, which means an admin reversed the order outside WooCommerce and is a full reversal referenced to the order. `checkout-draft` never reaches staging.
8. `CustomerRepository::lock_and_get()` runs before the insert so `balance_after` stays an unbroken chain per customer.
9. Nothing slow or externally fallible runs inside the transaction; hooks fire after commit.
10. `Engine/Points/*` stays pure. `ProgramGate` is the one place on the path that reads settings and user meta; campaign conditions compare against `CustomerFacts` read once by `ContextFactory::customer_facts()` and, if no evaluator is attached (Lite paying zero queries), not read at all. `CustomerFactsReader::read()` (Pro only) materializes facts, filtering to paid orders created strictly before the event timestamp and excluding the order being evaluated.

## Status

Scaffold, with the guard and evaluation path real. `LedgerService::earn()`, `LedgerRepository::find_by_idempotency_key()` / `insert_unless_duplicate()` and `QueryException::is_duplicate_key()` are implemented; `IdempotencyKey`, `Outcome`, `CustomerFacts`, `CustomerFactsReader`, `EarningService`'s gate-chain iteration, and `ProgramGate::check()` are also implemented. The rest are `TODO(F01-4)` stubs. The guard needs the table to carry `uniq_idempotency_key`, which only a `dbDelta` run installs — see `Database\Installer`. Refund / clawback attaches at `OrderListener::on_refunded()` and `ReviewListener::on_unapproved()` and is not designed here.

Listeners are registered at boot (`EarningService::build()` is the composition root, called from `YayLoyalty::__construct()` after Pro boots), so every event hook above is attached. `OrderListener::on_stageable()` and `on_awardable()` call `preview_and_log()`, which logs the previewed campaign, points or reason to debug and writes nothing. The two `route()` catches around them catch `\Throwable` and log with `Log::error()`: a listener must never break an order save. `on_cancelled()`, `on_refunded()` (step-3 scaffold) and the other listeners' bodies remain no-op stubs; their catches are unchanged. `on_approved()`, `on_unapproved()`, `on_registered()` remain stubs as well.
