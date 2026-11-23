---
id: BS-3
title: Referral program
status: decided
from: R-4
decision: B. Referrals table with a status per referral
created: 2026-11-17
---
# BS-3 Referral program

## Problem
Merchants want customers to bring in friends (R-4, requested 2026-11-13 for 1.1.0, Pro). Today nothing does this. The `referral` trigger, the `referral` ledger source and reference type, and `customers.referral_code` are all declared, but no build offers them (`includes/Enums/CampaignTrigger.php:28`, `includes/Pro/YayLoyaltyPro.php:51`).

Acceptance (R-4): a customer copies a referral link from My Points. When a new customer signs up through it and completes a first order, both get the points the merchant set. Self-referrals and refunded orders earn nothing.

## Constraints
- **Editions:** Pro only. Lite has no referral feature at all: no table, setting, card or page. Lite keeps recognising the stored values (`all_known()`, hard rule 6), and Pro attaches only through filters (hard rule 1).
- **Ledger:** only `LedgerService` writes points, through `EarningService::award()`, inside `Transaction::run`, with an `IdempotencyKey` (hard rule 3). One `award()` pays one user: the context's user (`EarningService.php:162`). A referral pays two people, which the key already allows for: "it holds the user, because a referral order pays two people" (`IdempotencyKey.php:14`).
- **When it pays:** only a `completed` order awards (`OrderListener` → `on_awardable`, `yayloyalty_order_award_triggers` filter at `OrderListener.php:161`, which Pro can append to). The payout must be hooked before `EarningService::build()` (hard rule 4).
- **Refunds:** `Reversal` claws back every `earn` row whose `order_id` is the order, whoever earned it (`LedgerRepository::earn_rows_for_order`, `Reversal.php:49,67`). Any referral row written with the friend's order id is reversed on cancel, trash, refund or `refunded` for free. A partial refund claws back pro rata (`ClawbackMath::owed`).
- **"First order":** no first-order handler exists to reuse. `first_order`, `order_count` and `spend_milestone` are offered by Pro (`YayLoyaltyPro.php:57-60`), but no handler class for them is in `includes/`. `ContextFactory::customer_facts( $user, $as_of, $exclude_order_id )` gives the count of paid orders before this one, which is what "first" needs.
- **Signup:** `SignupListener::is_self_registration()` already separates a visitor signing up from admin, import, CLI and cron accounts. A referral must use the same rule.
- **Gates:** `ProgramGate` (paused, opted out, excluded role) runs on the context's user, so an opted-out referrer earns nothing without extra code.
- **Storefront:** My Account `my-points` endpoint (`Storefront/AccountMyPoints.php`), both checkouts (classic and Store API), HPOS (order meta through `wc_get_order`). The PHP floor is 7.4.
- **Must not change:** existing purchase and signup earning, and the ledger key format.

## Options
### A. Referral campaign with attribution kept on the customer (no new table)
- **How:**
  - A Pro `referral` campaign holds two flat amounts: points for the referrer and points for the friend.
  - Each customer's code is generated lazily into `customers.referral_code`, which is already declared and unique.
  - My Points gets a "Refer a friend" card with the link (`?ref=CODE`) and a copy button.
  - Landing on the link sets a first-party cookie (e.g. 30 days).
  - On a self-registration `user_register` (same rule as `SignupListener`), Pro records the referrer in user meta and logs an activity event (`customer_meta`). Self-referral is refused there: the code's owner is the new user, or is logged in.
  - At completion, Pro appends `referral` to `yayloyalty_order_award_triggers` when the customer has a referrer and `customer_facts(...)->get_order_count() === 0`.
  - A `ReferralHandler` (flat points, like `BirthdayHandler`) is awarded twice for the same order, once with the friend's context and once with the referrer's.
  - Keys: `earn:{friend}:order:{id}:referral` and `earn:{referrer}:order:{id}:referral`.
  - `Reversal` already claws both back on refund or cancel.
- **Pros:**
  - Reuses the whole earning pipeline: gates, idempotency, emails, reversal, the daily limit and the activity log.
  - No schema change.
  - Downgrade-safe: stored rows are already recognised.
  - Matches the acceptance exactly.
- **Cons:**
  - There's no record of a "referral" as a thing. Pending referrals (signed up, no order yet) live only in user meta.
  - The admin sees referral earnings in the ledger (source `referral`) but has no Referrals list or reject button.
  - Fraud review is limited to the self-referral check.
  - `ReferenceType::REFERRAL` stays unused.
- **Risks:**
  - A cookie lost between the click and signup loses the attribution: another browser, or consent tools blocking it.
  - Partial-refund proration on a flat amount may surprise merchants (open question).
- **Edition split:** all new behaviour in `includes/Pro/`. Lite unchanged.
- **Size:** M.

### B. A referrals table with a status per referral
- **How:**
  - Attribution, the link and the code are the same as in A.
  - A new table `yayloyalty_referrals`: referrer, referee, code, status (`pending`, `rewarded`, `rejected`, `reversed`), reject reason, qualifying order id, timestamps. It's added by Pro only, and is what `ReferenceType::REFERRAL` was reserved for (`ReferenceType.php:28`).
  - Signup inserts a `pending` row.
  - The first completed order moves it to `rewarded` and writes the two earn rows with reference `referral:{id}` and the order id, so `Reversal` still claws them back. A reversal marks the row `reversed`.
  - Pro adds an admin "Referrals" page (REST + SPA list with filters and a reject action). My Points shows "friends joined / friends ordered".
- **Pros:**
  - Every referral is auditable.
  - The merchant can reject a suspicious one before it pays.
  - Reports (top referrers, conversion) and later rules (cap per referrer, tiered rewards) have a home.
- **Cons:**
  - A new table, repository, REST controller and SPA page: roughly twice A's work.
  - A new Pro table and its install path (idempotent, hard rule 5).
  - More surface to test, in a sprint already holding nothing else.
- **Risks:** the status must stay consistent with the ledger. A reversed order must move the row in the same transaction as the clawback, or the two disagree.
- **Edition split:** all of it in Pro, the table included.
- **Size:** L.

### C. Attribution on the order, through a referral code at checkout
- **How:**
  - The link puts the code into the WooCommerce session, and checkout shows it applied. The friend can also type a code.
  - The code is stored as order meta at checkout, through both adapters: classic `woocommerce_checkout_order_processed` and Store API.
  - At completion, the order pays both sides if it's the customer's first paid order and the customer isn't the code's owner (same user, same billing email).
- **Pros:**
  - Survives a lost cookie and a different browser.
  - Catches friends who check out as a guest and register at checkout.
  - The "code at checkout" pattern shoppers know from other referral programs.
- **Cons:**
  - Attribution moves from signup to the order, which bends the acceptance ("signs up through it").
  - Touches both checkouts and the Store API extension data: the riskiest WooCommerce surface in the plugin.
  - Friends usually expect a discount at checkout here, not points, which is a product change from R-4.
- **Risks:** a checkout regression on either flow.
- **Edition split:** Pro, but checkout hooks need a Lite seam.
- **Size:** L.

## Recommendation
**A. Referral campaign with attribution kept on the customer.**
- It meets every line of the acceptance and adds no table.
- It runs both payouts through the same `award()` path every other trigger uses. Gates, idempotency, emails, the daily limit and refund clawback come for free.
- Clawback is the part that's hardest to get right, and `Reversal` already handles rows of any user that carry the order id.
- B is the better long-term shape, and nothing in A blocks it: the user meta can be backfilled into a referrals table later.
- C changes what R-4 asks for and risks checkout.

**What would change my mind:**
- Merchants need to see and reject pending referrals in 1.1.0, or a referral report is part of the 1.1.0 promise: then B.
- Support reports that cookie loss is common for the target stores: then add C's checkout-code entry on top of A as a follow-up.

**Decided (2026-11-17): B. A referrals table with a status per referral.** The user chose B over the recommendation, so 1.1.0 ships the auditable referrals list and the reject action. Conditions:
- **Refunds:** a partial refund of the friend's first order keeps both sides' referral points. Only a full refund, a `refunded` status or a cancel/trash claws them back, in full, and moves the referral to `reversed`. This differs from purchase points: today `Reversal::refund` prorates every earn row on the order through `ClawbackMath::owed`, so referral rows need their own rule there.
- **Link window:** the attribution cookie lasts 30 days by default. The referral campaign has a setting to change it.

**Revised (2026-11-17): phased, following the referral wireframe.** The user supplied a wireframe for the whole program, artboards 1a–1g, local file `~/Downloads/YayLoyalty admin interface/YayLoyalty Referral.dc.html`, not in the repo. It's bigger than R-4, so it's built in phases. B's data model and Pro-only edition stand. These points above change for 1.1.0:
- **In 1.1.0:**
  - **Friend's reward:** a **coupon** on their first order, not points. Fixed or percentage, with a minimum order, it expires N days after signup, and it's bound to the account and applied automatically at checkout. This changes R-4's acceptance ("both get the points"); the roadmap item must be updated.
  - **Advocate's reward:** points only. The wireframe's "or a coupon" comes later.
  - **Hold before payout:** default 14 days after the friend's order completes, 0 pays on completion. A refund inside the hold rejects the referral, with no clawback needed. When the hold ends, the payout releases automatically. A merchant can pay early or reject from an Approvals tab. This settles BL-4 (planned).
  - **Qualifying rules:**
    - Minimum order value, which settles BL-7 (decided: a setting, matching the coupon minimum).
    - Exclude orders that used another coupon.
    - First order only, always.
  - **Self-referrals:** blocked, always on (same account or same email).
  - **Admin:** a Pro "Referral" item in the YayLoyalty header menu, with tabs **Referrals | Approvals | Settings** (wireframe 1a, 1b's table, 1c without fraud flags). A full refund after payout still claws the points back, and the referral becomes `reversed`.
  - **Storefront:** a **Referrals** tab in My Account (1d): the offer, the link with Copy, the counts (invited, ordered, points earned, on the way), and the invitation list in the customer's words. Plus a "Refer a friend" row in Ways to earn.
  - **Statuses:** `pending` (signed up, no qualifying order), `on_hold` (with `hold_until`), `rewarded`, `rejected` (a reason from a fixed list), `reversed`, `expired` (no qualifying order before the friend's coupon expired). `hold_until` returns to the table.
- **Later (new roadmap items):**
  - The Overview report: the click funnel, revenue, cost, CSV. Needs click tracking, BL-5.
  - The `/r/CODE` landing page (1g), the launcher referral card and the share sheet (1e), share buttons, and a code typed at checkout.
  - Fraud flags (email domain, IP hash) and the monthly cap per advocate. Their review queue extends Approvals. BL-8.
  - Referral emails, the advocate's coupon option, and the Lite upsell screen (1f).

## Data model (B)
Reviewed against the schema on 2026-11-17. A referral is the person who shares (the advocate) bringing in a new customer (the friend). The table is `yayloyalty_referrals`. It's Pro only: Pro adds it through `yayloyalty_table_schemas`, and a Lite site never has it (decided 2026-11-17, after the user confirmed referral is a Pro-only feature):
- `id`
- `advocate_user_id`, `friend_user_id`
- `referral_code`: a snapshot of the code used at signup, so a later regenerated code doesn't orphan the row
- `status`
- `reject_reason`
- `first_order_id`
- `created_at`, `updated_at`

| From | To | Cardinality | Through |
|---|---|---|---|
| customers (advocate) | referrals | 1 : N | `advocate_user_id` |
| customers (friend) | referrals | 1 : 0..1 | `friend_user_id`, `UNIQUE`: a friend is referred once, so their first order can't pay twice |
| referrals | ledger | 1 : 0..2 earn rows, plus their `refund_earn` rows | `reference_type = 'referral'`, `reference_id = referrals.id`, keys `earn:{user}:referral:{id}:referral` (the use `ReferenceType::REFERRAL` was reserved for) |
| referrals | WooCommerce order | N : 0..1, by id only, never joined (HPOS-safe) | `first_order_id` |
| customers | customer_meta | 1 : N | Referral events (joined, rewarded, rejected, reversed) go to the activity timeline |

- **Both earn rows carry `order_id = first_order_id`.** `Reversal` finds earn rows by order id (`LedgerRepository::earn_rows_for_order`). Without it, a cancel or full refund never claws the referral points back.
- **Rules kept in code, not the database:**
  - `advocate_user_id ≠ friend_user_id`
  - What happens to a referral row when either user's account is deleted (`ActivityEvent::ACCOUNT_DELETED`)
- **Indexes:**
  - `UNIQUE (friend_user_id)`
  - `KEY (advocate_user_id, status)` for the My Points counts and the admin list
  - `KEY (first_order_id)` for reversal lookups
- **Existing tables, for the record:**
  - `lots` and `lot_allocations` are Lite schema, kept in step by Lite's `LedgerService`. Only the batch expiry mode is Pro.
  - `tiers` and `tier_history` belong to R-2, whose roadmap target is 1.1.0, not 1.2.

## Open questions
- **Hold period:** should a qualifying order wait `hold_until` (e.g. the refund window) before paying?
  - If yes, it needs:
    - a status between `pending` and `rewarded` (e.g. `qualified`)
    - a scheduled release job
    - `KEY (status, hold_until)`
  - It also makes most full-refund clawbacks unnecessary.
  - Default for the plan: no hold. Pay at completion, as every other trigger does.
- **Visits table:** should 1.1.0 record clicks in a `referral_visits` table (`referral_code`, `visitor_hash`, `landing_path`, `created_at`; customers (advocate) 1 : N by code value; prunable)?
  - It's only needed for click and conversion stats. B didn't include it.
  - If it ships:
    - `visitor_hash` is a salted hash with no raw IP.
    - A daily job prunes rows older than the link window.
    - `referrals` gains a nullable `visit_id` (0..1).
  - Default for the plan: no visits table.
- **Minimum order:** does a qualifying first order need a minimum subtotal (as `first_order` campaigns have `min_subtotal`)?
- **Referrer cap:** is there a limit on how many referrals one customer can be paid for?
- **Guest orders:** does a guest order the friend claims later from My Points (`AccountClaimOrder`) count as their first order?
- **Existing accounts:** an existing customer who clicks a link and is already registered gets nothing. Confirm that's intended.
- **Outside this item:** the Pro triggers `first_order`, `order_count` and `spend_milestone` are offered but have no handler in `includes/`. Check what saving and firing such a campaign does.
