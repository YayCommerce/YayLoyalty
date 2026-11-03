# Order Points Recovery Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** active
**Goal:** On a paid order whose points never reached the customer, a shop manager restores them from the order screen. Points come back once per order, stored as that order's earning, and a "Loyalty points" column flags affected orders.
**Spec:** [docs/specs/2026-10-30-order-points-recovery-design.md](../../specs/2026-10-30-order-points-recovery-design.md)
**Architecture:** A new `LedgerService::recover_order()` writes one EARN row per order, keyed `earn:{user}:order:{id}:recovery`. Existing reversals then claw it back like any purchase earn, and a guard inside `write_keyed()`, under the customer lock, skips a later purchase earn. A read-only `OrderPointsState` feeds a PHP metabox, a REST route and the orders list column. The obvious alternative, `LedgerService::adjust()` with an `order_id`, was rejected in the spec: it is not idempotent and is never clawed back.
**Touches:**
- `includes/Services/LedgerService.php` (shared: every ledger write; the guard in `write_keyed()` runs for every keyed row)
- `includes/Repositories/LedgerRepository.php` (shared: `LedgerService`, `Reversal`, `OrderListener`, controllers; additive methods only)
- `includes/Engine/Earning/IdempotencyKey.php` (shared: earning, reversal, redemption, expiry key composition)
- `includes/Engine/Earning/Listeners/OrderListener.php`
- `includes/Engine/Earning/PendingPoints.php` (shared: `OrderListener`, `EarningService`, `OrderReversalListener`; additive methods only)
- `includes/Repositories/CustomerRepository.php` (shared: every `LedgerService` write and every customer read; one additive setter)
- `includes/Engine/Earning/Reversal.php`
- `includes/Engine/Earning/` (new `OrderPointsState`, `OrderPointsRecovery`)
- `includes/Controllers/` (new `OrderPointsRestController`)
- `includes/Engine/RestAPI.php`
- `includes/Engine/Admin/` (new `OrderPointsMetabox`, `OrderPointsColumn`)
- `includes/YayLoyalty.php`
- `assets/admin/order-points.js` (new)
- `apps/admin/src/pages/customers/drawer/CustomerDrawerActions.tsx` (`?adjust=1` opens the Adjust points dialog; amended 2026-11-03)

**Tests:** `tests/integration/Earning/OrderPointsRecoveryTest.php` (integration, `composer test:integration`). It guards ledger and money logic:
- recovery writes the keyed EARN row and moves `balance`, `lifetime_earned` and `pending_points`;
- a second call is a duplicate and writes nothing;
- recovery is refused when a live earn exists;
- a later purchase award is skipped with `recovered_manually`;
- cancelling writes the `refund_earn …:recovery` clawback;
- `test_recovery_after_stale_earned_meta` (case 4): meta claims 40 earned, there is no EARN row and `pending_points = 40`. The state resolves to `missing_known`/40, recovery writes one row, the meta stays 40 and `pending_points` becomes 0.

The user accepted all of these on 2026-10-30.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types. Also no `mixed`/`static`/`never` types, `catch` without a variable, `throw` expressions, a trailing comma after the last declared parameter, `new` in defaults, first-class callables or `$object::class`.
- Only `LedgerService` writes ledger rows and balances. `OrderPointsRecovery` gets the `LedgerService` built in `Engine\RestAPI::controllers()`, an existing listed construction site. No new construction site.
- Lite feature: everything lives in core `includes/`, nothing under `includes/Pro/`.
- Orders are read and written only through the WooCommerce CRUD API. Order meta is written only through `PendingPoints`, which saves meta only and never does a full save.
- The REST route uses `exec_write()` and `requires( Capabilities::MANAGE_POINTS )`. The actor is `get_current_user_id()`, never a request field.
- Points are integers from 1 to 1,000,000, taken from `OrderPointsState::get_figure()`, never from the request. The note is always "Recovered missing order points" (amended 2026-11-03: no Manual mode, no reason field).
- Strings use the `yayloyalty` text domain. Hooks, globals, meta box ids and column keys use the `yayloyalty_`/`yayloyalty-` prefix.
- Order notes and hooks that call out are written from `Transaction::after_commit()`.
- SQL values go through `$wpdb->prepare()` placeholders, and the table name through `esc_sql()` in backticks. Logging goes only through `Log::error|warning|debug`.
- Datetimes are stored in UTC and shown with `Dates::to_site_date()`.
- `assets/admin/order-points.js` is plain JS with no build step. It depends on `wp-api-fetch` and is enqueued only on the order edit screen.
- Lint only the changed PHP files with `composer lint -- <files>`. A whole-repo run fails today on known warnings (docs/known-issues.md); the changed files must add no error or warning.

## Review focus

- **A recovery and a completion running together.** Expected: one row only, because both writes take the customer lock and the guard and the live-earn check read with locking reads. Owner: Phase 1 Task 1, accepted test `test_later_completion_is_skipped_after_recovery` plus code review of `is_locking`.
- **An order recovered, then set to `processing` and completed again.** (Since 2026-11-03 the box offers recovery only on `completed`.) Expected: no purchase row, and the note "No points earned: points were recovered manually." Owner: Phase 1 Task 1 (test) and Task 3 (note check).
- **An order reversed then restored.** Expected: the Confirm figure is the original earn sum, and recovery succeeds even though the purchase key is spent. Owner: Phase 1 Task 2, verification step.
- **A double click or a retried POST.** Expected: 409 `yayloyalty_already_recovered`, with the balance unchanged. Owner: Phase 1 Task 1 (test) and Task 2 (REST check).
- **A partial refund of a recovered order.** Expected: claws back by the share of every product line, with no "campaign is gone" warning. Owner: Phase 1 Task 3, verification step.
- **Case 4, the server failed during completion with a persistent object cache.** The order meta says "earned 40" or "released", but the database and ledger have nothing. Expected: the box offers **Give 40 points**, recovery leaves `_yayloyalty_points_earned` at 40, and `pending_points` is correct. Owner: Phase 1 Task 2, accepted test `test_recovery_after_stale_earned_meta`, plus a Redis/Memcached check in its verification step.
- **A shop manager without `yayloyalty_manage_points`.** Expected: no box and 403 from the route. Owner: Phase 1 Task 4, verification step.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | The "Loyalty points" box on the order screen recovers missing points with a known figure in one click, once per order, and links every other manual grant to SPA Adjust points. Refunds and cancels claw them back, and a later completion adds nothing. | Spec acceptance 11 steps 1, 2, 4, 5 and 6 pass on the local site, and `composer test:integration` prints `OK` with the 7 `OrderPointsRecoveryTest` methods in its count | pending |
| 2 | The orders list "Loyalty points" column shows earned points, a **Missing** badge, or "–" | Spec acceptance 11 passes in full, step 3 included | pending |

## Interface contract

- `IdempotencyKey::RECOVERY = 'recovery'` — a qualifier accepted only for `LedgerType::EARN`, `LedgerType::REFUND_EARN` and `LedgerType::ADJUST` (a deleted refund's restore; amended 2026-11-03). Produced by Phase 1 Task 1.
- `LedgerRepository::order_points_summary( int $order_id, bool $is_locking = false ): array{earned: int, net: int, reversed: int}` — `earned` is the SUM of EARN points with this `order_id`. `net` is the SUM over EARN, REFUND_EARN and (ADJUST with `reference_type = refund`) rows with this `order_id`. `reversed` (amended 2026-11-03) is minus the SUM of REFUND_EARN rows with `reference_type = order`: what whole-order reversals took. Produced by Phase 1 Task 1, consumed by Phase 1 Tasks 1 and 2 and Phase 2 Task 1.
- `LedgerRepository::find_recovery_for_order( int $order_id, bool $is_locking = false ): ?LedgerEntry` — the EARN row with this `order_id` whose key ends in `:recovery`, whatever its user. The purchase guard reads it locking, so an order reassigned after its recovery is not paid again (amended 2026-11-03). Produced by Phase 1 Task 1, consumed by Phase 1 Task 2.
- `LedgerService::REASON_RECOVERED = 'recovered_manually'` and `LedgerService::REASON_ORDER_HAS_POINTS = 'order_has_points'` — produced by Phase 1 Task 1, consumed by Phase 1 Tasks 2 and 3.
- `LedgerService::recover_order( int $user_id, int $order_id, int $points, string $note, int $actor_id ): Outcome` — returns `awarded`, `duplicate` or `skipped( REASON_ORDER_HAS_POINTS )`. Produced by Phase 1 Task 1, consumed by Phase 1 Task 2.
- `OrderPointsState` (`includes/Engine/Earning/OrderPointsState.php`):
  - `resolve( \WC_Order $order, LedgerRepository $ledger ): self`
  - constants `NOT_PAID = 'not_paid'`, `GUEST = 'guest'`, `EARNED = 'earned'`, `RECOVERED = 'recovered'`, `AWAITING_COMPLETION = 'awaiting_completion'` (amended 2026-11-03), `MISSING_KNOWN = 'missing_known'`, `MISSING_UNKNOWN = 'missing_unknown'`
  - getters `get_status(): string`, `get_figure(): int`, `get_net_earned(): int` (the recovery row and its reversed flag reach callers through `to_array()`)
  - `to_array(): array`

  Produced by Phase 1 Task 2, consumed by Phase 1 Task 4 and Phase 2 Task 1.
- `PendingPoints`:
  - `forget_cached( int $order_id ): void` drops WooCommerce's cached order and meta.
  - `restate_earned( int $order_id, LedgerEntry $entry ): void` sets `_yayloyalty_points_earned` to the entry's points.
  - `resync_customer( int $user_id ): int` recomputes `customers.pending_points` from unreleased order meta.

  Produced by Phase 1 Task 2, consumed by Phase 1 Tasks 2 and 4.
- `CustomerRepository::set_pending_points( int $user_id, int $points ): void` (floored at 0), called only by `PendingPoints::resync_customer()`. Produced by Phase 1 Task 2.
- REST route `POST yayloyalty/v1/orders/(?P<id>\d+)/points` (the GET route was removed 2026-11-03: no client read it):
  - body: none. Only `missing_known` is recovered; `missing_unknown` gets 409 `yayloyalty_not_eligible`.
  - answers with `OrderPointsState::to_array()`
  - errors: 404 `yayloyalty_order_not_found`, 409 `yayloyalty_not_eligible`, 409 `yayloyalty_order_has_points`, 409 `yayloyalty_already_recovered`

  Produced by Phase 1 Task 2, consumed by Phase 1 Task 4's script.
