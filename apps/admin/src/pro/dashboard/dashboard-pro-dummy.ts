/**
 * Referrals and tier distribution for the Pro dashboard — the only dummy
 * data on that screen. `GET /dashboard/pro` returns `null` for both because
 * neither table exists yet (see docs/data-model.md), and the panels have to
 * be built and reviewed against something.
 *
 * SWAP POINT. When the referrals / tiers tables ship, DashboardProRestController
 * fills the two fields for real and this file is deleted; the consumers
 * already read `response.referrals ?? DUMMY_REFERRALS` and
 * `response.tier_distribution ?? DUMMY_TIER_DISTRIBUTION`, so nothing else
 * changes.
 */

export const DUMMY_REFERRALS = { completed: 84, prev_delta_pct: 15 } as const;

export const DUMMY_TIER_DISTRIBUTION = [
  { name: 'Explorer', members: 1847 },
  { name: 'Summit', members: 478 },
  { name: 'Alpine', members: 106 },
] as const;
