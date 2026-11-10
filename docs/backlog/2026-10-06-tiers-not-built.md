# VIP tiers are not built

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-06 decision to ship v1.0 without tiers (`docs/plans/2026-10-06-remove-tiers-and-referrals.md`, branch `trace/chore/remove-tiers-referrals`).

## Today

Tiers work in Pro:

- Tiers qualify in Lifetime or Rolling mode (3–24 months, with holds), set in Tiers → Qualification.
- The tier catalogue: the `tiers` table seeded with Bronze, Silver and Gold, the `yayloyalty_manage_tiers` capability (`Capabilities` v3), Pro's tier CRUD routes (`TiersRestController`) and the Pro Tiers page with its on/off switch.
- `Pro\Services\TierEvaluator` places customers, moves them on ledger writes, pays each tier's entry bonus once and emits `ActivityEvent::TIER_CHANGED`. Catalogue edits and the switch request sweeps that the daily `yayloyalty_tier_run` drains ([the decision record](../decisions/2026-11-04-tier-evaluator-sole-writer.md)).
- The drawer's tier card assigns and removes a manual tier floor, the activity log has a Tier changes filter, and the Customers list shows, sorts and filters by tier, with the tier kept in saved segments.
- Every campaign award is multiplied by the customer's tier multiplier (`Pro\Services\TierMultiplier`), and the ledger row records `tier_id` and `tier_multiplier`.
- Coupon rewards can require a "Minimum tier" or "Only this tier", set in the reward editor: `RewardConditionType::TIER`, enforced by `Pro\Services\RewardTierCondition`. Customers who don't meet it don't see the reward on the redeem card, in the launcher or in My Account, and can't claim it.
- The launcher's member panel shows the tier badge and progress to the next tier, with the window and hold in Rolling.

Still missing:

- Nothing more for conditions: `CampaignConditionType::TIER` stays in `UNAVAILABLE` by design, so campaigns stay tier-independent ([the tiers master](../specs/2026-11-02-tiers-master-design.md), D9). `ConditionsEvaluator` rejects it as `unsupported_condition_type`.
- `CustomerFacts::$tier_id`, always null: `CustomerFactsReader` never passes it.

The branch `trace/chore/remove-tiers-referrals` removed these placeholders. Restore them from its squash commit only as a starting point:

- the Tier reached, Tier ending soon and Tier lost emails, and the Loyalty: Tiers group.

The dashboard's Tier distribution card and its Lite teaser stay removed: the dashboard shows nothing about tiers, and an entry bonus reads "Bonus points" in its Recent activity.

## Why it matters

Tiers are a headline Pro feature, and the plugin name still says "VIP". Until they ship, the plugin sells what it doesn't do.

## Suggested fix

Start from a spec. Tiers need a table with a rank, because the `TIER` conditions' `gte`/`lte` operators are ordinal. They also need an evaluator that sets `tier_id` from `lifetime_earned`. Every surface above then reads real data, not placeholders. A new capability needs a `Capabilities` version bump that grants it to roles holding the related one.

## Done when

- [x] A tiers table with a rank exists, and a schema change or data migration ships it.
- [x] An evaluator sets `customers.tier_id`, `tier_evaluated_at` and `tier_progress_value`, and emits `ActivityEvent::TIER_CHANGED`.
- [x] Merchants can manage tiers on a Tiers page, behind a capability.
- [x] The Customers list filters by tier, and saved segments store it.
- [x] The drawer shows a customer's tier and lets staff assign one.
- [x] The launcher shows the tier badge and progress to the next tier.
- [ ] Tier emails send on a real trigger.
- [x] `RewardConditionType::TIER` leaves `UNAVAILABLE` and is enforced; `CampaignConditionType::TIER` stays unavailable (master D9).
