# The Adjust points dialog's customer description is never sent

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the Adjust points dialog UX change, 2026-10-08, branch `Trace/feat/adjust-points-dialog-ux`. Narrowed on 2026-10-10, when the "Notify the customer by email" checkbox was wired to the Points adjusted email (`docs/plans/2026-10-10-points-adjusted-email.md`).

## Today

`AdjustPointsOptions` (`apps/admin/src/pages/customers/components/adjust-points/AdjustPointsOptions.tsx`) shows a "Custom description for the customer" switch with a text input, under a `TODO(adjust-points)`. The value stays in the dialog's local state (`AdjustFormState.customerNote`). `adjustPointsSchema` (`apps/admin/src/lib/schema/customer.schema.ts`) carries `points`, `note`, `adjustLifetime` and `notify`, but no description. On the server, `CustomerRouteArgs::adjust()` has no description arg either, and `LedgerService::adjust()` takes no customer-facing text. On the storefront, `ShopperActivityRow::label()` always shows an adjustment as "Adjustment by {store}". The Points adjusted email (`PointsAdjustedEmail`) shows no description.

## Why it matters

The dialog offers a custom description in place of "Adjustment by {store}". It does nothing. An admin who fills it in believes the customer sees it, but the customer sees only "Adjustment by {store}".

## Suggested fix

Add an optional customer-facing description to the adjust request and to `LedgerService::adjust()`, store it with the ledger row (`note` holds the internal reason, so this needs a new column and a migration), and have `ShopperActivityRow::label()` show it in place of "Adjustment by {store}" when it is set. Consider showing it in the Points adjusted email too, as a new template variable. Read `docs/architecture.md` and the ledger's single-writer decision record first.

## Done when

- [ ] `adjustPointsSchema` and `CustomerRouteArgs::adjust()` both carry the customer-facing description.
- [x] Adjusting a customer with "Notify the customer by email" ticked sends them an email, checked in wp-admin with a mail catcher; with it unticked, no email is sent.
- [ ] `ShopperActivityRow::label()` returns the stored description for an adjustment that has one, and "Adjustment by {store}" otherwise.
- [ ] `AdjustPointsOptions.tsx` has no `TODO(adjust-points)` comments.
