---
id: P-4
title: Referral friend discount, hold and advocate payout
status: in-progress
from: BS-3
roadmap: R-4
design: UX-2
trello:
type: feat
branch: feat/p-4-referral-friend-discount-hold-and-advocate-pay
pr:
editions: [pro, lite]
created: 2026-11-17
---
# P-4 Referral friend discount, hold and advocate payout

Slice 2 of 4 for R-4, and it needs P-3 merged first: it builds on the referrals table, `ReferralSettings` and the `pending` rows. Lite gets four generic seams and no referral behaviour.

## Goal
A referred friend finds "Referral discount" already applied at checkout, classic and block, on a first order that meets the minimum. When that order completes, the referral turns **On hold** until `hold_until` (completion + the hold days, where 0 pays at once). When the hold ends, a daily job pays the advocate the snapshot's points and the referral turns **Rewarded**.
- A first order that doesn't qualify (under the minimum, another coupon when excluded, or the advocate's own email) turns it **Rejected**.
- A refund, cancel or trash during the hold turns it **Rejected** ("Order refunded during the hold") and pays nothing.
- A full clawback after payout turns it **Reversed**.
- A pending referral whose coupon expired with no paid order turns **Expired**.

Done means R-4's "discount applied automatically on a qualifying first order; once that order completes and the hold passes the advocate gets the points; self-referrals, orders under the minimum, and orders refunded during the hold earn nothing" passes the Verify list in Pro. Purchase, signup and review earning are unchanged in both editions.

## Non-goals
- The admin's Approve early and Reject buttons: P-5. P-4 provides `ReferralPayout::pay()` and the status writes P-5 calls.
- Showing statuses to the customer: P-6.
- A coupon for the advocate, referral emails, fraud flags and the cap (BL-8): later items.
- Counting the payout against the Pro daily earning limit: the merchant sets the referral amount, and the hold and Approvals already guard it (Decided).
- A real `shop_coupon` post per friend: the discount is a virtual coupon, as UX-2 says.

## Findings
1. **`award()` can't pay a flat amount without a campaign.** `PointsEngine::evaluate()` returns `REASON_NO_CAMPAIGNS` with no live campaign for the trigger (`includes/Engine/Points/PointsEngine.php:51-55`), and `Result::awarded()` requires a `Campaign` (`includes/Engine/Points/Result.php:63-71`). Referral points now come from settings, not a campaign. `grant_for_order()` already writes a campaign-less keyed EARN row, but only for an order's own customer (`includes/Engine/Earning/EarningService.php:247-305`). → T1 adds `EarningService::award_flat()`: gates, then one keyed EARN row with the context's order id, inside `Transaction::run`, through the same `LedgerService` (hard rule 3). It has no `PendingPoints` side effect, so the advocate's row never lands in the friend's order meta, which was the first draft's Finding 2. The earned email goes through `Emails::relay_grant()` after commit (`includes/Engine/Emails/Emails.php:86`).
2. **`ContextFactory::from_user()` builds a context with no order id** (`includes/Engine/Points/ContextFactory.php:72`), but the advocate's row must carry the friend's order id so `Reversal` finds it (`Reversal::full_order()` → `earn_rows_for_order`, `includes/Engine/Earning/Reversal.php:49`). → T1 gives `from_user()` an optional `?int $order_id`.
3. **No seam after a completed order's awards.** `OrderListener::on_awardable()` only filters its trigger list (`includes/Engine/Earning/Listeners/OrderListener.php:152-174`), and it's the only class that reads order statuses (`docs/knowledge/architecture.md`, Earning). → T1 fires `yayloyalty_order_awarded( WC_Order )` after the trigger loop.
4. **No seam for an order being refunded or cancelled.** `OrderReversalListener` handles a refund (`on_order_refunded`), a cancel, trash and the `refunded` status (`includes/Engine/Earning/Listeners/OrderReversalListener.php:50,108,124,139`). During a hold no referral earn row exists, so `yayloyalty_ledger_recorded` can't tell Pro the order was refunded. → T1 fires `yayloyalty_order_reversed( WC_Order )` once each of those paths has run.
5. **The friend's discount can't reuse `CouponProvider`.** It's built around points intents, rewards and the `yayloyalty-xxxxxxxx` pattern (`includes/Engine/Redemption/CouponProvider.php:63-71,165`), and it recognises its own codes by that pattern only (`Context::is_loyalty_code`, `includes/Engine/Points/Context.php:20,160`). → T2 is a separate Pro virtual coupon on the same WooCommerce filters (`woocommerce_get_shop_coupon_data`, `woocommerce_coupon_is_valid`, `woocommerce_cart_totals_coupon_label`) with the code `yl-referral-{id}`, which doesn't match the loyalty pattern. It's applied before `CouponProvider` (which runs at `PHP_INT_MAX`), so the points discount still comes last. Amounts convert from base with `CurrencyRate::from_base()` (`includes/Utils/CurrencyRate.php:52`).
6. **"First order"** is `ContextFactory::customer_facts( $user, $as_of, $exclude_order_id )->get_order_count() === 0` (`ContextFactory.php:147`), as the first draft found. The Pro `first_order` trigger still has no handler (BL-6, outside this plan).
7. **The daily job pattern** is `ExpiryRunner::register_daily()` / `keep_daily()` with async continuation in batches of 100 (`includes/Pro/Birthday/BirthdayRunner.php`). → T5 copies it as `yayloyalty_referral_run`.
8. **A partial refund after payout prorates the referral row** like any campaign-less row, through `Reversal::refund()` → `ClawbackMath::owed()` (`Reversal.php:67-80`). → The plan accepts this (Decided). The referral turns `reversed` only when its row is fully clawed back.
- **Differs:** `docs/knowledge/architecture.md` (Earning, Redemption, Expiry, the Pro section) and `helpers.md` (seams, `award_flat`) don't name the four seams, the referral coupon or the job. `integrations.md`'s Coupons and Action Scheduler rows don't mention them. → T6.

## Approach
- **Lite seams (T1), generic and inert without Pro:**
  - `EarningService::award_flat( string $trigger, Context $ctx, int $points, string $source, string $reference_type, int $reference_id ): Outcome`
    - The gate chain runs first. A blocking gate returns `skipped(reason)`.
    - It writes the EARN row with key `IdempotencyKey::for_earn( user, reference_type, reference_id, trigger )`, so a repeat is `duplicate`.
    - No award filter and no `PendingPoints`.
  - `ContextFactory::from_user( …, ?int $order_id = null )`.
  - `do_action( 'yayloyalty_order_awarded', $order )` at the end of `on_awardable()`.
  - `do_action( 'yayloyalty_order_reversed', $order )` after each reversal path in `OrderReversalListener`.
  - All of them are documented as "Internal seam, not a public API".
- **Friend coupon (Pro, T2):** `ReferralCoupon`.
  - **Data:** on `woocommerce_get_shop_coupon_data` for `yl-referral-{id}`, it returns `fixed_cart` or `percent` with the amount and `minimum_amount` from the referral's terms snapshot, in base currency like a merchant coupon, so YayCurrency converts them once (it skips only `yayloyalty-` codes). Because the coupon has data, WooCommerce enforces the minimum in both checkouts.
  - **Validity:** on `woocommerce_coupon_is_valid`, the coupon is valid only when all of these hold:
    - the current user is the referral's friend
    - the referral is `pending`
    - `coupon_expires_at` is in the future
    - the friend has no paid order yet
    - the program is enabled
  - **Auto-apply:** on `woocommerce_before_calculate_totals`, at a priority below `CouponProvider`, it adds the code for a logged-in friend with a pending referral when the cart meets the minimum. It silently removes the code when the coupon stops being valid, so checkout is never blocked.
  - **Label:** "Referral discount".
  - The Store API applies the same cart coupons, so block checkout shows the line with no JS.
- **Qualify at completion (Pro, T3):** `ReferralQualifier::check( Referral, WC_Order ): ?string` is pure and returns a reject reason or null. It checks, in order:
  - the friend has no paid order before this one
  - the order subtotal in base currency is at least `min_order`
  - with `exclude_other_coupons` on, the only coupons are this referral code and loyalty codes (`Context::loyalty_codes()`)
  - the order's billing email isn't the advocate's email

  `ReferralLifecycle::on_order_awarded()` finds the order customer's `pending` referral and runs the check. On a reason, the referral turns `rejected` with that reason: `not_qualifying`, or `self_referral` for the email match. Otherwise the referral turns `on_hold` with `first_order_id` and `hold_until = now + hold_days`. With `hold_days` 0 it pays at once.
- **Payout (Pro, T4):** `ReferralPayout::pay( Referral ): string` (the new status).
  - It calls `EarningService::build()->award_flat( CampaignTrigger::REFERRAL, ContextFactory::from_user( advocate, Dates::now(), [], first_order_id ), terms.advocate_points, LedgerSource::REFERRAL, ReferenceType::REFERRAL, id )`.
  - `awarded` or `duplicate` → `rewarded`, through a conditional `UPDATE … WHERE status = 'on_hold'`, so the job and P-5's Approve can't both move it.
  - The earned email relays after commit.
  - A gate skip (program paused, opted out, excluded role) leaves the referral `on_hold`, and the job retries daily. The merchant can reject it from P-5's Approvals.
- **Daily job (Pro, T5):** `ReferralRunner` on `yayloyalty_referral_run`:
  - pays `on_hold` referrals whose `hold_until ≤ now`
  - expires `pending` referrals whose `coupon_expires_at ≤ now` and whose friend has no paid order
  - works in batches of 100 with async continuation
  - runs whether or not the program is live, so held referrals still release after it's switched off (UX-2 States)
- **Reversal (Pro, T5):**
  - On `yayloyalty_order_reversed`, an `on_hold` referral with that `first_order_id` turns `rejected` with `refunded_during_hold`. No points moved, so nothing is clawed back.
  - On `yayloyalty_ledger_recorded`, a `refund_earn` row with source `referral` turns its referral (`reference_id`) from `rewarded` to `reversed` once the earn row is fully clawed back. This is idempotent, and the ledger stays the truth.
- **Repository writers (T3–T5)** go on `ReferralRepository`, each a conditional status update:
  - `find_pending_for_friend()`, `hold()`, `reject()`, `mark_rewarded()`, `mark_reversed()`
  - `due_for_release()`, `due_for_expiry()`, `expire()`
- **BL-9 default:** a guest order the friend claims later never qualifies. `on_order_awarded` only sees orders that complete while they belong to the friend's account, and a claimed order is already completed.
- **Editions:** Lite gets only T1's seams. The coupon, lifecycle, payout and job are in `includes/Pro/Referrals/`.
- **Compatibility:** HPOS (`wc_get_order` and order getters only), classic and block checkout, YayCurrency (base-currency terms, `CurrencyRate`), PHP 7.4.

## Tasks
- [x] T1 Lite seams: flat award, order id on a user context, order awarded and order reversed actions, and a referral row kept out of the friend's order and the advocate's daily total — `includes/Engine/Earning/EarningService.php`, `includes/Engine/Points/ContextFactory.php`, `includes/Engine/Earning/Listeners/OrderListener.php`, `includes/Engine/Earning/Listeners/OrderReversalListener.php`, `includes/Models/LedgerEntry.php`, `includes/Repositories/LedgerRepository.php`, `includes/Engine/Storefront/ShopperActivityRow.php`, `includes/Engine/Emails/PointsEarnedEmail.php` [risk: 3]
  - Reuse: `EarningService::grant_for_order()` and `blocked_reason()` (the campaign-less row and the gate chain), `LedgerService::earn()`, `IdempotencyKey::for_earn()`, `Transaction::run()`, `ContextFactory::from_user()`
  - Callers: `award_flat()` → `ReferralPayout::pay()` (T4); `from_user( …, $order_id )` → `ReferralPayout::pay()` (T4); action `yayloyalty_order_awarded` → `ReferralLifecycle::on_order_awarded()` (T3); action `yayloyalty_order_reversed` → `ReferralLifecycle::on_order_reversed()` (T5)
- [x] T2 Friend's virtual referral coupon, auto-applied in classic and block checkout — `includes/Pro/Referrals/ReferralCoupon.php` (new), `includes/Pro/Referrals/ReferralCartSync.php` (new, the auto-apply, split out to keep each file under 200 lines), `includes/Pro/Models/Referral.php` (the getters the coupon reads), `includes/Pro/Repositories/ReferralRepository.php`, `includes/Pro/YayLoyaltyPro.php` [risk: 3]
  - Reuse: `CouponProvider::virtual_coupon_data()` / `label()` / `sync_cart()` as the pattern (`includes/Engine/Redemption/CouponProvider.php`), `CurrencyRate::from_base()`, `ContextFactory::customer_facts()`, `ReferralSettings::get()` (P-3), `ListenerGuard::run()`
  - Callers: `ReferralCoupon::register()` → `YayLoyaltyPro::boot()`; `coupon_data()` → filter `woocommerce_get_shop_coupon_data`; `is_valid()` → filter `woocommerce_coupon_is_valid`; `sync_cart()` → action `woocommerce_before_calculate_totals`; `label()` → filter `woocommerce_cart_totals_coupon_label`; `ReferralRepository::find_pending_for_friend()` → `ReferralCoupon`, `ReferralLifecycle` (T3); `ReferralCoupon::code_for()` → `ReferralQualifier` (T3)
- [x] T3 Qualify the friend's first completed order and start the hold — `includes/Pro/Referrals/ReferralQualifier.php` (new), `includes/Pro/Referrals/ReferralLifecycle.php` (new), `includes/Pro/Enums/ReferralRejectReason.php` (new: UX-2's four admin reasons plus `self_referral`, `not_qualifying`, `refunded_during_hold`), `includes/Pro/Models/Referral.php` (the getters its writers read), `includes/Pro/Repositories/ReferralRepository.php`, `includes/Pro/YayLoyaltyPro.php` [risk: 3]
  - Reuse: `ContextFactory::customer_facts()`, `Context::loyalty_codes()`, `CurrencyRate::to_base()`, `PointsMath::snap()`, `StringEnumMethods`, `ListenerGuard::run()`, `Dates::offset()`
  - Callers: `ReferralLifecycle::register()` → `YayLoyaltyPro::boot()`; `on_order_awarded()` → action `yayloyalty_order_awarded` (T1); `ReferralQualifier::check()` → `ReferralLifecycle`; `ReferralRepository::hold()`, `reject()` → `ReferralLifecycle`, P-5's reject route; `hold_days === 0` → `ReferralPayout::pay()` (T4)
- [x] T4 Advocate payout — `includes/Pro/Referrals/ReferralPayout.php` (new), `includes/Pro/Referrals/ReferralPayoutAborted.php` (new), `includes/Pro/Referrals/ReferralLifecycle.php` (the 0-day hold), `includes/Pro/Repositories/ReferralRepository.php`, `includes/Pro/YayLoyaltyPro.php` [risk: 3]
  - Reuse: `EarningService::build()->award_flat()` (T1), `ContextFactory::from_user()` (T1), `Emails::relay_grant()`, `Transaction::after_commit()`, `PreviewLog::write_outcome()`, `LedgerSource::REFERRAL`, `ReferenceType::REFERRAL`, `CampaignTrigger::REFERRAL`
  - Callers: `ReferralPayout::pay()` → `ReferralLifecycle` (T3, hold 0), `ReferralRunner` (T5), P-5's approve route; `ReferralRepository::mark_rewarded()` → `ReferralPayout`
- [x] T5 Daily release and expiry job, and refund reversal — `includes/Pro/Referrals/ReferralRunner.php` (new), `includes/Pro/Referrals/ReferralLifecycle.php`, `includes/Pro/Repositories/ReferralRepository.php`, `includes/Pro/YayLoyaltyPro.php`, `includes/Engine/Expiry/ExpiryRunner.php` (`register_daily()` passes a continuation's arguments), `tests/php/integration/Pro/Referrals/ReferralTestCase.php` (new) [risk: 3]
  - Reuse: `BirthdayRunner` (shape), `ExpiryRunner::register_daily()` / `keep_daily()` / `GROUP`, `as_enqueue_async_action()`, action `yayloyalty_ledger_recorded` (`includes/Services/LedgerService.php:588`), `LedgerType::REFUND_EARN`, `LedgerRepository` clawback lookup for an earn row, `ContextFactory::customer_facts()`
  - Callers: `ReferralRunner::register()` → `YayLoyaltyPro::boot()`; `run()` → action `yayloyalty_referral_run`; `ReferralLifecycle::on_order_reversed()` → action `yayloyalty_order_reversed` (T1); `on_ledger_recorded()` → action `yayloyalty_ledger_recorded`; `ReferralRepository::due_for_release()`, `due_for_expiry()`, `expire()` → `ReferralRunner`; `mark_reversed()` → `ReferralLifecycle`
- [x] T6 Knowledge docs for the seams, the referral coupon and the job — `docs/knowledge/architecture.md`, `docs/knowledge/helpers.md`, `docs/knowledge/integrations.md`, `docs/knowledge/decisions.md`, `docs/knowledge/e2e.md` [risk: 1]
  - Reuse: n/a

**Tests:** [unit] ReferralQualifierTest (`tests/php/unit/Pro/Referrals/ReferralQualifierTest.php`) — a first order at or over the minimum qualifies, while one under it, a second order, another coupon with the exclusion on, or the advocate's billing email is rejected with the right reason, and a loyalty code isn't "another coupon"; [integration] ReferralPayoutTest (`tests/php/integration/Pro/Referrals/ReferralPayoutTest.php`) — a qualifying completion holds until `hold_until`, hold 0 pays at once, the job pays once with the friend's order id on the advocate's row and nothing added to the order's points meta, a re-run is a duplicate, a continuation through the hook resumes after its cursor, a referral rejected after it was loaded is not paid, a gate skip stays on hold, and an expired pending referral turns expired; [integration] ReferralReversalTest (`tests/php/integration/Pro/Referrals/ReferralReversalTest.php`) — a refund or cancel during the hold rejects with `refunded_during_hold` and writes no ledger row, a full refund after payout claws the row back and marks it reversed, and the order's purchase points reverse exactly as without Pro; [integration] ReferralCouponTest (`tests/php/integration/Pro/Referrals/ReferralCouponTest.php`) — the code is valid for the friend with a pending referral and invalid for another user, after `coupon_expires_at`, after a paid order, while a first order is still processing, and with the program off; [integration] AwardFlatTest (`tests/php/integration/Engine/Earning/AwardFlatTest.php`) — a gate blocks, a repeat is a duplicate, the row carries the context's order id, and a referral row adds nothing to the day's earning total or to the order's points

**E2E:** referral-checkout: a referred friend with a $60 cart sees "Referral discount −$10.00" in classic and block checkout, and with a $40 cart doesn't (new flow, tag `@P-4 @flow-referral-checkout`; extends the redeem-checkout fixtures).

## Verify
- `yayh test run unit --id=P-4` → OK (unit)
- `yayh test run integration --id=P-4` → OK (integration)
- `yayh test run integration` → OK (the existing earning and reversal suites still pass with the T1 seams)
- `yayh e2e run --grep @P-4` → passed (e2e)
- `yayh e2e run --grep @flow-earn-on-completed-order` → passed (purchase earning unchanged)
- `wp eval 'echo has_action( "yayloyalty_order_awarded" ) ? "hooked" : "none";'` → hooked
- `wp action-scheduler action list --hook=yayloyalty_referral_run --status=pending --format=count` → 1
- `vendor/bin/phpcs includes/Pro/Referrals includes/Engine/Earning/EarningService.php includes/Engine/Points/ContextFactory.php includes/Engine/Earning/Listeners/OrderListener.php includes/Engine/Earning/Listeners/OrderReversalListener.php` → exit 0
- `./scripts/run.sh release lite 1.0.0 --no-prompt` → passed (no `Referral` class named in the Lite zip)
- `unzip -l release/yayloyalty-lite-1.0.0.zip | grep -i referral` → exit 1
- Walk-through on the Pro e2e site with UX-2's values ($10 fixed, $50 minimum, 1,000 points, 14-day hold):
  - A referred friend registers. With a $60 cart, classic and block checkout show "Referral discount −$10.00". With $40 they don't (e2e).
  - Complete the order: `wp db query "SELECT status, first_order_id, hold_until FROM $(wp db prefix)yayloyalty_referrals ORDER BY id DESC LIMIT 1"` → `on_hold` with that order and a date 14 days out (integration).
  - `wp db query "UPDATE $(wp db prefix)yayloyalty_referrals SET hold_until=UTC_TIMESTAMP() WHERE status='on_hold'"`, then `wp action-scheduler run --hooks=yayloyalty_referral_run`. The status is `rewarded`, the advocate's balance rose 1,000, and the friend's order meta `_yayloyalty_points_earned` holds only the friend's purchase points (integration).
  - Refund that order fully: the advocate loses the 1,000 points and the status is `reversed` (integration).
- A second friend refunds during the hold: the status is `rejected`, `reject_reason` is `refunded_during_hold`, and `wp db query "SELECT COUNT(*) FROM $(wp db prefix)yayloyalty_ledger WHERE source='referral'"` doesn't rise (integration).
- Lite on a fresh site: a completed order earns its purchase points exactly as before, and there's no referral coupon line at checkout.

## Risks
- **Paying twice or the wrong person:** the ledger key `earn:{advocate}:referral:{id}:referral` and the conditional status update stop a double pay from the job and Approve together. The payout user is always the referral's advocate. Rollback: switch Program live off (no new holds or coupons). Held referrals still release, so to stop them, unschedule `yayloyalty_referral_run`. Points already paid can be adjusted by hand.
- **A friend's discount on an order that doesn't pay the advocate:** by design ("The friend keeps their discount either way", UX-2), with the exclusion rule and the hold as the guard.
- **Lite seam changes touch every award and reversal:** T1 only adds code paths. The existing integration suites and the earn e2e flow guard purchase, signup and review.
- **Status drifts from the ledger:** the `reversed` sync runs after commit. If it fails, the next clawback event fixes it, and the ledger stays the truth.
- **The daily job releases up to a day late** ("arrives 19 Sept" means that day's run). Accepted.
- Decided: a partial refund after payout prorates the referral points like any campaign-less row (Finding 8), and the referral turns `reversed` only on a full clawback.
- Decided: a gate that blocks the payout leaves the referral on hold and retries daily, rather than rejecting it.
- Decided (T1 review): the advocate's row carries the friend's order id only so a refund claws it back. `LedgerEntry::get_own_order_id()` is null for a referral row, so the advocate's history and earned email never show the friend's order, and the orders list's points column, "Missing points" and the grant check ignore it. `points_earned_on_site_day()` leaves referral rows out of the daily total, so a payout never holds back the advocate's own purchase points. `yayloyalty_order_awarded` fires from `OrderListener::route()`, not `on_awardable()`, so a guest order claimed later never qualifies (BL-9's default).
- Decided (T2): block checkout labels a coupon line with its code, so `ReferralCoupon::label_block_line()` adds a small inline `registerCheckoutFilters` on `wc-blocks-checkout` (Pro only), as `redeem-blocks.js` does for reward coupons. The minimum is checked against a subtotal read from the cart items, because `woocommerce_before_calculate_totals` runs before the cart's totals are fresh. A coupon the friend removes stays off for the session (BL-14). From the T2 review: "no paid order yet" also counts processing and on-hold orders, so a second order can't take the discount before the first completes; `woocommerce_check_cart_items` runs one totals pass first so a stale code is taken off silently; the code never joins an individual-use coupon; the minimum compares at price decimals.
- Decided (T3): `ReferralQualifier::check()` takes the terms and the facts it compares (prior orders, the order's subtotal in base currency as WooCommerce's minimum check reads it, the coupon codes, both emails) rather than a `WC_Order`, so it stays pure and unit-tested; `ReferralLifecycle` reads them from the order. Status writes go through one conditional `ReferralRepository::transition()`, so a move from the wrong status changes nothing. From the T3 review: the completion subtotal adds line tax only when the cart displays prices with tax (as the cart's minimum check did), both sides of the minimum round to base decimals, a completion with the program off or an order placed after `coupon_expires_at` leaves the referral pending (the job expires it).
- Decided (T4 review): `pay()` claims the referral first (`mark_rewarded()`, conditional on `on_hold`) and writes the ledger row in the same transaction, so a referral the refund path, Reject or another payout already moved is never paid; a gate skip rolls the claim back (`ReferralPayoutAborted`). An advocate whose account was deleted is left on hold and logged, so the merchant can reject it in P-5's Approvals. While the whole loyalty program is paused, `ProgramGate` skips the award, so held referrals wait (the referral program's own switch doesn't stop them).
- Decided (T5): one `ReferralRepository::due( status, date_column, after_id, limit )` serves both phases instead of `due_for_release()` / `due_for_expiry()`. The job runs release then expiry, continuing a full batch asynchronously. A pending referral doesn't expire while the friend has a processing or on-hold order placed inside the window, so that order can still qualify when it completes; a completed order doesn't hold it back. The reversed sync finds the advocate's referral row on the clawed-back order and compares `sum_refunded_for()` with its points. The tests share `tests/php/integration/Pro/Referrals/ReferralTestCase.php`. From the T5 review: `ExpiryRunner::register_daily()` takes `$accepted_args`, so the job's continuation keeps its phase and cursor, and "clawed back in full" counts the referral lot's expired points as `refund_earn()` does. A referral's status only moves forward: a deleted refund or an un-cancelled order doesn't bring back `rewarded` or `on_hold` (BL-13).
- Decided: any refund during the hold, partial or full, rejects the referral, matching UX-2's help text ("A refund during the hold cancels the reward").
- Open: BL-9 Does a guest order the friend later claims from My Points count as their first order? (default: no)
- Open: BL-8 Should one advocate's paid referrals be capped? (default: no cap)
