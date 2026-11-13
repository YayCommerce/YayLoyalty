---
id: BS-1
title: Advanced rules: AND/OR condition groups
status: decided
from: R-1
decision: A. Two-level groups in the same column
created: 2026-11-13
---
# BS-1 Advanced rules: AND/OR condition groups

## Problem
R-1 (sprint 1, target 1.1.0, Pro). A merchant wants a campaign or a reward to apply to a mix of customers, such as "country is US AND (lifetime spend >= $500 OR order count >= 5)". Today they can't build that.

- **Campaigns** store `conditions` as one flat list that is always ANDed, with at most one entry per type (`includes/Models/CampaignConditionSet.php:11-15`, duplicate check at `:206-216`). The editor says "Conditions are combined with AND." (`apps/admin/src/pro/campaigns/editor/CampaignEditorConditions.tsx:90`). Pro's `ConditionsEvaluator::evaluate()` fails on the first condition that doesn't match (`includes/Pro/Services/ConditionsEvaluator.php:49-57`).
- **Rewards** have a `conditions` column with its own vocabulary (`user_role`, `customer_ids`, `lifetime_points`; `includes/Enums/RewardConditionType.php:50-72`), but nothing enforces it. `RewardGate` asks `yayloyalty_reward_conditions_match` (`includes/Engine/Redemption/RewardGate.php:39`), which nobody hooks. The cart Context passes `null` facts (`includes/Engine/Rewards/ContextFactory.php:47`, "TODO … when the Pro conditions gate lands"). The reward editor shows no conditions and sends the stored list back unchanged (`apps/admin/src/pro/rewards/editor/reward-editor.form.ts:26-28`).

Acceptance (R-1): a merchant builds a campaign or reward condition as groups joined by AND/OR, and it applies only to matching customers. An existing flat condition list keeps working unchanged after the upgrade. The release brief adds: "flat conditions read as one AND group" (`docs/roadmap/release-1.1.0.md`).

The roadmap's `why` says lifetime spend, order count and lifetime points are "declared but not offered". That isn't true for campaigns on `dev`. All three are in `CampaignConditionType::all()` (only `TIER` is in `UNAVAILABLE`, `includes/Enums/CampaignConditionType.php:61-63`), the SPA offers them (`apps/admin/src/lib/schema/campaign.schema.ts:142-153`), and the evaluator handles them (`ConditionsEvaluator.php:122-129`). What's missing for those types is OR, not the types. For rewards, `lifetime_points` is declared but neither offered nor enforced.

## Constraints
- **Editions:** Pro only for the feature (the evaluators and both editors live in `includes/Pro/` and `apps/admin/src/pro/`). Lite still has to *read* the new stored shape, because models and REST live in Lite. Lite also keeps failing open: with no evaluator, a conditional campaign applies to everyone (`includes/Engine/Points/PointsEngine.php:160-176`, decisions.md "Lite fails open"). Hard rule 1: Pro attaches only through filters. Hard rule 2: SPA Pro code stays behind `src/pro/index.ts` and `IS_PRO_BUILD`.
- **Stored data:** campaigns and rewards keep `conditions` as LONGTEXT JSON in a `{"schema_version":1,"items":[…]}` envelope (`includes/Utils/Json.php:63-91`). `Json::decode_list()` turns any object that isn't a list into `[]`, so 1.0.0 code that reads a new object shape sees "no conditions". Hard rule 5: any migration must be idempotent. Hard rule 6: keep Pro values recognisable in Lite.
- **REST:** the `conditions` property already accepts `object` or `array` (`includes/Controllers/CampaignRestController.php:424-427`, `RewardRestController.php:514-517`), so the route schema doesn't need to change.
- **Evaluation is pure:** the evaluator compares, never queries. Customer facts load lazily, only when a history condition runs (`includes/Engine/Points/ContextFactory.php:143-153`, `Context::get_customer_facts()`). Reward quotes run on every cart totals pass, so reward facts have to load lazily too: `CustomerFactsReader::read()` loads every paid order (`includes/Engine/Points/CustomerFactsReader.php:20-29`).
- **Preview parity:** `explain()` is what `evaluate()` folds over, so the admin preview can never disagree with award time (`ConditionsEvaluator.php:59-73`).
- **Surfaces:** campaigns are earned on order completion and shown in product and launcher adverts (`PointsEngine::advertised()`). Rewards are quoted in the classic and block redeem card and re-checked at commit (`CommitWriter`, `RedemptionService`). Both checkouts share one quote path.
- PHP 7.4. Wire shapes are snake_case. TS copies start with a "Mirrors …" docblock (conventions.md).

## Options

### A. Two-level groups in the same column (root AND/OR, groups with their own AND/OR)
- **How:** `conditions` becomes `{ match: all|any, groups: [ { match: all|any, conditions: [ {type, operator, value} ] } ] }`.
  - A new Lite helper, `Utils\ConditionGroups`, parses either shape. A flat list becomes one `all` group. It also normalizes (drops empty groups, caps the group count), checks the structure, and folds per-condition results into group and root verdicts.
  - `CampaignConditionSet` and `RewardConditionSet` keep their own leaf vocabularies and value checks. They only call it per group, and the duplicate-type rule becomes per group.
  - Storage writes the old `{"schema_version":1,"items":[…]}` list whenever the tree is one `all` group, so a campaign nobody groups is stored byte-for-byte as before. Only a real grouping writes `{"schema_version":2,"match":…,"groups":…}`.
  - Pro's `ConditionsEvaluator::explain()` returns per-group results, and `evaluate()` folds them. A new Pro `RewardConditionsEvaluator` hooks the existing `yayloyalty_reward_conditions_match` filter. The rewards Context gets a lazy facts loader like the earning Context.
  - The SPA gets one group editor under `src/pro/conditions/`, which both the campaign and reward editors use.
- **Pros:** it expresses the acceptance example directly, as `all`( group `all`[country], group `any`[spend, orders] ). Most stores have flat lists, and those stay untouched on disk, so there's no migration and a downgrade keeps reading them. One structure serves both campaigns and rewards. Two levels (groups of conditions) is the familiar "rule groups" pattern of WooCommerce rule builders, and it fits the editor's existing one-row-per-condition layout.
- **Cons:** the REST read shape of `conditions` changes from a list to an object, so the SPA readers (table, dashboard, performance labels) change too. A grouped row read by 1.0.0 code decodes to no conditions (fails open). Reward enforcement is new end to end, not just regrouped.
- **Risks:** an off-by-one in the any/all fold awards points to the wrong customers. A unit test of the acceptance matrix covers it. Downgrade to 1.0.0 with grouped rows: those campaigns apply to everyone until re-edited.
- **Editions:** Lite reads and stores both shapes and fails open. Pro evaluates and edits.
- **Size:** L.

### B. Arbitrary-depth tree (a node is a condition or a group of nodes)
- **How:** same column, recursive `{ match, children: [ node ] }` with a depth cap (say 4). A recursive parser and validator, a recursive evaluator, and a nested query-builder UI with indent levels.
- **Pros:** fully general: any boolean formula.
- **Cons:** the UI is much harder to read and to build. Nested indents in a narrow wp-admin column, drag between levels, and merchants lose track past two levels. Validation and preview need depth and size caps. Nothing in R-1 or the release brief needs more than one level of grouping. It costs more than A for no acceptance criterion.
- **Risks:** a more complex fold and UI state, and more ways to save a tree nobody understands.
- **Editions:** as A.
- **Size:** L+ (about 1.5× A on the SPA).

### C. Keep the flat list and tag each condition with a group number (OR of AND groups)
- **How:** each entry gets a `group: n` key. Entries with the same `group` are ANDed and different groups are ORed (disjunctive normal form). Storage stays a list in the v1 envelope, and `decode_list()` keeps working.
- **Pros:** the smallest storage change. The 1.0.0 reader still parses rows (it ignores `group`).
- **Cons:** the acceptance example has to be distributed by hand: (US AND spend) OR (US AND orders), with the country condition repeated in every group. Merchants have to think in DNF, which is the main usability complaint about this model. A 1.0.0 reader ANDs every group together, which fails closed and silently stops campaigns, against the house fail-open rule. The "one per type" rule has to become "one per type per group" anyway.
- **Risks:** silent under-awarding after a downgrade. A confusing UI.
- **Editions:** as A.
- **Size:** M.

## Recommendation
**A. Two-level groups in the same column.** It's the smallest model that expresses the acceptance example as the merchant says it. Keeping flat lists byte-identical in storage meets "an existing flat condition list keeps working unchanged after upgrade" without a migration. Both condition sets can share one group helper and keep their separate leaf vocabularies, which the code chose on purpose (`CampaignConditionSet.php:14`, `RewardCondition.php:9`). B pays for generality nothing asks for. C saves storage work but makes merchants rewrite their own rule, and fails closed on a downgrade.

What would change my mind: a merchant request for a third nesting level (then B), or a decision that 1.0.0 downgrade compatibility outranks expressiveness (then C).

Rewards are the larger hidden part. Reward conditions are neither editable nor enforced today, so "reward condition as groups" means building reward-condition enforcement (Pro evaluator, lazy facts on the cart Context, the editor section) on top of the grouping. The plan sizes that in.

**Decided (autopilot sheet, 2026-11-13):** option A, with reward-condition enforcement and the reward editor "Who can redeem" step both built in R-1 (not split to a new item). A shopper who doesn't qualify has the reward hidden from the redeem card. The launcher's "Ways to redeem" stays as is for 1.1.0 (BL-1, deferred).

## Open questions
- Scope: the acceptance names rewards, but reward conditions aren't enforced or editable today. Should R-1 build them (recommended, as the acceptance says), or ship campaign groups first and move rewards to their own item?
- What a shopper who doesn't qualify sees for a conditional reward in the redeem card: hidden (proposed), or shown disabled like an unaffordable one.
- The launcher's "Ways to redeem" list (`includes/Pro/Storefront/RewardsPanel.php:30-45`) has no customer to check against and lists every live reward. Does it stay as is for 1.1.0?
