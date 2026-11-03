# Lite owns every redemption write, and Pro supplies reward types

**Purpose:** why all redemption writes live in Lite, which statuses a redemption row stores, when points are debited, and the rules by which a cancellation or a refund returns them.

**Update when:** never edit it. Supersede it with a new record if Pro gets a redemption writer, the status set changes, the debit moves, or a refund rule changes.

## Status

Superseded by [Lite owns every redemption write, and every redemption row is an applied discount](2026-10-30-lite-writes-every-redemption-as-an-applied-discount.md), 2026-10-30. Accepted 2026-10-07 by Jason, with the redemptions design.

## Context

- Customers spend points two ways: a slider at checkout that turns points into a cart discount (Lite), and catalog rewards claimed for a coupon (Pro).
- [`LedgerService` is the only writer of the ledger](2026-10-01-ledger-service-sole-writer.md), and its docblock lists the few places that may construct it. Each new writer of redemptions is another place to keep correct.
- [One tree builds both editions](2026-10-01-build-time-pro-lite-split.md): Lite is Pro without `includes/Pro/`. A store can downgrade from Pro to Lite while coupons issued under Pro are still unused, and Pro data has to keep its lifecycle there.
- A redemption row and its ledger row must agree. An order must never carry a points discount that no ledger row debited, and one order must never be debited twice, though WooCommerce can fire its order hook again on a payment retry.
- Nothing in the plugin runs on a schedule, and this work adds no scheduler.
- Options turned down:
  - **All catalog code in Pro.** After a downgrade, issued coupons would still work, but their rows would never move and refunds would return nothing. It would also give `LedgerService` one more construction site.
  - **All catalog code in Lite, switched on by a filter.** It ships Pro behaviour in the Lite zip, and the build validator would not catch it.
  - **Letting the order stand when the re-check at commit fails.** The shopper keeps the discount with no points debited.
  - **Debiting on payment instead of on order creation.** Two open orders could spend the same points.
  - **Stored `expired` and `completed` statuses.** `expired` needs a scheduler to set it. `completed` changes nothing about a redemption, and its two sources disagreed on when it applies.
  - **A proportional refund by refunded product lines.** The reward editor promises that a half refund returns half the points.
  - **Always returning points when a catalog coupon's order is cancelled.** WooCommerce already frees the coupon, so the customer would get both.

## Decision

- **Who writes.** Lite owns every redemption write. `Engine\Redemption\CommitWriter` is the only inserter of redemption rows, and `RedemptionService::build()` is the only place redemption constructs `LedgerService`. Pro supplies reward types, a conditions gate and its own routes, through the seams `yayloyalty_register_reward_handlers`, `yayloyalty_reward_conditions_match`, `yayloyalty_redemption_gates` and `yayloyalty_rest_controllers`. Pro never writes a redemption row or a ledger row.
- **Statuses.** A row stores one of five: `applied`, `issued`, `used`, `refunded`, `cancelled`. `expired` is derived at read time: an `issued` row whose `expires_at` has passed.
  - Slider: `applied`, then `cancelled` or `refunded`.
  - Catalog coupon: `issued`, then `used`, then `refunded`. `used` returns to `issued` when its order is cancelled.
- **When points are debited.** At order creation, in one transaction with the redemption row. The points come from the order's own coupon line, re-quoted under the customer's row lock. If that re-check fails, nothing is written, the coupon line comes off the order and the checkout fails.
- **The double-debit guard.** The slider's guard is the ledger idempotency key, one per customer and order, not an index on the redemptions table. The table's unique index is on `coupon_id`, which is NULL for the slider, so one order can hold several coupons of one reward.
- **Returning points.** Decided here; the reversal that implements these rules is built after this record.
  - An order that fails changes nothing.
  - A slider order that is cancelled or trashed restores its points in full, and the row becomes `cancelled`.
  - A refund of a slider order follows the reward's `refund_policy`. `proportional` returns refunded amount ÷ order total of the points, worked out cumulatively and keyed by refund id, so repeated part refunds never strand or double a point. `full` and `none` do as named.
  - An order set to `refunded` with no refund object restores by the same policy.
  - Deleting a refund debits again what that refund restored. The balance may go negative.
  - Un-cancelling an order debits again if the balance allows. Otherwise it leaves an order note.
  - A catalog coupon's order that is cancelled returns the row to `issued`, and no points move. A refund of that order returns points by the rules above, and the row becomes `refunded`.
  - A catalog coupon past `expires_at` reads as expired. WooCommerce rejects it, and no points return.
  - `restored_points` never exceeds `points`. A row becomes `refunded` only when they are equal.
  - When one order both restores redeemed points and claws back earned ones, the restore row is written first, in the same transaction.

## Consequences

- A coupon issued under Pro keeps moving through its lifecycle on a Lite build, and a refund there still returns its points.
- `LedgerService` gains one construction site for all of redemption, however many reward types Pro adds.
- A shopper whose balance dropped between cart and checkout sees the checkout fail and has to submit again without the discount. That is the cost of never leaving a discounted order with no debit.
- Points are held from the moment the order exists, including while it waits for payment. An abandoned pending order keeps them until it is cancelled.
- No row ever stores `expired`, so a report that counts expired coupons has to derive it from `expires_at`, as the read side does.
- Lite carries the lifecycle code for coupons that only Pro can issue.
- Recalculating an order in wp-admin can drop the virtual coupon while the points stay debited. [The backlog item](../backlog/2026-10-07-recalculate-drops-the-points-discount.md) tracks it.
