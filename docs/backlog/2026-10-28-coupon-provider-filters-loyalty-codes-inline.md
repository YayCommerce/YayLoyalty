# CouponProvider filters loyalty codes inline

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the yaytidy pass on the YayPricing conditions branch (`docs/plans/2026-10-28-yaypricing-conditions.md`, branch `paul/integration/yaypricing`).

## Today

`Engine\Points\Context::loyalty_codes( array $codes ): array` returns the loyalty codes among a coupon list. The YayPricing integration (`RewardAppliedCondition`, `LoyaltyCouponRerun`) uses it. `CouponProvider` still filters them inline, in `sync_cart()` (`array_filter( $applied, [ EarnContext::class, 'is_loyalty_code' ] )`) and in `sync_before_check()` (the same filter over `$cart->get_applied_coupons()`).

## Why it matters

"Which loyalty coupons are on the cart" then has two spellings. The inline filters skip the `strval` that `loyalty_codes()` applies, so a change to how codes are normalised has to be made in both.

## Suggested fix

In `CouponProvider::sync_cart()` and `::sync_before_check()`, replace each inline filter with `[] !== EarnContext::loyalty_codes( … )`. The other `is_loyalty_code()` callers test one code, or keep the codes that are not loyalty codes, so they stay as they are.

## Done when

- [ ] `CouponProvider::sync_cart()` uses `EarnContext::loyalty_codes()`.
- [ ] `CouponProvider::sync_before_check()` uses `EarnContext::loyalty_codes()`.
