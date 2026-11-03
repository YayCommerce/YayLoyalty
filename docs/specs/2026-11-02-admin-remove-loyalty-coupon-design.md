# Admin Removes a Loyalty Coupon Design

**Status:** accepted 2026-11-02
**Related:** [Redemption design](2026-10-16-redemption-design.md), [Lite owns every redemption write, and every redemption row is an applied discount](../decisions/2026-10-30-lite-writes-every-redemption-as-an-applied-discount.md) (superseded by the record this work adds)

## Goal

When a merchant removes a loyalty coupon line (the points discount or a reward line) from an order on the order edit screen, a popup asks whether to return the points the customer spent on it. "Restore points" returns them at once. "Don't restore points" removes the line and leaves the points as they are today. Separately, nobody can add a loyalty code to an order unless that order's redemption already debited it.

## Context

- Today nothing in YayLoyalty reacts when a coupon line leaves an order. The redemption row stays `applied` and the points stay debited. A later cancel restores them in full, and a refund restores them under the reward's `refund_policy`. The redemption spec only checked that loyalty line amounts hold when an admin edits an order ([redemption design § Edge cases](2026-10-16-redemption-design.md)).
- `Engine\Redemption\Reversal` (`includes/Engine/Redemption/Reversal.php`) is the only mover of redemption rows. `settle_each()` locks the row's customer and then the row, and acts only on the statuses an event lists. `restore()` writes one `REFUND_REDEEM` row through `LedgerService::refund_redeem()` under the first unused key, then calls `RedemptionRepository::record_restore()`. Keys come from `RedemptionDraft::of()`, so they are per reward. The second cancel uses `[ redemption, row id ]` and the first uses `[ order, order id ]`. Un-cancel debits once, so a row that has been cancelled twice never returns to `applied`.
- `Reversal` is built once by `RedemptionService::build()` in `includes/YayLoyalty.php`, and `$redemption->reversal()` hands it to the earning listeners. `LedgerService` is constructed only where its docblock lists.
- `Models\Redemption::STORED_STATUSES` is `applied`, `refunded`, `cancelled`. The `status` column is `varchar(12)` (`includes/Database/Schema/RedemptionsTable.php`). The REST enum (`RedemptionsRestController`) reads `STORED_STATUSES`. The SPA copy is `REDEMPTION_STATUSES` in `apps/admin/src/lib/schema/redemption.schema.ts`, with labels in `apps/admin/src/pages/customers/drawer/customer-drawer.redemption-rows.ts`.
- **WooCommerce admin removal** (WC 11.2.1 here):
  - `meta-boxes-order.js` binds `click` on `a.remove-coupon` (delegated from `#woocommerce-order-items`) and posts `action=woocommerce_remove_order_coupon` with `coupon = data-code`. Before posting, it passes the data through `$( '#woocommerce-order-items' ).triggerHandler( 'woocommerce_order_meta_box_remove_coupon_ajax_data', [ data ] )`.
  - `WC_AJAX::remove_order_coupon()` checks `order-item` and `edit_shop_orders`, calls `$order->remove_coupon( $code )` (which queues the item for delete), then `calculate_totals( false )`, which saves the order. The save deletes the item and fires `woocommerce_before_delete_order_item( $item_id )` while the item row still exists.
  - `woocommerce_order_removed_coupon` exists only from WC 10.8, and the plugin declares `WC requires at least: 10.0`.
  - WooCommerce shows the Remove link only while the order is editable (`pending`, `on-hold`, drafts).
- **WooCommerce coupon validation on an order:**
  - `WC_Abstract_Order::apply_coupon()` validates through `WC_Discounts( $order )`, and so does the REST v3 `calculate_coupons()`. REST v3 also removes every coupon line and re-applies each code from `coupon_lines` through `apply_coupon()`.
  - `recalculate_coupons()` calls `WC_Discounts::apply_coupon( $coupon, false )` and does not validate.
  - Today `CouponProvider::virtual_coupon_data()` returns reward data for a reward code with no cart, so an admin can apply `yayloyalty-…` to any order and get a discount no ledger row debited.
- The order screen already has a Lite admin box and plain script: `Engine\Admin\OrderPointsMetabox` with `assets/admin/order-points.js`, registered in the `is_admin()` branch of `YayLoyalty::__construct()`.

## Scope

**In:**
- A popup on the order edit screen (HPOS and legacy) when a user with `yayloyalty_manage_points` clicks Remove on a loyalty coupon line whose redemption row is `applied` and has points not yet restored.
- "Restore points" returns `points − restored_points` and moves the row to a new status `removed`. "Don't restore points" removes the line as WooCommerce does today. "Cancel" removes nothing.
- The new status `removed`, ignored by every existing reversal event.
- Order notes for a restore and for a restore that failed.
- A guard that refuses a loyalty code on an order unless that order has an `applied` redemption row with that code.
- `removed` in the REST enum, the SPA schema and the customer drawer label.
- Tests, accepted 2026-11-02:
  - New cases in `tests/integration/Engine/Redemption/ReversalTest.php` (integration suite) for criteria 2, 3, 5, 6 and 7.
  - A new `tests/integration/Engine/Redemption/LoyaltyCodeOnOrderTest.php` (integration suite) for criteria 10 and 11.
  - A new Playwright spec in `apps/e2e` for criterion 13.

**Out:**
- Any reaction to a removal that does not come through the popup's Restore button: REST `coupon_lines`, code calling `remove_coupon()`, a user without `yayloyalty_manage_points`. The row stays `applied`, as today.
- A popup or restore for an order that is not editable.
- Restoring part of a row's remaining points.
- Re-debiting points when a removed line comes back. The guard refuses such a code instead.
- Fixing the points discount's code failing to re-apply through REST v3 (it has no coupon data without a cart). This is a gap that already exists, not new.
- Raising the WooCommerce floor.

## Acceptance criteria

1. Given an `on-hold` order with the reward "$5 off" (500 points, `restored_points` 0), when a user with `yayloyalty_manage_points` clicks its Remove link, then a dialog shows "Remove $5 off?", "The customer spent 500 points on this discount." and the buttons "Restore 500 points" (focused), "Don't restore points" and "Cancel".
2. Given (1), when they click "Restore 500 points":
   - the coupon line leaves the order and the totals recalculate;
   - the customer's balance rises by exactly 500;
   - one `REFUND_REDEEM` ledger row exists with reference `redemption` / the row id;
   - the row is `removed` with `restored_points` 500;
   - the order has the note "$5 off removed — 500 points restored."
3. Given (1), when they click "Don't restore points", then the line leaves the order, no ledger row is written, and the row stays `applied` with `restored_points` 0. When the order is then cancelled, 500 points are restored and the row is `cancelled`.
4. Given (1), when they click "Cancel", then no request is sent and the line stays.
5. Given a row of 500 points with `restored_points` 200, the dialog adds "300 of them have not been returned yet." The button reads "Restore 300 points", and restoring writes exactly 300 and sets `restored_points` 500.
6. Given a `removed` row, when the order is cancelled, refunded, has a refund deleted or is un-cancelled, then no ledger row is written for it and it stays `removed`.
7. Given a `removed` row, when `Reversal::coupon_removed()` runs again for it, then it answers skipped `nothing_to_move` and writes nothing.
8. Given a user with `edit_shop_orders` but not `yayloyalty_manage_points`, no dialog appears and WooCommerce removes the line with no restore. A hand-crafted request with `yayloyalty_restore=1`, from that user or with a bad `yayloyalty_nonce`, answers `success: false` with an error, and the line stays on the order.
9. Given a loyalty line removed through REST v3 `coupon_lines` or by `$order->remove_coupon()`, the row and the ledger do not change.
10. Given an order with no `applied` redemption row for `yayloyalty-abc12345`, when `$order->apply_coupon( 'yayloyalty-abc12345' )` runs (admin "Apply coupon" or REST v3), then it returns a `WP_Error` with the message "This loyalty discount can only be applied by the customer at checkout." The order is unchanged.
11. Given an order whose `applied` row has code `yayloyalty-abc12345`, applying that code is allowed. `recalculate_coupons()` on any order with loyalty lines keeps them and their amounts.
12. It works on WooCommerce 10.0 and later, with HPOS and with legacy order storage, on PHP 7.4.
13. End-to-end verification: `pnpm --filter @yayloyalty/e2e test` passes the new popup spec. On the wp-env site, an `on-hold` order with a reward line shows the dialog on Remove. After "Restore …" the line is gone, the customer's balance (Customers page in the YayLoyalty admin) has gone up by the reward's points, and the order notes show "… removed — … restored."

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static` types, or a trailing comma after the last parameter.
- WooCommerce floor stays `10.0`. Do not hook `woocommerce_order_removed_coupon`.
- Only `LedgerService` writes ledger rows and balances, and nothing new constructs it. `Reversal` comes from `RedemptionService::build()`'s `reversal()`.
- Lite owns this feature. Nothing under `includes/Engine/` names `YayLoyalty\Pro\*`.
- Admin script is plain JS with no build step, like `assets/admin/order-points.js`.
- All strings use the `yayloyalty` text domain. "points" comes from `SettingsHelper::points_label_plural()` / `points_text()`, never hard-coded.
- Logging only through `Log::error()` with the reporting class as context.
- Capability `Capabilities::MANAGE_POINTS` (`yayloyalty_manage_points`). Nonce action `yayloyalty-restore-coupon`, field `yayloyalty_nonce`.

## Approach

**Chosen:** ride WooCommerce's own remove-coupon request.

**Architecture:**
- The order screen gets a list of restorable loyalty codes. A capture-phase click listener stops WooCommerce's handler for those codes and opens a dialog.
- Every choice except Cancel re-dispatches the click through jQuery, so WooCommerce's own handler sends its usual request. "Restore" first adds `yayloyalty_restore` and a nonce through WooCommerce's `woocommerce_order_meta_box_remove_coupon_ajax_data` filter.
- On the server, a handler on that AJAX action at priority 1 verifies the flag and attaches a one-shot listener to `woocommerce_before_delete_order_item`. When WooCommerce's save deletes the matching coupon item, the listener restores through `Reversal`.
- The validity guard lives with the other virtual-coupon hooks in `CouponProvider`, so it runs on REST and admin requests alike.

**Components:**
- `includes/Models/Redemption.php`: `STATUS_REMOVED = 'removed'`, added to `STORED_STATUSES`. The class and status docblocks describe it.
- `includes/Engine/Redemption/Reversal.php`: `coupon_removed( \WC_Order $order, string $code ): ?Outcome`. It finds the order's row whose `coupon_code` matches `$code` (compared with `wc_format_coupon_code()`) and returns null when there is none. Through `settle_each`-style locking it acts only on `applied`:
  - It restores `points − restored_points` under the single key `[ ReferenceType::REDEMPTION, row id ]` with note `Coupon removed from order`, and sets the status to `removed`.
  - When nothing remains, it calls `record_restore( id, points, removed )` with no ledger row.
  - The class docblock lists the new event, and the existing events' status lists stay as they are, so none of them includes `removed`.
- `includes/Engine/Admin/OrderCouponRemoval.php` (new): takes `Reversal` and `RedemptionRepository`, and `register()` attaches:
  - `admin_enqueue_scripts`: on `wc_get_page_screen_id( 'shop-order' )`, for `MANAGE_POINTS`, when the order has a restorable row. It enqueues `yayloyalty-order-coupon-removal` with inline `window.yayloyaltyCouponRemoval = { nonce, coupons: { code: { name, points, remaining } }, i18n }`.
  - `wp_ajax_woocommerce_remove_order_coupon` at priority 1: when `yayloyalty_restore` is set, it checks the nonce (`check_ajax_referer( 'yayloyalty-restore-coupon', 'yayloyalty_nonce', false )`) and the capability, and on failure answers `wp_send_json_error( [ 'error' => … ] )`. Otherwise it attaches the one-shot `woocommerce_before_delete_order_item` listener for the posted `order_id` and code.
  - The listener loads the item (`WC_Order_Factory::get_order_item()`). It acts only when the item is a `WC_Order_Item_Coupon` of that order with that code. It calls `coupon_removed()`, adds the note, then detaches itself. A `Throwable` is logged, gets the failure note, and is not rethrown, so the line still leaves the order.
- `assets/admin/order-coupon-removal.js` (new): the capture listener, the `<dialog>`, the `pending` flag, the ajax-data filter, and removing a code from `coupons` after it is sent.
- `includes/Engine/Redemption/CouponProvider.php`: a seventh hook, `woocommerce_coupon_is_valid` (3 args). When `$discounts->get_object()` is a `WC_Order`, the code is a loyalty code (`EarnContext::is_loyalty_code()`) and the order has no `applied` row with that code, it throws `Exception` with the message in criterion 10. Otherwise it returns `$valid` unchanged. The docblock count changes from six to seven.
- `includes/YayLoyalty.php`: in the `is_admin()` branch, `( new OrderCouponRemoval( $redemption->reversal(), new RedemptionRepository() ) )->register();`.
- `apps/admin/src/lib/schema/redemption.schema.ts`, `apps/admin/src/pages/customers/drawer/customer-drawer.redemption-rows.ts`: `'removed'`, labelled "Removed by admin".

**Data and storage:** One new value `removed` in `yayloyalty_redemptions.status`. There is no schema change (it fits `varchar(12)`), no migration, and nothing new on uninstall.

**Integration points:**
- Consumed WooCommerce hooks: `wp_ajax_woocommerce_remove_order_coupon` (priority 1), `woocommerce_before_delete_order_item`, `woocommerce_coupon_is_valid`, `admin_enqueue_scripts`.
- WooCommerce's JS event `woocommerce_order_meta_box_remove_coupon_ajax_data`.
- New POST fields: `yayloyalty_restore`, `yayloyalty_nonce`.
- No new plugin hooks and no REST route. The redemptions REST enum gains `removed` through `STORED_STATUSES`.

**UI:**
- A native `<dialog>` with WP admin button classes (`button button-primary`, `button`), opened over the order items box.
- Copy:
  - Title: "Remove {name}?"
  - Body: "The customer spent {points} on this discount." When some points were already returned, it adds "{remaining} of them have not been returned yet."
  - Buttons: "Restore {remaining}", "Don't restore points", "Cancel".
  - Note on restore: "{name} removed — {remaining} restored."
  - Note on failure: "{name} removed, but its points could not be restored. Adjust the customer's points by hand."
- `{points}` and `{remaining}` go through `SettingsHelper::points_text()`.

**Edge cases and failure modes:**

| Case | Behaviour |
|---|---|
| Order not editable | WooCommerce shows no Remove link, so there is no dialog |
| Row `cancelled`, `refunded` or `removed` | Code not in `coupons`: no dialog, plain WooCommerce removal |
| Points discount and reward lines on one order | One dialog per line. Only that line's row moves |
| Order customer reassigned | Lock and restore on the row's `user_id` |
| Restore sent twice / request replayed | Second call: row not `applied`, skipped `nothing_to_move` |
| `[ redemption, id ]` key already spent | Skipped `key_spent`. Failure note and `Log::error()`; line still removed |
| Ledger write throws | Same as above |
| Permanent order delete | Listener is attached only inside the remove-coupon request, so nothing happens |
| REST v3 update that keeps an existing reward line | Allowed: the order has its `applied` row |
| Recalculate | Not validated, so lines and amounts hold |

**Decision record:** the implementing PR adds `docs/decisions/2026-11-02-admin-removes-loyalty-coupon.md`. It supersedes [2026-10-30-lite-writes-every-redemption-as-an-applied-discount](../decisions/2026-10-30-lite-writes-every-redemption-as-an-applied-discount.md) whole, and restates its rules with the status `removed` and the admin-removal restore rule added. The 2026-10-30 record's Status becomes "superseded by" the new one.

## Alternatives considered

| Option | Why rejected |
|---|---|
| A YayLoyalty REST route that removes the coupon itself, then `reload_items()` | Duplicates `WC_AJAX::remove_order_coupon()` (taxable address, notes, usage count) and drifts as WooCommerce changes |
| Restore through REST first, then let WooCommerce remove | Two requests. A failure in between returns points while the discount stays on the order |
| Hook `woocommerce_order_removed_coupon` | Needs WooCommerce 10.8; the floor is 10.0 |
| Restore on every removal path (REST, code) | Merchant chose: only an explicit Restore moves points |
| Make "Don't restore" final (no restore on later cancel/refund) | Merchant chose: the row stays `applied` and the old rules apply |
| Leave a restored row `cancelled` or `refunded` | Un-cancel or a deleted refund would debit the points again |
| Refuse every loyalty code on an order | REST v3 re-applies existing lines on every `coupon_lines` update, so legitimate updates would fail |
| Show the dialog to anyone with `edit_shop_orders` | Merchant chose `yayloyalty_manage_points`, like the Loyalty points box |

## Rollout

None. There is no setting, no migration, and the existing rows are unchanged. Add a changelog line: "Removing a loyalty discount from an order now asks whether to return the customer's points."

## Open questions

None.
