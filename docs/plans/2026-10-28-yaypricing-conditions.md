# YayPricing Conditions Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** active
**Goal:** Register five YayLoyalty conditions in YayPricing's rule editor: reward applied, points earned or spent (lifetime), and points earned or spent this month.
**Spec:** [docs/specs/2026-10-28-yaypricing-conditions-design.md](../specs/2026-10-28-yaypricing-conditions-design.md)
**Architecture:** A Lite integration in `includes/Integrations/YayPricing/` registers `YAYDP_Condition_Type` subclasses on `yaydp_register_conditions`, which YayPricing has offered since 3.5.8. Points figures come from the customer row and two new net ledger sums, memoised per request. YayPricing evaluates before `CouponProvider::sync_cart()`, so a guarded second `calculate_totals()` keeps "reward applied" correct on the same request.
**Touches:**
- `includes/Integrations/YayPricing/` (new)
- `includes/YayLoyalty.php`
- `includes/Repositories/LedgerRepository.php` (shared: additive methods; current consumers are `LedgerService`, `EarningService`, the dashboard and campaign controllers, `NewPointsNotice`)
- `includes/Enums/LedgerType.php` (shared: additive)
- `includes/Utils/Dates.php` (shared: additive)
- `docs/architecture.md`

**Tests:** `tests/integration/YayPricingPointsTotalsTest.php`, integration suite. It guards the site-timezone month boundary and the net earned/spent type sets (spec acceptance criterion 9).

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Lite and Pro: nothing under `includes/Pro/`. Lite names Pro only in `YayLoyalty.php`'s guarded boot call, and `./scripts/run.sh release lite --no-prompt` must pass the validator.
- `YAYDP\*` classes are named only inside the `yaydp_register_conditions` callback and in the classes it constructs. Nothing autoloads them earlier (same rule as YayMail's adapters).
- Boot guard: `defined( 'YAYDP_VERSION' ) && version_compare( YAYDP_VERSION, '3.5.8', '>=' )`.
- Frozen slugs: `yayloyalty_reward_applied`, `yayloyalty_points_earned`, `yayloyalty_points_spent`, `yayloyalty_points_earned_this_month`, `yayloyalty_points_spent_this_month`.
- Read-only: no ledger or balance writes. Only `LedgerService` writes, and production code does not construct it here.
- Datetimes stored UTC; the month boundary is wall-clock 00:00 on day 1 in `wp_timezone()`.
- Strings use the `yayloyalty` text domain. Labels use `SettingsHelper::points_label_plural()`.
- Branch: `paul/integration/yaypricing` (current), PR into `dev/edd`. Commits follow CLAUDE.md's Git rules (Conventional Commits plus the Co-Authored-By trailer).
- Judge `composer lint` against `dev/edd`. It exits 1 today on `NoReservedKeywordParameterNames` warnings only (docs/known-issues.md); the bar is no new findings in changed files.

## Review focus

- **Nested `calculate_totals()` from `woocommerce_after_calculate_totals`.** Expected: exactly one extra run when loyalty codes changed, none otherwise, and no infinite loop on classic or block cart. Owned by Task 3 Steps 3–4.
- **Autoload before YayPricing.** Naming `PointsTotalCondition` or `RewardAppliedCondition` outside the register callback is a fatal when YayPricing is inactive. Expected: deactivating YayPricing leaves the store working with no PHP notice. Owned by Task 2 Step 5.
- **Shop pages with many products under a product-pricing rule using a points condition.** Expected: one query per metric per request. Owned by Task 2 Step 6.
- **A logged-in user with no customer row.** Expected: totals of 0, so "< 100" matches. Owned by Task 2 Step 6.

---

### Task 1: Month sums for points earned and spent

**Files:**
- Modify: `includes/Enums/LedgerType.php` (next to `lifetime_earned_types()`, ~line 57)
- Modify: `includes/Utils/Dates.php` (next to `site_day_start()`, ~line 44)
- Modify: `includes/Repositories/LedgerRepository.php` (next to `lifetime_earned_before()`, ~line 280)
- Create: `includes/Integrations/YayPricing/CustomerPointsTotals.php`
- Test: `tests/integration/YayPricingPointsTotalsTest.php`

**Interfaces:**
- Produces:
  - `LedgerType::lifetime_redeemed_types(): string[]`: the types where `affects_lifetime_redeemed()` is true, in `all()` order.
  - `Dates::site_month_start( ?string $utc ): ?\DateTimeImmutable`: 00:00 on day 1 of the month holding `$utc`, in `wp_timezone()`. Null when `$utc` is empty or unparsable, as `site_day_start()`.
  - `LedgerRepository::earned_since( int $user_id, string $from ): int`: signed `SUM(points)` of `lifetime_earned_types()` rows with `created_at >= $from`. 0 for `$user_id < 1`.
  - `LedgerRepository::redeemed_since( int $user_id, string $from ): int`: `-SUM(points)` of `lifetime_redeemed_types()` rows with `created_at >= $from`. 0 for `$user_id < 1`.
  - `final class YayLoyalty\Integrations\YayPricing\CustomerPointsTotals`:
    - Constants `EARNED = 'earned'`, `SPENT = 'spent'`, `EARNED_MONTH = 'earned_month'`, `SPENT_MONTH = 'spent_month'`.
    - `public function get( int $user_id, string $metric ): int`: lifetime values from `CustomerRepository::find_by_user_id()` (`get_lifetime_earned()` / `get_lifetime_redeemed()`, with a null row giving 0); month values from the two ledger methods with `$from = Dates::to_utc( Dates::site_month_start( Dates::now() ) )`. Memoised in an instance array `[ $user_id ][ $metric ]`. An unknown metric returns 0.
    - Constructor `__construct( ?LedgerRepository $ledger = null, ?CustomerRepository $customers = null )`, defaulting to new instances.
    - It names no `YAYDP\*` class.

- [ ] **Step 1: Write the failing test**

Create `tests/integration/YayPricingPointsTotalsTest.php` (`namespace YayLoyalty\Tests\Integration`, `final class YayPricingPointsTotalsTest extends IntegrationTestCase`). In `setUp()`, pin the timezone with `add_filter( 'pre_option_timezone_string', ... 'Asia/Ho_Chi_Minh' )`, and remove it in `tearDown()`. Write the rows through `$this->ledger_service()`. For the backdated REDEEM row, the test builds its own `LedgerEntry` with a unique idempotency key, the same shape as `redeem_draft()` but with a `created_at`.

```php
public function test_month_totals_count_net_rows_from_the_site_month_start(): void {
    $user_id = 616161;
    $start   = Dates::to_utc( Dates::site_month_start( Dates::now() ) );
    $before  = gmdate( 'Y-m-d H:i:s', Dates::to_timestamp( $start ) - 1 );
    $ledger  = $this->ledger_service();

    $ledger->earn( $this->earn_draft( $user_id, 1, 999, $before ) );           // last month: excluded
    $this->redeem_at( $user_id, 2, 999, $before );                              // last month: excluded
    $earn = $ledger->earn( $this->earn_draft( $user_id, 3, 100, $start ) );    // at the start: included
    $ledger->adjust( $user_id, 20, 'goodwill', 1 );                             // now
    $ledger->refund_earn( $earn->get_entry(), 30, ReferenceType::REFUND, 1 );   // now, -30
    $ledger->redeem( $this->redeem_draft( $user_id, 4, 50 ) );                  // now, -50
    $ledger->refund_redeem( $this->refund_redeem_at_now( $user_id, 4, 10 ) );   // now, +10

    $totals = new CustomerPointsTotals();

    $this->assertSame( 90, $totals->get( $user_id, CustomerPointsTotals::EARNED_MONTH ) );
    $this->assertSame( 40, $totals->get( $user_id, CustomerPointsTotals::SPENT_MONTH ) );
    $this->assertSame( 1089, $totals->get( $user_id, CustomerPointsTotals::EARNED ) );
    $this->assertSame( 1039, $totals->get( $user_id, CustomerPointsTotals::SPENT ) );
}

public function test_user_without_a_customer_row_has_zero_totals(): void {
    $totals = new CustomerPointsTotals();

    $this->assertSame( 0, $totals->get( 727272, CustomerPointsTotals::EARNED ) );
    $this->assertSame( 0, $totals->get( 727272, CustomerPointsTotals::SPENT_MONTH ) );
}
```

Use the accessor `Outcome` actually exposes for the written row in place of `get_entry()`, and the key format `IdempotencyKey` uses for refund_redeem, as in `tests/integration/Services/LedgerRefundRedeemTest.php`.

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test:integration -- --filter YayPricingPointsTotalsTest`
Expected: an error, class `CustomerPointsTotals` not found. Confirm the output shows PHPUnit ran; exit 0 alone proves nothing (docs/known-issues.md).

- [ ] **Step 3: Add `LedgerType::lifetime_redeemed_types()` and `Dates::site_month_start()`**

`site_month_start()` mirrors `site_day_start()`: `->setTimezone( wp_timezone() )`, then `->setDate( Y, n, 1 )->setTime( 0, 0 )`.

- [ ] **Step 4: Add `earned_since()` and `redeemed_since()` to `LedgerRepository`**

Both go through the private `sum_points()` with `Sql::in_placeholders()`, as `lifetime_earned_before()` does, which keeps them on `idx_user_created`.

- [ ] **Step 5: Create `CustomerPointsTotals`** per the Interfaces block.

- [ ] **Step 6: Run the test to confirm it passes**

Run: `composer test:integration -- --filter YayPricingPointsTotalsTest`
Expected: `OK (2 tests, 6 assertions)`

- [ ] **Step 7: Check unit tests and lint**

Run: `composer test`, then `vendor/bin/phpcs includes/Enums/LedgerType.php includes/Utils/Dates.php includes/Repositories/LedgerRepository.php includes/Integrations/YayPricing/CustomerPointsTotals.php`
Expected: unit suite `OK`. phpcs shows no errors and no warnings beyond those these files already had on `dev/edd`.

- [ ] **Step 8: Commit**

Stage: `includes/Enums/LedgerType.php`, `includes/Utils/Dates.php`, `includes/Repositories/LedgerRepository.php`, `includes/Integrations/YayPricing/CustomerPointsTotals.php`, `tests/integration/YayPricingPointsTotalsTest.php`
Message: `feat(yaypricing): net points earned and spent since the site month start`

### Task 2: Integration bootstrap and the four points conditions

**Files:**
- Create: `includes/Integrations/YayPricing/YayPricingIntegration.php`
- Create: `includes/Integrations/YayPricing/PointsTotalCondition.php`
- Modify: `includes/YayLoyalty.php:35` (add the call after `YayCurrencyIntegration::get_instance()`)

**Interfaces:**
- Consumes: `CustomerPointsTotals` and its metric constants (Task 1).
- Produces:
  - `final class YayPricingIntegration`, using `SingletonTrait`:
    - `public const MIN_YAYPRICING_VERSION = '3.5.8'`.
    - `public static function is_active(): bool`, which applies the boot guard.
    - The protected constructor returns early unless `is_active()`. Otherwise it adds `add_action( 'yaydp_register_conditions', [ $this, 'register_conditions' ] )`.
    - `public function register_conditions( $registry ): void`, with `@param \YAYDP\Condition\YAYDP_Condition_Registry $registry`, untyped like YayMail's. It creates one shared `CustomerPointsTotals` and registers four `PointsTotalCondition`s; Task 3 adds the fifth condition.
  - `final class PointsTotalCondition extends \YAYDP\Abstracts\YAYDP_Condition_Type`:
    - `__construct( string $slug, string $metric, CustomerPointsTotals $totals )`.
    - `slug()` returns `$slug`.
    - `label()`, from the metric. With the `points_label_plural()` value `%s`: "%s earned", "%s spent", "%s earned this month", "%s spent this month". Each has a translators comment.
    - `group()` returns `YAYDP_Condition_Registry::GROUP_CUSTOMER`.
    - `comparators()` returns `YAYDP_Comparators::numeric()`.
    - `editor()` returns `[ 'kind' => 'number' ]`.
    - `default_value()` returns `100`.
    - `check()`: `! $ctx->is_logged_in()` gives false; otherwise `YAYDP_Comparators::compare_numeric( $this->totals->get( $ctx->user_id(), $this->metric ), $condition )`.
  - Slug → metric: `yayloyalty_points_earned` → `EARNED`, `yayloyalty_points_spent` → `SPENT`, `yayloyalty_points_earned_this_month` → `EARNED_MONTH`, `yayloyalty_points_spent_this_month` → `SPENT_MONTH`.

- [ ] **Step 1: Create `PointsTotalCondition`** per the Interfaces block. Its class docblock says YayPricing's base class must already be loaded, so only `register_conditions()` may name it.

- [ ] **Step 2: Create `YayPricingIntegration`** per the Interfaces block. Its class docblock follows YayMail's in explaining why the adapters are named only inside the hook.

- [ ] **Step 3: Boot it from `includes/YayLoyalty.php`**: `\YayLoyalty\Integrations\YayPricing\YayPricingIntegration::get_instance();` after the YayCurrency line.

- [ ] **Step 4: Verify the editor lists the conditions** (spec AC 1)

Open: `/wp-admin/admin.php?page=yaypricing`, then add a cart discount rule and open the condition dropdown.
Expected: under Customer, "Points earned", "Points spent", "Points earned this month" and "Points spent this month" (with the store's label), each with the four numeric comparators and a number field defaulting to 100.

- [ ] **Step 5: Verify a missing YayPricing is harmless**

Action: deactivate YayPricing with `WP_DEBUG` and `WP_DEBUG_LOG` on, then load the shop, cart and `/wp-admin/admin.php?page=yayloyalty`.
Expected: the pages render, and `wp-content/debug.log` gains no line naming `YAYDP` or `YayPricing`. Reactivate YayPricing.

- [ ] **Step 6: Verify evaluation, guests, missing rows and query count** (spec AC 4, 6, 7)

Action:
1. Create a cart discount rule "Points earned ≥ 100 → 5% off" and a product pricing rule "Points earned this month ≥ 1 → 10% off all products".
2. Log in as a customer whose YayLoyalty Customers page shows lifetime earned ≥ 100 and who earned this month.
3. Open the shop page with Query Monitor active.

Expected:
- Product prices show 10% off, and the cart shows 5% off.
- Query Monitor lists at most one `yayloyalty_customers` SELECT and one `yayloyalty_ledger` SUM from `CustomerPointsTotals`.
- A guest sees neither discount.
- A new registered user who has never earned matches a temporary "Points earned < 100" rule.

- [ ] **Step 7: Lint the changed files**

Run: `vendor/bin/phpcs includes/Integrations/YayPricing includes/YayLoyalty.php`
Expected: no errors and no new warnings.

- [ ] **Step 8: Commit**

Stage: `includes/Integrations/YayPricing/YayPricingIntegration.php`, `includes/Integrations/YayPricing/PointsTotalCondition.php`, `includes/YayLoyalty.php`
Message: `feat(yaypricing): points earned and spent conditions for YayPricing rules`

### Task 3: "Loyalty reward applied" condition and the totals re-run

**Files:**
- Create: `includes/Integrations/YayPricing/RewardAppliedCondition.php`
- Create: `includes/Integrations/YayPricing/LoyaltyCouponRerun.php`
- Modify: `includes/Integrations/YayPricing/YayPricingIntegration.php` (constructor and `register_conditions()`)

**Interfaces:**
- Consumes: `YayPricingIntegration::register_conditions()` (Task 2); `Engine\Points\Context::is_loyalty_code( string $code ): bool`.
- Produces:
  - `final class RewardAppliedCondition extends \YAYDP\Abstracts\YAYDP_Condition_Type`:
    - `slug()` returns `'yayloyalty_reward_applied'`.
    - `label()` returns "Loyalty reward applied".
    - `group()` returns `YAYDP_Condition_Registry::GROUP_CART`.
    - `comparators()` returns `[ [ 'value' => true, 'label' => __( 'Applied', 'yayloyalty' ) ], [ 'value' => false, 'label' => __( 'Not applied', 'yayloyalty' ) ] ]`.
    - `editor()` returns `[ 'kind' => 'none' ]`.
    - `default_value()` returns `true`.
    - `check()` returns `$has === (bool) $condition['comparation']`, where `$has` is true when any code in `$ctx->applied_coupons()` passes `Context::is_loyalty_code()`.
  - `final class LoyaltyCouponRerun` (names no `YAYDP\*`):
    - `public function register(): void` adds `woocommerce_before_calculate_totals` → `snapshot` at priority `100` and `woocommerce_after_calculate_totals` → `rerun_if_changed` at `PHP_INT_MAX`.
    - `public function snapshot( \WC_Cart $cart ): void` stores the sorted loyalty codes of `$cart->get_applied_coupons()`, unless a re-run is in progress.
    - `public function rerun_if_changed( \WC_Cart $cart ): void`: when not already re-running and the current sorted loyalty codes differ from the snapshot, it sets the re-running flag, calls `$cart->calculate_totals()` inside `try`/`finally`, and clears the flag in the `finally`.

- [ ] **Step 1: Create `RewardAppliedCondition`** per the Interfaces block. Register it first in `register_conditions()`.

- [ ] **Step 2: Create `LoyaltyCouponRerun`** and call `( new LoyaltyCouponRerun() )->register()` in `YayPricingIntegration`'s constructor, after the guard. Its docblock names the reason: YayPricing evaluates at 110–112, and `CouponProvider::sync_cart()` runs at `PHP_INT_MAX`.

- [ ] **Step 3: Verify on the classic cart** (spec AC 2, 3, 8)

Action:
1. Disable Task 2's test rules, and create a cart discount rule "Loyalty reward applied = Applied → 10% off".
2. As a customer with enough points, on the classic `/cart/` page, apply a reward from the redeem card.
3. Remove it.
4. Temporarily add `error_log( 'yayloyalty rerun' )` in `rerun_if_changed()`'s re-run branch.

Expected:
- After applying, the totals show the reward and the 10% in the same refresh.
- After removing, both are gone in the same refresh.
- `debug.log` gets exactly one line per apply or remove, and none on a quantity change that leaves rewards alone.

Remove the `error_log` line before committing.

- [ ] **Step 4: Verify on the block cart and checkout**

Action: repeat Step 3 on the block Cart page and on block Checkout.
Expected: the same totals after each Store API response, with no PHP fatal or timeout. "Not applied" on a rule matches for a guest.

- [ ] **Step 5: Lint the changed files**

Run: `vendor/bin/phpcs includes/Integrations/YayPricing`
Expected: no errors and no new warnings.

- [ ] **Step 6: Commit**

Stage: `includes/Integrations/YayPricing/RewardAppliedCondition.php`, `includes/Integrations/YayPricing/LoyaltyCouponRerun.php`, `includes/Integrations/YayPricing/YayPricingIntegration.php`
Message: `feat(yaypricing): loyalty reward applied condition`

### Task 4: Architecture docs and release check

**Files:**
- Modify: `docs/architecture.md:18` (boot-order sentence) and `:73` (integration list)

**Interfaces:**
- Consumes: every class above.

- [ ] **Step 1: Update `docs/architecture.md`**
  - Line 18: add "the YayPricing integration (`Integrations\YayPricing\YayPricingIntegration`)" after YayCurrency.
  - After line 73, add a YayPricing entry in the YayCurrency entry's style. It covers the boot guard (3.5.8, `YAYDP_VERSION`), the five slugs on `yaydp_register_conditions`, net counting as `lifetime_*`, the site-timezone month from `Dates::site_month_start()`, the per-request memo in `CustomerPointsTotals`, and why `LoyaltyCouponRerun` exists.

- [ ] **Step 2: Verify the Lite build**

Run: `./scripts/run.sh release pro`, then `./scripts/run.sh release lite --no-prompt`, then `composer install`.
Expected: both zips are written to `release/`, and the validator reports no Pro reference in the Lite zip.

- [ ] **Step 3: End-to-end** (spec AC 10)

Action:
1. With YayPricing active, create a cart discount rule "Points earned this month ≥ 100 → 5% off".
2. As admin, adjust a customer +120 points. Log in as them and open the cart: 5% off is shown.
3. Change the rule to ≥ 200 and reload: no discount.
4. Add a cart discount rule "Loyalty reward applied = Applied → 10% off", apply a reward on the cart page: the reward and the 10% appear in the same refresh.
5. Remove it: both go.

Expected: each result as stated.

- [ ] **Step 4: Commit**

Stage: `docs/architecture.md`
Message: `docs(architecture): describe the YayPricing integration`
