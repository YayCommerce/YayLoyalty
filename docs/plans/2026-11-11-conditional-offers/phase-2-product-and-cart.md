# Phase 2: Product line and cart earn preview

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 (`PointsEngine::offers()`, `OfferLines`, `ConditionPhrases`, the evaluator's draft-Context inputs)
**Delivers:** the product line shows a member's personal figure and bonus lines; a "You'll earn" row on the classic cart and checkout and on the Cart and Checkout blocks.
**Exit criterion:** spec criteria 3, 4 and 8 hold by hand on the Local site, on Twenty Twenty-Five (block cart and checkout) and on a page with `[woocommerce_cart]` / `[woocommerce_checkout]`, as a guest, a plain customer and a `vip` customer. `composer test` still passes.

### Task 1: Facts and checkout inputs on draft Contexts, and `quote_campaign()`

**Files:**
- Modify: `includes/Engine/Points/ContextFactory.php` (`from_product()`, `from_cart()`)
- Modify: `includes/Engine/Points/PointsEngine.php` (`quote_campaign()`)

**Interfaces:**
- Consumes: `ContextFactory::customer_facts()`, `WC()->session`, `WC()->customer`
- Produces: `PointsEngine::quote_campaign( Campaign $campaign, Context $ctx ): int`, and the draft Context contents (README contract)

Steps: expand after Phase 1 lands. The checks: on the Local site, a $100 Shoes product as `vip` reads "Earn 300" from the existing line (`preview()` now matches Shoes on the product's line). A `lifetime_points gte 1` campaign matches on the product page for a customer with earned points. `composer test` passes.

### Task 2: Bonus lines on the product page

**Files:**
- Modify: `includes/Engine/Storefront/ProductEarnMessage.php` (bonus lines in `sentence()` / `markup()`, `add_variation_sentence()` adds `yayloyalty_earn_bonuses`)
- Modify: `assets/storefront/product-earn.js` (swap `yayloyalty_earn_bonuses` on `found_variation`, restore on `reset_data`)
- Modify: `assets/storefront/product-earn.css` (`.yayloyalty-product-earn__bonus`)
- Modify: `includes/Engine/Storefront/LauncherCopy.php` (`product_bonus( int $points, string $teaser ): string`, "Earn {points} {points_label} {teaser}")

**Interfaces:**
- Consumes: `OfferLines::for_trigger()` with `ContextFactory::from_product( $product, 1, $user_id )` as the viewer (null for a guest) and `ContextFactory::for_advert()` as the advert; `PointsEngine::quote_campaign()` (Task 1)
- Produces: variation data key `yayloyalty_earn_bonuses`

Steps: expand after Phase 1 lands. Include the Review focus check: a variable product where only one variation is in Shoes clears the bonus area when the other is picked, and restores the default on "Clear".

### Task 3: Cart and checkout earn preview

**Files:**
- Create: `includes/Engine/Storefront/CartEarnPreview.php`
- Create: `includes/Engine/Storefront/templates/cart-earn-row.php`
- Create: `assets/storefront/cart-earn-blocks.js`
- Create: `assets/storefront/cart-earn.css`
- Modify: `includes/Engine/Register/ScriptName.php` (`CART_EARN_BLOCKS_SCRIPT`)
- Modify: `includes/YayLoyalty.php` (`CartEarnPreview::register()` next to `ProductEarnMessage::register()`)
- Modify: `includes/Helpers/DesignSchema.php`, `apps/admin/src/lib/schema/design.schema.ts`, `apps/admin/src/pages/settings/DesignVisibilitySection.tsx`, `DesignStorefrontTextSection.tsx` (`show_cart_earn_preview`, `cart_earn_line`)

**Interfaces:**
- Consumes: `EarningService::build()->preview()`, `PointsEngine::evaluate()`, `OfferLines::for_trigger()`, `quote_campaign()`, `ContextFactory::from_cart()`, the `ProductEarnMessage` guest sign-in line, the Store API registration pattern in `Engine\Redemption\Adapters\StoreApiAdapter`, and the slot-fill pattern in `assets/storefront/redeem-blocks.js`
- Produces: `CartEarnPreview` and the two design keys (README contract)

Steps: expand after Phase 2 Task 2 lands. Include the Review focus check: an empty cart, and a $5 cart under a `min_subtotal` of 10 with no bonus, render no row on classic and block. The block row updates after a quantity change without a reload.
