# YayCurrency Currency and Rounding Design

**Status:** proposed
**Related:** design file `YayLoyalty Settings.dc.html` → Settings: Advanced (Pro), "Currency and rounding" card, callout 3

## Goal

On a store running YayCurrency, earning stops treating a foreign-currency order as if it were in the base currency, in Lite and Pro alike. A Pro merchant also decides on Settings → General how a point's cash value converts into each currency (YayCurrency's live rate, or a fixed rate per currency) and how a converted value rounds (down or nearest).

## Context

- Earning already has a multi-currency seam that nothing hooks. `ContextFactory::base_rate()` (`includes/Engine/Points/ContextFactory.php`) applies `yayloyalty_context_base_rate` (event currency → base currency multiplier, default `1.0`), and `BaseCalculator::compute()` multiplies the earning base by it. Today a €100 order earns as if it were $100.
- A point's value is set per reward, not globally: the `Reward` config `points_per_currency_unit` is in the store's base currency (`includes/Models/Reward.php`), and `Clamp::apply()` turns points into money through `PointsMath::points_to_currency()`, which rounds to nearest at the given decimals.
- Redemption is scaffolding: `RedemptionService`, `CouponProvider`, `Rewards\ContextFactory`, `FlexibleDiscountHandler` and both checkout adapters throw `Not implemented`. When built, `CouponProvider` adds a virtual `fixed_cart` coupon with code `CouponProvider::CODE`, quoted in the cart's currency (`get_woocommerce_currency()`).
- Clawback (`ClawbackMath::owed()`) is proportional in order currency, so the rate in force at refund time does not matter.
- YayCurrency 3.3.5:
  - Currencies are `yay-currency-manage` posts. A save updates them in place (`RestAPI::set_currency_manage_settings`), so post IDs are stable; deleting a currency deletes its post.
  - `YayCurrencyHelper::get_rate_fee( $currency )` is the rate after YayCurrency's fee, the rate products are priced at.
  - On a non-base order it stores `yay_currency_order_rate` (rate after fee) as order meta.
  - It converts every non-percent coupon amount on `woocommerce_coupon_get_amount`, then passes the result through `YayCurrency/GetCouponAmount` (or `YayCurrency/StoreCurrency/GetCouponAmount` when checkout falls back to base). Left alone, it would convert our already-converted discount a second time.
- General tab: `apps/admin/src/pages/settings/GeneralSettingsPage.tsx` is one form with one Save. Pro sections follow `apps/admin/src/pro/settings/FraudLimitsSection.tsx`; Lite shows `FraudLimitsUpsellCard` with `<ProGate />`.
- Settings storage: one option `yayloyalty_settings` through `SettingsHelper` (whitelist + coerce on read and write). Localized admin data: `LocalizeHelper` (`wcMeta`).
- Integration pattern: `includes/Integrations/YayMail/` holds a `YayMailIntegration` singleton that returns early when its plugin is absent. `includes/YayLoyalty.php` constructs it in both tiers, before Pro boots.
- Load order: both plugins boot on `plugins_loaded` at priority 10, and `YayLoyalty/` loads before `yaycurrency/`. So at YayLoyalty's boot YayCurrency's `plugin_init` has not run yet, but its `YAY_CURRENCY_VERSION` constant (defined at file load) already exists.

## Scope

**In:**
- A new integration folder, `includes/Integrations/YayCurrency/`, shaped like `includes/Integrations/YayMail/` and shipped in both tiers.
- Earning conversion in Lite and Pro, through the existing `yayloyalty_context_base_rate` seam.
- The "Currency and rounding" card in Pro, matching the design. It shows when YayCurrency is active.
- A Lite card with the same title and a `<ProGate />` upsell, also only when YayCurrency is active.
- A Lite helper that exposes the rate seam and a new rounding seam, so the redemption work calls them, plus a `$mode` argument on `PointsMath::points_to_currency()`.
- A coupon guard that stops YayCurrency from converting our redemption coupon twice. It does nothing until redemption ships.
- A Pro REST route for the per-currency fixed rates.
- Two unit tests (see Acceptance criteria 11 and 12).
- A YayCurrency entry in `docs/architecture.md`, next to the YayMail one, and the new component in its boot-order sentence.

**Out:**
- Editing currency settings in Lite. Lite runs on the stored values, or the defaults `live` and `down`.
- Checkout redemption itself: quoting, the coupon and the commit stay with the redemption work.
- The "Currency in emails" row on the Emails tab.
- The YayPricing and Yay Wholesale B2B integrations.
- Any remote rate lookup. `YayCurrencyHelper::get_rate_fee_from_currency_not_exists_in_list()` is never called.
- A new global "points value" setting. Rates stay per reward.
- Converting a customer's stored balance. Points are stored as points; only their cash value converts.

## Acceptance criteria

1. **Live rate, earning.** Lite or Pro, YayCurrency active, mode `live`, EUR rate after fee `0.91`, a 1 point per $1 campaign rounding down. When a €100 order completes, it earns `floor(100 / 0.91)` = **109** points. When the order has `yay_currency_order_rate`, that value is the rate used, not today's rate.
2. **Fixed rate, earning.** Mode `fixed` with EUR `yayloyalty_fixed_rate` = `"0.8000"`: the same order earns **125**. With the EUR fixed rate blank, EUR falls back to the live rate (109).
3. **Inactive.** With YayCurrency inactive, earning is identical to today in both tiers: the rate is `1.0` and €100 earns 100.
4. **Rounding.** `PointsMath::points_to_currency( 1200, 100 / 0.9149, 2, 'down' )` returns `10.97`, and with `'nearest'` it returns `10.98`. With no mode it returns the nearest value, as today. `'down'` is safe from float error: 1,200 points at 100 points per unit, 2 decimals, `'down'` returns `12.00`, not `11.99`.
5. **Rounding seam.** `CurrencyRate::rounding( $currency )` returns `currency_rounding` for a non-base currency and `nearest` for the base currency, in either tier with YayCurrency active. With YayCurrency inactive it returns `nearest`.
6. **Coupon guard.** For coupon code `CouponProvider::CODE`, both `YayCurrency/GetCouponAmount` and `YayCurrency/StoreCurrency/GetCouponAmount` return `$coupon->get_amount( 'edit' )`. Every other coupon's amount is unchanged.
7. **REST GET.** `GET yayloyalty/v1/currency-rates` returns:
   - `base_currency`;
   - `currencies`: `[{ code, symbol, decimals, live_rate, fixed_rate }]` for every YayCurrency currency except the base, with `live_rate` a decimal string and `fixed_rate` a decimal string or `null`;
   - `rewards`: `[{ id, name, points_per_currency_unit }]` for the active Flexible discount rewards.
8. **REST POST.** `POST yayloyalty/v1/currency-rates` with `{ "rates": { "EUR": "0.9100" } }` stores meta `yayloyalty_fixed_rate` = `"0.9100"` on the EUR currency post. `"0"`, `"-1"`, `"abc"` or a code that is not a YayCurrency currency returns `400` and writes nothing. `null` deletes the meta. Both routes require the capability the settings routes use.
9. **Settings.**
   - `currency_conversion` is `live` or `fixed`, default `live`.
   - `currency_rounding` is `down` or `nearest`, default `down`.
   - Any other value coerces to the default.
   - Both keys stay in Lite's whitelist, so a downgrade keeps them.
10. **UI.**
    - **Pro with YayCurrency active**, the card shows, in order:
      - the "NEEDS YAYCURRENCY" tag;
      - the line "YayCurrency is active with N currencies. Points are stored as points; only their cash value is converted.";
      - a read-only Base value row that lists each Flexible discount reward's rate ("Points discount: 100 points = $1.00 USD") and links to Rewards;
      - the Other currencies toggle "Convert at the live rate" / "Fixed value per currency";
      - the Rounding toggle "Down" / "Nearest", with the hint "rounding down means a discount never exceeds the points spent";
      - the preview line, e.g. "1,200 points is $12.00 · €10.92 · £9.36 at today's rates", built from the first reward and the unsaved form values.
    - **Fixed** shows one rate input per non-base currency, with the live rate as placeholder.
    - **Lite with YayCurrency active** shows the upsell card.
    - **YayCurrency inactive:** neither card shows.
11. **Test.** `tests/unit/Utils/PointsMathTest.php` covers `points_to_currency()` in `down` and `nearest` modes at 0, 2 and 3 decimals, including the values in criterion 4.
12. **Test.** `tests/unit/Integrations/YayCurrency/CurrencyRateMathTest.php` covers:
    - the resolution order: order rate, then fixed rate (fixed mode only), then live rate, then `1.0`;
    - inversion (`to_base = 1 / rate`);
    - a zero, negative or non-numeric rate at any step being skipped.
13. **End-to-end.**
    - Set up: `./scripts/run.sh dev pro` with YayCurrency configured for USD (base), EUR and GBP.
    - On Settings → General, choose Fixed, set EUR to `0.8000`, choose Down and Save. After a reload, the saved values show.
    - Place and complete a €100 order on a 1 point per $1 campaign. The customer's ledger shows **+125**.
    - Switch to Live and Save. A new €100 order earns `floor(100 / EUR rate after fee)`.
    - Deactivate YayCurrency. The card is gone, and a new order earns 1:1.

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call. Pro attaches only through filters that `boot()` adds. `scripts/validate-build.php` must pass on the Lite zip.
- `includes/Integrations/YayCurrency/` ships in Lite, so it never names Pro. The Pro REST controller may use its classes; never the reverse.
- Detect YayCurrency with `defined( 'YAY_CURRENCY_VERSION' )`, at boot. YayCurrency classes are named only inside hooks that run after `plugins_loaded`.
- SPA Pro code lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD`. `wcMeta.yaycurrency_active` is integration detection, never a Pro gate.
- Money:
  - Rates are decimal strings on the wire and in meta, at 4 decimals.
  - Computed money is rounded once, to the target currency's decimals (YayCurrency `numberDecimal`, or `wc_get_price_decimals()` for the base).
  - Points are integers.
- A TypeScript copy of PHP math or a PHP shape opens with `Mirrors <PHP symbol>, the source of truth.`
- Only `LedgerService` writes ledger rows. This change adds no writer.
- No remote HTTP calls.

## Approach

**Chosen:** one rate seam used in both directions, one rounding seam, and a YayCurrency integration in `includes/Integrations/YayCurrency/` (both tiers), with only the settings card and the fixed-rate REST route in Pro.

**Architecture:** the private `ContextFactory::base_rate()` moves into a public Lite helper, `CurrencyRate::to_base()`, so the redemption work uses the same seam as earning. A reward's rate in currency X is `points_per_currency_unit × CurrencyRate::to_base( X )`; for example, 100 pts/USD at EUR 0.91 is 109.89 pts/EUR. Rounding becomes a second seam that `points_to_currency()` callers read through `CurrencyRate::rounding()`. `YayCurrencyIntegration`, booted from `includes/YayLoyalty.php` next to `YayMailIntegration`, resolves a currency's rate through a pure `CurrencyRateMath`, in this order:

1. The order's `yay_currency_order_rate`.
2. The currency post's fixed rate, in fixed mode only.
3. The live rate after fee.
4. `1.0`.

It returns `1 / rate` to the seam. Without YayCurrency nothing subscribes, and the rate is `1.0` as today. The integration reads `currency_conversion` and `currency_rounding` in both tiers. Lite cannot edit them, so a Lite store runs on the defaults, or on whatever a Pro install stored before a downgrade.

**Components:**
- `includes/Utils/CurrencyRate.php` (Lite) has two methods. `to_base( string $currency, ?\WC_Order $order ): float` applies `yayloyalty_context_base_rate` and treats a value ≤ 0 as `1.0`. `rounding( string $currency ): string` applies `yayloyalty_points_value_rounding` and returns `PointsMath::ROUND_NEAREST` for anything it doesn't know. `Engine\Points\ContextFactory` calls `to_base()` in place of its private `base_rate()`.
- `includes/Utils/PointsMath.php`: `points_to_currency( int $points, $points_per_currency_unit, int $decimals, string $mode = self::ROUND_NEAREST )`. `down` floors at `$decimals` after a `round( …, $decimals + 6 )` guard against float error.
- `apps/admin/src/lib/points-math/`: the matching `mode` argument in the TS mirror.
- `includes/Helpers/SettingsHelper.php`:
  - two root fields, `currency_conversion` and `currency_rounding`;
  - a new coerce type for each (`currency_conversion`, `currency_rounding`) that maps unknown values to the default.
- `includes/Integrations/YayCurrency/YayCurrencyIntegration.php` is a singleton like `YayMailIntegration`, constructed in `includes/YayLoyalty.php` right after it. It returns early unless `defined( 'YAY_CURRENCY_VERSION' )`. It hooks `yayloyalty_context_base_rate`, `yayloyalty_points_value_rounding`, `YayCurrency/GetCouponAmount` and `YayCurrency/StoreCurrency/GetCouponAmount`.
- `includes/Integrations/YayCurrency/YayCurrencyRates.php` is the only class that calls YayCurrency. It supplies the base currency, the currency list (code, symbol, decimals, live rate after fee), a currency by code, and an order's `yay_currency_order_rate`.
- `includes/Integrations/YayCurrency/CurrencyRateMath.php` is pure, with no WordPress: it resolves a rate from the candidate inputs and inverts it.
- `includes/Integrations/YayCurrency/FixedRateStore.php` reads, writes and deletes post meta `yayloyalty_fixed_rate` by currency code.
- `includes/Pro/Controllers/CurrencyRatesRestController.php` serves `yayloyalty/v1/currency-rates` (GET and POST), registered the way the other Pro controllers are. It uses `YayCurrencyRates` and `FixedRateStore`, and it answers `404` when YayCurrency is inactive.
- `includes/Helpers/LocalizeHelper.php` adds `wcMeta.yaycurrency_active` (bool), from `YayCurrencyIntegration::is_active()`.
- SPA:
  - `apps/admin/src/pro/settings/CurrencySection.tsx`, exported from `pro/index.ts`;
  - the Lite `CurrencyUpsellCard`, with its title and upsell copy in `general-settings.copy.ts`;
  - the two fields in `general-settings.form.ts` and `lib/schema/settings.schema.ts`;
  - a currency-rates query and mutation next to `lib/queries/settings.queries.ts`;
  - the preview formatter in `lib/points-math/`.

**Data and storage:**
- Option `yayloyalty_settings` → `currency_conversion` (`live`), `currency_rounding` (`down`). There is no migration: missing keys read as the defaults, and `SettingsHelper::SCHEMA_VERSION` stays `2`.
- Post meta `yayloyalty_fixed_rate` on `yay-currency-manage` posts, a 4-decimal string, is never written for the base currency. It goes when YayCurrency deletes the currency post. The plugin has no uninstall routine (`docs/architecture.md`), and none is added.

**Integration points:**
- Filters:
  - `yayloyalty_context_base_rate` (existing; the integration subscribes, both tiers);
  - `yayloyalty_points_value_rounding` (new; `( string $mode, string $currency )`);
  - `YayCurrency/GetCouponAmount` and `YayCurrency/StoreCurrency/GetCouponAmount` (YayCurrency's; the integration subscribes, scoped to `CouponProvider::CODE`).
- REST: `yayloyalty/v1/currency-rates`.
- YayCurrency reads (only in `YayCurrencyRates`): `Helper::default_currency_code()`, `YayCurrencyHelper::converted_currency()`, `YayCurrencyHelper::detect_current_currency()`, `YayCurrencyHelper::get_currency_by_currency_code()`, `YayCurrencyHelper::get_rate_fee()`, order meta `yay_currency_order_rate`.

**UI:**
- The card sits on Settings → General, after Fraud limits, and is built from the existing shadcn `Card` and the toggle-group style the tab already uses.
- The page's one Save sends the settings update first, then the rates POST, but only when a rate changed.
- If either request fails, the form keeps its edits and an error toast shows (`toastApiError`). If both succeed, both queries refetch and the form resets from the saved values.

**Edge cases and failure modes:**
- YayCurrency is deactivated: the rate is `1.0`, rounding is `nearest`, both cards are hidden, and stored settings and meta are kept.
- YayCurrency has only the base currency: the card says there is nothing to convert, and the toggles are hidden.
- There are no active Flexible discount rewards: the Base value row reads "No points discount reward yet" with a link to Rewards, and the preview line is hidden.
- The order currency is not in YayCurrency and the order has no `yay_currency_order_rate`: the rate is `1.0`, and `Log` writes a debug entry with the order ID and currency.
- Checkout falls back to the base currency: the order is in base, so the rate is `1.0`.
- Downgrade from Pro to Lite: the stored mode, rounding and fixed rates keep applying (Pro data survives Lite), but Lite cannot edit them.
- Stored fixed meta is ≤ 0 or not numeric: it is treated as blank, so the live rate applies.
- A currency is renamed or deleted in YayCurrency: the meta follows the post. A deleted currency's rate is gone, and the merchant re-enters it if they re-add the currency.

**Decision record:** None. This extends the existing `yayloyalty_context_base_rate` seam and reverses no architectural choice.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Settings and panel only | Nothing would read the settings until redemption ships, and earning would stay wrong. |
| Wait for redemption | It would delay the earning fix, and the seam and guard can be built and tested now. |
| Integration under `includes/Pro/Integrations/YayCurrency/` | Lite stores would keep earning a ¥10,000 order as 10,000 base units. The user chose a shared folder like YayMail's. |
| Split: shared rates, Pro-only settings filter | It adds a filter only to hide two settings Lite cannot edit anyway. |
| New global points-value setting | It would change the reward model, and rates are per reward today. |
| Fixed "points per 1 unit" per currency | It would override every reward's own rate with one number. |
| Fixed rates kept in `yayloyalty_settings` | The user chose post meta on YayCurrency's currency posts, which go away with the currency. |
| Raw rate without YayCurrency's fee | A converted discount would not match the prices the shopper sees. |
| Today's live rate for orders | The rate can drift between checkout and completion. The order's stored rate is what the shopper paid at. |

## Rollout

- Existing installs read `live` and `down` by default.
- On any store with YayCurrency, Lite or Pro, earning on new non-base orders converts to base from this release on. The release note says so, for example "€100 orders now earn on their USD value". Points already awarded are not changed.

## Open questions

None.
