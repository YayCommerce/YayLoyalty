# Phase 3: Amount-only Refunds and Docs

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phases 1 and 2 landed. Task 1 is independent of them in code; Task 2 documents all three.
**Delivers:** an amount-only refund claws back earned points in proportion. The decision record, architecture doc, readme and backlog describe the new rules.
**Exit criterion:** the spec's criterion 21.
- `composer test:integration` prints `OK (N tests` including `PaymentFailedTest`, `RemovedLineReconcilerTest` and `ReversalFlowTest`.
- `pnpm --filter @yayloyalty/e2e test` passes.
- On brandy.test:
  1. A redeeming order set to Failed returns its points.
  2. Paying it from My Account takes them again.
  3. Refunding 50 % of a completed earning order by amount only shows a clawback of half its earned points in the customer drawer.

### Task 1: Amount share in the earn clawback

**Files:**
- Modify: `includes/Engine/Earning/Reversal.php`
- Test: `tests/integration/Earning/ReversalFlowTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: no new names. `Reversal::refund()` keeps its signature, and `bases()` gains the refunds and the order total.

Behaviour to pin:
- A refund "carries product lines" when any of its items has a `_refunded_item_id` that matches one of the order's product lines.
- Only refunds without product lines add `abs( (float) $refund->get_amount() ) × $original ÷ (float) $order->get_total()` to `$refunded`. Nothing is added when the order total is ≤ 0. The sum is capped at `$original`.
- The docblock of `refund()` loses "claws back nothing" and states the amount share.
- Tests, each built with the fixture's `create_campaign()`, `create_order()`, `transition()` and `refund()`:
  - `test_an_amount_only_refund_claws_back_its_share` (16):
    - create the campaign with `points_per_unit` 1, and `create_order( $user_id, [ 90 ] )`;
    - add a `WC_Order_Item_Shipping` with total 10.00, call `calculate_totals( false )`, save, and assert the total is 100.00;
    - `transition()` the order to `completed`, so it earns 90;
    - `refund( $order, 50 )` gives the ledger `[ 90, -45 ]`.
  - `test_a_second_amount_refund_that_completes_the_order_claws_back_the_rest` (17): the ledger becomes `[ 90, -45, -45 ]`.
  - `test_a_line_refund_then_an_amount_refund_add_up` (18): use the existing `completed_order()` (89 points, 10.00 line refunded, −29), then an amount-only refund of 10.00. The amount share is 10 × 29.99 ÷ 29.99 = 10.00, so the refunded base is 20.00 and the owed total is floor( 89 × 20 ÷ 29.99 ) = 59. The ledger is `[ 89, -29, -30 ]`.
  - `test_a_line_refund_with_extra_amount_claws_back_the_line_share_only`: `refund_lines()` on line 1 with the amount raised by 5.00 writes the same −29 as today.
  - `test_an_amount_refund_on_a_zero_total_order_claws_nothing` (19): no `refund_earn` row is written and no exception is thrown. On a 0.00 order the only amount-only refund WooCommerce allows is 0.00, which reads as fully refunded, so today it claws back everything (a probe on 2026-11-12: line 40.00 with a −40.00 fee earns 40, and a 0.00 refund writes −40). Decided by the user on 2026-11-12: follow the spec literally, so `refund()` claws nothing on an order whose total is ≤ 0. A cancel or trash of that order still claws back through `full_order()`.

- [ ] **Step 1: Write the failing tests**

Add to `ReversalFlowTest`, with `use YayLoyalty\Enums\CampaignTrigger;` already imported:

```php
/** 1 point per unit on a 90.00 line earns 90; the order is 100.00 with 10.00 shipping. */
private function order_with_shipping(): array {
    $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 1 ] );
    $user_id = $this->create_customer();
    $order   = $this->create_order( $user_id, [ 90 ] );

    $shipping = new \WC_Order_Item_Shipping();
    $shipping->set_method_title( 'Flat rate' );
    $shipping->set_total( 10 );
    $order->add_item( $shipping );
    $order->calculate_totals( false );
    $order->save();
    $this->assertSame( 100.0, (float) $order->get_total() );

    return [ $user_id, $this->transition( wc_get_order( $order->get_id() ), 'completed' ) ];
}

/** 90 × ( 50 × 90 ÷ 100 ) ÷ 90 = 45. */
public function test_an_amount_only_refund_claws_back_its_share(): void {
    list( $user_id, $order ) = $this->order_with_shipping();

    $this->refund( $order, 50 );

    $this->assertSame( [ 90, -45 ], $this->ledger_points( $user_id ) );
}

public function test_a_second_amount_refund_that_completes_the_order_claws_back_the_rest(): void {
    list( $user_id, $order ) = $this->order_with_shipping();

    $this->refund( $order, 50 );
    $this->refund( wc_get_order( $order->get_id() ), 50 );

    $this->assertSame( [ 90, -45, -45 ], $this->ledger_points( $user_id ) );
}

/** The line share 10.00 plus the amount share 10 × 29.99 ÷ 29.99 = 10.00: floor( 89 × 20 ÷ 29.99 ) = 59 owed. */
public function test_a_line_refund_then_an_amount_refund_add_up(): void {
    list( $user_id, $order ) = $this->completed_order();

    $this->refund( $order, 10 );

    $this->assertSame( [ 89, -29, -30 ], $this->ledger_points( $user_id ) );
}

public function test_a_line_refund_with_extra_amount_claws_back_the_line_share_only(): void {
    $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 3 ] );
    $user_id = $this->create_customer();
    $order   = $this->transition( $this->create_order( $user_id, [ 19.99, 10 ] ), 'completed' );
    $line    = array_values( $order->get_items() )[1];

    $this->refund(
        $order,
        15,
        [
            $line->get_id() => [
                'qty'          => 1,
                'refund_total' => 10,
            ],
        ]
    );

    $this->assertSame( [ 89, -29 ], $this->ledger_points( $user_id ) );
}

/** A 40.00 line and a −40.00 fee: the order earns 40 and totals 0.00. */
public function test_an_amount_refund_on_a_zero_total_order_claws_nothing(): void {
    $this->create_campaign();
    $user_id = $this->create_customer();
    $order   = $this->create_order( $user_id, [ 40 ] );

    $fee = new \WC_Order_Item_Fee();
    $fee->set_name( 'Gift' );
    $fee->set_total( -40 );
    $order->add_item( $fee );
    $order->calculate_totals( false );
    $order->save();
    $order = $this->transition( wc_get_order( $order->get_id() ), 'completed' );

    $this->refund( $order, 0 );

    $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter ReversalFlowTest`
Expected: 4 failures. The two amount-only tests and `test_a_line_refund_then_an_amount_refund_add_up` find no clawback for the amount-only refund; the zero-total test finds −40. The extra-amount test already passes; it pins today's behaviour.

- [ ] **Step 3: Implement in `Earning\Reversal`**

- `refund()` returns `[]` when `(float) $order->get_total() <= 0`: a 0.00 order's refunds claw nothing back (criterion 19). Otherwise it passes `self::amount_only_total( $refunds, $lines )`, computed once per call, and the order total to `bases()`.
- `amount_only_total( array $refunds, array $lines ): float` (private static): the sum of `abs( (float) $refund->get_amount() )` over refunds none of whose items has a `_refunded_item_id` in `$lines`.
- `bases()` gains `float $amount_only` and `float $order_total` (> 0). After the line loop: `$refunded += $amount_only * $original / $order_total`, then `$refunded = min( $refunded, $original )`.
- `refund()`'s docblock: replace "A refund with no line items (amount only) removes no base and claws back nothing, unless …" with: a refund with no product lines (an amount, as a gateway dashboard refund or a shipping refund) removes its share of the base, amount × base ÷ order total; a fully refunded order owes every point back; an order whose total is 0.00 or less claws nothing back on a refund. Update `bases()`'s docblock.

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter 'ReversalFlowTest|ReversalTest|OrderReversalListenerTest|PaymentFailedTest'`
Expected: `OK (` with 0 failures.

- [ ] **Step 5: Lint and commit**

Run: `composer lint -- includes/Engine/Earning/Reversal.php tests/integration/Earning/ReversalFlowTest.php`
Expected: no new findings.

Stage: `includes/Engine/Earning/Reversal.php`, `tests/integration/Earning/ReversalFlowTest.php`
Message: `fix(earning): claw back earned points on amount-only refunds`

### Task 2: Decision record, docs, readme and backlog

**Files:**
- Create: `docs/decisions/2026-11-12-redemption-follows-payment-and-order-lines.md`
- Modify: `docs/decisions/2026-11-02-admin-removes-loyalty-coupon.md` (Status line only: "superseded by …")
- Modify: `docs/architecture.md`
- Modify: `readme.txt`
- Delete: `docs/backlog/2026-10-07-recalculate-drops-the-points-discount.md`
- Modify: this plan's `README.md` (Status `done 2026-MM-DD`, phase table)

**Interfaces:**
- Consumes: everything above.
- Produces: none.

Behaviour to pin:
- The record follows `docs/decisions/2026-10-04-decision-record-practice.md`. It restates the 2026-11-02 record's rules and adds three:
  - the `unpaid` status with the failed and pay cycle;
  - `ledger` chain keys for that cycle only;
  - removal restores by default, with "Don't restore" final.
- `docs/architecture.md`, Redemption section:
  - Replace the `coupon_removed()` bullet with the reconciler, the waive flag and the `unpaid` cycle with its two triggers (pay-for-order strict, a late paid status lenient).
  - Point the "decision record" link at the new record.
  - In the `Reversal` bullet, say the failed and pay cycle repeats without limit.
- `readme.txt`:
  - Add the spec's three "Fix:" lines under `= 1.0.0 =`.
  - Replace "* Removing a loyalty discount from an order now asks whether to return the customer's points." with the second Fix line.
  - Add to the refund FAQ answer: "This includes refunds made from your payment provider's dashboard."

- [ ] **Step 1: Write the decision record**

Create `docs/decisions/2026-11-12-redemption-follows-payment-and-order-lines.md` in the format of `docs/decisions/2026-10-04-decision-record-practice.md`, with `Status: accepted`. It links the spec, restates the 2026-11-02 record's rules that still hold (Lite owns the writes, the status set, the refund rules, the loyalty-code guard), and adds:
- `unpaid`: a failed payment restores into it; pay-for-order debits back strictly, a late paid status leniently;
- `ledger` chain keys for that cycle only, so it repeats without limit while a re-fired event collides;
- a line leaving the order by any route restores at the end of the request (`RemovedLineReconciler`), and the dialog's "Don't restore" is final;
- amount-only refunds claw back earned points by amount × base ÷ order total.

Then set the 2026-11-02 record's Status line to `superseded by [2026-11-12](2026-11-12-redemption-follows-payment-and-order-lines.md)`.

- [ ] **Step 2: Update `docs/architecture.md`**

- Line ~99, the `Reversal` bullet: add that a failed payment makes rows `unpaid` and paying again makes them `applied`, a cycle that repeats without limit because each move is keyed on the ledger row it undoes.
- Line ~100: replace the `coupon_removed()` bullet. `Engine\Redemption\RemovedLineReconciler` queues loyalty lines from `woocommerce_before_delete_order_item`, drops a deleted order, and settles at `shutdown` (and early in the order screen's remove request) through `coupon_removed()`, skipping a line still on the order. `OrderCouponRemoval` only asks whether to waive, through `yayloyalty_waive`. `PayForOrderRedebit` and `OrderReversalListener::on_paid_after_failure()` are the two pay-again triggers, strict and lenient.
- Line ~71: the earn clawback bullet says amount-only refunds now claw back their share.
- Line ~109: point "The decision record" at the new record and describe it.

- [ ] **Step 3: Update `readme.txt`**

- Under `= 1.0.0 =`: add the spec's three "Fix:" lines, and replace "* Removing a loyalty discount from an order now asks whether to return the customer's points." with the second one, so it appears once.
- The refund FAQ answer (line ~67) ends with: "This includes refunds made from your payment provider's dashboard."

- [ ] **Step 4: Delete the backlog item**

`git rm docs/backlog/2026-10-07-recalculate-drops-the-points-discount.md`, after confirming with `./scripts/run.sh backlog` that nothing else references it (`grep -rn recalculate-drops docs`).

- [ ] **Step 5: Run the exit criterion**

1. `composer test:integration` prints `OK (N tests` or only the two failures that fail on the base (`ExpiryRemindersTest::test_a_restamp_that_moves_a_lot_clears_its_reminder`, `PointsEarnedEmailTest::test_the_value_line_shows_while_the_discount_reward_is_live`), with `PaymentFailedTest`, `RemovedLineReconcilerTest` and `ReversalFlowTest` in the run.
2. `cd apps/e2e && PATH=~/.nvm/versions/node/v22.23.5/bin:$PATH ./node_modules/.bin/playwright test` passes, apart from failures that fail the same way on the base (the license screen on `page=yayloyalty`).
3. On brandy.test, with the user's go-ahead, since it writes to the real site: the three walkthroughs of the exit criterion.

- [ ] **Step 6: Close the plan and commit**

- README: Status `done YYYY-MM-DD`, phase 3 row `done YYYY-MM-DD`.

Stage: the new decision record, `docs/decisions/2026-11-02-admin-removes-loyalty-coupon.md`, `docs/architecture.md`, `readme.txt`, the deleted backlog file, `docs/plans/2026-11-12-redemption-order-lifecycle-fixes/README.md`
Message: `docs(redemption): record the payment and order-line rules`

### Task 3: A pay attempt that does not go through returns the points again

Added 2026-11-12 after walkthrough 2 on brandy.test: pay-for-order with the terms unticked was refused by WooCommerce, but the points were already debited, and the order stayed `failed`. `WC_Form_Handler::pay_action()` fires `woocommerce_before_pay_action` before its terms check and the gateway, and the block pay-for-order debits on `woocommerce_store_api_checkout_order_processed` before `process_payment()` can throw. The user chose to fix it in this phase, with two new tests.

**Files:**
- Modify: `includes/Engine/Redemption/PayForOrderRedebit.php`
- Modify: `includes/Engine/Earning/Listeners/OrderListener.php`
- Modify: `includes/Engine/Earning/Listeners/OrderReversalListener.php`
- Test: `tests/integration/Engine/Redemption/PaymentFailedTest.php`
- Docs: the new decision record, `docs/architecture.md`, this plan's `README.md` contract

**Interfaces:**
- Produces: `PayForOrderRedebit::settle(): void`, hooked to `shutdown` by `register()`.

Behaviour to pin:
- `redebit_or_message()` remembers each order it debited in this request (an outcome `AWARDED`).
- `settle()` empties that list first, then reloads each order; when `wc_get_order()` loads it and it still `needs_payment()`, it calls `Reversal::payment_failed()`, so the rows are `unpaid` again under a fresh `[ ledger, id ]` key. A `Throwable` is logged with `Log::error( self::class, … )` and the loop goes on.
- A paid status reached from `failed` or `pending` re-debits `unpaid` rows leniently: `OrderListener` calls `on_paid_after_failure()` when `$to` is paid and `$from` is not, and `on_paid_after_failure()` returns at once unless `has_unpaid()`. An offsite gateway that sets the order `pending` before redirecting is thus debited when its webhook marks it paid.

- [ ] **Step 1: Write the failing tests**

```php
public function test_a_pay_attempt_that_leaves_the_order_unpaid_returns_the_points(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->reversal()->payment_failed( $order );
    $redebit = new PayForOrderRedebit( $this->reversal() );

    $this->assertNull( $redebit->redebit_or_message( $order ) );
    $redebit->settle();

    $this->assertSame( [ 1000, -500, 500, -500, 500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_UNPAID, $this->row()['status'] );

    $redebit->settle();
    $this->assertSame( [ 1000, -500, 500, -500, 500 ], $this->ledger_points( 7 ) );

    // An offsite gateway's webhook: pending, then paid.
    $order->update_status( 'processing' );
    $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );
    $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
}

public function test_a_pay_attempt_that_went_through_keeps_the_debit(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->reversal()->payment_failed( $order );
    $redebit = new PayForOrderRedebit( $this->reversal() );

    $redebit->redebit_or_message( $order );
    $order->update_status( 'on-hold' );
    $redebit->settle();

    $this->assertSame( [ 1000, -500, 500, -500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter PaymentFailedTest`
Expected: errors, "Call to undefined method …PayForOrderRedebit::settle()".

- [ ] **Step 3: Implement** as pinned above; update both classes' docblocks.

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter 'PaymentFailedTest|ReversalFlowTest|OrderReversalListenerTest|RemovedLineReconcilerTest'`
Expected: `OK (` with 0 failures.

- [ ] **Step 5: Verify on brandy.test**

Fail a redeeming order, open its Pay page, leave the terms unticked and press Pay. Expected: WooCommerce's terms error, the order still `failed`, the balance unchanged from before the attempt and the row `unpaid`. Then tick the terms and pay: the balance drops once and the order is on-hold.

- [ ] **Step 6: Docs, lint and commit**

- Decision record: the pay-again rule says an attempt that leaves the order unpaid returns the points at the end of the request, and a paid status from `pending` re-debits too.
- `docs/architecture.md`: the pay-again bullet says the same.
- README contract: add `PayForOrderRedebit::settle(): void`.

Run: `composer lint -- includes/Engine/Redemption/PayForOrderRedebit.php includes/Engine/Earning/Listeners/OrderListener.php includes/Engine/Earning/Listeners/OrderReversalListener.php tests/integration/Engine/Redemption/PaymentFailedTest.php`

Stage: those four files, the decision record, `docs/architecture.md`, `docs/plans/2026-11-12-redemption-order-lifecycle-fixes/README.md`, this file
Message: `fix(redemption): return the points when a pay-for-order attempt does not go through`
