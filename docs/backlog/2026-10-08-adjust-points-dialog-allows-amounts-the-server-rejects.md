# The Adjust points dialog lets the admin confirm amounts the server will reject

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the Adjust points dialog UX change, 2026-10-08, branch `Trace/feat/adjust-points-dialog-ux`.

## Today

`CustomerRestController::validate_adjustment()` refuses any adjustment larger than the `yayloyalty_max_manual_adjustment` filter's value (1,000,000 points by default), with "A single adjustment cannot exceed %s points." `AbstractRestController::assert_ceiling()` also refuses amounts above the role ceiling (`Capabilities::ceiling()`) for anyone but an administrator. The SPA already receives the ceiling as `ceiling()` and `isAdministrator()` in `apps/admin/src/lib/access.tsx`, but it doesn't receive the filtered maximum. `CustomersAdjustPointsDialog` and `AdjustPointsAmountField` read neither: `submitBlockers()` in `customers-adjust-points.form-state.ts` has no rule for a maximum. So an amount such as 20,000,000 passes the dialog, including its large-amount confirmation, and fails only after the admin clicks Add.

## Why it matters

The admin fills in the amount, ticks the large-amount confirmation and picks a reason, and only then gets an error toast. Nothing in the dialog says what the limit is. In bulk, every customer fails the same way, and the summary blames "points ceilings or permissions".

## Suggested fix

Send the filtered maximum to the SPA beside `ceiling` in `Capabilities::payload()` (or in the customer and preview responses). Add a `'overMax'` key to `submitBlockers()` that compares `amount` against the lower of that maximum and, for non-administrators, `ceiling()`. Show the limit under the amount field when the key applies. This was left out of the UX change because it needs a PHP change to send the maximum.

## Done when

- [ ] `Capabilities::payload()`, or the responses the dialog reads, carry the `yayloyalty_max_manual_adjustment` value.
- [ ] `submitBlockers()` returns a key for an amount above the effective maximum, which is the filtered maximum, or the role ceiling for a non-administrator when that is lower.
- [ ] Entering 20,000,000 in the dialog shows the limit under the amount and keeps the submit button disabled, checked in wp-admin.
