import { afterEach, describe, expect, it, vi } from 'vitest';

import { Reward } from '@/lib/schema/reward.schema';
import {
  availabilityLabel,
  costLabel,
  headerSubtitle,
  resolveRewardsScreen,
  rewardSubtitle,
  typeLabel,
} from '@/pro/rewards/reward-labels';

function reward(overrides: Partial<Reward>): Reward {
  return {
    id: 1,
    name: 'Test reward',
    slug: 'test-reward',
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
    ...overrides,
  };
}

const USD = { symbol: '$', position: 'left', thousand_sep: ',', decimal_sep: '.', num_decimals: 2 };

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('typeLabel', () => {
  it('matches the wireframe copy per type', () => {
    expect(typeLabel('flexible_discount')).toBe('Flexible discount');
    expect(typeLabel('fixed_coupon')).toBe('Discount');
    expect(typeLabel('percent_coupon')).toBe('Percentage');
    expect(typeLabel('free_shipping')).toBe('Free shipping');
    expect(typeLabel('free_product')).toBe('Free product');
  });
});

describe('costLabel', () => {
  it('is "any" for the slider, whose customer picks the amount', () => {
    expect(costLabel(reward({ type: 'flexible_discount', cost_points: null }))).toBe('any');
  });

  it('is the point cost with "pts" for every other type', () => {
    expect(costLabel(reward({ type: 'fixed_coupon', cost_points: 500 }))).toBe('500 pts');
  });
});

describe('rewardSubtitle', () => {
  it('is the fixed slider copy for flexible_discount', () => {
    expect(rewardSubtitle(reward({ type: 'flexible_discount' }))).toBe(
      'The customer chooses how many points to use at checkout',
    );
  });

  it('names the coupon expiry when the config carries one', () => {
    expect(rewardSubtitle(reward({ type: 'fixed_coupon', config: { coupon_expiry_days: 60 } }))).toBe(
      'Single-use coupon, valid 60 days after claiming',
    );
  });

  it('falls back to a generic line when no expiry is configured', () => {
    expect(rewardSubtitle(reward({ type: 'free_shipping', config: {} }))).toBe('Single-use coupon');
  });
});

describe('availabilityLabel', () => {
  it('reads the slider limits from config, not conditions', () => {
    expect(
      availabilityLabel(
        reward({ type: 'flexible_discount', config: { min_points: 100, max_percent_of_subtotal: null } }),
      ),
    ).toBe('100 pts minimum · up to 100% of the order');
  });

  it('defaults a catalog reward with no conditions to "Everyone"', () => {
    expect(availabilityLabel(reward({ conditions: [] }))).toBe('Everyone');
  });

  it('reads "once per customer" from a redemption_count lt 1 condition', () => {
    const withCondition = reward({
      conditions: [{ type: 'redemption_count', operator: 'lt', value: 1 }],
    });

    expect(availabilityLabel(withCondition)).toBe('once per customer');
  });

  it('reads a higher cap as "up to N per customer"', () => {
    const withCondition = reward({
      conditions: [{ type: 'redemption_count', operator: 'lt', value: 3 }],
    });

    expect(availabilityLabel(withCondition)).toBe('up to 3 per customer');
  });

  it('adds a minimum order and a redemption cap when config has them', () => {
    vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data: USD } } });

    expect(availabilityLabel(reward({ config: { min_order: 25, max_total: 100 } }))).toBe(
      'Everyone · minimum order $25.00 · 100 available',
    );
  });
});

describe('resolveRewardsScreen', () => {
  it('is the init case when only the built-in reward exists', () => {
    const builtin = reward({ id: 1, is_builtin: true, type: 'flexible_discount', cost_points: null });

    const screen = resolveRewardsScreen([builtin]);

    expect(screen.isInitCase).toBe(true);
    expect(screen.catalogRows).toHaveLength(0);
    expect(screen.rows).toEqual([builtin]);
  });

  it('is not the init case once a catalog reward exists', () => {
    const builtin = reward({ id: 1, is_builtin: true, type: 'flexible_discount', cost_points: null });
    const catalogReward = reward({ id: 2, name: '$5 off' });

    const screen = resolveRewardsScreen([catalogReward, builtin]);

    expect(screen.isInitCase).toBe(false);
    expect(screen.catalogRows).toEqual([catalogReward]);
  });

  it('puts the built-in reward first regardless of input order', () => {
    const builtin = reward({ id: 1, is_builtin: true, sort_order: 5, type: 'flexible_discount', cost_points: null });
    const catalogReward = reward({ id: 2, sort_order: 0 });

    const screen = resolveRewardsScreen([catalogReward, builtin]);

    expect(screen.rows.map((row) => row.id)).toEqual([1, 2]);
  });

  it('hides archived rewards from both rows and catalogRows', () => {
    const builtin = reward({ id: 1, is_builtin: true, type: 'flexible_discount', cost_points: null });
    const archived = reward({ id: 2, status: 'archived' });

    const screen = resolveRewardsScreen([builtin, archived]);

    expect(screen.rows).toEqual([builtin]);
    expect(screen.catalogRows).toHaveLength(0);
    // An archived reward is not a reason to show the empty state; it once had a catalog.
    expect(screen.isInitCase).toBe(true);
  });
});

describe('headerSubtitle', () => {
  it('is the init-case copy when there is no catalog reward', () => {
    const builtin = reward({ id: 1, is_builtin: true, type: 'flexible_discount', cost_points: null });

    expect(headerSubtitle(resolveRewardsScreen([builtin]))).toBe('One reward. Nothing has been redeemed yet.');
  });

  it('counts every visible row, built-in included, once a catalog exists', () => {
    const builtin = reward({ id: 1, is_builtin: true, type: 'flexible_discount', cost_points: null });
    const catalogReward = reward({ id: 2 });

    expect(headerSubtitle(resolveRewardsScreen([builtin, catalogReward]))).toBe(
      '2 rewards. Drag to reorder — this is the order customers see.',
    );
  });
});
