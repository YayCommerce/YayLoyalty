# The setup wizard can save a cart discount rate of 0

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** review of the setup wizard's finish validation, 2026-10-09, branch `trace/fix/wizard-exit-setup`.

## Today

`toSetupPayload()` in `apps/admin/src/components/setup-wizard/setup-wizard.form.ts` sends `cart_discount.points_per_currency_unit: 0` when the "worth" amount is 0, and its comment says 0 "makes the server refuse it". That holds for `purchase.points_per_unit`, whose `Campaign::config_schema()` spec has `exclusive_min: 0`. It does not hold for the cart discount: `Reward::config_schema()` gives `RewardType::FLEXIBLE_DISCOUNT`'s `points_per_currency_unit` `min: 0`, so `SetupService::validation_error()` accepts 0 and `SetupService::complete()` saves it.

## Why it matters

A merchant who leaves the "worth" amount at 0 turns the program on with points worth nothing. `PointsMath::points_to_currency()` returns null for a rate of 0 or less, so redemption and the balance's money value in `PointsEarnedEmail` silently stop working, with no error in the wizard.

## Suggested fix

Refuse a discount rate of 0 or less in `SetupService::validation_error()`, or give the reward schema's `points_per_currency_unit` an `exclusive_min: 0` if no flexible discount should ever have rate 0. It was left because the review only reported it.

## Done when

- [ ] `POST /yayloyalty/v1/setup` with `cart_discount.points_per_currency_unit: 0` returns an invalid-arguments error and changes no reward row.
- [ ] The comment on `toSetupPayload()` in `apps/admin/src/components/setup-wizard/setup-wizard.form.ts` is true for both rates it sends as 0.
