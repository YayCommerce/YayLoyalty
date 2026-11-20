=== YayLoyalty — Points and Rewards for WooCommerce ===
Contributors: yaycommerce
Tags: loyalty, points, rewards, woocommerce, discount
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Loyalty program plugin for WooCommerce: customers earn points and spend them as a discount.

== Description ==

YayLoyalty adds a points and rewards program to your WooCommerce store. Customers earn points when they shop, sign up and review your products, then spend them on money off a later order.

= Earn points =

* **Purchases**: points for every order, at a rate you set (for example 1 point per $1). Choose whether the rate counts the subtotal or the total and how fractions round.
* **Sign-ups**: a one-time welcome bonus when a customer creates an account.
* **Product reviews**: points for an approved review.
* Points from an order are added when the order completes, and taken back automatically when it is refunded, cancelled or trashed.

= Spend points =

* **Flexible discount**: customers choose how many points to use on the cart or checkout page and get the matching amount off. You set the value of a point and the minimum to redeem.
* Works with both the classic and the block-based Cart and Checkout.

= Show customers their points =

* A **My Points** tab in My Account with the balance, ways to earn and redeem, and the points history, which customers can export as CSV.
* A floating **rewards launcher** on your store, styled from the Design settings, with a version for guests that invites them to join.
* A **"You'll earn X points"** message on product pages and the points earned on the order-received page.
* **Blocks and shortcodes** to place the points balance, "Ways to earn" and "Ways to redeem" anywhere: `[yayloyalty_balance]`, `[yayloyalty_ways_to_earn]` and `[yayloyalty_ways_to_redeem]`, plus `[yayloyalty_redeem]` for the redeem card.

= Manage the program =

* A dashboard with points issued and redeemed, and the value of the points customers still hold.
* A customer list with each customer's balance and ledger, and manual point adjustments with a note.
* Points expiry after a period of inactivity, with a reminder email before points expire.
* Four WooCommerce emails: points earned, points adjusted, points expiring soon and points expired. They can be customized with YayMail.
* Compatible with YayCurrency (multi-currency) and YayPricing, and with WooCommerce High-Performance Order Storage.

= YayLoyalty Pro =

YayLoyalty Pro is a separate plugin that replaces this one and keeps all of its data. It adds a referral program (a friend's first-order discount, points for the referrer after a hold, approvals, and a Referrals tab in My Account with each customer's link), birthday, first-order, order-count and spend-milestone campaigns, campaign conditions, coupon, free-shipping and free-product rewards, batch expiry, daily earning limits, customer segments and more emails. Everything in this plugin works without it.

== Installation ==

1. Install and activate WooCommerce.
2. In WordPress, go to Plugins > Add New, search for "YayLoyalty" and click Install, then Activate. Or upload the plugin folder to `/wp-content/plugins/` and activate it on the Plugins screen.
3. Go to YayCommerce > YayLoyalty. The setup wizard names your points, sets how customers earn and spend them, and styles the rewards launcher.
4. Fine-tune earning under Campaigns and the discount under Rewards at any time.

== Frequently Asked Questions ==

= Does YayLoyalty need WooCommerce? =

Yes. YayLoyalty is a WooCommerce extension and does nothing without it.

= Does it work with the block-based Cart and Checkout? =

Yes. The redeem card works in the classic shortcode Cart and Checkout and in the Cart and Checkout blocks.

= Does it work with Elementor, Bricks or Divi? =

Yes. Each builder gets four YayLoyalty elements: Points balance, Ways to earn, Ways to redeem and Redeem card. Drag one onto a page and it shows exactly what its shortcode shows. In the builder, an element with nothing to show for you (for example the Redeem card with an empty cart) displays a short note in its place. Divi is supported through its Divi 4 modules.

= What happens to points when an order is refunded? =

The points earned from that order are taken back, and points the customer spent on it are returned. A partial refund takes back the matching share.

= Where do customers see their points? =

In the My Points tab of My Account, in the rewards launcher on your store, on product pages, at checkout and on the order-received page.

= Can I change what points are called? =

Yes. In Settings you can rename points, for example to "coins" or "stars", in the singular and the plural.

= Does YayLoyalty send data to an external service? =

No. YayLoyalty stores everything in your own database and makes no requests to outside servers.

== Screenshots ==

1. The dashboard: points issued, redeemed and outstanding.
2. Campaigns: how customers earn points.
3. The redeem card at checkout.
4. The My Points tab in My Account.
5. The rewards launcher on the storefront.

== Source code ==

The admin app, the block editor script and the storefront stylesheet are compiled with Vite. Their source is public at https://github.com/YayCommerce/YayLoyalty:

* `assets/dist/admin/` is built from `apps/admin/src/`
* `assets/dist/blocks/` is built from `apps/admin/src/blocks/`
* `assets/dist/storefront/` is built from `apps/admin/src/storefront.css`

To build them: install Node 22 and pnpm 11, then in the repository root run `pnpm install --ignore-scripts` and `./scripts/run.sh build lite`. The PHP dependencies already ship in the plugin, in `vendor/` and `vendor-prefixed/`.

== Changelog ==

= 1.0.0 =
* Initial release.
* Removing a loyalty discount from an order now asks whether to return the customer's points.
