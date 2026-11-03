# Admin Removes a Loyalty Coupon Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** done 2026-11-02
**Goal:** Removing a loyalty coupon line on the order edit screen asks whether to return its points, and no loyalty code can be put on an order that did not debit it.
**Spec:** [docs/specs/2026-11-02-admin-remove-loyalty-coupon-design.md](../specs/2026-11-02-admin-remove-loyalty-coupon-design.md)
**Architecture:** A dialog intercepts WooCommerce's own Remove click and, for "Restore", tags WooCommerce's own `woocommerce_remove_order_coupon` request. A priority-1 handler on that request listens once for the coupon item's delete and restores through `Reversal::coupon_removed()`, which moves the row to a new status `removed` that no other event touches. A `woocommerce_coupon_is_valid` guard in `CouponProvider` refuses a loyalty code on an order without an `applied` row for it. Riding WooCommerce's request avoids duplicating `WC_AJAX::remove_order_coupon()` (see the spec's Alternatives).
**Touches:** `includes/Models/Redemption.php`, `includes/Repositories/RedemptionRepository.php`, `includes/Engine/Redemption/Reversal.php`, `includes/Engine/Redemption/CouponProvider.php` (constructor gains a parameter; only consumer `includes/YayLoyalty.php`), new `includes/Engine/Admin/OrderCouponRemoval.php`, new `assets/admin/order-coupon-removal.js`, `includes/YayLoyalty.php`, `apps/admin/src/lib/schema/redemption.schema.ts`, `apps/admin/src/pages/customers/drawer/customer-drawer.redemption-rows.ts`, `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`, `apps/e2e/support/storefront.ts`, `apps/e2e/specs/reward-redemptions.spec.ts`, new `apps/e2e/specs/admin-remove-loyalty-coupon.spec.ts`, docs (`docs/decisions/`, `docs/architecture.md`), `readme.txt`.
**Tests:** (accepted in the spec, 2026-11-02)
- `tests/integration/Engine/Redemption/ReversalTest.php`, integration suite: new cases for criteria 2, 3, 5, 6 and 7 (what `coupon_removed()` restores and that no other event moves a `removed` row).
- `tests/integration/Engine/Redemption/LoyaltyCodeOnOrderTest.php` (new), integration suite: criteria 10 and 11 (the guard refuses an undebited code, allows a debited one, and recalculation keeps lines).
- `apps/e2e/specs/admin-remove-loyalty-coupon.spec.ts` (new), Playwright: criterion 13 (dialog, Cancel, Restore, balance, note).

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static` types, or a trailing comma after the last parameter.
- WooCommerce floor stays `10.0`. Do not hook `woocommerce_order_removed_coupon`.
- Only `LedgerService` writes ledger rows and balances, and nothing new constructs it. `Reversal` comes from `RedemptionService::build()`'s `reversal()`.
- Lite owns this feature. Nothing under `includes/Engine/` names `YayLoyalty\Pro\*`.
- Admin script is plain JS with no build step.
- All strings use the `yayloyalty` text domain. "points" comes from `SettingsHelper::points_label_plural()` / `points_text()`, never hard-coded.
- Logging only through `Log::error()` with the reporting class as context.
- Capability `Capabilities::MANAGE_POINTS` (`yayloyalty_manage_points`). Nonce action `yayloyalty-restore-coupon`, field `yayloyalty_nonce`.
- Coupon codes are compared with `wc_format_coupon_code()` on both sides.
- `composer lint` and the admin lint fail on `dev/edd` today ([known issues](../known-issues.md)): lint only the changed files, and expect no new findings.
- `composer test:integration` can exit 0 without running ([known issues](../known-issues.md)): a pass shows `OK (N tests` in the output.

## Decisions this plan adds to the spec

- **Lookup by code** lives in one place: `RedemptionRepository::find_for_order_code( int $order_id, string $code ): ?Redemption`. `Reversal`, the guard and the admin listener all use it.
- **Name in the dialog and notes:** the reward's name from `RewardRepository::find_reward( $row->get_reward_id() )`, or `__( 'Reward', 'yayloyalty' )` when the reward is gone (as `RewardClaimedEmail` does). `OrderCouponRemoval` therefore also takes a `RewardRepository`, read-only.
- **Copy uses the store's label:** the criteria's "500 points" renders as `points_text( 500 )`, so the e2e site (label "Points") shows "500 Points". "Don't restore points" is `sprintf( "Don't restore %s", points_label_plural() )`.
- **Outcomes → notes:** `AWARDED` adds the restore note. `REASON_KEY_SPENT`, or a `Throwable`, adds the failure note and calls `Log::error()`. `REASON_NOTHING_TO_MOVE` or `null` adds no note.
- **The code leaves `coupons` in the JS only after Restore.** After "Don't restore" the row stays `applied`, so a line re-added later gets the dialog again.

## Review focus

- A replayed or double-clicked Restore: the second request must find the row not `applied` and write nothing (Task 1 test `test_removing_the_same_coupon_twice_writes_once`; Task 3 step 6).
- The guard refusing a legitimate path: classic and block checkout create coupon lines without `WC_Discounts( $order )`, and `recalculate_coupons()` skips validation. Checkout must still redeem (Task 2 step 6), and recalculation must keep amounts (Task 2 test).
- `wp_send_json_error()` at priority 1 must stop WooCommerce's handler, so a refused Restore leaves the line on the order (Task 3 step 7).
- The one-shot listener must ignore other items deleted in the same save, and must not stay attached for a later delete (Task 3 step 5, two-line order).
- Legacy order storage: the screen id and order id differ from HPOS (Task 3 step 8).
- A `removed` row in the customer drawer: the zod enum must accept it, or the whole Redemptions tab fails to parse (Task 1 step 7; Task 4 asserts the label).

---

### Task 1: The `removed` status and `Reversal::coupon_removed()`

**Files:**
- Modify: `includes/Models/Redemption.php`
- Modify: `includes/Repositories/RedemptionRepository.php`
- Modify: `includes/Engine/Redemption/Reversal.php`
- Modify: `apps/admin/src/lib/schema/redemption.schema.ts`, `apps/admin/src/pages/customers/drawer/customer-drawer.redemption-rows.ts`, `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`
- Test: `tests/integration/Engine/Redemption/ReversalTest.php`

**Interfaces:**
- Produces:
  - `Redemption::STATUS_REMOVED = 'removed'`, in `STORED_STATUSES` (so the REST enum in `RedemptionsRestController` gains it with no edit there).
  - `RedemptionRepository::find_for_order_code( int $order_id, string $code ): ?Redemption`: the first row of `for_order( $order_id )` whose `coupon_code` matches under `wc_format_coupon_code()`, any status; null when none.
  - `Reversal::coupon_removed( \WC_Order $order, string $code ): ?Outcome`: null when no row matches. Otherwise it runs one `Transaction::run()` that locks the row's user and then the row. When the row is not `applied` it answers `Outcome::skipped( REASON_NOTHING_TO_MOVE )`. When `points − restored_points` is 0 it calls `record_restore( id, points, STATUS_REMOVED )` with no ledger row and answers `skipped( REASON_NOTHING_TO_MOVE )`. Otherwise it calls `restore( $row, $row->get_points(), STATUS_REMOVED, [ [ ReferenceType::REDEMPTION, id ] ], 'Coupon removed from order' )`.
  - TS: `REDEMPTION_STATUSES` gains `'removed'`; `REDEMPTION_STATUS_REMOVED = () => __('Removed by admin', 'yayloyalty')`.

- [x] **Step 1: Write the failing tests in `ReversalTest.php`**

Add `use YayLoyalty\Enums\ReferenceType;`.

```php
public function test_removing_a_coupon_restores_what_is_left_and_marks_the_row_removed(): void {
    $reward = $this->seeded_reward();
    $order  = $this->redeemed_order( $reward );

    $outcome = $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );

    $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
    $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    $this->assertSame( 1000, (int) $this->customer_row( 7 )['balance'] );
    $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
    $this->assertSame( 500, (int) $this->row()['restored_points'] );

    $ledger = $this->last_ledger_row();
    $this->assertSame( LedgerType::REFUND_REDEEM, $ledger['type'] );
    $this->assertSame( ReferenceType::REDEMPTION, $ledger['reference_type'] );
    $this->assertSame( (int) $this->row()['id'], (int) $ledger['reference_id'] );
    $this->assertSame( sprintf( 'refund_redeem:7:redemption:%d:flexible_discount', (int) $this->row()['id'] ), $ledger['idempotency_key'] );
}

public function test_a_line_removed_without_restore_leaves_the_row_for_the_cancel(): void {
    $reward = $this->seeded_reward();
    $order  = $this->redeemed_order( $reward );

    $order->remove_coupon( $reward->get_coupon_code() );
    $order->save();

    $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
    $this->assertSame( 0, (int) $this->row()['restored_points'] );

    $this->reversal()->cancelled( $order );

    $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_CANCELLED, $this->row()['status'] );
}

public function test_removing_a_coupon_after_a_part_refund_restores_only_the_rest(): void {
    $reward = $this->seeded_reward();
    $order  = $this->redeemed_order( $reward );
    $this->reversal()->refunded( $order, $this->refund_of( $order, 6.0 ) );
    $this->assertSame( 200, (int) $this->row()['restored_points'] );

    $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );

    $this->assertSame( [ 1000, -500, 200, 300 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
    $this->assertSame( 500, (int) $this->row()['restored_points'] );
}

public function test_no_order_event_moves_a_removed_row(): void {
    $reward = $this->seeded_reward();
    $order  = $this->redeemed_order( $reward );
    $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );

    $outcomes = array_merge(
        $this->reversal()->cancelled( $order ),
        $this->reversal()->refunded_status( $order ),
        $this->reversal()->uncancelled( $order )
    );
    $refund_id = $this->refund_of( $order, 7.5 );
    $outcomes  = array_merge( $outcomes, $this->reversal()->refunded( $order, $refund_id ) );
    wc_get_order( $refund_id )->delete( true );
    $outcomes = array_merge( $outcomes, $this->reversal()->refund_deleted( $refund_id, $order->get_id() ) );

    $this->assertCount( 5, $outcomes );
    foreach ( $outcomes as $outcome ) {
        $this->assertSame( Reversal::REASON_NOTHING_TO_MOVE, $outcome->get_reason() );
    }
    $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
}

public function test_removing_the_same_coupon_twice_writes_once(): void {
    $reward = $this->seeded_reward();
    $order  = $this->redeemed_order( $reward );
    $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );

    $outcome = $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );

    $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
    $this->assertSame( Reversal::REASON_NOTHING_TO_MOVE, $outcome->get_reason() );
    $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    $this->assertNull( $this->reversal()->coupon_removed( $order, 'summer10' ) );
}
```

- [x] **Step 2: Run them to confirm they fail**

Run: `composer test:integration -- --filter 'removing|removed|without_restore' tests/integration/Engine/Redemption/ReversalTest.php`
Expected: 4 errors, `Call to undefined method …Reversal::coupon_removed()` / undefined constant `STATUS_REMOVED`. `test_a_line_removed_without_restore_leaves_the_row_for_the_cancel` passes already, since it pins behaviour that exists today.

- [x] **Step 3: Add `STATUS_REMOVED` to `includes/Models/Redemption.php`**

Add it to `STORED_STATUSES`. The class docblock and the `STATUS_APPLIED` comment add `→ removed (an admin removed the line and restored what was left; final)`.

- [x] **Step 4: Add `RedemptionRepository::find_for_order_code()`** (signature in Interfaces). The class docblock's reader list gains the guard and the order screen.

- [x] **Step 5: Add `Reversal::coupon_removed()`** (contract in Interfaces)

Give the private `restore()` a trailing `?string $note = null` parameter that it passes on to `draft()`. The class docblock gains one sentence: an admin removing a line through the order screen restores what is left under `[ redemption, id ]` and makes the row `removed`, which no other event lists. The status lists of the existing events stay as they are.

- [x] **Step 6: Run the Reversal tests**

Run: `composer test:integration -- tests/integration/Engine/Redemption/ReversalTest.php`
Expected: `OK`, every existing test included.

- [x] **Step 7: Add `removed` to the SPA**

`REDEMPTION_STATUSES` gains `'removed'`. `customer-drawer.copy.ts` gains `REDEMPTION_STATUS_REMOVED` (Interfaces). `statusLabel()` gains `case 'removed'`.
Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` and `pnpm --filter @yayloyalty/admin-page test`
Expected: tsc exits 0, so the switch is exhaustive; Vitest passes.

- [x] **Step 8: Lint**

Run: `composer lint -- includes/Models/Redemption.php includes/Repositories/RedemptionRepository.php includes/Engine/Redemption/Reversal.php tests/integration/Engine/Redemption/ReversalTest.php`
Expected: no errors.

- [x] **Step 9: Commit**

Stage: the files above, the three SPA files and `ReversalTest.php`
Message: `feat(redemption): restore a removed loyalty coupon's points into a final removed status`

### Task 2: Refuse a loyalty code an order did not debit

**Files:**
- Modify: `includes/Engine/Redemption/CouponProvider.php`
- Modify: `includes/YayLoyalty.php` (`register_redemption()`)
- Test: `tests/integration/Engine/Redemption/LoyaltyCodeOnOrderTest.php` (new)

**Interfaces:**
- Consumes: `RedemptionRepository::find_for_order_code()`, `Reversal::coupon_removed()` (Task 1).
- Produces:
  - `CouponProvider::__construct( RedemptionService $service, SessionIntent $intent, RedemptionRepository $redemptions )`.
  - `CouponProvider::only_redeemed_on_order( $valid, \WC_Coupon $coupon, \WC_Discounts $discounts )` on `woocommerce_coupon_is_valid` (priority 10, 3 args). It throws `\Exception( __( 'This loyalty discount can only be applied by the customer at checkout.', 'yayloyalty' ) )` when `$discounts->get_object()` is a `\WC_Order`, the code passes `EarnContext::is_loyalty_code()`, and `find_for_order_code()` returns no row whose status is `applied`. Otherwise it returns `$valid` unchanged.

- [x] **Step 1: Write the failing tests** in a new `LoyaltyCodeOnOrderTest extends IntegrationTestCase` (namespace `YayLoyalty\Tests\Integration\Engine\Redemption`)

```php
private const REFUSED = 'This loyalty discount can only be applied by the customer at checkout.';

private function reversal(): Reversal {
    return new Reversal( $this->ledger_service(), new RedemptionRepository(), new CustomerRepository(), new RewardRepository() );
}

/** User 7 spends 500 on a $5 off reward line of an order of 100.00. */
private function order_with_committed_reward(): array {
    $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
    $reward = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
    $order  = $this->order_with_reward_lines( 7, 100.0, [ $reward->get_coupon_code() => 5.0 ] );
    $this->assertSame( Outcome::REDEEMED, $this->commit_writer()->commit_rewards( $order, [ $reward->get_coupon_code() => $reward ] )->get_status() );

    return [ $order, $reward ];
}

public function test_a_loyalty_code_the_order_did_not_debit_is_refused(): void {
    $order = $this->order_with_reward_lines( 7, 20.0, [] );

    $result = $order->apply_coupon( 'yayloyalty-abc12345' );

    $this->assertInstanceOf( \WP_Error::class, $result );
    $this->assertSame( self::REFUSED, $result->get_error_message() );
    $this->assertSame( [], wc_get_order( $order->get_id() )->get_coupon_codes() );
}

public function test_a_removed_rows_code_is_refused(): void {
    list( $order, $reward ) = $this->order_with_committed_reward();
    $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );
    $order->remove_coupon( $reward->get_coupon_code() );
    $order->save();

    $result = $order->apply_coupon( $reward->get_coupon_code() );

    $this->assertInstanceOf( \WP_Error::class, $result );
    $this->assertSame( self::REFUSED, $result->get_error_message() );
}

public function test_the_code_of_an_applied_row_is_allowed(): void {
    list( $order, $reward ) = $this->order_with_committed_reward();
    $order->remove_coupon( $reward->get_coupon_code() );
    $order->save();

    $this->assertTrue( $order->apply_coupon( $reward->get_coupon_code() ) );
    $this->assertSame( [ $reward->get_coupon_code() ], $order->get_coupon_codes() );
}

public function test_recalculating_keeps_loyalty_lines_and_amounts(): void {
    list( $order ) = $this->order_with_committed_reward();
    $before        = [ $order->get_coupon_codes(), $order->get_discount_total() ];

    $order->recalculate_coupons();

    $this->assertSame( $before, [ $order->get_coupon_codes(), $order->get_discount_total() ] );
}
```

- [x] **Step 2: Run them to confirm they fail**

Run: `composer test:integration -- tests/integration/Engine/Redemption/LoyaltyCodeOnOrderTest.php`
Expected: `test_a_loyalty_code_the_order_did_not_debit_is_refused` and `test_a_removed_rows_code_is_refused` fail (`apply_coupon` returns `true`). The other two pass already, since they pin behaviour the guard must keep.

- [x] **Step 3: Add the parameter and `only_redeemed_on_order()` to `CouponProvider`** (Interfaces). `register()` attaches it, and the docblock's "six hooks" becomes "seven hooks", with one line on why: REST v3 and the admin "Apply coupon" validate through `WC_Discounts( $order )`, and checkout and `recalculate_coupons()` do not.

- [x] **Step 4: Pass `new RedemptionRepository()`** as the third argument in `YayLoyalty::register_redemption()`, with its `use` line.

- [x] **Step 5: Run the redemption integration tests**

Run: `composer test:integration -- tests/integration/Engine/Redemption/`
Expected: `OK`, `ClassicCheckoutAdapterTest` and `CommitWriterTest` included.

- [x] **Step 6: Verify checkout still redeems**

Run: `pnpm --filter @yayloyalty/e2e test specs/redemptions.spec.ts specs/reward-redemptions.spec.ts` (wp-env started, `pro` SPA built)
Expected: all pass, so block and classic checkout still commit loyalty lines with the guard in place.

- [x] **Step 7: Verify the admin "Apply coupon" message**

On the wp-env site (`http://localhost:8889/wp-admin`, admin / password), open any `on-hold` order with no loyalty line and click **Apply coupon**. Enter `yayloyalty-abc12345`.
Expected: an alert with "This loyalty discount can only be applied by the customer at checkout." The order's totals don't change.

- [x] **Step 8: Lint and commit**

Run: `composer lint -- includes/Engine/Redemption/CouponProvider.php includes/YayLoyalty.php tests/integration/Engine/Redemption/LoyaltyCodeOnOrderTest.php`, expecting no errors.
Stage: those three files.
Message: `fix(redemption): refuse a loyalty code on an order that did not debit it`

### Task 3: The order-screen dialog and the restore on remove

**Files:**
- Create: `includes/Engine/Admin/OrderCouponRemoval.php`
- Create: `assets/admin/order-coupon-removal.js`
- Modify: `includes/YayLoyalty.php` (the `is_admin()` branch of `__construct()`)

**Interfaces:**
- Consumes: `Reversal::coupon_removed()`, `RedemptionRepository::find_for_order_code()`, `Redemption::STATUS_APPLIED` (Task 1).
- Produces (all on `final class OrderCouponRemoval` in `YayLoyalty\Engine\Admin`):
  - `__construct( Reversal $reversal, RedemptionRepository $redemptions, RewardRepository $rewards )`, `register(): void`.
  - `enqueue(): void` on `admin_enqueue_scripts`. It runs on the order edit screen only, using the screen check of `OrderPointsMetabox::enqueue()` (on branch `paul/improve/missing-points`): `get_current_screen()->id === wc_get_page_screen_id( 'shop-order' )` and `OrderUtil::is_order_edit_screen()`, plus `current_user_can( Capabilities::MANAGE_POINTS )`. The order id is `absint( $_GET['id'] ?? $_GET['post'] ?? 0 )` (HPOS, legacy). It enqueues only when at least one row is restorable: `applied`, a `coupon_code`, and `points > restored_points`. Handle `yayloyalty-order-coupon-removal`, deps `[ 'jquery', 'wc-admin-order-meta-boxes' ]`, in the footer, with a `before` inline script `window.yayloyaltyCouponRemoval = { nonce, coupons, i18n }`:
    - `coupons`: `{ [ wc_format_coupon_code( code ) ]: { name, points: points_text( points ), remaining: points_text( points − restored ) } }`.
    - `i18n`: `title` "Remove %s?", `spent` "The customer spent %s on this discount.", `unreturned` "%s of them have not been returned yet.", `restore` "Restore %s", `keep` "Don't restore %s" (already filled with `points_label_plural()`), `cancel` "Cancel".
  - `before_remove(): void` on `wp_ajax_woocommerce_remove_order_coupon`, priority 1. Without `yayloyalty_restore` it returns at once. Otherwise:
    - When `check_ajax_referer( 'yayloyalty-restore-coupon', 'yayloyalty_nonce', false )` fails, it answers `wp_send_json_error( [ 'error' => __( 'This page has expired. Reload it and try again.', 'yayloyalty' ) ] )`.
    - Without `MANAGE_POINTS` it answers `wp_send_json_error( [ 'error' => __( 'You are not allowed to restore points.', 'yayloyalty' ) ] )`.
    - Otherwise it attaches the one-shot listener for `absint( $_POST['order_id'] )` and `wc_format_coupon_code( wp_unslash( $_POST['coupon'] ) )`.
  - The listener, on `woocommerce_before_delete_order_item` ( `int $item_id` ). It acts only when `WC_Order_Factory::get_order_item( $item_id )` is a `WC_Order_Item_Coupon` with that order id and that formatted code. It then detaches itself and calls `coupon_removed()`, then adds an order note per the Decisions section:
    - Restored: "%1$s removed — %2$s restored." (name, `points_text( outcome points )`).
    - Failed: "%s removed, but its points could not be restored. Adjust the customer's points by hand."
    - A `Throwable` is caught, logged with `Log::error( self::class, … )`, gets the failure note, and is not rethrown.
- JS (`assets/admin/order-coupon-removal.js`, an IIFE like `assets/admin/order-points.js`):
  - A `document` click listener in the capture phase on `#woocommerce-order-items a.remove-coupon`. When the link's `data-code` (lowercased) is in `coupons` and `resending` is false, it calls `preventDefault()` and `stopImmediatePropagation()` and opens the dialog.
  - One `<dialog aria-labelledby>`, built once with `textContent`, opened with `showModal()`, with the Restore button focused. Escape counts as Cancel.
  - "Restore" sets `pending = code` and re-dispatches. "Don't restore" re-dispatches only. "Cancel" closes.
  - Re-dispatch: `resending = true; jQuery( link ).trigger( 'click' ); resending = false`.
  - `jQuery( '#woocommerce-order-items' ).on( 'woocommerce_order_meta_box_remove_coupon_ajax_data', ( e, data ) => … )`. When `pending === data.coupon` (lowercased), it adds `yayloyalty_restore: 1` and `yayloyalty_nonce`, deletes the code from `coupons` and clears `pending`. It always returns `data`.

- [x] **Step 1: Implement `OrderCouponRemoval`** (Interfaces). Its docblock says it is Lite's order-screen half of the admin-removal restore and why it rides WooCommerce's request.

- [x] **Step 2: Implement `assets/admin/order-coupon-removal.js`** (Interfaces). Its header comment names the PHP class that feeds it.

- [x] **Step 3: Register it** in the `is_admin()` branch: `( new \YayLoyalty\Engine\Admin\OrderCouponRemoval( $redemption->reversal(), new RedemptionRepository(), new RewardRepository() ) )->register();`, with the `use` lines.

- [x] **Step 4: Static checks**

Run: `composer lint -- includes/Engine/Admin/OrderCouponRemoval.php includes/YayLoyalty.php` and `node --check assets/admin/order-coupon-removal.js`
Expected: no errors from either.

- [x] **Step 5: Verify criteria 1, 2, 3, 4 and 5 by hand** (wp-env, HPOS on, the default)

Setup: give a customer 2000 points (YayLoyalty › Customers › Adjust points). As that customer, check out with the "$5 off" reward (500 points) and the points discount at 500 points, paying by check, so the order is `on-hold` with two loyalty lines.
- Click Remove on "$5 off". Expected: the dialog "Remove $5 off?" with "The customer spent 500 Points on this discount.", and "Restore 500 Points" focused, "Don't restore Points", "Cancel".
- Click Cancel. Expected: no request in the Network tab, and the line stays.
- Click Remove, then "Restore 500 Points". Expected:
  - the line is gone and the totals recalculate;
  - the points discount line is still there (the listener ignored it);
  - the balance rises by 500;
  - the notes show "$5 off removed — 500 Points restored.";
  - the drawer's Redemptions tab shows "Removed by admin" for that row.
- Click Remove on the points discount, then "Don't restore Points". Expected: the line is gone, there's no new ledger row, and the row stays "Applied". Set the order to Cancelled. Expected: 500 come back and the row reads "Cancelled".
- Criterion 5: on a fresh order of the same shape, refund 40% of the order total (restores 200 of 500), then click Remove. Expected: the dialog adds "300 Points of them have not been returned yet." and the button reads "Restore 300 Points". Restoring writes one row of 300.

- [x] **Step 6: Verify a replayed Restore**

In DevTools, find the Restore request (`action=woocommerce_remove_order_coupon` with `yayloyalty_restore=1`), copy it as fetch, and run it again.
Expected: `success: true`, no new ledger row, and no new YayLoyalty note.

- [x] **Step 7: Verify criterion 8**

Run: `pnpm --filter @yayloyalty/e2e exec wp-env run tests-cli wp cap remove shop_manager yayloyalty_manage_points`, then log in as a shop manager and open an `on-hold` order with a loyalty line.
- Expected: no dialog on Remove, and WooCommerce removes the line. The row stays `applied`.
- On another such order, run this in the console:

  ```js
  jQuery.post(ajaxurl, { action: 'woocommerce_remove_order_coupon', security: woocommerce_admin_meta_boxes.order_item_nonce, order_id: woocommerce_admin_meta_boxes.post_id, coupon: '<code>', yayloyalty_restore: 1, yayloyalty_nonce: 'x' }).then(console.log)
  ```

  Expected: `{ success: false, data: { error: "This page has expired…" } }`. After a reload, the line is still on the order.
- Restore the cap with `… wp cap add shop_manager yayloyalty_manage_points`.

- [x] **Step 8: Verify criterion 12 on legacy storage**

Run: `pnpm --filter @yayloyalty/e2e exec wp-env run tests-cli wp wc hpos disable` (run `wp wc hpos sync` first if it refuses). Repeat the Restore part of step 5 on `post.php?post=<id>&action=edit`.
Expected: the same dialog, restore and note. Then run `wp wc hpos enable`.

- [x] **Step 9: Commit**

Stage: `includes/Engine/Admin/OrderCouponRemoval.php`, `assets/admin/order-coupon-removal.js`, `includes/YayLoyalty.php`
Message: `feat(admin): ask whether to restore points when a loyalty coupon is removed from an order`

### Task 4: End-to-end spec

**Files:**
- Modify: `apps/e2e/support/storefront.ts`: move `rewardBox()` and `addWidgetsToCart()` here from `reward-redemptions.spec.ts`, exported unchanged.
- Modify: `apps/e2e/specs/reward-redemptions.spec.ts`: import them.
- Test: `apps/e2e/specs/admin-remove-loyalty-coupon.spec.ts` (new)

**Interfaces:**
- Consumes: the dialog, its copy and the note from Task 3; the `removed` status from Task 1.

- [x] **Step 1: Write the spec**

```ts
test.describe('an admin removes a loyalty coupon', () => {
  let rewardId = 0;

  test.beforeEach(async ({ api }) => {
    rewardId = await api.createReward({ name: '$5 off', type: 'fixed_coupon', cost_points: 500, config: { amount: 5 } });
  });

  test.afterEach(async ({ api }) => {
    await api.deleteReward(rewardId);
  });

  test('Restore takes the line off and returns its points', async ({ api, customer, shopperPage, adminPage }) => {
    await api.adjustPoints(customer.id, 1000);
    await addWidgetsToCart(shopperPage);
    await shopperPage.goto('/cart/');
    await rewardBox(shopperPage, '$5 off').check();
    await expect(blockDiscounts(shopperPage)).toContainText('$5 off');
    await shopperPage.goto('/checkout/');
    await shopperPage.getByLabel('Check payments').check();
    await shopperPage.getByRole('button', { name: 'Place Order' }).click();
    const orderId = await placedOrderId(shopperPage);
    await api.expectPoints(customer.id, { balance: 500 });

    await openOrder(adminPage, orderId);
    const removeLink = adminPage.locator('#woocommerce-order-items a.remove-coupon');
    const dialog = adminPage.getByRole('dialog', { name: 'Remove $5 off?' });

    await test.step('Cancel keeps the line', async () => {
      await removeLink.click();
      await expect(dialog).toContainText('The customer spent 500 Points on this discount.');
      await expect(dialog.getByRole('button', { name: 'Restore 500 Points' })).toBeFocused();
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(removeLink).toHaveCount(1);
    });

    await test.step('Restore removes the line and returns 500', async () => {
      await removeLink.click();
      await dialog.getByRole('button', { name: 'Restore 500 Points' }).click();
      await expect(removeLink).toHaveCount(0);
      await expect(adminPage.locator('ul.order_notes')).toContainText('$5 off removed — 500 Points restored.');
      await api.expectPoints(customer.id, { balance: 1000 });
      expect(await api.redemptions(customer.id)).toMatchObject([{ status: 'removed' }]);
    });

    await test.step('the Customers page shows the balance and the status', async () => {
      await adminPage.goto(`/wp-admin/admin.php?page=yayloyalty#/customers/${customer.id}`);
      const drawer = adminPage.getByRole('dialog');
      await expect(drawer).toContainText('1,000');
      await drawer.getByRole('tab', { name: 'Redemptions' }).click();
      await expect(drawer).toContainText('Removed by admin');
    });
  });
});
```

- [x] **Step 2: Run it** (rebuild first: `./scripts/run.sh build pro`)

Run: `pnpm --filter @yayloyalty/e2e test specs/admin-remove-loyalty-coupon.spec.ts specs/reward-redemptions.spec.ts`
Expected: all pass. Then run `pnpm --filter @yayloyalty/e2e typecheck`, expecting exit 0.

- [x] **Step 3: Commit**

Stage: the three e2e files.
Message: `test(e2e): cover restoring points when an admin removes a loyalty coupon`

### Task 5: Decision record, architecture and release note

**Files:**
- Create: `docs/decisions/2026-11-02-admin-removes-loyalty-coupon.md`
- Modify: `docs/decisions/2026-10-30-lite-writes-every-redemption-as-an-applied-discount.md` (Status line only)
- Modify: `docs/architecture.md` (the `Reversal` bullet near line 97 and the record link near line 105)
- Modify: `readme.txt`

- [x] **Step 1: Write the decision record.** It follows [the decision-record practice](../decisions/2026-10-04-decision-record-practice.md) and the 2026-10-30 record's sections. It supersedes the 2026-10-30 record whole and restates every rule of it. Its statuses are four: `removed` is final and set only by an admin's explicit Restore. It adds the admin-removal rule: `points − restored_points` under `[ redemption, id ]`, and only through the order screen's Restore. "Don't restore" and every other removal path leave the row `applied`. It adds the guard rule, that a loyalty code goes on an order only while that order has an `applied` row for it. It also records the options the spec turned down.

- [x] **Step 2: Mark the old record** with a `Superseded by` line linking [the new record](../decisions/2026-11-02-admin-removes-loyalty-coupon.md), dated 2026-11-02, under its `## Status`, keeping the acceptance line.

- [x] **Step 3: Update `docs/architecture.md`.** The `Reversal` bullet gains the admin-removal event and `removed`. The record link points to the new record. `OrderCouponRemoval` gets one line among the admin classes.

- [x] **Step 4: Add the release note** under `= 1.0.0 =` in `readme.txt`: `* Removing a loyalty discount from an order now asks whether to return the customer's points.`

- [x] **Step 5: Final verification (spec criterion 13)**

Run: `composer test:integration` and `pnpm --filter @yayloyalty/e2e test`
Expected: integration `OK`, and every Playwright spec passes, `admin-remove-loyalty-coupon.spec.ts` included. On the wp-env site, an `on-hold` order with a reward line shows the dialog on Remove. After "Restore …" the line is gone, the customer's balance on the Customers page has gone up by the reward's points, and the order notes show "… removed — … restored."

- [x] **Step 6: Commit**

Stage: the four docs files.
Message: `docs(redemption): record the removed status and the admin-removal restore rule`
