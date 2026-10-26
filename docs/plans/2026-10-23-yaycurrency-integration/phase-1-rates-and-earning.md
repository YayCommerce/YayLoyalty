# Phase 1: Rates, earning and the fee setting

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** nothing
**Delivers:** A foreign-currency order earns on its base-currency value. The launcher's "Ways to earn" rate and minimum show in the shopper's currency. Merchants can switch YayCurrency's fee in or out on Settings → General.
**Exit criterion:** on the test site (README setup), spec criteria 1, 2, 3, 4, 12 and 13 pass, and `composer test` prints `OK` with no failures.

### Task 1: Rate math

**Files:**
- Create: `includes/Integrations/YayCurrency/CurrencyRateMath.php`
- Test: `tests/unit/Integrations/YayCurrency/CurrencyRateMathTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `CurrencyRateMath::resolve()` and `CurrencyRateMath::fee_rate()` (see README contract)

- [ ] **Step 1: Write the failing test**

```php
namespace YayLoyalty\Tests\Unit\Integrations\YayCurrency;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Integrations\YayCurrency\CurrencyRateMath;

final class CurrencyRateMathTest extends TestCase {

    public function test_the_saved_order_rate_wins(): void {
        $this->assertSame( 0.00004, CurrencyRateMath::resolve( '0.0000400000', 0.00005 ) );
    }

    public function test_the_live_rate_applies_without_a_saved_rate(): void {
        $this->assertSame( 0.00005, CurrencyRateMath::resolve( null, 0.00005 ) );
    }

    public function test_no_rate_resolves_to_one(): void {
        $this->assertSame( 1.0, CurrencyRateMath::resolve( null, null ) );
    }

    public function test_unusable_rates_are_skipped(): void {
        $this->assertSame( 0.00005, CurrencyRateMath::resolve( '0', 0.00005 ) );
        $this->assertSame( 0.00005, CurrencyRateMath::resolve( 'abc', 0.00005 ) );
        $this->assertSame( 1.0, CurrencyRateMath::resolve( '-1', -2.0 ) );
        $this->assertSame( 1.0, CurrencyRateMath::resolve( '', 0.0 ) );
    }

    public function test_a_percentage_fee_is_added_when_included(): void {
        $fee = [ 'type' => 'percentage', 'value' => '10' ];

        $this->assertEqualsWithDelta( 0.000044, CurrencyRateMath::fee_rate( 0.00004, $fee, true ), 1e-12 );
        $this->assertSame( 0.00004, CurrencyRateMath::fee_rate( 0.00004, $fee, false ) );
    }

    public function test_a_fixed_fee_is_added_when_included(): void {
        $fee = [ 'type' => 'fixed', 'value' => '0.00001' ];

        $this->assertEqualsWithDelta( 0.00005, CurrencyRateMath::fee_rate( 0.00004, $fee, true ), 1e-12 );
        $this->assertSame( 0.00004, CurrencyRateMath::fee_rate( 0.00004, $fee, false ) );
    }

    public function test_a_missing_fee_adds_nothing(): void {
        $this->assertSame( 0.00004, CurrencyRateMath::fee_rate( 0.00004, [], true ) );
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test -- --filter CurrencyRateMathTest`
Expected: FAIL, class `CurrencyRateMath` not found

- [ ] **Step 3: Implement `CurrencyRateMath` in `includes/Integrations/YayCurrency/CurrencyRateMath.php`**

`final class` with the two static methods from the README contract. No WordPress calls; the file still opens with `defined( 'ABSPATH' ) || exit;`, which the unit bootstrap defines. The class docblock states the rate direction (currency units per 1 base unit). A `value` that isn't numeric adds `0`.

- [ ] **Step 4: Run the test to confirm it passes**

Run: `composer test -- --filter CurrencyRateMathTest`
Expected: PASS, 7 tests

- [ ] **Step 5: Lint the new files**

Run: `composer lint -- includes/Integrations/YayCurrency/CurrencyRateMath.php tests/unit/Integrations/YayCurrency/CurrencyRateMathTest.php`
Expected: no errors and no warnings

- [ ] **Step 6: Commit**

Stage: `includes/Integrations/YayCurrency/CurrencyRateMath.php`, `tests/unit/Integrations/YayCurrency/CurrencyRateMathTest.php`
Message: `feat(currency): add the pure rate resolution and fee math`

### Task 2: The rate helper and the earning default

**Files:**
- Create: `includes/Utils/CurrencyRate.php`
- Modify: `includes/Engine/Points/ContextFactory.php` (`base_rate()`)

**Interfaces:**
- Consumes: nothing
- Produces: `CurrencyRate` and the filter `yayloyalty_currency_rate` (see README contract)

- [ ] **Step 1: Implement `CurrencyRate` in `includes/Utils/CurrencyRate.php`**

`final class`, static methods per the README contract.
- `current()` returns `get_woocommerce_currency()`.
- `rate()` returns `1.0` when `$currency` equals `get_option( 'woocommerce_currency' )`. Otherwise it applies `yayloyalty_currency_rate` with `( 1.0, $currency, $order )`, with a docblock stating the direction and default, and returns `1.0` for a result that isn't numeric or is ≤ 0.
- `from_base()` and `to_base()` don't round.

- [ ] **Step 2: Change `ContextFactory::base_rate()`'s filter default to `1 / CurrencyRate::rate( $currency, $order )`**

`yayloyalty_context_base_rate` is still applied with the same arguments. Update its docblock to say where the default comes from.

- [ ] **Step 3: Verify nothing changes without a subscriber**

Run: `composer test`
Expected: `OK`, the same test count as before plus none failing

Then on the test site with YayCurrency **deactivated**, as the customer: buy the 25,000 ₫ product on classic checkout, then set the order to Completed in wp-admin.
Expected: the customer's ledger (YayLoyalty → Customers → the customer) shows +1.

- [ ] **Step 4: Lint**

Run: `composer lint -- includes/Utils/CurrencyRate.php includes/Engine/Points/ContextFactory.php`
Expected: no errors and no warnings

- [ ] **Step 5: Commit**

Stage: `includes/Utils/CurrencyRate.php`, `includes/Engine/Points/ContextFactory.php`
Message: `feat(currency): route every rate through CurrencyRate`

### Task 3: The YayCurrency integration and earning conversion

**Files:**
- Create: `includes/Integrations/YayCurrency/YayCurrencyIntegration.php`
- Create: `includes/Integrations/YayCurrency/YayCurrencyRates.php`
- Create: `includes/Integrations/YayCurrency/OrderRateSnapshot.php`
- Modify: `includes/YayLoyalty.php` (after `YayMailIntegration::get_instance()`)
- Modify: `includes/Helpers/SettingsHelper.php` (`get_default_settings()`, `schema()`)
- Modify: `includes/Engine/Points/Triggers/PurchaseHandler.php` (`is_eligible()`)
- Modify: `docs/architecture.md` (Boot and autoload, and Server layers, next to the YayMail mention)

**Interfaces:**
- Consumes: `CurrencyRateMath` (Task 1), `CurrencyRate` and `yayloyalty_currency_rate` (Task 2)
- Produces: `YayCurrencyIntegration`, `YayCurrencyRates`, `OrderRateSnapshot` and the `currency_include_fee` setting (see README contract)

- [ ] **Step 1: Add the setting `currency_include_fee`**

In `SettingsHelper`: default `true` in `get_default_settings()`, type `'bool'` in `schema()`. Leave `SCHEMA_VERSION` at `2`.

- [ ] **Step 2: Implement `YayCurrencyRates` in `includes/Integrations/YayCurrency/YayCurrencyRates.php`**

The only class that names YayCurrency.
- `live_rate( string $currency, bool $include_fee ): ?float` reads `\Yay_Currency\Helpers\YayCurrencyHelper::get_currency_by_currency_code( $currency )`. It returns `null` when that is falsy, `YayCurrencyHelper::get_rate_fee( $c )` when `$include_fee`, and `(float) $c['rate']` otherwise.
- `base_currency(): string` returns `\Yay_Currency\Helpers\Helper::default_currency_code()`.

- [ ] **Step 3: Implement `OrderRateSnapshot` in `includes/Integrations/YayCurrency/OrderRateSnapshot.php`**

- `save( \WC_Order $order ): void` returns early when the order currency is `get_option( 'woocommerce_currency' )` or `read( $order )` is not `null`. Otherwise it calls `$order->update_meta_data( self::META_KEY, wc_format_decimal( CurrencyRate::rate( $order->get_currency() ), 10 ) )`. It never calls `save()`: both hooks save the order afterwards.
- `read()` returns the meta, or `null` when it is `''`.

- [ ] **Step 4: Implement `YayCurrencyIntegration` in `includes/Integrations/YayCurrency/YayCurrencyIntegration.php`**

Singleton (`SingletonTrait`). The constructor returns early unless `self::is_active()`, which is `defined( 'YAY_CURRENCY_VERSION' )`. It then hooks:
- **`yayloyalty_currency_rate`** (10, 3) → `rate( float $rate, string $currency, ?\WC_Order $order ): float`. It returns `CurrencyRateMath::resolve( null === $order ? null : OrderRateSnapshot::read( $order ), YayCurrencyRates::live_rate( $currency, (bool) SettingsHelper::get_settings()['currency_include_fee'] ) )`. When both candidates are unusable, it writes `Log::debug( 'YayCurrencyIntegration', … )` with the order ID (or `none`) and the currency.
- **`woocommerce_checkout_create_order`** and **`woocommerce_store_api_checkout_update_order_meta`** (20, 1) → `OrderRateSnapshot::save( $order )`.
- **`YayCurrency/ConvertPrice/NotPermitted`** (10, 1) → `true` when `$_SERVER['REQUEST_URI']` (unslashed, sanitised) contains `'/' . rest_get_url_prefix() . '/yayloyalty/v1/'` or `'rest_route=/yayloyalty/v1/'` (raw or URL-encoded `%2Fyayloyalty%2Fv1%2F`). Otherwise it returns the incoming value.

The class docblock names the load order: YayLoyalty boots before YayCurrency, so YayCurrency classes are only used inside these callbacks.

- [ ] **Step 5: Construct the integration in `includes/YayLoyalty.php`**

Add `\YayLoyalty\Integrations\YayCurrency\YayCurrencyIntegration::get_instance();` on the line after the YayMail one.

- [ ] **Step 6: Snap the purchase minimum in `PurchaseHandler::is_eligible()`**

Compare `round( $ctx->get_order_subtotal() * $ctx->get_base_rate(), 9 ) < $min`. Nine decimals matches `PointsMath`'s `SNAP_DECIMALS`, so float error at an exact converted boundary never fails the minimum.

- [ ] **Step 7: Document the integration in `docs/architecture.md`**

- Add `Integrations\YayCurrency\YayCurrencyIntegration` to the constructor-order sentence in "Boot and autoload", right after the YayMail integration.
- Add one bullet under the server layers: the integration subscribes to `yayloyalty_currency_rate`, saves `_yayloyalty_currency_rate` on non-base orders, opts `yayloyalty/v1` REST requests out of YayCurrency's conversion, and is the only code that calls YayCurrency (`YayCurrencyRates`).

- [ ] **Step 8: Verify earning (spec criteria 1 and 3)**

On the test site, YayCurrency **active**, as the customer:
1. Switch to USD with YayCurrency's switcher. Buy the 25,000 ₫ product (shown as $1.00) on classic checkout. Complete the order in wp-admin. Expected: ledger +1.
2. Switch to ₫. Buy the 25,000 ₫ product and complete the order. Expected: ledger +1.
3. In USD, buy the same product on block checkout and complete the order. Expected: ledger +1, and the thank-you page said "You'll earn 1".
4. In wp-admin, create a manual USD order for $1.00, assign the customer and complete it. Expected: ledger +1.

- [ ] **Step 9: Verify the saved rate wins (spec criterion 2)**

In USD, place a $1.00 order and leave it Processing. Change YayCurrency's USD rate to `0.00005`, then complete the order.
Expected: ledger +1, not 0 (at `0.00005`, $1.00 would be 20,000 ₫). Set the rate back to `0.00004`.

- [ ] **Step 10: Verify the boundary snap**

Set the default purchase campaign's minimum order subtotal to 250,000 ₫. In USD, buy the 250,000 ₫ product ($10.00) and complete the order.
Expected: ledger +10. Then remove the minimum.

- [ ] **Step 11: Verify the admin REST opt-out**

With the USD cookie still set in the same browser, open YayLoyalty → Settings → Design.
Expected: the launcher preview shows ₫ amounts and the ₫ symbol.

- [ ] **Step 12: Lint and test**

Run: `composer lint -- includes/Integrations/YayCurrency includes/YayLoyalty.php includes/Helpers/SettingsHelper.php includes/Engine/Points/Triggers/PurchaseHandler.php`
Expected: no errors and no warnings

Run: `composer test`
Expected: `OK`

- [ ] **Step 13: Commit**

Stage: `includes/Integrations/YayCurrency/YayCurrencyIntegration.php`, `includes/Integrations/YayCurrency/YayCurrencyRates.php`, `includes/Integrations/YayCurrency/OrderRateSnapshot.php`, `includes/YayLoyalty.php`, `includes/Helpers/SettingsHelper.php`, `includes/Engine/Points/Triggers/PurchaseHandler.php`, `docs/architecture.md`
Message: `feat(currency): earn on the base value of YayCurrency orders`

### Task 4: The fee setting card

**Files:**
- Modify: `includes/Helpers/LocalizeHelper.php` (`get_meta()` → `wcMeta`)
- Modify: `apps/admin/src/types/localize.type.ts`
- Modify: `apps/admin/src/lib/schema/settings.schema.ts`
- Modify: `apps/admin/src/pages/settings/general-settings.form.ts`
- Modify: `apps/admin/src/pages/settings/general-settings.copy.ts`
- Modify: `apps/admin/src/pages/settings/GeneralSettingsPage.tsx`

**Interfaces:**
- Consumes: the `currency_include_fee` setting (Task 3)
- Produces: `wcMeta.yaycurrency_active` (see README contract)

- [ ] **Step 1: Localize `wcMeta.yaycurrency_active` in `LocalizeHelper::get_meta()`**

The value is `defined( 'YAY_CURRENCY_VERSION' )`, with a one-line comment saying it is integration detection, not a Pro gate. Add `yaycurrency_active: boolean` to the `wcMeta` type in `localize.type.ts`.

- [ ] **Step 2: Add `currency_include_fee: z.boolean()` to `settingsSchema` in `settings.schema.ts`**

- [ ] **Step 3: Extend the General form in `general-settings.form.ts`**

Add `currency_include_fee: true` to the `settingsSchema.pick( … )` keys, `currency_include_fee: settings.currency_include_fee` to `generalSettingsDefaults()`, and update the form docblock. `buildGeneralSettingsUpdate()` already sends only dirty fields.

- [ ] **Step 4: Add the card's strings to `general-settings.copy.ts`**

Use the same pattern as the existing copy functions:
- title "YayCurrency";
- description "Points keep the same value in every currency your shoppers pick. Amounts convert at YayCurrency's exchange rates.";
- label "Include YayCurrency's exchange fee";
- hint "On: a point buys the same product in every currency. Off: points convert at the plain exchange rate."

- [ ] **Step 5: Add `YayCurrencyCard` inside `GeneralSettingsPage.tsx`**

Define it in the same file (single-parent child). It's a shadcn `Card` with the title and description, and a `Switch` (`@/components/ui/switch`, `id="currency_include_fee"`) bound to the form field, with the label and hint. Render it after the Fraud limits line, only when `yayLoyaltyMeta.wcMeta.yaycurrency_active` is true, read the way the page's other code reads localized meta. No `IS_PRO_BUILD` check.

- [ ] **Step 6: Type-check and lint the SPA**

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`
Expected: exit 0

Run: `pnpm --filter @yayloyalty/admin-page exec eslint src/pages/settings/GeneralSettingsPage.tsx src/pages/settings/general-settings.form.ts src/pages/settings/general-settings.copy.ts src/lib/schema/settings.schema.ts src/types/localize.type.ts`
Expected: no errors and no warnings

Run: `pnpm --filter @yayloyalty/admin-page exec prettier --check` on the same five files
Expected: all formatted

- [ ] **Step 7: Verify the fee setting (spec criteria 12 and 13)**

Set YayCurrency's USD fee to `percentage` `10`.
1. Open YayLoyalty → Settings → General. Expected: the YayCurrency card shows with the switch on.
2. With the switch on, buy the 25,000 ₫ product in USD (shown as $1.10) and complete the order. Expected: ledger +1.
3. Turn the switch off and Save. Reload. Expected: it stays off.
4. Buy the same product ($1.10) and complete the order. Expected: ledger +1 (1.1 rounded down).
5. Set the fee back to `0`, turn the switch on and Save.
6. Run the Lite build with `./scripts/run.sh dev lite` and open the same page. Expected: the card shows there too.
7. Deactivate YayCurrency and reload. Expected: no card. `currency_include_fee` stays in the REST response of `GET /wp-json/yayloyalty/v1/settings`.

- [ ] **Step 8: Lint PHP**

Run: `composer lint -- includes/Helpers/LocalizeHelper.php`
Expected: no errors and no warnings

- [ ] **Step 9: Commit**

Stage: `includes/Helpers/LocalizeHelper.php`, `apps/admin/src/types/localize.type.ts`, `apps/admin/src/lib/schema/settings.schema.ts`, `apps/admin/src/pages/settings/general-settings.form.ts`, `apps/admin/src/pages/settings/general-settings.copy.ts`, `apps/admin/src/pages/settings/GeneralSettingsPage.tsx`
Message: `feat(settings): let merchants include YayCurrency's exchange fee`

### Task 5: The launcher's advertised earn rate

**Files:**
- Modify: `includes/Utils/PointsMath.php`
- Modify: `includes/Engine/Storefront/LauncherEarnRules.php` (`advertise()`)

**Interfaces:**
- Consumes: the context base rate (Task 2)
- Produces: `PointsMath::round_significant()` (see README contract)

- [ ] **Step 1: Add `PointsMath::round_significant( float $value, int $digits = 4 ): float`**

It returns `0.0` for `0.0`; otherwise `round( $value, $digits - 1 - (int) floor( log10( abs( $value ) ) ) )`. The docblock says it's for display of a converted rate only (`0.00004` stays `0.00004`; `1.0989010989` becomes `1.099`).

- [ ] **Step 2: Convert the purchase row in `LauncherEarnRules::advertise()`**

- When `$ctx->get_base_rate()` is not `1.0`, store `PointsMath::round_significant( $advert['value'] * $ctx->get_base_rate() )` as `earn[purchase]`, and `PurchaseHandler::min_subtotal( … ) / $ctx->get_base_rate()` rounded to `wc_get_price_decimals()` as `purchase_min`.
- With base rate `1.0`, both stay exactly as today.
- Update the method's docblock: the values are per unit of the shopper's currency.

- [ ] **Step 3: Verify the launcher (spec criterion 4)**

On the test site, as a guest and as the customer:
1. View the shop in USD and open the launcher. Expected: the purchase row reads "1 Point / $1" (dev/edd's fixed earn-rate format since the 2026-10-26 merge).
2. Set the campaign minimum to 250,000 ₫ and reload. Expected: "… on orders of $10 or more". Remove the minimum.
3. Open the 25,000 ₫ product's page. Expected: the earn line says 1 point.
4. Switch to ₫. Expected: the purchase row reads the per-₫ rate exactly as it did before this task.

- [ ] **Step 4: Lint and test**

Run: `composer lint -- includes/Utils/PointsMath.php includes/Engine/Storefront/LauncherEarnRules.php`
Expected: no errors and no warnings

Run: `composer test`
Expected: `OK`

- [ ] **Step 5: Commit**

Stage: `includes/Utils/PointsMath.php`, `includes/Engine/Storefront/LauncherEarnRules.php`
Message: `feat(launcher): advertise the earn rate in the shopper's currency`
