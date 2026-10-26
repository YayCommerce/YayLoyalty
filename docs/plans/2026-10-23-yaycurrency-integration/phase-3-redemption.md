# Phase 3: Redemption in the shopper's currency

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 (`CurrencyRate`, `YayCurrencyIntegration`) and Phase 2, Task 1 (the new `Clamp` signatures and the effective rate snapshot)
**Delivers:** the points slider and every Pro reward coupon redeem in the shopper's currency, on classic and block checkout.
**Exit criterion:** spec criteria 6, 7, 8, 9, 10, 11 and 14 pass on the test site, then the spec's end-to-end walkthrough (criterion 17, steps 1 to 6) passes in full. When it passes, set the README's Status to `done YYYY-MM-DD` in the PR that lands this phase.

### Task 1: The slider quotes in the charged currency

**Files:**
- Modify: `includes/Engine/Rewards/Context.php` (constructor, `get_currency_rate()`)
- Modify: `includes/Engine/Rewards/ContextFactory.php` (`from_cart()`, `from_order()`, `from_order_line()`)
- Modify: `includes/Engine/Rewards/Handlers/FlexibleDiscountHandler.php` (`quote()`)
- Modify: `includes/Integrations/YayCurrency/YayCurrencyIntegration.php` (the coupon guard)

**Interfaces:**
- Consumes: `CurrencyRate` (Phase 1), the new `Clamp` signatures (Phase 2, Task 1)
- Produces: `Rewards\Context::get_currency_rate()` and the coupon guard for `CouponProvider::CODE` (see README contract)

What this task does:
- `from_cart()` passes `CurrencyRate::rate( CurrencyRate::current() )`.
- `from_order()` and `from_order_line()` pass `CurrencyRate::rate( $order->get_currency(), $order )`, and `from_order()` calls `Clamp::points_for( $ours, $reward, $rate )`.
- `FlexibleDiscountHandler::quote()` passes `$ctx->get_currency_rate()` to `Clamp::apply()`.
- The guard hooks the three `woocommerce_coupon_get_*` filters at priority 20. It returns the coupon's `'edit'` value for code `CouponProvider::CODE` only, and the incoming value for every other coupon.

Verification covers:
- spec criteria 6 and 7, on classic and block checkout;
- switching currency with 1 point applied (the totals re-quote, and checkout debits what the card shows);
- the redeem card's live preview reading $1 per point;
- criterion 14: fallback checkout, with YayCurrency set to charge ₫ while browsing in USD.

Steps: expand after Phase 2 lands.

### Task 2: Reward coupons in the charged currency

**Files:**
- Modify: `includes/Engine/Redemption/RewardCoupons.php` (`in_currency()`)
- Modify: `includes/Engine/Redemption/RedemptionService.php` (`coupon_props_of()`)
- Modify: `includes/Pro/Services/DiscountRewardHandler.php` (`quote()`)
- Modify: `includes/Integrations/YayCurrency/YayCurrencyIntegration.php` (widen the guard)

**Interfaces:**
- Consumes: `Rewards\Context::get_currency_rate()` and the guard (Task 1)
- Produces: `RewardCoupons::in_currency()` (see README contract)

What this task does:
- `in_currency()` multiplies `amount` (only when `discount_type` is `fixed_cart`), `minimum_amount` and `max_discount` by `$rate` when they are set.
- `coupon_props_of()` returns `RewardCoupons::in_currency( $props, CurrencyRate::rate( CurrencyRate::current() ) )`.
- `DiscountRewardHandler::quote()` probes and caps with `RewardCoupons::in_currency( $this->coupon_props( $reward ), $ctx->get_currency_rate() )`.
- The guard's code check becomes `Engine\Points\Context::is_loyalty_code( $coupon->get_code() )`.

Verification covers:
- spec criteria 8, 9 and 10;
- criterion 11 in ₫;
- a merchant `shop_coupon` of a fixed amount still being converted by YayCurrency.

Steps: expand after Phase 2 lands.
