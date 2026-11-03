# Order points recovery Design

**Status:** accepted 2026-10-30
**Related:** None
**Amended:** 2026-10-30, with the user: case 4 (the award crashed, but the order meta says the points were earned). The ledger is the truth, the order cache is dropped before reading, `_yayloyalty_points_earned` is restated rather than added to, and `customers.pending_points` is recomputed from order meta.
**Amended:** 2026-11-03, with the user: the order screen recovers only missing points whose figure is known (`missing_known`), with one click and no reason. There is no Manual entry flow: every other manual grant, including `missing_unknown`, goes to SPA Adjust points through a link in the box that opens the customer's drawer with the Adjust points dialog open (`#/customers/{id}?adjust=1`). An order earns points only through customer actions; the admin only repairs a missing award.

## Goal

On a paid WooCommerce order whose points never reached the customer's balance, and where the plugin knows how many points the order should have earned, a shop manager restores them from the order screen with one click. Any other manual grant goes through SPA Adjust points, which the box links to. The points are stored as that order's earning, so a later refund or cancellation claws them back like any purchase points.

## Context

- Only `completed` awards. `OrderListener::route()` (`includes/Engine/Earning/Listeners/OrderListener.php:104-127`) stages a pending figure on `pending`/`on-hold` and does nothing on `processing`. Every handler runs inside `ListenerGuard`, which logs an exception and swallows it.
- The ledger has no row status. Pending points are only order meta, `_yayloyalty_points_pending` plus the release flag `_yayloyalty_points_released`, and the cached `customers.pending_points`. `PendingPoints` (`includes/Engine/Earning/PendingPoints.php`) is the only code that writes them. `release()` sets the flag and never deletes the figure.
- A purchase earn row carries the key `earn:{user}:order:{order_id}:purchase` (`IdempotencyKey::compose()`), which sits under a UNIQUE index. Once the order is reversed, that key is used up, and `OrderListener::note()` tells the merchant to "adjust manually".
- Reversals find earn rows with `LedgerRepository::earn_rows_for_order()` and key their clawbacks on the earn row's qualifier (`LedgerService::reversal_key()`).
- Manual adjustments (`LedgerService::adjust()`, `POST yayloyalty/v1/customers/{id}/adjust`) write unkeyed ADJUST rows with no `order_id`.
- The plugin adds no metabox, no order-list column and no admin script outside the SPA. On the order screen it writes only order notes.
- Ledger single-writer record: `docs/decisions/2026-10-01-ledger-service-sole-writer.md`. A new kind of movement is a new `LedgerService` method.

### Four ways the points go missing

| Case | Order meta and ledger left behind |
|---|---|
| 1. Stuck pending | ~~Paid order left at `processing`.~~ Not a missing case since 2026-11-03: only `completed` awards, so a `processing` order with a staged figure is waiting (`awaiting_completion`), and completing it awards the points. |
| 2. Award threw | Order is `completed`, the award failed and `ListenerGuard` swallowed it. The staged figure is unreleased and there is no earn row. |
| 3. Reversed then restored | Order was cancelled or trashed, then restored to a paid status. Its earn rows are fully clawed back and the purchase key is used up. |
| 4. Earned on paper only | The server failed while completing. `_yayloyalty_points_earned = N` (and possibly `_yayloyalty_points_released`) is visible on the order, but there is no EARN row, so the balance lacks N and `customers.pending_points` may still hold N. Causes: `Transaction` rolls back the database but not the object cache, so a persistent object cache (Redis, Memcached) can keep the rolled-back order meta; or the tables are not transactional. |

## Scope

**In:**
- A "Loyalty points" metabox on the order edit screen, for both HPOS and legacy storage, with a one-click Confirm flow, and a link to SPA Adjust points for any other manual grant.
- One SPA change: `#/customers/{id}?adjust=1` opens the customer's drawer with the Adjust points dialog open.
- A once-per-order recovery EARN row written by a new `LedgerService::recover_order()`.
- A guard so the normal purchase award skips an order that already has a recovery row.
- An order note on recovery.
- Case 4: the ledger is the truth. Order meta that claims earned points without an EARN row counts as missing. Recovery drops the order's cached copy first, restates `_yayloyalty_points_earned`, and recomputes `customers.pending_points` from order meta.
- A "Loyalty points" column on the orders list, for both HPOS and legacy storage.
- Guest orders: the box asks the admin to assign a customer through WooCommerce's own Customer field first.
- PHPUnit integration tests (below).

**Out:**
- Customer email on recovery.
- A "Missing points" filter on the orders list.
- Bulk recovery.
- A customer picker in the box, or matching by billing email.
- Recomputing points with the earning engine.
- Editing the prefilled figure in Confirm mode.
- Deducting points from the order screen.
- A second recovery on the same order. After that, the admin uses SPA Adjust points.
- Any other SPA (React) change.
- A Manual entry flow (points and reason typed on the order screen), and a reason on Confirm.
- Pro-specific behaviour.

## Acceptance criteria

1. Given a `completed` order with a customer, no live earn row and `_yayloyalty_points_pending = 120` unreleased, when an admin with `yayloyalty_manage_points` opens the order, then the box says the order should have earned **120** points and shows a **Give 120 points** button. There is no reason field.
2. When the admin clicks it, then in one transaction:
   - one EARN row is written with `points=120`, `source=order`, `reference_type=order`, `reference_id=order_id`, `order_id` set, `campaign_id=NULL`, `actor_id=admin`, `note = "Recovered missing order points"` and `idempotency_key = earn:{user}:order:{order_id}:recovery`;
   - the customer's `balance` and `lifetime_earned` rise by 120;
   - `customers.pending_points` is recomputed from order meta, so it falls by 120;
   - `_yayloyalty_points_released` is set and `_yayloyalty_points_earned` is set to 120 (restated, never added to).

   After commit, the order note "120 points added manually by {admin display name}: Recovered missing order points." is added.
3. Given an order whose earn rows are all fully clawed back (reversed then restored) and that is now paid, the Confirm figure is the sum of those earn rows' points when no unreleased staged figure exists.
3a. Case 4: given a paid order with a customer, no EARN row, `_yayloyalty_points_earned = 40`, `_yayloyalty_points_released` set and `customers.pending_points = 40`, the state is `missing_known` with figure 40, and the column shows **Missing**. Recovery then writes one 40-point EARN row, leaves `_yayloyalty_points_earned` at 40 (not 80), and sets `customers.pending_points` to 0. The box and the REST route read the order after dropping its cached copy.
4. Given a paid order with a customer, no live earn and no figure above 0 (`missing_unknown`), the box shows no button, only the link "Adjust points →" to `admin.php?page=yayloyalty#/customers/{customer_id}?adjust=1`. The `earned` and `recovered` boxes show the same link under their line. A POST for such an order returns 409 `yayloyalty_not_eligible` and writes nothing. The POST takes no body: no request field sets the points or the note. Opening the link shows the customer's drawer with the Adjust points dialog open, once: the `adjust` parameter is dropped from the URL, and the dialog does not open without `yayloyalty_manage_points` or for a deleted account.
5. A second POST for an order that already has a recovery row writes nothing. It returns 409 `yayloyalty_already_recovered`, and the box then shows "N points added manually by {admin} on {date}: {note}." (older rows may carry a reason typed before 2026-11-03)
6. A POST for an order with a live earn row (any EARN row whose clawbacks are below its points) returns 409 `yayloyalty_order_has_points` and writes nothing. The check runs under the customer lock.
7. Given a recovery row exists, paid to any customer (the order may have been reassigned since), when the order later reaches `completed` or `completed` fires again, then no purchase EARN row is written. The award is skipped with reason `recovered_manually`, and the order note says "No points earned: points were recovered manually."
8. Given a recovery row exists, when the order is cancelled, then a `refund_earn` row of −N is written with key `refund_earn:{user}:order:{order_id}:recovery`. A partial refund claws back by the share of every product line, and no "campaign is gone" warning is logged for recovery rows.
9. The box shows "Points are added when the order is completed." for an unpaid order, "N points will be added when the order is completed." (or, with no staged figure, the line without N) for a paid order that is not `completed` and has no live earn, and "Assign a customer in the Customer field and update the order to add points." for a guest order. Neither shows a button or the Adjust points link. The box is not registered for users without `yayloyalty_manage_points`, and the REST route returns 403 for them.
10. The orders list "Loyalty points" column shows the net earned points of an order with a live earn. It shows a **Missing** badge when the order is `completed`, has a customer, has no live earn, and either has an unreleased staged figure, had all its earn rows reversed, or has `_yayloyalty_points_earned > 0` with no EARN row. Otherwise it shows "–". An order whose award was skipped on purpose shows "–".
11. End-to-end verification, on the local site:
    1. Add `add_filter( 'yayloyalty_award_points', function () { throw new \RuntimeException( 'test' ); } );` in a mu-plugin.
    2. Place an order as a registered customer, then complete it. Expect no points.
    3. Remove the mu-plugin. The orders list now shows **Missing**.
    4. Open the order and click **Give N points**. The customer's balance in YayLoyalty › Customers rises by N.
    5. Set the order to Processing, then back to Completed. The balance does not change.
    6. Cancel the order. The balance falls by N.

    In addition, `composer test:integration` passes, including `tests/integration/Earning/OrderPointsRecoveryTest.php`. Per docs/known-issues.md, confirm the test count in the output, because exit 0 alone does not prove the suite ran.

## Constraints

- Product PHP runs on PHP 7.4 (CLAUDE.md hard rule 4 and `.claude/rules/php-standards.md`).
- Only `LedgerService` writes ledger rows and balances. `OrderPointsRecovery` receives the `LedgerService` built in `Engine\RestAPI::controllers()`, which is an existing listed construction site. No new construction site.
- Lite feature (core `includes/`). Nothing under `includes/Pro/`.
- Orders are read and written only through the WooCommerce CRUD API. Order meta is written only through `PendingPoints`, which saves meta only and never does a full save.
- The REST route uses `exec_write()` and `requires( Capabilities::MANAGE_POINTS )`. The actor comes from `get_current_user_id()`, never from the request.
- Points are integers from 1 to 1,000,000, taken from the state's figure, never from the request. The note is always the default "Recovered missing order points".
- Every string uses the `yayloyalty` text domain. Hooks and globals use the `yayloyalty_` prefix.
- Order notes and hooks that call out are written from `Transaction::after_commit()`.
- The script is plain JS with no build step, enqueued only on the order edit screen and depending on `wp-api-fetch`.

## Approach

**Chosen:** a new ledger method, a recovery service and an order-screen metabox.

**Architecture:**
- The metabox and the list column are rendered in PHP and read state from one read-only `OrderPointsState` resolver.
- The metabox's button posts to a new REST route. `OrderPointsRecovery` runs `LedgerService::recover_order()` and the `PendingPoints` release and projection in one transaction.
- The double-award guard sits inside `LedgerService`, under the customer lock, so it holds against a concurrent completion.

**Components:**

| Path | Responsibility |
|---|---|
| `includes/Services/LedgerService.php` | New `recover_order( int $user_id, int $order_id, int $points, string $note, int $actor_id ): Outcome`. Under the lock it returns `skipped( REASON_ORDER_HAS_POINTS = 'order_has_points' )` when a live earn exists. It writes the keyed EARN row through `write_keyed()` and returns `duplicate` when the key exists. `earn()` gains the guard: for a draft with `order_id` and qualifier `purchase`, a recovery row for the order, whatever its user, gives `skipped( REASON_RECOVERED = 'recovered_manually' )`. |
| `includes/Engine/Earning/IdempotencyKey.php` | Adds `RECOVERY = 'recovery'`, valid only for `earn`/`refund_earn`. Not added to `CampaignTrigger`. |
| `includes/Engine/Earning/OrderPointsState.php` | New, read only. Given a `WC_Order`, returns state `not_paid`, `guest`, `earned`, `recovered`, `awaiting_completion` (paid, not `completed`, no live earn; amended 2026-11-03), `missing_known` or `missing_unknown`, plus the figure, net earned and recovery row. Uses `LedgerRepository`, `PendingPoints::unreleased()` and `_yayloyalty_points_earned`. The box and the REST route call `PendingPoints::forget_cached()` before resolving; the column does not. |
| `includes/Engine/Earning/OrderPointsRecovery.php` | New. Calls `PendingPoints::forget_cached()` and re-reads the order. In one `Transaction::run` it runs `recover_order()`, `PendingPoints::release()`, `PendingPoints::restate_earned()` and `PendingPoints::resync_customer()`, then adds the order note after commit. |
| `includes/Engine/Earning/PendingPoints.php` | New `forget_cached( int $order_id ): void`, which drops WooCommerce's cached copy of the order and its meta: `clean_post_cache()`, plus `OrderCache::remove()` when `OrderUtil::orders_cache_usage_is_enabled()`. New `restate_earned( int $order_id, LedgerEntry $entry ): void`, which sets `_yayloyalty_points_earned` to the entry's points and `_yayloyalty_campaign_id` as `mark_awarded()` does. New `resync_customer( int $user_id ): int`, which sets `customers.pending_points` to the sum of `_yayloyalty_points_pending` over that customer's orders without `_yayloyalty_points_released`, and returns it. |
| `includes/Repositories/CustomerRepository.php` | New `set_pending_points( int $user_id, int $points ): void` (floored at 0), called only by `PendingPoints::resync_customer()`. |
| `includes/Controllers/OrderPointsRestController.php` | New. `POST yayloyalty/v1/orders/(?P<id>\d+)/points` (no GET: the box renders in PHP). The POST takes no body and recovers only `missing_known`, with the state's figure and the default note. Errors: 404 `yayloyalty_order_not_found`, 409 `yayloyalty_not_eligible` (not paid, guest or `missing_unknown`), 409 `yayloyalty_order_has_points`, 409 `yayloyalty_already_recovered`. Registered in `Engine\RestAPI::controllers()`. |
| `includes/Engine/Admin/OrderPointsMetabox.php` | New. `add_meta_boxes` registers id `yayloyalty-order-points` on `wc_get_page_screen_id( 'shop-order' )`, context `side`, only when the user has `yayloyalty_manage_points`. Enqueues the script on that screen. |
| `includes/Engine/Admin/OrderPointsColumn.php` | New. Adds the column `yayloyalty_points` through the four list hooks (below). |
| `assets/admin/order-points.js` | New. Posts through `wp.apiFetch`, shows the error inline, and reloads on success. |
| `apps/admin/src/pages/customers/drawer/CustomerDrawerActions.tsx` | Opens the Adjust points dialog once when the URL has `adjust=1`, then drops the parameter. |
| `includes/Engine/Earning/Listeners/OrderListener.php` | `note()` writes "No points earned: points were recovered manually." for `recovered_manually`. |
| `includes/Engine/Earning/Reversal.php` | `bases()` skips the "campaign is gone" warning when the row's qualifier is `recovery`. |
| Composition | The admin classes attach in their `register()`, called once from the existing admin composition root in `includes/YayLoyalty.php`. |

**Data and storage:**
- No new tables, options or migrations.
- New ledger rows use existing columns. Order meta uses the existing keys `_yayloyalty_points_pending`, `_yayloyalty_points_released` and `_yayloyalty_points_earned`.
- Nothing extra to remove on uninstall.

**Integration points:**

| Kind | Names |
|---|---|
| REST | `POST yayloyalty/v1/orders/{id}/points` |
| Metabox | `add_meta_boxes` |
| Orders list, legacy | `manage_edit-shop_order_columns`, `manage_shop_order_posts_custom_column` |
| Orders list, HPOS | `manage_woocommerce_page_wc-orders_columns`, `manage_woocommerce_page_wc-orders_custom_column` |
| Script | `admin_enqueue_scripts`, gated to the order edit screen |
| Existing event | `yayloyalty_ledger_recorded` fires for the recovery row, so the dashboard cache and activity update |
| Not fired | `yayloyalty_points_awarded`: the row came from no campaign evaluation |

**UI:** the seven box states are in acceptance criteria 1, 4, 5, 9 and 10. Only `missing_known` has a button; `earned`, `recovered` and `missing_unknown` show the Adjust points link. The column cell is one line: "N", a **Missing** badge, or "–".

**Edge cases and failure modes:**

| Situation | Expected behaviour |
|---|---|
| Recovery and completion run at the same time | Both hold the customer lock. Whichever commits second gets `recovered_manually` or `order_has_points`. |
| Customer reassigned after staging | Points go to the current customer, the same as `release()`. |
| Customer reassigned after a recovery, then the order completes | No purchase row for the new customer: the guard finds the recovery by order, not by user. |
| Customer reassigned after the order earned, then it completes again | No earn row for the new customer: `write_keyed()` skips any order earn with `order_has_points` while another customer still holds live points from the order, and the note says "No points earned: this order's points already went to another customer." Once those points are clawed back in full, the order earns for its new customer. Amended 2026-11-03. |
| Customer reassigned while a staged figure is unreleased | `OrderCustomerChangeListener` recomputes `pending_points` for the old and the new customer from order meta after the save, so the figure moves with the order. Amended 2026-11-03. |
| Double click | 409 `yayloyalty_already_recovered`. |
| Pro order triggers other than `purchase` | Unaffected by the guard. |
| Recovery row clawed back, then the order restored | Box state `recovered`, with "…reversed; use YayLoyalty › Customers › Adjust points." No second recovery. |
| Order trashed | Not paid, so no button. |
| Column cost | One indexed `order_id` ledger query per row, at most the page size. |
| Persistent object cache kept rolled-back order meta (case 4) | The box and REST read after `forget_cached()`, so they see the database. The column reads the cache, and stale "earned" meta with no EARN row still shows **Missing**. |
| `pending_points` drifted from order meta | Recovery recomputes it for that customer from order meta (the truth per `PendingPoints`), so a release that really happened is never subtracted twice. |
| Released staged figure with no EARN row and no earned meta (an award skipped on purpose) | `missing_unknown`: no button, the Adjust points link, and the column shows "–". |

**Decision record:** None. It adds a `LedgerService` method, which the single-writer record (`2026-10-01-ledger-service-sole-writer`) prescribes.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Re-run `EarningService::award()` with a force flag | The user chose the staged figure, not a recomputation. It cannot do free entry, and it fights the used purchase key on reversed orders. |
| `LedgerService::adjust()` with `order_id` | Not idempotent, and refunds or cancellations never claw it back. Contradicts "stored as the order's earning". |
| Repeatable recovery | Not idempotent. SPA Adjust points already covers repeat grants. |
| Customer picker or billing-email match for guests | WooCommerce's own Customer field already reassigns the order. |
| Column plus "Missing points" filter | Needs query hooks for both HPOS and legacy storage, and is heavy on large stores. |
| Editable Confirm figure | The user chose the staged figure as is. SPA Adjust points covers other amounts. |
| Manual entry flow on the order screen | Rejected 2026-11-03: an order earns only through customer actions, and the admin only repairs a known missing award. Free grants belong in SPA Adjust points, which has its own preview and history. |

## Tests

`tests/integration/Earning/OrderPointsRecoveryTest.php` (`composer test:integration`) guards ledger and money logic:
- Recovery writes the keyed EARN row and moves `balance`, `lifetime_earned` and `pending_points`.
- A second call is a duplicate and writes nothing.
- Recovery is refused when a live earn exists.
- A later purchase award is skipped with `recovered_manually`.
- Cancelling writes the `refund_earn …:recovery` clawback.
- `test_recovery_after_stale_earned_meta` (case 4): an order with `_yayloyalty_points_earned = 40`, released set, no EARN row and `pending_points = 40` resolves to `missing_known`/40. A POST writes one 40-point row, the meta stays at 40 and `pending_points` becomes 0. Accepted 2026-10-30.

The user accepted this test on 2026-10-30.

## Rollout

No migration and no setting. The box and the column appear after the update. Release note: "Recover missing loyalty points from the order screen."

## Open questions

None.
