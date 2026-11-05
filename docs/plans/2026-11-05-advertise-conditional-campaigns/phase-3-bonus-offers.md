# Phase 3: Bonus offers

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 2 Tasks 1 and 2
**Delivers:** "Ways to earn" lists conditional campaigns a shopper could reach, in generated words, with a Design switch to turn them off.
**Exit criterion:** `composer test` and `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` pass, and spec criteria 10 to 15 hold on a Pro site.

### Task 1: Offers from the engine

**Files:**
- Modify: `includes/Engine/Points/PointsEngine.php`
- Test: `tests/unit/Engine/Points/PointsEngineAdvertisedTest.php`

**Interfaces:**
- Consumes: private `PointsEngine::verdict()`
- Produces: `PointsEngine::advertised_offers()` (see README contract)

Walks candidates until the first `true`, which isn't kept. Each campaign with a `null` or `false` verdict and conditions is compared with the lowest value among conditional campaigns already passed: kept when its value is above 0 and not above that lowest value, per spec criterion 11. It takes an advert or estimate Context, and throws `\InvalidArgumentException` on any other, as `advertised()` does.

Test cases: a conditional campaign above B is kept; one below B isn't; one under a conditional paying less isn't; a campaign paying 0 isn't; a `true` campaign stops the walk and isn't kept.

- [ ] **Step 1: Add the five cases to `PointsEngineAdvertisedTest.php`, plus an event Context being refused**
- [ ] **Step 2: Run `composer test -- --filter PointsEngineAdvertisedTest`.** Expected: FAIL, `advertised_offers` not defined.
- [ ] **Step 3: Implement `advertised_offers()`**
- [ ] **Step 4: Run `composer test`.** Expected: PASS.
- [ ] **Step 5: Commit.** Stage the files above. Message: `feat(campaigns): list the conditional campaigns a shopper could reach`

### Task 2: Offer text and rows

**Files:**
- Create: `includes/Pro/Storefront/ConditionalOfferCopy.php`, `includes/Pro/Storefront/ConditionalOfferRows.php`
- Modify: `includes/Pro/YayLoyaltyPro.php`, `docs/architecture.md`

**Interfaces:**
- Consumes: `PointsEngine::advertised_offers()`, `ContextFactory::for_advert()`, `for_member_advert()`, `yayloyalty_launcher_earn_rows`, `visibility.show_bonus_offers`
- Produces: `ConditionalOfferCopy::label()`, `::basket_names()` (see README contract)

`label()` follows the spec's clause table, including the Review column. `ConditionalOfferRows::register()` follows `BirthdayEarnRow`, is called from `boot()`, and implements spec criteria 10 to 15, returning `$rows` untouched unless `DesignHelper::surface_enabled( $design, 'show_bonus_offers' )`: at most 3 rows, memo per viewer, a `try`/`catch` that logs `Log::warning( 'ConditionalOfferRows', … )`. `lifetime_spend` with `gt` reads "after spending more than $100", as a money amount has no next value. A threshold below 1 can't be described.

- [ ] **Step 1: Implement `ConditionalOfferCopy`**
- [ ] **Step 2: Implement `ConditionalOfferRows` and register it in `boot()`, after `BirthdayEarnRow`**
- [ ] **Step 3: Add the rows to the launcher's entry in `docs/architecture.md`**
- [ ] **Step 4: Verify on the local Pro site, with campaigns injected through `yayloyalty_campaign_candidates`, through `LauncherEarnRules::member_rows()`.** Each of spec criteria 10 to 13 and 15 gives the rows it states, for a guest and for members 444 and 620.
- [ ] **Step 5: Commit.** Stage the files above. Message: `feat(storefront): show bonus offers in Ways to earn`

### Task 3: The switch

**Files:**
- Modify: `includes/Helpers/DesignSchema.php`, `apps/admin/src/lib/schema/design.schema.ts`, `apps/admin/src/pages/settings/DesignVisibilitySection.tsx`, `apps/admin/src/pages/settings/design-settings.copy.ts`

**Interfaces:**
- Produces: `visibility.show_bonus_offers` (see README contract)

Key in defaults (`true`) and `schema()` (`bool`), and in the zod schema. In `DesignVisibilitySection.tsx`, after `show_thankyou_message`, `{IS_PRO_BUILD && <VisibilitySwitchField name="show_bonus_offers" label={VISIBILITY_BONUS_OFFERS_LABEL()} />}`, as that file already guards Pro fields; nothing needs `@/pro`. `VISIBILITY_BONUS_OFFERS_LABEL` is "Show bonus offers".

- [ ] **Step 1: Add the key to `DesignSchema` and `design.schema.ts`**
- [ ] **Step 2: Add the guarded switch and its label**
- [ ] **Step 3: Run `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`.** Expected: no errors.
- [ ] **Step 4: Verify spec criterion 14 on the local Pro site.** With the key off in memory, or the program off, `member_rows()` holds no offer rows.
- [ ] **Step 5: Commit.** Stage the files above. Message: `feat(design): add a switch for bonus offers`
