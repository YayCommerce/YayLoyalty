---
id: BS-7
title: Launcher shows only the rewards a shopper qualifies for
status: decided
from: R-8
decision: A. Check every condition through the gate's filter; guests get a fixed label
created: 2026-11-25
---
# BS-7 Launcher shows only the rewards a shopper qualifies for

## Problem
"Ways to redeem" (the launcher panel, the My Points tab and `[yayloyalty_ways_to_redeem]` and its block, all from `LauncherPayload::for_user()`) lists every live catalog reward, while the cart and checkout card hide the rewards whose conditions the shopper fails (P-1, BL-1). Dev has narrowed it partway: `RewardsPanel::redeem_offers()` (`includes/Pro/Storefront/RewardsPanel.php:47`) now gets the user id and asks `RewardTierCondition::allows()`. It checks only the **tier** condition, so a reward limited by `user_role`, `customer_ids` or `lifetime_points` still shows to every member and every guest. A guest also never sees a tier-gated reward, and nothing tells them it exists.

R-8 acceptance: a signed-in shopper sees in the launcher only the rewards whose conditions they meet. A guest sees conditional rewards labelled as for selected customers. A cached page never shows one shopper's list to another.

**PR #183** (`paul/feat/conditonal-campaigns`, open, last updated 2026-11-12, 23 commits) covers part of this in commit `48f1b08`. It is a larger feature: it advertises conditional campaigns as bonus teasers on the product page, cart and launcher. For "Ways to redeem" it:
- evaluates each reward through the gate's conditions filter with a balance of `PHP_INT_MAX`, so only the conditions decide (`RewardsPanel::conditions_hold()`);
- shows a failing reward as a **locked** row, with a teaser in place of its description: the merchant's `advert_text`, or the conditions put into words by `ConditionPhrases` ("for VIP members and once you've earned 500 Points"). With no teaser, or with Design → "Show bonus offers" off, the row is hidden;
- treats a guest as failing every condition, so guests get the locked rows and the cached guest page stays the same for everyone;
- adds `RewardConditionsMatch`, its own matcher built on the campaigns' `ConditionsEvaluator`, with `tier` "not yet evaluable".

Measured against dev and R-8:
- **Already on dev:** the gate reorder (conditions before balance, `includes/Engine/Redemption/RewardGate.php:36`) and passing a user id to `REDEEM_OFFERS_FILTER` (`includes/Engine/Storefront/LauncherPayload.php:244`).
- **Duplicated:** `RewardConditionsMatch` repeats P-1's `RewardConditionsEvaluator` and `RewardTierCondition`, which are both on the filter now (`includes/Pro/YayLoyaltyPro.php:131`). Dev's pair also handles tier, so #183's matcher would have to go.
- **Different from R-8:** members see the locked rows they don't qualify for, and acceptance says they see only the ones they meet. A guest sees a teaser that names the condition, or nothing for a customer-list condition, rather than "for selected customers".
- **Stale:** merging it into dev conflicts in 7 files, including `LauncherPayload.php`, `RewardsPanel.php`, `RewardGate.php`, `PointsEngine.php` and the reward editor.

## Constraints
- **Pro only** (R-8 `editions: pro`). Lite returns `[]` from `REDEEM_OFFERS_FILTER` and must stay unchanged. Pro attaches only through that filter (hard rules 1 and 2).
- **All four reward condition types are facts about the customer**, not the cart (`includes/Pro/Services/RewardConditionsEvaluator.php:60`, `RewardTierCondition.php:47`). The launcher can decide them with no cart, through the same `yayloyalty_reward_conditions_match` filter the card uses, so the listing and the claim can never disagree. A guest fails every condition by design.
- **Caching.** The launcher is printed server-side in `wp_footer` (`includes/Engine/Storefront/Launcher.php:45`), and so is the shortcode or block on any page. Guest pages are page-cached, so a guest's list must not depend on who the guest is. Signed-in pages already print the balance, and the class docblock accepts that (`LauncherPayload.php:22`). A per-member list adds no new exposure, as long as the common caches keep skipping signed-in pages.
- **The Design preview** calls `for_balance()` with no user (`includes/Engine/Storefront/LauncherPreview.php:46`), so it has no real member to check conditions against.
- **Design → Panel → `show_reward_catalog`** hides offers in the launcher only (`Launcher.php:168`). The new rules must respect it.
- PHP 7.4. Malformed offer rows are dropped by `LauncherPayload::redeem_offers()`, so a new row field must pass that check.

## Options

### A. Check every condition through the gate's filter; guests get a fixed "for selected customers" line (fresh on dev)
- **How:** in `RewardsPanel::redeem_offers()`, replace the `RewardTierCondition::allows()` call with the same gate seam the card uses: build a cart-less `Rewards\Context` with a `PHP_INT_MAX` balance and a lazy facts loader (`EarnContextFactory::customer_facts`, the same as `Rewards\ContextFactory::from_cart()` at `:50`), then apply `yayloyalty_reward_conditions_match`. This mirrors #183's `conditions_hold()`, minus its matcher. For a member, a failing reward is dropped. For a guest (null user), a reward that `has_conditions()` is kept, with its description replaced by one fixed, translated line such as "For selected customers". The line is the same for every guest, so the cached page is safe. A reward with no conditions shows as today. Members see no labels.
- **Pros:** meets acceptance word for word. A small diff in one Pro file, plus maybe a `text` override, with no new offer-row field and no Lite change. Reuses P-1's evaluator, so the listing and the claim can't drift apart. Leaves #183's teaser idea open as a later layer.
- **Cons:** a member never learns that a reward they could work towards exists (no "earn 200 more Points to unlock"). The guest line is generic and says nothing about how to qualify.
- **Risks:** one facts read per member per page, the same as the card. The Design preview acts as a guest, so its member sample shows labelled rows instead of the merchant's own view, which is a small, explainable difference.
- **#183 afterwards:** it rebases onto this and drops `RewardConditionsMatch`. Its locked rows then become a product choice for #183's own review, not R-8's.
- **Size:** S

### B. Land #183's "Ways to redeem" slice: locked rows with teasers (port `48f1b08`)
- **How:** take from #183 the `locked`/`teaser` offer row (`LauncherPayload::is_offer()`, the `ways-to-redeem.php` locked tone, the `rule-list-item.css` styling), `OfferLines::teaser()` with the per-reward `advert_mode`/`advert_text` config (`OfferLines::CONFIG_SPECS` added to `Reward::config_specs()`, plus the editor field `StorefrontTeaserField.tsx`), and `ConditionPhrases`. Evaluate through dev's filter, not `RewardConditionsMatch`. Members see what they qualify for, plus locked rows with "what it takes". Guests see locked rows with the same teaser.
- **Pros:** the richer storefront. It tells shoppers how to unlock a reward, and the merchant controls the wording. Reuses tested code from a teammate's branch.
- **Cons:** **breaks R-8 acceptance**: a member sees rewards whose conditions they don't meet (as locked rows), and a guest sees a condition phrase, not "for selected customers". It drags in parts of #183's admin and config surface (the teaser field, the config specs, `OfferLines`, which also serves the earn bonuses) and has to be untangled from the campaign-bonus half while #183 is still open. Two branches would end up editing the same files.
- **Risks:** a customer-list or `not_in` condition can't be worded, so that reward vanishes for everyone without a custom `advert_text`, which a merchant may find confusing. The rebase conflicts in 7 files.
- **Size:** M–L

### C. A's rules, but the member's list comes from a REST call after page load
- **How:** the HTML always carries the guest list, labelled as in A. For a signed-in viewer, `launcher.js` (and the My Points tab) fetch their own list from a new storefront route, the way the activity rows already load through `StorefrontActivityRestController` (`includes/Controllers/StorefrontActivityRestController.php:81`), and swap it in.
- **Pros:** the strongest reading of "a cached page never shows one shopper's list to another": it is safe even on a store that caches signed-in pages.
- **Cons:** the balance is still printed into the same page, so such a store leaks it anyway, and C protects the list but not the number beside it. Adds a REST route, nonce handling, a loading state and JS for the shortcode and block too. The list flickers in after load.
- **Size:** M

## Recommendation
**A.** It is the only option that meets R-8's acceptance as written, and it does so in about one Pro file by calling the same conditions filter the card uses. A reward then can't be listed in the launcher and refused at checkout, or the reverse. The guest line is the same for every visitor, so cached guest pages stay safe, and signed-in pages carry no more risk than the balance already does. That makes C's extra route and JS solve a problem the page still has elsewhere. Of #183, A keeps the part that matters for R-8 (evaluate the conditions with an unbounded balance; a guest fails them) and leaves the locked/teaser display as a separate product decision. #183 can still bring that in after it rebases, once it drops its duplicate matcher.

**Decided (2026-11-25):** A. A guest's line reads "For selected customers" (one fixed, translatable string).

What would change my mind: if product wants shoppers to see what they could unlock (the locked rows), choose B and rewrite R-8's acceptance first. If a supported customer runs a cache that serves signed-in pages, C becomes worth it, but only together with moving the balance out of the HTML.

## Open questions
- **The Design preview:** should the member sample show the labelled guest rows (A's default), or every reward unlabelled as a merchant would expect?
- **#183's future:** does the team still want locked rows with teasers for members? If so, it's a follow-up item on top of A, with its own acceptance, not part of R-8. Its owner should know A changes `RewardsPanel::redeem_offers()` under their branch.
- **My Points tab:** it shares the list. Should it follow the launcher's rules exactly (A assumes yes), given that only members see it?
