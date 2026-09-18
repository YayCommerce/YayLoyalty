/**
 * Dummy referrals and tier distribution until their tables exist. Delete
 * this file once GET /dashboard/pro fills them; consumers already fall
 * back with `??`.
 */

export const DUMMY_REFERRALS = { completed: 84, prev_delta_pct: 15 } as const;

export const DUMMY_TIER_DISTRIBUTION = [
  { name: 'Explorer', members: 1847 },
  { name: 'Summit', members: 478 },
  { name: 'Alpine', members: 106 },
] as const;
