import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  availabilityLabel,
  costLabel,
  headerSubtitle,
  resolveRewardsScreen,
  rewardSubtitle,
  typeLabel,
} from '@/pro/rewards/reward-labels';
import { reward } from './fixtures/reward.fixture';

const USD = { symbol: '$', position: 'left', thousand_sep: ',', decimal_sep: '.', num_decimals: 2 };

// Points take the store's separators, so every label reads the currency data.
beforeEach(() => {
  vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data: USD } } });
});

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
  it('is "any" for the flexible discount, whose customer picks the amount', () => {
    expect(costLabel(reward({ type: 'flexible_discount', cost_points: null }))).toBe('any');
  });

  it('is the point cost with "pts" for every other type', () => {
    expect(costLabel(reward({ type: 'fixed_coupon', cost_points: 500 }))).toBe('500 pts');
  });
});

describe('rewardSubtitle', () => {
  it('reads a flexible discount without a redemption mode as auto max', () => {
    expect(rewardSubtitle(reward({ type: 'flexible_discount' }))).toBe(
      'The most points the order allows are applied at checkout',
    );
  });

  it('is the coupon line for every catalog type', () => {
    expect(rewardSubtitle(reward({ type: 'fixed_coupon' }))).toBe('Applied as a coupon at checkout');
    expect(rewardSubtitle(reward({ type: 'free_product' }))).toBe('Applied as a coupon at checkout');
  });
});

describe('availabilityLabel', () => {
  it('reads the flexible discount limits from config, not conditions', () => {
    expect(
      availabilityLabel(
        reward({ type: 'flexible_discount', config: { min_points: 100, max_percent_of_discountable: null } }),
      ),
    ).toBe('100 pts minimum · up to 100% of the order');
  });

  it('defaults a catalog reward with no conditions to "Everyone"', () => {
    expect(availabilityLabel(reward({ conditions: [] }))).toBe('Everyone');
  });

  it('adds a minimum order when config has one', () => {
    vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data: USD } } });

    expect(availabilityLabel(reward({ config: { min_order: 25 } }))).toBe('Everyone · minimum order $25.00');
  });
});

describe('resolveRewardsScreen', () => {
  it('is the init case when only the built-in reward exists', () => {
    const builtin = reward({ id: 1, is_builtin: true, type: 'flexible_discount', cost_points: null });

    const screen = resolveRewardsScreen([builtin]);

    expect(screen.hasNoCatalogRewards).toBe(true);
    expect(screen.catalogRows).toHaveLength(0);
    expect(screen.rows).toEqual([builtin]);
  });

  it('is not the init case once a catalog reward exists', () => {
    const builtin = reward({ id: 1, is_builtin: true, type: 'flexible_discount', cost_points: null });
    const catalogReward = reward({ id: 2, name: '$5 off' });

    const screen = resolveRewardsScreen([catalogReward, builtin]);

    expect(screen.hasNoCatalogRewards).toBe(false);
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
    expect(screen.hasNoCatalogRewards).toBe(true);
  });

  it('collects archived rewards, in display order, for the Archived view', () => {
    const live = reward({ id: 1 });
    const first = reward({ id: 2, status: 'archived' });
    const second = reward({ id: 3, status: 'archived' });

    expect(resolveRewardsScreen([first, live, second]).archivedRows).toEqual([first, second]);
  });
});

describe('headerSubtitle', () => {
  it('is the init-case copy when there is no catalog reward', () => {
    const builtin = reward({ id: 1, is_builtin: true, type: 'flexible_discount', cost_points: null });

    expect(headerSubtitle(resolveRewardsScreen([builtin]))).toEqual({
      count: 'One reward',
      detail: 'Nothing has been redeemed yet.',
    });
  });

  it('counts every visible row, built-in included, once a catalog exists', () => {
    const builtin = reward({ id: 1, is_builtin: true, type: 'flexible_discount', cost_points: null });
    const catalogReward = reward({ id: 2 });

    expect(headerSubtitle(resolveRewardsScreen([builtin, catalogReward]))).toEqual({
      count: '2 rewards',
      detail: 'Drag to reorder — this is the order customers see.',
    });
  });
});
