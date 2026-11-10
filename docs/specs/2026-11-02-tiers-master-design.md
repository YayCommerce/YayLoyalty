# Tiers (master) Design

**Status:** accepted 2026-11-03
**Amended:** 2026-11-10: a coupon reward's TIER condition is either "Minimum tier" (`gte`) or "Only this tier" (`eq`), chosen in the reward editor with a "Tier rule" select (D10). The sections marked "amended 2026-11-10" describe the change.
**Related:** `docs/backlog/2026-10-06-tiers-not-built.md`, `docs/plans/2026-10-06-remove-tiers-and-referrals.md` (removed the v1.0 placeholders)

This master spec fixes the feature's direction. Each delivery slice (see Delivery) gets its own child spec or plan and is built from it.

## Goal

Pro merchants group customers into ranked tiers by the points they earn. Each tier multiplies the points campaigns award, pays a one-time entry bonus, and can gate coupon rewards. Shoppers see their tier and their progress to the next one in the launcher.

## Context

- **Stored shape left by v1.0** (`includes/Database/Schema/CustomersTable.php`): `customers.tier_id`, `tier_evaluated_at`, `tier_progress_value`, `KEY tier_id`. Nothing writes them, and `Models\Customer` does not map them.
- **Enums kept for tiers:**
  - `ActivityEvent::TIER_CHANGED`.
  - `CampaignConditionType::TIER` and `RewardConditionType::TIER`, both in `UNAVAILABLE`.
  - `CustomerFacts::$tier_id`, always null today because `CustomerFactsReader` never passes it.
- **Points earned:** `customers.lifetime_earned` = EARN + REFUND_EARN + ADJUST rows (`LedgerType::affects_lifetime_earned()`). Redemptions, expiry and CORRECTION rows don't touch it. Pending points live in `customers.pending_points` and are not part of it. `LedgerService::adjust( …, $adjust_lifetime )` already documents ADJUST rows as moving "tier progress".
- **Campaign selection** (`includes/Engine/Points/PointsEngine.php`): one campaign pays per trigger per event, the highest-priority one whose conditions match and whose handler's `is_eligible()` passes. Triggers stack across separate calls.
- **Points pipeline today:**
  1. The handler's `compute_points()` applies the rate and the campaign's `rounding`, then `yayloyalty_points_for_order` (purchase only).
  2. `CapPolicy` applies the campaign's `max_points_per_order`, and `PointsEngine::evaluate()` returns the `Result`.
  3. `EarningService::award()` applies `yayloyalty_award_points` (Pro's `DailyEarningLimit`, which may only lower the points) under the customer lock.
- **Reward conditions are not enforced today:**
  - `RewardGate::check()` applies `yayloyalty_reward_conditions_match` (default true), and nothing hooks it, Pro included.
  - `Rewards\ContextFactory::from_cart()` passes null facts (`TODO(redemption)`).
  - `RewardConditionType` offers `user_role`, `customer_ids` and `lifetime_points`, and `RewardRestController` saves them. The Pro reward editor has no conditions UI: it sends the stored list back untouched (`reward-editor.form.ts`). Only a REST call can set reward conditions today.
  - `RedemptionService::reward_options()` lists every live coupon reward with its quote, and the card shows a denied quote as unavailable.
- **Reward types:** coupon rewards (`fixed_coupon`, `percent_coupon`) are Pro-only. Lite registers no handler for them and offers none. The built-in `flexible_discount` takes no TIER condition.
- **Single writers:** `LedgerService` writes ledger rows and customer totals, and lists its five construction sites in its docblock. It fires `yayloyalty_ledger_recorded` after commit.
- **Precedent for window changes:** `ExpiryPolicy::ON_SHORTEN_KEEP`.
- **Daily job:** `ExpiryRunner` (`yayloyalty_expiry_run`, Action Scheduler group `yayloyalty`).
- **Capabilities:** `Capabilities::VERSION` is 2. v2 stripped `yayloyalty_manage_tiers`.

## Scope

**In:**
- A tiers table, seeded with Bronze, Silver and Gold. Merchants create, edit and delete tiers; at most 10 live tiers, at least 1.
- Qualification on points earned (`lifetime_earned` semantics, pending excluded), in one of two modes:
  - **Lifetime**;
  - **Rolling**, over 3, 6, 9, 12, 18 or 24 months (default 6), with a hold.
- A stored tier per customer, written only by a tier evaluator. It runs after each ledger write and in a daily job. Every change is logged as `TIER_CHANGED` activity.
- Retroactive placement of existing customers when tiers are turned on.
- Perks:
  - a points multiplier per tier, applied to the points every campaign awards;
  - a one-time entry bonus per tier;
  - a "Minimum tier" or "Only this tier" condition on coupon rewards (amended 2026-11-10).
- Enforcement of the reward TIER condition only, at listing and at claim, set in a dedicated "Tier" section of the reward editor.
- Manual tier assignment (a floor) from the customer drawer, with "No end" or "Limited" (days).
- A tier filter in the Customers list and saved segments.
- A launcher badge and a progress line to the next tier.
- Pro only. Lite keeps the stored data untouched.

**Out:**
- **Any tier condition on campaigns.** Campaigns stay tier-independent. `CampaignConditionType::TIER` stays in `UNAVAILABLE`, and the campaign editor gets no tier row.
- **A per-tier or per-order cap on multiplied points.**
- **Every reward condition type other than TIER** (`user_role`, `customer_ids`, `lifetime_points`): no enforcement, no editor UI. They stay as today, stored and ignored, until a later version brings back a Conditions section.
- All tier emails (Tier reached, Tier ending soon, Tier lost). They are a follow-up after the feature is complete; the `yayloyalty_tier_changed` action is their future hook.
- Free-text perks.
- Coupon or free-shipping entry rewards, and automatic tier discounts.
- Per-tier expiry or redemption rate.
- A spend-based metric, a calendar-year mode, and an inactivity-based downgrade.
- Anything about tiers on the dashboard: no tier-distribution card, and an entry bonus row in Recent activity reads "Bonus points", not the tier's name.
- My Account → Rewards tier section, and the shortcodes and blocks. My Account's reward list still hides tier-gated rewards (D16).
- A TIER condition on `flexible_discount`.
- Clawing back an entry bonus.

## Decisions

| # | Decision |
|---|---|
| D1 | **Metric:** points earned, `lifetime_earned` semantics, pending excluded. |
| D2 | **Modes:** `lifetime` and `rolling`. Rolling months come from 3, 6, 9, 12, 18 or 24, **default 6**. |
| D3 | **Changing the rolling months** never moves a stored `tier_held_until`. Current holds end on their old date, and the new window applies from each customer's next evaluation. Lifetime → Rolling gives every current member a hold of now + N months. Rolling → Lifetime clears holds and re-evaluates, which can only raise. |
| D4 | **Rolling window:** the qualifying total is the net points earned in the last N months. A clawback (REFUND_EARN) counts in the window of the earn row it reverses, not on its own date. |
| D5 | **Rolling hold:** reaching a tier sets `tier_held_until` = now + N months. At hold end the customer moves straight to the tier their window total qualifies for, possibly several steps down, with a new hold. Re-qualifying for the same tier renews the hold. An upgrade during a hold starts a new hold for the new tier. |
| D6 | **Refunds** re-evaluate at once and can downgrade, including during a hold, when the clawed-back points counted toward the current tier (D4). The entry bonus is never clawed back. |
| D7 | **Multiplied points** count toward tier progress. |
| D8 | **Multiplier:** one per tier, set on the Tiers tab, range 1.00–10.00, step 0.05. It acts only on the earning stage, after the campaign is done: campaign → the campaign's points (rate, rounding, `yayloyalty_points_for_order`, the campaign's `max_points_per_order`) → × tier multiplier → final points. The campaign's per-order cap bounds the campaign's points, not the multiplied result. See D22 for rounding and the daily cap. |
| D9 | **Campaigns are tier-independent.** No campaign condition, setting or editor row knows tiers. The tier reaches earning only through D8. |
| D10 | **Reward TIER condition** (amended 2026-11-10): one tier id with one of two rules. "Minimum tier" (`gte`): a customer sees and can claim rewards of their tier and every lower tier, compared by rank. "Only this tier" (`eq`): only customers whose live tier is that tier, compared by id, so a rerank doesn't change who qualifies. Offered on coupon rewards only, never on `flexible_discount`. Picking several tiers (`in`) and a maximum tier (`lte`) were rejected: no one asked for them, and they need a multi-select. |
| D11 | **Entry bonus:** paid only for the tier reached, never for skipped tiers. It counts toward points earned, but doesn't trigger a further upgrade in the same evaluation; the next ledger write can. Once per customer per tier, ever. The multiplier doesn't apply to it. |
| D12 | **Bonus cases:** none during the retroactive placement batch. Paid once on a manual assignment (same once-per-tier key). |
| D13 | **Manual assignment is a floor.** The customer is at least the assigned tier. Auto-evaluation can raise them above it, never lower them below it. Staff lower a customer by deducting lifetime points with Adjust (ADJUST row). |
| D14 | **Manual assignment end:** "No end" (default) or "Limited" with a number of days, 1–3650. At the end the floor clears, and the customer is re-evaluated (reason `manual_end`). |
| D15 | **Deleting a tier** that a reward condition references is blocked (409, with the referencing rewards). No tier is protected; the last live tier can't be deleted. |
| D16 | **Reward conditions: TIER only.** The evaluator checks the TIER condition and nothing else; every other stored type passes, as today. A reward whose TIER condition the customer doesn't meet is hidden from the redeem card, the launcher and My Account, and a forced claim fails with `REASON_CONDITIONS_NOT_MET`. A reward with no TIER condition behaves exactly as today. |
| D17 | **Lowest tier threshold** is merchant-set. Below it a customer has no tier (`tier_id` NULL) and a multiplier of 1.00. |
| D18 | **Thresholds:** unique among live tiers, and rank follows threshold order (rewritten on every save, no drag). Lowering a threshold upgrades customers through the re-evaluation batch. Raising one never downgrades current members: in Lifetime they keep the tier; in Rolling until their hold ends. |
| D19 | **Seed and switch:** Bronze 0 / Silver 800 / Gold 3000, multipliers 1.00 / 1.25 / 1.50, bonus 0, colours #CD7F32 / #C0C0C0 / #FFD700. `tiers_enabled` is off after upgrade. Turning it on runs the placement batch. |
| D20 | **Single writer:** the tier is stored. `TierEvaluator` is the only writer of the tier columns, and runs after commit, never inside `LedgerService`'s lock. This lands as a decision record. |
| D22 | **Multiplied points round down, always** (`floor`), whatever the campaign's own `rounding` says: 101 × 1.25 = 126. Pro's `DailyEarningLimit` then applies to the final, multiplied points, as it applies today to whatever an award writes. |
| D21 | **Delivery:** vertical slices 1–5 (see Delivery), all committed to `jason/feat/tiers`. The user opens the one PR from it into `dev/edd`. |

## Acceptance criteria

Child specs and plans refine these with their own exact values.

1. On upgrade, the tiers table holds Bronze 0 / Silver 800 / Gold 3000 with multipliers 1.00 / 1.25 / 1.50, bonus 0, and `tiers_enabled` is false.
2. With `tiers_enabled` false, no customer's `tier_id` changes, the multiplier is 1, reward TIER conditions don't match, and the launcher shows no tier.
3. Turning tiers on queues a placement batch. When it finishes, every customer row has the tier its points qualify for, `TIER_CHANGED` rows with reason `placement` exist, and no `tier_bonus` ledger row was written.
4. **Lifetime mode:** a customer at 780 completes an order earning 30. After commit, `tier_id` is Silver, `tier_progress_value` is 810, and one `TIER_CHANGED` row (Bronze → Silver, reason `earn`) exists.
5. **Multiplier after the campaign:** a purchase campaign pays 1 pt/unit with rounding down. A Silver customer's 101-unit order earns 126 points (campaign 101, then `floor(101 × 1.25)`), and `lifetime_earned` grows by 126.
6. **Campaign cap before the multiplier:** a purchase campaign pays 1 pt/unit with `max_points_per_order` 100. A Gold customer's 150-unit order earns 150 (campaign 100, then × 1.50).
7. **Campaigns unchanged:** for a tierless customer, or with tiers off, every campaign awards exactly what it awards today.
8. **Entry bonus, once per tier:** with Silver's bonus set to 100, reaching Silver writes one EARN row of 100 (not multiplied) with source `tier_bonus` and key `tier_bonus:{user_id}:{tier_id}`. Falling out of Silver and reaching it again writes no second row.
9. **No bonus cascade:** Silver's bonus would carry a customer past Gold's threshold, yet that evaluation leaves them in Silver. The next ledger write moves them to Gold.
10. **Refund downgrade:** a refund that claws back enough points to drop the customer below Silver's threshold leaves them in Bronze after commit (`TIER_CHANGED`, reason `refund`). Silver's bonus row stays.
11. **Reward hiding:** reward R has condition "Minimum tier: Silver". A Silver or Gold customer sees R on the redeem card, in the launcher and in My Account, and can claim it. A Bronze or tierless customer doesn't see it, and a forced claim fails with `REASON_CONDITIONS_NOT_MET`. With "Only this tier: Silver" instead (amended 2026-11-10), only a Silver customer sees and can claim R; Gold, Bronze and tierless customers are treated as Bronze is above.
12. **Other conditions untouched:** reward S has a REST-set `user_role` condition the customer doesn't meet, and no TIER condition. S is listed and claimable, as today.
13. **Blocked delete:** deleting Silver while R references it returns 409 and lists R. Once R no longer references it, the delete soft-deletes Silver. Its customers are re-evaluated into the remaining tiers (reason `tier_deleted`), and the activity log still shows "Silver". Deleting the last live tier returns 409.
14. **Rolling hold:** in Rolling mode with 6 months, a customer who reached Gold on 2027-01-10 has `tier_held_until` 2027-07-10 and stays Gold until then. On the first daily run on or after that date, they move to the tier their last-6-month total qualifies for, with a new hold.
15. **Months change:** with 12 months and a hold until 2027-12-01, changing to 6 months leaves `tier_held_until` at 2027-12-01.
16. **Refund in another window:** in Rolling mode, a refund of an order whose earn row is older than the window doesn't lower the qualifying total.
17. **Manual floor:** staff assign Gold, Limited 30 days, to a Bronze customer. `tier_id` is Gold and `tier_manual_until` is now + 30 days. `TIER_CHANGED` has reason `manual` and the actor id, and Gold's bonus is paid once. After 30 days, the daily run re-evaluates them (reason `manual_end`). While the floor holds, a refund never lowers them below Gold.
18. **Raised threshold:** raising Silver from 800 to 1000 leaves a Lifetime-mode Silver customer at 850 in Silver.
19. **Customers filter:** the Customers list filters by one or more tiers and "No tier". A saved segment stores the `tier` key and re-applies it.
20. **Launcher:** the member panel shows the badge (name and colour) and "X points to {next tier}", or "Top tier" at the highest. A tierless customer sees "X points to {lowest tier}".
21. **Lite keeps the data:** after swapping Pro for Lite and running `Installer::install()`, every tier row and customer tier column is unchanged, and every campaign awards what it would for a tierless customer.
22. **End to end** on the wp-env Pro site, with tiers on, Lifetime mode, the seed tiers, Silver's bonus at 100, the default purchase campaign at 1 pt/unit and a "Minimum tier: Silver" reward:
    1. A new customer's 800-unit completed order puts them in Silver, with 900 points (800 + 100 bonus).
    2. Their next 100-unit order earns 125.
    3. The launcher shows the Silver badge and progress to Gold.
    4. They can claim the Silver reward.
    5. Refunding the first order drops them out of Silver.

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call. Pro attaches to tiers only through filters that `boot()` adds.
- SPA tier code lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD`.
- Only `LedgerService` writes ledger rows and balances. The entry bonus goes through `LedgerService::earn()`, and the class that constructs it is added to `LedgerService`'s docblock list.
- Lite drops Pro behaviour, never Pro data (`docs/conventions.md`). The tiers table and customer columns are installed by Lite's `Installer::schemas()`. Stored reward TIER conditions keep validating in Lite through `all_known()`.
- Dates are stored in UTC (`Utils\Dates`). Multipliers are stored as `decimal(4,2)` and travel as decimal strings (`docs/conventions.md`).
- A schema change reaches existing sites only through a plugin version bump (`yayloyalty_db_version`).
- Orders are read through WooCommerce CRUD only (HPOS).
- New capability `yayloyalty_manage_tiers`, with `Capabilities::VERSION` 3, granted to every role that holds `yayloyalty_manage_campaigns`.
- No tier work inside `LedgerService`'s customer-row lock: the evaluator runs after commit.
- All work lands on `jason/feat/tiers`. Commit only when the user asks, per CLAUDE.md.

## Approach

**Chosen:** a stored tier with a single writer, evaluated after ledger writes and by a daily job, and a multiplier applied after the campaign.

**Architecture:**
- `Pro\Services\TierEvaluator` is the only writer of the tier columns. It runs on two triggers:
  - `yayloyalty_ledger_recorded` (after commit), for the affected customer;
  - a tier step in `yayloyalty_expiry_run`, for ended holds, ended manual floors, and the placement and re-evaluation batches.
- It computes the qualifying total (D1, D4), applies the hold (D5), the floor (D13) and the no-downgrade-on-raise rule (D18), then writes the result under the customer-row lock.
- On a change it records `TIER_CHANGED`. On an upgrade it pays the entry bonus through `LedgerService::earn()` in the same transaction.
- The bonus row re-enters the evaluator after commit. D11 makes that evaluation skip the upgrade.
- Earning picks and scores the campaign exactly as today. Once the campaign's points are final, the engine asks a new seam for the customer's multiplier, which Pro fills from the stored tier.

**Components:**
- `includes/Database/Schema/TiersTable.php` [new, Lite]: the tiers DDL.
- `includes/Database/Schema/CustomersTable.php` [edit, Lite]: the new tier columns.
- `includes/Models/Tier.php`, `includes/Repositories/TierRepository.php` [new, Lite]: the typed row and all tier SQL.
- `includes/Pro/Services/TierEvaluator.php` [new]: the qualifying total, hold, floor, write, activity and bonus.
- `includes/Pro/Services/TierMultiplier.php` [new]: answers the multiplier seam.
- `includes/Pro/Controllers/TiersRestController.php` [new]: tier CRUD and settings, delete with reference check, manual assignment.
- `includes/Pro/Services/RewardTierCondition.php` [new, slice 3]: hooks `yayloyalty_reward_conditions_match`, and checks only the reward's TIER condition against the customer's tier rank. Every other type passes.
- `includes/Engine/Rewards/ContextFactory.php` [edit, slice 3]: `from_cart()`, `from_order()` and `from_order_line()` pass `CustomerFacts` carrying `tier_id` and `tier_rank`, replacing the `TODO(redemption)`.
- `includes/Enums/RewardConditionType.php` [edit, slice 3]: `TIER` leaves `UNAVAILABLE`. `CampaignConditionType::TIER` stays in it.
- `includes/Pro/Storefront/RewardsPanel.php` and the `RedemptionService::reward_options()` callers [edit]: drop rewards whose TIER condition fails (D16).
- `includes/Engine/Points/PointsEngine.php` [edit, Lite]: the multiplier seam after `CapPolicy`, default 1.
- `includes/Access/Capabilities.php` [edit]: v3.
- SPA, all under `apps/admin/src/pro/`:
  - the Tiers tab;
  - the Customers tier column, filter and segment key;
  - the drawer tier card and Assign dialog;
  - the "Tier rule" and "Tier" rows in the reward editor's Tier section (amended 2026-11-10).
- `includes/Engine/Storefront/` [edit]: the badge and progress line through a launcher-payload filter that Pro fills.

**Data and storage:**
- **Table `{prefix}yayloyalty_tiers`:**

  | Column | Type |
  |---|---|
  | `id` | bigint unsigned AI PK |
  | `name` | varchar(100) |
  | `threshold` | bigint unsigned |
  | `tier_rank` | int unsigned |
  | `multiplier` | decimal(4,2) DEFAULT 1.00 |
  | `entry_bonus` | bigint unsigned DEFAULT 0 |
  | `color` | varchar(7) |
  | `created_at`, `updated_at` | datetime |
  | `deleted_at` | datetime NULL |

  Keys: `KEY idx_live_rank (deleted_at, tier_rank)`. Name and threshold uniqueness among live tiers is enforced in the repository, because a soft-deleted row would collide with a unique index.
- **New `customers` columns:**
  - `tier_held_until` datetime NULL;
  - `tier_achieved_at` datetime NULL;
  - `tier_manual_id` bigint unsigned NULL;
  - `tier_manual_until` datetime NULL (NULL with a `tier_manual_id` means No end).

  Keys: `KEY tier_held_until (tier_held_until)` and `KEY tier_manual_until (tier_manual_until)`. The existing `tier_id` is the effective tier, and `tier_progress_value` the qualifying total.
- **Settings** (root keys in `yayloyalty_settings`, whitelisted in both editions):
  - `tiers_enabled` bool, default false;
  - `tiers_mode` `lifetime` | `rolling`, default `lifetime`;
  - `tiers_rolling_months` one of 3, 6, 9, 12, 18, 24, default 6.
- **Ledger:** new `LedgerSource::TIER_BONUS` (`tier_bonus`) and `ReferenceType::TIER` (`tier`). The bonus row is type EARN.
- **Activity:** `TIER_CHANGED` payload `{from_tier_id, to_tier_id, from_name, to_name, reason, actor_id}`. `reason` ∈ `earn`, `refund`, `adjustment`, `hold_end`, `manual`, `manual_end`, `threshold_change`, `tier_deleted`, `placement`.
- **Uninstall:** none, as for every table today.

**Integration points:**
- New filter `yayloyalty_points_multiplier` (internal Pro seam), applied in `PointsEngine::evaluate()` after `CapPolicy`, with `(string '1.00', Campaign, Context)`. Pro returns the tier's multiplier for the Context's customer. `Breakdown` records the campaign's points and the multiplier, for the ledger note and the campaign preview. Because it sits in `evaluate()`, `EarningService::preview()` (pending points, the product-page line) shows multiplied figures too. `advertised()` (guests, Ways to earn) stays unmultiplied.
- `yayloyalty_reward_conditions_match`: its first subscriber (slice 3).
- `yayloyalty_ledger_recorded`: the evaluator's trigger.
- `yayloyalty_expiry_run`: gains a tier step after expiry.
- New action `yayloyalty_tier_changed` `(int $user_id, ?int $from, ?int $to, string $reason)`, fired after commit.
- REST, Pro, `yayloyalty/v1`, all behind `yayloyalty_manage_tiers` except `GET /tiers`, which needs `VIEW`:
  - `GET/POST /tiers`;
  - `PUT/DELETE /tiers/{id}`;
  - `PUT /tiers/settings`;
  - `POST /customers/{id}/tier`.

**UI:**
- **Tiers tab** (Pro nav item):
  - the on/off switch;
  - the mode, plus months for Rolling;
  - a list sorted by threshold, with name, threshold, multiplier, bonus, colour and member count;
  - create and edit dialogs;
  - delete with the list of referencing rewards.
- **Reward editor** (amended 2026-11-10): a dedicated "Tier" section on coupon rewards only. A "Tier rule" select ("Any tier" = no condition, "Minimum tier", "Only this tier") is followed, for the last two, by a required "Tier" select. It writes or removes the single `{type: tier, operator: gte|eq, value: tier_id}` entry, and keeps every other stored condition untouched. There is no general Conditions section.
- **Campaign editor:** unchanged.
- **Customers:** a tier column, a tier filter, and the segment key.
- **Drawer:**
  - a tier card: tier, progress, held until, and the manual floor with its end;
  - an "Assign tier" dialog: a tier select, and End: No end (default) / Limited with days.
- **Launcher:** the badge and progress line in the member panel.

**Edge cases and failure modes:**
- **Tier at award time.** Pending points are staged with the tier at checkout. `award()` re-evaluates with the tier at completion and is authoritative. The order that lifts a customer into a tier earns at the old tier's multiplier.
- **Lite downgrade:** no impact on earning or rewards.
  - The multiplier seam has no subscriber, so 1.00.
  - Campaigns carry no tier conditions (D9).
  - Reward TIER conditions exist only on coupon rewards, which Lite doesn't offer.
  - `tier_id` freezes, because the evaluator is gone.
- **No change for existing rewards:** only TIER is enforced, and no reward has a TIER condition before slice 3. Existing REST-set conditions stay ignored.
- **Checkout re-check:** `CommitWriter` re-quotes reward lines under the customer lock. A customer who drops below a reward's tier between the cart and the commit (a refund in between) has that line refused and the checkout failed, as for any failed re-check.
- **Deleting the lowest tier** leaves customers below the next threshold tierless (D17).
- **Concurrency.** The evaluator locks the customer row in its own transaction after the ledger commit. A racing double evaluation writes the same result, and the bonus key blocks a double bonus.
- **Large stores.** Placement and re-evaluation run 100 customers per Action Scheduler batch with async continuation, as `ExpiryRunner` does.

**Decision record:** `docs/decisions/YYYY-MM-DD-tier-evaluator-sole-writer.md`, added in slice 2, records D20.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Qualify on the available balance | Redeeming and expiry would demote customers. No competitor does it. |
| Compute the tier on the fly | No transition moment for history, bonus or emails. Holds and floors need stored state anyway. |
| Tier written inside `LedgerService` | Widens the ledger's single-writer role and its lock time. Tiers are Pro, while `LedgerService` is Lite. |
| Multiplier per campaign | Couples campaigns to tiers. N × M settings to keep in step. |
| Multiplier before the campaign's rounding and cap | Reaches into each campaign's own scoring. The user wants campaigns untouched and the multiplier as a separate stage (D8). |
| TIER condition on campaigns | Couples campaigns to tiers, depends on campaign priority to work, and fails open on a Lite downgrade (a Gold-only campaign would pay everyone). The multiplier covers the need. |
| A cap on multiplied points | Not wanted; the campaign's own cap and the daily cap bound earning. |
| One-step-per-period rolling downgrade | Keeps an inactive customer above their level for years |
| A manual tier that is fixed (can't be raised) | Blocks a customer who earns past it. A floor plus Adjust covers lowering. |
| Hard delete of tiers | Breaks activity history and stored conditions |
| Protected base tier | The lowest threshold is merchant-set (D17), so the base tier has nothing special left to protect |
| Enforcing every reward condition type, or a general Conditions section | The user limits this version to TIER; other conditions return in a later version (D16) |
| Reward-condition enforcement as its own slice 0 | With no UI to set other conditions it was invisible and unverifiable; TIER-only enforcement lives in slice 3 |
| Calendar-year reset, inactivity downgrade | A cliff edge on one date; one small action holds a tier forever |
| Three layer tasks (schema / backend / UI) | Schema alone can't be verified, backend only through REST until the end, and the PRs are too large. Replaced by D21. |

## Delivery

Decided (D21). Every slice leaves the plugin working and verifiable in the UI, and `tiers_enabled` stays off until slice 2 exposes it. All slices are committed to `jason/feat/tiers`.

| # | Slice | Artifact |
|---|---|---|
| 1 | **Tier catalogue:** schema (tiers table, customer columns, settings keys, seed, version bump), `Capabilities` v3, tier CRUD REST with delete rules, the Tiers tab list and dialogs | Child spec + plan |
| 2 | **Lifetime evaluation:** `TierEvaluator` (Lifetime), the after-commit trigger, placement and re-evaluation batches, `TIER_CHANGED` and `yayloyalty_tier_changed`, the entry bonus, the enable switch, the drawer card and manual assignment with its daily end step, the Customers filter and segments, the decision record | Child spec + plan |
| 3 | **Perks:** the multiplier seam and `TierMultiplier` (D8, D22), `RewardTierCondition` with the tier facts in `Rewards\ContextFactory`, reward hiding, the reward editor's "Tier" section | Child spec + plan |
| 4 | **Rolling mode:** the window total with clawback attribution, holds, the hold-end daily step, months changes (D3), the mode controls | Child spec + plan |
| 5 | **Storefront:** the launcher badge and progress line | Plan only |

## Open questions

None.
