# Lite owns every redemption write, and every redemption row is an applied discount

**Purpose:** why all redemption writes live in Lite, why every redemption row, the flexible discount's and each reward line's, is an applied discount with one set of statuses, when points are debited, and the rules by which a cancellation or a refund returns them.

**Update when:** never edit it. Supersede it with a new record if Pro gets a redemption writer, a reward is claimed for a stored coupon, the status set changes, the debit moves, or a refund rule changes.

## Status

Superseded by [Lite owns every redemption write, and an admin's Restore on the order screen is the only removal that returns points](2026-11-02-admin-removes-loyalty-coupon.md), 2026-11-02.

Accepted, 2026-10-30, by Trace Bui. It supersedes [Lite owns every redemption write, and Pro supplies reward types](2026-10-07-lite-owns-redemption-writes.md) whole, and restates every rule of it that still holds.

## Context

- Customers spend points two ways: the flexible discount, which turns points into a cart discount at checkout (Lite), and catalog rewards, Pro's `fixed_coupon` and `percent_coupon`. Both reach the cart as virtual coupons with no `shop_coupon` post, one coupon line per reward on the order. Why is the subject of [the stored codes record](2026-10-28-stack-points-discount-and-rewards-with-stored-codes.md), still proposed, and of the accepted records it supersedes before it.
- The superseded record gave catalog rewards a stored-coupon lifecycle: a row `issued` with its own `shop_coupon` (`coupon_id`, unique under `uk_coupon`), `used` once an order spent it, `issued` again when that order was cancelled, and `expired`, derived at read time, past `expires_at`. Once rewards became virtual coupons, nothing issued a coupon: `CommitWriter` writes every reward line's row `applied`, with no coupon post. The lifecycle lived on only as code no write path reached: the `issued` and `used` statuses, `Redemption::display_status()`, the outstanding-coupon figures of `RedemptionRepository::summary_by_rewards()`, a coupon expiry setting on rewards, and a skip in `Reversal` for rows with a `coupon_id`.
- A virtual coupon frees nothing in WooCommerce when its order is cancelled, so the superseded record's reason for not returning a catalog coupon's points on cancel no longer applies. Reward lines already return their points on cancel, as the flexible discount's do.
- [`LedgerService` is the only writer of the ledger](2026-10-01-ledger-service-sole-writer.md), and its docblock lists the few places that may construct it. Each new writer of redemptions is another place to keep correct.
- [One tree builds both editions](2026-10-01-build-time-pro-lite-split.md): Lite is Pro without `includes/Pro/`. A store can downgrade from Pro to Lite with orders placed under Pro still open to cancels and refunds.
- A redemption row and its ledger row must agree. An order must never carry a loyalty discount that no ledger row debited, and one order must never be debited twice, though WooCommerce can fire its order hook again on a payment retry.
- When the superseded record was written, nothing in the plugin ran on a schedule. Since then the daily expiry run (`Engine\Expiry\ExpiryRunner`) has become its one Action Scheduler job, for reasons unrelated to redemption. Redemption adds no job, and nothing it stores waits on one.
- The superseded record said Pro supplies a conditions gate through `yayloyalty_redemption_gates` and `yayloyalty_reward_conditions_match`. Lite still applies both filters, in `RedemptionService::build()` and `RewardGate`, but Pro has never attached to either. It supplies its reward types, its rows on the points card and its routes. This is unrelated to dropping the coupon lifecycle.
- There are no live stores yet, so a schema change needs no migration.
- Options turned down:
  - **Keeping the coupon lifecycle for a future reward claimed as a stored coupon.** No reward type issues one, no write path reached the code, and such a reward would need rules of its own for expiry and for a cancel that frees its coupon.
  - **A migration that drops `coupon_id`, `expires_at` and `uk_coupon` from existing tables.** There are no live stores, the columns are nullable and nothing reads them, and dbDelta never drops a column, so it would be a hand-written migration that only dev databases would run.
  - **All catalog code in Pro.** After a downgrade, rows written under Pro would never move and refunds would return nothing. It would also give `LedgerService` one more construction site.
  - **All catalog code in Lite, switched on by a filter.** It ships Pro behaviour in the Lite zip, and the build validator would not catch it.
  - **Letting the order stand when the re-check at commit fails.** The shopper keeps the discount with no points debited.
  - **Debiting on payment instead of on order creation.** Two open orders could spend the same points.
  - **An `expired` status, stored or derived, and a stored `completed`.** A virtual coupon exists only while the cart quotes it, so no row has anything left to expire. `completed` changes nothing about a redemption.
  - **A proportional refund by refunded product lines.** The reward editor promises that a half refund returns half the points.

## Decision

- **Who writes.** Lite owns every redemption write. `Engine\Redemption\CommitWriter` is the only inserter of redemption rows, and `Engine\Redemption\Reversal` moves only their `status` and `restored_points`. `RedemptionService::build()` is the only place redemption constructs `LedgerService`, and the writer and the reversal share it. Pro supplies reward types through `yayloyalty_register_reward_handlers`, its rows on the points card through `yayloyalty_redeem_card_rows` and its routes through `yayloyalty_rest_controllers`. Lite also offers it `yayloyalty_redemption_gates` and `yayloyalty_reward_conditions_match`. Pro never writes a redemption row or a ledger row.
- **Statuses.** A row stores one of three, `Models\Redemption::STORED_STATUSES`: `applied`, `refunded`, `cancelled`. Nothing is derived at read time.
  - Every row, the flexible discount's and each reward line's, is written `applied`, for an order that already exists.
  - `Reversal` moves a row to `cancelled` or `refunded`, and back to `applied` when the cancel or a refund is undone. A row that is partly refunded stays `applied`.
- **When points are debited.** When checkout creates the order, before payment, in one transaction with the redemption rows. The points come from the order's own loyalty coupon lines, each re-quoted under the customer's row lock, and the lines of one order are written all or none; how rewards and the flexible discount share the balance is [the stored codes record](2026-10-28-stack-points-discount-and-rewards-with-stored-codes.md)'s. If a re-check fails, nothing is written, every loyalty coupon line comes off the order and the checkout fails.
- **The double-debit guard.** The guard is the ledger idempotency key, one per customer, order and reward (`RedemptionDraft`), not an index on the redemptions table: `RedemptionsTable` defines no unique index besides the primary key. A commit that finds the order's rows already written answers duplicate and writes nothing. So an order holds at most one row per reward.
- **Returning points.** These rules apply to every row alike, each under its own key.
  - An order that fails changes nothing.
  - An order that is cancelled or trashed restores whatever its `applied` rows have not restored yet, and those rows become `cancelled`.
  - A refund follows the reward's `refund_policy` as it is when the refund runs. `proportional` returns refunded amount ÷ order total of the points, rounded down and worked out cumulatively, keyed by refund id, so repeated part refunds never strand or double a point; the refund that completes the order returns the rest. `full` and `none` do as named. Only the flexible discount has the setting, so a reward line, or a row whose reward is gone, follows `proportional`.
  - An order set to `refunded` with no refund object restores everything, unless the policy is `none`. When refund objects exist, the refunds decide and the status moves nothing.
  - Deleting a refund debits the row back down to what the remaining refunds justify. The balance may go negative. A `cancelled` row is not touched.
  - Un-cancelling an order debits again what the cancel restored beyond what its refunds justify, all or nothing, if the balance allows. Otherwise the row stays `cancelled` and the order gets a note.
  - `restored_points` never exceeds `points`. A row becomes `refunded` only when they are equal.
  - When one event both restores redeemed points and claws back earned ones, the restore row is written first, in the same transaction. When a refund is deleted, the earned points that refund clawed back are given back first, then the redeemed points are debited, in the same transaction.

## Consequences

- `LedgerService` has one construction site for all of redemption, however many reward types Pro adds.
- Every row moves by one set of rules, so `Reversal` has one path and no status that only some rows can reach.
- Orders placed under Pro still reverse on a Lite build: the rows, the keys and `Reversal` are all Lite's.
- A shopper whose balance dropped between cart and checkout sees the checkout fail and has to submit again without the discount. That is the cost of never leaving a discounted order with no debit.
- Points are held from the moment the order exists, including while it waits for payment. An abandoned pending order keeps them until it is cancelled.
- A reward line always refunds proportionally, whatever the flexible discount's policy says, until rewards get a `refund_policy` of their own.
- An order un-cancelled a second time is not debited again, because the key has no cycle part. Its note asks the merchant to adjust by hand.
- Installs created before this change keep `coupon_id`, `expires_at` and `uk_coupon` on the redemptions table as nullable leftovers that nothing writes or reads. A fresh install doesn't have them, so nothing may rely on them either way.
- A reward claimed for a stored coupon would need a new record that supersedes this one, with its own statuses, its rules for cancel and expiry, and the columns it needs.
- Deleting a reward is refused once a row names it, but nothing locks between that check and the delete. A checkout that commits a never-claimed reward in that gap leaves a row naming a deleted reward, which `Reversal` still settles under its key, as `proportional`.
- Recalculating an order in wp-admin can drop a virtual coupon while the points stay debited. [The backlog item](../backlog/2026-10-07-recalculate-drops-the-points-discount.md) tracks it.
