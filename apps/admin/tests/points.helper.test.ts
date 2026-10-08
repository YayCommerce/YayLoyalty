import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { formatPoints, pointsPerCurrencyLabel, spendPairFromRate } from '@/lib/helpers/points.helper';

const VND = { symbol: '₫', position: 'right_space', thousand_sep: '.', decimal_sep: ',', num_decimals: 0 };

beforeEach(() => {
  vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data: VND } } });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

// The pair is shown only when the engine pays it exactly; otherwise the rate per 1 unit.
describe('spendPairFromRate', () => {
  it.each([
    [2.5, { spend: 2, points: 5 }],
    [1 / 3, { spend: 3, points: 1 }],
    [0.05, { spend: 20, points: 1 }],
    [0.0001, { spend: 10000, points: 1 }],
    [2.000001, { spend: 1, points: 2.000001 }],
    [0.333333, { spend: 1, points: 0.333333 }],
  ])('reads %s as its pair', (rate, pair) => {
    expect(spendPairFromRate(rate)).toEqual(pair);
  });
});

// Points take the store's price separators, not the browser locale's.
describe('points formatting', () => {
  it('groups with the store thousands separator', () => {
    expect(formatPoints(12400)).toBe('12.400');
  });

  it('writes a rate with the store decimal separator', () => {
    expect(pointsPerCurrencyLabel(1.5)).toContain('3 pts per 2 ₫');
    expect(pointsPerCurrencyLabel(2.000001)).toContain('2,000001');
  });
});
