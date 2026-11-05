# Before an order exists, a campaign's conditions are true, false or unknown, and an unknown one can only lower a promise

**Purpose:** why the storefront evaluates campaign conditions for a known shopper, and why a condition it can't tell yet lowers what it promises.

**Update when:** never edit it. Supersede it with a new record if the storefront stops evaluating conditions, or an unknown condition stops lowering a promise.

## Status

Proposed, 2026-11-05.

## Context

- A campaign's conditions decide what an order pays, but the storefront speaks before the order exists. It knows the shopper and the product. It doesn't know the payment method or the address.
- "Ways to earn" never evaluated conditions, on the rule that its rows are the same for any shopper. A member who met a campaign's conditions saw the rate for everyone.
- The product page evaluated them as if an order existed, so every condition on the order failed. A campaign that would catch the order was skipped and the one below it promised. A campaign paying 0.5 on a category above one paying 1 showed 1, and the order paid 0.5.
- Options turned down:
  - **Treating unknown as matching.** It overpromises whenever the condition fails at order time.
  - **Treating unknown as not matching.** That is the bug above.
  - **Keeping adverts free of conditions.** A member who qualifies would never see the rate they earn.
  - **A separate estimate method beside `evaluate()`.** Every preview caller would change, and `EarningService::preview()` could disagree with the page.

## Decision

- With no order yet, `ConditionsEvaluator::verdict()` calls a campaign's conditions true, false, or null while one needs the order. A failed condition beats an unknown one.
- `PointsEngine` walks campaigns by priority on that verdict. True ends the walk, false skips the campaign, and null lowers the promise and walks on. With no true there is no promise.
- A Context says which walk it needs: an estimate (`Context::as_estimate()`) for a product or a cart, an advert with a user for a member, and an advert with no user for any shopper. The last never calls the evaluator: no shopper means no answer, so every conditional campaign stays unknown.
- An event Context never gets a verdict. What an order pays doesn't depend on any of this.
- A guest fails a condition about a customer, because a guest order fails it too.

## Consequences

- The storefront can't promise more than the order pays, and a member sees the rate their conditions earn.
- It can promise less: a campaign that pays more behind an unknown condition isn't in the figure until the order settles it.
- A member's "Ways to earn" differs from a guest's, so it can't be shared. Guest output stays the same for every guest.
- A condition about order history now reads the customer's orders on storefront pages, at most once per request, and only while a campaign holds such a condition. That read loads every paid order the customer has.
- An evaluator has a second method to answer, `verdict()`.
