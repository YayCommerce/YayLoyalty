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

Walks candidates until the first `true`, which isn't kept. Each campaign with a `null` or `false` verdict and conditions is compared with the lowest value among conditional campaigns already passed: kept when its value is above 0 and above that lowest value. It takes an advert or estimate Context, and throws `\InvalidArgumentException` on any other, as `advertised()` does.

Test cases: a conditional campaign above B is kept; one below B isn't; one under a conditional paying less isn't; a campaign paying 0 isn't; a `true` campaign stops the walk and isn't kept.

Steps: expand after Phase 2 lands.

### Task 2: Offer text and rows

**Files:**
- Create: `includes/Pro/Storefront/ConditionalOfferCopy.php`, `includes/Pro/Storefront/ConditionalOfferRows.php`
- Modify: `includes/Pro/YayLoyaltyPro.php`, `docs/architecture.md`

**Interfaces:**
- Consumes: `PointsEngine::advertised_offers()`, `ContextFactory::for_advert()`, `for_member_advert()`, `yayloyalty_launcher_earn_rows`, `visibility.show_bonus_offers`
- Produces: `ConditionalOfferCopy::label()`, `::basket_names()` (see README contract)

`label()` follows the spec's clause table, including the Review column. `ConditionalOfferRows::register()` follows `BirthdayEarnRow`, is called from `boot()`, and implements spec criteria 10 to 15, returning `$rows` untouched unless `DesignHelper::surface_enabled( $design, 'show_bonus_offers' )`: at most 3 rows, memo per viewer, a `try`/`catch` that logs `Log::warning( 'ConditionalOfferRows', … )`.

Steps: expand after Phase 2 lands.

### Task 3: The switch

**Files:**
- Modify: `includes/Helpers/DesignSchema.php`, `apps/admin/src/lib/schema/design.schema.ts`, `apps/admin/src/pages/settings/DesignVisibilitySection.tsx`, `apps/admin/src/pages/settings/design-settings.copy.ts`

**Interfaces:**
- Produces: `visibility.show_bonus_offers` (see README contract)

Key in defaults (`true`) and `schema()` (`bool`), and in the zod schema. In `DesignVisibilitySection.tsx`, after `show_thankyou_message`, `{IS_PRO_BUILD && <VisibilitySwitchField name="show_bonus_offers" label={VISIBILITY_BONUS_OFFERS_LABEL()} />}`, as that file already guards Pro fields; nothing needs `@/pro`. `VISIBILITY_BONUS_OFFERS_LABEL` is "Show bonus offers".

Steps: expand after Phase 2 lands.
