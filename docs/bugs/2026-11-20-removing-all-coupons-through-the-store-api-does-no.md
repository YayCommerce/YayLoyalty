---
id: B-2
title: Removing all coupons through the Store API does not cancel the points redemption
status: fixed
severity: medium
editions: [pro, lite]
found_in: 1.0.0
fixed_in:
branch: fix/b-2-removing-all-coupons-through-the-store-api-doe
pr:
created: 2026-11-20
---
# B-2 Removing all coupons through the Store API does not cancel the points redemption

## Symptoms
Found while checking the plugin against WooCommerce 11.3.1. A shopper redeems points, then the cart's coupons are all removed through the Store API (`DELETE /wc/store/v1/cart/coupons`). The response is `[]`, but the next `GET /wc/store/v1/cart` still shows the loyalty coupon and its discount. The merchant coupons are gone and the points discount stays.

## Environment
Plugin 1.0.0, both editions. WordPress 7.2 beta, WooCommerce 11.3.1, Brandy theme, Store API (block cart and checkout, or a headless client). Store currency VND, flexible discount `auto_max`, 100 points per currency unit.

## Repro steps
1. Log in as a customer with 500 points. Add a product (price 100) to the cart.
2. `POST /wc/store/v1/cart/apply-coupon` `{code: "qa-merchant10"}` (10% merchant coupon): total 90.
3. `POST /wc/store/v1/cart/extensions` `{namespace: "yayloyalty-redeem", data: {points: 100}}`: coupons `qa-merchant10` and `yayloyalty-huy9q01g`, total 85.
4. `DELETE /wc/store/v1/cart/coupons`: 200, body `[]`.
5. `GET /wc/store/v1/cart`: coupon `yayloyalty-huy9q01g` still applied, discount 5, total 95. `extensions.yayloyalty.requested` is still 100.

## Root cause
The points the shopper asked to spend, and the rewards they picked, live in the session (`SessionIntent`). `CouponProvider::sync_cart()` (`includes/Engine/Redemption/CouponProvider.php:241`) re-adds a loyalty code on every totals run while that intent is set. The only thing that clears the intent when a coupon goes is `CouponProvider::on_removed()` (`:290`), on `woocommerce_removed_coupon` (`:67`).

`DELETE /cart/coupons` calls `WC_Cart::remove_coupons()` (WooCommerce `includes/class-wc-cart.php:2287-2292`). It empties the applied list directly and fires no hook, so `on_removed()` never runs and the intent survives. Since WooCommerce 11.3.1 the route then calls `calculate_totals()` (`src/StoreApi/Routes/V1/CartCoupons.php:131-133`, changelog "Recalculate cart totals after removing all coupons through the Store API"), so `sync_cart()` puts the loyalty code back in the same request, while the route still answers `[]`. Before 11.3.1 the code came back on the next totals run instead, so the redemption was never cancelled there either.

Present since redemptions landed in 4bf722b (2026-10-17, #128), so it is in 1.0.0.

Confidence: high. Reproduced in the browser through the Store API, and `remove_coupons()` has no hook to listen to.

## Fix options
1. **Recommended.** In `StoreApiAdapter`, filter `rest_request_before_callbacks` and call `SessionIntent::clear()` when the request is `DELETE` on the Store API `cart/coupons` route (with or without `/v1`). The intent is gone before WooCommerce removes the coupons and recalculates, so `sync_cart()` takes the loyalty code off with the rest. One file, Store API only, the same place the adapter already takes the intent from the Store API.
2. Detect "every coupon vanished at once" in `sync_cart()` by remembering the last applied list in the session. More state, and it would also fire for `WC_Cart::empty_cart()` and WooCommerce removing coupons when coupons are disabled, which the owner did not ask for.

## Regression test proposal
[integration] `StoreApiRemoveAllCouponsTest::test_removing_all_coupons_cancels_the_redemption` in `tests/php/integration/Engine/Redemption/` (`@group B-2`). Set the intent (points and a picked reward), dispatch `DELETE /wc/store/v1/cart/coupons` through `rest_do_request()`, and assert the intent is cleared, plus that a `DELETE` on a single coupon route and a `GET` leave it alone. Integration, because the cause is a REST route and the WooCommerce session.

## Fix
`StoreApiAdapter` (`includes/Engine/Redemption/Adapters/StoreApiAdapter.php`) now cancels the redemption when the Store API removes every coupon:
- `watch_remove_all_coupons()` on `rest_request_before_callbacks` flags a `DELETE` on `/wc/store(/v1)/cart/coupons`. It only flags: the route loads the cart session and checks the nonce inside its own callback.
- `cancel_on_remove_all_coupons()` on `woocommerce_before_calculate_totals` at `PHP_INT_MAX - 2` (before the Pro referral sync and `CouponProvider::sync_cart()`) clears `SessionIntent` on the request's first totals run with no coupon left. On WooCommerce 11.3.1+ that is the route's own recalculation after `remove_coupons()`, so `sync_cart()` no longer puts the loyalty code back and the saved cart has no points discount.
- `cancel_after_remove_all_coupons()` on `rest_request_after_callbacks` covers WooCommerce before 11.3.1, where the route doesn't recalculate: it clears the intent when the route succeeded. When the route failed (bad nonce), it restores the points and rewards a totals run cleared while the cart loaded (found by yay:bug-reviewer).

Removing one coupon still goes through `CouponProvider::on_removed()`, unchanged. The Pro referral coupon still comes back on "remove all"; that is BL-23, not asked for here.

Verified:
- `tests/php/integration/Engine/Redemption/StoreApiRemoveAllCouponsTest.php` (`@group B-2`, WC 11.2.1 wp-env): remove-all clears the intent, a refused remove-all (nonce check on) keeps it, removing one merchant coupon and reading the cart keep it. The remove-all test fails without the fix and passes with it.
- `yayh test run integration`: 81 OK. `yayh test run unit`: 150 OK. `composer test`: 70 OK. `php -l` and phpcs on the adapter: clean.
- WooCommerce 11.3.1 (local site, file copied in and restored after), Store API in the browser: redeem 100 points with `qa-merchant10` (total 85); `DELETE /cart/coupons` with a bad nonce is 403 and the cart keeps both coupons and `requested: 100`; with the right nonce, the next `GET /cart` has no coupons, total 100, `requested: 0`. Removing only `qa-merchant10` keeps the points discount.
- Reviews: yay:code-reviewer (2 docblock findings, fixed); yay:bug-reviewer (1 risk, the bad-nonce case, fixed; the case-sensitive route regex now has `i`).
