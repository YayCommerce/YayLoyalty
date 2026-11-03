# Phase 2: Orders list column

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 Task 2 (`OrderPointsState`) and Task 4 (the box the column leads to)
**Delivers:** a "Loyalty points" column on WooCommerce › Orders, for HPOS and legacy storage. Each cell shows the net earned points, a **Missing** badge, or "–".
**Exit criterion:** spec acceptance 11 passes in full on the local site, step 3 included: after the failed award, the orders list shows **Missing** for that order. The cell shows N after recovery and "–" after the cancel. `composer test:integration` still prints `OK`.

### Task 1: `OrderPointsColumn`

**Files:**
- Create: `includes/Engine/Admin/OrderPointsColumn.php`
- Modify: `includes/YayLoyalty.php` (the admin branch, next to `OrderPointsMetabox::register()`)

**Interfaces:**
- Consumes: `OrderPointsState::resolve()`, `get_status()` and `get_net_earned()` (README contract)
- Produces: column key `yayloyalty_points`

**Decisions fixed now:**
- Hooks:
  - `manage_edit-shop_order_columns` and `manage_woocommerce_page_wc-orders_columns` add the column after `order_status`, titled "Loyalty points".
  - `manage_shop_order_posts_custom_column` (`$column, $post_id`) and `manage_woocommerce_page_wc-orders_custom_column` (`$column, $order`) render it.
- The column is added only for users with `yayloyalty_manage_points`.
- The cell shows the net earned points when the state is `EARNED` or `RECOVERED`. It shows `<mark class="order-status status-on-hold"><span>Missing</span></mark>` when the state is `MISSING_KNOWN`, and "–" otherwise.
- Cost: one `OrderPointsState::resolve()` per row, which runs at most two indexed `order_id` queries.

Steps: expand after Phase 1 lands.

### Task 2: Close the plan

**Files:**
- Modify: `docs/plans/2026-10-30-order-points-recovery/README.md` (Status `done YYYY-MM-DD`, both phases `done`)

Steps: expand after Phase 1 lands.
