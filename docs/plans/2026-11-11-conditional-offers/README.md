# Conditional Offers Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** active
**Goal:** Advertise Pro conditional earning campaigns and rewards on the storefront: personal values for members who qualify, teasers for everyone else.
**Spec:** `docs/specs/2026-11-11-conditional-offers-design.md`
**Architecture:** `PointsEngine::offers()` returns the base the storefront may promise plus the conditional bonuses above it, each with a status (matched / locked / at_checkout) folded from `ConditionsEvaluator::explain()`. `OfferLines` turns that into the row value and teaser lines, and every surface renders from it, so no surface copies the priority and match rules. Pro supplies the auto phrases through one internal filter.
**Touches:** `includes/Engine/Points/PointsEngine.php` (shared: `ProductEarnMessage`, `LauncherPayload`, `LauncherEarnRules`, `BirthdayEarnRow`, `EarningService`, the order, signup and review listeners), `includes/Pro/Services/ConditionsEvaluator.php` (shared: award time through `PointsEngine`, `CampaignPreviewRestController`), `includes/Engine/Points/ContextFactory.php` (shared: every listener, `EarningService`, `LauncherPayload`, `ProductEarnMessage`, `BirthdayRunner`, `CampaignPreviewRestController`), `includes/Engine/Storefront/LauncherEarnRules.php` and `LauncherPayload.php` (shared: launcher panels, `AccountMyPoints`, `Placements`, `points-card.php`, `BirthdayEarnRow`), `includes/Pro/Storefront/RewardsPanel.php`, `includes/Engine/Storefront/ProductEarnMessage.php`, new `OfferLines`, `CartEarnPreview` and `ConditionPhrases`, `includes/Helpers/DesignSchema.php`, `includes/Models/Campaign.php`, `includes/Models/Reward.php`, `includes/Pro/YayLoyaltyPro.php`, storefront templates, CSS and JS in `assets/storefront/`, the SPA design and Pro campaign and reward editors.
**Tests:**
- `tests/unit/Pro/ConditionsEvaluatorDraftContextTest.php` (`composer test`): award parity when the Context has an `order_id`, plus evaluating order-type conditions on draft Contexts (spec criteria 10 and 11).
- `tests/unit/Engine/Points/PointsEngineOffersTest.php` (`composer test`): bonus selection, statuses, the cap of 3, and no bonuses without an evaluator (spec criterion 11).
- `apps/e2e/specs/conditional-offers.spec.ts` (`pnpm --filter @yayloyalty/e2e test`): spec criteria 1–6.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types. Also out: `mixed`, `static`, `never` and intersection types; a `catch` without a variable; `throw` as an expression; a trailing comma after the last parameter of a declaration; `new` in a default value; first-class callables; `$object::class`.
- Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call in `includes/YayLoyalty.php`. Phrases, the reward condition checks and the editor field live in `includes/Pro/` and `apps/admin/src/pro/`, the field behind `IS_PRO_BUILD`. Lite engine code reaches Pro only through filters.
- No ledger or balance writes. Only `LedgerService` writes them, and nothing here calls it.
- Guest output on every surface depends only on the time, the currency and the product or cart, never on the viewer.
- New design keys go in `DesignSchema::get_default_design()` and `schema()`, and in `apps/admin/src/lib/schema/design.schema.ts`. New config keys go in the Lite `Campaign::config_schema()` / `Reward::config_schema()` for every Lite trigger and type, in Pro's `add_*_config_schema()` for Pro ones, and in the zod schemas under `apps/admin/src/lib/schema/`.
- New hooks attach in a `register()` called once from a composition root. Logging only through `Log::warning()`, with the reporting class as context. Each storefront surface catches `Throwable` and drops only its own bonus lines.
- Each storefront page view evaluates conditions at most once per campaign per viewer and trigger, and reads facts at most once.
- Strings use the `yayloyalty` text domain, and every placeholder has a translators comment.
- `yayloyalty_offer_condition_phrase` is an internal Pro seam, not public API.
- A class file in `includes/` opens with its namespace, its `use` lines, `defined( 'ABSPATH' ) || exit;` and the class docblock. WordPress style with 4-space indents and short arrays. Lint the changed files only, with `composer lint -- <files>`: `composer lint` exits 1 with no errors today (docs/known-issues.md), so the expectation is no new reports on the changed files.
- Storefront scripts are plain JS with no build step, like `assets/storefront/redeem-blocks.js`.
- Commits follow Conventional Commits `type(scope): summary`, end with `Co-Authored-By: Claude <model> <noreply@anthropic.com>`, and stage files by name.

## Review focus

- A Context with an `order_id` must give the same `explain()` as before for every type, so award results don't move. Owned by Phase 1 Task 1, through the accepted evaluator test.
- An admin campaign preview (`from_user`, no lines, no order) still answers `needs_order` for product and category conditions. Phase 1 Task 1, a test case.
- A guest advert Context with a `currency in [EUR]` campaign on a USD store gives status `locked`, not `at_checkout`. Phase 1 Task 2, a test case.
- No campaign for everyone: no row and no bonus lines for a guest, while a member with a matched bonus gets a row. Phase 1 Task 4, a verification step.
- A variable product whose selected variation has no bonus clears the bonus area. Phase 2 Task 2, a verification step.
- An empty cart, or a cart below the purchase campaign's `min_subtotal` with no bonus, renders no cart row in classic and block. Phase 2 Task 3, a verification step.
- A Lite build shows no bonus lines and no locked rows, and the validator passes. Phase 1 Task 4 and Phase 3 Task 3, verification steps.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | Ways to earn (launcher, My Points, `[yayloyalty_ways_to_earn]`) shows personal values and bonus teasers | `composer test` passes, including the two new tests; spec criteria 1, 2 and 7 hold by hand on the Local site | done |
| 2 | The product line shows the personal figure and bonus lines; a new cart and checkout earn row in classic and block | Spec criteria 3, 4 and 8 hold by hand on the Local site (Twenty Twenty-Five block cart plus a classic `[woocommerce_cart]` page) | pending |
| 3 | Locked rewards with teasers; a per-item teaser override in the admin; e2e coverage | `pnpm --filter @yayloyalty/e2e test specs/conditional-offers.spec.ts` passes; `./scripts/run.sh release lite --no-prompt` passes the validator; manual walk of criteria 1–6 | pending |

## Interface contract

**Phase 1 produces:**
- `PointsEngine::OFFER_MATCHED = 'matched'`, `OFFER_LOCKED = 'locked'`, `OFFER_AT_CHECKOUT = 'at_checkout'`.
- `PointsEngine::offers( string $trigger, Context $advert, ?Context $viewer ): array` returns `array{base: ?array{campaign: Campaign, value: float}, row: ?array{campaign: Campaign, value: float}, bonuses: list<array{campaign: Campaign, value: float, status: string, results: array}>}`. The walk stops at the first campaign for everyone or the first conditional one the viewer meets. `row` is the most a member is sure to be paid: what stopped the walk, or a lower-paying at-checkout campaign before it; null when nothing on the walk is sure to pay; `base` for a guest. `results` are each bonus's `explain()` rows. Each campaign is explained once and never also evaluated. Consumed by `OfferLines` (P1 T3) and the product page (P2 T2).
- Evaluator seam: the object from `PointsEngine::CONDITIONS_EVALUATOR_FILTER` must also expose `explain( array $conditions, Context $ctx ): array` for bonuses to be returned.
- `OfferLines` (`includes/Engine/Storefront/OfferLines.php`, Lite):
  - `PHRASE_FILTER = 'yayloyalty_offer_condition_phrase'`, `MAX_BONUSES = 3`, `MODE_AUTO = 'auto'`, `MODE_CUSTOM = 'custom'`, `MODE_OFF = 'off'`, `TEXT_MAX = 120`.
  - `for_trigger( PointsEngine $engine, string $trigger, Context $advert, ?Context $viewer ): array`, memoised per request by trigger, the viewer's contents (user id, lines, subtotal, checkout inputs) and time, whatever engine instance asks returns `array{base: ?array{campaign: Campaign, value: float}, row: ?array{campaign: Campaign, value: float}, bonuses: list<array{campaign: Campaign, value: float, status: string, teaser: string}>}`. Consumed by P1 T4, P2 T2 and P2 T3.
  - `teaser( array $config, array $conditions ): ?string`. Consumed by P3 T2 for rewards.
  - `enabled(): bool`: `DesignHelper::get_design()['visibility']['show_bonus_offers']`. Consumed by every surface.
- Filter `yayloyalty_offer_condition_phrase` `( ?string $phrase, array $conditions ): ?string`, which `ConditionPhrases` attaches in Pro.
- `ConditionPhrases` (`includes/Pro/Storefront/ConditionPhrases.php`): `register(): void`, `phrase( ?string $phrase, array $conditions ): ?string`. Consumed by P3 T1 (preview route).
- `LauncherEarnRules::advertise( PointsEngine $engine, Context $ctx, bool $can_register, int $viewer_id = 0 ): array`, whose return adds `bonuses: array<string, string[]>` keyed by trigger (each string is a whole bonus line).
- The `LauncherPayload::for_balance()` / `build()` payload adds `earn_bonuses: array<string, string[]>`.
- `LauncherEarnRules::member_rows( array $earn, float $purchase_min, string $purchase_rounding = PointsMath::ROUND_DOWN, ?int $viewer_id = null, array $bonuses = [] ): array`: each row may carry `bonuses: string[]`. `list_item()` passes `bonuses` through to `rule-list-item.php`.
- Design key `visibility.show_bonus_offers` (bool, default `true`).

**Phase 2 produces:**
- `PointsEngine::quote_campaign( Campaign $campaign, Context $ctx ): int`.
- `ContextFactory::from_product()` and `from_cart()` carry the facts loader for a user id > 0. `from_cart()` carries `EXTRA_PAYMENT_METHOD`, `EXTRA_BILLING_COUNTRY` and `EXTRA_SHIPPING_COUNTRY`.
- Variation data key `yayloyalty_earn_bonuses` (string HTML).
- `CartEarnPreview` (`includes/Engine/Storefront/CartEarnPreview.php`): `register(): void`, `markup( \WC_Cart $cart, int $user_id ): string`, `STORE_API_NAMESPACE = 'yayloyalty-earn-preview'`, `ScriptName::CART_EARN_BLOCKS_SCRIPT = 'yayloyalty/script/storefront-cart-earn-blocks'`.
- Design keys `visibility.show_cart_earn_preview` (bool, default `true`) and `storefront_text.cart_earn_line` (string, required, default "You'll earn {points} {points_label} with this order").

**Phase 3 produces:**
- Config keys `advert_mode` (`enum` with values `auto`, `custom`, `off`) and `advert_text` (`string`, ≤ `OfferLines::TEXT_MAX` characters after `sanitize_text_field()`) on every campaign trigger and reward type.
- `LauncherPayload::REDEEM_OFFERS_FILTER` callbacks get a third argument, `int $user_id` (0 for a guest). An offer may carry `locked: bool` and `teaser: string`.
- The campaign preview response adds `phrase: string|null`.
