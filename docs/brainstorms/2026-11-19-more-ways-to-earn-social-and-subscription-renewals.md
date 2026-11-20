---
id: BS-4
title: More ways to earn: social and subscription renewals
status: decided
from: R-5
decision: R-A renewal order trigger + S-A honor-system social buttons
created: 2026-11-19
---
# BS-4 More ways to earn: social and subscription renewals

## Problem
R-5 (sprint 2, Pro, 1.1.0): merchants want campaigns for following/sharing on social networks and for WooCommerce Subscriptions renewals. Acceptance: customers earn **once per action**, and a **refunded renewal takes its points back**.

Today both triggers are declared but not offered: `CampaignTrigger::SOCIAL` / `::RENEWAL` (`includes/Enums/CampaignTrigger.php:29-30`) are in `all_known()` only, and Pro leaves them out of `ADDED_TRIGGERS` on purpose (`includes/Pro/YayLoyaltyPro.php:69-80`). `LedgerSource::RENEWAL` exists (`includes/Enums/LedgerSource.php:18`), and the SPA schemas already list `renewal` (`apps/admin/src/lib/schema/campaign.schema.ts:39`, `ledger.schema.ts:19`).

The two halves share nothing but the campaign editor, so each gets its own options below. Pick one per half.

## Constraints
- **Pro only.** Handlers register through `yayloyalty_register_trigger_handlers` (`includes/Engine/Points/Triggers/Registry.php:50`), triggers through `yayloyalty_campaign_triggers`, config through `yayloyalty_campaign_config_whitelist` / `_schema`. Lite keeps recognising both values (hard rule 6), which it already does.
- **Hook before `EarningService::build()`** (hard rule 4): new gates or handlers attach in `YayLoyaltyPro::boot()`.
- **Only `LedgerService` writes**, through `EarningService::award()` / `award_flat()` (`includes/Engine/Earning/EarningService.php:162`, `:325`) with an `IdempotencyKey` (`{type}:{user}:{reftype}:{refid}:{qualifier}`). "Once per action" maps onto that key: no new dedupe table.
- **Renewal orders already earn purchase points today.** A WCS renewal is an ordinary `shop_order`, so `OrderListener::on_awardable()` awards it under `purchase` when it completes (`includes/Engine/Earning/Listeners/OrderListener.php:160-178`). A renewal campaign must decide whether it adds to or replaces that.
- **Only `completed` awards** (`Enums/OrderStatus::AWARD`). Many renewals (non-downloadable virtual subscriptions) sit in `processing` forever, so a status-based renewal award may never fire on those stores.
- **Refund reversal is already per order:** `Reversal::full_order()` and `refund()` claw back every earn row of the order (`includes/Engine/Earning/Reversal.php:49-90`). Any row written with reference `order:<renewal id>` gets the refund behaviour for free.
- **WooCommerce Subscriptions is optional.** Nothing in `includes/` references it today. The trigger must be offered only when WCS is active, and degrade to inert (not fatal) when WCS is deactivated.
- **Social can't be truly verified** without each network's OAuth API (Facebook, X, Instagram and TikTok all removed or gate "did user X follow page Y"). Every common loyalty plugin (WPLoyalty, YITH Points, Smile, Yotpo) uses an honor-system click.
- **Storefront gate:** any shopper-facing button asks `ProgramGate::storefront_enabled()`, and Pro surfaces also need the licence gate (architecture.md, Storefront).
- `DailyEarningLimit` (Pro) already clamps every award through `EarningService::AWARD_POINTS_FILTER`: social farming is capped for free if a merchant sets a limit.
- Related: BL-6 (first_order, order_count, spend_milestone are offered with no handler). Don't copy that mistake: whatever ships is offered only with a working handler.

## Options

### Renewal

#### R-A. Renewal as an order trigger on the existing award path
- **How:** Pro appends `renewal` to `yayloyalty_order_award_triggers` (`OrderListener.php:169`) when `wcs_order_contains_renewal( $order )`. A `RenewalHandler` (TriggerHandler, like `Pro/Birthday/BirthdayHandler.php`) pays flat points per renewal (config `points`, optional "every Nth renewal"), reference `order:<id>`, source `LedgerSource::RENEWAL`. A per-campaign switch `replace_purchase` drops `purchase` from the list for renewal orders, so merchants choose "on top of" or "instead of" purchase points.
- **Pros:** reuses staging, the completed-only rule, order notes and the whole refund path untouched. Once per renewal comes from the order id in the idempotency key. Small.
- **Cons:** inherits the `completed`-only rule, so `processing` renewals never earn unless the merchant completes them (same as purchase today, so at least consistent).
- **Risks:** WCS early renewals and manual "retry payment" create new orders: each paid renewal order earns once, which is the right behaviour. Switching subscriptions creates `switch` orders, not renewals: excluded by the WCS check.
- **Edition:** Pro. **Size:** S–M.

#### R-B. Award on WCS's own payment hook
- **How:** listen on `woocommerce_subscription_renewal_payment_complete( $subscription, $renewal_order )` and call `award()` there, independent of order status. Reversal still works because the row references the order.
- **Pros:** earns on payment even when renewals sit in `processing`. Matches how merchants think ("they paid again").
- **Cons:** a second award path outside `OrderListener`, which the architecture says is the only status router. Breaks the "completed awards" promise in the docs and the order-meta staging (pending points wouldn't show). A payment later marked failed/cancelled needs its own reversal wiring.
- **Edition:** Pro. **Size:** M.

### Social

#### S-A. Honor-system "Follow / Share" buttons, once per network
- **How:** a `social` campaign holds a list of actions (`network`: facebook, instagram, x, tiktok, youtube, linkedin, pinterest; `kind`: follow | share; `url`; `points`). Pro adds rows to Ways to earn (`yayloyalty_launcher_earn_rows`, like `Pro/Referrals/ReferralEarnRow.php`) and to My Points. The click opens the network in a new tab and POSTs a wc-ajax `yayloyalty_social` (nonce, signed-in) that calls `award_flat()` keyed on the campaign plus `<network>-<kind>`, so each customer earns each action once, ever.
- **Pros:** what every competitor ships, and what merchants expect. Uses the existing earn-row seam, `award_flat()`, idempotency and `DailyEarningLimit`. No external API keys.
- **Cons:** unverifiable: a shopper can claim without following. Bounded by "once per action" × the number of actions the merchant lists.
- **Risks:** `LedgerSource::SOCIAL` exists but `ReferenceType` has no `social` value (`includes/Enums/ReferenceType.php`). Either add one to Lite's list (hard rule 6: a downgraded site must still read the rows) or reference the campaign under an existing type. The 96-char key limit fits `earn:<user>:social:<campaign>:<network>-<kind>`. Removing an action from a campaign and adding it back must not pay again, so the qualifier is the network and kind, not a list position.
- **Edition:** Pro. **Size:** M.

#### S-B. Tracked share links (earn when a friend actually visits)
- **How:** "Share" buttons post a URL carrying the customer's code (the referral `?yl_ref=` machinery, `Pro/Referrals/ReferralAttribution.php`). Points land when a new visitor arrives through that link, once per network per customer (or per N visits).
- **Pros:** some proof the share happened. Reuses the referral code and cookie.
- **Cons:** only covers *share*, not *follow*, so it doesn't meet the acceptance alone. Visit counting needs storage and bot filtering, and full-page caches skip it (the same caveat as referrals). It overlaps the referral program merchants just got.
- **Edition:** Pro. **Size:** L.

(Rejected without a full write-up: verifying follows through network APIs. That's an OAuth app per network and APIs that no longer answer "does X follow Y". L+ and brittle.)

## Recommendation
**R-A + S-A.**

R-A keeps one award path. Renewal is "a purchase that happens to be a renewal", and the existing listener, staging, notes and refund clawback already give the acceptance's "refunded renewal takes its points back" with no new code. The `processing` gap is real but is the same rule purchase points follow today. It's better handled once for all orders, as a "award on processing" setting, than special-cased for renewals. That would make me switch to R-B: if support shows most subscription stores never complete renewals *and* the team won't add a store-wide award status.

S-A is the industry norm and fits the seams that already exist (earn rows, `award_flat`, idempotency, daily limit). Calling it honor-system in the admin copy keeps expectations honest. S-B is a different feature (share attribution) and doesn't cover follows. What would change my mind: a merchant requirement for proof of a share, which would make S-B a follow-up item, not a replacement.

Both halves are offered only when they work: `social` always (Pro), `renewal` only while `class_exists( 'WC_Subscriptions' )` / `function_exists( 'wcs_order_contains_renewal' )`.

## Open questions
- **Renewal vs purchase:** should a renewal campaign add to purchase points or replace them by default? R-A makes it a per-campaign switch; the default (proposed: *on top of*) is a product call.
- **Social catalogue:** which networks ship in 1.1.0, and is a merchant-defined "custom link" action wanted (e.g. join a Discord, newsletter page)? The roadmap note's "other actions" reading (newsletter, anniversary) stays out unless asked.
- **Social placement:** Ways to earn and My Points only, or also a block/shortcode button for page builders (BS-2 widgets)?
- **Award on `processing`:** worth a separate roadmap item for all order triggers?
