## Sprint 4 (2026-12-25 → 2027-01-07)

Goal: Customers earn points for referring friends who order (1.1.0).

### R-4 Referral program
- status: todo
- size: L
- editions: pro
- why: requested 2026-11-13 for 1.1; the referral trigger and customers.referral_code are reserved but not offered (CampaignTrigger::REFERRAL)
- acceptance: A customer copies their referral link from My Account → Referrals; a new customer who signs up through it gets the merchant's discount applied automatically on a qualifying first order, and once that order completes and the hold passes the advocate gets the points (the merchant can pay early or reject from Approvals); self-referrals, orders under the minimum, and orders refunded during the hold earn nothing
- target: 1.1.0
- note: Scope phased 2026-11-17 from the referral wireframe (1a-1g): 1.1.0 is the core slice in BS-3 'Revised' and UX-2; the report, landing page/sharing, fraud flags, emails and Lite upsell are R-10..R-14
