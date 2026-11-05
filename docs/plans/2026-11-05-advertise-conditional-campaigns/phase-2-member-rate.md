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

Steps: expand after Phase 1 lands.

### Task 2: Launcher payload uses it

**Files:**
- Modify: `includes/Engine/Storefront/LauncherPayload.php`, `docs/architecture.md`

**Interfaces:**
- Consumes: `ContextFactory::for_member_advert()`
- Produces: `LauncherPayload::for_balance()` with `$user_id` (see README contract)

`build()` passes the user id; `for_balance()` picks `for_member_advert()` when it is set, else `for_advert()`. `LauncherPreview`, `BirthdayEarnRow`, `Launcher::viewer_can_earn()` and `ProductEarnMessage::guest_line()` don't change. The verification step covers spec criteria 7 to 9 and shows no reads on a page with no facts condition.

Steps: expand after Phase 1 lands.
