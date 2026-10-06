# VIP tiers are not built

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-06 decision to ship v1.0 without tiers (`docs/plans/2026-10-06-remove-tiers-and-referrals.md`, branch `trace/chore/remove-tiers-referrals`).

## Today

v1.0 has no tier UI or logic. Only the stored shape stays:

- `customers` columns `tier_id`, `tier_evaluated_at` and `tier_progress_value`, with `KEY tier_id` (`includes/Database/Schema/CustomersTable.php`). Nothing writes them, and `Models\Customer` does not map them.
- `ActivityEvent::TIER_CHANGED`. Nothing emits it, and the drawer renders only its title.
- `CampaignConditionType::TIER` and `RewardConditionType::TIER`, kept out of `all()` by `UNAVAILABLE`. `ConditionsEvaluator` rejects them as `unsupported_condition_type`.
- `CustomerFacts::$tier_id`, always null: `CustomerFactsReader` never passes it.

That branch removed these placeholders. Restore them from its squash commit only as a starting point:

- the Tiers page and nav item;
- the `yayloyalty_manage_tiers` capability (`Capabilities` v2 strips it from roles);
- the Customers list `tier` filter and the saved-segment `tier` key;
- the drawer's Assign tier button and the activity log's Tier changes filter;
- the dashboard's Tier distribution card and its Lite teaser;
- the Design keys `tier.badges`, `storefront_text.tier_progress_line` and `panel.show_progress_to_next_tier`, with the Tier badges section;
- the Tier reached, Tier ending soon and Tier lost emails, and the Loyalty: Tiers group.

## Why it matters

Tiers are a headline Pro feature, and the plugin name still says "VIP". Until they ship, the plugin sells what it doesn't do.

## Suggested fix

Start from a spec. Tiers need a table with a rank, because the `TIER` conditions' `gte`/`lte` operators are ordinal. They also need an evaluator that sets `tier_id` from `lifetime_earned`. Every surface above then reads real data, not placeholders. A new capability needs a `Capabilities` version bump that grants it to roles holding the related one.

## Done when

- [ ] A tiers table with a rank exists, and a schema change or data migration ships it.
- [ ] An evaluator sets `customers.tier_id`, `tier_evaluated_at` and `tier_progress_value`, and emits `ActivityEvent::TIER_CHANGED`.
- [ ] Merchants can manage tiers on a Tiers page, behind a capability.
- [ ] The Customers list filters by tier, and saved segments store it.
- [ ] The drawer shows a customer's tier and lets staff assign one.
- [ ] The dashboard shows the tier distribution.
- [ ] The storefront shows tier badges and progress to the next tier, configured in Design.
- [ ] Tier emails send on a real trigger.
- [ ] `TIER` leaves `UNAVAILABLE` in both condition types, and `ConditionsEvaluator` evaluates it.
