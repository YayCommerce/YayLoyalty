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

- [ ] **Step 1: Give `from_product()` and `from_cart()` the facts loader**

In `public static function from_product( \WC_Product $product, int $qty = 1, ?int $user_id = null ): Context` and `public static function from_cart( \WC_Cart $cart, ?int $user_id = null ): Context`, read `$now = Dates::now()` once and pass it as `occurred_at`. For `$user_id > 0` pass `static fn() => self::customer_facts( $user_id, $now )` as the loader; a guest gets none (null). The loader stays lazy, so a page that evaluates no history condition reads no facts.

- [ ] **Step 2: Give `from_cart()` the checkout inputs**

Add the `$extra` argument: `Context::EXTRA_PAYMENT_METHOD` from `WC()->session->get( 'chosen_payment_method' )`, `EXTRA_BILLING_COUNTRY` from `WC()->customer->get_billing_country()` and `EXTRA_SHIPPING_COUNTRY` from `WC()->customer->get_shipping_country()`, each `(string)` and `''` when the session or customer is absent. Read them in a private `checkout_inputs(): array`.

- [ ] **Step 3: Implement `public function quote_campaign( Campaign $campaign, Context $ctx ): int` in `PointsEngine`**

The handler for `$campaign->get_trigger()`; 0 when `! $handler->is_eligible( $campaign, $ctx )`; otherwise `$this->caps->apply( $handler->compute_points( $campaign, $ctx )->get_points(), $campaign )`. It does not check conditions or liveness: a surface quotes a bonus that `offers()` already chose, to say what it would pay on this cart or product. Throws `\InvalidArgumentException` for `Context::advert()`, like `evaluate()`. Document it in the class docblock next to `offers()`.

- [ ] **Step 4: Verify on the Local site**

Run: `cd /Users/nguyentruong/Valet/brandy && php <scratchpad>/phase2/task1.php`. The script injects, through `yayloyalty_campaign_candidates`, "Shoes" (purchase, 30 per unit, `category_in_cart in [the product's own category]`, priority 20), "VIP" (20 per unit, `user_role in [customer]`, priority 10) and "Earned" (40 per unit, `lifetime_points gte 1`, priority 30, only when `CASE=earned`), and prints `ProductEarnMessage::sentence()` for a simple product as a guest, a customer and an administrator, and `quote_campaign()` for each injected campaign on that product's Context.
Expected: the customer reads 30 × price (Shoes matched on the product's line), the administrator 30 × price, the guest 30 × price. With `CASE=earned`, a customer with earned points reads 40 × price and the guest stays at 30 × price. Each `quote_campaign()` is rate × price.

- [ ] **Step 5: Run the unit suite**

Run: `composer test`
Expected: OK, every test passes.

- [ ] **Step 6: Lint the changed files**

Run: `composer lint -- includes/Engine/Points/ContextFactory.php includes/Engine/Points/PointsEngine.php`
Expected: no reports for these files.

- [ ] **Step 7: Commit**

Stage: `includes/Engine/Points/ContextFactory.php`, `includes/Engine/Points/PointsEngine.php`
Message: `feat(engine): quote one campaign and read customer facts on product and cart contexts`

### Task 2: Bonus lines on the product page

**Files:**
- Modify: `includes/Engine/Storefront/ProductEarnMessage.php` (bonus lines in `sentence()` / `markup()`, `add_variation_sentence()` adds `yayloyalty_earn_bonuses`)
- Modify: `assets/storefront/product-earn.js` (swap `yayloyalty_earn_bonuses` on `found_variation`, restore on `reset_data`)
- Modify: `assets/storefront/product-earn.css` (`.yayloyalty-product-earn__bonus`)
- Modify: `includes/Engine/Storefront/LauncherCopy.php` (`product_bonus( int $points, string $teaser ): string`, "Earn {points} {points_label} {teaser}")
- Modify: `includes/Engine/Points/PointsEngine.php` (`offers()`: each bonus also carries the evaluator's `results`)
- Modify: `includes/Engine/Storefront/OfferLines.php` (the memo key tells apart viewer Contexts with different lines)

**Interfaces:**
- Consumes: `OfferLines::for_trigger()` with `ContextFactory::from_product( $product, 1, $user_id )` as the viewer (null for a guest) and `ContextFactory::for_advert()` as the advert; `PointsEngine::quote_campaign()` (Task 1)
- Produces: variation data key `yayloyalty_earn_bonuses`

Two additions to Phase 1 code, both needed by the product page:
- Criterion 3 drops the Shoes line on a product outside Shoes, for a guest and for `vip`. Only the evaluator's per-condition rows say that the failing condition is this product's own category, so `offers()` keeps them on each bonus as `results` (the `explain()` rows, unchanged), and the product page asks the bonus's conditions about this product's line: the viewer Context is `from_product( $product, 1, $user_id )`, and for a guest `from_product( $product, 1, null )`, which still depends only on the product, the time and the currency. A guest's customer conditions answer `no_customer` there, so they stay `locked`, as on the advert.
- `OfferLines::for_trigger()` memoises by trigger and user id, so two products, or two variations, in one request would share the first one's answer. The key adds a fingerprint of the viewer's lines, subtotal and checkout inputs; a Context with no lines (`from_user()`) keeps one key per user, so Ways to earn still evaluates once.

- [ ] **Step 1: Carry `results` on each bonus in `PointsEngine::offers()`**

Each bonus becomes `array{campaign: Campaign, value: float, status: string, results: array}`, `results` being the `explain()` rows its status was folded from. Update the `@return` of `offers()` and of `OfferLines::for_trigger()`.

- [ ] **Step 2: Key the `OfferLines` memo by the viewer's contents**

Add `private static function viewer_key( ?Context $viewer ): string`: `'guest'` for null, else the user id (0 for none) plus `md5( wp_json_encode( … ) )` of each line's product id, variation id, quantity and subtotal, the order subtotal and the three `EXTRA_*` inputs. `for_trigger()` uses it in place of the user id.

- [ ] **Step 3: Add `LauncherCopy::product_bonus( int $points, string $teaser ): string`**

`points_sentence_html( __( 'Earn {points} {points_label} {teaser}', 'yayloyalty' ), $points )` with a translators comment, then `{teaser}` replaced by `esc_html( $teaser )`. Escaped HTML, the points in `<strong>`.

- [ ] **Step 4: Bonus lines in `ProductEarnMessage`**

- A private memo `lines( \WC_Product $product ): array{sentence: string, bonuses: string}` replaces `$sentences`; `sentence()` keeps its signature and returns `lines()['sentence']`. A variable product takes both from its cheapest variation that earns.
- `public static function bonus_lines( \WC_Product $product ): string`: '' when `sentence()` is ''. Otherwise `OfferLines::for_trigger( self::engine(), CampaignTrigger::PURCHASE, self::advert(), $viewer )` with `$viewer = ContextFactory::from_product( $product, 1, $user_id > 0 ? $user_id : null )`, where `self::advert()` is `ContextFactory::for_advert( Dates::now() )` read once per request. For each bonus: skip it when a `results` row of type `product_in_cart` or `category_in_cart` did not match (the product's Context has a line, so those are always compared, and a miss means this product can't earn it); `quote_campaign( campaign, $viewer )`, capped for a member by `EarningService::points_left_today()` like the figure; keep it when greater than the figure. Each kept line is `<p class="yayloyalty-product-earn__bonus">` + `LauncherCopy::product_bonus( $quote, $bonus['teaser'] )`. A `Throwable` is logged with `Log::warning( 'ProductEarnMessage', … )` and gives no bonus lines; the figure stays.
- `markup()` prints `<div class="yayloyalty-product-earn__bonuses" data-default="…">` after the line and before the guest line, `hidden` when empty, so a variation can fill it.
- `add_variation_sentence()` adds `$data['yayloyalty_earn_bonuses'] = self::bonus_lines( $variation )`.
- `guest_line()` becomes `public static function guest_line( string $css_class = self::CSS_CLASS ): string`, for the cart row (Task 3).

- [ ] **Step 5: Swap the bonus area in `assets/storefront/product-earn.js`**

On `found_variation`, when `variation.yayloyalty_earn_bonuses` is a string, put it in `.yayloyalty-product-earn__bonuses` and hide that element when it is ''. On `reset_data`, restore its `data-default`.

- [ ] **Step 6: Style `.yayloyalty-product-earn__bonus` in `assets/storefront/product-earn.css`**

Muted, past the icon like `__guest` (`padding-left: 26px`), the points in `<strong>` at weight 600.

- [ ] **Step 7: Verify criterion 3 on the Local site**

Run: `cd /Users/nguyentruong/Valet/brandy && php <scratchpad>/phase2/task2.php`. The script injects Shoes (30 per unit, the category of product A, priority 20) and VIP (20 per unit, `user_role in [customer]`, priority 10) over the live 10-per-unit campaign, picks product A (simple, in that category) and product B (simple, outside it), and prints the sentence, the bonus lines and the guest line of each as a guest, a customer (stands for `vip`) and an administrator (stands for a non-VIP member).
Expected:
- A as customer: 30 × price, no bonus line. B as customer: 20 × price, no bonus line.
- B as guest: 10 × price, the line "Earn {20 × price} … for Customer members", and the sign-in line. A as guest: 30 × price and no bonus line.
- B as administrator: 10 × price and the VIP line only (the Shoes line is dropped on a product outside Shoes).
- The guest's markup is byte-identical across two runs with different guest sessions.
- With `show_bonus_offers` filtered to false: no bonus lines, figures unchanged.

- [ ] **Step 8: Verify the variable product (Review focus)**

Run: `php <scratchpad>/phase2/task2.php` with `CASE=variable`. Product and category conditions read the parent (variations carry no categories, and `product_in_cart` compares parent ids), so variations differ only by price: VIP is injected with `min_subtotal` 43, and the script prints `add_variation_sentence()`'s `yayloyalty_earn` and `yayloyalty_earn_bonuses` for each variation of the Hoodie (42 and 45) as the administrator, and the parent's bonus area.
Expected: the 45 variations carry the VIP line; the 42 one carries `yayloyalty_earn_bonuses` = '', and so does the parent's default (its cheapest variation), printed hidden. Reading `product-earn.js`: `found_variation` writes that '' and hides the area; `reset_data` restores `data-default`. Picking a variation in a browser is recorded as a browser check.

- [ ] **Step 9: Run the unit suite and lint**

Run: `composer test`, then `composer lint -- includes/Engine/Storefront/ProductEarnMessage.php includes/Engine/Storefront/LauncherCopy.php includes/Engine/Storefront/OfferLines.php includes/Engine/Points/PointsEngine.php`, and `node --check assets/storefront/product-earn.js`
Expected: OK; no reports for these files; no syntax error.

- [ ] **Step 10: Commit**

Stage: `includes/Engine/Storefront/ProductEarnMessage.php`, `includes/Engine/Storefront/LauncherCopy.php`, `includes/Engine/Storefront/OfferLines.php`, `includes/Engine/Points/PointsEngine.php`, `assets/storefront/product-earn.js`, `assets/storefront/product-earn.css`
Message: `feat(storefront): show bonus lines under the product-page earning line`

### Task 3: Cart and checkout earn preview

**Files:**
- Create: `includes/Engine/Storefront/CartEarnPreview.php`
- Create: `includes/Engine/Storefront/templates/cart-earn-row.php`
- Create: `assets/storefront/cart-earn-blocks.js`
- Create: `assets/storefront/cart-earn.css`
- Modify: `includes/Engine/Register/ScriptName.php` (`CART_EARN_BLOCKS_SCRIPT`)
- Modify: `includes/YayLoyalty.php` (`CartEarnPreview::register()` next to `ProductEarnMessage::register()`)
- Modify: `includes/Helpers/DesignSchema.php`, `apps/admin/src/lib/schema/design.schema.ts`, `apps/admin/src/pages/settings/DesignVisibilitySection.tsx` (`show_cart_earn_preview`, `cart_earn_line`)
- Create: `apps/admin/src/pages/settings/DesignStorefrontTextSection.tsx` (the Storefront text card was removed in #141; it comes back holding only `cart_earn_line`)
- Modify: `apps/admin/src/pages/settings/DesignSettingsPage.tsx` (render the new section), `design-settings.copy.ts` (its strings)
- Modify: `assets/storefront/storefront-tokens.css` (`.yayloyalty-cart-earn` gets the shared tokens)

**Interfaces:**
- Consumes: `EarningService::build()->preview()`, `PointsEngine::evaluate()`, `OfferLines::for_trigger()`, `quote_campaign()`, `ContextFactory::from_cart()`, the `ProductEarnMessage` guest sign-in line, the Store API registration pattern in `Engine\Redemption\Adapters\StoreApiAdapter`, and the slot-fill pattern in `assets/storefront/redeem-blocks.js`
- Produces: `CartEarnPreview` and the two design keys (README contract)

- [ ] **Step 1: Add the design keys**

`DesignSchema::get_default_design()`: `visibility.show_cart_earn_preview => true`, and a `storefront_text` group with `cart_earn_line => __( "You'll earn {points} {points_label} with this order", 'yayloyalty' )` (translators comment). `schema()`: `show_cart_earn_preview => [ 'type' => 'bool' ]`, `storefront_text.cart_earn_line => [ 'type' => 'text', 'required' => true ]`. `design.schema.ts`: `show_cart_earn_preview: z.boolean()` and `storefront_text: z.object({ cart_earn_line: z.string().trim().min(1) })`.

- [ ] **Step 2: Add `ScriptName::CART_EARN_BLOCKS_SCRIPT = 'yayloyalty/script/storefront-cart-earn-blocks'` and `CART_EARN_STYLE = 'yayloyalty/style/storefront-cart-earn'`**

- [ ] **Step 3: Implement `CartEarnPreview` (static, like `ProductEarnMessage`)**

- `CSS_CLASS = 'yayloyalty-cart-earn'`, `STORE_API_NAMESPACE = 'yayloyalty-earn-preview'`.
- `public static function register(): void`: `render_row` on `woocommerce_cart_totals_after_order_total` and `woocommerce_review_order_after_order_total`; `attach` on `woocommerce_blocks_loaded` (or at once when it already ran); `enqueue_style` on `wp_enqueue_scripts`.
- `public static function attach(): void`: when `woocommerce_store_api_register_endpoint_data` exists, registers `CartSchema::IDENTIFIER` data under `STORE_API_NAMESPACE` with `data_callback` `cart_data` (`[ 'html' => markup( WC()->cart, get_current_user_id() ) ]`, `html` '' without a cart) and `schema_callback` `cart_schema` (one readonly string `html`), and `enqueue_blocks` on `woocommerce_blocks_enqueue_cart_block_scripts_after` / `…checkout_block_scripts_after`.
- `public static function render_row(): void`: `<tr class="yayloyalty-cart-earn-row"><td colspan="2">{markup}</td></tr>`, nothing when markup is ''.
- `public static function markup( \WC_Cart $cart, int $user_id ): string`: '' when `show_cart_earn_preview` is off (`DesignHelper::surface_enabled()`) or the cart is empty. The figure: a member's is `EarningService::build()->preview( CampaignTrigger::PURCHASE, ContextFactory::from_cart( $cart, $user_id ) )`, capped by `points_left_today()`; a guest's is `PointsEngine::evaluate()` on `from_cart( $cart, null )`. Bonus lines: `OfferLines::for_trigger()` on `for_advert( Dates::now() )` with `from_cart( $cart, $user_id )` as the viewer for a member and null for a guest; each bonus quoted with `quote_campaign()` on the cart Context (capped like the figure), kept when greater than the figure, worded by `LauncherCopy::product_bonus()`. '' when the figure is 0 and there are no bonus lines. The figure line fills `storefront_text.cart_earn_line` through `LauncherCopy::points_sentence_html()`; a guest also gets `ProductEarnMessage::guest_line( self::CSS_CLASS )`. A `Throwable` in the figure gives ''; one in the bonuses drops only them. Both logged with `Log::warning( 'CartEarnPreview', … )`.
- The template `templates/cart-earn-row.php` prints `<div class="yayloyalty-cart-earn">` with the icon, the line (`__line`), each bonus (`__bonus`) and the guest line, all passed in as escaped HTML and printed through `wp_kses( …, Kses::inline() )` (the guest line's link through `wp_kses_post()`).
- `enqueue_style()` on `is_cart() || is_checkout()`, and `enqueue_blocks()` (style plus `assets/storefront/cart-earn-blocks.js` with deps `wp-element`, `wp-plugins`, `wc-blocks-checkout`, and an inline `window.yayloyaltyCartEarnBlocks = { namespace }`).

- [ ] **Step 4: Write `assets/storefront/cart-earn-blocks.js` and `assets/storefront/cart-earn.css`**

The script registers plugin `yayloyalty-cart-earn` (scope `woocommerce-checkout`) that fills `ExperimentalOrderMeta` with a `div` whose inner HTML is `props.extensions[namespace].html`, and renders nothing when it is ''. The Store API answers every cart change with fresh `extensions`, so a quantity change redraws the row without a reload. The stylesheet imports `storefront-tokens.css`, which adds `.yayloyalty-cart-earn` to its root list; the card follows `product-earn.css`.

- [ ] **Step 5: Register it**

`includes/YayLoyalty.php`: `\YayLoyalty\Engine\Storefront\CartEarnPreview::register();` after `ProductEarnMessage::register()`.

- [ ] **Step 6: The admin fields**

`DesignVisibilitySection.tsx`: `<VisibilitySwitchField name="show_cart_earn_preview" label={VISIBILITY_CART_EARN_PREVIEW_LABEL()} />` after the product-page switch (both builds). `DesignStorefrontTextSection.tsx`: a card "Storefront text" with one `Input` registered as `storefront_text.cart_earn_line`, its label, a description naming `{points}` and `{points_label}`, and the required error. `DesignSettingsPage.tsx` renders it after the Visibility section. Strings in `design-settings.copy.ts`.

- [ ] **Step 7: Type-check the SPA**

Run: `cd apps/admin && node_modules/.bin/tsc --noEmit`
Expected: exit 0

- [ ] **Step 8: Verify criteria 4 and 8 and the Review focus on the Local site**

Run: `cd /Users/nguyentruong/Valet/brandy && php <scratchpad>/phase2/task3.php`, which builds an in-memory cart (no saved session or order) with Shoes and VIP injected as in Task 2 and prints `CartEarnPreview::markup()` as text for a guest, a customer and an administrator.
Expected:
- Product A (in Shoes) as administrator: "You'll earn {30 × price} … with this order", no bonus line. Product B as administrator: "You'll earn {10 × price} …", then "Earn {30 × price} … when your order includes {category}" and "Earn {20 × price} … for Customer members".
- Guest: the same figure as the administrator, the bonus lines, and the sign-in line; byte-identical across two guest runs.
- Empty cart: ''. A cart under an injected base `min_subtotal` (no bonus): ''.
- `show_cart_earn_preview` filtered to false: '' for every viewer. `show_bonus_offers` false: the figure line only.
- `cart_earn_line` filtered to "Points: {points}" shows that wording.
- The Store API: `CartEarnPreview::cart_data()` returns `{ html }` equal to `markup()`, and `cart_schema()` names `html`.

Run: `node --check assets/storefront/cart-earn-blocks.js`, `php -l` on the new PHP files.
Expected: no syntax errors.

The block slot rendering and the redraw after a quantity change need a browser (Twenty Twenty-Five Cart and Checkout blocks); record them as browser checks.

- [ ] **Step 9: Run the unit suite and lint**

Run: `composer test`, then `composer lint -- includes/Engine/Storefront/CartEarnPreview.php includes/Engine/Storefront/templates/cart-earn-row.php includes/Engine/Register/ScriptName.php includes/YayLoyalty.php includes/Helpers/DesignSchema.php`
Expected: OK; no reports for these files.

- [ ] **Step 10: Commit**

Stage: the files listed under **Files** for this task, by name
Message: `feat(storefront): show the points an order will earn on cart and checkout`
