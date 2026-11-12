# Lite owns every redemption write, and redeemed and earned points follow the order's payment and its lines

**Purpose:** why all redemption writes live in Lite, why every redemption row starts as an applied discount with one set of statuses, when points are debited, the rules by which a cancellation, a refund, a failed payment or a loyalty line leaving the order returns them, why a loyalty code goes on an order only while that order paid for it, and how an amount-only refund takes back earned points.

**Update when:** never edit it. Supersede it with a new record if Pro gets a redemption writer, a reward is claimed for a stored coupon, the status set changes, the debit moves, or a rule for returning or taking back points changes.

## Status

Accepted, 2026-11-12, by PhungTruong1997. It supersedes [Lite owns every redemption write, and an admin's Restore on the order screen is the only removal that returns points](2026-11-02-admin-removes-loyalty-coupon.md) whole, and restates every rule of it that still holds.

## Context

- Customers spend points two ways: the flexible discount, which turns points into a cart discount at checkout (Lite), and catalog rewards, Pro's `fixed_coupon` and `percent_coupon`. Both reach the cart as virtual coupons with no `shop_coupon` post, one coupon line per reward on the order. Why is the subject of [the stored codes record](2026-10-28-stack-points-discount-and-rewards-with-stored-codes.md).
- Earlier records gave catalog rewards a stored-coupon lifecycle (`issued`, `used`, `expired`). Once rewards became virtual coupons nothing issued a coupon, and that lifecycle was dropped: every row is written `applied`, and a virtual coupon frees nothing in WooCommerce when its order is cancelled, so reward lines return their points on cancel as the flexible discount's do.
- [`LedgerService` is the only writer of the ledger](2026-10-01-ledger-service-sole-writer.md), and its docblock lists the few places that may construct it. Each new writer of redemptions is another place to keep correct.
- [One tree builds both editions](2026-10-01-build-time-pro-lite-split.md): Lite is Pro without `includes/Pro/`. A store can downgrade from Pro to Lite with orders placed under Pro still open to cancels and refunds.
- A redemption row and its ledger row must agree. An order must never carry a loyalty discount that no ledger row debited, and one order must never be debited twice, though WooCommerce can fire its order hook again on a payment retry.
- Redemption adds no scheduled job, and nothing it stores waits on one.
- Lite applies `yayloyalty_redemption_gates` and `yayloyalty_reward_conditions_match`, in `RedemptionService::build()` and `RewardGate`, but Pro has never attached to either.
- WooCommerce's admin removal is one AJAX request, `woocommerce_remove_order_coupon`, whose data passes through the script filter `woocommerce_order_meta_box_remove_coupon_ajax_data` and whose save fires `woocommerce_before_delete_order_item` while the item still exists. `woocommerce_order_removed_coupon` exists only from WooCommerce 10.8, above the plugin's 10.0 floor. REST v3 removes every coupon line and re-applies each kept code on any `coupon_lines` update. The admin "Apply coupon" and REST v3 validate through `WC_Discounts( $order )`; checkout and `recalculate_coupons()` do not.
- New since the superseded record, from a customer report:
  - A declined payment left the points spent: the order went `failed` and the rows stayed `applied` until someone cancelled it. Paying again from My Account then neither returned nor took anything, so a customer whose card failed lost the points until a merchant noticed.
  - A loyalty line removed by REST v3, by code, or with the dialog's "Don't restore", left the points debited, and a later cancel returned them anyway, overriding "Don't restore".
  - Gateway dashboard refunds (Stripe, PayPal) carry no product lines. The earn clawback counted only refunded product lines, so they took back no earned points until the order was fully refunded.
  - Recalculating an order keeps virtual coupon lines at their stored amounts, so the old recalculate concern no longer reproduces.
- Options turned down:
  - **Keeping the coupon lifecycle for a future reward claimed as a stored coupon**, **a migration that drops the lifecycle's columns**, **all catalog code in Pro**, **all catalog code in Lite behind a filter**, **letting the order stand when the re-check at commit fails**, **debiting on payment**, **an `expired` or stored `completed` status** and **a proportional refund by refunded product lines**: turned down for the reasons the earlier records give, which still hold.
  - **A YayLoyalty REST route that removes the coupon itself**, and **restoring through a request of ours first**: they duplicate WooCommerce's removal or split it across two requests.
  - **Hooking `woocommerce_order_removed_coupon`.** It needs WooCommerce 10.8.
  - **Restoring the moment the item is deleted.** REST v3 deletes a kept line before re-applying it, so a kept line would be restored and then left on the order undebited.
  - **Treating a failed payment as a cancel.** A failed order is often paid again; a cancel's keys have no cycle part, so a second failure would restore nothing.
  - **A cycle counter on the redemption row.** It needs a schema change; keying each move on the ledger row it undoes gives every cycle fresh keys with none.
  - **Letting pay-for-order go ahead when the balance is short.** The order would be paid with a discount no ledger row debited.
  - **Leaving a restored row `cancelled` or `refunded`.** Un-cancel or a deleted refund would debit the points again.
  - **Refusing every loyalty code on an order.** REST v3 re-applies existing lines on every `coupon_lines` update, so legitimate updates would fail.
  - **Showing the choice to anyone who can edit orders.** It moves points, so it needs `yayloyalty_manage_points`, like the rest of points management.

## Decision

- **Who writes.** Lite owns every redemption write. `Engine\Redemption\CommitWriter` is the only inserter of redemption rows, and `Engine\Redemption\Reversal` moves only their `status` and `restored_points`. `RedemptionService::build()` is the only place redemption constructs `LedgerService`, and the writer and the reversal share it; `RemovedLineReconciler`, `PayForOrderRedebit` and the order listeners reach `Reversal` only through `RedemptionService::reversal()`. Pro supplies reward types through `yayloyalty_register_reward_handlers`, its rows on the points card through `yayloyalty_redeem_card_rows` and its routes through `yayloyalty_rest_controllers`. Lite also offers it `yayloyalty_redemption_gates` and `yayloyalty_reward_conditions_match`. Pro never writes a redemption row or a ledger row.
- **Statuses.** A row stores one of five, `Models\Redemption::STORED_STATUSES`: `applied`, `unpaid`, `refunded`, `cancelled`, `removed`. Nothing is derived at read time.
  - Every row, the flexible discount's and each reward line's, is written `applied`, for an order that already exists.
  - `Reversal` moves a row to `cancelled` or `refunded`, and back to `applied` when the cancel or a refund is undone. A row that is partly refunded stays `applied`.
  - `unpaid` means the order's payment failed and its points are back with the customer until it is paid.
  - `removed` means the row's line left the order. It is final: no cancel, refund, deleted refund, un-cancel or payment acts on it.
- **When points are debited.** When checkout creates the order, before payment, in one transaction with the redemption rows. The points come from the order's own loyalty coupon lines, each re-quoted under the customer's row lock, and the lines of one order are written all or none; how rewards and the flexible discount share the balance is [the stored codes record](2026-10-28-stack-points-discount-and-rewards-with-stored-codes.md)'s. If a re-check fails, nothing is written, every loyalty coupon line comes off the order and the checkout fails.
- **The double-debit guard.** The guard is the ledger idempotency key, one per customer, order and reward (`RedemptionDraft`), not an index on the redemptions table. A commit that finds the order's rows already written answers duplicate and writes nothing. So an order holds at most one row per reward.
- **Loyalty codes on an order.** A loyalty code goes on an order through `WC_Discounts( $order )` only while that order has an `applied` or `unpaid` row with that code; any other is refused with "This loyalty discount can only be applied by the customer at checkout." Checkout and recalculation do not validate, so they are unaffected. A `removed` row's code cannot come back, so nothing re-debits.
- **Returning points.** These rules apply to every row alike, each under its own key.
  - **A failed payment** restores whatever an `applied` row has not restored yet, and the row becomes `unpaid`. **Paying again** debits it back and makes it `applied`: pay-for-order (classic and block) debits before the gateway runs, all or nothing, and refuses the payment with "You no longer have enough points for the points discount on this order." when the balance is short; a paid status that arrives later (a gateway webhook) debits even into a negative balance, since the money already arrived. Each move is keyed on the ledger row it undoes (reference type `ledger`), so the cycle repeats without limit while a re-fired event composes the same key. The order gets a note for each move. Only this cycle uses `ledger` keys.
  - An order that is cancelled or trashed restores whatever its `applied` rows have not restored yet, and those rows become `cancelled`; an `unpaid` row has nothing left to restore and only becomes `cancelled`.
  - A refund follows the reward's `refund_policy` as it is when the refund runs. `proportional` returns refunded amount ÷ order total of the points, rounded down and worked out cumulatively, keyed by refund id, so repeated part refunds never strand or double a point; the refund that completes the order returns the rest. `full` and `none` do as named. Only the flexible discount has the setting, so a reward line, or a row whose reward is gone, follows `proportional`.
  - An order set to `refunded` with no refund object restores everything, unless the policy is `none`. When refund objects exist, the refunds decide and the status moves nothing.
  - Deleting a refund debits the row back down to what the remaining refunds justify. The balance may go negative. A `cancelled` row is not touched.
  - Un-cancelling an order debits again what the cancel restored beyond what its refunds justify, all or nothing, if the balance allows. Otherwise the row stays `cancelled` and the order gets a note.
  - **A loyalty line leaving the order**, by any route (the order screen, REST v3, code), returns `points − restored_points` of that one `applied` row under the key `[ redemption, row id ]`, and the row becomes `removed`. Deletions are settled once at the end of the request, so a line still on the order then was never removed, and a permanently deleted or trashed order is left to its own rules. An `unpaid` row's points are back already, so it becomes `removed` with no ledger row. The order gets a note, and a failed restore gets a note asking for a manual adjustment while the line still leaves.
  - **"Don't restore"** on the order edit screen, for a user with `yayloyalty_manage_points`, waives that return: the row becomes `removed` with nothing returned, the order gets a note naming the admin, and a later cancel or refund returns nothing for it.
  - `restored_points` never exceeds `points`. A row becomes `refunded` only when they are equal.
  - When one event both restores redeemed points and claws back earned ones, the restore row is written first, in the same transaction. When a refund is deleted, the earned points that refund clawed back are given back first, then the redeemed points are debited, in the same transaction.
- **Taking back earned points on a refund.** A refund claws back each earn row's share of the base its campaign scored, worked out cumulatively and keyed by refund id. Refunded product lines remove their own amount from the base. A refund with no product lines (a gateway dashboard refund, a shipping refund) removes amount × base ÷ order total. The refunded base never exceeds the base, and a fully refunded order owes every point back. An order whose total is 0.00 or less claws nothing back on a refund; a cancel or trash still takes everything back.

## Consequences

- `LedgerService` has one construction site for all of redemption, however many reward types Pro adds.
- Every row moves by one set of rules, so `Reversal` has one path per event and no status that only some rows can reach.
- Orders placed under Pro still reverse on a Lite build: the rows, the keys, `Reversal`, the reconciler and the order screen's dialog are all Lite's.
- A shopper whose balance dropped between cart and checkout, or between a failed payment and paying again, sees the payment refused and has to pay without the discount. That is the cost of never leaving a discounted order with no debit.
- Points are held from the moment the order exists, including while it waits for payment, and come back as soon as a payment fails. An abandoned pending order keeps them until it is cancelled.
- A late paid status can leave a customer's balance negative when they spent the returned points in between.
- A reward line always refunds proportionally, whatever the flexible discount's policy says, until rewards get a `refund_policy` of their own.
- An order un-cancelled a second time is not debited again, because a cancel's key has no cycle part. Its note asks the merchant to adjust by hand. The failed-and-pay cycle has no such limit.
- Orders already `failed` before this record keep their rows `applied`; a cancel or trash restores them. Amount-only refunds made before it are counted at the order's next refund.
- Installs created before the coupon lifecycle was dropped keep `coupon_id`, `expires_at` and `uk_coupon` on the redemptions table as nullable leftovers that nothing writes or reads.
- Deleting a reward is refused once a row names it, but nothing locks between that check and the delete. A row naming a deleted reward is still settled under its key, as `proportional`.
- Removal returns points at `shutdown`, after the response is sent, except in the order screen's own request, which settles before WooCommerce renders its notes. A restore that fails at `shutdown` is logged and noted on the order; nobody sees an error.
- The dialog's "Don't restore" rides WooCommerce's own request, so it depends on the script filter keeping its name. A WooCommerce change there turns "Don't restore" into a plain removal, which returns the points.
- A merchant who removes a line by REST v3 or code and wants the points kept adjusts them by hand.
- An admin can no longer add a loyalty code to an order by hand. The flexible discount's code still cannot be re-applied through REST v3 with no cart.
- A zero-total order keeps its earned points through any refund, including the 0.00 refund WooCommerce creates for it.
