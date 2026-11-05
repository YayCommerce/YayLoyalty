# Phase 4: Cart nudge

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 3 Tasks 1 to 3. Rebase on `paul/feat/guest-order-points` first if it has merged: it adds the first caller of `from_cart()` and a cart line in the same area.
**Delivers:** A shopper one item away from a bonus sees it on the classic cart and checkout and the Cart and Checkout blocks.
**Exit criterion:** the spec's criterion 18 walkthrough passes on a Pro site, and `./scripts/run.sh release lite --no-prompt` passes (then `composer install`).

### Task 1: The Lite seam and classic output

**Files:**
- Create: `includes/Engine/Storefront/CartEarnNudge.php`
- Modify: `includes/YayLoyalty.php` (register next to `ProductEarnMessage::register()`, line 60), `assets/storefront/redeem.css`

**Interfaces:**
- Consumes: `visibility.show_bonus_offers`
- Produces: filter `yayloyalty_cart_earn_nudge` (see README contract)

`text( \WC_Cart $cart ): string` returns '' for an empty cart, behind `DesignHelper::surface_enabled()`, and catches `\Throwable`. Prints on `woocommerce_before_cart_totals` and `woocommerce_review_order_before_payment`, escaped, and prints nothing for ''.

Steps: expand after Phase 3 lands.

### Task 2: Block output

**Files:**
- Modify: `includes/Engine/Redemption/Adapters/StoreApiAdapter.php`, `assets/storefront/redeem-blocks.js`

**Interfaces:**
- Consumes: `CartEarnNudge::text()`
- Produces: `cart_data()` key `nudge` (see README contract)

`nudge` in both return shapes of `cart_data()` and in `cart_schema()`. `redeem-blocks.js` renders it as a text node, never `dangerouslySetInnerHTML`, in the same fill above the card, and `Card` no longer returns null while a nudge exists.

Steps: expand after Phase 3 lands.

### Task 3: Pro answers the filter

**Files:**
- Create: `includes/Pro/Storefront/CartOfferNudge.php`
- Modify: `includes/Pro/YayLoyaltyPro.php`, `docs/architecture.md`

**Interfaces:**
- Consumes: `PointsEngine::advertised_offers()`, `ConditionsEvaluator::explain()`, `ConditionalOfferCopy::basket_names()`, `ContextFactory::from_cart()`, filter `yayloyalty_cart_earn_nudge`
- Produces: none

Calls `advertised_offers()` with `ContextFactory::from_cart( $cart, $user_id > 0 ? $user_id : null )`, so a cart that already meets a campaign stops the walk. Runs `ConditionsEvaluator::explain()` on each offer with that Context and takes the first where a `category_in_cart` or `product_in_cart` condition is unmatched and every other condition is matched or `needs_order` (spec criteria 16 and 17). Text: "Add an item from %1$s to earn %2$s on this order.", with `LauncherCopy::earn_rate()` for `%2$s`. The final step sets the plan's Status to `done` and adds the release note on `show_bonus_offers` to the PR description.

Steps: expand after Phase 3 lands.
