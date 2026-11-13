## Sprint 2 (2026-11-27 → 2026-12-10)

Goal: Customers move up VIP tiers and get each tier's perks (1.1.0).

### R-2 VIP tiers
- status: todo
- size: L
- editions: pro
- why: requested 2026-11-13 for 1.1; the customers table already reserves tier_id and tier progress columns, and the tier condition waits for it (CustomersTable.php, CampaignConditionType::TIER)
- acceptance: A merchant defines ranked tiers (e.g. Silver, Gold) by lifetime spend or points with a points multiplier per tier; customers see their tier and progress to the next in My Points, and are moved up or down when they cross a threshold, including after a refund
- target: 1.1.0
- note: also turns on the reserved tier condition (CampaignConditionType::TIER), so campaigns can target a tier
