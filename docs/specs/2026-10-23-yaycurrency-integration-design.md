# YayCurrency Integration Design

**Status:** accepted 2026-10-23
**Related:** supersedes [2026-10-07-yaycurrency-currency-and-rounding-design.md](2026-10-07-yaycurrency-currency-and-rounding-design.md)

## Goal

On a store running YayCurrency, a point keeps the same value in every currency the shopper picks in YayCurrency's switcher, in Lite and Pro. With ₫ as base and 25,000 ₫ = $1, a shopper viewing USD sees "1 point = $1", earns 1 point on a $1 order, and gets $1 off for 1 point, for the points slider and every reward type.

## Context

- **Earning seam.** `Engine\Points\ContextFactory::base_rate()` applies `yayloyalty_context_base_rate` (event currency → base currency multiplier, default `1.0`). `BaseCalculator::compute()` and `PurchaseHandler::is_eligible()` multiply by it. Nothing subscribes, so a $1 order earns as if it were 1 ₫.
- **Advertised earn rate.** `PurchaseHandler::advertised_value()` returns the campaign's raw `points_per_unit`. `LauncherEarnRules::advertise()` passes it and `PurchaseHandler::min_subtotal()` to `LauncherCopy::earn_rate()` without the context's base rate.
- **Redemption.** It's live in Lite (`includes/Engine/Redemption/`, `includes/Engine/Rewards/`).
  - `Rewards\ContextFactory::from_cart()` builds the quote context with `get_woocommerce_currency()` and a cart subtotal from `get_price()`. YayCurrency has converted both into the shopper's currency.
  - `Clamp::apply()` reads `points_per_currency_unit`, which is set in base currency, as if it were per shopper unit.
  - `CouponProvider::virtual_coupon_data()` emits `yayloyalty-redeem`. Pro reward coupons `yayloyalty-reward-{id}` get their amount, minimum and cap from `RewardHandler::coupon_props()` (`Pro\Services\DiscountRewardHandler`) through `RedemptionService::coupon_props_of()` and `RewardCoupons`.
  - `CommitWriter` re-quotes from the order's coupon line (`Rewards\ContextFactory::from_order()`, `Clamp::points_for()`). It rejects a slider discount that differs from the quote by half a smallest currency unit or more.
- **Display.** `LauncherPayload::worth()` and `::redeem_rule()`, `LauncherCopy::coupon_reward_line()`, `earn_rate()` and `redeem_rate()`, and `Pro\Storefront\RewardsPanel` (the "spend X more" line, removed since by the dev/edd redeem card v2) print base-currency numbers. `wc_price()` adds the symbol of the shopper's currency, because YayCurrency filters `woocommerce_currency`. Templates: `panel-member.php`, `panel-guest.php`, `account-rewards.php`, `points-card.php`, `redeem-card.php`.
- **Emails.** `LoyaltyEmail::balance_value()` passes `get_option( 'woocommerce_currency' )` explicitly, so emails already show the base currency.
- **YayCurrency Pro 3.3.5** (sibling folder `../YayCurrency`, read only):
  - Currencies are `yay-currency-manage` posts, read through `YayCurrencyHelper::converted_currency()` and `::get_currency_by_currency_code()`. A currency's `rate` is currency units per 1 base unit.
  - `YayCurrencyHelper::get_rate_fee( $currency )` adds the fee, either `percentage` or `fixed`. Product prices always use the rate after fee (`calculate_price_by_currency()`).
  - Front-end conversion runs only where `YayCurrencyHelper::is_reload_permitted()` allows it: not in wp-admin, but on REST and Store API requests. Its first check is `apply_filters( 'YayCurrency/ConvertPrice/NotPermitted', false )`.
  - On `woocommerce_coupon_get_amount`, `_minimum_amount` and `_maximum_amount`, at priority 10, it multiplies every non-percent coupon by the rate (`WooCommerceCurrency::change_coupon_amount()`, `convert_coupon_min_max_amount()`). Its fallback path calls `YayCurrency/FallbackCurrency/GetCouponAmount` without the coupon.
  - It saves only the rate after fee on the order (`yay_currency_order_rate`, `Hooks::custom_set_yay_currency_order_info()`).
  - The switcher posts a form with `yay-currency-nonce`, sets its cookie and reloads the page (`YayCurrencyHelper::get_currency_change_switcher()`).
  - Cache mode renders pages in base currency (`SupportHelper::force_base_currency_on_page_render()`) and refreshes prices through `yay-caching.js`.
- **Settings and integrations.**
  - Settings live in the option `yayloyalty_settings`, through `SettingsHelper` (defaults plus `schema()`, coerce on read and write; `SCHEMA_VERSION` = `2`).
  - Settings → General is `apps/admin/src/pages/settings/GeneralSettingsPage.tsx`: one form, one Save.
  - The integration pattern is `includes/Integrations/YayMail/YayMailIntegration.php`, constructed in `includes/YayLoyalty.php` before Pro boots.
- **Load order.** Both plugins boot on `plugins_loaded` at priority 10, and `YayLoyalty/` loads before `YayCurrency/`. `YAY_CURRENCY_VERSION` is defined when YayCurrency's main file loads, so it already exists when YayLoyalty boots.
- **Release.** The plugin has not been released. There are no installs to migrate, and this feature ships in the first release.

## Scope

**In:**
- A YayCurrency integration in `includes/Integrations/YayCurrency/`, shipped in Lite and Pro.
- A Lite rate helper, `includes/Utils/CurrencyRate.php`, used by every conversion in the plugin.
- Earning converted to base currency, in Lite and Pro. This includes the product-page earn line, the cart and checkout earn preview, the thank-you block and the launcher's advertised earn rate and minimum.
- Redemption in the shopper's currency for the points slider (`yayloyalty-redeem`) and every reward coupon (`yayloyalty-reward-{id}`), on classic and block checkout.
- A guard that stops YayCurrency converting loyalty coupons a second time.
- Storefront amounts in the shopper's currency:
  - the launcher (guest and member panels);
  - My Account → Rewards;
  - the `[yayloyalty_balance]` / `[yayloyalty_ways_to_earn]` shortcodes and the "Points balance" / "Ways to earn" blocks;
  - the redeem card;
  - Pro's Rewards panel.
- Saving the rate on each non-base order.
- A Settings → General card, in Lite and Pro, with the `currency_include_fee` switch. It shows only while YayCurrency is active.
- Keeping YayCurrency's conversion off for YayLoyalty's own admin REST requests.
- Doc updates: a YayCurrency entry in `docs/architecture.md` next to the YayMail one, the component added to its boot-order sentence, and a money rule in `docs/conventions.md` (see Constraints).
- Two unit tests (acceptance criteria 15 and 16).

**Out:**
- **Emails.** They keep showing the base currency.
- **Cache mode.** Refreshing the launcher on pages cached in YayCurrency's cache mode is deferred to a later release. Until then those pages show base-currency amounts.
- **Other multi-currency plugins.**
- **Any change to YayCurrency's code.**
- **Merchant-entered fixed rates per currency, and a YayLoyalty rounding setting.** The 2026-10-07 spec's Pro "Currency and rounding" card and its `yayloyalty/v1/currency-rates` route are dropped.
- **Converting stored balances.** Points are stored as points; only their cash value converts.
- **Pro campaign conditions.** `lifetime_spend` stays counted per currency (`CustomerFactsReader`), and the `currency` condition is unchanged.
- **Remote rate lookups.** `RateHelper::get_rate_fee_from_currency_not_exists_in_list()` is never called.
- **Admin reports and the admin SPA.** They keep showing the base currency.

## Acceptance criteria

Unless stated otherwise: YayCurrency active, base ₫ (0 decimals), USD with `rate` `0.00004`, fee `0`, rounding disabled, USD at 2 decimals. One purchase campaign at `points_per_unit` `0.00004` (1 point per 25,000 ₫) rounding down. The Flexible discount reward at `points_per_currency_unit` `0.00004` (1 point = 25,000 ₫). The shopper views USD.

1. **Earning.** A completed order of $1.00 earns **1** point. A completed order of 25,000 ₫ earns **1** point.
2. **Saved rate wins.** An order placed at USD `0.00004` keeps earning at that rate after the merchant changes USD to `0.00005` before completion: the $1.00 order still earns 1 point.
3. **No saved rate.** A USD order created in wp-admin (no `_yayloyalty_currency_rate`) earns at YayCurrency's live USD rate. An order in a currency YayCurrency doesn't list earns at rate `1.0`, and `Log::debug()` records the order ID and currency.
4. **Launcher earn rate.** The launcher's "Ways to earn" purchase row reads "1 Point / $1" (amended 2026-10-26: dev/edd fixed the earn-rate line's format and removed the Design tab's `earn_rate_line`). A campaign minimum of 250,000 ₫ appears as "on orders of $10 or more". The product page for a 25,000 ₫ product shows that it earns 1 point.
5. **Points value.** A member with 3 points sees "worth $3" in the launcher, "You could take $3 off today." in My Account, and "worth $3 off your next order" on the balance placement. "Ways to redeem" reads "1 Point = $1".
6. **Slider.** Applying 1 point on a $5.00 cart shows "$1 off" on the card, a $1.00 totals row and a $1.00 coupon line on the order. At checkout 1 point is debited, on classic and on block checkout.
7. **Slider limits in the shopper's currency.** With `max_percent_of_discountable` `50` on a $5.00 cart and a balance of 10, the slider allows at most 2 points ($2.00): the $2.50 ceiling is 2.5 points, which rounds down to 2.
8. **Fixed reward.** A Pro `fixed_coupon` reward with `amount` `25000` and `min_order` `250000` shows "On orders over $10" and gives $1.00 off a $12.00 cart.
9. **Percent reward.** A Pro `percent_coupon` reward with `percent` `10` and `max_discount` `25000` gives $1.00 off a $20.00 cart (capped) and $0.50 off a $5.00 cart. The percentage itself isn't converted.
10. **No double conversion.** For `yayloyalty-redeem` and every `yayloyalty-reward-{id}`, the amount, minimum and maximum WooCommerce reads after priority 20 equal the values YayLoyalty set. Every other coupon's amounts are unchanged, so YayCurrency still converts them.
11. **Base currency.** With the shopper on ₫, criteria 1 to 9 hold in ₫: "1 Point = 25,000 ₫", 1 point gives 25,000 ₫ off, and a 25,000 ₫ order earns 1 point.
12. **Fee setting.** USD fee is `percentage` `10`.
    - `currency_include_fee` on (the default): the rate is `0.000044`. A $1.10 order earns 1 point, and 1 point = $1.10.
    - Off: the rate is `0.00004`. The same $1.10 order earns 1 point (1.1 rounded down), and 1 point = $1.
    - With a `fixed` fee, the rate is `rate + value`.
    - The rate saved on an order is the one in force when the order was placed.
13. **YayCurrency inactive.** Behaviour is identical to today in both tiers: every rate is `1.0`, the General card is hidden, and `currency_include_fee` stays stored.
14. **Fallback checkout.** When YayCurrency charges checkout in a fallback currency, the card, the totals row and the order line all show the charged currency. The commit debits the points the card showed.
15. **Test.** `tests/unit/Engine/Rewards/ClampTest.php` (unit suite) covers `Clamp::apply()` and `Clamp::points_for()` with a currency rate:
    - at `0.00004`, 1 point is worth `1.00` at 2 decimals, and `points_for( 1.00 )` is 1;
    - with no rate argument, results equal today's;
    - the floor to the currency's decimals still holds (2 points at `points_per_currency_unit` `3` and rate `1.0` give `0.66`).
16. **Test.** `tests/unit/Integrations/YayCurrency/CurrencyRateMathTest.php` (unit suite) covers:
    - the resolution order: saved order rate, then live rate, then `1.0`;
    - the fee on and off, for `percentage` and `fixed` fees;
    - a zero, negative or non-numeric rate at any step being skipped.
17. **End-to-end** on `yaycommerce.test`, set up as above, with `./scripts/run.sh dev pro`:
    1. As a member with 3 points, switch to USD with YayCurrency's switcher. The launcher shows "worth $3", "1 Point = $1" and "1 Point / $1".
    2. Apply 1 point on classic checkout and place the order. The order shows a $1.00 points discount, and the ledger shows −1.
    3. Complete a $1.00 order. The ledger shows +1.
    4. Repeat step 2 on block checkout.
    5. Switch back to ₫. The same screens show 25,000 ₫ values.
    6. Deactivate YayCurrency. The General card is gone, and amounts read as today.

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static`/`never` types or first-class callables.
- `includes/Integrations/YayCurrency/` and `includes/Utils/CurrencyRate.php` ship in Lite and never name Pro. Pro may call them; never the reverse. `scripts/validate-build.php` must pass on the Lite zip.
- **Detection.** Detect YayCurrency with `defined( 'YAY_CURRENCY_VERSION' )` at boot. YayCurrency classes are named only inside hooks that run after `plugins_loaded`, and only in `YayCurrencyRates`.
- **YayCurrency is read only.** No edits to `../YayCurrency`. Use only its public helpers (`Helper::default_currency_code()`, `YayCurrencyHelper::get_currency_by_currency_code()`, `YayCurrencyHelper::get_rate_fee()`) and the filter `YayCurrency/ConvertPrice/NotPermitted`.
- **Rate direction.** A rate is currency units per 1 base unit, YayCurrency's direction. Base to currency is `amount × rate`; currency to base is `amount ÷ rate`. The base currency's rate is always `1.0`.
- **Money.**
  - Computed money is rounded once, to the target currency's decimals (`wc_get_price_decimals()` in the request that charges it).
  - Redemption values round down (`Clamp`'s existing floor), so a discount never exceeds the points spent.
  - Points are integers.
- **Display rule** (added to `docs/conventions.md#money`): a storefront amount is converted from base where it is computed (payload, view or copy builder). `LauncherCopy::money()` formats an amount already in the current currency and never converts.
- **Displayed rates.** A rate shown after conversion (points per unit) is rounded to 4 significant digits before `LauncherCopy::rate()`. Any rate below 1, converted or not, reads per point instead: "1 Point = 25,000 ₫" and "1 Point / 25,000 ₫" (amended 2026-10-26). With rate `1.0` it is shown unrounded, as today.
- **Ledger.** Only `LedgerService` writes ledger rows and balances. This change adds no writer.
- **Orders.** Order meta goes through WC CRUD (`$order->update_meta_data()`), never `update_post_meta()` (HPOS).
- **No remote HTTP calls.**
- **SPA.** The setting card is not Pro code and renders in both builds. `wcMeta.yaycurrency_active` is integration detection, never a Pro gate.
- **Mirrors.** A TypeScript copy of a PHP shape opens with `Mirrors <PHP symbol>, the source of truth.`

## Approach

**Chosen:** convert inside YayLoyalty into the currency the cart is charged in, with YayCurrency's rates. Earning converts order amounts to base, and display converts base amounts to the shopper's currency. Redemption prices points in the shopper's currency, and a WooCommerce-level guard keeps YayCurrency from converting loyalty coupons again.

**Architecture:**
- **One helper.** `CurrencyRate` (Lite) is the only place the plugin asks for a rate. Without YayCurrency it answers `1.0`, so behaviour is exactly today's.
- **The integration supplies the rate.** `YayCurrencyIntegration`, booted from `includes/YayLoyalty.php` right after `YayMailIntegration`, answers the new `yayloyalty_currency_rate` filter. It resolves the rate through the pure `CurrencyRateMath`: saved order rate, then YayCurrency's live rate (after fee only when `currency_include_fee` is on), then `1.0`.
- **Earning** gets `1 / rate` through the existing `yayloyalty_context_base_rate` default, so every engine consumer converts without a change of its own.
- **Redemption.** `Clamp` divides the reward's base-currency `points_per_currency_unit` by the rate, so the card, the cart, the order line and `CommitWriter`'s re-check all work in one currency. Reward coupon props are multiplied by the rate before WooCommerce sees them.
- **Display** converts base amounts at their source with the same rate. The switcher needs nothing from us: its page reload makes the next request's `get_woocommerce_currency()` return the new currency.

**Components:**
- **`includes/Utils/CurrencyRate.php`** (Lite, final, static):
  - `current(): string` is `get_woocommerce_currency()`, the charged currency.
  - `rate( string $currency, ?\WC_Order $order = null ): float` applies `yayloyalty_currency_rate` (default `1.0`). It returns `1.0` for the base currency (`get_option( 'woocommerce_currency' )`) and for a result ≤ 0 or non-numeric.
  - `from_base( float $amount, ?string $currency = null ): float` is `$amount × rate( $currency ?? current() )`, not rounded.
  - `to_base( float $amount, string $currency, ?\WC_Order $order = null ): float` is `$amount ÷ rate( $currency, $order )`, not rounded.
- **`includes/Integrations/YayCurrency/YayCurrencyIntegration.php`** is a singleton like `YayMailIntegration`. It returns early unless `defined( 'YAY_CURRENCY_VERSION' )`. Its `is_active(): bool` answers the same check. It hooks:
  - `yayloyalty_currency_rate` (priority 10, 3 args), which resolves through `YayCurrencyRates` and `CurrencyRateMath`;
  - `woocommerce_checkout_create_order` (priority 20, 1 arg) and `woocommerce_store_api_checkout_update_order_meta` (priority 20, 1 arg). Both call `OrderRateSnapshot::save( $order )`;
  - `woocommerce_coupon_get_amount`, `woocommerce_coupon_get_minimum_amount` and `woocommerce_coupon_get_maximum_amount` (priority 20, 2 args). Each returns the coupon's `'edit'`-context value when `Engine\Points\Context::is_loyalty_code( $coupon->get_code() )`, and the incoming value otherwise;
  - `YayCurrency/ConvertPrice/NotPermitted`, which returns `true` when the request URI targets the `yayloyalty/v1` REST namespace (pretty `/{rest_get_url_prefix()}/yayloyalty/v1/` or `?rest_route=/yayloyalty/v1/`), and the incoming value otherwise.
- **`includes/Integrations/YayCurrency/YayCurrencyRates.php`** is the only class that calls YayCurrency.
  - `live_rate( string $currency, bool $include_fee ): ?float`: `YayCurrencyHelper::get_rate_fee( $c )` when the fee is included, `(float) $c['rate']` when not; `null` when the currency isn't listed.
  - `base_currency(): string`: `Helper::default_currency_code()`.
- **`includes/Integrations/YayCurrency/CurrencyRateMath.php`** is pure, with no WordPress. `resolve( ?string $saved, ?float $live ): float` returns the first candidate above 0 that is numeric, else `1.0`. `fee_rate( float $rate, array $fee, bool $include ): float` mirrors `YayCurrencyHelper::get_rate_fee()` for the unit test.
- **`includes/Integrations/YayCurrency/OrderRateSnapshot.php`**
  - `save( \WC_Order $order ): void` writes meta `_yayloyalty_currency_rate` = `wc_format_decimal( CurrencyRate::rate( $order->get_currency() ), 10 )`, but only when the order currency isn't the base currency and the meta is absent. It never overwrites.
  - `read( \WC_Order $order ): ?string` returns it.
- **`includes/YayLoyalty.php`** constructs `YayCurrencyIntegration::get_instance()` right after `YayMailIntegration::get_instance()`.
- **`includes/Engine/Points/ContextFactory.php`**: `base_rate()`'s filter default becomes `1 / CurrencyRate::rate( $currency, $order )`. `yayloyalty_context_base_rate` is still applied on top, unchanged.
- **`includes/Engine/Storefront/LauncherEarnRules.php`**, `advertise()`:
  - the purchase value becomes `$advert['value'] * $ctx->get_base_rate()`;
  - `purchase_min` becomes `PurchaseHandler::min_subtotal(…) / $ctx->get_base_rate()`;
  - the converted rate is rounded per Constraints.
- **`includes/Engine/Rewards/Context.php`** gains `float $currency_rate = 1.0` as its last constructor argument, and `get_currency_rate()`.
- **`includes/Engine/Rewards/ContextFactory.php`**: `from_cart()` passes `CurrencyRate::rate( CurrencyRate::current() )`. `from_order()` and `from_order_line()` pass `CurrencyRate::rate( $order->get_currency(), $order )`, and `from_order()` calls `Clamp::points_for( $ours, $reward, $rate )`.
- **`includes/Engine/Rewards/Clamp.php`**: `apply( …, int $decimals, float $currency_rate = 1.0 )` and `points_for( float $value, Reward $reward, float $currency_rate = 1.0 )`. The effective rate is `points_per_currency_unit / $currency_rate`, and a `$currency_rate` ≤ 0 reads as `1.0`. The docblock states that `$discountable_amount` and the result are in the charged currency.
- **`includes/Engine/Rewards/Handlers/FlexibleDiscountHandler.php`** passes `$ctx->get_currency_rate()` to `Clamp::apply()`.
- **`includes/Engine/Redemption/RewardCoupons.php`** gains `in_currency( array $props, float $rate ): array`. It multiplies `amount` (for `fixed_cart` only), `minimum_amount` and `max_discount` by `$rate` when set, and leaves `percent` amounts unchanged.
- **`includes/Engine/Redemption/RedemptionService.php`**: `coupon_props_of()` returns `RewardCoupons::in_currency( $props, CurrencyRate::rate( CurrencyRate::current() ) )`.
- **`includes/Pro/Services/DiscountRewardHandler.php`**: `quote()` uses `RewardCoupons::in_currency( $this->coupon_props( $reward ), $ctx->get_currency_rate() )` for the probe and the cap.
- **`includes/Engine/Storefront/LauncherPayload.php`**:
  - `worth()` passes `CurrencyRate::rate( CurrencyRate::current() )` to `Clamp::apply()`;
  - `redeem_rule()` returns `points_per_unit` divided by that rate, rounded per Constraints.
- **`includes/Engine/Storefront/LauncherCopy.php`**: `coupon_reward_line()` converts `max_discount`, `min_order` and `amount` with `CurrencyRate::from_base()` before `money()`.
- **Settings:**
  - `includes/Helpers/SettingsHelper.php`: default `'currency_include_fee' => true`, and schema `'currency_include_fee' => 'bool'`;
  - `apps/admin/src/lib/schema/settings.schema.ts`: `currency_include_fee: z.boolean()`;
  - `includes/Helpers/LocalizeHelper.php`: `wcMeta.yaycurrency_active` (bool) from `defined( 'YAY_CURRENCY_VERSION' )`;
  - `apps/admin/src/types/localize.type.ts`: the matching field.
- **SPA:**
  - `GeneralSettingsPage.tsx` gets a `YayCurrencyCard` component defined in the same file, rendered after the Fraud limits card when `wcMeta.yaycurrency_active`;
  - `general-settings.form.ts` adds `currency_include_fee` to the form schema, defaults and update;
  - `general-settings.copy.ts` holds the card's strings.
- **Docs:** `docs/architecture.md` (YayCurrency entry, boot order) and `docs/conventions.md#money` (the display rule).

**Data and storage:**
- **Option `yayloyalty_settings`**, root key `currency_include_fee`: bool, default `true`. No migration; a missing key reads as `true`, and `SettingsHelper::SCHEMA_VERSION` stays `2`.
- **Order meta `_yayloyalty_currency_rate`**: a decimal string at 10 decimals, holding the rate in force when the order was placed.
  - Written only for non-base orders placed through classic or block checkout. Never rewritten.
  - Kept with the order. The plugin has no uninstall routine, and none is added.
- **No new tables.** No change to the `redemptions` table, which already stores `currency` per row.

**Integration points:**
- **Filters.**
  - `yayloyalty_currency_rate` (new, Lite) takes `( float $rate, string $currency, ?\WC_Order $order )`. Docblock: currency units per 1 base unit, default `1.0`.
  - `yayloyalty_context_base_rate` (existing, unchanged signature; default now derived from `CurrencyRate`).
  - `woocommerce_coupon_get_amount`, `woocommerce_coupon_get_minimum_amount` and `woocommerce_coupon_get_maximum_amount` at priority 20.
  - `YayCurrency/ConvertPrice/NotPermitted`.
- **Actions:** `woocommerce_checkout_create_order` and `woocommerce_store_api_checkout_update_order_meta` at priority 20.
- **YayCurrency reads**, only in `YayCurrencyRates`: `Helper::default_currency_code()`, `YayCurrencyHelper::get_currency_by_currency_code()` and `YayCurrencyHelper::get_rate_fee()`.
- **Shared code changed.**
  - `Clamp::apply()` and `points_for()` get new trailing parameters with defaults. Callers: `FlexibleDiscountHandler`, `LauncherPayload`, `Rewards\ContextFactory`.
  - The `Rewards\Context` constructor gets a trailing argument with a default.
  - Pro's `DiscountRewardHandler` calls the new Lite `RewardCoupons::in_currency()`.

**UI:**
- **Card.** Settings → General, after the Fraud limits card, in both builds, only when `wcMeta.yaycurrency_active`. It's a shadcn `Card` with title "YayCurrency" and description "Points keep the same value in every currency your shoppers pick. Amounts convert at YayCurrency's exchange rates."
- **Switch.** One `Switch` (`components/ui/switch.tsx`), `id` `currency_include_fee`, labelled "Include YayCurrency's exchange fee". Hint: "On: a point buys the same product in every currency. Off: points convert at the plain exchange rate."
- **Saving.** It saves with the page's existing Save, and only when the field is dirty.

**Edge cases and failure modes:**
- **YayCurrency deactivated:** every rate is `1.0`, the card is hidden, the stored setting and order meta are kept, and amounts read as today.
- **Only the base currency is configured:** every rate is `1.0`.
- **The shopper switches currency with points applied:** the next totals run re-quotes in the new currency, because `CouponProvider` re-derives amounts on every run. Nothing is stored in a currency.
- **The order currency isn't listed and the order has no saved rate:** rate `1.0`, plus a `Log::debug()` entry with the order ID and currency.
- **A stored or live rate ≤ 0 or non-numeric:** it's skipped in the resolution order.
- **Fallback checkout:** `CurrencyRate::current()` follows `get_woocommerce_currency()`, which YayCurrency sets to the charged currency. Acceptance criterion 14 verifies this.
- **Block checkout draft orders:** `woocommerce_new_order` can fire before `woocommerce_store_api_checkout_update_order_meta`. Staging on a draft without a saved rate uses the live rate. The saved rate is in place before `woocommerce_store_api_checkout_order_processed` (the commit).
- **YayCurrency price rounding** (`roundingType` `down`): an order at a boundary may earn one point less. Accepted.
- **Fixed prices per currency:** earning uses the amount paid, converted at the rate.
- **Admin requests:** no conversion runs in wp-admin. `yayloyalty/v1` REST requests opt out through `YayCurrency/ConvertPrice/NotPermitted`, so the Design preview and the admin SPA show the base currency even when the admin's browser has a YayCurrency cookie.
- **Cache mode:** cached pages show base-currency launcher amounts until the deferred work ships. My Account, cart and checkout are never cached by YayCurrency (`YayCurrency/Caching/NoCachePages`).
- **A refund or cancellation:** clawback stays proportional in the order's currency (`ClawbackMath::owed()`), and redemption restores are in points, so no rate is involved.

**Decision record:** None. This builds on the existing `yayloyalty_context_base_rate` seam and the virtual-coupon decision ([2026-10-14](../decisions/2026-10-14-loyalty-discounts-as-virtual-coupons.md)), and reverses no architectural choice.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Let YayCurrency convert loyalty coupons, and keep YayLoyalty's math in base | Two conversions (ours for display, YayCurrency's for the coupon) would have to agree to the cent under YayCurrency's rounding, and every cart and order read would need converting back. |
| A compat class inside YayCurrency | Changing YayCurrency's code is not allowed, and it would put the ledger's rules in another team's release cycle. |
| Guard through `YayCurrency/GetCouponAmount` and `YayCurrency/StoreCurrency/GetCouponAmount` | `YayCurrency/FallbackCurrency/GetCouponAmount` carries no coupon, so the fallback path couldn't be guarded. |
| Merchant-set fixed rates per currency (the 2026-10-07 spec) | A fixed rate breaks "same value in every currency"; YayCurrency's rates are the single source. |
| YayLoyalty rounding setting (down / nearest) | Redemption already floors to the currency's smallest unit; a second setting adds no value. |
| Use YayCurrency's `yay_currency_order_rate` for orders | It always includes the fee, so it can't honour `currency_include_fee` off. |
| Treat an order without a saved rate as base (`1.0`) | A foreign-currency admin order would earn on its raw number. |
| Hide redemption during fallback checkout | Shoppers would lose redemption in a supported YayCurrency mode. |
| A generic multi-currency provider layer | No current need; only YayCurrency is in scope. |
| Convert inside `LauncherCopy::money()` | Some callers pass amounts already in the shopper's currency (quote values), which would be converted twice. |

## Rollout

None. The plugin has not been released; this ships in the first release, with `currency_include_fee` defaulting to `true`.

## Open questions

None.
