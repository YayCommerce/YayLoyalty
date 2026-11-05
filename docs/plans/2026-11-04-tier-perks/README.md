# Tier Perks (Tiers slice 3) Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** done 2026-11-05
**Goal:** Tiered customers earn their tier's multiplier on every campaign award, shown on the admin ledger row. Coupon rewards can require a minimum tier, set in the reward editor, and lower-tier customers neither see nor claim them.
**Spec:** [docs/specs/2026-11-04-tier-perks-design.md](../../specs/2026-11-04-tier-perks-design.md) (accepted 2026-11-04), a child of [the tiers master](../../specs/2026-11-02-tiers-master-design.md).
**Architecture:**
- **Multiplier:** a Lite seam in `PointsEngine::evaluate()` asks `yayloyalty_points_multiplier` for an `Engine\Points\Multiplier` after `CapPolicy`, and floors the product in integer hundredths. `EarningService` copies the multiplier onto the EARN draft, as two new ledger columns.
- **Rewards:** the claim Context carries a new `Engine\Rewards\RewardFacts`. Pro's `RewardTierCondition` answers the existing `yayloyalty_reward_conditions_match` filter, and its `allows()` serves the listing paths that have no quote.
- **Campaigns** stay tier-independent (master D9).

**Touches:**
- **Lite (shared):**
  - `includes/Engine/Points/` (`Multiplier` [new], `PointsEngine`, `Breakdown`, `Result`): consumers are `EarningService`, `ProductEarnMessage`, the pending-points staging, `LauncherEarnRules`/`advertised()` (unchanged path) and the unit engine tests. The changes are additive, and a neutral multiplier gives identical results.
  - `includes/Engine/Earning/EarningService.php`.
  - `includes/Database/Schema/LedgerTable.php`, `includes/Models/LedgerEntry.php`: consumers are every ledger reader and writer, and `to_array()` gains two keys.
  - `includes/Controllers/LedgerItemTierNames.php`, `includes/Controllers/LedgerRestController.php`: consumers are the customer ledger route and Pro's `StoreLedgerRestController` (which reuses the item schema).
  - `includes/Enums/RewardConditionType.php`, `includes/Models/RewardConditionSet.php`, `includes/Controllers/RewardRestController.php`.
  - `includes/Engine/Rewards/` (`RewardFacts` [new], `Context`, `ContextFactory`): consumers are `RedemptionService`, `CommitWriter`, `RewardCoupons` and the `Quoter` gates.
  - `includes/Engine/Redemption/` (`RedemptionService`, `RewardGate` docblock).
  - `includes/Engine/Storefront/LauncherPayload.php`: consumers are `Launcher`, `AccountMyPoints`, `LauncherPreview` and the templates.
- **Pro:** `includes/Pro/Services/TierMultiplier.php` [new], `includes/Pro/Services/RewardTierCondition.php` [new], `includes/Pro/Storefront/RewardsPanel.php`, `includes/Pro/YayLoyaltyPro.php`.
- **SPA:**
  - `apps/admin/src/lib/schema/ledger.schema.ts`, `apps/admin/src/lib/helpers/ledger.helper.ts` (Lite, shared by the drawer and the store ledger);
  - `apps/admin/src/pro/rewards/editor/` (`reward-editor.form.ts`, `RewardEditorSections.tsx`).
- **Docs:** `docs/backlog/2026-10-06-tiers-not-built.md`.

**Tests:** all three accepted by the user on 2026-11-04.
- `tests/unit/Engine/Points/PointsEngineMultiplierTest.php` (suite `unit`, `composer test`) guards the multiplier math:
  - 101 × 1.25 = 126;
  - cap 100, then × 1.50 = 150;
  - 100 × 1.15 = 115 (integer math);
  - malformed or out-of-range answers → 1.00;
  - the neutral `Breakdown` and `Result`.
- `tests/integration/Earning/TierMultiplierAwardTest.php` (suite `integration`, `composer test:integration`) guards the ledger write:
  - a Silver award writes 126 with `tier_id` and `tier_multiplier` 1.25;
  - tierless and tiers-off awards write NULLs;
  - the daily limit cuts the multiplied figure.
- `tests/integration/Engine/Redemption/RewardTierConditionTest.php` (suite `integration`) guards the gate:
  - Silver and Gold are allowed;
  - Bronze, tierless and guest are refused;
  - tiers off is refused;
  - a reward with no TIER entry passes;
  - a deleted required tier is refused.

## Global constraints

- **PHP 7.4.** Product PHP may not use enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union/`mixed`/`static`/`never` types, `catch` without a variable, `throw` as an expression, a trailing comma after the last declared parameter, `new` in a default value, first-class callables or `$object::class` (hard rule 4, `.claude/rules/php-standards.md`). Only review catches this.
- **Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call** (hard rule 2). `TierMultiplier` and `RewardTierCondition` attach only through `YayLoyaltyPro::boot()`. Lite files don't name Pro in code; docblocks may.
- **SPA:** tier code lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD` (hard rule 3). The reward editor is already under `pro/`.
- **Ledger:** only `LedgerService` writes ledger rows (hard rule 5). The new columns travel on the `LedgerEntry` draft from `EarningService::draft_from()`, and no construction site is added.
- **Lite drops Pro behaviour, never Pro data:** Lite's `Installer::schemas()` installs the ledger columns, and stored TIER conditions keep validating in Lite.
- **Multipliers** are `decimal(4,2)`, travel as decimal strings (`"1.25"`), and are multiplied in integer hundredths, never floats.
- **Internal seams**, documented "Internal seam, not a public API.": `yayloyalty_points_multiplier`, the third argument of `yayloyalty_launcher_redeem_offers`, and `RewardFacts`. `yayloyalty_reward_conditions_match` keeps its signature.
- **Hooks:** every hook fired has a docblock. New Pro hooks attach in a `register()` method called once from `YayLoyaltyPro::boot()`.
- **Version:** the plugin version stays `1.0.0`. A dev site gets the ledger columns through `$WP option update yayloyalty_db_version 0`, then loading any wp-admin page.
- **SPA conventions:**
  - zod parses responses in `*.api.ts`, and a TypeScript copy of a PHP shape opens with `Mirrors <PHP symbol>, the source of truth.`;
  - react-hook-form + `zodResolver`, with form logic in the `.form.ts` module;
  - UI comes from `components/ui/`, with custom wrappers where they exist;
  - failures go through `toastApiError()`.
- **Component placement:** a child component used by one parent stays in the parent's file. If `RewardEditorSections.tsx` passes ~400 lines, stop and ask the user before splitting it.
- **Strings** use the `yayloyalty` text domain, with a `translators:` comment on every string with a placeholder.
- **Lint only changed files.**
  - PHP: `composer lint -- <files>`. Expected: no error or warning in those files. A whole-repo run fails on `dev/edd` too (docs/known-issues.md).
  - Admin: `pnpm --filter @yayloyalty/admin-page exec eslint <files>`. Expected: no error in those files.
  - Types: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`. Expected: exit 0.
- **`WP`** below means `pnpm --filter @yayloyalty/e2e exec wp-env run tests-cli wp`. The site is http://localhost:8889 (the `pro` SPA built, or `./scripts/run.sh dev pro` running).
- **Branch:** all work is on `jason/feat/tiers`. Commit only when the user asks. Stage by name, use a Conventional Commits message, and end it with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review focus

- **Float rounding in the multiplier.** Expected: 100 × 1.15 = 115, never 114. Owned by Phase 1 Task 1 (unit test).
- **A neutral multiplier changing today's earning.** Expected: with no subscriber, or factor 1.00, `Result`, `Breakdown::get_points()` and the written row are identical to today, with `tier_multiplier` NULL. Owned by Phase 1 Task 1 (unit test) and Task 2 (integration test).
- **`LedgerEntry::get_format()` drifting from `to_row()`.** Expected: the two new formats sit at the same positions as their keys, so `campaign_id` and the others keep their types. Owned by Phase 1 Task 2 (the integration test reads back every column of the written row).
- **`with_points()` dropping the multiplier.** Expected: when `DailyEarningLimit` lowers an award, the written row still carries `tier_id` and `tier_multiplier`. Owned by Phase 1 Task 2 (integration test, daily-limit case).
- **Tiers off.** Expected: the multiplier is 1.00, and every gated reward is hidden and refused, Gold customers included. Owned by Phase 1 Task 2 and Phase 2 Task 2 (integration tests).
- **An applied reward after a drop.** Expected: it stays on the redeem card as a disabled row, and checkout refuses it. Owned by Phase 2 Task 3 (manual check).
- **The editor dropping REST-set conditions.** Expected: setting or clearing Minimum tier keeps every other stored entry. Owned by Phase 3 Task 1 (manual check against `GET /rewards/{id}`).
- **Lite bundle.** Expected: the Lite zip validates, and no Pro code lands in it. Owned by Phase 3 Task 2.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | Tiered customers earn multiplied points, and the admin ledger and drawer label the row " · Silver ×1.25" | `composer test -- --filter PointsEngineMultiplierTest` and `composer test:integration -- --filter TierMultiplierAwardTest` pass. On wp-env a Silver customer's 101-unit completed order shows 126 points labelled "Earned on order #{id} · Silver ×1.25" in the drawer (spec AC 1, 4, 5). | done 2026-11-05 |
| 2 | `REST` accepts a "Minimum tier" condition on coupon rewards; gated rewards are hidden and refused on the redeem card, in the launcher and in My Account | `composer test:integration -- --filter RewardTierConditionTest` passes, and spec AC 8–13 pass by their manual checks in Phase 2 | done 2026-11-05 |
| 3 | The reward editor's "Tier" section; the Lite check; the end-to-end walkthrough; the backlog updated | Spec AC 14, 15, 16, 17 and the end-to-end AC 18 pass on wp-env, and the Lite release validates | done 2026-11-05 |

## Interface contract

**PHP, Lite: earning**
- `final class Engine\Points\Multiplier` (`includes/Engine/Points/Multiplier.php`). Phase 1 Task 1.
  - `__construct( string $factor, ?int $tier_id )`.
  - `static none(): self` returns `'1.00'`, null.
  - `get_factor(): string`, `get_tier_id(): ?int`, `is_neutral(): bool` (factor `'1.00'`).
  - `apply( int $points ): int` = `intdiv( $points * hundredths, 100 )`, where `hundredths` is the factor parsed as an integer from its string (`'1.25'` → 125), with no float.
  - `static from_filter( $value ): self`: a `Multiplier` whose factor matches `/^\d{1,2}\.\d{2}$/` and lies in `'1.00'`–`'10.00'` is kept. Anything else gives `none()` and one `Log::warning( 'PointsEngine', … )`, except an untouched `none()`.
- `PointsEngine::MULTIPLIER_FILTER = 'yayloyalty_points_multiplier'`, applied as `apply_filters( MULTIPLIER_FILTER, Multiplier::none(), Campaign $campaign, Context $ctx )` in `evaluate()` only, after `CapPolicy::apply()` and before the `<= 0` check. Phase 1 Task 1.
- `Breakdown::with_multiplier( int $campaign_points, Multiplier $multiplier, int $points ): self` (a clone). Also `get_campaign_points(): int` and `get_multiplier(): string`. `to_array()` adds `campaign_points` and `multiplier`. Defaults: `campaign_points` = `points`, `multiplier` = `'1.00'`. Phase 1 Task 1.
- `Result::awarded( int $points, Campaign $campaign, Breakdown $breakdown, string $reference_type, ?int $reference_id, string $source, ?Multiplier $multiplier = null )`, and `get_multiplier(): Multiplier` (`none()` when unset). Phase 1 Task 1.
- Ledger columns `tier_id bigint unsigned DEFAULT NULL` and `tier_multiplier decimal(4,2) DEFAULT NULL`, after `campaign_id`. Phase 1 Task 2.
- `LedgerEntry`. Phase 1 Task 2.
  - The constructor gains a tail `?int $tier_id = null, ?string $tier_multiplier = null`.
  - Getters `get_tier_id(): ?int` and `get_tier_multiplier(): ?string`.
  - `to_array()` adds `tier_id` and `tier_multiplier` (a string or null).
- `LedgerItemTierNames::attach()` also fills `tier_name` for any item with a non-null `tier_id`. Ledger item schema: `tier_multiplier` is `string|null`. Phase 1 Task 3.

**PHP, Pro: earning**
- `Pro\Services\TierMultiplier`. Phase 1 Task 2.
  - `register(): void`.
  - `for_context( $multiplier, Campaign $campaign, Context $ctx ): Multiplier` (filter callback, 3 args).

**PHP, Lite: rewards**
- `RewardConditionType::UNAVAILABLE = []`. `TIER` operators `[ OP_GTE ]`. Phase 2 Task 1.
- `RewardConditionSet::validate_value()` gains `case 'tier'`: a positive int. Phase 2 Task 1.
- `RewardRestController` error messages: "Only coupon rewards can require a tier." and "That tier no longer exists." (both `error_invalid_arguments`, 400). Phase 2 Task 1.
- `final class Engine\Rewards\RewardFacts` (`includes/Engine/Rewards/RewardFacts.php`). Phase 2 Task 2.
  - `__construct( int $lifetime_earned, ?int $tier_id, int $tier_rank )`.
  - `get_lifetime_earned(): int`, `get_tier_id(): ?int`, `get_tier_rank(): int`.
- `Engine\Rewards\Context`: constructor parameter 7 becomes `?RewardFacts $facts = null`, and `get_facts(): ?RewardFacts`. Phase 2 Task 2.
- `Engine\Rewards\ContextFactory`. Phase 2 Task 2.
  - `__construct( CustomerReader $customers, ?TierRepository $tiers = null )`, which falls back to `new TierRepository()` in the body.
  - `facts_for( ?int $user_id ): ?RewardFacts`: null for a guest, `{0, null, 0}` without a customer row, and otherwise `{lifetime_earned, tier_id, rank of find_live( tier_id ) or 0}`.
- `LauncherPayload::for_balance( array $design, ?int $balance, ?int $left_today = null, ?int $user_id = null )`. `REDEEM_OFFERS_FILTER` is applied with `( array $offers, string $now, ?int $user_id )`. Phase 2 Task 3.
- `RedemptionService::reward_options()` skips `REASON_CONDITIONS_NOT_MET` quotes unless the reward's code is applied on the cart. Phase 2 Task 3.

**PHP, Pro: rewards**
- `Pro\Services\RewardTierCondition`. Phase 2 Task 2.
  - `register(): void` subscribes to `yayloyalty_reward_conditions_match` (priority 10, 3 args).
  - `allows( Reward $reward, ?RewardFacts $facts ): bool`.
  - `matches( $matches, Reward $reward, \YayLoyalty\Engine\Rewards\Context $ctx ): bool`.
- `RewardsPanel::redeem_offers( array $offers, string $now, ?int $user_id = null ): array`, registered with 3 args. Phase 2 Task 3.

**SPA**
- `ledgerEntrySchema.tier_multiplier: z.string().nullable().default(null)`. Phase 1 Task 3.
- `RewardFormValues.min_tier_id: number | null`. Two helpers exported from `reward-editor.form.ts`: `minTierOf( conditions: RewardCondition[] ): number | null` and `withMinTier( conditions: RewardCondition[], tierId: number | null ): RewardCondition[]`. Phase 3 Task 1.
