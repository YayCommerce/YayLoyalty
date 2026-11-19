## Sprint 4 (2026-12-25 → 2027-01-07)

Goal: Holiday sprint, kept light: referral fraud checks, referral emails with the advocate coupon reward, and the Lite referral upsell.

### R-4 Referral program
- status: done
- size: L
- editions: pro
- why: requested 2026-11-13 for 1.1; the referral trigger and customers.referral_code are reserved but not offered (CampaignTrigger::REFERRAL)
- acceptance: A customer copies their referral link from My Account → Referrals; a new customer who signs up through it gets the merchant's discount applied automatically on a qualifying first order, and once that order completes and the hold passes the advocate gets the points (the merchant can pay early or reject from Approvals); self-referrals, orders under the minimum, and orders refunded during the hold earn nothing
- target: 1.1.0
- note: Scope phased 2026-11-17 from the referral wireframe (1a-1g): 1.1.0 is the core slice in BS-3 'Revised' and UX-2; the report, landing page/sharing, fraud flags, emails and Lite upsell are R-10..R-14
- started: 2026-11-17
- built: 2026-11-18
- merged: 2026-11-18

### R-13 Referral emails and advocate coupon reward
- status: todo
- size: M
- editions: pro
- depends: R-4
- why: requested 2026-11-17 in the referral wireframe (1a): advocates and friends hear when a referral moves, and a merchant can reward the advocate with a coupon instead of points
- acceptance: Settings → Emails lists referral emails (friend joined, reward on the way, reward paid) that send at those moments; Referral → Settings offers points or a coupon for the advocate, never both

### R-14 Referral upsell screen in Lite
- status: todo
- size: S
- editions: lite
- depends: R-4
- why: requested 2026-11-17 in the referral wireframe (1f): Lite merchants should see what the Pro referral programme does
- acceptance: In Lite the YayLoyalty header shows a locked Referral item that opens a Pro feature screen with example figures labelled as illustrative and one Upgrade button; the storefront shows nothing referral-related

### R-12 Referral fraud flags and monthly cap
- status: todo
- size: M
- editions: pro
- depends: R-4
- why: requested 2026-11-17 in the referral wireframe (1a, 1c): shared email domains, matching IP hashes and a per-advocate monthly cap should hold a referral for review instead of paying it (BL-8)
- acceptance: A referral whose friend shares the advocate's email domain or IP hash, or exceeds the advocate's monthly cap, is held as Flagged in Approvals with the matched checks spelled out, is excluded from bulk approve, and shows 'Under review' to the advocate
