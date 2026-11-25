---
id: P-12
title: Ways to redeem lists only the rewards a shopper qualifies for
status: in-progress
lane: small
from: BS-7
roadmap: R-8
type: feat
branch: feat/p-12-ways-to-redeem-lists-only-the-rewards-a-shopp
pr:
editions: [pro]
created: 2026-11-25
---
# P-12 Ways to redeem lists only the rewards a shopper qualifies for

## Goal
"Ways to redeem" (launcher panel, My Points tab, `[yayloyalty_ways_to_redeem]` and its block/widgets) decides each catalog reward's conditions through the same `yayloyalty_reward_conditions_match` filter the cart and checkout card use (BS-7 option A). Done means: a signed-in shopper sees only the rewards whose conditions they meet (role, customer list, lifetime points and tier); a guest sees a conditional reward with the one fixed line "For selected customers" in place of its description; and the guest output is the same for every guest, so a cached page never shows one shopper's list to another (R-8 acceptance).

## Tasks
- [ ] T1 Decide every condition in `RewardsPanel::redeem_offers()` — `includes/Pro/Storefront/RewardsPanel.php`, `includes/Pro/Services/RewardTierCondition.php`
  Replace the `RewardTierCondition::allows()` call. A member (`$user_id` set): build a cart-less `Rewards\Context` (balance `PHP_INT_MAX`, so only conditions decide; no discountable amount, no cart; `CurrencyRate::current()` and its rate; a lazy facts loader as `ContextFactory::from_cart()` builds it) and keep the reward only when the filter answers true. A guest (`null`): keep every reward; one that `has_conditions()` gets `text` = `__( 'For selected customers', 'yayloyalty' )`. Rewards with no conditions print as today. Update the class docblock and `RewardTierCondition`'s docblock, which both say the listings call `allows()`.
  Reuse: `LauncherPayload::offer()` in `includes/Engine/Storefront/LauncherPayload.php`; `Reward::has_conditions()` in `includes/Models/Reward.php`; `EarnContextFactory::customer_facts()` in `includes/Engine/Points/ContextFactory.php`; `CurrencyRate::current()`/`rate()` in `includes/Utils/CurrencyRate.php`; the `yayloyalty_reward_conditions_match` filter answered by `RewardConditionsEvaluator` and `RewardTierCondition` (`includes/Pro/YayLoyaltyPro.php:131`). New private `conditions_hold()` because `Rewards\ContextFactory` only builds from a cart or an order.
  Callers: `RewardsPanel::conditions_hold()` → `RewardsPanel::redeem_offers()` → filter `yayloyalty_launcher_redeem_offers`
- [ ] T2 Integration test for who sees which reward — `tests/php/integration/Pro/Storefront/RewardsPanelTest.php` (new)
  Reuse: `IntegrationTestCase`; the fixtures pattern (pinned settings, `TierRepository::seed_defaults()`, created users) of `tests/integration/Engine/Redemption/RewardTierConditionTest.php`

**Tests:** [integration] test_member_sees_only_rewards_whose_conditions_hold (tests/php/integration/Pro/Storefront/RewardsPanelTest.php) — a role-, customer-list- or lifetime-points-gated reward still listed to a member who fails it; [integration] test_tier_gated_reward_follows_member_tier (same file) — regression of the tier check moving onto the filter; [integration] test_guest_sees_conditional_rewards_labelled (same file) — guest list must keep conditional rewards with the fixed line, unconditioned ones unchanged, and be identical for two guest calls

## Verify
- `yayh test run integration --id=P-12` → exit 0 (integration)
- `yayh test run unit` → exit 0
- `vendor/bin/phpcs includes/Pro/Storefront/RewardsPanel.php includes/Pro/Services/RewardTierCondition.php` → exit 0
- `git diff --quiet dev -- includes ':!includes/Pro' apps assets` → exit 0 (Lite unchanged)
- `yayh code unused` → exit 0
- Member meets conditions: as a customer with the role a "Wholesale only" reward requires, open the launcher → Ways to redeem and My Account → My Points → the reward is listed with its normal line (integration)
- Member fails conditions: as a customer without that role, the same two places → the reward is not listed; the cart/checkout card doesn't offer it either (integration)
- Guest: logged out, open a page with `[yayloyalty_ways_to_redeem]` → the conditional reward shows "For selected customers" under its name and its cost; a reward with no conditions shows its usual line (integration)
- Cached guest page: `curl -s <page with the shortcode> | grep -c "For selected customers"` from two fresh sessions → the same count and the same markup
- Design → Panel → "Show reward catalog" off → the launcher lists no offers; My Points still lists them
- Design tab preview, member sample → still shows the sample rewards (it replaces offers with samples, `LauncherPreview.php:49`)

## Decided
- Small lane: one Pro file plus a test, no stored data, no new screen.
- The Design preview needs no change: `LauncherPreview::documents()` replaces `offers` with sample rewards (`includes/Engine/Storefront/LauncherPreview.php:49`), so BS-7's preview question is moot.
- My Points tab follows the launcher's rules: it reads the same payload.
- The guest launcher panel lists no offers today (`panel-guest.php` has no Ways to redeem); guests see the labelled rows on the shortcode, block and page-builder widgets. Whether the guest panel should list them is BL-36.
- Members see no labels; a reward they fail is dropped (R-8, not #183's locked rows).

## Risks
- One facts read per member per page view, only when a lifetime-points condition is asked (lazy loader), the same cost as the card. Rollback: revert T1.
- PR #183 changes `RewardsPanel::redeem_offers()` too; its owner rebases onto this and drops `RewardConditionsMatch`.
- Open: BL-36 Should the guest launcher panel list conditional rewards labelled? (default: no, guest panel unchanged)
- Settles: BL-1 planned P-12
