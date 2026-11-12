# Lite owns every redemption write, and an admin's Restore on the order screen is the only removal that returns points

**Purpose:** why all redemption writes live in Lite, why every redemption row starts as an applied discount with one set of statuses, when points are debited, the rules by which a cancellation, a refund or an admin removing the line returns them, and why a loyalty code goes on an order only while that order paid for it.

**Update when:** never edit it. Supersede it with a new record if Pro gets a redemption writer, a reward is claimed for a stored coupon, the status set changes, the debit moves, or a rule for returning points changes.

## Status

Superseded by [Lite owns every redemption write, and redeemed and earned points follow the order's payment and its lines](2026-11-12-redemption-follows-payment-and-order-lines.md), 2026-11-12.

Accepted, 2026-11-02, by PhungTruong1997. It supersedes [Lite owns every redemption write, and every redemption row is an applied discount](2026-10-30-lite-writes-every-redemption-as-an-applied-discount.md) whole, and restates every rule of it that still holds.

## Context

- Customers spend points two ways: the flexible discount, which turns points into a cart discount at checkout (Lite), and catalog rewards, Pro's `fixed_coupon` and `percent_coupon`. Both reach the cart as virtual coupons with no `shop_coupon` post, one coupon line per reward on the order. Why is the subject of [the stored codes record](2026-10-28-stack-points-discount-and-rewards-with-stored-codes.md), still proposed, and of the accepted records it supersedes before it.
- The record before the superseded one gave catalog rewards a stored-coupon lifecycle (`issued`, `used`, `expired`). Once rewards became virtual coupons nothing issued a coupon, and the superseded record dropped that lifecycle: every row is written `applied`, and a virtual coupon frees nothing in WooCommerce when its order is cancelled, so reward lines return their points on cancel as the flexible discount's do.
- [`LedgerService` is the only writer of the ledger](2026-10-01-ledger-service-sole-writer.md), and its docblock lists the few places that may construct it. Each new writer of redemptions is another place to keep correct.
- [One tree builds both editions](2026-10-01-build-time-pro-lite-split.md): Lite is Pro without `includes/Pro/`. A store can downgrade from Pro to Lite with orders placed under Pro still open to cancels and refunds.
- A redemption row and its ledger row must agree. An order must never carry a loyalty discount that no ledger row debited, and one order must never be debited twice, though WooCommerce can fire its order hook again on a payment retry.
- Redemption adds no scheduled job, and nothing it stores waits on one. The daily expiry run (`Engine\Expiry\ExpiryRunner`) is the plugin's one Action Scheduler job, for reasons unrelated to redemption.
- Lite applies `yayloyalty_redemption_gates` and `yayloyalty_reward_conditions_match`, in `RedemptionService::build()` and `RewardGate`, but Pro has never attached to either.
- There are no live stores yet, so a schema change needs no migration.
- New since the superseded record:
  - Merchants remove loyalty coupon lines from editable orders on the order edit screen. Nothing reacted: the row stayed `applied` and the points stayed debited until a later cancel or refund. Merchants asked to choose, at the moment of removal, whether the customer gets the points back.
  - WooCommerce's admin removal is one AJAX request, `woocommerce_remove_order_coupon`, whose data passes through the script filter `woocommerce_order_meta_box_remove_coupon_ajax_data` and whose save fires `woocommerce_before_delete_order_item` while the item still exists. `woocommerce_order_removed_coupon` exists only from WooCommerce 10.8, above the plugin's 10.0 floor.
  - With no cart, the virtual coupon data hook answered for a reward's code, so an admin could apply `yayloyalty-…` to any order and get a discount no ledger row debited. The admin "Apply coupon" and REST v3 validate through `WC_Discounts( $order )`; checkout and `recalculate_coupons()` do not. REST v3 also removes every coupon line and re-applies each code on any `coupon_lines` update.
- Options turned down:
  - **Keeping the coupon lifecycle for a future reward claimed as a stored coupon**, **a migration that drops the lifecycle's columns**, **all catalog code in Pro**, **all catalog code in Lite behind a filter**, **letting the order stand when the re-check at commit fails**, **debiting on payment**, **an `expired` or stored `completed` status** and **a proportional refund by refunded product lines**: turned down for the reasons the superseded record gives, which still hold.
  - **A YayLoyalty REST route that removes the coupon itself.** It would duplicate `WC_AJAX::remove_order_coupon()` (tax address, notes, usage count) and drift as WooCommerce changes.
  - **Restoring through a request of ours first, then letting WooCommerce remove.** Two requests: a failure between them returns the points while the discount stays.
  - **Hooking `woocommerce_order_removed_coupon`.** It needs WooCommerce 10.8.
  - **Restoring on every removal path (REST, code).** The merchant decides, and only an explicit Restore says so.
  - **Making "Don't restore" final.** The merchant chose that the row stays `applied` and the old cancel and refund rules still apply.
  - **Leaving a restored row `cancelled` or `refunded`.** Un-cancel or a deleted refund would debit the points again.
  - **Refusing every loyalty code on an order.** REST v3 re-applies existing lines on every `coupon_lines` update, so legitimate updates would fail.
  - **Showing the choice to anyone who can edit orders.** It moves points, so it needs `yayloyalty_manage_points`, like the rest of points management.

## Decision

- **Who writes.** Lite owns every redemption write. `Engine\Redemption\CommitWriter` is the only inserter of redemption rows, and `Engine\Redemption\Reversal` moves only their `status` and `restored_points`. `RedemptionService::build()` is the only place redemption constructs `LedgerService`, and the writer and the reversal share it; the order screen reaches `Reversal` only through `RedemptionService::reversal()`. Pro supplies reward types through `yayloyalty_register_reward_handlers`, its rows on the points card through `yayloyalty_redeem_card_rows` and its routes through `yayloyalty_rest_controllers`. Lite also offers it `yayloyalty_redemption_gates` and `yayloyalty_reward_conditions_match`. Pro never writes a redemption row or a ledger row.
- **Statuses.** A row stores one of four, `Models\Redemption::STORED_STATUSES`: `applied`, `refunded`, `cancelled`, `removed`. Nothing is derived at read time.
  - Every row, the flexible discount's and each reward line's, is written `applied`, for an order that already exists.
  - `Reversal` moves a row to `cancelled` or `refunded`, and back to `applied` when the cancel or a refund is undone. A row that is partly refunded stays `applied`.
  - `removed` is set only by an admin's Restore, and it is final: no cancel, refund, deleted refund or un-cancel acts on it.
- **When points are debited.** When checkout creates the order, before payment, in one transaction with the redemption rows. The points come from the order's own loyalty coupon lines, each re-quoted under the customer's row lock, and the lines of one order are written all or none; how rewards and the flexible discount share the balance is [the stored codes record](2026-10-28-stack-points-discount-and-rewards-with-stored-codes.md)'s. If a re-check fails, nothing is written, every loyalty coupon line comes off the order and the checkout fails.
- **The double-debit guard.** The guard is the ledger idempotency key, one per customer, order and reward (`RedemptionDraft`), not an index on the redemptions table: `RedemptionsTable` defines no unique index besides the primary key. A commit that finds the order's rows already written answers duplicate and writes nothing. So an order holds at most one row per reward.
- **Loyalty codes on an order.** A loyalty code goes on an order through `WC_Discounts( $order )` only while that order has an `applied` row with that code; any other is refused with "This loyalty discount can only be applied by the customer at checkout." Checkout and recalculation do not validate, so they are unaffected. A line removed without restore can come back; a `removed` row's cannot, so nothing re-debits.
- **Returning points.** These rules apply to every row alike, each under its own key.
  - An order that fails changes nothing.
  - An order that is cancelled or trashed restores whatever its `applied` rows have not restored yet, and those rows become `cancelled`.
  - A refund follows the reward's `refund_policy` as it is when the refund runs. `proportional` returns refunded amount ÷ order total of the points, rounded down and worked out cumulatively, keyed by refund id, so repeated part refunds never strand or double a point; the refund that completes the order returns the rest. `full` and `none` do as named. Only the flexible discount has the setting, so a reward line, or a row whose reward is gone, follows `proportional`.
  - An order set to `refunded` with no refund object restores everything, unless the policy is `none`. When refund objects exist, the refunds decide and the status moves nothing.
  - Deleting a refund debits the row back down to what the remaining refunds justify. The balance may go negative. A `cancelled` row is not touched.
  - Un-cancelling an order debits again what the cancel restored beyond what its refunds justify, all or nothing, if the balance allows. Otherwise the row stays `cancelled` and the order gets a note.
  - An admin removing a loyalty line on the order edit screen, with `yayloyalty_manage_points`, chooses. "Restore" returns `points − restored_points` of that one `applied` row under the key `[ redemption, row id ]`, and the row becomes `removed`; the order gets a note, and a failed restore gets a note asking for a manual adjustment while the line still leaves. "Don't restore", and every other way a line leaves an order (REST v3, code, a user without the capability), moves nothing: the row stays `applied` and the rules above still apply.
  - `restored_points` never exceeds `points`. A row becomes `refunded` only when they are equal.
  - When one event both restores redeemed points and claws back earned ones, the restore row is written first, in the same transaction. When a refund is deleted, the earned points that refund clawed back are given back first, then the redeemed points are debited, in the same transaction.

## Consequences

- `LedgerService` has one construction site for all of redemption, however many reward types Pro adds.
- Every row moves by one set of rules, so `Reversal` has one path per event and no status that only some rows can reach.
- Orders placed under Pro still reverse on a Lite build: the rows, the keys, `Reversal` and the order screen's Restore are all Lite's.
- A shopper whose balance dropped between cart and checkout sees the checkout fail and has to submit again without the discount. That is the cost of never leaving a discounted order with no debit.
- Points are held from the moment the order exists, including while it waits for payment. An abandoned pending order keeps them until it is cancelled.
- A reward line always refunds proportionally, whatever the flexible discount's policy says, until rewards get a `refund_policy` of their own.
- An order un-cancelled a second time is not debited again, because the key has no cycle part. Its note asks the merchant to adjust by hand.
- Installs created before the superseded record keep `coupon_id`, `expires_at` and `uk_coupon` on the redemptions table as nullable leftovers that nothing writes or reads. A fresh install doesn't have them, so nothing may rely on them either way.
- A reward claimed for a stored coupon would need a new record that supersedes this one, with its own statuses, its rules for cancel and expiry, and the columns it needs.
- Deleting a reward is refused once a row names it, but nothing locks between that check and the delete. A checkout that commits a never-claimed reward in that gap leaves a row naming a deleted reward, which `Reversal` still settles under its key, as `proportional`.
- Recalculating an order in wp-admin can drop a virtual coupon while the points stay debited. The backlog item `2026-10-07-recalculate-drops-the-points-discount` tracked it (removed 2026-11-12).
- Restore rides WooCommerce's own request, so it depends on the script filter and the item-delete action keeping their names and order. A WooCommerce change there turns Restore into a plain removal, not a double restore.
- A row an admin removed is final: re-adding its line is refused, and a later cancel or refund returns nothing more, since everything already came back.
- A removal outside the order screen's dialog (REST v3, code) leaves the points debited until a cancel or refund. Merchants who remove lines that way adjust points by hand.
- An admin can no longer add a loyalty code to an order by hand. The flexible discount's code still cannot be re-applied through REST v3 with no cart; that gap predates this record.
