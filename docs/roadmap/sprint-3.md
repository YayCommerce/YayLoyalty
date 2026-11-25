## Sprint 3 (2026-12-11 → 2026-12-24)

Goal: The referral program grows up: fraud flags and the monthly cap, referral emails with the advocate coupon, the Lite upsell, the landing page with sharing and code at checkout, and the overview report.

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

### R-12 Referral fraud flags and monthly cap
- status: todo
- size: M
- editions: pro
- depends: R-4
- why: requested 2026-11-17 in the referral wireframe (1a, 1c): shared email domains, matching IP hashes and a per-advocate monthly cap should hold a referral for review instead of paying it (BL-8)
- acceptance: A referral whose friend shares the advocate's email domain or IP hash, or exceeds the advocate's monthly cap, is held as Flagged in Approvals with the matched checks spelled out, is excluded from bulk approve, and shows 'Under review' to the advocate
- target: 1.1.2

### R-13 Referral emails and advocate coupon reward
- status: todo
- size: M
- editions: pro
- depends: R-4
- why: requested 2026-11-17 in the referral wireframe (1a): advocates and friends hear when a referral moves, and a merchant can reward the advocate with a coupon instead of points
- acceptance: Settings → Emails lists referral emails (friend joined, reward on the way, reward paid) that send at those moments; Referral → Settings offers points or a coupon for the advocate, never both
- target: 1.1.2

### R-14 Referral upsell screen in Lite
- status: todo
- size: S
- editions: lite
- depends: R-4
- why: requested 2026-11-17 in the referral wireframe (1f): Lite merchants should see what the Pro referral programme does
- acceptance: In Lite the YayLoyalty header shows a locked Referral item that opens a Pro feature screen with example figures labelled as illustrative and one Upgrade button; the storefront shows nothing referral-related
- target: 1.1.2

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
