# Advertise Conditional Campaigns Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** active
**Goal:** On a Pro store, shoppers see campaigns with conditions on the product page, in "Ways to earn", as "Bonus offers" rows and as a cart nudge, and the storefront never promises more than the order pays.
**Spec:** [docs/specs/2026-11-05-advertise-conditional-campaigns-design.md](../../specs/2026-11-05-advertise-conditional-campaigns-design.md)
**Architecture:** One three-state verdict (`true`, `false`, `null` for unknown) drives a single priority walk in `PointsEngine`: `true` stops, `false` skips, `null` lowers the promise. Surfaces reach it through flagged Contexts, not new call paths, so `EarningService::preview()` and every award keep today's behaviour.
**Touches:**
- (shared) `includes/Engine/Points/Context.php`, consumers: every engine class, `ConditionsEvaluator`, the trigger handlers
- (shared) `includes/Engine/Points/ContextFactory.php`, consumers: `ProductEarnMessage`, `LauncherPayload`, `Launcher`, `BirthdayEarnRow`, order, signup and review listeners, `CampaignPreviewRestController`
- (shared) `includes/Engine/Points/PointsEngine.php`, consumers: `EarningService`, `LauncherEarnRules`, `ProductEarnMessage`
- (shared) `includes/Enums/CampaignConditionType.php`, consumers: `ConditionsEvaluator`, campaign validation
- (shared) `includes/Engine/Storefront/LauncherPayload.php`, consumers: `Launcher`, `LauncherPreview`, `Placements`, `AccountMyPoints`
- (shared) `includes/Engine/Redemption/Adapters/StoreApiAdapter.php`, `assets/storefront/redeem-blocks.js`, consumers: the Cart and Checkout blocks
- `includes/Pro/Services/ConditionsEvaluator.php`, `includes/Pro/YayLoyaltyPro.php`, new files in `includes/Pro/Storefront/`, `includes/Engine/Storefront/CartEarnNudge.php`, `includes/Helpers/DesignSchema.php`, `apps/admin/src/lib/schema/design.schema.ts`, `apps/admin/src/pages/settings/DesignVisibilitySection.tsx`, `docs/architecture.md`, one decision record

**Tests:**
- `tests/unit/Pro/Services/ConditionsEvaluatorVerdictTest.php` (unit): the fold to `true`, `false` or `null`, and that a Context with no lines still reports `needs_order`.
- `tests/unit/Engine/Points/PointsEngineEstimateTest.php` (unit): the estimate walk, the overpromise case, and that an event Context takes today's path.
- `tests/unit/Engine/Points/PointsEngineAdvertisedTest.php` (unit, extended): a member advert under each verdict, an anonymous advert never calling the evaluator, and `advertised_offers()` dropping campaigns the engine would never pay.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, or the extra list in `.claude/rules/php-standards.md`.
- Lite names Pro only in the guarded `boot()` call. Pro attaches only through the filters `boot()` adds. Storefront output hooks live in Lite; Pro answers a filter.
- SPA Pro behaviour renders only behind `IS_PRO_BUILD`, a build-time constant.
- The evaluator queries nothing: facts come from the Context's loader.
- `EarningService::award()` and every Context built by `from_order()`, `from_user()` or `from_comment()` take the same path as today.
- Nothing personal in guest output.
- A failure in new storefront code logs through `Log::warning()` and costs only that row or line.
- New strings use the `yayloyalty` text domain and the merchant's points label.
- No ledger writes.
- Each phase is one PR into `dev/edd`. Commit messages follow Conventional Commits and end with the `Co-Authored-By` trailer. Commit or push only when asked.

## Review focus

- A member on a store with a facts condition: `CustomerFactsReader::read()` loads every paid order, and Phase 2 reaches it on each storefront page. Expected: at most one read per request. Phase 1 Task 3 and Phase 2 Task 1.
- `yayloyalty_campaign_matches` with a subscriber returning false on an estimate: the campaign is skipped, not treated as unknown. Phase 1 Task 2.
- A review campaign with a basket condition: never paid, so never offered. Phase 3 Task 2.
- A campaign whose offer pays only below another campaign: no row. Phase 3 Task 1.
- Lite zip: no name from `includes/Pro/`, nudge filter unanswered, no empty markup printed. Phase 4 Task 3.
- Block cart update after adding a matching item: the nudge goes in the same Store API response. Phase 4 Task 2.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | Correct product-page figures, overpromise fixed | `composer test` passes, and spec criteria 2 to 6 hold on a Pro site | implemented |
| 2 | A member's own rate in "Ways to earn" | `composer test` passes, and spec criteria 7 to 9 hold | pending |
| 3 | "Bonus offers" rows and their switch | `composer test` and `tsc --noEmit` pass, and spec criteria 10 to 15 hold | pending |
| 4 | The nudge on classic and block cart and checkout | Spec criteria 16 to 18 hold, and `./scripts/run.sh release lite --no-prompt` passes | pending |

## Interface contract

- `CampaignConditionType::INPUT_BASKET = 'basket'`: Phase 1 Task 1.
- `ConditionsEvaluator::verdict( array $conditions, Context $ctx ): ?bool`: Phase 1 Task 1, consumed by `PointsEngine`.
- `Context::as_estimate(): self`, `Context::is_estimate(): bool`: Phase 1 Task 2, consumed by `ContextFactory`.
- `PointsEngine::verdict( Campaign $campaign, Context $ctx ): ?bool` (private): Phase 1 Task 2, consumed by Phases 2 and 3 inside the class.
- `ContextFactory::shared_facts( int $user_id ): ?CustomerFacts` (private, memoised per request): Phase 1 Task 3, consumed by `for_member_advert()`.
- `Context::advert( string $at, string $currency, float $to_base_factor, ?int $user_id = null, array $user_roles = [], $customer_facts = null ): self`: Phase 2 Task 1.
- `ContextFactory::for_member_advert( string $at, int $user_id ): Context`: Phase 2 Task 1, consumed by Phases 2 and 3.
- `LauncherPayload::for_balance( array $design, ?int $balance, ?int $left_today = null, ?int $user_id = null ): array`: Phase 2 Task 2.
- `PointsEngine::advertised_offers( string $trigger, Context $ctx ): array` returning `array<int, array{campaign: Campaign, value: float}>`: Phase 3 Task 1, consumed by `ConditionalOfferRows` and `CartOfferNudge`.
- `ConditionalOfferCopy::label( Campaign $campaign ): ?string`: Phase 3 Task 2, consumed by `ConditionalOfferRows`.
- `ConditionalOfferCopy::basket_names( Campaign $campaign ): ?string`: Phase 3 Task 2, consumed by `CartOfferNudge`.
- Design key `visibility.show_bonus_offers` (bool, default `true`): Phase 3 Task 3, consumed by `ConditionalOfferRows` and `CartEarnNudge`.
- Filter `yayloyalty_cart_earn_nudge` ( string $text, \WC_Cart $cart, int $user_id ): string: Phase 4 Task 1, consumed by `CartOfferNudge`.
- `StoreApiAdapter::cart_data()` key `nudge` (string): Phase 4 Task 2, consumed by `redeem-blocks.js`.
