# YayCurrency Integration Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** done 2026-10-26
**Goal:** Points keep the same value in every currency a shopper picks in YayCurrency, for earning, storefront display and redemption, in Lite and Pro.
**Spec:** [docs/specs/2026-10-23-yaycurrency-integration-design.md](../../specs/2026-10-23-yaycurrency-integration-design.md)
**Architecture:** One Lite helper, `CurrencyRate`, answers every rate question through the new `yayloyalty_currency_rate` filter. A Lite integration in `includes/Integrations/YayCurrency/` answers that filter from YayCurrency's rates, saves the rate on each non-base order, and guards loyalty coupons from YayCurrency's second conversion. Earning converts to base through the existing `yayloyalty_context_base_rate` default. Display and redemption convert base config values into the charged currency where they are computed. The alternative, letting YayCurrency convert our coupons while we stay in base, was rejected in the spec: two conversions would have to agree to the cent.
**Touches:**
- `includes/Utils/CurrencyRate.php` and `includes/Utils/PointsMath.php` (shared: Engine, Storefront, Redemption, Pro)
- `includes/Integrations/YayCurrency/*` (new)
- `includes/YayLoyalty.php`
- `includes/Engine/Points/ContextFactory.php` (shared: every earning path, the product earn line, the launcher advert)
- `includes/Engine/Points/Triggers/PurchaseHandler.php`
- `includes/Engine/Storefront/LauncherEarnRules.php`, `LauncherPayload.php`, `LauncherCopy.php`
- `includes/Engine/Rewards/Clamp.php` (shared: `FlexibleDiscountHandler`, `LauncherPayload`, `Rewards\ContextFactory`)
- `includes/Engine/Rewards/Context.php` (shared: `Quoter`, the gates, every `RewardHandler`)
- `includes/Engine/Rewards/ContextFactory.php`, `includes/Engine/Rewards/Handlers/FlexibleDiscountHandler.php`
- `includes/Engine/Redemption/RewardCoupons.php` (shared: `RedemptionService`, Pro `DiscountRewardHandler`)
- `includes/Engine/Redemption/RedemptionService.php`
- `includes/Pro/Services/DiscountRewardHandler.php`
- `includes/Helpers/SettingsHelper.php`, `includes/Helpers/LocalizeHelper.php`
- `apps/admin/src/lib/schema/settings.schema.ts`, `apps/admin/src/types/localize.type.ts`
- `apps/admin/src/pages/settings/GeneralSettingsPage.tsx`, `general-settings.form.ts`, `general-settings.copy.ts`
- `docs/architecture.md`, `docs/conventions.md`

**Tests:** accepted with the spec (acceptance criteria 15 and 16):
- `tests/unit/Integrations/YayCurrency/CurrencyRateMathTest.php`, unit suite. Guards the rate resolution order (saved order rate, then live rate, then `1.0`), fee on and off for `percentage` and `fixed`, and skipping zero, negative and non-numeric rates.
- `tests/unit/Engine/Rewards/ClampTest.php` additions, unit suite. Guards `Clamp::apply()` and `Clamp::points_for()` with a currency rate (1 point = `1.00` at `0.00004`, the inverse, defaults unchanged, the floor to the currency's decimals).

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static`/`never` types, first-class callables, `catch` without a variable, `throw` as an expression, or a trailing comma after the last declared parameter. Only review catches these.
- `includes/Integrations/YayCurrency/` and `includes/Utils/CurrencyRate.php` ship in Lite and never name Pro. Pro may call them; never the reverse. `scripts/validate-build.php` must pass on the Lite zip.
- **Detection.** Detect YayCurrency with `defined( 'YAY_CURRENCY_VERSION' )` at boot. YayCurrency classes are named only inside hooks that run after `plugins_loaded`, and only in `YayCurrencyRates`.
- **YayCurrency is read only.** No edits to `../YayCurrency`. Use only `Helper::default_currency_code()`, `YayCurrencyHelper::get_currency_by_currency_code()`, `YayCurrencyHelper::get_rate_fee()` and the filter `YayCurrency/ConvertPrice/NotPermitted`.
- **Rate direction.** A rate is currency units per 1 base unit, YayCurrency's direction. Base to currency is `amount × rate`; currency to base is `amount ÷ rate`. The base currency's rate is always `1.0`.
- **Money.**
  - Computed money is rounded once, to the target currency's decimals (`wc_get_price_decimals()` in the request that charges it).
  - Redemption values round down (`Clamp`'s existing floor).
  - Points are integers.
- **Display rule:** a storefront amount is converted from base where it is computed. `LauncherCopy::money()` formats an amount already in the current currency and never converts.
- **Displayed rates.** A rate shown after conversion is rounded to 4 significant digits before `LauncherCopy::rate()`. A converted rate below 1 is kept exact, and any rate below 1, converted or not, is printed per point instead ("1 Point = 25,000 ₫", "1 Point / 25,000 ₫"), through `LauncherCopy::converted_rate()`; decided 2026-10-26 after a USD-base store read "0,00004 Points = 1 ₫". With rate `1.0` it is shown unrounded.
- **Ledger.** Only `LedgerService` writes ledger rows and balances. This plan adds no writer.
- **Orders.** Order meta goes through WC CRUD (`$order->update_meta_data()`), never `update_post_meta()`.
- **No remote HTTP calls.**
- **SPA.** The YayCurrency card is not Pro code and renders in both builds. `wcMeta.yaycurrency_active` is integration detection, never a Pro gate.
- **Mirrors.** A TypeScript copy of a PHP shape opens with `Mirrors <PHP symbol>, the source of truth.`
- **Style.** WordPress-Extra with this repo's exceptions (4-space indents, `[]` arrays). Logging only through `Log::error()`, `warning()` and `debug()`. Every hook the plugin fires has a docblock. New hooks attach in a `register()` method or the singleton constructor (boot class).
- **Settings keys** go in both `SettingsHelper::get_default_settings()` and `schema()`, then in the zod `settingsSchema`.
- **Git.**
  - Work on `jason/feat/Integration-yaycurrency`, branched from `dev/edd`.
  - Conventional Commits `type(scope): summary`, ending with `Co-Authored-By: Claude <model> <noreply@anthropic.com>`.
  - Stage files by name. Commit only when the user asks.
- **Checks already failing on `dev/edd`** ([docs/known-issues.md](../../known-issues.md)): `composer lint` (warnings in three unrelated files) and the admin lint (one `no-empty` error plus warnings). Judge both on the changed files only: no new error or warning.

## Review focus

- **Float error at an exact boundary.** A $10.00 order against a 250,000 ₫ campaign minimum computes `249999.99999999997` and must still qualify. Phase 1, Task 3 snaps the comparison; its verification step places that order.
- **Block checkout draft orders.** `woocommerce_new_order` can stage points on a draft before `woocommerce_store_api_checkout_update_order_meta` saves the rate. The final award must still be right. Phase 1, Task 3 verifies a block-checkout $1.00 order earns 1 point.
- **The rate changes between placing and completing an order.** The saved rate must win. Phase 1, Task 3, criterion 2 step.
- **An admin with a YayCurrency cookie opens the Design preview.** It must show ₫ amounts. Phase 1, Task 3 verification.
- **The shopper switches currency with points applied.** The next totals run must re-quote in the new currency, and checkout must debit what the card showed. Phase 3, Task 1 verification.
- **Fallback checkout.** The card, the totals row and the order line must all be in the charged currency. Phase 3, Task 1 verification (criterion 14).
- **The redemption rate snapshot and the redeem card's live preview.** `redeem.js` divides by `Result::get_rate_snapshot()`, so the snapshot must be the effective rate in the charged currency. Phase 2, Task 1 sets it; Phase 3, Task 1 verifies the slider preview.
- **Reward coupons before their props convert.** The guard must not cover `yayloyalty-reward-*` until Phase 3, Task 2 converts their props. Otherwise a 25,000 ₫ reward would take $25,000 off. Phase 3, Task 1 guards `CouponProvider::CODE` only; Task 2 widens it.

## Test site setup (once, before Phase 1 verification)

On `yaycommerce.test`:
- **YayCurrency:** base ₫ (VND, 0 decimals). Add USD with rate `0.00004`, fee `0` (type `fixed`), rounding `disabled`, 2 decimals.
- **YayLoyalty:**
  - the default purchase campaign at 1 point per 25,000 ₫ (`points_per_unit` `0.00004`), rounding down;
  - the Flexible discount reward at 1 point = 25,000 ₫ (`points_per_currency_unit` `0.00004`), `min_points` `1`, `increment` `1`.
- **Dev server:** run `./scripts/run.sh dev pro`.
- **Products:** one simple product at 25,000 ₫ and one at 250,000 ₫.
- **Customer:** one customer account.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | Foreign-currency orders earn on their base value. The launcher's earn rate converts. The fee setting works. | Spec criteria 1, 2, 3, 4, 12 and 13 pass on the test site, and `composer test` passes. | done 2026-10-26 |
| 2 | Launcher, My Account, placements and reward text show amounts in the shopper's currency. | Spec criteria 5, 8 (text) and 11 (display) pass, and `composer test` passes, including the new `ClampTest` cases. | done 2026-10-26 |
| 3 | The slider and every reward redeem in the shopper's currency, on classic and block checkout. | Spec criteria 6, 7, 8, 9, 10, 11 and 14 pass, then the spec's end-to-end walkthrough (criterion 17) passes in full. | done 2026-10-26 |

## Interface contract

- `YayLoyalty\Integrations\YayCurrency\CurrencyRateMath::resolve( ?string $saved, ?float $live ): float`: the first candidate that is numeric and above 0, else `1.0`. Produced by P1T1, consumed by P1T3.
- `CurrencyRateMath::fee_rate( float $rate, array $fee, bool $include ): float`: `$rate` when `! $include`; `$rate + $rate × value / 100` for `type` `percentage`; `$rate + value` otherwise. Mirrors `YayCurrencyHelper::get_rate_fee()`, which `YayCurrencyRates::live_rate()` calls in production as the spec requires. This copy exists for the unit test. Produced by P1T1.
- `YayLoyalty\Utils\CurrencyRate` (final, static). Produced by P1T2, consumed by P1T3, P1T5, P2 and P3:
  - `current(): string`
  - `rate( string $currency, ?\WC_Order $order = null ): float`
  - `from_base( float $amount, ?string $currency = null ): float`
  - `to_base( float $amount, string $currency, ?\WC_Order $order = null ): float`
- Filter `yayloyalty_currency_rate`, `( float $rate, string $currency, ?\WC_Order $order ): float`, default `1.0`. Applied by P1T2, subscribed by P1T3.
- `YayLoyalty\Integrations\YayCurrency\YayCurrencyIntegration` (singleton, `SingletonTrait`): `get_instance()` and `is_active(): bool`. Produced by P1T3; the coupon guard is added by P3T1 and widened by P3T2.
- `YayLoyalty\Integrations\YayCurrency\YayCurrencyRates`: `live_rate( string $currency, bool $include_fee ): ?float` and `base_currency(): string`. Produced by P1T3.
- `YayLoyalty\Integrations\YayCurrency\OrderRateSnapshot`: `META_KEY = '_yayloyalty_currency_rate'`, `save( \WC_Order $order ): void` and `read( \WC_Order $order ): ?string`. Produced by P1T3.
- Setting `yayloyalty_settings` → `currency_include_fee`: `bool`, default `true`. Backend by P1T3, UI by P1T4.
- `wcMeta.yaycurrency_active`: `bool`. Produced by P1T4.
- `YayLoyalty\Utils\PointsMath::round_significant( float $value, int $digits = 4 ): float`. Produced by P1T5, consumed by P2T2.
- `Clamp::apply( Reward $reward, int $requested, int $balance, ?float $cart_subtotal, string $currency, int $decimals, float $currency_rate = 1.0 ): Result` and `Clamp::points_for( float $value, Reward $reward, float $currency_rate = 1.0 ): int`. The effective rate is `points_per_currency_unit / $currency_rate`, and `Result::get_rate_snapshot()` returns that effective rate. Produced by P2T1, consumed by P2T2 and P3T1.
- `Rewards\Context::__construct( …, ?\WC_Cart $cart = null, float $currency_rate = 1.0 )` and `get_currency_rate(): float`. Produced by P3T1, consumed by P3T2.
- `RewardCoupons::in_currency( array $props, float $rate ): array`. Produced by P3T2.
- Coupon guard on `woocommerce_coupon_get_amount`, `woocommerce_coupon_get_minimum_amount` and `woocommerce_coupon_get_maximum_amount` at priority 20, 2 args. Produced by P3T1 for `CouponProvider::CODE`; P3T2 widens it to `Engine\Points\Context::is_loyalty_code()`.
