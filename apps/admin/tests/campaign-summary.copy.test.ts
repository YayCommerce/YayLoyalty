import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { Campaign, CampaignCondition } from '@/lib/schema/campaign.schema';
import type { CampaignFormValues } from '@/pro/campaigns/editor/campaign-editor.form';
import { campaignReadiness, summarizeCampaign } from '@/pro/campaigns/editor/campaign-summary.copy';

/**
 * The "What this means" rail: the rate, what the hidden Advanced settings
 * do, the window, and only the conditions that actually narrow anything.
 */
const USD = { currency: 'USD', symbol: '$', position: 'left', thousand_sep: ',', decimal_sep: '.', num_decimals: 2 };

beforeEach(() => {
  vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data: USD } } });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

function values(overrides: Partial<CampaignFormValues> = {}): CampaignFormValues {
  return {
    name: 'Points for purchases',
    status: 'active',
    priority: 0,
    config: { points_per_unit: 1 },
    conditions: [],
    starts_at: null,
    ends_at: null,
    ...overrides,
  };
}

function defaultCampaign(status: Campaign['status'], pointsPerUnit = 1): Campaign {
  return { status, config: { points_per_unit: pointsPerUnit } } as unknown as Campaign;
}

const purchase = { trigger: 'purchase' as const, defaultPurchaseCampaign: undefined };

describe('summarizeCampaign, purchase', () => {
  it('reads a default rate campaign in four lines', () => {
    expect(summarizeCampaign(values(), purchase)).toEqual([
      'Earns 1 pt per $1.00.',
      'Counted on the subtotal after discounts, rounded down.',
      'Runs continuously — no start or end date is set.',
      'No conditions.',
    ]);
  });

  it('adds one sentence per limit group that is set, after the counting sentence', () => {
    const config = {
      points_per_unit: 2,
      include_tax: true,
      min_subtotal: 500,
      max_points_per_order: 500,
      daily_cap: 1000,
      excluded_product_ids: [1, 2],
      excluded_category_ids: [3],
    };

    expect(summarizeCampaign(values({ config }), purchase)).toEqual([
      'Earns 2 pts per $1.00.',
      'Counted on the subtotal after discounts, plus tax, rounded down.',
      'Does not apply to orders under $500.00 (whole order, before discounts).',
      'Capped at 500 pts per order and 1,000 pts per day.',
      'Excluded: 2 products and 1 category.',
      'Runs continuously — no start or end date is set.',
      'No conditions.',
    ]);
  });

  it('computes a multiplier from an active default campaign', () => {
    const summary = summarizeCampaign(values({ config: { mode: 'multiplier', multiplier: 2 } }), {
      ...purchase,
      defaultPurchaseCampaign: defaultCampaign('active'),
    });

    expect(summary[0]).toBe('Earns 2 pts per $1.00, ×2 the default rate of 1.');
  });

  it('says a multiplier earns nothing while the default campaign is paused or missing', () => {
    const config = { mode: 'multiplier', multiplier: 2 };
    const expected =
      'Multiplies the default purchase campaign’s rate by ×2, but earns nothing until that campaign is active.';

    expect(
      summarizeCampaign(values({ config }), { ...purchase, defaultPurchaseCampaign: defaultCampaign('paused') })[0],
    ).toBe(expected);
    expect(summarizeCampaign(values({ config }), purchase)[0]).toBe(expected);
  });

  it('keeps a rate campaign’s rate line whatever the default campaign is doing', () => {
    expect(summarizeCampaign(values(), { ...purchase, isDefaultPurchasePending: true })[0]).toBe(
      'Earns 1 pt per $1.00.',
    );
  });

  it('says an active default campaign without a rate leaves the multiplier earning nothing', () => {
    const summary = summarizeCampaign(values({ config: { mode: 'multiplier', multiplier: 2 } }), {
      ...purchase,
      defaultPurchaseCampaign: { status: 'active', config: {} } as unknown as Campaign,
    });

    expect(summary[0]).toBe(
      'Multiplies the default purchase campaign’s rate by ×2, but earns nothing until that campaign is active.',
    );
  });

  it('leaves the rate out while the default campaign is still loading', () => {
    const summary = summarizeCampaign(values({ config: { mode: 'multiplier', multiplier: 2 } }), {
      ...purchase,
      isDefaultPurchasePending: true,
    });

    expect(summary[0]).toBe('Counted on the subtotal after discounts, rounded down.');
  });
});

describe('summarizeCampaign, conditions', () => {
  it('counts only conditions that hold values', () => {
    const conditions: CampaignCondition[] = [
      { type: 'user_role', operator: 'in', value: ['editor', 'shop_manager'] },
      { type: 'product_in_cart', operator: 'in', value: [] },
      { type: 'country', operator: 'in', value: { countries: [], source: 'shipping' } },
    ];

    expect(summarizeCampaign(values({ conditions }), purchase).at(-1)).toBe(
      'Restricted to orders matching: Customer role (2).',
    );
  });

  it('names a rule condition by its label and a country list by its count', () => {
    const conditions: CampaignCondition[] = [
      { type: 'country', operator: 'in', value: { countries: ['US', 'VN', 'FR'], source: 'billing' } },
      { type: 'lifetime_spend', operator: 'gte', value: { amount: 100 } },
    ];

    expect(summarizeCampaign(values({ conditions }), purchase).at(-1)).toBe(
      'Restricted to orders matching: Country (3); Lifetime spend.',
    );
  });

  it('names a between range on a number rule by its label, not as a two-value list', () => {
    const conditions: CampaignCondition[] = [{ type: 'order_count', operator: 'between', value: [1, 5] }];

    expect(summarizeCampaign(values({ conditions }), purchase).at(-1)).toBe(
      'Restricted to orders matching: Order count.',
    );
  });

  it('reads as no conditions when every row is empty', () => {
    const conditions: CampaignCondition[] = [{ type: 'customer_ids', operator: 'in', value: [] }];

    expect(summarizeCampaign(values({ conditions }), purchase).at(-1)).toBe('No conditions.');
  });
});

describe('summarizeCampaign, other triggers', () => {
  it('pluralises signup and review points', () => {
    expect(summarizeCampaign(values({ config: { points: 1 } }), { ...purchase, trigger: 'signup' })[0]).toBe(
      'Awards 1 point once, the first time a customer creates an account.',
    );
    expect(
      summarizeCampaign(values({ config: { points: 10, media_bonus_points: 5 } }), {
        ...purchase,
        trigger: 'review',
      })[0],
    ).toBe('Awards 10 points per approved review, plus 5 for one with a photo or video.');
  });
});

describe('campaignReadiness', () => {
  it('passes a named rate campaign with no dates', () => {
    const readiness = campaignReadiness(values(), { trigger: 'purchase', defaultPurchaseCampaign: undefined });

    expect(readiness.items.map((item) => item.ok)).toEqual([true, true]);
  });

  it('counts a multiplier with no stored factor as set, since the engine reads it as ×1', () => {
    const readiness = campaignReadiness(values({ config: { mode: 'multiplier' } }), {
      trigger: 'purchase',
      defaultPurchaseCampaign: { status: 'active', config: { points_per_unit: 1 } } as unknown as Campaign,
    });

    expect(readiness.items[0].ok).toBe(true);
  });

  it('flags a multiplier whose default campaign is paused', () => {
    const readiness = campaignReadiness(values({ config: { mode: 'multiplier', multiplier: 2 } }), {
      trigger: 'purchase',
      defaultPurchaseCampaign: defaultCampaign('paused'),
    });

    expect(readiness.items.map((item) => item.ok)).toEqual([true, true, false]);
  });
});
