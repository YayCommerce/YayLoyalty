# Product-Page Earning Message Design

**Status:** accepted 2026-10-10
**Related:** wireframe "YayLoyalty Shopping Flow", artboard 1a (product page earning message: signed in, guest, variable, excluded); backlog `docs/backlog/2026-10-08-launcher-advertises-earning-past-the-daily-cap.md`

## Goal

On a single product page, show the shopper how many points buying that product earns, directly under the price, so the loyalty program is visible at the moment of decision. Members see what they would earn; guests see the base rate and an invitation to sign in or register.

## Context

- `ContextFactory::from_product( \WC_Product $product, int $qty = 1, ?int $user_id = null )` (`includes/Engine/Points/ContextFactory.php`) builds an event Context for one product, ex-tax subtotal at the current price.
- `EarningService::preview( string $trigger, Context $ctx ): Result` (`includes/Engine/Earning/EarningService.php`) runs the earning gates (Lite `ProgramGate`: program on, a customer, not opted out, role not excluded; plus Pro's gates) then `PointsEngine::evaluate()`. It writes nothing and is uncapped by Pro's daily limit by design.
- `PointsEngine::build( CampaignRepository )->evaluate( CampaignTrigger::PURCHASE, $ctx )` is what the launcher's guest estimate uses (`LauncherPayload::product_estimate()`), which today skips variable products and catches `Throwable`.
- `PurchaseHandler::is_eligible()` rejects a context below the campaign's `min_subtotal`, so a cheap product alone earns nothing.
- `LauncherPayload::register_route()` (private today) returns `'account'`, `'checkout'` or null for where a guest can create an account; `LauncherEarnRules::advertise()` reports the signup campaign's points.
- Design settings: `DesignSchema::get_default_design()` / `schema()` (`includes/Helpers/DesignSchema.php`), read through `DesignHelper::get_design()`, which merges defaults over the stored design. SPA mirrors in `apps/admin/src/lib/schema/design.schema.ts`, with UI in `apps/admin/src/pages/settings/DesignVisibilitySection.tsx` and `DesignStorefrontTextSection.tsx`.
- Copy helpers live in `includes/Engine/Storefront/LauncherCopy.php` (`earn_rate()`, `storefront_text()`); labels in `SettingsHelper::points_label()` / `points_text()`.
- Storefront registration in `includes/YayLoyalty.php`: `AccountRewards::register()` runs on every request; `Launcher::register()` only when `! is_admin()`.
- WooCommerce's default block template `single-product.html` uses `woocommerce/product-price` and `woocommerce/add-to-cart-form`; the latter renders the classic variations form, so `found_variation` / `reset_data` fire in block and classic themes alike. The Local test site runs Twenty Twenty-Five (block theme), WooCommerce 11.1.2.
- `RedemptionService::is_available()` is false while the redemption backend is in progress; tiers are not built (`docs/backlog/` `tiers-not-built`).

## Scope

**In:**
- The earning line under the price on single product pages, in classic and block themes.
- Member figure from `EarningService::preview()`; guest figure from `PointsEngine::evaluate()` at the base rate, plus a sign-in / register line.
- Variable products: the cheapest variation's figure on load, updated without a reload when the shopper picks a variation, restored on reset.
- A Design-tab switch to turn it off and an editable sentence.

**Out:**
- The "That is $X back" worth sentence (waits for redemption) and the tier uplift sentence (waits for tiers).
- Product cards on shop, archive, related-product and collection listings.
- Recalculating when the quantity changes; the figure is for quantity 1.
- Shortcodes and blocks for the message.
- The daily earning cap (Pro): figures stay the uncapped best case; tracked in the existing backlog item.
- Applying the launcher's `visibility.hide_for_roles`; that setting is the launcher's.
- The "Add to Cart with Options" block's variation picker (does not fire `found_variation`).

## Acceptance criteria

1. Given the program is on, `visibility.product_message` is true and a simple product priced $180 under a purchase campaign of 1 point per $1, when a signed-in member opens its page, then exactly one `.yayloyalty-product-earn` element follows the price, reading "Earn 180 {label} with this purchase" with the store's label (on the Local site: "Earn 180 hehe with this purchase"), in a classic theme and in Twenty Twenty-Five.
2. Given the same product, when a guest opens it, then the same sentence shows at the base rate, followed by a "Sign in to start earning" link to My Account; when the signup campaign is on and `register_route()` is not null, the line continues "— or create an account and get {N} {label}" linking to My Account (`'account'`) or checkout (`'checkout'`).
3. Given a member who is opted out (`_yayloyalty_opted_out` user meta), has a role in settings `excluded_roles`, or the program is paused, then no `.yayloyalty-product-earn` element is rendered.
4. Given a product excluded from the purchase campaign, a product whose price is below the campaign's `min_subtotal`, a product with no price, a grouped product or an external product, then no `.yayloyalty-product-earn` element is rendered and no product-earn script is enqueued.
5. Given `visibility.product_message` is false, then nothing is rendered on any product page and no script is enqueued.
6. Given a variable product with variations at $180 and $210, when its page loads, the line shows the $180 variation's points; when the shopper picks the $210 variation, the line shows its points without a reload; when they click "Clear", the $180 figure returns. A variation that earns 0 hides the line while it is selected.
7. Given a variable product with more than 30 variations (WooCommerce's AJAX threshold), selecting a variation updates the line the same way (the `woocommerce_available_variation` key is present in the `admin-ajax` response).
8. Given related products or a product collection on a product page, then only the main product's price block gets the line.
9. Given the merchant edits `storefront_text.product_earn_line` to "You'll collect {points} {points_label}" and saves, then the product page shows "You'll collect 180 hehe"; saving it empty is rejected (required field).
10. Given the points computation throws, then the product page renders without the line and a `Log::warning` entry with context `ProductEarnMessage` is written.
11. End-to-end verification: on the Local site (Twenty Twenty-Five), with the switch on, open a simple product signed out (guest line + sign-in line), signed in as a non-admin customer (member line), and a variable product (line changes on variation pick and resets on Clear); then turn the switch off in Design → Visibility, save, reload: no line on any of the three.

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static`/`never` types (CLAUDE.md hard rule 4, `.claude/rules/php-standards.md`).
- Lite names Pro only in `includes/YayLoyalty.php`; this feature is Lite and uses no Pro names (hard rule 2).
- Writes nothing to the ledger; read-only use of `EarningService::preview()` and `PointsEngine::evaluate()` (hard rule 5).
- New design keys go in both `DesignSchema::get_default_design()` and `schema()`, and in `apps/admin/src/lib/schema/design.schema.ts`.
- New hooks attach in a `register()` method called once from `includes/YayLoyalty.php`; logging only through `Log::warning()`.
- The storefront script is plain JS with no build step (like `assets/storefront/account.css`), depends only on `jquery`, and is enqueued only on variable product pages that render the line.
- Guest output contains no per-user data, so full-page caches stay safe.
- Text domain `yayloyalty`; every new string translatable, with translators comments for placeholders.
- Tests: none (display only, no ledger or money math); each criterion is verified by hand.

## Approach

**Chosen:** A — precompute each variation's sentence into WooCommerce's variation data, with a small script that swaps it in.

**Architecture:** A new `ProductEarnMessage` class owns the feature. It computes one sentence per product (or per variation) on the server, prints it under the price through the classic summary hook or the block price filter, and for variable products adds each variation's sentence to WooCommerce's `available_variation` data. A plain-JS script listens to WooCommerce's own variation events and swaps the text. No new REST route or request.

**Components:**
- `includes/Engine/Storefront/ProductEarnMessage.php` (new): `register()`; hooks below; computes the figure (member via `EarningService::build()->preview()`, guest via `PointsEngine::build( new CampaignRepository() )->evaluate()`, both with `ContextFactory::from_product( $product, 1, $user_id )`), builds the sentence, renders the container, catches `Throwable`.
- `assets/storefront/product-earn.js` (new): on `found_variation` writes `variation.yayloyalty_earn` into `.yayloyalty-product-earn`'s sentence span and shows/hides the element by emptiness; on `reset_data` restores `data-default`.
- `includes/Engine/Storefront/LauncherCopy.php`: new `product_earn( string $template, int $points ): string` filling `{points}` (`number_format_i18n`) and `{points_label}` (`SettingsHelper::points_label( $points )`).
- `includes/Engine/Storefront/LauncherPayload.php`: `register_route()` becomes `public static`.
- `includes/Engine/Register/ScriptName.php`: `PRODUCT_EARN_SCRIPT = 'yayloyalty/script/storefront-product-earn'`.
- `includes/Helpers/DesignSchema.php`: two keys (below).
- `includes/YayLoyalty.php`: calls `ProductEarnMessage::register()` next to `AccountRewards::register()`, outside the `! is_admin()` block.
- `apps/admin/src/lib/schema/design.schema.ts`, `DesignVisibilitySection.tsx` (switch "Show on product pages"), `DesignStorefrontTextSection.tsx` (sixth field "Product page earning line") and their i18n strings.
- `docs/architecture.md`: a Storefront line for `ProductEarnMessage`.

**Data and storage:**
- `visibility.product_message`: `bool`, default `true`.
- `storefront_text.product_earn_line`: `text`, `required: true`, default `__( 'Earn {points} {points_label} with this purchase', 'yayloyalty' )` with a translators comment saying to keep the `{…}` placeholders.
- Both live in the existing design option. Existing installs get the defaults through `DesignHelper::get_design()`'s merge; no migration. Removed with the design option on uninstall.

**Integration points:**
- `woocommerce_single_product_summary`, priority 15 (classic; price is 10, excerpt 20).
- `render_block_woocommerce/product-price` (block themes): appends only when `is_product()` and the block instance's `postId` context equals `get_queried_object_id()`.
- `woocommerce_available_variation` (3 args): adds `yayloyalty_earn` (the sentence or `''`) to each variation; also runs on `admin-ajax` for stores above the 30-variation threshold.
- `wp_enqueue_scripts`: enqueues `PRODUCT_EARN_SCRIPT` with version `Launcher::asset_version()` on variable product pages that rendered the line.
- Shared code changed: `LauncherPayload::register_route()` visibility only (consumer: the launcher, unchanged); `LauncherCopy` gains one method.

**UI:** Theme-native markup: `<div class="yayloyalty-product-earn" data-default="{sentence}"><p><span>{sentence}</span></p>[guest line]</div>`, with one inline style rule for its top/bottom margin, inheriting the theme's text styles. Guest line: "Sign in to start earning" (link to `wc_get_page_permalink( 'myaccount' )`), and when signup points are advertised and `register_route()` is not null, "— or create an account and get %s" (link to My Account for `'account'`, checkout for `'checkout'`). Admin: switch in Design → Visibility, field in Design → Storefront text; not shown in the launcher preview.

**Edge cases and failure modes:**
- Variable product: the parent prints the cheapest variation's sentence (lowest `get_price()` among visible, purchasable variations); if every variation earns 0, nothing is rendered and no script loads.
- Variation earning 0: `yayloyalty_earn` is `''`; the script hides the element while it is selected.
- Grouped, external, or price `''` products: nothing.
- Sale and scheduled prices: `from_product()` reads the current price.
- Tax display setting: the engine uses ex-tax subtotals as at order time, so the figure matches the award.
- Page caches: guest output is base rate only; member output relies on caches skipping signed-in pages, the assumption `LauncherPayload` already documents.
- Pro daily cap reached: full figure shown (uncapped `preview()`), per the backlog item.
- `Throwable` during computation: logged via `Log::warning( 'ProductEarnMessage', … )`, nothing printed.
- "Add to Cart with Options" block: the line stays at the cheapest figure.

**Decision record:** None.

## Alternatives considered

| Option | Why rejected |
|---|---|
| B. REST route computing points on each variation pick | Adds a public route, nonce handling for member figures and a request with latency per pick; precomputing costs one evaluation per variation and needs none of that. |
| C. Lowest-variation figure only, no live update | Breaks wireframe case (b): the figure stops matching the selected price. |
| Always on, no setting | Merchants would have to pause the program to remove it after a theme conflict. |
| Empty sentence turns it off (no switch) | Turning a feature off by deleting text is less discoverable than a switch. |
| Conditional line below the minimum order ("on orders over $50") | Promises points the engine does not award for this purchase alone. |
| Follow quantity changes | Points are not linear (rounding, per-order caps); needs a round-trip or a table per quantity. |

## Rollout

On upgrade the message appears on every store with the program on, using the default sentence. The release note says so and points to Design → Visibility → "Show on product pages" to turn it off.

## Open questions

None.
