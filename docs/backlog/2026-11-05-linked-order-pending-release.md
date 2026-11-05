# A guest order linked before it completes lowers the customer's pending points

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** design of the guest order points on 2026-11-05 (`docs/specs/2026-11-05-guest-order-points-design.md`). Kept out of that change because it already happens today.

## Today

`PendingPoints::stage()` adds to `customers.pending_points` only for a registered customer, so a guest's staged order holds its figure in order meta alone. `PendingPoints::release()` subtracts from the order's customer at release time. An order staged as a guest, then linked to an account before it completes, subtracts a figure that was never added.

## Why it matters

The customer's cached pending points drop by the order's figure, floored at 0, so another open order's pending points disappear from the total. No balance or ledger row is affected.

## Suggested fix

Record on the order whether staging reached the customer's cached sum, and subtract at release only when it did.

## Done when

- [ ] An order staged as a guest and linked before completing leaves `customers.pending_points` unchanged when it is released.
- [ ] A registered customer's order still adds to and subtracts from `customers.pending_points` as today.
