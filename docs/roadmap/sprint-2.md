## Sprint 2 (2026-11-27 → 2026-12-10)

Goal: Ship 1.1.0 (all six items built), then make every reward and campaign a merchant can save actually pay: free shipping and free product redeem, milestone triggers fire, launcher filtering and privacy tools.

### R-2 VIP tiers
- status: done
- size: L
- editions: pro
- why: requested 2026-11-13 for 1.1; the customers table already reserves tier_id and tier progress columns, and the tier condition waits for it (CustomersTable.php, CampaignConditionType::TIER)
- acceptance: A merchant defines ranked tiers (e.g. Silver, Gold) by the points customers earned in the qualifying period (all time, the last N months, or the current or previous calendar period), with a points multiplier per tier; spending or redeeming points never lowers a tier; customers see their tier and progress to the next in My Points and move up when they cross a threshold; they move down when a refund of an order in their current qualifying period, or a staff deduction counted toward tier points, takes them under it, while a customer kept by a raised threshold, a mode switch or a hold keeps the tier until its next review
- target: 1.1.0
- note: Decided 2026-11-23: tiers count points earned in the qualifying period only, not spend, so redeeming points never drops a tier (release brief Q3). Built on jason/feat/tiers (PR #184); P-9 (PR #207) added the refund rule and the Adjust dialog's Count toward tier points option. Both merged 2026-11-23
- started: 2026-11-23
- merged: 2026-11-23
- built: 2026-11-23

### R-5 More ways to earn: social shares and subscription renewals
- status: done
- size: M
- editions: pro
- why: requested 2026-11-13 for 1.1; the social and renewal triggers are reserved but not offered (CampaignTrigger::SOCIAL, ::RENEWAL)
- acceptance: A merchant turns on campaigns for following/sharing on social networks and for WooCommerce Subscriptions renewals; customers earn once per action, and a renewal that is refunded takes its points back
- target: 1.1.0
- note: P-7 merged in PR #203 on 2026-11-23
- started: 2026-11-19
- built: 2026-11-19
- merged: 2026-11-23

### R-6 Tools: CSV import/export and bulk point adjustments
- status: done
- size: M
- editions: pro, lite
- why: requested 2026-11-13 for 1.1; merchants moving from another loyalty plugin need to bring balances in, and adjustments today are one customer at a time
- acceptance: On a Tools page a merchant exports all balances to CSV, imports a CSV of email and points (with a preview and per-row errors before applying), and adds or removes points for selected customers or a segment with a note, each change landing in the ledger
- target: 1.1.0
- started: 2026-11-19
- built: 2026-11-19
- merged: 2026-11-20

### R-7 Free shipping and free product rewards that redeem
- status: done
- size: M
- editions: pro
- why: known-issues.md: Pro offers free_shipping and free_product reward types but only coupon types have a redemption handler, so merchants can create rewards shoppers can't use
- acceptance: A shopper redeems a free-shipping reward and shipping drops to zero, or a free-product reward and the product is added at no cost, on classic and block checkout; a refund or cancelled order gives the points back
- started: 2026-11-23
- built: 2026-11-24
- merged: 2026-11-25
- target: 1.1.0

### R-8 Launcher shows only the rewards a shopper qualifies for
- status: in-progress
- size: S
- editions: pro
- from: BL-1
- why: BL-1 (deferred from P-1): the launcher's Ways to redeem lists conditional rewards to every shopper while cart and checkout hide them; open PR #183 may cover part of it
- acceptance: A signed-in shopper sees in the launcher only the rewards whose conditions they meet; a guest sees conditional rewards labelled as for selected customers, and a cached page never shows one shopper's list to another
- target: 1.1.1
- started: 2026-11-25
- built: 2026-11-25

### R-9 Privacy export and erase for loyalty data
- status: todo
- size: M
- editions: free, pro
- why: known-issues.md: no privacy exporter or eraser for the customer activity snapshot (TODO(privacy-eraser) in CustomerActivityService)
- acceptance: Tools > Export/Erase Personal Data includes a customer's points balance, ledger and activity; erasing removes the activity snapshot and anonymises ledger rows while keeping totals consistent
- target: 1.1.1

### R-15 Milestone triggers award points: first order, order count, spend milestone
- status: todo
- size: M
- editions: pro
- from: BL-6
- why: BL-6 (deferred from P-3): Pro offers first_order, order_count and spend_milestone campaigns but no handler awards them, so merchants can save campaigns that never pay
- acceptance: A shopper earns a first-order, Nth-order or spend-milestone campaign's points once when the qualifying order completes, and a refund that takes them back under the milestone reverses them
- target: 1.1.1
