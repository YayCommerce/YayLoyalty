---
id: P-1
title: Advanced rules: AND/OR condition groups
status: done
from: BS-1
roadmap: R-1
design: UX-1
trello:
type: feat
branch: feat/p-1-advanced-rules-and-or-condition-groups
pr:
editions: [pro, lite]
created: 2026-11-13
reviewed: pending
---
# P-1 Advanced rules: AND/OR condition groups

## Goal
A merchant builds a campaign's or a reward's conditions as groups joined by AND/OR. For example: country is US AND (lifetime spend >= $500 OR order count >= 5). The campaign then awards points, or the reward can be redeemed, only for customers who match. Done means:
1. That example, saved in the campaign editor, awards points on a completed order only to a customer it matches, and it reloads in the editor exactly as saved.
2. A reward limited by grouped conditions shows in the cart and checkout redeem card only for customers who match, and can only be committed by them.
3. Every campaign and reward saved in 1.0.0, a flat list, behaves the same after the upgrade, read as one AND group, and stays stored byte-for-byte as it was until someone saves it with real groups.
4. Lite reads and stores both shapes, keeps failing open, and its zip still passes `scripts/validate-build.php`.

## Non-goals
- Nesting deeper than two levels (groups of conditions). BS-1 option B was not taken.
- New condition types. `tier` stays in `UNAVAILABLE` until R-2.
- Conditions on the built-in "Points discount" (flexible) reward. The program's excluded roles already gate it.
- Filtering the launcher's "Ways to redeem" list by the shopper's conditions: BL-1 (deferred).
- Mounting the unmounted `ConditionsPreview` component in the editor. T3 only keeps it in step with the response.
- A data migration. Flat rows are read as they are (see Approach).

## Findings
1. **The roadmap's `why` is stale for campaigns.** Lifetime spend, order count and lifetime points are already offered and evaluated: only `TIER` is in `UNAVAILABLE` (`includes/Enums/CampaignConditionType.php:61-63`), the SPA lists all three (`apps/admin/src/lib/schema/campaign.schema.ts:142-153`), and the evaluator handles them (`includes/Pro/Services/ConditionsEvaluator.php:122-129`). The plan adds no campaign condition types. The work is grouping.
2. **Reward conditions are neither enforced nor editable.**
   - `RewardGate` applies `yayloyalty_reward_conditions_match` (`includes/Engine/Redemption/RewardGate.php:39`), and nothing in `includes/` hooks it.
   - The cart Context passes `null` facts (`includes/Engine/Rewards/ContextFactory.php:47`, a TODO for this gate).
   - The reward editor has no conditions field and re-sends the stored list (`apps/admin/src/pro/rewards/editor/reward-editor.form.ts:26-28`).
   - T4 builds the enforcement and T7 the editor section. That's the only open scope question (Risks).
3. **A 1.0.0 reader turns an object into "no conditions".** `Json::decode_list()` returns the fallback `[]` for an object without `items` (`includes/Utils/Json.php:75-91`). T1 therefore writes every tree that is one `all` group in the old `{"schema_version":1,"items":[…]}` envelope, and only a real grouping in the new `schema_version: 2` object. The downgrade risk is in Risks.
4. **"One entry per type" is checked per list** (`includes/Models/CampaignConditionSet.php:206-216`, `includes/Models/RewardConditionSet.php:164-167`). T1 scopes it to a group, so the same type can appear in two ORed groups.
5. **Two "no conditions" checks compare arrays.** `PointsEngine::applies_to_everyone()` uses `[] === $campaign->get_conditions()` (`includes/Engine/Points/PointsEngine.php:174-176`), and the preview uses `empty( $conditions )` (`includes/Pro/Controllers/CampaignPreviewRestController.php:66-76`). A tree array is never `[]`, so T2 and T3 switch them to `has_conditions()`.
6. **REST already accepts an object or an array** for `conditions` on both routes (`includes/Controllers/CampaignRestController.php:424-427`, `includes/Controllers/RewardRestController.php:514-517`). Only the read shape and the description change. There's no route or schema-type change.
7. **Every SPA reader assumes a list**: `conditionsLabel()` (`apps/admin/src/pro/campaigns/campaign-labels.ts:62-72`, used by `CampaignTable.tsx:888`, `DashboardTopCampaigns.tsx:60` and `CampaignPerformance.tsx:75`), `normalizeConditions()` (`campaign-editor.form.ts:90-92`) and `normalizeRewardConditions()` (`apps/admin/src/lib/schema/reward.schema.ts:104-114`). T5 replaces them with one reader that accepts both shapes. Because it does, a dashboard payload still cached in the flat shape (`DashboardCache`, 5 min, `DashboardProRestController.php:100`) renders fine, and the cache key doesn't need a bump.
8. **An older integration test asserts the flat read shape**: `tests/integration/Controllers/RewardRouteTest.php:91` (`$data['conditions'][0]['type']`) and `:375` (`[] === get_conditions()`). T2 updates both.
9. **Customer facts are expensive at cart time.** `CustomerFactsReader::read()` loads every paid order (`includes/Engine/Points/CustomerFactsReader.php:20-29`), and reward quotes run on each cart totals pass (`includes/Engine/Redemption/RedemptionService.php:193`, `:343`, `:389`). T4 gives the rewards Context the same lazy loader the earning Context uses (`includes/Engine/Points/Context.php:188-195`), so facts load only when a `lifetime_points` condition is evaluated.
10. **The redeem card shows a denied reward greyed out** (`includes/Pro/Storefront/RewardsPanel.php:96-112`, `is_denied`). With conditions enforced, that would show "wholesale only" rewards to every shopper, so T4 drops rows denied for `conditions_not_met`.
11. **The launcher's "Ways to redeem" has no customer to check** (`includes/Pro/Storefront/RewardsPanel.php:30-45`) → BL-1, deferred.
12. **`ConditionsPreview` isn't mounted** (`apps/admin/src/pro/campaigns/ConditionsPreview.tsx:12-16`). T3 only updates its response mirror and rendering.
- differs: `docs/knowledge/architecture.md` "Pro and Lite" mentions fail-open only for campaigns, and describes `conditions` as a list. `docs/knowledge/helpers.md`'s seam list lacks `yayloyalty_reward_conditions_match`. → T8 updates both, plus `decisions.md`, `testing.md` and `e2e.md`.

## Approach
- **Shape.** `conditions` is `{ match: "all"|"any", groups: [ { match: "all"|"any", conditions: [ {type, operator, value} ] } ] }`. Both `match` values default to `all`, there are at most 10 groups, and each group holds at most one entry per type. Empty groups are dropped by normalize. A flat list (1.0.0, or any API caller) is read as `{ match: all, groups: [ { match: all, conditions: <list> } ] }`.
- **Lite helper `Utils\ConditionGroups`** (T1) owns the structure:
  - `parse()`: either shape to the canonical tree.
  - `decode()` / `encode()`: the column, with v1 for one `all` group and v2 otherwise.
  - `assert_structure()`: match values, group cap.
  - `fold()`: per-condition results into group and root verdicts.

  `CampaignConditionSet` and `RewardConditionSet` keep their own vocabularies and value checks, and run them per group. This keeps the deliberate split between them (`CampaignConditionSet.php:14`, `RewardCondition.php:9`).
- **Models and REST** (T2). `Campaign` and `Reward` hold the tree. `from_row()` uses `ConditionGroups::decode()`, `to_row()` uses `encode()`, and a new `has_conditions()` exists. REST returns the tree on read and accepts either shape on write, through the existing `conditions_are_valid()` / `set_conditions()` path. There's no migration (hard rule 5 satisfied trivially), and rows change only when saved.
- **Pro evaluation.**
  - Campaigns: `ConditionsEvaluator::explain()` returns `{ matched, match, groups: [ { match, matched, conditions: [ <today's per-condition result> ] } ] }`, and `evaluate()` returns its `matched`, so the preview still can't disagree with the award (T3). It's still attached through `PointsEngine::CONDITIONS_EVALUATOR_FILTER`.
  - Rewards: a new `RewardConditionsEvaluator` hooks the existing `yayloyalty_reward_conditions_match` in `YayLoyaltyPro::boot()` (T4). The filter fires per check, not in `RedemptionService::build()`, so hard rule 4 holds anyway.
  - Lite has no subscriber on either seam and fails open for both.
- **Rewards Context** (T4) carries a facts loader, resolved once, like the earning Context. `from_cart`, `from_order` and `from_order_line` pass it, so the commit re-check sees the same facts as the cart.
- **Storefront** (T4). The redeem card (classic and block share `RewardsPanel::rows()`) hides a reward the shopper's conditions exclude. A reward already in the session that stops qualifying is refused at commit with the existing "Your account cannot spend %s on this order." (`RedemptionService.php:521`).
- **SPA** (T5–T7), following UX-1. One zod mirror and reader for the tree is in `lib/schema/condition-groups.schema.ts`. One `ConditionGroupsEditor` is in `src/pro/conditions/` (hard rule 2: Pro-only, reached from the Pro editors that already sit behind `src/pro/index.ts`). The campaign editor's step 3 uses it, and the reward editor gets step 3 "Who can redeem" for catalog types. Labels render "Country AND (Lifetime spend OR Order count)", and the rewards Availability says "Selected customers".
- **Editions.** Pro: both evaluators and both editors. Lite: `ConditionGroups`, models, REST and the lazy facts loader. Lite names no Pro class (hard rule 1).
- **Compatibility.** Flat rows are untouched on disk. HPOS and block checkout are unaffected: the order data and the Store API path are unchanged, and the reward gate runs in the shared quote path. Uninstall is unaffected (same columns).

## Tasks
- [x] T1 Shared condition-group structure, and both condition sets validate and normalize per group: `includes/Utils/ConditionGroups.php` (new), `includes/Models/CampaignConditionSet.php`, `includes/Models/RewardConditionSet.php`, `tests/php/unit/Models/CampaignConditionSetTest.php` (new) [risk: 3]
  - Reuse: `Json::encode()`, `encode_list()`, `decode()`, `decode_list()` in `includes/Utils/Json.php`; `ConditionValueRules::bad()` in `includes/Utils/ConditionValueRules.php`; each set's existing `normalize_value()` / `validate_value()` per entry
  - Callers:
    - `ConditionGroups::parse()` → both sets' `from_array()` / `normalize()` (T1), and the `Campaign` and `Reward` constructors (T2).
    - `ConditionGroups::decode()` → `Campaign::from_row()`, `Reward::from_row()` (T2).
    - `ConditionGroups::encode()` → `Campaign::to_row()`, `Reward::to_row()`, and both sets' `to_json()` (T1, T2).
    - `ConditionGroups::assert_structure()` → both sets' `validate()` (T1).
    - `ConditionGroups::fold()` → `ConditionsEvaluator::explain()` (T3), `RewardConditionsEvaluator::matches()` (T4).
    - `CampaignConditionSet::groups()` → `ConditionsEvaluator::explain()` (T3).
    - `RewardConditionSet::groups()` → `RewardConditionsEvaluator::matches()` (T4).
- [x] T2 Campaign and Reward models, REST and the earning engine read and write the tree, and the older test's flat assertions move to the tree: `includes/Models/Campaign.php`, `includes/Models/Reward.php`, `includes/Engine/Points/PointsEngine.php`, `includes/Controllers/CampaignRestController.php`, `includes/Controllers/RewardRestController.php`, `tests/integration/Controllers/RewardRouteTest.php` [risk: 3]
  - Reuse: `ConditionGroups` (T1); `CampaignConditionSet::normalize()`, `is_valid()` and `RewardConditionSet::normalize()`, `is_valid()` through the existing `conditions_are_valid()` / `set_conditions()`
  - Callers: `Campaign::has_conditions()` → `PointsEngine::applies_to_everyone()` (T2), `CampaignPreviewRestController::preview()` (T3); `Reward::has_conditions()` → `RewardConditionsEvaluator::matches()` (T4).
- [x] T3 Pro campaign evaluator folds groups, and the preview reports per group: `includes/Pro/Services/ConditionsEvaluator.php`, `includes/Pro/Controllers/CampaignPreviewRestController.php`, `apps/admin/src/pro/campaigns/conditions-preview.api.ts`, `apps/admin/src/pro/campaigns/ConditionsPreview.tsx`, `tests/php/unit/Pro/Services/ConditionsEvaluatorTest.php` (new) [risk: 3]
  - Reuse: `ConditionGroups::fold()` (T1); the existing `evaluate_one()` per condition; `Campaign::has_conditions()` (T2)
- [x] T4 Reward conditions enforced in Pro: a lazy facts loader on the rewards Context, a reward conditions evaluator on `yayloyalty_reward_conditions_match`, and redeem-card rows hidden when the conditions exclude the shopper: `includes/Engine/Rewards/Context.php`, `includes/Engine/Rewards/ContextFactory.php`, `includes/Engine/Points/ContextFactory.php`, `includes/Pro/Services/RewardConditionsEvaluator.php` (new), `includes/Pro/YayLoyaltyPro.php`, `includes/Pro/Storefront/RewardsPanel.php`, `tests/php/unit/Pro/Services/RewardConditionsEvaluatorTest.php` (new) [risk: 3]
  - Reuse: `Points\ContextFactory::customer_facts()` and `user_roles()` (made public) in `includes/Engine/Points/ContextFactory.php`; the closure-resolve pattern of `Points\Context::get_customer_facts()` (`includes/Engine/Points/Context.php:188-195`); `ConditionGroups::fold()` (T1); `RewardConditionType` operators; `Result::REASON_CONDITIONS_NOT_MET` in `includes/Engine/Rewards/Result.php`
  - Callers: `RewardConditionsEvaluator::matches()` → filter `yayloyalty_reward_conditions_match` (applied in `RewardGate::check()`), registered in `YayLoyaltyPro::boot()`; `Points\ContextFactory::user_roles()` (now public) → `RewardConditionsEvaluator::matches()`.
- [x] T5 SPA wire shape, the one reader for both shapes, and the grouped labels: `apps/admin/src/lib/schema/condition-groups.schema.ts` (new), `apps/admin/src/lib/schema/campaign.schema.ts`, `apps/admin/src/lib/schema/reward.schema.ts`, `apps/admin/src/pro/campaigns/campaign-labels.ts`, `apps/admin/src/pro/rewards/reward-labels.ts`, `apps/admin/src/pro/campaigns/add/campaign-templates.ts`, `apps/admin/src/pro/campaigns/editor/campaign-editor.form.ts`, `apps/admin/src/pro/rewards/editor/reward-editor.form.ts`, `apps/admin/src/pro/rewards/editor/RewardEditor.tsx`, `apps/admin/src/pro/campaigns/ConditionsPreview.tsx`, `tests/js/lib/schema/condition-groups.schema.test.ts` (new). UX-1 "Copy" (labels). The `campaign-editor.form.ts` reader change lands with T6, which rewrites the same file's error merge.
  - Reuse: `conditionTypeLabel()` in `campaign-labels.ts`; `campaignConditionSchema` and `rewardConditionSchema` as the leaf schemas; `structuredClone` as in `campaignToFormValues()`
  - Callers:
    - `conditionTreeSchema()` → `campaignUpdateInputSchema`, `rewardFieldsSchema` (T5).
    - `toConditionTree()` → `campaignToFormValues()`, `conditionsLabel()`, the reward form's stored-conditions passthrough (T5), `RewardEditor` duplicate (T7).
    - `availabilityLabel()`'s conditions branch → `RewardTable.tsx:307`.
- [x] T6 Group editor in the campaign editor; the condition rows and the error merge move to `src/pro/conditions/` for both editors; the browser spec: `apps/admin/src/pro/conditions/ConditionGroupsEditor.tsx` (new), `apps/admin/src/pro/conditions/ConditionRow.tsx` (new), `apps/admin/src/pro/conditions/condition-form.ts` (new), `apps/admin/src/pro/campaigns/editor/CampaignEditorConditions.tsx`, `apps/admin/src/pro/campaigns/editor/campaign-editor.form.ts`, `tests/e2e/specs/campaign-condition-groups.spec.ts` (new). UX-1 "Conditions, one group" and "Conditions, two or more groups".
  - Reuse: `ConditionRow`, `ConditionValueEditor`, `validateConditionValue()`, `reshapeConditionValue()`, `defaultConditionValue()` (moved from `CampaignEditorConditions.tsx`); `mergeConditionErrors()` (moved from `campaign-editor.form.ts`, now walking `conditions.groups.<g>.conditions.<i>`); `Select`, `Button`, `DropdownMenu`, `Empty` from `@/components/ui/`; `useFieldArray` nested as in the current section; e2e fixtures `users.admin` in `tests/e2e/fixtures/users.ts`
  - Callers: `ConditionGroupsEditor` → `CampaignEditorConditions` (T6), `RewardEditorSections` (T7); `mergeConditionErrors()` → `campaignFormResolver` (T6), the reward resolver (T7).
- [x] T7 "Who can redeem" step in the reward editor for catalog rewards: `apps/admin/src/pro/rewards/editor/RewardEditorSections.tsx`, `apps/admin/src/pro/rewards/editor/reward-editor.form.ts`, `apps/admin/src/pro/rewards/editor/RewardEditor.tsx`, `apps/admin/src/lib/schema/reward.schema.ts`. UX-1 "Reward editor, Who can redeem".
  - Reuse: `ConditionGroupsEditor` and `mergeConditionErrors()` (T6); `isCatalogRewardType()` already used in `RewardEditorSections.tsx`; `toConditionTree()` (T5)
  - Callers: `REWARD_CONDITION_TYPES` (new, mirrors `RewardConditionType::all()`) → `RewardEditorSections` (T7).
- [x] T8 Knowledge docs for the tree shape, reward enforcement, the new seam subscriber and the new tests: `docs/knowledge/architecture.md`, `docs/knowledge/decisions.md`, `docs/knowledge/helpers.md`, `docs/knowledge/testing.md`, `docs/knowledge/e2e.md` [risk: 1]
  - Reuse: n/a

**Tests:** [unit] CampaignConditionSetTest (tests/php/unit/Models/CampaignConditionSetTest.php) — a stored 1.0.0 flat list reads as one AND group and re-encodes byte-identical in the v1 envelope, a grouped tree round-trips as schema_version 2, empty groups are dropped, and a repeated type within a group or an 11th group is rejected; [unit] ConditionsEvaluatorTest (tests/php/unit/Pro/Services/ConditionsEvaluatorTest.php) — the acceptance example, country US AND (lifetime spend >= 500 OR order count >= 5), matches only the right customers across the four combinations, a flat list still ANDs, and evaluate() equals explain()'s verdict; [unit] RewardConditionsEvaluatorTest (tests/php/unit/Pro/Services/RewardConditionsEvaluatorTest.php) — role, specific-customer and lifetime-points groups decide redeemability, a guest fails a conditional reward and passes an unconditional one, and the facts loader is not called unless a lifetime_points condition runs; [js] condition-groups.schema.test (tests/js/lib/schema/condition-groups.schema.test.ts) — toConditionTree reads a flat list as one all group and leaves a tree unchanged, and conditionsLabel renders "Country AND (Lifetime spend OR Order count)"

**E2E:** campaign-condition-groups: seed a draft campaign with country US (billing) through REST, then in the campaign editor build country US (billing) AND (lifetime spend at least 500 OR order count at least 5), save, reload, and the two groups come back with Group 1 "all", Group 2 "any" and the top "all"

## Verify
- `yayh test run unit --id=P-1` → exit 0 (unit)
- `yayh test run js --id=P-1` → exit 0 (js)
- `composer test` → exit 0
- `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` → exit 0
- `pnpm --filter @yayloyalty/admin-page lint` → exit 0
- `./scripts/run.sh build lite` → exit 0
- `grep -rl 'YayLoyalty\\Pro' includes | grep -v '^includes/Pro/' | grep -v '^includes/YayLoyalty.php$'` → exit 1
- `yayh e2e cli wp eval 'echo YayLoyalty\Utils\ConditionGroups::encode( [ [ "type" => "user_role", "operator" => "in", "value" => [ "customer" ] ] ], 1 );'` → "items"
- `yayh e2e cli wp eval 'echo YayLoyalty\Utils\ConditionGroups::encode( [ "match" => "all", "groups" => [ [ "match" => "all", "conditions" => [ [ "type" => "user_role", "operator" => "in", "value" => [ "customer" ] ] ] ], [ "match" => "any", "conditions" => [ [ "type" => "order_count", "operator" => "gte", "value" => 5 ] ] ] ] ], 1 );'` → "schema_version":2
- `yayh e2e cli wp eval --user=admin '$q = new WP_REST_Request( "POST", "/yayloyalty/v1/campaigns" ); $q->set_body_params( [ "name" => "P-1 verify", "trigger" => "purchase", "status" => "draft", "config" => [ "points_per_unit" => 1 ], "conditions" => [ "match" => "all", "groups" => [ [ "match" => "all", "conditions" => [ [ "type" => "country", "operator" => "in", "value" => [ "countries" => [ "US" ], "source" => "billing" ] ] ] ], [ "match" => "any", "conditions" => [ [ "type" => "lifetime_spend", "operator" => "gte", "value" => [ "amount" => 500, "currency" => "USD" ] ], [ "type" => "order_count", "operator" => "gte", "value" => 5 ] ] ] ] ] ] ); $d = rest_do_request( $q )->get_data(); $g = rest_do_request( new WP_REST_Request( "GET", "/yayloyalty/v1/campaigns/" . $d["id"] ) )->get_data(); rest_do_request( new WP_REST_Request( "DELETE", "/yayloyalty/v1/campaigns/" . $d["id"] ) ); echo wp_json_encode( $g["conditions"] );'` → "match":"any"
- `yayh e2e cli wp eval --user=admin '$q = new WP_REST_Request( "POST", "/yayloyalty/v1/campaigns" ); $q->set_body_params( [ "name" => "P-1 cap", "trigger" => "purchase", "status" => "draft", "config" => [ "points_per_unit" => 1 ], "conditions" => [ "match" => "any", "groups" => array_fill( 0, 11, [ "match" => "all", "conditions" => [ [ "type" => "order_count", "operator" => "gte", "value" => 1 ] ] ] ) ] ] ); echo rest_do_request( $q )->get_status();'` → 400
- Earning, Pro (acceptance 1): create the UX-1 example campaign as the top purchase campaign. Complete an order for a US-billing customer with 5 earlier paid orders, and another for a US-billing customer with no history and lifetime spend under 500. The first order gets the campaign's points and the second falls through to the next campaign. Check with `yayh e2e cli wp eval 'echo wc_get_order( <id> )->get_meta( "_yayloyalty_campaign_id" );'` → the new campaign's id for the first order only. (unit covers the matrix)
- Upgrade (acceptance 3): on a site with 1.0.0 campaigns that have flat conditions, update to this branch and open the campaigns list. Each shows the same Conditions label as before. `yayh e2e cli wp db query "SELECT conditions FROM wp_yayloyalty_campaigns WHERE conditions LIKE '%items%'" --skip-column-names` returns the same rows before and after the upgrade. Opening one shows the one-group layout with "Match [all]".
- Reward, Pro (acceptance 2): make a fixed-coupon reward with "Who can redeem" = Specific customers [customer]. Signed in as `customer`, the classic and block redeem cards list it. Signed in as another shopper, it isn't listed. A shopper who applied it and then stopped qualifying is refused at checkout with "Your account cannot spend Points on this order." Check the stored row with `yayh e2e cli wp db query "SELECT conditions FROM wp_yayloyalty_rewards WHERE name='<name>'" --skip-column-names` → `"items"` (one all group).
- UX-1 acceptance (manual, admin):
  - With one group, "Match [all] of these conditions" shows and there's no group card.
  - With 10 groups, "Add group" is disabled and "Up to 10 groups." shows.
  - An empty group is gone after save and reload, with no error.
  - "Points discount" has no "Who can redeem" step.
  - A reward with conditions shows "Selected customers" in Availability.
  - Campaigns table shows "Country AND (Lifetime spend OR Order count)". (e2e covers save and reload)
- Lite (acceptance 4): build Lite (`./scripts/run.sh build lite`) and run it with the grouped campaign stored. The campaign awards everyone who meets its trigger (fails open), and `GET /yayloyalty/v1/campaigns/<id>` returns the tree unchanged.

## Risks
- **Wrong customers earn or lose points** if the any/all fold is wrong. Unit-tested at the acceptance matrix, and `evaluate()` stays a fold over `explain()`. Rollback: revert T3. Stored rows stay valid, because flat ones never changed.
- **Downgrade to 1.0.0 with grouped rows:** `Json::decode_list()` reads them as no conditions, so those campaigns apply to everyone and those rewards to every shopper (fail-open, the house rule) until re-saved. Flat rows are unaffected. Release notes should say "downgrading after using groups removes their targeting".
- **Cart cost:** if the lazy loader ever runs on every totals pass, `wc_get_orders( limit -1 )` per pass slows the cart. The loader resolves once per Context and only for a `lifetime_points` condition (T4's unit test asserts it isn't called otherwise).
- **REST read shape change:** `conditions` is now always an object on read. The only consumers are this SPA and the Pro dashboard (Finding 7). Third-party callers that read it as a list would break, but it's documented as an internal route.
- Decided (autopilot sheet): build both in R-1. Reward-condition enforcement (T4: Pro `RewardConditionsEvaluator` on `yayloyalty_reward_conditions_match`, lazy customer facts on the rewards Context, non-qualifying rewards hidden from the redeem card) and the reward editor "Who can redeem" step (T7) stay in scope; BS-1 option A (two-level groups).
