# Redemption Order Lifecycle Fixes Design

**Status:** accepted 2026-11-12
**Related:** a customer report, 2026-11-12 (three findings below); [Admin removes a loyalty coupon design](2026-11-02-admin-remove-loyalty-coupon-design.md), whose criteria 3 and 9 this design reverses; [Admin removes a loyalty coupon decision](../decisions/2026-11-02-admin-removes-loyalty-coupon.md), which the implementing PR supersedes; backlog item [recalculate drops the points discount](../backlog/2026-10-07-recalculate-drops-the-points-discount.md), which the implementing PR removes.

## Goal

Points move with the money. When a payment is declined, the customer gets back the points the order spent. They are debited again when the order is paid, as often as that cycle repeats. When a loyalty coupon line leaves an order by any route, its points come back, unless an admin chose "Don't restore" in the order-screen dialog. When a refund carries only an amount, as Stripe and PayPal dashboard refunds do, earned points are clawed back in proportion, the same way redeemed points are already returned.

## Context

The report:

1. A declined payment leaves redeemed points stuck on the dead order.
2. Removing a loyalty coupon in wp-admin is ignored, so the points stay deducted.
3. Amount-only refunds restore spent points but leave earned points in place.

What reproduction on brandy.test (WooCommerce 11.2.2, HPOS) found, 2026-11-12:

- **Finding 1.** The classic and Store API adapters debit redeemed points at `woocommerce_checkout_order_processed` / `woocommerce_store_api_checkout_order_processed`, before the gateway answers. `OrderListener::route()` does nothing for `failed` (`includes/Engine/Earning/Listeners/OrderListener.php`, the `processing`/`failed`/`checkout-draft` case). So a declined order keeps the debit.
- **Finding 2.**
  - The order-screen dialog's Restore works: order #10711 went `removed`, got a `refund_redeem` of +300 and a note.
  - The points stay debited on every other route, as the 2026-11-02 spec intended (criteria 3 and 9): "Don't restore" leaves the row `applied`, and a later cancel restores it in full. REST v3 `coupon_lines`, `$order->remove_coupon()`, and a removal without the dialog all restore nothing.
  - A `processing` order has no Remove link, because WooCommerce locks its items.
  - Recalculate and `recalculate_coupons()` keep both a reward line and the flexible discount's line at their amounts. WooCommerce treats a virtual coupon (id 0) as a deleted coupon and keeps the stored line discount. The 2026-10-07 backlog item no longer reproduces.
- **Finding 3.** `Earning\Reversal::refund()` (`includes/Engine/Earning/Reversal.php`) bases its clawback on refunded product lines only. A refund with no line items claws back nothing unless the order is fully refunded, while `Redemption\Reversal::refund_target()` already prorates by refunded amount ÷ order total.

Code this builds on:

- `Engine\Redemption\Reversal` (`includes/Engine/Redemption/Reversal.php`) moves redemption rows:
  - `settle_each()` locks the row's customer, then the row.
  - `restore()` and `debit()` write through `LedgerService::refund_redeem()` / `redeem()` under keys from `RedemptionDraft::of()`.
  - Keys come from `Engine\Earning\IdempotencyKey`: `{type}:{user}:{reference_type}:{reference_id}:{qualifier}`. They have no cycle part, so un-cancel debits once and then answers `key_spent`.
- `Engine\Earning\Listeners\OrderReversalListener` runs a redemption restore and the earn clawback in one `Transaction::run()` and notes both.
- `Engine\Admin\OrderCouponRemoval` and `assets/admin/order-coupon-removal.js` make up the dialog. A priority-1 handler on `wp_ajax_woocommerce_remove_order_coupon` attaches a one-shot `woocommerce_before_delete_order_item` listener that calls `Reversal::coupon_removed()`.
- `CouponProvider::only_redeemed_on_order()` (`woocommerce_coupon_is_valid`) refuses a loyalty code on an order without an `applied` row for it.
- WooCommerce pay-for-order:
  - Classic: `WC_Form_Handler::pay_action()` fires `woocommerce_before_pay_action( $order )`, then goes on to the gateway whatever notices were added.
  - Block: `CheckoutOrder` fires `woocommerce_store_api_checkout_order_processed( $order )` before payment, and `StoreApiAdapter::on_order_processed()` already hooks it.
- Storage:
  - `yayloyalty_redemptions.status` is `varchar(12)`.
  - `yayloyalty_ledger.reference_type` is `varchar(20)`.
  - `yayloyalty_ledger.redemption_id` is indexed.
  - The SPA keeps `REDEMPTION_STATUSES` in `apps/admin/src/lib/schema/redemption.schema.ts`. `reference_type` is a free `z.string()` in `ledger.schema.ts`, so it needs no SPA change.

## Scope

**In:**
- A new redemption status `unpaid`, for points returned because the order's payment failed.
- Restoring every `applied` row when an order moves to `failed`, and releasing its pending points.
- Debiting `unpaid` rows again:
  - Strictly when the customer submits pay-for-order, classic and block. A short balance refuses the payment.
  - Leniently, with the balance allowed to go negative, when the order leaves `failed` for `on-hold`, `processing` or `completed` without that page.
- Repeatable failed and pay cycles, keyed by chaining on the ledger row each move reverses (new `ReferenceType::LEDGER`).
- Restoring a loyalty line's points whenever the line leaves an order by any route, reconciled once at the end of the request.
- The dialog's "Don't restore" making the row `removed` with nothing returned, so no later event returns it.
- Amount-only refunds clawing back earned points by refunded amount × the campaign base ÷ order total.
- `unpaid` in the REST enum (through `STORED_STATUSES`), the SPA schema and the customer drawer label.
- A decision record superseding `2026-11-02-admin-removes-loyalty-coupon.md`.
- Removing the stale backlog item `2026-10-07-recalculate-drops-the-points-discount.md`.
- Tests, accepted 2026-11-12:
  - New `tests/integration/Engine/Redemption/PaymentFailedTest.php` (integration suite): criteria 1 to 8.
  - New `tests/integration/Engine/Redemption/RemovedLineReconcilerTest.php` (integration suite): criteria 9 to 15.
  - New cases in `tests/integration/Earning/ReversalFlowTest.php` (integration suite): criteria 16 to 19.
  - An updated `apps/e2e/specs/admin-remove-loyalty-coupon.spec.ts` (Playwright): criterion 21.

**Out:**
- Moving the redemption debit to payment time. Checkout still debits at order-processed.
- Changing the keys of cancel, un-cancel or refund. Only the failed and pay cycle uses `ledger` keys.
- A Remove link or restore UI on orders WooCommerce does not let you edit (`processing`, `completed`). The merchant moves the order to `on-hold` first.
- Backfilling orders that are already `failed`, or amount-only refunds made before the release. Both settle on the order's next matching event.
- Amount-share clawback for a refund that carries any product line. Its extra amount, such as shipping, still claws nothing, as today.
- Raising the WooCommerce floor or hooking `woocommerce_order_removed_coupon`.
- Any change to how earned points are awarded.

## Acceptance criteria

Unless a criterion says otherwise, customer C has 1000 points and order O carries the reward "$5 off" (row R, 300 points, `applied`) and the points discount (row F, 200 points, `applied`). The balance after checkout is 500.

1. When O moves `pending` → `failed`:
   - two `refund_redeem` rows are written: +300 for R with key `refund_redeem:C:ledger:<R's redeem row id>:reward_<id>`, and +200 for F with key `…:flexible_discount`;
   - the balance is 1000;
   - R and F are `unpaid` with `restored_points` equal to `points`;
   - O has the note "Restored 500 redeemed points (payment failed).";
   - O's pending points are released.
2. Given (1), when `failed` fires again for O, no ledger row is written.
3. Given (1), when C submits the classic pay-for-order form with balance 1000:
   - two negative `redeem` rows are written before the gateway runs, keyed `redeem:C:ledger:<the matching refund_redeem row id>:…`;
   - the balance is 500;
   - R and F are `applied` with `restored_points` 0.
4. Given (3), when the payment fails again and O moves to `failed`, the points are restored again under keys naming the debit rows from (3), and the balance is 1000. When C pays again successfully, the balance is 500. O's ledger then holds exactly 2 + 2 + 2 + 2 + 2 rows: checkout, fail, pay, fail, pay.
5. Given (1), when C has spent down to 400 and submits classic pay-for-order:
   - no ledger row is written;
   - the gateway is not called;
   - C is redirected to `$order->get_checkout_payment_url()` with the error notice "You no longer have enough points for the points discount on this order.";
   - R and F stay `unpaid`.
6. The same as (5) through the block pay-for-order (Store API `CheckoutOrder`): the response is HTTP 409 with code `yayloyalty_redeem_failed` and the same message.
7. Given (1), when O moves `failed` → `processing` without pay-for-order (a late gateway webhook), with C at 400:
   - the rows are debited anyway and the balance is −100;
   - R and F are `applied`;
   - O has the note "Debited 500 redeemed points again (payment received).".
   - Moving `failed` → `pending` debits nothing.
8. Given (1), when O is cancelled or trashed, no ledger row is written, and R and F are `cancelled`. When O is then un-cancelled to `pending`, today's un-cancel debit applies: key `[redemption, row id]`, all or nothing.
9. Given O at `on-hold`, when `$order->remove_coupon( <R's code> )` runs and the order is saved, at the end of the request:
   - R gets a `refund_redeem` of +300 with key `[redemption, R id]`;
   - R is `removed`;
   - F is untouched;
   - O has the note "$5 off removed — 300 points restored.".
10. Given O at `on-hold`, when a REST v3 `PUT /wc/v3/orders/<O>` sends `coupon_lines` that keep both codes, no ledger row is written, and R and F stay `applied`. When it omits R's code, criterion 9's result follows.
11. Given O at `on-hold`, when an admin with `yayloyalty_manage_points` removes R in the dialog with "Restore 300 points", criterion 9's result follows.
12. Given O at `on-hold`, when that admin removes R with "Don't restore points":
    - no ledger row is written;
    - R is `removed` with `restored_points` 0;
    - O has the note "$5 off removed — points not restored, as chosen by <admin display name>.".
    - When O is later cancelled, R gets no ledger row, and only F is restored.
13. A remove request carrying `yayloyalty_waive=1`, sent with a bad `yayloyalty_nonce` or by a user without `yayloyalty_manage_points`, answers `success: false`. The line stays on O, and nothing is written.
14. When O is permanently deleted, no ledger row is written for its lines.
15. Given O after (1), with R `unpaid`, when R's line is removed by any route, R becomes `removed`, no ledger row is written, and no note is added.
16. Given an order of 100.00 whose earn row is 90 points on 90.00 of eligible lines plus 10.00 shipping, when an amount-only refund of 50.00 is created:
    - a `refund_earn` row of −45 is written with key `[refund, refund id]`;
    - the redeemed-points restore stays as today, 50 ÷ 100 of each row under its policy.
17. Given (16), when a second amount-only refund of 50.00 completes the order, the clawback reaches the full 90.
18. Given an order refunded first by product line and then by amount only, the clawback is the line share plus the amount share, never above the earn row's points.
19. Given an order with total 0.00, an amount-only refund claws nothing back and raises no error.
20. Everything works on WooCommerce 10.0 and later, with HPOS and with legacy order storage, on PHP 7.4.
21. End-to-end verification: `composer test:integration` prints `OK (N tests` with the three new and extended test files included, and `pnpm --filter @yayloyalty/e2e test` passes the updated dialog spec. Then, on brandy.test, with a registered customer:
    - Place an order that spends points with a gateway set to decline. The customer's balance in YayLoyalty → Customers returns to its value before checkout, and the order shows the restore note.
    - Pay the order from My Account → Orders → Pay with a gateway that succeeds. The balance drops by the redeemed points again.
    - Refund 50 % of a completed earning order by amount only. The customer drawer shows a clawback of half the earned points.

## Constraints

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
- `composer lint` and the admin lint fail on `dev` today ([known issues](../known-issues.md)): lint only the changed files, and expect no new findings.
- `composer test:integration` can exit 0 without running ([known issues](../known-issues.md)): a pass shows `OK (N tests` in the output.

## Approach

**Chosen:** a failed and pay cycle with keys chained on the ledger, and removed lines reconciled at the end of the request.

**Architecture:**
- A failed order restores its redemption rows into the new status `unpaid`.
- The next payment attempt debits them back before the gateway runs, and refuses on a short balance. A status change that proves payment arrived without that page debits them back leniently.
- Each restore is keyed on the debit row it undoes, and each debit on the restore row it undoes. Every cycle therefore gets fresh keys, while a re-fired event recomposes the same key and collides.
- Coupon-line removals are collected as items are deleted and settled once at `shutdown`. A REST update that deletes and re-applies a line in the same request sees it back and does nothing.
- The earn clawback adds an amount share for refunds that carry no product line.

**Components:**
- `includes/Enums/ReferenceType.php`: `LEDGER = 'ledger'` (a `yayloyalty_ledger` id), added to `all()`.
- `includes/Models/Redemption.php`: `STATUS_UNPAID = 'unpaid'`, added to `STORED_STATUSES`. The status docblock becomes: applied ⇄ unpaid; applied | unpaid → cancelled | refunded | removed. `removed` covers both "restored what was left" and "admin chose not to restore".
- `includes/Repositories/LedgerRepository.php`: `latest_for_redemption( int $redemption_id, string $type ): ?LedgerEntry`, the highest-id row of that type for that redemption.
- `includes/Engine/Redemption/Reversal.php`:
  - `payment_failed( \WC_Order $order ): Outcome[]`, over `applied` rows:
    - restores `points − restored_points` under the key `[ ReferenceType::LEDGER, latest REDEEM row id ]`;
    - sets the row `unpaid` with `restored_points = points`;
    - a row with nothing left to restore becomes `unpaid` with no ledger row.
  - `payment_retried( \WC_Order $order, bool $allow_negative ): Outcome[]`, over `unpaid` rows:
    - debits `restored_points − refund_target()` under the key `[ ReferenceType::LEDGER, latest REFUND_REDEEM row id ]`, and the row becomes `applied`;
    - with `$allow_negative` false it is all or nothing across the order's rows, in one transaction: a short balance answers `Rewards\Result::REASON_INSUFFICIENT_BALANCE` and writes nothing.
  - `cancelled()` also lists `unpaid`. Its restore target is already met, so it only moves the row to `cancelled`, through `record_restore()` with no ledger row.
  - `coupon_removed( \WC_Order $order, string $code, bool $waive ): ?Outcome`:
    - acts on `applied` and `unpaid`;
    - for `unpaid` or `$waive` it writes no ledger row and calls `record_restore( id, restored_points, removed )`;
    - otherwise it restores as today.
- `includes/Engine/Earning/Listeners/OrderListener.php`:
  - `failed` routes to `OrderReversalListener::on_failed()`.
  - When `$from` is `failed` and `$to` is `on-hold`, `processing` or `completed`, it calls `OrderReversalListener::on_paid_after_failure()` before the route.
- `includes/Engine/Earning/Listeners/OrderReversalListener.php`:
  - `on_failed()` releases pending points, runs `payment_failed()` and notes "Restored %1$d redeemed points (payment failed).".
  - `on_paid_after_failure()` runs `payment_retried( $order, true )` and notes "Debited %1$d redeemed points again (payment received).".
- `includes/Engine/Redemption/PayForOrderRedebit.php` (new): `register()` hooks `woocommerce_before_pay_action`.
  - When the order has `unpaid` rows, it runs `payment_retried( $order, false )`.
  - On refusal it adds the error notice from criterion 5, then `wp_safe_redirect( $order->get_checkout_payment_url() )` and `exit`.
  - It also exposes `redebit_or_message( \WC_Order ): ?string` for the Store API adapter.
- `includes/Engine/Redemption/Adapters/StoreApiAdapter.php`: `on_order_processed()` first calls `redebit_or_message()`, and on a message throws `RouteException( 'yayloyalty_redeem_failed', $message, 409 )`. Then it calls `commit()` as today, which answers duplicate for an order that has rows.
- `includes/Engine/Redemption/RemovedLineReconciler.php` (new). `register()` hooks:
  - `woocommerce_before_delete_order_item`: queues `[ order_id ][ code ]` for a `WC_Order_Item_Coupon` whose code `EarnContext::is_loyalty_code()` accepts.
  - `woocommerce_before_delete_order`: drops that order from the queue.
  - `shutdown` at priority 0: settles the queue. For each entry it skips:
    - an order that is gone or in `trash`;
    - an order that still carries the code.

    Otherwise it calls `coupon_removed( $order, $code, $this->is_waived( order, code ) )` and notes the result. A `Throwable` is logged with `Log::error()` and gets the failure note.
  - `waive( int $order_id, string $code )`: called by `OrderCouponRemoval`.
- `includes/Engine/Admin/OrderCouponRemoval.php`:
  - `before_remove()` now looks for `yayloyalty_waive`. It checks the nonce and capability as today, and on success calls `RemovedLineReconciler::waive()`.
  - The one-shot `woocommerce_before_delete_order_item` listener and `restore()` are removed.
  - `enqueue()` keeps listing `applied` rows only. An `unpaid` row has nothing to return, so its line gets no dialog.
- `assets/admin/order-coupon-removal.js`: "Restore" re-sends the click with no extra data. "Don't restore" sets `pending`, and the ajax-data filter adds `yayloyalty_waive: 1` and `yayloyalty_nonce`.
- `includes/Engine/Redemption/CouponProvider.php`: `only_redeemed_on_order()` accepts a row that is `applied` or `unpaid`.
- `includes/Engine/Earning/Reversal.php`: `refund()` computes each earn row's refunded base as follows:
  - the line share over refunds that carry at least one `WC_Order_Item_Product` line, as today;
  - plus, for each refund with no product line, `abs( amount ) × original ÷ order total`, skipped when the order total is 0;
  - capped at `original`.

  The docblock replaces the "claws back nothing" sentence.
- `includes/YayLoyalty.php`: constructs and registers `PayForOrderRedebit` and `RemovedLineReconciler` (front end and admin both, since REST and pay-for-order run outside `is_admin()`). It passes the reconciler to `OrderCouponRemoval`.
- `apps/admin/src/lib/schema/redemption.schema.ts`: `'unpaid'` in `REDEMPTION_STATUSES`.
- `apps/admin/src/pages/customers/drawer/customer-drawer.redemption-rows.ts`: label "Returned: payment failed".

**Data and storage:**
- New values only: `unpaid` in `yayloyalty_redemptions.status`, and `ledger` in `yayloyalty_ledger.reference_type`.
- No migration and no backfill.
- Nothing new on uninstall.

**Integration points:**
- Consumed hooks:
  - `woocommerce_order_status_changed`, already hooked.
  - `woocommerce_before_pay_action`.
  - `woocommerce_store_api_checkout_order_processed`, already hooked.
  - `woocommerce_before_delete_order_item`.
  - `woocommerce_before_delete_order`.
  - `shutdown`.
  - `wp_ajax_woocommerce_remove_order_coupon` (priority 1, already hooked).
- WooCommerce JS event `woocommerce_order_meta_box_remove_coupon_ajax_data`.
- New POST field `yayloyalty_waive`. `yayloyalty_restore` is retired.
- No new plugin hooks, and no new REST route.

**UI:**
- The dialog's copy and buttons are unchanged.
- New strings:
  - Order note: "%1$s removed — points not restored, as chosen by %2$s."
  - Order note: "Restored %1$d redeemed points (payment failed)."
  - Order note: "Debited %1$d redeemed points again (payment received)."
  - Storefront error: "You no longer have enough points for the points discount on this order."
  - Drawer label: "Returned: payment failed".

**Edge cases and failure modes:**

| Case | Behaviour |
|---|---|
| `failed` fires twice, or two hooks see it | Same latest debit row, same key: duplicate, nothing written |
| Pay-for-order debit succeeds, then the gateway declines | Order → `failed` → restored under a key naming that debit row |
| Pay-for-order submitted twice concurrently | Customer and row locks serialise. The second finds the rows `applied`, so nothing to debit |
| Late webhook after a successful pay-for-order | Rows already `applied`: nothing to debit |
| `failed` → `cancelled` → un-cancel | `cancelled()` moves `unpaid` rows to `cancelled` with no ledger row. Un-cancel uses today's `[redemption, id]` debit |
| Refund on an `unpaid` order | `refunded()` acts on `applied` only. Nothing moves; the points are already back |
| REST v3 update keeping both lines | Lines deleted, then re-applied: the reconciler sees the codes back and skips |
| Order permanently deleted | Queue entry dropped at `woocommerce_before_delete_order` |
| Order trashed while a removal is queued | Skipped: trash restores through `on_order_trashed()` |
| Checkout commit refused and lines stripped | No redemption row exists, so the reconciler finds nothing |
| Reconcile throws at `shutdown` | `Log::error()` and failure note; other queue entries still settle |
| Amount-only refund on a 0.00 order | Amount share skipped |
| Refund with product lines plus shipping | Line share only, as today |

**Decision record:** the implementing PR adds `docs/decisions/2026-11-12-redemption-follows-payment-and-order-lines.md`. It supersedes [2026-11-02-admin-removes-loyalty-coupon](../decisions/2026-11-02-admin-removes-loyalty-coupon.md) whole, restates its rules, and adds three:
- the `unpaid` status with the failed and pay cycle;
- `ledger` chain keys for that cycle;
- removal restores by default, with "Don't restore" final.

The 2026-11-02 record's Status becomes "superseded by" the new one.

## Alternatives considered

| Option | Why rejected |
|---|---|
| A `cycle` column on `yayloyalty_redemptions`, in the key | Needs a dbDelta migration and a new qualifier vocabulary. Chained ledger keys give the same uniqueness with no schema change |
| Restore on `failed` and strip the loyalty lines instead of debiting again | Changes the order total the customer was quoted, and a late webhook success would leave a paid order without its discount |
| Debit leniently on retry and never refuse | User chose to refuse at pay-for-order when the balance is short, 2026-11-12 |
| Debit only at payment time instead of at checkout | Large change to the commit path, and it opens a window where the balance is spent twice |
| Reconcile on every `woocommerce_after_order_object_save` | Restores wrongly at REST v3's intermediate saves, and adds a query to every order save |
| Hook `woocommerce_order_removed_coupon` | Exists only from WooCommerce 10.8; the floor is 10.0. Still sees REST's remove-then-re-apply |
| Keep removal restores to the dialog only (2026-11-02 design) | The report shows merchants remove lines by other routes and expect points back |
| Claw every refund back by amount | A refund of a product the campaign excluded would claw points |
| Amount share against the total minus shipping | More accurate for shipping-only refunds, but needs a shipping-aware split. User chose amount ÷ order total |

## Rollout

- `readme.txt` changelog:
  - "Fix: a declined payment now returns the points the order spent, and they are taken again when the order is paid."
  - "Fix: removing a points discount or reward from an order in any way returns its points, unless you choose Don't restore."
  - "Fix: amount-only refunds, such as Stripe and PayPal dashboard refunds, now take back earned points in proportion."
- Orders already `failed` before the update keep their rows `applied`. Cancelling or trashing one restores its points through today's cancel path. Nothing restores them on upgrade.
- Amount-only refunds made before the update are counted at the order's next refund.

## Open questions

None.
