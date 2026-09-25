import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { DEFAULT_CAMPAIGN_CONFIG } from '@/lib/schema/campaign.schema';
import type { Campaign } from '@/lib/schema/campaign.schema';
import { givesLabel } from '@/pro/campaigns/campaign-labels';
import {
  defaultPurchaseRate,
  effectiveRateSentence,
  isDefaultPurchaseActive,
  purchaseAdvancedPhrases,
  purchaseCountingSentence,
  purchaseLimitSentences,
} from '@/pro/campaigns/editor/campaign-reward.copy';

/**
 * The purchase reward's "what is set" facts, phrased two ways: short for the
 * Advanced toggle, prose for the summary rail. Both must agree on which
 * settings count as set, and read nothing but config.
 */
const USD = { symbol: '$', position: 'left', thousand_sep: ',', decimal_sep: '.', num_decimals: 2 };

beforeEach(() => {
  vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data: { currency: 'USD', ...USD } } } });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

const ALL_SET = {
  include_tax: true,
  min_subtotal: 500,
  exclude_coupon_orders: true,
  max_points_per_order: 500,
  daily_cap: 1000,
  excluded_product_ids: [6521, 6522],
  excluded_category_ids: [17],
};

describe('purchaseAdvancedPhrases', () => {
  it('is empty when nothing beyond the defaults is set', () => {
    expect(purchaseAdvancedPhrases({})).toEqual([]);
    expect(purchaseAdvancedPhrases(DEFAULT_CAMPAIGN_CONFIG.purchase)).toEqual([]);
  });

  it('names each set value on its own, singular and plural', () => {
    expect(purchaseAdvancedPhrases({ include_tax: true })).toEqual(['Tax included']);
    expect(purchaseAdvancedPhrases({ min_subtotal: 500 })).toEqual(['Min $500.00']);
    expect(purchaseAdvancedPhrases({ exclude_coupon_orders: true })).toEqual(['No coupon orders']);
    expect(purchaseAdvancedPhrases({ max_points_per_order: 1 })).toEqual(['Capped at 1 pt/order']);
    expect(purchaseAdvancedPhrases({ daily_cap: 1000 })).toEqual(['Capped at 1,000 pts/day']);
    expect(purchaseAdvancedPhrases({ excluded_product_ids: [1] })).toEqual(['1 product excluded']);
    expect(purchaseAdvancedPhrases({ excluded_category_ids: [1, 2] })).toEqual(['2 categories excluded']);
  });

  it('lists everything set in field order', () => {
    expect(purchaseAdvancedPhrases(ALL_SET)).toEqual([
      'Tax included',
      'Min $500.00',
      'No coupon orders',
      'Capped at 500 pts/order',
      'Capped at 1,000 pts/day',
      '2 products excluded',
      '1 category excluded',
    ]);
  });
});

describe('purchaseCountingSentence', () => {
  it('falls back to after discounts, no tax, rounded down', () => {
    expect(purchaseCountingSentence({})).toBe('Counted on the subtotal after discounts, rounded down.');
  });

  it('reads an unknown rounding mode as rounded down, like the engine', () => {
    expect(purchaseCountingSentence({ rounding: 'sideways' })).toBe(
      'Counted on the subtotal after discounts, rounded down.',
    );
  });

  it('spells out base, tax and rounding', () => {
    expect(purchaseCountingSentence({ base: 'before_discounts', include_tax: true, rounding: 'up' })).toBe(
      'Counted on the subtotal before discounts, plus tax, rounded up.',
    );
    expect(purchaseCountingSentence({ base: 'after_discounts', include_tax: true, rounding: 'nearest' })).toBe(
      'Counted on the subtotal after discounts, plus tax, rounded to the nearest whole point.',
    );
    expect(purchaseCountingSentence({ base: 'before_discounts', include_tax: false, rounding: 'down' })).toBe(
      'Counted on the subtotal before discounts, rounded down.',
    );
  });
});

describe('purchaseLimitSentences', () => {
  it('is empty when nothing limits the campaign', () => {
    expect(purchaseLimitSentences({ include_tax: true })).toEqual([]);
    expect(purchaseLimitSentences({ max_points_per_order: 0, daily_cap: 0, min_subtotal: 0 })).toEqual([]);
  });

  it('groups eligibility, caps and exclusions, one sentence each', () => {
    expect(purchaseLimitSentences(ALL_SET)).toEqual([
      'Does not apply to orders under $500.00 (whole order, before discounts) or to orders with a coupon.',
      'Capped at 500 pts per order and 1,000 pts per day.',
      'Excluded: 2 products and 1 category.',
    ]);
  });

  it('keeps either half of a pair on its own', () => {
    expect(purchaseLimitSentences({ min_subtotal: 20 })).toEqual([
      'Does not apply to orders under $20.00 (whole order, before discounts).',
    ]);
    expect(purchaseLimitSentences({ exclude_coupon_orders: true })).toEqual([
      'Does not apply to orders with a coupon.',
    ]);
    expect(purchaseLimitSentences({ max_points_per_order: 1 })).toEqual(['Capped at 1 pt per order.']);
    expect(purchaseLimitSentences({ daily_cap: 50 })).toEqual(['Capped at 50 pts per day.']);
    expect(purchaseLimitSentences({ excluded_product_ids: [1] })).toEqual(['1 product excluded.']);
    expect(purchaseLimitSentences({ excluded_category_ids: [1, 2] })).toEqual(['2 categories excluded.']);
  });
});

describe('effectiveRateSentence', () => {
  it('reads the rate in rate mode', () => {
    expect(effectiveRateSentence({ points_per_unit: 1 }, undefined)).toBe('Earns 1 pt per $1.00.');
    expect(effectiveRateSentence({ points_per_unit: 2 }, 1)).toBe('Earns 2 pts per $1.00.');
  });

  it('computes the rate a multiplier produces from the default campaign', () => {
    expect(effectiveRateSentence({ mode: 'multiplier', multiplier: 2 }, 1)).toBe(
      'Earns 2 pts per $1.00, ×2 the default rate of 1.',
    );
    expect(effectiveRateSentence({ mode: 'multiplier' }, 3)).toBe('Earns 3 pts per $1.00, ×1 the default rate of 3.');
    expect(effectiveRateSentence({ mode: 'multiplier', multiplier: 1.1 }, 3)).toBe(
      'Earns 3.3 pts per $1.00, ×1.1 the default rate of 3.',
    );
  });

  it('says it earns nothing while the default campaign is missing or not active', () => {
    expect(effectiveRateSentence({ mode: 'multiplier', multiplier: 2 }, undefined)).toBe(
      'Multiplies the default purchase campaign’s rate by ×2, but earns nothing until that campaign is active.',
    );
  });
});

describe('default purchase campaign', () => {
  const campaign = (status: string, config: Record<string, unknown>) => ({ status, config }) as unknown as Campaign;

  it('lends its rate only while active, as the engine requires', () => {
    expect(isDefaultPurchaseActive(campaign('active', {}))).toBe(true);
    expect(isDefaultPurchaseActive(campaign('paused', {}))).toBe(false);
    expect(isDefaultPurchaseActive(undefined)).toBe(false);
    expect(defaultPurchaseRate(campaign('active', { points_per_unit: 2 }))).toBe(2);
    expect(defaultPurchaseRate(campaign('paused', { points_per_unit: 2 }))).toBeUndefined();
    expect(defaultPurchaseRate(campaign('active', {}))).toBeUndefined();
  });
});

describe('givesLabel', () => {
  it('pluralises the purchase rate', () => {
    expect(givesLabel('purchase', { points_per_unit: 1 })).toBe('1 pt per $1.00');
    expect(givesLabel('purchase', { points_per_unit: 2 })).toBe('2 pts per $1.00');
    expect(givesLabel('purchase', { mode: 'multiplier', multiplier: 2 })).toBe('×2 points');
  });
});
