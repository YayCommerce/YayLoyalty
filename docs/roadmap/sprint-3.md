## Sprint 3 (2026-12-11 → 2026-12-24)

Goal: Every reward and campaign a merchant can save actually pays: free shipping and free product redeem, milestone triggers fire, launcher filtering and privacy tools.

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

### R-15 Milestone triggers award points: first order, order count, spend milestone
- status: todo
- size: M
- editions: pro
- from: BL-6
- why: BL-6 (deferred from P-3): Pro offers first_order, order_count and spend_milestone campaigns but no handler awards them, so merchants can save campaigns that never pay
- acceptance: A shopper earns a first-order, Nth-order or spend-milestone campaign's points once when the qualifying order completes, and a refund that takes them back under the milestone reverses them
