## Sprint 1 (2026-11-13 → 2026-11-26)

Goal: Merchants can match campaign and reward conditions by any or all (1.1.0).

### R-1 Advanced rules: match any or all conditions
- status: in-progress
- size: L
- editions: pro
- why: requested 2026-11-13 for 1.1; campaign conditions today are one flat list, and lifetime spend, order count and lifetime points conditions are declared but not offered (includes/Enums/CampaignConditionType.php)
- acceptance: A merchant sets a campaign's or reward's conditions to match all or any of them (e.g. lifetime spend >= $500 OR order count >= 5) and it applies only to matching customers; an existing flat condition list keeps working unchanged after upgrade, as all
- target: 1.1.0
- started: 2026-11-13
- built: 2026-11-13
