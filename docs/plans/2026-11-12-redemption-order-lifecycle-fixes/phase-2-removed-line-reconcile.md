# Phase 2: Removed Line Reconcile

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 Task 1 (`STATUS_UNPAID`) and Task 2 (`Reversal` now takes `LedgerRepository`).
**Delivers:** a loyalty coupon line that leaves an order by any route (dialog, REST v3, `remove_coupon()`) returns its points at the end of the request. The dialog's "Don't restore" makes the row `removed` with nothing returned, and a later cancel does not return it.
**Exit criterion:**
- `composer test:integration -- --filter 'RemovedLineReconcilerTest|ReversalTest|LoyaltyCodeOnOrderTest'` prints `OK (` with 0 failures.
- `pnpm --filter @yayloyalty/e2e test admin-remove-loyalty-coupon` passes.

### Task 1: `coupon_removed()` waives and accepts unpaid rows; the guard accepts unpaid

**Files:**
- Modify: `includes/Engine/Redemption/Reversal.php`
- Modify: `includes/Engine/Redemption/CouponProvider.php`
- Test: `tests/integration/Engine/Redemption/ReversalTest.php`

**Interfaces:**
- Consumes: `STATUS_UNPAID`.
- Produces: `coupon_removed( \WC_Order $order, string $code, bool $waive = false ): ?Outcome` (see README contract).

Behaviour to pin:
- `$waive` true, or a row that is `unpaid`: no ledger row is written, `record_restore( id, restored_points, STATUS_REMOVED )` runs, and the answer is `Outcome::skipped( REASON_NOTHING_TO_MOVE )`.
- `only_redeemed_on_order()` accepts `applied` or `unpaid`.
- `ReversalTest::test_a_line_removed_without_restore_leaves_the_row_for_the_cancel` encodes the reversed rule. Replace it with `test_a_waived_removal_is_final`: after `coupon_removed( $order, $code, true )` the ledger is `[ 1000, -500 ]` and the row is `removed` with `restored_points` 0. After `cancelled( $order )` the ledger is still `[ 1000, -500 ]`.
- Add `test_removing_an_unpaid_line_writes_nothing` (spec criterion 15).

Steps: expand after Phase 1 lands.

### Task 2: `RemovedLineReconciler`

**Files:**
- Create: `includes/Engine/Redemption/RemovedLineReconciler.php`
- Modify: `includes/YayLoyalty.php`
- Test: `tests/integration/Engine/Redemption/RemovedLineReconcilerTest.php` (new)

**Interfaces:**
- Consumes: `coupon_removed()` from Task 1.
- Produces: `RemovedLineReconciler` (see README contract). Its constructor is `( Reversal $reversal, RedemptionRepository $redemptions, RewardRepository $rewards )`; the reward names are for the notes.

Behaviour to pin:
- Hooks:
  - `woocommerce_before_delete_order_item` ( `$item_id` ) queues the item's order and code when it is a loyalty `WC_Order_Item_Coupon`.
  - `woocommerce_before_delete_order` ( `$order_id` ) drops that order from the queue.
  - `shutdown` at priority 0 calls `settle()`.
- `settle()` empties the queue first, so a second call does nothing. For each entry it skips:
  - an order that `wc_get_order()` cannot load, or whose status is `trash`;
  - an order that still carries the code (`wc_format_coupon_code()` on both sides).

  Otherwise it calls `coupon_removed( $order, $code, isset( waived[order][code] ) )` and notes the outcome:
  - restored: `'%1$s removed — %2$s restored.'`, the existing string, moved from `OrderCouponRemoval`;
  - waived: `'%1$s removed — points not restored, as chosen by %2$s.'`, with the current user's `display_name`;
  - `key_spent` or a `Throwable`: the existing failure note, plus `Log::error( self::class, … )`. The loop continues to the next entry.
- Registered in `YayLoyalty::register_redemption()`, not behind `is_admin()`. The instance is kept for `OrderCouponRemoval`.
- Tests call `settle()` directly (spec criteria):
  - `test_remove_coupon_restores_at_settle` (9): the ledger gains +500 keyed `refund_redeem:7:redemption:<id>:flexible_discount`, the row is `removed`, and the note is present.
  - `test_a_rest_update_that_keeps_the_line_restores_nothing` (10): `PUT /wc/v3/orders/<id>` through `$this->send()` with `coupon_lines` holding the code, then `settle()`. The ledger is unchanged.
  - `test_a_rest_update_that_drops_the_line_restores_it` (10).
  - `test_a_waived_line_is_final` (12, server half): `waive( id, code )`, then `remove_coupon()`, then `settle()`. The note names the admin.
  - `test_a_deleted_order_restores_nothing` (14): `$order->delete( true )`, then `settle()`.
- Manual check for the Review-focus line "a reconcile that throws at shutdown":
  1. Temporarily make `coupon_removed()` throw. Do not commit this.
  2. Remove one of two loyalty lines with `remove_coupon()` in a scratch script.
  3. Expected: the order gets the failure note, the other line still settles, and the `yayloyalty` WooCommerce log has one `RemovedLineReconciler` error.

Steps: expand after Phase 1 lands.

### Task 3: The dialog's "Don't restore" waives

**Files:**
- Modify: `includes/Engine/Admin/OrderCouponRemoval.php`
- Modify: `assets/admin/order-coupon-removal.js`
- Modify: `includes/YayLoyalty.php`
- Test: `apps/e2e/specs/admin-remove-loyalty-coupon.spec.ts`

**Interfaces:**
- Consumes: `RemovedLineReconciler::waive()` from Task 2.
- Produces: the new `OrderCouponRemoval` constructor and the POST flag `yayloyalty_waive` (see README contract).

Behaviour to pin:
- `before_remove()` reads `yayloyalty_waive` instead of `yayloyalty_restore`. The nonce and capability checks and their errors are unchanged (criterion 13). On success it calls `$this->reconciler->waive( $order_id, $code )`.
- The one-shot listener, `restore()` and `note_failure()` are deleted; their notes now live in the reconciler.
- In the JS, "Restore" calls `resend( link )` with `pending = null`. "Don't restore" sets `pending = code`, and the ajax-data filter adds `yayloyalty_waive: 1` and `yayloyalty_nonce`.
- The class and script docblocks describe the new split: the server restores by default, and the dialog only asks whether to waive.
- e2e: keep the existing steps. Add a second test, "Don't restore keeps the points spent, even after a cancel": remove with "Don't restore Points", then set the order to Cancelled through `adminPage`. `api.expectPoints( customer.id, { balance: 500 } )`, and `api.redemptions()` matches `[{ status: 'removed' }]`.

Steps: expand after Phase 1 lands.
