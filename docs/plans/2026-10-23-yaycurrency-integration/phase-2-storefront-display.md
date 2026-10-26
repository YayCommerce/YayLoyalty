# Phase 2: Storefront display

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 (`CurrencyRate`, the integration's rate filter, `PointsMath::round_significant()`)
**Delivers:** the launcher, My Account → Rewards, the balance shortcode and block, and reward text show amounts in the shopper's currency.
**Exit criterion:**
- Spec criterion 5 passes in USD, and criterion 11's display parts pass in ₫.
- Criterion 8's text passes: "On orders over $10". (The "$2 more" line was dropped on 2026-10-26: the dev/edd redeem card v2, #144, removed it.)
- `composer test` prints `OK`, including the new `ClampTest` cases.

### Task 1: Clamp takes a currency rate

**Files:**
- Modify: `includes/Engine/Rewards/Clamp.php` (`apply()`, `points_for()`, class docblock)
- Test: `tests/unit/Engine/Rewards/ClampTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: the new `Clamp::apply()` and `Clamp::points_for()` signatures, and `Result::get_rate_snapshot()` as the effective rate (see README contract)

The test cases (criterion 15):
- `apply( unstepped reward with points_per_currency_unit 0.00004, 1, 10, null, 'USD', 2, 0.00004 )` is allowed with 1 point and value `1.0`.
- `points_for( 1.0, that reward, 0.00004 )` is `1`.
- `get_rate_snapshot()` is `1.0`.
- With no rate argument, every existing `ClampTest` case passes unchanged.
- `apply( unstepped reward with points_per_currency_unit 3, 2, 10, null, 'USD', 2 )` gives value `0.66`.

Steps: expand after Phase 1 lands.

### Task 2: Points value and redeem rate in the launcher and My Account

**Files:**
- Modify: `includes/Engine/Storefront/LauncherPayload.php` (`worth()`, `redeem_rule()`)

**Interfaces:**
- Consumes: `CurrencyRate::current()`, `CurrencyRate::rate()`, `PointsMath::round_significant()` (Phase 1), the new `Clamp::apply()` (Task 1)
- Produces: nothing new

`worth()` passes `CurrencyRate::rate( CurrencyRate::current() )` to `Clamp::apply()`. `redeem_rule()` returns `points_per_unit / rate`, rounded with `round_significant()` when the rate is not `1.0`. This covers the launcher panels, `account-rewards.php` and `points-card.php` (spec criterion 5).

Steps: expand after Phase 1 lands.

### Task 3: Reward text in the shopper's currency

**Files:**
- Modify: `includes/Engine/Storefront/LauncherCopy.php` (`coupon_reward_line()`)
- Modify: `docs/conventions.md` (`#money`: the display rule from the README's Global constraints)

**Interfaces:**
- Consumes: `CurrencyRate::from_base()` (Phase 1)
- Produces: nothing new

`coupon_reward_line()` converts `max_discount`, `min_order` and `amount` with `CurrencyRate::from_base()` before `money()`. `LauncherCopy::money()` itself does not change. The `RewardsPanel` missing-amount line was dropped (amended 2026-10-26: the dev/edd redeem card v2, #144, removed this line).

Steps: expand after Phase 1 lands.
