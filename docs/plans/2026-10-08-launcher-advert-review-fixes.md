# Launcher advert review fixes, and remove the campaign daily cap

**Status:** active
**Branch:** `paul/fix/launcher-shared-campaign-choice` (PR into `dev/edd`)
**Tests:** `tests/unit/Engine/Points/PointsEngineEvaluateTest.php` (new) and new cases in `tests/unit/Engine/Points/PointsEngineAdvertisedTest.php`, unit suite (accepted 2026-10-08, see Task 6)

## Context

`/code-review` on this branch found that the launcher's "Ways to earn" can still promise what the engine won't pay, plus some robustness gaps. Decided with the user:

- **Row rule: never overpromise.** A Pro-conditional campaign that sits above the first campaign for everyone may catch some shoppers, so the row shows the lowest value any shopper could reach. A handler's event gates (`min_subtotal`, `exclude_coupon_orders`, `require_verified`, `one_per_product`) are the row's terms, not uncertainty: a 0-paying gated campaign still blocks the rows below it (finding 1 stays hidden, now by rule).
- **Remove the per-campaign Daily cap (`daily_cap`) entirely.** That closes finding 3. Pro's separate "Daily limit per customer" (`advanced.max_points_per_day`, `includes/Pro/Services/DailyEarningLimit.php`) **stays**. So does `max_points_per_order`.
- Finding 4 (purchase filters and rounding): document only. A per-unit rate has no order to filter.
- Finding 6 (interface method): keep it and document it as required. The seam is internal and has no subscribers.

## Task 1: Remove `daily_cap` from the SPA (ship before the server change)

- `apps/admin/src/lib/schema/campaign.schema.ts`: drop `daily_cap` from the purchase schema (:316), the review schema (:325) and `DEFAULT_CAMPAIGN_CONFIG.review` (:350).
- `apps/admin/src/pro/campaigns/editor/CampaignEditorRewardPurchase.tsx`: drop `PurchaseConfig.daily_cap` and the Daily cap field (:312-324). "Maximum points per order" keeps the row: drop `sm:grid-cols-2` so it isn't half-width alone.
- `apps/admin/src/pro/campaigns/editor/CampaignEditorReward.tsx`: drop `ReviewConfig.daily_cap` and the review Daily cap field (:139-151).
- `apps/admin/src/pro/campaigns/editor/campaign-reward.copy.ts`: drop `dailyCap` facts and the "Capped at … per day" phrases. Delete `purchaseLimitSentences` / `purchaseCountingSentence` if they are still unimported (they are dead today).
- Check: `git grep -n daily_cap -- apps/admin/src` finds nothing. Vitest passes.

## Task 2: Remove `daily_cap` from the server (no migration, no legacy keys: the plugin hasn't shipped)

- `includes/Models/Campaign.php`: drop `daily_cap` from the purchase (:233) and review (:248) schemas and from `default_config(REVIEW)` (:422). Remove `LEGACY_CONFIG_KEYS` entirely, the multiplier's `mode`/`multiplier` included: the plugin hasn't shipped, so no stored row needs them. A local row that still holds one of these keys fails a PUT with 400 until it is fixed by hand.
- `includes/Engine/Points/CapPolicy.php`: per-order clamp only, as `apply( int $points, Campaign $campaign ): int`. Update its docblock and the caller in `PointsEngine::evaluate()`.
- `includes/Engine/Points/Context.php`: remove `points_earned_today` (property, constructor parameter 13, getter). Its only reader was CapPolicy.
- `includes/Engine/Points/ContextFactory.php`: remove the `$points_earned_today` parameter from `from_order()` / `from_comment()`, and from every positional `new Context(` call.
- `includes/Engine/Earning/Listeners/OrderListener.php` (:133-135): drop the per-trigger ledger read and its comment. `$ledger` stays for `sum_refunded_for()`; fix its docblock (:31).
- `includes/Engine/Earning/Listeners/ReviewListener.php`: drop the ledger read (:89). `$ledger` then has no use, so remove the property and constructor parameter and update `includes/YayLoyalty.php:63`.
- `includes/Repositories/LedgerRepository.php:69-76`: rewrite the docblock so it no longer names CapPolicy. Keep the method for `DailyEarningLimit`.
- `includes/Pro/YayLoyaltyPro.php:281, :303-307`: drop `daily_cap` from `describe_pro_config()`.
- `includes/Engine/Points/Triggers/ReviewHandler.php:13`: drop "daily_cap is CapPolicy's".
- `tests/unit/EngineTestCase.php:78, :105`: drop `$earned_today`.
- Check: `git grep -n "daily_cap\|earned_today" -- includes tests` shows only `LedgerRepository` / `DailyEarningLimit`'s `points_earned_today()`.

## Task 3: Advertising under the never-overpromise rule (findings 2, 11, 12)

In `includes/Engine/Points/PointsEngine.php`:

- `pick()` serves events only: matches filter, then `is_eligible()`, returning `?Campaign`. Remove its advert branch and its `array{campaign, value}` shape.
- `advertised()` walks `candidates()` itself:
  - A campaign that is not `applies_to_everyone()` is **partial**. Its `advertised_value()` lowers the floor, and the walk continues. Pro's evaluator is never called (there is no shopper), and the matches filter doesn't fire for it.
  - A campaign for everyone fires `yayloyalty_campaign_matches` with `true`. If the filter returns false, skip it. If true, it is the **stop**: floor = min(floor, its value), and the walk ends.
  - No stop reached, so some shoppers get nothing: return null.
  - Return `{campaign, value}` for the campaign that set the floor, or null when the floor is ≤ 0.
- Rewrite `advertised()`'s docblock: the rule; that event gates are the row's terms; that purchase filters and rounding are not applied (finding 4).
- `TriggerHandler::advertised_value( Campaign $campaign ): float`, no longer nullable: no handler returns null since the multiplier went. Its docblock says every handler must implement it (finding 6). Update the three handlers' return types.
- Fix `applies_to_everyone()`'s docblock: it no longer means "only such a campaign is advertised".

## Task 4: Guards on the engine (findings 7, 8)

- `evaluate()` throws `\InvalidArgumentException` for `$ctx->is_advert()`, mirroring `advertised()`. Add `@throws`.
- `candidates()`: drop the `(array)` cast and return `iterable` (PHP 7.4 ok), so a Traversable from `yayloyalty_campaign_candidates` behaves as it did on `dev/edd`. `pick()` and `advertised()` take `iterable`. Keep `evaluate()`'s `empty( $candidates )` check as on `dev/edd`.

## Task 5: Launcher robustness (findings 5, 9)

- `includes/Engine/Storefront/LauncherPayload.php`: move `PointsEngine::build()` inside the try in `earn_rules()`, which returns the rules plus `?PointsEngine`. `product_estimate()` takes `?PointsEngine` and returns null when it's missing. `for_balance()` then never throws on build, so the Design preview is covered too.
- `includes/Engine/Storefront/LauncherEarnRules.php::advertise()`: put a try/catch around each trigger's `advertised()`, with `Log::warning( 'LauncherEarnRules', … )`, so one broken trigger hides only its own row. Drop `@throws`. `LauncherPayload::earn_rules()` keeps its outer catch for the build only.

## Task 6: Tests (accepted)

- **New** `tests/unit/Engine/Points/PointsEngineEvaluateTest.php` (extends `EngineTestCase`). It pins `evaluate()`:
  - no candidates → `REASON_NO_CAMPAIGNS`;
  - first campaign fails `min_subtotal` and the second pays it;
  - first campaign pays 0, so `REASON_ZERO_POINTS` even though the next one pays;
  - `max_points_per_order` clamps;
  - nothing eligible → `REASON_NOT_ELIGIBLE`;
  - a `Context::advert()` throws.
- **Add to** `PointsEngineAdvertisedTest.php`:
  - a conditional campaign at 0.5 above one for everyone at 1 → value 0.5;
  - a conditional at 3 above one for everyone at 1 → value 1;
  - a review campaign at 0 points with `require_verified` above one at 30 → null;
  - only conditional campaigns → null.
  - The existing conditional test (evaluator never called, value 3.0) still holds. The "caps" case is dropped, since the campaign daily cap no longer exists.

## Task 7: Docs

- `docs/specs/2026-10-06-shared-campaign-choice-for-launcher-design.md`: change Status to `superseded 2026-10-08 by docs/plans/2026-10-08-launcher-advert-review-fixes.md`, so nobody acts on its multiplier criterion 2 or the `daily_cap` out-of-scope line.
- Run `/yaybacklog` to check `docs/backlog/2026-10-05-launcher-skips-pro-earning-gates.md` against the new rule. Leave the Pro daily-limit items (`2026-10-07-daily-earning-sum…`, `2026-10-08-launcher-advertises-earning-past-the-daily-cap.md`) alone: they cover the Pro limit, not `daily_cap`.
- Finding 14 (missing trailer on the pushed commit `7f0fe98`): no action, because that would mean rewriting pushed history.

## Verification

1. `composer test`: all unit tests pass, including the new ones.
2. Run `vendor/bin/phpcs` on the touched PHP files: no errors. Check them by hand for PHP 7.4 syntax (`iterable` is fine).
3. `pnpm --filter @yayloyalty/admin-page test` passes. `git grep -n daily_cap -- includes apps/admin/src tests` matches nothing.
4. Run an `apiFetch` PUT `campaigns/<review id>` with `"daily_cap": 5` in the config. Expected: 400. Saving the built-in review campaign from the editor returns 200 once its local row no longer holds `daily_cap`.
5. Browser, Pro, at :3030:
   - The purchase and review editors show no Daily cap field.
   - On the storefront, a conditional purchase campaign at 0.5 above one for everyone at 1 → the launcher shows 0.5 per unit. Raise the conditional to 3 → it shows 1.
6. Make a `yayloyalty_campaign_matches` callback that throws only for review. Expected: the purchase and signup rows still render, and one warning is logged.
