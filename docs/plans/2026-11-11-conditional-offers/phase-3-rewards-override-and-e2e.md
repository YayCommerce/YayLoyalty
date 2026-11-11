# Phase 3: Locked rewards, teaser override, e2e

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 (`OfferLines::teaser()`, `ConditionPhrases`) and Phase 2 (the cart and product surfaces the e2e spec covers)
**Delivers:** Ways to redeem shows locked rewards with a teaser and the redeem card hides them; merchants set Auto / Custom / Off per campaign and reward; e2e coverage of spec criteria 1–6.
**Exit criterion:** `pnpm --filter @yayloyalty/e2e test specs/conditional-offers.spec.ts` passes on wp-env; `./scripts/run.sh release lite --no-prompt` passes the validator (then `composer install`); `composer test` passes; a manual walk of spec criteria 1–6 on the Local site holds (spec criterion 12).

### Task 1: The `advert_mode` / `advert_text` keys and the "Storefront teaser" field

**Files:**
- Modify: `includes/Models/Campaign.php` (`config_schema()`: both keys under every Lite trigger)
- Modify: `includes/Models/Reward.php` (`config_schema()`: both keys under every Lite type; length cap on save)
- Modify: `includes/Pro/YayLoyaltyPro.php` (`add_campaign_config_schema()`, `add_reward_config_schema()`, `add_config_keys()`: both keys for Pro triggers and types)
- Modify: `includes/Pro/Controllers/CampaignPreviewRestController.php` (response `phrase`)
- Modify: the zod schemas for campaign and reward config under `apps/admin/src/lib/schema/`
- Create: `apps/admin/src/pro/shared/StorefrontTeaserField.tsx`; export it through `apps/admin/src/pro/index.ts`
- Modify: the Pro campaign and reward editor condition sections under `apps/admin/src/pro/campaigns/` and `apps/admin/src/pro/rewards/` (render the field when the item has at least one condition)

**Interfaces:**
- Consumes: `OfferLines::MODE_*`, `TEXT_MAX`, and `ConditionPhrases::phrase()` through the `yayloyalty_offer_condition_phrase` filter
- Produces: the config keys and the preview `phrase` (README contract)

Steps: expand after Phase 2 lands. The checks: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` exits 0. Saving a campaign with `advert_text` of 121 characters is rejected with the REST error the other config keys use. A Lite build keeps both keys on a saved campaign (`wp eval` reading `get_config()` after a downgrade). Spec criterion 6 holds for campaigns.

### Task 2: Locked rewards in Ways to redeem, hidden in the redeem card

**Files:**
- Modify: `includes/Engine/Storefront/LauncherPayload.php` (`redeem_offers( string $now, int $user_id )`, the filter's third argument)
- Modify: `includes/Pro/Storefront/RewardsPanel.php` (`redeem_offers( array $offers, string $now, int $user_id )`, `rows()` drops `REASON_CONDITIONS_NOT_MET`)
- Modify: `includes/Engine/Storefront/templates/ways-to-redeem.php`, `rule-list-item.php` (`tone` `locked` → `.yayloyalty-rule--locked`, the teaser as the text line)
- Modify: `assets/storefront/account/rule-list-item.css`

**Interfaces:**
- Consumes: the `yayloyalty_reward_conditions_match` filter on a `Engine\Rewards\Context` for the viewer, `OfferLines::teaser( $reward->get_config(), $reward->get_conditions() )`, `OfferLines::enabled()`
- Produces: the offer keys `locked` and `teaser`, and the filter's third argument (README contract)

Steps: expand after Phase 2 lands. The checks: spec criterion 5, plus the reward half of criterion 6. With `show_bonus_offers` off, locked rewards are absent. The Design tab preview (guest) shows conditional rewards as locked when they have a teaser.

### Task 3: e2e spec, docs, final verification

**Files:**
- Create: `apps/e2e/specs/conditional-offers.spec.ts`
- Modify: `apps/e2e/bin/setup.php` or `setup.sh` only if the `vip` role and a Shoes category can't be seeded from the spec through `wpCli()` (prefer seeding inside the spec)
- Modify: `docs/architecture.md` (one paragraph: `PointsEngine::offers()` / `OfferLines` as the single source for storefront advertising, and the draft-Context rule in the evaluator)
- Modify: `docs/plans/2026-11-11-conditional-offers/README.md` (Status `done 2026-MM-DD` and phase statuses, in the PR that finishes the plan)

**Interfaces:**
- Consumes: `apps/e2e/support/` (`adminApi`, `wpCli`, `logIn`, `openLauncher`, `addWidgetsToCart`)

Steps: expand after Phase 3 Task 2 lands. The spec seeds the spec's setup (Base, VIP, Shoes, the "VIP $10" reward, the role, a Shoes product at $100 and a non-shoe product at $100). It asserts criteria 1–6 as a guest, a plain customer and a `vip` customer, on the block cart. It switches `advert_mode` through `adminApi()`.
