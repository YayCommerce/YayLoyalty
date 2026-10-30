import { Reward } from '@/lib/schema/reward.schema';

/** A stored catalog reward with every field filled; tests override only what they assert on. */
export function reward(overrides: Partial<Reward> = {}): Reward {
  return {
    id: 1,
    name: 'Test reward',
    slug: 'test-reward',
    coupon_code: 'yayloyalty-ab12cd34',
    type: 'fixed_coupon',
    status: 'active',
    sort_order: 0,
    cost_points: 500,
    config: {},
    conditions: [],
    starts_at: null,
    ends_at: null,
    is_builtin: false,
    created_by: null,
    created_at: '2026-01-01 00:00:00',
    updated_at: '2026-01-01 00:00:00',
    is_deletable: true,
    redemption_summary: {
      claimed: 0,
      first_claimed_at: null,
    },
    ...overrides,
  };
}
