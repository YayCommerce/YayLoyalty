## Later

### R-7 Free shipping and free product rewards that redeem
- status: todo
- size: M
- editions: pro
- why: known-issues.md: Pro offers free_shipping and free_product reward types but only coupon types have a redemption handler, so merchants can create rewards shoppers can't use
- acceptance: A shopper redeems a free-shipping reward and shipping drops to zero, or a free-product reward and the product is added at no cost, on classic and block checkout; a refund or cancelled order gives the points back

### R-8 Launcher shows only the rewards a shopper qualifies for
- status: todo
- size: S
- editions: pro
- from: BL-1
- why: BL-1 (deferred from P-1): the launcher's Ways to redeem lists conditional rewards to every shopper while cart and checkout hide them; open PR #183 may cover part of it
- acceptance: A signed-in shopper sees in the launcher only the rewards whose conditions they meet; a guest sees conditional rewards labelled as for selected customers, and a cached page never shows one shopper's list to another

### R-9 Privacy export and erase for loyalty data
- status: todo
- size: M
- editions: free, pro
- why: known-issues.md: no privacy exporter or eraser for the customer activity snapshot (TODO(privacy-eraser) in CustomerActivityService)
- acceptance: Tools > Export/Erase Personal Data includes a customer's points balance, ledger and activity; erasing removes the activity snapshot and anonymises ledger rows while keeping totals consistent

### R-10 Referral overview report with click funnel
- status: todo
- size: L
- editions: pro
- depends: R-4
- why: requested 2026-11-17 in the referral wireframe (1b): merchants judge the programme by clicks → signups → orders and its revenue against its cost; needs click tracking (BL-5)
- acceptance: Referral → Overview shows clicks, signups, qualifying and completed orders with conversion rates, referral revenue, programme cost, points paid and cost per customer for a date range, and exports CSV; clicks count once per visitor per day

### R-11 Referral landing page, sharing and code at checkout
- status: todo
- size: L
- editions: pro
- depends: R-4
- why: requested 2026-11-17 in the referral wireframe (1e, 1g): a friend should land on a page that explains the offer, advocates share from their phone, and a friend who lost the link can type the code
- acceptance: A /r/CODE link opens a landing page with the advocate's offer and signup; My Account and the launcher show a referral card with share buttons and the native share sheet; a friend can enter the code at checkout (classic and block) and it overrides the cookie

### R-12 Referral fraud flags and monthly cap
- status: todo
- size: M
- editions: pro
- depends: R-4
- why: requested 2026-11-17 in the referral wireframe (1a, 1c): shared email domains, matching IP hashes and a per-advocate monthly cap should hold a referral for review instead of paying it (BL-8)
- acceptance: A referral whose friend shares the advocate's email domain or IP hash, or exceeds the advocate's monthly cap, is held as Flagged in Approvals with the matched checks spelled out, is excluded from bulk approve, and shows 'Under review' to the advocate

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
