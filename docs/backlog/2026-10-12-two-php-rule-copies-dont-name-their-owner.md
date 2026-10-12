# Two TypeScript copies of PHP rules don't use the Mirrors wording

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the second `/yayplancheck` review of `docs/plans/2026-10-12-clear-small-backlog.md`, branch `trace/chore/clear-small-backlog`, 2026-10-12. It reported these as judgment calls: the convention names shapes and constants, and these copy rules.

## Today

[docs/conventions.md](../conventions.md#typescript-copies-of-php-name-their-owner) says a TypeScript copy of PHP opens with `Mirrors <PHP symbol>, the source of truth.` These two copy a PHP rule but open with other wording:

- `lacksPurchaseRate` in `apps/admin/src/lib/schema/campaign.schema.ts` says "The form's side of CampaignRestController::lacks_purchase_rate()". The two also differ. PHP checks `CampaignTrigger::PURCHASE === $trigger && ! isset( $config['points_per_unit'] )`, so a string rate passes. TypeScript checks `typeof config.points_per_unit !== 'number'`, so a string rate is refused, and the trigger isn't checked.
- `liabilityContribution` in `apps/admin/src/pages/customers/components/customers-adjust-points.form-state.ts` describes `DashboardRepository::liability_points()`, which sums `balance > 0` only.

## Why it matters

A search for the fixed wording is how a PHP change finds its TypeScript copies. It misses these two, so a change to either PHP rule can leave the form and the server disagreeing. One copy already differs. The convention also says a copy never repeats PHP's validation, and `lacksPurchaseRate` is a form-side check of a rule the server enforces.

## Suggested fix

Reword both comments to open with `Mirrors <PHP symbol>, the source of truth.`, keeping the rest. For `lacksPurchaseRate`, decide first whether the form should keep its own check, given the convention. If it stays, it should match PHP's condition, or say why it's stricter.

## Done when

- [ ] The comment on `lacksPurchaseRate` in `apps/admin/src/lib/schema/campaign.schema.ts` opens with "Mirrors CampaignRestController::lacks_purchase_rate(), the source of truth.", or the function is gone.
- [ ] The comment on `liabilityContribution` in `apps/admin/src/pages/customers/components/customers-adjust-points.form-state.ts` opens with "Mirrors DashboardRepository::liability_points(), the source of truth."
- [ ] `lacksPurchaseRate`, if it still exists, refuses the same configs `CampaignRestController::lacks_purchase_rate()` refuses, or its comment names each difference.
