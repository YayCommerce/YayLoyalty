# Purchase earning still carries code for base options it can no longer take

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** Misleading-names review, 2026-10-30, branch trace/refactor/misleading-names (left out of the rename because it changes behaviour).

## Today

`Models\Campaign::FIXED_PURCHASE_CONFIG` sets `base` to `after_discounts` and `exclude_coupon_orders` to false. `Campaign::with_fixed_config()` lays it over every purchase campaign's config, in the constructor and in `set_config()`, so no stored or sent value survives. The code for the other values remains:

- `Engine\Points\BaseCalculator::compute()` keeps a `$before_discounts` branch that adds `get_subtotal()` instead of `get_total()`.
- `Engine\Points\Triggers\PurchaseHandler` returns false from its eligibility check when `exclude_coupon_orders` is set and `Context::get_real_coupon_codes()` is not empty. That gate is the only production reader of `get_real_coupon_codes()`; `tests/unit/Engine/Points/PointsEngineEvaluateTest.php` tests both.
- `Engine\Points\Context::get_loyalty_discount()` has no reader. `Engine\Points\ContextFactory` still sums the loyalty discount for it, once for an order and once for a cart.
- `Campaign::config_schema()` still accepts `before_discounts` for `base`, and `exclude_coupon_orders` and `include_shipping` as booleans. Nothing in `Engine\Points` reads `include_shipping`.
- In `apps/admin/src/lib/schema/campaign.schema.ts`, `PurchaseConfig` types `base` as `'after_discounts' | 'before_discounts'` and keeps `exclude_coupon_orders`, though no admin control edits either.

## Why it matters

A reader of `BaseCalculator` or `PurchaseHandler` sees two earning bases and a coupon gate, and has to find `FIXED_PURCHASE_CONFIG` to learn that purchases always earn after every discount. A change to the base made in the dead branch does nothing, and the unit tests keep a setting alive that the model throws away.

## Suggested fix

Delete the `before_discounts` branch, the `exclude_coupon_orders` gate and its test, `get_real_coupon_codes()`, and `get_loyalty_discount()` with the sums `ContextFactory` keeps for it. Narrow `config_schema()`'s `base` to `after_discounts` or drop the key, and the same for `exclude_coupon_orders`; decide whether `include_shipping` stays for round trips, as the SPA's comment on it says, or goes. Narrow `PurchaseConfig` to match. Left out of the rename because narrowing the schema makes a request that sends the old values fail instead of being overwritten.

## Done when

- [ ] `BaseCalculator::compute()` does not read `config['base']`.
- [ ] `PurchaseHandler` does not read `exclude_coupon_orders`.
- [ ] `Context` has no `get_real_coupon_codes()` method.
- [ ] `Context` has no `get_loyalty_discount()` method, and `ContextFactory` no longer sums a loyalty discount.
- [ ] `Campaign::config_schema()`'s purchase `base` no longer accepts `before_discounts`.
- [ ] `PurchaseConfig` in `apps/admin/src/lib/schema/campaign.schema.ts` does not type `base` as `before_discounts` and has no `exclude_coupon_orders`.
- [ ] `git grep -n include_shipping -- includes apps/admin/src` shows only a schema entry whose comment says why it is kept, or nothing.
- [ ] `composer test` passes.
