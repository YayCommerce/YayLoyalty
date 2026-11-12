# Redemption Order Lifecycle Fixes Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** active
**Goal:** Redeemed and earned points follow the order's money. A declined payment returns the redeemed points, and paying again takes them back. A loyalty line leaving an order by any route returns its points unless the admin chose "Don't restore". Amount-only refunds claw back earned points in proportion.
**Spec:** [docs/specs/2026-11-12-redemption-order-lifecycle-fixes-design.md](../../specs/2026-11-12-redemption-order-lifecycle-fixes-design.md)
**Architecture:**
- A failed order restores its rows into a new status `unpaid`. Pay-for-order debits them back before the gateway runs, and a late "paid" status does the same leniently. Each move is keyed on the ledger row it undoes (`ReferenceType::LEDGER`), so every cycle gets fresh keys while a re-fired event collides.
- Coupon-line deletions are queued and settled once at `shutdown`, so REST v3's remove-then-re-apply is not mistaken for a removal.
- The earn clawback adds an amount share for refunds that carry no product line.

**Touches:**
- `includes/Enums/ReferenceType.php` (shared: every ledger writer and the ledger REST output)
- `includes/Models/Redemption.php` (shared: REST enum, `RedemptionsRestController`, the SPA schema)
- `includes/Repositories/LedgerRepository.php`
- `includes/Engine/Redemption/Reversal.php` (shared: `RedemptionService::build()`, `OrderReversalListener`, `OrderCouponRemoval`, `ReversalTest`, `LoyaltyCodeOnOrderTest`, `OrderReversalListenerTest`; its constructor gains `LedgerRepository`)
- `includes/Engine/Earning/Listeners/OrderListener.php`
- `includes/Engine/Earning/Listeners/OrderReversalListener.php`
- new `includes/Engine/Redemption/PayForOrderRedebit.php`
- `includes/Engine/Redemption/Adapters/StoreApiAdapter.php`
- new `includes/Engine/Redemption/RemovedLineReconciler.php`
- `includes/Engine/Admin/OrderCouponRemoval.php`
- `assets/admin/order-coupon-removal.js`
- `includes/Engine/Redemption/CouponProvider.php`
- `includes/Engine/Earning/Reversal.php`
- `includes/YayLoyalty.php`
- `apps/admin/src/lib/schema/redemption.schema.ts`
- `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`
- `apps/admin/src/pages/customers/drawer/customer-drawer.redemption-rows.ts`
- `apps/e2e/specs/admin-remove-loyalty-coupon.spec.ts`
- docs: `docs/decisions/`, `docs/architecture.md`, `docs/backlog/`
- `readme.txt`

**Tests:** accepted in the spec, 2026-11-12.
- `tests/integration/Engine/Redemption/PaymentFailedTest.php` (new), integration suite. Guards the failed and pay cycle: spec criteria 1 to 8.
- `tests/integration/Engine/Redemption/RemovedLineReconcilerTest.php` (new), integration suite. Guards line-removal restores and waives: criteria 9, 10, 12 (server half), 14 and 15.
- New cases in `tests/integration/Earning/ReversalFlowTest.php`, integration suite. Guards the amount-only clawback: criteria 16 to 19.
- `apps/e2e/specs/admin-remove-loyalty-coupon.spec.ts` (updated), Playwright. Guards the dialog's Don't-restore being final: criteria 11 to 13.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static` types, or a trailing comma after the last parameter.
- WooCommerce floor stays `10.0`. Do not hook `woocommerce_order_removed_coupon`.
- Only `LedgerService` writes ledger rows and balances, and nothing new constructs it. `Redemption\Reversal` comes from `RedemptionService::build()`'s `reversal()`, and `Earning\Reversal` from `Reversal::build()`.
- Lite owns all of this. Nothing under `includes/Engine/` names `YayLoyalty\Pro\*`.
- Admin script stays plain JS with no build step.
- Strings use the `yayloyalty` text domain. "points" comes from `SettingsHelper::points_label_plural()` / `points_text()`, never hard-coded.
- Logging only through `Log::error()`, `warning()` or `debug()`, with the reporting class as context.
- Capability `Capabilities::MANAGE_POINTS` (`yayloyalty_manage_points`). Nonce action `yayloyalty-restore-coupon`, field `yayloyalty_nonce`. New POST flag `yayloyalty_waive`.
- Coupon codes are compared with `wc_format_coupon_code()` on both sides.
- No schema change: `unpaid` fits `status varchar(12)` and `ledger` fits `reference_type varchar(20)`.
- `composer lint` and the admin lint fail on `dev` today ([known issues](../../known-issues.md)): lint only the changed files, and expect no new findings.
- `composer test:integration` can exit 0 without running ([known issues](../../known-issues.md)): a pass shows `OK (N tests` in the output.
- Every hook this plan adds is attached in a `register()` method that `includes/YayLoyalty.php` calls once.

## Review focus

- **A customer double-clicks Pay on pay-for-order.** Both requests hit `payment_retried()`. The second must find the rows `applied` and write nothing. Owner: Phase 1 Task 2 (`test_retrying_twice_debits_once`).
- **A gateway marks the order `failed` and then `processing` in the same request,** as some 3DS flows do. The restore and the debit must both land, under distinct keys, leaving one net debit. Owner: Phase 1 Task 3 (`test_failed_then_processing_in_one_request_nets_one_debit`).
- **A REST v3 order update that keeps a loyalty line** must not restore it, even though WooCommerce deletes and re-adds the line during the request. Owner: Phase 2 Task 2 (`test_a_rest_update_that_keeps_the_line_restores_nothing`).
- **A reconcile that throws at `shutdown`** after the response is sent. The other queued lines still settle, and the order gets the failure note. Owner: Phase 2 Task 2 (manual check, step 5).
- **A refund that carries a product line plus a shipping amount** must keep today's line-only clawback. Owner: Phase 3 Task 1 (`test_a_line_refund_with_extra_amount_claws_back_the_line_share_only`).

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | A declined payment returns redeemed points. Paying again takes them back, or is refused when the balance is short. | `composer test:integration -- --filter PaymentFailedTest` prints `OK (` with 0 failures, and the manual decline-then-pay walkthrough in phase 1's exit criterion passes on brandy.test | done 2026-11-12 |
| 2 | Removing a loyalty line by any route returns its points. "Don't restore" is final. | `composer test:integration -- --filter 'RemovedLineReconcilerTest\|ReversalTest\|LoyaltyCodeOnOrderTest'` prints `OK (`, and `pnpm --filter @yayloyalty/e2e test admin-remove-loyalty-coupon` passes | done 2026-11-12 |
| 3 | Amount-only refunds claw back earned points. Docs and the decision record are updated. | The spec's criterion 21: `composer test:integration` prints `OK (N tests`, `pnpm --filter @yayloyalty/e2e test` passes, and the three brandy.test walkthroughs pass | pending |

## Interface contract

- `ReferenceType::LEDGER = 'ledger'`, in `ReferenceType::all()`. Produced by Phase 1 Task 1, consumed by Phase 1 Task 2.
- `Redemption::STATUS_UNPAID = 'unpaid'`, in `Redemption::STORED_STATUSES`. Produced by Phase 1 Task 1, consumed by Phase 1 Tasks 2 to 4 and Phase 2 Tasks 1 and 3.
- `LedgerRepository::latest_for_redemption( int $redemption_id, string $type ): ?LedgerEntry`: the row with the highest id of `$type` for that redemption. Produced by Phase 1 Task 1, consumed by Phase 1 Task 2.
- `Redemption\Reversal::payment_failed( \WC_Order $order ): Outcome[]`. Produced by Phase 1 Task 2, consumed by Phase 1 Task 3.
- `Redemption\Reversal::payment_retried( \WC_Order $order, bool $allow_negative ): Outcome[]`. When `$allow_negative` is false it is all or nothing: on a short balance every outcome is `Outcome::skipped( Rewards\Result::REASON_INSUFFICIENT_BALANCE )` and nothing is written. Produced by Phase 1 Task 2, consumed by Phase 1 Tasks 3 and 4.
- `Redemption\Reversal::has_unpaid( \WC_Order $order ): bool`. Produced by Phase 1 Task 2, consumed by Phase 1 Task 4.
- `OrderReversalListener::on_failed( \WC_Order $order ): void` and `::on_paid_after_failure( \WC_Order $order ): void`. Produced by Phase 1 Task 3.
- `PayForOrderRedebit::redebit_or_message( \WC_Order $order ): ?string` (null when allowed or nothing to do) and `::register(): void`. Produced by Phase 1 Task 4, consumed by `StoreApiAdapter`.
- `Redemption\Reversal::coupon_removed( \WC_Order $order, string $code, bool $waive = false ): ?Outcome`. It acts on `applied` and `unpaid`. Produced by Phase 2 Task 1, consumed by Phase 2 Task 2.
- `RemovedLineReconciler::register(): void`, `::waive( int $order_id, string $code ): void` and `::settle(): void` (public, so tests call it instead of `shutdown`). Produced by Phase 2 Task 2, consumed by Phase 2 Task 3.
- `OrderCouponRemoval::__construct( RedemptionRepository $redemptions, RewardRepository $rewards, RemovedLineReconciler $reconciler )`. Produced by Phase 2 Task 3.
- POST field `yayloyalty_waive` (`1`) on `woocommerce_remove_order_coupon`, with `yayloyalty_nonce`. It replaces `yayloyalty_restore`. Produced by Phase 2 Task 3.
- Order note templates, all translated:
  - `'Restored %1$d redeemed points (%2$s).'` with reason `'payment failed'`. Phase 1 Task 3.
  - `'Debited %1$d redeemed points again (%2$s).'` with reason `'payment received'`. Phase 1 Task 3.
  - `'%1$s removed — points not restored, as chosen by %2$s.'`. Phase 2 Task 2.
- Storefront message `'You no longer have enough points for the points discount on this order.'`. Phase 1 Task 4.
