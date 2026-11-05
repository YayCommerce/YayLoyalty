# Tier Perks (Tiers slice 3) Design

**Status:** accepted 2026-11-04
**Related:** [Tiers master design](2026-11-02-tiers-master-design.md) (Delivery slice 3), [slice 2 design](2026-11-04-tier-lifetime-evaluation-design.md), [slice 1 plan](../plans/2026-11-03-tier-catalogue.md), `docs/backlog/2026-10-06-tiers-not-built.md`

This child spec refines the master for slice 3. Everything the master decides (D1–D22) still holds. Where this doc departs from the master's wording, the **Refinements of the master** section says so.

## Goal

A tiered customer earns their tier's multiplier on every campaign award, and the admin ledger shows it on the row. A Pro merchant can set a "Minimum tier" on a coupon reward. Customers below that tier don't see the reward on the redeem card, in the launcher or in My Account, and can't claim it.

## Context

- **Slices 1 and 2 (done, on `jason/feat/tiers`):**
  - the tiers table, `Models\Tier` (`get_multiplier()` returns a decimal string), `Repositories\TierRepository` (`find_live()`, `find_many()`, `live()`) and `TIER_QUERIES` (`GET /tiers`, VIEW cap);
  - `Pro\Services\TierEvaluator` writes `customers.tier_id`, and `GET/PUT /tiers/settings` toggles `tiers_enabled`;
  - `TiersRestController` blocks deleting a tier a reward references (`RewardRepository::find_referencing_tier()`, 409 with the rewards);
  - the ledger list routes send `tier_name`, set today only on `tier_bonus` rows (deleted tiers included).
- **Earning:** `PointsEngine::evaluate()` picks the campaign, then calls `compute_points()`, then `CapPolicy::apply()`, then returns `Result::awarded()`.
  - `EarningService::preview()` returns `evaluate()`.
  - `award()` writes the row from `draft_from()`, through `LedgerService::earn()`, with `yayloyalty_award_points` (Pro `DailyEarningLimit`) under the lock.
  - `Breakdown` and `Result` carry no multiplier.
  - `advertised()` feeds Ways to earn.
  - `ProductEarnMessage` and the pending points use `preview()`.
- **Ledger table** (`Database\Schema\LedgerTable`): it has no tier columns. `note` is staff free text, exposed only for manual adjustments.
- **Labels:**
  - Admin: `apps/admin/src/lib/helpers/ledger.helper.ts`, `describeEarn()`.
  - Storefront: `Engine\Storefront\ShopperActivityRow::earn_label()`.
- **Reward conditions:**
  - `RewardConditionType::TIER` is in `UNAVAILABLE`, and its schema allows `in`, `not_in`, `gte` and `lte` with value kind `tier`.
  - `RewardConditionSet::validate_value()` has no `tier` case, so the value goes unvalidated.
  - `RewardRestController::conditions_error()` answers `error_invalid_arguments`.
- **Reward gate:** `Engine\Redemption\RewardGate::check()` applies `yayloyalty_reward_conditions_match` (default true). Nothing subscribes.
- **Reward Context:** `Engine\Rewards\Context` carries `?CustomerFacts $facts` (the points-engine class). `Rewards\ContextFactory` always passes null (`TODO(redemption)`). `from_cart()` already reads the customer row.
- **Listings:**
  - **Redeem card:** `RedemptionService::reward_options()`, read by `Pro\Storefront\RewardsPanel::rows()`. A denied quote prints as a disabled row (`is_denied`).
  - **Launcher and My Account "Ways to redeem":** `LauncherPayload::redeem_offers( $now )` applies `yayloyalty_launcher_redeem_offers` (`REDEEM_OFFERS_FILTER`), which `RewardsPanel::redeem_offers()` fills with every live coupon reward, with no user.
    - `AccountMyPoints` uses `LauncherPayload::for_user()`.
    - The Design tab's `LauncherPreview` calls `for_balance()` with a sample balance.
- **Reward editor:** `apps/admin/src/pro/rewards/editor/`.
  - `RewardEditorSections.tsx` (388 lines) has two sections: Basics, and "Cost and value" or "The rate".
  - `reward-editor.form.ts`'s `RewardFormValues` has no conditions, and a save sends the stored list back untouched.

## Scope

**In:**
- The multiplier seam in `PointsEngine::evaluate()`, the `Engine\Points\Multiplier` value, and Pro's `TierMultiplier` (D8, D22).
- `ledger.tier_id` and `ledger.tier_multiplier`, written on multiplied EARN rows, and the admin ledger and drawer label suffix.
- `TIER` leaving `RewardConditionType::UNAVAILABLE`, narrowed to `gte`, with value and save validation.
- `Engine\Rewards\RewardFacts` and `Rewards\ContextFactory::facts_for()`, passed to every reward Context.
- `Pro\Services\RewardTierCondition` (TIER only, D16).
- Hiding gated rewards on the redeem card, in the launcher and in My Account. Forced claims fail.
- The reward editor's "Tier" section on `fixed_coupon` and `percent_coupon` rewards.

**Out:**
- **Any campaign TIER condition, campaign editor row, or campaign priority warning (D9).** `CampaignConditionType::TIER` stays in `UNAVAILABLE`, and `Pro\Services\ConditionsEvaluator` is unchanged.
- A minimum tier column or badge on `RewardTable`.
- Showing the multiplier to shoppers: the storefront history label, the launcher's Ways to earn and `advertised()` stay unmultiplied.
- Enforcing `user_role`, `customer_ids` or `lifetime_points` reward conditions, and any editor UI for them.
- A TIER condition on `flexible_discount` or on any catalog type other than `fixed_coupon` and `percent_coupon`.
- A per-tier or per-order cap on multiplied points.
- Multiplying the entry bonus (D11), or a `DailyEarningLimit` change.
- A Design-preview flag that would list gated rewards for the sample member.
- Rolling mode (slice 4) and the launcher badge and progress line (slice 5).
- A plugin version bump: it stays `1.0.0`, as in slices 1–2.

## Acceptance criteria

Setup unless stated: the wp-env Pro site, seed tiers Bronze 0 / Silver 800 / Gold 3000 with multipliers 1.00 / 1.25 / 1.50 and bonus 0, tiers on, Lifetime mode, and the default purchase campaign at 1 pt per currency unit with rounding `down` and no `max_points_per_order`.

1. **Multiplier after the campaign** (master AC 5). A Silver customer's 101-unit order completes.
   - The EARN row has `points` 126, `tier_id` = Silver's id and `tier_multiplier` 1.25.
   - `lifetime_earned` grows by 126.
   - The admin ledger and the drawer's Points tab label the row "Earned on order #{id} · Silver ×1.25".
2. **Campaign cap before the multiplier** (master AC 6). The campaign's `max_points_per_order` is 100. A Gold customer's 150-unit order earns 150, from a campaign figure of 100 × 1.50.
3. **Integer math.** With Silver's multiplier set to 1.15, a Silver customer's 100-unit order earns 115, not 114.
4. **Unchanged at 1.00** (master AC 7). A tierless customer's, a Bronze customer's, and (with tiers off) a Gold customer's 101-unit orders each earn 101.
   - Their rows have `tier_id` NULL and `tier_multiplier` NULL.
   - Their labels have no suffix.
5. **Preview multiplied, advert not.** For a signed-in Silver customer:
   - a cart worth 101 units stages 126 pending points;
   - the product-page line for a 101-unit product shows 126.
   - The launcher's Ways to earn still shows the campaign's 1 pt per unit.
6. **Daily limit after the multiplier.** With the daily limit at 100 points, a Silver customer's first 101-unit order of the day writes 100 points, with `tier_multiplier` 1.25.
7. **Bonus not multiplied** (D11). With Silver's bonus at 100, the bonus row written on reaching Silver has `points` 100 and `tier_multiplier` NULL.
8. **Reward hiding** (master AC 11). Reward R is a `fixed_coupon` with condition `{type: "tier", operator: "gte", value: <Silver id>}`.
   - A Silver or Gold customer sees R on the redeem card, under "Ways to redeem" in the launcher and in My Account → Rewards, and can claim it.
   - A Bronze or tierless customer sees it in none of those places.
   - A forced claim of R by a Bronze customer (the redeem AJAX with R's id) is refused with `REASON_CONDITIONS_NOT_MET`, and the card shows "Your account cannot spend {points label} on this order."
9. **Tiers off hides gated rewards** (master AC 2). With `tiers_enabled` false, a Gold customer doesn't see R anywhere, and a forced claim is refused as in AC 8.
10. **Guests.** A guest's launcher doesn't list R.
11. **Applied reward after a drop.** A Silver customer ticks R on the cart, then a −200 ADJUST drops them to Bronze.
    - Reloading the cart shows R's row checked and disabled (`is_denied`).
    - Placing the order fails with the `REASON_REWARD_UNAVAILABLE` message.
    - Unticking R removes the row.
12. **Other conditions untouched** (master AC 12). Reward S has a REST-set `user_role` condition the customer doesn't meet, and no TIER entry. S is listed and claimable, as today.
13. **Save validation.** Each of these `PUT /rewards/{id}` requests returns 400 and leaves the row unchanged:
    - a TIER entry on the `flexible_discount` reward → "Only coupon rewards can require a tier.";
    - a TIER value that names no live tier → "That tier no longer exists.";
    - a TIER entry with operator `in` → "The reward conditions are malformed.";
    - a TIER value of `"abc"` → "The reward conditions are malformed.".

    A TIER entry `gte` with Silver's id on a `percent_coupon` reward returns 200.
14. **Editor writes only the tier entry.** S has a REST-set `user_role` condition.
    - In the editor, setting Minimum tier to Silver and saving stores both the `user_role` entry and `{type: "tier", operator: "gte", value: <Silver id>}`.
    - Setting it back to "Any tier" and saving stores only the `user_role` entry.
    - Reopening the editor shows the saved choice.
15. **Editor visibility.**
    - The "Tier" section shows on `fixed_coupon` and `percent_coupon` rewards and not on the points discount (`flexible_discount`).
    - With tiers off, the section shows the notice "Tiers are off. Rewards that require a tier are hidden from every customer until you turn tiers on."
16. **Blocked delete still holds** (master AC 13). Deleting Silver while R references it returns 409 listing R.
17. **Lite** (master AC 21). With the Lite build active over the same database:
    - `Installer::install()` leaves the new `ledger` columns and every stored TIER condition in place;
    - every campaign awards what it would for a tierless customer, with `tier_multiplier` NULL;
    - `scripts/validate-build.php` passes on the Lite zip.
18. **End to end** (master AC 22, steps 2 and 4). On the wp-env Pro site, with the setup above, Silver's bonus at 100 and reward R:
    1. A new customer's 800-unit completed order puts them in Silver with 900 points.
    2. Their next 100-unit order earns 125, labelled "· Silver ×1.25" in the drawer.
    3. They see R in the launcher and on the redeem card, and claim it at checkout.
    4. A Bronze customer on the same site sees no R anywhere.

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static` types, or `throw` as an expression.
- Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call. `TierMultiplier` and `RewardTierCondition` attach only through filters added in `YayLoyaltyPro::boot()`.
- SPA tier code lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD`.
- Only `LedgerService` writes ledger rows. The two new columns travel on the `LedgerEntry` draft that `EarningService::draft_from()` builds; no other writer is added.
- Lite drops Pro behaviour, never Pro data: Lite's `Installer::schemas()` installs the ledger columns, and stored TIER conditions keep validating in Lite.
- Multipliers are stored as `decimal(4,2)`, travel as decimal strings, and are multiplied in integers (hundredths), never floats.
- `yayloyalty_points_multiplier`, the third argument of `yayloyalty_launcher_redeem_offers`, and `RewardFacts` are internal seams, documented as "not a public API". `yayloyalty_reward_conditions_match` keeps its signature.
- The plugin version stays `1.0.0`. A dev site gets the ledger columns by re-running `Installer::install()` (reset `yayloyalty_db_version`).
- Tests follow CLAUDE.md. The plan proposes them with AskUserQuestion. Candidates, since this is points math: the `Multiplier` integer math (AC 1 and AC 3), and `RewardTierCondition` rank comparison (AC 8 and AC 9).
- All work lands on `jason/feat/tiers`. Commit only when the user asks.

## Approach

**Chosen:** a typed multiplier seam after `CapPolicy`, stored on the EARN row in two ledger columns, and a TIER-only reward evaluator over a new `RewardFacts` that the claim Context carries.

**Architecture:**
- **Earning** stays campaign-first. Once `CapPolicy` has bounded the campaign's points, the engine asks `yayloyalty_points_multiplier` for a `Multiplier` and floors the product. It records both figures on `Breakdown` and the `Multiplier` on `Result`. `EarningService` copies the multiplier onto the EARN draft.
- **Rewards:** `Rewards\ContextFactory` reads the customer's tier into `RewardFacts` once per Context. `RewardTierCondition` answers the existing gate filter and compares ranks. Its `allows()` serves the two listing paths that have no quote: the launcher and My Account.

**Components:**
- `includes/Engine/Points/Multiplier.php` [new, Lite]: immutable `{ string factor, ?int tier_id }`.
  - `none()` is `'1.00'`, null.
  - `is_neutral()`.
  - `apply( int $points ): int` = `intdiv( $points * hundredths, 100 )`.
  - `from_filter( $value ): self` accepts only a `Multiplier` whose factor is a decimal string from `1.00` to `10.00`. Anything else gives `none()` plus one `Log::warning( 'PointsEngine', … )`.
- `includes/Engine/Points/PointsEngine.php` [edit, Lite]:
  - `MULTIPLIER_FILTER = 'yayloyalty_points_multiplier'`.
  - In `evaluate()`, after `CapPolicy::apply()`: `$multiplier = Multiplier::from_filter( apply_filters( MULTIPLIER_FILTER, Multiplier::none(), $campaign, $ctx ) )`, then `$points = $multiplier->apply( $capped )`, then the existing `<= 0` check.
  - The filter docblock states that it is not called by `advertised()`.
- `includes/Engine/Points/Breakdown.php` [edit, Lite]: `with_multiplier( int $campaign_points, Multiplier $m ): self`, getters, and `campaign_points` and `multiplier` (factor string) in `to_array()`.
  - When neutral, `campaign_points` equals `points` and `multiplier` is `'1.00'`.
- `includes/Engine/Points/Result.php` [edit, Lite]: `awarded()` takes an optional `Multiplier`, and `get_multiplier(): Multiplier` returns `none()` when unset.
- `includes/Engine/Earning/EarningService.php` [edit, Lite]: `draft_from()` sets the entry's `tier_id` and `tier_multiplier` from `Result::get_multiplier()` only when it isn't neutral.
- `includes/Database/Schema/LedgerTable.php` [edit, Lite]: `tier_id bigint unsigned DEFAULT NULL` and `tier_multiplier decimal(4,2) DEFAULT NULL`, after `campaign_id`. No key.
- `includes/Models/LedgerEntry.php` [edit, Lite]: maps the two columns (constructor tail, `from_row()`, `to_row()`, format, getters, `to_array()` with `tier_multiplier` as a string or null).
- `includes/Controllers/LedgerRestController.php` and the other ledger list routes that set `tier_name` [edit, Lite]: `tier_name` is also filled for rows with a `tier_id`. Its description and schema add `tier_multiplier` (`string|null`).
- `includes/Pro/Services/TierMultiplier.php` [new]: subscribes to `yayloyalty_points_multiplier` and returns `none()` when any of these hold:
  - `tiers_enabled` is false;
  - the Context has no user id;
  - the customer row is missing or `tier_id` is NULL;
  - `TierRepository::find_live( tier_id )` is null.

  Otherwise it returns `new Multiplier( $tier->get_multiplier(), $tier->get_id() )`. It reads the stored tier now, not as of the event, and memoizes per request per user id.
- `includes/Enums/RewardConditionType.php` [edit, Lite]: `UNAVAILABLE` becomes `[]`, and `TIER`'s operators become `[ self::OP_GTE ]`.
- `includes/Models/RewardConditionSet.php` [edit, Lite]: `validate_value()` gains `case 'tier'`, a positive int (`ConditionValueRules::is_int_like()` and > 0).
- `includes/Controllers/RewardRestController.php` [edit, Lite]: after the structural check, a TIER entry fails with `error_invalid_arguments` in two cases:
  - the reward's type isn't `fixed_coupon` or `percent_coupon` → "Only coupon rewards can require a tier.";
  - `TierRepository::find_live( value )` is null → "That tier no longer exists.".
- `includes/Engine/Rewards/RewardFacts.php` [new, Lite]: immutable `{ int lifetime_earned, ?int tier_id, int tier_rank }`. `tier_rank` is the live tier's rank, or 0.
- `includes/Engine/Rewards/Context.php` [edit, Lite]: `$facts` becomes `?RewardFacts`, and `get_facts(): ?RewardFacts`.
- `includes/Engine/Rewards/ContextFactory.php` [edit, Lite]:
  - `facts_for( ?int $user_id ): ?RewardFacts` reads the customer row and `find_live( tier_id )`. It returns null for a guest, and `{0, null, 0}` for a user with no row.
  - `from_cart()`, `from_order()` and `from_order_line()` pass it, which removes the `TODO(redemption)`.
  - `RewardGate`'s docblock is updated to name `RewardFacts`.
- `includes/Pro/Services/RewardTierCondition.php` [new]: `register()` subscribes to `yayloyalty_reward_conditions_match` (priority 10, 3 args).
  - `allows( Reward $reward, ?RewardFacts $facts ): bool` returns true with no TIER entry. With one, it returns false when any of these hold:
    - tiers are off;
    - `$facts` is null;
    - `$facts->tier_rank` is 0;
    - the required tier isn't live;
    - `$facts->tier_rank` is below the required tier's rank.
  - The filter callback returns `$matches && $this->allows( $reward, $ctx->get_facts() )`.
  - Tier rows are memoized per request.
- `includes/Engine/Redemption/RedemptionService.php` [edit, Lite]: `reward_options()` skips a reward whose quote reason is `REASON_CONDITIONS_NOT_MET`, unless its coupon code is in `$cart->get_applied_coupons()`.
- `includes/Engine/Storefront/LauncherPayload.php` [edit, Lite]:
  - `for_balance( array $design, ?int $balance, ?int $left_today = null, ?int $user_id = null )`, which `build()` passes.
  - `redeem_offers( $now, $user_id )` applies `REDEEM_OFFERS_FILTER` with `( [], $now, $user_id )`.
- `includes/Pro/Storefront/RewardsPanel.php` [edit]:
  - `redeem_offers( array $offers, string $now, ?int $user_id = null )`, registered with 3 args, skips rewards that `RewardTierCondition::allows()` refuses for `ContextFactory::facts_for( $user_id )`, read once per call.
  - `rows()` is unchanged.
- `includes/Pro/YayLoyaltyPro.php` [edit]: registers `TierMultiplier` and `RewardTierCondition`.
- SPA:
  - `apps/admin/src/lib/schema/ledger.schema.ts` (or the file declaring `LedgerEntry`) [edit]: `tier_multiplier: string | null`, defaulting to null like `tier_name`.
  - `apps/admin/src/lib/helpers/ledger.helper.ts` [edit]: `describeEarn()` appends `" · {tier_name} ×{tier_multiplier}"` when `tier_multiplier` is set, or `" · ×{tier_multiplier}"` when `tier_name` is null. Built with `sprintf` and a translators comment.
  - `apps/admin/src/lib/schema/reward.schema.ts` [edit]: the condition type accepts `tier`.
  - `apps/admin/src/pro/rewards/editor/reward-editor.form.ts` [edit]: `min_tier_id: number | null`, seeded from the stored `tier`/`gte` entry. The save input rebuilds `conditions` (stored list minus `tier`, plus the new entry when set).
  - `apps/admin/src/pro/rewards/editor/RewardEditorSections.tsx` [edit]: step 3 `EditorSection` "Tier", for `fixed_coupon` and `percent_coupon` only. If the file passes ~400 lines, the plan asks the user before splitting the section out (single-parent rule).

**Data and storage:**
- `{prefix}yayloyalty_ledger` gains `tier_id bigint unsigned DEFAULT NULL` and `tier_multiplier decimal(4,2) DEFAULT NULL`. Both are NULL on every row except an EARN row whose multiplier was above 1.00. There is no backfill, so existing rows stay NULL.
- Applied by `Installer::install()` through dbDelta. Version unchanged.
- No option or settings key changes.
- Reward conditions keep their JSON shape. A TIER entry is `{"type":"tier","operator":"gte","value":<tier id>}`, at most one per reward (the existing duplicate-type rule).
- Uninstall: none, as for every table today.

**Integration points:**
- **New filter `yayloyalty_points_multiplier`** `( Multiplier $multiplier, Campaign $campaign, Context $ctx ): Multiplier`, an internal Pro seam, applied in `PointsEngine::evaluate()` only.
- **`yayloyalty_reward_conditions_match`:** its first subscriber, `RewardTierCondition`.
- **`yayloyalty_launcher_redeem_offers`:** gains a third argument, `?int $user_id` (null for a guest and for the Design preview).
- **`yayloyalty_award_points`** (`DailyEarningLimit`): unchanged. It now receives multiplied points.
- **REST:**
  - `PUT/POST /rewards` gain the two TIER validation errors.
  - The ledger list routes add `tier_multiplier` and widen `tier_name`.
  - No new routes.
- **SPA queries reused:** `TIER_QUERIES` (`GET /tiers`) and slice 2's tier settings query (`GET /tiers/settings`) for the editor notice.

**UI:**
- **Reward editor** (Pro): a third section, "Tier", after "Cost and value", on `fixed_coupon` and `percent_coupon` only. It holds one "Minimum tier" select:
  - "Any tier" (no condition) first, then the live tiers in threshold order, each with its colour dot;
  - help text: "Only customers in this tier or a higher one see and can claim this reward.";
  - with tiers off: an outline `Alert` with `InfoIcon` above the select, reading "Tiers are off. Rewards that require a tier are hidden from every customer until you turn tiers on."; the select stays editable;
  - tier list failure: the select is disabled, and an inline error offers a retry;
  - a stored tier id not in the live list: the select shows "Any tier", with the warning "The tier this reward required was removed."; saving clears the entry.
- **Admin ledger and drawer Points tab:** the label suffix " · Silver ×1.25".
- **Storefront:** no new UI. Gated rewards disappear from the redeem card, the launcher and My Account for customers below the tier; an applied, now-ineligible reward stays as a disabled row.

**Edge cases and failure modes:**
- **Tier at award time:** `TierMultiplier` reads the stored tier when `award()` runs. The order that lifts a customer into Silver earns at the old tier's multiplier (master). Pending points staged at checkout may differ from the award; the award is authoritative.
- **Deleted current tier:** the stored `tier_id` names a deleted tier until the delete sweep moves the customer. In the meantime the multiplier is 1.00, and `tier_rank` is 0, so gated rewards are hidden.
- **Malformed filter answer:** a non-`Multiplier`, or a factor outside 1.00–10.00, falls back to 1.00 with one warning log. An award is never blocked by the seam.
- **A multiplier can't zero an award,** since the factor is ≥ 1.00. `REASON_ZERO_POINTS` keeps its meaning.
- **REFUND_EARN** reverses the written row's points, multiplied included, as today. Refund rows carry no tier columns.
- **Rerank:** a threshold edit rewrites ranks. Both `RewardFacts` and the required tier read the current rank, so the comparison stays right.
- **Race between the D15 delete check and a reward save:** the save validates `find_live()`. A tier deleted after the save makes the condition fail closed (hidden), and the editor shows the removed-tier warning.
- **Lite downgrade:** with no multiplier subscriber the factor is 1.00, and the gate filter stays true, but Lite offers no coupon rewards, so nothing gated is reachable. Stored TIER entries keep validating.
- **Design tab preview:** no user, so gated rewards aren't listed in the preview's "Ways to redeem" (accepted).
- **Performance:** `facts_for()` is two primary-key reads per Context, and `reward_options()` builds one Context per card render. `TierMultiplier` memoizes per user per request. Product-page previews add at most one customer and one tier read per request.

**Decision record:** None. D20's record (slice 2) stands, and this slice adds no architectural choice beyond the master's.

## Refinements of the master

| Master wording | This spec | Why |
|---|---|---|
| Filter `yayloyalty_points_multiplier` with `(string '1.00', Campaign, Context)` | The filter carries `Engine\Points\Multiplier { factor, tier_id }` | The ledger label needs the tier id, which Lite can't know. |
| "`Breakdown` records … for the ledger note and the campaign preview" | Two ledger columns, `tier_id` and `tier_multiplier`, and a label suffix. The campaign preview is unchanged. | `note` is staff free text, and the campaign preview computes no points. |
| `ContextFactory` passes `CustomerFacts` carrying `tier_id` and `tier_rank` | `Engine\Rewards\RewardFacts { lifetime_earned, tier_id, tier_rank }` replaces `CustomerFacts` on the reward Context | An honest `CustomerFacts` needs `CustomerFactsReader`, which loads every paid order on each cart quote. |
| "`RewardsPanel` and the `reward_options()` callers drop rewards" | `reward_options()` keeps an applied, now-ineligible reward as a disabled row | The shopper needs a row to untick. Checkout refuses it anyway. |

## Alternatives considered

| Option | Why rejected |
|---|---|
| Campaign TIER condition in `ConditionsEvaluator`, a campaign editor row and a priority warning | The master's D9 rules it out (couples campaigns to tiers, depends on priority, fails open in Lite). The user confirmed following the master. |
| Multiplier written as text into `ledger.note` | Mixes machine data with staff notes, `note` is exposed only for adjustments, and the tier name would freeze. |
| Multiplier kept only in the in-memory `Breakdown` | Support can't explain why a 101-unit order earned 126. |
| Points `CustomerFacts` plus `tier_rank` on the reward Context | Costly (`CustomerFactsReader` on every cart quote), or dishonest (faked order count and spend). |
| `RewardTierCondition` reading the customer row itself | Breaks the "evaluators compare, never query" pattern the campaign evaluator follows. |
| Float multiplication | `100 × 1.15` floors to 114. |
| Hiding an applied, now-ineligible reward too | Leaves a coupon on the cart with no row to untick. |
| A minimum-tier badge on `RewardTable` | Not wanted for this slice. |
| A Design-preview flag to list gated rewards | Adds a seam for a preview-only nicety. Guest behaviour is acceptable there. |

## Rollout

- **Dev sites on `jason/feat/tiers`:** reset `yayloyalty_db_version` (or deactivate and reactivate) so `Installer::install()` adds the ledger columns.
- **No existing reward has a TIER entry,** so storefront behaviour changes only after a merchant sets one.
- **Earning changes only for customers in a tier whose multiplier is above 1.00, while tiers are on.** Tiers are off after upgrade (slice 1), so existing stores see no change until they turn tiers on.
- **Release note** (with the tiers release): the release that ships tiers must bump the plugin version so existing sites get the schema, as slices 1–2 already note.

## Open questions

None.
