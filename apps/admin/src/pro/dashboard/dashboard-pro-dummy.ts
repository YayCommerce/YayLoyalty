/**
 * Dummy referrals and tier distribution until their tables exist. Consumers already fall back with `??`.
 *
 * TODO(dashboard-pro): delete this file once GET /dashboard/pro fills referrals and tiers.
 */

export const DUMMY_REFERRALS = { completed: 84, prev_delta_pct: 15 } as const;

export const DUMMY_TIER_DISTRIBUTION = [
  { name: 'Explorer', members: 1847 },
  { name: 'Summit', members: 478 },
  { name: 'Alpine', members: 106 },
] as const;
