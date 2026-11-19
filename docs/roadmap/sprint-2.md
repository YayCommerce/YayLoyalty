## Sprint 2 (2026-11-27 → 2026-12-10)

Goal: Finish 1.1.0: VIP tiers, social and renewal earning, and the Tools page, so 1.1.0 can ship when the sprint ends (2026-12-10).

### R-2 VIP tiers
- status: todo
- size: L
- editions: pro
- why: requested 2026-11-13 for 1.1; the customers table already reserves tier_id and tier progress columns, and the tier condition waits for it (CustomersTable.php, CampaignConditionType::TIER)
- acceptance: A merchant defines ranked tiers (e.g. Silver, Gold) by lifetime spend or points with a points multiplier per tier; customers see their tier and progress to the next in My Points, and are moved up or down when they cross a threshold, including after a refund
- target: 1.1.0
- note: Jason's open PR #184 (jason/feat/tiers) already builds tiers; review it against this acceptance before brainstorming

### R-5 More ways to earn: social shares and subscription renewals
- status: in-progress
- size: M
- editions: pro
- why: requested 2026-11-13 for 1.1; the social and renewal triggers are reserved but not offered (CampaignTrigger::SOCIAL, ::RENEWAL)
- acceptance: A merchant turns on campaigns for following/sharing on social networks and for WooCommerce Subscriptions renewals; customers earn once per action, and a renewal that is refunded takes its points back
- target: 1.1.0
- note: reading unconfirmed: could also mean other actions (newsletter sign-up, account anniversary, product purchase of a set category); built from the reserved social and renewal triggers
- started: 2026-11-19

### R-6 Tools: CSV import/export and bulk point adjustments
- status: todo
- size: M
- editions: pro, lite
- why: requested 2026-11-13 for 1.1; merchants moving from another loyalty plugin need to bring balances in, and adjustments today are one customer at a time
- acceptance: On a Tools page a merchant exports all balances to CSV, imports a CSV of email and points (with a preview and per-row errors before applying), and adds or removes points for selected customers or a segment with a note, each change landing in the ledger
- target: 1.1.0
