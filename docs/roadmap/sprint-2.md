## Sprint 2 (2026-11-27 → 2026-12-10)

Goal: Finish 1.1.0: VIP tiers, social and renewal earning, and the Tools page, so 1.1.0 can ship when the sprint ends (2026-12-10).

### R-2 VIP tiers
- status: in-progress
- size: L
- editions: pro
- why: requested 2026-11-13 for 1.1; the customers table already reserves tier_id and tier progress columns, and the tier condition waits for it (CustomersTable.php, CampaignConditionType::TIER)
- acceptance: A merchant defines ranked tiers (e.g. Silver, Gold) by the points customers earned in the qualifying period (all time, the last N months, or the current or previous calendar period), with a points multiplier per tier; spending or redeeming points never lowers a tier; customers see their tier and progress to the next in My Points and move up when they cross a threshold; they move down when a refund of an order in their current qualifying period, or a staff deduction counted toward tier points, takes them under it, while a customer kept by a raised threshold, a mode switch or a hold keeps the tier until its next review
- target: 1.1.0
- note: Decided 2026-11-23: tiers count points earned in the qualifying period only, not spend, so redeeming points never drops a tier (release brief Q3). Built on jason/feat/tiers (PR #184 into dev); P-9 (PR #207) added the refund rule and the Adjust dialog's Count toward tier points option
- started: 2026-11-23

### R-5 More ways to earn: social shares and subscription renewals
- status: todo
- size: M
- editions: pro
- why: requested 2026-11-13 for 1.1; the social and renewal triggers are reserved but not offered (CampaignTrigger::SOCIAL, ::RENEWAL)
- acceptance: A merchant turns on campaigns for following/sharing on social networks and for WooCommerce Subscriptions renewals; customers earn once per action, and a renewal that is refunded takes its points back
- target: 1.1.0
- note: reading unconfirmed: could also mean other actions (newsletter sign-up, account anniversary, product purchase of a set category); built from the reserved social and renewal triggers

### R-6 Tools: CSV import/export and bulk point adjustments
- status: in-progress
- size: M
- editions: pro, lite
- why: requested 2026-11-13 for 1.1; merchants moving from another loyalty plugin need to bring balances in, and adjustments today are one customer at a time
- acceptance: On a Tools page a merchant exports all balances to CSV, imports a CSV of email and points (with a preview and per-row errors before applying), and adds or removes points for selected customers or a segment with a note, each change landing in the ledger
- target: 1.1.0
- started: 2026-11-19
- built: 2026-11-19
