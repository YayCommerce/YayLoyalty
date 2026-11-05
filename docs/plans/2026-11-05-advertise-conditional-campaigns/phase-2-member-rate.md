# Phase 2: A member's rate in Ways to earn

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 Tasks 1 to 3
**Delivers:** A member sees the rate their conditions earn them in "Ways to earn". Guests see what they see today.
**Exit criterion:** `composer test` passes, and spec criteria 7 to 9 hold on a Pro site.

### Task 1: Advert with a known shopper

**Files:**
- Modify: `includes/Engine/Points/Context.php`, `includes/Engine/Points/ContextFactory.php`, `includes/Engine/Points/PointsEngine.php`
- Test: `tests/unit/Engine/Points/PointsEngineAdvertisedTest.php`
- Create: `docs/decisions/2026-11-05-advertise-conditions-with-a-three-state-verdict.md`

**Interfaces:**
- Consumes: private `PointsEngine::verdict()`, `ContextFactory::shared_facts()`
- Produces: `Context::advert()` with a user, `ContextFactory::for_member_advert()` (see README contract)

Test cases: a member advert with `true` above B returns the conditional rate; with `false` returns B's; with `null` at 0.5 returns 0.5, and at 3 returns B's. `test_conditional_campaign_is_skipped_without_asking_the_evaluator` stays as it is.

`advertised()` replaces `applies_to_everyone()` and `matches()` with the private `verdict()`: `false` continues, `null` lowers, `true` lowers and stops. The docblocks of `advertised()`, `Context::advert()` and `yayloyalty_campaign_matches` change to match. `for_member_advert()` uses `shared_facts()`, so a page shares one read with the product line.

- [ ] **Step 1: Add the four member cases to `PointsEngineAdvertisedTest.php`, with `attach_verdicts()` and `member_advert()` helpers**
- [ ] **Step 2: Run `composer test -- --filter PointsEngineAdvertisedTest`.** Expected: FAIL on the new cases.
- [ ] **Step 3: Extend `Context::advert()`, add `ContextFactory::for_member_advert()`, and rewrite the loop in `advertised()` on the private `verdict()`**
- [ ] **Step 4: Run `composer test`.** Expected: PASS, the existing cases unedited.
- [ ] **Step 5: Add the decision record, Status proposed**
- [ ] **Step 6: Commit.** Stage the files above. Message: `feat(campaigns): advertise conditions a known shopper meets`

### Task 2: Launcher payload uses it

**Files:**
- Modify: `includes/Engine/Storefront/LauncherPayload.php`, `docs/architecture.md`

**Interfaces:**
- Consumes: `ContextFactory::for_member_advert()`
- Produces: `LauncherPayload::for_balance()` with `$user_id` (see README contract)

`build()` passes the user id; `for_balance()` picks `for_member_advert()` when it is set, else `for_advert()`. `LauncherPreview`, `BirthdayEarnRow`, `Launcher::viewer_can_earn()` and `ProductEarnMessage::guest_line()` don't change. 

- [ ] **Step 1: Add `?int $user_id = null` to `for_balance()`, pass it from `build()`, and pick the Context by it**
- [ ] **Step 2: Add the member rate to the launcher's entry in `docs/architecture.md`**
- [ ] **Step 3: Verify on the local Pro site, with campaigns injected through `yayloyalty_campaign_candidates`.** Read `LauncherPayload::for_user()['earn']['purchase']`. `order_count gte 5` at 2 above B at 1: a member with 5 or more paid orders gets 2.0, a member with fewer gets 1.0, a guest 1.0. `category_in_cart` at 3 above B: 1.0 for a member; at 0.5: 0.5.
- [ ] **Step 4: Verify the reads.** With no facts condition live, a member's payload makes 0 reads of their orders. With one live, a payload and a product line together make 1.
- [ ] **Step 5: Commit.** Stage the files above. Message: `feat(storefront): show a member the rate their conditions earn`
