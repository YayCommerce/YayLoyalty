---
id: BL-23
title: Removing all coupons through the Store API puts the Pro referral coupon back
kind: deferred
status: done
from: B-2
affects: []
default: 
decision: fix/b-2 branch (B-2)
created: 2026-11-20
settled: 2026-11-20
---
# BL-23 Removing all coupons through the Store API puts the Pro referral coupon back

`ReferralCartSync::sync()` (`includes/Pro/Referrals/ReferralCartSync.php`, on `woocommerce_before_calculate_totals` at `PHP_INT_MAX - 1`) re-adds a referred friend's coupon on every totals run while `wanted_code()` returns one. `DELETE /wc/store/v1/cart/coupons` empties the list through `WC_Cart::remove_coupons()`, and WooCommerce 11.3.1 recalculates in the same request, so the referral discount comes straight back, the same way the points discount did in B-2.

B-2 fixed only the points redemption, which is what the owner asked for. Whether a friend's referral discount should leave on "remove all coupons" is a product call: it is a one-time welcome discount the shopper didn't pick, unlike points. If it should, reuse the B-2 flag in `StoreApiAdapter` (or move it somewhere both can read) and have the referral sync stand down for that request.
