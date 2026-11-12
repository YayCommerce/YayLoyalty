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

- [ ] **Step 1: Write the failing tests**

In `ReversalTest`, delete `test_a_line_removed_without_restore_leaves_the_row_for_the_cancel` and add:

```php
public function test_a_waived_removal_is_final(): void {
    $reward = $this->seeded_reward();
    $order  = $this->redeemed_order( $reward );

    $outcome = $this->reversal()->coupon_removed( $order, $reward->get_coupon_code(), true );

    $this->assertSame( Reversal::REASON_NOTHING_TO_MOVE, $outcome->get_reason() );
    $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
    $this->assertSame( 0, (int) $this->row()['restored_points'] );

    $this->reversal()->cancelled( $order );

    $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
}

public function test_removing_an_unpaid_line_writes_nothing(): void {
    $reward = $this->seeded_reward();
    $order  = $this->redeemed_order( $reward );
    $this->reversal()->payment_failed( $order );

    $outcome = $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );

    $this->assertSame( Reversal::REASON_NOTHING_TO_MOVE, $outcome->get_reason() );
    $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
    $this->assertSame( 500, (int) $this->row()['restored_points'] );
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter ReversalTest`
Expected: 2 failures. `test_a_waived_removal_is_final` fails on the ledger (`coupon_removed()` ignores the third argument and restores 500); `test_removing_an_unpaid_line_writes_nothing` fails on the status (`unpaid` stays `unpaid`).

- [ ] **Step 3: Implement**

- `Redemption\Reversal::coupon_removed( \WC_Order $order, string $code, bool $waive = false ): ?Outcome`
  - The lock guard accepts `[ STATUS_APPLIED, STATUS_UNPAID ]`; anything else still answers `REASON_NOTHING_TO_MOVE`.
  - When `$waive` is true or the row is `unpaid`: `record_restore( id, $row->get_restored_points(), STATUS_REMOVED )`, no ledger row, answer `Outcome::skipped( REASON_NOTHING_TO_MOVE )`. This branch comes before the "already fully restored" branch.
  - Docblock: every route a line leaves the order by ends here (through `RemovedLineReconciler`); `$waive` is the admin's "Don't restore"; an `unpaid` row has its points back already.
- The class docblock: replace "An admin removing a line with Restore on the order screen returns …" with: a loyalty line leaving the order by any route returns what is left of its row under `[ redemption, id ]`, unless the admin waived it, and makes it `removed`.
- `CouponProvider::only_redeemed_on_order()` accepts a row that is `applied` or `unpaid` (`in_array( …, true )`), so a REST v3 update of an unpaid order that keeps its line passes. Update its docblock.

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter 'ReversalTest|LoyaltyCodeOnOrderTest|PaymentFailedTest'`
Expected: `OK (` with 0 failures.

- [ ] **Step 5: Lint and commit**

Run: `composer lint -- includes/Engine/Redemption/Reversal.php includes/Engine/Redemption/CouponProvider.php tests/integration/Engine/Redemption/ReversalTest.php`
Expected: no new findings.

Stage: `includes/Engine/Redemption/Reversal.php`, `includes/Engine/Redemption/CouponProvider.php`, `tests/integration/Engine/Redemption/ReversalTest.php`
Message: `feat(redemption): let a removed line waive its restore and accept unpaid rows`

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

- [ ] **Step 1: Write the failing tests**

`RemovedLineReconcilerTest extends IntegrationTestCase`. Its `reversal()`, `redeemed_order()`, `row()` and `last_ledger_row()` helpers are copied from `ReversalTest`, and `order_with_committed_reward()` from `LoyaltyCodeOnOrderTest` (the REST cases use a Pro fixed-coupon reward, whose code `new WC_Coupon()` can load without a cart). It also has:

```php
private RemovedLineReconciler $reconciler;

protected function setUp(): void {
    parent::setUp();

    $this->reconciler = new RemovedLineReconciler( $this->reversal(), new RedemptionRepository(), new RewardRepository() );
    $this->reconciler->register();
}

protected function tearDown(): void {
    remove_action( 'woocommerce_before_delete_order_item', [ $this->reconciler, 'on_item_deleted' ] );
    remove_action( 'woocommerce_before_delete_order', [ $this->reconciler, 'on_order_deleted' ] );
    remove_action( 'shutdown', [ $this->reconciler, 'settle' ], 0 );

    parent::tearDown();
}

/** @return string[] */
private function notes( \WC_Order $order ): array {
    return array_map( static fn( $note ): string => $note->content, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
}
```

The plugin's own reconciler is registered too and settles at the PHPUnit process's `shutdown`; by then each queued row is `removed` or its order is gone, so it writes nothing.

```php
public function test_remove_coupon_restores_at_settle(): void {
    $reward = $this->seeded_reward();
    $order  = $this->redeemed_order( $reward );

    $order->remove_coupon( $reward->get_coupon_code() );
    $order->save();
    $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );

    $this->reconciler->settle();

    $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    $this->assertSame( sprintf( 'refund_redeem:7:redemption:%d:flexible_discount', (int) $this->row()['id'] ), $this->last_ledger_row()['idempotency_key'] );
    $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
    $this->assertContains( sprintf( 'Cart discount removed — %s restored.', SettingsHelper::points_text( 500 ) ), $this->notes( $order ) );

    $this->reconciler->settle();
    $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
}

public function test_a_rest_update_that_keeps_the_line_restores_nothing(): void {
    $this->act_as_admin();
    list( $order, $reward ) = $this->order_with_committed_reward();

    $response = $this->send( 'PUT', '/wc/v3/orders/' . $order->get_id(), [ 'coupon_lines' => [ [ 'code' => $reward->get_coupon_code() ] ] ] );
    $this->reconciler->settle();

    $this->assertSame( 200, $response->get_status() );
    $this->assertSame( [ $reward->get_coupon_code() ], wc_get_order( $order->get_id() )->get_coupon_codes() );
    $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
}

public function test_a_rest_update_that_drops_the_line_restores_it(): void {
    $this->act_as_admin();
    list( $order ) = $this->order_with_committed_reward();

    $response = $this->send( 'PUT', '/wc/v3/orders/' . $order->get_id(), [ 'coupon_lines' => [] ] );
    $this->reconciler->settle();

    $this->assertSame( 200, $response->get_status() );
    $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
}

public function test_a_waived_line_is_final(): void {
    $this->act_as_admin();
    $reward = $this->seeded_reward();
    $order  = $this->redeemed_order( $reward );

    $this->reconciler->waive( $order->get_id(), $reward->get_coupon_code() );
    $order->remove_coupon( $reward->get_coupon_code() );
    $order->save();
    $this->reconciler->settle();

    $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
    $this->assertSame( 0, (int) $this->row()['restored_points'] );
    $this->assertContains( sprintf( 'Cart discount removed — points not restored, as chosen by %s.', wp_get_current_user()->display_name ), $this->notes( $order ) );
}

public function test_a_deleted_order_restores_nothing(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );

    $order->delete( true );
    $this->reconciler->settle();

    $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter RemovedLineReconcilerTest`
Expected: errors, "Class \"YayLoyalty\Engine\Redemption\RemovedLineReconciler\" not found".

- [ ] **Step 3: Implement `RemovedLineReconciler`**

`final class RemovedLineReconciler` in `includes/Engine/Redemption/`, constructor `( Reversal $reversal, RedemptionRepository $redemptions, RewardRepository $rewards )`.
- State: `array<int, array<string, true>> $queue` and `$waived`, both order id ⇒ formatted code ⇒ true.
- `register(): void` attaches `on_item_deleted` to `woocommerce_before_delete_order_item`, `on_order_deleted` to `woocommerce_before_delete_order`, and `settle` to `shutdown` at priority 0.
- `on_item_deleted( $item_id ): void`: loads `\WC_Order_Factory::get_order_item( absint( $item_id ) )`; queues `[ order_id ][ wc_format_coupon_code( code ) ]` when it is a `\WC_Order_Item_Coupon` and `EarnContext::is_loyalty_code()` (as `CouponProvider` uses it).
- `on_order_deleted( $order_id ): void`: `unset( $this->queue[ absint( $order_id ) ] )`.
- `waive( int $order_id, string $code ): void`: `$this->waived[ $order_id ][ wc_format_coupon_code( $code ) ] = true`.
- `settle(): void`: copies and empties `$queue` and `$waived` first, then for each entry:
  - skips it when `wc_get_order()` is not a `\WC_Order`, its status is `trash`, or a `wc_format_coupon_code()` of one of `get_coupon_codes()` equals the code;
  - reads the row with `find_by_order_and_coupon_code()` before the call (skip when null), for the name (`name_of()`, moved from `OrderCouponRemoval`) and to know whether it was `applied`;
  - calls `coupon_removed( $order, $code, $waived )` inside `try`, and notes:
    - `AWARDED`: `'%1$s removed — %2$s restored.'` with `SettingsHelper::points_text()` of the entry's points;
    - waived and the row was `applied`: `'%1$s removed — points not restored, as chosen by %2$s.'` with `wp_get_current_user()->display_name`;
    - `REASON_KEY_SPENT`: `Log::error( self::class, … )` and the failure note;
    - a `\Throwable`: `Log::error( self::class, … )` and the failure note; the loop goes on.
  - `note_failure()` moves here from `OrderCouponRemoval` with its string unchanged. Notes are added with `add_order_note( …, 0, true )` as today.
- Class docblock: why removals are queued and settled at `shutdown` (REST v3 removes every coupon line and re-applies the kept ones in one request), and that the dialog only decides whether to waive.
- `YayLoyalty::register_redemption()` builds it with `$service->reversal()`, `new RedemptionRepository()`, `new RewardRepository()`, calls `register()`, and returns it (return type `RemovedLineReconciler`). The constructor keeps it in a local `$reconciler` for Task 3. Update that method's docblock.

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter 'RemovedLineReconcilerTest|ReversalTest|LoyaltyCodeOnOrderTest'`
Expected: `OK (` with 0 failures.

- [ ] **Step 5: Verify a throwing reconcile by hand (Review focus)**

1. Temporarily add `throw new \RuntimeException( 'boom' );` as the first line of the closure in `Reversal::coupon_removed()` only when `$code` is the first reward's code. Do not commit this.
2. Run a scratch script with `wp eval-file` on brandy.test that commits an order with two Pro reward lines (as `order_with_reward_lines()` does), then calls `remove_coupon()` for both and `save()`. The script ends, so `shutdown` settles.
3. Expected: the order has the failure note for the first reward and "… removed — 500 Points restored." for the second; WooCommerce → Status → Logs (`yayloyalty`) has one `RemovedLineReconciler` error.
4. Revert the throw: `git diff includes/Engine/Redemption/Reversal.php` is empty.

- [ ] **Step 6: Lint and commit**

Run: `composer lint -- includes/Engine/Redemption/RemovedLineReconciler.php includes/YayLoyalty.php tests/integration/Engine/Redemption/RemovedLineReconcilerTest.php`
Expected: no new findings.

Stage: `includes/Engine/Redemption/RemovedLineReconciler.php`, `includes/YayLoyalty.php`, `tests/integration/Engine/Redemption/RemovedLineReconcilerTest.php`
Message: `feat(redemption): restore a loyalty line's points whenever it leaves an order`

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

- [ ] **Step 1: Write the failing e2e test**

In `admin-remove-loyalty-coupon.spec.ts`, move the checkout of the first test into a `placeOrderWithReward( api, customer, shopperPage ): Promise<number>` helper in the same file, and add:

```ts
test("Don't restore keeps the points spent, even after a cancel", async ({ api, customer, shopperPage, adminPage }) => {
  const orderId = await placeOrderWithReward(api, customer, shopperPage);

  await openOrder(adminPage, orderId);
  const removeLink = adminPage.locator('#woocommerce-order-items a.remove-coupon');
  await removeLink.dispatchEvent('click');
  await adminPage.getByRole('dialog', { name: 'Remove $5 off?' }).getByRole('button', { name: "Don't restore Points" }).click();
  await expect(removeLink).toHaveCount(0);
  await expect(adminPage.locator('ul.order_notes')).toContainText('$5 off removed — points not restored, as chosen by');

  await setOrderStatusInAdmin(adminPage, orderId, 'cancelled');
  await api.expectPoints(customer.id, { balance: 500 });
  expect(await api.redemptions(customer.id)).toMatchObject([{ status: 'removed' }]);
});
```

Running it needs Node 22 and wp-env: ask the user first. When it runs before Step 2, it fails on the balance (the cancel returns 500).

- [ ] **Step 2: Implement**

- `OrderCouponRemoval`:
  - Constructor `( RedemptionRepository $redemptions, RewardRepository $rewards, RemovedLineReconciler $reconciler )`; `Reversal` goes.
  - `before_remove()` first attaches `[ $this->reconciler, 'settle' ]` to `woocommerce_order_after_calculate_totals`, so the restore and its note land before `WC_AJAX::remove_order_coupon()` renders `notes_html` (it calls `calculate_totals( false )` after the line is deleted; without this the note shows only after a reload, and the existing "Restore" step's note check fails). Then it reads `yayloyalty_waive` instead of `yayloyalty_restore`. The nonce and capability checks and their errors are unchanged (criterion 13). On success it calls `$this->reconciler->waive( $order_id, $code )`.
  - Delete the one-shot listener, `restore()`, `note_failure()` and `name_of()`'s note use; `name_of()` stays for the dialog.
  - The class docblock describes the split: the server restores by default (`RemovedLineReconciler`), and the dialog only asks whether to waive.
- `order-coupon-removal.js`: "Restore" closes the dialog and calls `resend( link )` with `pending = null`; "Don't restore" sets `pending = code`, and the filter adds `yayloyalty_waive: 1` and `yayloyalty_nonce`. Update the file docblock and the `pending` comment.
- `YayLoyalty::__construct()`: `new OrderCouponRemoval( new RedemptionRepository(), new RewardRepository(), $reconciler )`.

- [ ] **Step 3: Verify**

1. `composer test:integration -- --filter 'RemovedLineReconcilerTest|ReversalTest|LoyaltyCodeOnOrderTest'` prints `OK (` with 0 failures.
2. With the user's go-ahead: `pnpm --filter @yayloyalty/e2e test admin-remove-loyalty-coupon` passes both tests.
3. Criterion 13 by hand on brandy.test: on an order with a loyalty line, run in the console `jQuery.post( ajaxurl, { action: 'woocommerce_remove_order_coupon', security: woocommerce_admin_meta_boxes.order_item_nonce, order_id: <id>, coupon: '<code>', yayloyalty_waive: 1, yayloyalty_nonce: 'bad' } )`. Expected: `success: false` with "This page has expired. Reload it and try again.", and after a reload the line is still there and the customer's balance is unchanged.

- [ ] **Step 4: Lint and commit**

Run: `composer lint -- includes/Engine/Admin/OrderCouponRemoval.php includes/YayLoyalty.php`
Expected: no new findings.

Stage: `includes/Engine/Admin/OrderCouponRemoval.php`, `assets/admin/order-coupon-removal.js`, `includes/YayLoyalty.php`, `apps/e2e/specs/admin-remove-loyalty-coupon.spec.ts`
Message: `feat(admin): make the removal dialog's Don't restore a final waive`
