# Product-Page Earning Message Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** active
**Goal:** Show "Earn {N} {label} with this purchase" under the price on single product pages, for members (gated figure) and guests (base rate plus a sign-in/register line), updating live when a variation is picked.
**Spec:** [docs/specs/2026-10-10-product-page-earning-message-design.md](../specs/2026-10-10-product-page-earning-message-design.md)
**Architecture:** One new class, `ProductEarnMessage`, computes the sentence on the server and prints it through the classic summary hook or the block price filter. For variable products it adds each variation's sentence to WooCommerce's own variation data, and a plain-JS file swaps it in on WooCommerce's `found_variation` / `reset_data` events. This avoids a new REST route and a request per pick (spec, Alternatives).
**Touches:** `includes/Helpers/DesignSchema.php` (shared: consumed by `DesignHelper`, `LauncherCopy`, `LauncherPreview`, the Design REST routes), `apps/admin/src/lib/schema/design.schema.ts`, `apps/admin/src/pages/settings/DesignVisibilitySection.tsx`, `DesignStorefrontTextSection.tsx`, `design-settings.copy.ts`, `includes/Engine/Storefront/LauncherCopy.php` (shared: consumed by launcher templates, `AccountRewards`), `includes/Engine/Storefront/LauncherPayload.php` (shared: `register_route()` made public; consumer `LauncherPayload::for_balance()` unchanged), new `includes/Engine/Storefront/ProductEarnMessage.php`, new `assets/storefront/product-earn.js`, `includes/Engine/Register/ScriptName.php`, `includes/YayLoyalty.php`, `docs/architecture.md`
**Tests:** none. CLAUDE.md warrants a test for money or ledger logic or a reproduced bug; this feature only displays a figure the existing `PointsEngine` / `EarningService::preview()` computes and writes nothing. Each task has a manual check instead.

## Changes from the spec

- **Number formatting:** the spec says `{points}` uses `number_format_i18n`. `docs/conventions.md` and the launcher route points through `SettingsHelper::format_number()`, so `{points}` uses `LauncherCopy::points()`.
- **Large variable products:** the spec says WooCommerce fetches variations over `admin-ajax`. It actually uses its own `?wc-ajax=get_variation` endpoint, which is not an admin request. Registering `ProductEarnMessage` outside the `! is_admin()` block (as the spec says) stays correct and harmless; verification uses the `wc-ajax` endpoint.
- **`{points_label}` filling:** `LauncherCopy::storefront_text()` replaces `{points_label}` with the plural label in every storefront_text value. `ProductEarnMessage` must read the raw `DesignHelper::get_design()['storefront_text']['product_earn_line']`, not the `storefront_text()` output, so "1 Point" stays singular.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types; also no `mixed`/`static`/`never` types, `catch` without a variable, `throw` as an expression, trailing comma after the last declared parameter, `new` in a default value, first-class callables, `$object::class`.
- Lite names Pro only in `includes/YayLoyalty.php`. Nothing in this plan references `includes/Pro/`.
- Only `LedgerService` writes ledger rows and balances. This plan writes none; it calls only `EarningService::preview()` and `PointsEngine::evaluate()`.
- New design keys go in both `DesignSchema::get_default_design()` and `schema()`, and in `apps/admin/src/lib/schema/design.schema.ts`.
- New hooks attach in a `register()` method called once from `includes/YayLoyalty.php`. Logging only through `Log::warning()` with the class name as context.
- Show points through `LauncherCopy::points()` / `SettingsHelper::points_label()`; never `number_format_i18n()`.
- `assets/storefront/product-earn.js` is hand-written source with no build step, depends only on `jquery`, and loads only on variable product pages that render the line. Never edit `assets/dist/`.
- Guest output contains no per-user data.
- Text domain `yayloyalty`; every new string translatable, with translators comments on placeholders.
- Comments are minimal: one-line docblocks, plus a why only where the code would look wrong.
- Branch from `dev/edd` as `shrief/feat/product-earn-message`. Commit messages are Conventional Commits.
- Checks that fail on `dev/edd` today (`docs/known-issues.md`): `composer lint` over the whole repo, admin `lint`, admin `tsc --noEmit` (TS2307 for `@emoji-mart/*`, `@radix-ui/react-progress`), `./scripts/run.sh build`. Run phpcs on changed PHP files only and expect no errors or warnings in them; for `tsc`, expect only those existing TS2307 errors.

## Review focus

- A related-products or product-collection block on the product page: only the main product's price gets the line, never each card (Task 2, Step 6).
- A member who opted out or has an excluded role must see nothing, not a figure they won't get (Task 2, Step 5).
- Variable product with a variation that earns 0, then "Clear": the line hides while that variation is selected and the cheapest figure comes back on Clear (Task 3, Step 5).
- A variable product fetched over `wc-ajax=get_variation` (stores above WooCommerce's 30-variation threshold): the response must carry `yayloyalty_earn` (Task 3, Step 6).
- Existing installs: after updating, `DesignHelper::get_design()` returns `product_message: true` and the default sentence without a save (Task 1, Step 3).
- An exception inside the points engine must not blank the product page (Task 2, Step 7).

---

### Task 1: Design settings for the message

**Files:**
- Modify: `includes/Helpers/DesignSchema.php` (defaults ~25-41, `schema()` ~75-101)
- Modify: `apps/admin/src/lib/schema/design.schema.ts`
- Modify: `apps/admin/src/pages/settings/DesignVisibilitySection.tsx`
- Modify: `apps/admin/src/pages/settings/DesignStorefrontTextSection.tsx`
- Modify: `apps/admin/src/pages/settings/design-settings.copy.ts`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - Design key `visibility.product_message`: `bool`, default `true`.
  - Design key `storefront_text.product_earn_line`: `text`, `required: true`, default `__( 'Earn {points} {points_label} with this purchase', 'yayloyalty' )`.

- [ ] **Step 1: Add both keys to `DesignSchema::get_default_design()` and `schema()`**

The default sentence carries the same translators comment as `earn_rate_line` ("keep every {…} placeholder as it is"). Schema: `'product_message' => [ 'type' => 'bool' ]` under `visibility`; `'product_earn_line' => [ 'type' => 'text', 'required' => true ]` under `storefront_text`.

- [ ] **Step 2: Mirror the keys in the SPA and add the controls**

- `design.schema.ts`: `product_earn_line: z.string().min(1)` in `storefront_text`; `product_message: z.boolean()` in `visibility`.
- `design-settings.copy.ts`: `VISIBILITY_PRODUCT_MESSAGE_LABEL = () => __('Show on product pages', 'yayloyalty')` and `STOREFRONT_PRODUCT_EARN_LINE_LABEL = () => __('Product page earning line', 'yayloyalty')`.
- `DesignVisibilitySection.tsx`: a `Field` with a `Controller`-driven `Switch` for `visibility.product_message`, id `design-visibility-product-message`, after "Hide on mobile", same markup as that switch.
- `DesignStorefrontTextSection.tsx`: a sixth `<StorefrontTextField name="product_earn_line" … />` after `signed_out_prompt`; update the "Five text inputs" docblock to six.

- [ ] **Step 3: Verify the defaults reach an existing install**

Run from `/Users/shrief/Local-Sites/yaydevs/app/public`: `php -d "mysqli.default_socket=/Users/shrief/Library/Application Support/Local/run/DmdPSy05S/mysql/mysqld.sock" /opt/homebrew/bin/wp eval '$d=\YayLoyalty\Helpers\DesignHelper::get_design(); var_export([$d["visibility"]["product_message"],$d["storefront_text"]["product_earn_line"]]);'`
Expected: `array ( 0 => true, 1 => 'Earn {points} {points_label} with this purchase', )`

- [ ] **Step 4: Verify in the admin**

Run `./scripts/run.sh dev`, open `/wp-admin/admin.php?page=yayloyalty#/settings/design` (Design tab).
Action: turn "Show on product pages" off, change "Product page earning line" to `You'll collect {points} {points_label}`, save, reload; then clear the line and try to save.
Expected: after reload the switch is off and the sentence is kept; saving the empty line shows the required-field error and doesn't save. Restore both (switch on, default sentence via Reset or retyping) before Task 2.

- [ ] **Step 5: Run the checks**

Run: `composer lint -- includes/Helpers/DesignSchema.php` — expected: no errors or warnings.
Run: `node node_modules/typescript/bin/tsc --noEmit` from `apps/admin` — expected: only the existing TS2307 errors.
Run: `pnpm --filter @yayloyalty/admin-page test` — expected: all suites pass (at least one test runs).

- [ ] **Step 6: Commit**

Stage: `includes/Helpers/DesignSchema.php`, `apps/admin/src/lib/schema/design.schema.ts`, `apps/admin/src/pages/settings/DesignVisibilitySection.tsx`, `apps/admin/src/pages/settings/DesignStorefrontTextSection.tsx`, `apps/admin/src/pages/settings/design-settings.copy.ts`
Message: `feat(design): settings for the product-page earning message`

### Task 2: The message on simple products

**Files:**
- Create: `includes/Engine/Storefront/ProductEarnMessage.php`
- Modify: `includes/Engine/Storefront/LauncherCopy.php` (after `earn_rate()`)
- Modify: `includes/Engine/Storefront/LauncherPayload.php` (`register_route()`, ~136)
- Modify: `includes/YayLoyalty.php:38`
- Modify: `docs/architecture.md:77` (Storefront bullets)

**Interfaces:**
- Consumes: Task 1's two design keys.
- Produces:
  - `LauncherCopy::product_earn( string $template, int $points ): string` — `strtr` of `{points}` → `self::points( $points )` and `{points_label}` → `SettingsHelper::points_label( $points )`.
  - `LauncherPayload::register_route(): ?string` — now `public static`, body unchanged.
  - `final class ProductEarnMessage` in `YayLoyalty\Engine\Storefront`:
    - `public const CSS_CLASS = 'yayloyalty-product-earn';`
    - `public static function register(): void`
    - `public static function render_summary(): void` — on `woocommerce_single_product_summary`, priority 15.
    - `public static function append_to_price_block( string $content, array $block, \WP_Block $instance ): string` — on `render_block_woocommerce/product-price`, 3 args.
    - `public static function sentence( \WC_Product $product ): string` — the filled sentence for one purchasable product at quantity 1, or `''`. Task 3 reuses it per variation.

- [ ] **Step 1: Add `LauncherCopy::product_earn()` and make `register_route()` public**

One-line docblock on each. No other change to `LauncherPayload`.

- [ ] **Step 2: Implement `ProductEarnMessage::sentence( \WC_Product $product ): string`**

Returns `''` when: `ProgramGate::program_enabled()` is false; `visibility.product_message` is false; the product is grouped or external, or `'' === $product->get_price()`; or the points are 0 or less. Otherwise points come from:
- member (`get_current_user_id() > 0`): `EarningService::build()->preview( CampaignTrigger::PURCHASE, ContextFactory::from_product( $product, 1, $user_id ) )->get_points()`;
- guest: `PointsEngine::build( new CampaignRepository() )->evaluate( CampaignTrigger::PURCHASE, ContextFactory::from_product( $product ) )->get_points()`.

Wrap the computation in `try { … } catch ( \Throwable $e )`: `Log::warning( 'ProductEarnMessage', 'estimate failed: ' . $e->getMessage() )` and return `''`. Fill with `LauncherCopy::product_earn()` using the raw `DesignHelper::get_design()['storefront_text']['product_earn_line']` (see "Changes from the spec"). Build the service and engine once per request (private static memo), since Task 3 calls this per variation.

- [ ] **Step 3: Implement the markup and both placement hooks**

A private `markup( string $sentence ): string` returns `''` for an empty sentence, else:
`<div class="yayloyalty-product-earn" data-default="{esc_attr sentence}" style="margin:0.5em 0 1em"><p class="yayloyalty-product-earn__line">{esc_html sentence}</p>{guest line}</div>`.
Guest line (only when `0 === get_current_user_id()`): `<p class="yayloyalty-product-earn__guest">` with a link "Sign in to start earning" to `wc_get_page_permalink( 'myaccount' )`. When `LauncherPayload::register_route()` is not null and the signup campaign is advertised (`LauncherEarnRules::advertise( PointsEngine, ContextFactory::for_advert( Dates::now() ), true )['earn'][ CampaignTrigger::SIGNUP ]` is not null), append " — or " and a link "create an account and get %s" (`SettingsHelper::points_text( $signup )`) to My Account for `'account'`, `wc_get_checkout_url()` for `'checkout'`. Translators comments on each `%s`.
- `render_summary()`: `global $product;` echo `markup( sentence( $product ) )` when `$product instanceof \WC_Product`. Echo goes through the already-escaped markup; add the `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` with that reason.
- `append_to_price_block()`: returns `$content` unchanged unless `is_product()` and `(int) ( $instance->context['postId'] ?? 0 ) === get_queried_object_id()`; then appends `markup( sentence( wc_get_product( get_queried_object_id() ) ) )`.
- `register()`: `add_action( 'woocommerce_single_product_summary', [ self::class, 'render_summary' ], 15 )` and `add_filter( 'render_block_woocommerce/product-price', [ self::class, 'append_to_price_block' ], 10, 3 )`.

- [ ] **Step 4: Wire it up and document it**

`includes/YayLoyalty.php`: `\YayLoyalty\Engine\Storefront\ProductEarnMessage::register();` right after `AccountRewards::register();`, outside the `! is_admin()` block. `docs/architecture.md`: one bullet after the My Account → Rewards bullet: the product-page earning line (`ProductEarnMessage`), its two placement hooks, member figure via `EarningService::preview()`, guest at the base rate, and the variation data key `yayloyalty_earn` (Task 3).

- [ ] **Step 5: Verify member, guest and gated member**

Simple product #29 "Shirt - Green" ($20, earns 20 on the Local site: label "hehe"), `http://yaydevs.local/?product=shirt-green`, Twenty Twenty-Five (block theme).
- Signed out (private window): one `.yayloyalty-product-earn` right under the price: "Earn 20 hehe with this purchase", then "Sign in to start earning" (plus the create-account clause only if the signup campaign is on and registration is allowed).
- Render as a member without a browser login: `wp eval 'wp_set_current_user(1); echo \YayLoyalty\Engine\Storefront\ProductEarnMessage::sentence( wc_get_product( 29 ) );'` — expected: `Earn 20 hehe with this purchase`.
- Opted out: `wp eval 'update_user_meta(1,"_yayloyalty_opted_out",1); wp_set_current_user(1); var_export( \YayLoyalty\Engine\Storefront\ProductEarnMessage::sentence( wc_get_product( 29 ) ) ); delete_user_meta(1,"_yayloyalty_opted_out");'` — expected: `''`.

(All `wp eval` lines run with the socket prefix from Task 1, Step 3.)

- [ ] **Step 6: Verify placement and the switch**

- Product #29 page source (signed out): exactly one `class="yayloyalty-product-earn"` in the HTML even though the template shows a related-products collection (`curl -s 'http://yaydevs.local/?product=shirt-green' | grep -c 'class="yayloyalty-product-earn"'` → `1`).
- Classic theme: `wp eval 'switch_theme("storefront");'` only if Storefront (or another classic theme) is installed — ask the user before switching their theme; otherwise check that `has_action( 'woocommerce_single_product_summary', [ ProductEarnMessage::class, 'render_summary' ] )` returns `15` in `wp eval`.
- Turn "Show on product pages" off in the Design tab, reload #29: `grep -c` → `0`. Turn it back on.
- Change "Product page earning line" to `You'll collect {points} {points_label}`, save, reload #29: the line reads "You'll collect 20 hehe". Reset it to the default.

- [ ] **Step 7: Verify the failure path**

`wp eval '$p = wc_get_product( 29 ); add_filter( "wp_get_object_terms_args", function(){ throw new \RuntimeException("boom"); } ); var_export( \YayLoyalty\Engine\Storefront\ProductEarnMessage::sentence( $p ) );'`
Expected: `''` (the throw comes from `ContextFactory::from_product()`'s category read, inside the `try`), and the WooCommerce log (WooCommerce → Status → Logs, source `yayloyalty`) has a warning with `ProductEarnMessage` and `boom`.

- [ ] **Step 8: Run the checks**

Run: `composer lint -- includes/Engine/Storefront/ProductEarnMessage.php includes/Engine/Storefront/LauncherCopy.php includes/Engine/Storefront/LauncherPayload.php includes/YayLoyalty.php` — expected: no errors or warnings.

- [ ] **Step 9: Commit**

Stage: `includes/Engine/Storefront/ProductEarnMessage.php`, `includes/Engine/Storefront/LauncherCopy.php`, `includes/Engine/Storefront/LauncherPayload.php`, `includes/YayLoyalty.php`, `docs/architecture.md`
Message: `feat(storefront): product-page earning message`

### Task 3: Variable products

**Files:**
- Modify: `includes/Engine/Storefront/ProductEarnMessage.php`
- Create: `assets/storefront/product-earn.js`
- Modify: `includes/Engine/Register/ScriptName.php`

**Interfaces:**
- Consumes: `ProductEarnMessage::sentence()`, `CSS_CLASS` and the markup from Task 2.
- Produces:
  - `ScriptName::PRODUCT_EARN_SCRIPT = 'yayloyalty/script/storefront-product-earn'`.
  - `public static function add_variation_sentence( array $data, \WC_Product $product, \WC_Product_Variation $variation ): array` — on `woocommerce_available_variation`, 3 args; sets `$data['yayloyalty_earn'] = self::sentence( $variation )`.
  - `public static function enqueue(): void` — on `wp_enqueue_scripts`.
  - Variation data key `yayloyalty_earn`: the variation's filled sentence, or `''`.

- [ ] **Step 1: Use the cheapest variation for a variable product's sentence**

In `sentence()`, when the product is variable, compute from the variation with the lowest `get_price()` among `$product->get_available_variations( 'objects' )` that have a price; `''` when none.

- [ ] **Step 2: Add `add_variation_sentence()` and register it**

`add_filter( 'woocommerce_available_variation', [ self::class, 'add_variation_sentence' ], 10, 3 )` in `register()`.

- [ ] **Step 3: Write `assets/storefront/product-earn.js`**

jQuery, no build. On `found_variation` of `form.variations_form`: find the `.yayloyalty-product-earn` that belongs to the product page (`document.querySelector`), write `variation.yayloyalty_earn` into `.yayloyalty-product-earn__line` with `textContent`, and set `hidden` on the container when it is `''`. On `reset_data`: restore `data-default` and remove `hidden`. Guard for `typeof variation.yayloyalty_earn !== 'string'` (leave the line as is).

- [ ] **Step 4: Register the handle and enqueue**

`ScriptName::PRODUCT_EARN_SCRIPT`, docblock "Product-page earning line: swaps the sentence when a variation is picked." `enqueue()`: only when `is_product()`, the queried product is variable and `'' !== self::sentence( $product )`; `wp_enqueue_script( ScriptName::PRODUCT_EARN_SCRIPT, YAYLOYALTY_PLUGIN_URL . 'assets/storefront/product-earn.js', [ 'jquery' ], Launcher::asset_version( 'assets/storefront/product-earn.js' ), [ 'in_footer' => true ] )`. Register on `wp_enqueue_scripts` in `register()`.

- [ ] **Step 5: Verify in the browser**

Setup (ask the user first; it adds a product to their site): `wp eval` that creates a published variable product "YL Earn Test" with attribute `Size` (`S`, `L`) and two variations at $180 and $210, and prints its id and permalink. Signed out, open its page.
Expected: line shows the $180 variation's figure on load (`Earn 180 hehe with this purchase` at 1 point per unit); pick L → `Earn 210 hehe with this purchase` without a reload; click Clear → back to 180. Then set the L variation's price to `0.5` via `wp eval`, reload, pick L → the line is hidden; Clear → shows again. Delete the test product afterwards (`wp eval 'wp_delete_post( <id>, true );'` plus its variations — ask the user before deleting).
Also open product #29 (simple): page source has no `product-earn.js`.

- [ ] **Step 6: Verify the wc-ajax path**

`curl -s -d 'product_id=<id>&attribute_size=L' 'http://yaydevs.local/?wc-ajax=get_variation'`
Expected: JSON containing `"yayloyalty_earn":"Earn 210 hehe with this purchase"`.

- [ ] **Step 7: Run the end-to-end check (spec, Acceptance criterion 11)**

On the Local site (Twenty Twenty-Five), with "Show on product pages" on: open product #29 signed out (guest line plus sign-in line), signed in as a non-admin customer (member line), and "YL Earn Test" (line changes on variation pick and resets on Clear); then turn the switch off in Design → Visibility, save, reload all three.
Expected: the three lines appear as described with the switch on; no `.yayloyalty-product-earn` on any of the three with it off. Turn the switch back on.

- [ ] **Step 8: Run the checks**

Run: `composer lint -- includes/Engine/Storefront/ProductEarnMessage.php includes/Engine/Register/ScriptName.php` — expected: no errors or warnings.
Run: `node -e "require('vm').createScript(require('fs').readFileSync('assets/storefront/product-earn.js','utf8'))"` — expected: no output (parses).

- [ ] **Step 9: Commit**

Set this plan's Status to `done YYYY-MM-DD` only after `/yayplancheck` passes; until then leave it `active`.
Stage: `includes/Engine/Storefront/ProductEarnMessage.php`, `assets/storefront/product-earn.js`, `includes/Engine/Register/ScriptName.php`
Message: `feat(storefront): update the earning line when a variation is picked`
