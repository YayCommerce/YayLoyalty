## Sprint 4 (2026-12-25 → 2027-01-07)

Goal: Holiday sprint, kept light: Elementor 4 atomic widgets; the rest is spare capacity for fixes.

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

### R-22 Elementor 4 atomic widgets for the loyalty placements
- status: todo
- size: M
- editions: pro, lite
- from: BL-3
- depends: R-3
- why: Deferred from P-2 (BL-3): Elementor 4's atomic editor doesn't offer the v3 widgets R-3 shipped
- acceptance: In Elementor 4's atomic editor a merchant adds the balance, ways to earn, ways to redeem and redeem card elements and they render as the shortcodes do; Elementor 3 sites keep the current widgets
- target: 1.1.3
