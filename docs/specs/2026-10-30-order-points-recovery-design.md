# Order points recovery Design

**Status:** accepted 2026-10-30
**Related:** None
**Amended:** 2026-10-30, with the user: case 4 (the award crashed, but the order meta says the points were earned). The ledger is the truth and the order cache is dropped before reading.
**Amended:** 2026-11-03, with the user: one click, no reason field and no Manual entry flow. Every other manual grant goes to SPA Adjust points through a link in the box (`#/customers/{id}?adjust=1`). An order earns points only through customer actions; the admin only repairs an award that never completed.
**Amended:** 2026-11-03, with the user: **retry the award.** The button re-runs the standard purchase award instead of writing a separate recovery row. "Missing" is decided by the order alone. A `processing` order and an order reversed then restored are not missing. The first version (a `…:recovery` EARN row) shipped on this branch and was replaced; see Alternatives.

## Goal

On a `completed` WooCommerce order whose award never completed, a shop manager re-runs that award from the order screen with one click. The points land as the order's normal purchase earning, so every later refund, cancellation and completion treats them like any purchase points. Any other manual grant goes through SPA Adjust points, which the box links to.

## Context

- Only `completed` awards. `OrderListener::route()` stages a pending figure on `pending`/`on-hold` and does nothing on `processing`. Every handler runs inside `ListenerGuard`, which logs an exception and swallows it.
- The award (`OrderListener::on_awardable()`) awards each trigger from `yayloyalty_order_award_triggers` through `EarningService::award()`, notes each outcome on the order, then releases the staged figure, whatever the outcome. So on a `completed` order an unreleased staged figure means the award never finished.
- Pending points are order meta, `_yayloyalty_points_pending` plus the release flag `_yayloyalty_points_released_at`, and the cached `customers.pending_points`. `PendingPoints` is the only code that writes them.
- A purchase earn row carries the key `earn:{user}:order:{order_id}:purchase`, under a UNIQUE index. Once the order is reversed, that key is used up, and the order note tells the merchant to "adjust manually".
- `EarningService::award()` evaluates campaigns live at the order's date (`ContextFactory::from_order()`), with each campaign's current status and configuration.

### When an order is missing points

An order is **missing** when it is `completed`, has a customer, has no EARN row for the order (any customer), is not fully refunded, and shows one of these signals:

| Signal | What the order holds | Causes |
|---|---|---|
| A. Staged, never released | `_yayloyalty_points_pending = N`, no release flag | The award threw and `ListenerGuard` swallowed it; the plugin was inactive when the order completed; the status was changed without `woocommerce_order_status_changed` (import, direct SQL); the request died mid-award. |
| C. Earned on paper only | `_yayloyalty_points_earned = N`, no EARN row | A persistent object cache (Redis, Memcached) kept order meta that the database rolled back; ledger rows deleted by hand. |

Not missing:

| Situation | Why |
|---|---|
| Paid, not `completed` (`processing`, `on-hold`) | Only `completed` awards: the points are waiting (`awaiting_completion`). |
| Earned, then reversed (cancel, trash, refunded status), then restored | The reversal is final by design; the order note already says "adjust manually". The box links to Adjust points. |
| Award skipped on purpose (no campaign, limit, excluded customer) | The figure was released and nothing was earned. |
| Guest order assigned a customer after completion; order completed before the plugin was installed | Nothing was staged for that customer. |
| Fully refunded | Nothing is owed. |

Known false positive: an order duplicated or migrated by a tool that copies all meta carries the original's signals. Out of scope.

## Scope

**In:**
- A "Loyalty points" metabox on the order edit screen (HPOS and legacy) with a one-click retry for a missing order, and a link to SPA Adjust points otherwise.
- SPA: `#/customers/{id}?adjust=1[&points=N]` opens the customer's drawer with the Adjust points dialog open, the amount prefilled with N.
- A "Loyalty points" column on the orders list (HPOS and legacy).
- A guard so an order reassigned after it earned is not paid again to its new customer, and `pending_points` that follow a reassigned order's staged figure.
- PHPUnit integration tests (below).

**Out:**
- Customer email on retry beyond what the award itself sends.
- A "Missing points" filter, bulk retry, a customer picker for guests.
- Deducting points from the order screen.
- Detecting orders duplicated with copied meta.
- Pro-specific behaviour.

## Acceptance criteria

1. Given a `completed` order with a customer, no EARN row and `_yayloyalty_points_pending = 120` unreleased, when an admin with `yayloyalty_manage_points` opens the order, then the box says the order should have earned **120** points and shows a **Give N points** button, where N is what the award gives now (what the retry writes). When N differs from 120, the box also says "Campaigns changed since checkout: retrying gives N points now."
2. When the admin clicks it, the order's award runs as on completion: one purchase EARN row keyed `earn:{user}:order:{order_id}:purchase` with its campaign, `pending_points` released, `_yayloyalty_points_earned = N`, the usual award note, and the order note "Points award retried by {admin display name}."
3. When the award gives 0 now (the campaign was paused, archived or changed), the box shows no button. It says "No campaign gives this order points any more. At checkout it was expected to earn 120 points." and links to Adjust points with `points=120`.
3a. Signal C: given a `completed` order with a customer, no EARN row, `_yayloyalty_points_earned = 40`, the release flag set and `customers.pending_points = 40`, the order is missing. The retry writes one 40-point row, leaves `_yayloyalty_points_earned` at 40 (not 80) and sets `pending_points` to 0. The box and the REST route read the order after dropping its cached copy.
4. An order that is paid, has a customer and is neither earned nor missing shows only the link "Adjust points →" to `admin.php?page=yayloyalty#/customers/{customer_id}?adjust=1`; an earned order shows "Earned N points." and the same link. A POST for it returns 409 `yayloyalty_not_eligible` (409 `yayloyalty_order_has_points` when earned) and writes nothing. The POST takes no body. Opening the link shows the drawer with the Adjust points dialog open once; the parameters are dropped from the URL, and the dialog does not open without `yayloyalty_manage_points` or for a deleted account.
5. A second POST, or a POST racing a completion, writes nothing and returns 409 `yayloyalty_order_has_points`.
6. A POST whose award gives nothing returns 409 `yayloyalty_not_awarded` with the reason and writes nothing.
7. An order that earned 40, was cancelled and was set back to `completed` is not missing: the box shows the Adjust points link only and the column "–".
8. An order reassigned to another customer after it earned, then completed again, writes nothing for the new customer and notes "No points earned: this order's points already went to another customer." Reassigning an order with an unreleased staged figure moves that figure's `pending_points` from the old customer to the new one.
9. The box shows "Points are added when the order is completed." for an unpaid order, "N points will be added when the order is completed." (or the line without N) for a paid order that is not `completed`, and "Assign a customer in the Customer field and update the order to add points." for a guest order, with no button and no link. The box is not registered for users without `yayloyalty_manage_points`, and the REST route returns 403 for them.
10. The orders list column shows the net earned points of an earned order, a **Missing** badge for a missing order, and "–" otherwise.
11. End-to-end verification, on the local site:
    1. Add `add_filter( 'yayloyalty_award_points', function () { throw new \RuntimeException( 'test' ); } );` in a mu-plugin.
    2. Place an order as a registered customer, then complete it. Expect no points.
    3. Remove the mu-plugin. The orders list now shows **Missing**.
    4. Open the order and click **Give N points**. The customer's balance in YayLoyalty › Customers rises by N.
    5. Set the order to Processing, then back to Completed. The balance does not change.
    6. Cancel the order. The balance falls by N.

    In addition, `composer test:integration` passes, including `tests/integration/Earning/OrderPointsRetryTest.php`. Per docs/known-issues.md, confirm the test count in the output, because exit 0 alone does not prove the suite ran.

## Constraints

- Product PHP runs on PHP 7.4 (CLAUDE.md hard rule 4 and `.claude/rules/php-standards.md`).
- Only `LedgerService` writes ledger rows and balances. The retry uses the `EarningService` from `EarningService::build()`, an existing construction site; no new `LedgerService` construction site.
- Lite feature (core `includes/`). Nothing under `includes/Pro/`.
- Orders are read and written only through the WooCommerce CRUD API. Order meta is written only through `PendingPoints`, which saves meta only.
- The REST route uses `exec_write()` and `requires( Capabilities::MANAGE_POINTS )`. The actor comes from `get_current_user_id()`. The POST takes no body.
- Every string uses the `yayloyalty` text domain. Hooks and globals use the `yayloyalty_` prefix.
- The script is plain JS with no build step, enqueued only on the order edit screen and depending on `wp-api-fetch`.

## Approach

**Chosen:** retry the order's own award.

**Architecture:**
- The award body moves from `OrderListener::on_awardable()` into `OrderAward::run()`. Completion and the retry both call it, so the retry writes exactly what completion writes and every guard, reversal and refund path applies unchanged.
- The metabox and the list column read one read-only `OrderPointsState` resolver. The box previews what the award gives now with `EarningService::preview()`.
- The double-pay guard for reassigned orders sits inside `LedgerService::write_keyed()`, under the customer lock.

**Components:**

| Path | Responsibility |
|---|---|
| `includes/Engine/Earning/OrderAward.php` | New. `run( \WC_Order $order ): Outcome[]`: awards each order trigger, notes each outcome, releases the staged figure. |
| `includes/Engine/Earning/Listeners/OrderListener.php` | `on_awardable()` calls `OrderAward::run()`. |
| `includes/Engine/Earning/OrderPointsState.php` | New, read only. States `not_paid`, `guest`, `earned`, `awaiting_completion`, `missing` (figure: the staged figure, else the earned meta) and `no_points`. The box and the REST route call `PendingPoints::forget_cached()` before resolving; the column does not. |
| `includes/Engine/Earning/OrderPointsRetry.php` | New. Drops the order's cache, clears the earned projection (`PendingPoints::forget_earned()`: the ledger has no EARN row, so it is wrong), runs `OrderAward::run()`, recomputes the customer's `pending_points`, and adds "Points award retried by {admin}." when anything was awarded. |
| `includes/Engine/Earning/PendingPoints.php` | New `forget_cached()`, `forget_earned()` and `resync_customer()`. |
| `includes/Engine/Earning/Listeners/OrderCustomerChangeListener.php` | New. Recomputes `pending_points` for the old and new customer when an order with an unreleased staged figure is reassigned. |
| `includes/Services/LedgerService.php` | `write_keyed()` skips an order earn with `order_has_points` while another customer still holds live points from the order. |
| `includes/Repositories/LedgerRepository.php` | New `order_points_summary()` (`earned`, `net`) and `net_points_of_others_for_order()`. |
| `includes/Repositories/CustomerRepository.php` | New `set_pending_points()`, called only by `PendingPoints::resync_customer()`. |
| `includes/Controllers/OrderPointsRestController.php` | New. `POST yayloyalty/v1/orders/(?P<id>\d+)/points`, no body. Errors: 404 `yayloyalty_order_not_found`, 409 `yayloyalty_order_has_points`, 409 `yayloyalty_not_eligible`, 409 `yayloyalty_not_awarded`. |
| `includes/Engine/Admin/OrderPointsMetabox.php` | New. Box id `yayloyalty-order-points`, side context, only for `yayloyalty_manage_points`. |
| `includes/Engine/Admin/OrderPointsColumn.php` | New. Column `yayloyalty_points` through the four list hooks. |
| `assets/admin/order-points.js` | New. Posts through `wp.apiFetch`, shows the error inline, reloads on success. |
| `apps/admin/src/pages/customers/drawer/CustomerDrawerActions.tsx`, `…/components/CustomersAdjustPointsDialog.tsx` | `adjust=1` opens Adjust points once; `points=N` prefills the amount. |

**Data and storage:** no new tables, options, migrations or ledger qualifiers. Order meta uses the existing keys.

**Integration points:**

| Kind | Names |
|---|---|
| REST | `POST yayloyalty/v1/orders/{id}/points` |
| Metabox | `add_meta_boxes` |
| Orders list, legacy | `manage_edit-shop_order_columns`, `manage_shop_order_posts_custom_column` |
| Orders list, HPOS | `manage_woocommerce_page_wc-orders_columns`, `manage_woocommerce_page_wc-orders_custom_column` |
| Order save | `woocommerce_before_order_object_save`, `woocommerce_after_order_object_save` |
| Existing events | the award's own: `yayloyalty_ledger_recorded`, `yayloyalty_points_awarded` |

**Edge cases and failure modes:**

| Situation | Expected behaviour |
|---|---|
| Retry and completion at the same time | Both write the same purchase key; the second is a duplicate. |
| Campaign changed since checkout | The box states the checkout figure and adds "Campaigns changed since checkout: retrying gives N points now."; the button offers N. |
| Campaign paused or archived since checkout | No button; the link prefills Adjust points with the checkout figure. |
| Earning limit reached today | The award holds back as on completion; its note says so. |
| The cause of the original failure persists | The retry fails again; the POST reports the error and writes nothing. |
| Persistent object cache kept rolled-back meta | The box and REST read after `forget_cached()`. |
| Column cost | One indexed `order_id` ledger query per row, plus a refunds query for a `completed` order with no live earn. |

**Decision record:** None. No new kind of ledger movement.

## Alternatives considered

| Option | Why rejected |
|---|---|
| A separate recovery EARN row (`earn:{user}:order:{id}:recovery`, no campaign, the staged figure) | Shipped first on this branch, replaced 2026-11-03. It needed its own qualifier, a guard against later completions, figure rules for reversed and refunded orders, and reversal tweaks. Testing found double payment after a reassignment, overpayment of refunded orders and refunds that never restored. |
| Order-keyed earn idempotency (`earn:order:{id}:purchase`, no user) | Fixes reassignment at the UNIQUE index, but migrates every existing key and changes how reversals key their rows. A separate decision. |
| `LedgerService::adjust()` with `order_id` | Not idempotent, and refunds or cancellations never claw it back. |
| Manual entry on the order screen | An order earns only through customer actions; free grants belong in SPA Adjust points. |
| Column plus "Missing points" filter | Needs query hooks for both storages; heavy on large stores. |

## Tests

`tests/integration/Earning/OrderPointsRetryTest.php` (`composer test:integration`) guards ledger and money logic. Accepted by the user on 2026-11-03:
- `test_retry_awards_a_failed_award` (signal A): one standard purchase row, pending released, a second POST refused.
- `test_retry_after_stale_earned_meta` (signal C): one 40-point row, `_yayloyalty_points_earned` stays 40, `pending_points` 0.
- `test_reassigned_order_is_not_paid_twice`: the new customer gets nothing.
- `test_reversed_order_is_not_missing`: a cancelled-then-restored order is not missing and the POST writes nothing.

## Rollout

No migration and no setting. The box and the column appear after the update. Release note: "Retry a loyalty points award that failed, from the order screen."

## Open questions

None.
