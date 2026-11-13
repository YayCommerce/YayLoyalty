## Sprint 5 (2027-01-08 → 2027-01-21)

Goal: Social and subscription-renewal earning, and a Tools page for CSV import/export and bulk points (1.1.0).

### R-5 More ways to earn: social shares and subscription renewals
- status: todo
- size: M
- editions: pro
- why: requested 2026-11-13 for 1.1; the social and renewal triggers are reserved but not offered (CampaignTrigger::SOCIAL, ::RENEWAL)
- acceptance: A merchant turns on campaigns for following/sharing on social networks and for WooCommerce Subscriptions renewals; customers earn once per action, and a renewal that is refunded takes its points back
- target: 1.1.0
- note: reading unconfirmed: could also mean other actions (newsletter sign-up, account anniversary, product purchase of a set category); built from the reserved social and renewal triggers

### R-6 Tools: CSV import/export and bulk point adjustments
- status: todo
- size: M
- editions: pro, lite
- why: requested 2026-11-13 for 1.1; merchants moving from another loyalty plugin need to bring balances in, and adjustments today are one customer at a time
- acceptance: On a Tools page a merchant exports all balances to CSV, imports a CSV of email and points (with a preview and per-row errors before applying), and adds or removes points for selected customers or a segment with a note, each change landing in the ledger
- target: 1.1.0
