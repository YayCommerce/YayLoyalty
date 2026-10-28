# YayPricing Conditions Design

**Status:** accepted 2026-10-28
**Related:** None

## Goal

On a store running YayPricing 3.5.8 or later, a merchant building a YayPricing rule (product pricing, cart discount, checkout fee or exclude) can make it depend on the shopper's loyalty activity. The five conditions: a YayLoyalty reward is applied to the cart; points earned (lifetime); points spent (lifetime); points earned this month; points spent this month. Shipped in Lite and Pro.

## Context

- **YayPricing 3.6.1** (sibling `../yaypricing`, read only). `YAYDP\Condition\YAYDP_Condition_Registry` (since 3.5.8) boots lazily on first use, registers core types, then fires `do_action( 'yaydp_register_conditions', $registry )`. An integration calls `$registry->register( YAYDP_Condition_Type $type )`.
  - `YAYDP\Abstracts\YAYDP_Condition_Type`: `slug()`, `label()`, `comparators()`, `check( array $condition, YAYDP_Condition_Context $ctx )` are abstract. `group()`, `families()`, `editor()`, `default_value()`, `tooltip()` and `incomplete()` are optional.
  - `YAYDP_Comparators::numeric()` (greater_than, less_than, gte, lte) and `::compare_numeric( $actual, $condition )`. `YAYDP_Condition_Context::applied_coupons()`, `::is_logged_in()` and `::user_id()`.
  - Groups: `GROUP_CART`, `GROUP_CUSTOMER`. The admin dropdown is built from `for_localize()`, so a registered type shows up in the rule editor with no JS.
  - A stored condition whose slug is unregistered (the plugin is gone) never matches.
  - The pattern to copy is `includes/integrations/b2bking/class-yaydp-b2bking-custom-role-condition.php`.
  - Rules are calculated on `woocommerce_before_calculate_totals`: product pricing at 110, cart discount at `YAYDP_CART_CALCULATE_PRIORITY` = 111, checkout fee at `YAYDP_CHECKOUT_CALCULATE_PRIORITY` = 112. `YAYDP_VERSION` is defined when its main file loads.
- **YayLoyalty.**
  - Integration pattern: `includes/Integrations/YayMail/YayMailIntegration.php` (adapter classes extend the sibling's base classes and are first named inside the sibling's register hook) and `includes/Integrations/YayCurrency/YayCurrencyIntegration.php`. Both are constructed in `includes/YayLoyalty.php` before Pro boots.
  - Rewards on the cart are virtual coupons: `yayloyalty-redeem` (slider) and `yayloyalty-reward-{id}`. `Engine\Points\Context::is_loyalty_code()` matches both.
  - `Engine\Redemption\CouponProvider::sync_cart()` adds and removes them on `woocommerce_before_calculate_totals` at `PHP_INT_MAX`, which is after YayPricing has run.
  - Lifetime totals: the customer row read via `CustomerRepository::find_by_user_id()`. `Models\Customer::get_lifetime_earned()` holds net earn + adjust − refund_earn. `::get_lifetime_redeemed()` holds |redeem| − refund_redeem. Rules: `LedgerType::affects_lifetime_earned()`, `::lifetime_earned_types()`, `::affects_lifetime_redeemed()`.
  - Ledger reads: `LedgerRepository::sum_points()` (private) and `lifetime_earned_before()`. Index `idx_user_created (user_id, created_at)`. Dates: `Utils\Dates::now()`, `::to_utc()`, `::site_day_start()` (site-timezone wall-clock boundary).

## Scope

**In:**
- `includes/Integrations/YayPricing/`, in Lite and Pro, booted from `includes/YayLoyalty.php` next to the YayCurrency integration.
- Five condition types registered on `yaydp_register_conditions`.
- The ledger and date helpers they need: month sums, net, in the site timezone.
- A once-only totals re-run, so "reward applied" is correct on the same request that applies or removes a reward.
- `docs/architecture.md`: a YayPricing entry next to YayMail/YayCurrency, and the component in the boot-order sentence.
- One integration test (acceptance criterion 9).

**Out:**
- YayPricing encouraged notices (`incomplete()`), e.g. "earn 50 more points to unlock".
- Choosing specific rewards, or the slider vs a catalog reward.
- Points balance, VIP tier and referral conditions.
- YayPricing versions below 3.5.8 and its legacy `yaydp_check_condition_by_{slug}` filters.
- Any change to YayPricing's code.
- Any YayLoyalty admin UI or setting.

## Acceptance criteria

1. With YayPricing ≥ 3.5.8 active, its rule editor lists "Loyalty reward applied" under Cart, and four points conditions under Customer. Without YayPricing, or below 3.5.8, YayLoyalty adds no hooks and no error is logged.
2. `yayloyalty_reward_applied` with comparator "Applied" (`true`) matches when the cart holds `yayloyalty-redeem` or any `yayloyalty-reward-{id}`. "Not applied" (`false`) matches otherwise, guests included.
3. A cart discount rule "10% off when Loyalty reward applied = Applied": applying a reward from the redeem card shows both discounts in the totals that same response, classic and block cart. Removing the reward drops the 10% in that same response.
4. `yayloyalty_points_earned` compares `get_lifetime_earned()`, and `yayloyalty_points_spent` compares `get_lifetime_redeemed()`. Example: a customer with lifetime_earned 500 matches "≥ 500" and not "> 500".
5. `yayloyalty_points_earned_this_month` compares the signed sum of `lifetime_earned_types()` rows with `created_at` ≥ 00:00 on the 1st of the current month in `wp_timezone()`, converted to UTC. `yayloyalty_points_spent_this_month` compares the negated sum of redeem + refund_redeem rows over the same window.
6. All four points conditions are false for a guest. A logged-in user with no customer row has totals of 0, compared normally: "< 100" matches and "≥ 1" does not.
7. Within one request, each total for a user is queried at most once, however many products or rules evaluate it.
8. The re-run in criterion 3 runs `calculate_totals()` at most once per outer calculation, and only when the set of loyalty codes differs from before YayPricing ran.
9. `tests/integration/YayPricingPointsTotalsTest.php`, run with `composer test:integration`, writes rows through `LedgerService` one second before and at the site-timezone month start (site timezone `Asia/Ho_Chi_Minh`). The rows are earn 100, adjust 20, refund_earn −30, redeem −50 and refund_redeem 10. It asserts earned-this-month 90 and spent-this-month 40, counting only the in-month rows.
10. End-to-end: on a site with YayPricing active, build a cart discount rule "Points earned this month ≥ 100 → 5% off". Log in as a customer who earned 120 this month (from an admin adjustment) and see 5% off in the cart. Change the rule to ≥ 200 and see no discount. Then add a second cart discount rule, "Loyalty reward applied = Applied → 10% off". Apply a reward on the cart page and see the reward and the 10% in the same refresh. Remove it and see both go.

## Constraints

- PHP 7.4 syntax in all product PHP: no enums, `match`, `readonly`, promoted properties, `?->`, named args or union types.
- Lite and Pro: nothing under `includes/Pro/`; the Lite build validator must pass.
- YayPricing classes (`YAYDP\*`) are named only inside the `yaydp_register_conditions` callback and the classes it constructs. Nothing autoloads them before then.
- Boot guard: `defined( 'YAYDP_VERSION' ) && version_compare( YAYDP_VERSION, '3.5.8', '>=' )`.
- Slugs are frozen once released: `yayloyalty_reward_applied`, `yayloyalty_points_earned`, `yayloyalty_points_spent`, `yayloyalty_points_earned_this_month`, `yayloyalty_points_spent_this_month`.
- Read-only: no ledger or balance writes (only `LedgerService` writes). Dates stored UTC; month boundary in `wp_timezone()`.
- Strings use the `yayloyalty` text domain, and labels use the merchant's points label (`SettingsHelper::points_label_plural()`).

## Approach

**Chosen:** native YayPricing condition registry, with a per-request totals cache and a guarded totals re-run.

**Architecture:** `YayPricingIntegration` checks the version guard, then adds the `yaydp_register_conditions` action and the re-run hooks. The callback constructs one `RewardAppliedCondition` and four `PointsTotalCondition` instances, each built with its slug, label and metric. Points conditions read through `CustomerPointsTotals`, which memoises per user per request and reads the customer row and two new ledger sums.

**Components:**
- `includes/Integrations/YayPricing/YayPricingIntegration.php`: guard, hooks, registers the five types.
- `includes/Integrations/YayPricing/RewardAppliedCondition.php`: extends `\YAYDP\Abstracts\YAYDP_Condition_Type`. Group `GROUP_CART`, editor `none`, default `true`, comparators `[ ['value'=>true,'label'=>'Applied'], ['value'=>false,'label'=>'Not applied'] ]`. `check()` tests `$ctx->applied_coupons()` with `Context::is_loyalty_code()`.
- `includes/Integrations/YayPricing/PointsTotalCondition.php`: one class, four instances. The constructor takes slug, label and metric (`earned`, `spent`, `earned_month`, `spent_month`). Group `GROUP_CUSTOMER`, `YAYDP_Comparators::numeric()`, editor `number`, default `100`. `check()` returns false for a guest, else `compare_numeric( totals, $condition )`.
- `includes/Integrations/YayPricing/CustomerPointsTotals.php`: memo `[user_id][metric] => int`. Lifetime figures come from `CustomerRepository::find_by_user_id()` (null → 0). Month figures come from the ledger with `Dates::site_month_start()`.
- `includes/Integrations/YayPricing/LoyaltyCouponRerun.php`: on `woocommerce_before_calculate_totals` at 100, snapshots the sorted loyalty codes. On `woocommerce_after_calculate_totals` at `PHP_INT_MAX`, if they differ and it is not already re-running, sets a flag, calls `$cart->calculate_totals()`, and clears the flag.
- `includes/Repositories/LedgerRepository.php`: adds `earned_since( int $user_id, string $from ): int`, the signed SUM of `lifetime_earned_types()`, and `redeemed_since( int $user_id, string $from ): int`, the −SUM of `lifetime_redeemed_types()`. Both filter on `created_at >= %s`.
- `includes/Enums/LedgerType.php`: adds `lifetime_redeemed_types()` next to `lifetime_earned_types()`.
- `includes/Utils/Dates.php`: adds `site_month_start( string $utc ): ?\DateTimeImmutable`, 00:00 on day 1 in `wp_timezone()`.

**Data and storage:** None new. Reads `yayloyalty_customers` and `yayloyalty_ledger`. YayPricing stores the conditions in its own rules. Nothing to migrate or uninstall.

**Integration points:**
- Hooks consumed: `yaydp_register_conditions`, `woocommerce_before_calculate_totals` (100), `woocommerce_after_calculate_totals` (`PHP_INT_MAX`).
- No YayLoyalty filters added.
- Shared code changed: `LedgerRepository`, `LedgerType` and `Dates`. All are additive, so no existing caller changes.

**UI:** None in YayLoyalty. Conditions appear in YayPricing's own rule editor.

**Edge cases and failure modes:**
- YayPricing deactivated: its stored YayLoyalty conditions never match, which is YayPricing's own behaviour.
- YayLoyalty deactivated while YayPricing stays active: same, never match.
- Guest: the points conditions are false, and "Not applied" is true.
- Product-pricing rules evaluated per product on shop pages: the memo keeps it to one query per metric per request.
- Re-run recursion: the flag stops a second nested run. A cart with no loyalty change pays nothing.
- A picked reward the balance cannot cover stays unapplied: it is not a coupon, so it does not count as applied.
- Month start during a DST change: wall-clock midnight, as `site_day_start()`.

**Decision record:** None. This follows the existing integration pattern.

## Alternatives considered

| Option | Why rejected |
|---|---|
| YayPricing legacy `yaydp_check_condition_by_{slug}` filters | Goes through a compatibility shim YayPricing is moving away from. 3.5.8+ is acceptable. |
| Read session intent for "reward applied" | Wrong when a picked reward stays unapplied for lack of balance. |
| Accept one-recalculation lag | The cart shows a stale YayPricing discount right after applying or removing a reward. |
| Gross counting (earn/redeem rows only) | Disagrees with the Customers page lifetime totals. User chose net. |
| Rolling 30 days | User chose calendar month in site timezone. |
| Pro only | YayMail/YayCurrency ship in Lite. User chose Lite and Pro. |
| Specific-reward picker | More UI. User chose "any loyalty discount". |

## Rollout

Unreleased plugin, no installs to migrate. Release note: "Adds YayPricing conditions (requires YayPricing 3.5.8+)".

## Open questions

None.

## Verification

- `composer test:integration` runs the new `YayPricingPointsTotalsTest`. Check that it actually ran (see docs/known-issues.md).
- `composer test` stays green.
- `php -l` on every new file under PHP 7.4 syntax rules, by review.
- `./scripts/run.sh release lite --no-prompt` passes the validator.
- The manual walkthrough in acceptance criterion 10.
