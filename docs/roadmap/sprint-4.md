## Sprint 4 (2026-12-25 → 2027-01-07)

Goal: Holiday sprint, kept light: referral events in the activity timeline, tier-point labels on adjustments, and balance export for the largest stores.

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

### R-19 Referral events in the customer activity timeline
- status: todo
- size: S
- editions: pro
- from: BL-10
- depends: R-4
- why: Deferred from P-3 (BL-10): merchants can't see referral joins, payouts, rejections or reversals in a customer's history
- acceptance: A customer's admin activity timeline shows when they joined through a referral and when a referral reward was paid, rejected or reversed; a site that never used referrals shows nothing new
- target: 1.1.3

### R-20 Label adjustments that don't count toward tier points
- status: todo
- size: S
- editions: pro
- from: BL-24
- depends: R-2
- why: Deferred from P-9 (BL-24): a staff adjustment kept out of tier points looks the same as one that counts in the drawer ledger and activity
- acceptance: In the customer drawer's ledger and activity, a staff adjustment that doesn't count toward tier points is labelled so; adjustments that count look as they do today
- target: 1.1.3

### R-21 Export balances for stores above 50,000 customers
- status: todo
- size: M
- editions: pro, lite
- from: BL-20
- depends: R-6
- why: Deferred from P-8 (BL-20): Tools > Export balances fails above the 50,000-row fetch cap, so the largest stores can't export
- acceptance: A store with 100,000 customers exports every balance from Tools in one CSV without a timeout or a missing row
- target: 1.1.3
