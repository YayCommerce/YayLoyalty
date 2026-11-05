# Phase 2: The reward TIER condition and reward hiding

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 landed and `/yayplancheck` passed. This phase needs no Phase 1 interface, only its tree state.
**Delivers:**
- REST accepts `{type: "tier", operator: "gte", value: <tier id>}` on coupon rewards.
- Customers below that tier, guests, and everyone while tiers are off, don't see the reward on the redeem card, in the launcher or in My Account, and can't claim it.
- An applied, now-ineligible reward stays as a disabled row.

**Exit criterion:** `composer test:integration -- --filter RewardTierConditionTest` passes, and spec AC 8–13 pass by Task 3's manual checks.

### Task 1: TIER vocabulary and save validation (Lite)

**Files:**
- Modify: `includes/Enums/RewardConditionType.php` (`UNAVAILABLE`, `schema()` TIER operators, and the TIER docblock)
- Modify: `includes/Models/RewardConditionSet.php` (`validate_value()`)
- Modify: `includes/Controllers/RewardRestController.php` (`conditions_error()`, and the create and update callers, which pass the reward type)

**Interfaces:**
- Consumes: `TierRepository::find_live()`.
- Produces: TIER accepted by `RewardConditionType::all()`, with operator `gte` only and a positive-int value. Two 400 messages. See the README contract.

The checks run in this order:
1. **Structure:** the existing "The reward conditions are malformed." message.
2. **Type:** "Only coupon rewards can require a tier." when the type isn't `RewardType::FIXED_COUPON` or `RewardType::PERCENT_COUPON`.
3. **Liveness:** "That tier no longer exists.".

On update, the reward type is the stored row's type.

**Verification** (spec AC 13, with `WP` and the REST eval form from the slice-1 plan):
- `PUT /rewards/{flexible id}` with a TIER entry returns 400 "Only coupon rewards can require a tier.".
- On a `percent_coupon`:
  - value `999999` returns 400 "That tier no longer exists.";
  - operator `in` returns 400 "The reward conditions are malformed.";
  - value `"abc"` returns 400 "The reward conditions are malformed.";
  - `gte` with Silver's id returns 200, and a later `GET` shows the entry.

Steps: expand after Phase 1 lands.

### Task 2: RewardFacts and RewardTierCondition

**Files:**
- Create: `includes/Engine/Rewards/RewardFacts.php`
- Modify: `includes/Engine/Rewards/Context.php` (`$facts` type, `get_facts()`)
- Modify: `includes/Engine/Rewards/ContextFactory.php` (constructor, `facts_for()`, and the three `from_*()` builders; remove `TODO(redemption)`)
- Modify: `includes/Engine/Redemption/RewardGate.php` (docblock only: names `RewardFacts` and TIER as the only enforced type)
- Create: `includes/Pro/Services/RewardTierCondition.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (`boot()`)
- Test: `tests/integration/Engine/Redemption/RewardTierConditionTest.php`

**Interfaces:**
- Consumes: Task 1's TIER vocabulary.
- Produces: `RewardFacts`, `ContextFactory::facts_for()`, `RewardTierCondition::allows()` and `matches()`. See the README contract.

**Test** (accepted): `final class RewardTierConditionTest extends IntegrationTestCase`, set up like `TierEvaluatorTest` (Pro skip, pinned `tiers_enabled`, seeded tiers). Reward R comes from `pro_reward( 'fixed_coupon', 100, [...] )`, with conditions set to the Silver `gte` entry. Assertions, each through `( new RewardTierCondition() )->allows( R, ( new ContextFactory( new CustomerRepository() ) )->facts_for( $user ) )`:
- Silver customer → `true`; Gold → `true`; Bronze → `false`; a customer with a row but no tier → `false`; guest (`facts_for( null )`) → `false`.
- With `tiers_enabled` false, the Gold customer → `false`.
- A reward with only a `user_role` entry, for the Bronze customer → `true`.
- After `TierRepository::soft_delete( Silver )`, the Gold customer → `false`.
- **Through the gate:** `RedemptionService::build( … )`'s quoter, quoting R for the Bronze customer over `cart_for( 50.0 )`, gives reason `Result::REASON_CONDITIONS_NOT_MET`. For the Silver customer the quote is allowed.

Steps: expand after Phase 1 lands.

### Task 3: Hiding gated rewards in every listing

**Files:**
- Modify: `includes/Engine/Redemption/RedemptionService.php` (`reward_options()`)
- Modify: `includes/Engine/Storefront/LauncherPayload.php` (`for_balance()`, `build()`, `redeem_offers()`, and the filter docblock)
- Modify: `includes/Pro/Storefront/RewardsPanel.php` (`register()` to 3 args, `redeem_offers()`, and the class docblock)

**Interfaces:**
- Consumes: Task 2's `RewardTierCondition::allows()` and `ContextFactory::facts_for()`.
- Produces: the third filter argument and `reward_options()`'s skip rule. See the README contract.

Notes:
- `LauncherPreview` keeps calling `for_balance()` without a user, so the Design preview hides gated rewards (accepted in the spec).
- `RewardsPanel::redeem_offers()` builds one `ContextFactory( new CustomerRepository() )` and reads `facts_for( $user_id )` once per call.

**Verification** (wp-env, with reward R "Minimum tier: Silver" set by REST, and S with only a REST-set `user_role` entry the customer doesn't meet):
1. **AC 8:** Silver and Gold customers see R on the redeem card, in the launcher's Ways to redeem and in My Account → Rewards, and can tick it and check out. A Bronze customer sees R in none of them. A forced claim by Bronze (the redeem AJAX with R's id, from the browser console) is refused, and the card shows "Your account cannot spend Points on this order."
2. **AC 9:** with tiers off, the Gold customer sees R nowhere.
3. **AC 10:** a guest's launcher doesn't list R.
4. **AC 11:** a Silver customer ticks R, then staff Adjust −200 drops them to Bronze. Reloading the cart shows R checked and disabled. Placing the order fails with "One of your rewards can no longer be used on this order. Review your rewards and try again." Unticking R removes its row.
5. **AC 12:** S is listed and claimable for that customer.

Steps: expand after Phase 1 lands.
