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
  - `test_an_amount_refund_on_a_zero_total_order_claws_nothing` (19): no `refund_earn` row is written and no exception is thrown.

Steps: expand after Phase 2 lands.

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

Steps: expand after Phase 2 lands.
