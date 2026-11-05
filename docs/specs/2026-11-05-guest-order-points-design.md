# Guest Order Points Design

**Status:** accepted 2026-11-05
**Related:** None

## Goal

A shopper whose completed guest order is linked to their account gets that order's points, exactly once, without the merchant doing anything.

## Context

- Only `completed` awards, in `includes/Engine/Earning/Listeners/OrderListener.php`. At that moment a guest order has `customer_id = 0`, so `ProgramGate` answers `no_customer` and nothing retries.
- Registering or logging in does not link guest orders. WooCommerce links them only through `wc_update_new_customer_past_orders()`: when a customer verifies their email or resets their password (`woocommerce_customer_email_verified`, WooCommerce 11.0 and later), or when an admin presses the link action in the Customers report.
- Linking changes no status, so `OrderListener` never sees it. The helper fires `woocommerce_update_new_customer_past_order` per order after saving it.
- A linked order already shows as missing points (`EarningService::is_missing_points()`, `Engine\Admin\OrderMissingPoints`), so an admin can grant it by hand.
- `mark_verified()` returns early for a customer already verified, so a verified customer's later guest order is never linked by WooCommerce.

## Scope

**In:**
- Award a completed order when WooCommerce links it to an account, by any of its routes.
- On login, link the guest orders of a customer whose email is verified.
- Claw back the share of refunds made before the link.

**Out:**
- Matching billing email at registration without verification: anyone could register a stranger's email and take their points.
- A time limit on how old a linked order may be.
- Bypassing the Pro daily limit for these awards.
- An admin changing the customer on the order screen: that order stays Missing for a manual grant.
- A setting, schema change or SPA change.

## Acceptance criteria

1. Given a completed guest order worth 25 points, when WooCommerce links it to a customer, then the customer has one earn row of 25 points and a balance of 25.
2. Given that order, when the link runs again, then no second row is written.
3. Given a completed guest order partly refunded before the link, when it is linked, then a `refund_earn` row takes back the refunded share.
4. Given an order not yet completed, when it is linked, then no earn row is written, and completing it awards as usual.
5. Given a customer verified on WooCommerce 11.0 or later with a later guest order on the same email, when they log in, then that order is linked and, if completed, awarded.
6. Given a customer not verified, when they log in, then no order is linked.
7. Given WooCommerce below 11.0, when a customer logs in, then nothing happens and login is unaffected.
8. Given the Pro daily limit is reached, when an order is linked, then the award is cut as any other award, and a wholly refused order stays Missing.
9. End-to-end verification: on the local site, buy as a guest with email A and complete the order, register with email A (balance 0), verify from My Account → Orders (balance rises by the order's points). Log out, buy again as a guest with email A, complete it, log in (balance rises again). Log out and in once more (balance unchanged).

## Constraints

- Product PHP runs on PHP 7.4.
- Lite code: nothing here names Pro.
- Only `LedgerService` writes ledger rows; this goes through `EarningService::award()` and `Reversal`.
- WooCommerce floor stays 10.0, so the verification service is checked with `class_exists()`.
- A failure must never break login or the link: both handlers run in `ListenerGuard`.

## Approach

**Chosen:** award on WooCommerce's own link, through a new listener.

**Architecture:** the listener hooks the action WooCommerce fires per linked order and hands an order that is missing points to `OrderListener::on_awardable()`, so scoring, notes, emails and pending release match a normal completion. `ContextFactory::from_order()` scores at the order's creation date. Refunds made before the link wrote nothing, so they are settled right after the award, as the admin grant already does.

**Components:**
- `includes/Engine/Earning/Listeners/GuestOrderLinkListener.php` — both hooks.
- `Reversal::settle_earlier_refunds()` — the clawback moved out of `OrderPointsRestController`, now shared.
- `OrderReversalListener::settle_earlier_refunds()` — runs it in a transaction and notes the order.

**Data and storage:** None.

**Integration points:** `woocommerce_update_new_customer_past_order`, `wp_login`, `wc_update_new_customer_past_orders()`, and `Automattic\WooCommerce\Internal\CustomerEmailVerification\EmailVerificationService::is_verified()`.

**UI:** None.

**Edge cases and failure modes:**
- `refunded`, `cancelled` or trashed: not completed, so not awarded.
- An order that already has an earn row: `is_missing_points()` stops it. The idempotency key alone would not, because it holds the user id.
- Opted out, excluded role or paused program: `award()` skips, and the order shows as not eligible.
- Several orders at once: one Points earned email each, under `throttle_per_week`. A throttled email costs no points.
- A partial refund: the email reports the gross award, then the ledger takes back the refunded share.
- Expiry counts from the ledger write, not from the order date.
- Program paused at login: nothing is linked.

**Decision record:** None.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Match billing email at registration or login | Unverified email lets anyone claim, and read, a stranger's orders |
| YayLoyalty's own verification email | Duplicates what WooCommerce 11.0 ships |
| Admin-only manual grant | Does not let a customer receive points on their own |

## Rollout

No migration. Orders linked before this ships stay Missing for a manual grant. Campaigns with no date range pay for old orders once they are linked, which belongs in the release note. On login the plugin depends on an Internal WooCommerce class, so recheck it on each WooCommerce major.

## Open questions

None.
