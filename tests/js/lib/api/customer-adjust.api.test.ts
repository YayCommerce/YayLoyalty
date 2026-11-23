import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();

vi.mock('@/lib/api/api', () => ({ api: { post: (...args: unknown[]) => post(...args) } }));

const { adjustCustomerPoints } = await import('@/lib/api/customer.api');
const { adjustPointsSchema } = await import('@/lib/schema/customer.schema');

const ENTRY = {
  id: 9,
  user_id: 4,
  points: 1000,
  balance_after: 1000,
  type: 'adjust',
  source: 'manual',
  reference_type: null,
  reference_id: null,
  campaign_id: null,
  tier_id: null,
  tier_multiplier: null,
  redemption_id: null,
  order_id: null,
  note: 'seed',
  customer_note: null,
  actor_id: 1,
  created_at: '2026-11-23 00:00:00',
};

const INPUT = { points: 1000, note: 'seed', adjustLifetime: true, notify: false };

/** The JSON body adjustCustomerPoints() posted. */
function sentBody(): Record<string, unknown> {
  return post.mock.calls[0][1].json;
}

describe('adjust points: the tier-points choice @P-9', () => {
  beforeEach(() => {
    post.mockReset();
    post.mockReturnValue({ json: async () => ENTRY });
  });

  it('accepts an input without adjustTier', () => {
    expect(adjustPointsSchema.safeParse(INPUT).success).toBe(true);
  });

  it('sends adjust_tier only when it is set', async () => {
    await adjustCustomerPoints(4, INPUT);
    expect(sentBody()).not.toHaveProperty('adjust_tier');

    post.mockClear();
    await adjustCustomerPoints(4, { ...INPUT, adjustTier: false });
    expect(sentBody()).toMatchObject({ adjust_lifetime: true, adjust_tier: false });
  });
});
