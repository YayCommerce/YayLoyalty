# The Adjust points dialog's customer description and email options are never sent

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the Adjust points dialog UX change, 2026-10-08, branch `Trace/feat/adjust-points-dialog-ux`.

## Today

`AdjustPointsOptions` (`apps/admin/src/pages/customers/components/adjust-points/AdjustPointsOptions.tsx`) shows a "Custom description for the customer" switch with a text input, and a "Notify the customer by email" checkbox, each under a `TODO(adjust-points)`. Both values stay in the dialog's local state (`AdjustFormState.customerNote`, `AdjustFormState.notifyByEmail`). `CustomersAdjustPointsDialog` builds its request from `adjustPointsSchema` (`apps/admin/src/lib/schema/customer.schema.ts`), which carries only `points`, `note` and `adjustLifetime`. On the server, `CustomerRestController::adjust_points()` and `CustomerRouteArgs` accept only `points`, `note` and `adjust_lifetime`, and `LedgerService::adjust()` takes no customer-facing text. On the storefront, `ShopperActivityRow::label()` always shows an adjustment as "Adjustment by {store}". No email under `includes/Engine/Emails/` covers a manual adjustment.

## Why it matters

The dialog offers a custom description in place of "Adjustment by {store}", and a "Notify the customer by email" checkbox. Neither does anything. An admin who fills them in believes the customer was told something, and the customer sees only "Adjustment by {store}" and gets no email.

## Suggested fix

Add an optional customer-facing description to the adjust request and to `LedgerService::adjust()`, store it with the ledger row, and have `ShopperActivityRow::label()` show it in place of "Adjustment by {store}" when it is set. Add a notify flag to the request, and send a new `LoyaltyEmail` subclass after the ledger write, for single and bulk adjustments. This was left out of the UX change because it crosses into PHP, the ledger schema and email: read `docs/architecture.md` and the ledger's single-writer decision record first.

## Done when

- [ ] `adjustPointsSchema` and `CustomerRouteArgs` both carry the customer-facing description and the notify flag.
- [ ] `ShopperActivityRow::label()` returns the stored description for an adjustment that has one, and "Adjustment by {store}" otherwise.
- [ ] Adjusting a customer with "Notify the customer by email" ticked sends them an email, checked in wp-admin with a mail catcher; with it unticked, no email is sent.
- [ ] `AdjustPointsOptions.tsx` has no `TODO(adjust-points)` comments.
