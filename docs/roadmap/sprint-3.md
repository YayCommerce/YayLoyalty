## Sprint 3 (2026-12-11 → 2026-12-24)

Goal: Referral growth: the landing page with sharing and code at checkout, and the overview report; plus referral events in the activity timeline, tier-point labels on adjustments, and balance export for the largest stores.

### R-3 Page builder widgets for Elementor, Bricks and Divi
- status: done
- size: L
- editions: pro, lite
- why: requested 2026-11-13 for 1.1; the balance, ways to earn, ways to redeem and redeem card exist only as blocks and shortcodes today
- acceptance: In Elementor, Bricks and Divi a merchant drags in the points balance, ways to earn, ways to redeem and redeem card widgets, sees a live preview in the builder, and they render the same as the shortcodes; a site without the builder loads no builder code
- target: 1.1.0
- started: 2026-11-16
- built: 2026-11-16
- merged: 2026-11-16

### R-10 Referral overview report with click funnel
- status: todo
- size: L
- editions: pro
- depends: R-4
- why: requested 2026-11-17 in the referral wireframe (1b): merchants judge the programme by clicks → signups → orders and its revenue against its cost; needs click tracking (BL-5)
- acceptance: Referral → Overview shows clicks, signups, qualifying and completed orders with conversion rates, referral revenue, programme cost, points paid and cost per customer for a date range, and exports CSV; clicks count once per visitor per day
- target: 1.1.2

### R-11 Referral landing page, sharing and code at checkout
- status: todo
- size: L
- editions: pro
- depends: R-4
- why: requested 2026-11-17 in the referral wireframe (1e, 1g): a friend should land on a page that explains the offer, advocates share from their phone, and a friend who lost the link can type the code
- acceptance: A /r/CODE link opens a landing page with the advocate's offer and signup; My Account and the launcher show a referral card with share buttons and the native share sheet; a friend can enter the code at checkout (classic and block) and it overrides the cookie
- target: 1.1.2

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
